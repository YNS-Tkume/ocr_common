<?php

use App\Http\Controllers\Common\OcrController;
use Illuminate\Support\Facades\Route;

Route::post('/ocr/extract', [OcrController::class, 'extract'])
    ->name('api.ocr.extract');
