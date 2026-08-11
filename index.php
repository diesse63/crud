<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

try {
    require_once 'db.php'; 
    $db = new Database();
} catch (Exception $e) {
    die("Errore di connessione al database: " . $e->getMessage());
}
session_start();

// --- LOGICA DOWNLOAD SCRIPT DB ---
if (isset($_POST['download_script']) && ($_GET['page'] ?? '') === 'analisi_db') {
    $db_download = new Database();
    $progetto_id_download = $_SESSION['progetto_id'] ?? null;
    if ($progetto_id_download) {
        if (!function_exists('dfs_sort')) {
            function dfs_sort($node, &$adj, &$visited, &$stack, &$sorted) {
                $visited[$node] = true;
                $stack[$node] = true;
                if (isset($adj[$node])) {
                    foreach ($adj[$node] as $dep) {
                        if (!isset($visited[$dep])) dfs_sort($dep, $adj, $visited, $stack, $sorted);
                    }
                }
                unset($stack[$node]);
                $sorted[] = $node;
            }
        }
        
        $path_analisi = __DIR__ . '/pages/analisi_db.php';
        if(file_exists($path_analisi)){
            require_once $path_analisi;
            $generated_data = generate_db_schema_and_sql($db_download, $progetto_id_download);
            $sql_script_download = $generated_data['sql_script'];
            $schema_data_download = $generated_data['schema_data'];

            $format = $_POST['format'] ?? 'sql';
            header('Content-Type: ' . ($format == 'sql' ? 'application/sql' : 'application/json'));
            header('Content-Disposition: attachment; filename="export_'.date('Ymd').'.'.$format.'"');
            echo ($format == 'sql' ? $sql_script_download : json_encode($schema_data_download, JSON_PRETTY_PRINT));
            exit;
        }
    }
}

// --- LOGICA SET_PROGETTO ---
if (isset($_GET['set_progetto'])) {
    $p = $db->fetch("SELECT * FROM progetti WHERE id = ?", [$_GET['set_progetto']]);
    if ($p) {
        $_SESSION['progetto_id'] = $p['id'];
        $_SESSION['progetto_nome'] = $p['nome'];
        $_SESSION['success_msg'] = "Progetto '" . htmlspecialchars($p['nome']) . "' selezionato con successo.";
        header("Location: index.php?page=tabelle&t=" . time());
        exit;
    } else {
        $_SESSION['error_msg'] = "Errore: Progetto non trovato.";
        header("Location: index.php?page=progetti&t=" . time());
        exit;
    }
}

