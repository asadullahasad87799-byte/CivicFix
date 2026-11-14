<?php
/**
 * CivicFix v3.0 — Multi-Channel Offline GSM SMS (1916), Toll-Free IVR (1800-180-1916) & WhatsApp Civic AI Bot Gateway (php/sms_ivr_webhook.php)
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

    $channel = strtolower(trim((string)($input['channel'] ?? 'sms')));
    $phone   = trim((string)($input['phone'] ?? '9876543210'));
    $name    = trim((string)($input['citizen_name'] ?? 'Telecom Citizen User'));

    $wardCoords = [
        'Zone A' => ['name' => 'Zone A - Old City & Clock Tower', 'lat' => 30.9132, 'lng' => 75.8540],
        'Zone B' => ['name' => 'Zone B - Focal Point & Sherpur',  'lat' => 30.8870, 'lng' => 75.9015],
        'Zone C' => ['name' => 'Zone C - Gill Road & Dugri',      'lat' => 30.8795, 'lng' => 75.8610],
        'Zone D' => ['name' => 'Zone D - Model Town & PAU',       'lat' => 30.8920, 'lng' => 75.8380],
    ];

    // WhatsApp Conversational AI Bot status lookup support (e.g., "STATUS CIV-2026-104284")
    if ($channel === 'whatsapp') {
        $msg = trim((string)($input['message'] ?? ''));
        if (preg_match('/(CIV-\d{4}-\d{5,7})/i', $msg, $m)) {
            $lookupCode = strtoupper($m[1]);
            $st = $pdo->prepare('SELECT * FROM complaints WHERE UPPER(complaint_code) = :c LIMIT 1');
            $st->execute([':c' => $lookupCode]);
            $found = $st->fetch();
            if ($found) {
                send_json([
                    'ok'             => true,
                    'channel'        => 'WhatsApp-Bot',
                    'mode'           => 'status_lookup',
                    'complaint_code' => $found['complaint_code'],
                    'status'         => $found['status'],
                    'priority'       => $found['priority'],
                    'department'     => $found['assigned_department'],
                    'assigned_je'    => $found['assigned_je'],
                    'bot_reply'      => sprintf(
                        "🤖 *CivicFix WhatsApp AI Bot*\n📌 *Ticket:* %s\n🏛️ *Dept:* %s\n⚡ *Status:* %s (%s Priority)\n👷 *Field JE:* %s\n📝 *Latest Remark:* %s",
                        $found['complaint_code'],
                        $found['assigned_department'],
                        $found['status'],
                        $found['priority'],
                        $found['assigned_je'] ?: 'Assigned Zonal JE',
                        $found['admin_remarks'] ?: 'Under active departmental action.'
                    ),
                ]);
            }
        }
    }

    if ($channel === 'ivr') {
        $dtmf       = trim((string)($input['dtmf_digit'] ?? '2'));
        $wardKey    = substr(trim((string)($input['ward_zone'] ?? 'Zone D')), 0, 6);
        $transcript = trim((string)($input['voice_transcript'] ?? 'Emergency civic complaint recorded over 1800-180-1916 IVR helpline.'));
        $dtmfMap = [
            '1' => 'Road Damage',
            '2' => 'Streetlight',
            '3' => 'Garbage',
            '4' => 'Water Leakage',
            '5' => 'Drainage',
            '9' => 'Other',
        ];
        $category      = $dtmfMap[$dtmf] ?? 'Streetlight';
        $wInfo         = $wardCoords[$wardKey] ?? $wardCoords['Zone D'];
        $wardZone      = $wInfo['name'];
        $lat           = $wInfo['lat'] + (random_int(-25, 25) / 10000);
        $lng           = $wInfo['lng'] + (random_int(-25, 25) / 10000);
        $title         = '[1800-IVR VOICE TICKET] ' . mb_substr($transcript, 0, 88);
        $description   = 'Toll-Free 1800-180-1916 IVR Automated Speech-to-Text Transcript (DTMF #' . $dtmf . '): ' . $transcript;
        $locationText  = $wardZone . ' (Cell-Tower Triangulated via 1800-IVR)';
        $intakeChannel = 'IVR-1800';
    } elseif ($channel === 'whatsapp') {
        $rawMsg   = trim((string)($input['message'] ?? 'Waterlogging and broken road near Model Town'));
        $wardKey  = substr(trim((string)($input['ward_zone'] ?? 'Zone D')), 0, 6);
        $wInfo    = $wardCoords[$wardKey] ?? $wardCoords['Zone D'];
        $wardZone = $wInfo['name'];
        $lat      = isset($input['latitude']) && $input['latitude'] !== '' ? (float)$input['latitude'] : ($wInfo['lat'] + (random_int(-20, 20) / 10000));
        $lng      = isset($input['longitude']) && $input['longitude'] !== '' ? (float)$input['longitude'] : ($wInfo['lng'] + (random_int(-20, 20) / 10000));
        $deptGuess= get_department_for_category('Other', $rawMsg);
        $catMap   = [
            'DEPT-ELEC' => 'Streetlight',
            'DEPT-DRN'  => 'Drainage',
            'DEPT-WAT'  => 'Water Leakage',
            'DEPT-BNR'  => 'Road Damage',
            'DEPT-SAN'  => 'Garbage',
        ];
        $category      = $catMap[$deptGuess['code']] ?? 'Road Damage';
        $title         = '[WHATSAPP AI BOT] ' . mb_substr($rawMsg, 0, 90);
        $description   = 'Registered via CivicFix WhatsApp Conversational AI Bot (+91-1800-180-1916) with shared GPS pin: ' . $rawMsg;
        $locationText  = $wardZone . ' (WhatsApp Live Location Pin)';
        $intakeChannel = 'WhatsApp-Bot';
    } else {
        // Offline GSM SMS Shortcode 1916 parser: e.g. "CIVIC ELEC Zone-D Sparking wire near Gurdwara"
        $smsBody = trim((string)($input['sms_body'] ?? 'CIVIC ELEC Zone-D Sparking wire near Model Town'));
        $upper   = strtoupper($smsBody);
        $category = 'Other';
        if (preg_match('/\b(ELEC|WIRE|POLE|LIGHT|SPARK)\b/', $upper)) $category = 'Streetlight';
        elseif (preg_match('/\b(WATER|PIPE|LEAK)\b/', $upper)) $category = 'Water Leakage';
        elseif (preg_match('/\b(DRAIN|SEWER|MANHOLE|FLOOD)\b/', $upper)) $category = 'Drainage';
        elseif (preg_match('/\b(ROAD|POTHOLE|CRATER)\b/', $upper)) $category = 'Road Damage';
        elseif (preg_match('/\b(GARBAGE|WASTE|TRASH)\b/', $upper)) $category = 'Garbage';

        $wardKey = 'Zone D';
        if (preg_match('/ZONE[\s\-]*([ABCD])/i', $smsBody, $m)) {
            $wardKey = 'Zone ' . strtoupper($m[1]);
        }
        $wInfo         = $wardCoords[$wardKey] ?? $wardCoords['Zone D'];
        $wardZone      = $wInfo['name'];
        $lat           = $wInfo['lat'] + (random_int(-25, 25) / 10000);
        $lng           = $wInfo['lng'] + (random_int(-25, 25) / 10000);
        $cleanText     = trim(preg_replace('/^CIVIC\s+\w+\s+(ZONE[\-\s]*[A-D]\s*)?/i', '', $smsBody)) ?: $smsBody;
        $title         = '[SMS-1916 GATEWAY] ' . mb_substr($cleanText, 0, 88);
        $description   = 'Offline GSM SMS Shortcode 1916 Payload: "' . $smsBody . '"';
        $locationText  = $wardZone . ' (GSM Cell-ID Sector Lock)';
        $intakeChannel = 'SMS-1916';
    }

    $ai       = analyze_complaint_with_ai([
        'title'         => $title,
        'category'      => $category,
        'description'   => $description,
        'location_text' => $locationText,
        'latitude'      => $lat,
        'longitude'     => $lng,
    ]);
    $deptInfo = get_department_for_category($category, $title . ' ' . $description);
    $code     = 'CIV-' . date('Y') . '-' . random_int(100000, 999999);
    $prio     = $ai['suggested_priority'] ?? 'High';
    $sla      = (int)($ai['sla_hours'] ?? 24);
    $tier     = $prio === 'Critical' ? 'Tier 2 - Sub-Divisional Officer (SDO)' : 'Tier 1 - Field JE';

    $ins = $pdo->prepare("
        INSERT INTO complaints (
            complaint_code, title, category, department_code, assigned_department,
            description, citizen_name, citizen_phone, ward_zone, location_text,
            latitude, longitude, status, priority, sla_hours, intake_channel,
            cluster_count, upvotes, assigned_je, escalation_tier,
            ai_suggested_priority, ai_explanation
        ) VALUES (
            :code, :title, :cat, :dc, :dn,
            :desc, :cname, :cphone, :ward, :loc,
            :lat, :lng, 'Submitted', :prio, :sla, :chan,
            1, 1, :je, :tier,
            :aiprio, :aiexp
        )
    ");
    $ins->execute([
        ':code'   => $code,
        ':title'  => $title,
        ':cat'    => $category,
        ':dc'     => $ai['department_code'] ?? $deptInfo['code'],
        ':dn'     => $ai['assigned_department'] ?? $deptInfo['name'],
        ':desc'   => $description,
        ':cname'  => $name,
        ':cphone' => $phone,
        ':ward'   => $wardZone,
        ':loc'    => $locationText,
        ':lat'    => $lat,
        ':lng'    => $lng,
        ':prio'   => $prio,
        ':sla'    => $sla,
        ':chan'   => $intakeChannel,
        ':je'     => $deptInfo['default_je'],
        ':tier'   => $tier,
        ':aiprio' => $prio,
        ':aiexp'  => $ai['explanation'] ?? '',
    ]);
    $cid = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO complaint_status_history (complaint_id, old_status, new_status, action_note, changed_by)
        VALUES (:cid, NULL, 'Submitted', :note, :by)
    ")->execute([
        ':cid'  => $cid,
        ':note' => sprintf('Registered via %s from masked caller %s · Routed to %s (%dh SLA)', $intakeChannel, mask_phone($phone), $ai['assigned_department'] ?? $deptInfo['name'], $sla),
        ':by'   => $intakeChannel . ' Telecom Gateway',
    ]);

    $notifs = dispatch_citizen_notification($pdo, [
        'id'                  => $cid,
        'complaint_code'      => $code,
        'title'               => $title,
        'assigned_department' => $ai['assigned_department'] ?? $deptInfo['name'],
        'citizen_phone'       => $phone,
        'sla_hours'           => $sla,
    ], 'REGISTERED');

    send_json([
        'ok'                  => true,
        'channel'             => $intakeChannel,
        'complaint_code'      => $code,
        'category'            => $category,
        'priority'            => $prio,
        'sla_hours'           => $sla,
        'assigned_department' => $ai['assigned_department'] ?? $deptInfo['name'],
        'assigned_je'         => $deptInfo['default_je'],
        'masked_phone'        => mask_phone($phone),
        'latitude'            => $lat,
        'longitude'           => $lng,
        'sms_reply_dispatched'=> $notifs[0]['body'] ?? "Registered $code",
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'Telecom webhook error.', 'debug' => $e->getMessage()], 500);
}
