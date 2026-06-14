<?php
session_start();
require_once '../config/db.php';
checkRole('admin');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

$promo_id = isset($_GET['promo_id']) ? intval($_GET['promo_id']) : null;
$apprenant_id = isset($_GET['apprenant_id']) ? intval($_GET['apprenant_id']) : null;
$message = "";
$admin_id = (int)($_SESSION['user']->id ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes'])) {
    require_csrf();
    try {
        if (!$apprenant_id) {
            throw new InvalidArgumentException('Veuillez selectionner un apprenant avant la sauvegarde.');
        }

        $pdo->beginTransaction();

        foreach ($_POST['notes'] ?? [] as $cours_id => $types) {
            if (!is_array($types)) {
                continue;
            }

            foreach ($types as $period => $rawValue) {
                if ($period === 'cote') {
                    $period = 'S1';
                }

                if (!in_array($period, ['S1', 'S2'], true)) {
                    continue;
                }

                $sync = itm_upsert_period_grade($pdo, $apprenant_id, $cours_id, $admin_id, $period, $rawValue);
                if ($sync === null) {
                    continue;
                }
            }
        }

        itm_refresh_student_decisions($pdo, $apprenant_id, $promo_id);
        $pdo->commit();
        $message = "success|Cotes synchronisees avec les bulletins et le jury.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        itm_log_error('Gestion notes sync failed: ' . $e->getMessage());
        $message = "error|" . $e->getMessage();
    }
}

// Récupération des promotions
$promotions = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll();
$apprenants = [];
if ($promo_id) {
    $stmt_app = $pdo->prepare("SELECT id, nom, post_nom, prenom FROM apprenants WHERE promotion_id = ? ORDER BY nom ASC");
    $stmt_app->execute([$promo_id]);
    $apprenants = $stmt_app->fetchAll();
}

