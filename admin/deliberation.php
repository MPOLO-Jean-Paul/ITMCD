<?php
session_start();
require_once '../config/db.php';

// 1. SÉCURITÉ ADMIN
if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";
$admin_id = (int)$_SESSION['user']->id;
$stats_display = [];

function delib_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function delib_student_label($student) {
    return trim(($student->nom ?? '') . ' ' . ($student->post_nom ?? '') . ' ' . ($student->prenom ?? ''));
}

function delib_message_kind($type) {
    if ($type === 'success') {
        return 'success';
    }
    if ($type === 'warning') {
        return 'warning';
    }
    if ($type === 'info') {
        return 'info';
    }
    return 'error';
}

function delib_build_url(array $overrides = [], $anchor = '') {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    $url = 'deliberation.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    if ($anchor !== '') {
        $url .= '#' . rawurlencode($anchor);
    }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

// 2. TRAITEMENT DE LA DÉLIBÉRATION
if (isset($_POST['confirmer_deliberation'])) {
    require_csrf();
    $semestre = $_POST['semestre']; 
    $password_input = $_POST['admin_password'];

    $stmt = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_OBJ);

    if ($admin && password_verify($password_input, $admin->mot_de_passe)) {
        try {
            $pdo->beginTransaction();
            $apprenants = $pdo->query("SELECT id, promotion_id FROM apprenants")->fetchAll(PDO::FETCH_OBJ);

            foreach ($apprenants as $app) {
                itm_refresh_student_decision($pdo, $app->id, $app->promotion_id, $semestre === 'JURY' ? 'FINAL' : $semestre);
            }
            
            $pdo->commit();
            $message = "success|Délibération terminée avec succès.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "danger|Erreur système : " . $e->getMessage();
        }
    } else {
        $message = "danger|Mot de passe incorrect.";
    }
}

// 3. STATISTIQUES
$valid_semestres = ['S1', 'S2', 'JURY'];
$semestre_view = $_POST['semestre'] ?? ($_GET['semestre_stats'] ?? 'S1');
$semestre_view = in_array($semestre_view, $valid_semestres, true) ? $semestre_view : 'S1';
$stats_semestre = $_GET['semestre_stats'] ?? $semestre_view;
$stats_semestre = in_array($stats_semestre, $valid_semestres, true) ? $stats_semestre : 'S1';
$period_calc_stats = $stats_semestre === 'JURY' ? 'FINAL' : $stats_semestre;
$promotions = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll(PDO::FETCH_OBJ);

foreach ($promotions as $promo) {
    $stmt_count = $pdo->prepare("SELECT id, promotion_id FROM apprenants WHERE promotion_id = ? ORDER BY nom, post_nom, prenom");
    $stmt_count->execute([$promo->id]);
    $students_preview = $stmt_count->fetchAll(PDO::FETCH_OBJ);
    $admis = 0;
    $echec = 0;
    $missing = 0;

    foreach ($students_preview as $student_preview) {
        $result = itm_student_result($pdo, $student_preview->id, $student_preview->promotion_id, $period_calc_stats);
        if ($result['decision'] === 'ADMIS') {
            $admis++;
        } else {
            $echec++;
        }
        $missing += (int)$result['missing'];
    }

    $total = count($students_preview);
    $stats_display[] = [
        'promo' => $promo->nom, 
        'total' => $total, 
        'admis' => $admis,
        'echec' => $echec,
        'missing' => $missing,
        'taux' => ($total > 0) ? round(($admis / $total) * 100, 1) : 0
    ];
}

$stats_promo_id = isset($_GET['stats_promo_id']) ? (int)$_GET['stats_promo_id'] : 0;
if ($stats_promo_id <= 0 && !empty($promotions)) {
    $stats_promo_id = (int)$promotions[0]->id;
}

$stats_courses = [];
if ($stats_promo_id > 0) {
    $courseSql = "
        SELECT c.id, c.nom, c.coefficient, c.semestre, p.nom AS promo_nom, u.nom AS prof_nom
        FROM cours c
        JOIN promotions p ON p.id = c.promotion_id
        LEFT JOIN utilisateurs u ON u.id = c.enseignant_id
        WHERE c.promotion_id = ?
    ";
    $params = [$stats_promo_id];
    if ($stats_semestre !== 'JURY') {
        $courseSql .= " AND (c.semestre = ? OR c.semestre = 'ANNUEL')";
        $params[] = $stats_semestre;
    }
    $courseSql .= " ORDER BY FIELD(c.semestre, 'S1', 'S2', 'ANNUEL'), c.nom ASC";
    $stmt_courses = $pdo->prepare($courseSql);
    $stmt_courses->execute($params);
    $stats_courses = $stmt_courses->fetchAll(PDO::FETCH_OBJ);
}

$stats_course_id = isset($_GET['stats_course_id']) ? (int)$_GET['stats_course_id'] : 0;
if ($stats_course_id <= 0 && !empty($stats_courses)) {
    $stats_course_id = (int)$stats_courses[0]->id;
}

