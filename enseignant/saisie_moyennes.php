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
$can_encode_moyennes = itm_setting($pdo, 'feature_encode_moyennes', true);

if (!$can_encode_s1 && !$can_encode_s2) {
    http_response_code(403);
    die("L'encodage est desactive par ladmin.");
}

if (!$can_encode_moyennes) {
    http_response_code(403);
    die("L'encodage des moyennes de secours est desactive par ladmin.");
}

function moy_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moy_alert($kind, $message) {
    return '<div class="moy-alert ' . moy_e($kind) . '">' . moy_e($message) . '</div>';
}

function moy_student_name($row) {
    return trim(($row->nom ?? '') . ' ' . ($row->post_nom ?? '') . ' ' . ($row->prenom ?? ''));
}

function moy_course_label($semester) {
    if ($semester === 'ANNUEL') {
        return 'Cours annuel';
    }

    return $semester === 'S2' ? 'Semestre 2' : 'Semestre 1';
}

$message = '';
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$selected_cours_id = isset($_GET['cours_id']) ? (int)$_GET['cours_id'] : (int)($_POST['cours_id'] ?? 0);

$stmt_cours = $pdo->prepare("
    SELECT c.id, c.nom, c.coefficient, c.semestre, c.promotion_id, p.nom AS promo
    FROM cours c
    JOIN promotions p ON p.id = c.promotion_id
    WHERE c.enseignant_id = ?
    ORDER BY p.nom ASC, FIELD(c.semestre, 'S1', 'S2', 'ANNUEL'), c.nom ASC
");
$stmt_cours->execute([$enseignant_id]);
$liste_cours = $stmt_cours->fetchAll(PDO::FETCH_OBJ);

$available_course_ids = array_map(function ($course) {
    return (int)$course->id;
}, $liste_cours);

if ($selected_cours_id > 0 && !in_array($selected_cours_id, $available_course_ids, true)) {
    $selected_cours_id = 0;
}

if ($selected_cours_id <= 0 && !empty($liste_cours)) {
    $selected_cours_id = (int)$liste_cours[0]->id;
}

$selected_course = null;
foreach ($liste_cours as $course) {
    if ((int)$course->id === $selected_cours_id) {
        $selected_course = $course;
        break;
    }
}

if ($request_method === 'POST' && isset($_POST['save_moyennes'])) {
    require_csrf();

    if (!$selected_course) {
        $message = moy_alert('error', 'Veuillez selectionner un cours valide.');
    } else {
        $moyennes = $_POST['moyennes'] ?? [];
        $updated = 0;
        $affectedStudents = [];

        try {
            $pdo->beginTransaction();
            foreach ($moyennes as $apprenantId => $rawValue) {
                $rawValue = trim((string)$rawValue);
                if ($rawValue === '') {
                    continue;
                }

                $sync = itm_upsert_moyenne($pdo, (int)$apprenantId, $selected_cours_id, $enseignant_id, $rawValue);
                if ($sync !== null) {
                    $updated++;
                    $affectedStudents[(int)$apprenantId] = (int)$apprenantId;
                }
            }

            foreach ($affectedStudents as $studentId) {
                itm_refresh_student_decisions($pdo, $studentId, $selected_course->promotion_id);
            }

            if ($updated > 0) {
                itm_insert_notification(
                    $pdo,
                    "Pr. $enseignant_nom a actualise $updated moyenne(s) de secours pour le cours : " . $selected_course->nom . ".",
                    'admin',
                    null,
                    'moyenne'
                );
            }

            $pdo->commit();
            $message = $updated > 0
                ? moy_alert('success', $updated . ' moyenne(s) enregistree(s) et decisions recalculées.')
                : moy_alert('warning', 'Aucune valeur n’a ete modifiee. Renseignez au moins une moyenne sur 10.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            itm_log_error('Teacher moyenne save failed: ' . $e->getMessage());
            $message = moy_alert('error', $e->getMessage());
        }
    }
}

$apprenants = [];
if ($selected_course) {
    $stmt_apps = $pdo->prepare("
        SELECT a.id, a.code_unique, a.nom, a.post_nom, a.prenom,
               MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) AS cote,
               MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) AS cote_s1,
               MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) AS cote_s2,
               MAX(CASE WHEN n.type_note = 'examen' THEN n.valeur END) AS examen,
               MAX(CASE WHEN n.type_note = 'moyenne' THEN n.valeur END) AS moyenne
        FROM apprenants a
        LEFT JOIN notes n ON n.apprenant_id = a.id AND n.cours_id = ?
        WHERE a.promotion_id = ?
        GROUP BY a.id, a.code_unique, a.nom, a.post_nom, a.prenom
        ORDER BY a.nom ASC, a.post_nom ASC, a.prenom ASC
    ");
    $stmt_apps->execute([$selected_course->id, $selected_course->promotion_id]);
    $apprenants = $stmt_apps->fetchAll(PDO::FETCH_OBJ);

    foreach ($apprenants as $row) {
        $period = $selected_course->semestre === 'S2' ? 'S2' : 'S1';
        $max = itm_effective_maxima($selected_course->semestre, $selected_course->coefficient, 'FINAL');
        $direct = itm_select_period_score($row, $selected_course->semestre, 'FINAL');
        $base = itm_obtained_points($direct, null, $row->examen ?? null, $max);
        $adjusted = itm_points_for_period($row, $selected_course->semestre, $selected_course->coefficient, 'FINAL');
        $row->_period = $period;
        $row->_max = $max;
        $row->_base = $base;
        $row->_adjusted = $adjusted;
        $row->_bonus = max(0.0, $adjusted - $base);
        $row->_has_grade = itm_has_grade_for_period($row, $selected_course->semestre, 'FINAL');
        $row->_pct = $max > 0 ? round(($base / $max) * 100, 1) : 0;
    }
}

