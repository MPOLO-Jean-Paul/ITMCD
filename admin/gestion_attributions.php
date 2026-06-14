<?php
session_start();
require_once '../config/db.php';
checkRole('admin');

$success = "";
$error = "";

// 1. LOGIQUE D'ATTRIBUTION
if (isset($_POST['attribuer'])) {
    require_csrf();
    $prof_id = $_POST['enseignant_id'];
    $cours_id = $_POST['cours_id'];

    try {
        $stmt = $pdo->prepare("INSERT INTO attributions (enseignant_id, cours_id) VALUES (?, ?)");
        $stmt->execute([$prof_id, $cours_id]);
        $success = "Le cours a été attribué avec succès !";
    } catch (PDOException $e) {
        $error = "Ce cours est déjà attribué à ce professeur.";
    }
}

// 2. RÉCUPÉRATION DES DONNÉES
$profs = $pdo->query("SELECT id, nom FROM utilisateurs WHERE role = 'enseignant' ORDER BY nom")->fetchAll();
// On récupère les cours avec le nom de leur promotion
$cours = $pdo->query("SELECT c.id, c.nom, p.nom as promo FROM cours c JOIN promotions p ON c.promotion_id = p.id ORDER BY p.nom, c.nom")->fetchAll();

// Liste des attributions actuelles
$sql_list = "SELECT a.id, u.nom as prof_nom, c.nom as cours_nom, p.nom as promo_nom 
             FROM attributions a
             JOIN utilisateurs u ON a.enseignant_id = u.id
             JOIN cours c ON a.cours_id = c.id
             JOIN promotions p ON c.promotion_id = p.id
             ORDER BY u.nom";
$attributions = $pdo->query($sql_list)->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4 shadow">
        <div class="container">
            <a href="index.php" class="btn btn-outline-light btn-sm">🏠 Dashboard</a>
            <span class="navbar-brand">Assignation des Enseignants</span>
        </div>
    </nav>

    <div class="container">
        <?php if($success): ?> <div class="alert alert-success"><?= $success ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="alert alert-danger"><?= $error ?></div> <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white fw-bold">🔗 Nouvelle Attribution</div>
                    <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Enseignant</label>
                                <select name="enseignant_id" class="form-select" required>
                                    <?php foreach($profs as $pr): ?>
                                        <option value="<?= $pr->id ?>"><?= htmlspecialchars($pr->nom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Cours & Promotion</label>
                                <select name="cours_id" class="form-select" required>
                                    <?php foreach($cours as $co): ?>
                                        <option value="<?= $co->id ?>">
                                            <?= htmlspecialchars($co->nom) ?> (<?= htmlspecialchars($co->promo) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="attribuer" class="btn btn-primary w-100 fw-bold">Lier le professeur au cours</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-bold">Récapitulatif des Charges Horaires</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Professeur</th>
                                    <th>Cours</th>
                                    <th>Promotion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($attributions as $at): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($at->prof_nom) ?></td>
                                    <td><?= htmlspecialchars($at->cours_nom) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($at->promo_nom) ?></span></td>
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
