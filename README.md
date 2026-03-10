## kt-minatoku Laravel Project

## Version
1.0.0

## Tech Stack
| Item  | Version |
| ------------- | ------------- |
| OS  | Ubuntu 24.04  |
| Web Server  | Apache 2.4  |
| PHP | PHP 8.3  |
| DB | MySQL 8.4 |
| Storage | Amazon S3 (MinIO for local) |

---

## OCR機能の設定と使い方

このプロジェクトは、Google Cloud Vision API を使用してドキュメントの内容をOCRで検出する機能を提供します。

### 1. 環境設定

#### 1.1 必須の環境変数（`.env`）

| 変数名 | 説明 | 例 |
|--------|------|-----|
| `GOOGLE_CREDENTIALS_PATH` | サービスアカウントJSONのパス | `storage/app/google/credentials.json` |
| `GOOGLE_OCR_DETECTION_TYPE` | 検出タイプ（検出タイプ一覧のいずれか）※第3章参照 | `DOCUMENT_TEXT_DETECTION` |
| `GOOGLE_LOG_CHANNEL` | OCR用ログチャンネル | `google_ocr` |
| `GOOGLE_DEFAULT_LANGUAGE` | デフォルト言語コード | `ja` |
| `GOOGLE_OCR_MAX_FILE_SIZE` | 最大ファイルサイズ（バイト） | `20971520`（20MB） |

#### 1.2 Google Cloud Vision API の準備

