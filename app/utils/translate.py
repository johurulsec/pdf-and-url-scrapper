import os, requests
from typing import Iterable, List

_API = "https://translation.googleapis.com/language/translate/v2"
_KEY = os.getenv("GOOGLE_TRANSLATE_API_KEY", "").strip()

def translate_texts_to_en(texts: Iterable[str], source_hint: str = None) -> List[str]:
    texts = list(texts)
    if not texts:
        return []
    if not _KEY:
        # No key provided: return originals (fail-safe)
        return texts

    out: List[str] = []
    CHUNK = 100  # batch size

    for i in range(0, len(texts), CHUNK):
        chunk = texts[i:i+CHUNK]
        payload = {"q": chunk, "target": "en", "format": "text"}
        if source_hint:
            payload["source"] = source_hint
        try:
            r = requests.post(_API, params={"key": _KEY}, json=payload, timeout=20)
            r.raise_for_status()
            data = r.json()["data"]["translations"]
            out.extend([d.get("translatedText", c) for c, d in zip(chunk, data)])
        except Exception:
            out.extend(chunk)
    return out
