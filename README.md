# lano-backend

Server-side proxy for Lano's receipt OCR. Its only job is to keep the Anthropic API key off the mobile client. The Lano Expo app sends a receipt image here, this service forwards it to Claude Haiku (vision), and returns a structured JSON extraction.

## Stack

- PHP 8.2+
- Laravel 13
- Anthropic Claude Haiku 4.5 (single-stage vision + extraction)
- SQLite for sessions / rate-limit storage (no domain data)

## Quickstart (local)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set the Anthropic key in `.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_VISION_MODEL=claude-haiku-4-5
ANTHROPIC_MAX_TOKENS=1024
```

Run the dev server bound to all interfaces (so a phone on the same WiFi can reach it):

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Health check:

```bash
curl http://127.0.0.1:8000/up
```

## API

### `POST /api/ocr/receipt`

Multipart upload. Throttled at 30 requests/minute per IP.

**Request**

| Field | Type | Notes |
|---|---|---|
| `image` | file | required, jpg/jpeg/png/webp/heic, &le; 8 MB |

**200 response**

```json
{
  "storeName": "SM Appliance Center",
  "purchaseDate": "2026-05-05",
  "total": 38995.0,
  "currency": "PHP",
  "items": [
    { "name": "LG 410L Inverter Refrigerator", "quantity": 1, "price": 36995 },
    { "name": "Extended Warranty 2 years", "quantity": 1, "price": 2000 }
  ],
  "warrantyExpiresAt": null,
  "warrantyMonths": 24,
  "returnDeadlineAt": null,
  "returnDays": 7
}
```

Any field that wasn't readable on the receipt is `null` (or `[]` for items, `"PHP"` for currency).

**Error responses**

| Status | Meaning |
|---|---|
| 422 | Image missing or invalid (see Laravel validation message) |
| 429 | Rate limit exceeded |
| 502 | Anthropic call failed (network or model error) |
| 500 | Unexpected server error |

## Configuration

| Env var | Default | Notes |
|---|---|---|
| `ANTHROPIC_API_KEY` | &mdash; | required |
| `ANTHROPIC_VISION_MODEL` | `claude-haiku-4-5` | |
| `ANTHROPIC_MAX_TOKENS` | `1024` | |

CORS lives in `config/cors.php`. `api/*` is currently open to all origins for dev &mdash; tighten `allowed_origins` to the production app's origin (or the Expo dev URL) before deploying publicly.

## Production notes

- The mobile client has `EXPO_PUBLIC_LANO_API_BASE` baked into the APK at build time, so deploy this service to a stable public URL **before** building the production APK.
- Set `APP_DEBUG=false` and `APP_ENV=production` in the deploy environment.
- Rotate `ANTHROPIC_API_KEY` if you ever suspect exposure (chat logs, screenshots, accidental commits).
- Tighten `config/cors.php` `allowed_origins` once the production client URL is known.
- Consider a tighter daily rate limit (Lano plan §9 calls for ~10 scans/day per user) once a user identifier is available &mdash; the current 30/min/IP is basic abuse protection only.

## Project layout (relevant files)

```
app/
  Http/
    Controllers/Api/OcrController.php   # receipt() handler
    Requests/Api/OcrReceiptRequest.php  # validates the upload
  Services/AnthropicVision.php          # Claude vision call + JSON normalization
config/
  cors.php                              # CORS rules (api/* open in dev)
  services.php                          # anthropic.* config block
routes/
  api.php                               # POST /api/ocr/receipt
```
