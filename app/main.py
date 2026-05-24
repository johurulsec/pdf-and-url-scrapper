import logging, traceback, re, requests, secrets, os, sys, json
from uuid import uuid4
from typing import Optional, List, Dict, Any
from fastapi import FastAPI, UploadFile, File, HTTPException, status
from fastapi.responses import JSONResponse
from pydantic import BaseModel, HttpUrl
from fastapi.responses import FileResponse
from starlette.concurrency import run_in_threadpool

from app.extractors.gemini_listing import run_extraction
from google.genai import errors as genai_errors

log = logging.getLogger("uvicorn.error")
app = FastAPI(title="JP Real Estate PDF→JSON API", version="1.0.0")
OUTPUT_DIR = "outputs"
os.makedirs(OUTPUT_DIR, exist_ok=True)  # Ensure output directory exists
WEB_SCRAPER_DIR = os.getenv(
    "WEB_SCRAPER_DIR",
    os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "intelligent-web-scrapper")),
)

@app.post("/extract")
async def extract_file(file: UploadFile = File(...)):
    data = await file.read()
    if not data:
        raise HTTPException(status_code=400, detail="Empty file.")
    try:
        result = run_extraction(data, file.filename)
        return JSONResponse(content=result)
    except genai_errors.ServerError as e:
        raise HTTPException(
            status_code=503,
            detail="Gemini service is temporarily unavailable. Please retry in a few seconds."
        )
    except Exception as e:
        log.exception("Extraction failed: %s", e)
        raise HTTPException(status_code=500, detail=str(e))


# ---- Optional: Batch by Google Drive URLs ----
class UrlBatch(BaseModel):
    urls: List[str]

class WebUrlRequest(BaseModel):
    url: HttpUrl

class WebUrlBatchRequest(BaseModel):
    urls: List[HttpUrl]

def _extract_web_url(url: str) -> Dict[str, Any]:
    if not os.path.isdir(WEB_SCRAPER_DIR):
        raise RuntimeError(
            f"Web scraper directory was not found. Set WEB_SCRAPER_DIR; current value: {WEB_SCRAPER_DIR}"
        )
    if WEB_SCRAPER_DIR not in sys.path:
        sys.path.insert(0, WEB_SCRAPER_DIR)

    try:
        from scraper import extract_from_url
    except ImportError as exc:
        raise RuntimeError(
            "Web scraper dependencies are missing. Install PDF API requirements, "
            "then run: pip install -r ../intelligent-web-scrapper/requirements.txt && playwright install"
        ) from exc

    return extract_from_url(url)

def _save_result_to_disk(result: Dict[str, Any]) -> str:
    """Save result to disk and return request_id"""
    request_id = str(uuid4())
    file_path = os.path.join(OUTPUT_DIR, f"{request_id}.json")
    with open(file_path, 'w', encoding='utf-8') as f:
        json.dump(result, f, indent=2, ensure_ascii=False)
    return request_id

def _drive_file_id(url: str) -> Optional[str]:
    m = re.search(r"/d/([A-Za-z0-9_-]{20,})/", url)
    return m.group(1) if m else None

def _download_google_drive_file(file_id: str) -> bytes:
    session = requests.Session()
    URL = "https://docs.google.com/uc?export=download"
    r = session.get(URL, params={"id": file_id}, stream=True)
    token = next((v for k,v in r.cookies.items() if k.startswith("download_warning")), None)
    if token:
        r = session.get(URL, params={"id": file_id, "confirm": token}, stream=True)
    r.raise_for_status()
    return r.content

@app.get("/download/{request_id}")
def download_result(request_id: str):
    path = os.path.join(OUTPUT_DIR, f"{request_id}.json")
    if not os.path.exists(path):
        raise HTTPException(status_code=404, detail="Result not found")
    return FileResponse(path, media_type="application/json", filename=f"{request_id}.json")

@app.get("/healthz")
def health():
    # If you want a deeper check, you can ping Redis or env vars here.
    return {"status": "ok"}

@app.post("/extract/url")
async def extract_web_url(body: WebUrlRequest):
    try:
        result = await run_in_threadpool(_extract_web_url, str(body.url))
        # Save result to disk
        request_id = await run_in_threadpool(_save_result_to_disk, result)
        # Return both the request_id and the result
        return JSONResponse(content={
            "requestId": request_id,
            "downloadUrl": f"/download/{request_id}",
            "data": result
        })
    except RuntimeError as e:
        log.exception("URL scraper is not available: %s", e)
        raise HTTPException(status_code=503, detail=str(e))
    except Exception as e:
        log.exception("URL extraction failed: %s", e)
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/extract/url-batch")
async def extract_web_url_batch(body: WebUrlBatchRequest):
    results: List[Dict[str, Any]] = []
    for url in body.urls:
        try:
            result = await run_in_threadpool(_extract_web_url, str(url))
            # Save result to disk
            request_id = await run_in_threadpool(_save_result_to_disk, result)
            results.append({
                "requestId": request_id,
                "downloadUrl": f"/download/{request_id}",
                "url": str(url),
                "status": "success",
                "data": result
            })
        except Exception as e:
            results.append({
                "url": str(url),
                "status": "error",
                "error": str(e),
                "data": {
                    "schemaVersion": "1.0",
                    "requestId": None,
                    "locale": "en-US",
                    "source": {
                        "fileName": None,
                        "url": str(url),
                        "runAt": "",
                        "mode": "live-url",
                        "model": "playwright-chromium + beautifulsoup",
                    },
                    "listing": None,
                    "raw": {"groups": []},
                    "meta": {"durationMs": None, "tokens": [], "error": str(e)},
                }
            })
    return {"items": results}
"""
@app.post("/extract/url-batch")
def extract_from_urls(body: UrlBatch):
    results: List[Dict[str, Any]] = []
    for url in body.urls:
        try:
            fid = _drive_file_id(url)
            if not fid: raise ValueError("Invalid Drive link")
            content = _download_google_drive_file(fid)
            filename = f"{fid}.pdf"  # naive guess
            result = run_extraction(content, filename)
            results.append(result)
        except Exception as e:
            results.append({
                "schemaVersion": "1.0",
                "requestId": secrets.token_hex(8),
                "locale": "ja-JP",
                "source": {"fileName": url, "runAt": "", "mode": "url", "model": ""},
                "listing": {},
                "raw": {"groups": {}},
                "meta": {"durationMs": None, "tokens": {}},
                "errors": [str(e)]
            })
    return {"items": results}
"""
