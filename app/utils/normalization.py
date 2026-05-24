import re
from typing import Optional

KANJI_NUM = {"〇":0,"一":1,"二":2,"三":3,"四":4,"五":5,"六":6,"七":7,"八":8,"九":9}
STRUCTURE_MAP = {
    "鉄筋コンクリート": "RC", "RC": "RC",
    "鉄骨鉄筋コンクリート": "SRC", "SRC": "SRC",
    "鉄骨": "S", "重量鉄骨":"heavy_steel", "軽量鉄骨":"light_steel",
    "木造": "W"
}
TENURE_MAP = {"所有権":"ownership", "借地権":"leasehold", "敷地権":"site_right"}

def normalize_price_jpy(text: str) -> Optional[int]:
    t = (text or "").replace(",", "")
    m = re.search(r"([0-9]+(?:\.[0-9]+)?)\s*(億|万)?円?", t)
    if not m: return None
    val = float(m.group(1)); unit = m.group(2)
    if unit == "億": return int(round(val * 100_000_000))
    if unit == "万": return int(round(val * 10_000))
    return int(round(val))

def normalize_area_m2(text: str) -> Optional[float]:
    t = (text or "").replace(",", "").strip()
    m = re.search(r"([0-9]+(?:\.[0-9]+)?)\s*(m2|㎡|m²|坪|m|ｍ)", t, re.IGNORECASE)
    if not m:
        return None
    val = float(m.group(1))
    unit = m.group(2)
    if unit in ("坪",):
        return round(val * 3.305785, 2)
    # 'm' or 'ｍ' often loses the superscript in PDFs → treat as m²
    return round(val, 2)

def normalize_ratio_percent(text: str) -> Optional[int]:
    t = (text or "")
    m = re.search(r"([0-9]{1,3})\s*%", t)
    if m: return int(m.group(1))
    m = re.search(r"([0-9]{1,3})\s*/\s*([0-9]{1,3})", t)
    if m:
        num, den = int(m.group(1)), int(m.group(2))
        if den: return int(round(100 * num / den))
    return None

def normalize_direction(text: str) -> Optional[str]:
    t = (text or "")
    for k in ["北西","南西","北東","南東","北","東","南","西"]:
        if k in t: return k
    return None

def normalize_meters(text: str) -> Optional[float]:
    t = (text or "").replace("ｍ","m")
    m = re.search(r"([0-9]+(?:\.[0-9]+)?)\s*m", t)
    return float(m.group(1)) if m else None

def normalize_walk_minutes(text: str) -> Optional[int]:
    t = (text or "")
    m = re.search(r"徒歩\s*([0-9]+)\s*分", t)
    return int(m.group(1)) if m else None

def normalize_tenure(text: str) -> Optional[str]:
    t = (text or "")
    for k,v in TENURE_MAP.items():
        if k in t: return v
    return None

def normalize_structure(text: str) -> Optional[str]:
    t = (text or "")
    for k,v in STRUCTURE_MAP.items():
        if k in t: return v
    return None
