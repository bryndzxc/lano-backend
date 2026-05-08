<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicVision
{
    private const PROMPT = <<<'TXT'
You will be shown a photo of a paper receipt. Extract these fields and return ONLY valid JSON, no prose, no code fences:

{
  "storeName": string,
  "purchaseDate": "YYYY-MM-DD" or null,
  "total": number or null,
  "items": [{ "name": string, "quantity": number, "price": number }],
  "warrantyExpiresAt": "YYYY-MM-DD" or null,
  "warrantyMonths": number or null,
  "returnDeadlineAt": "YYYY-MM-DD" or null,
  "returnDays": number or null
}

Rules:
- If a field is unreadable, use null.
- Do NOT include currency. Ignore any currency symbols on the receipt — the client handles currency separately.
- Date: convert any format to ISO YYYY-MM-DD.
- total: the final amount paid (after taxes/discounts). Numbers only, no currency symbols.
- Items: only include line items that are clearly readable. Skip if uncertain.
- Quantity defaults to 1 if not specified.
- warrantyExpiresAt: an EXPLICIT warranty end date if the receipt prints one (e.g., "Warranty valid until 2026-08-14"). null if no explicit date is printed.
- warrantyMonths: warranty DURATION in months as an integer if the receipt states a length (e.g., "1 year" -> 12, "2 years" -> 24, "12 months" -> 12). null if not stated. Lifetime / unclear -> null. Do not compute this from purchaseDate — only extract what is explicitly written.
- returnDeadlineAt: an EXPLICIT return-window end date if printed (e.g., "Return by 2026-08-28"). null if not printed.
- returnDays: return-window LENGTH in days if stated (e.g., "Returns within 14 days" -> 14, "30-day return" -> 30). null if not stated.
- Both forms can be returned together if the receipt prints both. Otherwise return whichever applies and null for the other.
TXT;

    public function extractFromBase64(string $imageBase64, string $mimeType = 'image/jpeg'): array
    {
        $config = config('services.anthropic');
        $apiKey = $config['api_key'] ?? null;

        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured on the server.');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $config['version'],
                'content-type' => 'application/json',
            ])->timeout(45)->post($config['endpoint'], [
                'model' => $config['vision_model'],
                'max_tokens' => $config['max_tokens'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $imageBase64,
                            ],
                        ],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Anthropic: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $snippet = mb_substr((string) $response->body(), 0, 200);
            throw new RuntimeException("Claude API {$response->status()}: {$snippet}");
        }

        $payload = $response->json();
        if (! empty($payload['error']['message'])) {
            throw new RuntimeException('Claude API error: '.$payload['error']['message']);
        }

        $text = collect($payload['content'] ?? [])
            ->firstWhere('type', 'text')['text'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Claude returned no text content.');
        }

        return $this->parseExtraction($text);
    }

    private function parseExtraction(string $text): array
    {
        $cleaned = $this->stripCodeFence(trim($text));
        $parsed = json_decode($cleaned, true);

        if (! is_array($parsed)) {
            throw new RuntimeException(
                'Claude returned invalid JSON: '.mb_substr($cleaned, 0, 120)
            );
        }

        return [
            'storeName' => is_string($parsed['storeName'] ?? null) ? $parsed['storeName'] : '',
            'purchaseDate' => $this->isoDate($parsed['purchaseDate'] ?? null),
            'total' => $this->finiteNumber($parsed['total'] ?? null),
            'currency' => is_string($parsed['currency'] ?? null)
                && preg_match('/^[A-Za-z]{3}$/', $parsed['currency'])
                ? strtoupper($parsed['currency'])
                : null,
            'items' => $this->normalizeItems($parsed['items'] ?? []),
            'warrantyExpiresAt' => $this->isoDate($parsed['warrantyExpiresAt'] ?? null),
            'warrantyMonths' => $this->positiveInt($parsed['warrantyMonths'] ?? null),
            'returnDeadlineAt' => $this->isoDate($parsed['returnDeadlineAt'] ?? null),
            'returnDays' => $this->positiveInt($parsed['returnDays'] ?? null),
        ];
    }

    private function stripCodeFence(string $text): string
    {
        if (! str_starts_with($text, '```')) {
            return $text;
        }
        $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text) ?? $text;
        return preg_replace('/\s*```\s*$/', '', $text) ?? $text;
    }

    private function isoDate(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)
            ? $raw
            : null;
    }

    private function finiteNumber(mixed $raw): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }
        $value = (float) $raw;
        return is_finite($value) ? $value : null;
    }

    private function positiveInt(mixed $raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }
        $value = (int) round((float) $raw);
        return $value > 0 ? $value : null;
    }

    private function normalizeItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
            if ($name === '') {
                continue;
            }
            $quantity = is_numeric($item['quantity'] ?? null)
                ? (float) $item['quantity']
                : 1.0;
            $price = is_numeric($item['price'] ?? null)
                ? (float) $item['price']
                : 0.0;
            $out[] = [
                'name' => $name,
                'quantity' => $quantity,
                'price' => $price,
            ];
        }
        return $out;
    }
}
