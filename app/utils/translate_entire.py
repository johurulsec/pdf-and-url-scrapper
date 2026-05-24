from typing import Any, Dict, List, Tuple, Union
import copy, os
from app.utils.translate import translate_texts_to_en

PRESERVE_PER_FIELD = os.getenv("TRANSLATE_PRESERVE_ORIGINAL_PER_FIELD", "false").lower() in ("1","true","yes")
PRESERVE_RAW_COPY   = os.getenv("TRANSLATE_PRESERVE_RAW_COPY", "true").lower() in ("1","true","yes")
SOURCE_LANG_HINT    = os.getenv("TRANSLATE_SOURCE_HINT", "ja").strip() or None

_SKIP_PREFIXES = [ ["raw","originalJa"] ]  # do not translate the preserved original

def _is_skipped(base: List[Union[str,int]]) -> bool:
    for pref in _SKIP_PREFIXES:
        if len(base) >= len(pref) and all(base[i] == pref[i] for i in range(len(pref))):
            return True
    return False

def _collect_string_paths(obj: Any, base: List[Union[str,int]], bag: List[Tuple[List[Union[str,int]], str]]):
    if _is_skipped(base):
        return
    if isinstance(obj, dict):
        for k, v in obj.items():
            _collect_string_paths(v, base + [k], bag)
    elif isinstance(obj, list):
        for idx, v in enumerate(obj):
            _collect_string_paths(v, base + [idx], bag)
    elif isinstance(obj, str):
        bag.append((base, obj))

def _assign_path(root: Any, path: List[Union[str,int]], value: Any):
    cur = root
    for p in path[:-1]:
        cur = cur[p]
    cur[path[-1]] = value

def _derive_preserve_key(original_key: str) -> str:
    return f"{original_key}_ja"

def translate_entire_document_to_english(result: Dict[str, Any]) -> Dict[str, Any]:
    if not isinstance(result, dict):
        return result

    # Keep a deep copy of original (without nesting original inside itself)
    if PRESERVE_RAW_COPY:
        original_copy = copy.deepcopy(result)
        if isinstance(original_copy, dict) and "raw" in original_copy:
            # avoid huge recursion / bloat
            try:
                del original_copy["raw"]["originalJa"]
            except Exception:
                pass
        raw = result.setdefault("raw", {})
        if "originalJa" not in raw:
            raw["originalJa"] = original_copy

    targets: List[Tuple[List[Union[str,int]], str]] = []
    _collect_string_paths(result, [], targets)
    if not targets:
        result["locale"] = "en-US"
        result.setdefault("meta", {})["normalizedLocale"] = "en-US"
        return result

    originals = [t[1] for t in targets]
    translated = translate_texts_to_en(originals, source_hint=SOURCE_LANG_HINT)

    for (path, orig), en in zip(targets, translated):
        # optionally preserve original next to field
        if PRESERVE_PER_FIELD and path and isinstance(path[-1], str):
            parent = result
            for p in path[:-1]:
                parent = parent[p]
            key = _derive_preserve_key(path[-1])
            if key not in parent:
                parent[key] = orig
        _assign_path(result, path, en)

    result["locale"] = "en-US"
    result.setdefault("meta", {})["normalizedLocale"] = "en-US"
    return result
