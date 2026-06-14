<?php
session_start();
require_once '../config/db.php';

// Sécurité : Uniquement pour les admins connectés
if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
    try {
        itm_ensure_notifications_table($pdo);
        // Met à jour toutes les notifications non lues
        $pdo->query("UPDATE notifications SET est_lu = 1 WHERE est_lu = 0 AND (target_role IS NULL OR target_role = '' OR target_role = 'admin') AND target_user_id IS NULL");
        echo json_encode(['status' => 'success']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error']);
    }
} else {
    http_response_code(403);
}
?>
