<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'enseignant') {
    header("Location: ../login.php");
    exit();
}

$enseignant_id = (int)$_SESSION['user']->id;
$enseignant_nom = $_SESSION['user']->nom;
$can_encode_s1 = itm_setting($pdo, 'feature_encode_s1', true);
$can_encode_s2 = itm_setting($pdo, 'feature_encode_s2', true);
$can_update_notes = itm_setting($pdo, 'feature_update_notes', false);

if (!$can_encode_s1 && !$can_encode_s2) {
    http_response_code(403);
    die("L'encodage des cotes est desactive par ladmin.");
}

function enc_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function enc_alert($kind, $message) {
    return '<div class="ultra-alert ' . enc_e($kind) . '">' . enc_e($message) . '</div>';
}

function enc_period_from_note($typeNote, $courseSemester) {
    if ($typeNote === 'cote_s2') {
        return 'S2';
    }
    if ($typeNote === 'cote_s1') {
        return 'S1';
    }

    return $courseSemester === 'S2' ? 'S2' : 'S1';
}

function enc_can_encode_period($period, $canEncodeS1, $canEncodeS2) {
    return $period === 'S2' ? $canEncodeS2 : $canEncodeS1;
}

function enc_course_mode_label($semester) {
    if ($semester === 'ANNUEL') {
        return 'Cours annuel';
    }

    return $semester === 'S2' ? 'Cours semestriel S2' : 'Cours semestriel S1';
}

function enc_student_name($row) {
    return trim(($row['nom'] ?? '') . ' ' . ($row['post_nom'] ?? '') . ' ' . ($row['prenom'] ?? ''));
}

if (isset($_GET['ajax_search'], $_GET['term'], $_GET['cours_id'])) {
    $term = strtoupper(trim((string)$_GET['term']));
    $cours_target = (int)$_GET['cours_id'];
    $results = [];

    if ($term !== '' && $cours_target > 0) {
        $stmt_ajax = $pdo->prepare("
            SELECT a.code_unique, p.nom AS promo_nom,
                   MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) AS cote,
                   MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) AS cote_s1,
                   MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) AS cote_s2
            FROM apprenants a
            JOIN cours c ON c.promotion_id = a.promotion_id
            JOIN promotions p ON p.id = a.promotion_id
            LEFT JOIN notes n ON n.apprenant_id = a.id
                AND n.cours_id = c.id
                AND n.type_note IN ('cote', 'cote_s1', 'cote_s2')
            WHERE c.id = ?
              AND c.enseignant_id = ?
              AND a.code_unique LIKE ?
            GROUP BY a.id, a.code_unique, p.nom
            ORDER BY a.code_unique ASC
            LIMIT 10
        ");
        $stmt_ajax->execute([$cours_target, $enseignant_id, $term . '%']);
        $results = array_map(function ($row) {
            return [
                'code' => $row['code_unique'],
                'promotion' => $row['promo_nom'],
                's1' => $row['cote_s1'] ?? $row['cote'] ?? null,
                's2' => $row['cote_s2'] ?? null,
            ];
        }, $stmt_ajax->fetchAll(PDO::FETCH_ASSOC));
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results);
    exit;
}

$message = "";
$selected_cours_id = isset($_GET['cours_id']) ? (int)$_GET['cours_id'] : 0;
$selected_period = in_array(($_GET['periode'] ?? ''), ['S1', 'S2'], true) ? $_GET['periode'] : null;

