<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";

$stmt_annee = $pdo->query("SELECT id, libelle FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
$annee_active_data = $stmt_annee->fetch(PDO::FETCH_OBJ);

if (!$annee_active_data) {
    $message = "<div class='alert-custom alert-danger-custom'>
                    <i class='bi bi-exclamation-triangle-fill'></i> 
                    <div><b>Erreur Système :</b> Aucune année scolaire n'est active.</div>
                </div>";
} else {
    $annee_id = $annee_active_data->id;
    $annee_libelle = $annee_active_data->libelle;

    if (isset($_POST['add_apprenant'])) {
        require_csrf();
        $nom = strtoupper(trim($_POST['nom']));
        $post_nom = strtoupper(trim($_POST['post_nom']));
        $prenom = ucfirst(trim($_POST['prenom']));
        $promotion_id = $_POST['promotion_id'];

        $check = $pdo->prepare("SELECT id FROM apprenants WHERE nom = ? AND post_nom = ? AND prenom = ? AND promotion_id = ? AND annee_scolaire_id = ?");
        $check->execute([$nom, $post_nom, $prenom, $promotion_id, $annee_id]);

        if ($check->rowCount() > 0) {
            $message = "<div class='alert-custom alert-warning-custom'>
                            <i class='bi bi-exclamation-octagon-fill'></i> 
                            <div><b>Attention :</b> Cet apprenant est déjà inscrit pour l'année $annee_libelle.</div>
                        </div>";
        } else {
            $c = '0123456789';
            $l = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            $code_unique = $l[rand(0, 23)] . $c[rand(0, 9)] . $c[rand(0, 9)] . $l[rand(0, 23)] . $c[rand(0, 9)] . $l[rand(0, 23)];

            try {
                $sql = "INSERT INTO apprenants (nom, post_nom, prenom, promotion_id, code_unique, annee_scolaire_id) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $post_nom, $prenom, $promotion_id, $code_unique, $annee_id]);
                
                $message = "<div class='alert-custom alert-success-custom'>
                                <i class='bi bi-check-circle-fill'></i> 
                                <div>Inscription réussie ! Code : <b class='text-warning'>$code_unique</b></div>
                            </div>";
            } catch (PDOException $e) {
                $message = "<div class='alert-custom alert-danger-custom'>
                                <i class='bi bi-x-circle-fill'></i> Erreur technique lors de l'enregistrement.
                            </div>";
            }
        }
    }
}

$promotions = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --bg-body: #020617; 
            --bg-card: rgba(30, 41, 59, 0.7); 
            --accent: #38bdf8; 
            --accent-hover: #0ea5e9;
            --white: #ffffff; 
            --sidebar-width: 280px;
            --glass-border: rgba(255, 255, 255, 0.08);
        }

        body { 
            background: radial-gradient(circle at top right, #0f172a, #020617);
            color: var(--white);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            min-height: 100vh;
        }

        /* SIDEBAR ULTRA */
        #sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0; top: 0;
            background: rgba(2, 6, 23, 0.8);
            backdrop-filter: blur(12px);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            z-index: 1050;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #sidebar,
        .mobile-header {
            display: none !important;
        }

        #content { 
            margin: 0 auto; 
            padding: 40px 20px; 
            width: min(980px, 100%);
            transition: all 0.3s;
        }

        .nav-link {
            padding: 14px 24px;
            margin: 4px 15px;
            border-radius: 12px;
            display: flex; align-items: center; gap: 12px;
            text-decoration: none; color: #94a3b8 !important;
            transition: 0.2s;
        }

        .nav-link:hover { background: rgba(255,255,255,0.05); color: var(--white) !important; }
        .nav-link.active { background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(56, 189, 248, 0.05)); color: var(--accent) !important; border-left: 4px solid var(--accent); }

        /* CARD GLASSMORPHISM */
        .form-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            padding: 40px;
            max-width: 700px;
            margin: 20px auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-label { font-size: 0.75rem; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px; }

        .form-control, .form-select {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--glass-border);
            color: white !important;
            padding: 14px;
            border-radius: 12px;
            transition: 0.3s;
        }

        .form-control:focus, .form-select:focus { 
            border-color: var(--accent); 
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.15); 
            background: #0f172a; 
        }

        /* BUTTON DESIGN */
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            border: none; color: #020617;
            font-weight: 700; padding: 16px;
            border-radius: 14px;
            transition: 0.3s;
            box-shadow: 0 10px 15px -3px rgba(56, 189, 248, 0.3);
        }

        .btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgba(56, 189, 248, 0.4); opacity: 0.9; }

        /* CUSTOM ALERTS */
        .alert-custom {
            padding: 16px;
            border-radius: 14px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid transparent;
        }
        .alert-success-custom { background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .alert-danger-custom { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: #f87171; }
        .alert-warning-custom { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); color: #fbbf24; }

        /* MOBILE OPTIMIZATION */
        .mobile-header {
            display: none;
            padding: 15px 20px;
            background: #020617;
            border-bottom: 1px solid var(--glass-border);
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0; z-index: 1100;
        }

        @media (max-width: 992px) {
            #content { padding: 20px; }
            .form-card { padding: 25px; }
        }

        /* Session Indicator */
        .session-badge {
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.2);
            padding: 12px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: var(--accent);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

    <div class="mobile-header">
        <h4 class="mb-0 fw-bold">ITMCD<span style="color:var(--accent)">.</span></h4>
        <button class="btn text-white p-0" onclick="toggleSidebar()">
            <i class="bi bi-list fs-2"></i>
        </button>
    </div>

    <nav id="sidebar">
        <div class="p-4">
            <h4 class="mb-0 fw-bold">ITMCD.<span style="color:var(--accent)">.</span></h4>
        </div>
        <div class="nav flex-column mt-3">
            <a href="index.php" class="nav-link"><i class="bi bi-grid-1x2-fill"></i>Accueil</a>
            <a href="liste_globale.php" class="nav-link"><i class="bi bi-people-fill"></i> Apprenants</a>
            <a href="ajouter_apprenant.php" class="nav-link active"><i class="bi bi-person-plus-fill"></i> Inscription</a>
        </div>
        
        <div class="mt-auto p-3">
            <hr class="opacity-10">
            <a href="../logout.php" class="nav-link text-danger">
                <i class="bi bi-box-arrow-right"></i> Déconnexion
            </a>
        </div>
    </nav>

    <main id="content">
        <div class="text-center mb-5">
            <h2 class="fw-bold mb-1">NOUVELLE INSCRIPTION</h2>
            <p class="text-secondary">Remplissez les informations pour générer un code unique</p>
        </div>

        <div class="form-card">
            <?= $message ?>
            
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label text-uppercase fw-bold">Nom</label>
                        <input type="text" name="nom" class="form-control" required placeholder="NOM">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-uppercase fw-bold">Post-nom</label>
                        <input type="text" name="post_nom" class="form-control" required placeholder="POST-NOM">
                    </div>
                    <div class="col-12">
                        <label class="form-label text-uppercase fw-bold">Prénom</label>
                        <input type="text" name="prenom" class="form-control" required placeholder="Prénom de l'élève">
                    </div>
                    <div class="col-12">
                        <label class="form-label text-uppercase fw-bold">Promotion</label>
                        <select name="promotion_id" class="form-select" required>
                            <option value="" hidden>Choisir une promotion...</option>
                            <?php foreach($promotions as $p): ?>
                                <option value="<?= $p->id ?>"><?= htmlspecialchars($p->nom) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="my-4 session-badge">
                    <i class="bi bi-calendar-check"></i>
                    <span>Session active : <b><?= $annee_libelle ?? 'Non définie' ?></b></span>
                </div>

                <button type="submit" name="add_apprenant" class="btn btn-primary w-100 mt-2" <?= !$annee_active_data ? 'disabled' : '' ?>>
                    <i class="bi bi-person-check-fill me-2"></i> VALIDER L'INSCRIPTION
                </button>
            </form>
        </div>
    </main>

    <script>
        function toggleSidebar() {}
    </script>
</body>
</html>
