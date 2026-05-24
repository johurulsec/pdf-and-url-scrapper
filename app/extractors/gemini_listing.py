# app/extractors/gemini_listing.py
import os
import time
import secrets
import datetime as dt
import json
import logging
from typing import Any, Dict, List

from google import genai
from google.genai import types
from google.genai import errors as genai_errors
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type

from app.core.config import settings
from app.schemas_listing import ExtractEnvelope
from app.utils.pdf_image import pdf_to_images, ensure_png
from app.utils.pdf_text import pdf_to_text_pages
from app.utils.normalize_fields import standardize
from app.utils.exact_extractors import extract_exact
from app.utils.geocode import geocode_address
from app.utils.translate_entire import translate_entire_document_to_english

log = logging.getLogger("uvicorn.error")


OUTPUT_DIR = "outputs"
os.makedirs(OUTPUT_DIR, exist_ok=True)

SYSTEM_PROMPT = """
あなたは日本の不動産広告(PDF/画像)から項目を抽出するエージェントです。
出力は JSON のみ。説明文やマークダウンは禁止。値は原文のまま日本語・単位で返す。
JSONのキーは以下の日本語キーのみを使用し、存在しないものは欠落可:
種類, 価格, 値段, 販売価格, 所在, 所在地, 住所, 土地面積, 延床面積, 建物面積,
建ぺい率, 容積率, 用途地域, 道路, 幅員, 間口, 方位, 接道, 地目, 所有権, 借地権, 敷地権,
共有持分, 共有持分分子, 共有持分分母, 現況, 沿線, 駅, 徒歩, 交通,
築年月, 建築年月, 増改築, リノベーション, 間取り, 構造, 鉄筋コンクリート, 鉄骨鉄筋コンクリート, 鉄骨, 重量鉄骨, 軽量鉄骨, 木造,
駐車場, 車庫, 備考, 容積率但し書き, 道路メモ
"""


PRIORITY_KEYS = [
    "価格", "値段", "販売価格",
    "土地面積", "敷地面積", "建物面積", "延床面積", "延床",
    "建ぺい率", "建蔽率", "容積率", "幅員", "間口", "徒歩"
]


def _make_client():
    """Builds a Google GenAI client (Vertex AI or API key)."""
    if settings.USE_VERTEX:
        if not settings.GCP_PROJECT:
            raise RuntimeError("Set GOOGLE_CLOUD_PROJECT for Vertex AI.")
        return genai.Client(vertexai=True, project=settings.GCP_PROJECT, location=settings.GCP_LOCATION)
    if not settings.GOOGLE_API_KEY:
        raise RuntimeError("Set GOOGLE_API_KEY.")
    return genai.Client(api_key=settings.GOOGLE_API_KEY)


def _build_parts(images: List[bytes], text_hint: str) -> List[types.Part]:
    """Constructs content parts: system prompt + optional text hint + image pages."""
    parts: List[types.Part] = [types.Part.from_text(SYSTEM_PROMPT)]
    if text_hint:
        parts.append(types.Part.from_text("参考テキスト:\n" + text_hint[:12000]))
    for i, img in enumerate(images[: settings.MAX_PAGES], start=1):
        try:
            png = ensure_png(img)
            parts.append(types.Part.from_bytes(data=png, mime_type="image/png"))
        except Exception as e:
            log.error("Failed to build image part for page %s: %s", i, e)
            raise RuntimeError(f"image-part-build-failed: page={i}") from e
    return parts


