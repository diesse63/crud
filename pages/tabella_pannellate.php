<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : CRUD Generator
 * Versione   : 10.2
 * Creato il  : 2026-07-31 00:00:00
 * Modificato il: 2026-08-10 00:00
 * Progetto   : CRUD Generator
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/pannellate_core.php';
require_once __DIR__ . '/versioning.php';
pannellateBootSession();
pannellateEnsureDb();

$pageTitle = 'Elenco schede configurate nel progetto attivo - gestionale';
$project = pannellateProjectContext();
$progettoId = (int) $project['id'];
$progettoNome = (string) $project['name'];

try {
    if (!isset($db) || !($db instanceof Database)) {
        $db = new Database();
    }
} catch (Throwable $databaseError) {
    http_response_code(500);
    echo '<div class="alert alert-danger m-3">Errore di connessione al database.</div>';
    return;
}

$deleteConfigurationId = isset($_GET['delete_configuration'])
    ? (int) ($_GET['configuration_id'] ?? 0)
    : 0;

$openUpdateId = isset($_GET['open_update'])
    ? (int) ($_GET['configuration_id'] ?? 0)
    : 0;

if ($openUpdateId > 0 && $progettoId > 0) {
    pannellateRedirectToCreator($openUpdateId);
}

if ($deleteConfigurationId > 0 && $progettoId > 0) {
    $page = $db->fetch(
        "SELECT id, nome_pagina, nome_file, percorso_file
         FROM pagine_visualizzazione
         WHERE id = ? AND IDprogetto = ?",
        [$deleteConfigurationId, $progettoId]
    );

    if ($page) {
        $safeFilePath = null;
        $pageFileName = trim((string) ($page['nome_file'] ?? ''));
        $storedPath = trim((string) ($page['percorso_file'] ?? ''));
        if ($storedPath !== '') {
            $realPath = realpath($storedPath);
            $projectPages = realpath(__DIR__ . '/sito/' . preg_replace('/[^a-z0-9_]/i', '', strtolower($progettoNome ?: 'gestionale')) . '/pages');
            if ($realPath !== false && $projectPages !== false) {
                $allowedPrefix = rtrim($projectPages, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (str_starts_with($realPath, $allowedPrefix)) {
                    $safeFilePath = $realPath;
                }
            }
        }

        $db->beginTransaction();
        try {
            if ($pageFileName !== '') {
                $db->execute(
                    "DELETE FROM menu_home_voci
                     WHERE IDprogetto = ? AND nome_file = ?",
                    [$progettoId, $pageFileName]
                );
            }
            $db->execute(
                "DELETE FROM pagine_visualizzazione_modali WHERE IDpagina = ?",
                [$deleteConfigurationId]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione_campi WHERE IDpagina = ?",
                [$deleteConfigurationId]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione_tabelle WHERE IDpagina = ?",
                [$deleteConfigurationId]
            );
            $db->execute(
                "UPDATE pagine_visualizzazione_campi
                 SET link_pagina_id = NULL,
                     link_parametro = NULL,
                     link_campo_valore = NULL
                 WHERE link_pagina_id = ?",
                [$deleteConfigurationId]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$deleteConfigurationId, $progettoId]
            );

            $db->commit();

            if ($safeFilePath !== null && is_file($safeFilePath)) {
                @unlink($safeFilePath);
            }

            header('Location: index.php?page=tabella_pannellate');
            exit;
        } catch (Throwable $deleteError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo '<div class="alert alert-danger m-3">'
                . 'Impossibile eliminare la pagina: '
                . htmlspecialchars($deleteError->getMessage(), ENT_QUOTES, 'UTF-8')
                . '</div>';
            return;
        }
    }
}

$pageDeployConfig = null;
if ($progettoId > 0) {
    $pageDeployConfig = $db->fetch(
        "SELECT ultima_pubblicazione, ultimo_esito
         FROM progetti_deploy_https
         WHERE IDprogetto = ?",
        [$progettoId]
    ) ?: null;
}

