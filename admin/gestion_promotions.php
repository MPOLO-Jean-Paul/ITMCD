<?php
session_start();
require_once '../config/db.php';

// Sécurité Admin (Optionnel mais conseillé)
if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";

// 1. Protection contre les doublons
if (isset($_POST['add_promo'])) {
    require_csrf();
    $nom_promo = strtoupper(trim($_POST['nom_promo']));
    if (!empty($nom_promo)) {
        $check = $pdo->prepare("SELECT id FROM promotions WHERE nom = ?");
        $check->execute([$nom_promo]);
        
        if ($check->rowCount() > 0) {
            $message = "danger|Cette promotion existe déjà.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO promotions (nom) VALUES (?)");
            $stmt->execute([$nom_promo]);
            $message = "success|Promotion ajoutée avec succès.";
        }
    }
}

// 2. Suppression
if (isset($_GET['delete'])) {
    if (!isset($_GET['csrf_token']) || !hash_equals(csrf_token(), $_GET['csrf_token'])) {
        http_response_code(419);
        die('Session expiree. Veuillez recharger la page.');
    }
    $pdo->prepare("DELETE FROM promotions WHERE id = ?")->execute([$_GET['delete']]);
    header("Location: gestion_promotions.php"); 
    exit();
}

// 3. Récupération
$promos = $pdo->query("SELECT * FROM promotions ORDER BY nom ASC")->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <style>
        :root { 
            --bg-body: #0f172a; 
            --bg-card: #1e293b; 
            --accent: #38bdf8; 
            --border: rgba(255, 255, 255, 0.08);
            --text-muted: #94a3b8;
        }
        
        body { 
            background-color: var(--bg-body);
            background-image: radial-gradient(circle at 0% 0%, rgba(56, 189, 248, 0.1) 0%, transparent 40%),
                              radial-gradient(circle at 100% 100%, rgba(56, 189, 248, 0.05) 0%, transparent 40%);
            color: #f8fafc; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            min-height: 100vh;
            margin: 0;
        }

        /* HEADER */
        .top-nav { 
            background: rgba(15, 23, 42, 0.8); 
            backdrop-filter: blur(12px); 
            border-bottom: 1px solid var(--border); 
            padding: 15px 20px; 
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        /* CARDS */
        .card-glass { 
            background: var(--bg-card); 
            border: 1px solid var(--border); 
            border-radius: 20px; 
            padding: 24px;
            box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5);
        }

        /* TABLE DESIGN REVISITÉ */
        .table-container {
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .table { margin: 0; }
        .table thead th { 
            background: #f8fafc; 
            color: #475569; 
            font-size: 0.7rem; 
            text-transform: uppercase; 
            letter-spacing: 0.05em;
            padding: 20px; 
            border: none;
        }

        .table td { 
            padding: 18px 20px; 
            border-bottom: 1px solid #f1f5f9; 
            vertical-align: middle;
            color: #1e293b !important;
            font-weight: 600;
        }

        .table tbody tr:hover { background-color: #f1f5f9; }

        /* INPUTS & BUTTONS */
        .form-label-small { 
            color: var(--text-muted); 
            font-size: 0.7rem; 
            font-weight: 700; 
            text-transform: uppercase; 
            margin-bottom: 8px; 
            display: block; 
        }

        .form-control { 
            background: rgba(2, 6, 23, 0.5); 
            border: 1px solid var(--border); 
            color: white; 
            border-radius: 12px; 
            padding: 12px 15px;
            transition: 0.3s;
        }

        .form-control:focus {
            background: rgba(2, 6, 23, 0.8);
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.15);
            color: white;
        }

        .btn-accent { 
            background: var(--accent); 
            color: #0f172a; 
            border: none; 
            padding: 14px; 
            border-radius: 12px; 
            font-weight: 800; 
            width: 100%; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-accent:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(56, 189, 248, 0.3);
            filter: brightness(1.1);
        }

        /* ACTIONS */
        .action-delete {
            background: #fee2e2;
            color: #dc2626;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            text-decoration: none;
            transition: 0.2s;
        }
        .action-delete:hover { background: #ef4444; color: white; transform: scale(1.1); }

        /* RESPONSIVE */
        @media (min-width: 992px) {
            .main-layout { display: grid; grid-template-columns: 350px 1fr; gap: 30px; padding: 30px; }
        }
        @media (max-width: 991px) {
            .main-layout { padding: 15px; }
            .card-glass { margin-bottom: 20px; }
        }
    </style>
</head>
<body>

    <nav class="top-nav d-flex align-items-center">
        <a href="index.php" class="text-white text-decoration-none d-flex align-items-center gap-2 small">
            <span class="material-symbols-rounded fs-5">arrow_back</span> Retour
        </a>
        <div class="ms-auto text-end">
            <h6 class="mb-0 fw-bold" style="letter-spacing: -0.5px;">GESTION PROMOTIONS</h6>
            
        </div>
    </nav>

    <div class="main-layout container-fluid">
        <aside>
            <?php if($message): list($type, $txt) = explode('|', $message); ?>
                <div class="alert alert-<?= $type ?> border-0 rounded-4 mb-4 small fw-bold shadow-sm d-flex align-items-center gap-2">
                    <span class="material-symbols-rounded fs-5">info</span> <?= $txt ?>
                </div>
            <?php endif; ?>

            <div class="card-glass">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="bg-info bg-opacity-10 p-2 rounded-3">
                        <span class="material-symbols-rounded text-info">school</span>
                    </div>
                    <h6 class="fw-bold mb-0">Nouvelle Promotion</h6>
                </div>
                
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-4">
                        <label class="form-label-small">Nom de la promotion</label>
                        <input type="text" name="nom_promo" class="form-control" placeholder="ex: 1ère INF" autocomplete="off" required>
                    </div>
                    <button type="submit" name="add_promo" class="btn-accent d-flex align-items-center justify-content-center gap-2">
                        <span class="material-symbols-rounded fs-5">save</span> ENREGISTRER
                    </button>
                </form>
            </div>
        </aside>

        <main>
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Désignation</th>
                                <th class="text-end pe-4">Options</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($promos as $p): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="badge bg-light text-secondary border">#<?= $p->id ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-primary bg-opacity-10 p-1 rounded-circle d-none d-sm-block"></div>
                                        <?= htmlspecialchars($p->nom) ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end">
                                        <a href="?delete=<?= $p->id ?>&csrf_token=<?= urlencode(csrf_token()) ?>" 
                                           onclick="return confirm('Voulez-vous vraiment supprimer cette promotion ?')" 
                                           class="action-delete" 
                                           title="Supprimer">
                                            <span class="material-symbols-rounded fs-5">delete_sweep</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($promos)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-5">
                                    <img src="https://cdn-icons-png.flaticon.com/512/7486/7486744.png" width="60" class="opacity-25 mb-3" alt="">
                                    <p class="text-muted small fw-bold">Aucune promotion pour le moment.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

</body>
</html>