@retry(
    stop=stop_after_attempt(3),                          
    wait=wait_exponential(multiplier=2, min=2, max=20), 
    retry=retry_if_exception_type(genai_errors.ServerError),
    reraise=True,
)
def _call_model(images: List[bytes], text_hint: str) -> Dict[str, Any]:
    """
    Calls Gemini with retries on transient ServerError (e.g., 503 overloaded).
    Returns JSON dict parsed from model response.
    """
    client = _make_client()
    parts = _build_parts(images, text_hint)
    cfg = types.GenerateContentConfig(
        temperature=0.2, top_k=32, response_mime_type="application/json"
    )
    resp = client.models.generate_content(
        model=settings.GEMINI_MODEL,
        contents=types.Content(role="user", parts=parts),
        config=cfg,
    )

    raw_text = getattr(resp, "text", None)
    if not raw_text and getattr(resp, "candidates", None):
        c0_parts = resp.candidates[0].content.parts
        raw_text = c0_parts[0].text if c0_parts else ""

    if not raw_text:
        raise RuntimeError("model-empty-response")

    try:
        return json.loads(raw_text)
    except Exception:
        
        log.error("Bad JSON from model (first 1k): %s", raw_text[:1000])
        parts[0] = types.Part.from_text(SYSTEM_PROMPT + "\n必ずJSONのみで返してください。")
        resp2 = client.models.generate_content(
            model=settings.GEMINI_MODEL,
            contents=types.Content(role="user", parts=parts),
            config=types.GenerateContentConfig(
                temperature=0.1, response_mime_type="application/json"
            ),
        )
        raw_text2 = getattr(resp2, "text", None) or ""
        if not raw_text2:
            raise RuntimeError("model-empty-response-retry")
        return json.loads(raw_text2)


def run_extraction(file_bytes: bytes, filename: str) -> dict:
    """
    End-to-end:
      PDF/image → images + vector text → regex exacts → model JSON → merge → normalize
      → geocode (lat/lng) → translate whole JSON to English → validate → save → return
    """
    t0 = time.time()

    
    if filename.lower().endswith(".pdf"):
        images = pdf_to_images(file_bytes)
        text_pages = pdf_to_text_pages(file_bytes)
    else:
        images = [ensure_png(file_bytes)]
        text_pages = []

    
    exact_raw = extract_exact(text_pages)

    
    text_hint = (
        "\n\n".join([f"[PAGE {i}] {t}" for i, t in enumerate(text_pages, 1)])
        if settings.INCLUDE_OCR_HINT
        else ""
    )

    
    model_raw = _call_model(images, text_hint)

    
    merged_raw = dict(model_raw)
    for k, v in exact_raw.items():
        if v is None:
            continue
        if k in PRIORITY_KEYS or settings.STRICT_VERBATIM:
            merged_raw[k] = v
        else:
            merged_raw.setdefault(k, v)

    
    listing = standardize(merged_raw)

    
    request_id = secrets.token_hex(8)
    env: Dict[str, Any] = {
        "schemaVersion": settings.SCHEMA_VERSION,
        "requestId": request_id,
        "locale": settings.LOCALE,  
        "source": {
            "fileName": filename,
            "runAt": dt.datetime.utcnow().isoformat() + "Z",
            "mode": "inline-file",
            "model": settings.GEMINI_MODEL,
        },
        "listing": listing,
        "raw": {"groups": model_raw.get("groups", {})},
        "meta": {
            "durationMs": int((time.time() - t0) * 1000),
            "tokens": {},
        },
    }

    
    try:
        addr = env.get("listing", {}).get("address", {})
        addr_full = addr.get("full")
        if addr_full:
            latlng = geocode_address(addr_full)
            if latlng:
                env["listing"].setdefault("address", {})
                env["listing"]["address"]["lat"] = latlng[0]
                env["listing"]["address"]["lng"] = latlng[1]
    except Exception as ge:
        log.warning("Geocoding failed: %s", ge)

    try:
        if os.getenv("AUTO_TRANSLATE_ALL_TO_EN", "true").lower() in ("1", "true", "yes"):
            translate_entire_document_to_english(env)
    except Exception as te:
        log.warning("Translation step failed: %s", te)

    
    validated = ExtractEnvelope.model_validate(env).model_dump()

    out_path = os.path.join(OUTPUT_DIR, f"{request_id}.json")
    try:
        with open(out_path, "w", encoding="utf-8") as f:
            json.dump(validated, f, ensure_ascii=False, indent=2)
    except Exception as we:
        log.error("Failed to write output %s: %s", out_path, we)

    return validated
