<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Installer ITMCD</title>
    <meta name="theme-color" content="#0f6bff">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="../assets/app-shell.css?v=20260610-professional-responsive-ui">
    <script defer src="../assets/app-shell.js?v=20260610-professional-responsive-ui"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --g-blue: #1a73e8;
            --bg: #f0f6ff;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            margin: 0; padding: 20px;
            display: flex; flex-direction: column; align-items: center;
            text-align: center; color: #1d1d1f;
        }
        .container { max-width: 400px; width: 100%; }
        .app-icon {
            width: 100px; height: 100px;
            background: white; border-radius: 22px;
            margin: 40px auto 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex; align-items: center; justify-content: center;
            padding: 10px;
        }
        .app-icon img { width: 100%; height: auto; object-fit: contain; }
        
        h1 { font-size: 24px; font-weight: 800; margin-bottom: 10px; }
        p { font-size: 15px; color: #6e6e73; line-height: 1.5; margin-bottom: 30px; }

        .guide-box {
            background: white; border-radius: 20px;
            padding: 20px; margin-bottom: 20px;
            text-align: left; box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .step { display: flex; align-items: center; gap: 15px; margin-bottom: 15px; }
        .step:last-child { margin-bottom: 0; }
        .step-num {
            min-width: 28px; height: 28px; background: var(--g-blue);
            color: white; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-weight: 800; font-size: 14px;
        }
        .step-txt { font-size: 14px; font-weight: 600; }
        
        .btn-back {
            text-decoration: none; color: var(--g-blue);
            font-weight: 700; font-size: 15px; margin-top: 20px; display: inline-block;
        }
        
        /* Cacher les guides selon l'OS */
        .ios-only, .android-only { display: none; }
    </style>
</head>
<body>

<div class="container">
    <div class="app-icon">
        <img src="../images/LOGO.png" alt="Logo">
    </div>
    
    <h1>ITMCD sur votre mobile</h1>
    <p>Accédez à votre espace enseignant en un clic depuis votre écran d'accueil.</p>

    <div class="guide-box ios-only" id="iosGuide">
        <div style="font-weight: 800; margin-bottom: 15px; color: var(--g-blue);">Instructions pour iPhone :</div>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-txt">Appuyez sur le bouton <strong>Partager</strong> <span style="font-size: 18px;">⎋</span> en bas de Safari.</div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-txt">Faites défiler et appuyez sur <strong>Sur l'écran d'accueil</strong> <span style="font-size: 18px;">⊞</span>.</div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-txt">Appuyez sur <strong>Ajouter</strong> en haut à droite.</div>
        </div>
    </div>

    <div class="guide-box android-only" id="androidGuide">
        <div style="font-weight: 800; margin-bottom: 15px; color: var(--g-blue);">Instructions Android :</div>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-txt">Appuyez sur les <strong>trois points (⋮)</strong> en haut à droite.</div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-txt">Sélectionnez <strong>Installer l'application</strong> ou <strong>Ajouter à l'écran d'accueil</strong>.</div>
        </div>
    </div>

    <a href="index.php" class="btn-back">← Retour à l'accueil</a>
</div>

<script>
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    
    if (isIOS) {
        document.getElementById('iosGuide').style.display = 'block';
    } else {
        document.getElementById('androidGuide').style.display = 'block';
    }
</script>

</body>
</html>