$stmt_hist = $pdo->prepare("
    SELECT n.valeur, n.date_encodage, a.code_unique, a.nom, a.post_nom, a.prenom,
           c.nom AS cours_nom, p.nom AS promo_nom
    FROM notes n
    JOIN apprenants a ON a.id = n.apprenant_id
    JOIN cours c ON c.id = n.cours_id
    JOIN promotions p ON p.id = c.promotion_id
    WHERE c.enseignant_id = ?
      AND n.type_note = 'moyenne'
    ORDER BY n.date_encodage DESC, n.id DESC
    LIMIT 18
");
$stmt_hist->execute([$enseignant_id]);
$historique = $stmt_hist->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>ITMCD | Moyennes de secours</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Space+Grotesk:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet">
    <style>
        :root {
            --bg: #eef3f8;
            --panel: #ffffff;
            --panel-soft: #f8fbff;
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
            background:
                radial-gradient(circle at top left, rgba(37,99,235,.12), transparent 30rem),
                linear-gradient(180deg, #fbfdff 0%, var(--bg) 58%, #e8f0f7 100%);
            color: var(--ink);
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(37,99,235,.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37,99,235,.045) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.55), transparent 75%);
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            min-height: 74px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--line);
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        .topbar-inner {
            width: min(1180px, 100%);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .brandline { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .icon-btn {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        .brand-title { min-width: 0; }
        .brand-title strong {
            display: block;
            font-family: 'Space Grotesk', sans-serif;
            color: var(--primary);
            font-size: 1.05rem;
            line-height: 1;
        }
        .brand-title span {
            display: block;
            color: var(--muted);
            font-size: .76rem;
            font-weight: 800;
            margin-top: 4px;
        }
        .shell {
            position: relative;
            width: min(1180px, calc(100% - 28px));
            margin: 24px auto 54px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 330px;
            gap: 18px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .card-pad { padding: 20px; }
        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: end;
            margin-bottom: 16px;
        }
        h1, h2 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            color: var(--ink);
            letter-spacing: 0;
        }
        h1 { font-size: clamp(1.45rem, 2.4vw, 2rem); line-height: 1.08; }
        h2 { font-size: 1rem; }
        .subtext { color: var(--muted); font-weight: 650; line-height: 1.5; margin: 8px 0 0; }
        .rule-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0;
        }
        .rule {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            background: var(--panel-soft);
        }
        .rule span { display: block; color: var(--muted); font-size: .68rem; font-weight: 900; text-transform: uppercase; }
        .rule strong { display: block; margin-top: 5px; font-size: .9rem; }
        .course-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: end;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fbfdff;
        }
        label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
        }
        select, input {
            width: 100%;
            min-height: 46px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--ink);
            padding: 12px 13px;
            font-size: .95rem;
            font-weight: 750;
            outline: none;
        }
        select:focus, input:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
        }
        .btn {
            min-height: 46px;
            border: 0;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary), var(--cyan));
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 16px;
            text-decoration: none;
            font-weight: 900;
            box-shadow: 0 14px 28px rgba(37,99,235,.18);
            white-space: nowrap;
        }
        .moy-alert {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: #eff6ff;
            color: var(--primary);
            font-weight: 800;
        }
        .moy-alert.success { background: #ecfdf5; border-color: #bbf7d0; color: var(--green); }
        .moy-alert.warning { background: #fff7ed; border-color: #fed7aa; color: var(--amber); }
        .moy-alert.error { background: #fef2f2; border-color: #fecaca; color: var(--red); }
        .table-wrap {
            margin-top: 16px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        table {
            width: 100%;
            min-width: 820px;
            border-collapse: collapse;
            background: #fff;
        }
        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: middle;
            font-size: .86rem;
        }
        th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #475569;
            font-size: .7rem;
            font-weight: 900;
            text-transform: uppercase;
        }
        tr:last-child td { border-bottom: 0; }
        .student-name { font-weight: 900; color: var(--ink); }
        .student-code { color: var(--primary); font-weight: 900; font-size: .78rem; }
        .metric-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 5px 8px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--panel-soft);
            color: var(--muted);
            font-size: .74rem;
            font-weight: 900;
        }
        .metric-pill.ok { color: var(--green); background: #ecfdf5; border-color: #bbf7d0; }
        .metric-pill.warn { color: var(--amber); background: #fff7ed; border-color: #fed7aa; }
        .metric-pill.none { color: var(--red); background: #fef2f2; border-color: #fecaca; }
        .avg-input { max-width: 112px; }
        .submitbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .submitbar small { color: var(--muted); font-weight: 750; }
        .side-stack { display: grid; gap: 14px; align-self: start; }
        .course-card-title { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .history-list { display: grid; gap: 9px; max-height: 650px; overflow: auto; padding-right: 4px; }
        .history-item {
            display: grid;
            gap: 4px;
            padding: 11px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel-soft);
        }
        .history-item strong { font-size: .86rem; }
        .history-item span { color: var(--muted); font-size: .74rem; font-weight: 750; line-height: 1.35; }
        .empty {
            padding: 20px;
            border: 1px dashed var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--muted);
            text-align: center;
            font-weight: 800;
        }
        @media (max-width: 980px) {
            .shell { grid-template-columns: 1fr; }
            .rule-strip { grid-template-columns: 1fr; }
            .hero, .course-form { grid-template-columns: 1fr; }
            .btn { width: 100%; }
        }
        @media (max-width: 620px) {
            .shell { width: min(100% - 18px, 1180px); margin-top: 14px; }
            .card-pad { padding: 14px; }
            .topbar-inner { align-items: flex-start; }
            .brand-title strong { font-size: .96rem; }
            .brand-title span { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        }
    </style>
</head>
<body>
<nav class="topbar">
    <div class="topbar-inner">
        <div class="brandline">
            <a href="index.php" class="icon-btn" aria-label="Retour"><span class="material-symbols-rounded">arrow_back</span></a>
            <div class="brand-title">
                <strong>Moyennes de secours</strong>
                <span>Encodage enseignant sur 10</span>
            </div>
        </div>
        <a class="icon-btn" href="encodage_examen.php" aria-label="Encodage des cotes"><span class="material-symbols-rounded">edit_note</span></a>
    </div>
</nav>

<main class="shell">
    <section class="card card-pad">
        <div class="hero">
            <div>
                <h1>Encoder la moyenne qui soutient l'examen</h1>
                <p class="subtext">Elle reste invisible dans la grille et le bulletin, puis s'ajoute uniquement dans le calcul de la cote obtenue.</p>
            </div>
        </div>

        <?= $message ?>

        <form class="course-form" method="GET">
            <div>
                <label for="cours_id">Cours</label>
                <select id="cours_id" name="cours_id" onchange="this.form.submit()">
                    <?php foreach ($liste_cours as $course): ?>
                        <option value="<?= (int)$course->id ?>" <?= (int)$course->id === $selected_cours_id ? 'selected' : '' ?>>
                            <?= moy_e($course->promo . ' - ' . $course->nom . ' /' . itm_format_points($course->coefficient)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit"><span class="material-symbols-rounded">search</span> Charger</button>
        </form>

        <?php if ($selected_course): ?>
        <div class="rule-strip">
            <div class="rule"><span>Base</span><strong><?= moy_e(moy_course_label($selected_course->semestre)) ?> /<?= itm_format_points($selected_course->coefficient) ?></strong></div>
            <div class="rule"><span>Conversion</span><strong>Moyenne /10 -> bonus max 25%</strong></div>
            <div class="rule"><span>Condition</span><strong>Ajout si la cote est <= 75%</strong></div>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="cours_id" value="<?= (int)$selected_course->id ?>">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Apprenant</th>
                            <th>Cote actuelle</th>
                            <th>Statut</th>
                            <th>Bonus</th>
                            <th>Moyenne /10</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($apprenants)): ?>
                        <tr><td colspan="5" class="empty">Aucun apprenant dans cette promotion.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($apprenants as $row): ?>
                        <?php
                            $gradeClass = !$row->_has_grade ? 'none' : ($row->_pct <= 75 ? 'warn' : 'ok');
                            $gradeLabel = !$row->_has_grade ? 'Non cote' : itm_format_points($row->_base) . '/' . itm_format_points($row->_max);
                            $statusText = !$row->_has_grade ? 'En attente de cote' : ($row->_pct <= 75 ? 'Eligible au secours' : 'Pas de secours');
                            $bonusText = $row->_bonus > 0 ? '+' . itm_format_points($row->_bonus) : '-';
                        ?>
                        <tr>
                            <td>
                                <div class="student-name"><?= moy_e(moy_student_name($row)) ?></div>
                                <div class="student-code"><?= moy_e($row->code_unique) ?></div>
                            </td>
                            <td><span class="metric-pill <?= $gradeClass ?>"><?= $gradeLabel ?></span></td>
                            <td><span class="metric-pill <?= $gradeClass ?>"><?= moy_e($statusText) ?></span></td>
                            <td><span class="metric-pill <?= $row->_bonus > 0 ? 'ok' : '' ?>"><?= $bonusText ?></span></td>
                            <td>
                                <input class="avg-input" type="number" name="moyennes[<?= (int)$row->id ?>]" min="0" max="10" step="0.01" value="<?= $row->moyenne !== null ? moy_e(itm_format_points($row->moyenne)) : '' ?>" placeholder="0-10" inputmode="decimal">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="submitbar">
                <small>Les champs vides sont ignores. Une moyenne deja enregistree peut etre remplacee par une nouvelle valeur.</small>
                <button class="btn" type="submit" name="save_moyennes" value="1"><span class="material-symbols-rounded">save</span> Enregistrer</button>
            </div>
        </form>
        <?php else: ?>
            <div class="empty">Aucun cours ne vous est encore attribue.</div>
        <?php endif; ?>
    </section>

    <aside class="side-stack">
        <section class="card card-pad">
            <div class="course-card-title">
                <span class="icon-btn" aria-hidden="true"><span class="material-symbols-rounded">info</span></span>
                <h2>Regle appliquee</h2>
            </div>
            <p class="subtext">Sur un maxima de 20, une moyenne de 8/10 ajoute au plus 4 points. Si la cote de depart depasse 75%, le bonus reste a 0.</p>
        </section>
        <section class="card card-pad">
            <div class="course-card-title">
                <span class="icon-btn" aria-hidden="true"><span class="material-symbols-rounded">history</span></span>
                <h2>Dernieres moyennes</h2>
            </div>
            <div class="history-list">
                <?php if (empty($historique)): ?>
                    <div class="empty">Aucun encodage de moyenne pour le moment.</div>
                <?php endif; ?>
                <?php foreach ($historique as $item): ?>
                    <div class="history-item">
                        <strong><?= moy_e($item->code_unique) ?> - <?= moy_e(moy_student_name($item)) ?></strong>
                        <span><?= moy_e($item->cours_nom) ?>, <?= moy_e($item->promo_nom) ?></span>
                        <span><?= itm_format_points($item->valeur) ?>/10 - <?= moy_e(date('d/m/Y H:i', strtotime($item->date_encodage))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </aside>
</main>
</body>
</html>
