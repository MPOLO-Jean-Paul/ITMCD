<?php
define('ITM_SKIP_DB_CONNECT', true);
define('ITM_SKIP_SESSION_SETUP', true);
require_once __DIR__ . '/config/db.php';

$isCli = PHP_SAPI === 'cli';
$appDebug = filter_var(itm_env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

if (!$isCli && !$appDebug) {
    http_response_code(404);
    exit('Diagnostic desactive.');
}

header('Content-Type: text/plain; charset=utf-8');

echo "ITMCD diagnostic DB\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Server: " . ($_SERVER['HTTP_HOST'] ?? 'CLI') . "\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'OK' : 'MANQUANT') . "\n";

$host = itm_env('DB_HOST', '127.0.0.1');
$dbname = itm_env('DB_NAME', 'itmcd');
$user = itm_env('DB_USER', 'root');
$pass = itm_env('DB_PASS', '');
$port = itm_env('DB_PORT', '3306');

echo "Host: " . $host . "\n";
echo "Database: " . $dbname . "\n";
echo "User: " . $user . "\n";
echo "Port: " . $port . "\n";

try {
    $pdo = itm_create_pdo_connection($host, $dbname, $user, $pass, $port);
    echo "Connexion: OK\n";
    echo "Utilisateurs: " . $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn() . "\n";
} catch (PDOException $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo "Connexion: ERREUR\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}
