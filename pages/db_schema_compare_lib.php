<?php
/**
 * CRUD Generator – PHP MySQL
 * Confronto schema locale e DB destinatario
 * Versione: 1.2
 * Creato il: 2026-07-26
 */

declare(strict_types=1);

function crudSchemaSplitStatements(string $sql): array
{
    $sql = preg_replace('/\/\*![0-9]{5}\s*(.*?)\s*\*\//s', '$1', $sql) ?? $sql;
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '--') || str_starts_with($trim, '#')) continue;
        $clean[] = $line;
    }

    $sql = implode("\n", $clean);
    $statements = [];
    $buffer = '';
    $single = false;
    $double = false;
    $escape = false;

    for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
        $ch = $sql[$i];
        $buffer .= $ch;

        if ($escape) {
            $escape = false;
            continue;
        }
        if ($ch === '\\') {
            $escape = true;
            continue;
        }
        if ($ch === "'" && !$double) {
            $single = !$single;
            continue;
        }
        if ($ch === '"' && !$single) {
            $double = !$double;
            continue;
        }
        if ($ch === ';' && !$single && !$double) {
            $statements[] = trim(substr($buffer, 0, -1));
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') $statements[] = trim($buffer);
    return $statements;
}

function crudSchemaExtractTables(string $sql): array
{
    $tables = [];
    foreach (crudSchemaSplitStatements($sql) as $statement) {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $statement, $match)) {
            continue;
        }
        $tables[$match[1]] = crudSchemaCanonicalizeCreate($statement);
    }
    ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    return $tables;
}

