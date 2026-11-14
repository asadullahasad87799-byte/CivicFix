<?php
/**
 * CivicFix v3.0 — Real-Time Live AI Triage, Department Routing & 150m Cluster Preview (php/ai_preview.php)
 */

require_once __DIR__ . '/python_client.php';

try {
    $pdo = get_db_connection();
    $input = $_POST;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $stmt = $pdo->query("
        SELECT complaint_code, title, category, description, location_text, latitude, longitude, status, cluster_count
        FROM complaints
        ORDER BY id DESC
        LIMIT 60
    ");
    $existing = $stmt->fetchAll();

    $result = analyze_complaint_with_ai($input, $existing);
    send_json($result);
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'AI preview failed.', 'debug' => $e->getMessage()], 500);
}
