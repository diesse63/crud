<?php
require_once __DIR__ . '/genera_pagina_visualizzazione_modali.php';
/**
 * genera_pagina_visualizzazione.php
 *
 * Pagina interna dell'applicazione CRUD.
 * - Verifica schema.sql nella cartella del progetto attivo.
 * - Carica tabelle, campi, indici e foreign key dal DB della CRUD.
 * - Consente la selezione e l'ordinamento dei campi.
 * - Salva la configurazione nelle tabelle pagine_visualizzazione*.
 * - Genera una singola pagina PHP nella cartella /pages del progetto.
 *
 * INTEGRAZIONE:
 * - Questo file va copiato in /membri/dasi/pages/pages/.
 * - Viene incluso da index.php?page=genera_pagina_visualizzazione.
 * - Bootstrap 5 e Bootstrap Icons sono caricati dal layout index.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/versioning.php';


$progettoId   = isset($_SESSION['progetto_id']) ? (int) $_SESSION['progetto_id'] : 0;
$progettoNome = trim((string) ($_SESSION['progetto_nome'] ?? ''));
$initialConfigurationId = isset($_GET['configuration_id']) ? max(0, (int) $_GET['configuration_id']) : 0;

function jsonResponse(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function sanitizeFolderName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = preg_replace('/[\s\.\,\!\?]+/u', '_', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $name = preg_replace('/[^a-z0-9_]/', '', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_') ?: 'progetto_senza_nome';
}

function sanitizePhpFileName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\.php$/i', '', $name);
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/[\s\.\,\!\?\/\\\\]+/u', '_', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $name = preg_replace('/[^a-z0-9_]/', '', $name);
    $name = preg_replace('/_+/', '_', $name);
    return (trim($name, '_') ?: 'pagina_visualizzazione') . '.php';
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function pathAllowedByOpenBaseDir(string $path): bool
{
    $openBaseDir = (string) ini_get('open_basedir');
    if ($openBaseDir === '') {
        return true;
    }

    $normalizedPath = str_replace('\\', '/', $path);
    foreach (explode(PATH_SEPARATOR, $openBaseDir) as $baseDir) {
        $baseDir = trim($baseDir);
        if ($baseDir === '') {
            continue;
        }

        $normalizedBase = rtrim(str_replace('\\', '/', $baseDir), '/');
        if ($normalizedPath === $normalizedBase || str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return true;
        }
    }

    return false;
}

function safeIsFile(string $path): bool
{
    return pathAllowedByOpenBaseDir($path) && is_file($path);
}

function safeIsDir(string $path): bool
{
    return pathAllowedByOpenBaseDir($path) && is_dir($path);
}

function projectPaths(string $projectName): array
{
    $folder = sanitizeFolderName($projectName);

    /*
     * La pagina può essere collocata:
     * - in /membri/dasi/pages/
     * - oppure in /membri/dasi/pages/pages/
     *
     * Per evitare dipendenze dalla posizione del file, vengono verificati
     * più percorsi possibili. Ha priorità quello che contiene schema.sql.
     */
    $candidates = [];

    // File collocato direttamente in /membri/dasi/pages/
    $candidates[] = __DIR__
        . DIRECTORY_SEPARATOR . 'sito'
        . DIRECTORY_SEPARATOR . $folder;

    // File collocato in /membri/dasi/pages/pages/
    $candidates[] = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'sito'
        . DIRECTORY_SEPARATOR . $folder;

    // Percorso ricavato dalla document root del sito.
    $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($documentRoot !== '') {
        $candidates[] = $documentRoot
            . DIRECTORY_SEPARATOR . 'pages'
            . DIRECTORY_SEPARATOR . 'sito'
            . DIRECTORY_SEPARATOR . $folder;

        $candidates[] = $documentRoot
            . DIRECTORY_SEPARATOR . 'sito'
            . DIRECTORY_SEPARATOR . $folder;
    }

    // Percorso assoluto Altervista usato dall'applicazione.
    $candidates[] = DIRECTORY_SEPARATOR . 'membri'
        . DIRECTORY_SEPARATOR . 'dasi'
        . DIRECTORY_SEPARATOR . 'pages'
        . DIRECTORY_SEPARATOR . 'sito'
        . DIRECTORY_SEPARATOR . $folder;

    $candidates = array_values(array_unique($candidates));

    $root = $candidates[0];

    // Prima scelta: cartella che contiene davvero schema.sql.
    foreach ($candidates as $candidate) {
        if (safeIsFile($candidate . DIRECTORY_SEPARATOR . 'schema.sql')) {
            $root = $candidate;
            break;
        }
    }

    // Seconda scelta: cartella progetto esistente.
    if (!safeIsDir($root)) {
        foreach ($candidates as $candidate) {
            if (safeIsDir($candidate)) {
                $root = $candidate;
                break;
            }
        }
    }

    return [
        'folder' => $folder,
        'root' => $root,
        'schema' => $root . DIRECTORY_SEPARATOR . 'schema.sql',
        'pages' => $root . DIRECTORY_SEPARATOR . 'pages',
        'candidates' => $candidates,
    ];
}

function requireProject(int $projectId, string $projectName): void
{
    if ($projectId <= 0 || $projectName === '') {
        jsonResponse(['ok' => false, 'message' => 'Nessun progetto attivo selezionato.'], 400);
    }
}

function loadTable(PDO|Database $db, int $projectId, int $tableId): ?array
{
    return $db->fetch(
        'SELECT id, nome, descrizione
         FROM tabelle
         WHERE id = ? AND IDprogetto = ?',
        [$tableId, $projectId]
    ) ?: null;
}

function loadFields(Database $db, int $tableId): array
{
    $fields = $db->fetchAll(
        "SELECT
            c.id,
            c.IDtabella,
            c.nome,
            c.nome_descrittivo,
            c.tipo,
            c.lunghezza,
            c.default_value,
            c.indice_tipo,
            c.nullable,
            c.auto_increment,
            c.modifica,
            c.ordine,
            EXISTS (
                SELECT 1
                FROM foreign_keys_campi fkc
                WHERE fkc.IDcampo_locale = c.id
            ) AS is_fk,
            GROUP_CONCAT(
                DISTINCT CONCAT(i.nome, ':', i.tipo)
                ORDER BY i.nome
                SEPARATOR ', '
            ) AS indici
         FROM campi c
         LEFT JOIN indici_campi ic ON ic.IDcampo = c.id
         LEFT JOIN indici i ON i.id = ic.IDindice
         WHERE c.IDtabella = ?
         GROUP BY
            c.id, c.IDtabella, c.nome, c.nome_descrittivo, c.tipo, c.lunghezza,
            c.default_value, c.indice_tipo, c.nullable,
            c.auto_increment, c.modifica, c.ordine
         ORDER BY COALESCE(c.ordine, 999999), c.id",
        [$tableId]
    );

    foreach ($fields as &$field) {
        $indexText = strtoupper((string) ($field['indici'] ?? ''));
        $primaryIndexFound = (bool) $db->fetchColumn(
            "SELECT COUNT(*)
             FROM indici i
             JOIN indici_campi ic ON ic.IDindice = i.id
             WHERE ic.IDcampo = ?
               AND (
                    UPPER(COALESCE(i.nome, '')) = 'PRIMARY'
                    OR UPPER(COALESCE(i.tipo, '')) = 'PRIMARY'
               )",
            [(int) $field['id']]
        );

        /*
         * Nel modello DASI la PK di sistema può essere rappresentata
         * direttamente dal campo AUTO_INCREMENT senza una riga PRIMARY
         * nella tabella indici. Lo schema SQL generato usa comunque quel
         * campo come PRIMARY KEY.
         */
        $field['is_pk'] =
            $primaryIndexFound
            || !empty($field['auto_increment']);
        $field['is_unique'] =
            strtoupper((string) $field['indice_tipo']) === 'UNICO'
            || str_contains($indexText, ':UNIQUE');
        $field['is_index'] =
            strtoupper((string) $field['indice_tipo']) === 'INDICE'
            || !empty($field['indici']);
        $field['is_fk'] = (bool) $field['is_fk'];
        $field['nullable'] = (bool) $field['nullable'];
        $field['auto_increment'] = (bool) $field['auto_increment'];
    }
    unset($field);

    return $fields;
}

/**
 * Restituisce tutte le FK direttamente collegate alla tabella principale,
 * sia in uscita sia in entrata.
 */
function loadRelations(Database $db, int $projectId, int $mainTableId): array
{
    $rows = $db->fetchAll(
        "SELECT
            fk.id AS fk_id,
            fk.nome AS fk_nome,
            fk.IDtabella AS local_table_id,
            tl.nome AS local_table_name,
            cl.nome AS local_field_name,
            cl.nome_descrittivo AS local_field_descrittivo,
            cr.IDtabella AS referenced_table_id,
            tr.nome AS referenced_table_name,
            GROUP_CONCAT(
                CONCAT(
                    cl.id, ':', cl.nome,
                    '=',
                    cr.id, ':', cr.nome
                )
                ORDER BY fkc.ordine
                SEPARATOR '||'
            ) AS columns_map,
            fk.on_delete,
            fk.on_update
         FROM foreign_keys fk
         JOIN tabelle tl ON tl.id = fk.IDtabella
         JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id
         JOIN campi cl ON cl.id = fkc.IDcampo_locale
         JOIN campi cr ON cr.id = fkc.IDcampo_referenziato
         JOIN tabelle tr ON tr.id = cr.IDtabella
         WHERE tl.IDprogetto = ?
           AND tr.IDprogetto = ?
           AND (fk.IDtabella = ? OR cr.IDtabella = ?)
         GROUP BY
            fk.id, fk.nome, fk.IDtabella, tl.nome, cl.nome, cl.nome_descrittivo,
            cr.IDtabella, tr.nome, fk.on_delete, fk.on_update
         ORDER BY
            CASE WHEN fk.IDtabella = ? THEN 0 ELSE 1 END,
            tr.nome, tl.nome, fk.nome",
        [$projectId, $projectId, $mainTableId, $mainTableId, $mainTableId]
    );

    $result = [];
    foreach ($rows as $row) {
        $outgoing = (int) $row['local_table_id'] === $mainTableId;
        $secondaryId = $outgoing
            ? (int) $row['referenced_table_id']
            : (int) $row['local_table_id'];
        $secondaryName = $outgoing
            ? $row['referenced_table_name']
            : $row['local_table_name'];

        $pairs = [];
        foreach (explode('||', (string) $row['columns_map']) as $pair) {
            [$localRaw, $refRaw] = array_pad(explode('=', $pair, 2), 2, '');
            [$localId, $localName] = array_pad(explode(':', $localRaw, 2), 2, '');
            [$refId, $refName] = array_pad(explode(':', $refRaw, 2), 2, '');

            if ($localId === '' || $localName === '' || $refId === '' || $refName === '') {
                continue;
            }

            if ($outgoing) {
                $mainFieldId = (int) $localId;
                $mainFieldName = $localName;
                $linkedFieldId = (int) $refId;
                $linkedFieldName = $refName;
            } else {
                $mainFieldId = (int) $refId;
                $mainFieldName = $refName;
                $linkedFieldId = (int) $localId;
                $linkedFieldName = $localName;
            }

            $pairs[] = [
                'local' => $localName,
                'referenced' => $refName,
                'local_field_id' => (int) $localId,
                'local_field_name' => $localName,
                'referenced_field_id' => (int) $refId,
                'referenced_field_name' => $refName,
                'main_field_id' => $mainFieldId,
                'main_field_name' => $mainFieldName,
                'linked_field_id' => $linkedFieldId,
                'linked_field_name' => $linkedFieldName,
            ];
        }

        $result[] = [
            'fk_id' => (int) $row['fk_id'],
            'fk_nome' => $row['fk_nome'],
            'direction' => $outgoing ? 'OUT' : 'IN',
            'main_table_id' => $mainTableId,
            'secondary_table_id' => $secondaryId,
            'secondary_table_name' => $secondaryName,
            'local_table_id' => (int) $row['local_table_id'],
            'local_table_name' => $row['local_table_name'],
            'local_field_name' => $row['local_field_name'],
            'local_field_descrittivo' => $row['local_field_descrittivo'],
            'referenced_table_id' => (int) $row['referenced_table_id'],
            'referenced_table_name' => $row['referenced_table_name'],
            'pairs' => $pairs,
            'on_delete' => $row['on_delete'],
            'on_update' => $row['on_update'],
        ];
    }

    return $result;
}

function buildSqlPreview(
    Database $db,
    int $projectId,
    int $mainTableId,
    array $selectedTables,
    array $selectedFields
): array {
    $mainTable = loadTable($db, $projectId, $mainTableId);
    if (!$mainTable) {
        throw new RuntimeException('Tabella principale non valida.');
    }

    $tableMap = [
        $mainTableId => [
            'id' => $mainTableId,
            'name' => $mainTable['nome'],
            'alias' => 't0',
            'type' => 'PRINCIPALE',
            'fk_id' => null,
            'join_type' => null,
        ]
    ];

    $relations = loadRelations($db, $projectId, $mainTableId);
    $relationsByFk = [];
    foreach ($relations as $relation) {
        $relationsByFk[(int) $relation['fk_id']] = $relation;
    }

    $buildTableKey = static function (int $tableId, int $fkId = 0): string {
        return $tableId . ':' . max(0, $fkId);
    };

    $aliasIndex = 1;
    $joins = [];
    foreach ($selectedTables as $selectedTable) {
        $secondaryId = (int) ($selectedTable['table_id'] ?? 0);
        $fkId = (int) ($selectedTable['fk_id'] ?? 0);
        $joinType = strtoupper((string) ($selectedTable['join_type'] ?? 'LEFT'));
        if (!in_array($joinType, ['LEFT', 'INNER'], true)) {
            $joinType = 'LEFT';
        }

        if (!$secondaryId || !$fkId || !isset($relationsByFk[$fkId])) {
            continue;
        }

        $relation = $relationsByFk[$fkId];
        if ((int) $relation['secondary_table_id'] !== $secondaryId) {
            continue;
        }

        $alias = 't' . $aliasIndex++;
        $tableKey = $secondaryId . ':' . $fkId;
        $tableMap[$tableKey] = [
            'id' => $secondaryId,
            'name' => $relation['secondary_table_name'],
            'alias' => $alias,
            'type' => 'SECONDARIA',
            'fk_id' => $fkId,
            'table_key' => $tableKey,
            'join_type' => $joinType,
        ];

        $conditions = [];
        foreach ($relation['pairs'] as $pair) {
            $localFieldName = trim((string) (
                $pair['local']
                ?? $pair['local_field_name']
                ?? ''
            ));
            $referencedFieldName = trim((string) (
                $pair['referenced']
                ?? $pair['referenced_field_name']
                ?? ''
            ));

            if ($localFieldName === '' || $referencedFieldName === '') {
                throw new RuntimeException(
                    'Definizione incompleta della foreign key '
                    . ($relation['fk_nome'] ?? $fkId)
                    . ': nome campo locale o referenziato mancante.'
                );
            }

            if ($relation['direction'] === 'OUT') {
                $conditions[] =
                    't0.' . quoteIdentifier($localFieldName) .
                    ' = ' . $alias . '.' . quoteIdentifier($referencedFieldName);
            } else {
                $conditions[] =
                    $alias . '.' . quoteIdentifier($localFieldName) .
                    ' = t0.' . quoteIdentifier($referencedFieldName);
            }
        }

        if ($conditions) {
            $joins[] =
                $joinType . ' JOIN ' .
                quoteIdentifier($relation['secondary_table_name']) . ' ' . $alias .
                ' ON ' . implode(' AND ', $conditions);
        }
    }

    $fieldIds = [];
    foreach ($selectedFields as $field) {
        $fieldIds[] = (int) ($field['field_id'] ?? 0);
    }
    $fieldIds = array_values(array_unique(array_filter($fieldIds)));

    if (!$fieldIds) {
        throw new RuntimeException('Selezionare almeno un campo.');
    }

    $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
    $rows = $db->fetchAll(
        "SELECT c.id, c.IDtabella, c.nome, t.nome AS tabella_nome
         FROM campi c
         JOIN tabelle t ON t.id = c.IDtabella
         WHERE c.id IN ($placeholders)
           AND t.IDprogetto = ?",
        [...$fieldIds, $projectId]
    );

    $fieldMap = [];
    foreach ($rows as $row) {
        $fieldMap[(int) $row['id']] = $row;
    }

    $select = [];
    $normalizedFields = [];
    foreach ($selectedFields as $position => $selectedField) {
        $fieldId = (int) ($selectedField['field_id'] ?? 0);
        if (!isset($fieldMap[$fieldId])) {
            continue;
        }

        $field = $fieldMap[$fieldId];
        $tableId = (int) $field['IDtabella'];
        if (!isset($tableMap[$tableId])) {
            continue;
        }

        $sourceFkId = max(0, (int) ($selectedField['source_fk_id'] ?? 0));
        $tableKey = $buildTableKey($tableId, $tableId === $mainTableId ? 0 : $sourceFkId);
        if (!isset($tableMap[$tableKey])) {
            if ($tableId !== $mainTableId || !isset($tableMap[$tableId])) {
                continue;
            }
            $tableKey = $tableId;
        }

        $sourceAlias = $tableMap[$tableKey]['alias'];
        $outputAlias = 'c' . ($position + 1);
        $select[] =
            $sourceAlias . '.' . quoteIdentifier($field['nome']) .
            ' AS ' . quoteIdentifier($outputAlias);

        $qualified = $tableId === $mainTableId
            ? $field['nome']
            : $field['tabella_nome'] . '.' . $field['nome'];

        $normalizedFields[] = [
            'field_id' => $fieldId,
            'table_id' => $tableId,
            'table_key' => $tableKey,
            'source_fk_id' => $tableId === $mainTableId ? 0 : $sourceFkId,
            'table_name' => $field['tabella_nome'],
            'field_name' => $field['nome'],
            'qualified_name' => $qualified,
            'label' => trim((string) ($selectedField['label'] ?? '')) ?: $qualified,
            'order' => $position + 1,
            'visible_table' => !empty($selectedField['visible_table']),
            'visible_card' => !empty($selectedField['visible_card']),
            'visible_modal' => !empty($selectedField['visible_modal']),
            'searchable' => !empty($selectedField['searchable']),
            'sortable' => !empty($selectedField['sortable']),
            'format' => (($value = trim((string) ($selectedField['format'] ?? ''))) !== '')
                ? $value
                : '',
            'alignment' => normalizeAlignmentCode((string) ($selectedField['alignment'] ?? 'SINISTRA')),
            'width' => trim((string) ($selectedField['width'] ?? '')),
            'bootstrap_col' => in_array((string) ($selectedField['bootstrap_col'] ?? '6'), ['3','4','6','8','12'], true)
                ? (string) $selectedField['bootstrap_col'] : '6',
            'filter_enabled' => !empty($selectedField['filter_enabled']),
            'filter_type' => (string) ($selectedField['filter_type'] ?? 'TESTO'),
            'link_page_id' => (int) ($selectedField['link_page_id'] ?? 0),
            'link_parameter' => trim((string) ($selectedField['link_parameter'] ?? '')),
            'link_value_field' => trim((string) ($selectedField['link_value_field'] ?? '')),
            'base_path' => trim((string) ($selectedField['base_path'] ?? '')),
            'output_alias' => $outputAlias,
        ];
    }

    if (!$select) {
        throw new RuntimeException('I campi selezionati non appartengono alle tabelle ammesse.');
    }

    $sql = "SELECT\n    " . implode(",\n    ", $select) .
        "\nFROM " . quoteIdentifier($mainTable['nome']) . " t0";

    if ($joins) {
        $sql .= "\n" . implode("\n", $joins);
    }

    return [
        'sql' => $sql,
        'main_table' => $mainTable,
        'tables' => array_values($tableMap),
        'fields' => $normalizedFields,
    ];
}


function buildCrudConfiguration(
    Database $db,
    int $projectId,
    int $mainTableId
): array {
    $table = loadTable($db, $projectId, $mainTableId);
    if (!$table) {
        throw new RuntimeException('Tabella principale CRUD non valida.');
    }

    $fields = loadFields($db, $mainTableId);
    $primaryFields = array_values(array_filter(
        $fields,
        fn(array $field): bool => !empty($field['is_pk'])
    ));

    /*
     * Compatibilità con progetti più vecchi: se nessun indice PRIMARY
     * è registrato, un solo campo AUTO_INCREMENT viene considerato PK.
     */
    if (!$primaryFields) {
        $autoIncrementFields = array_values(array_filter(
            $fields,
            fn(array $field): bool => !empty($field['auto_increment'])
        ));

        if (count($autoIncrementFields) === 1) {
            $autoIncrementFields[0]['is_pk'] = true;
            $primaryFields = $autoIncrementFields;
        }
    }

    if (count($primaryFields) !== 1) {
        return [
            'available' => false,
            'reason' => count($primaryFields) === 0
                ? 'La tabella principale non possiede una chiave primaria.'
                : 'Il CRUD automatico richiede una chiave primaria composta da un solo campo.',
            'table_name' => $table['nome'],
            'fields' => [],
        ];
    }

    $primary = $primaryFields[0];

    $fkRows = $db->fetchAll(
        "SELECT
            cl.id AS local_field_id,
            cl.nome AS local_field_name,
            cr.id AS referenced_field_id,
            cr.nome AS referenced_field_name,
            tr.id AS referenced_table_id,
            tr.nome AS referenced_table_name
         FROM foreign_keys fk
         JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id
         JOIN campi cl ON cl.id = fkc.IDcampo_locale
         JOIN campi cr ON cr.id = fkc.IDcampo_referenziato
         JOIN tabelle tr ON tr.id = cr.IDtabella
         WHERE fk.IDtabella = ?
         ORDER BY fk.id, fkc.ordine",
        [$mainTableId]
    );

    $fkByLocalField = [];
    foreach ($fkRows as $fkRow) {
        $description = $db->fetch(
            "SELECT c.id, c.nome, c.tipo
             FROM campi c
             WHERE c.IDtabella = ?
               AND c.id <> ?
               AND LOWER(c.tipo) IN ('varchar','text','char')
             ORDER BY
               CASE
                   WHEN LOWER(c.nome) IN ('descrizione','nome','titolo','denominazione') THEN 0
                   ELSE 1
               END,
               COALESCE(c.ordine, 999999),
               c.id
             LIMIT 1",
            [
                (int) $fkRow['referenced_table_id'],
                (int) $fkRow['referenced_field_id'],
            ]
        );

        if (!$description) {
            $description = $db->fetch(
                "SELECT c.id, c.nome, c.tipo
                 FROM campi c
                 WHERE c.IDtabella = ?
                   AND c.id <> ?
                 ORDER BY COALESCE(c.ordine, 999999), c.id
                 LIMIT 1",
                [
                    (int) $fkRow['referenced_table_id'],
                    (int) $fkRow['referenced_field_id'],
                ]
            );
        }

        $fkByLocalField[(int) $fkRow['local_field_id']] = [
            'referenced_table_id' => (int) $fkRow['referenced_table_id'],
            'referenced_table_name' => $fkRow['referenced_table_name'],
            'referenced_field_id' => (int) $fkRow['referenced_field_id'],
            'referenced_field_name' => $fkRow['referenced_field_name'],
            'description_field_id' => (int) ($description['id'] ?? $fkRow['referenced_field_id']),
            'description_field_name' => $description['nome'] ?? $fkRow['referenced_field_name'],
        ];
    }

    $crudFields = [];
    foreach ($fields as $field) {
        $isPrimary = !empty($field['is_pk']);
        $isAuto = !empty($field['auto_increment']);
        $isAutomaticTimestamp =
            strtolower((string) $field['tipo']) === 'timestamp'
            && !empty($field['modifica']);

        $crudFields[] = [
            'field_id' => (int) $field['id'],
            'field_name' => $field['nome'],
            'field_type' => strtolower((string) $field['tipo']),
            'length' => $field['lunghezza'],
            'nullable' => !empty($field['nullable']),
            'default_value' => $field['default_value'],
            'is_primary' => $isPrimary,
            'auto_increment' => $isAuto,
            'editable' => !$isAuto && !$isAutomaticTimestamp,
            'required' => !$isAuto
                && !$isAutomaticTimestamp
                && empty($field['nullable'])
                && ($field['default_value'] === null || $field['default_value'] === ''),
            'fk' => $fkByLocalField[(int) $field['id']] ?? null,
            'bootstrap_col' => '6',
        ];
    }

    return [
        'available' => true,
        'reason' => '',
        'table_name' => $table['nome'],
        'primary_key' => [
            'field_id' => (int) $primary['id'],
            'field_name' => $primary['nome'],
            'field_type' => strtolower((string) $primary['tipo']),
        ],
        'fields' => $crudFields,
    ];
}