// --- LOG DI NAVIGAZIONE ---
if (!isset($_SESSION['navigation_log'])) {
    $_SESSION['navigation_log'] = [];
}
$current_page_entry = [
    'page' => $_GET['page'] ?? 'progetti',
    'timestamp' => date('Y-m-d H:i:s'),
];
$params = $_GET;
unset($params['page'], $params['t']);
if (!empty($params)) {
    $current_page_entry['params'] = http_build_query($params);
}
$_SESSION['navigation_log'][] = $current_page_entry;
if (count($_SESSION['navigation_log']) > 10) {
    $_SESSION['navigation_log'] = array_slice($_SESSION['navigation_log'], -10);
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$page = $_GET['page'] ?? 'progetti';
$pageAliases = [
    'scheda_singola' => 'creatore_pagina',
    'scheda_tabellare' => 'creatore_pagina',
    'scheda_master_detail' => 'creatore_pagina',
];
if (isset($pageAliases[$page])) {
    $page = $pageAliases[$page];
}
$base_dir = __DIR__ . "/pages/";
$page_path = $base_dir . $page . ".php";
$cache_buster = time();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD Creator</title>
    <link rel="icon" type="image/png" sizes="512x512" href="icona512.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root { --sidebar-width: 280px; }
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; overflow-y: auto; margin: 0; }
        
        #wrapper { display: flex; width: 100vw; min-height: 100vh; height: 100vh; }
        
        /* SIDEBAR DESKTOP */
        #sidebar { 
            min-width: var(--sidebar-width); 
            max-width: var(--sidebar-width); 
            background: #212529; 
            transition: all 0.3s ease; 
            z-index: 1040;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        #sidebar.collapsed { margin-left: calc(-1 * var(--sidebar-width)); }

        #content { flex: 1; display: flex; flex-direction: column; min-width: 0; min-height: 0; background: #f4f7f6; overflow: hidden; }
        .page-container { flex: 1; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 20px; }

        .navbar { background: #fff; border-bottom: 1px solid #dee2e6; padding: 10px 15px; flex-shrink: 0; position: relative; z-index: 1045; }
        
        /* OVERLAY MOBILE */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 1030;
        }

        @media (max-width: 768px) {
            #wrapper { height: auto; min-height: 100vh; }
            #sidebar {
                position: fixed;
                height: 100vh;
                max-height: 100vh;
                left: calc(-1 * var(--sidebar-width));
            }
            #sidebar.active { left: 0; }
            #sidebar.collapsed { margin-left: 0; } /* Reset desktop behavior on mobile */
            .sidebar-overlay.active { display: block; }
            #content { min-height: 100vh; }
            .page-container { padding: 16px 12px; }
        }

        .sidebar-header { padding: 20px 15px; background: #1a1d20; text-align: center; }
        .large-app-icon { width: 70px; height: 70px; margin-bottom: 10px; }
        
        #sidebar ul li > a { padding: 12px 15px; display: block; text-decoration: none; border-bottom: 1px solid #2c3136; color: #f8f9fa; font-weight: 600; }
        #sidebar ul li > a:hover { background: #343a40; }
        #sidebar ul ul li > a { color: #adb5bd; padding-left: 35px; background: #1b1f23; font-size: 0.9rem; }
        #sidebar ul li > a.active { background: #0d6efd; color: #fff; }

        .sql-box { background: #272822; color: #f8f8f2; padding: 20px; border-radius: 5px; overflow: auto; font-family: monospace; border-left: 6px solid #a6e22e; }
    </style>
</head>
<body>

<div id="wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <nav id="sidebar">
        <div class="sidebar-header">
            <img src="icona512.png" alt="Icon" class="large-app-icon d-block mx-auto">
            <a href="index.php" class="text-white text-decoration-none h5">CRUD Creator</a>
        </div>
        <div class="flex-grow-1 overflow-auto">
            <ul class="list-unstyled components">
                <li><a href="index.php?page=progetti&t=<?= $cache_buster ?>" class="<?= $page == 'progetti' ? 'active' : '' ?>"><i class="bi bi-house-door me-2"></i> Home</a></li>
                <?php if(isset($_SESSION['progetto_id'])): ?>
                    <li class="p-2 small text-uppercase text-muted border-bottom border-secondary bg-dark" style="font-size: 0.65rem;">
                        <i class="bi bi-gear-fill me-1"></i> Progetto: <?= htmlspecialchars($_SESSION['progetto_nome']) ?>
                    </li>
                    <li>
                        <a href="#cartellaSub" data-bs-toggle="collapse" class="dropdown-toggle <?= $page == 'cartella_progetto' ? 'active' : '' ?>"><i class="bi bi-folder-symlink me-2"></i> Cartella</a>
                        <ul class="collapse list-unstyled <?= $page == 'cartella_progetto' ? 'show' : '' ?>" id="cartellaSub">
                            <li><a href="index.php?page=cartella_progetto&view=files&t=<?= $cache_buster ?>" class="<?= $page == 'cartella_progetto' && ($_GET['view']??'') !== 'db' ? 'active' : '' ?>">Allineamento file</a></li>
                            <li><a href="index.php?page=cartella_progetto&view=db&t=<?= $cache_buster ?>" class="<?= $page == 'cartella_progetto' && ($_GET['view']??'') === 'db' ? 'active' : '' ?>">Allineamento DB</a></li>
                        </ul>
                    </li>
                    <li><a href="index.php?page=log&t=<?= $cache_buster ?>" class="<?= $page == 'log' ? 'active' : '' ?>"><i class="bi bi-journal-text me-2"></i> Log</a></li>
                    <li>
                        <a href="#dbSub" data-bs-toggle="collapse" class="dropdown-toggle <?= in_array($page, ['tabelle', 'campi', 'analisi_db', 'schema_db']) ? 'active' : '' ?>"><i class="bi bi-database-fill-gear me-2"></i> Database</a>
                        <ul class="collapse list-unstyled <?= in_array($page, ['tabelle', 'campi', 'analisi_db', 'schema_db']) ? 'show' : '' ?>" id="dbSub">
                            <li><a href="index.php?page=tabelle&t=<?= $cache_buster ?>" class="<?= ($page == 'tabelle' || $page == 'campi') ? 'active' : '' ?>">Tabelle</a></li>
                            <li><a href="index.php?page=analisi_db&t=<?= $cache_buster ?>" class="<?= $page == 'analisi_db' ? 'active' : '' ?>">Schema SQL</a></li>
                            <li><a href="index.php?page=schema_db&t=<?= $cache_buster ?>" class="<?= $page == 'schema_db' ? 'active' : '' ?>">Visualizzazione</a></li>
                        </ul>
                    </li>
                    <li>
                        <a href="#siteSub" data-bs-toggle="collapse" class="dropdown-toggle <?= in_array($page, ['genera_home', 'tabella_pannellate']) ? 'active' : '' ?>"><i class="bi bi-globe me-2"></i> Sito</a>
                        <ul class="collapse list-unstyled <?= in_array($page, ['genera_home', 'tabella_pannellate']) ? 'show' : '' ?>" id="siteSub">
                            <li><a href="index.php?page=genera_home&t=<?= $cache_buster ?>" class="<?= $page == 'genera_home' ? 'active' : '' ?>">Genera Home</a></li>
                            <li><a href="index.php?page=tabella_pannellate&t=<?= $cache_buster ?>" class="<?= in_array($page, ['tabella_pannellate', 'creatore_pagina']) ? 'active' : '' ?>">Gestione Pannellate</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <div id="content">
        <nav class="navbar shadow-sm">
            <div class="container-fluid">
                <button type="button" id="sidebarCollapse" class="btn btn-outline-primary me-3">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <nav aria-label="breadcrumb" class="d-none d-sm-block">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php">CRUD Creator</a></li>
                        <li class="breadcrumb-item active"><?= ucfirst(str_replace('_', ' ', $page)) ?></li>
                    </ol>
                </nav>
            </div>
        </nav>

        <div class="page-container">
            <?php
                if(file_exists($page_path)) {
                    include($page_path);
                } else {
                    $fallback = $base_dir . "progetti.php";
                    if (file_exists($fallback)) include($fallback);
                    else echo "<div class='alert alert-danger'>Pagina non trovata.</div>";
                }
            ?>
        </div>
    </div>
</div>

<!-- Modal & Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;"></div>
<div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Conferma</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmationModalBody">Sei sicuro?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" id="confirmActionButton">Conferma</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function showToast(type, message) {
        let bgColor = (type === 'success') ? 'bg-success' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-danger');
        const toastHtml = `<div class="toast align-items-center text-white ${bgColor} border-0" role="alert"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
        const $toast = $(toastHtml);
        $('.toast-container').append($toast);
        new bootstrap.Toast($toast[0]).show();
    }

    $(document).ready(function () {
        const $sidebar = $('#sidebar');
        const $overlay = $('#sidebarOverlay');
        const mobileQuery = window.matchMedia('(max-width: 768px)');

        // GESTIONE SIDEBAR
        function toggleSidebar() {
            if (mobileQuery.matches) {
                $sidebar.toggleClass('active');
                $overlay.toggleClass('active');
            } else {
                $sidebar.toggleClass('collapsed');
            }
        }

        $('#sidebarCollapse, #sidebarOverlay').on('click', toggleSidebar);

        // Chiusura automatica su selezione (Mobile)
        $('#sidebar a').on('click', function() {
            if ($(this).hasClass('dropdown-toggle')) return;
            if (mobileQuery.matches) {
                $sidebar.removeClass('active');
                $overlay.removeClass('active');
            }
        });

        // Logica originale Modals/Tabelle
        let confirmCallback = null;
        window.showConfirmationModal = function(message, callback) {
            confirmCallback = callback;
            $('#confirmationModalBody').html(message);
            new bootstrap.Modal(document.getElementById('confirmationModal')).show();
        }
        $('#confirmActionButton').on('click', function() {
            if (confirmCallback) confirmCallback();
            bootstrap.Modal.getInstance(document.getElementById('confirmationModal')).hide();
        });

        $(document).on('click', '.table-row-tabella', function(e) {
            if ($(e.target).closest('a, button, form, input').length) return;
            window.location.href = $(this).data('href');
        });

        <?php if (isset($_SESSION['success_msg'])): ?>
            showToast('success', "<?= addslashes($_SESSION['success_msg']) ?>");
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_msg'])): ?>
            showToast('danger', "<?= addslashes($_SESSION['error_msg']) ?>");
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>
    });
</script>
</body>
</html>
<?php ob_end_flush(); ?>
