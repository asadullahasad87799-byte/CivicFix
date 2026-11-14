"""
CivicFix — Transparent Heuristic Priority & Departmental Routing Engine (python/priority_engine.py)
Automatically determines:
1. Suggested Priority (Low, Medium, High, Critical)
2. Urgency Score (0-100) with matched hazard keywords
3. Target Administrative Body (Department Routing)
4. Statutory SLA Resolution Window (in hours)
"""

import re
from typing import Dict, List, Any

DEPARTMENT_ROUTING: Dict[str, Dict[str, Any]] = {
    "Road Damage": {
        "code": "DEPT-BNR",
        "name": "B&R (Buildings & Roads) Engineering Wing",
        "officer": "Executive Engineer (XEN - Roads & Bridges)",
        "default_sla_hours": 48,
    },
    "Streetlight": {
        "code": "DEPT-ELEC",
        "name": "Municipal Electrical Wing & PSPCL Nodal Cell",
        "officer": "Sub-Divisional Officer (Electrical / Streetlight)",
        "default_sla_hours": 24,
    },
    "Garbage": {
        "code": "DEPT-SAN",
        "name": "Health & Sanitation (Swachh Bharat) Wing",
        "officer": "Chief Sanitary Inspector (CSI - Solid Waste)",
        "default_sla_hours": 24,
    },
    "Water Leakage": {
        "code": "DEPT-WAT",
        "name": "O&M Water Supply & Potable Pipeline Cell",
        "officer": "Sub-Divisional Engineer (O&M Water Supply)",
        "default_sla_hours": 24,
    },
    "Drainage": {
        "code": "DEPT-DRN",
        "name": "O&M Sewerage & Storm Drainage Wing",
        "officer": "Executive Engineer (Sewerage & Drainage)",
        "default_sla_hours": 36,
    },
    "Other": {
        "code": "DEPT-GEN",
        "name": "General Civic & Tehbazari Nodal Cell",
        "officer": "Zonal Nodal Grievance Officer",
        "default_sla_hours": 72,
    },
}

CATEGORY_BASE_SCORES: Dict[str, int] = {
    "Water Leakage": 58,
    "Drainage": 56,
    "Streetlight": 52,
    "Road Damage": 55,
    "Garbage": 48,
    "Other": 42,
}

CRITICAL_INDICATORS: List[str] = [
    "sparking", "live wire", "electric shock", "exposed wire", "hanging wire",
    "fallen pole", "electric pole", "open manhole", "uncovered manhole",
    "missing manhole", "collapse", "caved in", "sinkhole", "accident",
    "injured", "skidding", "hospital", "school", "college", "ambulance",
    "pipeline burst", "main pipe burst", "flooding", "fire", "short circuit",
    "danger", "dangerous", "life threatening", "fatal"
]

HIGH_INDICATORS: List[str] = [
    "deep pothole", "large pothole", "crater", "overflowing", "sewage",
    "blocked drain", "waterlogging", "contaminated", "dirty water",
    "foul smell", "stray animals", "main road", "highway", "bus stand",
    "bus stop", "crossing", "junction", "rush hour", "traffic jam",
    "pitch dark", "not working since", "3 days", "week"
]

LOW_INDICATORS: List[str] = [
    "minor", "small crack", "faded paint", "cosmetic", "suggestion",
    "signboard", "bench", "slightly", "occasional"
]


def analyze_priority(title: str, category: str, description: str, location_text: str = "") -> Dict[str, Any]:
    clean_category = (category or "Other").strip()
    if clean_category not in DEPARTMENT_ROUTING:
        clean_category = "Other"

    combined_text = f"{title or ''} {description or ''} {location_text or ''}".lower()
    combined_text = re.sub(r"\s+", " ", combined_text)

    # Smart cross-category department detection if user picks "Other" or mentions electric poles/wires
    dept_info = dict(DEPARTMENT_ROUTING[clean_category])
    if any(k in combined_text for k in ["electric pole", "fallen pole", "live wire", "sparking", "transformer", "streetlight"]):
        dept_info = dict(DEPARTMENT_ROUTING["Streetlight"])
    elif any(k in combined_text for k in ["sewer", "manhole", "drainage", "waterlogging"]):
        dept_info = dict(DEPARTMENT_ROUTING["Drainage"])
    elif any(k in combined_text for k in ["water pipe", "pipeline burst", "drinking water", "leaking pipe"]):
        dept_info = dict(DEPARTMENT_ROUTING["Water Leakage"])
    elif any(k in combined_text for k in ["pothole", "road broken", "footpath", "asphalt"]):
        dept_info = dict(DEPARTMENT_ROUTING["Road Damage"])
    elif any(k in combined_text for k in ["garbage", "trash", "waste", "dustbin", "dead animal"]):
        dept_info = dict(DEPARTMENT_ROUTING["Garbage"])

    base_score = CATEGORY_BASE_SCORES.get(clean_category, 45)

    matched_critical: List[str] = [kw for kw in CRITICAL_INDICATORS if kw in combined_text]
    matched_high: List[str] = [kw for kw in HIGH_INDICATORS if kw in combined_text]
    matched_low: List[str] = [kw for kw in LOW_INDICATORS if kw in combined_text]

    score = base_score + (len(matched_critical) * 22) + (len(matched_high) * 10) - (len(matched_low) * 12)
    urgency_score = max(15, min(99, score))
    all_matched = matched_critical + matched_high + matched_low

    if matched_critical or urgency_score >= 82:
        suggested_priority = "Critical"
        sla_hours = 12
        reason_detail = f"public-safety hazard indicators ({', '.join(matched_critical[:4])})" if matched_critical else "compounding urgency factors"
        explanation = (
            f"AI Triage (Score {urgency_score}/100): Flagged CRITICAL due to {reason_detail}. "
            f"Auto-routed to {dept_info['name']} with 12-Hour Emergency SLA."
        )
    elif matched_high or urgency_score >= 62:
        suggested_priority = "High"
        sla_hours = 24
        reason_detail = f"high-impact indicators ({', '.join(matched_high[:4])})" if matched_high else f"active {clean_category} disruption"
        explanation = (
            f"AI Triage (Score {urgency_score}/100): Assigned HIGH priority based on {reason_detail}. "
            f"Auto-routed to {dept_info['name']} with 24-Hour SLA."
        )
    elif matched_low and urgency_score < 45:
        suggested_priority = "Low"
        sla_hours = 72
        explanation = (
            f"AI Triage (Score {urgency_score}/100): Assigned LOW priority ({', '.join(matched_low[:3])}). "
            f"Routed to {dept_info['name']} (72-Hour routine SLA)."
        )
    else:
        suggested_priority = "Medium"
        sla_hours = dept_info["default_sla_hours"]
        explanation = (
            f"AI Triage (Score {urgency_score}/100): Assigned standard MEDIUM priority. "
            f"Routed to {dept_info['name']} ({sla_hours}-Hour SLA)."
        )

    return {
        "suggested_priority": suggested_priority,
        "urgency_score": urgency_score,
        "matched_keywords": all_matched[:8],
        "assigned_department": dept_info["name"],
        "department_code": dept_info["code"],
        "nodal_officer": dept_info["officer"],
        "sla_hours": sla_hours,
        "explanation": explanation,
    }