function crudSchemaNormalizeCreate(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', 'CREATE TABLE', $sql) ?? $sql;
    $sql = preg_replace('/\s+AUTO_INCREMENT=\d+\b/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+ROW_FORMAT=\w+\b/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+COLLATE=[a-zA-Z0-9_]+/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
    $sql = str_replace(['` ', ' `'], ['` ', ' `'], $sql);
    return strtolower(trim($sql));
}

function crudSchemaCanonicalizeCreate(string $sql): string
{
    $parts = crudSchemaSplitCreateBody($sql);
    if (!$parts) {
        return crudSchemaNormalizeCreate($sql);
    }

    $grouped = [];
    foreach ($parts as $part) {
        $info = crudSchemaClassifyCreatePart($part);
        $group = $info['group'];
        $name = $info['name'] !== '' ? $info['name'] : $info['detail'];
        $grouped[$group][$name] = crudSchemaNormalizeCreate($info['detail']);
    }

    ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
    $chunks = [];
    foreach ($grouped as $group => $items) {
        ksort($items, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($items as $name => $normalized) {
            $chunks[] = $group . ':' . $name . '=' . $normalized;
        }
    }

    return implode(' | ', $chunks);
}

function crudSchemaSplitCreateBody(string $sql): array
{
    $body = '';
    if (preg_match('/\((.*)\)\s*$/s', $sql, $match)) {
        $body = $match[1];
    }
    if ($body === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*(?![^()]*\))/s', trim($body)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $line): bool => $line !== ''));
}

function crudSchemaClassifyCreatePart(string $line): array
{
    $normalized = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    $normalized = strtolower($normalized);

    if ($normalized === '') {
        return ['group' => 'other', 'name' => '', 'detail' => ''];
    }

    if (preg_match('/^`?([a-z0-9_]+)`?\s+/', $normalized, $m)) {
        $name = $m[1];
    } else {
        $name = '';
    }

    if (preg_match('/^primary\s+key\b/', $normalized)) {
        return ['group' => 'primary_key', 'name' => 'primary', 'detail' => $line];
    }
    if (preg_match('/^(unique\s+key|unique\s+index|constraint\b.*\bunique\b)/', $normalized)) {
        return ['group' => 'unique_key', 'name' => $name ?: $normalized, 'detail' => $line];
    }
    if (preg_match('/^(key|index|fulltext\s+key|spatial\s+key)\b/', $normalized)) {
        return ['group' => 'index', 'name' => $name ?: $normalized, 'detail' => $line];
    }
    if (preg_match('/^foreign\s+key\b|constraint\b.*\bforeign\s+key\b/', $normalized)) {
        return ['group' => 'foreign_key', 'name' => $name ?: $normalized, 'detail' => $line];
    }
    if (preg_match('/^check\b/', $normalized)) {
        return ['group' => 'check', 'name' => $name ?: $normalized, 'detail' => $line];
    }
    if (preg_match('/^(engine|default\s+charset|charset|collate|comment)\b/', $normalized)) {
        return ['group' => 'table_option', 'name' => $name ?: $normalized, 'detail' => $line];
    }

    return ['group' => 'column', 'name' => $name ?: $normalized, 'detail' => $line];
}

function crudSchemaFormatDiffSummary(array $localOnly, array $remoteOnly): string
{
    $parts = [];
    if ($localOnly) {
        $parts[] = 'solo nel CRUD: ' . crudSchemaSummarizeDifferenceLines($localOnly);
    }
    if ($remoteOnly) {
        $parts[] = 'solo nel destinatario: ' . crudSchemaSummarizeDifferenceLines($remoteOnly);
    }
    return $parts ? 'Strutture diverse. ' . implode(' | ', $parts) : 'Struttura database differente.';
}

function crudSchemaSummarizeDifferenceLines(array $lines): string
{
    $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    if (!$lines) {
        return 'nessun dettaglio disponibile';
    }

    $sample = array_slice($lines, 0, 2);
    $sample = array_map(static function (string $line): string {
        if (preg_match('/\bunique\s+key\b/i', $line) || preg_match('/\bconstraint\b.*\bunique\b/i', $line)) {
            return 'UQ: ' . $line;
        }
        if (preg_match('/\bforeign\s+key\b/i', $line)) {
            return 'FK: ' . $line;
        }
        if (preg_match('/^\s*key\b/i', $line) || preg_match('/\bindex\b/i', $line)) {
            return 'IDX: ' . $line;
        }
        return $line;
    }, $sample);
    $text = implode('; ', $sample);
    if (count($lines) > 2) {
        $text .= ' ...';
    }

    return $text;
}

function crudSchemaExplainDifference(string $localSql, string $remoteSql): string
{
    $localParts = crudSchemaSplitCreateBody($localSql);
    $remoteParts = crudSchemaSplitCreateBody($remoteSql);

    if (!$localParts || !$remoteParts) {
        return 'Struttura database differente.';
    }

    $localGrouped = [];
    $remoteGrouped = [];
    foreach ($localParts as $part) {
        $info = crudSchemaClassifyCreatePart($part);
        $localGrouped[$info['group']][$info['name']] = $info['detail'];
    }
    foreach ($remoteParts as $part) {
        $info = crudSchemaClassifyCreatePart($part);
        $remoteGrouped[$info['group']][$info['name']] = $info['detail'];
    }

    $labels = [
        'column' => 'colonne',
        'primary_key' => 'primary key',
        'unique_key' => 'vincoli univoci (UQ)',
        'index' => 'indici',
        'foreign_key' => 'foreign key (FK)',
        'check' => 'check',
        'table_option' => 'opzioni tabella',
        'other' => 'altro',
    ];

    $messages = [];
    foreach ($labels as $group => $label) {
        $localItems = $localGrouped[$group] ?? [];
        $remoteItems = $remoteGrouped[$group] ?? [];
        $localNames = array_keys($localItems);
        $remoteNames = array_keys($remoteItems);
        $missing = array_values(array_diff($localNames, $remoteNames));
        $extra = array_values(array_diff($remoteNames, $localNames));

        $changed = [];
        foreach (array_intersect($localNames, $remoteNames) as $name) {
            if (crudSchemaCanonicalizeCreate($localItems[$name]) !== crudSchemaCanonicalizeCreate($remoteItems[$name])) {
                $changed[] = $name;
            }
        }

        if ($missing || $extra || $changed) {
            $chunks = [];
            if ($missing) {
                $chunks[] = 'solo nel CRUD: ' . implode(', ', array_slice($missing, 0, 3)) . (count($missing) > 3 ? ' ...' : '');
            }
            if ($extra) {
                $chunks[] = 'solo nel destinatario: ' . implode(', ', array_slice($extra, 0, 3)) . (count($extra) > 3 ? ' ...' : '');
            }
            if ($changed) {
                $chunks[] = 'modificati: ' . implode(', ', array_slice($changed, 0, 3)) . (count($changed) > 3 ? ' ...' : '');
            }
            $messages[] = $label . ' ' . implode(' | ', $chunks);
        }
    }

    if ($messages) {
        return 'Strutture diverse. ' . implode(' || ', $messages);
    }

    return 'Struttura database differente.';
}

function crudSchemaCompare(string $localSql, string $remoteSql): array
{
    $localTables = crudSchemaExtractTables($localSql);
    $remoteTables = crudSchemaExtractTables($remoteSql);

    $allNames = array_values(array_unique(array_merge(
        array_keys($localTables),
        array_keys($remoteTables)
    )));
    sort($allNames, SORT_NATURAL | SORT_FLAG_CASE);

    $rows = [];
    $summary = [
        'equal' => 0,
        'different' => 0,
        'missing_remote' => 0,
        'extra_remote' => 0,
    ];

    foreach ($allNames as $table) {
        $inLocal = array_key_exists($table, $localTables);
        $inRemote = array_key_exists($table, $remoteTables);

        if ($inLocal && !$inRemote) {
            $status = 'missing_remote';
            $detail = 'Tabella prevista da schema.sql ma assente nel DB destinatario.';
        } elseif (!$inLocal && $inRemote) {
            $status = 'extra_remote';
            $detail = 'Tabella presente nel DB destinatario ma assente da schema.sql.';
        } elseif ($localTables[$table] === $remoteTables[$table]) {
            $status = 'equal';
            $detail = 'Struttura allineata.';
        } else {
            $status = 'different';
            $detail = crudSchemaExplainDifference($localTables[$table], $remoteTables[$table]);
        }

        $summary[$status]++;
        $rows[] = [
            'table' => $table,
            'status' => $status,
            'detail' => $detail,
            'local_create' => $inLocal ? $localTables[$table] : '',
            'remote_create' => $inRemote ? $remoteTables[$table] : '',
        ];
    }

    return [
        'summary' => $summary,
        'rows' => $rows,
        'is_aligned' => $summary['different'] === 0
            && $summary['missing_remote'] === 0
            && $summary['extra_remote'] === 0,
    ];
}

function crudSchemaFetchRemote(
    string $receiverUrl,
    string $token,
    string $deployPath = '.',
    string $projectUuid = ''
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Estensione cURL non disponibile.');
    }

    $ch = curl_init($receiverUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'action' => 'export_schema',
            'deploy_path' => $deployPath,
            'project_uuid' => $projectUuid,
        ],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Deploy-Token: ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'CRUD-Schema-Inspector/1.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Errore cURL: ' . $error);
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Risposta non JSON dal rilevatore DB remoto.');
    }
    if ($status < 200 || $status >= 300 || empty($json['success'])) {
        throw new RuntimeException((string)($json['message'] ?? ('Errore HTTP ' . $status)));
    }

    return $json;
}

function crudSchemaApplyRemote(
    string $receiverUrl,
    string $token,
    string $deployPath,
    string $projectUuid,
    string $alignmentSql
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Estensione cURL non disponibile.');
    }

    $ch = curl_init($receiverUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'action' => 'apply_alignment',
            'deploy_path' => $deployPath,
            'project_uuid' => $projectUuid,
            'alignment_sql' => $alignmentSql,
        ],
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Deploy-Token: ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'CRUD-Schema-Aligner/1.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Errore cURL: ' . $error);
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Risposta non JSON dal ricevitore DB remoto.');
    }
    if ($status < 200 || $status >= 300 || empty($json['success'])) {
        throw new RuntimeException((string)($json['message'] ?? ('Errore HTTP ' . $status)));
    }

    return $json;
}