$course_stats = null;
if ($stats_course_id > 0) {
    $selectedCourse = null;
    foreach ($stats_courses as $course) {
        if ((int)$course->id === $stats_course_id) {
            $selectedCourse = $course;
            break;
        }
    }

    if ($selectedCourse) {
        $stmt_students = $pdo->prepare("
            SELECT a.id, a.code_unique,
                   MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) AS cote,
                   MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) AS cote_s1,
                   MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) AS cote_s2,
                   MAX(CASE WHEN n.type_note = 'examen' THEN n.valeur END) AS examen,
                   MAX(CASE WHEN n.type_note = 'moyenne' THEN n.valeur END) AS moyenne
            FROM apprenants a
            LEFT JOIN notes n ON n.apprenant_id = a.id AND n.cours_id = ?
            WHERE a.promotion_id = ?
            GROUP BY a.id, a.code_unique
            ORDER BY a.code_unique ASC
        ");
        $stmt_students->execute([$stats_course_id, $stats_promo_id]);
        $rows = $stmt_students->fetchAll(PDO::FETCH_OBJ);

        $success = 0;
        $failed = 0;
        $missing = 0;
        $effectiveMax = itm_effective_maxima($selectedCourse->semestre, $selectedCourse->coefficient, $period_calc_stats);
        $threshold = $effectiveMax / 2;

        foreach ($rows as $row) {
            if (!itm_has_grade_for_period($row, $selectedCourse->semestre, $period_calc_stats)) {
                $missing++;
                continue;
            }

            $points = itm_points_for_period($row, $selectedCourse->semestre, $selectedCourse->coefficient, $period_calc_stats);
            if ($points >= $threshold) {
                $success++;
            } else {
                $failed++;
            }
        }

        $total = count($rows);
        $course_stats = [
            'course' => $selectedCourse,
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'missing' => $missing,
            'graded' => $success + $failed,
            'threshold' => $threshold,
            'max' => $effectiveMax,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
            'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 1) : 0,
        ];
    }
}

// 4. JURY ANNUEL
$jury_message = "";
$jury_promo_id = isset($_GET['jury_promo_id']) ? (int)$_GET['jury_promo_id'] : 0;
$jury_filter = $_GET['jury_filter'] ?? '';
$jury_filter = in_array($jury_filter, ['ADMIS', 'ECHEC'], true) ? $jury_filter : '';
$jury_student_id = isset($_GET['jury_student_id']) ? (int)$_GET['jury_student_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jury_update_grade'])) {
    require_csrf();
    $jury_student_id = (int)($_POST['jury_student_id'] ?? 0);
    $stmt_student = $pdo->prepare("SELECT id, promotion_id FROM apprenants WHERE id = ? LIMIT 1");
    $stmt_student->execute([$jury_student_id]);
    $student_for_update = $stmt_student->fetch(PDO::FETCH_OBJ);

    if (!$student_for_update) {
        $jury_message = "error|Apprenant introuvable.";
    } else {
        try {
            $pdo->beginTransaction();
            foreach ($_POST['jury_notes'] ?? [] as $course_id => $periods) {
                if (!is_array($periods)) {
                    continue;
                }
                foreach ($periods as $period => $rawValue) {
                    if (!in_array($period, ['S1', 'S2'], true)) {
                        continue;
                    }
                    itm_upsert_period_grade($pdo, $jury_student_id, $course_id, $admin_id, $period, $rawValue);
                }
            }
            itm_refresh_student_decisions($pdo, $jury_student_id, $student_for_update->promotion_id);
            $pdo->commit();
            $jury_message = "success|Cotes du jury actualisees et bulletin annuel mis a jour.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            itm_log_error('Jury grade update failed: ' . $e->getMessage());
            $jury_message = "error|" . $e->getMessage();
        }
    }
}

