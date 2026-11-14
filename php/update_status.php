<?php
/**
 * CivicFix v2.0 — Admin Status, JE Work-Order Dispatch & Automated Citizen SMS/Email Resolution Proof Endpoint (php/update_status.php)
 */

session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'error' => 'Method not allowed. Use POST.'], 405);
}

if (empty($_SESSION['admin_logged_in'])) {
    send_json([
        'ok'    => false,
        'error' => 'Unauthorized: Please sign in as a Nodal Officer to update complaints.'
    ], 403);
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

    $code          = trim((string)($input['complaint_code'] ?? ''));
    $newStatus     = trim((string)($input['status'] ?? ''));
    $newPriority   = trim((string)($input['priority'] ?? ''));
    $newDeptCode   = trim((string)($input['department_code'] ?? ''));
    $adminRemarks  = trim((string)($input['admin_remarks'] ?? ''));
    $actionNote    = trim((string)($input['action_note'] ?? ''));
    $assignedJe    = trim((string)($input['assigned_je'] ?? ''));
    $escalationTier= trim((string)($input['escalation_tier'] ?? ''));

    if ($code === '') {
        send_json(['ok' => false, 'error' => 'Complaint ID is required.'], 400);
    }

    $stmt = $pdo->prepare('SELECT * FROM complaints WHERE UPPER(complaint_code) = UPPER(:code) LIMIT 1');
    $stmt->execute([':code' => $code]);
    $existing = $stmt->fetch();

    if (!$existing) {
        send_json(['ok' => false, 'error' => 'Complaint not found.'], 404);
    }

    $deptCatalog = [
        'DEPT-BNR'  => 'B&R (Buildings & Roads) Engineering Wing',
        'DEPT-ELEC' => 'Municipal Electrical Wing & PSPCL Nodal Cell',
        'DEPT-SAN'  => 'Health & Sanitation (Swachh Bharat) Wing',
        'DEPT-WAT'  => 'O&M Water Supply & Potable Pipeline Cell',
        'DEPT-DRN'  => 'O&M Sewerage & Storm Drainage Wing',
        'DEPT-GEN'  => 'General Civic & Tehbazari Nodal Cell',
    ];

    $validStatuses   = ['Submitted', 'Under Review', 'In Progress', 'Resolved', 'Rejected'];
    $validPriorities = ['Low', 'Medium', 'High', 'Critical'];

    $finalStatus   = in_array($newStatus, $validStatuses, true) ? $newStatus : $existing['status'];
    $finalPriority = in_array($newPriority, $validPriorities, true) ? $newPriority : $existing['priority'];
    $finalDeptCode = isset($deptCatalog[$newDeptCode]) ? $newDeptCode : ($existing['department_code'] ?? 'DEPT-GEN');
    $finalDeptName = $deptCatalog[$finalDeptCode] ?? ($existing['assigned_department'] ?? 'General Civic & Tehbazari Nodal Cell');
    $finalRemarks  = $adminRemarks !== '' ? $adminRemarks : $existing['admin_remarks'];
    $finalJe       = $assignedJe !== '' ? $assignedJe : ($existing['assigned_je'] ?? null);
    $finalTier     = $escalationTier !== '' ? $escalationTier : ($existing['escalation_tier'] ?? 'Tier 1 - Field JE');

    $resolutionPhotoPath = $existing['resolution_photo_path'];
    if (!empty($_FILES['resolution_photo']) && $_FILES['resolution_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['resolution_photo'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            send_json(['ok' => false, 'error' => 'Resolution photo must be a valid image under 5 MB.'], 400);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowedMimes[$mime])) {
            send_json(['ok' => false, 'error' => 'Only JPG, PNG, and WEBP images are allowed.'], 400);
        }

        $uploadDir = dirname(__DIR__) . '/uploads/resolutions';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeFilename = 'resolved_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMimes[$mime];
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $safeFilename)) {
            send_json(['ok' => false, 'error' => 'Failed to save resolution photo on server.'], 500);
        }
        $resolutionPhotoPath = 'uploads/resolutions/' . $safeFilename;
    }

    $upd = $pdo->prepare('
        UPDATE complaints
        SET status = :status,
            priority = :priority,
            department_code = :dept_code,
            assigned_department = :dept_name,
            admin_remarks = :remarks,
            resolution_photo_path = :res_photo,
            assigned_je = :je,
            escalation_tier = :tier
        WHERE id = :id
    ');
    $upd->execute([
        ':status'    => $finalStatus,
        ':priority'  => $finalPriority,
        ':dept_code' => $finalDeptCode,
        ':dept_name' => $finalDeptName,
        ':remarks'   => $finalRemarks,
        ':res_photo' => $resolutionPhotoPath,
        ':je'        => $finalJe,
        ':tier'      => $finalTier,
        ':id'        => $existing['id'],
    ]);

    $changes = [];
    if ($finalDeptCode !== ($existing['department_code'] ?? '')) {
        $changes[] = 'Transferred to ' . $finalDeptName . ' (' . $finalDeptCode . ')';
    }
    if ($finalStatus !== $existing['status']) {
        $changes[] = 'Status updated from "' . $existing['status'] . '" to "' . $finalStatus . '"';
    }
    if ($finalPriority !== $existing['priority']) {
        $changes[] = 'Priority updated from "' . $existing['priority'] . '" to "' . $finalPriority . '"';
    }
    if ($finalJe && $finalJe !== ($existing['assigned_je'] ?? '')) {
        $changes[] = 'Field Work-Order assigned to ' . $finalJe;
    }
    if ($resolutionPhotoPath !== $existing['resolution_photo_path']) {
        $changes[] = 'Official After-Image resolution proof photograph attached';
    }
    if ($actionNote !== '') {
        $changes[] = $actionNote;
    } elseif ($adminRemarks !== '' && $adminRemarks !== $existing['admin_remarks']) {
        $changes[] = 'Departmental Resolution Description: ' . $adminRemarks;
    }
    if (empty($changes)) {
        $changes[] = 'Complaint reviewed by Nodal Officer.';
    }

    // Automatically dispatch Privacy-Preserving SMS & Email Resolution Proof when marked Resolved
    $dispatchedNotifications = [];
    if ($finalStatus === 'Resolved' && ($existing['status'] !== 'Resolved' || $resolutionPhotoPath !== $existing['resolution_photo_path'])) {
        $updatedRow = array_merge($existing, [
            'status'                => $finalStatus,
            'assigned_department'   => $finalDeptName,
            'admin_remarks'         => $finalRemarks,
            'resolution_photo_path' => $resolutionPhotoPath,
        ]);
        $dispatchedNotifications = dispatch_citizen_notification($pdo, $updatedRow, 'RESOLVED', (string)$finalRemarks);
        $changes[] = 'Automated SMS & Email Resolution Receipt (with After-Image & Feedback Link) dispatched to masked citizen contact';
    }

    $officerName = $_SESSION['admin_full_name'] ?? ($_SESSION['admin_username'] ?? 'Nodal Officer');

    $insHist = $pdo->prepare('
        INSERT INTO complaint_status_history (complaint_id, old_status, new_status, action_note, changed_by)
        VALUES (:cid, :old_s, :new_s, :note, :by)
    ');
    $insHist->execute([
        ':cid'   => $existing['id'],
        ':old_s' => $existing['status'],
        ':new_s' => $finalStatus,
        ':note'  => implode(' · ', $changes),
        ':by'    => $officerName,
    ]);

    send_json([
        'ok'                       => true,
        'message'                  => 'Complaint updated and logged in status history.',
        'complaint_code'           => $existing['complaint_code'],
        'status'                   => $finalStatus,
        'priority'                 => $finalPriority,
        'department_code'          => $finalDeptCode,
        'assigned_department'      => $finalDeptName,
        'admin_remarks'            => $finalRemarks,
        'assigned_je'              => $finalJe,
        'escalation_tier'          => $finalTier,
        'resolution_photo_path'    => $resolutionPhotoPath,
        'notifications_dispatched' => $dispatchedNotifications,
        'changed_by'               => $officerName,
        'updated_at'               => date('c'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Failed to update complaint.', 'debug' => $e->getMessage()], 500);
}
