<?php
/**
 * creatore_pagina_fk.php
 *
 * Funzioni di supporto per la gestione dei record collegati nella
 * pagina generatore CRUD.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Accesso diretto non consentito.');
}

function creatorePaginaLoadTableColumns(Database $db, string $tableName): array
{
    try {
        return $db->fetchAll('SHOW FULL COLUMNS FROM ' . crudQuote($tableName));
    } catch (Throwable $primaryError) {
        try {
            return $db->fetchAll(
                'SELECT
                    COLUMN_NAME AS Field,
                    COLUMN_TYPE AS Type,
                    IS_NULLABLE AS `Null`,
                    COLUMN_DEFAULT AS `Default`,
                    EXTRA AS Extra
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$tableName]
            );
        } catch (Throwable $fallbackError) {
            throw new RuntimeException(
                'Impossibile leggere la struttura della tabella collegata: ' . $fallbackError->getMessage(),
                0,
                $primaryError
            );
        }
    }
}

function creatorePaginaBuildRelatedSchemaFields(Database $db, string $fkTable, string $relatedValue = '', array $relatedRecord = []): array
{
    $rows = creatorePaginaLoadTableColumns($db, $fkTable);
    $fields = [];

    foreach ($rows as $row) {
        $name = (string) ($row['Field'] ?? '');
        $extra = strtolower((string) ($row['Extra'] ?? ''));
        if ($name === '' || str_contains($extra, 'auto_increment')) {
            continue;
        }

        $isNullable = strtoupper((string) ($row['Null'] ?? '')) === 'YES';
        $default = $row['Default'] ?? null;
        $required = !$isNullable && $default === null;
        if (!$required) {
            continue;
        }

        $type = strtolower((string) ($row['Type'] ?? 'text'));
        $inputType = match (true) {
            str_contains($type, 'int') => 'number',
            str_contains($type, 'date') && !str_contains($type, 'time') => 'date',
            str_contains($type, 'datetime') || str_contains($type, 'timestamp') => 'datetime-local',
            str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double') => 'number',
            default => 'text',
        };

        $fields[] = [
            'name' => $name,
            'label' => $name,
            'input_type' => $inputType,
            'required' => $relatedValue === '',
            'value' => $relatedRecord[$name] ?? null,
        ];
    }

    return $fields;
}

function creatorePaginaNormalizeRelatedPayload(array $related): array
{
    return is_array($related) ? $related : [];
}

function creatorePaginaRenderRelatedSchemaPayload(
    Database $db,
    string $crudCsrf,
    string $fkTable,
    string $crudAction,
    string $relatedValue = '',
    string $fkValueField = ''
): void {
    if (!hash_equals($crudCsrf, (string) ($_GET['csrf'] ?? $crudCsrf))) {
        throw new RuntimeException('Sessione scaduta. Ricaricare la pagina.');
    }

    if ($fkTable === '') {
        throw new RuntimeException('Tabella collegata non valida.');
    }

    $relatedRecord = [];
    if ($crudAction === 'related_record') {
        $fkValueField = trim($fkValueField);
        if ($fkValueField === '') {
            throw new RuntimeException('Campo chiave della tabella collegata non valido.');
        }
        if ($relatedValue === '') {
            throw new RuntimeException('Selezionare un record collegato da modificare.');
        }

        $relatedRecord = $db->fetch(
            'SELECT * FROM ' . crudQuote($fkTable)
            . ' WHERE ' . crudQuote($fkValueField) . ' = ?',
            [$relatedValue]
        ) ?: [];
    }

    pannellateJsonResponse([
        'ok' => true,
        'fields' => creatorePaginaBuildRelatedSchemaFields($db, $fkTable, $relatedValue, $relatedRecord),
        'record_value' => $relatedValue,
    ]);
}

function creatorePaginaHandleRelatedCrudPost(
    Database $db,
    string $crudCsrf,
    string $crudAction,
    array $crudConfig,
    array $modalCrudConfig,
    array $modalConfig,
    bool $modalCrudAdd,
    bool $modalCrudEdit,
    bool $modalCrudDelete,
    string $postedCrudAction,
    array $posted,
    array $query = []
): void {
    if (!hash_equals($crudCsrf, (string) ($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Sessione scaduta. Ricaricare la pagina.');
    }

    $action = $postedCrudAction;
    $tableName = (string) ($crudConfig['table_name'] ?? '');
    $pkName = (string) ($crudConfig['primary_key']['field_name'] ?? '');

    if ($action === 'delete') {
        if (!$modalCrudDelete) {
            throw new RuntimeException('Cancellazione non abilitata.');
        }
        $pkValue = $_POST['pk_value'] ?? null;
        if ($pkValue === null || $pkValue === '') {
            throw new RuntimeException('Chiave del record collegato non valida.');
        }
        $db->execute(
            'DELETE FROM ' . crudQuote((string) $modalCrudConfig['table_name'])
            . ' WHERE ' . crudQuote((string) $modalCrudConfig['primary_key']['field_name']) . ' = ?',
            [$pkValue]
        );
        header('Location: ' . crudRedirectUrl('deleted'));
        exit;
    }

    if ($action === 'insert_related') {
        $fkTable = normalizeRelatedTableName((string) ($_POST['fk_table'] ?? ''));
        $related = creatorePaginaNormalizeRelatedPayload($_POST['related'] ?? []);
        if ($fkTable === '') {
            throw new RuntimeException('Tabella collegata non valida.');
        }
        $schemaRows = creatorePaginaLoadTableColumns($db, $fkTable);
        $insertColumns = [];
        $insertValues = [];
        foreach ($schemaRows as $schemaRow) {
            $columnName = (string) ($schemaRow['Field'] ?? '');
            $extra = strtolower((string) ($schemaRow['Extra'] ?? ''));
            if ($columnName === '' || str_contains($extra, 'auto_increment')) {
                continue;
            }
            $isNullable = strtoupper((string) ($schemaRow['Null'] ?? '')) === 'YES';
            $default = $schemaRow['Default'] ?? null;
            $required = !$isNullable && $default === null;
            if (!$required) {
                continue;
            }
            if (!array_key_exists($columnName, $related)) {
                throw new RuntimeException('Compilare il campo collegato ' . $columnName . '.');
            }
            $insertColumns[] = $columnName;
            $insertValues[] = crudNormalizeValue([
                'field_type' => strtolower((string) ($schemaRow['Type'] ?? 'text')),
                'nullable' => false,
            ], $related[$columnName]);
        }
        if (!$insertColumns) {
            throw new RuntimeException('Nessun campo obbligatorio da inserire.');
        }
        $db->execute(
            'INSERT INTO ' . crudQuote($fkTable)
            . ' (' . implode(', ', array_map('crudQuote', $insertColumns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($insertColumns), '?')) . ')',
            $insertValues
        );
        $recordValue = (string) $db->lastInsertId();
        $labelField = trim((string) ($_POST['fk_label_field'] ?? ''));
        $recordLabel = $recordValue;
        if ($labelField !== '') {
            $label = $db->fetchColumn(
                'SELECT ' . crudQuote($labelField) . ' FROM ' . crudQuote($fkTable)
                . ' WHERE ' . crudQuote((string) ($_POST['fk_value_field'] ?? 'id')) . ' = ?',
                [$recordValue]
            );
            if ($label !== false && $label !== null) {
                $recordLabel = (string) $label;
            }
        }
        pannellateJsonResponse(['ok' => true, 'record_value' => $recordValue, 'record_label' => $recordLabel]);
    }

    if ($action === 'update_related') {
        $fkTable = normalizeRelatedTableName((string) ($_POST['fk_table'] ?? ''));
        $fkValueField = trim((string) ($_POST['fk_value_field'] ?? ''));
        $related = creatorePaginaNormalizeRelatedPayload($_POST['related'] ?? []);
        $recordValue = trim((string) ($_POST['fk_value'] ?? ''));
        if ($fkTable === '' || $fkValueField === '') {
            throw new RuntimeException('Tabella collegata non valida.');
        }
        if ($recordValue === '') {
            throw new RuntimeException('Chiave del record collegato non valida.');
        }
        $schemaRows = creatorePaginaLoadTableColumns($db, $fkTable);
        $updateColumns = [];
        $updateValues = [];
        foreach ($schemaRows as $schemaRow) {
            $columnName = (string) ($schemaRow['Field'] ?? '');
            $extra = strtolower((string) ($schemaRow['Extra'] ?? ''));
            if ($columnName === '' || str_contains($extra, 'auto_increment') || $columnName === $fkValueField) {
                continue;
            }
            if (!array_key_exists($columnName, $related)) {
                continue;
            }
            $updateColumns[] = $columnName;
            $updateValues[] = crudNormalizeValue([
                'field_type' => strtolower((string) ($schemaRow['Type'] ?? 'text')),
                'nullable' => strtoupper((string) ($schemaRow['Null'] ?? '')) === 'YES',
            ], $related[$columnName]);
        }
        if (!$updateColumns) {
            throw new RuntimeException('Nessun campo modificabile da aggiornare.');
        }
        $db->execute(
            'UPDATE ' . crudQuote($fkTable)
            . ' SET ' . implode(', ', array_map(fn(string $column): string => crudQuote($column) . ' = ?', $updateColumns))
            . ' WHERE ' . crudQuote($fkValueField) . ' = ?',
            [...$updateValues, $recordValue]
        );
        $recordLabel = $recordValue;
        $labelField = trim((string) ($_POST['fk_label_field'] ?? ''));
        if ($labelField !== '') {
            $label = $db->fetchColumn(
                'SELECT ' . crudQuote($labelField) . ' FROM ' . crudQuote($fkTable)
                . ' WHERE ' . crudQuote($fkValueField) . ' = ?',
                [$recordValue]
            );
            if ($label !== false && $label !== null) {
                $recordLabel = (string) $label;
            }
        }
        pannellateJsonResponse(['ok' => true, 'record_value' => $recordValue, 'record_label' => $recordLabel]);
    }

    throw new RuntimeException('Operazione collegata non riconosciuta.');
}
