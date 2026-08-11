<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : CRUD Generator
 * Versione   : 10.2
 * Creato il  : 2026-08-02 00:00:00
 * Modificato il: 2026-08-02 14:42
 * Progetto   : CRUD Generator
 * ============================================================
 */

if (!function_exists('pannellateBootSession')) {
    function pannellateBootSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $payload, int $status = 200): void
    {
        pannellateJsonResponse($payload, $status);
    }
}

if (!function_exists('pannellateEnsureDb')) {
    function pannellateEnsureDb(): void
    {
        require_once __DIR__ . '/../db.php';
    }
}

if (!function_exists('pannellateProjectContext')) {
    function pannellateProjectContext(): array
    {
        pannellateBootSession();
        return [
            'id' => isset($_SESSION['progetto_id']) ? (int) $_SESSION['progetto_id'] : 0,
            'name' => trim((string) ($_SESSION['progetto_nome'] ?? '')),
        ];
    }
}

if (!function_exists('pannellateRequireProject')) {
    function pannellateRequireProject(): void
    {
        $project = pannellateProjectContext();
        if ($project['id'] <= 0 || $project['name'] === '') {
            echo '<div class="alert alert-warning m-4">Seleziona prima un progetto attivo.</div>';
            exit;
        }
    }
}

if (!function_exists('pannellateRedirectToCreator')) {
    function pannellateRedirectToCreator(int $configurationId): void
    {
        header('Location: index.php?page=creatore_pagina&configuration_id=' . $configurationId . '&t=' . time());
        exit;
    }
}

if (!function_exists('pannellateRedirectToUpdater')) {
    function pannellateRedirectToUpdater(int $configurationId): void
    {
        header('Location: index.php?page=modifica_pagina&configuration_id=' . $configurationId . '&t=' . time());
        exit;
    }
}

if (!function_exists('pannellateJsonResponse')) {
    function pannellateJsonResponse(array $payload, int $status = 200): void
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
}

if (!function_exists('pannellateQuoteIdentifier')) {
    function pannellateQuoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('quoteIdentifier')) {
    function quoteIdentifier(string $identifier): string
    {
        return pannellateQuoteIdentifier($identifier);
    }
}

if (!function_exists('pannellateQuoteSqlString')) {
    function pannellateQuoteSqlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

if (!function_exists('quoteSqlString')) {
    function quoteSqlString(string $value): string
    {
        return pannellateQuoteSqlString($value);
    }
}

if (!function_exists('pannellateNormalizeRelatedTableName')) {
    function pannellateNormalizeRelatedTableName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = explode(':', $value, 2);
        return trim($parts[0]);
    }
}

if (!function_exists('normalizeRelatedTableName')) {
    function normalizeRelatedTableName(string $value): string
    {
        return pannellateNormalizeRelatedTableName($value);
    }
}

if (!function_exists('pannellatePathAllowedByOpenBaseDir')) {
    function pannellatePathAllowedByOpenBaseDir(string $path): bool
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
}

if (!function_exists('pannellateSafeIsFile')) {
    function pannellateSafeIsFile(string $path): bool
    {
        return pannellatePathAllowedByOpenBaseDir($path) && is_file($path);
    }
}

if (!function_exists('pannellateSafeIsDir')) {
    function pannellateSafeIsDir(string $path): bool
    {
        return pannellatePathAllowedByOpenBaseDir($path) && is_dir($path);
    }
}

if (!function_exists('pannellateNormalizePageTypeDescription')) {
    function pannellateNormalizePageTypeDescription(string $description): string
    {
        $description = trim($description);
        if ($description === 'Scheda singola - un record per pagina, con pannellata modale opzionale.') {
            return 'Scheda singola - un record per pagina.';
        }

        return $description;
    }
}

if (!function_exists('normalizePageTypeDescription')) {
    function normalizePageTypeDescription(string $description): string
    {
        return pannellateNormalizePageTypeDescription($description);
    }
}

if (!function_exists('pannellateSanitizeFolderName')) {
    function pannellateSanitizeFolderName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/[\s\.\,\!\?]+/u', '_', $name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        $name = preg_replace('/_+/', '_', $name);
        return trim($name, '_') ?: 'progetto_senza_nome';
    }
}

if (!function_exists('sanitizeFolderName')) {
    function sanitizeFolderName(string $name): string
    {
        return pannellateSanitizeFolderName($name);
    }
}

if (!function_exists('pannellateSanitizePhpFileName')) {
    function pannellateSanitizePhpFileName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\.php$/i', '', $name);
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/[\s\.\,\!\?\/\\\\]+/u', '_', $name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = preg_replace('/php$/i', '', $name);
        $name = trim($name, '_') ?: 'pagina_visualizzazione';
        return preg_replace('/\.php$/i', '', $name) . '.php';
    }
}

if (!function_exists('sanitizePhpFileName')) {
    function sanitizePhpFileName(string $name): string
    {
        return pannellateSanitizePhpFileName($name);
    }
}

