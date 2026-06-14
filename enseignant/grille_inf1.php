<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']->role, ['admin', 'enseignant'], true)) {
    header("Location: ../login.php");
    exit();
}

$selected_promotion_id = isset($_GET['promotion_id']) && $_GET['promotion_id'] !== 'all' ? (int)$_GET['promotion_id'] : null;
$all_promotions = $pdo->query("SELECT id, nom FROM promotions ORDER BY nom ASC")->fetchAll(PDO::FETCH_OBJ);
$promotions = $all_promotions;

if ($selected_promotion_id) {
    $promotions = array_values(array_filter($promotions, function ($promo) use ($selected_promotion_id) {
        return (int)$promo->id === $selected_promotion_id;
    }));
}

$grilles = [];
foreach ($promotions as $promo) {
    $stmt_items = $pdo->prepare("SELECT id, nom, coefficient, semestre FROM cours WHERE promotion_id = ? ORDER BY semestre, nom");
    $stmt_items->execute([$promo->id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_OBJ);

    $stmt_apps = $pdo->prepare("SELECT id, nom, post_nom, prenom, code_unique FROM apprenants WHERE promotion_id = ? ORDER BY nom, post_nom, prenom");
    $stmt_apps->execute([$promo->id]);
    $apprenants = $stmt_apps->fetchAll(PDO::FETCH_OBJ);

    $notes = [];
    $stmt_notes = $pdo->prepare("
        SELECT n.apprenant_id, n.cours_id,
               MAX(CASE WHEN n.type_note = 'cote' THEN n.valeur END) AS cote,
               MAX(CASE WHEN n.type_note = 'cote_s1' THEN n.valeur END) AS cote_s1,
               MAX(CASE WHEN n.type_note = 'cote_s2' THEN n.valeur END) AS cote_s2,
               MAX(CASE WHEN n.type_note = 'examen' THEN n.valeur END) AS examen,
               MAX(CASE WHEN n.type_note = 'moyenne' THEN n.valeur END) AS moyenne
        FROM notes n
        JOIN cours c ON c.id = n.cours_id
        WHERE c.promotion_id = ?
        GROUP BY n.apprenant_id, n.cours_id
    ");
    $stmt_notes->execute([$promo->id]);
    foreach ($stmt_notes->fetchAll(PDO::FETCH_OBJ) as $note) {
        $notes[$note->apprenant_id][$note->cours_id] = $note;
    }

    $grilles[] = ['promotion' => $promo, 'items' => $items, 'apprenants' => $apprenants, 'notes' => $notes];
}

if (($_GET['action'] ?? '') === 'excel') {
    while (ob_get_level()) { ob_end_clean(); }
    $suffix = $selected_promotion_id ? ('_' . ($grilles[0]['promotion']->nom ?? 'promotion')) : '_toutes_promotions';
    $filename = 'grille_des_cotes' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $suffix) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    foreach ($grilles as $grille) {
        $items = $grille['items'];
        $apprenants = $grille['apprenants'];
        $notes = $grille['notes'];
        $total_maxima = array_sum(array_map(function ($item) { return itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL'); }, $items));
        echo '<table border="1">';
        echo '<tr><th colspan="' . (count($items) + 6) . '">GRILLE DES COTES - PROMOTION : ' . htmlspecialchars($grille['promotion']->nom, ENT_QUOTES, 'UTF-8') . '</th></tr>';
        echo '<tr><th>N°</th><th>Noms & postnoms</th><th>Code</th>';
        foreach ($items as $item) {
            echo '<th>' . htmlspecialchars($item->nom, ENT_QUOTES, 'UTF-8') . ' /' . itm_format_points(itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL')) . '</th>';
        }
        echo '<th>Total /' . itm_format_points($total_maxima) . '</th><th>%</th><th>Decision</th></tr>';

        $i = 1;
        foreach ($apprenants as $app) {
            $total_obtenu = 0;
            echo '<tr><td>' . $i++ . '</td><td>' . htmlspecialchars(trim($app->nom . ' ' . $app->post_nom . ' ' . $app->prenom), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($app->code_unique, ENT_QUOTES, 'UTF-8') . '</td>';
            foreach ($items as $item) {
                $note = $notes[$app->id][$item->id] ?? null;
                $points = $note ? itm_points_for_period($note, $item->semestre, $item->coefficient, 'FINAL') : 0;
                $total_obtenu += $points;
                echo '<td>' . ($note ? itm_format_points($points) : '-') . '</td>';
            }
            $pct = $total_maxima > 0 ? ($total_obtenu / $total_maxima) * 100 : 0;
            echo '<td>' . itm_format_points($total_obtenu) . '</td><td>' . number_format($pct, 1) . '%</td><td>' . ($pct >= 60 ? 'ADMIS' : 'ECHEC') . '</td></tr>';
        }
        if (empty($apprenants)) {
            echo '<tr><td colspan="' . (count($items) + 6) . '">Aucun apprenant dans cette promotion.</td></tr>';
        }
        echo '</table><br>';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grilles des cotes - ITMCD</title>
    <?= app_shell_assets('../') ?>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; color: #111827; margin: 0; min-height: 100vh; overflow-y: auto; font-size: 11px; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: flex-start; flex-wrap: wrap; min-height: 62px; padding: 10px 18px; background: #fff; border-bottom: 1px solid #d1d5db; }
        .toolbar form { margin: 0 0 0 auto; }
        .toolbar a, .toolbar button, .toolbar select { border: 1px solid #d1d5db; background: #fff; color: #111827; border-radius: 6px; padding: 8px 10px; text-decoration: none; font-weight: 700; }
        .sheet { box-sizing: border-box; background: #fff; margin: 18px auto; padding: 14px; width: min(calc(100% - 24px), 1120px); box-shadow: 0 18px 45px rgba(15,23,42,.10); border: 1px solid #dbe5f0; border-radius: 8px; overflow: auto; }
        h2, h3 { text-align: center; margin: 4px 0; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; min-width: var(--screen-table-width, 900px); }
        th, td { border: 1px solid #111827; padding: 4px; text-align: center; vertical-align: middle; white-space: nowrap; overflow-wrap: normal; word-break: normal; }
        th { background: #e5e7eb; font-weight: 800; }
        .rank { width: 42px; }
        .student { text-align: left; min-width: 190px; font-weight: 700; white-space: normal; }
        .code { min-width: 76px; }
        .decision { min-width: 70px; }
        .vertical { writing-mode: vertical-rl; transform: rotate(180deg); height: 130px; min-width: 28px; max-width: 36px; white-space: normal; overflow-wrap: anywhere; }
        .course-col, .course-cell { width: var(--screen-course-width, 48px); }
        .total { background: #f3f4f6; font-weight: 800; }
        .pass { color: #047857; font-weight: 800; }
        .fail { color: #b91c1c; font-weight: 800; }
        .empty { color: #9ca3af; }
        @page { size: A4 landscape; margin: 0; }
        @media print {
            html, body { width: 297mm !important; min-width: 297mm !important; min-height: 210mm; overflow: visible !important; }
            body { background: #fff; padding: 0 !important; }
            .toolbar, .itm-theme-switch { display: none !important; }
            .sheet {
                box-sizing: border-box;
                box-shadow: none;
                border-radius: 0;
                margin: 0;
                padding: 4mm;
                width: 297mm !important;
                max-width: 297mm !important;
                height: 210mm !important;
                max-height: 210mm !important;
                overflow: hidden !important;
                page-break-after: always;
                break-after: page;
            }
            .sheet:last-of-type { page-break-after: auto; break-after: auto; }
            h2 { font-size: 11px; line-height: 1.05; margin: 0 0 1mm; }
            h3 { font-size: 9px; line-height: 1.05; margin: 0 0 1.5mm; }
            table {
                min-width: 0 !important;
                width: 100% !important;
                table-layout: fixed;
                margin-top: 0;
                font-size: var(--print-font, 7px);
                line-height: 1.05;
            }
            th, td {
                padding: var(--print-pad, 1px);
                border-width: .6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            thead th { padding: 1px; }
            tbody tr { height: var(--print-row-height, 4mm); }
            .rank { width: 7mm !important; }
            .student { width: 42mm !important; min-width: 42mm !important; max-width: 42mm !important; }
            .code { width: 18mm !important; min-width: 18mm !important; max-width: 18mm !important; }
            .total-col { width: 14mm !important; }
            .percent { width: 11mm !important; }
            .decision { width: 15mm !important; min-width: 15mm !important; }
            .course-col, .course-cell { width: var(--course-width, 7mm) !important; min-width: var(--course-width, 7mm) !important; max-width: var(--course-width, 7mm) !important; }
            .vertical {
                height: var(--vertical-height, 24mm) !important;
                min-width: var(--course-width, 7mm) !important;
                max-width: var(--course-width, 7mm) !important;
                white-space: normal;
                text-overflow: clip;
            }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a href="index.php">Retour</a>
    <form method="GET">
        <select name="promotion_id" onchange="this.form.submit()">
            <option value="all">Toutes les promotions</option>
            <?php foreach ($all_promotions as $promo): ?>
                <option value="<?= $promo->id ?>" <?= $selected_promotion_id === (int)$promo->id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($promo->nom) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php $exportQuery = http_build_query(['promotion_id' => $selected_promotion_id ?: 'all']); ?>
    <button onclick="window.print()">Imprimer</button>
    <button type="button" onclick="downloadGridPdf()">Export PDF</button>
    <a href="grille_inf1.php?<?= htmlspecialchars($exportQuery . '&action=excel', ENT_QUOTES, 'UTF-8') ?>">Export Excel</a>
</div>

<?php foreach ($grilles as $grille): ?>
    <?php
        $items = $grille['items'];
        $apprenants = $grille['apprenants'];
        $notes = $grille['notes'];
        $total_maxima = array_sum(array_map(function ($item) { return itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL'); }, $items));
        $course_count = max(count($items), 1);
        $student_count = max(count($apprenants), 1);
        $course_density = min(1, 18 / $course_count);
        $vertical_mm = round(max(16, min(30, 28 * $course_density)), 2);
        $row_height_mm = round(max(1.55, min(5.4, (178 - $vertical_mm) / $student_count)), 2);
        $print_font = round(max(3.8, min(7.2, min($row_height_mm * 1.85, 7.2 * $course_density + 1.2))), 2);
        $print_pad = round(max(0.5, min(2.1, $row_height_mm * 0.25)), 2);
        $screen_table_width = max(920, 330 + ($course_count * 48));
        $course_width_mm = round(max(3.2, min(10, (268 - 107) / $course_count)), 2);
        $screen_course_width = max(38, min(58, (int)round($course_width_mm * 8)));
        $print_style = "--course-count: {$course_count}; --student-count: {$student_count}; --screen-table-width: {$screen_table_width}px; --screen-course-width: {$screen_course_width}px; --print-font: {$print_font}px; --print-pad: {$print_pad}px; --vertical-height: {$vertical_mm}mm; --print-row-height: {$row_height_mm}mm; --course-width: {$course_width_mm}mm;";
    ?>
    <section class="sheet" style="<?= $print_style ?>">
        <h2>Grille des cotes</h2>
        <h3>Promotion : <?= htmlspecialchars($grille['promotion']->nom) ?></h3>
        <table>
            <thead>
                <tr>
                    <th class="rank" rowspan="2">N°</th>
                    <th class="student" rowspan="2">Noms & postnoms</th>
                    <th class="code" rowspan="2">Code</th>
                    <?php foreach ($items as $item): ?>
                        <th class="vertical course-col"><?= htmlspecialchars($item->nom) ?><br>/<?= itm_format_points(itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL')) ?></th>
                    <?php endforeach; ?>
                    <th class="total total-col" rowspan="2">Total<br>/<?= itm_format_points($total_maxima) ?></th>
                    <th class="percent" rowspan="2">%</th>
                    <th class="decision" rowspan="2">Décision</th>
                </tr>
                <tr>
                    <?php foreach ($items as $item): ?>
                        <th class="course-col"><?= itm_format_points($item->coefficient) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($apprenants)): ?>
                    <tr><td colspan="<?= count($items) + 6 ?>" class="empty">Aucun apprenant dans cette promotion.</td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($apprenants as $app): ?>
                    <?php $total_obtenu = 0; ?>
                    <tr>
                        <td class="rank"><?= $i++ ?></td>
                        <td class="student"><?= htmlspecialchars(trim($app->nom . ' ' . $app->post_nom . ' ' . $app->prenom)) ?></td>
                        <td class="code"><?= htmlspecialchars($app->code_unique) ?></td>
                        <?php foreach ($items as $item): ?>
                            <?php
                                $note = $notes[$app->id][$item->id] ?? null;
                                $max_item = itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL');
                                $points = $note ? itm_points_for_period($note, $item->semestre, $item->coefficient, 'FINAL') : 0;
                                $line_failed = $note && $points < ($max_item / 2);
                                $total_obtenu += $points;
                            ?>
                            <td class="course-cell <?= $line_failed ? 'fail' : '' ?>"><?= $note ? itm_format_points($points) : '<span class="empty">-</span>' ?></td>
                        <?php endforeach; ?>
                        <?php
                            $pct = $total_maxima > 0 ? ($total_obtenu / $total_maxima) * 100 : 0;
                            $admis = $pct >= 60;
                        ?>
                        <td class="total total-col"><?= itm_format_points($total_obtenu) ?></td>
                        <td class="total percent"><?= number_format($pct, 1) ?>%</td>
                        <td class="decision <?= $admis ? 'pass' : 'fail' ?>"><?= $admis ? 'ADMIS' : 'ECHEC' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>
<script>
    function downloadGridPdf() {
        document.body.classList.add('grid-exporting-pdf');
        window.print();
        setTimeout(function () {
            document.body.classList.remove('grid-exporting-pdf');
        }, 1200);
    }
</script>
</body>
</html>
