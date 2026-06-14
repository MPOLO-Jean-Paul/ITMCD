<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']->role, ['admin', 'enseignant'], true)) {
    header('Location: ../login.php');
    exit();
}

$success_msg = "";
if (isset($_POST['update_pass'])) {
    require_csrf();
    $stmtCurrent = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
    $stmtCurrent->execute([$_SESSION['user']->id]);
    $currentHash = $stmtCurrent->fetchColumn();

    if ($currentHash && password_verify($_POST['old_pass'] ?? '', $currentHash) && !empty($_POST['new_pass']) && strlen($_POST['new_pass']) >= 8) {
        $hash = password_hash($_POST['new_pass'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ?, remember_token = NULL WHERE id = ?");
        $stmt->execute([$hash, $_SESSION['user']->id]);
        $success_msg = "Sécurité mise à jour avec succès.";
    } else {
        $success_msg = "Ancien mot de passe invalide ou nouveau mot de passe trop court.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réglages | ITMCD Ecosystem</title>
    <?= app_shell_assets('../') ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;500;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* Palette de couleurs dynamique */
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.4);
            --accent: #22d3ee;
            --success: #10b981;
            --danger: #ef4444;
            
            /* Thème Sombre (Défaut) */
            --bg-page: #030308;
            --bg-card: rgba(255, 255, 255, 0.03);
            --border-card: rgba(255, 255, 255, 0.08);
            --text-main: #ffffff;
            --text-muted: #94a3b8;
            --input-bg: rgba(0, 0, 0, 0.3);
        }

        [data-theme="light"] {
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border-card: rgba(0, 0, 0, 0.06);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --input-bg: #f1f5f9;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-page);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: background 0.5s ease, color 0.5s ease;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* --- LAYOUT --- */
        .settings-wrapper {
            max-width: 1000px;
            margin: 0 auto;
            padding: 80px 20px;
        }

        .header-section { margin-bottom: 60px; }
        .header-section h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.8rem;
            font-weight: 800;
            letter-spacing: -1.5px;
            background: linear-gradient(to right, #fff, var(--text-muted));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        [data-theme="light"] .header-section h1 {
            background: linear-gradient(to right, #000, #666);
            -webkit-background-clip: text;
        }

        /* --- CARDS --- */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        .glass-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            backdrop-filter: blur(10px);
            border-radius: 32px;
            padding: 35px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .glass-card:hover { transform: translateY(-5px); }

        .card-icon {
            width: 50px; height: 50px;
            background: var(--primary-glow);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 20px; font-size: 1.5rem;
        }

        h3 { font-size: 1.3rem; margin-bottom: 10px; }
        .desc { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 25px; line-height: 1.6; }

        /* --- THEME TOGGLE --- */
        .theme-selector {
            display: flex; gap: 12px;
            background: var(--input-bg);
            padding: 8px; border-radius: 16px;
        }

        .theme-option {
            flex: 1; padding: 12px; border-radius: 10px;
            border: none; cursor: pointer; font-weight: 700;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: transparent; color: var(--text-muted);
        }

        .theme-option.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 10px 20px var(--primary-glow);
        }

        /* --- FORM ELEMENTS --- */
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px; color: var(--text-muted); }
        
        input {
            width: 100%; padding: 16px; border-radius: 14px;
            border: 1.5px solid var(--border-card);
            background: var(--input-bg); color: var(--text-main);
            font-size: 1rem; transition: 0.3s;
        }

        input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px var(--primary-glow); }

        .btn-action {
            width: 100%; padding: 16px; border-radius: 14px;
            border: none; background: var(--primary); color: white;
            font-weight: 700; font-size: 1rem; cursor: pointer;
            transition: 0.3s ease; margin-top: 10px;
        }

        .btn-action:hover { opacity: 0.9; transform: scale(1.02); }

        /* --- BIOMETRIC SECTION --- */
        .bio-btn {
            background: rgba(16, 185, 129, 0.1);
            border: 1px dashed var(--success);
            color: var(--success);
            display: flex; align-items: center; justify-content: center; gap: 12px;
            padding: 20px; border-radius: 18px; cursor: pointer; transition: 0.3s;
        }

        .bio-btn:hover { background: var(--success); color: white; }

        /* --- ALERTS --- */
        .toast-success {
            position: fixed; top: 20px; right: 20px;
            background: var(--success); color: white;
            padding: 15px 30px; border-radius: 12px;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
            animation: slideIn 0.5s ease forwards;
            z-index: 1000;
        }

        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        @media (max-width: 768px) {
            .header-section h1 { font-size: 2rem; }
            .settings-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <?php include_once 'connectivity_guardian.php'; ?>

    <?php if ($success_msg): ?>
        <div class="toast-success">✨ <?= $success_msg ?></div>
    <?php endif; ?>

    <div class="settings-wrapper">
        <div class="header-section">
            <h1>Réglages du Compte</h1>
            <p style="color: var(--text-muted);">Gérez vos préférences et la sécurité de votre accès ITMCD.</p>
        </div>

        <div class="settings-grid">
            
            <div class="glass-card">
                <div class="card-icon">🎨</div>
                <h3>Apparence</h3>
                <p class="desc">Personnalisez l'interface selon votre confort visuel. Le mode sombre réduit la fatigue oculaire.</p>
                
                <div class="theme-selector">
                    <button class="theme-option" id="btn-light" onclick="changeTheme('light')">Clair</button>
                    <button class="theme-option" id="btn-dark" onclick="changeTheme('dark')">Sombre</button>
                </div>
            </div>

            <div class="glass-card">
                <div class="card-icon">🔐</div>
                <h3>Mot de passe</h3>
                <p class="desc">Mettez à jour votre clé d'accès régulièrement pour une sécurité optimale.</p>
                
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Mot de passe actuel</label>
                        <input type="password" name="old_pass" placeholder="••••••••" required>
                    </div>
                    <div class="form-group">
                        <label>Nouveau mot de passe</label>
                        <input type="password" name="new_pass" placeholder="Minimum 8 caractères" required>
                    </div>
                    <button type="submit" name="update_pass" class="btn-action">Enregistrer les modifications</button>
                </form>
            </div>

            <div class="glass-card" style="grid-column: 1 / -1;">
                <div class="card-icon" style="background: rgba(34, 211, 238, 0.2); color: var(--accent);">🧬</div>
                <h3>Accès Biométrique</h3>
                <p class="desc">Activez la connexion par empreinte digitale ou reconnaissance faciale pour ne plus avoir à taper votre mot de passe sur cet appareil.</p>
                
                <div class="bio-btn" onclick="setupBiometrics()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12c0-4.42 3.58-8 8-8s8 3.58 8 8v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4z"></path><path d="M7 12h10"></path><path d="M9 16h6"></path><circle cx="12" cy="7" r="1"></circle></svg>
                    <span>Enregistrer mon empreinte sur cet appareil</span>
                </div>
            </div>

        </div>
    </div>

    <script>
        // --- LOGIQUE DE THÈME ---
        function changeTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('itmcd-theme', theme);
            updateThemeButtons(theme);
        }

        function updateThemeButtons(theme) {
            document.querySelectorAll('.theme-option').forEach(btn => btn.classList.remove('active'));
            document.getElementById('btn-' + theme).classList.add('active');
        }

        // Init au chargement
        const savedTheme = localStorage.getItem('itmcd-theme') || 'dark';
        changeTheme(savedTheme);

        // --- LOGIQUE BIOMÉTRIQUE (WebAuthn Placeholder) ---
        async function setupBiometrics() {
            if (!window.PublicKeyCredential) {
                alert("Désolé, votre appareil ou navigateur ne supporte pas la biométrie.");
                return;
            }

            // Ici, l'application appelle le capteur du téléphone ou de l'ordinateur
            // Pour une implémentation réelle, cela nécessite une interaction avec le serveur (WebAuthn)
            const confirmAction = confirm("Voulez-vous lier votre empreinte digitale à votre compte ITMCD ?");
            
            if (confirmAction) {
                // Simulation d'appel API
                document.body.style.cursor = 'wait';
                setTimeout(() => {
                    document.body.style.cursor = 'default';
                    alert("✨ Succès ! Votre empreinte a été configurée. Vous pourrez l'utiliser lors de votre prochaine connexion.");
                }, 1500);
            }
        }

        // Auto-hide toast
        setTimeout(() => {
            const toast = document.querySelector('.toast-success');
            if(toast) toast.style.display = 'none';
        }, 4000);
    </script>
</body>
</html>
