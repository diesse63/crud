<?php
/**
 * pages/schema_db.php
 *
 * Diagramma ER dinamico da schema.sql
 * - File unico
 * - Nessuna libreria esterna
 * - Compatibile con Altervista/PHP 8+
 * - Legge: pages/sito/<nome_progetto>/schema.sql
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('schemaSanitizeFolderName')) {
    function schemaSanitizeFolderName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace([' ', '.', ',', '!', '?'], '_', $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        return $name ?: 'progetto_senza_nome';
    }
}

if (!function_exists('schemaSplitTopLevel')) {
    function schemaSplitTopLevel(string $text): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($escaped) {
                $buffer .= $ch;
                $escaped = false;
                continue;
            }

            if ($ch === '\\' && $quote !== null) {
                $buffer .= $ch;
                $escaped = true;
                continue;
            }

            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === $quote) {
                    if ($quote === "'" && $i + 1 < $length && $text[$i + 1] === "'") {
                        $buffer .= $text[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $ch;
                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }

        return $parts;
    }
}

if (!function_exists('schemaParseIdentifierList')) {
    function schemaParseIdentifierList(string $text): array
    {
        preg_match_all('/`([^`]+)`|([A-Za-z0-9_]+)/', $text, $matches, PREG_SET_ORDER);
        $out = [];

        foreach ($matches as $match) {
            $value = $match[1] !== '' ? $match[1] : $match[2];
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}

if (!function_exists('schemaParseColumn')) {
    function schemaParseColumn(string $definition): ?array
    {
        if (!preg_match('/^\s*`([^`]+)`\s+(.+)$/is', $definition, $m)) {
            return null;
        }

        $name = $m[1];
        $rest = trim($m[2]);

        $keywords = [
            'NOT NULL', 'NULL', 'DEFAULT', 'AUTO_INCREMENT', 'PRIMARY KEY',
            'UNIQUE', 'COMMENT', 'COLLATE', 'CHARACTER SET', 'ON UPDATE',
            'GENERATED', 'REFERENCES', 'CHECK'
        ];

        $cut = strlen($rest);
        foreach ($keywords as $keyword) {
            if (preg_match('/\s+' . preg_quote($keyword, '/') . '\b/i', $rest, $km, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, $km[0][1]);
            }
        }

        $type = trim(substr($rest, 0, $cut));
        $nullable = !preg_match('/\bNOT\s+NULL\b/i', $rest);
        $autoIncrement = (bool)preg_match('/\bAUTO_INCREMENT\b/i', $rest);
        $inlinePrimary = (bool)preg_match('/\bPRIMARY\s+KEY\b/i', $rest);
        $inlineUnique = (bool)preg_match('/\bUNIQUE\b/i', $rest);

        $default = null;
        if (preg_match('/\bDEFAULT\s+((?:\'(?:\'\'|[^\'])*\')|(?:"(?:""|[^"])*")|(?:[^\s,]+))/i', $rest, $dm)) {
            $default = $dm[1];
        }

        $onUpdate = null;
        if (preg_match('/\bON\s+UPDATE\s+([^\s,]+)/i', $rest, $um)) {
            $onUpdate = $um[1];
        }

        return [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
            'auto_increment' => $autoIncrement,
            'primary' => $inlinePrimary,
            'unique' => $inlineUnique,
            'indexed' => false,
            'foreign' => false,
            'default' => $default,
            'on_update' => $onUpdate,
            'fk_targets' => []
        ];
    }
}

if (!function_exists('schemaParseSql')) {
    function schemaParseSql(string $sql): array
    {
        $sql = preg_replace('/\/\*![\s\S]*?\*\//', '', $sql);
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = preg_replace('/^\s*#.*$/m', '', $sql);

        $tables = [];
        $relations = [];

        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE\s*=/is',
            $sql,
            $matches,
            PREG_SET_ORDER
        );

        if (!$matches) {
            preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*;/is',
                $sql,
                $matches,
                PREG_SET_ORDER
            );
        }

        foreach ($matches as $tableMatch) {
            $tableName = $tableMatch[1];
            $body = $tableMatch[2];
            $parts = schemaSplitTopLevel($body);

            $table = [
                'name' => $tableName,
                'columns' => [],
                'primary_key' => [],
                'indexes' => [],
                'foreign_keys' => []
            ];

            foreach ($parts as $part) {
                $trimmed = trim($part);

                if ($trimmed === '') {
                    continue;
                }

                if ($trimmed[0] === '`') {
                    $column = schemaParseColumn($trimmed);
                    if ($column !== null) {
                        $table['columns'][$column['name']] = $column;
                        if ($column['primary']) {
                            $table['primary_key'][] = $column['name'];
                        }
                    }
                    continue;
                }

                if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/is', $trimmed, $pkm)) {
                    $cols = schemaParseIdentifierList($pkm[1]);
                    $table['primary_key'] = array_values(array_unique(array_merge($table['primary_key'], $cols)));
                    continue;
                }

                if (preg_match('/^(?:CONSTRAINT\s+`?([^`\s]+)`?\s+)?FOREIGN\s+KEY\s*\((.+?)\)\s+REFERENCES\s+`?([^`\s(]+)`?\s*\((.+?)\)(.*)$/is', $trimmed, $fkm)) {
                    $fkName = $fkm[1] ?: ('fk_' . $tableName . '_' . count($table['foreign_keys']));
                    $localColumns = schemaParseIdentifierList($fkm[2]);
                    $refTable = $fkm[3];
                    $refColumns = schemaParseIdentifierList($fkm[4]);
                    $tail = $fkm[5];

                    $onDelete = 'RESTRICT';
                    $onUpdate = 'RESTRICT';

                    if (preg_match('/ON\s+DELETE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)/i', $tail, $od)) {
                        $onDelete = strtoupper(preg_replace('/\s+/', ' ', $od[1]));
                    }
                    if (preg_match('/ON\s+UPDATE\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)/i', $tail, $ou)) {
                        $onUpdate = strtoupper(preg_replace('/\s+/', ' ', $ou[1]));
                    }

                    $fk = [
                        'name' => $fkName,
                        'columns' => $localColumns,
                        'ref_table' => $refTable,
                        'ref_columns' => $refColumns,
                        'on_delete' => $onDelete,
                        'on_update' => $onUpdate
                    ];

                    $table['foreign_keys'][] = $fk;

                    $relations[] = [
                        'name' => $fkName,
                        'from_table' => $tableName,
                        'from_columns' => $localColumns,
                        'to_table' => $refTable,
                        'to_columns' => $refColumns,
                        'on_delete' => $onDelete,
                        'on_update' => $onUpdate
                    ];

                    continue;
                }

                if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`?([^`\s(]+)`?\s*\((.+)\)$/is', $trimmed, $im)) {
                    $unique = trim($im[1]) !== '';
                    $indexName = $im[2];
                    $cols = schemaParseIdentifierList($im[3]);

                    $table['indexes'][] = [
                        'name' => $indexName,
                        'columns' => $cols,
                        'unique' => $unique,
                        'type' => $unique ? 'UNIQUE' : 'INDEX'
                    ];

                    continue;
                }

                if (preg_match('/^UNIQUE\s*\((.+)\)$/is', $trimmed, $um)) {
                    $cols = schemaParseIdentifierList($um[1]);
                    $table['indexes'][] = [
                        'name' => 'uniq_' . implode('_', $cols),
                        'columns' => $cols,
                        'unique' => true,
                        'type' => 'UNIQUE'
                    ];
                }
            }

            foreach ($table['primary_key'] as $columnName) {
                if (isset($table['columns'][$columnName])) {
                    $table['columns'][$columnName]['primary'] = true;
                    $table['columns'][$columnName]['indexed'] = true;
                }
            }

            foreach ($table['indexes'] as $index) {
                foreach ($index['columns'] as $columnName) {
                    if (isset($table['columns'][$columnName])) {
                        $table['columns'][$columnName]['indexed'] = true;
                        if ($index['unique']) {
                            $table['columns'][$columnName]['unique'] = true;
                        }
                    }
                }
            }

            foreach ($table['foreign_keys'] as $fk) {
                foreach ($fk['columns'] as $position => $columnName) {
                    if (isset($table['columns'][$columnName])) {
                        $table['columns'][$columnName]['foreign'] = true;
                        $table['columns'][$columnName]['indexed'] = true;
                        $table['columns'][$columnName]['fk_targets'][] = [
                            'table' => $fk['ref_table'],
                            'column' => $fk['ref_columns'][$position] ?? '',
                            'constraint' => $fk['name']
                        ];
                    }
                }
            }

            $table['columns'] = array_values($table['columns']);
            $tables[$tableName] = $table;
        }

        return [
            'tables' => array_values($tables),
            'relations' => $relations,
            'statistics' => [
                'tables' => count($tables),
                'columns' => array_sum(array_map(fn($t) => count($t['columns']), $tables)),
                'foreign_keys' => count($relations),
                'indexes' => array_sum(array_map(fn($t) => count($t['indexes']), $tables)),
                'unique_indexes' => array_sum(array_map(
                    fn($t) => count(array_filter($t['indexes'], fn($i) => $i['unique'])),
                    $tables
                ))
            ]
        ];
    }
}

$projectId = $_SESSION['progetto_id'] ?? null;
$projectName = $_SESSION['progetto_nome'] ?? null;
$folderName = $projectName ? schemaSanitizeFolderName($projectName) : null;

$projectRoot = $folderName
    ? __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $folderName
    : null;

$schemaPath = $projectRoot
    ? $projectRoot . DIRECTORY_SEPARATOR . 'schema.sql'
    : null;

$schemaExists = $schemaPath && is_file($schemaPath);
$buildRequested = isset($_GET['build']) && $_GET['build'] === '1';

$databaseModel = null;
$errorMessage = null;
$schemaModified = null;

if ($schemaExists) {
    $schemaModified = date('d/m/Y H:i', filemtime($schemaPath));

    if ($buildRequested) {
        $sqlContent = @file_get_contents($schemaPath);

        if ($sqlContent === false) {
            $errorMessage = 'Impossibile leggere il file schema.sql.';
        } else {
            try {
                $databaseModel = schemaParseSql($sqlContent);

                if (empty($databaseModel['tables'])) {
                    $errorMessage = 'Nessuna istruzione CREATE TABLE riconosciuta nel file schema.sql.';
                }
            } catch (Throwable $e) {
                $errorMessage = 'Errore durante l’analisi dello schema: ' . $e->getMessage();
            }
        }
    }
}

$modelJson = $databaseModel
    ? json_encode($databaseModel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : 'null';
?>

<style>
.schema-page {
    --blue: #0f5db8;
    --blue-dark: #0b4a92;
    --blue-soft: #eaf3ff;
    --border: #d9e2ec;
    --text: #1f2937;
    --muted: #64748b;
    --panel: #ffffff;
    --bg: #f5f7fb;
    --danger: #b42318;
    --success: #157347;
    position: relative;
    color: var(--text);
}

.schema-page * {
    box-sizing: border-box;
}

.schema-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}

.schema-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.schema-title-badge {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    color: white;
    background: linear-gradient(145deg, var(--blue), var(--blue-dark));
    box-shadow: 0 8px 20px rgba(15, 93, 184, .22);
    font-size: 20px;
}

.schema-title h4 {
    margin: 0;
    font-size: 1.25rem;
}

.schema-title small {
    color: var(--muted);
}

.schema-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 10px 26px rgba(30, 41, 59, .07);
}

.schema-empty {
    padding: 34px;
    text-align: center;
}

.schema-empty-icon {
    width: 66px;
    height: 66px;
    margin: 0 auto 16px;
    border-radius: 18px;
    display: grid;
    place-items: center;
    background: #fff4e5;
    color: #b54708;
    font-size: 30px;
}

.schema-toolbar {
    padding: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    align-items: center;
    border-bottom: 1px solid var(--border);
    background: #fbfdff;
    border-radius: 14px 14px 0 0;
}

.schema-toolbar .btn {
    white-space: nowrap;
}

.schema-search {
    margin-left: auto;
    min-width: 240px;
    position: relative;
}

.schema-search input {
    padding-left: 35px;
}

.schema-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
}

.schema-stats {
    padding: 10px 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border-bottom: 1px solid var(--border);
    background: white;
}

.schema-stat {
    border: 1px solid #dce7f3;
    background: #f8fbff;
    color: #31516f;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.schema-workspace-wrap {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 330px;
    min-height: 680px;
}

.schema-canvas {
    position: relative;
    overflow: hidden;
    min-height: 680px;
    background-color: #fff;
    background-image:
        linear-gradient(#edf2f7 1px, transparent 1px),
        linear-gradient(90deg, #edf2f7 1px, transparent 1px);
    background-size: 24px 24px;
    border-radius: 0 0 0 14px;
    cursor: grab;
    user-select: none;
}

.schema-canvas.is-panning {
    cursor: grabbing;
}

#schemaSvg {
    width: 100%;
    height: 680px;
    display: block;
}

.schema-side {
    border-left: 1px solid var(--border);
    background: #fbfdff;
    border-radius: 0 0 14px 0;
    overflow: auto;
    max-height: 680px;
}

.schema-side-header {
    position: sticky;
    top: 0;
    z-index: 2;
    background: linear-gradient(145deg, var(--blue), var(--blue-dark));
    color: white;
    padding: 15px 16px;
}

.schema-side-body {
    padding: 15px;
}

.schema-side-placeholder {
    color: var(--muted);
    text-align: center;
    padding: 42px 18px;
}

.schema-info-section {
    margin-bottom: 18px;
}

.schema-info-section h6 {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    margin: 0 0 8px;
}

.schema-field-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 9px 10px;
    margin-bottom: 7px;
    font-size: 12px;
}

.schema-field-card strong {
    display: block;
    font-size: 13px;
    color: var(--text);
}

.schema-tag {
    display: inline-flex;
    align-items: center;
    margin: 4px 4px 0 0;
    padding: 2px 6px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    background: #edf4ff;
    color: #245d9e;
}

.schema-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 16px;
    padding: 10px 14px;
    border-top: 1px solid var(--border);
    background: #fbfdff;
    border-radius: 0 0 14px 14px;
    font-size: 12px;
    color: #475569;
}

.schema-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.schema-alert {
    padding: 14px 16px;
    border-radius: 10px;
    border: 1px solid #f4c7c3;
    background: #fff4f2;
    color: var(--danger);
    margin-bottom: 15px;
}

@media (max-width: 1050px) {
    .schema-workspace-wrap {
        grid-template-columns: 1fr;
    }

    .schema-side {
        border-left: 0;
        border-top: 1px solid var(--border);
        max-height: none;
        border-radius: 0;
    }

    .schema-canvas {
        border-radius: 0;
    }
}

@media (max-width: 700px) {
    .schema-header {
        align-items: flex-start;
    }

    .schema-title {
        align-items: flex-start;
    }

    .schema-title h4 {
        font-size: 1.05rem;
    }

    .schema-toolbar {
        padding: 10px;
    }

    .schema-search {
        width: 100%;
        min-width: 0;
        margin-left: 0;
    }

    .schema-legend {
        gap: 8px 12px;
        font-size: 11px;
    }

    .schema-side-body {
        padding: 12px;
    }

    .schema-field-card {
        font-size: 11px;
    }

    #schemaSvg,
    .schema-canvas {
        height: 480px;
        min-height: 480px;
    }
}

@media (max-width: 420px) {
    #schemaSvg,
    .schema-canvas {
        height: 420px;
        min-height: 420px;
    }

    .schema-title-badge {
        width: 38px;
        height: 38px;
        font-size: 18px;
    }
}
</style>

<div class="container-fluid py-3 schema-page">
    <div class="schema-header">
        <div class="schema-title">
            <div class="schema-title-badge">
                <i class="bi bi-diagram-3"></i>
            </div>
            <div>
                <h4>Diagramma schema SQL</h4>
                <small>
                    Progetto:
                    <strong><?= htmlspecialchars((string)($projectName ?? 'nessuno'), ENT_QUOTES, 'UTF-8') ?></strong>
                </small>
            </div>
        </div>

        <?php if ($schemaExists): ?>
            <a
                class="btn btn-primary fw-semibold shadow-sm"
                href="?page=schema_db&amp;build=1"
            >
                <i class="bi bi-diagram-3-fill me-2"></i>
                <?= $buildRequested ? 'Ricostruisci diagramma' : 'Costruisci diagramma' ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$projectId || !$projectName): ?>
        <div class="schema-card schema-empty">
            <div class="schema-empty-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <h5>Nessun progetto attivo</h5>
            <p class="text-muted mb-0">
                Seleziona prima un progetto tramite l’apposita voce del menu.
            </p>
        </div>

    <?php elseif (!$schemaExists): ?>
        <div class="schema-card schema-empty">
            <div class="schema-empty-icon"><i class="bi bi-file-earmark-x"></i></div>
            <h5>Il file schema.sql non è presente</h5>
            <p class="text-muted mb-2">
                Crea lo schema SQL utilizzando l’apposita voce del menu hamburger.
            </p>
            <small class="text-muted">
                Percorso atteso:
                <code><?= htmlspecialchars((string)$schemaPath, ENT_QUOTES, 'UTF-8') ?></code>
            </small>
        </div>

    <?php else: ?>
        <?php if ($errorMessage): ?>
            <div class="schema-alert">
                <i class="bi bi-exclamation-octagon me-2"></i>
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!$buildRequested || !$databaseModel): ?>
            <div class="schema-card schema-empty">
                <div class="schema-empty-icon" style="background:#eaf3ff;color:#0f5db8;">
                    <i class="bi bi-database-gear"></i>
                </div>
                <h5>Schema SQL disponibile</h5>
                <p class="text-muted mb-3">
                    Premi il pulsante <strong>Costruisci diagramma</strong> per analizzare il file e generare lo schema ER.
                </p>
                <div class="small text-muted">
                    Ultima modifica: <strong><?= htmlspecialchars((string)$schemaModified) ?></strong>
                </div>
            </div>

        <?php else: ?>
            <div class="schema-card">
                <div class="schema-toolbar">
                    <a class="btn btn-primary btn-sm" href="?page=schema_db&amp;build=1">
                        <i class="bi bi-arrow-clockwise me-1"></i> Ricostruisci
                    </a>
                    <button class="btn btn-outline-primary btn-sm" type="button" id="fitBtn">
                        <i class="bi bi-arrows-fullscreen me-1"></i> Adatta
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="zoomInBtn">
                        <i class="bi bi-zoom-in"></i>
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="zoomOutBtn">
                        <i class="bi bi-zoom-out"></i>
                    </button>
                    <button class="btn btn-outline-success btn-sm" type="button" id="exportSvgBtn">
                        <i class="bi bi-filetype-svg me-1"></i> SVG
                    </button>
                    <button class="btn btn-outline-success btn-sm" type="button" id="exportPngBtn">
                        <i class="bi bi-file-earmark-image me-1"></i> PNG
                    </button>

                    <div class="schema-search">
                        <i class="bi bi-search"></i>
                        <input
                            type="search"
                            id="schemaSearch"
                            class="form-control form-control-sm"
                            placeholder="Cerca tabella..."
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="schema-stats">
                    <span class="schema-stat"><?= (int)$databaseModel['statistics']['tables'] ?> tabelle</span>
                    <span class="schema-stat"><?= (int)$databaseModel['statistics']['columns'] ?> campi</span>
                    <span class="schema-stat"><?= (int)$databaseModel['statistics']['foreign_keys'] ?> foreign key</span>
                    <span class="schema-stat"><?= (int)$databaseModel['statistics']['indexes'] ?> indici</span>
                    <span class="schema-stat"><?= (int)$databaseModel['statistics']['unique_indexes'] ?> unique</span>
                    <span class="schema-stat">schema.sql: <?= htmlspecialchars((string)$schemaModified) ?></span>
                </div>

                <div class="schema-workspace-wrap">
                    <div class="schema-canvas" id="schemaCanvas">
                        <svg
                            id="schemaSvg"
                            xmlns="http://www.w3.org/2000/svg"
                            role="img"
                            aria-label="Diagramma entità-relazioni"
                        >
                            <defs>
                                <filter id="cardShadow" x="-20%" y="-20%" width="140%" height="140%">
                                    <feDropShadow dx="0" dy="4" stdDeviation="4" flood-color="#0f172a" flood-opacity=".14"/>
                                </filter>

                                <marker
                                    id="crowMarker"
                                    viewBox="0 0 18 18"
                                    refX="16"
                                    refY="9"
                                    markerWidth="18"
                                    markerHeight="18"
                                    orient="auto"
                                    markerUnits="userSpaceOnUse"
                                >
                                    <path d="M16,9 L2,2 M16,9 L2,9 M16,9 L2,16"
                                          fill="none"
                                          stroke="#55708b"
                                          stroke-width="1.6"
                                          stroke-linecap="round"/>
                                </marker>

                                <marker
                                    id="oneMarker"
                                    viewBox="0 0 16 16"
                                    refX="3"
                                    refY="8"
                                    markerWidth="16"
                                    markerHeight="16"
                                    orient="auto"
                                    markerUnits="userSpaceOnUse"
                                >
                                    <path d="M4,2 L4,14 M8,2 L8,14"
                                          fill="none"
                                          stroke="#55708b"
                                          stroke-width="1.4"/>
                                </marker>
                            </defs>
                            <g id="viewport">
                                <g id="relationLayer"></g>
                                <g id="tableLayer"></g>
                            </g>
                        </svg>
                    </div>

                    <aside class="schema-side">
                        <div class="schema-side-header">
                            <strong id="detailsTitle">Dettagli tabella</strong>
                        </div>
                        <div class="schema-side-body" id="detailsBody">
                            <div class="schema-side-placeholder">
                                <i class="bi bi-hand-index-thumb fs-2 d-block mb-2"></i>
                                Seleziona una tabella per visualizzare campi, chiavi e indici.
                            </div>
                        </div>
                    </aside>
                </div>

                <div class="schema-legend">
                    <span class="schema-legend-item">
                        <span class="legend-dot" style="background:#0f5db8"></span> PK
                    </span>
                    <span class="schema-legend-item">
                        <span class="legend-dot" style="background:#00a6c7"></span> FK
                    </span>
                    <span class="schema-legend-item">
                        <span class="legend-dot" style="background:#7c3aed"></span> UNIQUE
                    </span>
                    <span class="schema-legend-item">
                        <span class="legend-dot" style="background:#d97706"></span> INDEX
                    </span>
                    <span class="schema-legend-item">
                        <span class="legend-dot" style="background:#94a3b8"></span> campo normale
                    </span>
                    <span class="schema-legend-item ms-auto">
                        Rotella: zoom · trascina lo sfondo: sposta
                    </span>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($databaseModel): ?>
<script>
(() => {
    'use strict';

    const model = <?= $modelJson ?>;
    const svg = document.getElementById('schemaSvg');
    const canvas = document.getElementById('schemaCanvas');
    const viewport = document.getElementById('viewport');
    const tableLayer = document.getElementById('tableLayer');
    const relationLayer = document.getElementById('relationLayer');

    if (!svg || !canvas || !viewport || !model) return;

    const NS = 'http://www.w3.org/2000/svg';

    const state = {
        scale: 1,
        x: 30,
        y: 30,
        minScale: 0.22,
        maxScale: 2.8,
        dragging: false,
        startX: 0,
        startY: 0,
        originX: 0,
        originY: 0,
        selectedTable: null,
        tableNodes: new Map(),
        tableBoxes: new Map(),
        relationNodes: []
    };

    const config = {
        boxWidth: 285,
        headerHeight: 44,
        rowHeight: 27,
        footerPadding: 12,
        gapX: 120,
        gapY: 105,
        margin: 70
    };

    function svgEl(name, attrs = {}, text = null) {
        const el = document.createElementNS(NS, name);
        Object.entries(attrs).forEach(([key, value]) => el.setAttribute(key, String(value)));
        if (text !== null) el.textContent = text;
        return el;
    }

    function escapeText(value) {
        return String(value ?? '');
    }

    function calculateLevels() {
        const names = model.tables.map(t => t.name);
        const level = Object.fromEntries(names.map(name => [name, 0]));
        const maxIterations = Math.max(10, names.length * names.length);

        for (let iteration = 0; iteration < maxIterations; iteration++) {
            let changed = false;

            for (const relation of model.relations) {
                if (!(relation.from_table in level) || !(relation.to_table in level)) continue;

                const desired = level[relation.to_table] + 1;
                if (level[relation.from_table] < desired) {
                    level[relation.from_table] = desired;
                    changed = true;
                }
            }

            if (!changed) break;
        }

        return level;
    }

    function buildLayout() {
        const levels = calculateLevels();
        const grouped = new Map();

        model.tables.forEach(table => {
            const l = Math.min(levels[table.name] ?? 0, Math.max(0, model.tables.length - 1));
            if (!grouped.has(l)) grouped.set(l, []);
            grouped.get(l).push(table);
        });

        const sortedLevels = [...grouped.keys()].sort((a, b) => a - b);
        const positions = {};
        let currentY = config.margin;
        let maxWidth = 0;

        for (const l of sortedLevels) {
            const tables = grouped.get(l).sort((a, b) => a.name.localeCompare(b.name));
            const rowWidth = tables.length * config.boxWidth + Math.max(0, tables.length - 1) * config.gapX;
            maxWidth = Math.max(maxWidth, rowWidth);
            const maxHeight = Math.max(...tables.map(t => tableHeight(t)));

            tables.forEach((table, index) => {
                positions[table.name] = {
                    x: config.margin + index * (config.boxWidth + config.gapX),
                    y: currentY,
                    width: config.boxWidth,
                    height: tableHeight(table),
                    level: l
                };
            });

            currentY += maxHeight + config.gapY;
        }

        const overallWidth = maxWidth + config.margin * 2;
        const overallHeight = currentY + config.margin;

        for (const l of sortedLevels) {
            const tables = grouped.get(l);
            const rowWidth = tables.length * config.boxWidth + Math.max(0, tables.length - 1) * config.gapX;
            const offset = (overallWidth - rowWidth) / 2 - config.margin;

            tables.forEach(table => {
                positions[table.name].x += offset;
            });
        }

        return { positions, width: overallWidth, height: overallHeight };
    }

    function tableHeight(table) {
        return config.headerHeight + table.columns.length * config.rowHeight + config.footerPadding;
    }

    function columnStyle(column) {
        if (column.primary) return { fill: '#0f5db8', badge: 'PK' };
        if (column.foreign) return { fill: '#00a6c7', badge: 'FK' };
        if (column.unique) return { fill: '#7c3aed', badge: 'UQ' };
        if (column.indexed) return { fill: '#d97706', badge: 'IX' };
        return { fill: '#64748b', badge: '' };
    }

    function renderTable(table, box) {
        const group = svgEl('g', {
            class: 'schema-table',
            'data-table': table.name,
            tabindex: '0',
            role: 'button',
            'aria-label': `Tabella ${table.name}`
        });

        group.style.cursor = 'pointer';

        const outer = svgEl('rect', {
            x: box.x,
            y: box.y,
            width: box.width,
            height: box.height,
            rx: 9,
            fill: '#ffffff',
            stroke: '#1768b5',
            'stroke-width': 1.6,
            filter: 'url(#cardShadow)'
        });

        const header = svgEl('path', {
            d: [
                `M ${box.x + 9} ${box.y}`,
                `H ${box.x + box.width - 9}`,
                `Q ${box.x + box.width} ${box.y} ${box.x + box.width} ${box.y + 9}`,
                `V ${box.y + config.headerHeight}`,
                `H ${box.x}`,
                `V ${box.y + 9}`,
                `Q ${box.x} ${box.y} ${box.x + 9} ${box.y}`,
                'Z'
            ].join(' '),
            fill: '#0f5db8'
        });

        const title = svgEl('text', {
            x: box.x + box.width / 2,
            y: box.y + 28,
            'text-anchor': 'middle',
            'font-family': 'Arial, sans-serif',
            'font-size': 15,
            'font-weight': 700,
            fill: '#ffffff'
        }, table.name.toUpperCase());

        group.append(outer, header, title);

        table.columns.forEach((column, index) => {
            const rowY = box.y + config.headerHeight + index * config.rowHeight;
            const style = columnStyle(column);

            if (index > 0) {
                group.appendChild(svgEl('line', {
                    x1: box.x,
                    y1: rowY,
                    x2: box.x + box.width,
                    y2: rowY,
                    stroke: '#edf2f7',
                    'stroke-width': 1
                }));
            }

            if (style.badge) {
                group.appendChild(svgEl('rect', {
                    x: box.x + 10,
                    y: rowY + 7,
                    width: 28,
                    height: 14,
                    rx: 7,
                    fill: style.fill
                }));

                group.appendChild(svgEl('text', {
                    x: box.x + 24,
                    y: rowY + 17.5,
                    'text-anchor': 'middle',
                    'font-family': 'Arial, sans-serif',
                    'font-size': 8.5,
                    'font-weight': 700,
                    fill: '#ffffff'
                }, style.badge));
            } else {
                group.appendChild(svgEl('circle', {
                    cx: box.x + 24,
                    cy: rowY + 14,
                    r: 4,
                    fill: style.fill
                }));
            }

            group.appendChild(svgEl('text', {
                x: box.x + 46,
                y: rowY + 18,
                'font-family': 'Arial, sans-serif',
                'font-size': 12.5,
                'font-weight': column.primary || column.foreign ? 700 : 500,
                fill: column.primary ? '#0f5db8' : (column.foreign ? '#007f99' : '#26384a')
            }, column.name));

            const flags = [];
            if (!column.nullable) flags.push('NN');
            if (column.auto_increment) flags.push('AI');

            const rightText = `${column.type}${flags.length ? ' · ' + flags.join(' ') : ''}`;

            group.appendChild(svgEl('text', {
                x: box.x + box.width - 10,
                y: rowY + 18,
                'text-anchor': 'end',
                'font-family': 'Arial, sans-serif',
                'font-size': 11,
                fill: '#64748b'
            }, rightText));
        });

        group.addEventListener('click', (event) => {
            event.stopPropagation();
            selectTable(table.name);
        });

        group.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectTable(table.name);
            }
        });

        tableLayer.appendChild(group);
        state.tableNodes.set(table.name, group);
        state.tableBoxes.set(table.name, box);
    }

    function relationPath(fromBox, toBox, index) {
        const fromCenterX = fromBox.x + fromBox.width / 2;
        const toCenterX = toBox.x + toBox.width / 2;
        const fromCenterY = fromBox.y + fromBox.height / 2;
        const toCenterY = toBox.y + toBox.height / 2;

        let startX, startY, endX, endY;

        if (fromBox.y > toBox.y + toBox.height) {
            startX = fromCenterX;
            startY = fromBox.y;
            endX = toCenterX;
            endY = toBox.y + toBox.height;
        } else if (toBox.y > fromBox.y + fromBox.height) {
            startX = fromCenterX;
            startY = fromBox.y + fromBox.height;
            endX = toCenterX;
            endY = toBox.y;
        } else if (fromBox.x > toBox.x) {
            startX = fromBox.x;
            startY = fromCenterY;
            endX = toBox.x + toBox.width;
            endY = toCenterY;
        } else {
            startX = fromBox.x + fromBox.width;
            startY = fromCenterY;
            endX = toBox.x;
            endY = toCenterY;
        }

        const horizontalFirst = Math.abs(endX - startX) > Math.abs(endY - startY);
        const offset = (index % 4) * 10;

        if (horizontalFirst) {
            const midX = (startX + endX) / 2 + offset;
            return `M ${startX} ${startY} H ${midX} V ${endY} H ${endX}`;
        }

        const midY = (startY + endY) / 2 + offset;
        return `M ${startX} ${startY} V ${midY} H ${endX} V ${endY}`;
    }

    function renderRelations() {
        state.relationNodes = [];

        model.relations.forEach((relation, index) => {
            const fromBox = state.tableBoxes.get(relation.from_table);
            const toBox = state.tableBoxes.get(relation.to_table);

            if (!fromBox || !toBox) return;

            const path = svgEl('path', {
                d: relationPath(fromBox, toBox, index),
                fill: 'none',
                stroke: '#55708b',
                'stroke-width': 1.6,
                'stroke-linejoin': 'round',
                'stroke-linecap': 'round',
                'marker-start': 'url(#crowMarker)',
                'marker-end': 'url(#oneMarker)',
                class: 'schema-relation',
                'data-from': relation.from_table,
                'data-to': relation.to_table
            });

            path.style.pointerEvents = 'stroke';
            path.style.cursor = 'pointer';

            const title = svgEl('title', {}, `${relation.from_table}.${relation.from_columns.join(', ')} → ${relation.to_table}.${relation.to_columns.join(', ')}`);
            path.appendChild(title);

            relationLayer.appendChild(path);
            state.relationNodes.push(path);
        });
    }

    function render() {
        tableLayer.innerHTML = '';
        relationLayer.innerHTML = '';
        state.tableNodes.clear();
        state.tableBoxes.clear();

        const layout = buildLayout();

        model.tables.forEach(table => {
            renderTable(table, layout.positions[table.name]);
        });

        renderRelations();
        fitDiagram();
    }

    function applyTransform() {
        viewport.setAttribute('transform', `translate(${state.x} ${state.y}) scale(${state.scale})`);
    }

    function getDiagramBounds() {
        const bounds = tableLayer.getBBox();
        return {
            x: bounds.x,
            y: bounds.y,
            width: Math.max(bounds.width, 1),
            height: Math.max(bounds.height, 1)
        };
    }

    function fitDiagram() {
        const bounds = getDiagramBounds();
        const canvasRect = canvas.getBoundingClientRect();
        const padding = 45;

        const scaleX = (canvasRect.width - padding * 2) / bounds.width;
        const scaleY = (canvasRect.height - padding * 2) / bounds.height;

        state.scale = Math.max(state.minScale, Math.min(1.15, scaleX, scaleY));
        state.x = (canvasRect.width - bounds.width * state.scale) / 2 - bounds.x * state.scale;
        state.y = (canvasRect.height - bounds.height * state.scale) / 2 - bounds.y * state.scale;

        applyTransform();
    }

    function zoomAt(factor, clientX = null, clientY = null) {
        const rect = svg.getBoundingClientRect();
        const px = clientX === null ? rect.width / 2 : clientX - rect.left;
        const py = clientY === null ? rect.height / 2 : clientY - rect.top;

        const oldScale = state.scale;
        const newScale = Math.max(state.minScale, Math.min(state.maxScale, oldScale * factor));

        const worldX = (px - state.x) / oldScale;
        const worldY = (py - state.y) / oldScale;

        state.scale = newScale;
        state.x = px - worldX * newScale;
        state.y = py - worldY * newScale;

        applyTransform();
    }

    function selectTable(tableName) {
        state.selectedTable = tableName;

        state.tableNodes.forEach((node, name) => {
            const rect = node.querySelector('rect');
            node.style.opacity = '1';
            if (rect) {
                rect.setAttribute('stroke-width', name === tableName ? '3.4' : '1.6');
                rect.setAttribute('stroke', name === tableName ? '#ff8a00' : '#1768b5');
            }
        });

        const connected = new Set([tableName]);

        state.relationNodes.forEach(path => {
            const from = path.dataset.from;
            const to = path.dataset.to;
            const active = from === tableName || to === tableName;

            if (active) {
                connected.add(from);
                connected.add(to);
                path.setAttribute('stroke', '#ff8a00');
                path.setAttribute('stroke-width', '3');
                path.style.opacity = '1';
            } else {
                path.setAttribute('stroke', '#b5c3d1');
                path.setAttribute('stroke-width', '1.2');
                path.style.opacity = '.35';
            }
        });

        state.tableNodes.forEach((node, name) => {
            if (!connected.has(name)) {
                node.style.opacity = '.28';
            }
        });

        showDetails(tableName);
    }

    function clearSelection() {
        state.selectedTable = null;

        state.tableNodes.forEach(node => {
            node.style.opacity = '1';
            const rect = node.querySelector('rect');
            if (rect) {
                rect.setAttribute('stroke-width', '1.6');
                rect.setAttribute('stroke', '#1768b5');
            }
        });

        state.relationNodes.forEach(path => {
            path.setAttribute('stroke', '#55708b');
            path.setAttribute('stroke-width', '1.6');
            path.style.opacity = '1';
        });
    }

    function showDetails(tableName) {
        const table = model.tables.find(t => t.name === tableName);
        if (!table) return;

        document.getElementById('detailsTitle').textContent = table.name.toUpperCase();

        const columnsHtml = table.columns.map(column => {
            const tags = [];
            if (column.primary) tags.push('PK');
            if (column.foreign) tags.push('FK');
            if (column.unique) tags.push('UNIQUE');
            if (column.indexed) tags.push('INDEX');
            if (!column.nullable) tags.push('NOT NULL');
            if (column.auto_increment) tags.push('AUTO_INCREMENT');

            const targets = (column.fk_targets || []).map(target =>
                `<div class="mt-1 text-muted">→ ${htmlEscape(target.table)}.${htmlEscape(target.column)}</div>`
            ).join('');

            return `
                <div class="schema-field-card">
                    <strong>${htmlEscape(column.name)}</strong>
                    <div class="text-muted">${htmlEscape(column.type)}</div>
                    ${tags.map(tag => `<span class="schema-tag">${htmlEscape(tag)}</span>`).join('')}
                    ${column.default !== null ? `<div class="mt-1 text-muted">Default: ${htmlEscape(column.default)}</div>` : ''}
                    ${targets}
                </div>
            `;
        }).join('');

        const indexesHtml = table.indexes.length
            ? table.indexes.map(index => `
                <div class="schema-field-card">
                    <strong>${htmlEscape(index.name)}</strong>
                    <div class="text-muted">${htmlEscape(index.type)} · ${htmlEscape(index.columns.join(', '))}</div>
                </div>
            `).join('')
            : '<div class="text-muted small">Nessun indice aggiuntivo.</div>';

        const foreignHtml = table.foreign_keys.length
            ? table.foreign_keys.map(fk => `
                <div class="schema-field-card">
                    <strong>${htmlEscape(fk.name)}</strong>
                    <div>Campo: ${htmlEscape(fk.columns.join(', '))}</div>
                    <div>Riferimento: ${htmlEscape(fk.ref_table)}.${htmlEscape(fk.ref_columns.join(', '))}</div>
                    <div class="text-muted mt-1">DELETE ${htmlEscape(fk.on_delete)} · UPDATE ${htmlEscape(fk.on_update)}</div>
                </div>
            `).join('')
            : '<div class="text-muted small">Nessuna foreign key in uscita.</div>';

        document.getElementById('detailsBody').innerHTML = `
            <div class="schema-info-section">
                <h6>Campi</h6>
                ${columnsHtml}
            </div>
            <div class="schema-info-section">
                <h6>Indici</h6>
                ${indexesHtml}
            </div>
            <div class="schema-info-section">
                <h6>Foreign key</h6>
                ${foreignHtml}
            </div>
        `;
    }

    function htmlEscape(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function centerOnTable(tableName) {
        const box = state.tableBoxes.get(tableName);
        if (!box) return;

        const rect = canvas.getBoundingClientRect();
        state.x = rect.width / 2 - (box.x + box.width / 2) * state.scale;
        state.y = rect.height / 2 - (box.y + box.height / 2) * state.scale;

        applyTransform();
        selectTable(tableName);
    }

    function serializeSvg() {
        const clone = svg.cloneNode(true);
        clone.setAttribute('xmlns', NS);

        const bounds = getDiagramBounds();
        const padding = 40;

        clone.setAttribute(
            'viewBox',
            `${bounds.x - padding} ${bounds.y - padding} ${bounds.width + padding * 2} ${bounds.height + padding * 2}`
        );
        clone.setAttribute('width', bounds.width + padding * 2);
        clone.setAttribute('height', bounds.height + padding * 2);

        const cloneViewport = clone.querySelector('#viewport');
        if (cloneViewport) cloneViewport.removeAttribute('transform');

        const style = document.createElementNS(NS, 'style');
        style.textContent = `
            text { font-family: Arial, sans-serif; }
            .schema-table { opacity: 1 !important; }
            .schema-relation { opacity: 1 !important; }
        `;
        clone.insertBefore(style, clone.firstChild);

        return new XMLSerializer().serializeToString(clone);
    }

    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function exportSvg() {
        const content = serializeSvg();
        downloadBlob(
            new Blob([content], { type: 'image/svg+xml;charset=utf-8' }),
            'diagramma_schema.svg'
        );
    }

    function exportPng() {
        const content = serializeSvg();
        const blob = new Blob([content], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const image = new Image();

        image.onload = () => {
            const maxDimension = 5000;
            let width = image.width;
            let height = image.height;
            const ratio = Math.min(1, maxDimension / Math.max(width, height));

            width = Math.max(1, Math.round(width * ratio));
            height = Math.max(1, Math.round(height * ratio));

            const canvasExport = document.createElement('canvas');
            canvasExport.width = width * 2;
            canvasExport.height = height * 2;

            const ctx = canvasExport.getContext('2d');
            ctx.scale(2, 2);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(image, 0, 0, width, height);

            canvasExport.toBlob(pngBlob => {
                if (pngBlob) downloadBlob(pngBlob, 'diagramma_schema.png');
                URL.revokeObjectURL(url);
            }, 'image/png');
        };

        image.onerror = () => {
            URL.revokeObjectURL(url);
            alert('Impossibile esportare il diagramma PNG.');
        };

        image.src = url;
    }

    svg.addEventListener('wheel', event => {
        event.preventDefault();
        zoomAt(event.deltaY < 0 ? 1.12 : 0.89, event.clientX, event.clientY);
    }, { passive: false });

    canvas.addEventListener('mousedown', event => {
        if (event.button !== 0) return;
        if (event.target.closest('.schema-table')) return;

        state.dragging = true;
        state.startX = event.clientX;
        state.startY = event.clientY;
        state.originX = state.x;
        state.originY = state.y;
        canvas.classList.add('is-panning');
    });

    window.addEventListener('mousemove', event => {
        if (!state.dragging) return;

        state.x = state.originX + (event.clientX - state.startX);
        state.y = state.originY + (event.clientY - state.startY);
        applyTransform();
    });

    window.addEventListener('mouseup', () => {
        state.dragging = false;
        canvas.classList.remove('is-panning');
    });

    svg.addEventListener('click', event => {
        if (!event.target.closest('.schema-table')) {
            clearSelection();
        }
    });

    document.getElementById('fitBtn')?.addEventListener('click', fitDiagram);
    document.getElementById('zoomInBtn')?.addEventListener('click', () => zoomAt(1.2));
    document.getElementById('zoomOutBtn')?.addEventListener('click', () => zoomAt(0.83));
    document.getElementById('exportSvgBtn')?.addEventListener('click', exportSvg);
    document.getElementById('exportPngBtn')?.addEventListener('click', exportPng);

    document.getElementById('schemaSearch')?.addEventListener('input', event => {
        const term = event.target.value.trim().toLowerCase();

        if (!term) {
            clearSelection();
            return;
        }

        const match = model.tables.find(table => table.name.toLowerCase().includes(term));

        if (match) {
            centerOnTable(match.name);
        }
    });

    window.addEventListener('resize', () => {
        if (!state.dragging) fitDiagram();
    });

    render();
})();
</script>
<?php endif; ?>
