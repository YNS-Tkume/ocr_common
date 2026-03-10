<?php

return [
    'credentials_path' => env('GOOGLE_CREDENTIALS_PATH'),
    'log_channel' => env('GOOGLE_LOG_CHANNEL', 'google_ocr'),
    'default_language' => env('GOOGLE_DEFAULT_LANGUAGE', 'ja'),
    'max_file_size' => (int) env('GOOGLE_OCR_MAX_FILE_SIZE', 20 * 1024 * 1024),
    'ocr_allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/tiff',
        'image/x-tiff',
    ],
];
