<?php
session_start();
require_once 'config/db.php';

$message = "";

if (!isset($_SESSION['user']) && isset($_COOKIE['remember_token'])) {
    $rememberHash = hash('sha256', $_COOKIE['remember_token']);
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE remember_token = ?");
    $stmt->execute([$rememberHash]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        header("Location: {$user->role}/index.php");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    require_csrf();
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);

    if ($user && password_verify($password, $user->mot_de_passe)) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE utilisateurs SET remember_token=? WHERE id=?")->execute([hash('sha256', $token), $user->id]);
            setcookie('remember_token', $token, secure_remember_cookie_options(time() + (86400 * 30)));
        }
        $redirect = "{$user->role}/index.php";
    } else {
        $message = "<div class='alert-msg'><i class='bi bi-shield-slash-fill'></i> Échec d'authentification système</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>ITMCD | Accès Sécurisé</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('') ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script>
        (function () {
            try {
                const saved = localStorage.getItem('itmcd-app-shell-v1:theme') || localStorage.getItem('itmcd-theme') || 'light';
                document.documentElement.setAttribute('data-theme', saved === 'dark' ? 'dark' : 'light');
            } catch {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --login-bg: #eef3f8;
            --login-bg-strong: #ffffff;
            --login-card: rgba(255, 255, 255, .96);
            --login-card-solid: #ffffff;
            --login-field: #f8fafc;
            --login-field-focus: #ffffff;
            --login-ink: #101828;
            --login-strong: #0f172a;
            --login-muted: #64748b;
            --login-line: rgba(15, 23, 42, .12);
            --login-brand: #1d4ed8;
            --login-brand-2: #0e7490;
            --login-danger: #dc2626;
            --login-success: #16a34a;
            --login-shadow: 0 22px 60px rgba(15, 23, 42, .16);
            --login-soft-shadow: 0 10px 26px rgba(15, 23, 42, .08);
            --login-radius: 8px;
        }

        html[data-theme="dark"] {
            color-scheme: dark;
            --login-bg: #0b111f;
            --login-bg-strong: #151922;
            --login-card: rgba(22, 26, 34, .96);
            --login-card-solid: #171b24;
            --login-field: #111722;
            --login-field-focus: #151d2b;
            --login-ink: #e7ecf3;
            --login-strong: #ffffff;
            --login-muted: #a9b2bf;
            --login-line: rgba(226, 232, 240, .15);
            --login-brand: #6aa6ff;
            --login-brand-2: #3dd4c8;
            --login-danger: #ff7b8a;
            --login-success: #55d98b;
            --login-shadow: 0 24px 64px rgba(0, 0, 0, .36);
            --login-soft-shadow: 0 14px 34px rgba(0, 0, 0, .25);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        body.login-shell {
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, var(--login-bg-strong) 0%, var(--login-bg) 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--login-ink);
            overflow-x: hidden;
            position: relative;
            padding: max(18px, env(safe-area-inset-top)) 18px max(18px, env(safe-area-inset-bottom));
            -webkit-tap-highlight-color: transparent;
        }

        body.login-shell::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(15, 23, 42, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15, 23, 42, .035) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .42), transparent 68%);
        }

        html[data-theme="dark"] body.login-shell::before {
            background-image:
                linear-gradient(rgba(226, 232, 240, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(226, 232, 240, .035) 1px, transparent 1px);
        }

        .login-layout {
            width: min(100%, 340px);
            min-height: calc(100dvh - 36px);
            display: grid;
            align-content: center;
            gap: 16px;
            position: relative;
            z-index: 1;
        }

        .login-topbar {
            position: fixed;
            top: calc(14px + env(safe-area-inset-top));
            right: 14px;
            z-index: 40;
        }

        .login-theme-control {
            display: inline-flex;
            gap: 4px;
            padding: 4px;
            background: color-mix(in srgb, var(--login-card-solid) 92%, transparent);
            border: 1px solid var(--login-line);
            border-radius: var(--login-radius);
            box-shadow: var(--login-soft-shadow);
            backdrop-filter: blur(18px) saturate(150%);
            -webkit-backdrop-filter: blur(18px) saturate(150%);
        }

        .theme-choice {
            min-height: 34px;
            border: 0;
            border-radius: 6px;
            padding: 0 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: transparent;
            color: var(--login-muted);
            font: 800 12px/1 'Plus Jakarta Sans', sans-serif;
            cursor: pointer;
        }

        .theme-choice.active {
            background: linear-gradient(135deg, var(--login-brand), var(--login-brand-2));
            color: #ffffff;
        }

        .login-card {
            width: 100%;
            padding: clamp(24px, 6vw, 34px);
            background: var(--login-card);
            backdrop-filter: blur(22px) saturate(150%);
            -webkit-backdrop-filter: blur(22px) saturate(150%);
            border: 1px solid var(--login-line);
            border-radius: var(--login-radius);
            box-shadow: var(--login-shadow);
            display: block;
            position: relative;
            opacity: 0;
            transform: translateY(10px);
            animation: loginEnter .36s cubic-bezier(.2, .8, .2, 1) forwards;
        }

        .login-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 22px;
        }

        .logo-container {
            width: 70px;
            height: 70px;
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--login-field);
            border: 1px solid var(--login-line);
            border-radius: var(--login-radius);
            box-shadow: var(--login-soft-shadow);
        }

        .logo-img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            filter: drop-shadow(0 10px 14px rgba(29, 78, 216, .14));
        }

        .login-badge {
            min-height: 36px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            border-radius: var(--login-radius);
            background: color-mix(in srgb, var(--login-success) 11%, transparent);
            color: var(--login-success);
            font-size: 12px;
            font-weight: 800;
            border: 1px solid color-mix(in srgb, var(--login-success) 26%, transparent);
        }

        h1 {
            font-size: clamp(24px, 6vw, 30px);
            line-height: 1.08;
            font-weight: 850;
            text-align: left;
            color: var(--login-strong);
            letter-spacing: 0;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--login-muted);
            font-size: 14px;
            font-weight: 650;
            line-height: 1.5;
            margin-bottom: 26px;
        }

        .form-field {
            display: grid;
            gap: 8px;
            margin-bottom: 16px;
        }

        .form-field label {
            color: var(--login-strong);
            font-size: 12px;
            font-weight: 850;
        }

        .input-shell {
            position: relative;
        }

        .form-input {
            width: 100%;
            min-height: 54px;
            padding: 15px 52px;
            background: var(--login-field);
            border: 1px solid var(--login-line);
            border-radius: var(--login-radius);
            color: var(--login-ink);
            font-size: 15px;
            font-weight: 700;
            outline: none;
            transition: border-color .2s ease, background .2s ease, box-shadow .2s ease;
        }

        .form-input::placeholder {
            color: color-mix(in srgb, var(--login-muted) 72%, transparent);
        }

        .form-input::-ms-reveal,
        .form-input::-ms-clear {
            display: none;
        }

        .form-input:focus {
            border-color: color-mix(in srgb, var(--login-brand) 75%, transparent);
            background: var(--login-field-focus);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--login-brand) 16%, transparent);
        }

        .input-shell i.main-icon {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--login-muted);
            font-size: 18px;
            pointer-events: none;
            line-height: 1;
        }

        .input-shell.has-password .form-input {
            padding-right: 58px;
        }

        .toggle-password {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border: 0;
            border-radius: var(--login-radius);
            background: transparent;
            color: var(--login-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .15s ease, color .15s ease;
        }

        .toggle-password:hover {
            background: color-mix(in srgb, var(--login-brand) 10%, transparent);
            color: var(--login-brand);
        }

        .toggle-password:focus-visible {
            outline: none;
            background: color-mix(in srgb, var(--login-brand) 12%, transparent);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--login-brand) 18%, transparent);
            color: var(--login-brand);
        }

        .toggle-password.is-visible {
            background: color-mix(in srgb, var(--login-brand) 12%, transparent);
            color: var(--login-brand);
        }

        .toggle-password i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            font-size: 17px;
            line-height: 1;
            pointer-events: none;
        }

        .toggle-password i::before {
            display: block;
            line-height: 1;
        }

        .toggle-password:active {
            transform: translateY(-50%) scale(.93);
        }

        .theme-choice:active {
            transform: scale(.985);
        }

        .btn-submit:active {
            transform: scale(.985);
        }

        .remember-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 4px 0 22px;
        }

        .remember-label {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 34px;
            cursor: pointer;
            color: var(--login-muted);
            font-size: 13px;
            font-weight: 750;
            line-height: 1.2;
        }

        .remember-label input {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            flex: 0 0 20px;
            margin: 0;
            border: 1px solid var(--login-line);
            border-radius: 7px;
            background: var(--login-field);
            accent-color: var(--login-brand);
            display: inline-grid;
            place-items: center;
        }

        .remember-label input::after {
            content: "";
            width: 9px;
            height: 5px;
            border-left: 2px solid #fff;
            border-bottom: 2px solid #fff;
            transform: rotate(-45deg) scale(0);
            transform-origin: center;
            transition: transform .16s ease;
        }

        .remember-label input:checked {
            background: linear-gradient(135deg, var(--login-brand), var(--login-brand-2));
            border-color: transparent;
        }

        .remember-label input:checked::after {
            transform: rotate(-45deg) scale(1);
        }

        .remember-label span {
            display: inline-flex;
            align-items: center;
            min-height: 20px;
        }

        .btn-submit {
            width: 100%;
            min-height: 54px;
            padding: 0 18px;
            background: linear-gradient(135deg, var(--login-brand), var(--login-brand-2));
            border: none;
            border-radius: var(--login-radius);
            color: #fff;
            font-size: 15px;
            font-weight: 850;
            letter-spacing: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
            box-shadow: 0 14px 30px color-mix(in srgb, var(--login-brand) 26%, transparent);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 36px color-mix(in srgb, var(--login-brand) 32%, transparent);
        }

        .btn-submit[aria-busy="true"] {
            opacity: .88;
            cursor: progress;
        }

        #transitionLayer {
            position: fixed;
            inset: 0;
            background: linear-gradient(180deg, #0f172a 0%, #020617 100%);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .loader-box {
            text-align: center;
            padding: 30px;
            width: 90%;
            max-width: 350px;
        }

        .percent-text {
            font-family: 'JetBrains Mono', monospace;
            font-size: 54px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 10px;
        }

        .alert-msg {
            background: color-mix(in srgb, var(--login-danger) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--login-danger) 24%, transparent);
            color: var(--login-danger);
            padding: 12px 14px;
            border-radius: var(--login-radius);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-footer {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid var(--login-line);
            text-align: center;
            color: var(--login-muted);
            font-size: 11px;
            font-weight: 800;
        }

        .spin {
            display: inline-block;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes loginEnter { to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 520px) {
            body.login-shell {
                padding-left: 14px;
                padding-right: 14px;
            }

            .login-layout {
                width: calc(100vw - 28px);
                max-width: calc(100vw - 28px);
                min-height: calc(100dvh - 28px);
                align-content: end;
                padding-top: 62px;
            }

            .login-card {
                width: 100%;
                max-width: 100%;
            }

            .login-topbar {
                left: 50%;
                right: auto;
                transform: translateX(-50%);
            }

            .login-card { padding: 22px; }

            .login-card-top {
                margin-bottom: 18px;
            }

            .login-badge {
                width: 38px;
                justify-content: center;
                padding: 0;
            }

            .login-badge span {
                display: none;
            }

            .logo-container {
                width: 62px;
                height: 62px;
            }

            .logo-img {
                width: 50px;
                height: 50px;
            }
        }

        @media (max-height: 700px) and (min-width: 521px) {
            .login-layout {
                align-content: center;
                padding-top: 56px;
            }

            .login-card {
                padding: 22px;
            }
        }

        .login-layout {
            width: min(100%, 340px);
            max-width: calc(100vw - 54px);
        }

        .login-card {
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }

        .login-card-top {
            display: grid;
            grid-template-columns: auto minmax(0, max-content);
            align-items: center;
        }

        .login-badge {
            max-width: 100%;
            min-width: 0;
            justify-self: end;
        }

        .login-card h1,
        .login-card .subtitle,
        .login-card label,
        .remember-label,
        .login-footer {
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        @media (max-width: 520px) {
            body.login-shell {
                padding-left: 16px;
                padding-right: 16px;
            }

            .login-layout {
                width: min(340px, calc(100vw - 54px));
                max-width: calc(100vw - 54px);
            }

            .login-card {
                padding-left: 18px;
                padding-right: 18px;
            }
        }
    </style>
</head>
<body class="login-shell">
    <main class="login-layout">
        <div class="login-topbar">
            <div class="login-theme-control" role="group" aria-label="Theme">
                <button type="button" class="theme-choice" data-login-theme="light">
                    <i class="bi bi-sun"></i><span>Clair</span>
                </button>
                <button type="button" class="theme-choice" data-login-theme="dark">
                    <i class="bi bi-moon-stars"></i><span>Sombre</span>
                </button>
            </div>
        </div>

    <div id="transitionLayer">
        <div class="loader-box">
            <img src="images/LOGO.png" style="width: 50px; margin-bottom: 20px;" alt="Logo">
            <div class="percent-text" id="percentCount">00%</div>
            <p id="statusText" style="font-family:'JetBrains Mono'; font-size:10px; color:#6aa6ff; letter-spacing:3px; text-transform:uppercase;">Initialisation...</p>
            <div style="width: 100%; height: 2px; background: rgba(255,255,255,0.05); margin-top: 20px; position: relative; overflow: hidden;">
                <div id="progressFill" style="position: absolute; left: 0; top: 0; height: 100%; width: 0%; background: #6aa6ff; box-shadow: 0 0 15px #6aa6ff; transition: width 0.1s linear;"></div>
            </div>
        </div>
    </div>

    <section class="login-card" id="loginCard" aria-labelledby="loginTitle">
        <div class="login-card-top">
            <div class="logo-container">
                <img src="images/LOGO.png" alt="Logo" class="logo-img">
            </div>
            <div class="login-badge">
                <i class="bi bi-shield-check"></i>
                <span>Accès sécurisé</span>
            </div>
        </div>
        <h1 id="loginTitle">Bienvenue sur ITMCD</h1>
        <p class="subtitle">Connectez-vous à votre espace de gestion académique.</p>

        <?php if($message) echo $message; ?>

        <form method="POST" id="loginForm">
            <?= csrf_field() ?>
            <div class="form-field">
                <label for="email">Adresse email</label>
                <div class="input-shell">
                    <input type="email" name="email" id="email" class="form-input" placeholder="nom@itmcd.app" required autocomplete="email">
                    <i class="bi bi-person-fill main-icon"></i>
                </div>
            </div>

            <div class="form-field">
                <label for="password">Mot de passe</label>
                <div class="input-shell has-password">
                    <input type="password" name="password" id="password" class="form-input" placeholder="Votre mot de passe" required autocomplete="current-password">
                    <i class="bi bi-key-fill main-icon"></i>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Afficher le mot de passe" aria-controls="password" aria-pressed="false" title="Afficher le mot de passe">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="remember-row">
                <label class="remember-label">
                    <input type="checkbox" name="remember">
                    <span>Rester connecté</span>
                </label>
            </div>

            <button type="submit" name="login" class="btn-submit" id="submitBtn">
                <i class="bi bi-box-arrow-in-right"></i>
                <span>Se connecter</span>
            </button>
        </form>

        <div class="login-footer">
            &copy; <?= date('Y') ?> ITMCD
        </div>
    </section>
    </main>

    <script>
        const form = document.getElementById('loginForm');
        const card = document.getElementById('loginCard');
        const transition = document.getElementById('transitionLayer');
        const themeButtons = document.querySelectorAll('[data-login-theme]');
        const themeKeys = ['itmcd-app-shell-v1:theme', 'itmcd-theme'];

        function setLoginTheme(theme) {
            const selected = theme === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', selected);
            document.body.dataset.itmTheme = selected;
            document.body.dataset.itmResolvedTheme = selected;
            try {
                themeKeys.forEach((key) => localStorage.setItem(key, selected));
            } catch {}
            themeButtons.forEach((button) => {
                const active = button.dataset.loginTheme === selected;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            card.style.display = 'block';
            let selected = 'light';
            try {
                selected = localStorage.getItem('itmcd-app-shell-v1:theme') || localStorage.getItem('itmcd-theme') || 'light';
            } catch {}
            setLoginTheme(selected);
        });

        form.addEventListener('submit', () => {
            const submit = document.getElementById('submitBtn');
            submit.setAttribute('aria-busy', 'true');
            submit.innerHTML = '<i class="bi bi-arrow-repeat spin"></i><span>Connexion...</span>';
        });

        themeButtons.forEach((button) => {
            button.addEventListener('click', () => setLoginTheme(button.dataset.loginTheme));
        });

        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('togglePassword');
        const passwordToggleIcon = passwordToggle.querySelector('i');

        function syncPasswordVisibility(isVisible) {
            passwordInput.type = isVisible ? 'text' : 'password';
            passwordToggleIcon.className = 'bi ' + (isVisible ? 'bi-eye-slash' : 'bi-eye');
            passwordToggle.classList.toggle('is-visible', isVisible);
            passwordToggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
            const label = isVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
            passwordToggle.setAttribute('aria-label', label);
            passwordToggle.title = label;
        }

        passwordToggle.addEventListener('mousedown', (event) => {
            event.preventDefault();
        });

        passwordToggle.addEventListener('click', () => {
            syncPasswordVisibility(passwordInput.type === 'password');
            passwordInput.focus({ preventScroll: true });
        });

        <?php if(isset($redirect)): ?>
        window.onload = () => {
            card.style.display = 'block';
            card.style.opacity = "0";
            transition.style.display = 'flex';
            let progress = 0;
            const steps = [{p:25,m:"Sécurisation..."}, {p:55,m:"Vérification..."}, {p:82,m:"Préparation..."}, {p:100,m:"Accès accordé"}];
            let stepIdx = 0;
            const interval = setInterval(() => {
                progress += 1;
                document.getElementById('percentCount').innerText = progress.toString().padStart(2, '0') + "%";
                document.getElementById('progressFill').style.width = progress + "%";
                if(stepIdx < steps.length && progress >= steps[stepIdx].p) {
                    document.getElementById('statusText').innerText = steps[stepIdx].m;
                    stepIdx++;
                }
                if(progress >= 100) {
                    clearInterval(interval);
                    setTimeout(() => { 
                        transition.style.opacity = "0";
                        transition.style.transition = "opacity 0.6s ease";
                        setTimeout(() => { window.location.href = "<?= $redirect ?>"; }, 600);
                    }, 500);
                }
            }, 60);
        };
        <?php endif; ?>
    </script>
</body>
</html>
