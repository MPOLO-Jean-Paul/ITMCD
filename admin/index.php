<?php
session_start();
require_once '../config/db.php';

/* --- BACKEND --- */
if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// 1. GESTION DE L'ANNÉE À AFFICHER
$stmt_active = $pdo->query("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
$annee_active_default = $stmt_active->fetchColumn();

$annee_filtre = isset($_GET['annee_id']) ? (int)$_GET['annee_id'] : $annee_active_default;

try {
    $liste_annees = $pdo->query("SELECT * FROM annees_scolaires ORDER BY libelle DESC")->fetchAll();

    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM apprenants WHERE annee_scolaire_id = ?");
    $stmt1->execute([$annee_filtre]);
    $count_apprenants = $stmt1->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM cours c 
                            JOIN promotions p ON c.promotion_id = p.id 
                            JOIN apprenants a ON a.promotion_id = p.id 
                            WHERE a.annee_scolaire_id = ?");
    $stmt2->execute([$annee_filtre]);
    $count_cours = $stmt2->fetchColumn();

    $stmt3 = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM utilisateurs u 
                            JOIN cours c ON c.enseignant_id = u.id 
                            JOIN promotions p ON c.promotion_id = p.id 
                            JOIN apprenants a ON a.promotion_id = p.id 
                            WHERE a.annee_scolaire_id = ? AND u.role = 'enseignant'");
    $stmt3->execute([$annee_filtre]);
    $count_profs = $stmt3->fetchColumn();

    $count_promos = $pdo->query("SELECT COUNT(*) FROM promotions")->fetchColumn();
} catch (PDOException $e) {
    $count_apprenants = $count_cours = $count_profs = $count_promos = 0;
    $liste_annees = [];
}

// Compte des notifications non lues
itm_ensure_notifications_table($pdo);
$stmt_notif_count = $pdo->query("SELECT COUNT(*) FROM notifications WHERE est_lu = 0 AND (target_role IS NULL OR target_role = '' OR target_role = 'admin') AND target_user_id IS NULL");
$count_notifications = (int)$stmt_notif_count->fetchColumn(); // Force int pour éviter les erreurs JS
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet">
    <style>
        :root{
            --bg:#020617;
            --bg-soft:#0f172a;
            --card:#111827;
            --card-hover:#1e293b;
            --text:#f8fafc;
            --muted:#94a3b8;
            --blue:#38bdf8;
            --green:#4ade80;
            --purple:#c084fc;
            --orange:#fb923c;
            --red:#ef4444;
            --radius-xl:26px;
            --radius-l:18px;
            --shadow:0 40px 80px rgba(0,0,0,.55);
        }

        *{box-sizing:border-box}
        body{
            margin:0;
            font-family:Inter,system-ui;
            background:
            radial-gradient(800px at 20% -10%, rgba(56,189,248,.12), transparent),
            radial-gradient(600px at 90% 10%, rgba(192,132,252,.12), transparent),
            linear-gradient(180deg,var(--bg),var(--bg-soft));
            color:var(--text);
            min-height:100vh;
        }

        .header{
            position:sticky;
            top:0;
            z-index:100;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:18px 26px;
            background:rgba(2,6,23,.75);
            backdrop-filter:blur(14px);
            border-bottom:1px solid rgba(255,255,255,.06);
        }

        .brand{display:flex; align-items: center; gap: 12px;}
        .brand-logo{height: 38px; width: auto;}
        .brand-text{display:flex;flex-direction:column;}
        .brand strong{font-size:1.1rem;letter-spacing:.4px;}
        .brand small{font-size:.7rem;color:var(--blue);letter-spacing:1px;text-transform:uppercase;}

        .avatar{
            width:44px;height:44px;
            border-radius:50%;
            background:linear-gradient(135deg,#1a73e8,#38bdf8);
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:700;
            box-shadow:0 10px 30px rgba(56,189,248,.45);
        }

        .container{max-width:1100px;margin:auto;padding:26px;}

        .greeting{margin-bottom:28px;display: flex;justify-content: space-between;align-items: flex-end;flex-wrap: wrap;gap: 20px;}
        .greeting h1{margin:0;font-size:2rem;font-weight:800;}
        .greeting p{margin-top:6px;color:var(--muted);}

        .year-filter {
            background: var(--card);
            padding: 10px 18px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .year-filter select {
            background: transparent;
            color: var(--blue);
            border: none;
            font-weight: 700;
            font-size: 0.9rem;
            outline: none;
            cursor: pointer;
        }

        .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:34px;}
        .kpi{background:linear-gradient(180deg,var(--card),#0b1220);border-radius:var(--radius-l);padding:22px;box-shadow:var(--shadow);border:1px solid rgba(255,255,255,.06);position:relative;overflow:hidden;}

        .kpi::after{content:'';position:absolute;top:-30px;right:-30px;width:90px;height:90px;border-radius:50%;filter:blur(35px);opacity:.35;}
        .kpi.blue::after{background:var(--blue)}
        .kpi.green::after{background:var(--green)}
        .kpi.purple::after{background:var(--purple)}
        .kpi.orange::after{background:var(--orange)}

        .kpi .icon{font-size:30px;margin-bottom:10px;}
        .kpi .num{font-size:2.4rem;font-weight:700;}
        .kpi .label{font-size:.85rem;color:var(--muted);}
        .kpi .hint{font-size:.78rem;color:var(--muted);margin-top:8px;}

        .section-title{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
        .section-title span{font-weight:600;}
        .section-title::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.08);}

        .actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;}
        .action{background:linear-gradient(180deg,var(--card),#0b1220);border-radius:var(--radius-l);padding:18px 20px;display:flex;align-items:center;gap:16px;text-decoration:none;color:var(--text);border:1px solid rgba(255,255,255,.05);transition:.25s ease;}
        .action:hover{background:var(--card-hover);transform:translateY(-2px);}
        .year-filter select option { background: #0d1425; }

        /* ── KPI GRID ────────────────────────────────── */
        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 36px; }

        .kpi {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(12px);
            transition: transform .22s ease, border-color .22s ease, background .22s ease;
        }
        .kpi:hover { transform: translateY(-3px); background: var(--surface-hover); border-color: var(--border-hover); }
        .kpi::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 2px;
            border-radius: 2px 2px 0 0;
        }
        .kpi.blue::before { background: linear-gradient(90deg, var(--blue), transparent); }
        .kpi.green::before { background: linear-gradient(90deg, var(--green), transparent); }
        .kpi.purple::before { background: linear-gradient(90deg, var(--purple), transparent); }
        .kpi.orange::before { background: linear-gradient(90deg, var(--orange), transparent); }

        .kpi-icon {
            width: 42px; height: 42px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; margin-bottom: 14px;
        }
        .kpi.blue .kpi-icon { background: var(--blue-glow); color: var(--blue); }
        .kpi.green .kpi-icon { background: var(--green-glow); color: var(--green); }
        .kpi.purple .kpi-icon { background: var(--purple-glow); color: var(--purple); }
        .kpi.orange .kpi-icon { background: var(--orange-glow); color: var(--orange); }

        .kpi .num { font-size: 2.6rem; font-weight: 900; line-height: 1; letter-spacing: -1px; margin-bottom: 4px; }
        .kpi.blue .num { color: var(--blue); }
        .kpi.green .num { color: var(--green); }
        .kpi.purple .num { color: var(--purple); }
        .kpi.orange .num { color: var(--orange); }
        .kpi .label { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
        .kpi .hint { font-size: 11.5px; color: var(--muted); font-weight: 500; }

        /* ── SECTIONS ────────────────────────────────── */
        .section-label {
            display: flex; align-items: center; gap: 12px;
            font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--muted);
            margin: 32px 0 14px;
        }
        .section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        /* ── ACTIONS GRID ────────────────────────────── */
        .actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 13px; }

        .action {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 17px 18px;
            display: flex; align-items: center; gap: 14px;
            text-decoration: none; color: var(--text);
            transition: transform .2s ease, background .2s ease, border-color .2s ease, box-shadow .2s ease;
            backdrop-filter: blur(10px);
        }
        .action:hover {
            background: var(--surface-hover);
            border-color: var(--border-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        .action:active { transform: translateY(0); }

        .action .ico {
            width: 40px; height: 40px; flex: 0 0 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        .action .txt { flex: 1; font-weight: 700; font-size: 13.5px; line-height: 1.3; }
        .action .txt small { display: block; color: var(--muted); font-weight: 500; font-size: 11.5px; margin-top: 2px; }
        .action .arr { color: var(--muted); font-size: 18px; flex-shrink: 0; transition: transform .2s; }
        .action:hover .arr { transform: translateX(3px); color: var(--text); }

        .action.danger { border-color: rgba(248,113,113,0.18); }
        .action.danger:hover { border-color: rgba(248,113,113,0.38); background: rgba(248,113,113,0.06); }
        .action.danger .txt { color: var(--red); }

        /* ── TOAST ───────────────────────────────────── */
        #toast-box {
            position: fixed; top: 80px; right: 20px;
            z-index: 9999; display: flex; flex-direction: column; gap: 10px;
            pointer-events: none;
        }
        .toast {
            pointer-events: auto;
            background: rgba(15,22,42,0.98);
            backdrop-filter: blur(16px);
            border-left: 3px solid var(--blue);
            color: var(--text);
            padding: 14px 20px;
            border-radius: var(--radius-sm);
            box-shadow: 0 16px 48px rgba(0,0,0,0.6);
            display: flex; align-items: center; gap: 14px;
            min-width: 300px; max-width: 380px;
            transform: translateX(130%);
            transition: transform 0.38s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid var(--border);
        }
        .toast.show { transform: translateX(0); }
        .toast .material-symbols-rounded { font-size: 22px; color: var(--blue); flex-shrink: 0; }

        /* ── RESPONSIVE ──────────────────────────────── */
        @media (max-width: 1000px) { .kpis { grid-template-columns: repeat(2,1fr); } .actions { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 620px) { .kpis { grid-template-columns: 1fr 1fr; } .actions { grid-template-columns: 1fr; } .page { padding-inline: 16px; } .user-chip span { display: none; } .btn-logout span { display: none; } }
        @media (max-width: 400px) { .kpis { grid-template-columns: 1fr; } }

        /* Entrance animations */
        @keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:none; } }
        .kpi { animation: fadeUp .35s ease both; }
        .kpi:nth-child(1){animation-delay:.04s} .kpi:nth-child(2){animation-delay:.08s} .kpi:nth-child(3){animation-delay:.12s} .kpi:nth-child(4){animation-delay:.16s}
        .action { animation: fadeUp .32s ease both; }
        .action:nth-child(1){animation-delay:.06s} .action:nth-child(2){animation-delay:.10s} .action:nth-child(3){animation-delay:.14s}
        .action:nth-child(4){animation-delay:.18s} .action:nth-child(5){animation-delay:.22s} .action:nth-child(6){animation-delay:.26s}
        .action:nth-child(7){animation-delay:.30s} .action:nth-child(8){animation-delay:.34s} .action:nth-child(9){animation-delay:.38s}
        .action:nth-child(10){animation-delay:.42s} .action:nth-child(11){animation-delay:.46s}

        /* Tableau de bord admin - finition claire et stable */
        :root {
            --bg: #f5f8fc;
            --bg-soft: #eef4fa;
            --card: #ffffff;
            --card-hover: #f8fbff;
            --surface: #ffffff;
            --surface-hover: #f8fbff;
            --border: #dbe5f0;
            --border-hover: #bfd0e4;
            --text: #172033;
            --muted: #66758a;
            --blue: #2563eb;
            --green: #16a34a;
            --purple: #7c3aed;
            --orange: #d97706;
            --pink: #db2777;
            --red: #dc2626;
            --blue-glow: rgba(37, 99, 235, .11);
            --green-glow: rgba(22, 163, 74, .11);
            --purple-glow: rgba(124, 58, 237, .11);
            --orange-glow: rgba(217, 119, 6, .12);
            --pink-glow: rgba(219, 39, 119, .11);
            --radius: 12px;
            --radius-l: 12px;
            --radius-xl: 14px;
            --shadow: 0 18px 45px rgba(15, 23, 42, .08);
        }

        body {
            background: linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
            color: var(--text);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            background-image:
                linear-gradient(rgba(15, 23, 42, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15, 23, 42, .035) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.55), transparent 70%);
        }

        .header {
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 10px 28px rgba(15,23,42,.08);
        }

        .brand {
            text-decoration: none;
            color: var(--text);
        }

        .brand strong { color: var(--text); }
        .brand small { color: var(--blue); }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .notif-btn,
        .btn-logout,
        .user-chip {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            box-shadow: 0 10px 24px rgba(15,23,42,.06);
        }

        .notif-btn {
            width: 42px;
            justify-content: center;
            position: relative;
            cursor: pointer;
        }

        #notif-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: var(--red);
            color: #fff;
            font-size: 10px;
            font-weight: 900;
            border: 2px solid #fff;
        }

        .user-chip {
            padding: 4px 12px 4px 4px;
            font-weight: 800;
            max-width: 190px;
        }

        .avatar {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--blue), #0e7490);
            color: #fff;
            box-shadow: none;
        }

        .btn-logout {
            padding: 0 14px;
            text-decoration: none;
            font-weight: 800;
        }

        .btn-logout:hover,
        .notif-btn:hover {
            border-color: var(--border-hover);
            color: var(--blue);
            background: var(--card-hover);
        }

        .page {
            width: min(100%, 1180px);
            max-width: calc(100vw - 32px);
            margin: 0 auto;
            padding: 28px 0 90px;
        }

        .admin-page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }

        .admin-page-header h1 {
            margin: 0;
            color: var(--text);
            font-size: clamp(26px, 4vw, 38px);
            line-height: 1.05;
            font-weight: 900;
        }

        .admin-page-header p {
            margin: 8px 0 0;
            color: var(--muted);
            font-weight: 650;
        }

        .year-filter {
            min-height: 46px;
            background: var(--surface);
            border-color: var(--border);
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15,23,42,.06);
        }

        .year-filter select {
            color: var(--blue);
            min-height: 32px;
        }

        .year-filter select option {
            background: #ffffff;
            color: var(--text);
        }

        .kpis,
        .actions {
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        }

        .kpi,
        .action {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .kpi {
            min-height: 158px;
        }

        .kpi:hover,
        .action:hover {
            background: var(--surface-hover);
            border-color: var(--border-hover);
        }

        .kpi .label,
        .action .txt {
            color: var(--text);
        }

        .section-label {
            color: var(--muted);
        }

        .section-label::after {
            background: var(--border);
        }

        @media (max-width: 720px) {
            .header {
                padding: 12px;
                gap: 10px;
            }

            .brand-text,
            .user-chip span,
            .btn-logout span {
                display: none;
            }

            .header-right {
                gap: 8px;
            }

            .page {
                max-width: calc(100vw - 24px);
                padding-top: 22px;
            }

            .admin-page-header {
                align-items: stretch;
                flex-direction: column;
            }

            .year-filter {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>

<header class="header">
    <a class="brand" href="#">
        <img src="../images/LOGO.png" alt="Logo" class="brand-logo">
        <div class="brand-text">
            <strong>ITMCD</strong>
            <small style="display:block">Administration</small>
        </div>
    </a>

    <div class="header-right">
        <div id="notif-trigger" class="notif-btn" title="Notifications" role="button" tabindex="0">
            <span class="material-symbols-rounded" style="font-size:20px">notifications</span>
            <div id="notif-badge" style="display:<?= $count_notifications > 0 ? 'flex' : 'none' ?>">
                <?= $count_notifications > 9 ? '9+' : $count_notifications ?>
            </div>
        </div>

        <div class="user-chip">
            <div class="avatar"><?= strtoupper(substr($_SESSION['user']->nom, 0, 1)) ?></div>
            <span><?= htmlspecialchars(explode(' ', $_SESSION['user']->nom)[0]) ?></span>
        </div>

        <a href="../logout.php" class="btn-logout" title="Déconnexion">
            <span class="material-symbols-rounded" style="font-size:17px">power_settings_new</span>
            <span>Quitter</span>
        </a>
    </div>
</header>

<div id="toast-box"></div>

<main class="page">
    <div class="admin-page-header">
        <div>
            <h1>Tableau de bord</h1>
            <p>Vue d'ensemble — gestion académique ITMCD</p>
        </div>
        <form method="GET" class="year-filter">
            <span class="material-symbols-rounded" style="color:var(--muted);font-size:18px">calendar_month</span>
            <select name="annee_id" onchange="this.form.submit()">
                <?php foreach($liste_annees as $an): ?>
                    <option value="<?= $an->id ?>" <?= ($an->id == $annee_filtre) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($an->libelle) ?> <?= $an->est_active ? '● Actif' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi blue">
            <div class="kpi-icon"><span class="material-symbols-rounded">groups</span></div>
            <div class="num"><?= $count_apprenants ?></div>
            <div class="label">Apprenants</div>
            <div class="hint">Inscrits pour l'année sélectionnée</div>
        </div>
        <div class="kpi green">
            <div class="kpi-icon"><span class="material-symbols-rounded">menu_book</span></div>
            <div class="num"><?= $count_cours ?></div>
            <div class="label">Cours &amp; événements</div>
            <div class="hint">Unités liées aux promotions actives</div>
        </div>
        <div class="kpi purple">
            <div class="kpi-icon"><span class="material-symbols-rounded">co_present</span></div>
            <div class="num"><?= $count_profs ?></div>
            <div class="label">Enseignants</div>
            <div class="hint">Intervenants affectés aux cours</div>
        </div>
        <div class="kpi orange">
            <div class="kpi-icon"><span class="material-symbols-rounded">school</span></div>
            <div class="num"><?= $count_promos ?></div>
            <div class="label">Promotions</div>
            <div class="hint">Parcours configurés dans l'école</div>
        </div>
    </div>

    <!-- Actions: Système -->
    <div class="section-label"><span>Système &amp; Configuration</span></div>
    <div class="actions">
        <a class="action" href="monitoring.php">
            <div class="ico" style="background:var(--orange-glow);color:var(--orange)"><span class="material-symbols-rounded">settings_suggest</span></div>
            <div class="txt">Config &amp; Monitoring<small>Paramètres système et surveillance</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="parametres.php">
            <div class="ico" style="background:var(--blue-glow);color:var(--blue)"><span class="material-symbols-rounded">tune</span></div>
            <div class="txt">Paramètres enseignants<small>Accès et permissions</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="cloture_annee.php">
            <div class="ico" style="background:rgba(248,113,113,.12);color:var(--red)"><span class="material-symbols-rounded">event_repeat</span></div>
            <div class="txt">Gestion Année scolaire<small>Créer, activer, clôturer</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
    </div>

    <!-- Actions: Pédagogie -->
    <div class="section-label"><span>Pédagogie</span></div>
    <div class="actions">
        <a class="action" href="gestion_cours.php">
            <div class="ico" style="background:var(--blue-glow);color:var(--blue)"><span class="material-symbols-rounded">library_add</span></div>
            <div class="txt">Cours &amp; événements<small>Ajouter et gérer les cours</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="gestion_notes.php">
            <div class="ico" style="background:var(--green-glow);color:var(--green)"><span class="material-symbols-rounded">monitoring</span></div>
            <div class="txt">Cotes apprenants<small>Saisie et vérification</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="bulletin.php">
            <div class="ico" style="background:var(--orange-glow);color:var(--orange)"><span class="material-symbols-rounded">assignment</span></div>
            <div class="txt">Bulletins des notes<small>Générer les bulletins PDF</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="grille_inf1.php">
            <div class="ico" style="background:var(--green-glow);color:var(--green)"><span class="material-symbols-rounded">table_chart</span></div>
            <div class="txt">Grille des notes<small>Vue globale par promotion</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action danger" href="deliberation.php">
            <div class="ico" style="background:rgba(248,113,113,.12);color:var(--red)"><span class="material-symbols-rounded">gavel</span></div>
            <div class="txt">Délibération<small>Jury et résultats finaux</small></div>
            <span class="material-symbols-rounded arr" style="color:var(--red)">chevron_right</span>
        </a>
    </div>

    <!-- Actions: Personnes -->
    <div class="section-label"><span>Personnes</span></div>
    <div class="actions">
        <a class="action" href="gestion_enseignants.php">
            <div class="ico" style="background:var(--purple-glow);color:var(--purple)"><span class="material-symbols-rounded">manage_accounts</span></div>
            <div class="txt">Gestion Enseignants<small>Profils et affectations</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="liste_globale.php">
            <div class="ico" style="background:var(--purple-glow);color:var(--purple)"><span class="material-symbols-rounded">groups</span></div>
            <div class="txt">Gestion Apprenants<small>Liste et profils des élèves</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="ajouter_apprenant.php">
            <div class="ico" style="background:var(--blue-glow);color:var(--blue)"><span class="material-symbols-rounded">person_add</span></div>
            <div class="txt">Inscription Apprenants<small>Enregistrer un nouvel élève</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
        <a class="action" href="gestion_promotions.php">
            <div class="ico" style="background:var(--pink-glow);color:var(--pink)"><span class="material-symbols-rounded">domain</span></div>
            <div class="txt">Gestion Promotions<small>Classes et filières</small></div>
            <span class="material-symbols-rounded arr">chevron_right</span>
        </a>
    </div>
</main>

<script>
let lastNotifCount = <?= (int)$count_notifications ?>;

document.addEventListener('DOMContentLoaded', function () {
    const trigger = document.getElementById('notif-trigger');
    trigger.addEventListener('click', function () {
        const badge = document.getElementById('notif-badge');
        if (badge.style.display !== 'none') {
            markNotificationsAsRead();
        } else {
            window.location.href = 'monitoring.php';
        }
    });
    setInterval(checkNotifications, 5000);
});

function checkNotifications() {
    fetch('check_notifications.php?t=' + Date.now())
        .then(r => r.json())
        .then(data => {
            const badge = document.getElementById('notif-badge');
            let count = parseInt(data.count);
            if (count > 0) {
                badge.innerText = count > 9 ? '9+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
            if (count > lastNotifCount) {
                showSmartAlert(data.message);
                lastNotifCount = count;
            }
        })
        .catch(err => console.error('Erreur check notif:', err));
}

function showSmartAlert(msg) {
    let toast = document.createElement('div');
    toast.classList.add('toast');
    toast.innerHTML = `<span class="material-symbols-rounded">notifications_active</span><div>${msg}</div>`;
    const box = document.getElementById('toast-box');
    if (box) {
        box.appendChild(toast);
        requestAnimationFrame(() => { toast.classList.add('show'); });
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 500);
        }, 6000);
    }
    if ('Notification' in window && Notification.permission === 'granted') {
        navigator.serviceWorker.ready.then(reg => {
            reg.showNotification('ITMCD - Administration', {
                body: msg, icon: '../images/LOGO.png', tag: 'itmcd-alert', renotify: true
            });
        });
    }
}

function markNotificationsAsRead() {
    fetch('mark_notifications_read.php?t=' + Date.now())
        .then(r => {
            if (r.ok) {
                document.getElementById('notif-badge').style.display = 'none';
                lastNotifCount = 0;
                window.location.href = 'monitoring.php';
            }
        });
}
</script>
</body>
</html>
