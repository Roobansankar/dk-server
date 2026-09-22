<?php

header('Content-Type: application/json');
echo json_encode([
    'sapi' => PHP_SAPI,
    'ini_loaded_file' => php_ini_loaded_file(),
    'ini_scanned_files' => php_ini_scanned_files(),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'memory_limit' => ini_get('memory_limit'),
    'proc_open' => function_exists('proc_open') ? 'available' : 'MISSING',
    'disable_functions' => ini_get('disable_functions'),
], JSON_PRETTY_PRINT);
