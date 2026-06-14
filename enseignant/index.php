<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'enseignant') {
    header("Location: ../login.php"); exit();
}

$enseignant = $_SESSION['user'];
$heure = date('H');
$salutation = ($heure < 18) ? "Bonjour" : "Bonsoir";
$can_encode = itm_setting($pdo, 'feature_encode_s1', true) || itm_setting($pdo, 'feature_encode_s2', true);
$can_encode_moyennes = itm_setting($pdo, 'feature_encode_moyennes', true);
$can_download = itm_setting($pdo, 'feature_download_notes', true);

// Données
$stmt_annee = $pdo->query("SELECT libelle FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
$annee_active = $stmt_annee->fetchColumn() ?: "2025-2026";

$stmt = $pdo->prepare("
    SELECT c.nom AS cours, p.nom AS promotion, p.id AS promotion_id,
           c.id, c.coefficient, c.semestre,
           COUNT(DISTINCT a.id) AS apprenants,
           COUNT(DISTINCT ng.apprenant_id) AS cotes_saisies,
           AVG(ng.valeur) AS moyenne_cote
    FROM cours c
    JOIN promotions p ON p.id = c.promotion_id
    LEFT JOIN apprenants a ON a.promotion_id = c.promotion_id
    LEFT JOIN (
        SELECT cours_id, apprenant_id, MAX(valeur) AS valeur
        FROM notes
        WHERE type_note IN ('cote', 'cote_s1', 'cote_s2')
        GROUP BY cours_id, apprenant_id
    ) ng ON ng.cours_id = c.id
    WHERE c.enseignant_id = ?
    GROUP BY c.id, c.nom, c.coefficient, c.semestre, p.id, p.nom
    ORDER BY p.nom, FIELD(c.semestre, 'S1', 'S2', 'ANNUEL'), c.nom
");
$stmt->execute([$enseignant->id]);
$attributions = $stmt->fetchAll(PDO::FETCH_OBJ);

// Stats reelles: resultat agrege par apprenant, avec moyenne de secours incluse.
$stats_score_sql = "
    CASE
        WHEN ng.cote IS NOT NULL THEN LEAST(
            LEAST(ng.cote, c.coefficient) +
            CASE
                WHEN ng.moyenne IS NOT NULL AND LEAST(ng.cote, c.coefficient) <= (c.coefficient * 0.75)
                THEN (LEAST(GREATEST(ng.moyenne, 0), 10) / 10) * (c.coefficient * 0.25)
                ELSE 0
            END,
            c.coefficient
        )
        WHEN ng.examen IS NOT NULL THEN LEAST(
            LEAST((COALESCE(ng.examen, 0) / 10) * c.coefficient, c.coefficient) +
            CASE
                WHEN ng.moyenne IS NOT NULL AND LEAST((COALESCE(ng.examen, 0) / 10) * c.coefficient, c.coefficient) <= (c.coefficient * 0.75)
                THEN (LEAST(GREATEST(ng.moyenne, 0), 10) / 10) * (c.coefficient * 0.25)
                ELSE 0
            END,
            c.coefficient
        )
        ELSE 0
    END
";
$stmt_total = $pdo->prepare("
    SELECT COUNT(DISTINCT a.id)
    FROM apprenants a
    INNER JOIN promotions p ON a.promotion_id = p.id
    INNER JOIN cours c ON c.promotion_id = p.id
    WHERE c.enseignant_id = ?
");
$stmt_total->execute([$enseignant->id]);
$success_total = (int)$stmt_total->fetchColumn();

$stmt_reussites = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT a.id
        FROM apprenants a
        JOIN cours c ON c.promotion_id = a.promotion_id AND c.enseignant_id = ?
        LEFT JOIN (
            SELECT apprenant_id, cours_id,
                   MAX(CASE WHEN type_note IN ('cote', 'cote_s1', 'cote_s2') THEN valeur END) AS cote,
                   MAX(CASE WHEN type_note = 'examen' THEN valeur END) AS examen,
                   MAX(CASE WHEN type_note = 'moyenne' THEN valeur END) AS moyenne
            FROM notes
            GROUP BY apprenant_id, cours_id
        ) ng ON ng.apprenant_id = a.id AND ng.cours_id = c.id
        GROUP BY a.id
        HAVING (SUM($stats_score_sql) / NULLIF(SUM(c.coefficient), 0)) * 100 >= 60
    ) AS table_reussite
");
$stmt_reussites->execute([$enseignant->id]);
$success_count = (int)$stmt_reussites->fetchColumn();
$s = (object)['ok' => $success_count, 'total' => $success_total];
$taux = ($success_total > 0) ? round(($success_count / $success_total) * 100) : 0;
$total_cours = count($attributions);
$total_apprenants_suivis = 0;
$total_cotes_saisies = 0;
foreach ($attributions as $item) {
    $total_apprenants_suivis += (int)$item->apprenants;
    $total_cotes_saisies += (int)$item->cotes_saisies;
}
$progression_globale = $total_apprenants_suivis > 0 ? round(($total_cotes_saisies / $total_apprenants_suivis) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>ITMCD</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1a73e8">
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet">
    <style>
        :root {
            --g-blue: #1a73e8;
            --g-blue-soft: #e8f0fe;
            --surface: #ffffff;
            --surface-soft: #f8fbff;
            --bg-canvas: #eef3f8;
            --accent: #4285f4;
            --success: #16a34a;
            --danger: #ff3b30;
            --line: #dbe5f0;
            --muted: #64748b;
            --strong: #172033;
            --shadow-soft: 0 16px 38px rgba(15,23,42,.07);
        }

        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        body { 
            background: var(--bg-canvas);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0; padding: 0; min-height: 100vh;
            color: #1d1d1f;
            overflow-x: hidden;
        }

        .orb { display: none; }

        .glass-nav { 
            position: sticky; top: 0; padding: 12px 20px; 
            background: rgba(255, 255, 255, 0.92); 
            backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); 
            display: flex; justify-content: space-between; align-items: center; 
            z-index: 1000; border-bottom: 1px solid var(--line); 
        }

        .glass-nav .itm-theme-slot { display: none !important; }

        .header-menu-btn {
            background: #f8fafc; border: 1px solid var(--line);
            width: 42px; height: 42px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; cursor: pointer;
        }
        .teacher-nav-meta { display:flex; align-items:center; gap:10px; min-width:0; }
        .teacher-nav-user { text-align:right; min-width:0; }
        .teacher-nav-user strong { display:block; color:var(--strong); font-size:12px; line-height:1.1; max-width:190px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .teacher-nav-user span { display:block; color:var(--accent); font-size:10px; font-weight:800; margin-top:3px; }
        .teacher-logo-tile { width:38px; height:38px; flex:0 0 38px; background:var(--surface); border-radius:8px; padding:4px; border:1px solid var(--line); box-shadow:0 8px 20px rgba(15,23,42,.07); }
        .teacher-logo-tile img { width:100%; height:100%; object-fit:contain; display:block; }

        .side-drawer {
            position: fixed; top: 0; left: 0;
            width: min(326px, calc(100vw - 28px)); height: 100dvh;
            background: #ffffff;
            z-index: 3000; 
            transform: translateX(-104%);
            transition: transform .28s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; flex-direction: column;
            border-right: 1px solid var(--line);
            box-shadow: 20px 0 55px rgba(15,23,42,.12);
        }
        .side-drawer.open { transform: translateX(0); }

        .drawer-header { padding: 18px; background: linear-gradient(180deg, #ffffff, #f8fbff); border-bottom:1px solid var(--line); display:grid; grid-template-columns:minmax(0,1fr) auto; gap:12px; align-items:start; }
        .drawer-brand { display:flex; gap:12px; align-items:center; min-width:0; }
        .drawer-logo { width:44px; height:44px; flex:0 0 44px; border-radius:10px; border:1px solid var(--line); background:#fff; padding:5px; box-shadow:0 10px 24px rgba(15,23,42,.07); }
        .drawer-logo img { width:100%; height:100%; object-fit:contain; display:block; }
        .drawer-title { font-weight: 900; font-size: 16px; letter-spacing: 0; color:var(--strong); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .drawer-subtitle { font-size: 11px; color: var(--muted); font-weight: 800; margin-top: 4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .drawer-close { width: 38px; height: 38px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); color: var(--strong); display:flex; align-items:center; justify-content:center; cursor:pointer; }
        .drawer-content { padding: 12px; flex-grow: 1; overflow-y:auto; }
        .drawer-section-title { font-size: 10px; font-weight: 900; color: #8090a4; text-transform: uppercase; margin: 16px 8px 8px; letter-spacing: .04em; }

        .drawer-item { 
            display: flex; align-items: center; gap: 14px; 
            min-height: 46px;
            padding: 11px 12px; border-radius: 8px;
            text-decoration: none; color: #1d1d1f; 
            font-weight: 780; font-size: 14px;
            transition: background .18s ease, color .18s ease, border-color .18s ease;
            border: 1px solid transparent;
            position:relative;
        }
        .drawer-item:hover { background: var(--g-blue-soft); color: var(--g-blue); border-color: color-mix(in srgb, var(--g-blue) 18%, transparent); }
        .drawer-item.active { background: #f0f6ff; color: var(--g-blue); border-color: color-mix(in srgb, var(--g-blue) 24%, transparent); box-shadow: inset 3px 0 0 var(--g-blue); }
        .drawer-item .material-symbols-rounded { width:24px; height:24px; flex:0 0 24px; display:inline-flex; align-items:center; justify-content:center; }
        .drawer-item i { font-style: normal; font-size: 20px; }
        .material-symbols-rounded { font-size: 20px; line-height: 1; }
        .drawer-footer { border-top:1px solid var(--line); padding:12px; background:#fbfdff; }
        .drawer-logout { color: var(--danger); background:#fff; border-color:#f1d2d0; }
        .drawer-logout:hover { background:#fff5f5; color:#d92d20; border-color:#ffc9c4; }

        .drawer-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(15,23,42,0.36); backdrop-filter: blur(4px);
            z-index: 2900; display: none;
        }
        .drawer-overlay.show { display: block; }

        .container { padding: 18px 20px 140px; max-width: 1040px; margin: 0 auto; }
        .welcome-hero { margin-bottom: 18px; }
        .welcome-hero h1 { font-size: clamp(24px, 4vw, 34px); line-height:1.12; font-weight: 850; letter-spacing: 0; color:#172033; margin: 8px 0 10px; }
        .welcome-hero p { color: var(--muted); margin: 0; font-weight: 650; line-height: 1.45; }

        .bento-container { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; align-items: stretch; }
        .bento-card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px; cursor: pointer; box-shadow:var(--shadow-soft); transition:.18s ease; min-width:0; text-decoration:none; color:inherit; }
        .bento-card:hover { transform: translateY(-2px); border-color: color-mix(in srgb, var(--g-blue) 36%, var(--line)); }
        .stat-card { grid-column: span 6; background: linear-gradient(135deg, #1a73e8, #0f8aa8); color: white; display:flex; flex-direction:column; justify-content:space-between; min-height:178px; position:relative; overflow:hidden; }
        .stat-card::after { content:""; position:absolute; right:-42px; bottom:-42px; width:140px; height:140px; border:1px solid rgba(255,255,255,.28); border-radius:50%; pointer-events:none; }
        .stat-grid { grid-column: span 6; display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .mini-stat { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 18px; box-shadow:0 10px 28px rgba(15,23,42,.05); min-width:0; position:relative; overflow:hidden; }
        .mini-stat::before { content:""; position:absolute; inset:0 0 auto; height:3px; background:linear-gradient(90deg,var(--g-blue),#0f8aa8); opacity:.85; }
        .mini-stat span { display:block; color:var(--muted); font-size:11px; font-weight:850; text-transform:uppercase; }
        .mini-stat strong { display:block; color:#172033; font-size: clamp(22px, 5vw, 34px); line-height:1; margin-top:8px; }
        .mini-stat small { display:block; color:var(--muted); font-size:11px; font-weight:700; margin-top:8px; line-height:1.3; }
        .mini-stat.success::before { background:linear-gradient(90deg,var(--success),#0f8aa8); }
        .mini-stat.warning::before { background:linear-gradient(90deg,#f59e0b,#1a73e8); }
        .action-card { grid-column: span 4; min-height:128px; display:flex; flex-direction:column; justify-content:space-between; gap:14px; }
        .action-card small { color:var(--muted); font-weight:650; line-height:1.35; }
        .action-title { display:flex; align-items:center; gap:12px; font-weight:850; font-size:15px; color:#172033; }
        .action-icon { width: 42px; height: 42px; flex:0 0 42px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0; background: var(--g-blue-soft); color: var(--g-blue); }

        .section-head { display:flex; align-items:flex-end; justify-content:space-between; gap:12px; margin: 30px 0 14px; }
        .section-head h2 { margin:0; font-size:20px; color:#172033; }
        .section-head span { color:var(--muted); font-size:12px; font-weight:750; }
        .course-list { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .course-row { background: var(--surface); border-radius: 12px; padding: 16px; display: grid; grid-template-columns: 42px minmax(0,1fr) auto; align-items: center; gap: 14px; border: 1px solid var(--line); box-shadow:0 10px 28px rgba(15,23,42,.05); text-decoration:none; color:inherit; min-width:0; transition:.18s ease; }
        .course-row:hover { border-color: color-mix(in srgb, var(--g-blue) 34%, var(--line)); transform: translateY(-1px); }
        .course-name { font-weight: 850; font-size: 14px; color:#172033; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .course-meta { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .course-pill { display:inline-flex; align-items:center; min-height:24px; padding:4px 8px; border-radius:999px; background:#f3f7fc; color:#516172; font-size:11px; font-weight:800; line-height:1; }
        .course-status { display:grid; justify-items:end; gap:4px; color:var(--muted); font-size:10px; font-weight:800; text-transform:uppercase; }
        .course-status strong { color:var(--g-blue); font-size:18px; line-height:1; }
        .course-progress { grid-column:2 / -1; height:7px; background:#edf2f7; border-radius:999px; overflow:hidden; }
        .course-progress span { display:block; height:100%; background:linear-gradient(90deg, var(--g-blue), #0f8aa8); border-radius:inherit; }
        
        .dock-wrapper { position: fixed; bottom: 30px; left: 0; right: 0; display: flex; justify-content: center; z-index: 2000; }
        .dock { background: rgba(22, 22, 23, 0.92); backdrop-filter: blur(18px); padding: 10px 15px; border-radius: 18px; display: flex; gap: 15px; border: 1px solid rgba(255,255,255,0.1); box-shadow:0 18px 45px rgba(15,23,42,.25); }
        .dock-link { text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 3px; color: #8e8e93; font-size: 10px; font-weight: 700; min-width: 55px; }
        .dock-link .material-symbols-rounded { font-size: 19px; }
        .dock-link.active { color: white; }
        .teacher-theme-control { display: none; }
        @media (max-width: 1200px) {
            .stat-card,
            .stat-grid,
            .action-card { grid-column: span 12; }
            .course-list { grid-template-columns: 1fr; }
            .teacher-theme-control {
                position: fixed;
                top: 12px;
                right: 12px;
                z-index: 7100;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px;
                border-radius: 999px;
                border: 1px solid var(--line);
                background: rgba(255,255,255,.94);
                box-shadow: 0 12px 28px rgba(15,23,42,.12);
                backdrop-filter: blur(14px);
            }
            .teacher-theme-control button {
                width: 32px;
                height: 32px;
                border: 0;
                border-radius: 999px;
                background: transparent;
                color: #64748b;
                font-weight: 900;
            }
            .teacher-theme-control button.active {
                background: linear-gradient(135deg, var(--g-blue), #0f8aa8);
                color: #fff;
            }
            .dock { max-width: calc(100vw - 24px); overflow-x:auto; }
            .glass-nav > div:last-child { width: 38px !important; max-width: 38px !important; overflow: visible !important; gap: 0 !important; }
            .glass-nav > div:last-child > div:first-child { display: none !important; }
            .glass-nav > div:last-child > div:last-child { flex: 0 0 36px !important; width: 36px !important; height: 36px !important; }
            .teacher-nav-meta { width:38px; max-width:38px; min-width:38px; overflow:visible; gap:0; }
            .teacher-nav-user { display:none; }
            .teacher-logo-tile { flex:0 0 36px; width:36px; height:36px; }
            .dock-wrapper { box-sizing: border-box !important; width: 100vw !important; max-width: 100vw !important; padding: 0 10px calc(6px + env(safe-area-inset-bottom)) !important; overflow: hidden !important; }
            .dock { box-sizing: border-box !important; width: calc(100vw - 20px) !important; max-width: calc(100vw - 20px) !important; min-width: 0 !important; display: grid !important; grid-auto-flow: column !important; grid-auto-columns: minmax(0, 1fr) !important; justify-content: stretch !important; justify-items: stretch !important; gap: 4px !important; padding: 8px !important; overflow: hidden !important; border-radius: 16px !important; }
            .dock-link { flex: initial !important; min-width: 0 !important; width: 100% !important; padding-inline: 2px !important; }
            .dock-link span:last-child { display: block !important; max-width: 100% !important; overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important; font-size: 10px !important; }
        }
        @media (max-width: 620px) {
            .container { padding-inline: 12px; }
            .stat-grid { grid-template-columns: 1fr; }
            .course-row { grid-template-columns: 42px minmax(0,1fr); }
            .course-status { display:none; }
            .course-progress { grid-column:1 / -1; }
        }
    </style>
</head>
<body>

<div class="orb orb-1"></div>

<nav class="glass-nav">
    <button class="header-menu-btn" onclick="toggleDrawer()" aria-label="Ouvrir le menu">
        <span class="material-symbols-rounded">menu</span>
    </button>
    <div class="teacher-nav-meta">
        <div class="teacher-nav-user">
            <strong>Pr. <?= htmlspecialchars(explode(' ', $enseignant->nom)[0]) ?></strong>
            <span><?= htmlspecialchars($annee_active) ?></span>
        </div>
        <div class="teacher-logo-tile">
            <img src="../images/LOGO.png" alt="ITMCD">
        </div>
    </div>
</nav>

<div class="teacher-theme-control" role="group" aria-label="Thème">
    <button type="button" data-teacher-theme="light" aria-label="Thème clair"><span class="material-symbols-rounded">light_mode</span></button>
    <button type="button" data-teacher-theme="dark" aria-label="Thème sombre"><span class="material-symbols-rounded">dark_mode</span></button>
</div>

<div class="drawer-overlay" id="drawerOverlay" onclick="toggleDrawer()"></div>

<aside class="side-drawer" id="sideDrawer">
    <div class="drawer-header">
        <div class="drawer-brand">
            <div class="drawer-logo">
                <img src="../images/LOGO.png" alt="ITMCD">
            </div>
            <div style="min-width:0;">
                <div class="drawer-title">Pr. <?= htmlspecialchars($enseignant->nom) ?></div>
                <div class="drawer-subtitle">Espace enseignant • <?= htmlspecialchars($annee_active) ?></div>
            </div>
        </div>
        <button type="button" class="drawer-close" onclick="toggleDrawer()" aria-label="Fermer le menu">
            <span class="material-symbols-rounded">close</span>
        </button>
    </div>
    
    <div class="drawer-content">
        <div class="drawer-section-title">Principal</div>
        <a href="index.php" class="drawer-item active"><span class="material-symbols-rounded">dashboard</span> Accueil</a>
        
        <div class="drawer-section-title">Travail</div>
        <?php if ($can_encode): ?>
        <a href="encodage_examen.php" class="drawer-item"><span class="material-symbols-rounded">edit_note</span> Encodage des cotes</a>
        <?php endif; ?>
        <?php if ($can_encode_moyennes): ?>
        <a href="saisie_moyennes.php" class="drawer-item"><span class="material-symbols-rounded">add_chart</span> Moyennes de secours</a>
        <?php endif; ?>
        
        <?php if ($can_download): ?>
        <a href="export_notes.php" class="drawer-item"><span class="material-symbols-rounded">download</span> Télécharger notes</a>
        <?php endif; ?>
        <a href="stats_enseignant.php" class="drawer-item"><span class="material-symbols-rounded">query_stats</span> Statistiques</a>
    </div>
    <div class="drawer-footer">
        <a href="../logout.php" class="drawer-item drawer-logout"><span class="material-symbols-rounded">logout</span> Déconnexion</a>
    </div>
</aside>

<main class="container">
    <div class="welcome-hero">
        <h1><?= $salutation ?>, <br>Pr. <?= htmlspecialchars($enseignant->nom) ?></h1>
        <p><?= count($attributions) ?> attribution<?= count($attributions) > 1 ? 's' : '' ?> active<?= count($attributions) > 1 ? 's' : '' ?> pour <?= htmlspecialchars($annee_active) ?>.</p>
    </div>

    <div class="bento-container">
        <div class="bento-card stat-card">
            <div style="font-weight: 700; opacity: 0.9; font-size: 12px; text-transform: uppercase;">Taux réel de réussite</div>
            <div style="font-size: clamp(42px, 8vw, 58px); font-weight: 850; margin: 8px 0; line-height:1;"><?= $taux ?>%</div>
            <div style="font-size:13px; opacity:.88; font-weight:650;"><?= (int)$s->ok ?> apprenant<?= (int)$s->ok > 1 ? 's' : '' ?> en réussite sur <?= (int)$s->total ?> suivi<?= (int)$s->total > 1 ? 's' : '' ?>.</div>
        </div>
        <div class="stat-grid">
            <div class="mini-stat">
                <span>Cours attribués</span>
                <strong><?= $total_cours ?></strong>
                <small>Unités actives dans votre charge</small>
            </div>
            <div class="mini-stat success">
                <span>Apprenants suivis</span>
                <strong><?= $total_apprenants_suivis ?></strong>
                <small>Total cumulé sur vos cours</small>
            </div>
            <div class="mini-stat">
                <span>Cotes saisies</span>
                <strong><?= $total_cotes_saisies ?></strong>
                <small>Notes enregistrées et disponibles</small>
            </div>
            <div class="mini-stat warning">
                <span>Progression</span>
                <strong><?= $progression_globale ?>%</strong>
                <small>Avancement de vos encodages</small>
            </div>
        </div>
        <?php if ($can_encode): ?>
        <a class="bento-card action-card" href="encodage_examen.php">
            <div class="action-title"><div class="action-icon"><span class="material-symbols-rounded">edit_note</span></div><span>Encoder les cotes</span></div>
            <small>Sélectionnez un cours, vérifiez la planification et saisissez la cote sur le maxima configuré.</small>
        </a>
        <?php endif; ?>
        <?php if ($can_encode_moyennes): ?>
        <a class="bento-card action-card" href="saisie_moyennes.php">
            <div class="action-title"><div class="action-icon"><span class="material-symbols-rounded">add_chart</span></div><span>Moyennes de secours</span></div>
            <small>Ajoutez la moyenne sur 10 qui renforce les cotes faibles sans l'afficher dans la grille.</small>
        </a>
        <?php endif; ?>
        <a class="bento-card action-card" href="stats_enseignant.php">
            <div class="action-title"><div class="action-icon"><span class="material-symbols-rounded">query_stats</span></div><span>Statistiques</span></div>
            <small>Consultez la réussite, les moyennes et la progression de vos cours.</small>
        </a>
        <?php if ($can_download): ?>
        <a class="bento-card action-card" href="export_notes.php">
            <div class="action-title"><div class="action-icon"><span class="material-symbols-rounded">download</span></div><span>Exporter les notes</span></div>
            <small>Téléchargez les listes et les états des cotes par cours.</small>
        </a>
        <?php endif; ?>
    </div>

    <div class="section-head">
        <h2>Vos Cours</h2>
        <span>Cliquez sur un cours pour ouvrir sa cotation</span>
    </div>
    <?php if (empty($attributions)): ?>
    <div class="course-row">
        <div class="action-icon" style="margin-bottom:0;"><span class="material-symbols-rounded">info</span></div>
        <div style="flex: 1;">
            <div style="font-weight: 700; font-size: 14px;">Aucune attribution disponible</div>
            <div style="font-size: 12px; color: #8e8e93;">Les cours affectés apparaitront ici.</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="course-list">
    <?php foreach($attributions as $a): ?>
    <?php
        $encoded = (int)$a->cotes_saisies;
        $expected = max(0, (int)$a->apprenants);
        $progress = $expected > 0 ? min(100, round(($encoded / $expected) * 100)) : 0;
        $semester_label = $a->semestre === 'ANNUEL' ? 'Annuel' : $a->semestre;
    ?>
    <a class="course-row" href="encodage_examen.php?cours_id=<?= (int)$a->id ?>">
        <div class="action-icon" style="margin-bottom:0;"><span class="material-symbols-rounded">menu_book</span></div>
        <div style="min-width:0;">
            <div class="course-name"><?= htmlspecialchars($a->cours) ?></div>
            <div class="course-meta">
                <span class="course-pill"><?= htmlspecialchars($a->promotion) ?></span>
                <span class="course-pill">Planification : <?= htmlspecialchars($semester_label) ?></span>
                <span class="course-pill">Maxima /<?= itm_format_points($a->coefficient) ?></span>
          </div>
        </div>
        <div class="course-status">
            <strong><?= $progress ?>%</strong>
            <span>coté</span>
            <span class="material-symbols-rounded">chevron_right</span>
        </div>
        <div class="course-progress" aria-label="Progression <?= $progress ?>%">
            <span style="width: <?= $progress ?>%;"></span>
        </div>
    </a>
    <?php endforeach; ?>
    </div>
</main>

<div class="dock-wrapper">
    <nav class="dock">
        <a href="index.php" class="dock-link active"><span class="material-symbols-rounded">dashboard</span><span>Accueil</span></a>
        <?php if ($can_encode): ?>
        <a href="encodage_examen.php" class="dock-link"><span class="material-symbols-rounded">edit_note</span><span>Cotes</span></a>
        <?php endif; ?>
        <?php if ($can_encode_moyennes): ?>
        <a href="saisie_moyennes.php" class="dock-link"><span class="material-symbols-rounded">add_chart</span><span>Moy.</span></a>
        <?php endif; ?>
        <?php if ($can_download): ?>
        <a href="export_notes.php" class="dock-link"><span class="material-symbols-rounded">download</span><span>Export</span></a>
        <?php endif; ?>
        <a href="stats_enseignant.php" class="dock-link"><span class="material-symbols-rounded">query_stats</span><span>Stats</span></a>
        <a href="../logout.php" class="dock-link"><span class="material-symbols-rounded" style="color: var(--danger);">logout</span><span>Sortir</span></a>
    </nav>
</div>

<script>
    function toggleDrawer() {
        document.getElementById('sideDrawer').classList.toggle('open');
        document.getElementById('drawerOverlay').classList.toggle('show');
    }

    const teacherThemeButtons = document.querySelectorAll('[data-teacher-theme]');
    function applyTeacherTheme(theme) {
        const selected = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', selected);
        document.body.dataset.itmTheme = selected;
        document.body.dataset.itmResolvedTheme = selected;
        try {
            localStorage.setItem('itmcd-app-shell-v1:theme', selected);
            localStorage.setItem('itmcd-theme', selected);
        } catch (e) {}
        teacherThemeButtons.forEach((button) => button.classList.toggle('active', button.dataset.teacherTheme === selected));
    }
    teacherThemeButtons.forEach((button) => button.addEventListener('click', () => applyTeacherTheme(button.dataset.teacherTheme)));
    try {
        applyTeacherTheme(localStorage.getItem('itmcd-app-shell-v1:theme') || localStorage.getItem('itmcd-theme') || 'light');
    } catch (e) {
        applyTeacherTheme('light');
    }
</script>

</body>
</html>
