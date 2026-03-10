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

    /*
    |--------------------------------------------------------------------------
    | フォームフィールドマッピング
    |--------------------------------------------------------------------------
    | OCRで抽出した値を、フォームの入力ボックスやドロップダウンに割り当てます。
    | キー = フォームフィールド名（input name, select id 等）
    | 値 = OCR結果の取得方法
    |
    | 直接マッピング: 'form_field_name' => 'ocr_result_key'  (text, labels 等)
    | 正規表現抽出:  'form_field_name' => ['source' => 'text', 'pattern' => '/.../u']
    | 配列の要素:    'form_field_name' => ['source' => 'labels', 'property' => 'description', 'index' => 0]
    */
    'form_field_mapping' => [
        // 入力ボックス例
        // 'full_text' => 'text',  // 全文
        // 'invoice_number' => ['source' => 'text', 'pattern' => '/請求番号[：:]\s*(.+)/u'],  // 請求番号
        // 'document_date' => ['source' => 'text', 'pattern' => '/日付[：:]\s*(\d{4}[-\/]\d{2}[-\/]\d{2})/u', 'group' => 1],  // 日付

        // ドロップダウン例: 文書の「見積書」「請求書」「領収書」を抽出し、select の value に割り当て
        // 'document_type' => ['source' => 'text', 'pattern' => '/(見積書|請求書|領収書)/u'],  // 書類種別

        // ドロップダウン例: ラベル検出の先頭（例: "Document"）をカテゴリ選択に割り当て
        // 'category' => ['source' => 'labels', 'property' => 'description', 'index' => 0],  // カテゴリ
    ],
];
