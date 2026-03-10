<?php

$detectionTypeEnv = env('GOOGLE_OCR_DETECTION_TYPE', 'DOCUMENT_TEXT_DETECTION');
$detectionTypes = is_string($detectionTypeEnv)
    ? array_map('trim', preg_split('/[,|]/', $detectionTypeEnv, -1, PREG_SPLIT_NO_EMPTY))
    : (is_array($detectionTypeEnv) ? $detectionTypeEnv : ['DOCUMENT_TEXT_DETECTION']);
$detectionTypes = array_values(array_filter($detectionTypes)) ?: ['DOCUMENT_TEXT_DETECTION'];

return [
    'credentials_path' => env('GOOGLE_CREDENTIALS_PATH'),
    'detection_type' => $detectionTypes[0],
    'detection_types' => $detectionTypes,
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
