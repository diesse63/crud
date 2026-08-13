<?php
$action = (string)($_GET['action'] ?? '');

if ($action !== '') {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) session_start();

$progettoId = (int)($_SESSION['progetto_id'] ?? 0);
$progettoNome = trim((string)($_SESSION['progetto_nome'] ?? ''));

if ($progettoId <= 0) {
    if ($action !== '') {
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Seleziona un progetto attivo.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo '<div class="alert alert-warning m-4">Seleziona un progetto attivo.</div>';
    return;
}

if (!isset($db)) {
    require_once dirname(__DIR__) . '/db.php';
    if (!isset($db)) $db = new Database();
}

function ghJson(array $data, int $status = 200): never {
    if (ob_get_level() > 0 && ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
function ghFolder(string $name): string {
    $name = strtolower(trim($name));
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $name = preg_replace('/[^a-z0-9]+/', '_', $name);
    return trim($name, '_') ?: 'progetto_web';
}
function ghIcon(string $icon): string {
    $icon = preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($icon));
    if ($icon === '') return 'bi-file-earmark';
    return str_starts_with($icon, 'bi-') ? $icon : 'bi-' . $icon;
}
function ghIconOptions(): array {
    return [
        'bi-file-earmark' => 'File',
        'bi-house' => 'Home',
        'bi-grid' => 'Griglia',
        'bi-folder' => 'Cartella',
        'bi-folder2-open' => 'Cartella aperta',
        'bi-folder-plus' => 'Cartella con aggiunta',
        'bi-file-earmark-text' => 'Documento',
        'bi-file-earmark-code' => 'Codice',
        'bi-table' => 'Tabella',
        'bi-database' => 'Database',
        'bi-database-gear' => 'Database con impostazioni',
        'bi-gear' => 'Impostazioni',
        'bi-gear-fill' => 'Impostazioni piene',
        'bi-list' => 'Elenco',
        'bi-list-ul' => 'Elenco puntato',
        'bi-layout-text-window-reverse' => 'Layout finestra',
        'bi-box-arrow-right' => 'Uscita',
        'bi-plus-lg' => 'Aggiungi',
        'bi-pencil' => 'Modifica',
        'bi-trash' => 'Cestino',
        'bi-search' => 'Cerca',
        'bi-funnel' => 'Filtro',
        'bi-bell' => 'Notifica',
        'bi-star' => 'Preferito',
        'bi-heart' => 'Cuore',
        'bi-link-45deg' => 'Collegamento',
        'bi-diagram-3' => 'Relazioni',
        'bi-person' => 'Persona',
        'bi-people' => 'Persone',
        'bi-calendar' => 'Calendario',
        'bi-check-circle' => 'Confermato',
        'bi-exclamation-circle' => 'Attenzione',
        'bi-info-circle' => 'Informazioni',
    ];
}
function ghPages(string $path): array {
    if (!is_dir($path)) return [];
    $result = [];
    foreach (new DirectoryIterator($path) as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $name = $file->getFilename();
        $result[] = [
            'file_name' => $name,
            'label' => ucwords(str_replace(['_', '-'], ' ', pathinfo($name, PATHINFO_FILENAME)))
        ];
    }
    usort($result, fn($a, $b) => strnatcasecmp($a['file_name'], $b['file_name']));
    return $result;
}
function ghTree(array $rows): array {
    $map = [];
    foreach ($rows as $row) $map[(int)($row['IDpadre'] ?? 0)][] = $row;
    $build = function(int $parent) use (&$build, &$map): array {
        $items = $map[$parent] ?? [];
        usort($items, fn($a, $b) => ((int)$a['ordine']) <=> ((int)$b['ordine']));
        foreach ($items as &$item) $item['children'] = $build((int)$item['id']);
        return $items;
    };
    return $build(0);
}
function ghMenuCode(array $items, int $level = 0): string {
    $out = '';
    foreach ($items as $item) {
        if (empty($item['visibile'])) continue;
        $label = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
        $icon = htmlspecialchars(ghIcon((string)$item['icona']), ENT_QUOTES, 'UTF-8');
        $indent = str_repeat('    ', $level);
        if ($item['tipo'] === 'GRUPPO') {
            $id = 'submenu-' . (int)$item['id'];
            $out .= "{$indent}<div class=\"menu-group\">\n";
            $out .= "{$indent}  <button class=\"nav-link menu-toggle\" type=\"button\" data-bs-toggle=\"collapse\" data-bs-target=\"#{$id}\"><span><i class=\"bi {$icon} me-2\"></i>{$label}</span><i class=\"bi bi-chevron-down\"></i></button>\n";
            $out .= "{$indent}  <div class=\"collapse submenu\" id=\"{$id}\">\n";
            $out .= ghMenuCode($item['children'] ?? [], $level + 2);
            $out .= "{$indent}  </div>\n{$indent}</div>\n";
        } else {
            $fileName = basename((string) $item['nome_file']);
            if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $file = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
            $url = '?page=' . rawurlencode($fileName);

            $out .= "{$indent}<a class=\"nav-link\" href=\"{$url}\"><i class=\"bi {$icon} me-2\"></i>{$label}</a>\n";
        }
    }
    return $out;
}

$folder = ghFolder($progettoNome);
$base = __DIR__ . '/sito/' . $folder;
$pagesPath = $base . '/pages';
$indexPath = $base . '/index.php';
$serviceWorkerPath = $base . '/sw.js';

if ($action !== '') {
    try {
        if ($action === 'load') {
            $rows = $db->fetchAll(
                "SELECT id, IDpadre, tipo, nome_file, label, icona, visibile, ordine
                 FROM menu_home_voci
                 WHERE IDprogetto = ?
                 ORDER BY COALESCE(IDpadre,0), ordine, id",
                [$progettoId]
            );
            $settings = $db->fetch(
                "SELECT
                    id,
                    titolo_sito,
                    descrizione_home,
                    data_modifica
                 FROM menu_home_config
                 WHERE IDprogetto = ?",
                [$progettoId]
            );

            $configExists = is_array($settings) && !empty($settings['id']);

            if (!$configExists) {
                $settings = [
                    'id' => null,
                    'titolo_sito' => $progettoNome,
                    'descrizione_home' => '',
                    'data_modifica' => null
                ];
            }

            ghJson([
                'ok' => true,
                'pages' => ghPages($pagesPath),
                'menu' => ghTree($rows),
                'settings' => $settings,
                'configuration_exists' => $configExists,
                'menu_items_count' => count($rows)
            ]);
        }

        if ($action === 'save') {
            $payload = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($payload)) ghJson(['ok' => false, 'message' => 'Dati non validi.'], 422);

            $title = trim((string)($payload['title'] ?? $progettoNome)) ?: $progettoNome;
            $description = trim((string)($payload['description'] ?? ''));
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            $db->beginTransaction();
            try {
                $exists = (int)$db->fetchColumn(
                    "SELECT COUNT(*) FROM menu_home_config WHERE IDprogetto = ?",
                    [$progettoId]
                );
                if ($exists) {
                    $db->execute(
                        "UPDATE menu_home_config
                         SET titolo_sito = ?, descrizione_home = ?, data_modifica = CURRENT_TIMESTAMP
                         WHERE IDprogetto = ?",
                        [$title, $description, $progettoId]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO menu_home_config
                         (IDprogetto, titolo_sito, descrizione_home)
                         VALUES (?, ?, ?)",
                        [$progettoId, $title, $description]
                    );
                }

                $db->execute("DELETE FROM menu_home_voci WHERE IDprogetto = ?", [$progettoId]);

                $insert = function(array $nodes, ?int $parent = null) use (&$insert, $db, $progettoId): void {
                    foreach ($nodes as $i => $node) {
                        $type = strtoupper((string)($node['type'] ?? 'PAGINA'));
                        if (!in_array($type, ['PAGINA', 'GRUPPO'], true)) $type = 'PAGINA';
                        $label = trim((string)($node['label'] ?? ''));
                        if ($label === '') continue;
                        $file = $type === 'PAGINA' ? basename((string)($node['file_name'] ?? '')) : null;
                        if ($type === 'PAGINA' && strtolower(pathinfo((string)$file, PATHINFO_EXTENSION)) !== 'php') continue;

                        $db->execute(
                            "INSERT INTO menu_home_voci
                             (IDprogetto, IDpadre, tipo, nome_file, label, icona, visibile, ordine)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [$progettoId, $parent, $type, $file, $label, ghIcon((string)($node['icon'] ?? '')), !empty($node['visible']) ? 1 : 0, $i + 1]
                        );
                        $id = (int)$db->lastInsertId();
                        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
                        if ($children) $insert($children, $id);
                    }
                };
                $insert($items);
                $db->commit();
                ghJson([
                    'ok' => true,
                    'message' => 'Configurazione Home e menu aggiornata correttamente.'
                ]);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }

        if ($action === 'generate') {
            $settings = $db->fetch(
                "SELECT titolo_sito, descrizione_home FROM menu_home_config WHERE IDprogetto = ?",
                [$progettoId]
            ) ?: ['titolo_sito' => $progettoNome, 'descrizione_home' => ''];
            $rows = $db->fetchAll(
                "SELECT id, IDpadre, tipo, nome_file, label, icona, visibile, ordine
                 FROM menu_home_voci WHERE IDprogetto = ?
                 ORDER BY COALESCE(IDpadre,0), ordine, id",
                [$progettoId]
            );

            $title = var_export((string)$settings['titolo_sito'], true);
            $description = var_export((string)$settings['descrizione_home'], true);
            $menu = ghMenuCode(ghTree($rows), 2);

            $code = <<<PHP
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

\$siteTitle = {$title};
\$homeDescription = {$description};
\$requestedPage = basename((string) (\$_GET['page'] ?? ''));
\$pagesPath = __DIR__ . DIRECTORY_SEPARATOR . 'pages';
\$pagePath = '';
\$bootstrapError = '';

/*
 * Inizializzazione centralizzata del database.
 * Le pagine CRUD incluse riutilizzano lo stesso oggetto \$db.
 */
try {
    \$databaseFile = __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

    if (!is_file(\$databaseFile)) {
        throw new RuntimeException(
            'File db.php non trovato nella cartella principale del sito.'
        );
    }

    require_once \$databaseFile;

    if (!class_exists('Database')) {
        throw new RuntimeException(
            'La classe Database non è definita nel file db.php.'
        );
    }

    if (!isset(\$db) || !(\$db instanceof Database)) {
        \$db = new Database();
    }
} catch (Throwable \$error) {
    \$bootstrapError = \$error->getMessage();
    error_log('Errore avvio sito CRUD: ' . \$bootstrapError);
}

/*
 * Sono accettati esclusivamente file PHP realmente presenti in /pages.
 * basename impedisce path traversal come ../file.php.
 */
if (
    \$requestedPage !== ''
    && strtolower(pathinfo(\$requestedPage, PATHINFO_EXTENSION)) === 'php'
) {
    \$candidatePath = \$pagesPath
        . DIRECTORY_SEPARATOR
        . \$requestedPage;

    \$candidate = realpath(\$candidatePath);
    \$root = realpath(\$pagesPath);

    if (
        \$candidate !== false
        && \$root !== false
        && is_file(\$candidate)
        && str_starts_with(
            \$candidate,
            rtrim(\$root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        )
    ) {
        \$pagePath = \$candidate;
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(\$siteTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--sidebar:280px}body{background:#f5f7fa}.sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:#212529;color:#fff;overflow:auto;transition:.25s;z-index:1045}.sidebar.closed{transform:translateX(-100%)}.sidebar .nav-link{display:flex;justify-content:flex-start;align-items:center;width:100%;padding:.8rem 1rem;color:#c9ced3;border:0;background:transparent;text-decoration:none}.sidebar .nav-link:hover{background:#343a40;color:#fff}.menu-toggle{justify-content:space-between!important}.submenu .nav-link{padding-left:2.8rem}.main{margin-left:var(--sidebar);transition:.25s}.main.full{margin-left:0}.topbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #ddd;z-index:1030}.generator-fab{position:fixed;right:1.25rem;bottom:1.25rem;left:auto;z-index:1080;border-radius:999px;box-shadow:0 .75rem 1.75rem rgba(0,0,0,.18);padding:.9rem 1.2rem;display:inline-flex;align-items:center;white-space:nowrap}.generator-fab i{font-size:1.05rem}.generator-fab{max-width:none}@media(max-width:991.98px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:none}.main{margin-left:0}}@media(max-width:575.98px){.generator-fab{left:.85rem;right:.85rem;bottom:.85rem;width:calc(100% - 1.7rem);justify-content:center;padding:.85rem 1rem;text-align:center;white-space:normal}}
</style>
</head>
<body>
<aside class="sidebar" id="sidebar">
<div class="p-3 bg-dark fw-bold"><?= htmlspecialchars(\$siteTitle, ENT_QUOTES, 'UTF-8') ?></div>
<nav>
<a class="nav-link" href="index.php"><i class="bi bi-house me-2"></i>Home</a>
{$menu}
</nav>
</aside>
<main class="main" id="main">
<nav class="topbar p-2"><button class="btn btn-dark btn-sm" id="menuToggle"><i class="bi bi-list"></i></button></nav>
<div class="container-fluid p-4">
<?php if (\$bootstrapError !== ''): ?>
<div class="alert alert-danger">
    <h1 class="h5">Errore di avvio dell'applicazione</h1>
    <p class="mb-1">
        Non è stato possibile inizializzare il collegamento al database.
    </p>
    <small>
        <?= htmlspecialchars(\$bootstrapError, ENT_QUOTES, 'UTF-8') ?>
    </small>
</div>

<?php elseif (\$pagePath !== ''): ?>
    <?php
    try {
        require \$pagePath;
    } catch (Throwable \$pageError) {
        http_response_code(500);
        error_log(
            'Errore pagina ' . \$requestedPage . ': '
            . \$pageError->getMessage()
        );
        ?>
        <div class="alert alert-danger">
            <h1 class="h5">Errore nel caricamento della pagina</h1>
            <p class="mb-1">
                La pagina
                <code><?= htmlspecialchars(\$requestedPage, ENT_QUOTES, 'UTF-8') ?></code>
                ha generato un errore.
            </p>
            <small>
                <?= htmlspecialchars(\$pageError->getMessage(), ENT_QUOTES, 'UTF-8') ?>
            </small>
        </div>
        <?php
    }
    ?>

<?php elseif (\$requestedPage !== ''): ?>
<div class="alert alert-warning">
    Pagina
    <code><?= htmlspecialchars(\$requestedPage, ENT_QUOTES, 'UTF-8') ?></code>
    non disponibile.
</div>

<?php else: ?>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <h1 class="h3">
            <?= htmlspecialchars(\$siteTitle, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p class="text-muted">
            <?= nl2br(htmlspecialchars(\$homeDescription, ENT_QUOTES, 'UTF-8')) ?>
        </p>
    </div>
</div>
<?php endif; ?>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const s=document.getElementById('sidebar'),m=document.getElementById('main');
document.getElementById('menuToggle').onclick=()=>{if(innerWidth<992)s.classList.toggle('open');else{s.classList.toggle('closed');m.classList.toggle('full')}};

// Service Worker disattivato durante lo sviluppo CRUD.
// Le pagine PHP generate devono sempre essere lette dal server,
// altrimenti si possono vedere campi/modali vecchi salvati in cache.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const registrations = await navigator.serviceWorker.getRegistrations();
            for (const registration of registrations) {
                await registration.unregister();
            }

            if (window.caches) {
                const cacheNames = await caches.keys();
                await Promise.all(cacheNames.map(name => caches.delete(name)));
            }
        } catch (error) {
            console.warn('Pulizia Service Worker/cache non completata:', error);
        }
    });
}
</script>
</body>
</html>
PHP;


            if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
                throw new RuntimeException('Impossibile creare la cartella progetto.');
            }
            if (file_put_contents($indexPath, $code, LOCK_EX) === false) {
                throw new RuntimeException('Errore durante la generazione di index.php.');
            }

            // Non generiamo più sw.js: in sviluppo causava cache di vecchie pagine PHP.
            // Se esiste una versione precedente, la eliminiamo dalla cartella progetto
            // così non verrà più pubblicata sul sito destinatario.
            $serviceWorkerRemoved = false;
            if (is_file($serviceWorkerPath)) {
                $serviceWorkerRemoved = @unlink($serviceWorkerPath);
                if (!$serviceWorkerRemoved && is_file($serviceWorkerPath)) {
                    throw new RuntimeException(
                        'index.php creato, ma non è stato possibile eliminare il vecchio sw.js.'
                    );
                }
            }

            ghJson([
                'ok' => true,
                'message' => $serviceWorkerRemoved
                    ? 'index.php generato correttamente. Vecchio sw.js eliminato.'
                    : 'index.php generato correttamente. Service worker disattivato.',
                'path' => '/sito/' . $folder . '/index.php',
                'service_worker_removed' => $serviceWorkerRemoved
            ]);
        }

        ghJson(['ok' => false, 'message' => 'Azione non riconosciuta.'], 404);
    } catch (Throwable $e) {
        ghJson(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}
?>
<style>
.menu-builder-root,.menu-children{min-height:70px}.page-item,.menu-item{border:1px solid #dee2e6;border-radius:.6rem;background:#fff;margin-bottom:.6rem}.page-item{padding:.7rem;cursor:grab}.menu-head{display:flex;align-items:center;gap:.5rem;padding:.65rem}.drag{cursor:grab;color:#6c757d}.menu-editor{border-top:1px solid #dee2e6;background:#f8f9fa;padding:.75rem}.menu-children{margin:0 .65rem .65rem 2rem;padding:.5rem;border:1px dashed #adb5bd;border-radius:.5rem;background:#f8f9fa}.menu-children:empty:before{content:"Trascina qui le sottovoci";display:block;text-align:center;color:#8b9299;font-size:.8rem;padding:.5rem}.sortable-ghost{opacity:.35}.icon-preview{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #ced4da;border-radius:.4rem}.icon-live{min-width:2.35rem;justify-content:center}.icon-filter::placeholder{color:#adb5bd}.sticky-actions{position:sticky;bottom:0;background:#fffffff2;border-top:1px solid #ddd;z-index:20}@media (max-width: 767.98px){.menu-editor .row{--bs-gutter-y:.5rem}.menu-editor .col-md-3,.menu-editor .col-md-4,.menu-editor .col-md-5{width:100%}.menu-editor .input-group{flex-wrap:nowrap}.menu-editor .icon-filter,.menu-editor .form-select{min-height:2.25rem}.menu-editor .icon-live{width:2.5rem}}
</style>

<div class="container-fluid p-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<div>
    <h2 class="h4 mb-1">Generatore Home e Hamburger Menu</h2>
    <div class="small text-muted">
        Progetto:
        <strong><?= htmlspecialchars($progettoNome, ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div id="configurationStatus" class="small mt-1"></div>
</div>
<button class="btn btn-outline-secondary" id="reloadBtn">
    <i class="bi bi-arrow-clockwise"></i>
    Ricarica configurazione
</button>
</div>

<div id="msg"></div>

<div class="card shadow-sm border-0 mb-3"><div class="card-body"><div class="row g-3">
<div class="col-md-5"><label class="form-label">Titolo sito</label><input id="siteTitle" class="form-control"></div>
<div class="col-md-7"><label class="form-label">Descrizione Home</label><textarea id="homeDescription" class="form-control" rows="2"></textarea></div>
</div></div></div>

<div class="row g-3">
<div class="col-lg-4"><div class="card shadow-sm border-0 h-100">
<div class="card-header d-flex justify-content-between"><strong>Pagine in /pages</strong><span class="badge bg-secondary" id="count">0</span></div>
<div class="card-body"><input id="search" class="form-control mb-3" placeholder="Cerca pagina"><div id="available"></div></div>
</div></div>

<div class="col-lg-8"><div class="card shadow-sm border-0 h-100">
<div class="card-header d-flex justify-content-between"><strong>Struttura menu</strong><button class="btn btn-sm btn-outline-primary" id="addGroup"><i class="bi bi-folder-plus"></i> Gruppo</button></div>
<div class="card-body"><div class="alert alert-light border small">Trascina le pagine. Per una sottovoce, trascina dentro l'area tratteggiata della voce principale.</div><div id="menuRoot" class="menu-builder-root"></div></div>
</div></div>
</div>

<div class="mt-3 py-3">
<button class="btn btn-outline-primary" id="saveBtn"><i class="bi bi-database-check"></i> Salva menu</button>
</div>

<button type="button" class="btn btn-success fw-bold generator-fab" id="generateBtn" aria-label="Genera index.php senza sw.js" style="position:fixed;right:1.25rem;bottom:1.25rem;left:auto;">
    <i class="bi bi-file-earmark-code me-2"></i>
    Genera index.php senza sw.js
</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(()=>{ 
const endpoint='pages/genera_home.php?action=',state={pages:[],menu:[],sortables:[],availableSortable:null,availablePageFileNames:new Set()};
const A=document.getElementById('available'),
R=document.getElementById('menuRoot'),
T=document.getElementById('siteTitle'),
D=document.getElementById('homeDescription'),
S=document.getElementById('search'),
M=document.getElementById('msg'),
C=document.getElementById('configurationStatus');
const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
const id=()=>`i_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`;
const normalizePageFileName=v=>{
    const file=String(v??'').trim();
    if(!file)return '';
    const base=file.split(/[\\/]/).pop()||'';
    return base.toLowerCase().endsWith('.php')?base:'';
};
const normalizeIcon = value => {
    const icon = String(value ?? '').trim().replace(/[^a-zA-Z0-9\-_]/g, '');
    if (!icon) {
        return 'bi-file-earmark';
    }
    return icon.startsWith('bi-') ? icon : `bi-${icon}`;
};
const iconOptions = [
    ['bi-file-earmark','File'],
    ['bi-house','Home'],
    ['bi-grid','Griglia'],
    ['bi-house-door','Casa con porta'],
    ['bi-house-heart','Casa con cuore'],
    ['bi-shop','Negozio'],
    ['bi-building','Edificio'],
    ['bi-building-gear','Edificio con impostazioni'],
    ['bi-person-badge','Badge persona'],
    ['bi-person-gear','Persona con impostazioni'],
    ['bi-people-fill','Gruppo persone'],
    ['bi-folder','Cartella'],
    ['bi-folder2-open','Cartella aperta'],
    ['bi-folder-plus','Cartella con aggiunta'],
    ['bi-file-earmark-text','Documento'],
    ['bi-file-earmark-code','Codice'],
    ['bi-file-earmark-plus','Nuovo file'],
    ['bi-file-earmark-spreadsheet','Foglio dati'],
    ['bi-table','Tabella'],
    ['bi-database','Database'],
    ['bi-database-gear','Database con impostazioni'],
    ['bi-server','Server'],
    ['bi-hdd-network','Rete'],
    ['bi-gear','Impostazioni'],
    ['bi-gear-fill','Impostazioni piene'],
    ['bi-list','Elenco'],
    ['bi-list-ul','Elenco puntato'],
    ['bi-list-check','Lista controlli'],
    ['bi-card-list','Scheda elenco'],
    ['bi-layout-text-window-reverse','Layout finestra'],
    ['bi-box-arrow-right','Uscita'],
    ['bi-plus-lg','Aggiungi'],
    ['bi-pencil','Modifica'],
    ['bi-pencil-square','Modifica scheda'],
    ['bi-trash','Cestino'],
    ['bi-trash3','Eliminazione'],
    ['bi-search','Cerca'],
    ['bi-funnel','Filtro'],
    ['bi-funnel-fill','Filtro pieno'],
    ['bi-bell','Notifica'],
    ['bi-bell-fill','Notifica piena'],
    ['bi-star','Preferito'],
    ['bi-heart','Cuore'],
    ['bi-link-45deg','Collegamento'],
    ['bi-diagram-3','Relazioni'],
    ['bi-diagram-2','Schema'],
    ['bi-person','Persona'],
    ['bi-person-lines-fill','Persona con elenco'],
    ['bi-calendar','Calendario'],
    ['bi-calendar-event','Evento'],
    ['bi-clock','Orario'],
    ['bi-check-circle','Confermato'],
    ['bi-exclamation-circle','Attenzione'],
    ['bi-info-circle','Informazioni']
];
const iconOptionHtml = iconOptions.map(([value,label])=>`<option value="${esc(value)}">${esc(label)} (${esc(value)})</option>`).join('');
const renderIconSelect = (selectedIcon, filterText = '') => {
    const normalized = normalizeIcon(selectedIcon || 'bi-file-earmark');
    const filter = String(filterText ?? '').trim().toLowerCase();
    const options = iconOptions.map(([value,label]) => {
        if (filter && !value.toLowerCase().includes(filter) && !label.toLowerCase().includes(filter)) {
            return '';
        }
        const selected = value === normalized ? ' selected' : '';
        return `<option value="${esc(value)}"${selected}>${esc(label)} (${esc(value)})</option>`;
    }).join('');
    const custom = iconOptions.some(([value]) => value === normalized)
        ? ''
        : `<option value="${esc(normalized)}" selected>${esc(normalized)} (personalizzata)</option>`;
    return options + custom;
};
async function req(url,opt={}){
    const r=await fetch(url,opt);
    const raw=await r.text();
    let d;

    try{
        d=JSON.parse(raw);
    }catch(error){
        const clean=raw
            .replace(/<[^>]*>/g,' ')
            .replace(/\s+/g,' ')
            .trim();

        throw new Error(
            'Il server non ha restituito JSON. Risposta: '
            +(clean.slice(0,240)||'risposta vuota')
        );
    }

    if(!r.ok||d.ok===false){
        throw new Error(d.message||'Errore');
    }

    return d;
}
function msg(t,type='success'){M.innerHTML=`<div class="alert alert-${type}">${esc(t)}</div>`}
function norm(x){return{clientId:id(),type:String(x.tipo||x.type||'PAGINA').toUpperCase(),file_name:normalizePageFileName(x.nome_file||x.file_name),label:x.label||'',icon:x.icona||x.icon||'bi-file-earmark',visible:Number(x.visibile??x.visible??1)===1,children:(x.children||[]).map(norm)}}
function flat(a,r=[]){a.forEach(x=>{r.push(x);flat(x.children||[],r)});return r}
function used(){return new Set(flat(state.menu).filter(x=>x.type==='PAGINA').map(x=>normalizePageFileName(x.file_name)).filter(Boolean))}
function renderAvailable(){
    const q=S.value.toLowerCase();
    const u=used();
    const rows=state.pages.filter(p=>{
        const file=normalizePageFileName(p.file_name);
        return file && !u.has(file) && (!q||file.toLowerCase().includes(q)||p.label.toLowerCase().includes(q));
    });

    document.getElementById('count').textContent=rows.length;

    if(state.availableSortable){
        state.availableSortable.destroy();
        state.availableSortable=null;
    }

    A.innerHTML=rows.map(p=>`<div class="page-item" data-file="${esc(p.file_name)}" data-label="${esc(p.label)}"><i class="bi bi-grip-vertical"></i> <i class="bi bi-file-earmark-code text-primary"></i> <strong>${esc(p.label)}</strong><div class="small text-muted ms-4">${esc(p.file_name)}</div></div>`).join('')||'<div class="text-muted text-center p-4">Nessuna pagina disponibile</div>';
    state.availableSortable=new Sortable(A,{group:{name:'menu',pull:'clone',put:false},sort:false,draggable:'.page-item'});
}
function itemHtml(x){
    const selectedIcon = normalizeIcon(x.icon || 'bi-file-earmark');
    return `<div class="menu-item" data-id="${x.clientId}" data-type="${x.type}"><div class="menu-head"><span class="drag"><i class="bi bi-grip-vertical"></i></span><span class="icon-preview"><i class="bi ${esc(selectedIcon)}"></i></span><div class="flex-grow-1"><strong class="summary">${esc(x.label)}</strong><div class="small text-muted">${x.type==='GRUPPO'?'Gruppo':esc(x.file_name)}</div></div><button class="btn btn-sm btn-outline-secondary edit"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-outline-danger del"><i class="bi bi-trash"></i></button></div><div class="menu-editor d-none"><div class="row g-2 align-items-end"><div class="col-md-5"><label class="small">Label</label><input class="form-control form-control-sm label" value="${esc(x.label)}"></div><div class="col-md-3"><label class="small">Cerca icona</label><input class="form-control form-control-sm icon-filter" placeholder="cartella, file, bi-folder..."></div><div class="col-md-4"><label class="small">Icona Bootstrap</label><div class="input-group input-group-sm"><span class="input-group-text icon-live"><i class="bi ${esc(selectedIcon)}"></i></span><select class="form-select form-select-sm icon">${renderIconSelect(selectedIcon)}</select></div></div><div class="col-md-3"><label class="small d-block">Visibile</label><input type="checkbox" class="form-check-input visible" ${x.visible?'checked':''}></div></div></div><div class="menu-children">${(x.children||[]).map(itemHtml).join('')}</div></div>`}
function find(a,k){for(const x of a){if(x.clientId===k)return x;const y=find(x.children||[],k);if(y)return y}return null}
function remove(a,k){const i=a.findIndex(x=>x.clientId===k);if(i>=0)return a.splice(i,1)[0];for(const x of a){const y=remove(x.children||[],k);if(y)return y}return null}
function sync(){function read(c){return [...c.children].filter(e=>e.classList.contains('menu-item')).map(e=>{const old=find(state.menu,e.dataset.id);return{clientId:e.dataset.id,type:e.dataset.type,file_name:old?.file_name||'',label:e.querySelector(':scope > .menu-editor .label')?.value.trim()||old?.label||'',icon:e.querySelector(':scope > .menu-editor .icon')?.value.trim()||old?.icon||'bi-file-earmark',visible:e.querySelector(':scope > .menu-editor .visible')?.checked??true,children:read(e.querySelector(':scope > .menu-children'))}})}state.menu=read(R)}
function bind(){R.querySelectorAll('.edit').forEach(b=>b.onclick=()=>b.closest('.menu-item').querySelector(':scope > .menu-editor').classList.toggle('d-none'));R.querySelectorAll('.del').forEach(b=>b.onclick=()=>{sync();const e=b.closest('.menu-item'),x=find(state.menu,e.dataset.id);if(confirm(`Eliminare "${x?.label||''}" dal menu?`)){remove(state.menu,e.dataset.id);render()}});R.querySelectorAll('.label,.icon,.visible,.icon-filter').forEach(c=>c.onchange=()=>{const e=c.closest('.menu-item');e.querySelector(':scope > .menu-head .summary').textContent=e.querySelector('.label').value||'Senza label';const i=normalizeIcon(e.querySelector('.icon').value);e.querySelector('.icon-preview i').className='bi '+i;e.querySelector('.icon-live i').className='bi '+i;if(c.classList.contains('icon-filter')){const sel=e.querySelector('.icon');sel.innerHTML=renderIconSelect(sel.value,c.value)}})}
function render(){state.sortables.forEach(s=>s.destroy());state.sortables=[];R.innerHTML=state.menu.map(itemHtml).join('')||'<div class="text-muted text-center p-5 border rounded">Trascina qui le pagine</div>';bind();[R,...R.querySelectorAll('.menu-children')].forEach(c=>{const s=new Sortable(c,{group:'menu',animation:150,handle:'.drag',draggable:'.menu-item,.page-item',onAdd:e=>{if(e.item.classList.contains('page-item')){const n={clientId:id(),type:'PAGINA',file_name:e.item.dataset.file,label:e.item.dataset.label,icon:'bi-file-earmark',visible:true,children:[]};e.item.remove();sync();const pe=e.to.closest('.menu-item');if(e.to.classList.contains('menu-children')&&pe)find(state.menu,pe.dataset.id).children.splice(e.newIndex,0,n);else state.menu.splice(e.newIndex,0,n);render()}else setTimeout(sync)},onEnd:()=>setTimeout(()=>{sync();renderAvailable()})});state.sortables.push(s)});renderAvailable()}
function serial(a){return a.map(x=>({type:x.type,file_name:x.file_name,label:x.label,icon:x.icon,visible:x.visible,children:serial(x.children||[])}))}
async function load(){
    C.innerHTML='<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Caricamento configurazione salvata...</span>';

    try{
        const d=await req(endpoint+'load');

        state.pages=(d.pages||[]).map(p=>({
            ...p,
            file_name: normalizePageFileName(p.file_name)
        })).filter(p=>p.file_name);
        state.menu=(d.menu||[]).map(norm);

        // Store available page file names in a Set for quick lookup
        state.availablePageFileNames = new Set(state.pages.map(p => p.file_name));

        T.value=d.settings?.titolo_sito||'';
        D.value=d.settings?.descrizione_home||'';

        if(d.configuration_exists){
            const modified=d.settings?.data_modifica
                ? ` · ultimo salvataggio: ${esc(d.settings.data_modifica)}`
                : '';

            C.innerHTML=
                `<span class="text-success">`
                + `<i class="bi bi-check-circle me-1"></i>`
                + `Configurazione caricata: ${Number(d.menu_items_count||0)} voci${modified}`
                + `</span>`;
        }else{
            C.innerHTML=
                '<span class="text-warning">'
                + '<i class="bi bi-info-circle me-1"></i>'
                + 'Nessuna configurazione salvata: nuova configurazione.'
                + '</span>';
        }

        render();
    }catch(e){
        C.innerHTML='<span class="text-danger">Configurazione non caricata.</span>';
        msg(e.message,'danger');
    }
}
async function save(){
    sync();

    const nonExistentPages = [];
    function checkPages(items) {
        for (const item of items) {
            const fileName = normalizePageFileName(item.file_name);
            if (item.type === 'PAGINA' && fileName && !state.availablePageFileNames.has(fileName)) {
                nonExistentPages.push(item);
            }

            if (Array.isArray(item.children) && item.children.length > 0) {
                checkPages(item.children);
            }
        }
    }
    checkPages(state.menu);

    if (nonExistentPages.length > 0) {
        const confirmMessage = `Le seguenti pagine non esistono più nel filesystem e verranno rimosse dal menu:\n\n`
            + nonExistentPages.map(p => `- ${p.label} (${p.file_name})`).join('\n')
            + `\n\nVuoi continuare e rimuoverle?`;

        if (!confirm(confirmMessage)) {
            msg('Salvataggio annullato dall\'utente.', 'info');
            return; // Abort save
        }

        // Remove non-existent pages from state.menu
        function cleanMenu(items) {
            return items.filter(item => {
                const fileName = normalizePageFileName(item.file_name);
                if (item.type === 'PAGINA' && fileName && !state.availablePageFileNames.has(fileName)) {
                    return false;
                }
                if (Array.isArray(item.children) && item.children.length > 0) {
                    item.children = cleanMenu(item.children);
                }
                return true;
            });
        }
        state.menu = cleanMenu(state.menu);
        render();
        renderAvailable();
        msg('Pagine non esistenti rimosse dal menu.', 'info');
    }

    const d=await req(endpoint+'save',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            title:T.value.trim(),
            description:D.value.trim(),
            items:serial(state.menu)
        })
    });

    msg(d.message);
    await load();
}
document.getElementById('addGroup').onclick=()=>{sync();state.menu.push({clientId:id(),type:'GRUPPO',file_name:'',label:'Nuovo gruppo',icon:'bi-folder',visible:true,children:[]});render()};
document.getElementById('saveBtn').onclick=async()=>{try{await save()}catch(e){msg(e.message,'danger')}};
document.getElementById('generateBtn').onclick=async()=>{try{await save();const d=await req(endpoint+'generate',{method:'POST'});msg(d.message+' '+d.path)}catch(e){msg(e.message,'danger')}};
document.getElementById('reloadBtn').onclick=()=>{
    if(confirm('Ricaricare la configurazione salvata? Le modifiche non salvate verranno perse.')){
        load();
    }
};

S.oninput=renderAvailable;
load();
})();
</script>

