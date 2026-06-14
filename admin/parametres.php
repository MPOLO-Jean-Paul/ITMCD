<?php
session_start();
require_once '../config/db.php';
checkRole('admin');

$settings = [
    'feature_encode_s1' => ['label' => 'Encodage semestre 1', 'hint' => 'Autorise les enseignants a saisir les cotes du premier semestre.', 'icon' => 'counter_1', 'default' => true],
    'feature_encode_s2' => ['label' => 'Encodage semestre 2', 'hint' => 'Autorise les enseignants a saisir les cotes du second semestre.', 'icon' => 'counter_2', 'default' => true],
    'feature_encode_moyennes' => ['label' => 'Encodage des moyennes de secours', 'hint' => 'Autorise les enseignants a saisir la moyenne sur 10 qui soutient les cotes d examen faibles.', 'icon' => 'add_chart', 'default' => true],
    'feature_update_notes' => ['label' => 'Mise a jour des cotes', 'hint' => 'Autorise les enseignants a remplacer une cote deja enregistree.', 'icon' => 'edit_square', 'default' => false],
    'feature_download_notes' => ['label' => 'Telechargements', 'hint' => 'Donne acces aux releves complets et aux listes des echecs.', 'icon' => 'download', 'default' => true],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $previousValues = [];
    foreach ($settings as $key => $setting) {
        $previousValues[$key] = itm_setting($pdo, $key, $setting['default']);
    }

    $notifiedTeachers = 0;
    foreach ($settings as $key => $setting) {
        $enabled = isset($_POST[$key]);
        itm_save_setting($pdo, $key, $enabled);

        if (in_array($key, ['feature_encode_s1', 'feature_encode_s2'], true) && $previousValues[$key] !== $enabled) {
            $period = $key === 'feature_encode_s2' ? 'S2' : 'S1';
            $notifiedTeachers += itm_notify_teachers_encoding_toggle($pdo, $period, $enabled);
        }

        if ($key === 'feature_encode_moyennes' && $previousValues[$key] !== $enabled) {
            $notifiedTeachers += itm_notify_role(
                $pdo,
                'enseignant',
                $enabled
                    ? "Encodage des moyennes de secours ouvert : vous pouvez saisir les moyennes sur 10."
                    : "Encodage des moyennes de secours ferme : la saisie des moyennes est desactivee.",
                'moyenne'
            );
        }
    }
    header('Location: parametres.php?status=saved&notified=' . $notifiedTeachers);
    exit();
}

$values = [];
foreach ($settings as $key => $setting) {
    $values[$key] = itm_setting($pdo, $key, $setting['default']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parametres ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet">
    <style>
        :root { --bg:#eef3f8; --panel:#fff; --panel-soft:#f8fafc; --ink:#132033; --muted:#64748b; --line:#dbe5f0; --blue:#2563eb; --cyan:#0891b2; --green:#15803d; --red:#b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, sans-serif; background: var(--bg); color: var(--ink); }
        .wrap { width: min(1040px, calc(100% - 28px)); margin: 32px auto; }
        .top { display: flex; align-items: center; justify-content: space-between; gap:16px; margin-bottom: 22px; }
        a { color: var(--blue); text-decoration: none; font-weight: 800; }
        h1 { margin: 0; font-size: 1.65rem; letter-spacing:0; }
        .subtitle { color:var(--muted); margin:6px 0 0; line-height:1.45; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 8px 20px 20px; box-shadow:0 18px 45px rgba(15,23,42,.08); overflow:hidden; }
        .setting { display: grid; grid-template-columns: auto 1fr auto auto; gap: 16px; align-items: center; padding: 18px 0; border-bottom: 1px solid var(--line); }
        .setting:last-of-type { border-bottom: 0; }
        .setting strong { display: block; margin-bottom: 5px; font-size:1rem; }
        .setting span { color: var(--muted); font-size: .9rem; line-height:1.35; }
        .setting-icon { width:42px; height:42px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#eff6ff; color:var(--blue); border:1px solid #bfdbfe; }
        .status-pill { border-radius: 999px; padding: 7px 10px; font-size:.75rem; font-weight:850; white-space:nowrap; }
        .status-on { color: var(--green); background:#dcfce7; }
        .status-off { color: var(--red); background:#fee2e2; }
        .switch { position: relative; width: 58px; height: 32px; flex: 0 0 58px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; inset: 0; cursor: pointer; background: #cbd5e1; border-radius: 999px; transition: .2s; }
        .slider:before { content: ""; position: absolute; height: 24px; width: 24px; left: 4px; top: 4px; background: white; border-radius: 50%; transition: .2s; }
        .switch input:checked + .slider { background: linear-gradient(135deg, var(--blue), var(--cyan)); }
        .switch input:checked + .slider:before { transform: translateX(26px); }
        .btn { margin-top: 14px; border: 0; background: linear-gradient(135deg, var(--blue), var(--cyan)); color: #fff; border-radius: 8px; padding: 15px 18px; font-weight: 850; cursor: pointer; width: 100%; box-shadow:0 12px 24px rgba(37,99,235,.18); }
        .ok { background: #dcfce7; color: var(--green); padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-weight:800; border:1px solid #bbf7d0; }
        .back-link { display:inline-flex; align-items:center; gap:6px; min-height:40px; padding:8px 12px; border:1px solid var(--line); border-radius:8px; background:var(--panel-soft); }
        @media (max-width: 700px) {
            .top { align-items:flex-start; flex-direction:column-reverse; }
            .setting { grid-template-columns: auto 1fr; gap: 12px; }
            .status-pill { grid-column:2; width:max-content; max-width:100%; }
            .switch { grid-column:2; }
        }
    </style>
</head>
<body>
<main class="wrap">
    <div class="top">
        <a href="index.php" class="back-link"><span class="material-symbols-rounded" aria-hidden="true">arrow_back</span>Retour</a>
        <div>
            <h1>Parametres enseignants</h1>
            <p class="subtitle">Controlez les actions disponibles sur les ecrans enseignant.</p>
        </div>
    </div>

    <?php if (($_GET['status'] ?? '') === 'saved'): ?>
        <div class="ok">
            Parametres sauvegardes.
            <?php if ((int)($_GET['notified'] ?? 0) > 0): ?>
                <?= (int)$_GET['notified'] ?> notification(s) envoyee(s) aux enseignants.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="panel">
        <?= csrf_field() ?>
        <?php foreach ($settings as $key => $setting): ?>
            <label class="setting">
                <span class="setting-icon material-symbols-rounded" aria-hidden="true"><?= htmlspecialchars($setting['icon']) ?></span>
                <div>
                    <strong><?= htmlspecialchars($setting['label']) ?></strong>
                    <span><?= htmlspecialchars($setting['hint']) ?></span>
                </div>
                <span class="status-pill <?= $values[$key] ? 'status-on' : 'status-off' ?>"><?= $values[$key] ? 'Actif' : 'Bloque' ?></span>
                <span class="switch">
                    <input type="checkbox" name="<?= htmlspecialchars($key) ?>" <?= $values[$key] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </span>
            </label>
        <?php endforeach; ?>
        <button class="btn" type="submit">Enregistrer les parametres</button>
    </form>
</main>
</body>
</html>
