<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$error = "";
$status = $_GET['status'] ?? "";
$edit_mode = false;
$edit_cours = null;

if (isset($_GET['delete'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals(csrf_token(), $_GET['csrf_token'])) {
        http_response_code(419);
        die('Session expiree. Veuillez recharger la page.');
    }
    $pdo->prepare("DELETE FROM cours WHERE id=?")->execute([$_GET['delete']]);
    header("Location: gestion_cours.php?status=deleted");
    exit();
}

if (isset($_GET['edit'])) {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT * FROM cours WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit_cours = $stmt->fetch();
}

if (isset($_POST['save_cours'])) {
    require_csrf();
    $nom = strtoupper(trim($_POST['nom']));
    $coef = (int)$_POST['coefficient'];
    $promo = (int)$_POST['promotion_id'];
    $prof = (int)$_POST['enseignant_id'];
    $semestre = $_POST['semestre'];
    $id = $_POST['id'] ?? null;

    $check = $pdo->prepare("SELECT id FROM cours WHERE nom = ? AND promotion_id = ? AND id != ?");
    $check->execute([$nom, $promo, $id ?? 0]);
    
    if ($coef < 1 || $coef > 2000) {
        $error = "La pondération doit être comprise entre 1 et 2000.";
    } elseif ($check->fetch()) {
        $error = "Cet élément est déjà attribué à cette promotion.";
    } else {
        if ($id) {
            $pdo->prepare("UPDATE cours SET nom=?, coefficient=?, promotion_id=?, enseignant_id=?, semestre=? WHERE id=?")
                ->execute([$nom, $coef, $promo, $prof, $semestre, $id]);
            header("Location: gestion_cours.php?status=updated");
        } else {
            $pdo->prepare("INSERT INTO cours (nom, coefficient, promotion_id, enseignant_id, semestre) VALUES (?, ?, ?, ?, ?)")
                ->execute([$nom, $coef, $promo, $prof, $semestre]);
            header("Location: gestion_cours.php?status=success");
        }
        exit();
    }
}

$promos = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll();
$profs = $pdo->query("SELECT * FROM utilisateurs WHERE role='enseignant' ORDER BY nom ASC")->fetchAll();
$cours = $pdo->query("
    SELECT c.*, p.nom as promo, u.nom as prof 
    FROM cours c 
    JOIN promotions p ON c.promotion_id=p.id 
    JOIN utilisateurs u ON c.enseignant_id=u.id 
    ORDER BY p.nom, FIELD(c.semestre, 'S1', 'S2', 'ANNUEL'), c.nom
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    
    <style>
        :root {
            --page: #eef3f8;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --line: #dbe5f0;
            --ink: #132033;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-soft: #e8f0fe;
            --success: #15803d;
            --success-soft: #e8f8ef;
            --danger: #b91c1c;
            --danger-soft: #fef2f2;
            --shadow: 0 18px 42px rgba(15, 23, 42, .08);
        }

        * { box-sizing: border-box; }

        body {
            background: var(--page);
            color: var(--ink);
            font-family: 'Inter', sans-serif;
            margin: 0;
        }

        header {
            background: rgba(255,255,255,.95);
            border-bottom: 1px solid var(--line);
            padding: 14px clamp(14px, 3vw, 24px);
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(14px);
            box-shadow: 0 10px 28px rgba(15,23,42,.07);
        }

        .brand-link {
            color: var(--ink);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
        }

        .admin-container {
            display: grid;
            grid-template-columns: minmax(300px, 370px) minmax(0, 1fr);
            gap: 18px;
            padding: clamp(12px, 3vw, 24px);
            max-width: 1500px;
            margin: auto;
        }

        .premium-card,
        .table-area {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .premium-card {
            padding: clamp(16px, 3vw, 22px);
            height: fit-content;
        }

        .form-section-title {
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            color: var(--primary);
            letter-spacing: 0;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section-title::after {
            content: "";
            height: 1px;
            background: var(--line);
            flex-grow: 1;
        }

        .input-field {
            background: var(--surface-soft) !important;
            border: 1px solid var(--line) !important;
            border-radius: 8px !important;
            color: var(--ink) !important;
            min-height: 42px;
            padding: 10px 12px !important;
            width: 100%;
            margin-bottom: 14px;
            font-weight: 650;
        }

        .input-field:focus {
            outline: 2px solid rgba(37, 99, 235, .18);
            border-color: var(--primary) !important;
            background: #fff !important;
        }

        .custom-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
            display: block;
            font-weight: 800;
        }

        .btn-action {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            min-height: 44px;
            padding: 11px 14px;
            font-weight: 800;
            width: 100%;
        }

        .table-area { overflow: hidden; }

        .table-header-custom {
            padding: 16px 18px;
            background: var(--surface-soft);
            border-bottom: 1px solid var(--line);
        }

        .text-dim { color: var(--muted) !important; }
        .text-accent { color: var(--primary) !important; }

        .pro-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pro-table th {
            padding: 12px 14px;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            text-align: left;
            background: #f1f5f9;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        .pro-table td {
            padding: 13px 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            color: var(--ink);
        }

        .pro-table tbody tr:hover { background: #fbfdff; }
        .pro-table tbody tr:last-child td { border-bottom: 0; }

        .course-title {
            font-weight: 850;
            overflow-wrap: anywhere;
            line-height: 1.25;
        }

        .tag-promo,
        .tag-semestre {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            min-height: 26px;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: 850;
            font-size: 12px;
            line-height: 1;
        }

        .tag-promo {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .tag-semestre {
            background: var(--success-soft);
            color: var(--success);
        }

        .avatar-prof {
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
            background: #eef2ff;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 900;
            color: var(--primary);
        }

        .row-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .icon-action {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .icon-action.edit { color: var(--primary); }
        .icon-action.delete { color: var(--danger); background: var(--danger-soft); border-color: #fecaca; }

        @media (max-width: 1200px) {
            .admin-container { grid-template-columns: 1fr; }
        }

        @media (max-width: 760px) {
            header { padding: 12px; }
            .admin-container { padding: 10px; gap: 12px; }
            .premium-card,
            .table-area { box-shadow: 0 10px 28px rgba(15, 23, 42, .07); }
            .table-header-custom {
                padding: 14px;
                align-items: flex-start !important;
                gap: 10px;
            }
            .table-responsive { overflow: visible; }
            .pro-table,
            .pro-table tbody,
            .pro-table tr,
            .pro-table td { display: block; width: 100%; }
            .pro-table thead { display: none; }
            .pro-table tbody { padding: 10px; background: var(--page); }
            .pro-table tr {
                background: #fff;
                border: 1px solid var(--line);
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 10px;
                box-shadow: 0 8px 22px rgba(15, 23, 42, .05);
            }
            .pro-table td {
                display: grid;
                grid-template-columns: 94px minmax(0, 1fr);
                align-items: center;
                gap: 12px;
                padding: 8px 0;
                border: 0;
                font-size: 13px;
            }
            .pro-table td::before {
                content: attr(data-label);
                color: var(--muted);
                font-size: 11px;
                font-weight: 900;
                text-transform: uppercase;
            }
            .pro-table td[data-label="Élément"] {
                grid-template-columns: 1fr;
                padding-top: 0;
            }
            .pro-table td[data-label="Élément"]::before { display: none; }
            .pro-table td.actions-cell {
                grid-template-columns: 1fr;
                border-top: 1px dashed var(--line);
                margin-top: 8px;
                padding-top: 12px;
            }
            .pro-table td.actions-cell::before { display: none; }
            .row-actions { justify-content: stretch; }
            .icon-action { flex: 1; height: 40px; }
        }
    </style>
</head>
<body>

<header class="d-flex justify-content-between align-items-center">
    <a href="index.php" class="brand-link">
        <span class="material-symbols-rounded">arrow_back</span>
        <span class="d-none d-sm-inline">Retour au menu</span>
    </a>
    <div style="width: 32px; height: 32px; background: var(--primary); color: #fff; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem;">AC.</div>
</header>

<div class="admin-container">
    <div class="premium-card">
        <div class="form-section-title">Configuration</div>
        
        <?php if($error): ?>
            <div class="alert alert-danger bg-danger bg-opacity-10 border-0 text-danger small mb-3"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $edit_cours->id ?? '' ?>">

            <label class="custom-label">Nom du cours ou événement</label>
            <input type="text" name="nom" class="input-field" value="<?= $edit_cours->nom ?? '' ?>" required placeholder="ex: BIOCHIMIE, STAGE, PRATIQUE">

            <div class="row g-2">
                <div class="col-6">
                    <label class="custom-label">Pondération / Maxima</label>
                    <input type="number" name="coefficient" class="input-field" min="1" max="2000" value="<?= $edit_cours->coefficient ?? '' ?>" required>
                </div>
                <div class="col-6">
                    <label class="custom-label">Semestre</label>
                    <select name="semestre" class="input-field">
                        <option value="S1" <?= isset($edit_cours)&&$edit_cours->semestre=='S1'?'selected':'' ?>>S1</option>
                        <option value="S2" <?= isset($edit_cours)&&$edit_cours->semestre=='S2'?'selected':'' ?>>S2</option>
                        <option value="ANNUEL" <?= isset($edit_cours)&&$edit_cours->semestre=='ANNUEL'?'selected':'' ?>>Annuel</option>
                    </select>
                </div>
            </div>

            <label class="custom-label">Promotion</label>
            <select name="promotion_id" class="input-field">
                <?php foreach($promos as $p): ?>
                <option value="<?= $p->id ?>" <?= isset($edit_cours)&&$edit_cours->promotion_id==$p->id?'selected':'' ?>>
                    <?= htmlspecialchars($p->nom) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label class="custom-label">Enseignant</label>
            <select name="enseignant_id" class="input-field">
                <?php foreach($profs as $pr): ?>
                <option value="<?= $pr->id ?>" <?= isset($edit_cours)&&$edit_cours->enseignant_id==$pr->id?'selected':'' ?>>
                    <?= htmlspecialchars($pr->nom) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="save_cours" class="btn-action mt-2">
                <?= $edit_mode ? 'Mettre à jour' : 'Enregistrer' ?>
            </button>

            <?php if($edit_mode): ?>
                <a href="gestion_cours.php" class="d-block text-center text-dim small mt-3 text-decoration-none">Annuler</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-area">
        <div class="table-header-custom d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0 fw-bold">Répertoire</h6>
                <p class="text-dim small mb-0 d-none d-sm-block">Cours, stages et autres évaluations</p>
            </div>
            <div class="badge bg-primary rounded-pill"><?= count($cours) ?> éléments</div>
        </div>

        <div class="table-responsive">
            <table class="pro-table">
                <thead>
                    <tr>
                        <th>Promotion</th>
                        <th>Intitulé</th>
                        <th>Période</th>
                        <th>Maxima</th>
                        <th>Enseignant</th>
                        <th class="text-end">Options</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($cours as $c): ?>
                    <tr>
                        <td data-label="Promotion"><span class="tag-promo"><?= htmlspecialchars($c->promo) ?></span></td>
                        <td data-label="Élément"><div class="course-title"><?= htmlspecialchars($c->nom) ?></div></td>
                        <td data-label="Période"><span class="tag-semestre"><?= $c->semestre ?></span></td>
                        <td data-label="Maxima" class="fw-bold text-accent"><?= itm_format_points($c->coefficient) ?></td>
                        <td data-label="Prof">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-prof"><?= htmlspecialchars(substr($c->prof, 0, 1)) ?></div>
                                <span class="small"><?= htmlspecialchars($c->prof) ?></span>
                            </div>
                        </td>
                        <td data-label="Options" class="actions-cell">
                            <div class="row-actions">
                                <a href="?edit=<?= $c->id ?>" class="icon-action edit" aria-label="Modifier <?= htmlspecialchars($c->nom) ?>">
                                    <span class="material-symbols-rounded" style="font-size: 1.1rem;">edit</span>
                                </a>
                                <a href="?delete=<?= $c->id ?>&csrf_token=<?= urlencode(csrf_token()) ?>" class="icon-action delete" aria-label="Supprimer <?= htmlspecialchars($c->nom) ?>" onclick="return confirm('Supprimer ?')">
                                    <span class="material-symbols-rounded" style="font-size: 1.1rem;">delete</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
