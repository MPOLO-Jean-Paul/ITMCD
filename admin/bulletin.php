<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// 1. GESTION DE L'ANNÉE SCOLAIRE (FILTRE SÉCURISÉ)
if (isset($_GET['annee_id'])) {
    $_SESSION['annee_filtre_id'] = $_GET['annee_id'];
}

if (!isset($_SESSION['annee_filtre_id'])) {
    $stmt_active = $pdo->query("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
    $active = $stmt_active->fetchColumn();
    $_SESSION['annee_filtre_id'] = $active ? $active : null;
}
$current_annee_id = $_SESSION['annee_filtre_id'];

// RÉCUPÉRATION DES PARAMÈTRES (Promotion + Période/Semestre)
$promotion_id = isset($_GET['promotion_id']) ? $_GET['promotion_id'] : null;
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'S1'; 

$apprenants = [];
$bulletins = [];

$promotions = $pdo->query("SELECT id, nom FROM promotions ORDER BY nom")->fetchAll(PDO::FETCH_OBJ);
$toutes_annees = $pdo->query("SELECT * FROM annees_scolaires ORDER BY libelle DESC")->fetchAll(PDO::FETCH_OBJ);

if ($promotion_id) {
    // 2. Filtrage des apprenants par Année + Promotion
    $sql_app = "SELECT a.*, p.nom AS promo 
                FROM apprenants a 
                JOIN promotions p ON a.promotion_id = p.id
                WHERE a.annee_scolaire_id = ?";
    
    if ($promotion_id !== 'all') {
        $sql_app .= " AND p.id = ?";
        $stmt = $pdo->prepare($sql_app . " ORDER BY a.nom");
        $stmt->execute([$current_annee_id, $promotion_id]);
    } else {
        $stmt = $pdo->prepare($sql_app . " ORDER BY p.nom, a.nom");
        $stmt->execute([$current_annee_id]);
    }
    $apprenants = $stmt->fetchAll(PDO::FETCH_OBJ);

    // 3. Récupération des notes avec filtre de période strict
    foreach ($apprenants as $ap) {
        $filter = "";
        if ($periode === 'S1') {
            $filter = " AND (c.semestre = 'S1' OR c.semestre = 'ANNUEL')";
        } elseif ($periode === 'S2') {
            $filter = " AND (c.semestre = 'S2' OR c.semestre = 'ANNUEL')";
        }

        $sql = "SELECT c.nom as cours_nom, c.coefficient, c.semestre,
                       MAX(CASE WHEN n.type_note='cote' THEN n.valeur END) AS cote,
                       MAX(CASE WHEN n.type_note='cote_s1' THEN n.valeur END) AS cote_s1,
                       MAX(CASE WHEN n.type_note='cote_s2' THEN n.valeur END) AS cote_s2,
                       MAX(CASE WHEN n.type_note='examen' THEN n.valeur END) AS examen,
                       MAX(CASE WHEN n.type_note='examen' THEN n.valeur END) AS exe,
                       MAX(CASE WHEN n.type_note='moyenne' THEN n.valeur END) AS moyenne
                FROM cours c
                LEFT JOIN notes n ON n.cours_id = c.id AND n.apprenant_id = ?
                WHERE c.promotion_id = ? $filter
                GROUP BY c.id, c.nom, c.coefficient, c.semestre
                ORDER BY c.semestre, c.nom";
        
        $st = $pdo->prepare($sql);
        $st->execute([$ap->id, $ap->promotion_id]);
        $bulletins[$ap->id] = $st->fetchAll(PDO::FETCH_OBJ);
    }
}

// Libellé de l'année pour l'entête
$annee_libelle = "Session non définie";
foreach($toutes_annees as $an) {
    if($an->id == $current_annee_id) $annee_libelle = $an->libelle;
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
<style>
:root{ --paper:#fff; --ink:#0f172a; --line:#0f172a; --soft:#f1f5f9; }
body{ background:#e5e7eb; font-family:Segoe UI,Roboto,sans-serif; color:var(--ink); padding-top: 120px; }
.bulletin-paper{ background:var(--paper); width:210mm; min-height:297mm; margin:0 auto 30px; padding:18mm 16mm; box-shadow:0 10px 30px rgba(0,0,0,.15); page-break-after:always; overflow:hidden; }
.header-inst{ text-align:center; border-bottom:2px double var(--line); padding-bottom:6px; margin-bottom:10px; }
.header-inst h3{font-size:13px;font-weight:800;margin:0;}
.header-inst p{font-size:9px;margin:0;}
.bulletin-paper h4{ font-size:12px; font-weight:800; margin:6px 0 4px; }
.bulletin-paper p{ font-size:9px; margin-bottom:2px; }
.table-notes{ border:1.5px solid var(--line); font-size:8.5px; }
.table-notes th, .table-notes td{ border:1px solid var(--line); padding:2px 4px; }
.table-notes thead th{ background:var(--soft); font-size:8px; }

/* MODIFICATION ICI : Texte en noir et en gras pour les boites de décision */
.decision-box{ 
    border:2px solid var(--line); 
    padding:5px; 
    margin-top:6px; 
    font-size:9px; 
    font-weight: 900; 
    color: #000 !important; 
}

.qr-code{ width:70px; height:70px; border:1px solid var(--line); padding:2px; }

.no-print { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; background: rgba(229,231,235,.94); padding-bottom: 10px; backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-bottom:1px solid rgba(15,23,42,.10); }
.no-print form{ border:1px solid rgba(15,23,42,.10); border-radius:14px!important; box-shadow:0 14px 36px rgba(15,23,42,.10)!important; }
.no-print label{ color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:0; }
.no-print .form-select,.no-print .btn{ min-height:42px; border-radius:10px; font-weight:700; }
.table-notes{ width:100%; table-layout:fixed; }
.table-notes th,.table-notes td{ overflow-wrap:anywhere; vertical-align:middle; }
.table-notes th:first-child,.table-notes td:first-child{ width:34%; }

@media print{ 
    @page{size:A4;margin:0;} 
    body{background:#fff; padding-top: 0;} 
    .no-print{display:none!important;} 
    .bulletin-paper{ width:210mm; min-height:297mm; box-shadow:none; margin:0; page-break-after:always; } 
}

@media screen and (max-width: 920px) {
    body{ padding-top: 220px; }
    .no-print .container{ max-width:100%; padding-left:10px; padding-right:10px; }
    .no-print form{ display:grid; grid-template-columns:1fr 1fr; gap:10px!important; }
    .no-print form > div{ width:100%; }
    .no-print .col-md-2{ grid-column:1 / -1; margin-top:0!important; }
    .bulletin-paper{
        width:min(210mm, calc(100vw - 20px));
        min-height:auto;
        padding:18px 14px;
        margin-bottom:18px;
    }
    .table-notes{ font-size:7.4px; }
    .table-notes th,.table-notes td{ padding:2px 3px; }
}

@media screen and (max-width: 560px) {
    body{ padding-top: 300px; }
    .no-print form{ grid-template-columns:1fr; }
    .bulletin-paper{ padding:14px 10px; }
    .header-inst h3{ font-size:10px; }
    .header-inst p,.bulletin-paper p{ font-size:7.4px; }
    .bulletin-paper h4{ font-size:10px; }
    .table-notes{ font-size:6.6px; }
    .decision-box{ font-size:7.4px; }
}
</style>
</head>
<body>

<div class="no-print">
    <div class="container py-3">
        <form method="GET" class="row g-2 align-items-center bg-white p-3 rounded shadow-sm">
            <div class="col-md-3">
                <label class="small fw-bold">Année Scolaire</label>
                <select name="annee_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach($toutes_annees as $an): ?>
                        <option value="<?= $an->id ?>" <?= $current_annee_id==$an->id?'selected':'' ?>>
                            <?= $an->libelle ?> <?= $an->est_active ? '(Active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="small fw-bold">Promotion</label>
                <select name="promotion_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Sélectionnez une promotion --</option>
                    <option value="all" <?= $promotion_id==='all'?'selected':'' ?>>Toutes les promotions</option>
                    <?php foreach($promotions as $p): ?>
                        <option value="<?= $p->id ?>" <?= $promotion_id==$p->id?'selected':'' ?>><?= $p->nom ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">Période</label>
                <select name="periode" class="form-select" onchange="this.form.submit()">
                    <option value="S1" <?= $periode==='S1'?'selected':'' ?>>1er Semestre</option>
                    <option value="S2" <?= $periode==='S2'?'selected':'' ?>>2ème Semestre</option>
                    <option value="FINAL" <?= $periode==='FINAL'?'selected':'' ?>>Bulletin Annuel</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1 mt-4">
                <button type="button" onclick="window.print()" class="btn btn-dark flex-grow-1">🖨️</button>
                <a href="index.php" class="btn btn-outline-primary flex-grow-1">🏠</a>
            </div>
        </form>
    </div>
</div>

<?php if(empty($apprenants) && $promotion_id): ?>
    <div class="text-center mt-5 no-print">
        <p class="text-muted">Aucun apprenant trouvé pour cette sélection dans l'année <?= $annee_libelle ?>.</p>
    </div>
<?php endif; ?>

<?php foreach($apprenants as $ap):
    $sum_ponderee = 0;
    $sum_coeff = 0;
    $fail = false;
?>
<div class="bulletin-paper">
    <div class="header-inst">
        <h3>REPUBLIQUE DEMOCRATIQUE DU CONGO</h3>
        <p>Ministère de la Santé Publique</p>
        <p><strong>INSTITUT TECHNIQUE MEDICAL COEUR DE DIEU (ITMCD)</strong></p>
    </div>

    <div class="text-center">
        <h4 class="text-decoration-underline">
            <?php 
                if($periode == 'S1') echo "BULLETIN DU PREMIER SEMESTRE";
                elseif($periode == 'S2') echo "BULLETIN DU SECOND SEMESTRE";
                else echo "BULLETIN DE NOTES ANNUEL";
            ?>
        </h4>
        <p>Année scolaire <?= $annee_libelle ?></p>
    </div>

    <div class="row mb-1">
        <div class="col-7">
            <p><strong>NOM :</strong> <?= strtoupper($ap->nom.' '.$ap->post_nom) ?></p>
            <p><strong>PRENOM :</strong> <?= $ap->prenom ?></p>
            <p><strong>PROMOTION :</strong> <?= $ap->promo ?></p>
        </div>
        <div class="col-5 text-end">
            <p><strong>CODE :</strong> <?= $ap->code_unique ?></p>
        </div>
    </div>

    <table class="table table-notes">
        <thead class="text-center">
            <tr>
                <th class="text-start">COURS</th>
                <th>PERIODE</th>
                <th>ANCIEN EXAM.</th>
                <th>COTE OBTENUE</th>
                <th>MAXIMA</th>
                <th>%</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($bulletins[$ap->id] as $b):
            $is_missing = !itm_has_grade_for_period($b, $b->semestre, $periode);
            if($is_missing) $fail = true;
            
            $effective_max = itm_effective_maxima($b->semestre, $b->coefficient, $periode);
            $points = itm_points_for_period($b, $b->semestre, $b->coefficient, $periode);
            $line_pct = ($effective_max > 0) ? ($points / $effective_max) * 100 : 0;
            $line_failed = !$is_missing && $points < ($effective_max / 2);
            
            $sum_ponderee += $points;
            $sum_coeff += $effective_max;
        ?>
            <tr class="text-center">
                <td class="text-start"><?= $b->cours_nom ?></td>
                <td><small><?= $b->semestre ?></small></td>
                <td><?= ($b->exe !== null) ? itm_format_points($b->exe) . '/10' : '--' ?></td>
                <td class="<?= $line_failed ? 'text-danger fw-bold' : '' ?>"><?= $is_missing ? '<span class="text-danger">INC</span>' : itm_format_points($points) ?></td>
                <td><?= itm_format_points($effective_max) ?></td>
                <td><?= $is_missing ? '--' : number_format($line_pct, 1) . '%' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php
    $avg = ($sum_coeff > 0) ? ($sum_ponderee / $sum_coeff) * 100 : 0;
    $pct = $avg;

    // Logique de la Mention (Optionnel : vous pouvez l'ajuster aussi)
    if ($pct >= 90) $mention = "A";
    elseif ($pct >= 80) $mention = "B";
    elseif ($pct >= 70) $mention = "C";
    elseif ($pct >= 60) $mention = "D";
    elseif ($pct >= 50) $mention = "E";
    else $mention = "E";

    // NOUVELLE LOGIQUE DE DÉCISION BASÉE SUR LE POURCENTAGE (60%)
    if($fail) {
        $dec = "ECHEC (Notes manquantes)";
        $cls = "text-warning"; 
    } elseif($pct >= 60) { // On vérifie si le pourcentage est >= 60
        $dec = "ADMIS"; 
        $cls = "text-success"; 
    } else { 
        $dec = "ECHEC";
        $cls = "text-danger"; 
    }
    ?>

    <div class="decision-box text-center <?= $cls ?>">
        TOTAL OBTENU : <?= itm_format_points($sum_ponderee) ?> / <?= itm_format_points($sum_coeff) ?> 
    </div>
    <div class="decision-box text-center <?= $cls ?>">
        POURCENTAGE : <?= number_format($pct, 2) ?> %
    </div>
    <div class="decision-box text-center <?= $cls ?>">
        MENTION : <?= $mention ?>
    </div>
    <div class="decision-box text-center <?= $cls ?>">
        MAXIMA TOTAL : <?= itm_format_points($sum_coeff) ?>
    </div>
    <div class="decision-box text-center <?= $cls ?>">
        DECISION DU JURY : <?= $dec ?>
    </div>
    
    <div class="row mt-2 align-items-center">
        <div class="col-4 text-center">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?= urlencode($ap->code_unique.'-'.$avg) ?>" class="qr-code">
        </div>
        <div class="col-8">
            <div class="row text-center">
                <div class="col-6">
                    <p class="small fw-bold text-decoration-underline mb-5">Le Préfet</p>
                    <p>NDOSI WA NGOY Gloire</p>
                </div>
                <div class="col-6">
                    <p class="small fw-bold text-decoration-underline mb-5">La Proviseur</p>
                    <p>NONGO MUTOMBO Urcile</p>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center mt-4 pt-2 border-top small text-muted">
        Fait à Lubumbashi, le <?= date('d/m/Y') ?>. Bulletin sécurisé généré par ITMCD-System.
    </div>
</div>
<?php endforeach; ?>

</body>
</html>
