"""
CivicFix — Python Flask AI & Departmental Routing Microservice (python/app.py)
Listens on http://127.0.0.1:5000
"""

from flask import Flask, request, jsonify
from priority_engine import analyze_priority
from duplicate_detector import find_possible_duplicates

app = Flask(__name__)


@app.route("/health", methods=["GET"])
def health_check():
    return jsonify({
        "ok": True,
        "service": "CivicFix Python AI Triage & Departmental Routing Engine",
        "engine_type": "Multi-Department Heuristic + Geospatial Duplicate Detector (v2)",
        "endpoints": ["/health", "/analyze"]
    }), 200


@app.route("/analyze", methods=["POST"])
def analyze_complaint():
    data = request.get_json(silent=True) or {}

    title = str(data.get("title") or "").strip()
    category = str(data.get("category") or "Other").strip()
    description = str(data.get("description") or "").strip()
    location_text = str(data.get("location_text") or "").strip()
    latitude = data.get("latitude")
    longitude = data.get("longitude")
    existing_complaints = data.get("existing_complaints") or []

    if not title and not description:
        return jsonify({
            "ok": False,
            "analysis_success": False,
            "error": "Complaint title or description is required for analysis."
        }), 400

    priority_res = analyze_priority(
        title=title,
        category=category,
        description=description,
        location_text=location_text,
    )

    duplicates = find_possible_duplicates(
        title=title,
        category=category,
        description=description,
        location_text=location_text,
        latitude=latitude,
        longitude=longitude,
        existing_complaints=existing_complaints,
    )

    return jsonify({
        "ok": True,
        "analysis_success": True,
        "analysis_source": "python_flask_heuristic_v2",
        "suggested_priority": priority_res["suggested_priority"],
        "urgency_score": priority_res["urgency_score"],
        "matched_keywords": priority_res["matched_keywords"],
        "assigned_department": priority_res["assigned_department"],
        "department_code": priority_res["department_code"],
        "nodal_officer": priority_res["nodal_officer"],
        "sla_hours": priority_res["sla_hours"],
        "explanation": priority_res["explanation"],
        "possible_duplicates": duplicates,
    }), 200


if __name__ == "__main__":
    print("============================================================")
    print(" CivicFix Python AI & Department Routing Engine on port 5000")
    print(" Health Check: http://127.0.0.1:5000/health")
    print("============================================================")
    app.run(host="127.0.0.1", port=5000, debug=False)
