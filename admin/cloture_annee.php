<?php
session_start();
require_once '../config/db.php';

// Sécurité Admin
if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";

// 1. LOGIQUE DE CRÉATION/ACTIVATION D'ANNÉE
if (isset($_POST['creer_annee'])) {
    require_csrf();
    $libelle = htmlspecialchars(trim($_POST['libelle_annee']));
    
    // VERIFICATION DOUBLON : On vérifie si le libellé existe déjà
    $check = $pdo->prepare("SELECT id FROM annees_scolaires WHERE libelle = ?");
    $check->execute([$libelle]);
    
    if ($check->rowCount() > 0) {
        $message = "danger|L'année scolaire '$libelle' existe déjà dans le système.";
    } else {
        $pdo->beginTransaction();
        try {
            // Désactiver toutes les années
            $pdo->query("UPDATE annees_scolaires SET est_active = 0");
            
            // Insérer et activer la nouvelle
            $ins = $pdo->prepare("INSERT INTO annees_scolaires (libelle, est_active) VALUES (?, 1)");
            $ins->execute([$libelle]);
            
            $pdo->commit();
            $message = "success|L'année scolaire $libelle est désormais l'année active du système.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "danger|Erreur lors de la création de l'année scolaire.";
        }
    }
}

// 2. LOGIQUE DE RECONDUCTION (PASSAGE DE CLASSE)
if (isset($_POST['lancer_reconduction'])) {
    require_csrf();
    $annee_source = $_POST['annee_source'];
    $annee_dest = $_POST['annee_dest'];

    if ($annee_source == $annee_dest) {
        $message = "danger|Sélectionnez deux années différentes pour effectuer le transfert.";
    } else {
        $pdo->beginTransaction();
        try {
            // Récupérer le préfixe pour le code unique (ex: 2026 -> 26)
            $stmt_lib = $pdo->prepare("SELECT libelle FROM annees_scolaires WHERE id = ?");
            $stmt_lib->execute([$annee_dest]);
            $prefix = substr($stmt_lib->fetchColumn(), 2, 2);

            // Récupérer les élèves de l'année source
            $stmt = $pdo->prepare("SELECT * FROM apprenants WHERE annee_scolaire_id = ?");
            $stmt->execute([$annee_source]);
            $eleves = $stmt->fetchAll();

            foreach ($eleves as $e) {
                $nouvelle_promo = $e->promotion_id; // Par défaut : Redoublement

                // Si ADMIS : Recherche de la promotion suivante
                if (isset($e->decision_jury) && $e->decision_jury === 'ADMIS') {
                    $stmt_p = $pdo->prepare("SELECT id_promotion_suivante FROM promotions WHERE id = ?");
                    $stmt_p->execute([$e->promotion_id]);
                    $res = $stmt_p->fetch();
                    if ($res && $res->id_promotion_suivante) {
                        $nouvelle_promo = $res->id_promotion_suivante;
                    }
                }

                // Nouveau code unique pour la nouvelle année
                $nouveau_code = "ITM-" . $prefix . "-" . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4);

                $ins = $pdo->prepare("INSERT INTO apprenants (nom, post_nom, prenom, code_unique, promotion_id, annee_scolaire_id) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$e->nom, $e->post_nom, $e->prenom, $nouveau_code, $nouvelle_promo, $annee_dest]);
            }
            
            $pdo->commit();
            $message = "success|Opération réussie : Les élèves ont été transférés vers la nouvelle session.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "danger|Erreur lors du transfert des données.";
        }
    }
}

