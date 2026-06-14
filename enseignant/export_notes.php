<?php
ob_start();
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'enseignant') {
    header("Location: ../login.php"); exit();
}

$enseignant_id = $_SESSION['user']->id;
$prof_nom = $_SESSION['user']->nom;
$selected_cours_id = isset($_GET['cours_id']) ? intval($_GET['cours_id']) : null;
$export_mode = ($_GET['mode'] ?? 'all') === 'failed' ? 'failed' : 'all';
$requested_period = $_GET['periode'] ?? null;

if (!itm_setting($pdo, 'feature_download_notes', true)) {
    http_response_code(403);
    die("La fonctionnalite Telecharger est desactivee par ladmin.");
}

// --- LOGIQUE DE RÉCUPÉRATION ---
$donnees_eleves = [];
$info = null;
$filename = "Export";
$total_count = 0;
$failed_count = 0;

if ($selected_cours_id) {
    $stmt = $pdo->prepare("SELECT c.nom as cours, c.coefficient, c.semestre, p.nom as promo FROM cours c JOIN promotions p ON c.promotion_id = p.id WHERE c.id = ? AND c.enseignant_id = ?");
    $stmt->execute([$selected_cours_id, $enseignant_id]);
    $info = $stmt->fetch(PDO::FETCH_OBJ);

    if ($info) {
        $periode = itm_resolve_period_for_course($info->semestre, $requested_period);
        $filename = str_replace(' ', '_', $info->cours . "_" . $info->promo . "_" . $periode . ($export_mode === 'failed' ? "_Echecs" : ""));
        $query = "SELECT a.code_unique, a.nom, a.post_nom, a.prenom, c.coefficient,
                  MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) as cote,
                  MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) as cote_s1,
                  MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) as cote_s2,
                  MAX(CASE WHEN n.type_note = 'examen' THEN n.valeur END) as examen,
                  MAX(CASE WHEN n.type_note = 'moyenne' THEN n.valeur END) as moyenne
                  FROM apprenants a
                  INNER JOIN cours c ON a.promotion_id = c.promotion_id
                  LEFT JOIN notes n ON n.apprenant_id = a.id AND n.cours_id = c.id
                  WHERE c.id = ?
                  GROUP BY a.id, a.code_unique, a.nom, a.post_nom, a.prenom, c.coefficient
                  ORDER BY a.nom ASC";
        $stmt_data = $pdo->prepare($query);
        $stmt_data->execute([$selected_cours_id]);
        $donnees_eleves = $stmt_data->fetchAll(PDO::FETCH_OBJ);
        $total_count = count($donnees_eleves);
        foreach ($donnees_eleves as $e) {
            $points = itm_points_for_period($e, $info->semestre, $e->coefficient, $periode);
            $max = itm_effective_maxima($info->semestre, $e->coefficient, $periode);
            if ($max > 0 && $points < ($max / 2)) {
                $failed_count++;
            }
        }
        if ($export_mode === 'failed') {
            $donnees_eleves = array_values(array_filter($donnees_eleves, function ($e) use ($info, $periode) {
                $points = itm_points_for_period($e, $info->semestre, $e->coefficient, $periode);
                $max = itm_effective_maxima($info->semestre, $e->coefficient, $periode);
                return $max > 0 && $points < ($max / 2);
            }));
        }
    }
}

