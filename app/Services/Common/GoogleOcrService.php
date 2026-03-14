<?php

namespace App\Services\Common;

use Google\Cloud\Vision\V1\{
    AnnotateFileRequest,
    AnnotateImageRequest,
    BatchAnnotateFilesRequest,
    BatchAnnotateImagesRequest,
    Feature,
    Feature\Type,
    Image,
    InputConfig,
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
     * Detection type for Vision API (single, for backward compatibility).
     *
     * @var int $detectionType
     */
    protected int $detectionType;

    /**
     * Detection types for Vision API (multiple).
     *
     * @var array<int, int>
     */
    protected array $detectionTypes;

    /**
     * Valid detection types (all Vision API feature types).
     *
     * @var array<string, int>
     */
    private const VALID_DETECTION_TYPES = [
        'TYPE_UNSPECIFIED' => Type::TYPE_UNSPECIFIED,
        'FACE_DETECTION' => Type::FACE_DETECTION,
        'LANDMARK_DETECTION' => Type::LANDMARK_DETECTION,
        'LOGO_DETECTION' => Type::LOGO_DETECTION,
        'LABEL_DETECTION' => Type::LABEL_DETECTION,
        'TEXT_DETECTION' => Type::TEXT_DETECTION,
        'DOCUMENT_TEXT_DETECTION' => Type::DOCUMENT_TEXT_DETECTION,
        'SAFE_SEARCH_DETECTION' => Type::SAFE_SEARCH_DETECTION,
        'IMAGE_PROPERTIES' => Type::IMAGE_PROPERTIES,
        'CROP_HINTS' => Type::CROP_HINTS,
        'WEB_DETECTION' => Type::WEB_DETECTION,
        'PRODUCT_SEARCH' => Type::PRODUCT_SEARCH,
        'OBJECT_LOCALIZATION' => Type::OBJECT_LOCALIZATION,
    ];

    /**
     * Constructor initializing GoogleOcrService.
     */
    public function __construct()
    {
        $this->channel = (string) config('google.log_channel', 'google_ocr');
        $this->maxFileSize = (int) config('google.max_file_size', 20 * 1024 * 1024);
        $this->defaultLanguage = (string) config('google.default_language', 'ja');
        $this->detectionTypes = $this->resolveDetectionTypes(config('google.detection_types', ['DOCUMENT_TEXT_DETECTION']));
        $this->detectionType = $this->detectionTypes[0] ?? Type::DOCUMENT_TEXT_DETECTION;

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
            $payload = $this->getValidatedPayload($file);
            if ($payload === null) {
                return null;
            }

            $fileData = $payload['fileData'];
            $mimeType = $payload['mimeType'];

            if ($this->isPdfMimeType($mimeType)) {
                $responses = $this->annotatePdfWithTypes($fileData, [$this->detectionType], $options);
                if (empty($responses)) {
                    return null;
                }

                $texts = [];
                foreach ($responses as $response) {
                    $fullTextAnnotation = $response->getFullTextAnnotation();
                    if ($fullTextAnnotation) {
                        $text = $fullTextAnnotation->getText();
                        if (is_string($text) && trim($text) !== '') {
                            $texts[] = $text;
                        }
                    }
                }

                return empty($texts) ? null : implode("\n\n", $texts);
            }

            $image = (new Image())->setContent($fileData);
            $feature = (new Feature())->setType($this->detectionType);

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
            $payload = $this->getValidatedPayload($file);
            if ($payload === null) {
                return [];
            }

            $fileData = $payload['fileData'];
            $mimeType = $payload['mimeType'];

            if ($this->isPdfMimeType($mimeType)) {
                $responses = $this->annotatePdfWithTypes($fileData, [$this->detectionType], $options);
                if (empty($responses)) {
                    return [];
                }

                $results = [];
                foreach ($responses as $response) {
                    $pageResults = [];
                    $fullTextAnnotation = $response->getFullTextAnnotation();
                    if ($fullTextAnnotation) {
                        $pageResults = $this->buildResultsFromFullTextAnnotation($fullTextAnnotation);
                    }
                    if (empty($pageResults)) {
                        $pageResults = $this->buildResultsFromTextAnnotations(
                            $response->getTextAnnotations() ?? []
                        );
                    }
                    $results = array_merge($results, $pageResults);
                }

                return $results;
            }

            $annotateResponse = $this->annotateDocumentFromData($fileData, $options);

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
            $payload = $this->getValidatedPayload($file);
            if ($payload === null) {
                return null;
            }

            $fileData = $payload['fileData'];
            $mimeType = $payload['mimeType'];

            if ($this->isPdfMimeType($mimeType)) {
                $responses = $this->annotatePdfWithTypes($fileData, [$this->detectionType]);
                if (empty($responses)) {
                    return null;
                }

                foreach ($responses as $response) {
                    $fullTextAnnotation = $response->getFullTextAnnotation();
                    if ($fullTextAnnotation) {
                        $language = $this->extractLanguageFromFullTextAnnotation($fullTextAnnotation);
                        if ($language !== null) {
                            return $language;
                        }
                    }
                }

                return $this->defaultLanguage;
            }

            $annotateResponse = $this->annotateDocumentFromData($fileData);
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
     * Detect with multiple configured detection types.
     * Uses GOOGLE_OCR_DETECTION_TYPE (comma or pipe separated) for detection types.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function detectWithMultipleTypes($file, array $options = []): array
    {
        if (!$this->client) {
            return [];
        }

        try {
            $payload = $this->getValidatedPayload($file);
            if ($payload === null) {
                return [];
            }

            $fileData = $payload['fileData'];
            $mimeType = $payload['mimeType'];

            if ($this->isPdfMimeType($mimeType)) {
                $responses = $this->annotatePdfWithTypes($fileData, $this->detectionTypes, $options);
                if (empty($responses)) {
                    return [];
                }

                $result = [];
                foreach ($responses as $response) {
                    $pageResult = $this->buildDetectionResultFromResponse($response);
                    $result = $this->mergeDetectionResults($result, $pageResult);
                }

                return $this->applyResultMapping($result);
            }

            $annotateResponse = $this->annotateWithMultipleTypesFromData($fileData, $options);
            if ($annotateResponse === null) {
                return [];
            }

            $result = $this->buildDetectionResultFromResponse($annotateResponse);

            return $this->applyResultMapping($result);
        } catch (\Google\ApiCore\ApiException $error) {
            $this->logError('Error detecting with multiple types', $error, $file);

            return [];
        } catch (\Exception $error) {
            $this->logError('Error detecting with multiple types', $error, $file);

            return [];
        }
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

        return $this->annotateDocumentFromData($fileData, $options);
    }

    /**
     * Execute a Vision image annotation request from file content.
     *
     * @param string $fileData
     * @param array<string, mixed> $options
     *
     * @return \Google\Cloud\Vision\V1\AnnotateImageResponse|null
     */
    protected function annotateDocumentFromData(string $fileData, array $options = [])
    {
        if (!$this->client instanceof ImageAnnotatorClient) {
            return null;
        }

        $image = (new Image())->setContent($fileData);
        $feature = (new Feature())->setType($this->detectionType);

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
     * Execute Vision annotation request with multiple detection types.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     * @param array<string, mixed> $options
     *
     * @return \Google\Cloud\Vision\V1\AnnotateImageResponse|null
     */
    protected function annotateWithMultipleTypes($file, array $options = [])
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

        return $this->annotateWithMultipleTypesFromData($fileData, $options);
    }

    /**
     * Execute Vision image annotation request with multiple detection types from file content.
     *
     * @param string $fileData
     * @param array<string, mixed> $options
     *
     * @return \Google\Cloud\Vision\V1\AnnotateImageResponse|null
     */
    protected function annotateWithMultipleTypesFromData(string $fileData, array $options = [])
    {
        if (!$this->client instanceof ImageAnnotatorClient) {
            return null;
        }

        $features = [];
        foreach ($this->detectionTypes as $type) {
            $features[] = (new Feature())->setType($type);
        }

        if (empty($features)) {
            return null;
        }

        $image = (new Image())->setContent($fileData);
        $annotateRequest = (new AnnotateImageRequest())
            ->setImage($image)
            ->setFeatures($features);

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
     * Execute Vision file annotation request for PDF and return page responses.
     *
     * @param string $fileData
     * @param array<int, int> $featureTypes
     * @param array<string, mixed> $options
     *
     * @return array<int, \Google\Cloud\Vision\V1\AnnotateImageResponse>
     */
    protected function annotatePdfWithTypes(string $fileData, array $featureTypes, array $options = []): array
    {
        if (!$this->client instanceof ImageAnnotatorClient) {
            return [];
        }

        $features = [];
        foreach ($featureTypes as $type) {
            $features[] = (new Feature())->setType($type);
        }

        if (empty($features)) {
            return [];
        }

        $inputConfig = (new InputConfig())
            ->setMimeType('application/pdf')
            ->setContent($fileData);

        $fileRequest = (new AnnotateFileRequest())
            ->setInputConfig($inputConfig)
            ->setFeatures($features);

        if (isset($options['pages']) && is_array($options['pages'])) {
            $fileRequest->setPages($options['pages']);
        }

        $batchRequest = (new BatchAnnotateFilesRequest())
            ->setRequests([$fileRequest]);

        $batchResponse = $this->client->batchAnnotateFiles($batchRequest);
        $fileResponses = $batchResponse->getResponses();
        $firstFileResponse = $fileResponses[0] ?? null;

        if ($firstFileResponse === null) {
            return [];
        }

        $pageResponses = $firstFileResponse->getResponses();

        if (!is_iterable($pageResponses)) {
            return [];
        }

        $results = [];
        foreach ($pageResponses as $response) {
            $results[] = $response;
        }

        return $results;
    }

    /**
     * Get validated payload including content and MIME type.
     *
     * @param string|\Illuminate\Http\UploadedFile|resource $file
     *
     * @return array{fileData: string, mimeType: string}|null
     */
    protected function getValidatedPayload($file): ?array
    {
        $fileData = $this->getFileData($file);
        if ($fileData === null) {
            return null;
        }

        if (!$this->validateFile($fileData, $file)) {
            return null;
        }

        return [
            'fileData' => $fileData,
            'mimeType' => $this->getMimeType($file, $fileData),
        ];
    }

    /**
     * @param string $mimeType
     *
     * @return bool
     */
    protected function isPdfMimeType(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    /**
     * Build structured detection result from AnnotateImageResponse.
     *
     * @param \Google\Cloud\Vision\V1\AnnotateImageResponse $response
     *
     * @return array<string, mixed>
     */
    protected function buildDetectionResultFromResponse($response): array
    {
        $result = [];

        $fullTextAnnotation = $response->getFullTextAnnotation();
        if ($fullTextAnnotation) {
            $result['text'] = $fullTextAnnotation->getText();
            $result['text_detection'] = $this->buildResultsFromFullTextAnnotation($fullTextAnnotation);
        }

        $labelAnnotations = $response->getLabelAnnotations();
        if ($labelAnnotations && count($labelAnnotations) > 0) {
            $result['labels'] = $this->convertLabelAnnotationsToArray($labelAnnotations);
        }

        $faceAnnotations = $response->getFaceAnnotations();
        if ($faceAnnotations && count($faceAnnotations) > 0) {
            $result['faces'] = $this->convertFaceAnnotationsToArray($faceAnnotations);
        }

        $landmarkAnnotations = $response->getLandmarkAnnotations();
        if ($landmarkAnnotations && count($landmarkAnnotations) > 0) {
            $result['landmarks'] = $this->convertEntityAnnotationsToArray($landmarkAnnotations);
        }

        $logoAnnotations = $response->getLogoAnnotations();
        if ($logoAnnotations && count($logoAnnotations) > 0) {
            $result['logos'] = $this->convertEntityAnnotationsToArray($logoAnnotations);
        }

        $safeSearchAnnotation = $response->getSafeSearchAnnotation();
        if ($safeSearchAnnotation) {
            $result['safe_search'] = [
                'adult' => $this->likelihoodToString($safeSearchAnnotation->getAdult()),
                'spoof' => $this->likelihoodToString($safeSearchAnnotation->getSpoof()),
                'medical' => $this->likelihoodToString($safeSearchAnnotation->getMedical()),
                'violence' => $this->likelihoodToString($safeSearchAnnotation->getViolence()),
                'racy' => $this->likelihoodToString($safeSearchAnnotation->getRacy()),
            ];
        }

        $imageProperties = $response->getImagePropertiesAnnotation();
        if ($imageProperties) {
            $dominantColors = $imageProperties->getDominantColors();
            if ($dominantColors) {
                $colors = [];
                foreach ($dominantColors->getColors() as $colorInfo) {
                    $color = $colorInfo->getColor();
                    if ($color) {
                        $colors[] = [
                            'red' => $color->getRed(),
                            'green' => $color->getGreen(),
                            'blue' => $color->getBlue(),
                            'alpha' => $color->getAlpha(),
                            'score' => $colorInfo->getScore(),
                            'pixel_fraction' => $colorInfo->getPixelFraction(),
                        ];
                    }
                }
                $result['image_properties'] = ['dominant_colors' => $colors];
            }
        }

        $cropHintsAnnotation = $response->getCropHintsAnnotation();
        if ($cropHintsAnnotation) {
            $hints = [];
            foreach ($cropHintsAnnotation->getCropHints() as $hint) {
                $boundingPoly = $hint->getBoundingPoly();
                $vertices = [];
                if ($boundingPoly) {
                    foreach ($boundingPoly->getVertices() as $vertex) {
                        $vertices[] = ['x' => $vertex->getX(), 'y' => $vertex->getY()];
                    }
                }
                $hints[] = [
                    'bounding_poly' => $vertices,
                    'confidence' => $hint->getConfidence(),
                    'importance_fraction' => $hint->getImportanceFraction(),
                ];
            }
            $result['crop_hints'] = $hints;
        }

        $webDetection = $response->getWebDetection();
        if ($webDetection) {
            $webEntities = $webDetection->getWebEntities();
            if ($webEntities && count($webEntities) > 0) {
                $webResult = [];
                foreach ($webEntities as $entity) {
                    $webResult[] = [
                        'entity_id' => $entity->getEntityId(),
                        'score' => $entity->getScore(),
                        'description' => $entity->getDescription(),
                    ];
                }
                $result['web_detection'] = ['entities' => $webResult];
            }
        }

        $localizedObjects = $response->getLocalizedObjectAnnotations();
        if ($localizedObjects && count($localizedObjects) > 0) {
            $result['object_localization'] = $this->convertLocalizedObjectAnnotationsToArray($localizedObjects);
        }

        return $result;
    }

    /**
     * Apply form field mapping to detection result.
     * フォームの入力ボックス・ドロップダウンに割り当てる値を生成し、form_values に追加する。
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected function applyResultMapping(array $result): array
    {
        $formValues = $this->buildFormFieldValues($result);

        if (!empty($formValues)) {
            $result['form_values'] = $formValues;
        }

        return $result;
    }

    /**
     * Merge page-level detection results into a single result.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     *
     * @return array<string, mixed>
     */
    protected function mergeDetectionResults(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($key === 'text' && is_string($value)) {
                $existing = isset($base[$key]) && is_string($base[$key]) ? $base[$key] : '';
                $base[$key] = $existing === '' ? $value : $existing . "\n\n" . $value;
                continue;
            }

            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;
                continue;
            }

            if (is_array($base[$key]) && is_array($value)) {
                $base[$key] = array_merge($base[$key], $value);
                continue;
            }
        }

        return $base;
    }

    /**
     * Build form field values from OCR result using form_field_mapping config.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected function buildFormFieldValues(array $result): array
    {
        $mapping = config('google.form_field_mapping', []);

        if (empty($mapping) || !is_array($mapping)) {
            return [];
        }

        $formValues = [];

        foreach ($mapping as $formFieldName => $sourceConfig) {
            $value = $this->resolveFormFieldValue($result, $formFieldName, $sourceConfig);

            if ($value !== null) {
                $formValues[$formFieldName] = $value;
            }
        }

        return $formValues;
    }

    /**
     * Resolve value for a form field from OCR result.
     *
     * @param array<string, mixed> $result
     * @param string $formFieldName
     * @param mixed $sourceConfig
     *
     * @return mixed
     */
    protected function resolveFormFieldValue(array $result, string $formFieldName, $sourceConfig)
    {
        if (is_string($sourceConfig)) {
            return $this->resolveDirectMapping($result, $sourceConfig);
        }

        if (is_array($sourceConfig)) {
            if (!empty($sourceConfig['pattern']) && !empty($sourceConfig['source'])) {
                return $this->resolvePatternExtraction($result, $sourceConfig);
            }
            if (!empty($sourceConfig['source']) && isset($sourceConfig['property'])) {
                return $this->resolveArrayProperty($result, $sourceConfig);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @param string $ocrKey
     *
     * @return mixed
     */
    protected function resolveDirectMapping(array $result, string $ocrKey)
    {
        $value = $result[$ocrKey] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $config
     *
     * @return string|null
     */
    protected function resolvePatternExtraction(array $result, array $config): ?string
    {
        $sourceText = $result[$config['source']] ?? null;

        if (!is_string($sourceText)) {
            return null;
        }

        $group = $config['group'] ?? 1;
        $matches = [];

        if (preg_match($config['pattern'], $sourceText, $matches) && isset($matches[$group])) {
            return trim($matches[$group]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $config
     *
     * @return mixed
     */
    protected function resolveArrayProperty(array $result, array $config)
    {
        $source = $result[$config['source']] ?? null;

        if (!is_array($source)) {
            return null;
        }

        $property = $config['property'];
        $index = $config['index'] ?? null;
        $join = $config['join'] ?? null;

        $values = [];

        foreach ($source as $item) {
            if (is_array($item) && isset($item[$property])) {
                $values[] = $item[$property];
            }
        }

        if (empty($values)) {
            return null;
        }

        if ($index !== null) {
            return $values[$index] ?? null;
        }

        if ($join !== null) {
            return implode($join, $values);
        }

        return $values[0] ?? null;
    }

    /**
     * @param iterable<\Google\Cloud\Vision\V1\EntityAnnotation> $annotations
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertLabelAnnotationsToArray(iterable $annotations): array
    {
        $result = [];
        foreach ($annotations as $ann) {
            $result[] = [
                'description' => $ann->getDescription(),
                'score' => $ann->getScore(),
                'topicality' => $ann->getTopicality(),
            ];
        }

        return $result;
    }

    /**
     * @param iterable<\Google\Cloud\Vision\V1\FaceAnnotation> $annotations
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertFaceAnnotationsToArray(iterable $annotations): array
    {
        $result = [];
        foreach ($annotations as $ann) {
            $result[] = [
                'detection_confidence' => $ann->getDetectionConfidence(),
                'joy_likelihood' => $this->likelihoodToString($ann->getJoyLikelihood()),
                'sorrow_likelihood' => $this->likelihoodToString($ann->getSorrowLikelihood()),
                'anger_likelihood' => $this->likelihoodToString($ann->getAngerLikelihood()),
                'surprise_likelihood' => $this->likelihoodToString($ann->getSurpriseLikelihood()),
            ];
        }

        return $result;
    }

    /**
     * @param iterable<\Google\Cloud\Vision\V1\EntityAnnotation> $annotations
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertEntityAnnotationsToArray(iterable $annotations): array
    {
        $result = [];
        foreach ($annotations as $ann) {
            $result[] = [
                'description' => $ann->getDescription(),
                'score' => $ann->getScore(),
            ];
        }

        return $result;
    }

    /**
     * @param iterable<\Google\Cloud\Vision\V1\LocalizedObjectAnnotation> $annotations
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertLocalizedObjectAnnotationsToArray(iterable $annotations): array
    {
        $result = [];
        foreach ($annotations as $ann) {
            $vertices = [];
            $boundingPoly = $ann->getBoundingPoly();
            if ($boundingPoly) {
                foreach ($boundingPoly->getVertices() as $vertex) {
                    $vertices[] = ['x' => $vertex->getX(), 'y' => $vertex->getY()];
                }
            }
            $result[] = [
                'name' => $ann->getName(),
                'score' => $ann->getScore(),
                'bounding_poly' => $vertices,
            ];
        }

        return $result;
    }

    /**
     * @param int|null $likelihood
     *
     * @return string
     */
    private function likelihoodToString($likelihood): string
    {
        $map = [
            0 => 'UNKNOWN',
            1 => 'VERY_UNLIKELY',
            2 => 'UNLIKELY',
            3 => 'POSSIBLE',
            4 => 'LIKELY',
            5 => 'VERY_LIKELY',
        ];

        return $map[(int) $likelihood] ?? 'UNKNOWN';
    }

    /**
     * Resolve detection type from config string.
     *
     * @param string $type
     *
     * @return int
     */
    protected function resolveDetectionType(string $type): int
    {
        $normalized = strtoupper($type);

        return self::VALID_DETECTION_TYPES[$normalized] ?? Type::DOCUMENT_TEXT_DETECTION;
    }

    /**
     * Resolve detection types from config array.
     *
     * @param array<int, string> $types
     *
     * @return array<int, int>
     */
    protected function resolveDetectionTypes(array $types): array
    {
        $resolved = [];

        foreach ($types as $type) {
            if (!is_string($type) || $type === '') {
                continue;
            }
            $value = $this->resolveDetectionType($type);
            if (!in_array($value, $resolved, true)) {
                $resolved[] = $value;
            }
        }

        return $resolved ?: [Type::DOCUMENT_TEXT_DETECTION];
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
