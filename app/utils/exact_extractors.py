import re
from typing import Dict, Any, Optional

_NUM = r"[0-9]+(?:\.[0-9]+)?"
_WS = r"[ \t\u3000]*"         

def _first(pat: str, s: str, flags=0) -> Optional[str]:
    m = re.search(pat, s, flags)
    return m.group(1) if m else None

def _exists(pat: str, s: str, flags=0) -> bool:
    return re.search(pat, s, flags) is not None

def extract_price(text: str) -> Optional[str]:
    
    t = text.replace(",", "")
    m = re.search(rf"(?:(?P<oku>{_NUM})\s*億)?\s*(?:(?P<man>{_NUM})\s*万)?\s*円", t)
    if m and (m.group("oku") or m.group("man")):
        oku = float(m.group("oku")) if m.group("oku") else 0.0
        man = float(m.group("man")) if m.group("man") else 0.0
        total = int(round(oku*100_000_000 + man*10_000))
        return f"{total}円"
    
    m2 = re.search(rf"(?P<n>{_NUM})\s*円", t)
    return f"{int(float(m2.group('n')))}円" if m2 else None

def _area_any(text: str, key_words: list[str]) -> Optional[str]:
    
    for kw in key_words:
        m = re.search(rf"{kw}[^0-9]*({_NUM})\s*(?:m2|㎡|m²|坪)", text, re.IGNORECASE)
        if m:
            return f"{m.group(1)}㎡"
    return None

def extract_areas(text: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    land = _area_any(text, ["土地面積", "敷地面積"])
    bld  = _area_any(text, ["建物面積", "延床面積", "延床", "床面積"])
    if land: out["土地面積"] = land
    if bld:  out["延床面積"] = bld
    return out

def extract_ratios(text: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    bcr = _first(rf"建(?:ぺい|蔽)率{_WS}({_NUM})\s*%", text)
    far = _first(rf"容積率{_WS}({_NUM})\s*%(?:（{_WS}({_NUM}){_WS}％{_WS}）)?", text)
    # capture effective FAR
    m = re.search(rf"容積率{_WS}({_NUM})\s*%(?:（{_WS}({_NUM}){_WS}％{_WS}）)?", text)
    eff = m.group(2) if m else None
    if bcr: out["建ぺい率"] = f"{int(float(bcr))}%"
    if m:
        out["容積率"] = f"{int(float(m.group(1)))}%{f'（{int(float(eff))}％）' if eff else ''}"
    return out

def extract_utilities(text: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if _exists(r"(公営水道|上水道|水道)", text): out["水道"] = "公営水道"
    if _exists(r"(公共下水|下水)", text): out["下水"] = "公共下水"
    if _exists(r"(都市ガス)", text): out["都市ガス"] = "都市ガス"
    if _exists(r"(電気)", text): out["電気"] = "電気"
    return out

def extract_parking(text: str) -> Optional[str]:
 
    if _exists(r"駐車(?:場)?\s*(無し|なし)", text):
        return "無し"
    if _exists(r"駐車(?:場)?\s*(有|あり|有り)", text):
        return "有"
    return None

def extract_road(text: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    dir_ = _first(r"(北西|南西|北東|南東|北|東|南|西)(?:側)?", text)
    wid_ = _first(rf"幅員{_WS}約?({_NUM})\s*m", text)
    typ_ = "公道" if _exists(r"公道", text) else ("私道" if _exists(r"私道", text) else None)
    if dir_: out["方位"] = dir_
    if wid_: out["幅員"] = f"{wid_}m"
    if typ_: out["道路"] = typ_
    return out

def extract_transport(text: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    walk = _first(rf"徒歩{_WS}([0-9一二三四五六七八九十]+){_WS}分", text)
    if walk: out["徒歩"] = walk + "分"
    return out

def extract_exact(text_pages: list[str]) -> Dict[str, Any]:
    """
    Runs deterministic extractors over all pages and returns a flat Japanese-key dict.
    If multiple hits, uses the first seen.
    """
    out: Dict[str, Any] = {}
    for t in text_pages:
        if "価格" not in out:
            p = extract_price(t)
            if p: out["価格"] = p
        out.update({k:v for k,v in extract_areas(t).items() if k not in out})
        out.update({k:v for k,v in extract_ratios(t).items() if k not in out})
        out.update({k:v for k,v in extract_utilities(t).items() if k not in out})
        prk = extract_parking(t)
        if prk and "駐車場" not in out:
            out["駐車場"] = prk
        out.update({k:v for k,v in extract_road(t).items() if k not in out})
        out.update({k:v for k,v in extract_transport(t).items() if k not in out})
    return out