function generatePagePhp(array $configuration): string
{
    $title = var_export($configuration['title'], true);
    $sql = var_export($configuration['sql'], true);
    $type = var_export($configuration['view_type'], true);
    $typeId = (int) ($configuration['type_id'] ?? 0);
    $rowsPerPage = max(1, (int) $configuration['rows_per_page']);
    $searchEnabled = $configuration['search_enabled'] ? 'true' : 'false';
    $sortEnabled = $configuration['sort_enabled'] ? 'true' : 'false';
    $paginationEnabled = $configuration['pagination_enabled'] ? 'true' : 'false';
    $modalEnabled = $configuration['modal_enabled'] ? 'true' : 'false';
    $fieldsExport = var_export($configuration['fields'], true);
    $modalConfigExport = var_export($configuration['modal_config'] ?? null, true);
    $crudConfigExport = var_export($configuration['crud_config'] ?? [], true);
    $crudEnabled = !empty($configuration['crud_enabled']) ? 'true' : 'false';
    $crudAdd = !empty($configuration['crud_add']) ? 'true' : 'false';
    $crudEdit = !empty($configuration['crud_edit']) ? 'true' : 'false';
    $crudDelete = !empty($configuration['crud_delete']) ? 'true' : 'false';
    $generatorLabel = match ((string) ($configuration['view_type'] ?? '')) {
        'SCHEDA_TABELLARE' => 'Scheda Tabellare',
        'MASTER_DETAIL' => 'Master Detail',
        default => 'Scheda Singola',
    };
    $generatorVersion = crudVersionNormalize((string) ($configuration['generator_version'] ?? match ($generatorLabel) {
        'Scheda Tabellare' => '1.10',
        'Master Detail' => '1.8',
        default => '1.37',
    }));
    $generatedPageVersion = crudVersionForceMajor(
        (string) ($configuration['generated_page_version'] ?? CRUD_FIXED_VERSION_MAJOR . '.0'),
        CRUD_FIXED_VERSION_MAJOR,
        0
    );
    $generatedAt = date('Y-m-d H:i:s');

    $singleCardModalPhp = generatedSingleCardModalPhp();
    $tableRowCardModalPhp = generatedTableRowCardModalPhp();

    return <<<PHP
<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : {$generatorLabel}
 * Versione creatore : {$generatorVersion}
 * Versione pagina   : {$generatedPageVersion}
 * Creato il  : {$generatedAt}
 * Modificato il: {$generatedAt}
 *
 * ATTENZIONE:
 * questo file è generato automaticamente; eventuali modifiche
 * manuali possono essere sovrascritte alla successiva generazione.
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db.php';
\$generatedBy = '{$generatorLabel}';
\$generatedVersion = '{$generatorVersion}';
\$generatedPageVersion = '{$generatedPageVersion}';
\$generatedAt = '{$generatedAt}';

/*
 * La pagina può essere:
 * - inclusa dall'index.php generato;
 * - aperta direttamente dalla cartella /pages.
 *
 * db.php può creare già \$db oppure dichiarare soltanto la classe Database.
 * Il controllo evita sia variabili non definite sia doppie connessioni.
 */
try {
    if (!isset(\$db) || !(\$db instanceof Database)) {
        \$db = new Database();
    }
} catch (Throwable \$databaseError) {
    error_log(
        'Errore database pagina CRUD ' . basename(__FILE__) . ': '
        . \$databaseError->getMessage()
    );

    http_response_code(500);

    echo '<div class="alert alert-danger m-3">'
        . '<strong>Errore di connessione al database.</strong><br>'
        . 'Controllare il file <code>db.php</code> e i parametri di connessione.'
        . '</div>';

    return;
}

\$pageTitle = {$title};
\$viewType = {$type};
\$pageTypeId = {$typeId};
\$baseSql = {$sql};
\$fields = {$fieldsExport};
\$rowsPerPage = \$viewType === 'SCHEDA_SINGOLA' ? 1 : {$rowsPerPage};
\$searchEnabled = {$searchEnabled};
\$sortEnabled = {$sortEnabled};
\$paginationEnabled = {$paginationEnabled};
\$modalEnabled = {$modalEnabled};
\$modalConfig = {$modalConfigExport};
\$crudConfig = {$crudConfigExport};
\$crudEnabled = {$crudEnabled} && !empty(\$crudConfig['available']);
\$crudAdd = {$crudAdd};
\$crudEdit = {$crudEdit};
\$crudDelete = {$crudDelete};

\$modalVisibleFields = array_values(array_filter(
    \$fields,
    static fn (array \$field): bool => !empty(\$field['visible_modal'])
));
\$hasLinkedModalDetail =
    is_array(\$modalConfig)
    && !empty(\$modalConfig['fields']);
\$hasModalDetail =
    \$modalEnabled
    && (\$hasLinkedModalDetail || !empty(\$modalVisibleFields));

\$hasExternalNavigation = false;
foreach (\$fields as \$navigationField) {
    if (
        trim((string) (\$navigationField['link_target_file'] ?? '')) !== ''
        && trim((string) (\$navigationField['link_parameter'] ?? '')) !== ''
        && trim((string) (\$navigationField['link_value_alias'] ?? '')) !== ''
    ) {
        \$hasExternalNavigation = true;
        break;
    }
}

if (!isset(\$_SESSION['generated_crud_csrf'])) {
    \$_SESSION['generated_crud_csrf'] = bin2hex(random_bytes(24));
}
\$crudCsrf = \$_SESSION['generated_crud_csrf'];
\$crudMessage = '';
\$crudError = '';
\$crudEditRecord = null;
\$crudDropdowns = [];

function crudQuote(string \$identifier): string
{
    return '`' . str_replace('`', '``', \$identifier) . '`';
}

function crudNormalizeValue(array \$field, mixed \$value): mixed
{
    if (is_string(\$value)) {
        \$value = trim(\$value);
    }

    if (\$value === '' && !empty(\$field['nullable'])) {
        return null;
    }

    \$fieldType = strtolower((string) (\$field['field_type'] ?? \$field['type'] ?? 'text'));
    if (\$value === '') {
        if (in_array(\$fieldType, ['date', 'datetime', 'timestamp', 'time', 'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'float', 'double', 'decimal'], true)) {
            return null;
        }
        return '';
    }

    \$numericTypes = ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'float', 'double', 'decimal'];

    if (in_array(\$fieldType, \$numericTypes, true)) {
        \$normalized = preg_replace('/\s+/', '', (string) \$value);
        if (\$normalized === null) {
            \$normalized = (string) \$value;
        }

        \$commaPos = strrpos(\$normalized, ',');
        \$dotPos = strrpos(\$normalized, '.');

        if (\$commaPos !== false && \$dotPos !== false) {
            if (\$commaPos > \$dotPos) {
                \$normalized = str_replace('.', '', \$normalized);
                \$normalized = str_replace(',', '.', \$normalized);
            } else {
                \$normalized = str_replace(',', '', \$normalized);
            }
        } elseif (\$commaPos !== false) {
            \$normalized = str_replace('.', '', \$normalized);
            \$normalized = str_replace(',', '.', \$normalized);
        }

        if (!is_numeric(\$normalized)) {
            \$normalized = preg_replace('/[^0-9\.\-]/', '', \$normalized);
        }

        return match (\$fieldType) {
            'int', 'tinyint', 'smallint', 'mediumint', 'bigint' => (int) \$normalized,
            default => (float) \$normalized,
        };
    }

    return \$value;
}

function crudRedirectUrl(string \$messageCode): string
{
    \$requestUri = (string) (\$_SERVER['REQUEST_URI'] ?? '');
    \$path = parse_url(\$requestUri, PHP_URL_PATH);

    if (!is_string(\$path) || \$path === '') {
        \$path = basename((string) (\$_SERVER['PHP_SELF'] ?? 'index.php'));
    }

    \$query = \$_GET;
    unset(
        \$query['crud_message'],
        \$query['edit'],
        \$query['record']
    );

    \$query['crud_message'] = \$messageCode;

    return \$path . '?' . http_build_query(\$query);
}

function crudRedirectRecordUrl(string \$messageCode, mixed \$recordValue): string
{
    \$requestUri = (string) (\$_SERVER['REQUEST_URI'] ?? '');
    \$path = parse_url(\$requestUri, PHP_URL_PATH);

    if (!is_string(\$path) || \$path === '') {
        \$path = basename((string) (\$_SERVER['PHP_SELF'] ?? 'index.php'));
    }

    \$query = \$_GET;
    unset(
        \$query['crud_message'],
        \$query['edit']
    );

    \$query['crud_message'] = \$messageCode;
    \$query['record'] = \$recordValue;

    return \$path . '?' . http_build_query(\$query);
}

if (\$crudEnabled) {
    foreach (\$crudConfig['fields'] as \$crudField) {
        if (empty(\$crudField['fk'])) continue;

        \$fk = \$crudField['fk'];
        \$optionSql =
            'SELECT ' . crudQuote((string) \$fk['referenced_field_name']) . ' AS option_value, '
            . crudQuote((string) \$fk['description_field_name']) . ' AS option_label '
            . 'FROM ' . crudQuote((string) \$fk['referenced_table_name'])
            . ' ORDER BY ' . crudQuote((string) \$fk['description_field_name']);

        \$crudDropdowns[\$crudField['field_name']] = \$db->fetchAll(\$optionSql);
    }

    if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['crud_action'])) {
        try {
            if (!hash_equals(\$crudCsrf, (string) (\$_POST['csrf'] ?? ''))) {
                throw new RuntimeException('Sessione scaduta. Ricaricare la pagina.');
            }

            \$action = (string) \$_POST['crud_action'];
            \$tableName = (string) \$crudConfig['table_name'];
            \$pkName = (string) \$crudConfig['primary_key']['field_name'];
            \$posted = is_array(\$_POST['crud'] ?? null) ? \$_POST['crud'] : [];

            if (\$action === 'delete') {
                if (!\$crudDelete) {
                    throw new RuntimeException('Cancellazione non abilitata.');
                }

                \$pkValue = \$_POST['pk_value'] ?? null;
                if (\$pkValue === null || \$pkValue === '') {
                    throw new RuntimeException('Chiave del record non valida.');
                }

                \$db->execute(
                    'DELETE FROM ' . crudQuote(\$tableName)
                    . ' WHERE ' . crudQuote(\$pkName) . ' = ?',
                    [\$pkValue]
                );

                header('Location: ' . crudRedirectUrl('deleted'));
                exit;
            }

            \$columns = [];
            \$values = [];
            foreach (\$crudConfig['fields'] as \$field) {
                if (empty(\$field['editable'])) continue;

                \$fieldName = (string) \$field['field_name'];
                if (!array_key_exists(\$fieldName, \$posted)) {
                    if (!empty(\$field['required'])) {
                        throw new RuntimeException('Compilare il campo ' . \$fieldName . '.');
                    }
                    continue;
                }

                \$value = crudNormalizeValue(\$field, \$posted[\$fieldName]);
                if (!empty(\$field['required']) && (\$value === '' || \$value === null)) {
                    throw new RuntimeException('Compilare il campo ' . \$fieldName . '.');
                }

                \$columns[] = \$fieldName;
                \$values[] = \$value;
            }

            if (\$action === 'insert') {
                if (!\$crudAdd) {
                    throw new RuntimeException('Inserimento non abilitato.');
                }
                if (!\$columns) {
                    throw new RuntimeException('Nessun campo inseribile.');
                }

                \$sqlInsert =
                    'INSERT INTO ' . crudQuote(\$tableName)
                    . ' (' . implode(', ', array_map('crudQuote', \$columns)) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count(\$columns), '?')) . ')';

                \$db->execute(\$sqlInsert, \$values);
                header('Location: ' . crudRedirectRecordUrl('inserted', \$db->lastInsertId()));
                exit;
            }

            if (\$action === 'update') {
                if (!\$crudEdit) {
                    throw new RuntimeException('Modifica non abilitata.');
                }

                \$pkValue = \$_POST['pk_value'] ?? null;
                if (\$pkValue === null || \$pkValue === '') {
                    throw new RuntimeException('Chiave del record non valida.');
                }

                \$sets = array_map(
                    fn(string \$column): string => crudQuote(\$column) . ' = ?',
                    \$columns
                );

                \$db->execute(
                    'UPDATE ' . crudQuote(\$tableName)
                    . ' SET ' . implode(', ', \$sets)
                    . ' WHERE ' . crudQuote(\$pkName) . ' = ?',
                    [...\$values, \$pkValue]
                );

                header('Location: ' . crudRedirectRecordUrl('updated', \$pkValue));
                exit;
            }
        } catch (Throwable \$crudException) {
            \$crudError = \$crudException->getMessage();
        }
    }

    \$editId = \$_GET['edit'] ?? null;
    if (\$crudEdit && \$editId !== null && \$editId !== '') {
        \$crudEditRecord = \$db->fetch(
            'SELECT * FROM ' . crudQuote((string) \$crudConfig['table_name'])
            . ' WHERE ' . crudQuote((string) \$crudConfig['primary_key']['field_name']) . ' = ?',
            [\$editId]
        ) ?: null;
    }

    \$messageCode = (string) (\$_GET['crud_message'] ?? '');
    \$crudMessage = match (\$messageCode) {
        'inserted' => 'Record inserito correttamente.',
        'updated' => 'Record modificato correttamente.',
        'deleted' => 'Record cancellato correttamente.',
        default => '',
    };
}

