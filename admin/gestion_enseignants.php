<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";
$edit_mode = false;
$edit_prof = null;

/* SUPPRESSION */
if (isset($_GET['delete'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals(csrf_token(), $_GET['csrf_token'])) {
        http_response_code(419);
        die('Session expiree. Veuillez recharger la page.');
    }
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM utilisateurs WHERE id=? AND role='enseignant'")->execute([$id]);
    header("Location: gestion_enseignants.php");
    exit();
}

/* CHARGEMENT POUR MODIFICATION */
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=? AND role='enseignant'");
    $stmt->execute([(int)$_GET['edit']]);
    $edit_prof = $stmt->fetch(PDO::FETCH_OBJ); // Ajout de FETCH_OBJ pour la cohérence
}

/* AJOUT / MODIFICATION */
if (isset($_POST['save_prof'])) {
    require_csrf();
    $nom = strtoupper(trim($_POST['nom']));
    $email = trim($_POST['email']);
    $id = $_POST['id'] ?? null;

    try {
        if ($id) {
            /* MODIFICATION */
            if (!empty($_POST['password'])) {
                $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE utilisateurs SET nom=?, email=?, mot_de_passe=? WHERE id=?")
                    ->execute([$nom, $email, $pass, $id]);
            } else {
                $pdo->prepare("UPDATE utilisateurs SET nom=?, email=? WHERE id=?")
                    ->execute([$nom, $email, $id]);
            }
            $message = "success|Informations mises à jour.";
        } else {
            /* AJOUT */
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO utilisateurs (nom,email,mot_de_passe,role) VALUES (?,?,?,'enseignant')")
                ->execute([$nom, $email, $pass]);
            $message = "success|Compte enseignant créé.";
        }
    } catch (PDOException $e) {
        $message = "danger|Email déjà utilisé.";
    }
}

$enseignants = $pdo->query("SELECT * FROM utilisateurs WHERE role='enseignant' ORDER BY nom")->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>ITMCD</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="../images/LOGO.png">
<?= app_shell_assets('../') ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet">

<style>
:root{
--bg:#0f172a;
--card:#1e293b;
--text:#f8fafc;
--muted:#94a3b8;
--border:rgba(255,255,255,.08);
--blue:#1a73e8;
--green:#4ade80;
--red:#ef4444;
--radius:20px;
}