1. **GCPプロジェクト作成**  
   [Google Cloud Console](https://console.cloud.google.com/) でプロジェクトを作成

2. **Vision API 有効化**  
   「Cloud Vision API」を有効化

3. **サービスアカウント作成**  
   - IAM → サービスアカウント作成  
   - ロールに「Cloud Vision API ユーザー」を付与  
   - キー（JSON）をダウンロード

4. **認証情報の配置**  
   - ダウンロードしたJSONを `storage/app/google/credentials.json` に配置  
   - `.gitignore` で `storage/app/google/` を除外することを推奨




### 2.  必要なファイル一覧

#### 2.1 アプリケーションコード

| ファイル | 役割 |
|----------|------|
| `app/Services/Common/GoogleOcrService.php` | Google Cloud Vision API を使ったOCR処理のコア |
| `app/Http/Controllers/Common/OcrController.php` | OCR API エンドポイント用コントローラ |
| `app/Http/Controllers/Controller.php` | 基底コントローラ（Laravel標準） |
| `app/Http/Requests/Common/ProcessOcrRequest.php` | ファイルアップロードのバリデーション |

#### 2.2 設定ファイル

| ファイル | 役割 |
|----------|------|
| `config/google.php` | OCR用設定（認証パス、検出タイプ、ログ、ファイルサイズ、MIMEタイプ） |
| `config/logging.php` | `google_ocr` ログチャンネル定義 |

#### 2.3 ルーティング

| ファイル | 役割 |
|----------|------|
| `routes/api.php` | `POST /api/ocr/extract`、`POST /api/ocr/detect-multiple` のルート定義 |

#### 2.4 認証情報（手動で配置）

| ファイル | 役割 |
|----------|------|
| `storage/app/google/credentials.json` | Google Cloud サービスアカウントのJSONキー |

#### 2.5 依存関係（Composer）

| パッケージ | 役割 |
|------------|------|
| `google/cloud-vision` | Google Cloud Vision API クライアント |
| `laravel/framework` | Laravel フレームワーク |

#### 2.6 ファイル構成イメージ

```
ocr_common/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php
│   │   │   └── Common/
│   │   │       └── OcrController.php
│   │   └── Requests/
│   │       └── Common/
│   │           └── ProcessOcrRequest.php
│   └── Services/
│       └── Common/
│           └── GoogleOcrService.php
├── config/
│   ├── google.php
│   └── logging.php
├── routes/
│   └── api.php
├── storage/
│   └── app/
│       └── google/
│           └── credentials.json   ← 手動配置
└── composer.json                  ← google/cloud-vision 必須
```

#### 2.7 依存関係図

| 呼び出し元 | 参照先 |
|------------|--------|
| `OcrController` | `GoogleOcrService`, `ProcessOcrRequest` |
| `ProcessOcrRequest` | `config('google.ocr_allowed_mime_types')`, `config('google.max_file_size')` |
| `GoogleOcrService` | `config('google.*')`, `google/cloud-vision` |
| `config/logging.php` | `google_ocr` チャンネル（`GoogleOcrService` が使用） |

#### 2.8 テスト用（任意）

| ファイル | 役割 |
|----------|------|
| `tests/Feature/GoogleOcrServiceTest.php` | `GoogleOcrService` のテスト |




### 3. 設定ファイル（`config/google.php`）

```php
'credentials_path' => env('GOOGLE_CREDENTIALS_PATH'),
'detection_type' => ...,   // GOOGLE_OCR_DETECTION_TYPE から取得（先頭値）
'detection_types' => ...,  // GOOGLE_OCR_DETECTION_TYPE をカンマ/パイプでパースした配列
'log_channel' => env('GOOGLE_LOG_CHANNEL', 'google_ocr'),
'default_language' => env('GOOGLE_DEFAULT_LANGUAGE', 'ja'),
'max_file_size' => (int) env('GOOGLE_OCR_MAX_FILE_SIZE', 20 * 1024 * 1024),
'ocr_allowed_mime_types' => [
    'application/pdf',
    'image/jpeg', 'image/jpg', 'image/png',
    'image/tiff', 'image/x-tiff',
],
```

※ `detection_type` と `detection_types` は `GOOGLE_OCR_DETECTION_TYPE` の値から自動で設定されます。

**検出タイプ:**
検出タイプ一覧のいずれかを指定できます。
テキスト抽出には `DOCUMENT_TEXT_DETECTION`（デフォルト）または `TEXT_DETECTION` を推奨します。

| 定数名 | 用途 |
|--------|------|
| `TYPE_UNSPECIFIED` | 未指定 |
| `FACE_DETECTION` | 顔検出 |
| `LANDMARK_DETECTION` | ランドマーク検出 |
| `LOGO_DETECTION` | ロゴ検出 |
| `LABEL_DETECTION` | ラベル検出（物体・シーン分類） |
| `TEXT_DETECTION` | テキスト検出（看板など） |
| `DOCUMENT_TEXT_DETECTION` | 文書テキスト検出（文書・手書き向け） |
| `SAFE_SEARCH_DETECTION` | 安全検索（不適切コンテンツ検出） |
| `IMAGE_PROPERTIES` | 画像プロパティ（主色など） |
| `CROP_HINTS` | クロップ候補 |
| `WEB_DETECTION` | Web検出（類似画像・ページ） |
| `PRODUCT_SEARCH` | 商品検索 |
| `OBJECT_LOCALIZATION` | 物体の位置検出 |

※ `extractText`、`detectText`、`detectLanguage` はテキスト系（`DOCUMENT_TEXT_DETECTION`、`TEXT_DETECTION`）でテキストを返します。それ以外の検出タイプでは `null` または空配列になります。

**`GOOGLE_OCR_DETECTION_TYPE` の設定例（複数指定対応）:**

```env
# 単一の検出タイプ
GOOGLE_OCR_DETECTION_TYPE=DOCUMENT_TEXT_DETECTION

# 複数の検出タイプ（カンマ区切り）
GOOGLE_OCR_DETECTION_TYPE=DOCUMENT_TEXT_DETECTION,LABEL_DETECTION,SAFE_SEARCH_DETECTION

# 複数の検出タイプ（パイプ区切り）
GOOGLE_OCR_DETECTION_TYPE=DOCUMENT_TEXT_DETECTION|LABEL_DETECTION|SAFE_SEARCH_DETECTION
```

複数指定時は `detectWithMultipleTypes()` メソッドまたは `POST /api/ocr/detect-multiple` エンドポイントを使用してください。





### 4. 利用方法

利用方法は2種類（Curlコマンドでの利用、もしくは、プログラム内でのメソッド呼出による利用）

#### 4.1 API エンドポイント経由

**エンドポイント:**
- `POST /api/ocr/extract` - テキスト抽出（単一検出タイプ）
- `POST /api/ocr/detect-multiple` - 複数検出タイプで検出（`GOOGLE_OCR_DETECTION_TYPE` で複数指定時）

**リクエスト:**
- Content-Type: `multipart/form-data`
- パラメータ: `files`（複数可）

**例（cURL）:**
```bash
# テキスト抽出
curl -X POST http://localhost:8000/api/ocr/extract -F "files[]=@document.pdf"

# 複数検出タイプで検出
curl -X POST http://localhost:8000/api/ocr/detect-multiple -F "files[]=@image.jpg"
```

**レスポンス例（extract）:**
```json
{
  "data": {
    "files": [
      { "filename": "document.pdf", "text": "抽出されたテキスト..." },
      { "filename": "image.png", "text": "..." }
    ],
    "combined_text": "全ファイルのテキストを結合した文字列"
  }
}
```

**レスポンス例（detect-multiple）:**
```json
{
  "data": {
    "files": [
      {
        "filename": "image.jpg",
        "detection": {
          "text": "抽出されたテキスト...",
          "labels": [
            { "description": "ラベル名", "score": 0.95, "topicality": 0.9 }
          ],
          "safe_search": {
            "adult": "VERY_UNLIKELY",
            "spoof": "VERY_UNLIKELY",
            "medical": "VERY_UNLIKELY",
            "violence": "VERY_UNLIKELY",
            "racy": "VERY_UNLIKELY"
          }
        }
      }
    ]
  }
}
```

#### 4.2 プログラム内での利用（DI）

`GoogleOcrService` をコンストラクタで注入し、メソッドを呼び出します。

```php
use App\Services\Common\GoogleOcrService;

class YourController extends Controller
{
    public function __construct(
        protected GoogleOcrService $ocrService
    ) {}

    public function process(UploadedFile $file): string|null
    {
        $text = $this->ocrService->extractText($file);
        return $text;
    }
}
```




### 5. GoogleOcrService のメソッド

| メソッド | 戻り値 | 用途 |
|----------|--------|------|
| `extractText($file, $options)` | `string|null` | 全文テキスト抽出 |
| `detectText($file, $options)` | `array` | 単語単位のテキスト＋位置・信頼度 |
| `detectLanguage($file)` | `string|null` | 言語コード検出 |
| `getTextWithConfidence($file, $minConfidence)` | `array` | 信頼度でフィルタしたテキスト |
| `detectWithMultipleTypes($file, $options)` | `array` | 複数検出タイプで検出（`text`, `labels`, `faces` 等を返す） |

optionsで使用できるものは優先言語設定

// 日本語を優先
$options = [
    'languageHints' => ['ja'],  
];

// 日本語・英語の両方を候補に
$results = $ocrService->detectText($file, [
    'languageHints' => ['ja', 'en'],
]);


#### 5.1 extractText（全文抽出）　※基本は全文抽出を使用する
```php
$text = $this->ocrService->extractText($file);

// 言語ヒントを指定
$text = $this->ocrService->extractText($file, [
    'languageHints' => ['ja', 'en'],
]);
```

#### 5.2 detectText（位置・信頼度付き）
```php
$results = $this->ocrService->detectText($file);
// 各要素: ['text' => '...', 'confidence' => 0.95, 'boundingBox' => [['x'=>0,'y'=>0], ...]]
```

#### 5.3 getTextWithConfidence（信頼度フィルタ）
```php
$results = $this->ocrService->getTextWithConfidence($file, 0.8);
// confidence >= 0.8 のものだけ返す
```

#### 5.4 detectWithMultipleTypes（複数検出タイプ）
`GOOGLE_OCR_DETECTION_TYPE` で複数指定した検出タイプで一括検出します。

```php
$result = $this->ocrService->detectWithMultipleTypes($file);
// 返却例: ['text' => '...', 'labels' => [...], 'safe_search' => [...], ...]
```

**返却されるキー（検出タイプに応じて）:** `text`, `text_detection`, `labels`, `faces`, `landmarks`, `logos`, `safe_search`, `image_properties`, `crop_hints`, `web_detection`, `object_localization`




### 6. 入力形式

次の3種類を渡せます。

| 型 | 例 |
|----|-----|
| ファイルパス（文字列） | `'/path/to/document.pdf'` |
| `UploadedFile` | `$request->file('file')` |
| リソース | `fopen('file.pdf', 'rb')` |





### 7. 制約とバリデーション

- **対応形式:** PDF、JPEG、PNG、TIFF
- **サイズ制限:** デフォルト20MB（`GOOGLE_OCR_MAX_FILE_SIZE` で変更可）
- **バリデーション:** `ProcessOcrRequest` で `files` 必須、`mimetypes`、`max` を検証




### 8. エラー処理とログ

- **失敗時の戻り値:** `extractText` → `null`、`detectText` → `[]`、`detectLanguage` → `null`、`detectWithMultipleTypes` → `[]`
- **ログ:** `google_ocr` チャンネル、`storage/logs/google_ocr/laravel.log` に出力


---


