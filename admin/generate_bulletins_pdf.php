<?php
session_start();
require_once '../config/db.php';
checkRole('admin');

$promotion = $_GET['promotion'] ?? 'all';

if ($promotion === 'all') {
    $apprenants = $pdo->query("SELECT id FROM apprenants ORDER BY promotion_id, nom")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id FROM apprenants WHERE promotion_id = ?");
    $stmt->execute([$promotion]);
    $apprenants = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>ITMCD</title>
<link rel="icon" type="image/png" href="../images/LOGO.png">
<?= app_shell_assets('../') ?>

<!-- ⚠️ EXACTEMENT LES MÊMES STYLES -->
<?= file_get_contents('bulletin_styles.css'); ?>

<style>
.page{
    page-break-after: always;
}
</style>
</head>
<body>

<?php foreach($apprenants as $ap): ?>
<div class="page">
    <?php
    $_GET['apprenant_id'] = $ap->id;
    include 'bulletin.php';
    ?>
</div>
<?php endforeach; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(async ()=>{
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF('p','mm','a4');

    const pages = document.querySelectorAll('.page');

    for(let i=0;i<pages.length;i++){
        const canvas = await html2canvas(pages[i], { scale: 2 });
        const img = canvas.toDataURL('image/png');

        if(i > 0) pdf.addPage();
        pdf.addImage(img, 'PNG', 0, 0, 210, 297);
    }

    pdf.save("bulletins_ITMCD.pdf");
})();
</script>

</body>
</html>
