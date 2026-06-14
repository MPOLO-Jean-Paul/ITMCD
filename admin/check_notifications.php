<?php
// check_notifications.php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['nouveau' => false, 'count' => 0]);
    exit;
}

itm_ensure_notifications_table($pdo);
$dateColumn = itm_notification_date_column($pdo);

// 1. Récupérer toutes les notifs non lues
$stmt = $pdo->prepare("
    SELECT id, message, `$dateColumn` AS created_at
    FROM notifications
    WHERE est_lu = 0
      AND (target_role IS NULL OR target_role = '' OR target_role = 'admin')
      AND target_user_id IS NULL
    ORDER BY `$dateColumn` DESC, id DESC
");
$stmt->execute();
$notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$count = count($notifs);

if ($count === 0) {
    echo json_encode(['nouveau' => false, 'count' => 0]);
    exit;
}

// 2. Logique de regroupement (L'intelligence artificielle du script)
$summary_message = "";
$type_counts = [
    'notes' => 0,
    'moyennes' => 0,
    'autres' => 0
];

// On analyse les messages pour les catégoriser
foreach ($notifs as $n) {
    $msg = strtolower($n['message']);
    if (strpos($msg, 'note') !== false || strpos($msg, 'cote') !== false || strpos($msg, 'examen') !== false) {
        $type_counts['notes']++;
    } elseif (strpos($msg, 'moyenne') !== false) {
        $type_counts['moyennes']++;
    } else {
        $type_counts['autres']++;
    }
}

// 3. Construction du message résumé
if ($type_counts['notes'] > 1) {
    $summary_message = "Nouvelle activité : " . $type_counts['notes'] . " notes ont été encodées récemment.";
} elseif ($type_counts['moyennes'] > 1) {
    $summary_message = "Mise à jour : " . $type_counts['moyennes'] . " moyennes ont été calculées.";
} elseif ($count == 1) {
    // Si une seule notif, on affiche le message tel quel
    $summary_message = $notifs[0]['message'];
} else {
    $summary_message = "Vous avez $count nouvelles notifications en attente.";
}

// On renvoie le résumé
echo json_encode([
    'nouveau' => true,
    'count' => $count,
    'message' => $summary_message
]);
?>
