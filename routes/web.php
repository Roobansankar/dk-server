<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The "storage/{path}" route that serves uploaded media when there's no
// storage:link symlink is Laravel's own (see 'serve' on the 'public' disk
// in config/filesystems.php) — nothing to register here.
