<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Http\Requests\Common\ProcessOcrRequest;
use App\Services\Common\GoogleOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Generic OCR controller. Callable from anywhere to extract text from files.
 */
class OcrController extends Controller
{
    /**
     * @param \App\Services\Common\GoogleOcrService $ocrService
     */
    public function __construct(
        protected GoogleOcrService $ocrService
    ) {
    }

    /**
     * Extract text from uploaded files using Google OCR.
     *
     * @param \App\Http\Requests\Common\ProcessOcrRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function extract(ProcessOcrRequest $request): JsonResponse
    {
        try {
            $files = $request->file('files', []);
            if (!is_array($files)) {
                $files = $files ? [$files] : [];
            }

            if (empty($files)) {
                return response()->json([
                    'message' => 'No files uploaded',
                    'data' => null,
                ], Response::HTTP_BAD_REQUEST);
            }

            foreach ($files as $file) {
                if (!$file->isValid()) {
                    return response()->json([
                        'message' => 'Invalid file uploaded',
                        'data' => null,
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            $results = [];
            $combinedTexts = [];

            foreach ($files as $file) {
                $text = $this->ocrService->extractText($file);
                $results[] = [
                    'filename' => $file->getClientOriginalName(),
                    'text' => $text ?? '',
                ];
                if ($text !== null && $text !== '') {
                    $combinedTexts[] = $text;
                }
            }

            return response()->json([
                'data' => [
                    'files' => $results,
                    'combined_text' => implode("\n\n", $combinedTexts),
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $exception) {
            Log::error('Error processing OCR files.', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'file_count' => isset($files) ? count($files) : 0,
                'file_names' => isset($files) ? array_map(fn ($file) => $file->getClientOriginalName(), $files) : [],
            ]);

            return response()->json([
                'message' => 'Failed to process OCR files: ' . $exception->getMessage(),
                'data' => null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Detect with multiple configured detection types.
     *
     * @param \App\Http\Requests\Common\ProcessOcrRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function detectMultiple(ProcessOcrRequest $request): JsonResponse
    {
        try {
            $files = $request->file('files', []);
            if (!is_array($files)) {
                $files = $files ? [$files] : [];
            }

            if (empty($files)) {
                return response()->json([
                    'message' => 'No files uploaded',
                    'data' => null,
                ], Response::HTTP_BAD_REQUEST);
            }

            foreach ($files as $file) {
                if (!$file->isValid()) {
                    return response()->json([
                        'message' => 'Invalid file uploaded',
                        'data' => null,
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            $results = [];

            foreach ($files as $file) {
                $detection = $this->ocrService->detectWithMultipleTypes($file);
                $results[] = [
                    'filename' => $file->getClientOriginalName(),
                    'detection' => $detection,
                ];
            }

            return response()->json([
                'data' => [
                    'files' => $results,
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $exception) {
            Log::error('Error processing OCR with multiple types.', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'file_count' => isset($files) ? count($files) : 0,
                'file_names' => isset($files) ? array_map(fn ($file) => $file->getClientOriginalName(), $files) : [],
            ]);

            return response()->json([
                'message' => 'Failed to process: ' . $exception->getMessage(),
                'data' => null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
