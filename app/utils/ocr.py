import io
import os
from typing import List, Tuple, Dict, Any, Optional

from PIL import Image

# --- Try PaddleOCR first (optional), else pytesseract ---
_BACKEND = "none"
try:
    from paddleocr import PaddleOCR  # type: ignore
    _paddle = PaddleOCR(use_angle_cls=True, lang="japan")  # ja model
    _BACKEND = "paddle"
except Exception:
    try:
        import pytesseract  # type: ignore
        # Optional: external tesseract path from .env
        _tess_cmd = os.getenv("TESSERACT_CMD")
        if _tess_cmd:
            pytesseract.pytesseract.tesseract_cmd = _tess_cmd
        _BACKEND = "tesseract"
    except Exception:
        _BACKEND = "none"


def _ensure_image(img_or_bytes: bytes | Image.Image) -> Image.Image:
    if isinstance(img_or_bytes, Image.Image):
        return img_or_bytes
    return Image.open(io.BytesIO(img_or_bytes)).convert("RGB")


def ocr_image_to_lines(
    img_or_bytes: bytes | Image.Image,
    lang_hint: str = "jpn",
    join_threshold: int = 0,
) -> List[str]:
    """
    Returns a list of text lines extracted from an image.
    - lang_hint: "jpn" for Tesseract; Paddle uses 'japan' set at init.
    - join_threshold: if >0, lines shorter than N chars will be merged to previous line.
    """
    if _BACKEND == "none":
        return []

    im = _ensure_image(img_or_bytes)

    if _BACKEND == "paddle":
        # result: List[List[ [ [x,y],... ], (text, conf) ]]
        result = _paddle.ocr(im, cls=True)
        lines: List[str] = []
        for page in result:
            for box, (txt, conf) in page:
                if txt and txt.strip():
                    lines.append(txt.strip())
        # simple join of too-short lines (optional)
        if join_threshold > 0 and lines:
            merged: List[str] = []
            for line in lines:
                if merged and len(line) < join_threshold:
                    merged[-1] = (merged[-1] + " " + line).strip()
                else:
                    merged.append(line)
            lines = merged
        return lines

    # --- tesseract path ---
    import pytesseract  # local import to avoid NameError if not installed
    # Japanese + digits/symbols; add 'eng' to stabilize numbers/units if needed
    tess_lang = lang_hint  # e.g., "jpn"
    config = "--psm 6"     # assume uniform blocks of text
    text = pytesseract.image_to_string(im, lang=tess_lang, config=config)
    # Split into lines; strip empties
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    if join_threshold > 0 and lines:
        merged: List[str] = []
        for line in lines:
            if merged and len(line) < join_threshold:
                merged[-1] = (merged[-1] + " " + line).strip()
            else:
                merged.append(line)
        lines = merged
    return lines


def ocr_images_to_paragraph(
    images: List[bytes | Image.Image],
    lang_hint: str = "jpn",
    join_threshold: int = 0,
    page_sep: str = "\n\n",
) -> str:
    """
    OCR multiple images and return a single paragraph string.
    """
    paragraphs: List[str] = []
    for img in images:
        lines = ocr_image_to_lines(img, lang_hint=lang_hint, join_threshold=join_threshold)
        if lines:
            paragraphs.append("\n".join(lines))
    return page_sep.join(paragraphs).strip()


def ocr_images_structured(
    images: List[bytes | Image.Image],
    lang_hint: str = "jpn",
) -> List[Dict[str, Any]]:
    """
    Returns a structured list per page:
    [
      { "page": 1, "lines": ["...","..."], "backend":
"""