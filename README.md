# PDF to JSON Extractor API

A small FastAPI service that extracts structured listing information from Japanese real-estate PDFs and images using Google GenAI (Gemini) and local PDF processing.
It can also scrape supported Japanese property web pages and return English JSON.

## Features
- Upload PDF or image files and receive a JSON schema with extracted fields
- Submit a Japanese property page URL and receive English listing JSON
- Optionally use Google Vertex AI or API Key for the Google GenAI (Gemini) model
- OCR and text-extraction via local tools; outputs saved to `outputs/`

## Requirements
- Python 3.10+
- Git
- A Google GenAI credential: either `GOOGLE_API_KEY` or Vertex AI settings (`GOOGLE_GENAI_USE_VERTEXAI`, `GOOGLE_CLOUD_PROJECT`, `GOOGLE_CLOUD_LOCATION`).

## Quickstart

1. Create and activate a virtual environment

```bash
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
playwright install
```

2. Create a `.env` file in the project root with one of the following options:

API key example:

```text
GOOGLE_API_KEY=your_api_key_here
GEMINI_MODEL=gemini-2.5-flash
```

Vertex AI example:

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

4. Health check

```bash
curl http://localhost:8000/healthz
```

5. Extract from a file

```bash
curl -X POST "http://localhost:8000/extract" -F "file=@/path/to/file.pdf"
```

6. Extract from a Japanese property web URL

```bash
curl -X POST "http://localhost:8000/extract/url" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://suumo.jp/ms/chuko/tokyo/sc_minato/nc_78986958/"}'
```

7. Extract multiple URLs

```bash
curl -X POST "http://localhost:8000/extract/url-batch" \
  -H "Content-Type: application/json" \
  -d '{"urls":["https://suumo.jp/ms/chuko/tokyo/sc_minato/nc_78986958/"]}'
```

## Outputs
- Successful extractions are written to `outputs/{requestId}.json`.

## Tests

Run the project's tests with:

```bash
pytest -q
```

## Docker

The repository contains a `Dockerfile` and `docker-compose.yml`. You may use Docker if you prefer; ensure your environment variables are supplied to the container.

## Configuration
- See `app/core/config.py` for configurable options (locale, model name, MAX_PAGES, etc.).
- The URL scraper is loaded from `../intelligent-web-scrapper` by default. Set `WEB_SCRAPER_DIR=/path/to/intelligent-web-scrapper` if that folder lives somewhere else.

## Troubleshooting
- If you see errors about missing Google credentials, set the environment variables in `.env` or export them in the shell.
- If model responses are empty or invalid JSON, check connectivity and that `GEMINI_MODEL` is correct.

## License
This project is provided as-is. Check repository license for details.
