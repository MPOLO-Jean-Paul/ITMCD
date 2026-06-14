<?php
session_start();
require_once '../config/db.php';
checkRole('admin');

$selected_promotion_id = isset($_GET['promotion_id']) && $_GET['promotion_id'] !== 'all' ? (int)$_GET['promotion_id'] : null;
$promotions = $pdo->query("SELECT id, nom FROM promotions ORDER BY nom ASC")->fetchAll(PDO::FETCH_OBJ);

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
    if (!empty($items) && !empty($apprenants)) {
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
    }

    $grilles[] = [
        'promotion' => $promo,
        'items' => $items,
        'apprenants' => $apprenants,
        'notes' => $notes,
    ];
}

$all_promotions = $pdo->query("SELECT id, nom FROM promotions ORDER BY nom ASC")->fetchAll(PDO::FETCH_OBJ);

$annee_libelle = $pdo->query("SELECT libelle FROM annees_scolaires WHERE est_active = 1 LIMIT 1")->fetchColumn() ?: date('Y');

function grid_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function grid_student_name($student) {
    return trim(($student->nom ?? '') . ' ' . ($student->post_nom ?? '') . ' ' . ($student->prenom ?? ''));
}

function grid_export_suffix($selectedPromotionId, array $grilles) {
    $suffix = $selectedPromotionId ? ('_' . ($grilles[0]['promotion']->nom ?? 'promotion')) : '_toutes_promotions';
    return preg_replace('/[^A-Za-z0-9_-]+/', '_', $suffix);
}

function grid_total_maxima(array $items) {
    return array_sum(array_map(function ($item) {
        return itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL');
    }, $items));
}

function grid_print_metrics($courseCount, $studentCount) {
    $courseCount = max((int)$courseCount, 1);
    $studentCount = max((int)$studentCount, 1);
    $density = min(1, 18 / $courseCount);

    $rank = $courseCount >= 28 ? 4.6 : 5.5;
    $student = $courseCount >= 34 ? 24 : ($courseCount >= 26 ? 29 : ($courseCount >= 18 ? 34 : 42));
    $code = $courseCount >= 34 ? 9 : ($courseCount >= 24 ? 11 : 15);
    $total = $courseCount >= 30 ? 8 : 10.5;
    $percent = $courseCount >= 30 ? 7 : 9;
    $decision = $courseCount >= 30 ? 8.5 : 12;
    $reserved = $rank + $student + $code + $total + $percent + $decision;
    $courseWidth = round(max(2.8, min(11, (285 - $reserved) / $courseCount)), 2);
    $vertical = round(max(10.5, min(22, 21 * $density + 2)), 2);
    $rowHeight = round(max(0.78, min(4.05, (148 - $vertical) / $studentCount)), 2);
    $printFont = round(max(2.7, min(6.7, min($rowHeight * 1.82, 6.4 * $density + 1.05))), 2);
    $printPad = round(max(0.2, min(1.45, $rowHeight * 0.20)), 2);
    $screenTableWidth = max(940, 340 + ($courseCount * 50));
    $screenCourseWidth = max(38, min(62, (int)round($courseWidth * 8.5)));
    $screenFont = round(max(8.2, min(11.4, 12.1 - ($courseCount * 0.09))), 2);

    return [
        'rank' => $rank,
        'student' => $student,
        'code' => $code,
        'total' => $total,
        'percent' => $percent,
        'decision' => $decision,
        'course_width' => $courseWidth,
        'vertical' => $vertical,
        'row_height' => $rowHeight,
        'print_font' => $printFont,
        'print_pad' => $printPad,
        'screen_table_width' => $screenTableWidth,
        'screen_course_width' => $screenCourseWidth,
        'screen_font' => $screenFont,
    ];
}

function grid_plain_text($value) {
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', $text));
    return trim($text);
}

