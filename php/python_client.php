<?php
/**
 * CivicFix v3.0 — Hybrid Python Flask Microservice Client + Native PHP AI Fallback (php/python_client.php)
 * Connects to http://127.0.0.1:5000/analyze when Python Flask is running, and seamlessly falls back
 * to the built-in PHP Heuristic + Haversine Geospatial Duplicate Engine when Flask is offline.
 */

require_once __DIR__ . '/db.php';

function analyze_complaint_with_ai(array $payload, array $existingComplaints = []): array {
    $title       = trim((string)($payload['title'] ?? ''));
    $category    = trim((string)($payload['category'] ?? 'Other'));
    $description = trim((string)($payload['description'] ?? ''));
    $location    = trim((string)($payload['location_text'] ?? ''));
    $lat         = isset($payload['latitude']) && $payload['latitude'] !== '' ? (float)$payload['latitude'] : null;
    $lng         = isset($payload['longitude']) && $payload['longitude'] !== '' ? (float)$payload['longitude'] : null;

    // 1. Try Python Flask AI Service on port 5000 (fast 1.2s timeout)
    $reqBody = json_encode([
        'title'               => $title,
        'category'            => $category,
        'description'         => $description,
        'location_text'       => $location,
        'latitude'            => $lat,
        'longitude'           => $lng,
        'existing_complaints' => $existingComplaints,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $reqBody,
            'timeout'       => 1.2,
            'ignore_errors' => true,
        ]
    ]);

    $rawResp = @file_get_contents('http://127.0.0.1:5000/analyze', false, $ctx);
    if ($rawResp !== false) {
        $decoded = json_decode($rawResp, true);
        if (is_array($decoded) && !empty($decoded['ok'])) {
            return $decoded;
        }
    }

    // 2. Built-in Native PHP AI Triage & Haversine Geospatial Duplicate Engine (Zero-Downtime Fallback)
    $combined = strtolower($title . ' ' . $description . ' ' . $location);
    $dept     = get_department_for_category($category, $combined);

    $criticalWords = [
        'sparking', 'live wire', 'electric shock', 'exposed wire', 'fallen pole',
        'electric pole', 'open manhole', 'uncovered manhole', 'missing manhole',
        'collapse', 'sinkhole', 'accident', 'injured', 'skidding', 'hospital',
        'school', 'college', 'ambulance', 'pipeline burst', 'main pipe burst',
        'flooding', 'fire', 'short circuit', 'danger', 'life threatening', 'fatal'
    ];
    $highWords = [
        'deep pothole', 'large pothole', 'crater', 'overflowing', 'sewage',
        'blocked drain', 'waterlogging', 'contaminated', 'dirty water',
        'foul smell', 'main road', 'highway', 'bus stand', 'crossing',
        'junction', 'rush hour', 'traffic jam', 'pitch dark', 'transformer'
    ];
    $lowWords = [
        'minor', 'small crack', 'faded paint', 'cosmetic', 'suggestion', 'bench'
    ];

    $baseScores = [
        'Water Leakage' => 58,
        'Drainage'      => 56,
        'Road Damage'   => 55,
        'Streetlight'   => 54,
        'Garbage'       => 50,
        'Other'         => 44,
    ];
    $score = $baseScores[$category] ?? 48;

    $matchedCrit = [];
    foreach ($criticalWords as $kw) {
        if (strpos($combined, $kw) !== false) $matchedCrit[] = $kw;
    }
    $matchedHigh = [];
    foreach ($highWords as $kw) {
        if (strpos($combined, $kw) !== false) $matchedHigh[] = $kw;
    }
    $matchedLow = [];
    foreach ($lowWords as $kw) {
        if (strpos($combined, $kw) !== false) $matchedLow[] = $kw;
    }

    $score += count($matchedCrit) * 20 + count($matchedHigh) * 10 - count($matchedLow) * 10;
    $urgencyScore = max(18, min(99, $score));
    $allMatched   = array_slice(array_merge($matchedCrit, $matchedHigh, $matchedLow), 0, 8);

    if (!empty($matchedCrit) || $urgencyScore >= 82) {
        $suggestedPriority = 'Critical';
        $slaHours          = 12;
        $kwText            = !empty($matchedCrit) ? implode(', ', array_slice($matchedCrit, 0, 4)) : 'severe public safety indicators';
        $explanation       = sprintf(
            'AI Triage (Score %d/100): Flagged CRITICAL due to hazard indicators (%s). Auto-routed to %s (%s) with 12-Hour Emergency SLA.',
            $urgencyScore,
            $kwText,
            $dept['name'],
            $dept['code']
        );
    } elseif (!empty($matchedHigh) || $urgencyScore >= 62) {
        $suggestedPriority = 'High';
        $slaHours          = 24;
        $kwText            = !empty($matchedHigh) ? implode(', ', array_slice($matchedHigh, 0, 4)) : 'active civic disruption';
        $explanation       = sprintf(
            'AI Triage (Score %d/100): Assigned HIGH priority (%s). Auto-routed to %s (%s) with 24-Hour SLA.',
            $urgencyScore,
            $kwText,
            $dept['name'],
            $dept['code']
        );
    } elseif (!empty($matchedLow) && $urgencyScore < 45) {
        $suggestedPriority = 'Low';
        $slaHours          = 72;
        $explanation       = sprintf(
            'AI Triage (Score %d/100): Assigned LOW priority (%s). Routed to %s (%s) with 72-Hour routine SLA.',
            $urgencyScore,
            implode(', ', array_slice($matchedLow, 0, 3)),
            $dept['name'],
            $dept['code']
        );
    } else {
        $suggestedPriority = 'Medium';
        $slaHours          = (int)$dept['default_sla'];
        $explanation       = sprintf(
            'AI Triage (Score %d/100): Assigned standard MEDIUM priority. Routed to %s (%s) with %d-Hour statutory SLA.',
            $urgencyScore,
            $dept['name'],
            $dept['code'],
            $slaHours
        );
    }

    // Haversine + Token Jaccard Duplicate & 150m Cluster Detection
    $duplicates = [];
    $tokensNew  = array_unique(preg_split('/[^a-z0-9]+/', $combined, -1, PREG_SPLIT_NO_EMPTY));
    $tokensNew  = array_filter($tokensNew, fn($w) => strlen($w) >= 3);

    foreach ($existingComplaints as $ex) {
        $exCode = $ex['complaint_code'] ?? '';
        if ($exCode === '' || ($ex['status'] ?? '') === 'Resolved') continue;

        $exText   = strtolower(($ex['title'] ?? '') . ' ' . ($ex['description'] ?? '') . ' ' . ($ex['location_text'] ?? ''));
        $tokensEx = array_unique(preg_split('/[^a-z0-9]+/', $exText, -1, PREG_SPLIT_NO_EMPTY));
        $tokensEx = array_filter($tokensEx, fn($w) => strlen($w) >= 3);

        $inter = count(array_intersect($tokensNew, $tokensEx));
        $union = max(1, count(array_unique(array_merge($tokensNew, $tokensEx))));
        $sim   = ($inter / $union) * 65.0;

        if (strcasecmp((string)($ex['category'] ?? ''), $category) === 0) {
            $sim += 18.0;
        }

        $distKm = null;
        if ($lat !== null && $lng !== null && isset($ex['latitude'], $ex['longitude']) && $ex['latitude'] !== null && $ex['longitude'] !== null) {
            $distKm = round(haversine_distance_km($lat, $lng, (float)$ex['latitude'], (float)$ex['longitude']), 3);
            if ($distKm <= 0.16) {
                $sim += 28.0;
            } elseif ($distKm <= 0.50) {
                $sim += 14.0;
            }
        }

        $finalSim = round(min(99.0, $sim), 1);
        if ($finalSim >= 35.0) {
            $duplicates[] = [
                'complaint_code'   => $exCode,
                'title'            => $ex['title'] ?? '',
                'status'           => $ex['status'] ?? 'Submitted',
                'similarity_score' => $finalSim,
                'distance_km'      => $distKm,
                'is_within_150m'   => ($distKm !== null && $distKm <= 0.16),
                'match_reason'     => $finalSim . '% match' . ($distKm !== null ? ' · ' . round($distKm * 1000) . 'm away' : ''),
            ];
        }
    }

    usort($duplicates, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);

    return [
        'ok'                  => true,
        'analysis_success'    => true,
        'analysis_source'     => 'civicfix_hybrid_ai_v3',
        'suggested_priority'  => $suggestedPriority,
        'urgency_score'       => $urgencyScore,
        'matched_keywords'    => $allMatched,
        'assigned_department' => $dept['name'],
        'department_code'     => $dept['code'],
        'nodal_officer'       => $dept['officer'],
        'assigned_je'         => $dept['default_je'],
        'sla_hours'           => $slaHours,
        'explanation'         => $explanation,
        'possible_duplicates' => array_slice($duplicates, 0, 3),
    ];
}