*{box-sizing:border-box}
body{
margin:0;
font-family:Inter,system-ui;
background:linear-gradient(180deg,#020617,#0f172a);
color:var(--text);
min-height: 100vh;
}

header{
position:sticky;
top:0;
z-index:100;
padding:15px 20px;
display:flex;
justify-content:space-between;
align-items:center;
background:rgba(2,6,23,.85);
backdrop-filter:blur(14px);
border-bottom:1px solid var(--border);
}

header a{
color:white;
text-decoration:none;
display:flex;
gap:8px;
align-items:center;
font-size: 0.9rem;
}

.container{
max-width:1200px;
margin:auto;
padding:20px;
display:grid;
gap:25px;
/* Par défaut : une seule colonne (mobile) */
grid-template-columns: 1fr;
}

/* Sur tablette et desktop : deux colonnes */
@media (min-width: 992px) {
    .container {
        grid-template-columns: 380px 1fr;
        padding: 30px;
    }
}

.card{
background:var(--card);
border:1px solid var(--border);
border-radius:var(--radius);
padding:20px;
height: fit-content;
}

h3{
margin:0 0 20px;
font-size:1rem;
display:flex;
gap:10px;
align-items:center;
color:var(--blue);
}

label{
font-size:.7rem;
color:var(--muted);
text-transform:uppercase;
letter-spacing:.6px;
margin-bottom:6px;
display:block;
}

input{
width:100%;
padding:14px;
border-radius:14px;
border:1px solid var(--border);
background:#020617;
color:white;
font-size: 1rem;
transition: all 0.2s;
}

input:focus{
outline:none;
border-color:var(--blue);
box-shadow:0 0 0 4px rgba(26,115,232,.18);
}

button{
margin-top:10px;
width:100%;
padding:16px;
border:none;
border-radius:16px;
background:linear-gradient(135deg,#1a73e8,#38bdf8);
color: white;
font-weight: 700;
font-size: 0.95rem;
cursor:pointer;
transition: opacity 0.2s;
}

button:hover { opacity: 0.9; }

.alert{
padding:14px 18px;
border-radius:14px;
margin-bottom:20px;
font-weight:600;
font-size: 0.85rem;
}
.alert-success{background:rgba(74,222,128,.15);color:var(--green)}
.alert-danger{background:rgba(239,68,68,.2);color:#fecaca}

/* TABLE RESPONSIVE */
.table-wrapper {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow-x: auto; /* Permet le défilement horizontal sur petit écran */
}

.table{
width:100%;
border-collapse:collapse;
min-width: 500px; /* Assure que la table reste lisible */
}

th,td{
padding:16px;
border-bottom:1px solid rgba(255,255,255,.05);
}

th{
font-size:.7rem;
letter-spacing:1px;
text-transform:uppercase;
color:var(--muted);
text-align:left;
}

.actions{
display:flex;
gap:15px;
justify-content:flex-end;
}

.icon-btn{
text-decoration: none;
background:transparent;
display: flex;
align-items: center;
justify-content: center;
transition: transform 0.2s;
}

.icon-btn:hover { transform: scale(1.1); }
.icon-btn.edit{color:var(--blue)}
.icon-btn.delete{color:var(--red)}

.avatar{
width:32px;height:32px;
border-radius:50%;
background:linear-gradient(135deg,#1a73e8,#38bdf8);
display:flex;
align-items:center;
justify-content:center;
font-weight:700;
color:#020617;
font-size: 0.8rem;
flex-shrink: 0;
}

strong { font-size: 0.9rem; }
.email-text { font-size: 0.85rem; color: var(--muted); }

/* Ajustement pour très petits écrans */
@media (max-width: 480px) {
    header strong { font-size: 0.9rem; }
    .container { padding: 15px; }
    .card { padding: 15px; }
}
</style>
</head>

<body>

<header>
<a href="index.php">
<span class="material-symbols-rounded">arrow_back</span> <span class="nav-text">Dashboard</span>
</a>
<strong>Corps enseignant</strong>
</header>

<main class="container">

    <section class="card">
        <h3>
            <span class="material-symbols-rounded"><?= $edit_mode?'lock_reset':'person_add' ?></span>
            <?= $edit_mode?'Modifier / Réinitialiser':'Nouvel enseignant' ?>
        </h3>

        <?php if($message): list($t,$m)=explode('|',$message); ?>
            <div class="alert alert-<?= $t ?>"><?= $m ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $edit_prof->id ?? '' ?>">

            <div style="margin-bottom: 20px;">
                <label>Nom complet</label>
                <input name="nom" required value="<?= $edit_prof->nom ?? '' ?>" placeholder="Ex: MULLER JEAN">
            </div>

            <div style="margin-bottom: 20px;">
                <label>Email</label>
                <input name="email" type="email" required value="<?= $edit_prof->email ?? '' ?>" placeholder="email@exemple.com">
            </div>

            <div style="margin-bottom: 20px;">
                <label><?= $edit_mode ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe' ?></label>
                <input name="password" type="password" <?= $edit_mode ? '' : 'required' ?> 
                       placeholder="<?= $edit_mode ? 'Laisser vide pour garder l\'ancien' : '••••••••' ?>">
            </div>

            <button name="save_prof">
                <?= $edit_mode?'Mettre à jour':'Créer le compte' ?>
            </button>
            
            <?php if($edit_mode): ?>
                <a href="gestion_enseignants.php" style="display:block; text-align:center; margin-top:15px; color:var(--muted); text-decoration:none; font-size:0.8rem;">Annuler la modification</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Enseignant</th>
                    <th>Email</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($enseignants as $e): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <div class="avatar"><?= substr($e->nom,0,1) ?></div>
                            <strong><?= htmlspecialchars($e->nom) ?></strong>
                        </div>
                    </td>
                    <td class="email-text"><?= htmlspecialchars($e->email) ?></td>
                    <td>
                        <div class="actions">
                            <a class="icon-btn edit" href="?edit=<?= $e->id ?>" title="Modifier">
                                <span class="material-symbols-rounded">edit</span>
                            </a>
                            <a class="icon-btn delete" 
                               href="?delete=<?= $e->id ?>&csrf_token=<?= urlencode(csrf_token()) ?>" 
                               onclick="return confirm('Supprimer cet enseignant ?')" title="Supprimer">
                                <span class="material-symbols-rounded">delete</span>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($enseignants)): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:30px; color:var(--muted)">Aucun enseignant enregistré.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

</main>
</body>
</html>
