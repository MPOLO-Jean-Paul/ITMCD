<?php
session_start(); 
require_once '../config/db.php';
checkRole('admin'); 

if (!isset($_GET['id'])) { header("Location: liste_globale.php"); exit(); }
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM apprenants WHERE id = ?");
$stmt->execute([$id]);
$apprenant = $stmt->fetch();
if (!$apprenant) { die("Introuvable"); }

if (isset($_POST['update_student'])) {
    require_csrf();
    $update = $pdo->prepare("UPDATE apprenants SET nom=?, post_nom=?, prenom=?, promotion_id=? WHERE id=?");
    $update->execute([$_POST['nom'], $_POST['post_nom'], $_POST['prenom'], $_POST['promotion_id'], $id]);
    header("Location: liste_globale.php?status=ok");
    exit();
}

$promotions = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll();
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f1f5f9; font-family: 'Inter', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { border: none; border-radius: 16px; width: 100%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .form-label { font-weight: 600; font-size: 0.85rem; color: #64748b; text-uppercase: uppercase; }
        .btn-primary { background: #2563eb; border: none; padding: 12px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>

    <div class="card p-2">
        <div class="card-body">
            <div class="text-center mb-4">
                <div class="bg-warning d-inline-block p-3 rounded-circle mb-3"><i class="bi bi-pencil-fill fs-4"></i></div>
                <h4 class="fw-bold">Édition Apprenant</h4>
                <p class="text-muted">Code : <span class="badge bg-dark"><?= $apprenant->code_unique ?></span></p>
            </div>

            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($apprenant->nom) ?>" required>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Post-Nom</label>
                        <input type="text" name="post_nom" class="form-control" value="<?= htmlspecialchars($apprenant->post_nom) ?>" required>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Prénom</label>
                        <input type="text" name="prenom" class="form-control" value="<?= htmlspecialchars($apprenant->prenom) ?>" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Promotion</label>
                    <select name="promotion_id" class="form-select" required>
                        <?php foreach($promotions as $p): ?>
                            <option value="<?= $p->id ?>" <?= ($p->id == $apprenant->promotion_id) ? 'selected' : '' ?>><?= htmlspecialchars($p->nom) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" name="update_student" class="btn btn-primary">Mettre à jour</button>
                    <a href="liste_globale.php" class="btn btn-light text-muted">Annuler</a>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
