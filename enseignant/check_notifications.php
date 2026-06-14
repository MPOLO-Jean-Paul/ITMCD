<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'enseignant') {
    echo json_encode(['nouveau' => false, 'count' => 0]);
    exit;
}

itm_ensure_notifications_table($pdo);
$dateColumn = itm_notification_date_column($pdo);
$userId = (int)$_SESSION['user']->id;

$stmt = $pdo->prepare("
    SELECT id, message, type, `$dateColumn` AS created_at
    FROM notifications
    WHERE est_lu = 0
      AND target_user_id = ?
      AND (target_role = 'enseignant' OR target_role IS NULL)
    ORDER BY id DESC
    LIMIT 8
");
$stmt->execute([$userId]);
$notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$count = count($notifs);

if ($count === 0) {
    echo json_encode(['nouveau' => false, 'count' => 0]);
    exit;
}

$latest = $notifs[0];
$summary = $count === 1
    ? $latest['message']
    : $count . " notifications d'encodage en attente.";

echo json_encode([
    'nouveau' => true,
    'count' => $count,
    'latest_id' => (int)$latest['id'],
    'message' => $summary,
    'items' => $notifs,
]);
