<?php

use App\Http\Controllers\Api\OcrController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')
    ->post('/ocr/receipt', [OcrController::class, 'receipt']);
