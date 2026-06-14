<?php

function itm_is_secure_request() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';

    return strtolower($forwardedProto) === 'https' || strtolower($forwardedSsl) === 'on';
}

function itm_storage_path($file = '') {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }

    return $file === '' ? $base : $base . DIRECTORY_SEPARATOR . $file;
}

function itm_log_error($message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $logFile = itm_storage_path('itm-errors.log');

    if (is_dir(dirname($logFile)) && is_writable(dirname($logFile))) {
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    error_log($message);
}

function itm_render_system_error($title, $message, $kind = 'error') {
    $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
    $kind = in_array($kind, ['error', 'warning', 'info', 'success'], true) ? $kind : 'error';

    http_response_code($kind === 'error' ? 500 : 200);
    echo "<!DOCTYPE html>
<html lang=\"fr\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>ITMCD - Message systeme</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --ink: #101828;
            --muted: #667085;
            --line: rgba(16,24,40,.12);
            --error: #dc2626;
            --warning: #d97706;
            --info: #2563eb;
            --success: #16a34a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 22px;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(37,99,235,.12), transparent 28rem),
                linear-gradient(180deg, #fbfdff, var(--bg));
            font-family: system-ui, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif;
        }
        .system-card {
            width: min(100%, 520px);
            padding: clamp(22px, 5vw, 32px);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 24px 70px rgba(16,24,40,.14);
        }
        .badge {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            margin-bottom: 18px;
            color: #fff;
            background: var(--$kind);
            font-size: 24px;
            font-weight: 900;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.12;
            overflow-wrap: anywhere;
        }
        @media (max-width: 520px) {
            h1 { font-size: 24px; }
        }
        p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }
    </style>
</head>
<body>
    <main class=\"system-card\" role=\"alert\">
        <div class=\"badge\" aria-hidden=\"true\">!</div>
        <h1>{$safeTitle}</h1>
        <p>{$safeMessage}</p>
    </main>
</body>
</html>";
    exit;
}

if (!defined('ITM_SKIP_SESSION_SETUP') && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $sessionPath = itm_storage_path('sessions');
    if (is_dir($sessionPath) || @mkdir($sessionPath, 0755, true)) {
        @session_save_path($sessionPath);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => itm_is_secure_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function itm_env($key, $default = null) {
    static $loaded = false;
    static $localConfig = null;

    if (!$loaded) {
        $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
        if (is_file($configFile) && is_readable($configFile)) {
            $localConfig = require $configFile;
        }

        $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile) && is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($name !== '' && getenv($name) === false) {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                }
            }
        }
        $loaded = true;
    }

    if (is_array($localConfig) && array_key_exists($key, $localConfig)) {
        return $localConfig[$key];
    }

    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$host = itm_env('DB_HOST', '127.0.0.1');
