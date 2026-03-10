<?php

namespace App\Services\Common;

use Google\Cloud\Vision\V1\{
    AnnotateImageRequest,
    BatchAnnotateImagesRequest,
    Feature,
    Feature\Type,
    Image,
};
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Google Cloud Vision OCR service.
 *
 * @SuppressWarnings("ExcessiveClassComplexity")
 */
class GoogleOcrService
{
    /**
     * Google Cloud Vision API client instance.
     *
     * @var \Google\Cloud\Vision\V1\Client\ImageAnnotatorClient|null $client
     */
    protected $client = null;

    /**
     * Channel for logging.
     *
     * @var string $channel
     */
    protected string $channel;

    /**
     * Maximum file size in bytes (20MB).
     *
     * @var int $maxFileSize
     */
    protected int $maxFileSize;

    /**
     * Default language code.
     *
     * @var string $defaultLanguage
     */
    protected string $defaultLanguage;

    /**
     * Constructor initializing GoogleOcrService.
     */
    public function __construct()
    {
        $this->channel = (string) config('google.log_channel', 'google_ocr');
        $this->maxFileSize = (int) config('google.max_file_size', 20 * 1024 * 1024);
        $this->defaultLanguage = (string) config('google.default_language', 'ja');

        try {
            $credentialsPath = config('google.credentials_path');

            // Priority: Service account credentials > default credentials
            if (is_string($credentialsPath) && $credentialsPath !== '' && file_exists($credentialsPath)) {
                putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
                $this->client = new ImageAnnotatorClient();

                return;
            }

            // Fallback: default credentials (for GCP environments)
            $this->client = new ImageAnnotatorClient();
        } catch (\Exception $error) {
            Log::channel($this->channel)->error(
                'Error initializing Google Cloud Vision API client',
                [
                    'error' => $error->getMessage(),
                    'trace' => $error->getTraceAsString(),
                ],
            );

            $this->client = null;
        }
    }

