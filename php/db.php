<?php
/**
 * CivicFix v3.0 — Database Connection, Auto-Migration, Privacy Masking & Seed Engine (php/db.php)
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'civicfix_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function send_json(array $payload, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mask_phone(?string $phone): string {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($digits) < 6) {
        return $digits !== '' ? 'XXXXXX' . substr($digits, -2) : 'Anonymous (Privacy Protected)';
    }
    $first2 = substr($digits, 0, 2);
    $last2  = substr($digits, -2);
    return $first2 . 'XXXXXX' . $last2;
}

function mask_email(?string $email): string {
    $email = trim((string)$email);
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }
    [$user, $domain] = explode('@', $email, 2);
    $uFirst = substr($user, 0, 1);
    $uLast  = strlen($user) > 2 ? substr($user, -1) : '';
    return $uFirst . '***' . $uLast . '@' . $domain;
}

function get_department_for_category(string $category, string $combinedText = ''): array {
    $catalog = [
        'Road Damage' => [
            'code'        => 'DEPT-BNR',
            'name'        => 'B&R (Buildings & Roads) Engineering Wing',
            'officer'     => 'Executive Engineer (XEN - Roads & Bridges)',
            'default_sla' => 48,
            'default_je'  => 'Er. Gurpreet Singh (JE - B&R Zone D)',
        ],
        'Streetlight' => [
            'code'        => 'DEPT-ELEC',
            'name'        => 'Municipal Electrical Wing & PSPCL Nodal Cell',
            'officer'     => 'Sub-Divisional Officer (Electrical / Streetlight)',
            'default_sla' => 12,
            'default_je'  => 'Er. Amanpreet Kaur (JE - Electrical & PSPCL Cell)',
        ],
        'Garbage' => [
            'code'        => 'DEPT-SAN',
            'name'        => 'Health & Sanitation (Swachh Bharat) Wing',
            'officer'     => 'Chief Sanitary Inspector (CSI - Solid Waste)',
            'default_sla' => 24,
            'default_je'  => 'Sh. Rajesh Sharma (Sanitary Inspector - Swachhata)',
        ],
        'Water Leakage' => [
            'code'        => 'DEPT-WAT',
            'name'        => 'O&M Water Supply & Potable Pipeline Cell',
            'officer'     => 'Sub-Divisional Engineer (O&M Water Supply)',
            'default_sla' => 24,
            'default_je'  => 'Er. Harजिंदर Sidhu (JE - O&M Water Supply)',
        ],
        'Drainage' => [
            'code'        => 'DEPT-DRN',
            'name'        => 'O&M Sewerage & Storm Drainage Wing',
            'officer'     => 'Executive Engineer (Sewerage & Drainage)',
            'default_sla' => 36,
            'default_je'  => 'Er. Vikramjit Gill (JE - Storm Drainage & Sewer)',
        ],
        'Other' => [
            'code'        => 'DEPT-GEN',
            'name'        => 'General Civic & Tehbazari Nodal Cell',
            'officer'     => 'Zonal Nodal Grievance Officer',
            'default_sla' => 72,
            'default_je'  => 'Sh. Manpreet Verma (Zonal Field Inspector)',
        ],
    ];

    $text = strtolower($category . ' ' . $combinedText);
    if (preg_match('/(spark|wire|pole|transformer|electric|shock|streetlight|light)/i', $text)) {
        return $catalog['Streetlight'];
    }
    if (preg_match('/(manhole|sewer|drain|waterlog|gutter|nullah)/i', $text)) {
        return $catalog['Drainage'];
    }
    if (preg_match('/(water pipe|pipeline|leak|drinking water|supply burst)/i', $text)) {
        return $catalog['Water Leakage'];
    }
    if (preg_match('/(pothole|crater|road|asphalt|footpath|pavement)/i', $text)) {
        return $catalog['Road Damage'];
    }
    if (preg_match('/(garbage|waste|trash|dump|dustbin|sanitation|dead animal)/i', $text)) {
        return $catalog['Garbage'];
    }
    return $catalog[$category] ?? $catalog['Other'];
}

function haversine_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $r * $c;
}

function dispatch_citizen_notification(PDO $pdo, array $complaint, string $eventType = 'RESOLVED', string $customNote = ''): array {
    $dispatched = [];
    $cid        = (int)($complaint['id'] ?? 0);
    $code       = (string)($complaint['complaint_code'] ?? 'CIV-2026-000000');
    $title      = (string)($complaint['title'] ?? 'Civic Grievance');
    $dept       = (string)($complaint['assigned_department'] ?? 'Municipal Department');
    $resPhoto   = (string)($complaint['resolution_photo_path'] ?? '');
    $phoneMask  = mask_phone($complaint['citizen_phone'] ?? '');
    $emailMask  = mask_email($complaint['citizen_email'] ?? '');

    if ($eventType === 'RESOLVED') {
        $smsSubject = "SMS Dispatch [1916-GOV] -> $phoneMask";
        $smsBody    = sprintf(
            "[CivicFix Gov Alert] Dear Citizen, your grievance %s (%s) has been RESOLVED by %s. Resolution Note: %s. View Before/After Photo Proof & rate satisfaction (1-5 Stars) or Re-Open at: http://localhost/CivicFix/?track=%s",
            $code,
            mb_substr($title, 0, 38),
            $dept,
            $customNote !== '' ? $customNote : 'Verified field rectification completed.',
            $code
        );
    } elseif ($eventType === 'REOPENED') {
        $smsSubject = "Tier-2 SDO Escalation Alert [1916-GOV] -> $phoneMask";
        $smsBody    = sprintf(
            "[CivicFix Escalation] Grievance %s has been RE-OPENED on citizen appeal and escalated to Tier-2 Sub-Divisional Officer (SDO). Note: %s",
            $code,
            $customNote
        );
    } else {
        $smsSubject = "Acknowledgement SMS [1916-GOV] -> $phoneMask";
        $smsBody    = sprintf(
            "[CivicFix Registered] Grievance ID %s logged under %s (SLA: %dh). Track live GPS & status at http://localhost/CivicFix/?track=%s",
            $code,
            $dept,
            (int)($complaint['sla_hours'] ?? 24),
            $code
        );
    }

    $ins = $pdo->prepare('
        INSERT INTO citizen_notifications (complaint_id, channel, recipient_masked, subject, message_body, attachment_path, delivery_status)
        VALUES (:cid, :ch, :rec, :subj, :body, :att, :st)
    ');

    if ($cid > 0) {
        $ins->execute([
            ':cid'  => $cid,
            ':ch'   => 'SMS (1916 Gateway)',
            ':rec'  => $phoneMask,
            ':subj' => $smsSubject,
            ':body' => $smsBody,
            ':att'  => $resPhoto ?: null,
            ':st'   => 'DELIVERED (GSM-1916)',
        ]);
        $dispatched[] = ['channel' => 'SMS', 'recipient' => $phoneMask, 'subject' => $smsSubject, 'body' => $smsBody];

        if ($emailMask !== '') {
            $emailSubj = "[CivicFix Official Proof] Grievance $code - $eventType ($dept)";
            $emailBody = $smsBody . ($resPhoto ? " | Attached After-Image Evidence: $resPhoto" : "");
            $ins->execute([
                ':cid'  => $cid,
                ':ch'   => 'Email Receipt (SMTP)',
                ':rec'  => $emailMask,
                ':subj' => $emailSubj,
                ':body' => $emailBody,
                ':att'  => $resPhoto ?: null,
                ':st'   => 'SENT (250 OK)',
            ]);
            $dispatched[] = ['channel' => 'Email', 'recipient' => $emailMask, 'subject' => $emailSubj, 'body' => $emailBody];
        }
    }

    return $dispatched;
}

function ensure_sample_svg_assets(): void {
    $root = dirname(__DIR__);
    $issuesDir = $root . '/uploads/issues';
    $resDir    = $root . '/uploads/resolutions';
    if (!is_dir($issuesDir)) @mkdir($issuesDir, 0755, true);
    if (!is_dir($resDir)) @mkdir($resDir, 0755, true);

    $beforeSvg = $issuesDir . '/sample_before_pothole.svg';
    if (!file_exists($beforeSvg)) {
        file_put_contents($beforeSvg, <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400" viewBox="0 0 640 400">
  <rect width="640" height="400" fill="#1e293b"/>
  <polygon points="0,130 640,130 640,400 0,400" fill="#334155"/>
  <line x1="60" y1="260" x2="580" y2="260" stroke="#facc15" stroke-width="6" stroke-dasharray="28,18"/>
  <ellipse cx="320" cy="275" rx="145" ry="62" fill="#0f172a" stroke="#ef4444" stroke-width="4"/>
  <ellipse cx="325" cy="282" rx="115" ry="44" fill="#0284c7" opacity="0.65"/>
  <rect x="18" y="18" width="604" height="54" rx="8" fill="#7f1d1d" opacity="0.92"/>
  <text x="34" y="42" fill="#ffffff" font-family="Arial,sans-serif" font-size="16" font-weight="bold">BEFORE EVIDENCE • GEO-TAGGED CITIZEN PHOTO (30.8965° N, 75.8458° E)</text>
  <text x="34" y="62" fill="#fecaca" font-family="Arial,sans-serif" font-size="13">Deep Waterlogged Pothole &amp; Broken Asphalt on Pakhowal Road • AI Vision Hazard: 91/100</text>
</svg>
SVG
        );
    }

    $afterSvg = $resDir . '/sample_after_repaired.svg';
    if (!file_exists($afterSvg)) {
        file_put_contents($afterSvg, <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400" viewBox="0 0 640 400">
  <rect width="640" height="400" fill="#0f172a"/>
  <polygon points="0,130 640,130 640,400 0,400" fill="#1e293b"/>
  <line x1="60" y1="260" x2="580" y2="260" stroke="#facc15" stroke-width="6" stroke-dasharray="28,18"/>
  <rect x="165" y="210" width="310" height="125" rx="14" fill="#064e3b" stroke="#10b981" stroke-width="4"/>
  <text x="320" y="275" text-anchor="middle" fill="#a7f3d0" font-family="Arial,sans-serif" font-size="18" font-weight="bold">FRESH BITUMEN HOT-MIX PATCHED &amp; COMPACTED</text>
  <rect x="18" y="18" width="604" height="54" rx="8" fill="#065f46" opacity="0.95"/>
  <text x="34" y="42" fill="#ffffff" font-family="Arial,sans-serif" font-size="16" font-weight="bold">AFTER RESOLUTION PROOF • JE VERIFIED (30.8965° N, 75.8458° E)</text>
  <text x="34" y="62" fill="#d1fae5" font-family="Arial,sans-serif" font-size="13">Inspected by Er. Gurpreet Singh (JE - B&amp;R Wing) • Dispatched to Citizen via SMS &amp; Email</text>
</svg>
SVG
        );
    }
}

function get_db_connection(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');

    run_auto_migrations($pdo);
    ensure_sample_svg_assets();
    return $pdo;
}

function run_auto_migrations(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(120) NOT NULL,
            department VARCHAR(120) NOT NULL DEFAULT 'District & Municipal Command Center',
            role VARCHAR(40) NOT NULL DEFAULT 'super_admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_code VARCHAR(32) NOT NULL UNIQUE,
            title VARCHAR(160) NOT NULL,
            category VARCHAR(60) NOT NULL,
            department_code VARCHAR(32) NOT NULL DEFAULT 'DEPT-GEN',
            assigned_department VARCHAR(160) NOT NULL DEFAULT 'General Civic & Tehbazari Nodal Cell',
            description TEXT NOT NULL,
            citizen_name VARCHAR(100) DEFAULT 'Anonymous Citizen',
            citizen_phone VARCHAR(30) DEFAULT NULL,
            citizen_email VARCHAR(120) DEFAULT NULL,
            ward_zone VARCHAR(100) DEFAULT 'Zone D - Model Town',
            location_text VARCHAR(255) NOT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            photo_path VARCHAR(255) DEFAULT NULL,
            resolution_photo_path VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'Submitted',
            priority VARCHAR(24) NOT NULL DEFAULT 'Medium',
            sla_hours INT NOT NULL DEFAULT 48,
            intake_channel VARCHAR(40) NOT NULL DEFAULT 'Web Portal',
            cluster_count INT NOT NULL DEFAULT 1,
            ai_suggested_priority VARCHAR(24) DEFAULT NULL,
            ai_explanation TEXT DEFAULT NULL,
            possible_duplicate_of VARCHAR(32) DEFAULT NULL,
            duplicate_similarity DECIMAL(5,2) DEFAULT NULL,
            upvotes INT NOT NULL DEFAULT 1,
            admin_remarks TEXT DEFAULT NULL,
            assigned_je VARCHAR(120) DEFAULT NULL,
            escalation_tier VARCHAR(80) DEFAULT 'Tier 1 - Field JE',
            citizen_rating TINYINT DEFAULT NULL,
            citizen_feedback TEXT DEFAULT NULL,
            feedback_at DATETIME DEFAULT NULL,
            reopen_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaint_status_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_id INT NOT NULL,
            old_status VARCHAR(32) DEFAULT NULL,
            new_status VARCHAR(32) NOT NULL,
            action_note TEXT NOT NULL,
            changed_by VARCHAR(120) NOT NULL DEFAULT 'System',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_analysis_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_id INT NOT NULL,
            suggested_priority VARCHAR(24) NOT NULL,
            urgency_score INT NOT NULL DEFAULT 50,
            matched_keywords VARCHAR(255) DEFAULT NULL,
            explanation TEXT DEFAULT NULL,
            duplicate_candidates_json TEXT DEFAULT NULL,
            analysis_source VARCHAR(64) DEFAULT 'heuristic_v3',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS citizen_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_id INT NOT NULL,
            channel VARCHAR(32) NOT NULL,
            recipient_masked VARCHAR(80) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            message_body TEXT NOT NULL,
            attachment_path VARCHAR(255) DEFAULT NULL,
            delivery_status VARCHAR(40) NOT NULL DEFAULT 'DELIVERED',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Ensure v2/v3 columns exist if upgrading from an older table
    $colsStmt = $pdo->query("SHOW COLUMNS FROM complaints");
    $existingCols = array_column($colsStmt->fetchAll(), 'Field');
    $alterMap = [
        'department_code'       => "ADD COLUMN department_code VARCHAR(32) NOT NULL DEFAULT 'DEPT-GEN'",
        'assigned_department'   => "ADD COLUMN assigned_department VARCHAR(160) NOT NULL DEFAULT 'General Civic & Tehbazari Nodal Cell'",
        'citizen_email'         => "ADD COLUMN citizen_email VARCHAR(120) DEFAULT NULL",
        'ward_zone'             => "ADD COLUMN ward_zone VARCHAR(100) DEFAULT 'Zone D - Model Town'",
        'resolution_photo_path' => "ADD COLUMN resolution_photo_path VARCHAR(255) DEFAULT NULL",
        'sla_hours'             => "ADD COLUMN sla_hours INT NOT NULL DEFAULT 48",
        'intake_channel'        => "ADD COLUMN intake_channel VARCHAR(40) NOT NULL DEFAULT 'Web Portal'",
        'cluster_count'         => "ADD COLUMN cluster_count INT NOT NULL DEFAULT 1",
        'assigned_je'           => "ADD COLUMN assigned_je VARCHAR(120) DEFAULT NULL",
        'escalation_tier'       => "ADD COLUMN escalation_tier VARCHAR(80) DEFAULT 'Tier 1 - Field JE'",
        'citizen_rating'        => "ADD COLUMN citizen_rating TINYINT DEFAULT NULL",
        'citizen_feedback'      => "ADD COLUMN citizen_feedback TEXT DEFAULT NULL",
        'feedback_at'           => "ADD COLUMN feedback_at DATETIME DEFAULT NULL",
        'reopen_count'          => "ADD COLUMN reopen_count INT NOT NULL DEFAULT 0",
    ];
    foreach ($alterMap as $col => $sqlFragment) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE complaints $sqlFragment");
        }
    }

    // Seed default admin user if empty
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    if ($adminCount === 0) {
        $hash = password_hash('Admin@123', PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO admin_users (username, password_hash, full_name, department, role)
            VALUES ('admin', :h, 'Er. Rajveer Singh (Nodal District Grievance Officer)', 'District & Municipal ICCC Command Center', 'super_admin')
        ")->execute([':h' => $hash]);
    }

    // Seed realistic Ludhiana grievances if table is empty
    $compCount = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
    if ($compCount === 0) {
        $seeds = [
            [
                'code'   => 'CIV-2026-104284',
                'title'  => 'Deep waterlogged pothole cluster causing two-wheeler skidding near Pakhowal Road Canal Bridge',
                'cat'    => 'Road Damage',
                'dc'     => 'DEPT-BNR',
                'dn'     => 'B&R (Buildings & Roads) Engineering Wing',
                'desc'   => 'Three interconnected 1.2m wide potholes filled with rainwater right at the Pakhowal Road Sidhwan Canal approach. Multiple commuters have slipped during evening rush hour.',
                'cname'  => 'Harpreet Singh Gill',
                'cphone' => '9814255634',
                'cemail' => 'harpreet.gill@gmail.com',
                'ward'   => 'Zone D - Pakhowal & Sarabha Nagar',
                'loc'    => 'Pakhowal Road near Sidhwan Canal Bridge, Ludhiana',
                'lat'    => 30.8965,
                'lng'    => 75.8458,
                'photo'  => 'uploads/issues/sample_before_pothole.svg',
                'rphoto' => 'uploads/resolutions/sample_after_repaired.svg',
                'status' => 'Resolved',
                'prio'   => 'High',
                'sla'    => 48,
                'chan'   => 'Web Portal',
                'cc'     => 4,
                'upv'    => 19,
                'rem'    => 'Hot-mix bituminous concrete laid and compacted via roller; road surface leveled and traffic restored.',
                'je'     => 'Er. Gurpreet Singh (JE - B&R Zone D)',
                'tier'   => 'Tier 1 - Field JE',
                'rating' => 5,
                'fb'     => 'Verified the After-Image and drove over Pakhowal Bridge today—smooth patch work completed within 18 hours!',
                'ai_exp' => 'AI Triage (Score 84/100): High-impact arterial road hazard (pothole, skidding, rush hour). Clustered 4 reports within 150m.',
            ],
            [
                'code'   => 'CIV-2026-104283',
                'title'  => '[MASTER CLUSTER • 14 REPORTS] Leaning electric pole & live sparking 11kV wire near Model Town Market',
                'cat'    => 'Streetlight',
                'dc'     => 'DEPT-ELEC',
                'dn'     => 'Municipal Electrical Wing & PSPCL Nodal Cell',
                'desc'   => 'After heavy winds, an iron electric pole outside Model Town Gol Market is leaning dangerously and live overhead cable is sparking near parked vehicles.',
                'cname'  => 'Dr. Manuraj Moudgil & 13 Residents',
                'cphone' => '9876501290',
                'cemail' => 'citizen.alert@punjab.gov.in',
                'ward'   => 'Zone D - Model Town & Sarabha Nagar',
                'loc'    => 'Gol Market Main Crossing, Model Town, Ludhiana',
                'lat'    => 30.8884,
                'lng'    => 75.8432,
                'photo'  => null,
                'rphoto' => null,
                'status' => 'In Progress',
                'prio'   => 'Critical',
                'sla'    => 4,
                'chan'   => 'SMS-1916',
                'cc'     => 14,
                'upv'    => 34,
                'rem'    => 'PSPCL Emergency Line Crew & Hydraulic Sky-Lift Van dispatched; feeder isolated and pole stabilization underway.',
                'je'     => 'Er. Amanpreet Kaur (JE - Electrical & PSPCL Cell)',
                'tier'   => 'Tier 3 - Emergency Disaster Cell',
                'rating' => null,
                'fb'     => null,
                'ai_exp' => 'AI Triage (Score 98/100): Flagged CRITICAL life-safety hazard (sparking, live wire, electric pole). Auto-clustered 14 reports within 150m.',
            ],
            [
                'code'   => 'CIV-2026-104282',
                'title'  => 'Uncovered storm drainage manhole overflowing onto Gill Road flyover service lane',
                'cat'    => 'Drainage',
                'dc'     => 'DEPT-DRN',
                'dn'     => 'O&M Sewerage & Storm Drainage Wing',
                'desc'   => 'Main sewer manhole cover is missing near Gill Road ITI Chowk and black sewage water is submerging the service road, posing a severe fall hazard for pedestrians and cyclists.',
                'cname'  => 'Asadullah & Civic Volunteer Team',
                'cphone' => '9872011445',
                'cemail' => 'asadullah.cse@punjab.edu.in',
                'ward'   => 'Zone C - Gill Road & Miller Ganj',
                'loc'    => 'Near ITI Gate, Gill Road Service Lane, Ludhiana',
                'lat'    => 30.8825,
                'lng'    => 75.8622,
                'photo'  => null,
                'rphoto' => null,
                'status' => 'Under Review',
                'prio'   => 'Critical',
                'sla'    => 12,
                'chan'   => 'WhatsApp-Bot',
                'cc'     => 6,
                'upv'    => 22,
                'rem'    => 'Barricading team and Super-Sucker Jetting Truck assigned; heavy-duty RCC manhole cover requisitioned from Zone-C store.',
                'je'     => 'Er. Vikramjit Gill (JE - Storm Drainage & Sewer)',
                'tier'   => 'Tier 2 - Sub-Divisional Officer (SDO)',
                'rating' => null,
                'fb'     => null,
                'ai_exp' => 'AI Triage (Score 94/100): Flagged CRITICAL due to open manhole & sewage flooding on highway corridor.',
            ],
            [
                'code'   => 'CIV-2026-104281',
                'title'  => 'Potable water supply main pipeline burst wasting thousands of liters near PAU Gate No. 2',
                'cat'    => 'Water Leakage',
                'dc'     => 'DEPT-WAT',
                'dn'     => 'O&M Water Supply & Potable Pipeline Cell',
                'desc'   => 'High-pressure municipal drinking water main line ruptured near Ferozepur Road PAU Gate No. 2 since 6:00 AM; surrounding residential taps have low pressure.',
                'cname'  => 'Prof. Gurdev Sandhu',
                'cphone' => '9417088231',
                'cemail' => 'gsandhu.pau@gmail.com',
                'ward'   => 'Zone D - PAU & Ferozepur Road',
                'loc'    => 'Outside PAU Gate No. 2, Ferozepur Road, Ludhiana',
                'lat'    => 30.9018,
                'lng'    => 75.8095,
                'photo'  => null,
                'rphoto' => null,
                'status' => 'In Progress',
                'prio'   => 'High',
                'sla'    => 24,
                'chan'   => 'IVR-1800',
                'cc'     => 3,
                'upv'    => 15,
                'rem'    => 'O&M excavation crew on site; sluice valve closed and ductile iron collar clamp installation in progress.',
                'je'     => 'Er. Harjinder Sidhu (JE - O&M Water Supply)',
                'tier'   => 'Tier 1 - Field JE',
                'rating' => null,
                'fb'     => null,
                'ai_exp' => 'AI Triage (Score 86/100): Flagged HIGH priority potable pipeline burst affecting institutional zone.',
            ],
            [
                'code'   => 'CIV-2026-104280',
                'title'  => 'Secondary garbage secondary collection point overflowing near Clock Tower (Ghanta Ghar) Chowk',
                'cat'    => 'Garbage',
                'dc'     => 'DEPT-SAN',
                'dn'     => 'Health & Sanitation (Swachh Bharat) Wing',
                'desc'   => 'Uncollected commercial and wet waste accumulating outside the designated compactor bin near Ghanta Ghar market, causing foul odor and blocking pedestrian movement.',
                'cname'  => 'Aayan & Market Welfare Association',
                'cphone' => '9815533901',
                'cemail' => 'market.assoc.ldh@gmail.com',
                'ward'   => 'Zone A - Old City & Clock Tower',
                'loc'    => 'Near Clock Tower (Ghanta Ghar) Main Bazaar, Ludhiana',
                'lat'    => 30.9136,
                'lng'    => 75.8534,
                'photo'  => null,
                'rphoto' => null,
                'status' => 'Submitted',
                'prio'   => 'Medium',
                'sla'    => 24,
                'chan'   => 'Web Portal',
                'cc'     => 2,
                'upv'    => 11,
                'rem'    => 'Assigned to Zone-A Swachhata EV Compactor Route #4 for immediate clearance and bleaching powder spray.',
                'je'     => 'Sh. Rajesh Sharma (Sanitary Inspector - Swachhata)',
                'tier'   => 'Tier 1 - Field JE',
                'rating' => null,
                'fb'     => null,
                'ai_exp' => 'AI Triage (Score 68/100): Routed to Health & Sanitation (Swachh Bharat) Wing with 24-Hour SLA.',
            ],
        ];

        $ins = $pdo->prepare("
            INSERT INTO complaints (
                complaint_code, title, category, department_code, assigned_department,
                description, citizen_name, citizen_phone, citizen_email, ward_zone,
                location_text, latitude, longitude, photo_path, resolution_photo_path,
                status, priority, sla_hours, intake_channel, cluster_count, upvotes,
                admin_remarks, assigned_je, escalation_tier, citizen_rating,
                citizen_feedback, feedback_at, ai_suggested_priority, ai_explanation
            ) VALUES (
                :code, :title, :cat, :dc, :dn,
                :desc, :cname, :cphone, :cemail, :ward,
                :loc, :lat, :lng, :photo, :rphoto,
                :status, :prio, :sla, :chan, :cc, :upv,
                :rem, :je, :tier, :rating,
                :fb, :fbat, :prio2, :ai_exp
            )
        ");

        $insHist = $pdo->prepare("
            INSERT INTO complaint_status_history (complaint_id, old_status, new_status, action_note, changed_by)
            VALUES (:cid, :old_s, :new_s, :note, :by)
        ");

        foreach ($seeds as $s) {
            $ins->execute([
                ':code'   => $s['code'],
                ':title'  => $s['title'],
                ':cat'    => $s['cat'],
                ':dc'     => $s['dc'],
                ':dn'     => $s['dn'],
                ':desc'   => $s['desc'],
                ':cname'  => $s['cname'],
                ':cphone' => $s['cphone'],
                ':cemail' => $s['cemail'],
                ':ward'   => $s['ward'],
                ':loc'    => $s['loc'],
                ':lat'    => $s['lat'],
                ':lng'    => $s['lng'],
                ':photo'  => $s['photo'],
                ':rphoto' => $s['rphoto'],
                ':status' => $s['status'],
                ':prio'   => $s['prio'],
                ':sla'    => $s['sla'],
                ':chan'   => $s['chan'],
                ':cc'     => $s['cc'],
                ':upv'    => $s['upv'],
                ':rem'    => $s['rem'],
                ':je'     => $s['je'],
                ':tier'   => $s['tier'],
                ':rating' => $s['rating'],
                ':fb'     => $s['fb'],
                ':fbat'   => $s['rating'] ? date('Y-m-d H:i:s') : null,
                ':prio2'  => $s['prio'],
                ':ai_exp' => $s['ai_exp'],
            ]);
            $cid = (int)$pdo->lastInsertId();
            $insHist->execute([
                ':cid'   => $cid,
                ':old_s' => null,
                ':new_s' => 'Submitted',
                ':note'  => 'Grievance registered via ' . $s['chan'] . ' and triaged by CivicFix AI Engine (' . $s['dn'] . ').',
                ':by'    => 'CivicFix Omni-Channel Intake Engine',
            ]);
            if ($s['status'] !== 'Submitted') {
                $insHist->execute([
                    ':cid'   => $cid,
                    ':old_s' => 'Submitted',
                    ':new_s' => $s['status'],
                    ':note'  => $s['rem'],
                    ':by'    => $s['je'],
                ]);
            }
            if ($s['status'] === 'Resolved') {
                dispatch_citizen_notification($pdo, array_merge($s, [
                    'id'                    => $cid,
                    'complaint_code'        => $s['code'],
                    'assigned_department'   => $s['dn'],
                    'resolution_photo_path' => $s['rphoto'],
                ]), 'RESOLVED', $s['rem']);
            }
        }
    }
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'db.php' && isset($_GET['test'])) {
    try {
        $pdo = get_db_connection();
        send_json(['ok' => true, 'message' => 'CivicFix v3.0 database connected & auto-migrated successfully.']);
    } catch (Throwable $e) {
        send_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
