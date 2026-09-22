<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

/**
 * Shared by Store/UpdateVideoRequest.
 *
 * Reproduced against a real 7.66 MB phone-camera export with this server's
 * default php.ini (upload_max_filesize=2M): PHP itself rejects the file
 * (UPLOAD_ERR_INI_SIZE) before Laravel's controller ever runs, which makes
 * `UploadedFile::isValid()` false. Laravel's Validator short-circuits THAT
 * case on its own — see Illuminate\Validation\Validator::validateAttribute(),
 * the `$value instanceof UploadedFile && ! $value->isValid()` check — and
 * always fails with its built-in `uploaded` rule ("The :attribute failed to
 * upload.") *before* evaluating any of this request's own rules, including a
 * custom closure rule. There is no way to out-prioritize that from `rules()`.
 *
 * The one hook that runs after it: a `withValidator()` `after()` callback,
 * which can inspect the real PHP upload error code and replace the generic
 * message with what actually happened.
 */
trait ValidatesVideoFile
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->has('video')) {
                return;
            }

            $file = $this->file('video');
            if (! $file instanceof UploadedFile || $file->isValid()) {
                return;
            }

            $message = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'This file is larger than the server currently allows for uploads '.
                    '(its upload_max_filesize/post_max_size — the app itself has no size limit). Ask an admin to raise the server '.
                    'setting, or use a smaller file.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted before it finished. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file (no writable temp '.
                    'directory). Contact an admin.',
                UPLOAD_ERR_EXTENSION => 'A server PHP extension blocked this upload.',
                default => 'The file could not be uploaded (upload error '.$file->getError().').',
            };

            // Laravel's own generic "failed to upload" message is already in
            // there (from the 'uploaded' rule) — replace it with the specific
            // one instead of showing both.
            $validator->errors()->forget('video');
            $validator->errors()->add('video', $message);
        });
    }
}
