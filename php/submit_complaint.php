<?php
/**
 * CivicFix v3.0 — Grievance Submission, 150m Spatial Cluster Deduplication & Privacy-Masked Receipt Engine (php/submit_complaint.php)
 */

require_once __DIR__ . '/python_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

try {
    $pdo = get_db_connection();
    $input = $_POST;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $title         = trim((string)($input['title'] ?? ''));
    $category      = trim((string)($input['category'] ?? 'Other'));
    $userPriority  = trim((string)($input['priority'] ?? 'Auto'));
    $wardZone      = trim((string)($input['ward_zone'] ?? 'Zone D - Model Town'));
    $locationText  = trim((string)($input['location_text'] ?? ''));
    $description   = trim((string)($input['description'] ?? ''));
    $citizenName   = trim((string)($input['citizen_name'] ?? 'Anonymous Citizen')) ?: 'Anonymous Citizen';
    $citizenPhone  = trim((string)($input['citizen_phone'] ?? ''));
    $citizenEmail  = trim((string)($input['citizen_email'] ?? ''));
    $intakeChannel = trim((string)($input['intake_channel'] ?? 'Web Portal')) ?: 'Web Portal';
    $sosMode       = !empty($input['disaster_sos_mode']);

    $lat = isset($input['latitude']) && $input['latitude'] !== '' ? (float)$input['latitude'] : 30.9010;
    $lng = isset($input['longitude']) && $input['longitude'] !== '' ? (float)$input['longitude'] : 75.8573;

    if ($title === '' || $description === '' || $locationText === '') {
        send_json(['ok' => false, 'error' => 'Please provide a Grievance Title, Location Landmark, and Description.'], 400);
    }

    // Handle photo upload or live camera base64 snapshot
    $photoPath = null;
    $uploadDir = dirname(__DIR__) . '/uploads/issues';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
            $ext = 'jpg';
        }
        $fname = 'issue_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fname)) {
            $photoPath = 'uploads/issues/' . $fname;
        }
    } elseif (!empty($input['camera_base64'])) {
        $b64 = (string)$input['camera_base64'];
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $b64, $m)) {
            $ext  = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
            $data = base64_decode($m[2]);
            if ($data !== false) {
                $fname = 'cam_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                file_put_contents($uploadDir . '/' . $fname, $data);
                $photoPath = 'uploads/issues/' . $fname;
            }
        }
    }

    // Run AI Triage & Duplicate/Cluster Analysis
    $stmtEx = $pdo->query("
        SELECT id, complaint_code, title, category, description, location_text, latitude, longitude, status, cluster_count, upvotes
        FROM complaints
        ORDER BY id DESC
        LIMIT 80
    ");
    $existingList = $stmtEx->fetchAll();

    $ai = analyze_complaint_with_ai([
        'title'         => $title,
        'category'      => $category,
        'description'   => $description,
        'location_text' => $locationText,
        'latitude'      => $lat,
        'longitude'     => $lng,
    ], $existingList);

    $deptInfo = get_department_for_category($category, $title . ' ' . $description);
    $finalPriority = ($userPriority !== '' && $userPriority !== 'Auto')
        ? $userPriority
        : ($ai['suggested_priority'] ?? 'Medium');
    $slaHours = (int)($ai['sla_hours'] ?? $deptInfo['default_sla']);
    $escalationTier = $finalPriority === 'Critical' ? 'Tier 2 - Sub-Divisional Officer (SDO)' : 'Tier 1 - Field JE';

    if ($sosMode) {
        $finalPriority  = 'Critical';
        $slaHours       = 4;
        $escalationTier = 'Tier 3 - Emergency Disaster Cell';
        $intakeChannel  = 'Disaster-SOS';
    }

    // Check if an active complaint of the same category exists within 150m (0.15 km) for automatic spatial clustering
    $clusteredInto = null;
    foreach ($existingList as $ex) {
        if (($ex['status'] ?? '') === 'Resolved') continue;
        if (strcasecmp((string)$ex['category'], $category) !== 0) continue;
        if ($ex['latitude'] !== null && $ex['longitude'] !== null) {
            $distKm = haversine_distance_km($lat, $lng, (float)$ex['latitude'], (float)$ex['longitude']);
            if ($distKm <= 0.15) {
                $clusteredInto = $ex;
                break;
            }
        }
    }

    $possibleDupCode = null;
    $possibleDupSim  = null;
    if (!empty($ai['possible_duplicates'][0])) {
        $possibleDupCode = $ai['possible_duplicates'][0]['complaint_code'];
        $possibleDupSim  = (float)$ai['possible_duplicates'][0]['similarity_score'];
    }

    $code = 'CIV-' . date('Y') . '-' . random_int(100000, 999999);
    $clusterCount = 1;

    if ($clusteredInto) {
        // Increment Master Cluster count on the nearby master ticket as well so surge clustering is reflected
        $pdo->prepare("
            UPDATE complaints
            SET cluster_count = cluster_count + 1,
                upvotes = upvotes + 1
            WHERE id = :id
        ")->execute([':id' => $clusteredInto['id']]);
        $possibleDupCode = $clusteredInto['complaint_code'];
        $possibleDupSim  = 96.5;
    }

    $ins = $pdo->prepare("
        INSERT INTO complaints (
            complaint_code, title, category, department_code, assigned_department,
            description, citizen_name, citizen_phone, citizen_email, ward_zone,
            location_text, latitude, longitude, photo_path, status, priority,
            sla_hours, intake_channel, cluster_count, ai_suggested_priority,
            ai_explanation, possible_duplicate_of, duplicate_similarity,
            upvotes, assigned_je, escalation_tier
        ) VALUES (
            :code, :title, :cat, :dc, :dn,
            :desc, :cname, :cphone, :cemail, :ward,
            :loc, :lat, :lng, :photo, 'Submitted', :prio,
            :sla, :chan, :cc, :aiprio,
            :aiexp, :dupcode, :dupsim,
            1, :je, :tier
        )
    ");

    $ins->execute([
        ':code'    => $code,
        ':title'   => $title,
        ':cat'     => $category,
        ':dc'      => $ai['department_code'] ?? $deptInfo['code'],
        ':dn'      => $ai['assigned_department'] ?? $deptInfo['name'],
        ':desc'    => $description,
        ':cname'   => $citizenName,
        ':cphone'  => $citizenPhone ?: null,
        ':cemail'  => $citizenEmail ?: null,
        ':ward'    => $wardZone,
        ':loc'     => $locationText,
        ':lat'     => $lat,
        ':lng'     => $lng,
        ':photo'   => $photoPath,
        ':prio'    => $finalPriority,
        ':sla'     => $slaHours,
        ':chan'    => $intakeChannel,
        ':cc'      => $clusterCount,
        ':aiprio'  => $ai['suggested_priority'] ?? $finalPriority,
        ':aiexp'   => $ai['explanation'] ?? '',
        ':dupcode' => $possibleDupCode,
        ':dupsim'  => $possibleDupSim,
        ':je'      => $deptInfo['default_je'],
        ':tier'    => $escalationTier,
    ]);

    $cid = (int)$pdo->lastInsertId();

    // Log status history
    $histNote = sprintf(
        'Registered via %s · Assigned to %s (%s) · Field JE: %s · Statutory SLA: %dh',
        $intakeChannel,
        $ai['assigned_department'] ?? $deptInfo['name'],
        $ai['department_code'] ?? $deptInfo['code'],
        $deptInfo['default_je'],
        $slaHours
    );
    if ($clusteredInto) {
        $histNote .= sprintf(' · Linked to 150m Spatial Cluster Master Ticket %s', $clusteredInto['complaint_code']);
    }

    $pdo->prepare("
        INSERT INTO complaint_status_history (complaint_id, old_status, new_status, action_note, changed_by)
        VALUES (:cid, NULL, 'Submitted', :note, 'CivicFix v3.0 AI Intake Engine')
    ")->execute([':cid' => $cid, ':note' => $histNote]);

    // Save AI analysis record
    $pdo->prepare("
        INSERT INTO ai_analysis_results (complaint_id, suggested_priority, urgency_score, matched_keywords, explanation, duplicate_candidates_json, analysis_source)
        VALUES (:cid, :sp, :sc, :kw, :exp, :dups, :src)
    ")->execute([
        ':cid'  => $cid,
        ':sp'   => $ai['suggested_priority'] ?? $finalPriority,
        ':sc'   => (int)($ai['urgency_score'] ?? 60),
        ':kw'   => implode(', ', $ai['matched_keywords'] ?? []),
        ':exp'  => $ai['explanation'] ?? '',
        ':dups' => json_encode($ai['possible_duplicates'] ?? []),
        ':src'  => $ai['analysis_source'] ?? 'civicfix_hybrid_ai_v3',
    ]);

    // Dispatch privacy-masked acknowledgement SMS/Email
    $notifs = dispatch_citizen_notification($pdo, [
        'id'                  => $cid,
        'complaint_code'      => $code,
        'title'               => $title,
        'assigned_department' => $ai['assigned_department'] ?? $deptInfo['name'],
        'citizen_phone'       => $citizenPhone,
        'citizen_email'       => $citizenEmail,
        'sla_hours'           => $slaHours,
    ], 'REGISTERED');

    send_json([
        'ok'                       => true,
        'message'                  => 'Grievance registered successfully!',
        'complaint_code'           => $code,
        'status'                   => 'Submitted',
        'priority'                 => $finalPriority,
        'sla_hours'                => $slaHours,
        'department_code'          => $ai['department_code'] ?? $deptInfo['code'],
        'assigned_department'      => $ai['assigned_department'] ?? $deptInfo['name'],
        'assigned_je'              => $deptInfo['default_je'],
        'escalation_tier'          => $escalationTier,
        'ai_suggested_priority'    => $ai['suggested_priority'] ?? $finalPriority,
        'urgency_score'            => (int)($ai['urgency_score'] ?? 60),
        'ai_explanation'           => $ai['explanation'] ?? '',
        'possible_duplicate_of'    => $possibleDupCode,
        'clustered_master_ticket'  => $clusteredInto ? $clusteredInto['complaint_code'] : null,
        'citizen_phone_masked'     => mask_phone($citizenPhone),
        'citizen_email_masked'     => mask_email($citizenEmail),
        'notifications_dispatched' => $notifs,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Failed to submit complaint.', 'debug' => $e->getMessage()], 500);
}
