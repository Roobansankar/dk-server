<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteSettingsRequest;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;

class SiteSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SiteSetting::orderBy('group')->orderBy('key')->get()
                ->map(fn (SiteSetting $s) => [
                    'key' => $s->key,
                    'value' => $s->castValue(),
                    'type' => $s->type,
                    'group' => $s->group,
                ]),
        ]);
    }

    public function update(UpdateSiteSettingsRequest $request): JsonResponse
    {
        foreach ($request->array('settings') as $key => $value) {
            $setting = SiteSetting::where('key', $key)->first();

            if (! $setting) {
                continue; // only known keys are editable; new keys are added via seeder/migration
            }

            $setting->update([
                'value' => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : $value),
            ]);
        }

        return $this->index();
    }
}
