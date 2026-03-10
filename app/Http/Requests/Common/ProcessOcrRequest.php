<?php

namespace App\Http\Requests\Common;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for processing OCR files (generic, callable from anywhere).
 */
class ProcessOcrRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $allowedMimes = config('google.ocr_allowed_mime_types', [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/tiff',
            'image/x-tiff',
        ]);
        $maxKilobytes = (int) ceil((int) config('google.max_file_size', 20 * 1024 * 1024) / 1024);

        return [
            'files' => [
                'required',
                'array',
            ],
            'files.*' => [
                'required',
                'file',
                'mimetypes:' . implode(',', $allowedMimes),
                'max:' . $maxKilobytes,
            ],
        ];
    }
}
