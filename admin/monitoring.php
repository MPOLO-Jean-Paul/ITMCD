<?php
session_start();
require_once '../config/db.php';

// 1. SÉCURITÉ & RÉCUPÉRATION SESSION
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = is_array($_SESSION['user']) ? (object)$_SESSION['user'] : $_SESSION['user'];

if ($user->role !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$admin_id = $user->id;
$msg_profil = "";

// --- GESTION DES FILTRES INDÉPENDANTS ---
$allowed_periods = ['S1', 'S2', 'FINAL'];
$periode = $_GET['periode'] ?? 'S1'; // Pour le bloc Performance
$p_flux = $_GET['p_flux'] ?? 'S1'; // Pour le bloc Flux
$p_suivi = $_GET['p_suivi'] ?? 'S1'; // Pour le bloc Suivi

$periode = in_array($periode, $allowed_periods, true) ? $periode : 'S1';
$p_flux = in_array($p_flux, $allowed_periods, true) ? $p_flux : 'S1';
$p_suivi = in_array($p_suivi, $allowed_periods, true) ? $p_suivi : 'S1';

// Logique SQL Performance (Global)
if ($periode === 'S1') { $col_decision = "decision_s1"; } 
elseif ($periode === 'S2') { $col_decision = "decision_s2"; } 
else { $col_decision = "decision_jury"; }

// Logique SQL Suivi Encodage
$sql_filtre_suivi = ($p_suivi === 'FINAL') ? "" : "AND (c.semestre = '$p_suivi' OR c.semestre = 'ANNUEL')";

// Logique SQL Flux d'activités
$sql_filtre_flux = ($p_flux === 'FINAL') ? "" : "AND (c.semestre = '$p_flux' OR c.semestre = 'ANNUEL')";

$monitor_score_sql = "
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


// 2. MISE À JOUR DU PROFIL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    require_csrf();
    $nom = htmlspecialchars($_POST['nom']);
    $email = htmlspecialchars($_POST['email']);
    
    if (!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, email = ?, mot_de_passe = ? WHERE id = ?");
        $stmt->execute([$nom, $email, $hash, $admin_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, email = ? WHERE id = ?");
        $stmt->execute([$nom, $email, $admin_id]);
    }
    $msg_profil = "Profil synchronisé avec succès !";
}

// 3. REQUÊTES SQL
try {
    // Statistiques des promotions - Calcul basé sur total obtenu / total maxima >= 60%
    $promos_stats = $pdo->query("
        SELECT p.nom, p.id,
        (SELECT COUNT(*) FROM apprenants WHERE promotion_id = p.id) as total_eleves,
        (SELECT COUNT(*) FROM apprenants ap
         WHERE ap.promotion_id = p.id 
         AND (
            SELECT (
                SUM(
                    $monitor_score_sql
                ) / NULLIF(SUM(c.coefficient), 0)
            ) * 100
            FROM cours c
            LEFT JOIN (
                SELECT apprenant_id, cours_id,
                       MAX(CASE WHEN type_note IN ('cote', 'cote_s1', 'cote_s2') THEN valeur END) AS cote,
                       MAX(CASE WHEN type_note = 'examen' THEN valeur END) AS examen,
                       MAX(CASE WHEN type_note = 'moyenne' THEN valeur END) AS moyenne
                FROM notes
                GROUP BY apprenant_id, cours_id
            ) ng ON ng.cours_id = c.id AND ng.apprenant_id = ap.id
            WHERE c.promotion_id = ap.promotion_id
         ) >= 60
        ) as reussites
        FROM promotions p
    ")->fetchAll(PDO::FETCH_OBJ);

    // Le reste du calcul (global_total, global_reussites, etc.) reste identique
    $global_total = 0; $global_reussites = 0;
    foreach($promos_stats as $ps) { 
        $global_total += $ps->total_eleves; 
        $global_reussites += $ps->reussites; 
    }
    $global_echecs = $global_total - $global_reussites;
    $taux_reussite_gen = ($global_total > 0) ? round(($global_reussites / $global_total) * 100) : 0;
    $taux_echec_gen = ($global_total > 0) ? (100 - $taux_reussite_gen) : 0;

    // Progression des profs (FILTRÉE PAR $p_suivi) - cotes directes
    $stmt_profs = $pdo->prepare("
        SELECT u.nom, 
        (SELECT SUM((SELECT COUNT(*) FROM apprenants WHERE promotion_id = c.promotion_id)) 
         FROM cours c WHERE c.enseignant_id = u.id $sql_filtre_suivi) as total_attendus,
        (SELECT COUNT(*) FROM notes n 
         JOIN cours c ON n.cours_id = c.id 
         WHERE n.enseignant_id = u.id AND n.type_note = 'cote' $sql_filtre_suivi) as saisies_cote
        FROM utilisateurs u
        WHERE u.role = 'enseignant'
        ORDER BY u.nom ASC
    ");
    $stmt_profs->execute();
    $profs_progress = $stmt_profs->fetchAll(PDO::FETCH_OBJ);

    // Flux d'activités (FILTRÉ PAR $p_flux)
    $recent_notes = $pdo->query("
        SELECT n.date_encodage, n.type_note, a.nom as eleve_nom, c.nom as cours_nom, u.nom as prof_nom
        FROM notes n
        JOIN apprenants a ON n.apprenant_id = a.id
        JOIN cours c ON n.cours_id = c.id
        JOIN utilisateurs u ON n.enseignant_id = u.id
        WHERE 1 $sql_filtre_flux
        ORDER BY n.date_encodage DESC LIMIT 15
    ")->fetchAll(PDO::FETCH_OBJ);

} catch (PDOException $e) {
    die("<div style='color:white; background:red; padding:20px;'>Erreur Critique SQL : " . $e->getMessage() . "</div>");
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,fill,GRAD@24,400,1,0" rel="stylesheet">

<style>
    :root {
        --bg: #030712;
        --glass: rgba(17, 24, 39, 0.7);
        --primary: #6366f1;
        --accent: #22d3ee;
        --border: rgba(255, 255, 255, 0.06);
    }

    body {
        background: var(--bg);
        color: #f3f4f6;
        font-family: 'Plus Jakarta Sans', sans-serif;
        background-image: radial-gradient(circle at 0% 0%, rgba(99, 102, 241, 0.15) 0%, transparent 35%);
        padding-top: 80px;
        overflow-x: hidden;
    }

    .nav-header {
        position: fixed;
        top: 0; left: 0; right: 0;
        z-index: 1050;
        padding: 12px 20px;
        background: rgba(3, 7, 18, 0.9);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--border);
        display: flex; justify-content: space-between; align-items: center;
    }

    /* Adaptations Mobile pour le Header */
    @media (max-width: 576px) {
        .nav-header { padding: 10px 15px; }
        body { padding-top: 70px; }
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .premium-card {
        background: var(--glass);
        backdrop-filter: blur(15px);
        border: 1px solid var(--border);
        border-radius: 28px;
        padding: 20px;
        height: 100%;
        animation: fadeInUp 0.6s ease-out forwards;
    }

    .input-quantum {
        background: rgba(0,0,0,0.3) !important;
        border: 1px solid var(--border) !important;
        color: white !important;
        border-radius: 14px !important;
        padding: 12px !important;
    }

    .filter-btn {
        background: rgba(255,255,255,0.05);
        border: 1px solid var(--border);
        color: #94a3b8;
        padding: 6px 14px;
        border-radius: 12px;
        text-decoration: none;
        font-size: 0.75rem;
        transition: 0.3s;
        white-space: nowrap;
    }
    .filter-btn.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    label {
        color: #ffffff !important;
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 8px;
        display: block;
    }

    .progress-track {
        background: rgba(255,255,255,0.05);
        height: 8px; border-radius: 10px; margin: 10px 0;
        overflow: hidden;
    }
    .progress-bar-fill {
        height: 100%; background: linear-gradient(90deg, var(--primary), var(--accent));
        border-radius: 10px; transition: 1.5s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .notif-item {
        background: rgba(255,255,255,0.02);
        border-left: 4px solid var(--primary);
        padding: 12px; border-radius: 15px; margin-bottom: 10px;
    }

    .scroll-container {
        max-height: 400px;
        overflow-y: auto;
        padding-right: 5px;
    }

    /* Scrollbar discrète */
    .scroll-container::-webkit-scrollbar { width: 4px; }
    .scroll-container::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }

    /* Ajustements Tableaux Mobile */
    .table-responsive {
        border-radius: 15px;
        overflow: hidden;
    }

    @media (max-width: 768px) {
        .premium-card { border-radius: 20px; padding: 15px; }
        .row.g-4 { --bs-gutter-y: 1.5rem; }
    }

    .container-fluid {
        width: calc(100vw - 32px) !important;
        max-width: 1180px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    .monitoring-layout,
    .row.g-4 {
        display: grid !important;
        width: calc(100vw - 32px) !important;
        max-width: 100% !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 18px !important;
        align-items: start !important;
        justify-items: stretch !important;
    }

    .monitoring-layout > .monitoring-panel,
    .row.g-4 > [class*="col-"] {
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        flex: initial !important;
        justify-self: stretch !important;
    }

    .monitoring-profile,
    .row.g-4 > [class*="col-"]:nth-child(1) {
        grid-column: 1 !important;
        grid-row: 1 !important;
    }

    .monitoring-flux,
    .row.g-4 > [class*="col-"]:nth-child(2) {
        grid-column: 2 !important;
        grid-row: 1 !important;
    }

    .monitoring-suivi,
    .row.g-4 > [class*="col-"]:nth-child(3) {
        grid-column: 1 / -1 !important;
        grid-row: 2 !important;
        justify-self: stretch !important;
        align-self: stretch !important;
    }

    .premium-card {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .table-responsive {
        max-width: 100% !important;
        overflow-x: auto !important;
    }

    .nav-header > a .material-symbols-rounded {
        display: none !important;
    }

    .premium-card > .d-flex:first-child,
    .premium-card .d-flex.justify-content-between {
        display: flex !important;
        flex-wrap: wrap !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px !important;
        min-width: 0 !important;
    }

    .premium-card h5 {
        flex: 1 1 220px !important;
        min-width: 0 !important;
        max-width: 100% !important;
        white-space: normal !important;
        overflow-wrap: normal !important;
        word-break: normal !important;
        line-height: 1.18 !important;
    }

    .premium-card .filter-btn {
        width: auto !important;
        min-width: 44px !important;
        flex: 0 0 auto !important;
        padding: 9px 13px !important;
    }

    .premium-card .table {
        min-width: 620px !important;
    }

    @media (min-width: 1280px) {
        .container-fluid,
        .monitoring-layout {
            width: 1180px !important;
            min-width: 1180px !important;
            max-width: 1180px !important;
        }

        .monitoring-layout {
            grid-template-columns: 581px 581px !important;
        }

        .monitoring-profile,
        .monitoring-flux {
            width: 581px !important;
        }

        .monitoring-suivi {
            width: 1180px !important;
        }
    }

    @media (min-width: 981px) and (max-width: 1279px) {
        .container-fluid,
        .monitoring-layout {
            width: calc(100vw - 32px) !important;
            max-width: calc(100vw - 32px) !important;
        }

        .monitoring-layout {
            grid-template-columns: calc((100vw - 50px) / 2) calc((100vw - 50px) / 2) !important;
        }
    }

    @media (max-width: 980px) {
        .container-fluid { width: calc(100vw - 20px) !important; max-width: calc(100vw - 20px) !important; }
        .monitoring-layout { width: 100% !important; max-width: 100% !important; }
        .monitoring-layout, .row.g-4 { grid-template-columns: 1fr !important; }
        .monitoring-layout > .monitoring-panel,
        .row.g-4 > [class*="col-"] {
            grid-column: 1 !important;
            grid-row: auto !important;
        }
    }
</style>
</head>
<body>

<div class="nav-header">
    <a href="index.php" class="text-white text-decoration-none d-flex align-items-center gap-2">
        <span class="material-symbols-rounded text-accent">Retour</span>
        <b class="d-none d-sm-inline">Retour</b>
    </a>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary rounded-pill px-2 py-1" style="font-size: 0.7rem;">Admin</span>
        <div class="text-white-50 small" style="font-size: 0.75rem;"><?= date('d/m') ?></div>
    </div>
</div>

<div class="container-fluid p-3 p-md-4" style="display:block; width:calc(100vw - 32px) !important; max-width:1180px !important; margin-left:auto; margin-right:auto;">
    <div class="monitoring-layout" style="display:grid; width:calc(100vw - 32px) !important; max-width:100% !important; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px; align-items:start; justify-items:stretch;">
        
        <div class="monitoring-panel monitoring-profile" style="grid-column:1; grid-row:1; width:100%; max-width:none; min-width:0; justify-self:stretch;">
            <div class="premium-card">
                <h5 class="mb-4 d-flex align-items-center gap-2 text-white">
                    <span class="material-symbols-rounded text-primary"></span> Profil Admin
                </h5>
                <?php if($msg_profil): ?>
                    <div class="alert alert-success bg-success bg-opacity-10 border-0 text-success small py-2"><?= $msg_profil ?></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label>Nom complet</label>
                        <input type="text" name="nom" class="form-control input-quantum" value="<?= htmlspecialchars($user->nom) ?>">
                    </div>
                    <div class="mb-3">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control input-quantum" value="<?= htmlspecialchars($user->email) ?>">
                    </div>
                    <div class="mb-4">
                        <label>Nouveau mot de passe (optionnel)</label>
                        <input type="password" name="password" class="form-control input-quantum" placeholder="••••••••">
                    </div>
                    <button type="submit" name="update_profile" class="btn btn-primary w-100 fw-bold py-2 shadow-lg" style="border-radius:12px">
                        Mettre à jour
                    </button>
                </form>
            </div>
        </div>

       
        <div class="monitoring-panel monitoring-flux" style="grid-column:2; grid-row:1; width:100%; max-width:none; min-width:0; justify-self:stretch;">
            <div class="premium-card">
                <div class="d-flex justify-content-between align-items-center mb-4 gap-2">
                    <h5 class="m-0 d-flex align-items-center gap-2 text-white">
                        <span class="material-symbols-rounded text-warning"></span> Flux D'activités
                    </h5>
                    <div class="d-flex gap-1">
                        <a href="?p_flux=S1&periode=<?= $periode ?>&p_suivi=<?= $p_suivi ?>" class="filter-btn <?= $p_flux == 'S1' ? 'active' : '' ?>">S1</a>
                        <a href="?p_flux=S2&periode=<?= $periode ?>&p_suivi=<?= $p_suivi ?>" class="filter-btn <?= $p_flux == 'S2' ? 'active' : '' ?>">S2</a>
                    </div>
                </div>

                <div class="scroll-container">
                    <?php foreach($recent_notes as $n): ?>
                        <div class="notif-item">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="fw-bold text-primary text-truncate" style="max-width: 70%;"><?= htmlspecialchars($n->prof_nom) ?></small>
                                <small class="text-muted" style="font-size: 0.65rem;"><?= date('H:i', strtotime($n->date_encodage)) ?></small>
                            </div>
                            <div class="small text-white-50" style="font-size: 0.75rem;">
                                A saisi : <b><?= htmlspecialchars($n->type_note) ?></b> de <b><?= htmlspecialchars($n->eleve_nom) ?></b>
                            </div>
                            <div class="mt-1 d-flex align-items-center" style="font-size: 0.7rem; color: #10b981;">
                                <span class="material-symbols-rounded me-1" style="font-size: 0.8rem;">Cours : </span> <?= htmlspecialchars($n->cours_nom) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="monitoring-panel monitoring-suivi" style="grid-column:1 / -1; grid-row:2; width:100%; max-width:none; min-width:0; justify-self:stretch;">
            <div class="premium-card">
                <div class="d-flex justify-content-between align-items-center mb-4 gap-2">
                    <h5 class="m-0 d-flex align-items-center gap-2 text-white">
                        <span class="material-symbols-rounded text-accent"></span> Suivi Encodage
                    </h5>
                    <div class="d-flex gap-1">
                        <a href="?p_suivi=S1&periode=<?= $periode ?>&p_flux=<?= $p_flux ?>" class="filter-btn <?= $p_suivi == 'S1' ? 'active' : '' ?>">S1</a>
                        <a href="?p_suivi=S2&periode=<?= $periode ?>&p_flux=<?= $p_flux ?>" class="filter-btn <?= $p_suivi == 'S2' ? 'active' : '' ?>">S2</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-dark table-borderless align-middle m-0" style="font-size: 0.8rem;">
                        <thead>
                            <tr class="text-white-50 border-bottom border-white border-opacity-10">
                                <th class="ps-0">Prof.</th>
                                <th>Progression</th>
                                <th class="text-end">État</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($profs_progress as $pp): 
                                $attendus = $pp->total_attendus ?? 0;
                                $s_cote = $pp->saisies_cote ?? 0;
                                
                                $p_cote = ($attendus > 0) ? round(($s_cote / $attendus) * 100) : 0;
                                
                                $is_done = ($p_cote >= 100 && $attendus > 0);
                            ?>
                            <tr>
                                <td class="ps-0">
                                    <div class="fw-bold text-white text-truncate" style="max-width: 80px;"><?= explode(' ', $pp->nom)[0] ?></div>
                                    <div class="text-muted" style="font-size: 0.65rem;">Eff: <?= $attendus ?></div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                            <div class="progress-track flex-grow-1 m-0" style="height: 3px;">
                                            <div class="progress-bar-fill" style="width: <?= $p_cote ?>%; background: var(--accent);"></div>
                                        </div>
                                        <span style="font-size: 0.65rem; color: var(--accent); min-width: 25px;"><?= $p_cote ?>%</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <span class="material-symbols-rounded <?= $is_done ? 'text-success' : 'text-warning' ?>" style="font-size: 1.2rem;">
                                        <?= $is_done ? 'check_circle' : 'En cours' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>
