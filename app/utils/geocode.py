import os, time, hashlib, requests
from typing import Optional, Tuple
from urllib.parse import urlencode

_cache = {}
def _get(k): 
    v = _cache.get(k)
    return v["val"] if v and (time.time() - v["t"] < 86400) else None  # 24h
def _set(k, val): 
    _cache[k] = {"t": time.time(), "val": val}

def geocode_address(address_text: str) -> Optional[Tuple[float, float]]:
    if not address_text:
        return None

    ck = "gc:" + hashlib.sha1(address_text.strip().encode("utf-8")).hexdigest()
    c = _get(ck)
    if c: 
        return c

    gkey = os.getenv("GOOGLE_MAPS_API_KEY", "").strip()
    #
    if gkey:
        try:
            url = "https://maps.googleapis.com/maps/api/geocode/json?" + urlencode({
                "address": address_text, "language": "ja", "region": "jp", "key": gkey
            })
            r = requests.get(url, timeout=10); r.raise_for_status()
            js = r.json()
            if js.get("results"):
                loc = js["results"][0]["geometry"]["location"]
                latlng = (loc["lat"], loc["lng"])
                _set(ck, latlng)
                return latlng
        except Exception:
            pass

    
    try:
        url = "https://nominatim.openstreetmap.org/search?" + urlencode({
            "q": address_text, "format": "json", "limit": 1
        })
        headers = {"User-Agent": "jp-pdf-json/1.0 (ops@example.com)"}  # set your contact
        r = requests.get(url, headers=headers, timeout=12); r.raise_for_status()
        arr = r.json()
        if arr:
            latlng = (float(arr[0]["lat"]), float(arr[0]["lon"]))
            _set(ck, latlng)
            return latlng
    except Exception:
        return None

    return None