$dbname = itm_env('DB_NAME', 'itmcd');
$user = itm_env('DB_USER', 'root');
$pass = itm_env('DB_PASS', '');
$port = itm_env('DB_PORT', '3306');
$appDebug = filter_var(itm_env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

function itm_create_pdo_connection($host, $dbname, $user, $pass, $port = '3306') {
    if (!extension_loaded('pdo_mysql')) {
        throw new PDOException('Extension PHP pdo_mysql indisponible.');
    }

    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    if (!empty($port)) {
        $dsn .= ";port=$port";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
}

if (!defined('ITM_SKIP_DB_CONNECT')) {
    try {
        $pdo = itm_create_pdo_connection($host, $dbname, $user, $pass, $port);
    } catch (PDOException $e) {
        itm_log_error('Database connection failed on host "' . $host . '" for database "' . $dbname . '": ' . $e->getMessage());
        itm_render_system_error(
            'Connexion a la base de donnees indisponible',
            $appDebug ? 'Detail technique : ' . $e->getMessage() : 'Le service est temporairement indisponible. Veuillez reessayer dans quelques instants.',
            'error'
        );
    }
}

function checkRole($role_attendu) {
    if (!isset($_SESSION['user']) || $_SESSION['user']->role !== $role_attendu) {
        header("Location: ../login.php");
        exit();
    }
}

function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf() {
    if (!verify_csrf()) {
        http_response_code(419);
        die('Session expiree. Veuillez recharger la page.');
    }
}

function teacherOwnsCourse(PDO $pdo, $teacherId, $courseId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cours WHERE id = ? AND enseignant_id = ?");
    $stmt->execute([(int)$courseId, (int)$teacherId]);
    return (int)$stmt->fetchColumn() > 0;
}

function itm_ensure_settings_table(PDO $pdo) {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS parametres_app (
        cle VARCHAR(80) NOT NULL PRIMARY KEY,
        valeur VARCHAR(20) NOT NULL DEFAULT '0',
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function itm_setting(PDO $pdo, $key, $default = true) {
    itm_ensure_settings_table($pdo);
    $stmt = $pdo->prepare("SELECT valeur FROM parametres_app WHERE cle = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    if ($value === false) {
        return (bool)$default;
    }

    return in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
}

function itm_save_setting(PDO $pdo, $key, $enabled) {
    itm_ensure_settings_table($pdo);
    $stmt = $pdo->prepare("INSERT INTO parametres_app (cle, valeur) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), updated_at = NOW()");
    $stmt->execute([$key, $enabled ? '1' : '0']);
}

function itm_note_type_for_period($period) {
    return $period === 'S2' ? 'cote_s2' : 'cote_s1';
}

function itm_period_label($period) {
    return $period === 'S2' ? 'Semestre 2' : 'Semestre 1';
}

function itm_allowed_periods_for_course($courseSemester) {
    if ($courseSemester === 'S2') {
        return ['S2'];
    }
    if ($courseSemester === 'S1') {
        return ['S1'];
    }
    return ['S1', 'S2'];
}

function itm_resolve_period_for_course($courseSemester, $requestedPeriod = null) {
    $allowed = itm_allowed_periods_for_course($courseSemester);
    return in_array($requestedPeriod, $allowed, true) ? $requestedPeriod : $allowed[0];
}

function itm_moyenne_bonus_points($moyenne, $maxPoints) {
    $maxPoints = (float)$maxPoints;
    if ($maxPoints <= 0 || $moyenne === null || $moyenne === '') {
        return 0.0;
    }

    $moyenne = max(0.0, min((float)$moyenne, 10.0));
    return ($moyenne / 10) * ($maxPoints * 0.25);
}

function itm_apply_moyenne_backup($basePoints, $moyenne, $maxPoints) {
    $maxPoints = (float)$maxPoints;
    if ($maxPoints <= 0) {
        return 0.0;
    }

    $basePoints = max(0.0, min((float)$basePoints, $maxPoints));
    if ($moyenne === null || $moyenne === '' || $basePoints > ($maxPoints * 0.75)) {
        return $basePoints;
    }

    return min($maxPoints, $basePoints + itm_moyenne_bonus_points($moyenne, $maxPoints));
}

function itm_obtained_points($directScore, $legacyAverage, $legacyExam, $maxPoints) {
    $maxPoints = (float)$maxPoints;
    if ($maxPoints <= 0) {
        return 0.0;
    }

    if ($directScore !== null && $directScore !== '') {
        return itm_apply_moyenne_backup($directScore, $legacyAverage, $maxPoints);
    }

    if ($legacyExam !== null) {
        $legacyPoints = ((float)$legacyExam / 10) * $maxPoints;
        return itm_apply_moyenne_backup($legacyPoints, $legacyAverage, $maxPoints);
    }

    return 0.0;
}

function itm_has_grade($directScore, $legacyAverage, $legacyExam) {
    return $directScore !== null || $legacyExam !== null;
}

function itm_select_period_score($note, $courseSemester, $period = 'FINAL') {
    $cote = $note->cote ?? null;
    $coteS1 = $note->cote_s1 ?? null;
    $coteS2 = $note->cote_s2 ?? null;

    if ($period === 'S1') {
        return $coteS1 !== null ? $coteS1 : $cote;
    }

    if ($period === 'S2') {
        return $coteS2 !== null ? $coteS2 : $cote;
    }

    if ($courseSemester === 'ANNUEL') {
        if ($coteS1 !== null || $coteS2 !== null || $cote !== null) {
            $s1 = $coteS1 !== null ? $coteS1 : ($cote !== null ? $cote : 0);
            $s2 = $coteS2 !== null ? $coteS2 : 0;
            return ((float)$s1 + (float)$s2) / 2;
        }
    }

    if ($courseSemester === 'S2') {
        return $coteS2 !== null ? $coteS2 : $cote;
    }

    return $coteS1 !== null ? $coteS1 : $cote;
}

function itm_effective_maxima($courseSemester, $maxPoints, $period = 'FINAL') {
    $maxPoints = (float)$maxPoints;
    return $maxPoints;
}

function itm_points_for_period($note, $courseSemester, $maxPoints, $period = 'FINAL') {
    $directScore = itm_select_period_score($note, $courseSemester, $period);
    $effectiveMax = itm_effective_maxima($courseSemester, $maxPoints, $period);
    return itm_obtained_points($directScore, $note->moyenne ?? null, $note->examen ?? null, $effectiveMax);
}

function itm_has_grade_for_period($note, $courseSemester, $period = 'FINAL') {
    return itm_select_period_score($note, $courseSemester, $period) !== null
        || ($note->examen ?? null) !== null;
}

function itm_format_points($value, $decimals = 2) {
    $value = (float)$value;
    $formatted = number_format($value, $decimals, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function itm_decision_column_for_period($period) {
    if ($period === 'S1') {
        return 'decision_s1';
    }
    if ($period === 'S2') {
        return 'decision_s2';
    }
    return 'decision_jury';
}

function itm_period_for_note_type($typeNote, $courseSemester = 'S1') {
    if ($typeNote === 'cote_s2') {
        return 'S2';
    }
    if ($typeNote === 'cote_s1') {
        return 'S1';
    }
    return $courseSemester === 'S2' ? 'S2' : 'S1';
}

function itm_sanitize_grade_value($value) {
    if ($value === null) {
        return null;
    }

    $value = trim(str_replace(',', '.', (string)$value));
    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Cote invalide.');
    }

    return round((float)$value, 2);
}

function itm_upsert_period_grade(PDO $pdo, $apprenantId, $coursId, $enseignantId, $period, $value) {
    $value = itm_sanitize_grade_value($value);
    if ($value === null) {
        return null;
    }

    $stmtCourse = $pdo->prepare("SELECT id, coefficient, enseignant_id, semestre FROM cours WHERE id = ? LIMIT 1");
    $stmtCourse->execute([(int)$coursId]);
    $course = $stmtCourse->fetch(PDO::FETCH_OBJ);
    if (!$course) {
        throw new RuntimeException('Cours introuvable.');
    }

    $resolvedPeriod = itm_resolve_period_for_course($course->semestre, $period);
    $maxima = (float)$course->coefficient;

    if ($value < 0 || $value > $maxima) {
        throw new InvalidArgumentException('La cote doit etre comprise entre 0 et ' . itm_format_points($maxima) . '.');
    }

    $noteType = itm_note_type_for_period($resolvedPeriod);
    $noteTeacherId = (int)($course->enseignant_id ?: $enseignantId);
    if ($noteTeacherId <= 0) {
        throw new RuntimeException('Aucun enseignant associe a ce cours.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO notes (apprenant_id, cours_id, enseignant_id, type_note, valeur, date_encodage)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            valeur = VALUES(valeur),
            enseignant_id = VALUES(enseignant_id),
            date_encodage = NOW()
    ");
    $stmt->execute([(int)$apprenantId, (int)$coursId, $noteTeacherId, $noteType, $value]);

    return [
        'cours' => $course,
        'period' => $resolvedPeriod,
        'type_note' => $noteType,
        'value' => $value,
    ];
}

function itm_upsert_moyenne(PDO $pdo, $apprenantId, $coursId, $enseignantId, $value) {
    $value = itm_sanitize_grade_value($value);
    if ($value === null) {
        return null;
    }

    if ($value < 0 || $value > 10) {
        throw new InvalidArgumentException('La moyenne doit etre comprise entre 0 et 10.');
    }

    $stmtCourse = $pdo->prepare("SELECT id, nom, coefficient, enseignant_id, semestre, promotion_id FROM cours WHERE id = ? LIMIT 1");
    $stmtCourse->execute([(int)$coursId]);
    $course = $stmtCourse->fetch(PDO::FETCH_OBJ);
    if (!$course) {
        throw new RuntimeException('Cours introuvable.');
    }

    $stmtStudent = $pdo->prepare("SELECT id, promotion_id FROM apprenants WHERE id = ? LIMIT 1");
    $stmtStudent->execute([(int)$apprenantId]);
    $student = $stmtStudent->fetch(PDO::FETCH_OBJ);
    if (!$student || (int)$student->promotion_id !== (int)$course->promotion_id) {
        throw new RuntimeException('Cet apprenant ne fait pas partie de la promotion du cours selectionne.');
    }

    $noteTeacherId = (int)($course->enseignant_id ?: $enseignantId);
    if ($noteTeacherId <= 0) {
        throw new RuntimeException('Aucun enseignant associe a ce cours.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO notes (apprenant_id, cours_id, enseignant_id, type_note, valeur, date_encodage)
        VALUES (?, ?, ?, 'moyenne', ?, NOW())
        ON DUPLICATE KEY UPDATE
            valeur = VALUES(valeur),
            enseignant_id = VALUES(enseignant_id),
            date_encodage = NOW()
    ");
    $stmt->execute([(int)$apprenantId, (int)$coursId, $noteTeacherId, $value]);

    return [
        'cours' => $course,
        'type_note' => 'moyenne',
        'value' => $value,
    ];
}

function itm_student_course_results(PDO $pdo, $apprenantId, $promotionId, $period = 'FINAL') {
    $period = $period === 'JURY' ? 'FINAL' : $period;
    $filter = '';
    $params = [(int)$apprenantId, (int)$promotionId];

    if ($period === 'S1' || $period === 'S2') {
        $filter = " AND (c.semestre = ? OR c.semestre = 'ANNUEL')";
        $params[] = $period;
    }

    $stmt = $pdo->prepare("
        SELECT c.id AS cours_id, c.nom AS cours_nom, c.coefficient, c.semestre,
               c.enseignant_id, u.nom AS prof_nom,
               MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) AS cote,
               MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) AS cote_s1,
               MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) AS cote_s2,
               MAX(CASE WHEN n.type_note = 'examen' THEN n.valeur END) AS examen,
               MAX(CASE WHEN n.type_note = 'moyenne' THEN n.valeur END) AS moyenne
        FROM cours c
        LEFT JOIN utilisateurs u ON u.id = c.enseignant_id
        LEFT JOIN notes n ON n.cours_id = c.id AND n.apprenant_id = ?
        WHERE c.promotion_id = ? $filter
        GROUP BY c.id, c.nom, c.coefficient, c.semestre, c.enseignant_id, u.nom
        ORDER BY FIELD(c.semestre, 'S1', 'S2', 'ANNUEL'), c.nom ASC
    ");
    $stmt->execute($params);
    $courses = $stmt->fetchAll(PDO::FETCH_OBJ);

    foreach ($courses as $course) {
        $course->_period = $period;
        $course->_max = itm_effective_maxima($course->semestre, $course->coefficient, $period);
        $course->_points = itm_points_for_period($course, $course->semestre, $course->coefficient, $period);
        $course->_has_grade = itm_has_grade_for_period($course, $course->semestre, $period);
        $course->_threshold = $course->_max / 2;
        $course->_failed = $course->_has_grade && $course->_points < $course->_threshold;
        $course->_percentage = $course->_max > 0 ? round(($course->_points / $course->_max) * 100, 1) : 0;
    }

    return $courses;
}

function itm_student_result(PDO $pdo, $apprenantId, $promotionId, $period = 'FINAL') {
    $period = $period === 'JURY' ? 'FINAL' : $period;
    $courses = itm_student_course_results($pdo, $apprenantId, $promotionId, $period);
    $totalPoints = 0.0;
    $totalMax = 0.0;
    $missing = 0;
    $failedCourses = [];

    foreach ($courses as $course) {
        $totalPoints += (float)$course->_points;
        $totalMax += (float)$course->_max;

        if (!$course->_has_grade) {
            $missing++;
            $failedCourses[] = $course;
            continue;
        }

        if ($course->_failed) {
            $failedCourses[] = $course;
        }
    }

    $percentage = $totalMax > 0 ? round(($totalPoints / $totalMax) * 100, 2) : 0.0;
    $decision = ($totalMax > 0 && $missing === 0 && $percentage >= 60) ? 'ADMIS' : 'ECHEC';

    return [
        'courses' => $courses,
        'failed_courses' => $failedCourses,
        'total_points' => $totalPoints,
        'total_max' => $totalMax,
        'percentage' => $percentage,
        'missing' => $missing,
        'decision' => $decision,
    ];
}

function itm_refresh_student_decision(PDO $pdo, $apprenantId, $promotionId, $period = 'FINAL') {
    $result = itm_student_result($pdo, $apprenantId, $promotionId, $period);
    $column = itm_decision_column_for_period($period);
    $stmt = $pdo->prepare("UPDATE apprenants SET $column = ? WHERE id = ?");
    $stmt->execute([$result['decision'], (int)$apprenantId]);
    return $result;
}

function itm_refresh_student_decisions(PDO $pdo, $apprenantId, $promotionId = null) {
    $apprenantId = (int)$apprenantId;
    if ($apprenantId <= 0) {
        throw new InvalidArgumentException('Apprenant invalide.');
    }

    if ($promotionId === null || (int)$promotionId <= 0) {
        $stmt = $pdo->prepare("SELECT promotion_id FROM apprenants WHERE id = ? LIMIT 1");
        $stmt->execute([$apprenantId]);
        $promotionId = $stmt->fetchColumn();
    }

    $promotionId = (int)$promotionId;
    if ($promotionId <= 0) {
        throw new RuntimeException('Promotion introuvable pour cet apprenant.');
    }

    return [
        'S1' => itm_refresh_student_decision($pdo, $apprenantId, $promotionId, 'S1'),
        'S2' => itm_refresh_student_decision($pdo, $apprenantId, $promotionId, 'S2'),
        'FINAL' => itm_refresh_student_decision($pdo, $apprenantId, $promotionId, 'FINAL'),
    ];
}

function secure_remember_cookie_options($expires) {
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => itm_is_secure_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function app_shell_assets($root = '../') {
    $root = rtrim($root, '/') . '/';
    $assetVersion = '20260612-dashboard-push-grid-2';
    $vapidPublicKey = itm_env('VAPID_PUBLIC_KEY', '');
    return "\n<meta name=\"theme-color\" content=\"#0f6bff\">\n"
        . "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n"
        . "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n"
        . "<meta name=\"itm-root\" content=\"" . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . "\">\n"
        . "<meta name=\"itm-vapid-public-key\" content=\"" . htmlspecialchars($vapidPublicKey, ENT_QUOTES, 'UTF-8') . "\">\n"
        . "<link rel=\"manifest\" href=\"" . htmlspecialchars($root . 'manifest.json', ENT_QUOTES, 'UTF-8') . "\">\n"
        . "<link rel=\"stylesheet\" href=\"" . htmlspecialchars($root . 'assets/app-shell.css?v=' . $assetVersion, ENT_QUOTES, 'UTF-8') . "\">\n"
        . "<link rel=\"stylesheet\" href=\"" . htmlspecialchars($root . 'assets/app-modern.css?v=' . $assetVersion, ENT_QUOTES, 'UTF-8') . "\">\n"
        . "<script defer src=\"" . htmlspecialchars($root . 'assets/app-shell.js?v=' . $assetVersion, ENT_QUOTES, 'UTF-8') . "\"></script>\n";
}

function itm_table_has_column(PDO $pdo, $table, $column) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE $quotedColumn");
    return (bool)$stmt->fetch();
}

function itm_ensure_notifications_table(PDO $pdo) {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        message TEXT NOT NULL,
        est_lu TINYINT(1) DEFAULT 0,
        target_role VARCHAR(30) NULL,
        target_user_id INT NULL,
        type VARCHAR(60) NULL,
        cree_le TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [
        'target_role' => "ALTER TABLE notifications ADD COLUMN target_role VARCHAR(30) NULL AFTER est_lu",
        'target_user_id' => "ALTER TABLE notifications ADD COLUMN target_user_id INT NULL AFTER target_role",
        'type' => "ALTER TABLE notifications ADD COLUMN type VARCHAR(60) NULL AFTER target_user_id",
    ];

    foreach ($columns as $column => $sql) {
        if (!itm_table_has_column($pdo, 'notifications', $column)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                itm_log_error('Notification schema update failed for ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    if (!itm_table_has_column($pdo, 'notifications', 'cree_le') && !itm_table_has_column($pdo, 'notifications', 'created_at')) {
        try {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN cree_le TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        } catch (Throwable $e) {
            itm_log_error('Notification date column update failed: ' . $e->getMessage());
        }
    }

    $done = true;
}

function itm_notification_date_column(PDO $pdo) {
    itm_ensure_notifications_table($pdo);
    if (itm_table_has_column($pdo, 'notifications', 'created_at')) {
        return 'created_at';
    }
    return 'cree_le';
}

function itm_insert_notification(PDO $pdo, $message, $targetRole = 'admin', $targetUserId = null, $type = 'general') {
    itm_ensure_notifications_table($pdo);
    $dateColumn = itm_notification_date_column($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO notifications (message, est_lu, target_role, target_user_id, type, `$dateColumn`)
        VALUES (?, 0, ?, ?, ?, NOW())
    ");
    $stmt->execute([(string)$message, $targetRole, $targetUserId ? (int)$targetUserId : null, $type]);

    if ($targetUserId) {
        itm_push_user($pdo, (int)$targetUserId);
    } elseif ($targetRole) {
        itm_push_role($pdo, (string)$targetRole);
    }
}

function itm_notify_role(PDO $pdo, $role, $message, $type = 'general') {
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE role = ? ORDER BY id ASC");
    $stmt->execute([$role]);
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        itm_insert_notification($pdo, $message, $role, (int)$userId, $type);
        $count++;
    }
    return $count;
}

function itm_notify_teachers_encoding_toggle(PDO $pdo, $period, $enabled) {
    $label = itm_period_label($period);
    $message = $enabled
        ? "Encodage $label ouvert : les cotes doivent etre encodees."
        : "Cloture d'encodage $label atteinte : l'encodage des cotes est desactive.";

    return itm_notify_role($pdo, 'enseignant', $message, 'encodage');
}

function itm_ensure_push_subscriptions_table(PDO $pdo) {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        endpoint_hash CHAR(64) NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [
        'endpoint_hash' => "ALTER TABLE push_subscriptions ADD COLUMN endpoint_hash CHAR(64) NULL AFTER endpoint",
        'updated_at' => "ALTER TABLE push_subscriptions ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($columns as $column => $sql) {
        if (!itm_table_has_column($pdo, 'push_subscriptions', $column)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                itm_log_error('Push subscription schema update failed for ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    try {
        $pdo->exec("UPDATE push_subscriptions SET endpoint_hash = SHA2(endpoint, 256) WHERE endpoint_hash IS NULL OR endpoint_hash = ''");
    } catch (Throwable $e) {
        itm_log_error('Push endpoint hash backfill failed: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE push_subscriptions ADD UNIQUE KEY uq_push_user_endpoint (user_id, endpoint_hash)");
    } catch (Throwable $e) {
        // Index may already exist or old duplicate rows may need manual cleanup.
    }

    $done = true;
}

function itm_save_push_subscription(PDO $pdo, $userId, array $data) {
    itm_ensure_push_subscriptions_table($pdo);

    $endpoint = (string)($data['endpoint'] ?? '');
    $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
    $p256dh = (string)($keys['p256dh'] ?? '');
    $auth = (string)($keys['auth'] ?? '');

    if ($endpoint === '' || $p256dh === '' || $auth === '' || !preg_match('#^https://#i', $endpoint)) {
        return false;
    }

    $hash = hash('sha256', $endpoint);
    $stmt = $pdo->prepare("
        SELECT id FROM push_subscriptions
        WHERE user_id = ? AND endpoint_hash = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([(int)$userId, $hash]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $update = $pdo->prepare("
            UPDATE push_subscriptions
            SET endpoint = ?, p256dh = ?, auth = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$endpoint, $p256dh, $auth, (int)$existingId]);
        return true;
    }

    $insert = $pdo->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $insert->execute([(int)$userId, $endpoint, $hash, $p256dh, $auth]);
    return true;
}

function itm_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function itm_ecdsa_der_to_jose($der, $partLength = 32) {
    $offset = 0;
    $readLength = function () use ($der, &$offset) {
        $length = ord($der[$offset++]);
        if ($length < 0x80) {
            return $length;
        }
        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }
        return $length;
    };

    if (ord($der[$offset++]) !== 0x30) {
        return '';
    }
    $readLength();
    if (ord($der[$offset++]) !== 0x02) {
        return '';
    }
    $r = substr($der, $offset, $readLength());
    $offset += strlen($r);
    if (ord($der[$offset++]) !== 0x02) {
        return '';
    }
    $s = substr($der, $offset, $readLength());

    $r = str_pad(substr(ltrim($r, "\x00"), -$partLength), $partLength, "\x00", STR_PAD_LEFT);
    $s = str_pad(substr(ltrim($s, "\x00"), -$partLength), $partLength, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function itm_vapid_jwt($audience) {
    $privatePem = str_replace('\\n', "\n", (string)itm_env('VAPID_PRIVATE_KEY_PEM', ''));
    $subject = (string)itm_env('VAPID_SUBJECT', 'mailto:admin@itmcd.local');

    if ($privatePem === '' || !function_exists('openssl_sign')) {
        return '';
    }

    $header = itm_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = itm_base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 3600,
        'sub' => $subject,
    ]));
    $unsigned = $header . '.' . $claims;

    if (!openssl_sign($unsigned, $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
        return '';
    }

    $joseSignature = itm_ecdsa_der_to_jose($signature);
    return $joseSignature === '' ? '' : $unsigned . '.' . itm_base64url_encode($joseSignature);
}

function itm_send_web_push_wakeup($endpoint) {
    $publicKey = (string)itm_env('VAPID_PUBLIC_KEY', '');
    if ($publicKey === '' || !function_exists('curl_init')) {
        return null;
    }

    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $jwt = itm_vapid_jwt($audience);
    if ($jwt === '') {
        return null;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => [
            'TTL: 3600',
            'Urgency: high',
            'Content-Length: 0',
            'Authorization: vapid t=' . $jwt . ', k=' . $publicKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status;
}

function itm_push_role(PDO $pdo, $role) {
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE role = ? ORDER BY id ASC");
    $stmt->execute([(string)$role]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        itm_push_user($pdo, (int)$userId);
    }
}

function itm_push_user(PDO $pdo, $userId) {
    itm_ensure_push_subscriptions_table($pdo);
    $stmt = $pdo->prepare("SELECT id, endpoint FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([(int)$userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $subscription) {
        $status = itm_send_web_push_wakeup($subscription['endpoint']);
        if (in_array($status, [404, 410], true)) {
            $delete = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?");
            $delete->execute([(int)$subscription['id']]);
        }
    }
}
?>