    /**
     * Extract full text from file.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param array<string, mixed> $options
     *
     * @return string|null
     */
    public function extractText($file, array $options = []): string|null
    {
        if (!$this->client) {
            return null;
        }

        try {
            $fileData = $this->getFileData($file);

            if ($fileData === null) {
                return null;
            }

            if (!$this->validateFile($fileData, $file)) {
                return null;
            }

            $image = (new Image())->setContent($fileData);
            $feature = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);

            $annotateRequest = (new AnnotateImageRequest())
                ->setImage($image)
                ->setFeatures([$feature]);

            if (isset($options['languageHints']) && is_array($options['languageHints'])) {
                $imageContext = new \Google\Cloud\Vision\V1\ImageContext();
                $imageContext->setLanguageHints($options['languageHints']);
                $annotateRequest->setImageContext($imageContext);
            }

            $batchRequest = (new BatchAnnotateImagesRequest())
                ->setRequests([$annotateRequest]);

            $batchResponse = $this->client->batchAnnotateImages($batchRequest);
            $responses = $batchResponse->getResponses();

            if (empty($responses)) {
                return null;
            }

            $fullTextAnnotation = $responses[0]->getFullTextAnnotation();

            if ($fullTextAnnotation) {
                return $fullTextAnnotation->getText();
            }

            return null;
        } catch (\Google\ApiCore\ApiException $error) {
            $this->logError('Error extracting text from file', $error, $file);

            return null;
        } catch (\Exception $error) {
            $this->logError('Error extracting text from file', $error, $file);

            return null;
        }
    }

    /**
     * Detect text with bounding boxes and confidence scores.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param array<string, mixed> $options
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectText($file, array $options = []): array
    {
        if (!$this->client) {
            return [];
        }

        try {
            $annotateResponse = $this->annotateDocument($file, $options);

            if ($annotateResponse === null) {
                return [];
            }

            $fullTextAnnotation = $annotateResponse->getFullTextAnnotation();

            $results = [];

            if ($fullTextAnnotation) {
                $results = $this->buildResultsFromFullTextAnnotation($fullTextAnnotation);
            }

            // Fallback to text annotations if full text annotation is not available
            if (empty($results)) {
                $results = $this->buildResultsFromTextAnnotations(
                    $annotateResponse->getTextAnnotations() ?? []
                );
            }

            return $results;
        } catch (\Google\ApiCore\ApiException $error) {
            $this->logError('Error detecting text from file', $error, $file);

            return [];
        } catch (\Exception $error) {
            $this->logError('Error detecting text from file', $error, $file);

            return [];
        }
    }

    /**
     * Detect document language.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     *
     * @return string|null
     */
    public function detectLanguage($file): string|null
    {
        if (!$this->client) {
            return null;
        }

        try {
            $annotateResponse = $this->annotateDocument($file);

            if ($annotateResponse === null) {
                return null;
            }

            $fullTextAnnotation = $annotateResponse->getFullTextAnnotation();

            if ($fullTextAnnotation) {
                $language = $this->extractLanguageFromFullTextAnnotation($fullTextAnnotation);

                if ($language !== null) {
                    return $language;
                }
            }

            return $this->defaultLanguage;
        } catch (\Google\ApiCore\ApiException $error) {
            $this->logError('Error detecting language from file', $error, $file);

            return null;
        } catch (\Exception $error) {
            $this->logError('Error detecting language from file', $error, $file);

            return null;
        }
    }

    /**
     * Get text with confidence filtering.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param float $minConfidence
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTextWithConfidence(
        $file,
        float $minConfidence = 0.7,
    ): array {
        $detectedTexts = $this->detectText($file);

        return array_filter($detectedTexts, function (array $item) use ($minConfidence) {
            return $item['confidence'] >= $minConfidence;
        });
    }

    /**
     * Destructor to close the client connection.
     */
    public function __destruct()
    {
        if ($this->client instanceof ImageAnnotatorClient) {
            try {
                $this->client->close();
            } catch (\Exception $error) {
                // Silently handle cleanup errors
            }
        }
    }

    /**
     * Get file data from various input types.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     *
     * @return string|null
     */
    protected function getFileData($file): string|null
    {
        try {
            if (is_string($file)) {
                if (!file_exists($file)) {
                    return null;
                }

                $contents = file_get_contents($file);

                if ($contents === false) {
                    return null;
                }

                return $contents;
            }

            if (is_resource($file)) {
                $contents = stream_get_contents($file);

                if ($contents === false) {
                    return null;
                }

                return $contents;
            }

            if ($file instanceof UploadedFile) {
                $contents = $file->get();

                return $contents === false ? null : $contents;
            }

            return null;
        } catch (\Exception $error) {
            $this->logError('Error reading file data', $error, $file);

            return null;
        }
    }

    /**
     * Validate file format and size.
     *
     * @param string $fileData
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     *
     * @return bool
     */
    protected function validateFile(string $fileData, $file): bool
    {
        $fileSize = strlen($fileData);

        if ($fileSize > $this->maxFileSize) {
            return false;
        }

        $mimeType = $this->getMimeType($file, $fileData);
        $allowedMimes = config('google.ocr_allowed_mime_types', ['application/pdf']);

        if (!in_array($mimeType, $allowedMimes, true)) {
            return false;
        }

        return true;
    }

    /**
     * Get MIME type of file.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param string $fileData
     *
     * @return string
     */
    protected function getMimeType($file, string $fileData): string
    {
        if ($file instanceof UploadedFile) {
            $mimeType = $file->getMimeType();

            return is_string($mimeType) ? $mimeType : 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        if (is_string($file) && file_exists($file)) {
            $mimeType = finfo_file($finfo, $file);
            finfo_close($finfo);

            return is_string($mimeType) ? $mimeType : 'application/octet-stream';
        }

        $mimeType = finfo_buffer($finfo, $fileData);
        finfo_close($finfo);

        return is_string($mimeType) ? $mimeType : 'application/octet-stream';
    }

    /**
     * Log error with context.
     *
     * @param string $message
     * @param \Exception $error
     * @param string|\Illuminate\Http\UploadedFile|resource|null $file
     *
     * @return void
     */
    protected function logError(
        string $message,
        \Exception $error,
        $file = null,
    ): void {
        $context = [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ];

        if ($file instanceof UploadedFile) {
            $context['file_name'] = $file->getClientOriginalName();
            $context['file_size'] = $file->getSize();
            $context['mime_type'] = $file->getMimeType();
        } elseif (is_string($file)) {
            $context['file_path'] = $file;
        }

        if ($error instanceof \Google\ApiCore\ApiException) {
            $context['status_code'] = $error->getCode();
            $context['status_message'] = $error->getBasicMessage();
        }

        Log::channel($this->channel)->error($message, $context);
    }

    /**
     * Execute a Vision document annotation request and return the first response.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param array<string, mixed> $options
     *
     * @return \Google\Cloud\Vision\V1\AnnotateImageResponse|null
     */
    protected function annotateDocument($file, array $options = [])
    {
        if (!$this->client instanceof ImageAnnotatorClient) {
            return null;
        }

        $fileData = $this->getFileData($file);

        if ($fileData === null) {
            return null;
        }

        if (!$this->validateFile($fileData, $file)) {
            return null;
        }

        $image = (new Image())->setContent($fileData);
        $feature = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);

        $annotateRequest = (new AnnotateImageRequest())
            ->setImage($image)
            ->setFeatures([$feature]);

        if (isset($options['languageHints']) && is_array($options['languageHints'])) {
            $imageContext = new \Google\Cloud\Vision\V1\ImageContext();
            $imageContext->setLanguageHints($options['languageHints']);
            $annotateRequest->setImageContext($imageContext);
        }

        $batchRequest = (new BatchAnnotateImagesRequest())
            ->setRequests([$annotateRequest]);

        $batchResponse = $this->client->batchAnnotateImages($batchRequest);
        $responses = $batchResponse->getResponses();

        return $responses[0] ?? null;
    }

    /**
     * Build detection results from full text annotation.
     *
     * @param \Google\Cloud\Vision\V1\TextAnnotation $fullTextAnnotation
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResultsFromFullTextAnnotation($fullTextAnnotation): array
    {
        $results = [];
        $pages = $fullTextAnnotation->getPages();

        if (!$pages) {
            return $results;
        }

        foreach ($pages as $page) {
            $results = array_merge($results, $this->buildResultsFromPage($page));
        }

        return $results;
    }

    /**
     * Build detection results from a single page.
     *
     * @param \Google\Cloud\Vision\V1\Page $page
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResultsFromPage($page): array
    {
        $results = [];
        $blocks = $page->getBlocks();

        if (!$blocks) {
            return $results;
        }

        foreach ($blocks as $block) {
            $results = array_merge($results, $this->buildResultsFromBlock($block));
        }

        return $results;
    }

    /**
     * Build detection results from a single block.
     *
     * @param \Google\Cloud\Vision\V1\Block $block
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResultsFromBlock($block): array
    {
        $results = [];
        $paragraphs = $block->getParagraphs();

        if (!$paragraphs) {
            return $results;
        }

        foreach ($paragraphs as $paragraph) {
            $results = array_merge($results, $this->buildResultsFromParagraph($paragraph, $block));
        }

        return $results;
    }

    /**
     * Build detection results from a single paragraph.
     *
     * @param \Google\Cloud\Vision\V1\Paragraph $paragraph
     * @param \Google\Cloud\Vision\V1\Block     $block
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResultsFromParagraph($paragraph, $block): array
    {
        $results = [];
        $words = $paragraph->getWords();

        if (!$words) {
            return $results;
        }

        foreach ($words as $word) {
            $results[] = $this->buildWordResult($word, $block);
        }

        return $results;
    }

    /**
     * Build a single word detection result.
     *
     * @param \Google\Cloud\Vision\V1\Word  $word
     * @param \Google\Cloud\Vision\V1\Block $block
     *
     * @return array<string, mixed>
     */
    private function buildWordResult($word, $block): array
    {
        $symbols = $word->getSymbols();
        $wordText = '';

        if ($symbols) {
            foreach ($symbols as $symbol) {
                $wordText .= $symbol->getText();
            }
        }

        $boundingBox = $word->getBoundingBox();
        $vertices = [];

        if ($boundingBox) {
            foreach ($boundingBox->getVertices() as $vertex) {
                $vertices[] = [
                    'x' => $vertex->getX(),
                    'y' => $vertex->getY(),
                ];
            }
        }

        $confidence = $block->getConfidence() ?? 0.9;

        return [
            'text' => $wordText,
            'confidence' => $confidence,
            'boundingBox' => $vertices,
        ];
    }

    /**
     * Build detection results from text annotations.
     *
     * @param iterable<\Google\Cloud\Vision\V1\EntityAnnotation> $textAnnotations
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResultsFromTextAnnotations(iterable $textAnnotations): array
    {
        $results = [];

        foreach ($textAnnotations as $annotation) {
            $boundingPoly = $annotation->getBoundingPoly();
            $vertices = [];

            if ($boundingPoly) {
                foreach ($boundingPoly->getVertices() as $vertex) {
                    $vertices[] = [
                        'x' => $vertex->getX(),
                        'y' => $vertex->getY(),
                    ];
                }
            }

            $results[] = [
                'text' => $annotation->getDescription() ?? '',
                'confidence' => 0.9,
                'boundingBox' => $vertices,
            ];
        }

        return $results;
    }

    /**
     * Extract primary language code from full text annotation.
     *
     * @param \Google\Cloud\Vision\V1\TextAnnotation $fullTextAnnotation
     *
     * @return string|null
     */
    private function extractLanguageFromFullTextAnnotation($fullTextAnnotation): ?string
    {
        $pages = $fullTextAnnotation->getPages();

        if (!$pages || count($pages) === 0) {
            return null;
        }

        $page = $pages[0];
        $property = $page->getProperty();

        if ($property) {
            $detectedLanguages = $property->getDetectedLanguages();

            if ($detectedLanguages && count($detectedLanguages) > 0) {
                return $detectedLanguages[0]->getLanguageCode();
            }
        }

        $blocks = $page->getBlocks();

        if ($blocks && count($blocks) > 0) {
            $block = $blocks[0];
            $blockProperty = $block->getProperty();

            if ($blockProperty) {
                $detectedLanguages = $blockProperty->getDetectedLanguages();

                if ($detectedLanguages && count($detectedLanguages) > 0) {
                    return $detectedLanguages[0]->getLanguageCode();
                }
            }
        }

        return null;
    }
}