if (isset($_POST['save_examen'])) {
    require_csrf();

    $cours_id = (int)($_POST['cours_id'] ?? 0);
    $selected_cours_id = $cours_id;
    $requested_period = $_POST['periode'] ?? null;
    $selected_period = in_array($requested_period, ['S1', 'S2'], true) ? $requested_period : null;
    $code_saisi = strtoupper(trim((string)($_POST['code_unique'] ?? '')));
    $note_examen = str_replace(',', '.', trim((string)($_POST['note'] ?? '')));

    if ($cours_id <= 0) {
        $message = enc_alert('error', 'Veuillez selectionner un cours.');
    } elseif ($code_saisi === '') {
        $message = enc_alert('error', "Veuillez renseigner le code de l'apprenant.");
    } else {
        $stmt_info = $pdo->prepare("
            SELECT a.id as apprenant_id, a.promotion_id as promo_eleve, a.nom, a.post_nom, a.prenom,
                   c.promotion_id as promo_cours, c.nom as cours_nom, c.coefficient as maxima, c.semestre,
                   p.nom as promo_nom
            FROM apprenants a
            JOIN cours c ON c.id = ? AND c.enseignant_id = ?
            JOIN promotions p ON p.id = c.promotion_id
            WHERE a.code_unique = ?
            LIMIT 1
        ");
        $stmt_info->execute([$cours_id, $enseignant_id, $code_saisi]);
        $info = $stmt_info->fetch(PDO::FETCH_ASSOC);

        if (!$info) {
            $message = enc_alert('error', "Code apprenant ou cours introuvable.");
        } else {
            $periode = itm_resolve_period_for_course($info['semestre'], $selected_period);
            $selected_period = $periode;
            $note_type = itm_note_type_for_period($periode);

            if (!enc_can_encode_period($periode, $can_encode_s1, $can_encode_s2)) {
                $message = enc_alert('error', 'Encodage ' . itm_period_label($periode) . ' verrouille par ladmin.');
            } elseif (!is_numeric($note_examen) || (float)$note_examen < 0 || (float)$note_examen > (float)$info['maxima']) {
                $message = enc_alert('error', 'La cote doit etre comprise entre 0 et ' . itm_format_points($info['maxima']) . '.');
            } elseif ((int)$info['promo_eleve'] !== (int)$info['promo_cours']) {
                $message = enc_alert('error', 'Cet apprenant ne fait pas partie de la promotion du cours selectionne.');
            } else {
                $check_notes = $pdo->prepare("
                    SELECT id, type_note, valeur
                    FROM notes
                    WHERE apprenant_id = ?
                      AND cours_id = ?
                      AND type_note IN (?, 'cote')
                    ORDER BY FIELD(type_note, ?, 'cote') ASC, id DESC
                    LIMIT 1
                ");
                $check_notes->execute([$info['apprenant_id'], $cours_id, $note_type, $note_type]);
                $existing_note = $check_notes->fetch(PDO::FETCH_ASSOC);

                if ($existing_note && !$can_update_notes) {
                    $message = enc_alert('error', 'Cote deja enregistree. La mise a jour est desactivee par ladmin.');
                } else {
                    try {
                        $sync = itm_upsert_period_grade($pdo, $info['apprenant_id'], $cours_id, $enseignant_id, $periode, $note_examen);
                        if ($sync) {
                            itm_refresh_student_decisions($pdo, $info['apprenant_id'], $info['promo_eleve']);
                            $action = $existing_note ? 'mis a jour' : 'encode';
                            itm_insert_notification(
                                $pdo,
                                "Pr. $enseignant_nom a $action la cote " . itm_period_label($periode) . " de " . $info['cours_nom'] . " pour $code_saisi.",
                                'admin',
                                null,
                                'cote'
                            );
                            $message = enc_alert(
                                'success',
                                ($existing_note ? 'Cote mise a jour pour ' : 'Cote enregistree pour ') . $code_saisi . ' (' . itm_period_label($periode) . ').'
                            );
                        }
                    } catch (Throwable $e) {
                        itm_log_error('Teacher grade save failed: ' . $e->getMessage());
                        $message = enc_alert('error', $e->getMessage());
                    }
                }
            }
        }
    }
}

$stmt_c = $pdo->prepare("
    SELECT c.id, c.nom, c.coefficient, c.semestre, p.nom as promo
    FROM cours c
    JOIN promotions p ON c.promotion_id = p.id
    WHERE c.enseignant_id = ?
    ORDER BY p.nom ASC, FIELD(c.semestre, 'S1', 'S2', 'ANNUEL'), c.nom ASC
");
$stmt_c->execute([$enseignant_id]);
$liste_cours = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

$available_course_ids = array_map('intval', array_column($liste_cours, 'id'));
if ($selected_cours_id > 0 && !in_array($selected_cours_id, $available_course_ids, true)) {
    $selected_cours_id = 0;
}

if ($selected_cours_id <= 0 && !empty($liste_cours)) {
    $selected_cours_id = (int)$liste_cours[0]['id'];
}

$sql_hist = "
    SELECT n.id as note_id, n.valeur, n.type_note, n.date_encodage,
           a.code_unique, a.nom, a.post_nom, a.prenom,
           c.id as cours_id, c.nom as cours_nom, c.coefficient, c.semestre,
           p.nom as promo_nom
    FROM notes n
    JOIN apprenants a ON n.apprenant_id = a.id
    JOIN cours c ON n.cours_id = c.id
    JOIN promotions p ON c.promotion_id = p.id
    WHERE c.enseignant_id = ?
      AND n.type_note IN ('cote', 'cote_s1', 'cote_s2')
    ORDER BY n.date_encodage DESC, n.id DESC
";
$stmt_hist = $pdo->prepare($sql_hist);
$stmt_hist->execute([$enseignant_id]);
$historique = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

$course_count = count($liste_cours);
$history_count = count($historique);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>ITMCD | Encodage des cotes</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet">
    <style>
        :root {
            --bg: #eef3f8;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --primary: #2563eb;
            --cyan: #0891b2;
            --green: #15803d;
            --amber: #b45309;
            --red: #b91c1c;
            --ink: #132033;
            --muted: #64748b;
            --line: #dbe5f0;
            --shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }

        .quantum-nav {
            position: fixed;
            inset: 0 0 auto;
            min-height: 74px;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            display: flex;
            align-items: center;
            padding: 10px 16px;
        }

        .nav-content {
            width: min(1180px, 100%);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .nav-left {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 12px;
        }

        .nav-back-circle {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 8px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .nav-title { min-width: 0; }
        .main-t {
            display: block;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800;
            color: var(--primary);
            line-height: 1;
        }
        .page-t {
            display: block;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 800;
            margin-top: 4px;
        }

        .status-strip {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .status-pill,
        .period-badge,
        .mini-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 30px;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.74rem;
            font-weight: 850;
            border: 1px solid var(--line);
            color: var(--muted);
            background: var(--panel-soft);
            white-space: nowrap;
        }

        .status-pill.ok,
        .mini-pill.ok {
            color: var(--green);
            background: #ecfdf5;
            border-color: #bbf7d0;
        }

        .status-pill.locked,
        .mini-pill.locked {
            color: var(--red);
            background: #fef2f2;
            border-color: #fecaca;
        }

        .ultra-container {
            width: min(1180px, calc(100% - 32px));
            margin: 98px auto 42px;
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(360px, 0.98fr);
            gap: 20px;
            align-items: start;
        }

        .quantum-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
            padding: 22px;
        }

        .card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }

        h1,
        h2 {
            font-family: 'Space Grotesk', sans-serif;
            margin: 0;
            color: var(--ink);
            letter-spacing: 0;
        }

        h1 { font-size: clamp(1.35rem, 2.3vw, 1.95rem); line-height: 1.08; }
        h2 { font-size: 1rem; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .field-group.full,
        .course-context,
        .update-hint,
        .submit-row {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 0.74rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        select,
        input {
            width: 100%;
            min-height: 48px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--ink);
            padding: 13px 14px;
            font-size: 0.96rem;
            font-weight: 700;
            outline: none;
            transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
        }

        select:focus,
        input:focus {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        select:disabled,
        input:disabled {
            cursor: not-allowed;
            opacity: 0.72;
        }

        .field-help {
            display: block;
            min-height: 18px;
            margin-top: 6px;
            color: var(--muted);
            font-size: 0.76rem;
            line-height: 1.35;
        }

        .course-context {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fbfdff;
        }

        .context-cell span {
            display: block;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 850;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .context-cell strong {
            display: block;
            color: var(--ink);
            font-size: 0.9rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .search-wrapper { position: relative; }

        .custom-suggestions {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            z-index: 2000;
            display: none;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.28);
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.14);
        }

        .suggestion-item {
            width: 100%;
            border: 0;
            border-bottom: 1px solid var(--line);
            background: #ffffff;
            color: var(--ink);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            text-align: left;
        }

        .suggestion-item:last-child { border-bottom: 0; }
        .suggestion-item:hover { background: #eff6ff; }
        .suggestion-main { min-width: 0; display: grid; gap: 3px; }
        .suggestion-code { font-family: 'Space Grotesk', sans-serif; color: var(--primary); font-weight: 850; }
        .suggestion-promo { color: var(--muted); font-size: 0.78rem; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .note-hero {
            min-height: 74px;
            border-color: #bae6fd;
            background: #ecfeff;
            color: var(--primary);
            text-align: center;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.7rem, 3vw, 2.25rem);
            font-weight: 850;
        }

        .update-hint {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 46px;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            background: #fff7ed;
            color: var(--amber);
            padding: 11px 12px;
            font-size: 0.84rem;
            font-weight: 800;
        }

        .update-hint.show { display: flex; }
        .update-hint.blocked { border-color: #fecaca; background: #fef2f2; color: var(--red); }
        .update-hint.ready { border-color: #bfdbfe; background: #eff6ff; color: var(--primary); }

        .btn-glow {
            width: 100%;
            min-height: 54px;
            border: 0;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary), var(--cyan));
            color: #ffffff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            font-size: 0.96rem;
            font-weight: 850;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.22);
            transition: transform 0.15s, opacity 0.15s;
        }

        .btn-glow:hover { transform: translateY(-1px); }
        .btn-glow:disabled {
            cursor: not-allowed;
            opacity: 0.58;
            transform: none;
            box-shadow: none;
        }

        .hist-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .history-toolbar {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }

        .history-toolbar select { min-height: 44px; padding: 10px 12px; font-size: 0.86rem; }

        .scroll-area {
            max-height: 620px;
            overflow-y: auto;
            padding-right: 4px;
            scrollbar-color: var(--line) transparent;
        }

        .item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel-soft);
            margin-bottom: 10px;
        }

        .item[hidden],
        .history-empty[hidden] {
            display: none;
        }

        .item-main { min-width: 0; }
        .item-title {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            margin-bottom: 5px;
        }

        .code-pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eef6ff;
            color: var(--primary);
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 850;
            font-size: 0.78rem;
        }

        .course-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--ink);
            font-size: 0.9rem;
            font-weight: 850;
        }

        .student-name,
        .meta-row {
            color: var(--muted);
            font-size: 0.76rem;
            font-weight: 700;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .period-badge {
            min-height: 24px;
            padding: 4px 8px;
            color: var(--cyan);
            background: #ecfeff;
            border-color: #bae6fd;
        }

        .score-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .score-block { min-width: 84px; text-align: right; }
        .score-val {
            display: block;
            color: var(--green);
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.05rem;
            font-weight: 850;
            line-height: 1;
        }

        .score-val.fail-score { color: var(--red); }
        .score-max { color: var(--muted); font-size: 0.7rem; font-weight: 800; }

        .history-readonly-badge {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d1fae5;
            border-radius: 8px;
            background: #f0fdf4;
            color: #16a34a;
            font-size: 1.1rem;
            pointer-events: none;
            user-select: none;
        }

        .history-empty {
            border: 1px dashed var(--line);
            border-radius: 8px;
            padding: 34px 18px;
            text-align: center;
            color: var(--muted);
            font-weight: 750;
        }

        .ultra-alert {
            margin-bottom: 16px;
            padding: 13px 14px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.86rem;
            border: 1px solid var(--line);
        }

        .ultra-alert.success {
            color: var(--green);
            background: #ecfdf5;
            border-color: #bbf7d0;
        }

        .ultra-alert.error {
            color: var(--red);
            background: #fef2f2;
            border-color: #fecaca;
        }

        @media (max-width: 980px) {
            .ultra-container { grid-template-columns: 1fr; }
            .history-card { order: 2; }
        }

        @media (max-width: 720px) {
            .quantum-nav { align-items: flex-start; }
            .nav-content { align-items: flex-start; }
            .status-strip { display: none; }
            .ultra-container {
                width: min(100% - 22px, 1180px);
                margin-top: 92px;
            }
            .quantum-card { padding: 17px; }
            .card-head { flex-direction: column; }
            .form-grid,
            .course-context { grid-template-columns: 1fr; }
            .item { grid-template-columns: 1fr; }
            .score-actions { justify-content: space-between; }
            .score-block { text-align: left; }
        }
    </style>
</head>
<body>

<nav class="quantum-nav">
    <div class="nav-content">
        <div class="nav-left">
            <a href="index.php" class="nav-back-circle" aria-label="Retour">
                <span class="material-symbols-rounded" aria-hidden="true">arrow_back</span>
            </a>
            <div class="nav-title">
                <span class="main-t">ITMCD</span>
                <span class="page-t">Encodage des cotes</span>
            </div>
        </div>
        <div class="status-strip" aria-label="Etat des autorisations">
            <span class="status-pill <?= $can_encode_s1 ? 'ok' : 'locked' ?>">
                <span class="material-symbols-rounded" aria-hidden="true"><?= $can_encode_s1 ? 'lock_open' : 'lock' ?></span>
                S1 <?= $can_encode_s1 ? 'ouvert' : 'ferme' ?>
            </span>
            <span class="status-pill <?= $can_encode_s2 ? 'ok' : 'locked' ?>">
                <span class="material-symbols-rounded" aria-hidden="true"><?= $can_encode_s2 ? 'lock_open' : 'lock' ?></span>
                S2 <?= $can_encode_s2 ? 'ouvert' : 'ferme' ?>
            </span>
            <span class="status-pill <?= $can_update_notes ? 'ok' : 'locked' ?>">
                <span class="material-symbols-rounded" aria-hidden="true"><?= $can_update_notes ? 'edit_square' : 'lock' ?></span>
                Corrections <?= $can_update_notes ? 'actives' : 'bloquees' ?>
            </span>
        </div>
    </div>
</nav>

<main class="ultra-container">
    <section class="quantum-card encoding-card">
        <div class="card-head">
            <div>
                <span class="eyebrow"><span class="material-symbols-rounded" aria-hidden="true">edit_note</span> Saisie enseignant</span>
                <h1>Encodage des cotes</h1>
            </div>
            <span class="mini-pill <?= $can_update_notes ? 'ok' : 'locked' ?>">
                <?= $can_update_notes ? 'Mise a jour active' : 'Mise a jour verrouillee' ?>
            </span>
        </div>

        <?= $message ?>

        <form method="POST" autocomplete="off" id="encoding_form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field-group full">
                    <label for="cours_select">Cours</label>
                    <select name="cours_id" id="cours_select" required>
                        <?php if (empty($liste_cours)): ?>
                            <option value="">Aucun cours attribue</option>
                        <?php else: ?>
                            <?php foreach($liste_cours as $c): ?>
                                <option
                                    value="<?= (int)$c['id'] ?>"
                                    data-max="<?= enc_e($c['coefficient']) ?>"
                                    data-semestre="<?= enc_e($c['semestre']) ?>"
                                    data-promo="<?= enc_e($c['promo']) ?>"
                                    data-nom="<?= enc_e($c['nom']) ?>"
                                    <?= $selected_cours_id === (int)$c['id'] ? 'selected' : '' ?>
                                >
                                    <?= enc_e($c['nom']) ?> - <?= enc_e($c['promo']) ?> - <?= enc_e(enc_course_mode_label($c['semestre'])) ?> /<?= itm_format_points($c['coefficient']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="course-context" aria-live="polite">
                    <div class="context-cell">
                        <span>Planification</span>
                        <strong id="context_semestre">-</strong>
                    </div>
                    <div class="context-cell">
                        <span>Promotion</span>
                        <strong id="context_promo">-</strong>
                    </div>
                    <div class="context-cell">
                        <span>Maxima</span>
                        <strong id="context_max">-</strong>
                    </div>
                    <div class="context-cell">
                        <span>Mode</span>
                        <strong id="context_mode">-</strong>
                    </div>
                </div>

                <div class="field-group">
                    <label for="periode_select">Semestre</label>
                    <select name="periode" id="periode_select" required>
                        <option value="S1" <?= $selected_period === 'S1' ? 'selected' : '' ?>>Semestre 1</option>
                        <option value="S2" <?= $selected_period === 'S2' ? 'selected' : '' ?>>Semestre 2</option>
                    </select>
                    <small class="field-help" id="period_help"></small>
                </div>

                <div class="field-group search-wrapper">
                    <label for="code_input">Code de l'apprenant</label>
                    <input type="text" name="code_unique" id="code_input" placeholder="Ex: PJ13WR" required>
                    <div id="suggestions_box" class="custom-suggestions"></div>
                </div>

                <div class="field-group full">
                    <label id="note_label" for="note_input">Cote obtenue</label>
                    <input type="number" name="note" id="note_input" step="0.1" min="0" class="note-hero" placeholder="0.0" required>
                </div>

                <div id="existing_note_hint" class="update-hint" aria-live="polite"></div>

                <div class="submit-row">
                    <button type="submit" name="save_examen" class="btn-glow" id="submit_btn">
                        <span class="material-symbols-rounded" aria-hidden="true">save</span>
                        <span id="submit_label">Enregistrer la cote</span>
                    </button>
                </div>
            </div>
        </form>
    </section>

    <section class="quantum-card history-card">
        <div class="hist-header">
            <div>
                <span class="eyebrow"><span class="material-symbols-rounded" aria-hidden="true">history</span> Suivi</span>
                <h2>Historique des cotes</h2>
            </div>
            <span class="mini-pill" id="visible_count"><?= (int)$history_count ?> entree(s)</span>
        </div>

        <div class="history-toolbar">
            <select id="history_filter" aria-label="Filtrer l'historique par cours">
                <option value="0">Tous les cours</option>
                <?php foreach($liste_cours as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $selected_cours_id === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= enc_e($c['nom']) ?> - <?= enc_e($c['promo']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="scroll-area" id="history_list">
            <?php foreach($historique as $h): ?>
                <?php
                    $period = enc_period_from_note($h['type_note'], $h['semestre']);
                    $is_fail = (float)$h['valeur'] < ((float)$h['coefficient'] / 2);
                    $student = enc_student_name($h);
                ?>
                <article
                    class="item"
                    data-course-id="<?= (int)$h['cours_id'] ?>"
                    data-period="<?= enc_e($period) ?>"
                    data-code="<?= enc_e(strtoupper($h['code_unique'])) ?>"
                    data-value="<?= enc_e($h['valeur']) ?>"
                    data-max="<?= enc_e($h['coefficient']) ?>"
                >
                    <div class="item-main">
                        <div class="item-title">
                            <span class="code-pill"><?= enc_e($h['code_unique']) ?></span>
                            <strong class="course-name"><?= enc_e($h['cours_nom']) ?></strong>
                        </div>
                        <div class="student-name"><?= enc_e($student !== '' ? $student : 'Apprenant') ?></div>
                        <div class="meta-row">
                            <span class="period-badge"><?= enc_e($period) ?></span>
                            <span><?= enc_e($h['promo_nom']) ?></span>
                            <span><?= date('d/m/Y H:i', strtotime($h['date_encodage'])) ?></span>
                        </div>
                    </div>
                    <div class="score-actions">
                        <div class="score-block">
                            <span class="score-val <?= $is_fail ? 'fail-score' : '' ?>"><?= itm_format_points($h['valeur']) ?></span>
                            <span class="score-max">/<?= itm_format_points($h['coefficient']) ?></span>
                        </div>
                        <span class="history-readonly-badge" aria-label="Cote enregistrée">
                            <span class="material-symbols-rounded" aria-hidden="true">check_circle</span>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
            <div class="history-empty" id="history_empty" hidden>Aucune cote pour cette selection.</div>
        </div>
    </section>
</main>

<script>
    const canEncodeS1 = <?= $can_encode_s1 ? 'true' : 'false' ?>;
    const canEncodeS2 = <?= $can_encode_s2 ? 'true' : 'false' ?>;
    const canUpdateNotes = <?= $can_update_notes ? 'true' : 'false' ?>;
    const initialPeriod = <?= json_encode($selected_period ?: '') ?>;

    const coursSelect = document.getElementById('cours_select');
    const codeInput = document.getElementById('code_input');
    const noteInput = document.getElementById('note_input');
    const noteLabel = document.getElementById('note_label');
    const periodeSelect = document.getElementById('periode_select');
    const periodHelp = document.getElementById('period_help');
    const sugBox = document.getElementById('suggestions_box');
    const submitBtn = document.getElementById('submit_btn');
    const submitLabel = document.getElementById('submit_label');
    const historyFilter = document.getElementById('history_filter');
    const historyItems = Array.from(document.querySelectorAll('.item'));
    const historyEmpty = document.getElementById('history_empty');
    const visibleCount = document.getElementById('visible_count');
    const updateHint = document.getElementById('existing_note_hint');
    const contextSemestre = document.getElementById('context_semestre');
    const contextPromo = document.getElementById('context_promo');
    const contextMax = document.getElementById('context_max');
    const contextMode = document.getElementById('context_mode');

    let typingTimer;

    function formatPoints(value) {
        const number = Number.parseFloat(value);
        if (!Number.isFinite(number)) return '-';
        return number.toLocaleString('fr-FR', { maximumFractionDigits: 2 });
    }

    function periodLabel(period) {
        return period === 'S2' ? 'Semestre 2' : 'Semestre 1';
    }

    function allowedPeriods(semester) {
        if (semester === 'S2') return ['S2'];
        if (semester === 'S1') return ['S1'];
        return ['S1', 'S2'];
    }

    function periodIsOpen(period) {
        return period === 'S2' ? canEncodeS2 : canEncodeS1;
    }

    function modeLabel(semester) {
        if (semester === 'ANNUEL') return 'Cours annuel';
        return semester === 'S2' ? 'Cours semestriel S2' : 'Cours semestriel S1';
    }

    function currentCourseOption() {
        return coursSelect.options[coursSelect.selectedIndex] || null;
    }

    function currentCourseId() {
        return coursSelect.value || '';
    }

    function findExistingEntry() {
        const code = codeInput.value.trim().toUpperCase();
        const courseId = currentCourseId();
        const period = periodeSelect.value;
        if (!code || !courseId || !period) return null;

        return historyItems.find((item) => (
            item.dataset.courseId === courseId
            && item.dataset.period === period
            && item.dataset.code.toUpperCase() === code
        )) || null;
    }

    function syncCourseState(preferredPeriod = '') {
        const selected = currentCourseOption();
        const courseId = currentCourseId();
        const max = selected ? selected.dataset.max : '';
        const semester = selected ? selected.dataset.semestre : '';
        const promo = selected ? selected.dataset.promo : '';
        const courseName = selected ? selected.dataset.nom : '';
        const periods = allowedPeriods(semester);
        const openPeriods = periods.filter(periodIsOpen);
        const wantedPeriod = preferredPeriod || periodeSelect.value || initialPeriod;

        periodeSelect.innerHTML = '';
        if (openPeriods.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Encodage verrouille';
            periodeSelect.appendChild(option);
            periodeSelect.disabled = true;
        } else {
            openPeriods.forEach((period) => {
                const option = document.createElement('option');
                option.value = period;
                option.textContent = periodLabel(period);
                option.selected = period === wantedPeriod || (!openPeriods.includes(wantedPeriod) && period === openPeriods[0]);
                periodeSelect.appendChild(option);
            });
            periodeSelect.disabled = false;
        }

        contextSemestre.textContent = semester || '-';
        contextPromo.textContent = promo || '-';
        contextMax.textContent = max ? '/' + formatPoints(max) : '-';
        contextMode.textContent = semester ? modeLabel(semester) : '-';

        if (semester === 'ANNUEL') {
            periodHelp.textContent = 'Cours annuel: choisissez le semestre a encoder.';
        } else if (semester) {
            periodHelp.textContent = 'Planification detectee automatiquement: ' + periodLabel(semester) + '.';
        } else {
            periodHelp.textContent = '';
        }

        if (max) {
            noteInput.max = max;
            noteLabel.textContent = 'Cote obtenue / ' + formatPoints(max);
            noteInput.placeholder = '0 - ' + formatPoints(max);
        } else {
            noteInput.removeAttribute('max');
            noteLabel.textContent = 'Cote obtenue';
            noteInput.placeholder = '0.0';
        }

        if (courseId && historyFilter) {
            historyFilter.value = courseId;
        }

        filterHistory();
        updateExistingHint();
        updateSubmitState();

        if (courseName) {
            document.title = 'ITMCD | ' + courseName;
        }
    }

    function updateExistingHint() {
        const existing = findExistingEntry();
        updateHint.className = 'update-hint';
        updateHint.textContent = '';

        if (!existing) {
            return;
        }

        if (canUpdateNotes) {
            updateHint.classList.add('show', 'ready');
            updateHint.textContent = 'Cote existante: ' + formatPoints(existing.dataset.value) + '/' + formatPoints(existing.dataset.max) + '. La nouvelle cote remplacera cette valeur.';
        } else {
            updateHint.classList.add('show', 'blocked');
            updateHint.textContent = 'Cote deja enregistree. La correction est verrouillee par ladmin.';
        }
    }

    function updateSubmitState() {
        const hasCourse = !!currentCourseId();
        const hasPeriod = !!periodeSelect.value && !periodeSelect.disabled;
        const existing = findExistingEntry();
        const blockedByExisting = !!existing && !canUpdateNotes;
        const blocked = !hasCourse || !hasPeriod || blockedByExisting;

        submitBtn.disabled = blocked;

        if (!hasCourse) {
            submitLabel.textContent = 'Selectionner un cours';
        } else if (!hasPeriod) {
            submitLabel.textContent = 'Encodage verrouille';
        } else if (blockedByExisting) {
            submitLabel.textContent = 'Cote deja enregistree';
        } else if (existing && canUpdateNotes) {
            submitLabel.textContent = 'Mettre a jour la cote';
        } else {
            submitLabel.textContent = 'Enregistrer la cote';
        }
    }

    function filterHistory() {
        const filterCourse = historyFilter ? historyFilter.value : '0';
        const activeCourse = filterCourse !== '0' ? filterCourse : '';
        const activePeriod = activeCourse && activeCourse === currentCourseId() ? periodeSelect.value : '';
        let count = 0;

        historyItems.forEach((item) => {
            const matchesCourse = !activeCourse || item.dataset.courseId === activeCourse;
            const matchesPeriod = !activePeriod || item.dataset.period === activePeriod;
            const visible = matchesCourse && matchesPeriod;
            item.hidden = !visible;
            if (visible) count++;
        });

        historyEmpty.hidden = count !== 0;
        visibleCount.textContent = count + ' entree(s)';
    }

    document.addEventListener('click', (event) => {
        if (!sugBox.contains(event.target) && event.target !== codeInput) {
            sugBox.style.display = 'none';
        }
    });

    codeInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        clearTimeout(typingTimer);
        updateExistingHint();
        updateSubmitState();

        const val = this.value.trim();
        const coursId = currentCourseId();

        if (val.length === 0 || coursId === '') {
            sugBox.style.display = 'none';
            return;
        }

        typingTimer = setTimeout(() => {
            fetch(`?ajax_search=1&term=${encodeURIComponent(val)}&cours_id=${encodeURIComponent(coursId)}&t=${Date.now()}`)
                .then((response) => response.ok ? response.json() : [])
                .then((data) => {
                    sugBox.innerHTML = '';
                    if (!Array.isArray(data) || data.length === 0) {
                        sugBox.style.display = 'none';
                        return;
                    }

                    data.forEach((student) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'suggestion-item';
                        const main = document.createElement('span');
                        main.className = 'suggestion-main';

                        const code = document.createElement('span');
                        code.className = 'suggestion-code';
                        code.textContent = student.code || '';

                        const promo = document.createElement('span');
                        promo.className = 'suggestion-promo';
                        promo.textContent = student.promotion || 'Promotion non definie';

                        const icon = document.createElement('span');
                        icon.className = 'material-symbols-rounded';
                        icon.setAttribute('aria-hidden', 'true');
                        icon.textContent = 'north_west';

                        main.appendChild(code);
                        main.appendChild(promo);
                        button.appendChild(main);
                        button.appendChild(icon);
                        button.addEventListener('click', () => {
                            codeInput.value = student.code;
                            sugBox.style.display = 'none';
                            updateExistingHint();
                            updateSubmitState();
                            noteInput.focus();
                        });
                        sugBox.appendChild(button);
                    });

                    sugBox.style.display = 'block';
                })
                .catch(() => {
                    sugBox.style.display = 'none';
                });
        }, 160);
    });

    coursSelect.addEventListener('change', () => {
        codeInput.value = '';
        noteInput.value = '';
        sugBox.style.display = 'none';
        syncCourseState();
        if (currentCourseId()) {
            codeInput.focus();
        }
    });

    periodeSelect.addEventListener('change', () => {
        filterHistory();
        updateExistingHint();
        updateSubmitState();
    });

    if (historyFilter) {
        historyFilter.addEventListener('change', filterHistory);
    }

    // Note: Les enseignants ne peuvent pas modifier ni supprimer les cotes existantes.
    // L'historique est affiché en lecture seule avec un badge de confirmation.

    syncCourseState(initialPeriod);

    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => syncCourseState(periodeSelect.value || initialPeriod), 120);
    });
</script>

</body>
</html>