$annees = $pdo->query("SELECT * FROM annees_scolaires ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ITMCD</title>
    <link rel="icon" type="image/png" href="../images/LOGO.png">
    <?= app_shell_assets('../') ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --bg: #0f172a; --card: #1e293b; --accent: #38bdf8; --white: #ffffff; }
        
        body { background: var(--bg); color: var(--white); font-family: 'Segoe UI', sans-serif; margin: 0; overflow-x: hidden; }
        
        /* HEADER FIXE - PLEINE LARGEUR */
        .sticky-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            z-index: 1000;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        /* CONTENU SANS SIDEBAR */
        #content { padding: 20px; padding-top: 100px; max-width: 1200px; margin: 0 auto; }
        
        /* CARDS */
        .form-card { background: var(--card); border-radius: 16px; padding: 25px; border: 1px solid rgba(255,255,255,0.08); margin-bottom: 25px; height: 100%; }
        
        .input-noir { background: white !important; color: black !important; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 12px; }
        
        .form-select { background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); color: white !important; border-radius: 10px; padding: 12px; }
        
        .btn-primary { background: var(--accent); border: none; color: #0f172a; font-weight: bold; border-radius: 10px; }
        .btn-warning { border-radius: 10px; }
        
        .badge-active { background: #22c55e; color: white; padding: 4px 10px; border-radius: 20px; font-size: 10px; }

        /* MOBILE ADJUSTMENTS */
        @media (max-width: 992px) {
            #content { padding-top: 80px; }
            .sticky-header h2 { font-size: 1.1rem; }
        }

        @media (max-width: 576px) {
            .form-card { padding: 18px; }
            .btn { padding: 12px !important; font-size: 0.9rem; }
        }
    </style>
</head>
<body>

    <div class="sticky-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
             <h4 class="fw-bold m-0 d-none d-sm-block">ITMCD<span style="color:var(--accent)">.</span></h4>
             <h2 class="fw-bold m-0 text-truncate fs-5">CLÔTURE & RECONDUCTION</h2>
        </div>
        <a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-house me-1"></i> <span class="d-none d-md-inline">Retour</span></a>
    </div>

    <main id="content">
        <div class="container-fluid">
            <?php if($message): list($type, $txt) = explode('|', $message); ?>
                <div class="alert alert-<?= $type ?> border-0 shadow-sm small mb-4"><?= $txt ?></div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-5 col-12">
                    <div class="form-card">
                        <h5 class="mb-4 text-accent small fw-bold text-uppercase"><i class="bi bi-plus-circle me-2"></i> Initialiser une Année</h5>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="small fw-bold text-uppercase opacity-75 mb-2 d-block">Libellé de la session</label>
                                <input type="text" name="libelle_annee" class="form-control input-noir" placeholder="Ex: 2025-2026" required>
                            </div>
                            <button type="submit" name="creer_annee" class="btn btn-primary w-100 p-3">
                                CRÉER ET ACTIVER
                            </button>
                        </form>
                    </div>

                    <div class="form-card mt-4">
                        <h5 class="mb-4 text-accent small fw-bold text-uppercase"><i class="bi bi-clock-history me-2"></i> Historique</h5>
                        <div class="table-responsive">
                            <table class="table table-dark table-sm opacity-75 small">
                                <thead><tr><th>Session</th><th class="text-end">Statut</th></tr></thead>
                                <tbody>
                                    <?php foreach($annees as $a): ?>
                                    <tr>
                                        <td><?= $a->libelle ?></td>
                                        <td class="text-end"><?= $a->est_active ? '<span class="badge-active">ACTIVE</span>' : '<span class="text-muted">Archive</span>' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 col-12">
                    <div class="form-card">
                        <h5 class="mb-4 text-warning small fw-bold text-uppercase"><i class="bi bi-arrow-repeat me-2"></i> Passage Automatique</h5>
                        
                        <p class="small text-white-50 mb-4">
                            Transférez les dossiers vers une nouvelle session. 
                            Les élèves <b>Admis</b> montent de niveau, les autres sont reconduits.
                        </p>
                        
                        <form method="POST">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="small fw-bold text-uppercase opacity-75 mb-2 d-block">Année Source</label>
                                <select name="annee_source" class="form-select" required>
                                    <?php foreach($annees as $a): ?>
                                        <option value="<?= $a->id ?>"><?= $a->libelle ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="small fw-bold text-uppercase opacity-75 mb-2 d-block">Année Destination</label>
                                <select name="annee_dest" class="form-select" required>
                                    <?php foreach($annees as $a): ?>
                                        <option value="<?= $a->id ?>"><?= $a->libelle ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="p-3 mb-4 rounded bg-warning bg-opacity-10 border border-warning border-opacity-25">
                                <div class="d-flex gap-2 align-items-start">
                                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                    <span style="font-size: 0.8rem;">Assurez-vous que les <b>décisions du jury</b> sont validées pour l'année source.</span>
                                </div>
                            </div>

                            <button type="submit" name="lancer_reconduction" class="btn btn-warning w-100 p-3 fw-bold" onclick="return confirm('Confirmer le transfert global des élèves ?')">
                                LANCER LE TRANSFERT
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

</body>
</html>