function grid_short_text($value, $maxChars) {
    $text = grid_plain_text($value);
    $maxChars = (int)$maxChars;
    if ($maxChars <= 0) {
        return $text;
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $maxChars ? mb_substr($text, 0, max(1, $maxChars - 1), 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $maxChars ? substr($text, 0, max(1, $maxChars - 1)) . '...' : $text;
}

function grid_pdf_escape($value) {
    $text = grid_plain_text($value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function grid_pdf_text_width($value, $size) {
    $text = grid_plain_text($value);
    return strlen($text) * $size * 0.48;
}

function grid_pdf_text(&$content, $pageHeight, $x, $topY, $size, $text, $font = 'F1', $align = 'left', $maxChars = 0) {
    $text = grid_short_text($text, $maxChars);
    $drawX = $x;
    if ($align === 'center') {
        $drawX = $x - (grid_pdf_text_width($text, $size) / 2);
    } elseif ($align === 'right') {
        $drawX = $x - grid_pdf_text_width($text, $size);
    }
    $content .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $drawX, $pageHeight - $topY, grid_pdf_escape($text));
}

function grid_pdf_rotated_text(&$content, $pageHeight, $x, $topY, $size, $text, $maxChars = 0) {
    $text = grid_short_text($text, $maxChars);
    $content .= sprintf("BT /F1 %.2F Tf 0 1 -1 0 %.2F %.2F Tm (%s) Tj ET\n", $size, $x, $pageHeight - $topY, grid_pdf_escape($text));
}

function grid_pdf_rect(&$content, $pageHeight, $x, $topY, $width, $height, $fill = false) {
    $content .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $pageHeight - $topY - $height, $width, $height, $fill ? 'f' : 'S');
}

function grid_pdf_cell(&$content, $pageHeight, $x, $topY, $width, $height, $text, $size, $align = 'left', $bold = false, $fill = false) {
    if ($fill) {
        $content .= "0.93 0.95 0.98 rg\n";
        grid_pdf_rect($content, $pageHeight, $x, $topY, $width, $height, true);
        $content .= "0 0 0 rg\n";
    }
    grid_pdf_rect($content, $pageHeight, $x, $topY, $width, $height, false);
    $padding = 2.2;
    $maxChars = max(1, (int)(($width - ($padding * 2)) / max(1, $size * 0.48)));
    $textY = $topY + ($height / 2) + ($size * 0.33);
    if ($align === 'center') {
        grid_pdf_text($content, $pageHeight, $x + ($width / 2), $textY, $size, $text, $bold ? 'F2' : 'F1', 'center', $maxChars);
    } elseif ($align === 'right') {
        grid_pdf_text($content, $pageHeight, $x + $width - $padding, $textY, $size, $text, $bold ? 'F2' : 'F1', 'right', $maxChars);
    } else {
        grid_pdf_text($content, $pageHeight, $x + $padding, $textY, $size, $text, $bold ? 'F2' : 'F1', 'left', $maxChars);
    }
}

function grid_pdf_page_content(array $grille, $anneeLibelle) {
    $pageWidth = 841.89;
    $pageHeight = 595.28;
    $margin = 20.5;
    $contentWidth = $pageWidth - ($margin * 2);
    $items = $grille['items'];
    $apprenants = $grille['apprenants'];
    $notes = $grille['notes'];
    $courseCount = max(count($items), 1);
    $studentCount = max(count($apprenants), 1);
    $metrics = grid_print_metrics($courseCount, $studentCount);
    $totalMaxima = grid_total_maxima($items);

    $rankW = $metrics['rank'] * 2.83465;
    $studentW = $metrics['student'] * 2.83465;
    $codeW = $metrics['code'] * 2.83465;
    $totalW = $metrics['total'] * 2.83465;
    $percentW = $metrics['percent'] * 2.83465;
    $decisionW = $metrics['decision'] * 2.83465;
    $courseW = (count($items) > 0)
        ? max(7.2, ($contentWidth - $rankW - $studentW - $codeW - $totalW - $percentW - $decisionW) / count($items))
        : 0;
    $headerH = max(28, $metrics['vertical'] * 2.83465);
    $subHeaderH = 10;
    $tableTop = 100;
    $footerTop = 556;
    $bodyHeight = max(90, $footerTop - $tableTop - $headerH - $subHeaderH - 5);
    $rowH = min($metrics['row_height'] * 2.83465, $bodyHeight / $studentCount);
    $rowH = max(2.4, $rowH);
    $font = max(2.6, min($metrics['print_font'], $rowH * 0.66, $courseW * 0.63));

    $content = "0 0 0 RG 0 0 0 rg 0.45 w\n";
    grid_pdf_text($content, $pageHeight, $pageWidth / 2, 27, 10.5, 'REPUBLIQUE DEMOCRATIQUE DU CONGO', 'F2', 'center');
    grid_pdf_text($content, $pageHeight, $pageWidth / 2, 40, 7.6, 'Ministere de la Sante Publique', 'F1', 'center');
    grid_pdf_text($content, $pageHeight, $pageWidth / 2, 52, 8.2, 'INSTITUT TECHNIQUE MEDICAL COEUR DE DIEU (ITMCD)', 'F2', 'center');
    $content .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $margin, $pageHeight - 60, $pageWidth - $margin, $pageHeight - 60);
    grid_pdf_text($content, $pageHeight, $pageWidth / 2, 76, 10.2, 'GRILLE DES COTES', 'F2', 'center');
    grid_pdf_text($content, $pageHeight, $pageWidth / 2, 90, 8.2, 'Promotion : ' . $grille['promotion']->nom . ' | Annee scolaire ' . $anneeLibelle, 'F1', 'center');

    $x = $margin;
    $y = $tableTop;
    grid_pdf_cell($content, $pageHeight, $x, $y, $rankW, $headerH + $subHeaderH, 'N°', $font, 'center', true, true);
    $x += $rankW;
    grid_pdf_cell($content, $pageHeight, $x, $y, $studentW, $headerH + $subHeaderH, 'Noms & postnoms', $font, 'left', true, true);
    $x += $studentW;
    grid_pdf_cell($content, $pageHeight, $x, $y, $codeW, $headerH + $subHeaderH, 'Code', $font, 'center', true, true);
    $x += $codeW;

    foreach ($items as $item) {
        grid_pdf_rect($content, $pageHeight, $x, $y, $courseW, $headerH, false);
        $label = $item->nom . ' /' . itm_format_points(itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL'));
        grid_pdf_rotated_text($content, $pageHeight, $x + ($courseW / 2) + ($font / 2), $y + $headerH - 3, max(2.4, $font * 0.88), $label, max(6, (int)($headerH / max(1, $font * 0.45))));
        grid_pdf_cell($content, $pageHeight, $x, $y + $headerH, $courseW, $subHeaderH, itm_format_points($item->coefficient), $font, 'center', true, true);
        $x += $courseW;
    }

    grid_pdf_cell($content, $pageHeight, $x, $y, $totalW, $headerH + $subHeaderH, 'Total /' . itm_format_points($totalMaxima), $font, 'center', true, true);
    $x += $totalW;
    grid_pdf_cell($content, $pageHeight, $x, $y, $percentW, $headerH + $subHeaderH, '%', $font, 'center', true, true);
    $x += $percentW;
    grid_pdf_cell($content, $pageHeight, $x, $y, $decisionW, $headerH + $subHeaderH, 'Decision', $font, 'center', true, true);

    $y += $headerH + $subHeaderH;
    $i = 1;
    foreach ($apprenants as $app) {
        $x = $margin;
        $totalObtenu = 0;
        grid_pdf_cell($content, $pageHeight, $x, $y, $rankW, $rowH, $i++, $font, 'center');
        $x += $rankW;
        grid_pdf_cell($content, $pageHeight, $x, $y, $studentW, $rowH, grid_student_name($app), $font, 'left', true);
        $x += $studentW;
        grid_pdf_cell($content, $pageHeight, $x, $y, $codeW, $rowH, $app->code_unique, $font, 'center');
        $x += $codeW;

        foreach ($items as $item) {
            $note = $notes[$app->id][$item->id] ?? null;
            $points = $note ? itm_points_for_period($note, $item->semestre, $item->coefficient, 'FINAL') : 0;
            $totalObtenu += $points;
            grid_pdf_cell($content, $pageHeight, $x, $y, $courseW, $rowH, $note ? itm_format_points($points) : '-', $font, 'center');
            $x += $courseW;
        }

        $pct = $totalMaxima > 0 ? ($totalObtenu / $totalMaxima) * 100 : 0;
        grid_pdf_cell($content, $pageHeight, $x, $y, $totalW, $rowH, itm_format_points($totalObtenu), $font, 'center', true);
        $x += $totalW;
        grid_pdf_cell($content, $pageHeight, $x, $y, $percentW, $rowH, number_format($pct, 1) . '%', $font, 'center', true);
        $x += $percentW;
        grid_pdf_cell($content, $pageHeight, $x, $y, $decisionW, $rowH, $pct >= 60 ? 'ADMIS' : 'ECHEC', $font, 'center', true);
        $y += $rowH;
    }

    if (empty($apprenants)) {
        grid_pdf_cell($content, $pageHeight, $margin, $y, $contentWidth, 18, 'Aucun apprenant dans cette promotion.', 7, 'center');
    }

    $content .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $margin, $pageHeight - 536, $pageWidth - $margin, $pageHeight - 536);
    grid_pdf_text($content, $pageHeight, $margin + 40, 548, 7.2, 'Le Prefet', 'F2', 'center');
    grid_pdf_text($content, $pageHeight, $margin + 40, 563, 6.7, 'NDOSI WA NGOY Gloire', 'F1', 'center');
    grid_pdf_text($content, $pageHeight, $pageWidth - $margin - 48, 548, 7.2, 'La Proviseur', 'F2', 'center');
    grid_pdf_text($content, $pageHeight, $pageWidth - $margin - 48, 563, 6.7, 'NONGO MUTOMBO Urcile', 'F1', 'center');
    grid_pdf_text($content, $pageHeight, $pageWidth / 2, 582, 6.5, 'Fait a Lubumbashi, le ' . date('d/m/Y') . '. Grille securisee generee par ITMCD-System.', 'F1', 'center');

    return $content;
}

function grid_send_pdf(array $grilles, $anneeLibelle, $filename) {
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $kids = [];
    $nextId = 5;

    foreach ($grilles as $grille) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $kids[] = $pageId . ' 0 R';
        $content = grid_pdf_page_content($grille, $anneeLibelle);
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 841.89 595.28] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    $maxObject = max(array_keys($objects));
    for ($i = 1; $i <= $maxObject; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . ($objects[$i] ?? '<< >>') . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (($_GET['action'] ?? '') === 'excel') {
    while (ob_get_level()) { ob_end_clean(); }
    $filename = 'grille_des_cotes' . grid_export_suffix($selected_promotion_id, $grilles) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"><style>';
    echo '@page{mso-page-orientation:landscape;margin:.28in .22in .28in .22in;}';
    echo 'body{font-family:Arial,sans-serif;font-size:8pt;color:#111827;}';
    echo '.sheet{page-break-after:always;} .sheet:last-child{page-break-after:auto;}';
    echo 'table{border-collapse:collapse;width:100%;} th,td{border:1px solid #111827;padding:2px 3px;text-align:center;vertical-align:middle;}';
    echo 'th{background:#e5e7eb;font-weight:bold;} .left{text-align:left;} .title{font-weight:bold;text-align:center;font-size:11pt;} .sub{text-align:center;font-size:8pt;} .footer{font-size:7pt;color:#475569;}';
    echo '</style></head><body>';
    foreach ($grilles as $grille) {
        $items = $grille['items'];
        $apprenants = $grille['apprenants'];
        $notes = $grille['notes'];
        $total_maxima = grid_total_maxima($items);
        echo '<div class="sheet"><table>';
        echo '<tr><td colspan="' . (count($items) + 6) . '" class="sub">REPUBLIQUE DEMOCRATIQUE DU CONGO<br>Ministère de la Santé Publique<br><strong>INSTITUT TECHNIQUE MEDICAL COEUR DE DIEU (ITMCD)</strong></td></tr>';
        echo '<tr><td colspan="' . (count($items) + 6) . '" class="title">GRILLE DES COTES</td></tr>';
        echo '<tr><td colspan="' . (count($items) + 6) . '" class="sub">Promotion : ' . grid_e($grille['promotion']->nom) . ' | Année scolaire ' . grid_e($annee_libelle) . '</td></tr>';
        echo '<tr><th>N°</th><th>Noms & postnoms</th><th>Code</th>';
        foreach ($items as $item) {
            echo '<th>' . grid_e($item->nom) . ' /' . itm_format_points(itm_effective_maxima($item->semestre, $item->coefficient, 'FINAL')) . '</th>';
        }
        echo '<th>Total /' . itm_format_points($total_maxima) . '</th><th>%</th><th>Decision</th></tr>';

        $i = 1;
        foreach ($apprenants as $app) {
            $total_obtenu = 0;
            echo '<tr><td>' . $i++ . '</td><td class="left">' . grid_e(grid_student_name($app)) . '</td><td>' . grid_e($app->code_unique) . '</td>';
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
        echo '<tr><td colspan="' . (count($items) + 6) . '" class="footer">Le Préfet : NDOSI WA NGOY Gloire &nbsp;&nbsp; | &nbsp;&nbsp; La Proviseur : NONGO MUTOMBO Urcile<br>Fait à Lubumbashi, le ' . date('d/m/Y') . '. Grille sécurisée générée par ITMCD-System.</td></tr>';
        echo '</table></div>';
    }
    echo '</body></html>';
    exit;
}

if (($_GET['action'] ?? '') === 'pdf') {
    $filename = 'grille_des_cotes' . grid_export_suffix($selected_promotion_id, $grilles) . '.pdf';
    grid_send_pdf($grilles, $annee_libelle, $filename);
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
        body { font-family: Arial, sans-serif; background: #eef3f8; color: #111827; margin: 0; min-height: 100vh; overflow-y: auto; font-size: 11px; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: flex-start; flex-wrap: wrap; min-height: 62px; padding: 10px 18px; background: rgba(255,255,255,.94); border-bottom: 1px solid #dbe5f0; box-shadow: 0 12px 28px rgba(15,23,42,.08); backdrop-filter: blur(16px); }
        .toolbar form { margin: 0 0 0 auto; min-width: min(260px, 100%); }
        .toolbar a, .toolbar button, .toolbar select { border: 1px solid #d1d5db; background: #fff; color: #111827; border-radius: 8px; padding: 9px 12px; text-decoration: none; font-weight: 800; max-width: 100%; }
        .toolbar a[href*="action=pdf"] { background:#111827; color:#fff; border-color:#111827; }
        .toolbar select { width: 100%; }
        .sheet { box-sizing: border-box; background: #fff; margin: 18px auto; padding: 14px; width: min(calc(100% - 24px), 1240px); box-shadow: 0 18px 45px rgba(15,23,42,.10); border: 1px solid #dbe5f0; border-radius: 10px; overflow: auto; }
        .grid-header { text-align:center; border-bottom:2px double #111827; padding-bottom:7px; margin-bottom:9px; min-width: var(--screen-table-width, 900px); }
        .grid-header h3 { font-size:13px; font-weight:800; margin:0; text-transform:uppercase; line-height:1.15; }
        .grid-header p { font-size:9px; margin:1px 0 0; line-height:1.2; }
        .grid-title { text-align:center; min-width: var(--screen-table-width, 900px); margin-bottom:8px; }
        .grid-title h2 { margin:0; font-size:15px; text-transform:uppercase; text-decoration:underline; line-height:1.15; }
        .grid-title p { margin:3px 0 0; color:#475569; font-size:10px; font-weight:700; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; min-width: var(--screen-table-width, 900px); table-layout: fixed; font-size: var(--screen-font, 11px); }
        th, td { border: 1px solid #111827; padding: 4px; text-align: center; vertical-align: middle; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; overflow-wrap: normal; word-break: normal; }
        th { background: #e5e7eb; font-weight: 800; }
        .rank { width: 42px; min-width: 42px; }
        .student { width: 190px; min-width: 190px; text-align: left; font-weight: 700; white-space: normal; }
        .code { width: 76px; min-width: 76px; }
        .total-col { width: 68px; min-width: 68px; }
        .percent { width: 58px; min-width: 58px; }
        .decision { width: 70px; min-width: 70px; }
        .vertical { writing-mode: vertical-rl; transform: rotate(180deg); height: 130px; min-width: 28px; max-width: 36px; white-space: normal; overflow-wrap: anywhere; }
        .course-col, .course-cell { width: var(--screen-course-width, 48px); }
        .total { background: #f3f4f6; font-weight: 800; }
        .pass { color: #047857; font-weight: 800; }
        .fail { color: #b91c1c; font-weight: 800; }
        .empty { color: #9ca3af; }
        .grid-footer { min-width: var(--screen-table-width, 900px); border-top:1px solid #111827; margin-top:10px; padding-top:8px; color:#475569; font-size:9px; }
        .grid-signatures { display:grid; grid-template-columns:1fr 1fr; gap:18px; text-align:center; color:#111827; font-weight:700; margin-bottom:8px; }
        .grid-signatures span { display:block; min-height:18px; text-decoration:underline; }
        .grid-generated { text-align:center; }
        @page { size: A4 landscape; margin: 0; }
        @media screen and (max-width: 720px) {
            .toolbar { align-items: stretch; }
            .toolbar form { margin-left: 0; flex: 1 1 100%; }
            .toolbar a, .toolbar button { flex: 1 1 auto; text-align: center; }
            .sheet { width: calc(100% - 16px); margin: 10px auto; padding: 10px; }
        }
        @media print {
            html, body { width: 297mm !important; min-width: 297mm !important; height: auto !important; min-height: 210mm !important; overflow: visible !important; }
            body, body.itm-modern-ui, body.itm-has-fixed-header { background: #fff !important; padding: 0 !important; margin: 0 !important; font-size: var(--print-font, 6px); }
            body::before { content: none !important; display: none !important; }
            .toolbar, .itm-theme-switch, .itm-theme-slot, .itm-auto-header, .itm-fixed-header { display: none !important; }
            .sheet {
                box-shadow: none;
                border: 0;
                border-radius: 0;
                margin: 0;
                box-sizing: border-box;
                padding: 4.8mm 5.8mm;
                width: 297mm !important;
                max-width: 297mm !important;
                height: 210mm !important;
                max-height: 210mm !important;
                overflow: hidden !important;
                page-break-after: auto;
                page-break-inside: avoid;
                break-after: auto;
                break-inside: avoid;
                display: flex;
                flex-direction: column;
            }
            .sheet:not(:last-of-type) { page-break-after: always; break-after: page; }
            .sheet:last-of-type { page-break-after: auto; break-after: auto; }
            .grid-header { flex:0 0 auto; min-width:0 !important; padding-bottom:1.4mm; margin-bottom:1.2mm; }
            .grid-header h3 { font-size:9px; line-height:1.05; }
            .grid-header p { font-size:6.5px; line-height:1.08; margin-top:.2mm; }
            .grid-title { flex:0 0 auto; min-width:0 !important; margin-bottom:1mm; }
            .grid-title h2 { font-size:9px; line-height:1.05; }
            .grid-title p { font-size:6.8px; line-height:1.05; margin-top:.5mm; }
            table {
                min-width: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                table-layout: fixed;
                margin-top: 0;
                font-size: var(--print-font, 7px);
                line-height: 1.05;
                flex: 1 1 auto;
            }
            th, td {
                padding: var(--print-pad, 1px);
                border-width: .6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            thead th { padding: .7px; }
            tbody tr { height: var(--print-row-height, 4mm); }
            .rank { width: var(--rank-width, 5.5mm) !important; }
            .student { width: var(--student-width, 38mm) !important; min-width: var(--student-width, 38mm) !important; max-width: var(--student-width, 38mm) !important; }
            .code { width: var(--code-width, 14mm) !important; min-width: var(--code-width, 14mm) !important; max-width: var(--code-width, 14mm) !important; }
            .total-col { width: var(--total-width, 10mm) !important; }
            .percent { width: var(--percent-width, 9mm) !important; }
            .decision { width: var(--decision-width, 12mm) !important; min-width: var(--decision-width, 12mm) !important; }
            .course-col, .course-cell { width: var(--course-width, 6mm) !important; min-width: var(--course-width, 6mm) !important; max-width: var(--course-width, 6mm) !important; }
            .vertical {
                height: var(--vertical-height, 24mm) !important;
                min-width: var(--course-width, 6mm) !important;
                max-width: var(--course-width, 6mm) !important;
                white-space: normal;
                text-overflow: clip;
            }
            .grid-footer { flex:0 0 auto; min-width:0 !important; margin-top:1.2mm; padding-top:1mm; font-size:6px; line-height:1.08; }
            .grid-signatures { margin-bottom:.7mm; gap:8mm; }
            .grid-signatures span { min-height:3.6mm; }
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
    <a href="grille_inf1.php?<?= htmlspecialchars($exportQuery . '&action=pdf', ENT_QUOTES, 'UTF-8') ?>">Export PDF</a>
    <a href="grille_inf1.php?<?= htmlspecialchars($exportQuery . '&action=excel', ENT_QUOTES, 'UTF-8') ?>">Export Excel</a>
</div>

<?php foreach ($grilles as $grille): ?>
    <?php
        $items = $grille['items'];
        $apprenants = $grille['apprenants'];
        $notes = $grille['notes'];
        $total_maxima = grid_total_maxima($items);
        $course_count = max(count($items), 1);
        $student_count = max(count($apprenants), 1);
        $metrics = grid_print_metrics($course_count, $student_count);
        $print_style = "--course-count: {$course_count}; --student-count: {$student_count}; --screen-table-width: {$metrics['screen_table_width']}px; --screen-course-width: {$metrics['screen_course_width']}px; --screen-font: {$metrics['screen_font']}px; --print-font: {$metrics['print_font']}px; --print-pad: {$metrics['print_pad']}px; --vertical-height: {$metrics['vertical']}mm; --print-row-height: {$metrics['row_height']}mm; --rank-width: {$metrics['rank']}mm; --student-width: {$metrics['student']}mm; --code-width: {$metrics['code']}mm; --total-width: {$metrics['total']}mm; --percent-width: {$metrics['percent']}mm; --decision-width: {$metrics['decision']}mm; --course-width: {$metrics['course_width']}mm;";
    ?>
    <section class="sheet" style="<?= $print_style ?>">
        <div class="grid-header">
            <h3>REPUBLIQUE DEMOCRATIQUE DU CONGO</h3>
            <p>Ministère de la Santé Publique</p>
            <p><strong>INSTITUT TECHNIQUE MEDICAL COEUR DE DIEU (ITMCD)</strong></p>
        </div>
        <div class="grid-title">
            <h2>Grille des cotes</h2>
            <p>Promotion : <?= htmlspecialchars($grille['promotion']->nom) ?> | Année scolaire <?= htmlspecialchars($annee_libelle) ?></p>
        </div>
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
                        <td class="student"><?= htmlspecialchars(grid_student_name($app)) ?></td>
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
        <footer class="grid-footer">
            <div class="grid-signatures">
                <div><span>Le Préfet</span>NDOSI WA NGOY Gloire</div>
                <div><span>La Proviseur</span>NONGO MUTOMBO Urcile</div>
            </div>
            <div class="grid-generated">
                Fait à Lubumbashi, le <?= date('d/m/Y') ?>. Grille sécurisée générée par ITMCD-System.
            </div>
        </footer>
    </section>
<?php endforeach; ?>
</body>
</html>
