<?php
session_start();
require_once '../config/db.php';
checkRole('admin');

$message = "";
$note_id = isset($_GET['id']) ? intval($_GET['id']) : null;

// 1. RÉCUPÉRATION DE LA NOTE ACTUELLE
$stmt = $pdo->prepare("
    SELECT n.*, a.nom, a.prenom, c.nom as cours_nom, c.coefficient
    FROM notes n 
    JOIN apprenants a ON n.apprenant_id = a.id 
    JOIN cours c ON n.cours_id = c.id 
    WHERE n.id = ?
");
$stmt->execute([$note_id]);
$note_data = $stmt->fetch();

if (!$note_data) {
    die("Note introuvable.");
}

// 2. TRAITEMENT DE LA MODIFICATION
if (isset($_POST['update_note'])) {
    require_csrf();
    $nouvelle_valeur = floatval($_POST['valeur']);
    
    $max_autorise = ($note_data->type_note === 'cote') ? (float)$note_data->coefficient : 10;
    if ($nouvelle_valeur >= 0 && $nouvelle_valeur <= $max_autorise) {
        $update = $pdo->prepare("UPDATE notes SET valeur = ? WHERE id = ?");
        $update->execute([$nouvelle_valeur, $note_id]);
        $message = "<div class='alert alert-success'>✅ Note mise à jour avec succès. <a href='gestion_notes.php'>Retour à la liste</a></div>";
        // Rafraîchir les données affichées
        $note_data->valeur = $nouvelle_valeur;
    } else {
        $message = "<div class='alert alert-danger'>❌ La note doit être comprise entre 0 et 20.</div>";
    }
}
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
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Correction d'une note</h5>
                    </div>
                    <div class="card-body">
                        <?= $message ?>
                        <p><strong>Apprenant :</strong> <?= htmlspecialchars($note_data->nom . ' ' . $note_data->prenom) ?></p>
                        <p><strong>Cours :</strong> <?= htmlspecialchars($note_data->cours_nom) ?></p>
                        
                        <form method="POST">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Nouvelle Note (/20)</label>
                                <input type="number" step="0.1" name="valeur" class="form-control form-control-lg" 
                                       value="<?= $note_data->valeur ?>" min="0" max="<?= ($note_data->type_note === 'cote') ? $note_data->coefficient : 10 ?>" required>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="gestion_notes.php" class="btn btn-secondary">Annuler</a>
                                <button type="submit" name="update_note" class="btn btn-warning fw-bold">Enregistrer le changement</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