if (!function_exists('pannellateProjectPaths')) {
    function pannellateProjectPaths(string $projectName): array
    {
        $folder = pannellateSanitizeFolderName($projectName);

        $candidates = [];
        $candidates[] = __DIR__
            . DIRECTORY_SEPARATOR . 'sito'
            . DIRECTORY_SEPARATOR . $folder;
        $candidates[] = dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'sito'
            . DIRECTORY_SEPARATOR . $folder;

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

        $candidates[] = DIRECTORY_SEPARATOR . 'membri'
            . DIRECTORY_SEPARATOR . 'dasi'
            . DIRECTORY_SEPARATOR . 'pages'
            . DIRECTORY_SEPARATOR . 'sito'
            . DIRECTORY_SEPARATOR . $folder;

        $candidates = array_values(array_unique($candidates));
        $root = $candidates[0];

        foreach ($candidates as $candidate) {
            if (pannellateSafeIsFile($candidate . DIRECTORY_SEPARATOR . 'schema.sql')) {
                $root = $candidate;
                break;
            }
        }

        if (!pannellateSafeIsDir($root)) {
            foreach ($candidates as $candidate) {
                if (pannellateSafeIsDir($candidate)) {
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
}

if (!function_exists('projectPaths')) {
    function projectPaths(string $projectName): array
    {
        return pannellateProjectPaths($projectName);
    }
}

if (!function_exists('pannellateRequireProjectOrJson')) {
    function pannellateRequireProjectOrJson(int $projectId, string $projectName): void
    {
        if ($projectId <= 0 || $projectName === '') {
            pannellateJsonResponse(['ok' => false, 'message' => 'Nessun progetto attivo selezionato.'], 400);
        }
    }
}

if (!function_exists('requireProject')) {
    function requireProject(int $projectId, string $projectName): void
    {
        pannellateRequireProjectOrJson($projectId, $projectName);
    }
}

if (!function_exists('pannellateLoadTable')) {
    function pannellateLoadTable(PDO|Database $db, int $projectId, int $tableId): ?array
    {
        return $db->fetch(
            'SELECT id, nome, descrizione
             FROM tabelle
             WHERE id = ? AND IDprogetto = ?',
            [$tableId, $projectId]
        ) ?: null;
    }
}

if (!function_exists('loadTable')) {
    function loadTable(PDO|Database $db, int $projectId, int $tableId): ?array
    {
        return pannellateLoadTable($db, $projectId, $tableId);
    }
}

if (!function_exists('pannellateLoadFields')) {
    function pannellateLoadFields(Database $db, int $tableId): array
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
               AND c.nome NOT LIKE '__virtual_pvc\_%'
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

            $field['is_pk'] = $primaryIndexFound || !empty($field['auto_increment']);
            $field['is_unique'] = strtoupper((string) $field['indice_tipo']) === 'UNICO' || str_contains($indexText, ':UNIQUE');
            $field['is_index'] = strtoupper((string) $field['indice_tipo']) === 'INDICE' || !empty($field['indici']);
            $field['is_fk'] = (bool) $field['is_fk'];
            $field['nullable'] = (bool) $field['nullable'];
            $field['auto_increment'] = (bool) $field['auto_increment'];
        }
        unset($field);

        return $fields;
    }
}

if (!function_exists('loadFields')) {
    function loadFields(Database $db, int $tableId): array
    {
        return pannellateLoadFields($db, $tableId);
    }
}

if (!function_exists('pannellateLoadRelations')) {
    function pannellateLoadRelations(Database $db, int $projectId, int $mainTableId): array
    {
        $rows = $db->fetchAll(
            "SELECT
                fk.id AS fk_id,
                fk.nome AS fk_nome,
                fk.IDtabella AS local_table_id,
                tl.nome AS local_table_name,
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
                fk.id, fk.nome, fk.IDtabella, tl.nome,
                cr.IDtabella, tr.nome, fk.on_delete, fk.on_update
             ORDER BY
                CASE WHEN fk.IDtabella = ? THEN 0 ELSE 1 END,
                tr.nome, tl.nome, fk.nome",
            [$projectId, $projectId, $mainTableId, $mainTableId, $mainTableId]
        );

        $result = [];
        foreach ($rows as $row) {
            $outgoing = (int) $row['local_table_id'] === $mainTableId;
            $secondaryId = $outgoing ? (int) $row['referenced_table_id'] : (int) $row['local_table_id'];
            $secondaryName = $outgoing ? $row['referenced_table_name'] : $row['local_table_name'];

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
                'referenced_table_id' => (int) $row['referenced_table_id'],
                'referenced_table_name' => $row['referenced_table_name'],
                'pairs' => $pairs,
                'on_delete' => $row['on_delete'],
                'on_update' => $row['on_update'],
            ];
        }

        return $result;
    }
}

if (!function_exists('loadRelations')) {
    function loadRelations(Database $db, int $projectId, int $mainTableId): array
    {
        return pannellateLoadRelations($db, $projectId, $mainTableId);
    }
}
