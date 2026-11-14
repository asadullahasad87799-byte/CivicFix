"""
CivicFix — Possible Duplicate Complaint Detector (python/duplicate_detector.py)
Compares a newly submitted complaint against existing complaints using:
1. Category matching
2. Token Jaccard + SequenceMatcher text similarity
3. Optional Haversine geographic distance (when latitude & longitude exist)
Labels matches strictly as "possible duplicates" for administrator review.
"""

import math
import re
from difflib import SequenceMatcher
from typing import Dict, List, Any, Optional

STOPWORDS = {
    "the", "and", "near", "from", "with", "that", "this", "have", "been",
    "since", "very", "road", "area", "side", "main", "ludhiana", "please",
    "there", "here", "into", "onto", "some", "more"
}


def tokenize(text: str) -> set:
    words = re.findall(r"[a-z0-9]{3,}", (text or "").lower())
    return {w for w in words if w not in STOPWORDS}


def haversine_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Calculates great-circle distance in kilometers between two lat/lng points."""
    radius_km = 6371.0
    d_lat = math.radians(lat2 - lat1)
    d_lon = math.radians(lon2 - lon1)
    a = (
        math.sin(d_lat / 2) ** 2
        + math.cos(math.radians(lat1))
        * math.cos(math.radians(lat2))
        * math.sin(d_lon / 2) ** 2
    )
    c = 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))
    return radius_km * c


def safe_float(val: Any) -> Optional[float]:
    try:
        if val is None or val == "":
            return None
        return float(val)
    except (ValueError, TypeError):
        return None


def find_possible_duplicates(
    title: str,
    category: str,
    description: str,
    location_text: str,
    latitude: Any,
    longitude: Any,
    existing_complaints: List[Dict[str, Any]],
    threshold: float = 34.0,
) -> List[Dict[str, Any]]:
    """
    Returns up to 3 possible duplicate complaints sorted by similarity score.
    """
    new_text = f"{title or ''} {description or ''} {location_text or ''}".lower().strip()
    new_tokens = tokenize(new_text)
    lat1 = safe_float(latitude)
    lon1 = safe_float(longitude)

    candidates: List[Dict[str, Any]] = []

    for item in existing_complaints or []:
        ex_code = item.get("complaint_code")
        if not ex_code:
            continue

        ex_cat = (item.get("category") or "").strip()
        ex_title = item.get("title") or ""
        ex_desc = item.get("description") or ""
        ex_loc = item.get("location_text") or ""
        ex_text = f"{ex_title} {ex_desc} {ex_loc}".lower().strip()

        # Token Jaccard similarity (0..1)
        ex_tokens = tokenize(ex_text)
        if new_tokens and ex_tokens:
            jaccard = len(new_tokens & ex_tokens) / len(new_tokens | ex_tokens)
        else:
            jaccard = 0.0

        # Sequence similarity on titles + location (0..1)
        seq_ratio = SequenceMatcher(
            None,
            f"{title or ''} {location_text or ''}".lower(),
            f"{ex_title} {ex_loc}".lower(),
        ).ratio()

        # Combined text score (0..100)
        score = (jaccard * 55.0) + (seq_ratio * 45.0)

        # Category alignment bonus or penalty
        if ex_cat.lower() == (category or "").strip().lower():
            score += 10.0
        else:
            score *= 0.65

        # Geographic proximity bonus if both have valid coordinates
        dist_km: Optional[float] = None
        lat2 = safe_float(item.get("latitude"))
        lon2 = safe_float(item.get("longitude"))
        if lat1 is not None and lon1 is not None and lat2 is not None and lon2 is not None:
            dist_km = round(haversine_km(lat1, lon1, lat2, lon2), 3)
            if dist_km <= 0.25:      # Within 250 meters
                score += 18.0
            elif dist_km <= 0.75:    # Within 750 meters
                score += 10.0

        final_score = round(min(99.0, score), 1)
        if final_score >= threshold:
            reason_parts = [f"{final_score}% text/category similarity"]
            if dist_km is not None:
                reason_parts.append(f"{dist_km} km apart")
            candidates.append({
                "complaint_code": ex_code,
                "title": ex_title,
                "status": item.get("status", "Submitted"),
                "similarity_score": final_score,
                "distance_km": dist_km,
                "label": "Possible Duplicate (Requires Admin Verification)",
                "match_reason": " · ".join(reason_parts),
            })

    candidates.sort(key=lambda x: x["similarity_score"], reverse=True)
    return candidates[:3]