\$page = max(1, (int) (\$_GET['p'] ?? 1));
\$search = trim((string) (\$_GET['q'] ?? ''));
\$sort = trim((string) (\$_GET['sort'] ?? ''));
\$direction = strtoupper((string) (\$_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
\$advancedFilters = is_array(\$_GET['f'] ?? null) ? \$_GET['f'] : [];
\$navigateRecord = \$_GET['record'] ?? null;

\$visibleFields = array_values(array_filter(
    \$fields,
    fn(array \$field): bool => \$viewType === 'SCHEDA_SINGOLA'
        ? !empty(\$field['visible_card'])
        : !empty(\$field['visible_table'])
));

\$searchableAliases = [];
\$sortableAliases = [];
foreach (\$fields as \$field) {
    if (!empty(\$field['searchable'])) {
        \$searchableAliases[] = \$field['output_alias'];
    }
    if (
        !empty(\$field['sortable'])
        || (
            \$pageTypeId === 2
            && \$sortEnabled
            && !empty(\$field['visible_table'])
        )
    ) {
        \$sortableAliases[\$field['output_alias']] = true;
    }
}

\$wrappedSql = "SELECT * FROM (" . \$baseSql . ") generated_data";
\$where = [];
\$params = [];

if (\$searchEnabled && \$search !== '' && \$searchableAliases) {
    \$parts = [];
    foreach (\$searchableAliases as \$alias) {
        \$parts[] = "CAST(`" . str_replace('`', '``', \$alias) . "` AS CHAR) LIKE ?";
        \$params[] = '%' . \$search . '%';
    }
    \$where[] = '(' . implode(' OR ', \$parts) . ')';
}

foreach (\$fields as \$field) {
    if (empty(\$field['filter_enabled'])) continue;

    \$alias = \$field['output_alias'];
    \$type = \$field['filter_type'] ?? 'TESTO';
    \$filter = \$advancedFilters[\$alias] ?? '';

    if (in_array(\$type, ['INTERVALLO_NUMERO', 'INTERVALLO_DATA'], true)) {
        \$from = trim((string) (\$filter['from'] ?? ''));
        \$to = trim((string) (\$filter['to'] ?? ''));
        if (\$from !== '') {
            \$where[] = "`" . str_replace('`', '``', \$alias) . "` >= ?";
            \$params[] = \$from;
        }
        if (\$to !== '') {
            \$where[] = "`" . str_replace('`', '``', \$alias) . "` <= ?";
            \$params[] = \$to;
        }
        continue;
    }

    \$filter = trim((string) \$filter);
    if (\$filter === '') continue;

    if (in_array(\$type, ['UGUALE', 'BOOLEANO'], true)) {
        \$where[] = "`" . str_replace('`', '``', \$alias) . "` = ?";
        \$params[] = \$filter;
    } else {
        \$where[] = "CAST(`" . str_replace('`', '``', \$alias) . "` AS CHAR) LIKE ?";
        \$params[] = '%' . \$filter . '%';
    }
}

if (\$where) {
    \$wrappedSql .= ' WHERE ' . implode(' AND ', \$where);
}

\$countSql = "SELECT COUNT(*) FROM (" . \$wrappedSql . ") count_data";
\$totalRows = (int) \$db->fetchColumn(\$countSql, \$params);
\$useRowPagination = \$paginationEnabled || \$pageTypeId === 1;
\$totalPages = \$useRowPagination
    ? max(1, (int) ceil(\$totalRows / \$rowsPerPage))
    : 1;
\$page = min(\$page, \$totalPages);

if (\$sortEnabled && isset(\$sortableAliases[\$sort])) {
    \$wrappedSql .= " ORDER BY `" . str_replace('`', '``', \$sort) . "` " . \$direction;
} elseif (\$sortEnabled && \$sortableAliases) {
    \$defaultOrder = [];
    foreach (array_keys(\$sortableAliases) as \$sortableAlias) {
        \$defaultOrder[] = '`' . str_replace('`', '``', \$sortableAlias) . '` ASC';
    }
    \$wrappedSql .= ' ORDER BY ' . implode(', ', \$defaultOrder);
}

if (
    \$useRowPagination
    && \$navigateRecord !== null
    && \$navigateRecord !== ''
    && !empty(\$crudConfig['primary_key_alias'])
) {
    \$primaryKeyAlias = (string) \$crudConfig['primary_key_alias'];
    \$orderedRecords = \$db->fetchAll(\$wrappedSql, \$params);
    foreach (\$orderedRecords as \$recordIndex => \$orderedRecord) {
        if ((string) (\$orderedRecord[\$primaryKeyAlias] ?? '') === (string) \$navigateRecord) {
            \$page = (int) floor(\$recordIndex / \$rowsPerPage) + 1;
            break;
        }
    }
}

if (\$useRowPagination) {
    \$offset = (\$page - 1) * \$rowsPerPage;
    \$wrappedSql .= " LIMIT " . (int) \$rowsPerPage . " OFFSET " . (int) \$offset;
}

\$rows = \$db->fetchAll(\$wrappedSql, \$params);

\$modalDataByRow = [];

if (\$hasLinkedModalDetail) {
    \$modalSelect = [];
    foreach (\$modalConfig['fields'] as \$field) {
        \$modalSelect[] =
            "`" . str_replace('`', '``', (string) \$field['field_name']) . "` AS `" .
            str_replace('`', '``', (string) \$field['alias']) . "`";
    }

    \$modalSql =
        "SELECT " . implode(', ', \$modalSelect)
        . " FROM `" . str_replace('`', '``', (string) \$modalConfig['linked_table_name']) . "`"
        . " WHERE `" . str_replace('`', '``', (string) \$modalConfig['linked_field_name']) . "` = ?";

    foreach (\$rows as \$rowIndex => \$row) {
        \$filterValue = \$row[\$modalConfig['main_value_alias']] ?? null;

        if (\$filterValue === null || \$filterValue === '') {
            \$modalDataByRow[\$rowIndex] = [];
            continue;
        }

        \$modalDataByRow[\$rowIndex] = \$db->fetchAll(\$modalSql, [\$filterValue]);
    }
}

function displayValue(mixed \$value, string \$format, string \$basePath = ''): string
{
    if (\$value === null || \$value === '') {
        return '<span class="text-muted">—</span>';
    }

    \$raw = (string) \$value;
    \$resource = \$basePath !== ''
        ? rtrim(\$basePath, '/') . '/' . ltrim(\$raw, '/')
        : \$raw;
    \$safeResource = htmlspecialchars(\$resource, ENT_QUOTES, 'UTF-8');

    \$format = trim((string) \$format);
    if (\$format === '') {
        return htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8');
    }

    \$normalizedFormat = strtolower(trim(\$format));
    \$compactFormat = str_replace(' ', '', \$normalizedFormat);

    \$normalizeNumericValue = static function (mixed \$numericValue): ?float {
        if (\$numericValue === null || \$numericValue === '') {
            return null;
        }

        \$normalized = preg_replace('/\\s+/', '', (string) \$numericValue);
        if (\$normalized === null || \$normalized === '') {
            return null;
        }

        \$commaPos = strrpos(\$normalized, ',');
        \$dotPos = strrpos(\$normalized, '.');

        if (\$commaPos !== false && \$dotPos !== false) {
            if (\$commaPos > \$dotPos) {
                \$normalized = str_replace('.', '', \$normalized);
                \$normalized = str_replace(',', '.', \$normalized);
            } else {
                \$normalized = str_replace(',', '', \$normalized);
            }
        } elseif (\$commaPos !== false) {
            \$normalized = str_replace('.', '', \$normalized);
            \$normalized = str_replace(',', '.', \$normalized);
        }

        \$normalized = preg_replace('/[^0-9\\.\\-]/', '', \$normalized);
        if (\$normalized === null || \$normalized === '' || !is_numeric(\$normalized)) {
            return null;
        }

        return (float) \$normalized;
    };

    \$normalizeDateValue = static function (mixed \$dateValue, array \$formats): ?DateTimeImmutable {
        \$rawDate = trim((string) \$dateValue);
        if (\$rawDate === '') {
            return null;
        }

        foreach (\$formats as \$phpFormat) {
            \$dateTime = DateTimeImmutable::createFromFormat(\$phpFormat, \$rawDate);
            if (\$dateTime instanceof DateTimeImmutable) {
                \$errors = DateTimeImmutable::getLastErrors();
                if ((\$errors['warning_count'] ?? 0) === 0 && (\$errors['error_count'] ?? 0) === 0) {
                    return \$dateTime;
                }
            }
        }

        \$timestamp = strtotime(\$rawDate);
        return \$timestamp ? (new DateTimeImmutable())->setTimestamp(\$timestamp) : null;
    };

    if (str_contains(\$compactFormat, '#')) {
        \$decimals = 0;
        if (preg_match('/[,.](0+)$/', \$compactFormat, \$matches)) {
            \$decimals = strlen(\$matches[1]);
        }

        \$numericValue = \$normalizeNumericValue(\$value);
        return htmlspecialchars(number_format(\$numericValue ?? (float) \$value, \$decimals, ',', '.'), ENT_QUOTES, 'UTF-8');
    }

    if (in_array(\$compactFormat, ['dd/mm/aaaa', 'dd/mm/yyyy'], true)) {
        \$dateTime = \$normalizeDateValue(\$value, ['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y']);
        return \$dateTime ? \$dateTime->format('d/m/Y') : htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8');
    }

    if (in_array(\$compactFormat, ['dd/mm/aaaahh:mm', 'dd/mm/yyyyhh:mm'], true)) {
        \$dateTime = \$normalizeDateValue(\$value, ['d/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i']);
        \$formatted = \$dateTime ? \$dateTime->format('d/m/Y H:i') : (string) \$value;
        return htmlspecialchars(\$formatted, ENT_QUOTES, 'UTF-8');
    }

    if (in_array(\$compactFormat, ['aaaa-mm-gg', 'yyyy-mm-dd'], true)) {
        \$dateTime = \$normalizeDateValue(\$value, ['Y-m-d', 'd/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y']);
        \$formatted = \$dateTime ? \$dateTime->format('Y-m-d') : (string) \$value;
        return htmlspecialchars(\$formatted, ENT_QUOTES, 'UTF-8');
    }

    if (in_array(\$compactFormat, ['hh:mm', 'hh:mm:ss'], true)) {
        \$dateTime = \$normalizeDateValue(\$value, ['H:i:s', 'H:i', 'h:i:s A', 'h:i A']);
        if (!\$dateTime) {
            return htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8');
        }

        \$formatted = \$compactFormat === 'hh:mm'
            ? \$dateTime->format('H:i')
            : \$dateTime->format('H:i:s');
        return \$formatted;
    }

    switch (\$normalizedFormat) {
        case 'utente@esempio.i':
            return '<a href="mailto:' . htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8') . '</a>';
        case 'https://esempio.it':
            return '<a href="' . \$safeResource . '" target="_blank" rel="noopener">'
                . htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8') . '</a>';
        case '{"esempio":true}':
            \$decoded = json_decode((string) \$value, true);
            \$formatted = \$decoded === null
                ? (string) \$value
                : json_encode(\$decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return '<pre class="mb-0 small">' . htmlspecialchars(\$formatted, ENT_QUOTES, 'UTF-8') . '</pre>';
        case '1':
            return (bool) \$value
                ? '<span class="badge bg-success">Sì</span>'
                : '<span class="badge bg-secondary">No</span>';
        case 'valuta':
            \$numericValue = \$normalizeNumericValue(\$value);
            \$formatted = number_format(\$numericValue ?? (float) \$value, 2, ',', '.') . ' €';
            return htmlspecialchars(\$formatted, ENT_QUOTES, 'UTF-8');
        case 'booleano':
            return (bool) \$value
                ? '<span class="badge bg-success">Sì</span>'
                : '<span class="badge bg-secondary">No</span>';
        case 'json':
            \$decoded = json_decode((string) \$value, true);
            \$formatted = \$decoded === null
                ? (string) \$value
                : json_encode(\$decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return '<pre class="mb-0 small">' . htmlspecialchars(\$formatted, ENT_QUOTES, 'UTF-8') . '</pre>';
        case 'immagine':
            return '<a href="' . \$safeResource . '" target="_blank" rel="noopener">'
                . '<img src="' . \$safeResource . '" alt="" class="img-fluid rounded border" '
                . 'style="max-height:180px;object-fit:contain"></a>';
        case 'file':
            \$name = basename(parse_url(\$resource, PHP_URL_PATH) ?: \$resource);
            return '<a class="btn btn-sm btn-outline-primary" href="' . \$safeResource
                . '" target="_blank" rel="noopener" download><i class="bi bi-download me-1"></i>'
                . htmlspecialchars(\$name, ENT_QUOTES, 'UTF-8') . '</a>';
        case 'url':
            return '<a href="' . \$safeResource . '" target="_blank" rel="noopener">'
                . htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8') . '</a>';
        case 'email':
            return '<a href="mailto:' . htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8') . '</a>';
        default:
            return nl2br(htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8'));
    }
}

function linkedValue(array \$field, array \$row): string
{
    \$html = displayValue(
        \$row[\$field['output_alias']] ?? null,
        \$field['format'],
        \$field['base_path'] ?? ''
    );

    \$file = trim((string) (\$field['link_target_file'] ?? ''));
    \$param = trim((string) (\$field['link_parameter'] ?? ''));
    \$valueAlias = trim((string) (\$field['link_value_alias'] ?? ''));

    if (\$file === '' || \$param === '' || \$valueAlias === '') return \$html;

    \$value = \$row[\$valueAlias] ?? null;
    if (\$value === null || \$value === '') return \$html;

    \$url = \$file . (str_contains(\$file, '?') ? '&' : '?')
        . rawurlencode(\$param) . '=' . rawurlencode((string) \$value);

    return '<a class="text-decoration-none" href="'
        . htmlspecialchars(\$url, ENT_QUOTES, 'UTF-8') . '">' . \$html . '</a>';
}

function defaultFormatByType(string \$type): string
{
    \$type = strtolower(trim(\$type));

    if (in_array(\$type, ['int', 'integer', 'smallint', 'mediumint', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'], true)) {
        return in_array(\$type, ['decimal', 'numeric', 'float', 'double', 'real'], true) ? '#.##0,00' : '#.##0';
    }

    if (\$type === 'date') {
        return 'dd/mm/aaaa';
    }

    if (in_array(\$type, ['datetime', 'timestamp'], true)) {
        return 'dd/mm/aaaa hh:mm';
    }

    if (\$type === 'time') {
        return 'hh:mm';
    }

    if (in_array(\$type, ['email', 'mail'], true)) {
        return 'utente@esempio.it';
    }

    if (in_array(\$type, ['url', 'link'], true)) {
        return 'https://esempio.it';
    }

    if (\$type === 'json') {
        return '{"esempio":true}';
    }

    if (in_array(\$type, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string'], true)) {
        return 'testo';
    }

    if (in_array(\$type, ['boolean', 'bool', 'tinyint'], true)) {
        return '1';
    }

    return '';
}

function normalizeAlignmentCode(string \$value): string
{
    \$value = strtoupper(trim(\$value));
    return in_array(\$value, ['SINISTRA', 'CENTRO', 'DESTRA'], true) ? \$value : 'SINISTRA';
}

function resolveFieldFormat(array \$field): string
{
    \$rawFormat = trim((string) (\$field['format'] ?? ''));
    if (\$rawFormat !== '') {
        return \$rawFormat;
    }

    \$customFormat = trim((string) (\$field['customFormat'] ?? ''));
    if (\$customFormat !== '') {
        return \$customFormat;
    }

    return defaultFormatByType((string) (\$field['fieldType'] ?? \$field['tipo'] ?? ''));
}

function buildQuery(array \$overrides = []): string
{
    \$query = array_merge(\$_GET, \$overrides);
    if (!array_key_exists('crud_message', \$overrides)) {
        unset(\$query['crud_message']);
    }
    if (!array_key_exists('edit', \$overrides)) {
        unset(\$query['edit']);
    }
    if (!array_key_exists('record', \$overrides)) {
        unset(\$query['record']);
    }
    foreach (\$query as \$key => \$value) {
        if (\$value === null || \$value === '') {
            unset(\$query[\$key]);
        }
    }
    return '?' . http_build_query(\$query);
}
?>

<div class="container-fluid py-3 generated-view-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h3 class="mb-0"><?= htmlspecialchars(\$pageTitle, ENT_QUOTES, 'UTF-8') ?></h3>
            <span class="badge text-bg-secondary">
                File v<?= htmlspecialchars(\$generatedPageVersion, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if (\$crudEnabled && \$crudAdd && \$viewType !== 'SCHEDA_SINGOLA'): ?>
                <button type="button"
                        class="btn btn-success"
                        data-bs-toggle="modal"
                        data-bs-target="#crudInsertModal">
                    <i class="bi bi-plus-lg me-1"></i>Aggiungi
                </button>
            <?php endif; ?>

        </div>

        <?php if (\$searchEnabled): ?>
            <form method="get" class="d-flex gap-2">
                <?php foreach (\$_GET as \$key => \$value): ?>
                    <?php if (!in_array(\$key, ['q', 'p', 'crud_message', 'edit', 'record'], true)): ?>
                        <input type="hidden"
                               name="<?= htmlspecialchars((string) \$key, ENT_QUOTES, 'UTF-8') ?>"
                               value="<?= htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="search"
                       name="q"
                       class="form-control"
                       value="<?= htmlspecialchars(\$search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Cerca...">
                <button class="btn btn-primary" type="submit">Cerca</button>
                <?php if (\$search !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(buildQuery(['q' => null, 'p' => 1]), ENT_QUOTES, 'UTF-8') ?>">Azzera</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>


    <?php if (\$crudMessage !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars(\$crudMessage, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (\$crudError !== ''): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars(\$crudError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php
    \$filterFields = array_values(array_filter(
        \$fields,
        fn(array \$field): bool => !empty(\$field['filter_enabled'])
    ));
    ?>
    <?php if (\$filterFields): ?>
        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-light">
                <strong><i class="bi bi-funnel me-1"></i>Filtri avanzati</strong>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <?php foreach (\$filterFields as \$field): ?>
                        <?php
                        \$alias = \$field['output_alias'];
                        \$type = \$field['filter_type'] ?? 'TESTO';
                        \$current = \$advancedFilters[\$alias] ?? '';
                        ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <label class="form-label"><?= htmlspecialchars(\$field['label'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if (in_array(\$type, ['INTERVALLO_NUMERO','INTERVALLO_DATA'], true)): ?>
                                <?php \$inputType = \$type === 'INTERVALLO_DATA' ? 'date' : 'number'; ?>
                                <div class="input-group">
                                    <input type="<?= \$inputType ?>" class="form-control"
                                           name="f[<?= \$alias ?>][from]"
                                           value="<?= htmlspecialchars((string) (\$current['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="Da">
                                    <input type="<?= \$inputType ?>" class="form-control"
                                           name="f[<?= \$alias ?>][to]"
                                           value="<?= htmlspecialchars((string) (\$current['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="A">
                                </div>
                            <?php elseif (\$type === 'BOOLEANO'): ?>
                                <select class="form-select" name="f[<?= \$alias ?>]">
                                    <option value="">Tutti</option>
                                    <option value="1" <?= (string) \$current === '1' ? 'selected' : '' ?>>Sì</option>
                                    <option value="0" <?= (string) \$current === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control" name="f[<?= \$alias ?>]"
                                       value="<?= htmlspecialchars((string) \$current, ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="<?= \$type === 'UGUALE' ? 'Valore esatto' : 'Contiene...' ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Applica filtri</button>
                        <a class="btn btn-outline-secondary" href="?">Azzera</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (\$viewType === 'SCHEDA_SINGOLA'): ?>
        <?php if (!\$rows): ?>
            <div class="alert alert-secondary">
                Nessun dato disponibile.
            </div>
        <?php else: ?>
            <?php \$row = \$rows[0]; ?>
            <?php
            \$currentPk = \$crudEnabled
                ? (\$row[\$crudConfig['primary_key_alias']] ?? null)
                : null;
            ?>

            <div class="card shadow-sm"
                 data-record-value="<?= htmlspecialchars((string) (\$currentPk ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong><?= htmlspecialchars(\$pageTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-secondary">
                            Record <?= number_format(\$page, 0, ',', '.') ?>
                            di <?= number_format(\$totalRows, 0, ',', '.') ?>
                        </span>
                        <?php if (\$hasModalDetail): ?>
                            <button class="btn btn-sm btn-outline-primary" type="button"
                                    data-bs-toggle="modal" data-bs-target="#singleCardModal">
                                <i class="bi bi-table me-1"></i>Dettaglio
                            </button>
                        <?php endif; ?>
                        <?php if (\$crudEnabled && \$currentPk !== null): ?>
                            <?php if (\$crudEdit): ?>
                                <a class="btn btn-sm btn-outline-warning"
                                   href="<?= htmlspecialchars(buildQuery(['edit' => \$currentPk]), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-pencil me-1"></i>Modifica
                                </a>
                            <?php endif; ?>
                            <?php if (\$crudDelete && \$viewType !== 'SCHEDA_SINGOLA'): ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Cancellare definitivamente il record?');">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\$crudCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="crud_action" value="delete">
                                    <input type="hidden" name="pk_value" value="<?= htmlspecialchars((string) \$currentPk, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">
                                        <i class="bi bi-trash me-1"></i>Cancella
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach (\$fields as \$field): ?>
                            <?php if (empty(\$field['visible_card'])) continue; ?>

                            <?php
                            \$alignment = match (\$field['alignment']) {
                                'CENTRO' => 'text-center',
                                'DESTRA' => 'text-end',
                                default => 'text-start',
                            };
                            ?>

                            <div class="col-12 col-md-<?= htmlspecialchars((string) (\$field['bootstrap_col'] ?? '6'), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="border rounded p-3 h-100 bg-light-subtle">
                                    <div class="small text-muted mb-1">
                                        <?= htmlspecialchars(\$field['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="<?= \$alignment ?> fw-semibold">
                                        <?= linkedValue(\$field, \$row) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (\$totalRows > 1): ?>
                    <div class="card-footer bg-white">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="btn-group" role="group">
                                <a class="btn btn-outline-secondary <?= \$page <= 1 ? 'disabled' : '' ?>"
                                   href="<?= htmlspecialchars(buildQuery(['p' => 1]), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-skip-backward-fill me-1"></i>
                                    Primo
                                </a>

                                <a class="btn btn-outline-primary <?= \$page <= 1 ? 'disabled' : '' ?>"
                                   href="<?= htmlspecialchars(buildQuery(['p' => max(1, \$page - 1)]), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-chevron-left me-1"></i>
                                    Precedente
                                </a>
                            </div>

                            <div class="small text-muted">
                                Scheda <?= number_format(\$page, 0, ',', '.') ?>
                                / <?= number_format(\$totalPages, 0, ',', '.') ?>
                            </div>

                            <div class="btn-group" role="group">
                                <a class="btn btn-outline-primary <?= \$page >= \$totalPages ? 'disabled' : '' ?>"
                                   href="<?= htmlspecialchars(buildQuery(['p' => min(\$totalPages, \$page + 1)]), ENT_QUOTES, 'UTF-8') ?>">
                                    Successivo
                                    <i class="bi bi-chevron-right ms-1"></i>
                                </a>

                                <a class="btn btn-outline-secondary <?= \$page >= \$totalPages ? 'disabled' : '' ?>"
                                   href="<?= htmlspecialchars(buildQuery(['p' => \$totalPages]), ENT_QUOTES, 'UTF-8') ?>">
                                    Ultimo
                                    <i class="bi bi-skip-forward-fill ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

{$singleCardModalPhp}
        <?php endif; ?>
    <?php else: ?>
        <div class="table-responsive border rounded shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php foreach (\$visibleFields as \$field): ?>
                            <th style="<?= \$field['width'] !== '' ? 'width:' . htmlspecialchars(\$field['width'], ENT_QUOTES, 'UTF-8') : '' ?>">
                                <?php if (\$pageTypeId === 2 && \$sortEnabled): ?>
                                    <?php
                                    \$newDirection = (\$sort === \$field['output_alias'] && \$direction === 'ASC') ? 'DESC' : 'ASC';
                                    ?>
                                    <a class="text-decoration-none text-dark"
                                       href="<?= htmlspecialchars(buildQuery([
                                           'sort' => \$field['output_alias'],
                                           'dir' => \$newDirection,
                                           'p' => 1
                                       ]), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(\$field['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars(\$field['label'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        <?php if (\$crudEnabled && (\$crudEdit || \$crudDelete)): ?>
                            <th class="text-end">Azioni</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!\$rows): ?>
                        <tr>
                            <td colspan="<?= count(\$visibleFields) + ((\$crudEnabled && (\$crudEdit || \$crudDelete)) ? 1 : 0) ?>"
                                class="text-center text-muted py-4">
                                Nessun dato disponibile.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach (\$rows as \$rowIndex => \$row): ?>
                        <?php
                        \$rowRecordValue = \$crudEnabled
                            ? (\$row[\$crudConfig['primary_key_alias']] ?? null)
                            : null;
                        ?>
                        <tr class="<?= \$hasModalDetail ? 'table-row-toggle' : '' ?>"
                            data-record-value="<?= htmlspecialchars((string) (\$rowRecordValue ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            <?= \$hasModalDetail ? 'tabindex="0" role="button" aria-expanded="false" data-inline-target="#recordInline' . \$rowIndex . '"' : '' ?>>
                            <?php foreach (\$visibleFields as \$field): ?>
                                <?php
                                \$alignment = match (\$field['alignment']) {
                                    'CENTRO' => 'text-center',
                                    'DESTRA' => 'text-end',
                                    default => 'text-start',
                                };
                                ?>
                                <td class="<?= \$alignment ?>">
                                    <?= linkedValue(\$field, \$row) ?>
                                </td>
                            <?php endforeach; ?>

                            <?php if (\$crudEnabled && (\$crudEdit || \$crudDelete)): ?>
                                <?php
                                \$currentPk = \$crudEnabled
                                    ? (\$row[\$crudConfig['primary_key_alias']] ?? null)
                                    : null;
                                ?>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <?php if (\$crudEnabled && \$currentPk !== null): ?>
                                            <?php if (\$crudEdit): ?>
                                                <a class="btn btn-sm btn-outline-warning"
                                                   href="<?= htmlspecialchars(buildQuery(['edit' => \$currentPk]), ENT_QUOTES, 'UTF-8') ?>">
                                                    Modifica
                                                </a>
                                            <?php endif; ?>

                                            <?php if (\$crudDelete): ?>
                                                <form method="post"
                                                      onsubmit="return confirm('Cancellare definitivamente il record?');">
                                                    <input type="hidden" name="csrf"
                                                           value="<?= htmlspecialchars(\$crudCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="crud_action" value="delete">
                                                    <input type="hidden" name="pk_value"
                                                           value="<?= htmlspecialchars((string) \$currentPk, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit">
                                                        Cancella
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
{$tableRowCardModalPhp}
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (\$hasModalDetail): ?>
        <script>
        document.querySelectorAll('.table-row-toggle').forEach(row => {
            row.addEventListener('dblclick', event => {
                if (event.target.closest('a,button,form,input,textarea,select,label')) return;

                const targetSelector = row.dataset.inlineTarget;
                if (!targetSelector) return;

                const target = document.querySelector(targetSelector);
                if (!target) return;

                bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).toggle();
            });
        });
        </script>
    <?php endif; ?>

    <?php if (\$pageTypeId === 2): ?>
        <script>
        (() => {
            const closeOtherOpenModals = currentModal => {
                document.querySelectorAll('.modal.show').forEach(openModal => {
                    if (openModal === currentModal) return;
                    bootstrap.Modal.getOrCreateInstance(openModal).hide();
                });
            };

            document.addEventListener('show.bs.modal', event => {
                closeOtherOpenModals(event.target);
            });

            document.addEventListener('hidden.bs.modal', () => {
                if (document.querySelector('.modal.show')) return;

                document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                    backdrop.remove();
                });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
            });
        })();
        </script>
    <?php endif; ?>

    <?php if (\$navigateRecord !== null && \$navigateRecord !== ''): ?>
        <script>
        (() => {
            const requestedRecord = <?= json_encode((string) \$navigateRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const selectedRow = Array.from(document.querySelectorAll('[data-record-value]'))
                .find(row => row.dataset.recordValue === requestedRecord);
            if (!selectedRow) return;

            if (!selectedRow.hasAttribute('tabindex')) selectedRow.setAttribute('tabindex', '-1');
            selectedRow.focus({ preventScroll: true });
            window.requestAnimationFrame(() => {
                selectedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        })();
        </script>
    <?php endif; ?>

    <?php if (\$viewType !== 'SCHEDA_SINGOLA' && \$paginationEnabled && \$totalPages > 1): ?>
        <nav class="mt-3" aria-label="Paginazione">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?= \$page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(buildQuery(['p' => max(1, \$page - 1)]), ENT_QUOTES, 'UTF-8') ?>">Precedente</a>
                </li>

                <?php
                \$start = max(1, \$page - 3);
                \$end = min(\$totalPages, \$page + 3);
                for (\$number = \$start; \$number <= \$end; \$number++):
                ?>
                    <li class="page-item <?= \$number === \$page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(buildQuery(['p' => \$number]), ENT_QUOTES, 'UTF-8') ?>">
                            <?= \$number ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?= \$page >= \$totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(buildQuery(['p' => min(\$totalPages, \$page + 1)]), ENT_QUOTES, 'UTF-8') ?>">Successiva</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

    <?php
    function renderCrudField(
        array \$field,
        mixed \$value,
        array \$dropdowns,
        bool \$disabled = false
    ): void {
        \$name = (string) \$field['field_name'];
        \$safeName = htmlspecialchars(\$name, ENT_QUOTES, 'UTF-8');
        \$safeValue = htmlspecialchars((string) (\$value ?? ''), ENT_QUOTES, 'UTF-8');
        \$required = !empty(\$field['required']) ? 'required' : '';
        \$disabledAttr = \$disabled ? 'disabled' : '';

        echo '<label class="form-label">' . htmlspecialchars(\$name, ENT_QUOTES, 'UTF-8') . '</label>';

        if (!empty(\$field['fk'])) {
            echo '<select class="form-select" name="crud[' . \$safeName . ']" ' . \$required . ' ' . \$disabledAttr . '>';
            echo '<option value="">-- selezionare --</option>';
            foreach (\$dropdowns[\$name] ?? [] as \$option) {
                \$selected = (string) (\$option['option_value'] ?? '') === (string) (\$value ?? '')
                    ? ' selected'
                    : '';
                echo '<option value="'
                    . htmlspecialchars((string) (\$option['option_value'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '"' . \$selected . '>'
                    . htmlspecialchars((string) (\$option['option_label'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '</option>';
            }
            echo '</select>';
            return;
        }

        \$type = strtolower((string) (\$field['field_type'] ?? \$field['type'] ?? 'text'));

        if (\$type === 'text' || \$type === 'json') {
            echo '<textarea class="form-control" rows="3" name="crud[' . \$safeName . ']" '
                . \$required . ' ' . \$disabledAttr . '>' . \$safeValue . '</textarea>';
            return;
        }

        if (\$type === 'boolean' || \$type === 'tinyint') {
            echo '<select class="form-select" name="crud[' . \$safeName . ']" ' . \$required . ' ' . \$disabledAttr . '>';
            echo '<option value="">-- selezionare --</option>';
            echo '<option value="1"' . ((string) \$value === '1' ? ' selected' : '') . '>Sì</option>';
            echo '<option value="0"' . ((string) \$value === '0' ? ' selected' : '') . '>No</option>';
            echo '</select>';
            return;
        }

        \$inputType = match (\$type) {
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime-local',
            'int', 'smallint', 'bigint' => 'number',
            'decimal', 'float', 'double' => 'text',
            default => 'text',
        };

        if (\$type === 'date' && \$value) {
            \$timestamp = strtotime(str_replace('/', '-', (string) \$value));
            if (\$timestamp) {
                \$safeValue = date('Y-m-d', \$timestamp);
            }
        }

        if (in_array(\$type, ['datetime', 'timestamp'], true) && \$value) {
            \$timestamp = strtotime(str_replace('/', '-', (string) \$value));
            if (\$timestamp) {
                \$safeValue = date('Y-m-d\TH:i', \$timestamp);
            }
        }

        \$inputExtraAttributes = in_array(\$type, ['decimal', 'float', 'double'], true)
            ? ' inputmode="decimal"'
            : '';

        echo '<input type="' . \$inputType . '" class="form-control" name="crud['
            . \$safeName . ']" value="' . \$safeValue . '"' . \$inputExtraAttributes . ' ' . \$required . ' ' . \$disabledAttr . '>';
    }
    ?>

    <?php if (\$crudEnabled && \$crudAdd && \$viewType !== 'SCHEDA_SINGOLA'): ?>
        <div class="modal fade" id="crudInsertModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form method="post" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Inserisci record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf"
                               value="<?= htmlspecialchars(\$crudCsrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="crud_action" value="insert">

                        <div class="row g-3">
                            <?php foreach (\$crudConfig['fields'] as \$crudField): ?>
                                <?php if (isset(\$crudField['insert_visible']) && empty(\$crudField['insert_visible'])) continue; ?>
                                <?php
                                \$crudBootstrapCol = (int) (\$crudField['bootstrap_col'] ?? 6);
                                if (\$crudBootstrapCol < 1 || \$crudBootstrapCol > 12) \$crudBootstrapCol = 6;
                                ?>
                                <div class="col-12 col-md-<?= \$crudBootstrapCol ?>">
                                    <?php renderCrudField(\$crudField, null, \$crudDropdowns, empty(\$crudField['editable'])); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Annulla
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-lg me-1"></i>Inserisci
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (\$crudEnabled && \$crudEdit && \$crudEditRecord): ?>
        <div class="modal fade show"
             id="crudEditModal"
             tabindex="-1"
             aria-modal="true"
             role="dialog"
             style="display:block;background:rgba(0,0,0,.45)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form method="post" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Modifica record</h5>
                        <a class="btn-close"
                           href="<?= htmlspecialchars(buildQuery(['edit' => null]), ENT_QUOTES, 'UTF-8') ?>"></a>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf"
                               value="<?= htmlspecialchars(\$crudCsrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="crud_action" value="update">
                        <input type="hidden" name="pk_value"
                               value="<?= htmlspecialchars(
                                   (string) (\$crudEditRecord[\$crudConfig['primary_key']['field_name']] ?? ''),
                                   ENT_QUOTES,
                                   'UTF-8'
                               ) ?>">

                        <div class="row g-3">
                            <?php foreach (\$crudConfig['fields'] as \$crudField): ?>
                                <?php if (isset(\$crudField['update_visible']) && empty(\$crudField['update_visible'])) continue; ?>
                                <?php
                                \$crudBootstrapCol = (int) (\$crudField['bootstrap_col'] ?? 6);
                                if (\$crudBootstrapCol < 1 || \$crudBootstrapCol > 12) \$crudBootstrapCol = 6;
                                ?>
                                <div class="col-12 col-md-<?= \$crudBootstrapCol ?>">
                                    <?php renderCrudField(
                                        \$crudField,
                                        \$crudEditRecord[\$crudField['field_name']] ?? null,
                                        \$crudDropdowns,
                                        empty(\$crudField['editable'])
                                    ); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a class="btn btn-secondary"
                           href="<?= htmlspecialchars(buildQuery(['edit' => null]), ENT_QUOTES, 'UTF-8') ?>">
                            Annulla
                        </a>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-lg me-1"></i>Salva modifica
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="small text-muted mt-2">
        Record trovati: <?= number_format(\$totalRows, 0, ',', '.') ?>
    </div>
</div>
PHP;
}

function repairGeneratedDisplayValueBlock(string $generatedCode): string
{
    $pattern = '/function displayValue\\(mixed\\s*,\\s*string\\s*,\\s*string\\s*=\\s*\'\'\\): string.*?(?=\\nfunction linkedValue\\(array \\$field, array \\$row\\): string)/s';
    $replacement = <<<'PHP'
function displayValue(mixed $value, string $format, string $basePath = ''): string
{
    if ($value === null || $value === '') {
        return '<span class="text-muted">—</span>';
    }

    $raw = (string) $value;
    $resource = $basePath !== ''
        ? rtrim($basePath, '/') . '/' . ltrim($raw, '/')
        : $raw;
    $safeResource = htmlspecialchars($resource, ENT_QUOTES, 'UTF-8');

    $format = trim((string) $format);
    if ($format === '') {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    $normalizedFormat = strtolower(trim($format));
    $compactFormat = str_replace(' ', '', $normalizedFormat);

    $normalizeNumericValue = static function (mixed $numericValue): ?float {
        if ($numericValue === null || $numericValue === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', (string) $numericValue);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        $commaPos = strrpos($normalized, ',');
        $dotPos = strrpos($normalized, '.');

        if ($commaPos !== false && $dotPos !== false) {
            if ($commaPos > $dotPos) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($commaPos !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9\\.\\-]/', '', $normalized);
        if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    };

    $normalizeDateValue = static function (mixed $dateValue, array $formats): ?DateTimeImmutable {
        $rawDate = trim((string) $dateValue);
        if ($rawDate === '') {
            return null;
        }

        foreach ($formats as $phpFormat) {
            $dateTime = DateTimeImmutable::createFromFormat($phpFormat, $rawDate);
            if ($dateTime instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();
                if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                    return $dateTime;
                }
            }
        }

        $timestamp = strtotime($rawDate);
        return $timestamp ? (new DateTimeImmutable())->setTimestamp($timestamp) : null;
    };

    if (str_contains($compactFormat, '#')) {
        $decimals = 0;
        if (preg_match('/[,.](0+)$/', $compactFormat, $matches)) {
            $decimals = strlen($matches[1]);
        }

        $numericValue = $normalizeNumericValue($value);
        return htmlspecialchars(number_format($numericValue ?? (float) $value, $decimals, ',', '.'), ENT_QUOTES, 'UTF-8');
    }

    if (in_array($compactFormat, ['dd/mm/aaaa', 'dd/mm/yyyy'], true)) {
        $dateTime = $normalizeDateValue($value, ['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y']);
        return $dateTime ? $dateTime->format('d/m/Y') : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    if (in_array($compactFormat, ['dd/mm/aaaahh:mm', 'dd/mm/yyyyhh:mm'], true)) {
        $dateTime = $normalizeDateValue($value, ['d/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i']);
        $formatted = $dateTime ? $dateTime->format('d/m/Y H:i') : (string) $value;
        return htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
    }

    if (in_array($compactFormat, ['aaaa-mm-gg', 'yyyy-mm-dd'], true)) {
        $dateTime = $normalizeDateValue($value, ['Y-m-d', 'd/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y']);
        $formatted = $dateTime ? $dateTime->format('Y-m-d') : (string) $value;
        return htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
    }

    if (in_array($compactFormat, ['hh:mm', 'hh:mm:ss'], true)) {
        $dateTime = $normalizeDateValue($value, ['H:i:s', 'H:i', 'h:i:s A', 'h:i A']);
        if (!$dateTime) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        $formatted = $compactFormat === 'hh:mm'
            ? $dateTime->format('H:i')
            : $dateTime->format('H:i:s');
        return htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
    }

    switch ($normalizedFormat) {
        case 'valuta':
            $numericValue = $normalizeNumericValue($value);
            return htmlspecialchars(number_format($numericValue ?? (float) $value, 2, ',', '.') . ' €', ENT_QUOTES, 'UTF-8');
        case 'booleano':
            return (bool) $value
                ? '<span class="badge bg-success">Sì</span>'
                : '<span class="badge bg-secondary">No</span>';
        case 'json':
            $decoded = json_decode((string) $value, true);
            $formatted = $decoded === null
                ? (string) $value
                : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return '<pre class="mb-0 small">' . htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8') . '</pre>';
        case 'immagine':
            return '<a href="' . $safeResource . '" target="_blank" rel="noopener">'
                . '<img src="' . $safeResource . '" alt="" class="img-fluid rounded border" '
                . 'style="max-height:180px;object-fit:contain"></a>';
        case 'file':
            $name = basename(parse_url($resource, PHP_URL_PATH) ?: $resource);
            return '<a class="btn btn-sm btn-outline-primary" href="' . $safeResource
                . '" target="_blank" rel="noopener" download><i class="bi bi-download me-1"></i>'
                . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
        case 'url':
            return '<a href="' . $safeResource . '" target="_blank" rel="noopener">'
                . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</a>';
        case 'email':
            return '<a href="mailto:' . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</a>';
        default:
            return nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
    }
}

PHP;

    return preg_replace($pattern, $replacement, $generatedCode) ?? $generatedCode;
}

/* =========================
 * ENDPOINT AJAX
 * ========================= */

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($action !== '') {
    requireProject($progettoId, $progettoNome);
    $paths = projectPaths($progettoNome);

    try {
        if ($action === 'project_status') {
            jsonResponse([
                'ok' => true,
                'project_id' => $progettoId,
                'project_name' => $progettoNome,
                'project_folder' => $paths['folder'],
                'schema_exists' => safeIsFile($paths['schema']),
                'schema_path' => $paths['schema'],
                'pages_path' => $paths['pages'],
            ]);
        }

        if (!safeIsFile($paths['schema'])) {
            jsonResponse([
                'ok' => false,
                'message' => 'schema.sql non trovato. Crearlo dalla voce dedicata del menu prima di proseguire.'
            ], 409);
        }


        if ($action === 'list_configurations') {
            $rows = $db->fetchAll(
                "SELECT
                    pv.id,
                    pv.nome_pagina,
                    pv.nome_file,
                    pv.descrizione,
                    pv.titolo_pagina,
                    pv.tipo_visualizzazione,
                    pv.stato,
                    pv.data_modifica,
                    pv.data_generazione,
                    t.nome AS tabella_principale
                 FROM pagine_visualizzazione pv
                 JOIN tabelle t ON t.id = pv.IDtabella_principale
                 WHERE pv.IDprogetto = ?
                 ORDER BY pv.data_modifica DESC, pv.nome_pagina",
                [$progettoId]
            );

            jsonResponse([
                'ok' => true,
                'configurations' => $rows,
            ]);
        }

        if ($action === 'load_configuration') {
            $configurationId = (int) ($_GET['configuration_id'] ?? 0);

            $page = $db->fetch(
                "SELECT *
                 FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            $tables = $db->fetchAll(
                "SELECT
                    pvt.id,
                    pvt.IDtabella,
                    pvt.tipo_tabella,
                    pvt.alias_sql,
                    pvt.IDforeign_key,
                    pvt.tipo_join,
                    pvt.ordine_join,
                    t.nome AS tabella_nome
                 FROM pagine_visualizzazione_tabelle pvt
                 JOIN tabelle t ON t.id = pvt.IDtabella
                 WHERE pvt.IDpagina = ?
                 ORDER BY pvt.ordine_join, pvt.id",
                [$configurationId]
            );

            $fields = $db->fetchAll(
                "SELECT
                    pvc.IDcampo,
                    pvc.ordine,
                    pvc.etichetta,
                    pvc.nome_qualificato,
                    pvc.visibile_tabella,
                    pvc.visibile_scheda,
                    pvc.visibile_modale,
                    pvc.ordinabile,
                    pvc.ricercabile,
                    pvc.allineamento,
                    pvc.formato_visualizzazione,
                    pvc.larghezza_colonna,
                    pvc.larghezza_bootstrap,
                    pvc.filtro_abilitato,
                    pvc.tipo_filtro,
                    pvc.link_pagina_id,
                    pvc.link_parametro,
                    pvc.link_campo_valore,
                    pvc.percorso_base,
                    c.IDtabella,
                    c.nome AS campo_nome,
                    t.nome AS tabella_nome
                 FROM pagine_visualizzazione_campi pvc
                 JOIN campi c ON c.id = pvc.IDcampo
                 JOIN tabelle t ON t.id = c.IDtabella
                 WHERE pvc.IDpagina = ?
                 ORDER BY pvc.ordine, pvc.id",
                [$configurationId]
            );

            $modal = $db->fetch(
                "SELECT *
                 FROM pagine_visualizzazione_modali
                 WHERE IDpagina = ?",
                [$configurationId]
            );

            if ($modal) {
                $modal['enabled'] = true;
                $modal['linked_table_id'] = (int) $modal['IDtabella_collegata'];
                $modal['fk_id'] = (int) $modal['IDforeign_key'];
                $modal['main_field_id'] = (int) $modal['IDcampo_principale'];
                $modal['linked_field_id'] = (int) $modal['IDcampo_collegato'];
                $modal['fields'] = json_decode(
                    (string) ($modal['configurazione_campi'] ?? '[]'),
                    true
                ) ?: [];
            }

            jsonResponse([
                'ok' => true,
                'page' => $page,
                'tables' => $tables,
                'fields' => $fields,
                'modal' => $modal ?: null,
            ]);
        }

        if ($action === 'delete_configuration') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $configurationId = (int) ($payload['configuration_id'] ?? 0);

            if ($configurationId <= 0) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non valida.'
                ], 422);
            }

            $page = $db->fetch(
                "SELECT id, nome_pagina, nome_file, percorso_file
                 FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            /*
             * Controllo preventivo del file. Se esiste ma non è eliminabile,
             * la cancellazione non parte, per evitare configurazioni orfane.
             */
            $storedPath = trim((string) ($page['percorso_file'] ?? ''));
            $safeFilePath = null;

            if ($storedPath !== '') {
                $realPages = realpath($paths['pages']);
                $realFile = realpath($storedPath);

                if ($realPages !== false && $realFile !== false) {
                    $allowedPrefix = rtrim($realPages, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR;

                    if (!str_starts_with($realFile, $allowedPrefix)) {
                        jsonResponse([
                            'ok' => false,
                            'message' => 'Il file generato non appartiene alla cartella pages del progetto.'
                        ], 409);
                    }

                    if (is_file($realFile) && !is_writable($realFile)) {
                        jsonResponse([
                            'ok' => false,
                            'message' => 'Il file PHP generato non è eliminabile: controllare i permessi.'
                        ], 409);
                    }

                    $safeFilePath = $realFile;
                } elseif (is_file($storedPath)) {
                    jsonResponse([
                        'ok' => false,
                        'message' => 'Il percorso del file generato non è considerato sicuro.'
                    ], 409);
                }
            }

            $db->beginTransaction();

            try {
                /*
                 * Cancellazione esplicita di tutti i dati collegati.
                 * Non dipende dalla presenza di ON DELETE CASCADE.
                 */
                $deletedModal = $db->execute(
                    "DELETE FROM pagine_visualizzazione_modali
                     WHERE IDpagina = ?",
                    [$configurationId]
                );

                $deletedFields = $db->execute(
                    "DELETE FROM pagine_visualizzazione_campi
                     WHERE IDpagina = ?",
                    [$configurationId]
                );

                $deletedTables = $db->execute(
                    "DELETE FROM pagine_visualizzazione_tabelle
                     WHERE IDpagina = ?",
                    [$configurationId]
                );

                /*
                 * I collegamenti provenienti da altre pagine vengono
                 * disattivati prima di eliminare la pagina destinazione.
                 */
                $removedLinks = $db->execute(
                    "UPDATE pagine_visualizzazione_campi
                     SET link_pagina_id = NULL,
                         link_parametro = NULL,
                         link_campo_valore = NULL
                     WHERE link_pagina_id = ?",
                    [$configurationId]
                );

                $deletedPage = $db->execute(
                    "DELETE FROM pagine_visualizzazione
                     WHERE id = ? AND IDprogetto = ?",
                    [$configurationId, $progettoId]
                );

                if (!$deletedPage) {
                    throw new RuntimeException('La pagina non è stata eliminata.');
                }

                $fileDeleted = false;
                if ($safeFilePath !== null && is_file($safeFilePath)) {
                    if (!unlink($safeFilePath)) {
                        throw new RuntimeException(
                            'Impossibile eliminare il file PHP generato. Nessun dato è stato cancellato.'
                        );
                    }
                    $fileDeleted = true;
                }

                $db->commit();

                jsonResponse([
                    'ok' => true,
                    'message' => 'Pagina, dati collegati e file PHP eliminati correttamente.',
                    'file_deleted' => $fileDeleted,
                    'deleted' => [
                        'modal' => (int) $deletedModal,
                        'fields' => (int) $deletedFields,
                        'tables' => (int) $deletedTables,
                        'incoming_links' => (int) $removedLinks,
                    ],
                ]);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }

        if ($action === 'rename_configuration') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $configurationId = (int) ($payload['configuration_id'] ?? 0);
            $newPageName = trim((string) ($payload['new_page_name'] ?? ''));
            $newFileName = sanitizeFileName((string) ($payload['new_file_name'] ?? ''));

            if ($configurationId <= 0 || $newPageName === '') {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Indicare un nome pagina valido.'
                ], 422);
            }

            if ($newFileName === '') {
                $newFileName = sanitizeFileName($newPageName);
            }

            $page = $db->fetch(
                "SELECT id, nome_pagina, nome_file, percorso_file
                 FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            $duplicate = (int) $db->fetchColumn(
                "SELECT COUNT(*)
                 FROM pagine_visualizzazione
                 WHERE IDprogetto = ?
                   AND id <> ?
                   AND (LOWER(nome_pagina) = LOWER(?) OR LOWER(nome_file) = LOWER(?))",
                [$progettoId, $configurationId, $newPageName, $newFileName]
            );

            if ($duplicate > 0) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Esiste già una pagina con lo stesso nome o nome file.'
                ], 409);
            }

            $oldFileName = (string) $page['nome_file'];
            $oldStoredPath = trim((string) ($page['percorso_file'] ?? ''));
            $newPath = rtrim($paths['pages'], DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . $newFileName;

            if (
                $oldFileName !== $newFileName
                && is_file($newPath)
            ) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'Nella cartella pages esiste già il file ' . $newFileName . '.'
                ], 409);
            }

            $oldSafePath = null;
            if ($oldStoredPath !== '') {
                $realPages = realpath($paths['pages']);
                $realOldFile = realpath($oldStoredPath);

                if ($realPages !== false && $realOldFile !== false) {
                    $allowedPrefix = rtrim($realPages, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR;

                    if (!str_starts_with($realOldFile, $allowedPrefix)) {
                        jsonResponse([
                            'ok' => false,
                            'message' => 'Il file attuale non appartiene alla cartella pages del progetto.'
                        ], 409);
                    }

                    $oldSafePath = $realOldFile;
                }
            }

            /*
             * Individua prima tutti i file generati che collegano questa pagina.
             * Il loro contenuto contiene il nome file risolto in fase di generazione.
             */
            $referencingPages = $db->fetchAll(
                "SELECT DISTINCT pv.id, pv.percorso_file
                 FROM pagine_visualizzazione_campi pvc
                 JOIN pagine_visualizzazione pv ON pv.id = pvc.IDpagina
                 WHERE pvc.link_pagina_id = ?
                   AND pv.IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            $modifiedLinkFiles = [];
            $renamedPhysicalFile = false;

            $db->beginTransaction();

            try {
                if (
                    $oldSafePath !== null
                    && is_file($oldSafePath)
                    && $oldFileName !== $newFileName
                ) {
                    if (!rename($oldSafePath, $newPath)) {
                        throw new RuntimeException('Impossibile rinominare il file PHP generato.');
                    }
                    $renamedPhysicalFile = true;
                }

                foreach ($referencingPages as $reference) {
                    $referencePath = trim((string) ($reference['percorso_file'] ?? ''));
                    if ($referencePath === '' || !is_file($referencePath)) {
                        continue;
                    }

                    $content = file_get_contents($referencePath);
                    if ($content === false) {
                        throw new RuntimeException(
                            'Impossibile leggere un file che contiene collegamenti alla pagina.'
                        );
                    }

                    $updatedContent = str_replace(
                        [
                            var_export($oldFileName, true),
                            '"' . $oldFileName . '"',
                            "'" . $oldFileName . "'",
                        ],
                        [
                            var_export($newFileName, true),
                            '"' . $newFileName . '"',
                            "'" . $newFileName . "'",
                        ],
                        $content
                    );

                    if ($updatedContent !== $content) {
                        if (file_put_contents($referencePath, $updatedContent, LOCK_EX) === false) {
                            throw new RuntimeException(
                                'Impossibile aggiornare un file che collega la pagina rinominata.'
                            );
                        }
                        $modifiedLinkFiles[] = $referencePath;
                    }
                }

                $db->execute(
                    "UPDATE pagine_visualizzazione
                     SET nome_pagina = ?,
                         nome_file = ?,
                         percorso_file = ?,
                         data_modifica = CURRENT_TIMESTAMP
                     WHERE id = ? AND IDprogetto = ?",
                    [
                        $newPageName,
                        $newFileName,
                        $oldSafePath !== null ? $newPath : $oldStoredPath,
                        $configurationId,
                        $progettoId,
                    ]
                );

                $db->commit();

                jsonResponse([
                    'ok' => true,
                    'message' => 'Pagina rinominata e collegamenti aggiornati correttamente.',
                    'new_page_name' => $newPageName,
                    'new_file_name' => $newFileName,
                    'file_renamed' => $renamedPhysicalFile,
                    'updated_link_files' => count($modifiedLinkFiles),
                ]);
            } catch (Throwable $e) {
                $db->rollBack();

                /*
                 * Ripristino del nome fisico in caso di errore successivo.
                 */
                if (
                    $renamedPhysicalFile
                    && is_file($newPath)
                    && !is_file((string) $oldSafePath)
                ) {
                    @rename($newPath, (string) $oldSafePath);
                }

                /*
                 * Ripristina nei file già modificati il vecchio collegamento.
                 */
                foreach ($modifiedLinkFiles as $modifiedPath) {
                    if (!is_file($modifiedPath)) continue;
                    $content = file_get_contents($modifiedPath);
                    if ($content === false) continue;
                    $content = str_replace($newFileName, $oldFileName, $content);
                    @file_put_contents($modifiedPath, $content, LOCK_EX);
                }

                throw $e;
            }
        }

        if ($action === 'table_details') {
            $tableId = (int) ($_GET['table_id'] ?? 0);
            $table = loadTable($db, $progettoId, $tableId);

            if (!$table) {
                jsonResponse(['ok' => false, 'message' => 'Tabella non valida.'], 404);
            }

            $relations = loadRelations($db, $progettoId, $tableId);
            foreach ($relations as &$relation) {
                $relation['fields'] = loadFields($db, (int) $relation['secondary_table_id']);
            }
            unset($relation);

            jsonResponse([
                'ok' => true,
                'table' => $table,
                'fields' => loadFields($db, $tableId),
                'relations' => $relations,
            ]);
        }

        if ($action === 'preview') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                jsonResponse(['ok' => false, 'message' => 'Dati non validi.'], 400);
            }

            $built = buildSqlPreview(
                $db,
                $progettoId,
                (int) ($payload['main_table_id'] ?? 0),
                (array) ($payload['tables'] ?? []),
                (array) ($payload['fields'] ?? [])
            );

            jsonResponse(['ok' => true, 'sql' => $built['sql']]);
        }

        if ($action === 'save_generate') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                jsonResponse(['ok' => false, 'message' => 'Dati non validi.'], 400);
            }

            $pageName = trim((string) ($payload['page_name'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));
            $title = trim((string) ($payload['title'] ?? $pageName));
            $fileName = sanitizePhpFileName((string) ($payload['file_name'] ?? $pageName));
            $viewType = strtoupper((string) ($payload['view_type'] ?? 'TABELLA_MODALE'));

            if ($pageName === '') {
                jsonResponse(['ok' => false, 'message' => 'Indicare il nome della pagina.'], 422);
            }
            if (!in_array($viewType, ['SCHEDA_SINGOLA', 'TABELLA_MODALE'], true)) {
                $viewType = 'TABELLA_MODALE';
            }

            $selectedFieldsForBuild = (array) ($payload['fields'] ?? []);

            $crudRequested = !empty($payload['crud_enabled']);
            $crudConfiguration = buildCrudConfiguration(
                $db,
                $progettoId,
                (int) ($payload['main_table_id'] ?? 0)
            );

            if ($crudRequested && empty($crudConfiguration['available'])) {
                throw new RuntimeException(
                    'CRUD non disponibile: ' . ($crudConfiguration['reason'] ?? 'configurazione non valida')
                );
            }

            if ($crudRequested) {
                $crudPrimaryId = (int) $crudConfiguration['primary_key']['field_id'];
                $primaryPresent = false;

                foreach ($selectedFieldsForBuild as $selectedField) {
                    if ((int) ($selectedField['field_id'] ?? 0) === $crudPrimaryId) {
                        $primaryPresent = true;
                        break;
                    }
                }

                if (!$primaryPresent) {
                    $selectedFieldsForBuild[] = [
                        'field_id' => $crudPrimaryId,
                        'label' => $crudConfiguration['primary_key']['field_name'],
                        'visible_table' => 0,
                        'visible_card' => 0,
                        'visible_modal' => 0,
                        'searchable' => 0,
                        'sortable' => 0,
                        'technical_hidden' => 1,
                    ];
                }
            }

            $isTableViewType = $typeId === 2;
            $modalEnabled = $isTableViewType || !empty($payload['modal_enabled']);

            $rawModalForBuild = is_array($payload['modal_config'] ?? null)
                ? $payload['modal_config']
                : null;

            if ($modalEnabled && $rawModalForBuild) {
                $technicalMainFieldId = (int) ($rawModalForBuild['main_field_id'] ?? 0);
                $technicalFieldPresent = false;

                foreach ($selectedFieldsForBuild as $selectedField) {
                    if ((int) ($selectedField['field_id'] ?? 0) === $technicalMainFieldId) {
                        $technicalFieldPresent = true;
                        break;
                    }
                }

                if ($technicalMainFieldId > 0 && !$technicalFieldPresent) {
                    $technicalField = $db->fetch(
                        "SELECT c.id, c.IDtabella, c.nome, c.tipo
                         FROM campi c
                         JOIN tabelle t ON t.id = c.IDtabella
                         WHERE c.id = ? AND t.IDprogetto = ?",
                        [$technicalMainFieldId, $progettoId]
                    );

                    if ($technicalField) {
                        $selectedFieldsForBuild[] = [
                            'field_id' => (int) $technicalField['id'],
                            'table_id' => (int) $technicalField['IDtabella'],
                            'label' => $technicalField['nome'],
                            'format' => '',
                            'visible_table' => 0,
                            'visible_card' => 0,
                            'visible_modal' => 0,
                            'searchable' => 0,
                            'sortable' => 0,
                            'technical_hidden' => 1,
                        ];
                    }
                }
            }

            $built = buildSqlPreview(
                $db,
                $progettoId,
                (int) ($payload['main_table_id'] ?? 0),
                (array) ($payload['tables'] ?? []),
                $selectedFieldsForBuild
            );

            if ($crudRequested) {
                $crudConfiguration['primary_key_alias'] = '';
                foreach ($built['fields'] as $builtField) {
                    if (
                        (int) $builtField['field_id']
                        === (int) $crudConfiguration['primary_key']['field_id']
                    ) {
                        $crudConfiguration['primary_key_alias'] = $builtField['output_alias'];
                        break;
                    }
                }

                if ($crudConfiguration['primary_key_alias'] === '') {
                    throw new RuntimeException('Impossibile includere la chiave primaria nella query CRUD.');
                }
            }

            $fieldAliasByQualifiedName = [];
            foreach ($built['fields'] as $builtField) {
                $fieldAliasByQualifiedName[$builtField['qualified_name']] = $builtField['output_alias'];
            }

            foreach ($built['fields'] as &$builtField) {
                $builtField['link_target_file'] = '';
                $builtField['link_value_alias'] = '';

                if (!empty($builtField['link_page_id'])) {
                    $linkedPage = $db->fetch(
                        "SELECT nome_file FROM pagine_visualizzazione
                         WHERE id = ? AND IDprogetto = ?",
                        [$builtField['link_page_id'], $progettoId]
                    );
                    if ($linkedPage) {
                        $builtField['link_target_file'] = $linkedPage['nome_file'];
                        $valueField = $builtField['link_value_field'] ?: $builtField['qualified_name'];
                        $builtField['link_value_alias'] = $fieldAliasByQualifiedName[$valueField] ?? '';
                    }
                }
            }
            unset($builtField);

            $modalConfig = null;

            if ($modalEnabled && is_array($payload['modal_config'] ?? null)) {
                $rawModal = $payload['modal_config'];

                $linkedTable = loadTable(
                    $db,
                    $progettoId,
                    (int) ($rawModal['linked_table_id'] ?? 0)
                );
                if (!$linkedTable) {
                    throw new RuntimeException('Tabella collegata del modale non valida.');
                }

                $mainField = $db->fetch(
                    "SELECT c.id, c.nome, c.tipo
                     FROM campi c
                     JOIN tabelle t ON t.id = c.IDtabella
                     WHERE c.id = ? AND t.IDprogetto = ?",
                    [(int) ($rawModal['main_field_id'] ?? 0), $progettoId]
                );
                $linkedField = $db->fetch(
                    "SELECT c.id, c.nome, c.tipo
                     FROM campi c
                     JOIN tabelle t ON t.id = c.IDtabella
                     WHERE c.id = ? AND c.IDtabella = ? AND t.IDprogetto = ?",
                    [
                        (int) ($rawModal['linked_field_id'] ?? 0),
                        (int) $linkedTable['id'],
                        $progettoId
                    ]
                );

                if (!$mainField || !$linkedField) {
                    throw new RuntimeException('Campi di collegamento del modale non validi.');
                }

                $mainValueAlias = '';
                foreach ($built['fields'] as $field) {
                    if ((int) $field['field_id'] === (int) $mainField['id']) {
                        $mainValueAlias = $field['output_alias'];
                        break;
                    }
                }

                if ($mainValueAlias === '') {
                    throw new RuntimeException(
                        'Impossibile aggiungere il campo tecnico usato per filtrare il modale.'
                    );
                }

                $normalizedModalFields = [];
                foreach ((array) ($rawModal['fields'] ?? []) as $order => $modalField) {
                    $field = $db->fetch(
                        "SELECT c.id, c.nome, c.tipo
                         FROM campi c
                         JOIN tabelle t ON t.id = c.IDtabella
                         WHERE c.id = ? AND c.IDtabella = ? AND t.IDprogetto = ?",
                        [
                            (int) ($modalField['fieldId'] ?? $modalField['field_id'] ?? 0),
                            (int) $linkedTable['id'],
                            $progettoId
                        ]
                    );
                    if (!$field) continue;

                    $normalizedModalFields[] = [
                        'field_id' => (int) $field['id'],
                        'field_name' => $field['nome'],
                        'field_type' => $field['tipo'],
                        'label' => trim((string) ($modalField['label'] ?? $field['nome'])),
                        'format' => (($value = trim((string) ($modalField['format'] ?? ''))) !== '')
                            ? $value
                            : '',
                        'bootstrap_col' => (string) ($modalField['bootstrapCol'] ?? $modalField['bootstrap_col'] ?? '6'),
                        'base_path' => trim((string) ($modalField['basePath'] ?? $modalField['base_path'] ?? '')),
                        'filter_enabled' => !empty($modalField['filterEnabled'] ?? $modalField['filter_enabled']),
                        'filter_type' => (string) ($modalField['filterType'] ?? $modalField['filter_type'] ?? 'TESTO'),
                        'alias' => 'modal_f' . ($order + 1),
                        'order' => $order + 1,
                    ];
                }

                if (!$normalizedModalFields) {
                    throw new RuntimeException('Selezionare almeno un campo per il modale.');
                }

                $modalConfig = [
                    'enabled' => true,
                    'title' => trim((string) ($rawModal['title'] ?? 'Dati collegati')),
                    'view_type' => $viewType === 'SCHEDA_SINGOLA'
                        ? 'TABELLA'
                        : 'SCHEDA_SINGOLA',
                    'linked_table_id' => (int) $linkedTable['id'],
                    'linked_table_name' => $linkedTable['nome'],
                    'fk_id' => (int) ($rawModal['fk_id'] ?? 0),
                    'main_field_id' => (int) $mainField['id'],
                    'main_field_name' => $mainField['nome'],
                    'linked_field_id' => (int) $linkedField['id'],
                    'linked_field_name' => $linkedField['nome'],
                    'main_value_alias' => $mainValueAlias,
                    'fields' => $normalizedModalFields,
                ];
            }

            $configuration = [
                'title' => $title ?: $pageName,
                'description' => $description,
                'view_type' => $viewType,
                'type_id' => $typeId,
                'sql' => $built['sql'],
                'fields' => $built['fields'],
                'rows_per_page' => max(1, min(500, (int) ($payload['rows_per_page'] ?? 25))),
                'search_enabled' => !empty($payload['search_enabled']),
                'sort_enabled' => !empty($payload['sort_enabled']),
                'pagination_enabled' => !empty($payload['pagination_enabled']),
                'modal_enabled' => $modalEnabled,
                'modal_config' => $modalConfig,
                'crud_enabled' => $crudRequested,
                'crud_add' => $crudRequested && !empty($payload['crud_add']),
                'crud_edit' => $crudRequested && !empty($payload['crud_edit']),
                'crud_delete' => $crudRequested && !empty($payload['crud_delete']),
                'crud_config' => $crudConfiguration,
            ];

            if (!is_dir($paths['pages']) && !mkdir($paths['pages'], 0755, true) && !is_dir($paths['pages'])) {
                throw new RuntimeException('Impossibile creare la cartella pages del progetto.');
            }

            $targetPath = $paths['pages'] . DIRECTORY_SEPARATOR . $fileName;
            $generatedCode = repairGeneratedDisplayValueBlock(generatePagePhp($configuration));

            $db->beginTransaction();

            try {
                $existingId = (int) ($payload['configuration_id'] ?? 0);
                if ($existingId <= 0) {
                    $existingId = (int) $db->fetchColumn(
                        "SELECT id
                         FROM pagine_visualizzazione
                         WHERE IDprogetto = ?
                           AND (LOWER(nome_pagina) = LOWER(?) OR LOWER(nome_file) = LOWER(?))
                         ORDER BY id DESC
                         LIMIT 1",
                        [$progettoId, $pageName, $fileName]
                    );
                }

                if ($existingId > 0) {
                    $exists = $db->fetchColumn(
                        'SELECT COUNT(*) FROM pagine_visualizzazione WHERE id = ? AND IDprogetto = ?',
                        [$existingId, $progettoId]
                    );
                    if (!$exists) {
                        throw new RuntimeException('Configurazione da aggiornare non trovata.');
                    }

                    $db->execute(
                        "UPDATE pagine_visualizzazione
                         SET nome_pagina = ?,
                             nome_file = ?,
                             descrizione = ?,
                             IDtabella_principale = ?,
                             tipo_visualizzazione = ?,
                             titolo_pagina = ?,
                             righe_per_pagina = ?,
                             ricerca_abilitata = ?,
                             ordinamento_abilitato = ?,
                             paginazione_abilitata = ?,
                             mostra_dettaglio_modale = ?,
                             crud_abilitato = ?,
                             crud_aggiungi = ?,
                             crud_modifica = ?,
                             crud_cancella = ?,
                             percorso_file = ?,
                             sql_generata = ?,
                             stato = 'GENERATA',
                             data_generazione = CURRENT_TIMESTAMP
                         WHERE id = ? AND IDprogetto = ?",
                        [
                            $pageName,
                            $fileName,
                            $description,
                            (int) $built['main_table']['id'],
                            $viewType,
                            $configuration['title'],
                            $configuration['rows_per_page'],
                            (int) $configuration['search_enabled'],
                            (int) $configuration['sort_enabled'],
                            (int) $configuration['pagination_enabled'],
                            (int) $configuration['modal_enabled'],
                            (int) $configuration['crud_enabled'],
                            (int) $configuration['crud_add'],
                            (int) $configuration['crud_edit'],
                            (int) $configuration['crud_delete'],
                            $targetPath,
                            $built['sql'],
                            $existingId,
                            $progettoId,
                        ]
                    );
                    $pageId = $existingId;

                    $db->execute(
                        'DELETE FROM pagine_visualizzazione_campi WHERE IDpagina = ?',
                        [$pageId]
                    );
                    $db->execute(
                        'DELETE FROM pagine_visualizzazione_tabelle WHERE IDpagina = ?',
                        [$pageId]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione (
                            IDprogetto,
                            nome_pagina,
                            nome_file,
                            descrizione,
                            IDtabella_principale,
                            tipo_visualizzazione,
                            titolo_pagina,
                            righe_per_pagina,
                            ricerca_abilitata,
                            ordinamento_abilitato,
                            paginazione_abilitata,
                            mostra_dettaglio_modale,
                            crud_abilitato,
                            crud_aggiungi,
                            crud_modifica,
                            crud_cancella,
                            percorso_file,
                            sql_generata,
                            stato,
                            data_generazione
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'GENERATA', CURRENT_TIMESTAMP)",
                        [
                            $progettoId,
                            $pageName,
                            $fileName,
                            $description,
                            (int) $built['main_table']['id'],
                            $viewType,
                            $configuration['title'],
                            $configuration['rows_per_page'],
                            (int) $configuration['search_enabled'],
                            (int) $configuration['sort_enabled'],
                            (int) $configuration['pagination_enabled'],
                            (int) $configuration['modal_enabled'],
                            (int) $configuration['crud_enabled'],
                            (int) $configuration['crud_add'],
                            (int) $configuration['crud_edit'],
                            (int) $configuration['crud_delete'],
                            $targetPath,
                            $built['sql'],
                        ]
                    );
                    $pageId = (int) $db->lastInsertId();
                }

                $pageTableIds = [];

                foreach ($built['tables'] as $order => $table) {
                    $tableKey = (int) $table['id'] . ':' . max(0, (int) ($table['fk_id'] ?? 0));
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_tabelle (
                            IDpagina,
                            IDtabella,
                            tipo_tabella,
                            alias_sql,
                            IDforeign_key,
                            tipo_join,
                            ordine_join,
                            selezionata
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                        [
                            $pageId,
                            (int) $table['id'],
                            $table['type'],
                            $table['alias'],
                            $table['fk_id'],
                            $table['join_type'] ?: 'LEFT',
                            $order,
                        ]
                    );
                    $pageTableIds[$tableKey] = (int) $db->lastInsertId();
                }

                foreach ($built['fields'] as $field) {
                    $fieldTableKey = (int) $field['table_id'] . ':' . max(0, (int) ($field['source_fk_id'] ?? 0));
                    $pageTableId = $pageTableIds[$fieldTableKey] ?? 0;
                    if (!$pageTableId) {
                        continue;
                    }

                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_campi (
                            IDpagina,
                            IDpagina_tabella,
                            IDcampo,
                            ordine,
                            etichetta,
                            nome_qualificato,
                            visibile_tabella,
                            visibile_scheda,
                            visibile_modale,
                            ordinabile,
                            ricercabile,
                            allineamento,
                            formato_visualizzazione,
                            larghezza_colonna,
                            larghezza_bootstrap,
                            filtro_abilitato,
                            tipo_filtro,
                            link_pagina_id,
                            link_parametro,
                            link_campo_valore,
                            percorso_base
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $pageId,
                            $pageTableId,
                            (int) $field['field_id'],
                            (int) $field['order'],
                            $field['label'],
                            $field['qualified_name'],
                            (int) $field['visible_table'],
                            (int) $field['visible_card'],
                            (int) $field['visible_modal'],
                            (int) $field['sortable'],
                            (int) $field['searchable'],
                            $field['alignment'],
                            $field['format'],
                            $field['width'] !== '' ? $field['width'] : null,
                            $field['bootstrap_col'],
                            (int) $field['filter_enabled'],
                            $field['filter_type'],
                            $field['link_page_id'] ?: null,
                            $field['link_parameter'] ?: null,
                            $field['link_value_field'] ?: null,
                            $field['base_path'] ?: null,
                        ]
                    );
                }

                $db->execute(
                    "DELETE FROM pagine_visualizzazione_modali WHERE IDpagina = ?",
                    [$pageId]
                );

                if ($configuration['modal_enabled'] && $modalConfig) {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_modali (
                            IDpagina,
                            IDtabella_collegata,
                            IDforeign_key,
                            IDcampo_principale,
                            IDcampo_collegato,
                            titolo_modale,
                            tipo_visualizzazione,
                            configurazione_campi
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $pageId,
                            $modalConfig['linked_table_id'],
                            $modalConfig['fk_id'],
                            $modalConfig['main_field_id'],
                            $modalConfig['linked_field_id'],
                            $modalConfig['title'],
                            $modalConfig['view_type'],
                            json_encode(
                                $modalConfig['fields'],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                        ]
                    );
                }

                $temporaryPath = $targetPath . '.tmp';
                if (file_put_contents($temporaryPath, $generatedCode, LOCK_EX) === false) {
                    throw new RuntimeException('Scrittura del file temporaneo non riuscita.');
                }

                if (!rename($temporaryPath, $targetPath)) {
                    @unlink($temporaryPath);
                    throw new RuntimeException('Sostituzione del file PHP non riuscita.');
                }

                $db->commit();

                jsonResponse([
                    'ok' => true,
                    'message' => 'Configurazione salvata e pagina PHP generata.',
                    'configuration_id' => $pageId,
                    'file_name' => $fileName,
                    'file_path' => $targetPath,
                    'generated_page_version' => $configuration['generated_page_version'] ?? null,
                    'sql' => $built['sql'],
                ]);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }

        jsonResponse(['ok' => false, 'message' => 'Azione non riconosciuta.'], 404);
    } catch (Throwable $e) {
        error_log('[genera_pagina_visualizzazione] ' . $e->getMessage());

        $message = $e->getMessage();
        if (
            str_contains($message, "Unknown column 'pvc.") ||
            str_contains($message, 'larghezza_bootstrap') ||
            str_contains($message, 'filtro_abilitato') ||
            str_contains($message, 'tipo_filtro') ||
            str_contains($message, 'link_pagina_id')
        ) {
            $message =
                'Database metadati non aggiornato. '
                . 'Eseguire una sola volta migrazione_visualizzazione_altervista_v7_3.sql.';
        }

        jsonResponse([
            'ok' => false,
            'message' => $message,
        ], 500);
    }
}

/* =========================
 * RENDER PAGINA
 * ========================= */

/*
 * Il rendering completo deve avvenire dal layout principale.
 * L'accesso diretto è consentito soltanto agli endpoint AJAX gestiti sopra.
 */
if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__)) {
    header('Location: ../index.php?page=genera_pagina_visualizzazione');
    exit;
}

$paths = projectPaths($progettoNome);
$schemaExists = $progettoId > 0 && $progettoNome !== '' && safeIsFile($paths['schema']);

$tables = [];
if ($progettoId > 0) {
    $tables = $db->fetchAll(
        'SELECT id, nome, descrizione
         FROM tabelle
         WHERE IDprogetto = ?
         ORDER BY ordine, nome',
        [$progettoId]
    );
}
?>

<style>
.generator-shell { max-width: 1600px; margin: 0 auto; }
.generator-step { border: 1px solid #dee2e6; border-radius: .75rem; background: #fff; }
.generator-step + .generator-step { margin-top: 1rem; }
.generator-step-header { padding: .9rem 1rem; border-bottom: 1px solid #dee2e6; background: #f8f9fa; border-radius: .75rem .75rem 0 0; }
.generator-step-body { padding: 1rem; }
.field-list { min-height: 180px; max-height: 460px; overflow: auto; }
.field-card { border: 1px solid #dee2e6; border-radius: .5rem; padding: .65rem; background: #fff; cursor: grab; }
.field-card + .field-card { margin-top: .5rem; }
.field-card.dragging { opacity: .45; }
.field-meta { font-size: .75rem; color: #6c757d; }
.selected-field-list { min-height: 180px; max-height: none; overflow: visible; border: 2px dashed #adb5bd; border-radius: .75rem; padding: .75rem; background: #f8f9fa; }
.selected-item { border: 1px solid #ced4da; border-radius: .6rem; background: #fff; padding: .75rem; margin-bottom: .65rem; }
.selected-item.drag-over { border-color: #0d6efd; }
.sql-preview { min-height: 220px; max-height: 420px; overflow: auto; white-space: pre; background: #111827; color: #e5e7eb; border-radius: .65rem; padding: 1rem; font-family: Consolas, monospace; font-size: .82rem; }
.relation-card { border: 1px solid #dee2e6; border-radius: .6rem; padding: .75rem; margin-bottom: .65rem; }
.schema-stop { border-left: 5px solid #dc3545; }
.sticky-summary { position: sticky; top: 1rem; }


/* Layout dinamico - Campi da visualizzare */
#selectedFields{
    height: auto !important;
    max-height: none !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    -webkit-overflow-scrolling: touch;
}

@media (max-width:1200px){
    #selectedFields{
        height: auto !important;
        max-height: none !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
    }
}

@media (max-width:575.98px){
    .generator-shell{
        padding-left: .75rem;
        padding-right: .75rem;
    }

    .generator-step-body{
        padding: .85rem;
    }
}
</style>

<div class="container-fluid py-3 generator-shell">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="mb-1">
                <i class="bi bi-layout-text-window-reverse me-2"></i>
                Generatore pagina di visualizzazione
            </h3>
            <div class="text-muted">
                Progetto:
                <strong><?= htmlspecialchars($progettoNome ?: 'nessun progetto attivo', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>
        <span class="badge <?= $schemaExists ? 'text-bg-success' : 'text-bg-danger' ?> fs-6">
            <?= $schemaExists ? 'schema.sql presente' : 'schema.sql assente' ?>
        </span>
    </div>

    <?php if ($progettoId <= 0 || $progettoNome === ''): ?>
        <div class="alert alert-danger">Nessun progetto attivo selezionato.</div>
    <?php elseif (!$schemaExists): ?>
        <div class="alert alert-danger schema-stop">
            <h5 class="alert-heading">Procedura bloccata</h5>
            <p class="mb-2">
                Il file <code>schema.sql</code> non è stato individuato nei percorsi verificati:
            </p>
            <ul class="mb-3">
                <?php foreach ($paths['candidates'] as $candidate): ?>
                    <li>
                        <code><?= htmlspecialchars(
                            $candidate . DIRECTORY_SEPARATOR . 'schema.sql',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></code>
                    </li>
                <?php endforeach; ?>
            </ul>
            <hr>
            <p class="mb-0">
                Se il file esiste, controllare che il progetto attivo in sessione corrisponda alla cartella
                <strong><?= htmlspecialchars($paths['folder'], ENT_QUOTES, 'UTF-8') ?></strong>.
            </p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-12 col-xl-8">
                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>Configurazioni esistenti</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-7">
                                <label for="existingConfiguration" class="form-label">
                                    Pagina già costruita
                                </label>
                                <select id="existingConfiguration" class="form-select">
                                    <option value="">-- nuova pagina --</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button"
                                            class="btn btn-outline-primary"
                                            id="loadConfigurationButton"
                                            disabled>
                                        <i class="bi bi-pencil-square me-1"></i>
                                        Carica per modifica
                                    </button>
                                    <button type="button"
                                            class="btn btn-outline-warning"
                                            id="renameConfigurationButton"
                                            disabled>
                                        <i class="bi bi-input-cursor-text me-1"></i>
                                        Rinomina
                                    </button>
                                    <button type="button"
                                            class="btn btn-outline-danger"
                                            id="deleteConfigurationButton"
                                            disabled>
                                        <i class="bi bi-trash me-1"></i>
                                        Elimina
                                    </button>
                                    <button type="button"
                                            class="btn btn-outline-secondary"
                                            id="newConfigurationButton">
                                        <i class="bi bi-plus-lg me-1"></i>
                                        Nuova
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div id="configurationInfo" class="small text-muted mt-2"></div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>1. Dati della pagina</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="pageName" class="form-label">Nome pagina</label>
                                <input type="text" id="pageName" class="form-control" placeholder="Es. Elenco clienti">
                            </div>
                            <div class="col-md-6">
                                <label for="fileName" class="form-label">Nome file PHP</label>
                                <input type="text" id="fileName" class="form-control" placeholder="elenco_clienti.php">
                            </div>
                            <div class="col-md-8">
                                <label for="pageTitle" class="form-label">Titolo visualizzato</label>
                                <input type="text" id="pageTitle" class="form-control" placeholder="Elenco clienti">
                            </div>
                            <div class="col-12">
                                <label for="pageDescription" class="form-label">Descrizione</label>
                                <textarea id="pageDescription"
                                          class="form-control"
                                          rows="3"
                                          maxlength="2000"
                                          placeholder="Descrizione, finalità o note della pagina"></textarea>
                                <div class="form-text">
                                    Informazione interna associata alla configurazione della pagina.
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="rowsPerPage" class="form-label">Righe per pagina</label>
                                <input type="number" id="rowsPerPage" class="form-control" min="1" max="500" value="25">
                                <div class="form-text" id="rowsPerPageHelp">
                                    Numero di record mostrati nella tabella.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label d-block">Tipo visualizzazione</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="viewType" id="viewTable" value="TABELLA_MODALE" checked>
                                    <label class="form-check-label" for="viewTable">Tabellare con dettaglio modale</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="viewType" id="viewCard" value="SCHEDA_SINGOLA">
                                    <label class="form-check-label" for="viewCard">Scheda singola</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>2. Tabella principale</strong>
                    </div>
                    <div class="generator-step-body">
                        <label for="mainTable" class="form-label">Selezionare la tabella principale</label>
                        <select id="mainTable" class="form-select">
                            <option value="">-- selezionare --</option>
                            <?php foreach ($tables as $table): ?>
                                <option value="<?= (int) $table['id'] ?>">
                                    <?= htmlspecialchars($table['nome'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="tableStatus" class="small text-muted mt-2"></div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>3. Tabelle collegate</strong>
                    </div>
                    <div class="generator-step-body">
                        <div id="relationsContainer" class="text-muted">
                            Selezionare prima la tabella principale.
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>4. Campi disponibili e campi da visualizzare</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <h6>Campi disponibili</h6>
                                <div id="availableFields"></div>
                            </div>
                            <div class="col-lg-6">
                                <h6>Campi da visualizzare</h6>
                                <div id="selectedFields" class="selected-field-list" style="height: auto !important; max-height: none !important; overflow: visible !important;">
                                    <div class="text-muted text-center py-5" id="selectedPlaceholder">
                                        Trascinare qui i campi.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>5. Opzioni generali</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-3">
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="searchEnabled" checked>
                                <label class="form-check-label" for="searchEnabled">Ricerca</label>
                            </div>
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="sortEnabled" checked>
                                <label class="form-check-label" for="sortEnabled">Ordinamento</label>
                            </div>
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="paginationEnabled" checked>
                                <label class="form-check-label" for="paginationEnabled">Paginazione</label>
                            </div>
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="modalEnabled" checked>
                                <label class="form-check-label" for="modalEnabled">Dettaglio modale</label>
                            </div>
                            <div class="col-12"><hr class="my-2"></div>
                            <div class="col-12">
                                <div class="fw-semibold mb-2">Funzioni CRUD sulla tabella principale</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="crudEnabled">
                                        <label class="form-check-label" for="crudEnabled">Abilita CRUD</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input crud-option" type="checkbox" id="crudAdd">
                                        <label class="form-check-label" for="crudAdd">Aggiungi / Inserisci</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input crud-option" type="checkbox" id="crudEdit">
                                        <label class="form-check-label" for="crudEdit">Modifica</label>
                                    </div>
                                    <div class="form-check">
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input crud-option" type="checkbox" id="crudDelete">
                                        <label class="form-check-label" for="crudDelete">Cancella</label>
                                    </div>
                                </div>
                                <div class="form-text">
                                    Il CRUD opera esclusivamente sulla tabella principale.
                                    Le foreign key vengono mostrate come menu a discesa con descrizione leggibile.
                                </div>
                            </div>

                            <div class="col-12 mt-2">
                                <button type="button"
                                        class="btn btn-outline-primary d-none"
                                        id="openModalManagementButton">
                                    <i class="bi bi-window-stack me-1"></i>
                                    Gestisci pannellata modale
                                </button>
                                <div class="small text-muted mt-1" id="modalManagementStatus"></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-4">
                <div class="sticky-summary">
                    <section class="generator-step">
                        <div class="generator-step-header d-flex justify-content-between align-items-center">
                            <strong>Anteprima SQL</strong>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="refreshPreview">
                                Aggiorna
                            </button>
                        </div>
                        <div class="generator-step-body">
                            <pre id="sqlPreview" class="sql-preview">Selezionare tabella e campi.</pre>
                        </div>
                    </section>

                    <section class="generator-step">
                        <div class="generator-step-header">
                            <strong>Generazione</strong>
                        </div>
                        <div class="generator-step-body">
                            <div id="resultMessage"></div>
                            <div class="d-grid gap-2">
                                <button type="button"
                                        class="btn btn-outline-primary fw-bold"
                                        id="pagePreviewButton"
                                        data-bs-toggle="modal"
                                        data-bs-target="#pagePreviewModal">
                                    <i class="bi bi-eye me-2"></i>
                                    Anteprima pagina
                                </button>

                                <button type="button"
                                        class="btn btn-success fw-bold"
                                        id="generateButton">
                                    <i class="bi bi-file-earmark-code me-2"></i>
                                    Salva e genera pagina PHP
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php renderModalManagementPanel(); ?>
<?php renderPagePreviewModal(); ?>

<script>
(() => {
    'use strict';

    /*
     * Quando il progetto non è disponibile o schema.sql non è presente,
     * il modulo di configurazione non viene renderizzato. In tal caso
     * il JavaScript termina senza produrre errori.
     */
    if (!document.getElementById('mainTable')) {
        return;
    }

    const GENERATOR_VERSION = '1.4';
    const AJAX_ENDPOINT = new URL(
        'pages/genera_pagina_visualizzazione.php',
        window.location.href
    ).pathname;
    console.info(`Generatore pagine visualizzazione v${GENERATOR_VERSION}`);

    const state = {
        mainTableId: null,
        mainTableName: '',
        relations: [],
        selectedRelations: new Map(),
        fieldsByTable: new Map(),
        selectedFields: [],
        configurationOptions: [],
        configurationId: null,
        modalConfig: null
    };

    const mainTable = document.getElementById('mainTable');
    const pageName = document.getElementById('pageName');
    const fileName = document.getElementById('fileName');
    const pageTitle = document.getElementById('pageTitle');
    const pageDescription = document.getElementById('pageDescription');
    const relationsContainer = document.getElementById('relationsContainer');
    const availableFields = document.getElementById('availableFields');
    const selectedFields = document.getElementById('selectedFields');
    selectedFields.style.setProperty('height', 'auto', 'important');
    selectedFields.style.setProperty('max-height', 'none', 'important');
    selectedFields.style.setProperty('overflow', 'visible', 'important');
    const sqlPreview = document.getElementById('sqlPreview');
    const resultMessage = document.getElementById('resultMessage');
    const modalEnabled = document.getElementById('modalEnabled');
    const crudEnabled = document.getElementById('crudEnabled');
    const crudAdd = document.getElementById('crudAdd');
    const crudEdit = document.getElementById('crudEdit');
    const crudDelete = document.getElementById('crudDelete');
    const crudOptions = Array.from(document.querySelectorAll('.crud-option'));
    const openModalManagementButton =
        document.getElementById('openModalManagementButton');
    const modalManagementStatus =
        document.getElementById('modalManagementStatus');
    const existingConfiguration = document.getElementById('existingConfiguration');
    const loadConfigurationButton = document.getElementById('loadConfigurationButton');
    const renameConfigurationButton = document.getElementById('renameConfigurationButton');
    const deleteConfigurationButton = document.getElementById('deleteConfigurationButton');
    const newConfigurationButton = document.getElementById('newConfigurationButton');
    const configurationInfo = document.getElementById('configurationInfo');
    const pagePreviewButton = document.getElementById('pagePreviewButton');
    const pagePreviewContent = document.getElementById('pagePreviewContent');
    const pagePreviewWarnings = document.getElementById('pagePreviewWarnings');

    /*
     * Endpoint diretto: evita che index.php aggiunga HTML alle risposte JSON.
     * Il parametro _v impedisce l'uso di una risposta AJAX memorizzata.
     */
    const ajaxEndpoint = AJAX_ENDPOINT;



    function getMainPageModalContext() {
        const mainViewType = document.querySelector(
            'input[name="viewType"]:checked'
        )?.value || 'TABELLA_MODALE';

        return {
            mainViewType,
            relations: state.relations,
            selectedRelationIds: Array.from(state.selectedRelations.keys()).map(Number),
            mainSelectedFieldTableIds: [
                ...new Set(state.selectedFields.map(field => Number(field.tableId)))
            ]
        };
    }

    window.getMainPageModalContext = getMainPageModalContext;

    function canManageModal() {
        if (!modalEnabled.checked) return false;

        return state.selectedFields.some(field =>
            Number(field.tableId) !== Number(state.mainTableId)
            && Array.from(state.selectedRelations.values()).some(
                relation => Number(relation.table_id) === Number(field.tableId)
            )
        );
    }

    function updateModalManagementButton() {
        const available = canManageModal();

        openModalManagementButton.classList.toggle('d-none', !available);
        openModalManagementButton.disabled = !available;

        if (!modalEnabled.checked) {
            modalManagementStatus.textContent = 'Visualizzazione modale disattivata.';
        } else if (!available) {
            modalManagementStatus.textContent =
                'Selezionare almeno un campo di una tabella collegata per configurare il modale.';
        } else if (state.modalConfig?.fields?.length) {
            modalManagementStatus.textContent =
                `Modale configurato: ${state.modalConfig.fields.length} campi.`;
        } else {
            modalManagementStatus.textContent =
                'La pannellata modale può essere configurata.';
        }

        window.ModalPageManager?.syncContext(getMainPageModalContext());
    }

    async function loadConfigurationList(selectedId = null) {
        if (!existingConfiguration) {
            console.error('Elemento #existingConfiguration non trovato.');
            return;
        }

        try {
            if (configurationInfo) {
                configurationInfo.innerHTML =
                    '<span class="text-muted">Caricamento pagine già generate...</span>';
            }

            const data = await request(`${ajaxEndpoint}?action=list_configurations`);
            const rows = data.configurations || [];
            state.configurationOptions = rows;

            existingConfiguration.innerHTML = '<option value="">-- nuova pagina --</option>'
                + rows.map(row => {
                    const label = `${row.nome_pagina} · ${row.nome_file} · ${row.tabella_principale}`;
                    const description = escapeHtml(row.descrizione || '');
                    return `<option value="${Number(row.id)}" title="${description}">${escapeHtml(label)}</option>`;
                }).join('');

            if (selectedId) {
                existingConfiguration.value = String(selectedId);
            }

            updateConfigurationButtons();

            if (configurationInfo && rows.length === 0) {
                configurationInfo.textContent =
                    'Nessuna pagina salvata per il progetto corrente.';
            }
        } catch (error) {
            configurationInfo.innerHTML =
                `<span class="text-danger">${escapeHtml(error.message)}</span>`;
        }
    }

    function updateConfigurationButtons() {
        if (!existingConfiguration) return;

        const selected = Number(existingConfiguration.value || 0);
        loadConfigurationButton.disabled = selected <= 0;
        renameConfigurationButton.disabled = selected <= 0;
        deleteConfigurationButton.disabled = selected <= 0;

        if (selected <= 0) {
            configurationInfo.textContent = 'Creazione di una nuova pagina.';
        }
    }

    function clearPageForm() {
        state.configurationId = null;
        state.mainTableId = null;
        state.mainTableName = '';
        resetConfiguration();

        existingConfiguration.value = '';
        mainTable.value = '';
        pageName.value = '';
        fileName.value = '';
        pageTitle.value = '';
        pageDescription.value = '';
        delete fileName.dataset.manual;
        delete pageTitle.dataset.manual;

        document.getElementById('rowsPerPage').value = '25';
        document.getElementById('searchEnabled').checked = true;
        document.getElementById('sortEnabled').checked = true;
        document.getElementById('paginationEnabled').checked = true;
        modalEnabled.checked = false;
        modalEnabled.disabled = false;
        crudEnabled.checked = false;
        crudAdd.checked = false;
        crudEdit.checked = false;
        crudDelete.checked = false;
        crudOptions.forEach(option => option.disabled = true);
        state.modalConfig = null;
        window.ModalPageManager?.clear(true);
        document.getElementById('viewTable').checked = true;
        document.getElementById('tableStatus').textContent = '';
        updateViewTypeControls();

        updateConfigurationButtons();
        configurationInfo.textContent = 'Creazione di una nuova pagina.';
    }

    async function loadExistingConfiguration(configurationId) {
        const data = await request(
            `${ajaxEndpoint}?action=load_configuration&configuration_id=${encodeURIComponent(configurationId)}`
        );

        const savedPage = data.page;
        state.configurationId = Number(savedPage.id);

        pageName.value = savedPage.nome_pagina || '';
        fileName.value = savedPage.nome_file || '';
        pageTitle.value = savedPage.titolo_pagina || '';
        pageDescription.value = savedPage.descrizione || '';
        fileName.dataset.manual = '1';
        pageTitle.dataset.manual = '1';

        document.getElementById('rowsPerPage').value =
            String(savedPage.righe_per_pagina || 25);
        document.getElementById('searchEnabled').checked =
            Number(savedPage.ricerca_abilitata) === 1;
        document.getElementById('sortEnabled').checked =
            Number(savedPage.ordinamento_abilitato) === 1;
        document.getElementById('paginationEnabled').checked =
            Number(savedPage.paginazione_abilitata) === 1;
        modalEnabled.checked =
            Number(savedPage.mostra_dettaglio_modale) === 1;
        state.modalConfig = data.modal || null;
        crudEnabled.checked = Number(savedPage.crud_abilitato || 0) === 1;
        crudAdd.checked = Number(savedPage.crud_aggiungi || 0) === 1;
        crudEdit.checked = Number(savedPage.crud_modifica || 0) === 1;
        crudDelete.checked = Number(savedPage.crud_cancella || 0) === 1;
        crudOptions.forEach(option => option.disabled = !crudEnabled.checked);

        const isCard = savedPage.tipo_visualizzazione === 'SCHEDA_SINGOLA';
        document.getElementById(isCard ? 'viewCard' : 'viewTable').checked = true;
        updateViewTypeControls();

        mainTable.value = String(savedPage.IDtabella_principale);
        await loadMainTable(Number(savedPage.IDtabella_principale));

        /*
         * Ripristino delle tabelle secondarie tramite le FK memorizzate.
         */
        const savedSecondaryTables = (data.tables || []).filter(
            table => table.tipo_tabella === 'SECONDARIA'
        );

        for (const savedTable of savedSecondaryTables) {
            const relationIndex = state.relations.findIndex(
                relation => Number(relation.fk_id) === Number(savedTable.IDforeign_key)
            );

            if (relationIndex < 0) continue;

            const relation = state.relations[relationIndex];
            state.selectedRelations.set(Number(relation.fk_id), {
                table_id: Number(relation.secondary_table_id),
                table_key: `${Number(relation.secondary_table_id)}:${Number(relation.fk_id)}`,
                fk_id: Number(relation.fk_id),
                join_type: savedTable.tipo_join || 'LEFT'
            });

            state.fieldsByTable.set(`${Number(relation.secondary_table_id)}:${Number(relation.fk_id)}`, {
                tableId: Number(relation.secondary_table_id),
                tableKey: `${Number(relation.secondary_table_id)}:${Number(relation.fk_id)}`,
                tableName: relation.secondary_table_name,
                fields: relation.fields || []
            });
        }

        renderRelations();

        /*
         * Dopo il nuovo rendering delle relazioni vengono sincronizzati
         * checkbox e tipo di JOIN con la configurazione salvata.
         */
        state.relations.forEach((relation, index) => {
            const saved = state.selectedRelations.get(Number(relation.fk_id));
            if (!saved) return;

            const toggle = document.querySelector(
                `.relation-toggle[data-index="${index}"]`
            );
            const join = document.querySelector(
                `.relation-join[data-index="${index}"]`
            );

            if (toggle) toggle.checked = true;
            if (join) {
                join.disabled = false;
                join.value = saved.join_type;
            }
        });

        state.selectedFields = (data.fields || []).map(field => ({
            fieldId: Number(field.IDcampo),
            tableId: Number(field.IDtabella),
            tableName: field.tabella_nome,
            fieldName: field.campo_nome,
            fieldType: field.campo_tipo || field.tipo || '',
            qualifiedName: field.nome_qualificato,
            label: field.etichetta || field.nome_qualificato,
            visibleTable: Number(field.visibile_tabella) === 1,
            visibleCard: Number(field.visibile_scheda) === 1,
            visibleModal: Number(field.visibile_modale) === 1,
            searchable: Number(field.ricercabile) === 1,
            sortable: Number(field.ordinabile) === 1,
            format: resolveFieldFormat({
                format: field.formato_visualizzazione || '',
                customFormat: '',
                fieldType: field.campo_tipo || field.tipo || '',
                tipo: field.campo_tipo || field.tipo || ''
            }),
            customFormat: '',
            alignment: field.allineamento || 'SINISTRA',
            width: field.larghezza_colonna || '',
            bootstrapCol: String(field.larghezza_bootstrap || '6'),
            filterEnabled: Number(field.filtro_abilitato) === 1,
            filterType: field.tipo_filtro || 'TESTO',
            linkPageId: Number(field.link_pagina_id || 0),
            linkParameter: field.link_parametro || '',
            linkValueField: field.link_campo_valore || '',
            basePath: field.percorso_base || ''
        }));

        renderAvailableFields();
        renderSelectedFields();

        window.ModalPageManager?.setConfig(
            state.modalConfig,
            getMainPageModalContext()
        );
        updateModalManagementButton();

        await refreshPreview();

        existingConfiguration.value = String(configurationId);
        updateConfigurationButtons();

        configurationInfo.innerHTML =
            `<span class="text-success">Configurazione caricata: `
            + `<strong>${escapeHtml(savedPage.nome_pagina)}</strong>. `
            + `Il pulsante “Salva e genera” aggiornerà la pagina esistente.</span>`;
    }

    async function deleteExistingConfiguration(configurationId) {
        const option = existingConfiguration.options[
            existingConfiguration.selectedIndex
        ];
        const label = option ? option.textContent : 'la configurazione selezionata';

        const confirmed = window.confirm(
            `Eliminare definitivamente ${label}?\n\n`
            + `Saranno cancellati:\n`
            + `• configurazione della pagina\n`
            + `• campi configurati\n`
            + `• tabelle e relazioni collegate\n`
            + `• configurazione modale\n`
            + `• collegamenti provenienti da altre pagine\n`
            + `• file PHP generato\n\n`
            + `L'operazione non può essere annullata.`
        );

        if (!confirmed) return;

        const data = await request(
            `${ajaxEndpoint}?action=delete_configuration`,
            {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({configuration_id: configurationId})
            }
        );

        clearPageForm();
        await loadConfigurationList();

        resultMessage.innerHTML = data.warning
            ? `<div class="alert alert-warning">${escapeHtml(data.message)}</div>`
            : `<div class="alert alert-success">${escapeHtml(data.message)}</div>`;
    }

    async function renameExistingConfiguration(configurationId) {
        const option = existingConfiguration.options[
            existingConfiguration.selectedIndex
        ];

        if (!option) return;

        const loaded = await request(
            `${ajaxEndpoint}?action=load_configuration&configuration_id=${configurationId}`
        );

        const currentPageName = String(loaded.page?.nome_pagina || '').trim();
        const currentFileName = String(loaded.page?.nome_file || '').trim();

        const newPageName = window.prompt(
            'Nuovo nome della pagina:',
            currentPageName
        );

        if (newPageName === null) return;

        const cleanPageName = newPageName.trim();
        if (!cleanPageName) {
            window.alert('Il nome della pagina non può essere vuoto.');
            return;
        }

        const suggestedFileName = normalizeFileName(cleanPageName);
        const newFileNameInput = window.prompt(
            'Nuovo nome del file PHP:',
            currentFileName || suggestedFileName
        );

        if (newFileNameInput === null) return;

        const newFileName = normalizeFileName(newFileNameInput);

        const confirmed = window.confirm(
            `Confermare la rinomina?\n\n`
            + `Pagina: ${currentPageName} → ${cleanPageName}\n`
            + `File: ${currentFileName} → ${newFileName}\n\n`
            + `Saranno aggiornati anche i collegamenti presenti nelle altre pagine generate.`
        );

        if (!confirmed) return;

        const data = await request(
            `${ajaxEndpoint}?action=rename_configuration`,
            {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    configuration_id: configurationId,
                    new_page_name: cleanPageName,
                    new_file_name: newFileName
                })
            }
        );

        await loadConfigurationList();
        existingConfiguration.value = String(configurationId);
        updateConfigurationButtons();

        if (state.loadedConfigurationId === configurationId) {
            pageName.value = data.new_page_name;
            fileName.value = data.new_file_name;
            pageName.dataset.manual = '1';
            fileName.dataset.manual = '1';
        }

        configurationInfo.innerHTML =
            `<span class="text-success">${escapeHtml(data.message)} `
            + `File collegati aggiornati: <strong>${Number(data.updated_link_files || 0)}</strong>.</span>`;

        resultMessage.innerHTML =
            `<div class="alert alert-success">${escapeHtml(data.message)}</div>`;
    }

    function normalizeFileName(value) {
        let name = String(value || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\.php$/i, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .replace(/_+/g, '_');

        return (name || 'pagina_visualizzazione') + '.php';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function fieldBadges(field) {
        const badges = [];
        badges.push(`<span class="badge text-bg-light">${escapeHtml(field.tipo)}${field.lunghezza ? '(' + escapeHtml(field.lunghezza) + ')' : ''}</span>`);
        badges.push(`<span class="badge ${field.nullable ? 'text-bg-secondary' : 'text-bg-dark'}">${field.nullable ? 'NULL' : 'NOT NULL'}</span>`);
        if (field.auto_increment) badges.push('<span class="badge text-bg-info">AI</span>');
        if (field.is_pk) badges.push('<span class="badge text-bg-warning">PK</span>');
        if (field.is_fk) badges.push('<span class="badge text-bg-primary">FK</span>');
        if (field.is_unique) badges.push('<span class="badge text-bg-success">UK</span>');
        else if (field.is_index) badges.push('<span class="badge text-bg-secondary">IDX</span>');
        return badges.join(' ');
    }

    function parseFieldFormat(formatValue) {
        const format = String(formatValue || '');
        if (format.startsWith('CUSTOM:')) {
            return {
                base: 'PERSONALIZZATO',
                custom: format.slice(7),
            };
        }

        return {
            base: format,
            custom: '',
        };
    }

    function resetConfiguration() {
        state.relations = [];
        state.selectedRelations.clear();
        state.fieldsByTable.clear();
        state.selectedFields = [];
        relationsContainer.innerHTML = '<span class="text-muted">Selezionare prima la tabella principale.</span>';
        availableFields.innerHTML = '';
        renderSelectedFields();
        sqlPreview.textContent = 'Selezionare tabella e campi.';
        resultMessage.innerHTML = '';
    }

    async function request(url, options = {}) {
        const separator = url.includes('?') ? '&' : '?';
        const requestUrl = `${url}${separator}_v=${encodeURIComponent(GENERATOR_VERSION)}&_ts=${Date.now()}`;

        const response = await fetch(requestUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options
        });

        const rawText = await response.text();
        let data;

        try {
            data = JSON.parse(rawText);
        } catch (error) {
            const preview = rawText
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 300);

            throw new Error(
                `Risposta del server non valida (HTTP ${response.status})`
                + (preview ? `: ${preview}` : '.')
            );
        }

        if (!response.ok || !data.ok) {
            throw new Error(
                data.message || `Operazione non riuscita (HTTP ${response.status}).`
            );
        }

        return data;
    }

    async function loadMainTable(tableId) {
        const data = await request(`${ajaxEndpoint}?action=table_details&table_id=${encodeURIComponent(tableId)}`);
        state.mainTableId = Number(data.table.id);
        state.mainTableName = data.table.nome;
        state.relations = data.relations || [];
        state.selectedRelations.clear();
        state.fieldsByTable.clear();
        state.selectedFields = [];
        state.fieldsByTable.set(state.mainTableId, {
            tableId: state.mainTableId,
            tableName: state.mainTableName,
            fields: data.fields || []
        });

        renderRelations();
        renderAvailableFields();
        renderSelectedFields();
        document.getElementById('tableStatus').textContent =
            `${data.fields.length} campi, ${state.relations.length} collegamenti FK diretti.`;
    }

    function renderRelations() {
        if (!state.relations.length) {
            relationsContainer.innerHTML = '<div class="alert alert-secondary mb-0">Nessuna tabella direttamente collegata tramite foreign key.</div>';
            return;
        }

        relationsContainer.innerHTML = state.relations.map((relation, index) => {
            const map = relation.pairs
                .map(pair => `${escapeHtml(pair.local)} → ${escapeHtml(pair.referenced)}`)
                .join(', ');
            const relationTitle = escapeHtml(
                relation.relation_label
                || `${relation.fk_nome || relation.local_field_name || ''}${relation.local_field_descrittivo ? ' - ' + relation.local_field_descrittivo : ''} -> ${relation.secondary_table_name || ''}`
            );

            return `
                <div class="relation-card">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="form-check">
                                <input class="form-check-input relation-toggle"
                                       type="checkbox"
                                       id="relation_${relation.fk_id}_${index}"
                                       data-index="${index}">
                                <label class="form-check-label fw-bold"
                                       for="relation_${relation.fk_id}_${index}">
                                    ${relationTitle}
                                </label>
                            </div>
                            <div class="small text-muted">
                                Campo FK: ${relationTitle}
                            </div>
                            <div class="small text-muted">
                                FK: ${escapeHtml(relation.fk_nome)} ·
                                direzione ${relation.direction === 'OUT' ? 'principale → secondaria' : 'secondaria → principale'}
                            </div>
                            <div class="small"><code>${map}</code></div>
                        </div>
                        <div>
                            <select class="form-select form-select-sm relation-join"
                                    data-index="${index}"
                                    disabled>
                                <option value="LEFT">LEFT JOIN</option>
                                <option value="INNER">INNER JOIN</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        document.querySelectorAll('.relation-toggle').forEach(toggle => {
            toggle.addEventListener('change', event => {
                const index = Number(event.currentTarget.dataset.index);
                const relation = state.relations[index];
                const joinSelect = document.querySelector(`.relation-join[data-index="${index}"]`);
                joinSelect.disabled = !event.currentTarget.checked;

                if (event.currentTarget.checked) {
                    state.selectedRelations.set(relation.fk_id, {
                        table_id: relation.secondary_table_id,
                        table_key: `${relation.secondary_table_id}:${relation.fk_id}`,
                        fk_id: relation.fk_id,
                        join_type: joinSelect.value
                    });
                    state.fieldsByTable.set(`${relation.secondary_table_id}:${relation.fk_id}`, {
                        tableId: Number(relation.secondary_table_id),
                        tableKey: `${relation.secondary_table_id}:${relation.fk_id}`,
                        tableName: relation.secondary_table_name,
                        fields: relation.fields || []
                    });
                } else {
                    state.selectedRelations.delete(relation.fk_id);
                    state.fieldsByTable.delete(`${relation.secondary_table_id}:${relation.fk_id}`);
                    state.selectedFields = state.selectedFields.filter(
                        field => !(Number(field.tableId) === Number(relation.secondary_table_id) && Number(field.fkId) === Number(relation.fk_id))
                    );
                }

                renderAvailableFields();
                renderSelectedFields();
                refreshPreviewDebounced();
            });
        });

        document.querySelectorAll('.relation-join').forEach(select => {
            select.addEventListener('change', event => {
                const index = Number(event.currentTarget.dataset.index);
                const relation = state.relations[index];
                if (state.selectedRelations.has(relation.fk_id)) {
                    state.selectedRelations.set(relation.fk_id, {
                        table_id: relation.secondary_table_id,
                        table_key: `${relation.secondary_table_id}:${relation.fk_id}`,
                        fk_id: relation.fk_id,
                        join_type: event.currentTarget.value
                    });
                    refreshPreviewDebounced();
                }
            });
        });
    }

    function renderAvailableFields() {
        const blocks = [];

        for (const table of state.fieldsByTable.values()) {
            const cards = table.fields.map(field => {
                const selected = state.selectedFields.some(item => Number(item.fieldId) === Number(field.id));
                const tableLabel = Number(table.tableId) === Number(state.mainTableId)
                    ? escapeHtml(table.tableName)
                    : `FK -> ${escapeHtml(table.tableName)}`;
                return `
                    <div class="field-card ${selected ? 'opacity-50' : ''}"
                         draggable="${selected ? 'false' : 'true'}"
                         data-field-id="${field.id}"
                         data-table-id="${table.tableId}">
                        <div class="fw-semibold">
                            ${Number(table.tableId) === Number(state.mainTableId)
                                ? escapeHtml(field.nome)
                                : escapeHtml(table.tableName + '.' + field.nome)}
                        </div>
                        <div class="field-meta mt-1">${fieldBadges(field)}</div>
                        ${field.default_value !== null
                            ? `<div class="field-meta mt-1">Default: ${escapeHtml(field.default_value)}</div>`
                            : ''}
                    </div>
                `;
            }).join('');

            blocks.push(`
                <div class="mb-3">
                    <div class="fw-bold border-bottom pb-1 mb-2">${Number(table.tableId) === Number(state.mainTableId) ? escapeHtml(table.tableName) : `FK -> ${escapeHtml(table.tableName)}`}</div>
                    <div class="field-list">${cards || '<span class="text-muted">Nessun campo.</span>'}</div>
                </div>
            `);
        }

        availableFields.innerHTML = blocks.join('');

        document.querySelectorAll('.field-card[draggable="true"]').forEach(card => {
            card.addEventListener('dragstart', event => {
                card.classList.add('dragging');
                event.dataTransfer.effectAllowed = 'copy';
                event.dataTransfer.setData('text/plain', JSON.stringify({
                    fieldId: Number(card.dataset.fieldId),
                    tableId: Number(card.dataset.tableId)
                }));
            });
            card.addEventListener('dragend', () => card.classList.remove('dragging'));
            card.addEventListener('dblclick', () => addField(
                Number(card.dataset.fieldId),
                Number(card.dataset.tableId)
            ));
        });
    }

    function findField(fieldId, tableId) {
        const table = state.fieldsByTable.get(Number(tableId));
        if (!table) return null;
        const field = table.fields.find(item => Number(item.id) === Number(fieldId));
        return field ? {field, table} : null;
    }

    function addField(fieldId, tableId) {
        if (state.selectedFields.some(item => Number(item.fieldId) === Number(fieldId))) {
            return;
        }

        const found = findField(fieldId, tableId);
        if (!found) return;

        const qualified = Number(tableId) === Number(state.mainTableId)
            ? found.field.nome
            : `${found.table.tableName}.${found.field.nome}`;
        const defaultLabel = String(found.field.nome_descrittivo || '').trim() || qualified;

        state.selectedFields.push({
            fieldId: Number(fieldId),
            tableId: Number(tableId),
            tableName: found.table.tableName,
            fieldName: found.field.nome,
            qualifiedName: qualified,
            label: defaultLabel,
            visibleTable: true,
            visibleCard: true,
            visibleModal: true,
            searchable: !['json', 'text'].includes(String(found.field.tipo).toLowerCase()),
            sortable: !['json', 'text'].includes(String(found.field.tipo).toLowerCase()),
            format: defaultFormatByType(found.field.tipo || ''),
            customFormat: '',
            alignment: ['int', 'float', 'decimal'].includes(String(found.field.tipo).toLowerCase())
                ? 'DESTRA'
                : 'SINISTRA',
            width: '',
            bootstrapCol: '6', filterEnabled: false, filterType: 'TESTO',
            linkPageId: 0, linkParameter: '', linkValueField: '', basePath: ''
        });

        renderAvailableFields();
        renderSelectedFields();
        refreshPreviewDebounced();
    }

    selectedFields.addEventListener('dragover', event => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });

    selectedFields.addEventListener('drop', event => {
        event.preventDefault();
        try {
            const payload = JSON.parse(event.dataTransfer.getData('text/plain'));
            addField(payload.fieldId, payload.tableId);
        } catch (error) {
            console.error(error);
        }
    });

    function renderSelectedFields() {
        if (!state.selectedFields.length) {
            selectedFields.innerHTML = `
                <div class="text-muted text-center py-5" id="selectedPlaceholder">
                    Trascinare qui i campi.
                </div>
            `;
            updateModalManagementButton();
            return;
        }

        updateModalManagementButton();

        selectedFields.innerHTML = state.selectedFields.map((item, index) => {
            const parsedFormat = parseFieldFormat(item.format);
            const typeDefault = defaultFormatByType(item.fieldType || item.tipo || '');
            const formatValue = escapeHtml(item.format || '');
            return `
            <div class="selected-item"
                 draggable="true"
                 data-index="${index}">
                <div class="d-flex justify-content-between gap-2 mb-2">
                    <div>
                        <span class="badge text-bg-secondary me-1">${index + 1}</span>
                        <strong>${escapeHtml(item.qualifiedName)}</strong>
                    </div>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger remove-selected"
                            data-index="${index}">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>

                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label small mb-1">Etichetta</label>
                        <input type="text"
                               class="form-control form-control-sm field-option"
                               data-index="${index}"
                               data-key="label"
                               value="${escapeHtml(item.label)}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Formato</label>
                        <input type="text"
                               class="form-control form-control-sm field-option"
                               data-index="${index}"
                               data-key="format"
                               value="${formatValue}"
                               placeholder="dd/mm/aaaa, hh:mm:ss, #.##0,00, 00000, data unix">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Allineamento</label>
                        <select class="form-select form-select-sm field-option"
                                data-index="${index}"
                                data-key="alignment">
                            ${['SINISTRA','CENTRO','DESTRA']
                                .map(value => `<option value="${value}" ${item.alignment === value ? 'selected' : ''}>${value}</option>`)
                                .join('')}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Larghezza</label>
                        <input type="text"
                               class="form-control form-control-sm field-option"
                               data-index="${index}"
                               data-key="width"
                               value="${escapeHtml(item.width)}"
                               placeholder="es. 180px o 20%">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Layout scheda</label>
                        <select class="form-select form-select-sm field-option" data-index="${index}" data-key="bootstrapCol">
                            ${[['12','Riga intera'],['8','Due terzi'],['6','Metà'],['4','Un terzo'],['3','Un quarto']]
                                .map(([v,l]) => `<option value="${v}" ${item.bootstrapCol === v ? 'selected' : ''}>${l}</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Percorso base file/immagine</label>
                        <input class="form-control form-control-sm field-option" data-index="${index}" data-key="basePath"
                               value="${escapeHtml(item.basePath || '')}" placeholder="uploads/">
                    </div>
                    <div class="col-md-4 pt-4">
                        <div class="form-check">
                            <input class="form-check-input field-check" type="checkbox" data-index="${index}"
                                   data-key="filterEnabled" ${item.filterEnabled ? 'checked' : ''}>
                            <label class="form-check-label small">Filtro avanzato</label>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small mb-1">Tipo filtro</label>
                        <select class="form-select form-select-sm field-option" data-index="${index}" data-key="filterType">
                            ${['TESTO','UGUALE','INTERVALLO_NUMERO','INTERVALLO_DATA','BOOLEANO']
                                .map(v => `<option value="${v}" ${item.filterType === v ? 'selected' : ''}>${v}</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Pagina collegata</label>
                        <select class="form-select form-select-sm field-option" data-index="${index}" data-key="linkPageId">
                            <option value="0">Nessuna</option>
                            ${(state.configurationOptions || []).filter(p => Number(p.id) !== Number(state.configurationId || 0))
                                .map(p => `<option value="${Number(p.id)}" ${Number(item.linkPageId) === Number(p.id) ? 'selected' : ''}>${escapeHtml(p.nome_file)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Parametro URL</label>
                        <input class="form-control form-control-sm field-option" data-index="${index}" data-key="linkParameter"
                               value="${escapeHtml(item.linkParameter || '')}" placeholder="id_cliente">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Campo valore</label>
                        <select class="form-select form-select-sm field-option" data-index="${index}" data-key="linkValueField">
                            <option value="">Campo corrente</option>
                            ${state.selectedFields.map(f => `<option value="${escapeHtml(f.qualifiedName)}" ${item.linkValueField === f.qualifiedName ? 'selected' : ''}>${escapeHtml(f.qualifiedName)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-md-6 d-flex flex-wrap align-items-end gap-3 pb-1">
                        ${[
                            ['visibleTable', 'Tabella'],
                            ['visibleCard', 'Scheda'],
                            ['visibleModal', 'Modale'],
                            ['searchable', 'Ricerca'],
                            ['sortable', 'Ordina']
                        ].map(([key, label]) => `
                            <div class="form-check form-check-inline m-0">
                                <input class="form-check-input field-check"
                                       type="checkbox"
                                       data-index="${index}"
                                       data-key="${key}"
                                       ${item[key] ? 'checked' : ''}>
                                <label class="form-check-label small">${label}</label>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
        `}).join('');

        document.querySelectorAll('.remove-selected').forEach(button => {
            button.addEventListener('click', event => {
                const index = Number(event.currentTarget.dataset.index);
                state.selectedFields.splice(index, 1);
                renderAvailableFields();
                renderSelectedFields();
                refreshPreviewDebounced();
            });
        });

        document.querySelectorAll('.field-option').forEach(input => {
            input.addEventListener('input', event => {
                const index = Number(event.currentTarget.dataset.index);
                const key = event.currentTarget.dataset.key;
                if (key === 'format') {
                    const value = event.currentTarget.value;
                    state.selectedFields[index].format = value;
                    refreshPreviewDebounced();
                    return;
                }

            state.selectedFields[index][key] = event.currentTarget.value;
            refreshPreviewDebounced();
        });
        });

        document.querySelectorAll('.field-check').forEach(input => {
            input.addEventListener('change', event => {
                const index = Number(event.currentTarget.dataset.index);
                const key = event.currentTarget.dataset.key;
                state.selectedFields[index][key] = event.currentTarget.checked;
                refreshPreviewDebounced();
            });
        });

        let draggedIndex = null;
        document.querySelectorAll('.selected-item').forEach(item => {
            item.addEventListener('dragstart', event => {
                draggedIndex = Number(item.dataset.index);
                event.dataTransfer.effectAllowed = 'move';
            });
            item.addEventListener('dragover', event => {
                event.preventDefault();
                item.classList.add('drag-over');
            });
            item.addEventListener('dragleave', () => item.classList.remove('drag-over'));
            item.addEventListener('drop', event => {
                event.preventDefault();
                item.classList.remove('drag-over');
                const targetIndex = Number(item.dataset.index);
                if (draggedIndex === null || draggedIndex === targetIndex) return;
                const [moved] = state.selectedFields.splice(draggedIndex, 1);
                state.selectedFields.splice(targetIndex, 0, moved);
                renderSelectedFields();
                refreshPreviewDebounced();
            });
        });
    }


    function previewSampleValue(item, rowNumber) {
        const resolvedFormat = resolveFieldFormat(item);
        const rawFormat = String(resolvedFormat || '');
        const customFormat = rawFormat.startsWith('CUSTOM:') ? rawFormat.slice(7) : String(item.customFormat || '');
        const format = rawFormat.startsWith('CUSTOM:') ? 'PERSONALIZZATO' : rawFormat.toUpperCase();
        const fieldType = String(item.fieldType || item.tipo || '').toLowerCase();
        const fieldName = String(item.fieldName || '').toLowerCase();

        if (fieldType === 'date') {
            return '1970-01-01';
        }

        if (['datetime', 'timestamp'].includes(fieldType)) {
            return '1970-01-01 00:00:00';
        }

        if (fieldType === 'time') {
            return '00:00:00';
        }

        if (['email', 'mail'].includes(fieldType)) {
            return 'utente@esempio.it';
        }

        if (['url', 'link'].includes(fieldType)) {
            return 'https://esempio.it';
        }

        if (fieldType === 'json') {
            return '<pre class="small mb-0">{&quot;esempio&quot;:true}</pre>';
        }

        if (['boolean', 'bool', 'tinyint'].includes(fieldType)) {
            return '1';
        }

        if (format === 'VALUTA') {
            return `${(1250.50 + rowNumber * 137.25).toLocaleString('it-IT', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            })} €`;
        }

        if (format === 'BOOLEANO') {
            return rowNumber % 2 === 0
                ? '<span class="badge bg-success">Sì</span>'
                : '<span class="badge bg-secondary">No</span>';
        }

        if (format === 'IMMAGINE') return '<div class="border rounded p-3 text-center">Anteprima immagine</div>';
        if (format === 'FILE') return '<span class="btn btn-sm btn-outline-primary">documento.pdf</span>';
        if (format === 'URL') return '<span class="text-primary text-decoration-underline">https://esempio.it</span>';
        if (format === 'EMAIL') return '<span class="text-primary text-decoration-underline">utente@esempio.it</span>';
        if (format === 'JSON') return '<pre class="small mb-0">{&quot;esempio&quot;:true}</pre>';

        if (format === 'PERSONALIZZATO') {
            const mask = customFormat || 'dd/mm/aaaa';
            if (mask === '0') return String(7 + rowNumber);
            if (mask === '0,00') return `${(12.5 + rowNumber).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            if (mask === '#,##0') return (1000 + rowNumber * 100).toLocaleString('it-IT', { maximumFractionDigits: 0 });
            if (mask === '#,##0,00') return (1000.5 + rowNumber * 12.5).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (mask === 'gg/mm/aaaa') return `${String(10 + rowNumber).padStart(2, '0')}/07/2026`;
            if (mask === 'gg-mmm-aaaa') return `${String(10 + rowNumber).padStart(2, '0')}/lug/2026`;
            if (mask === 'aaaa-mm-gg') return `2026-07-${String(10 + rowNumber).padStart(2, '0')}`;
            if (mask === 'gg/mm/aa') return `${String(10 + rowNumber).padStart(2, '0')}/07/26`;
            if (mask === 'hh:mm') return `${String(8 + rowNumber).padStart(2, '0')}:30`;
            if (mask === 'hh:mm:ss') return `${String(8 + rowNumber).padStart(2, '0')}:30:15`;
            if (mask === 'hh:mm AM/PM') return `${String(8 + rowNumber).padStart(2, '0')}:30 ${rowNumber % 2 === 0 ? 'AM' : 'PM'}`;
            if (mask === '0%') return `${10 + rowNumber}%`;
            if (mask === '0,0%') return `${(10 + rowNumber).toLocaleString('it-IT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
            if (mask === '0,00%') return `${(10 + rowNumber).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
            if (/^0+$/.test(mask)) return String(rowNumber).padStart(mask.length, '0');
            return escapeHtml(mask.replace(/data unix/i, String(1700000000 + rowNumber)));
        }

        if (format === 'JSON') {
            return `<pre class="small mb-0">${escapeHtml(JSON.stringify({
                esempio: true,
                riga: rowNumber
            }, null, 2))}</pre>`;
        }

        if (
            fieldName.startsWith('id')
            || fieldName.endsWith('_id')
            || fieldName === 'id'
        ) {
            return String(rowNumber);
        }

        if (fieldName.includes('email')) {
            return `utente${rowNumber}@esempio.it`;
        }

        if (fieldName.includes('telefono') || fieldName.includes('cellulare')) {
            return `333 12345${String(rowNumber).padStart(2, '0')}`;
        }

        if (fieldName.includes('nome')) {
            return ['Mario', 'Anna', 'Luca', 'Giulia'][rowNumber - 1] || `Nome ${rowNumber}`;
        }

        if (fieldName.includes('cognome')) {
            return ['Rossi', 'Bianchi', 'Verdi', 'Neri'][rowNumber - 1] || `Cognome ${rowNumber}`;
        }

        if (fieldName.includes('descrizione') || fieldName.includes('note')) {
            return `Testo dimostrativo della riga ${rowNumber}`;
        }

        return `${item.label || item.qualifiedName} ${rowNumber}`;
    }

    function previewAlignmentClass(alignment) {
        switch (alignment) {
            case 'CENTRO':
                return 'text-center';
            case 'DESTRA':
                return 'text-end';
            default:
                return 'text-start';
        }
    }

    function renderPagePreview() {
        pagePreviewWarnings.innerHTML = '';

        if (!state.mainTableId) {
            pagePreviewContent.innerHTML = `
                <div class="alert alert-warning mb-0">
                    Selezionare la tabella principale.
                </div>
            `;
            return;
        }

        if (!state.selectedFields.length) {
            pagePreviewContent.innerHTML = `
                <div class="alert alert-warning mb-0">
                    Selezionare almeno un campo.
                </div>
            `;
            return;
        }

        const currentPayload = payload();
        const title = currentPayload.title || currentPayload.page_name || 'Titolo pagina';
        const isCard = currentPayload.view_type === 'SCHEDA_SINGOLA';
        const visibleTableFields = state.selectedFields.filter(
            item => item.visibleTable
        );
        const visibleCardFields = state.selectedFields.filter(
            item => item.visibleCard
        );
        const visibleModalFields = state.selectedFields.filter(
            item => item.visibleModal
        );

        const warnings = [];
        if (!isCard && visibleTableFields.length === 0) {
            warnings.push('Nessun campo è visibile nella tabella principale.');
        }
        if (isCard && visibleCardFields.length === 0) {
            warnings.push('Nessun campo è visibile nella scheda.');
        }
        if (
            !isCard
            && currentPayload.modal_enabled
            && visibleModalFields.length === 0
        ) {
            warnings.push('Il dettaglio modale è abilitato ma non contiene campi visibili.');
        }

        if (warnings.length) {
            pagePreviewWarnings.innerHTML = `
                <div class="alert alert-warning">
                    ${warnings.map(warning => `<div>${escapeHtml(warning)}</div>`).join('')}
                </div>
            `;
        }

        let html = `
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h3 class="mb-0">${escapeHtml(title)}</h3>
        `;

        if (currentPayload.search_enabled) {
            html += `
                <div class="d-flex gap-2">
                    <input type="search"
                           class="form-control"
                           value=""
                           placeholder="Cerca..."
                           style="max-width: 260px">
                    <button type="button" class="btn btn-primary">Cerca</button>
                </div>
            `;
        }

        html += `</div>`;

        if (isCard) {
            const rowNumber = 1;

            html += `
                <div class="card shadow-sm">
                    <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <strong>${escapeHtml(title)}</strong>
                        <span class="badge text-bg-secondary">Record 1 di 25</span>
                    </div>

                    <div class="card-body">
                        <div class="row g-3">
            `;

            for (const item of visibleCardFields) {
                html += `
                    <div class="col-12 col-md-${escapeHtml(item.bootstrapCol || '6')}">
                        <div class="border rounded p-3 h-100 bg-light-subtle">
                            <div class="small text-muted mb-1">
                                ${escapeHtml(item.label)}
                            </div>
                            <div class="${previewAlignmentClass(item.alignment)} fw-semibold">
                                ${previewSampleValue(item, rowNumber)}
                            </div>
                        </div>
                    </div>
                `;
            }

            html += `
                        </div>
                    </div>

                    <div class="card-footer bg-white">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-secondary disabled">
                                    <i class="bi bi-skip-backward-fill me-1"></i>
                                    Primo
                                </button>
                                <button type="button" class="btn btn-outline-primary disabled">
                                    <i class="bi bi-chevron-left me-1"></i>
                                    Precedente
                                </button>
                            </div>

                            <div class="small text-muted">
                                Scheda 1 / 25
                            </div>

                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary">
                                    Successivo
                                    <i class="bi bi-chevron-right ms-1"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary">
                                    Ultimo
                                    <i class="bi bi-skip-forward-fill ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else {
            html += `
                <div class="table-responsive border rounded shadow-sm">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
            `;

            for (const item of visibleTableFields) {
                const widthStyle = item.width
                    ? ` style="width:${escapeHtml(item.width)}"`
                    : '';

                html += `
                    <th${widthStyle} class="${previewAlignmentClass(item.alignment)}">
                        ${currentPayload.sort_enabled
                            ? `<span>${escapeHtml(item.label)} <i class="bi bi-arrow-down-up small text-muted"></i></span>`
                            : escapeHtml(item.label)}
                    </th>
                `;
            }

            if (currentPayload.modal_enabled) {
                html += '<th class="text-end">Azioni</th>';
            }

            html += `
                            </tr>
                        </thead>
                        <tbody>
            `;

            for (let rowNumber = 1; rowNumber <= 4; rowNumber++) {
                html += '<tr>';

                for (const item of visibleTableFields) {
                    html += `
                        <td class="${previewAlignmentClass(item.alignment)}">
                            ${previewSampleValue(item, rowNumber)}
                        </td>
                    `;
                }

                if (currentPayload.modal_enabled) {
                    html += `
                        <td class="text-end">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary preview-detail-button"
                                    data-row="${rowNumber}">
                                Dettaglio
                            </button>
                        </td>
                    `;
                }

                html += '</tr>';
            }

            html += `
                        </tbody>
                    </table>
                </div>
            `;
        }

        if (!isCard && currentPayload.pagination_enabled) {
            html += `
                <nav class="mt-3">
                    <ul class="pagination justify-content-center mb-2">
                        <li class="page-item disabled">
                            <span class="page-link">Precedente</span>
                        </li>
                        <li class="page-item active">
                            <span class="page-link">1</span>
                        </li>
                        <li class="page-item">
                            <span class="page-link">2</span>
                        </li>
                        <li class="page-item">
                            <span class="page-link">3</span>
                        </li>
                        <li class="page-item">
                            <span class="page-link">Successiva</span>
                        </li>
                    </ul>
                </nav>
            `;
        }

        html += `
            <div class="small text-muted mt-2">
                Record trovati: 25
            </div>
        `;

        /*
         * Modale dettaglio interno all'anteprima.
         * Non usa un secondo Bootstrap modal per evitare modali sovrapposti:
         * il contenuto viene mostrato direttamente in un riquadro.
         */
        if (currentPayload.modal_enabled) {
            html += `
                <div id="previewRecordDetail"
                     class="border rounded bg-light p-3 mt-3 d-none">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Dettaglio record</h5>
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                id="closePreviewDetail">
                            Chiudi dettaglio
                        </button>
                    </div>
                    <div class="table-responsive"><table class="table table-bordered table-striped mb-0"><tbody id="previewRecordDetailContent"></tbody></table></div>
                </div>
            `;
        }

        pagePreviewContent.innerHTML = html;

        document.querySelectorAll('.preview-detail-button').forEach(button => {
            button.addEventListener('click', event => {
                const rowNumber = Number(event.currentTarget.dataset.row || 1);
                const detailBox = document.getElementById('previewRecordDetail');
                const detailContent = document.getElementById(
                    'previewRecordDetailContent'
                );

                if (!detailBox || !detailContent) return;

                detailContent.innerHTML = visibleModalFields.map(item => `
                    <tr><th style="width:35%">${escapeHtml(item.label)}</th>
                    <td>${previewSampleValue(item, rowNumber)}</td></tr>
                `).join('');

                detailBox.classList.remove('d-none');
                detailBox.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            });
        });

        const closeDetail = document.getElementById('closePreviewDetail');
        if (closeDetail) {
            closeDetail.addEventListener('click', () => {
                document.getElementById('previewRecordDetail')
                    ?.classList.add('d-none');
            });
        }
    }

    function payload() {
        return {
            configuration_id: state.configurationId,
            page_name: pageName.value.trim(),
            file_name: fileName.value.trim(),
            title: pageTitle.value.trim(),
            description: pageDescription.value.trim(),
            main_table_id: state.mainTableId,
            view_type: document.querySelector('input[name="viewType"]:checked').value,
            rows_per_page: Number(document.getElementById('rowsPerPage').value || 25),
            search_enabled: document.getElementById('searchEnabled').checked,
            sort_enabled: document.getElementById('sortEnabled').checked,
            pagination_enabled: document.getElementById('paginationEnabled').checked,
            modal_enabled: modalEnabled.checked,
            crud_enabled: crudEnabled.checked,
            crud_add: crudEnabled.checked && crudAdd.checked,
            crud_edit: crudEnabled.checked && crudEdit.checked,
            crud_delete: crudEnabled.checked && crudDelete.checked,
            modal_config: modalEnabled.checked
                ? (window.ModalPageManager?.getConfig() || state.modalConfig)
                : null,
            tables: Array.from(state.selectedRelations.values()),
            fields: state.selectedFields.map(item => ({
                field_id: item.fieldId,
                label: item.label,
                visible_table: item.visibleTable,
                visible_card: item.visibleCard,
                visible_modal: item.visibleModal,
                searchable: item.searchable,
                sortable: item.sortable,
                format: item.format,
                alignment: item.alignment,
                width: item.width,
                bootstrap_col: item.bootstrapCol,
                filter_enabled: item.filterEnabled,
                filter_type: item.filterType,
                link_page_id: Number(item.linkPageId || 0),
                link_parameter: item.linkParameter,
                link_value_field: item.linkValueField || item.qualifiedName,
                base_path: item.basePath
            }))
        };
    }

    let previewTimer = null;
    function refreshPreviewDebounced() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 300);
    }

    async function refreshPreview() {
        if (!state.mainTableId || !state.selectedFields.length) {
            sqlPreview.textContent = 'Selezionare tabella e campi.';
            return;
        }

        sqlPreview.textContent = 'Generazione anteprima...';

        try {
            const data = await request(`${ajaxEndpoint}?action=preview`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload())
            });
            sqlPreview.textContent = data.sql;
        } catch (error) {
            sqlPreview.textContent = error.message;
        }
    }


    existingConfiguration?.addEventListener('change', () => {
        updateConfigurationButtons();

        const selected = Number(existingConfiguration.value || 0);
        if (selected > 0) {
            const option = existingConfiguration.options[
                existingConfiguration.selectedIndex
            ];
            configurationInfo.textContent =
                `Selezionata: ${option ? option.textContent : selected}`;
        }
    });

    loadConfigurationButton?.addEventListener('click', async () => {
        const configurationId = Number(existingConfiguration.value || 0);
        if (!configurationId) return;

        if (
            state.configurationId !== configurationId
            && (state.selectedFields.length > 0 || pageName.value.trim() !== '')
        ) {
            const confirmed = window.confirm(
                'Il caricamento sostituirà la configurazione attualmente presente nella pagina. Continuare?'
            );
            if (!confirmed) return;
        }

        loadConfigurationButton.disabled = true;
        loadConfigurationButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1"></span>Caricamento';

        try {
            await loadExistingConfiguration(configurationId);
        } catch (error) {
            resultMessage.innerHTML =
                `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        } finally {
            loadConfigurationButton.disabled = false;
            loadConfigurationButton.innerHTML =
                '<i class="bi bi-pencil-square me-1"></i>Carica per modifica';
        }
    });

    renameConfigurationButton?.addEventListener('click', async () => {
        const configurationId = Number(existingConfiguration.value || 0);
        if (configurationId <= 0) return;

        renameConfigurationButton.disabled = true;

        try {
            await renameExistingConfiguration(configurationId);
        } catch (error) {
            resultMessage.innerHTML =
                `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        } finally {
            updateConfigurationButtons();
        }
    });

    deleteConfigurationButton?.addEventListener('click', async () => {
        const configurationId = Number(existingConfiguration.value || 0);
        if (!configurationId) return;

        deleteConfigurationButton.disabled = true;

        try {
            await deleteExistingConfiguration(configurationId);
        } catch (error) {
            resultMessage.innerHTML =
                `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        } finally {
            updateConfigurationButtons();
        }
    });

    newConfigurationButton?.addEventListener('click', () => {
        if (
            state.selectedFields.length > 0
            || pageName.value.trim() !== ''
            || state.configurationId
        ) {
            const confirmed = window.confirm(
                'Azzerare la configurazione corrente e creare una nuova pagina?'
            );
            if (!confirmed) return;
        }

        clearPageForm();
    });

    if (mainTable) {
        mainTable.addEventListener('change', async () => {
            /*
             * Si usa direttamente la costante mainTable. Non viene più letto
             * event.currentTarget, che può diventare null nelle operazioni async.
             */
            const newId = Number(mainTable.value || 0);
            const previousId = state.mainTableId;

            if (previousId && newId !== previousId) {
                const confirmed = window.confirm(
                    'La modifica della tabella principale azzererà tabelle collegate e campi selezionati. Continuare?'
                );

                if (!confirmed) {
                    mainTable.value = String(previousId);
                    return;
                }
            }

            resetConfiguration();

            if (!newId) {
                state.mainTableId = null;
                state.mainTableName = '';
                return;
            }

            mainTable.disabled = true;

            try {
                await loadMainTable(newId);
            } catch (error) {
                console.error('Errore caricamento tabella:', error);
                alert(error.message);
                mainTable.value = previousId ? String(previousId) : '';
                state.mainTableId = previousId || null;

                if (!previousId) {
                    state.mainTableName = '';
                    resetConfiguration();
                }
            } finally {
                mainTable.disabled = false;
            }
        });
    }

    pageName.addEventListener('input', () => {
        if (!fileName.dataset.manual) {
            fileName.value = normalizeFileName(pageName.value);
        }
        if (!pageTitle.dataset.manual) {
            pageTitle.value = pageName.value;
        }
    });

    pageName.addEventListener('blur', () => {
        const cleanPageName = sanitizePageName(pageName.value);
        if (!cleanPageName) return;

        const normalizedPageName = cleanPageName.toLowerCase();
        pageName.value = normalizedPageName;
        fileName.value = normalizeFileName(normalizedPageName);
        pageTitle.value = `Scheda: ${toTitleCase(normalizedPageName)}`;
    });

    fileName.addEventListener('input', () => {
        fileName.dataset.manual = '1';
    });

    fileName.addEventListener('blur', () => {
        fileName.value = normalizeFileName(fileName.value || pageName.value);
    });

    pageTitle.addEventListener('input', () => {
        pageTitle.dataset.manual = '1';
    });

    function toTitleCase(value) {
        return String(value || '')
            .toLowerCase()
            .split(/\s+/)
            .filter(Boolean)
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }

    function sanitizePageName(value) {
        return String(value || '')
            .trim()
            .replace(/[^\p{L}\p{N}\s]/gu, '')
            .replace(/\s+/g, ' ');
    }

    function updateViewTypeControls() {
        const selectedType = document.querySelector(
            'input[name="viewType"]:checked'
        )?.value || 'TABELLA_MODALE';

        const tableMode = selectedType === 'TABELLA_MODALE';
        const rowsInput = document.getElementById('rowsPerPage');
        const rowsHelp = document.getElementById('rowsPerPageHelp');

        modalEnabled.disabled = false;
        rowsInput.disabled = !tableMode;
        rowsHelp.textContent = tableMode
            ? 'Numero di record mostrati in ogni pagina della tabella.'
            : 'La scheda singola mostra sempre un solo record per pagina.';
    }


    crudEnabled.addEventListener('change', () => {
        crudOptions.forEach(option => {
            option.disabled = !crudEnabled.checked;
        });

        if (!crudEnabled.checked) {
            crudAdd.checked = false;
            crudEdit.checked = false;
            crudDelete.checked = false;
        }
    });

    modalEnabled.addEventListener('change', () => {
        if (!modalEnabled.checked) {
            const hasData =
                Boolean(state.modalConfig)
                || Boolean(window.ModalPageManager?.hasSelectedFields());

            if (
                hasData
                && !window.confirm(
                    'Disattivando il modale verranno cancellati tutti i dati della pannellata secondaria. Continuare?'
                )
            ) {
                modalEnabled.checked = true;
                return;
            }

            state.modalConfig = null;
            window.ModalPageManager?.clear(true);
        }

        updateModalManagementButton();
        refreshPreviewDebounced();
    });

    openModalManagementButton.addEventListener('click', () => {
        if (!canManageModal()) return;
        window.ModalPageManager?.open(getMainPageModalContext());
    });

    window.addEventListener('modal-config-changed', event => {
        state.modalConfig = event.detail;
        updateModalManagementButton();
    });

    document.querySelectorAll('input[name="viewType"]').forEach(input => {
        input.addEventListener('change', () => {
            updateViewTypeControls();
            updateModalManagementButton();
            renderPagePreview();
        });
    });

    pagePreviewButton.addEventListener('click', renderPagePreview);

    document.getElementById('pagePreviewModal').addEventListener(
        'show.bs.modal',
        renderPagePreview
    );

    document.getElementById('refreshPreview').addEventListener('click', refreshPreview);

    document.getElementById('generateButton').addEventListener('click', async () => {
        resultMessage.innerHTML = '';

        if (!pageName.value.trim()) {
            pageName.focus();
            resultMessage.innerHTML = '<div class="alert alert-danger">Indicare il nome della pagina.</div>';
            return;
        }
        if (!state.mainTableId) {
            resultMessage.innerHTML = '<div class="alert alert-danger">Selezionare la tabella principale.</div>';
            return;
        }
        if (!state.selectedFields.length) {
            resultMessage.innerHTML = '<div class="alert alert-danger">Selezionare almeno un campo.</div>';
            return;
        }

        fileName.value = normalizeFileName(fileName.value || pageName.value);

        const button = document.getElementById('generateButton');
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generazione...';

        try {
            const data = await request(`${ajaxEndpoint}?action=save_generate`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload())
            });

            state.configurationId = data.configuration_id;
            sqlPreview.textContent = data.sql;
            await loadConfigurationList(data.configuration_id);
            configurationInfo.innerHTML =
                `<span class="text-success">Configurazione salvata e pronta per successive modifiche.</span>`;
            resultMessage.innerHTML = `
                <div class="alert alert-success">
                    <strong>Salvataggio completato</strong><br>
                    Nome scheda: <code>${escapeHtml(data.file_name || pageName.value || '')}</code><br>
                    Versione: <code>${escapeHtml(data.generated_page_version || data.page_version || data.version || '')}</code><br>
                    Percorso: <code>${escapeHtml(data.file_path || '')}</code>
                </div>
            `;
        } catch (error) {
            resultMessage.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-file-earmark-code me-2"></i>Salva e genera pagina PHP';
        }
    });

    updateViewTypeControls();
    updateModalManagementButton();
    await loadConfigurationList(<?= (int) $initialConfigurationId ?>);
    if (<?= (int) $initialConfigurationId ?> > 0) {
        try {
            await loadExistingConfiguration(<?= (int) $initialConfigurationId ?>);
        } catch (error) {
            configurationInfo.innerHTML = `<span class="text-danger">${escapeHtml(error.message)}</span>`;
        }
    }
})();
</script>
