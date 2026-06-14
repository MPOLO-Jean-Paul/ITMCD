<?php
session_start();
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

$saved = itm_save_push_subscription($pdo, (int)$_SESSION['user']->id, $data);

if (!$saved) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid subscription']);
    exit;
}

echo json_encode(['status' => 'success']);
