<?php
session_start();
require_once 'config/db.php';

if (isset($_SESSION['user']->id)) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET remember_token = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user']->id]);
}

setcookie('remember_token', '', secure_remember_cookie_options(time() - 3600));
session_unset();
session_destroy();
header("Location: login.php");
exit;
?>
