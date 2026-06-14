<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'enseignant') {
    header("Location: ../login.php"); exit();
}

$enseignant_id = $_SESSION['user']->id;
$stats_score_sql = "
    CASE
        WHEN ng.cote IS NOT NULL THEN LEAST(
            LEAST(ng.cote, c.coefficient) +
            CASE
                WHEN ng.moyenne IS NOT NULL AND LEAST(ng.cote, c.coefficient) <= (c.coefficient * 0.75)
                THEN (LEAST(GREATEST(ng.moyenne, 0), 10) / 10) * (c.coefficient * 0.25)
                ELSE 0
            END,
            c.coefficient
        )
        WHEN ng.examen IS NOT NULL THEN LEAST(
            LEAST((COALESCE(ng.examen, 0) / 10) * c.coefficient, c.coefficient) +
            CASE
                WHEN ng.moyenne IS NOT NULL AND LEAST((COALESCE(ng.examen, 0) / 10) * c.coefficient, c.coefficient) <= (c.coefficient * 0.75)
                THEN (LEAST(GREATEST(ng.moyenne, 0), 10) / 10) * (c.coefficient * 0.25)
                ELSE 0
            END,
            c.coefficient
        )
        ELSE 0
    END
";

// 1. COMPTER LE TOTAL D'APPRENANTS UNIQUES
$stmt_total = $pdo->prepare("
    SELECT COUNT(DISTINCT a.id) 
    FROM apprenants a
    INNER JOIN promotions p ON a.promotion_id = p.id
    INNER JOIN cours c ON c.promotion_id = p.id
    WHERE c.enseignant_id = ?
");
$stmt_total->execute([$enseignant_id]);
$total_apprenants = (int)$stmt_total->fetchColumn();

// 2. POURCENTAGE GÉNÉRAL DE L'ENSEIGNANT
$stmt_moy = $pdo->prepare("
    SELECT AVG(score_pct) FROM (
        SELECT (($stats_score_sql / c.coefficient) * 100) AS score_pct
        FROM cours c
        LEFT JOIN (
            SELECT apprenant_id, cours_id,
                   MAX(CASE WHEN type_note IN ('cote', 'cote_s1', 'cote_s2') THEN valeur END) AS cote,
                   MAX(CASE WHEN type_note = 'examen' THEN valeur END) AS examen,
                   MAX(CASE WHEN type_note = 'moyenne' THEN valeur END) AS moyenne
            FROM notes
            GROUP BY apprenant_id, cours_id
        ) ng ON ng.cours_id = c.id
        WHERE c.enseignant_id = ? AND (ng.cote IS NOT NULL OR ng.examen IS NOT NULL)
    ) scores
");
$stmt_moy->execute([$enseignant_id]);
$moyenne_gen = round($stmt_moy->fetchColumn() ?? 0, 2);

// 3. NOMBRE DE RÉUSSITES (>= 60% par apprenant sur les éléments du professeur)
$stmt_reussites = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT a.id
        FROM apprenants a
        JOIN cours c ON c.promotion_id = a.promotion_id AND c.enseignant_id = ?
        LEFT JOIN (
            SELECT apprenant_id, cours_id,
                   MAX(CASE WHEN type_note IN ('cote', 'cote_s1', 'cote_s2') THEN valeur END) AS cote,
                   MAX(CASE WHEN type_note = 'examen' THEN valeur END) AS examen,
                   MAX(CASE WHEN type_note = 'moyenne' THEN valeur END) AS moyenne
            FROM notes
            GROUP BY apprenant_id, cours_id
        ) ng ON ng.apprenant_id = a.id AND ng.cours_id = c.id
        GROUP BY a.id
        HAVING (
            SUM($stats_score_sql) / NULLIF(SUM(c.coefficient), 0)
        ) * 100 >= 60
    ) AS table_reussite
");
$stmt_reussites->execute([$enseignant_id]);
$total_reussites = (int)$stmt_reussites->fetchColumn();

$taux_global = ($total_apprenants > 0) ? round(($total_reussites / $total_apprenants) * 100) : 0;

// 4. PERFORMANCE PAR COURS
$stmt_performance = $pdo->prepare("
    SELECT c.id, c.nom as cours_nom, p.nom as promo_nom, p.id as promo_id
    FROM cours c
    JOIN promotions p ON c.promotion_id = p.id
    WHERE c.enseignant_id = ?
");
$stmt_performance->execute([$enseignant_id]);
$performance_data = $stmt_performance->fetchAll(PDO::FETCH_OBJ);

// 5. TOP 3 TALENTS (Basé sur la table apprenants: nom, prenom)
$stmt_top = $pdo->prepare("
    SELECT a.nom, a.prenom, p.nom as promo,
           (
            SUM($stats_score_sql) / NULLIF(SUM(c.coefficient), 0)
           ) * 100 as moyenne_perso
    FROM apprenants a
    JOIN promotions p ON a.promotion_id = p.id
    JOIN cours c ON c.promotion_id = a.promotion_id AND c.enseignant_id = ?
    LEFT JOIN (
        SELECT apprenant_id, cours_id,
               MAX(CASE WHEN type_note IN ('cote', 'cote_s1', 'cote_s2') THEN valeur END) AS cote,
               MAX(CASE WHEN type_note = 'examen' THEN valeur END) AS examen,
               MAX(CASE WHEN type_note = 'moyenne' THEN valeur END) AS moyenne
        FROM notes
        GROUP BY apprenant_id, cours_id
    ) ng ON ng.apprenant_id = a.id AND ng.cours_id = c.id
    GROUP BY a.id, a.nom, a.prenom, p.nom
    ORDER BY moyenne_perso DESC
    LIMIT 3
");
$stmt_top->execute([$enseignant_id]);
$top_talents = $stmt_top->fetchAll(PDO::FETCH_OBJ);

// LOGIQUE REMARQUE
$remarque_titre = "Analyse Statistique";
$remarque_texte = "Données insuffisantes.";
$couleur = "var(--primary)";

if($taux_global >= 70) {
    $remarque_titre = "Excellence Académique 🌟";
    $remarque_texte = "Vos résultats sont excellents. La majorité des étudiants assimilent parfaitement votre contenu.";
    $couleur = "#22c55e";
} elseif($taux_global >= 40) {
    $remarque_titre = "Résultats Stables 📈";
    $remarque_texte = "La progression est constante. Concentrez-vous sur les étudiants en zone rouge pour améliorer le taux.";
    $couleur = "var(--accent)";
} else {
    $remarque_titre = "Attention Requise ⚠️";
    $remarque_texte = "Le taux de réussite est faible. Envisagez une révision des chapitres complexes.";
    $couleur = "#f87171";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700;800&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #030308; --primary: #6366f1; --accent: #22d3ee; --card: rgba(255, 255, 255, 0.03); --border: rgba(255, 255, 255, 0.08); --text-main: #f8fafc; --text-dim: #94a3b8; }
        body { background: var(--bg); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; }
        .header { position: sticky; top: 0; background: rgba(3, 3, 8, 0.8); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border); padding: 20px; display: flex; align-items: center; z-index: 100; }
        .back-btn { text-decoration: none; color: var(--accent); font-size: 1.2rem; margin-right: 15px; }
        .container { padding: 25px 20px 120px; }
        .kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .kpi-card { background: var(--card); border: 1px solid var(--border); border-radius: 24px; padding: 20px; text-align: center; }
        .kpi-val { font-family: 'Space Grotesk'; font-size: 1.8rem; font-weight: 800; display: block; }
        .kpi-label { font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; }
        .remarque-box { margin-bottom: 25px; padding: 20px; background: rgba(255,255,255,0.01); border-radius: 24px; border: 1px dashed <?= $couleur ?>; }
        .chart-section { background: var(--card); border: 1px solid var(--border); border-radius: 30px; padding: 25px; margin-bottom: 25px; }
        .chart-title { font-family: 'Space Grotesk'; font-size: 1.1rem; margin-bottom: 20px; display: block; }
        .talent-card { display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.02); padding: 12px; border-radius: 18px; margin-bottom: 10px; border: 1px solid var(--border); }
        .rank { color: var(--accent); font-weight: 800; width: 25px; }
        .talent-moy { font-weight: 800; background: rgba(99, 102, 241, 0.2); padding: 4px 8px; border-radius: 8px; font-size: 0.75rem; }
        .bar-container { margin-bottom: 18px; }
        .bar-info { display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 8px; }
        .bar-bg { height: 8px; background: rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden; }
        .bar-fill { height: 100%; background: linear-gradient(90deg, var(--primary), var(--accent)); border-radius: 10px; }
        .tab-bar { position: fixed; bottom: 20px; left: 20px; right: 20px; background: rgba(10, 10, 20, 0.9); backdrop-filter: blur(20px); border: 1px solid var(--border); border-radius: 24px; height: 70px; display: flex; align-items: center; justify-content: space-around; }
        .tab-item { color: var(--text-dim); text-decoration: none; display: flex; flex-direction: column; align-items: center; font-size: 1.2rem; }
        .tab-item span { font-size: 0.55rem; font-weight: 800; margin-top: 4px; }
    </style>
</head>
<body>

<div class="header">
    <a href="index.php" class="back-btn">←</a>
    <h1 style="font-family:'Space Grotesk'; font-size:1.1rem; margin:0;"> STATS PERFORMANCES</h1>
</div>

<div class="container">
    <div class="kpi-grid">
        <div class="kpi-card" style="border-bottom: 3px solid var(--accent);">
            <span class="kpi-val"><?= $taux_global ?>%</span>
            <span class="kpi-label">Réussite</span>
        </div>
        <div class="kpi-card" style="border-bottom: 3px solid var(--primary);">
            <span class="kpi-val"><?= $moyenne_gen ?>%</span>
            <span class="kpi-label">Score moyen</span>
        </div>
    </div>

    <div class="remarque-box">
        <strong style="color:<?= $couleur ?>; font-family:'Space Grotesk'; display:block; margin-bottom:6px;">REMARQUE : <?= $remarque_titre ?></strong>
        <p style="margin:0; font-size: 0.8rem; color: var(--text-dim); line-height: 1.5;"><?= $remarque_texte ?></p>
    </div>

    <?php if(!empty($top_talents)): ?>
    <div class="chart-section" style="border: 1px solid rgba(34, 211, 238, 0.15);">
        <span class="chart-title">🏆 Top Talents</span>
        <?php $r=1; foreach($top_talents as $t): ?>
        <div class="talent-card">
            <div class="rank"><?= $r++ ?></div>
            <div style="flex: 1; font-size: 0.85rem;">
                <strong><?= htmlspecialchars($t->nom . ' ' . $t->prenom) ?></strong><br>
                <small style="color:var(--text-dim)"><?= htmlspecialchars($t->promo) ?></small>
            </div>
            <div class="talent-moy"><?= round($t->moyenne_perso, 2) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="chart-section">
        <span class="chart-title">📊 Taux par Promotion</span>
        <?php foreach($performance_data as $pc): 
            // Calcul du total d'apprenants dans la promo
            $st_p = $pdo->prepare("SELECT COUNT(*) FROM apprenants WHERE promotion_id = ?");
            $st_p->execute([$pc->promo_id]);
            $total_p = (int)$st_p->fetchColumn();

            // Calcul des réussites pour cet élément précis
            $st_r = $pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT a.id
                    FROM apprenants a
                    LEFT JOIN (
                        SELECT apprenant_id, cours_id,
                               MAX(CASE WHEN type_note IN ('cote', 'cote_s1', 'cote_s2') THEN valeur END) AS cote,
                               MAX(CASE WHEN type_note = 'examen' THEN valeur END) AS examen,
                               MAX(CASE WHEN type_note = 'moyenne' THEN valeur END) AS moyenne
                        FROM notes
                        GROUP BY apprenant_id, cours_id
                    ) ng ON ng.apprenant_id = a.id AND ng.cours_id = ?
                    JOIN cours c ON c.id = ?
                    WHERE a.promotion_id = c.promotion_id
                    AND (
                        $stats_score_sql / c.coefficient
                    ) * 100 >= 60
                ) as tmp
            ");
            $st_r->execute([$pc->id, $pc->id]);
            $reussites_p = (int)$st_r->fetchColumn();

            $taux_p = ($total_p > 0) ? ($reussites_p / $total_p) * 100 : 0;
        ?>
        <div class="bar-container">
            <div class="bar-info">
                <span><strong><?= htmlspecialchars($pc->promo_nom) ?></strong> <small style="color:var(--text-dim)"><?= htmlspecialchars($pc->cours_nom) ?></small></span>
                <span style="font-weight:700; color:var(--accent);"><?= round($taux_p, 1) ?>%</span>
            </div>
            <div class="bar-bg"><div class="bar-fill" style="width: <?= $taux_p ?>%;"></div></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<nav class="tab-bar">
    <a href="index.php" class="tab-item">🏠<span>HOME</span></a>
    <a href="encodage_examen.php" class="tab-item">📝<span>COTES</span></a>
    <a href="stats_enseignant.php" class="tab-item" style="color:var(--accent)">📈<span>STATS</span></a>
    <a href="../logout.php" class="tab-item" style="color:#f87171;">⎋<span>SORTIE</span></a>
</nav>

</body>
</html>
