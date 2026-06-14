<?php
define('ITM_SKIP_DB_CONNECT', true);
define('ITM_SKIP_SESSION_SETUP', true);
require_once __DIR__ . '/config/db.php';

$isCli = PHP_SAPI === 'cli';
$appDebug = filter_var(itm_env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

if (!$isCli || !$appDebug) {
    http_response_code(404);
    exit('Script de test desactive.');
}

echo "Script de test desactive. Utilisez les ecrans d'administration pour modifier les comptes.\n";
