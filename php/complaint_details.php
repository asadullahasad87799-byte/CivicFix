<?php
/**
 * CivicFix v2.0 — Full Complaint Details, Notification Log & Feedback Endpoint (php/complaint_details.php)
 */

session_start();
require_once __DIR__ . '/db.php';

$code = trim((string)($_GET['id'] ?? ''));
if ($code === '') {
    send_json(['ok' => false, 'error' => 'Complaint ID is required.'], 400);
}

try {
    $pdo     = get_db_connection();
    $isAdmin = !empty($_SESSION['admin_logged_in']);

    $stmt = $pdo->prepare('SELECT * FROM complaints WHERE UPPER(complaint_code) = UPPER(:code) LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    if (!$row) {
        send_json(['ok' => false, 'error' => 'Complaint not found.'], 404);
    }

    $histStmt = $pdo->prepare('
        SELECT old_status, new_status, action_note, changed_by, created_at
        FROM complaint_status_history
        WHERE complaint_id = :cid
        ORDER BY created_at ASC, id ASC
    ');
    $histStmt->execute([':cid' => $row['id']]);
    $history = $histStmt->fetchAll();

    $aiStmt = $pdo->prepare('
        SELECT suggested_priority, urgency_score, matched_keywords, explanation, duplicate_candidates_json, analysis_source, created_at
        FROM ai_analysis_results
        WHERE complaint_id = :cid
        ORDER BY id DESC
        LIMIT 1
    ');
    $aiStmt->execute([':cid' => $row['id']]);
    $aiRow = $aiStmt->fetch();

    $aiDetails = $aiRow ? [
        'suggested_priority'   => $aiRow['suggested_priority'],
        'urgency_score'        => (int)$aiRow['urgency_score'],
        'matched_keywords'     => $aiRow['matched_keywords'],
        'explanation'          => $aiRow['explanation'],
        'duplicate_candidates' => json_decode($aiRow['duplicate_candidates_json'] ?: '[]', true) ?: [],
        'analysis_source'      => $aiRow['analysis_source'],
        'analyzed_at'          => $aiRow['created_at'],
    ] : null;

    $notifStmt = $pdo->prepare('
        SELECT channel, recipient_masked, subject, message_body, attachment_path, delivery_status, created_at
        FROM citizen_notifications
        WHERE complaint_id = :cid
        ORDER BY id DESC
    ');
    $notifStmt->execute([':cid' => $row['id']]);
    $notifications = $notifStmt->fetchAll();

    $detail = [
        'id'                    => (int)$row['id'],
        'complaint_code'        => $row['complaint_code'],
        'title'                 => $row['title'],
        'category'              => $row['category'],
        'department_code'       => $row['department_code'] ?? 'DEPT-GEN',
        'assigned_department'   => $row['assigned_department'] ?? 'General Civic & Tehbazari Nodal Cell',
        'description'           => $row['description'],
        'ward_zone'             => $row['ward_zone'],
        'location_text'         => $row['location_text'],
        'latitude'              => $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude'             => $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'photo_path'            => $row['photo_path'],
        'resolution_photo_path' => $row['resolution_photo_path'],
        'status'                => $row['status'],
        'priority'              => $row['priority'],
        'sla_hours'             => (int)($row['sla_hours'] ?? 48),
        'intake_channel'        => $row['intake_channel'] ?? 'Web Portal',
        'cluster_count'         => (int)($row['cluster_count'] ?? 1),
        'ai_suggested_priority' => $row['ai_suggested_priority'],
        'ai_explanation'        => $row['ai_explanation'],
        'possible_duplicate_of' => $row['possible_duplicate_of'],
        'duplicate_similarity'  => $row['duplicate_similarity'] !== null ? (float)$row['duplicate_similarity'] : null,
        'upvotes'               => (int)$row['upvotes'],
        'admin_remarks'         => $row['admin_remarks'],
        'assigned_je'           => $row['assigned_je'] ?? null,
        'escalation_tier'       => $row['escalation_tier'] ?? 'Tier 1 - Field JE',
        'citizen_rating'        => $row['citizen_rating'] !== null ? (int)$row['citizen_rating'] : null,
        'citizen_feedback'      => $row['citizen_feedback'] ?? null,
        'feedback_at'           => $row['feedback_at'] ?? null,
        'reopen_count'          => (int)($row['reopen_count'] ?? 0),
        'created_at'            => $row['created_at'],
        'updated_at'            => $row['updated_at'],
        'history'               => $history,
        'ai_details'            => $aiDetails,
        'notifications'         => $notifications,
        'citizen_name'          => $row['citizen_name'] ?: 'Citizen Report',
        'citizen_phone_masked'  => mask_phone($row['citizen_phone'] ?? null),
        'citizen_email_masked'  => mask_email($row['citizen_email'] ?? null),
    ];

    if ($isAdmin) {
        $detail['citizen_phone'] = $row['citizen_phone'];
        $detail['citizen_email'] = $row['citizen_email'];
    }

    send_json(['ok' => true, 'is_admin' => $isAdmin, 'complaint' => $detail]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Failed to load complaint details.', 'debug' => $e->getMessage()], 500);
}
