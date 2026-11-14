<?php
/**
 * CivicFix v3.0 — Citizen Grievance Tracking, Before/After Proof & Audit Ledger Endpoint (php/track_complaint.php)
 */

require_once __DIR__ . '/db.php';

$code = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
if ($code === '') {
    send_json(['ok' => false, 'error' => 'Please enter a valid Complaint ID (e.g., CIV-2026-104284).'], 400);
}

try {
    $pdo  = get_db_connection();
    $stmt = $pdo->prepare('SELECT * FROM complaints WHERE UPPER(complaint_code) = UPPER(:code) LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    if (!$row) {
        send_json(['ok' => false, 'error' => "No grievance found with ID '$code'. Try CIV-2026-104284 or CIV-2026-104283."], 404);
    }

    $histStmt = $pdo->prepare('
        SELECT old_status, new_status, action_note, changed_by, created_at
        FROM complaint_status_history
        WHERE complaint_id = :cid
        ORDER BY created_at ASC, id ASC
    ');
    $histStmt->execute([':cid' => $row['id']]);
    $history = $histStmt->fetchAll();

    $notifStmt = $pdo->prepare('
        SELECT channel, recipient_masked, subject, message_body, attachment_path, delivery_status, created_at
        FROM citizen_notifications
        WHERE complaint_id = :cid
        ORDER BY id DESC
    ');
    $notifStmt->execute([':cid' => $row['id']]);
    $notifications = $notifStmt->fetchAll();

    send_json([
        'ok'        => true,
        'complaint' => [
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
            'upvotes'               => (int)$row['upvotes'],
            'ai_suggested_priority' => $row['ai_suggested_priority'],
            'ai_explanation'        => $row['ai_explanation'],
            'admin_remarks'         => $row['admin_remarks'],
            'assigned_je'           => $row['assigned_je'],
            'escalation_tier'       => $row['escalation_tier'] ?? 'Tier 1 - Field JE',
            'citizen_rating'        => $row['citizen_rating'] !== null ? (int)$row['citizen_rating'] : null,
            'citizen_feedback'      => $row['citizen_feedback'],
            'reopen_count'          => (int)($row['reopen_count'] ?? 0),
            'citizen_name'          => $row['citizen_name'] ?: 'Anonymous Citizen',
            'citizen_phone_masked'  => mask_phone($row['citizen_phone'] ?? null),
            'citizen_email_masked'  => mask_email($row['citizen_email'] ?? null),
            'created_at'            => $row['created_at'],
            'updated_at'            => $row['updated_at'],
            'history'               => $history,
            'notifications'         => $notifications,
        ]
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Tracking lookup failed.', 'debug' => $e->getMessage()], 500);
}