$cacheBuster = time();

$rows = [];
if ($progettoId > 0) {
    $rows = $db->fetchAll(
        "SELECT
            pv.*,
            COALESCE(pt.codice, '') AS tipo_visualizzazione,
            COALESCE(pt.descrizione, '') AS tipo_descrizione
         FROM pagine_visualizzazione pv
         LEFT JOIN pagine_visualizzazione_tipo pt ON pt.id = pv.IDtipo
         WHERE pv.IDprogetto = ?
         ORDER BY pv.data_modifica DESC, pv.nome_pagina, pv.nome_file",
        [$progettoId]
    );
}

$typeLabelMap = [
    'HOME' => 'Home',
    'SCHEDA_SINGOLA' => 'Singola',
    'TABELLA_MODALE' => 'Tabellare',
    'MASTER_DETAIL' => 'Master detail',
];

function pageStatusLabel(array $row): array
{
    $storedPath = trim((string) ($row['percorso_file'] ?? ''));
    $status = strtoupper((string) ($row['stato'] ?? ''));

    if ($status === 'GENERATA' || $storedPath !== '') {
        return ['label' => 'Generata', 'class' => 'text-bg-success'];
    }

    if ($status === 'SALVATA' || $status === 'CONFIGURATA') {
        return ['label' => 'Configurata', 'class' => 'text-bg-warning'];
    }

    return ['label' => 'Da verificare', 'class' => 'text-bg-secondary'];
}

function pageTransmissionLabel(array $row, ?array $deployConfig): array
{
    $storedPath = trim((string) ($row['percorso_file'] ?? ''));
    $publishedAt = trim((string) ($deployConfig['ultima_pubblicazione'] ?? ''));
    $lastDeployStatus = strtoupper((string) ($deployConfig['ultimo_esito'] ?? ''));
    $resolvedPath = $storedPath !== '' ? realpath($storedPath) : false;

    if ($resolvedPath === false || !is_file($resolvedPath)) {
        return ['label' => 'File non presente', 'class' => 'text-bg-secondary'];
    }

    if ($publishedAt === '' || $lastDeployStatus !== 'SUCCESSO') {
        return ['label' => 'Da trasmettere', 'class' => 'text-bg-warning'];
    }

    $fileModified = @filemtime($resolvedPath);
    $publishedTs = strtotime($publishedAt);

    if ($fileModified !== false && $publishedTs !== false && $fileModified > $publishedTs) {
        return ['label' => 'Da ritrasmettere', 'class' => 'text-bg-warning'];
    }

    return ['label' => 'Trasmessa', 'class' => 'text-bg-success'];
}

function pageVersionLabel(array $row): string
{
    $storedPath = trim((string) ($row['percorso_file'] ?? ''));
    if ($storedPath === '') {
        return '-';
    }

    $resolvedPath = realpath($storedPath);
    if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
        return '-';
    }

    $content = @file_get_contents($resolvedPath);
    if ($content === false) {
        return '-';
    }

    if (preg_match('/^\s*\*\s*Versione pagina\s*:\s*([0-9]+\.[0-9]+)/mi', $content, $matches)) {
        return crudVersionNormalize((string) $matches[1]);
    }

    if (preg_match('/\$generatedPageVersion\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"];/i', $content, $matches)) {
        return crudVersionNormalize((string) $matches[1]);
    }

    return '-';
}

