<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OcrReceiptRequest;
use App\Services\AnthropicVision;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OcrController extends Controller
{
    public function __construct(private readonly AnthropicVision $vision)
    {
    }

    public function receipt(OcrReceiptRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $mime = $file->getMimeType() ?: 'image/jpeg';
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        try {
            $extracted = $this->vision->extractFromBase64($base64, $mime);
        } catch (RuntimeException $e) {
            Log::warning('OCR extraction failed', [
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'extraction_failed',
                'message' => $e->getMessage(),
            ], 502);
        } catch (Throwable $e) {
            Log::error('OCR unexpected failure', [
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'server_error',
                'message' => 'Unexpected error processing the receipt.',
            ], 500);
        }

        return response()->json($extracted);
    }
}
