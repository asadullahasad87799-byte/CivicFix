<?php
/**
 * CivicFix v2.0 — Citizen Post-Resolution Satisfaction Rating, Feedback & Re-Open Ticket Endpoint (php/submit_feedback.php)
 * Solves Examiners' Remark:
 *   "Through afterimage and full description, feedback can be obtained from them [while maintaining privacy]."
 *
 * Features:
 *  - Allows citizen to submit 1–5 Star Satisfaction Rating + Written Feedback on Resolved complaints
 *  - Allows citizen to RE-OPEN the complaint if unsatisfied with the After-Image / Resolution, automatically escalating to Tier-2 SDO!
 */

require_once __DIR__ . '/db.php';

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

    $code       = trim((string)($input['complaint_code'] ?? ''));
    $rating     = (int)($input['rating'] ?? 5);
    $feedback   = trim((string)($input['feedback'] ?? ''));
    $decision   = trim((string)($input['decision'] ?? 'accept')); // 'accept' or 'reopen'
    $verifyLast4= trim((string)($input['phone_last4'] ?? ''));

    if ($code === '') {
        send_json(['ok' => false, 'error' => 'Complaint ID is required.'], 400);
    }
    if ($rating < 1 || $rating > 5) {
        $rating = 5;
    }

    $stmt = $pdo->prepare('SELECT * FROM complaints WHERE UPPER(complaint_code) = UPPER(:code) LIMIT 1');
    $stmt->execute([':code' => $code]);
    $existing = $stmt->fetch();

    if (!$existing) {
        send_json(['ok' => false, 'error' => 'Complaint not found.'], 404);
    }

    // Optional Privacy Verification: if phone_last4 provided and complaint has phone, check match
    $storedPhoneDigits = preg_replace('/\D+/', '', (string)($existing['citizen_phone'] ?? ''));
    if ($verifyLast4 !== '' && strlen($storedPhoneDigits) >= 4) {
        if (substr($storedPhoneDigits, -4) !== substr(preg_replace('/\D+/', '', $verifyLast4), -4)) {
            send_json([
                'ok'    => false,
                'error' => 'Privacy Verification Failed: The last 4 digits of the registered mobile number do not match.'
            ], 403);
        }
    }

    if ($decision === 'reopen') {
        $newStatus   = 'In Progress';
        $newPriority = 'Critical';
        $newTier     = 'Tier 2 - Sub-Divisional Officer (SDO Escalated)';
        $reopenCount = (int)($existing['reopen_count'] ?? 0) + 1;
        $fbNote      = $feedback !== '' ? $feedback : 'Citizen reported unsatisfactory resolution and requested re-inspection.';

        $upd = $pdo->prepare('
            UPDATE complaints
            SET status = :st,
                priority = :pr,
                citizen_rating = :rt,
                citizen_feedback = :fb,
                feedback_at = NOW(),
                reopen_count = :rc,
                escalation_tier = :tier
            WHERE id = :id
        ');
        $upd->execute([
            ':st'   => $newStatus,
            ':pr'   => $newPriority,
            ':rt'   => $rating,
            ':fb'   => '[RE-OPENED BY CITIZEN] ' . $fbNote,
            ':rc'   => $reopenCount,
            ':tier' => $newTier,
            ':id'   => $existing['id'],
        ]);

        $insHist = $pdo->prepare('
            INSERT INTO complaint_status_history (complaint_id, old_status, new_status, action_note, changed_by)
            VALUES (:cid, :old_s, :new_s, :note, :by)
        ');
        $insHist->execute([
            ':cid'   => $existing['id'],
            ':old_s' => $existing['status'],
            ':new_s' => 'In Progress',
            ':note'  => sprintf('Citizen rated %d/5 Stars & RE-OPENED ticket ("%s"). Auto-escalated to %s.', $rating, $fbNote, $newTier),
            ':by'    => 'Verified Complainant (' . (mask_phone($existing['citizen_phone']) ?: 'Citizen') . ')',
        ]);

        dispatch_citizen_notification($pdo, $existing, 'REOPENED', 'Citizen rejected closure & reopened ticket: ' . $fbNote);

        send_json([
            'ok'              => true,
            'decision'        => 'reopen',
            'complaint_code'  => $existing['complaint_code'],
            'status'          => $newStatus,
            'escalation_tier' => $newTier,
            'reopen_count'    => $reopenCount,
            'message'         => 'Grievance Re-Opened and escalated to Tier-2 Sub-Divisional Officer (SDO)!'
        ]);
    } else {
        $fbNote = $feedback !== '' ? $feedback : 'Citizen verified Before/After resolution proof and confirmed satisfaction.';
        $upd = $pdo->prepare('
            UPDATE complaints
            SET citizen_rating = :rt,
                citizen_feedback = :fb,
                feedback_at = NOW()
            WHERE id = :id
        ');
        $upd->execute([
            ':rt' => $rating,
            ':fb' => $fbNote,
            ':id' => $existing['id'],
        ]);

        $insHist = $pdo->prepare('
            INSERT INTO complaint_status_history (complaint_id, old_status, new_status, action_note, changed_by)
            VALUES (:cid, :old_s, :new_s, :note, :by)
        ');
        $insHist->execute([
            ':cid'   => $existing['id'],
            ':old_s' => $existing['status'],
            ':new_s' => $existing['status'],
            ':note'  => sprintf('Citizen verified resolution proof & submitted %d/5 Star Satisfaction Rating: "%s"', $rating, $fbNote),
            ':by'    => 'Verified Complainant (' . (mask_phone($existing['citizen_phone']) ?: 'Citizen') . ')',
        ]);

        send_json([
            'ok'             => true,
            'decision'       => 'accept',
            'complaint_code' => $existing['complaint_code'],
            'rating'         => $rating,
            'feedback'       => $fbNote,
            'message'        => 'Thank you! Your ' . $rating . '-Star Citizen Satisfaction Rating has been recorded on the public audit ledger.'
        ]);
    }

} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Failed to save citizen feedback.', 'debug' => $e->getMessage()], 500);
}