function creatorVersionLabel(array $row): string
{
    $storedPath = trim((string) ($row['percorso_file'] ?? ''));
    if ($storedPath === '') {
        return '-';
    }

    $resolvedPath = realpath($storedPath);
    if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
        return '-';
    }

    $content = @file_get_contents($resolvedPath);
    if ($content === false) {
        return '-';
    }

    if (preg_match('/^\s*\*\s*Versione creatore\s*:\s*([0-9]+\.[0-9]+)/mi', $content, $matches)) {
        return crudVersionNormalize((string) $matches[1]);
    }

    if (preg_match('/\$generatedVersion\s*=\s*[\'"]([0-9]+\.[0-9]+)[\'"];/i', $content, $matches)) {
        return crudVersionNormalize((string) $matches[1]);
    }

    return '-';
}
?>
<style>
.tabella-pannellate-fab{
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 1080;
    border-radius: 999px;
    box-shadow: 0 .75rem 1.75rem rgba(0, 0, 0, .18);
    padding: .9rem 1.2rem;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
}
.tabella-pannellate-fab i{
    font-size: 1.05rem;
}
@media (max-width: 576px){
    .tabella-pannellate-fab{
        left: .85rem;
        right: .85rem;
        bottom: .85rem;
        width: calc(100% - 1.7rem);
        justify-content: center;
    }
}
</style>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="mb-0">Elenco schede configurate nel progetto attivo - gestionale</h2>
            <div class="text-muted">Gestione pagine</div>
        </div>
    </div>
    <a class="btn btn-primary tabella-pannellate-fab"
       href="index.php?page=creatore_pagina&mode=new&t=<?= $cacheBuster ?>">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
        <span class="visually-hidden">Nuova pannellata</span>
    </a>

    <?php if ($progettoId <= 0): ?>
        <div class="alert alert-warning">
            Seleziona prima un progetto attivo.
        </div>
    <?php elseif (!$rows): ?>
        <div class="alert alert-secondary">
            Nessuna scheda configurata.
        </div>
    <?php else: ?>
        <div class="table-responsive shadow-sm border rounded">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nome scheda</th>
                        <th>Nome file</th>
                        <th>Titolo visualizzato</th>
                        <th>Tipo scheda</th>
                        <th>Versione creator</th>
                        <th>Versione pagina</th>
                        <th>Stato</th>
                        <th>Trasmissione</th>
                        <th class="text-end" style="width:1%">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $typeCode = strtoupper((string) ($row['tipo_visualizzazione'] ?? ''));
                        $typeDescription = trim((string) ($row['tipo_descrizione'] ?? ''));
                        $typeLabel = $typeLabelMap[$typeCode] ?? $typeCode;
                        if ($typeDescription !== '') {
                            $typeLabel = $typeDescription;
                        }
                        $creatorVersion = creatorVersionLabel($row);
                        $pageVersion = pageVersionLabel($row);
                        $status = pageStatusLabel($row);
                        $transmission = pageTransmissionLabel($row, $pageDeployConfig);
                        $editUrl = 'index.php?page=creatore_pagina&mode=edit&configuration_id=' . (int) ($row['id'] ?? 0) . '&t=' . $cacheBuster;
                        ?>
                        <tr class="align-middle"
                            data-href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"
                            style="cursor:pointer">
                            <td><?= htmlspecialchars((string) ($row['nome_pagina'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><code><?= htmlspecialchars((string) ($row['nome_file'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars(
                                trim((string) ($row['titolo_pagina'] ?? '')) !== ''
                                    ? (string) $row['titolo_pagina']
                                    : ((string) ($row['nome_pagina'] ?? '') !== '' ? (string) $row['nome_pagina'] : (string) ($row['nome_file'] ?? '')),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></td>
                            <td><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($creatorVersion, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($pageVersion, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><span class="badge <?= htmlspecialchars($status['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><span class="badge <?= htmlspecialchars($transmission['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($transmission['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-outline-danger"
                                   style="min-width:11rem"
                                   href="index.php?page=tabella_pannellate&delete_configuration=1&configuration_id=<?= (int) ($row['id'] ?? 0) ?>&t=<?= $cacheBuster ?>"
                                   onclick="return confirm('Eliminare completamente questa pagina?');">
                                    Elimina
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        document.querySelectorAll('tr[data-href]').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) {
                    return;
                }
                window.location.href = row.dataset.href;
            });
        });
        </script>
    <?php endif; ?>
</div>