// --- EXPORT EXCEL ---
if (isset($_GET['action']) && $_GET['action'] == 'excel' && $info) {
    while (ob_get_level()) { ob_end_clean(); }
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=".$filename.".xls");
    echo "\xEF\xBB\xBF"; 
    echo "<table border='1'>
            <tr style='background:#003366; color:white;'><th colspan='7'>ITMCD - RELEVÉ OFFICIEL</th></tr>
            <tr><th colspan='7'>COURS : {$info->cours} | PROMO : {$info->promo} | ".itm_period_label($periode)."</th></tr>
            <tr><th colspan='7'>PROF : ".strtoupper($prof_nom).($export_mode === 'failed' ? " | ECHECS SEULEMENT" : "")."</th></tr>
            <tr style='background:#eee;'><th>Matricule</th><th>Nom Complet</th><th>Anc. Exa</th><th>Cote</th><th>Maxima</th><th>%</th></tr>";
    foreach ($donnees_eleves as $e) {
        $points = itm_points_for_period($e, $info->semestre, $e->coefficient, $periode);
        $max = itm_effective_maxima($info->semestre, $e->coefficient, $periode);
        $pct = $max > 0 ? ($points / $max) * 100 : 0;
        $style = $points < ($max / 2) ? " style='color:#b91c1c; font-weight:bold;'" : "";
        echo "<tr{$style}><td>'{$e->code_unique}</td><td>".strtoupper($e->nom." ".$e->post_nom)." {$e->prenom}</td><td>".($e->examen ?? '')."</td><td>".itm_format_points($points)."</td><td>".itm_format_points($max)."</td><td>".number_format($pct, 1)."%</td></tr>";
    }
    echo "</table>"; exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Export Notes - ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root { --ink:#172033; --muted:#64748b; --line:#dbe3ef; --panel:#ffffff; --bg:#eef3f8; --blue:#2563eb; --green:#15803d; --red:#b91c1c; }
        * { box-sizing: border-box; }
        body { background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, Segoe UI, sans-serif; overflow-x: hidden; margin: 0; }
        .export-container { padding: 18px; width: min(960px, 100%); margin: 0 auto; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:14px; margin: 8px 0 18px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; }
        .brand img { width:38px; height:38px; object-fit:contain; background:#fff; border:1px solid var(--line); border-radius:8px; padding:4px; }
        .back-link { color: var(--blue); text-decoration:none; font-weight:800; font-size:.9rem; }
        .mobile-card { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 18px; box-shadow: 0 18px 40px rgba(15,23,42,.08); margin-bottom: 16px; width: 100%; }
        .screen-title { font-size:1.45rem; line-height:1.15; font-weight:850; margin:0 0 4px; }
        .screen-subtitle { color:var(--muted); margin:0 0 16px; font-size:.95rem; }
        .form-label { color:#334155; letter-spacing:.02em; }
        .form-select { border-color: var(--line) !important; min-height: 48px; }
        .summary-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:14px; }
        .metric { border:1px solid var(--line); border-radius:8px; padding:12px; background:#f8fafc; }
        .metric strong { display:block; font-size:1.3rem; line-height:1; }
        .metric span { color:var(--muted); font-size:.78rem; font-weight:700; text-transform:uppercase; }
        .actions-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; }
        .btn-download { display: flex; align-items: center; justify-content: center; gap: 10px; min-height: 54px; padding: 14px; border-radius: 8px; font-weight: 850; border: none; width: 100%; text-decoration: none; font-size: 15px; color: white; text-align:center; }
        .btn-excel { background: var(--green); }
        .btn-pdf { background: var(--blue); }
        .btn-danger-soft { background: var(--red); }
        .empty-state { text-align:center; color:var(--muted); padding:32px 14px; border:1px dashed var(--line); border-radius:8px; background:#fff; }

        /* --- STYLES SPÉCIFIQUES POUR LE PDF --- */
        #pdf-content { display: none; width: 190mm; padding: 10px; color: black; background: white; }
        .pdf-header { text-align: center; border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 10px; }
        
        .pdf-table { width: 100%; border-collapse: collapse; }
        .pdf-table th, .pdf-table td { border: 1px solid #000; padding: 8px; font-size: 12px; }
        
        /* EMPECHE LA COUPURE DES LIGNES ENTRE DEUX PAGES */
        .pdf-table tr { page-break-inside: avoid; page-break-after: auto; }
        
        .signature-section { margin-top: 50px; display: flex; justify-content: space-between; page-break-inside: avoid; }
        @media (max-width: 640px) {
            .actions-grid, .summary-grid { grid-template-columns: 1fr; }
            .export-container { padding: 14px; }
        }
    </style>
</head>
<body>

<div class="export-container">
    <div class="topbar">
        <div class="brand">
            <img src="../images/LOGO.png" alt="ITMCD">
            <div>
                <div>ITMCD</div>
                <small class="text-muted">Exportation des notes</small>
            </div>
        </div>
        <a href="index.php" class="back-link">Retour</a>
    </div>

    <div class="mobile-card">
        <h1 class="screen-title">Télécharger les cotes</h1>
        <p class="screen-subtitle">Préparez un relevé complet ou seulement la liste des apprenants en échec.</p>
        <form method="GET">
            <label class="small fw-bold text-secondary mb-2">SÉLECTIONNEZ LE COURS</label>
            <select name="cours_id" class="form-select form-select-lg border-2" onchange="this.form.submit()" style="border-radius: 10px;">
                <option value="">-- Choisir --</option>
                <?php
                $sc = $pdo->prepare("SELECT c.id, c.nom, p.nom as promo FROM cours c JOIN promotions p ON c.promotion_id = p.id WHERE c.enseignant_id = ?");
                $sc->execute([$enseignant_id]);
                while($c = $sc->fetch(PDO::FETCH_OBJ)): ?>
                    <option value="<?= $c->id ?>" <?= $selected_cours_id == $c->id ? 'selected' : '' ?>>
                        <?= $c->promo ?> - <?= $c->nom ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <?php if($info): ?>
                <label class="small fw-bold text-secondary mt-3 mb-2">RUBRIQUE</label>
                <select name="periode" class="form-select border-2" onchange="this.form.submit()" style="border-radius: 10px;">
                    <?php foreach (itm_allowed_periods_for_course($info->semestre) as $p): ?>
                        <option value="<?= $p ?>" <?= $periode === $p ? 'selected' : '' ?>><?= itm_period_label($p) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="mode" value="<?= htmlspecialchars($export_mode) ?>">
            <?php endif; ?>
        </form>

        <?php if($info): ?>
            <div class="summary-grid">
                <div class="metric"><strong><?= $total_count ?></strong><span>Apprenants</span></div>
                <div class="metric"><strong style="color:var(--red);"><?= $failed_count ?></strong><span>Échecs</span></div>
                <div class="metric"><strong><?= itm_period_label($periode) ?></strong><span>Rubrique</span></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if($info): ?>
        <div class="mobile-card">
            <div class="actions-grid">
            <a href="export_notes.php?cours_id=<?= $selected_cours_id ?>&periode=<?= $periode ?>&mode=all&action=excel" class="btn-download btn-excel">
                Excel complet
            </a>
            <button onclick="generatePDF()" class="btn-download btn-pdf">
                <?= $export_mode === 'failed' ? 'PDF échecs' : 'PDF complet' ?>
            </button>
            <a href="export_notes.php?cours_id=<?= $selected_cours_id ?>&periode=<?= $periode ?>&mode=failed&action=excel" class="btn-download btn-danger-soft">
                Excel échecs
            </a>
            <a href="export_notes.php?cours_id=<?= $selected_cours_id ?>&periode=<?= $periode ?>&mode=failed" class="btn-download btn-danger-soft">
                Ouvrir les échecs
            </a>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">Sélectionnez un cours pour afficher les options de téléchargement.</div>
    <?php endif; ?>
</div>

<?php if($info): ?>
<div id="pdf-content">
    <div class="pdf-header">
        <h2 style="margin:0;">INSTITUT TECHNIQUE MÉDICAL (ITMCD)</h2>
        <p style="margin:5px 0;">Coordination des Études et des Examens</p>
        <h3 style="text-decoration: underline;">RELEVÉ DE NOTES OFFICIEL</h3>
    </div>

    <div style="margin-bottom: 20px; font-size: 14px;">
        <p><strong>COURS :</strong> <?= strtoupper($info->cours) ?></p>
        <p><strong>PROMOTION :</strong> <?= $info->promo ?></p>
        <p><strong>RUBRIQUE :</strong> <?= itm_period_label($periode) ?><?= $export_mode === 'failed' ? ' - ECHECS SEULEMENT' : '' ?></p>
        <p><strong>ENSEIGNANT :</strong> <?= strtoupper($prof_nom) ?></p>
        <p><strong>DATE D'ÉMISSION :</strong> <?= date('d/m/Y') ?></p>
    </div>

    <table class="pdf-table">
        <thead>
            <tr style="background: #f2f2f2;">
                <th>N°</th>
                <th>Matricule</th>
                <th>Nom, Post-nom & Prénom</th>
                <th>Anc. Exa</th>
                <th>Cote</th>
                <th>Maxima</th>
                <th>%</th>
            </tr>
        </thead>
        <tbody>
            <?php $i=1; foreach($donnees_eleves as $e): ?>
                <tr>
                    <td style="text-align:center;"><?= $i++ ?></td>
                    <td><?= $e->code_unique ?></td>
                    <td><?= strtoupper($e->nom." ".$e->post_nom)." ".$e->prenom ?></td>
                    <?php
                        $points = itm_points_for_period($e, $info->semestre, $e->coefficient, $periode);
                        $max = itm_effective_maxima($info->semestre, $e->coefficient, $periode);
                        $pct = $max > 0 ? ($points / $max) * 100 : 0;
                        $fail_line = $points < ($max / 2);
                    ?>
                    <td style="text-align:center;"><?= $e->examen ?? '' ?></td>
                    <td style="text-align:center; font-weight:bold; color:<?= $fail_line ? '#b91c1c' : '#000' ?>;"><?= itm_format_points($points) ?></td>
                    <td style="text-align:center;"><?= itm_format_points($max) ?></td>
                    <td style="text-align:center;"><?= number_format($pct, 1) ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="signature-section">
        <div style="text-align:center;">
            <p>Signature de l'Enseignant</p>
            <br><br>
            <p>_______________________</p>
        </div>
        <div style="text-align:center;">
            <p>Le Préfet des Études</p>
            <br><br>
            <p>_______________________</p>
        </div>
    </div>
</div>

<script>
function generatePDF() {
    const element = document.getElementById('pdf-content');
    element.style.display = 'block'; 
    
    const opt = {
      margin:       10,
      filename:     '<?= $filename ?>.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2, useCORS: true },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
      // Configuration pour gérer les sauts de page intelligemment
      pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
    };

    html2pdf().set(opt).from(element).save().then(() => {
        element.style.display = 'none';
    });
}
</script>
<?php endif; ?>

</body>
</html>
