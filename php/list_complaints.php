<?php
/**
 * CivicFix v3.0 — Public & Command Center Grievance Feed with 4-Stage SLA Escalation Matrix (php/list_complaints.php)
 */

session_start();
require_once __DIR__ . '/db.php';

try {
    $pdo     = get_db_connection();
    $isAdmin = !empty($_SESSION['admin_logged_in']);

    $stmt = $pdo->query("
        SELECT *
        FROM complaints
        ORDER BY
            CASE priority
                WHEN 'Critical' THEN 1
                WHEN 'High' THEN 2
                WHEN 'Medium' THEN 3
                ELSE 4
            END ASC,
            id DESC
    ");
    $rows = $stmt->fetchAll();

    $items = [];
    $now   = time();

    foreach ($rows as $r) {
        $createdTs  = strtotime((string)$r['created_at']) ?: $now;
        $elapsedHrs = max(0.1, round(($now - $createdTs) / 3600, 1));
        $slaHours   = max(1, (int)($r['sla_hours'] ?? 48));
        $slaPct     = round(($elapsedHrs / $slaHours) * 100);

        if ($r['status'] === 'Resolved') {
            $slaState = 'SLA Met (Verified Closed)';
        } elseif ($slaPct >= 150) {
            $slaState = 'T+150% Deputy Commissioner Alert';
        } elseif ($slaPct >= 100) {
            $slaState = 'T+100% SLA Breached (SDO Escalated)';
        } elseif ($slaPct >= 50) {
            $slaState = 'T+50% SLA Warning Sent';
        } else {
            $slaState = 'Within SLA Window (' . max(1, (int)ceil($slaHours - $elapsedHrs)) . 'h left)';
        }

        $item = [
            'id'                    => (int)$r['id'],
            'complaint_code'        => $r['complaint_code'],
            'title'                 => $r['title'],
            'category'              => $r['category'],
            'department_code'       => $r['department_code'] ?? 'DEPT-GEN',
            'assigned_department'   => $r['assigned_department'] ?? 'General Civic & Tehbazari Nodal Cell',
            'description'           => $r['description'],
            'ward_zone'             => $r['ward_zone'] ?? 'Zone D - Model Town',
            'location_text'         => $r['location_text'],
            'latitude'              => $r['latitude'] !== null ? (float)$r['latitude'] : null,
            'longitude'             => $r['longitude'] !== null ? (float)$r['longitude'] : null,
            'photo_path'            => $r['photo_path'],
            'resolution_photo_path' => $r['resolution_photo_path'],
            'status'                => $r['status'],
            'priority'              => $r['priority'],
            'sla_hours'             => $slaHours,
            'elapsed_hours'         => $elapsedHrs,
            'sla_percent'           => $slaPct,
            'sla_state'             => $slaState,
            'intake_channel'        => $r['intake_channel'] ?? 'Web Portal',
            'cluster_count'         => (int)($r['cluster_count'] ?? 1),
            'ai_suggested_priority' => $r['ai_suggested_priority'],
            'ai_explanation'        => $r['ai_explanation'],
            'possible_duplicate_of' => $r['possible_duplicate_of'],
            'duplicate_similarity'  => $r['duplicate_similarity'] !== null ? (float)$r['duplicate_similarity'] : null,
            'upvotes'               => (int)$r['upvotes'],
            'admin_remarks'         => $r['admin_remarks'],
            'assigned_je'           => $r['assigned_je'],
            'escalation_tier'       => $r['escalation_tier'] ?? 'Tier 1 - Field JE',
            'citizen_rating'        => $r['citizen_rating'] !== null ? (int)$r['citizen_rating'] : null,
            'citizen_feedback'      => $r['citizen_feedback'],
            'reopen_count'          => (int)($r['reopen_count'] ?? 0),
            'citizen_name'          => $r['citizen_name'] ?: 'Anonymous Citizen',
            'citizen_phone_masked'  => mask_phone($r['citizen_phone'] ?? null),
            'citizen_email_masked'  => mask_email($r['citizen_email'] ?? null),
            'created_at'            => $r['created_at'],
            'updated_at'            => $r['updated_at'],
        ];

        if ($isAdmin) {
            $item['citizen_phone'] = $r['citizen_phone'];
            $item['citizen_email'] = $r['citizen_email'];
        }

        $items[] = $item;
    }

    send_json([
        'ok'         => true,
        'is_admin'   => $isAdmin,
        'count'      => count($items),
        'complaints' => $items,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Failed to load complaints.', 'debug' => $e->getMessage()], 500);
}
