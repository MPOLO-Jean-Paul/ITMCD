<?php
session_start(); 
require_once '../config/db.php';
checkRole('admin'); 

// 1. GESTION DE L'ANNÉE SCOLAIRE FILTRÉE (SESSION + GET)
if (isset($_GET['annee_id'])) {
    $_SESSION['annee_filtre_id'] = $_GET['annee_id'];
}

// Si aucune année n'est choisie, on prend l'année active par défaut
if (!isset($_SESSION['annee_filtre_id'])) {
    $stmt_active = $pdo->query("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
    $active = $stmt_active->fetchColumn();
    $_SESSION['annee_filtre_id'] = $active ? $active : null;
}

$current_annee_id = $_SESSION['annee_filtre_id'];

if (isset($_GET['delete_id'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals(csrf_token(), $_GET['csrf_token'])) {
        http_response_code(419);
        die('Session expiree. Veuillez recharger la page.');
    }
    $pdo->prepare("DELETE FROM apprenants WHERE id = ?")->execute([$_GET['delete_id']]);
    header("Location: liste_globale.php");
    exit();
}

$filter_promo = $_GET['promo_id'] ?? '';

// 2. CONSTRUCTION DE LA REQUÊTE AVEC FILTRE ANNÉE + PROMO
$query = "SELECT a.*, p.nom as promo 
          FROM apprenants a 
          JOIN promotions p ON a.promotion_id = p.id 
          WHERE 1=1";

$params = [];

if ($current_annee_id) {
    $query .= " AND a.annee_scolaire_id = :aid";
    $params['aid'] = $current_annee_id;
}

if (!empty($filter_promo)) { 
    $query .= " AND a.promotion_id = :pid"; 
    $params['pid'] = $filter_promo;
}

$query .= " ORDER BY a.nom ASC, a.post_nom ASC, a.prenom ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$etudiants = $stmt->fetchAll();

// Récupération des données pour les filtres
$promotions = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll();
$toutes_annees = $pdo->query("SELECT * FROM annees_scolaires ORDER BY libelle DESC")->fetchAll();

$promotion_nom = '';
if (!empty($filter_promo)) {
    foreach ($promotions as $p) {
        if ($p->id == $filter_promo) { 
            $promotion_nom = $p->nom; 
            break; 
        }
    }
}

$annee_libelle = '';
foreach ($toutes_annees as $an) {
    if ($an->id == $current_annee_id) {
        $annee_libelle = $an->libelle;
        break;
    }
}
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
        :root{--bg-body:#0f172a;--bg-card:#1e293b;--accent:#38bdf8;--white:#fff;--muted:#94a3b8;--danger:#ef4444}
        body{background:var(--bg-body);color:var(--white);font-family:'Segoe UI',sans-serif}
        
        #sidebar{width:260px;height:100vh;position:fixed;left:0;top:0;background:#020617;border-right:1px solid rgba(255,255,255,.05);z-index:1000;transition:.3s;display:flex;flex-direction:column}
        #sidebar .nav-link{color:var(--muted);padding:14px 24px;display:flex;gap:12px;text-decoration:none;transition:0.2s}
        #sidebar .nav-link.active{background:rgba(56,189,248,.1);color:var(--accent)}
        
        .logout-link { color: var(--danger) !important; font-weight: 600; }
        .logout-link:hover { background: rgba(239, 68, 68, 0.1) !important; }

        #sidebar,.mobile-header{display:none!important}
        #content{margin:0 auto;padding:30px;width:min(1180px,100%);}
        .card{background:var(--bg-card);border-radius:16px;border:1px solid rgba(255,255,255,.08)}
        
        .form-select { background-color: var(--bg-card); border-color: rgba(255,255,255,0.1); color: white; }
        
        @media(max-width:992px){
            #content{margin-left:0}
        }

        @media print{
            body{background:white;color:black}
            #sidebar,.actions,.mobile-header,.btn,.nav-link,form{display:none!important}
            #content{margin:0;padding:0}
            table{width:100%;border-collapse:collapse;color:black!important}
            th,td{border:1px solid #000!important;padding:8px;color:black!important}
            .print-title{text-align:center;margin-bottom:20px;color:black!important}
        }
    </style>
</head>
<body>

<nav id="sidebar">
    <div class="p-4 d-flex justify-content-between align-items-center">
        <h4 class="mb-0 fw-bold">ITMCD<span style="color:var(--accent)">.</span></h4>
        <button class="btn btn-sm btn-outline-light d-lg-none" id="closeSidebar"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="nav flex-column mt-3">
        <a href="index.php" class="nav-link"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
        <a href="liste_globale.php" class="nav-link active"><i class="bi bi-people-fill"></i> Apprenants</a>
        <a href="gestion_enseignants.php" class="nav-link"><i class="bi bi-person-badge-fill"></i> Enseignants</a>
        <a href="gestion_cours.php" class="nav-link"><i class="bi bi-book"></i> Cours</a>
        <a href="gestion_notes.php" class="nav-link"><i class="bi bi-award-fill"></i> Notes</a>
    </div>
    <div class="mt-auto p-3">
        <hr class="opacity-10">
        <a href="../logout.php" class="nav-link logout-link">
            <i class="bi bi-box-arrow-right fs-5"></i> Déconnexion
        </a>
    </div>
</nav>

<main id="content">
    <div class="mobile-header">
        <button class="btn btn-outline-info me-3" id="sidebarCollapse"><i class="bi bi-list fs-3"></i></button>
        <h4 class="mb-0 fw-bold">Apprenants</h4>
    </div>

    <h2 class="fw-bold print-title mb-4" id="pageTitle">
        <?php if($promotion_nom): ?>
            LISTE - <?= htmlspecialchars($promotion_nom) ?> (<?= $annee_libelle ?>)
        <?php else: ?>
            RÉPERTOIRE GÉNÉRAL - <?= $annee_libelle ?>
        <?php endif; ?>
    </h2>

    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3">
        <form method="GET" class="d-flex flex-wrap gap-3">
            <div class="d-flex flex-column gap-2">
                <label class="small text-white text-uppercase fw-bold">Année Scolaire</label>
                <select name="annee_id" class="form-select w-auto" onchange="this.form.submit()">
                    <?php foreach($toutes_annees as $an): ?>
                        <option value="<?= $an->id ?>" <?= ($current_annee_id == $an->id)?'selected':'' ?>>
                            <?= $an->libelle ?> <?= $an->est_active ? '●' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex flex-column gap-2">
                <label class="small text-white text-uppercase fw-bold">Filtrer par promotion</label>
                <select name="promo_id" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Toutes les promotions</option>
                    <?php foreach($promotions as $p): ?>
                        <option value="<?= $p->id ?>" <?= ($filter_promo==$p->id)?'selected':'' ?>><?= $p->nom ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <div class="actions d-flex gap-2">
            <button class="btn btn-outline-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
            <button class="btn btn-danger shadow-sm" onclick="generatePDF()"><i class="bi bi-file-earmark-pdf"></i> Générer PDF</button>
        </div>
    </div>

    <div class="card shadow-lg">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0" id="listeTable">
                <thead>
                    <tr class="border-bottom border-white border-opacity-10">
                        <th class="ps-4 py-3">Nom</th>
                        <th>Postnom</th>
                        <th>Prénom</th>
                        <th>Promotion</th>
                        <th class="pe-4">Code Unique</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($etudiants) > 0): ?>
                        <?php foreach($etudiants as $e): ?>
                        <tr class="align-middle border-bottom border-white border-opacity-5">
                            <td class="ps-4 fw-bold"><?= htmlspecialchars($e->nom) ?></td>
                            <td><?= htmlspecialchars($e->post_nom) ?></td>
                            <td><?= htmlspecialchars($e->prenom) ?></td>
                            <td><span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25"><?= htmlspecialchars($e->promo) ?></span></td>
                            <td class="pe-4 fw-mono text-accent"><?= htmlspecialchars($e->code_unique) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">Aucun apprenant trouvé pour cette sélection.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

<script>
    function generatePDF() {
        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'mm', 'a4');
            const title = document.getElementById('pageTitle').innerText;

            doc.setFontSize(18);
            doc.text("INSTITUT TECHNIQUE ITMCD", 105, 15, { align: 'center' });
            doc.setFontSize(11);
            doc.text(title, 105, 22, { align: 'center' });

            doc.autoTable({
                html: '#listeTable',
                startY: 30,
                theme: 'grid',
                headStyles: { fillColor: [15, 23, 42], textColor: [255, 255, 255] },
                styles: { fontSize: 9 }
            });

            doc.save(title.replace(/ /g, "_") + ".pdf");
        } catch (error) {
            alert("Erreur PDF. Vérifiez votre connexion internet.");
        }
    }
</script>
</body>
</html>
