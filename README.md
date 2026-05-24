# PDF to JSON Extractor API

A FastAPI service that extracts structured listing data from Japanese real-estate PDFs, images, and supported property web pages.
This project combines local PDF/image extraction with Google GenAI (Gemini) and a web scraper to produce JSON output.

## Key Features
- Upload PDF or image files for structured JSON extraction
- Extract JSON from supported Japanese property web URLs
- Save extracted results to disk in `outputs/`
- Provide download links for saved JSON files
- Supports Google GenAI via API key or Vertex AI

## Requirements
- Python 3.10+
- Git
- A Google GenAI credential:
  - `GOOGLE_API_KEY`, or
  - Vertex AI settings: `GOOGLE_GENAI_USE_VERTEXAI`, `GOOGLE_CLOUD_PROJECT`, `GOOGLE_CLOUD_LOCATION`

## Setup
1. Create and activate a virtual environment

```bash
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
playwright install
```

2. Create a `.env` file in the project root

Example with API key:

```text
GOOGLE_API_KEY=your_api_key_here
GEMINI_MODEL=gemini-2.5-flash
```

Example with Vertex AI:

```text
GOOGLE_GENAI_USE_VERTEXAI=true
GOOGLE_CLOUD_PROJECT=your-gcp-project
GOOGLE_CLOUD_LOCATION=us-central1
GEMINI_MODEL=gemini-2.5-flash
```

3. Start the server

```bash
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

4. Open Swagger UI

Visit `http://localhost:8000/docs` to explore and test the API interactively.

## API Endpoints

### Health check
- `GET /healthz`
- Returns: `{ "status": "ok" }`

### Extract from file
- `POST /extract`
- Form field: `file` (PDF or image)
- Example:

```bash
curl -X POST "http://localhost:8000/extract" -F "file=@/path/to/file.pdf"
```

### Extract from a web URL
- `POST /extract/url`
- JSON body: `{ "url": "https://example.com" }`
- Returns saved JSON metadata plus extracted data
- Example:

```bash
curl -X POST "http://localhost:8000/extract/url" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://suumo.jp/ms/chuko/tokyo/sc_minato/nc_78986958/"}'
```

### Extract multiple URLs
- `POST /extract/url-batch`
- JSON body: `{ "urls": ["https://url1", "https://url2"] }`
- Example:

```bash
curl -X POST "http://localhost:8000/extract/url-batch" \
  -H "Content-Type: application/json" \
  -d '{"urls":["https://suumo.jp/ms/chuko/tokyo/sc_minato/nc_78986958/"]}'
```

### Download saved JSON
- `GET /download/{request_id}`
- Downloads the saved extraction JSON file from `outputs/{request_id}.json`

## Output Files
- Extracted JSON results are saved under `outputs/`
- `requestId` values are returned by `/extract/url` and `/extract/url-batch`
- Use the returned `downloadUrl` to fetch the saved JSON file

## Configuration
- The URL scraper directory defaults to `../intelligent-web-scrapper`
- Override with `WEB_SCRAPER_DIR=/path/to/intelligent-web-scrapper`
- Additional options may be configured in `app/core/config.py`

## Testing

Run tests with:

```bash
pytest -q
```

## Docker

This repository includes a `Dockerfile` and `docker-compose.yml`.
If you use Docker, make sure environment variables are provided to the container.

## Troubleshooting
- Missing Google credentials: verify `.env` or shell exports
- Invalid scraper import: confirm `WEB_SCRAPER_DIR` points to a valid scraper project
- Playwright issues: run `playwright install`

## License
This project is provided as-is. See repository license for details.