$jury_students_all = $pdo->query("
    SELECT a.*, p.nom AS promo_nom
    FROM apprenants a
    JOIN promotions p ON p.id = a.promotion_id
    ORDER BY p.nom ASC, a.nom ASC, a.post_nom ASC, a.prenom ASC
")->fetchAll(PDO::FETCH_OBJ);

$jury_global = ['total' => 0, 'admis' => 0, 'echec' => 0, 'taux' => 0, 'echec_taux' => 0];
$jury_by_promo = [];
$jury_students = [];
$jury_selected_student = null;
$jury_selected_result = null;

foreach ($promotions as $promo) {
    $jury_by_promo[(int)$promo->id] = [
        'id' => (int)$promo->id,
        'promo' => $promo->nom,
        'total' => 0,
        'admis' => 0,
        'echec' => 0,
        'taux' => 0,
        'echec_taux' => 0,
    ];
}

foreach ($jury_students_all as $student) {
    $result = itm_student_result($pdo, $student->id, $student->promotion_id, 'FINAL');
    $student->_jury_result = $result;
    $student->_jury_decision = $result['decision'];
    $student->_jury_percentage = $result['percentage'];
    $student->_jury_failed_count = count($result['failed_courses']);
    $student->_jury_missing = $result['missing'];

    $promoKey = (int)$student->promotion_id;
    if (!isset($jury_by_promo[$promoKey])) {
        $jury_by_promo[$promoKey] = [
            'id' => $promoKey,
            'promo' => $student->promo_nom,
            'total' => 0,
            'admis' => 0,
            'echec' => 0,
            'taux' => 0,
            'echec_taux' => 0,
        ];
    }

    $jury_global['total']++;
    $jury_by_promo[$promoKey]['total']++;
    if ($result['decision'] === 'ADMIS') {
        $jury_global['admis']++;
        $jury_by_promo[$promoKey]['admis']++;
    } else {
        $jury_global['echec']++;
        $jury_by_promo[$promoKey]['echec']++;
    }

    if ($jury_filter !== '' && $result['decision'] === $jury_filter && ($jury_promo_id <= 0 || (int)$student->promotion_id === $jury_promo_id)) {
        $jury_students[] = $student;
    }

    if ($jury_student_id > 0 && (int)$student->id === $jury_student_id) {
        $jury_selected_student = $student;
        $jury_selected_result = $result;
    }
}

$jury_global['taux'] = $jury_global['total'] > 0 ? round(($jury_global['admis'] / $jury_global['total']) * 100, 1) : 0;
$jury_global['echec_taux'] = $jury_global['total'] > 0 ? round(($jury_global['echec'] / $jury_global['total']) * 100, 1) : 0;
foreach ($jury_by_promo as &$promoStats) {
    $promoStats['taux'] = $promoStats['total'] > 0 ? round(($promoStats['admis'] / $promoStats['total']) * 100, 1) : 0;
    $promoStats['echec_taux'] = $promoStats['total'] > 0 ? round(($promoStats['echec'] / $promoStats['total']) * 100, 1) : 0;
}
unset($promoStats);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITMCD</title>
    
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Symbols+Rounded" rel="stylesheet">
    
    <style>
        :root {
            --md-sys-color-background: #eef3f8;
            --md-sys-color-surface: #ffffff;
            --md-sys-color-surface-container: #f8fafc;
            --md-sys-color-primary: #2563eb;
            --md-sys-color-on-primary: #ffffff;
            --md-sys-color-secondary: #0891b2;
            --md-sys-color-error: #b91c1c;
            --md-sys-color-success: #15803d;
            --md-sys-color-warning: #b45309;
            --md-sys-color-on-surface: #132033;
            --md-sys-color-on-surface-variant: #64748b;
            --md-sys-color-outline: #dbe5f0;
            --top-bar-height: 64px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--md-sys-color-background);
            color: var(--md-sys-color-on-surface);
            font-family: 'Roboto', sans-serif;
            line-height: 1.5;
            min-height: 100vh;
        }

        /* --- EN-TÊTE FIXE --- */
        .top-app-bar {
            background-color: var(--md-sys-color-surface);
            height: var(--top-bar-height);
            display: flex;
            align-items: center;
            padding: 0 16px;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 10px 28px rgba(15,23,42,0.08);
            border-bottom: 1px solid var(--md-sys-color-outline);
        }

        .headline {
            font-size: clamp(16px, 4vw, 22px);
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .logo-img { height: 32px; width: auto; flex-shrink: 0; }

        .icon-btn {
            margin-left: auto;
            color: var(--md-sys-color-on-surface-variant);
            padding: 8px;
            border-radius: 50%;
            text-decoration: none;
            display: flex;
        }

        /* --- CONTENU --- */
        .main-content {
            padding: 16px;
            margin-top: var(--top-bar-height); /* Compense l'entête fixe */
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Responsive : Passage en colonnes sur tablette/PC */
        @media (min-width: 850px) {
            .main-content {
                display: grid;
                grid-template-columns: 350px 1fr;
                padding: 24px;
                gap: 24px;
            }
        }

        .card {
            background-color: var(--md-sys-color-surface);
            border: 1px solid var(--md-sys-color-outline);
            border-radius: 8px;
            padding: clamp(16px, 3vw, 24px);
            height: fit-content;
            box-shadow: 0 18px 42px rgba(15,23,42,0.08);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        /* --- FORMULAIRE --- */
        .form-select, .text-field {
            width: 100%;
            background-color: var(--md-sys-color-surface-container);
            border: 1px solid var(--md-sys-color-outline);
            color: var(--md-sys-color-on-surface);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 16px;
        }

        .btn-filled {
            background-color: var(--md-sys-color-primary);
            color: var(--md-sys-color-on-primary);
            border: none;
            border-radius: 100px;
            height: 48px;
            font-weight: 500;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        /* --- TABLEAU RESPONSIVE --- */
        .table-wrapper {
            width: 100%;
            overflow-x: auto; /* Permet le défilement horizontal sur mobile */
            -webkit-overflow-scrolling: touch;
            border-radius: 8px;
            background: var(--md-sys-color-surface-container);
            border: 1px solid var(--md-sys-color-outline);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px; /* Assure que le tableau ne s'écrase pas trop */
        }

        .data-table th {
            text-align: left;
            padding: 12px;
            font-size: 12px;
            color: var(--md-sys-color-on-surface-variant);
            border-bottom: 1px solid var(--md-sys-color-outline);
        }

        .data-table td {
            padding: 16px 12px;
            font-size: 14px;
            border-bottom: 1px solid var(--md-sys-color-outline);
        }

        /* --- BARRE DE PROGRESSION --- */
        .progress-container {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .progress-track {
            background: #e2e8f0;
            height: 6px;
            width: 60px;
            border-radius: 3px;
            overflow: hidden;
        }

        @media (min-width: 480px) { .progress-track { width: 100px; } }

        .progress-indicator {
            background: linear-gradient(90deg, var(--md-sys-color-primary), var(--md-sys-color-secondary));
            height: 100%;
        }

        .stats-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stats-form .btn-filled { align-self: end; }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .metric {
            border: 1px solid var(--md-sys-color-outline);
            border-radius: 8px;
            background: var(--md-sys-color-surface-container);
            padding: 14px;
            min-width: 0;
        }

        .metric span {
            display: block;
            color: var(--md-sys-color-on-surface-variant);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .metric strong {
            display: block;
            color: var(--md-sys-color-on-surface);
            font-size: clamp(1.2rem, 3vw, 1.7rem);
            line-height: 1;
        }

        .metric.success strong { color: var(--md-sys-color-success); }
        .metric.danger strong { color: var(--md-sys-color-error); }
        .metric.warning strong { color: var(--md-sys-color-warning); }

        .course-summary {
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: var(--md-sys-color-primary);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.45;
        }

        .system-message {
            border: 1px solid var(--md-sys-color-outline);
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.45;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            overflow-wrap: anywhere;
        }

        .system-message[data-kind="success"] {
            background: rgba(21, 128, 61, .10);
            color: var(--md-sys-color-success);
            border-color: rgba(21, 128, 61, .22);
        }

        .system-message[data-kind="error"] {
            background: rgba(185, 28, 28, .10);
            color: var(--md-sys-color-error);
            border-color: rgba(185, 28, 28, .24);
        }

        .jury-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .jury-global-grid,
        .jury-promo-grid {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .jury-global-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .jury-promo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .jury-stat-card,
        .jury-promo-card {
            display: block;
            min-width: 0;
            text-decoration: none;
            color: inherit;
            border: 1px solid var(--md-sys-color-outline);
            background: var(--md-sys-color-surface-container);
            border-radius: 8px;
            padding: 14px;
            transition: border-color .18s ease, transform .18s ease, background .18s ease;
            overflow-wrap: anywhere;
        }

        .jury-stat-card:hover,
        .jury-promo-card:hover,
        .jury-promo-card.active {
            border-color: var(--md-sys-color-primary);
            background: #eff6ff;
            transform: translateY(-1px);
        }

        .jury-stat-card span,
        .jury-promo-card span {
            display: block;
            color: var(--md-sys-color-on-surface-variant);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .jury-stat-card strong,
        .jury-promo-card strong {
            display: block;
            font-size: 1.55rem;
            line-height: 1.05;
        }

        .jury-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .jury-chip {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--md-sys-color-outline);
            background: #fff;
            color: var(--md-sys-color-on-surface);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.15;
        }

        .jury-chip.success { color: var(--md-sys-color-success); }
        .jury-chip.danger { color: var(--md-sys-color-error); }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            border: 1px solid var(--md-sys-color-outline);
            white-space: nowrap;
        }

        .status-pill.success {
            color: var(--md-sys-color-success);
            background: rgba(21, 128, 61, .10);
            border-color: rgba(21, 128, 61, .22);
        }

        .status-pill.danger {
            color: var(--md-sys-color-error);
            background: rgba(185, 28, 28, .10);
            border-color: rgba(185, 28, 28, .22);
        }

        .status-pill.warning {
            color: var(--md-sys-color-warning);
            background: rgba(180, 83, 9, .12);
            border-color: rgba(180, 83, 9, .24);
        }

        .student-open {
            color: var(--md-sys-color-primary);
            font-weight: 800;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        .jury-detail {
            margin-top: 16px;
            border-top: 1px solid var(--md-sys-color-outline);
            padding-top: 16px;
        }

        .jury-period-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 16px 0 8px;
            color: var(--md-sys-color-on-surface-variant);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .jury-period-heading::after {
            content: "";
            height: 1px;
            flex: 1;
            background: var(--md-sys-color-outline);
        }

        .jury-grade-row {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(210px, .9fr);
            gap: 12px;
            align-items: center;
            padding: 14px;
            border: 1px solid var(--md-sys-color-outline);
            border-radius: 8px;
            background: var(--md-sys-color-surface-container);
            margin-bottom: 10px;
        }

        .jury-grade-row.success {
            background: rgba(21, 128, 61, .06);
            border-color: rgba(21, 128, 61, .18);
        }

        .jury-grade-row.danger {
            background: rgba(185, 28, 28, .06);
            border-color: rgba(185, 28, 28, .20);
        }

        .jury-grade-row.warning {
            background: rgba(180, 83, 9, .07);
            border-color: rgba(180, 83, 9, .22);
        }

        .jury-grade-title {
            min-width: 0;
            font-weight: 850;
            overflow-wrap: anywhere;
        }

        .jury-grade-meta {
            margin-top: 4px;
            color: var(--md-sys-color-on-surface-variant);
            font-size: 12px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .jury-grade-topline {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .jury-grade-score {
            flex-shrink: 0;
            color: var(--md-sys-color-on-surface);
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .jury-grade-inputs {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
        }

        .jury-grade-inputs label {
            display: grid;
            gap: 4px;
            min-width: 82px;
            color: var(--md-sys-color-on-surface-variant);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .jury-grade-inputs input {
            width: 100%;
            min-height: 42px;
            border: 1px solid var(--md-sys-color-outline);
            border-radius: 8px;
            background: #fff;
            color: var(--md-sys-color-on-surface);
            padding: 8px;
            font-weight: 850;
        }

        .jury-grade-inputs input:focus {
            outline: 2px solid rgba(37, 99, 235, .25);
            border-color: var(--md-sys-color-primary);
        }

        .jury-empty {
            padding: 16px;
            border: 1px dashed var(--md-sys-color-outline);
            border-radius: 8px;
            color: var(--md-sys-color-on-surface-variant);
            font-size: 14px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        /* --- MODAL --- */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            padding: 16px;
        }
        .modal-overlay.open { display: flex; }

        .modal-dialog {
            background: var(--md-sys-color-surface-container);
            padding: 24px;
            border-radius: 8px;
            width: 100%;
            max-width: 400px;
            border: 1px solid var(--md-sys-color-outline);
        }

        .modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
        .btn-text {
            background: transparent;
            color: var(--md-sys-color-primary);
            border: none;
            padding: 12px;
            cursor: pointer;
            font-weight: 500;
        }

        @media (max-width: 760px) {
            .stats-form,
            .metric-grid,
            .jury-global-grid,
            .jury-promo-grid {
                grid-template-columns: 1fr;
            }

            .jury-grade-row {
                grid-template-columns: 1fr;
            }

            .jury-grade-inputs {
                justify-content: flex-start;
            }

            .jury-stat-card strong,
            .jury-promo-card strong {
                font-size: 1.28rem;
            }
        }
    </style>
</head>
<body>

<header class="top-app-bar">
    <div class="headline">
        <img src="../images/LOGO.png" alt="Logo" class="logo-img">
        <span>Admin ITMCD</span>
    </div>
    <a href="index.php" class="icon-btn">
        <span class="material-symbols-rounded">close</span>
    </a>
</header>

<main class="main-content">
    
    <section>
        <div class="card">
            <div class="card-header">
                <span class="material-symbols-rounded" style="color: var(--md-sys-color-primary);">gavel</span>
                <div class="card-title" style="font-size: 18px;">Délibération</div>
            </div>

            <?php if($message): [$type, $txt] = array_pad(explode('|', $message, 2), 2, 'Operation terminee.'); ?>
                <div class="system-message" data-kind="<?= delib_message_kind($type) ?>">
                    <span class="material-symbols-rounded" aria-hidden="true"><?= $type === 'success' ? 'check_circle' : 'error' ?></span>
                    <span><?= delib_e($txt) ?></span>
                </div>
            <?php endif; ?>

            <form id="deliberationForm">
                <label style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">PÉRIODE</label>
                <select id="pSelect" class="form-select">
                    <option value="S1" <?= $semestre_view === 'S1' ? 'selected' : '' ?>>Semestre 1</option>
                    <option value="S2" <?= $semestre_view === 'S2' ? 'selected' : '' ?>>Semestre 2</option>
                    <option value="JURY" <?= $semestre_view === 'JURY' ? 'selected' : '' ?>>Jury Annuel</option>
                </select>

                <button type="button" onclick="openModal()" class="btn-filled">
                    <span class="material-symbols-rounded">play_arrow</span>
                    Lancer le calcul
                </button>
            </form>
        </div>
    </section>

    <section>
        <div class="card">
            <div class="card-header" style="justify-content: space-between;">
                <div class="card-title">Aperçu <?= $semestre_view ?></div>
                <div style="font-size: 12px; opacity: 0.7;"><?= date('Y') ?></div>
            </div>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PROMOTION</th>
                            <th style="text-align: center;">EFFECTIF</th>
                            <th style="text-align: center;">ADMIS</th>
                            <th style="text-align: right;">TAUX</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($stats_display as $s): ?>
                        <tr>
                            <td style="font-weight: 500;"><?= htmlspecialchars($s['promo']) ?></td>
                            <td style="text-align: center;"><?= $s['total'] ?></td>
                            <td style="text-align: center; color: var(--md-sys-color-success); font-weight: bold;"><?= $s['admis'] ?></td>
                            <td style="text-align: right;">
                                <div class="progress-container">
                                    <span><?= $s['taux'] ?>%</span>
                                    <div class="progress-track">
                                        <div class="progress-indicator" style="width: <?= $s['taux'] ?>%"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="margin-top: 16px;">
            <div class="card-header" style="justify-content: space-between;">
                <div>
                    <div class="card-title">Statistiques par cours</div>
                    <div style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">Réussites et échecs selon le cours sélectionné</div>
                </div>
                <span class="material-symbols-rounded" style="color: var(--md-sys-color-primary);">query_stats</span>
            </div>

            <form method="GET" class="stats-form">
                <div>
                    <label style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">PROMOTION</label>
                    <select name="stats_promo_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($promotions as $promo): ?>
                            <option value="<?= (int)$promo->id ?>" <?= $stats_promo_id === (int)$promo->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($promo->nom) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">PÉRIODE</label>
                    <select name="semestre_stats" class="form-select" onchange="this.form.submit()">
                        <option value="S1" <?= $stats_semestre === 'S1' ? 'selected' : '' ?>>Semestre 1</option>
                        <option value="S2" <?= $stats_semestre === 'S2' ? 'selected' : '' ?>>Semestre 2</option>
                        <option value="JURY" <?= $stats_semestre === 'JURY' ? 'selected' : '' ?>>Jury annuel</option>
                    </select>
                </div>

                <div>
                    <label style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">COURS</label>
                    <select name="stats_course_id" class="form-select" onchange="this.form.submit()" <?= empty($stats_courses) ? 'disabled' : '' ?>>
                        <?php if (empty($stats_courses)): ?>
                            <option value="">Aucun cours</option>
                        <?php else: ?>
                            <?php foreach ($stats_courses as $course): ?>
                                <option value="<?= (int)$course->id ?>" <?= $stats_course_id === (int)$course->id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($course->nom) ?> - <?= htmlspecialchars($course->semestre) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </form>

            <?php if ($course_stats): ?>
                <div class="course-summary">
                    <?= htmlspecialchars($course_stats['course']->nom) ?> • <?= htmlspecialchars($course_stats['course']->promo_nom) ?>
                    • Seuil de réussite : <?= itm_format_points($course_stats['threshold']) ?>/<?= itm_format_points($course_stats['max']) ?>
                    <?php if (!empty($course_stats['course']->prof_nom)): ?>
                        • Prof. <?= htmlspecialchars($course_stats['course']->prof_nom) ?>
                    <?php endif; ?>
                </div>
                <div class="metric-grid">
                    <div class="metric success">
                        <span>Réussites</span>
                        <strong><?= (int)$course_stats['success'] ?></strong>
                    </div>
                    <div class="metric danger">
                        <span>Échecs</span>
                        <strong><?= (int)$course_stats['failed'] ?></strong>
                    </div>
                    <div class="metric warning">
                        <span>Non encodés</span>
                        <strong><?= (int)$course_stats['missing'] ?></strong>
                    </div>
                </div>
                <div class="progress-container" style="justify-content: flex-start; margin-top: 14px;">
                    <span><?= $course_stats['success_rate'] ?>% réussite</span>
                    <div class="progress-track" style="width: min(220px, 55vw);">
                        <div class="progress-indicator" style="width: <?= $course_stats['success_rate'] ?>%"></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="course-summary">Sélectionnez une promotion et un cours pour afficher les statistiques.</div>
            <?php endif; ?>
        </div>

        <div class="card" id="jury-panel" style="margin-top: 16px;">
            <div class="card-header" style="justify-content: space-between;">
                <div>
                    <div class="card-title">Jury annuel</div>
                    <div style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">Statistiques globales, par promotion et delibération individuelle</div>
                </div>
                <span class="material-symbols-rounded" style="color: var(--md-sys-color-primary);">groups</span>
            </div>

            <?php if($jury_message): [$jury_type, $jury_txt] = array_pad(explode('|', $jury_message, 2), 2, 'Operation terminee.'); ?>
                <div class="system-message" data-kind="<?= delib_message_kind($jury_type) ?>">
                    <span class="material-symbols-rounded" aria-hidden="true"><?= $jury_type === 'success' ? 'check_circle' : 'error' ?></span>
                    <span><?= delib_e($jury_txt) ?></span>
                </div>
            <?php endif; ?>

            <form method="GET" class="jury-controls">
                <div>
                    <label style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">PROMOTION DU JURY</label>
                    <select name="jury_promo_id" class="form-select" onchange="this.form.submit()">
                        <option value="0" <?= $jury_promo_id <= 0 ? 'selected' : '' ?>>Toutes les promotions</option>
                        <?php foreach ($promotions as $promo): ?>
                            <option value="<?= (int)$promo->id ?>" <?= $jury_promo_id === (int)$promo->id ? 'selected' : '' ?>>
                                <?= delib_e($promo->nom) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($jury_filter !== ''): ?>
                        <input type="hidden" name="jury_filter" value="<?= delib_e($jury_filter) ?>">
                    <?php endif; ?>
                </div>
            </form>

            <div class="jury-global-grid">
                <a class="jury-stat-card" href="<?= delib_build_url(['jury_filter' => '', 'jury_promo_id' => $jury_promo_id, 'jury_student_id' => ''], 'jury-panel') ?>">
                    <span>Effectif global</span>
                    <strong><?= (int)$jury_global['total'] ?></strong>
                    <small><?= $jury_global['taux'] ?>% de réussite</small>
                </a>
                <a class="jury-stat-card" href="<?= delib_build_url(['jury_filter' => 'ADMIS', 'jury_promo_id' => 0, 'jury_student_id' => ''], 'jury-panel') ?>">
                    <span>Réussite globale</span>
                    <strong style="color: var(--md-sys-color-success);"><?= (int)$jury_global['admis'] ?></strong>
                    <small>Voir tous les admis</small>
                </a>
                <a class="jury-stat-card" href="<?= delib_build_url(['jury_filter' => 'ECHEC', 'jury_promo_id' => 0, 'jury_student_id' => ''], 'jury-panel') ?>">
                    <span>Échec global</span>
                    <strong style="color: var(--md-sys-color-error);"><?= (int)$jury_global['echec'] ?></strong>
                    <small><?= $jury_global['echec_taux'] ?>% • Voir tous les échecs</small>
                </a>
            </div>

            <div class="jury-promo-grid">
                <?php foreach ($jury_by_promo as $promoStats): ?>
                    <div class="jury-promo-card <?= $jury_promo_id === (int)$promoStats['id'] ? 'active' : '' ?>">
                        <span><?= delib_e($promoStats['promo']) ?></span>
                        <strong><?= (int)$promoStats['total'] ?> apprenants</strong>
                        <small><?= $promoStats['taux'] ?>% réussite • <?= $promoStats['echec_taux'] ?>% échec</small>
                        <div class="jury-card-actions">
                            <a class="jury-chip success" href="<?= delib_build_url(['jury_filter' => 'ADMIS', 'jury_promo_id' => (int)$promoStats['id'], 'jury_student_id' => ''], 'jury-panel') ?>">
                                <?= (int)$promoStats['admis'] ?> admis
                            </a>
                            <a class="jury-chip danger" href="<?= delib_build_url(['jury_filter' => 'ECHEC', 'jury_promo_id' => (int)$promoStats['id'], 'jury_student_id' => ''], 'jury-panel') ?>">
                                <?= (int)$promoStats['echec'] ?> échec(s)
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($jury_filter !== ''): ?>
                <div class="table-wrapper" style="margin-top: 16px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>APPRENANT</th>
                                <th>PROMOTION</th>
                                <th style="text-align: center;">%</th>
                                <th style="text-align: center;">COURS À REVOIR</th>
                                <th style="text-align: right;">DÉCISION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jury_students as $student): ?>
                                <tr>
                                    <td>
                                        <a class="student-open" href="<?= delib_build_url(['jury_student_id' => (int)$student->id, 'jury_filter' => $jury_filter, 'jury_promo_id' => $jury_promo_id], 'jury-detail') ?>">
                                            <?= delib_e(delib_student_label($student)) ?>
                                        </a>
                                        <div style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);"><?= delib_e($student->code_unique ?? '') ?></div>
                                    </td>
                                    <td><?= delib_e($student->promo_nom) ?></td>
                                    <td style="text-align: center; font-weight: 800;"><?= number_format($student->_jury_percentage, 2) ?>%</td>
                                    <td style="text-align: center;"><?= (int)$student->_jury_failed_count ?><?= $student->_jury_missing ? ' dont ' . (int)$student->_jury_missing . ' incomplet(s)' : '' ?></td>
                                    <td style="text-align: right;">
                                        <span class="status-pill <?= $student->_jury_decision === 'ADMIS' ? 'success' : 'danger' ?>">
                                            <?= delib_e($student->_jury_decision) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($jury_students)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--md-sys-color-on-surface-variant);">
                                        Aucun apprenant pour ce filtre.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="jury-empty" style="margin-top: 16px;">
                    Cliquez sur une réussite ou un échec global, ou sur une statistique de promotion, pour afficher le tableau détaillé des apprenants.
                </div>
            <?php endif; ?>

            <?php if ($jury_selected_student && $jury_selected_result): ?>
                <div class="jury-detail" id="jury-detail">
                    <div class="card-header" style="padding: 0; margin-bottom: 12px; justify-content: space-between;">
                        <div>
                            <div class="card-title"><?= delib_e(delib_student_label($jury_selected_student)) ?></div>
                            <div style="font-size: 12px; color: var(--md-sys-color-on-surface-variant);">
                                <?= delib_e($jury_selected_student->promo_nom) ?> • <?= number_format($jury_selected_result['percentage'], 2) ?>%
                            </div>
                        </div>
                        <span class="status-pill <?= $jury_selected_result['decision'] === 'ADMIS' ? 'success' : 'danger' ?>">
                            <?= delib_e($jury_selected_result['decision']) ?>
                        </span>
                    </div>

                    <?php $jury_courses = $jury_selected_result['courses'] ?? []; ?>
                    <?php if (!empty($jury_courses)): ?>
                        <form method="POST" action="<?= delib_build_url(['jury_student_id' => (int)$jury_selected_student->id], 'jury-detail') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="jury_student_id" value="<?= (int)$jury_selected_student->id ?>">
                            <div class="jury-empty" style="margin-bottom: 12px;">
                                Toutes les cotes de l'apprenant sont affichées ci-dessous. Les lignes rouges ou orange indiquent les cours à corriger en priorité.
                            </div>
                            <?php $last_semestre = null; ?>
                            <?php foreach ($jury_courses as $course): ?>
                                <?php
                                    if ($last_semestre !== $course->semestre):
                                        $last_semestre = $course->semestre;
                                ?>
                                    <div class="jury-period-heading"><?= delib_e($course->semestre === 'ANNUEL' ? 'Cours annuels' : $course->semestre) ?></div>
                                <?php endif; ?>
                                <?php
                                    $row_kind = !$course->_has_grade ? 'warning' : ($course->_failed ? 'danger' : 'success');
                                    $row_status = !$course->_has_grade ? 'Incomplet' : ($course->_failed ? 'A revoir' : 'OK');
                                ?>
                                <div class="jury-grade-row <?= $row_kind ?>">
                                    <div>
                                        <div class="jury-grade-topline">
                                            <div class="jury-grade-title"><?= delib_e($course->cours_nom) ?></div>
                                            <span class="status-pill <?= $row_kind ?>"><?= delib_e($row_status) ?></span>
                                        </div>
                                        <div class="jury-grade-meta">
                                            <?= delib_e($course->semestre) ?> • Max <?= itm_format_points($course->_max) ?> • Obtenu <?= itm_format_points($course->_points) ?> • Seuil <?= itm_format_points($course->_threshold) ?>
                                            <?php if (!empty($course->prof_nom)): ?>
                                                • Prof. <?= delib_e($course->prof_nom) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="jury-grade-score">
                                            <?= $course->_has_grade ? number_format($course->_percentage, 1) . '%' : 'Cote manquante' ?>
                                        </div>
                                    </div>
                                    <div class="jury-grade-inputs">
                                        <?php foreach (itm_allowed_periods_for_course($course->semestre) as $period): ?>
                                            <?php
                                                $periodValue = $period === 'S2' ? $course->cote_s2 : $course->cote_s1;
                                                if ($periodValue === null && $course->semestre !== 'ANNUEL') {
                                                    $periodValue = $course->cote;
                                                } elseif ($periodValue === null && $course->semestre === 'ANNUEL' && $period === 'S1' && $course->cote_s2 === null) {
                                                    $periodValue = $course->cote;
                                                }
                                            ?>
                                            <label>
                                                <?= delib_e(itm_period_label($period)) ?>
                                                <input type="number"
                                                       step="0.1"
                                                       min="0"
                                                       max="<?= itm_format_points($course->coefficient) ?>"
                                                       name="jury_notes[<?= (int)$course->cours_id ?>][<?= delib_e($period) ?>]"
                                                       placeholder="0 à <?= itm_format_points($course->coefficient) ?>"
                                                       value="<?= $periodValue !== null ? itm_format_points($periodValue) : '' ?>">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" name="jury_update_grade" class="btn-filled" style="margin-top: 12px;">
                                <span class="material-symbols-rounded">verified</span>
                                Actualiser les cotes et recalculer le jury
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="jury-empty">
                            Aucun cours trouvé pour cet apprenant.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<div class="modal-overlay" id="safetyModal">
    <div class="modal-dialog">
        <h3 style="margin-bottom: 12px;">Confirmation</h3>
        <p style="font-size: 14px; color: var(--md-sys-color-on-surface-variant); margin-bottom: 20px;">
            Veuillez saisir votre mot de passe pour confirmer la délibération.
        </p>
        
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="semestre" id="hidSem">
            <input type="password" name="admin_password" class="text-field" placeholder="Mot de passe" required>
            
            <div class="modal-actions">
                <button type="button" class="btn-text" onclick="closeModal()">ANNULER</button>
                <button type="submit" name="confirmer_deliberation" class="btn-text" style="color: var(--md-sys-color-error)">VALIDER</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() {
        document.getElementById('hidSem').value = document.getElementById('pSelect').value;
        document.getElementById('safetyModal').classList.add('open');
    }

    function closeModal() {
        document.getElementById('safetyModal').classList.remove('open');
    }

    // Fermeture au clic extérieur
    window.onclick = (e) => { if(e.target == document.getElementById('safetyModal')) closeModal(); }
</script>

</body>
</html>
