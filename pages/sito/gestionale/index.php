<?php
/**
 * GENERATO AUTOMATICAMENTE
 * Progetto: gestionale
 */

// 1. Inclusione della classe Database (creata precedentemente)
$db_error = null;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
try {
    if (file_exists('db.php')) {
        require_once 'db.php';
        // L'istanza $db viene creata automaticamente dentro db.php
    } else {
        throw new Exception("File db.php non trovato.");
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// Verifica stato per il semaforo
$is_connected = (isset($db) && !$db_error);
?>
<!DOCTYPE html>
<html lang='it'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>gestionale</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css'>
    <style>
        :root { --sidebar-width: 260px; }
        body { background: #f4f7f6; overflow-x: hidden; display: flex; min-height: 100vh; }
        
        /* Sidebar Styles */
        #sidebar { 
            width: var(--sidebar-width); 
            background: #212529; 
            color: white; 
            transition: all 0.3s; 
            z-index: 1000;
        }
        #sidebar.collapsed { margin-left: calc(-1 * var(--sidebar-width)); }
        
        .sidebar-header { padding: 20px; background: #1a1d20; display: flex; align-items: center; justify-content: space-between; }
        .nav-link { color: #adb5bd; padding: 12px 20px; border-bottom: 1px solid #2c3136; }
        .nav-link:hover { background: #343a40; color: white; }
        
        /* Semaforo Styles */
        .semaforo-box { padding: 15px; background: #2c3136; margin: 10px; border-radius: 8px; font-size: 0.85rem; }
        .light { 
            width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 8px;
            box-shadow: 0 0 8px rgba(0,0,0,0.5);
        }
        .light-green { background: #2ecc71; box-shadow: 0 0 10px #2ecc71; }
        .light-red { background: #e74c3c; box-shadow: 0 0 10px #e74c3c; }

        .content-area { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .top-navbar { background: white; border-bottom: 1px solid #ddd; padding: 10px 20px; position: relative; z-index: 1001; }

        @media (max-width: 768px) {
            body { display: block; }
            #sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                transform: translateX(-100%);
                z-index: 1030;
                box-shadow: 0 0 24px rgba(0, 0, 0, 0.2);
            }
            #sidebar.open { transform: translateX(0); }
            #sidebar.collapsed { margin-left: 0; }
            .content-area { min-height: 100vh; }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <nav id='sidebar'>
        <div class='sidebar-header'>
            <span class='fw-bold'>gestionale</span>
        </div>
        
        <div class='semaforo-box'>
            <div class='d-flex align-items-center'>
                <span class='light <?php echo $is_connected ? "light-green" : "light-red"; ?>'></span>
                <span>DB: <?php echo $is_connected ? "CONNESSO" : "DISCONNESSO"; ?></span>
            </div>
            <?php if($db_error): ?>
                <div class='text-danger tiny mt-1' style='font-size: 10px;'><?php echo $db_error; ?></div>
            <?php endif; ?>
        </div>

        <div class='nav flex-column mt-3'>
            <a href='#' class='nav-link'><i class='bi bi-house me-2'></i> Home</a>
            <a href='#' class='nav-link'><i class='bi bi-grid me-2'></i> Dashboard</a>
            <a href='#' class='nav-link'><i class='bi bi-gear me-2'></i> Impostazioni</a>
        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <div class='content-area'>
        <nav class='top-navbar d-flex align-items-center'>
            <button id='toggleBtn' class='btn btn-dark btn-sm me-3'><i class='bi bi-list'></i></button>
            <h5 class='mb-0'>Pagina Principale</h5>
        </nav>

        <div class='p-4'>
            <?php if (!empty($_SESSION['db_sync_report']) && is_array($_SESSION['db_sync_report'])): ?>
                <div class='card shadow-sm mb-3 border-warning'>
                    <div class='card-header bg-warning-subtle fw-semibold'>Report allineamento DB</div>
                    <div class='card-body'>
                        <div class='table-responsive'>
                            <table class='table table-sm table-bordered align-middle mb-0'>
                                <thead class='table-light'>
                                    <tr>
                                        <th style='width: 80px;'>Esito</th>
                                        <th>Operazione</th>
                                        <th>Errore</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_SESSION['db_sync_report'] as $row): ?>
                                        <tr>
                                            <td>
                                                <?php if (($row['status'] ?? '') === 'ok'): ?>
                                                    <span class='text-success fw-bold'>OK</span>
                                                <?php elseif (($row['status'] ?? '') === 'fail'): ?>
                                                    <span class='text-danger fw-bold'>KO</span>
                                                <?php else: ?>
                                                    <span class='text-muted'>..</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= htmlspecialchars((string)($row['statement'] ?? '')) ?></code></td>
                                            <td class='text-danger small'><?= htmlspecialchars((string)($row['error'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['db_sync_report']); ?>
            <?php endif; ?>
            <div class='card shadow-sm'>
                <div class='card-body'>
                    <h3>Benvenuti su gestionale</h3>
                    <hr>
                    <p>Il sito è stato generato con successo. La sidebar a sinistra mostra lo stato della connessione al database in tempo reale.</p>
                </div>
            </div>
        </div>
    </div>

    <script src='https://code.jquery.com/jquery-3.6.0.min.js'></script>
    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleBtn');

            toggleBtn.addEventListener('click', function () {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('open');
                } else {
                    sidebar.classList.toggle('collapsed');
                }
            });
        })();
    </script>
</body>
</html>
