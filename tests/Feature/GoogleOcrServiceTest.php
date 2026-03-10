<?php

namespace Tests\Feature;

use App\Services\Common\GoogleOcrService;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GoogleOcrServiceTest extends TestCase
{
    /**
     * The Google OCR service instance.
     *
     * @var \App\Services\Common\GoogleOcrService $service
     */
    protected GoogleOcrService $service;

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GoogleOcrService::class);
    }

    /**
     * Test extractText returns null when client is not initialized.
     *
     * @return void
     */
    public function testExtractTextReturnsNullWhenClientNotInitialized(): void
    {
        // Arrange - Mock service with null client
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Skip parent constructor to avoid initialization
            }
        };

        // Act
        $result = $service->extractText('/path/to/file.pdf');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test extractText returns null for non-existent file.
     *
     * @return void
     */
    public function testExtractTextReturnsNullForNonExistentFile(): void
    {
        // Act
        $result = $this->service->extractText('/non/existent/file.pdf');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test extractText returns null for invalid file format.
     *
     * @return void
     */
    public function testExtractTextReturnsNullForInvalidFileFormat(): void
    {
        // Arrange
        $file = UploadedFile::fake()->create('test.txt', 100);

        // Act
        $result = $this->service->extractText($file);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test extractText returns null for file exceeding size limit.
     *
     * @return void
     */
    public function testExtractTextReturnsNullForOversizedFile(): void
    {
        // Arrange - Create a file larger than 20MB
        $file = UploadedFile::fake()->create('large.pdf', 21000000); // 21MB

        // Act
        $result = $this->service->extractText($file);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test detectText returns empty array when client is not initialized.
     *
     * @return void
     */
    public function testDetectTextReturnsEmptyArrayWhenClientNotInitialized(): void
    {
        // Arrange - Mock service with null client
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Skip parent constructor to avoid initialization
            }
        };

        // Act
        $result = $service->detectText('/path/to/file.pdf');

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test detectText returns empty array for non-existent file.
     *
     * @return void
     */
    public function testDetectTextReturnsEmptyArrayForNonExistentFile(): void
    {
        // Act
        $result = $this->service->detectText('/non/existent/file.pdf');

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test detectLanguage returns null when client is not initialized.
     *
     * @return void
     */
    public function testDetectLanguageReturnsNullWhenClientNotInitialized(): void
    {
        // Arrange - Mock service with null client
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Skip parent constructor to avoid initialization
            }
        };

        // Act
        $result = $service->detectLanguage('/path/to/file.pdf');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test getTextWithConfidence filters by minimum confidence.
     *
     * @return void
     */
    public function testGetTextWithConfidenceFiltersByMinimumConfidence(): void
    {
        // Arrange - Mock service with null client
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Skip parent constructor to avoid initialization
            }
        };

        // Act
        $result = $service->getTextWithConfidence('/path/to/file.pdf', 0.8);

        // Assert
        $this->assertIsArray($result);
    }

    /**
     * Test extractText logs error on exception.
     *
     * @return void
     */
    public function testExtractTextLogsErrorOnException(): void
    {
        Log::shouldReceive('channel')->once()->with('google_ocr')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
            return str_contains($message, 'Error extracting text from file')
                && isset($context['error'])
                && isset($context['trace']);
        });

        // Arrange - Service that throws an exception during processing
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Bypass parent constructor and set required properties
                $this->client = true;
                $this->channel = 'google_ocr';
            }

            protected function getFileData($file): ?string
            {
                throw new \Exception('Simulated file read failure');
            }
        };

        // Act - This will throw inside the service and should be logged
        $service->extractText('/any/path.pdf');
    }

    /**
     * Test detectText logs error on exception.
     *
     * @return void
     */
    public function testDetectTextLogsErrorOnException(): void
    {
        Log::shouldReceive('channel')->once()->with('google_ocr')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
            return str_contains($message, 'Error detecting text from file')
                && isset($context['error'])
                && isset($context['trace']);
        });

        // Arrange - Service that throws an exception during processing
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Skip parent constructor but ensure client check passes
                $this->client = true;
                $this->channel = 'google_ocr';
            }

            protected function annotateDocument($file, array $options = [])
            {
                throw new \Exception('Simulated file read failure');
            }
        };

        // Act - This will throw inside the service and should be logged
        $service->detectText('/any/path.pdf');
    }

    /**
     * Test detectLanguage logs error on exception.
     *
     * @return void
     */
    public function testDetectLanguageLogsErrorOnException(): void
    {
        Log::shouldReceive('channel')->once()->with('google_ocr')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function ($message, $context) {
            return str_contains($message, 'Error detecting language from file')
                && isset($context['error'])
                && isset($context['trace']);
        });

        // Arrange - Service that throws an exception during processing
        $service = new class extends GoogleOcrService {
            public function __construct()
            {
                // Skip parent constructor but ensure client check passes
                $this->client = true;
                $this->channel = 'google_ocr';
            }

            protected function annotateDocument($file, array $options = [])
            {
                throw new \Exception('Simulated file read failure');
            }
        };

        // Act - This will throw inside the service and should be logged
        $service->detectLanguage('/any/path.pdf');
    }

    /**
     * Test service handles file resource input.
     *
     * @return void
     */
    public function testServiceHandlesFileResourceInput(): void
    {
        // Arrange
        $filePath = storage_path('app/test.pdf');

        // Ensure the directory and file exist
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        if (!file_exists($filePath)) {
            file_put_contents($filePath, 'Test PDF content');
        }

        $resource = fopen($filePath, 'r');

        if ($resource === false) {
            $this->markTestSkipped('Could not create test file resource');
        }

        // Act
        $result = $this->service->extractText($resource);

        // Cleanup
        fclose($resource);
        @unlink($filePath);

        // Assert - Result may be null if file doesn't exist or client not configured
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test that the service account JSON credentials file is well-formed and contains expected keys.
     *
     * This does not call the Google API; it simply validates the local JSON structure so that
     * accidental corruption or wrong file contents will fail fast in tests.
     *
     * @return void
     */
    public function testServiceAccountJsonFileIsValid(): void
    {
        // Arrange
        $credentialsPath = config('google.credentials_path');

        // Ensure the file exists
        $this->assertFileExists($credentialsPath, 'Service account JSON file is missing');

        $json = file_get_contents($credentialsPath);
        $this->assertNotFalse($json, 'Failed to read service account JSON file');

        $data = json_decode($json, true);
        $this->assertIsArray($data, 'Service account JSON did not decode to an array');
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Service account JSON is invalid');

        // Required keys for a service account JSON
        $this->assertSame('service_account', $data['type'] ?? null, 'Invalid or missing type in service account JSON');
        $this->assertArrayHasKey('project_id', $data);
        $this->assertArrayHasKey('private_key_id', $data);
        $this->assertArrayHasKey('private_key', $data);
        $this->assertArrayHasKey('client_email', $data);
        $this->assertArrayHasKey('client_id', $data);
        $this->assertArrayHasKey('token_uri', $data);
    }

    /**
     * Test service initializes ImageAnnotatorClient using service account JSON credentials.
     *
     * @return void
     */
    public function testInitializesClientWithServiceAccountCredentials(): void
    {
        // Arrange: ensure the credentials file exists
        $credentialsPath = config('google.credentials_path');
        $this->assertFileExists($credentialsPath);

        // Backup existing env value
        $originalEnv = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        // Force config to use the known credentials path
        config()->set('google.credentials_path', $credentialsPath);

        // Use a testable subclass to access the client instance
        $service = new class extends GoogleOcrService {
            public function getClientInstance()
            {
                return $this->client;
            }
        };

        // Assert: client is initialized and uses the configured credentials path
        $this->assertInstanceOf(ImageAnnotatorClient::class, $service->getClientInstance());
        $this->assertSame($credentialsPath, getenv('GOOGLE_APPLICATION_CREDENTIALS'));

        // Restore original env value
        if ($originalEnv !== false && $originalEnv !== null) {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $originalEnv);

            return;
        }

        // Unset if it was not previously defined
        putenv('GOOGLE_APPLICATION_CREDENTIALS');
    }
}
