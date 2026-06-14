<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'enseignant') {
    http_response_code(403);
    echo json_encode(['status' => 'error']);
    exit;
}

itm_ensure_notifications_table($pdo);
$userId = (int)$_SESSION['user']->id;
$ids = $_POST['ids'] ?? [];

if (is_string($ids)) {
    $decoded = json_decode($ids, true);
    $ids = is_array($decoded) ? $decoded : [];
}

$ids = array_values(array_filter(array_map('intval', (array)$ids)));

if (empty($ids)) {
    $stmt = $pdo->prepare("UPDATE notifications SET est_lu = 1 WHERE target_user_id = ? AND target_role = 'enseignant'");
    $stmt->execute([$userId]);
} else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$userId], $ids);
    $stmt = $pdo->prepare("UPDATE notifications SET est_lu = 1 WHERE target_user_id = ? AND id IN ($placeholders)");
    $stmt->execute($params);
}

echo json_encode(['status' => 'success']);
