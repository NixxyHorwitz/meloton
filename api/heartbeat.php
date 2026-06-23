<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Accept only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON Payload']);
    exit;
}

$interval_minutes = isset($data['interval_minutes']) ? (int)$data['interval_minutes'] : 5;
$device_info      = $data['device_info'] ?? 'PGAForwarder';
$payload_text     = $rawBody;

try {
    $pdo->prepare("INSERT INTO forwarder_heartbeats (interval_minutes, device_info, payload_text, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$interval_minutes, $device_info, $payload_text]);
        
    echo json_encode(['status' => 'success', 'message' => 'Heartbeat received']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save heartbeat', 'details' => $e->getMessage()]);
}