$notes_detaillees = [];
$apprenant_selectionne = null;
if ($apprenant_id) {
    $stmt_a = $pdo->prepare("SELECT a.*, p.nom as promo_nom FROM apprenants a JOIN promotions p ON a.promotion_id = p.id WHERE a.id = ?");
    $stmt_a->execute([$apprenant_id]);
    $apprenant_selectionne = $stmt_a->fetch();

    // Requête SQL corrigée pour pivoter les données (transformer 2 lignes de notes en colonnes)
    $sql = "SELECT c.id as cours_id, c.nom as cours_nom, c.coefficient as pond, c.semestre, u.nom as prof_nom,
                   MAX(CASE WHEN n.type_note = 'examen' THEN n.valeur END) as val_exe,
                   MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) as val_cote,
                   MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) as val_cote_s1,
                   MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) as val_cote_s2,
                   MAX(CASE WHEN n.type_note = 'moyenne' THEN n.valeur END) as val_moyenne
            FROM cours c
            JOIN utilisateurs u ON c.enseignant_id = u.id
            LEFT JOIN notes n ON c.id = n.cours_id AND n.apprenant_id = ?
            WHERE c.promotion_id = ? 
            GROUP BY c.id, c.nom, c.coefficient, c.semestre, u.nom
            ORDER BY c.nom ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$apprenant_id, $promo_id]);
    $notes_detaillees = $stmt->fetchAll();

    foreach ($notes_detaillees as $row) {
        $row->_period_inputs = [];
        $row->_period_values = [];
        $row->_period_maxima = [];
        $row->_effective_max = 0.0;
        $row->_display_points = 0.0;
        $row->_has_grade = false;
        $row->cote = $row->val_cote;
        $row->cote_s1 = $row->val_cote_s1;
        $row->cote_s2 = $row->val_cote_s2;
        $row->examen = $row->val_exe;
        $row->moyenne = $row->val_moyenne;
        $row->_period_factor = $row->semestre === 'ANNUEL' ? 0.5 : 1.0;

        foreach (itm_allowed_periods_for_course($row->semestre) as $period) {
            $direct = $period === 'S2' ? $row->val_cote_s2 : $row->val_cote_s1;
            if ($direct === null && $row->semestre !== 'ANNUEL') {
                $direct = $row->val_cote;
            } elseif ($direct === null && $row->semestre === 'ANNUEL' && $period === 'S1' && $row->val_cote_s2 === null) {
                $direct = $row->val_cote;
            }

            $periodPoints = itm_obtained_points($direct, $row->val_moyenne, $row->val_exe, $row->pond);
            $row->_period_inputs[$period] = $direct !== null ? (float)$direct : ($row->val_exe !== null ? $periodPoints : null);
            $row->_period_values[$period] = $periodPoints;
            $row->_period_maxima[$period] = (float)$row->pond;
            $row->_effective_max += (float)$row->pond;
            $row->_display_points += $periodPoints;
            $row->_has_grade = $row->_has_grade || $direct !== null || $row->val_exe !== null;
        }

        $row->_effective_max = itm_effective_maxima($row->semestre, $row->pond, 'FINAL');
        $row->_display_points = itm_points_for_period($row, $row->semestre, $row->pond, 'FINAL');
        $row->_has_grade = itm_has_grade_for_period($row, $row->semestre, 'FINAL');
        $row->_is_fail = $row->_has_grade && $row->_display_points < ($row->_effective_max / 2);
        $row->_status_order = $row->_is_fail ? 0 : ($row->_has_grade ? 2 : 1);
    }

    usort($notes_detaillees, function ($a, $b) {
        if ($a->_status_order !== $b->_status_order) {
            return $a->_status_order <=> $b->_status_order;
        }
        return strcasecmp($a->cours_nom, $b->cours_nom);
    });
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITMCD.</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <style>
        :root {
            --bg: #050508;
            --accent: #2563eb;
            --accent-glow: rgba(37, 99, 235, 0.3);
            --accent-2: #0891b2;
            --card: rgba(15, 15, 25, 0.8);
            --border: rgba(255, 255, 255, 0.08);
            --text-dim: #94a3b8;
            --success: #00ffaa;
            --danger: #ff4d4d;
        }

        body {
            background-color: var(--bg);
            color: #ffffff;
            font-family: 'Outfit', sans-serif;
            background-image: 
                radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.16) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(8, 145, 178, 0.12) 0px, transparent 50%);
            min-height: 100vh;
            padding-bottom: 120px;
            overflow-x: hidden;
        }

        /* --- Nouvel Header --- */
        .glass-nav {
            background: rgba(15, 15, 25, 0.7);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1100;
        }

        .btn-back {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: #fff;
            padding: 8px 20px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.3s;
        }

        .btn-back:hover {
            background: var(--accent);
            color: white;
            transform: translateX(-5px);
        }

        .bento-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            backdrop-filter: blur(12px);
            transition: all 0.3s ease;
            height: 100%;
        }

        .bento-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .stat-circle {
            width: 70px; height: 70px;
            border-radius: 50%;
            border: 3px solid var(--accent-glow);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 1.1rem;
            box-shadow: 0 0 15px var(--accent-glow);
            flex-shrink: 0;
            transition: 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .grade-control {
            background: rgba(0,0,0,0.4) !important;
            border: 2px solid var(--border) !important;
            color: #fff !important;
            border-radius: 12px !important;
            width: 100% !important;
            max-width: 90px;
            text-align: center;
            font-family: 'JetBrains Mono', monospace;
            padding: 8px !important;
            transition: 0.3s;
        }

        .grade-control:focus {
            border-color: var(--accent) !important;
            box-shadow: 0 0 15px var(--accent-glow) !important;
            outline: none;
        }

        .grade-control.fail { color: var(--danger) !important; border-color: rgba(255, 77, 77, 0.3) !important; }
        .grade-control.pass { color: var(--success) !important; border-color: rgba(0, 255, 170, 0.3) !important; }

        .progress-mini {
            height: 6px;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            margin-top: 8px;
            overflow: hidden;
            width: 100%;
        }
        .progress-inner {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            width: 0%;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .course-status {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 800;
            margin-top: 8px;
            border: 1px solid var(--border);
        }

        .course-status.fail { color: var(--danger); background: rgba(255, 77, 77, 0.12); border-color: rgba(255, 77, 77, 0.28); }
        .course-status.pass { color: var(--success); background: rgba(0, 255, 170, 0.1); border-color: rgba(0, 255, 170, 0.25); }
        .course-status.pending { color: #facc15; background: rgba(250, 204, 21, 0.1); border-color: rgba(250, 204, 21, 0.25); }

        .sync-alert {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            overflow-wrap: anywhere;
        }

        .sync-alert.success {
            color: var(--success);
            background: rgba(0, 255, 170, 0.1);
            border-color: rgba(0, 255, 170, 0.25);
        }

        .sync-alert.error {
            color: var(--danger);
            background: rgba(255, 77, 77, 0.1);
            border-color: rgba(255, 77, 77, 0.28);
        }

        .period-grade-grid {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .period-grade-field {
            display: grid;
            gap: 6px;
            justify-items: center;
            min-width: 86px;
            margin: 0;
        }

        .period-grade-field span {
            color: var(--text-dim);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0;
        }

        .q-table-container { width: 100%; }
        
        @media (max-width: 768px) {
            .q-table thead { display: none; }
            .grade-row {
                display: flex;
                flex-direction: column;
                background: rgba(255,255,255,0.03);
                margin-bottom: 15px;
                padding: 20px !important;
                border-radius: 20px !important;
                border: 1px solid var(--border);
            }
            .grade-row td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0 !important;
                border: none !important;
                width: 100% !important;
                text-align: left !important;
            }
            .grade-row td:first-child { flex-direction: column; align-items: flex-start; }
            .grade-row td:nth-child(2)::before { content: "Cote"; color: var(--text-dim); font-size: 0.8rem; }
            .grade-row td:nth-child(3)::before { content: "Maxima"; color: var(--text-dim); font-size: 0.8rem; }
            .grade-row td:nth-child(4)::before { content: "Statut"; color: var(--text-dim); font-size: 0.8rem; }
            .period-grade-grid { justify-content: flex-end; }
        }

        @media (min-width: 769px) {
            .q-table { width: 100%; border-collapse: separate; border-spacing: 0 12px; }
            .q-table tr { background: rgba(255,255,255,0.02); transition: 0.3s; }
            .q-table tr:hover { background: rgba(255,255,255,0.05); }
            .q-table td { padding: 1.2rem; vertical-align: middle; }
            .q-table td:first-child { border-radius: 20px 0 0 20px; }
            .q-table td:last-child { border-radius: 0 20px 20px 0; text-align: right; }
        }

        .save-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            left: 20px;
            background: #fff;
            color: #000;
            border: none;
            padding: 16px;
            border-radius: 18px;
            font-weight: 800;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            transition: 0.3s;
            z-index: 1000;
        }

        @media (min-width: 768px) {
            .save-btn { width: auto; left: auto; padding: 16px 35px; bottom: 30px; right: 30px; }
        }

        .save-btn:hover {
            transform: translateY(-5px) scale(1.05);
            background: var(--accent);
            color: #fff;
        }

        /* Animations d'entrée */
        .grade-row {
            animation: fadeInRight 0.5s ease backwards;
        }
    </style>
</head>
<body>

    <nav class="glass-nav mb-4">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="index.php" class="btn-back">
                <span class="material-symbols-outlined">arrow_back</span>
                Retour
            </a>
            <div class="fw-800 text-uppercase small tracking-widest" style="letter-spacing: 2px;">
                <span class="text-dim">Gestion des </span> <span style="color:var(--accent)">Côtes</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row g-3 mb-4 animate__animated animate__fadeIn">
            <div class="col-md-8">
                <div class="bento-card p-4 d-flex flex-column justify-content-center">
                    <h1 class="fw-800 h2 mb-1">Gestion <span style="color:var(--accent)">Scolaire</span></h1>
                    <p class="text-dim small mb-0">Saisie optimisée • ITMCD.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bento-card p-3 d-flex align-items-center gap-3">
                    <div class="stat-circle" id="global-perc">0%</div>
                    <div>
                        <div class="small text-dim text-uppercase fw-600" style="font-size: 0.7rem;">Moyenne Générale</div>
                        <div class="h5 fw-800 mb-0" id="total-global">00.0 / 00</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): [$message_type, $message_text] = array_pad(explode('|', $message, 2), 2, 'Operation terminee.'); ?>
            <div class="sync-alert <?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?>">
                <span class="material-symbols-outlined"><?= $message_type === 'success' ? 'check_circle' : 'error' ?></span>
                <span><?= htmlspecialchars($message_text, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-6 animate__animated animate__fadeInLeft">
                <div class="bento-card p-3">
                    <label class="small text-accent fw-800 text-uppercase mb-2 d-block">1. Promotion</label>
                    <form method="GET">
                        <select name="promo_id" class="form-select grade-control w-100" onchange="this.form.submit()" style="max-width:none; text-align: left !important;">
                            <option value="">Sélectionner une classe</option>
                            <?php foreach($promotions as $p): ?>
                                <option value="<?= $p->id ?>" <?= $promo_id == $p->id ? 'selected' : '' ?>><?= $p->nom ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="col-md-6 animate__animated animate__fadeInRight">
                <div class="bento-card p-3 <?= !$promo_id ? 'opacity-25' : '' ?>">
                    <label class="small text-accent fw-800 text-uppercase mb-2 d-block">2. Apprenant</label>
                    <form method="GET">
                        <input type="hidden" name="promo_id" value="<?= $promo_id ?>">
                        <select name="apprenant_id" class="form-select grade-control w-100" onchange="this.form.submit()" <?= !$promo_id ? 'disabled' : '' ?> style="max-width:none; text-align: left !important;">
                            <option value="">Élèves (<?= count($apprenants) ?>)</option>
                            <?php foreach($apprenants as $app): ?>
                                <option value="<?= $app->id ?>" <?= $apprenant_id == $app->id ? 'selected' : '' ?>>
                                    <?= strtoupper($app->nom) ?> <?= $app->prenom ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <?php if($apprenant_selectionne): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="q-table-container">
                <table class="q-table">
                    <thead>
                        <tr>
                            <th class="text-dim small fw-600">ÉLÉMENT</th>
                            <th class="text-center text-dim small fw-600">COTE(S) OBTENUE(S)</th>
                            <th class="text-center text-dim small fw-600">MAXIMA</th>
                            <th class="text-end text-dim small fw-600">STATUT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 0; foreach($notes_detaillees as $row): $i++; ?>
                        <tr class="grade-row" style="animation-delay: <?= $i * 0.05 ?>s">
                            <td>
                                <div class="fw-600 h6 mb-0"><?= $row->cours_nom ?></div>
                                <div class="small text-dim" style="font-size: 0.75rem;">Prof. <?= $row->prof_nom ?> • <?= $row->semestre ?> • Maxima <?= itm_format_points($row->_effective_max) ?></div>
                                <?php if ($row->val_exe !== null): ?>
                                    <div class="small text-dim" style="font-size: 0.7rem;">
                                        Ancien Examen: <?= $row->val_exe ?>/10
                                    </div>
                                <?php endif; ?>
                                <div class="progress-mini"><div class="progress-inner"></div></div>
                            </td>
                            <td class="text-center">
                                <div class="period-grade-grid">
                                    <?php foreach ($row->_period_inputs as $period => $period_value): ?>
                                        <label class="period-grade-field">
                                            <span><?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="number"
                                                   step="0.1"
                                                   max="<?= itm_format_points($row->_period_maxima[$period]) ?>"
                                                   min="0"
                                                   name="notes[<?= (int)$row->cours_id ?>][<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>]"
                                                   class="grade-control val-cote"
                                                   value="<?= $period_value !== null ? itm_format_points($period_value) : '' ?>"
                                                   data-period="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>"
                                                   oninput="recalculate(this)">
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="fw-800"><?= itm_format_points($row->_effective_max) ?></span>
                            </td>
                            <td class="text-end">
                                <div class="fw-800 mb-0"><span class="row-total">0</span><span class="text-dim" style="font-size: 0.7rem;">%</span></div>
                                <div class="small text-accent fw-600" style="font-size: 0.7rem;"><span class="row-weighted">0</span> pts</div>
                            </td>
                            <input type="hidden" class="coeff-val" value="<?= itm_format_points($row->_effective_max) ?>">
                            <input type="hidden" class="period-factor" value="<?= itm_format_points($row->_period_factor) ?>">
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" name="save_notes" class="save-btn animate__animated animate__bounceInUp">
                <span class="material-symbols-outlined">sync</span>
                SYNCHRONISER LES NOTES
            </button>
        </form>
        <?php else: ?>
        <div class="bento-card p-5 text-center animate__animated animate__fadeInUp">
            <div class="stat-circle mx-auto mb-3" style="width: 80px; height: 80px; opacity: 0.3;">?</div>
            <h5 class="fw-800">En attente de sélection</h5>
            <p class="text-dim small">Choisissez une promotion et un apprenant pour gérer les notes.</p>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function recalculate(el) {
            const row = el.closest('.grade-row');
            let cote = 0;
            const factor = parseFloat(row.querySelector('.period-factor')?.value) || 1;
            row.querySelectorAll('.val-cote').forEach(input => {
                const raw = String(input.value || '').trim();
                if (raw !== '') cote += (parseFloat(raw.replace(',', '.')) || 0) * factor;
            });
            const coeff = parseFloat(row.querySelector('.coeff-val').value) || 1;

            const perc = coeff > 0 ? (cote / coeff) * 100 : 0;
            row.querySelector('.row-total').innerText = perc.toFixed(1);
            row.querySelector('.row-weighted').innerText = cote.toFixed(1);

            row.querySelector('.progress-inner').style.width = perc + '%';

            row.querySelectorAll('.val-cote').forEach(input => {
                const individual = parseFloat(String(input.value || '').replace(',', '.'));
                const inputMax = parseFloat(input.max) || coeff;
                const filled = Number.isFinite(individual);
                input.classList.toggle('fail', filled && individual < (inputMax / 2));
                input.classList.toggle('pass', filled && individual >= (inputMax / 2));
            });

            updateGlobalStats();
        }

        function updateGlobalStats() {
            let totalObtenu = 0;
            let totalMax = 0;
            
            document.querySelectorAll('.grade-row').forEach(row => {
                let t = 0;
                const factor = parseFloat(row.querySelector('.period-factor')?.value) || 1;
                row.querySelectorAll('.val-cote').forEach(input => {
                    const raw = String(input.value || '').trim();
                    if (raw !== '') t += (parseFloat(raw.replace(',', '.')) || 0) * factor;
                });
                const c = parseFloat(row.querySelector('.coeff-val').value) || 0;
                totalObtenu += t; 
                totalMax += c;
            });

            const globalPerc = totalMax > 0 ? (totalObtenu / totalMax) * 100 : 0;
            
            document.getElementById('total-global').innerText = totalObtenu.toFixed(1) + ' / ' + totalMax;
            document.getElementById('global-perc').innerText = Math.round(globalPerc) + '%';
            
            const circle = document.querySelector('.stat-circle');
            if(globalPerc < 50) circle.style.borderColor = 'var(--danger)';
            else if(globalPerc < 75) circle.style.borderColor = 'var(--accent)';
            else circle.style.borderColor = 'var(--success)';
        }

        // Initialisation au chargement
        window.addEventListener('load', () => {
            document.querySelectorAll('.val-cote').forEach(el => recalculate(el));
        });
    </script>
</body>
</html>
