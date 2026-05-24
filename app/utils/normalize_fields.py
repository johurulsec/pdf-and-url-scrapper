# app/utils/normalize_fields.py
from typing import Dict, Any, List
import re

from .normalization import (
    normalize_price_jpy, normalize_area_m2, normalize_ratio_percent,
    normalize_direction, normalize_meters, normalize_walk_minutes,
    normalize_tenure, normalize_structure
)

def standardize(raw: Dict[str, Any]) -> Dict[str, Any]:
    # Concatenate values from multiple possible raw keys
    g = lambda *keys: " ".join(str(raw.get(k, "") or "") for k in keys)

    # ---- propertyType ----
    prop: List[str] = []
    t = g("種類", "物件種別", "タイプ", "戸建て", "マンション", "1棟マンション", "アパート", "土地")
    if "戸建" in t or "一戸建" in t:
        prop.append("detached")
    if "マンション" in t and "1棟" in t:
        prop.append("building_mansion")
    elif "マンション" in t and "1棟" not in t:
        prop.append("mansion")
    if "アパート" in t:
        prop.append("apartment")
    if "土地" in t:
        prop.append("land")

    # ---- price ----
    price = None
    for k in ["価格", "販売価格", "値段", "price"]:
        price = price or normalize_price_jpy(str(raw.get(k, "") or ""))

    # ---- areas ----
    land = None
    for k in ["土地面積", "敷地面積"]:
        land = land or normalize_area_m2(str(raw.get(k, "") or ""))
    bld = None
    for k in ["建物面積", "延床面積", "延床", "床面積"]:
        bld = bld or normalize_area_m2(str(raw.get(k, "") or ""))

    # ---- ratios (BCR/FAR + effective FAR in parentheses) ----
    bcr = None
    for k in ["建ぺい率", "建蔽率"]:
        bcr = bcr or normalize_ratio_percent(str(raw.get(k, "") or ""))

    far_text = str(raw.get("容積率", "") or "")
    far = normalize_ratio_percent(far_text)
    # Effective FAR pattern: 300％（160％）
    eff = None
    m_eff = re.search(r"（\s*([0-9]{1,3})\s*％\s*）", far_text)
    if m_eff:
        eff = int(m_eff.group(1))
    notes = raw.get("容積率但し書き") or (f"{eff}%" if eff and not far_text.strip() else None)

    # ---- road ----
    direction = normalize_direction(g("接道", "道路", "方位", "方向"))
    width = normalize_meters(g("幅員", "道路幅員"))
    frontage = normalize_meters(g("間口", "接道長さ", "長さ"))
    road_type = "public" if "公道" in g("道路", "接道") else ("private" if "私道" in g("道路", "接道") else None)

    # ---- rights / land category ----
    rights: List[str] = []
    ten = normalize_tenure(g("所有権", "借地権", "敷地権", "権利"))
    if ten:
        rights.append(ten)

    land_cat = None
    for token in ["宅地", "山林", "田", "畑", "雑種地"]:
        if token in g("地目", "土地分類", "備考"):
            land_cat = token
            break

    # ---- transport ----
    transport = []
    line = raw.get("沿線") or raw.get("路線") or raw.get("line")
    station = raw.get("駅") or raw.get("station")
    walk = normalize_walk_minutes(g("徒歩", "アクセス", "交通"))
    if line or station or walk is not None:
        transport.append({"line": line, "station": station, "walkMinutes": walk})

    # ---- address ----
    address_full = raw.get("住所") or raw.get("所在地") or raw.get("所在") or raw.get("address")

    # ---- built / structure ----
    built_text = raw.get("築年月") or raw.get("建築年月")
    struct_text = g("構造", "鉄筋コンクリート", "鉄骨鉄筋コンクリート", "鉄骨", "重量鉄骨", "軽量鉄骨", "木造")
    struct_code = normalize_structure(struct_text)

    _clean = " ".join(struct_text.split()) if struct_text else ""
    if _clean:
        parts = _clean.split()
        dedup = []
        for p in parts:
            if not dedup or dedup[-1] != p:
                dedup.append(p)
        struct_text = " ".join(dedup).strip()

    # ---- utilities (patched) ----
    util_src = g("水道", "下水", "ガス", "都市ガス", "設備")
    utilities = {
        "water": True if ("水道" in util_src or "公営水道" in util_src) else None,
        "sewer": True if ("下水" in util_src or "公共下水" in util_src) else None,
        "gas": "city" if "都市ガス" in util_src else None,
        "cityGas": True if "都市ガス" in util_src else None,
        "electricity": True if "電気" in util_src else None
    }

    # ---- parking (patched) ----
    ptxt = g("駐車場", "車庫", "駐車")
    parking = None
    if any(k in ptxt for k in ["駐車", "車庫", "駐車場"]):
        if ("無し" in ptxt) or ("なし" in ptxt):
            parking = {"available": False, "count": None, "type": None, "text": ptxt or "無し"}
        else:
            parking = {"available": True, "count": None, "type": None, "text": ptxt}

    # ---- zoning / status ----
    zoning = raw.get("用途地域")
    status = raw.get("現況") or raw.get("状態")

    # ---- raw mentions (keep audit trail) ----
    raw_mentions = []
    for k in ["土地面積", "建物面積", "延床面積", "延床", "敷地面積", "1階面積", "2階面積", "3階面積", "面積"]:
        if raw.get(k):
            raw_mentions.append(str(k) + "/" + str(raw.get(k)))

    # ---- assemble listing ----
    listing = {
        "propertyType": prop or [],
        "priceJPY": price,
        "address": {"full": address_full},
        "areas": {
            "landArea_m2": land,
            "buildingArea_m2": bld,
            "floorArea_m2": bld,
            "siteArea_m2": land,
            "rawMentions": raw_mentions
        },
        "rights": rights,
        "share": raw.get("共有持分"),
        "landCategory": land_cat,
        "road": {
            "width_m": width,
            "frontage_m": frontage,
            "direction": direction,
            "type": road_type,
            "notes": g("接道", "道路メモ")
        },
        "ratios": {
            "buildingCoverage_pct": bcr,
            "floorAreaRatio_pct": far,
            "floorAreaRatioEffective_pct": eff,
            "notes": notes
        },
        "zoning": zoning,
        "utilities": utilities,
        "status": status,
        "transport": transport,
        "built": {
            "builtYearMonth": built_text,
            "renovation": raw.get("増改築") or raw.get("リノベーション")
        },
        "floorPlan": raw.get("間取り") or raw.get("floorPlan"),
        "structure": {
            "code": normalize_structure(struct_text),
            "text": (struct_text or None) if struct_text.strip() else None
        },
        "parking": parking or {"available": None, "count": None, "type": None, "text": None},
        "notes": raw.get("備考") or None
    }
    return listing
