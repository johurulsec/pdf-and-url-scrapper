# PHP PDF to JSON Extractor API

This is a PHP port of the FastAPI service for Hostinger-style PHP hosting.

It keeps the same public API shape as the Python service, but it is not a
line-for-line conversion. The Python code uses Python-only packages such as
FastAPI, PyMuPDF, Pydantic, PaddleOCR/Tesseract wrappers, and Playwright. The
PHP version replaces those parts with PHP routing and Gemini REST calls.

## Requirements

- PHP 8.1+
- PHP cURL extension
- PHP DOM/XML extension
- PHP mbstring extension
- A Google Gemini API key
- Optional: Google Translate API key
- Optional: Google Maps API key

## Setup

1. Upload `php-app` to your hosting account.
2. Point the domain document root to `php-app/public`.
3. Copy the environment template:

```bash
cp .env.example .env
```

4. Edit `php-app/.env`:

```text
GOOGLE_API_KEY=your_api_key_here
GEMINI_MODEL=gemini-2.5-flash
INCLUDE_OCR_HINT=true
AUTO_TRANSLATE_ALL_TO_EN=true
OUTPUT_ENGLISH_ONLY=true
PRESERVE_ORIGINAL_JA=false
REMOTE_FETCH_URL_TEMPLATE=
REMOTE_FETCH_API_KEY=
REMOTE_FETCH_API_KEY_HEADER=
REMOTE_FETCH_JSON_FIELD=
PREFER_BROWSER_SCRAPER_FOR_RAKUMACHI=false
```

5. Make `php-app/outputs` writable by PHP.

## Local Run On Ubuntu

```bash
sudo apt update
sudo apt install php-cli php-curl php-xml php-mbstring
cd php-app
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Open:

```text
http://127.0.0.1:8080/healthz
```

## Endpoints

- `GET /healthz`
- `GET /health/browser-scraper`
- `POST /extract` with multipart form field `file`
- `POST /extract/url` with JSON body `{ "url": "https://example.com" }`
- `POST /extract/url-batch` with JSON body `{ "urls": ["https://example.com"] }`
- `POST /debug/url` with JSON body `{ "url": "https://example.com" }` in local/dev mode
- `GET /download/{request_id}`

## Notes

The Python version rasterizes PDFs with PyMuPDF before sending images to Gemini.
This PHP version sends PDF/image bytes directly to Gemini REST using inline
media. When the server has `pdftotext`, PHP also extracts selectable PDF text as
a Gemini hint and deterministic fallback. On shared hosting without `pdftotext`,
the app still works through Gemini inline media, but scanned/image-only PDFs rely
more heavily on Gemini.

Feature comparison:

- Same: `/healthz`, `/extract`, `/extract/url`, `/extract/url-batch`, `/download/{request_id}`.
- Same: Gemini-based JSON extraction, normalization, geocoding, translation, saved output JSON.
- Different: no FastAPI/Pydantic because this is plain PHP.
- Different: no PyMuPDF PDF rasterization on shared hosting; PDFs are sent directly to Gemini.
- Different: no Python Playwright scraper; URL extraction uses browser-like PHP cURL plus HTML/JSON-LD/table text extraction. Sites that require JavaScript rendering or block hosting-provider IPs may still need a PHP-callable remote scraping API.
- Unsupported in this PHP port: Vertex AI mode via `GOOGLE_GENAI_USE_VERTEXAI`.

For English-only JSON, keep `AUTO_TRANSLATE_ALL_TO_EN=true`,
`OUTPUT_ENGLISH_ONLY=true`, and add a valid `GOOGLE_TRANSLATE_API_KEY`.
If `GOOGLE_TRANSLATE_API_KEY` is empty, Japanese values cannot be translated and
will remain unchanged.

## Rakumachi / Hostinger PHP Fallback

Some Rakumachi pages block plain PHP cURL with `403`. Hostinger shared hosting
cannot run a local Python Playwright browser service, so keep the Python browser
fallback disabled and use a remote scraping API that your PHP app can call over
HTTPS.

In `php-app/.env`:

```text
PREFER_BROWSER_SCRAPER_FOR_RAKUMACHI=false
BROWSER_SCRAPER_URL=
BROWSER_SCRAPER_API_KEY=
```

If your scraping API uses all parameters in the URL, configure:

```text
REMOTE_FETCH_URL_TEMPLATE=https://example-scraping-api.test/fetch?api_key={api_key}&url={url}
REMOTE_FETCH_API_KEY=your_scraping_api_key
REMOTE_FETCH_API_KEY_HEADER=
REMOTE_FETCH_JSON_FIELD=
```

If your scraping API returns JSON instead of raw HTML/text, set the response
field path:

```text
REMOTE_FETCH_JSON_FIELD=html
```

Supported template placeholders are `{url}` for URL-encoded target URL,
`{url_raw}` for the raw target URL, and `{api_key}` for the URL-encoded remote
fetch key.
