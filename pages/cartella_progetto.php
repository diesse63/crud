<?php
/**
 * Gestione cartella progetto e pubblicazione HTTPS.
 * Versione: 1.9 - 11/08/2026
 *
 * Funzioni principali:
 * - esplorazione, rinomina e cancellazione dei file del progetto;
 * - esportazione ZIP, mantenuta come procedura alternativa;
 * - salvataggio configurazione HTTPS per singolo progetto;
 * - token cifrato sul server tramite AES-256-GCM;
 * - ping, inspect, associate, disassociate e pubblicazione tramite deploy_receiver.php v1.2;
 * - protocol_version=1, verifica SHA-256, pubblicazione smart dei soli file necessari e risposta JSON;
 * - pubblicazione HTTPS dei soli file del progetto;
 * - configurazione DB destinatario integrata nel file, senza setup_db_destinatario.php e senza librerie esterne;
 * - generazione schema.sql/schema.json integrata, senza analisi_db.php come passaggio manuale;
 * - pulizia automatica dei backup db.php: conserva solo le ultime 2 versioni locali;
 * - confronto a tre pannelli tra schema.sql CRUD, struttura DB remota e differenze.
 * - viewer dei file con pulsante per copiare il codice negli appunti.
 */
// 1. GESTIONE DOWNLOAD ZIP (Deve stare prima di ogni altra cosa)
if (isset($_GET['action']) && $_GET['action'] === 'export_zip') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    // Funzione interna per sanitize (non possiamo contare su quella sotto se usciamo prima)
    $p_nome = $_SESSION['progetto_nome'] ?? 'progetto';
    $f_name = strtolower(trim($p_nome));
    $f_name = str_replace(array(' ', '.', ',', '!', '?'), '_', $f_name);
    $f_name = preg_replace('/[^a-z0-9\_]/', '', $f_name) ?: 'progetto';

    $dir_to_zip = __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $f_name;
    $zip_path = __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $f_name . '_export.zip';

    if (extension_loaded('zip') && is_dir($dir_to_zip)) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir_to_zip), RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen(realpath($dir_to_zip)) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();

            // PULIZIA BUFFER per evitare corruzione file
            if (ob_get_length()) ob_end_clean();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $f_name . '.zip"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($zip_path));
            header('Pragma: no-cache');
            
            readfile($zip_path);
            unlink($zip_path); // Elimina il temporaneo
            exit;
        }
    }
    die("Errore durante la creazione dello ZIP. Verifica i permessi o se la cartella è vuota.");
}

// --- LOGICA NORMALE DELLA PAGINA ---
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/db_schema_compare_lib.php';
require_once __DIR__ . '/versioning.php';


/* ============================================================
 * GENERAZIONE SCHEMA.SQL INTEGRATA
 * Ex analisi_db.php: la logica resta disponibile come funzione interna.
 * ============================================================ */
if (!function_exists('projectSchemaSanitizeFolderName')) {
    function projectSchemaSanitizeFolderName($name) {
        $name = strtolower(trim((string)$name));
        $name = str_replace(array(' ', '.', ',', '!', '?'), '_', $name);
        $name = preg_replace('/[^a-z0-9\_]/', '', $name);
        return $name ?: 'progetto_senza_nome';
    }
}

if (!function_exists('projectSchemaDfsSort')) {
    function projectSchemaDfsSort($node, &$adj, &$visited, &$stack, &$sorted) {
        $visited[$node] = true;
        $stack[$node] = true;
        if (isset($adj[$node])) {
            foreach ($adj[$node] as $dep) {
                if (!isset($visited[$dep])) {
                    projectSchemaDfsSort($dep, $adj, $visited, $stack, $sorted);
                }
            }
        }
        unset($stack[$node]);
        $sorted[] = $node;
    }
}

if (!function_exists('projectSchemaSqlDefault')) {
    function projectSchemaSqlDefault($value) {
        $value = (string)$value;
        $upper = strtoupper(trim($value));
        if ($upper === 'NULL' || $upper === 'CURRENT_TIMESTAMP' || $upper === 'CURRENT_DATE' || $upper === 'CURRENT_TIME') {
            return $upper;
        }
        if (is_numeric($value)) {
            return $value;
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

if (!function_exists('projectSchemaFieldIndexType')) {
    function projectSchemaFieldIndexType(array $field): string {
        $tipo = strtoupper(trim((string)($field['indice_tipo'] ?? '')));
        if ($tipo !== '') {
            return $tipo;
        }
        if (!empty($field['unico'])) {
            return 'UNICO';
        }
        if (!empty($field['indice'])) {
            return 'INDICE';
        }
        if (!empty($field['auto_increment'])) {
            return 'PRIMARY';
        }
        return '';
    }
}

if (!function_exists('projectSchemaColumnDefinition')) {
    function projectSchemaColumnDefinition(array $field): string {
        $name = (string)($field['nome'] ?? '');
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Nome campo non valido nello schema.');
        }

        $type = strtoupper((string)($field['tipo'] ?? 'varchar'));
        $length = trim((string)($field['lunghezza'] ?? ''));
        $def = "  `{$name}` {$type}";
        if ($length !== '') {
            $def .= "({$length})";
        }

        $nullable = !empty($field['nullable']);
        $def .= $nullable ? ' NULL' : ' NOT NULL';

        $default = $field['default_value'] ?? null;
        $isTimestampAuto = strtolower((string)($field['tipo'] ?? '')) === 'timestamp' && !empty($field['modifica']);
        if ($default !== null && $default !== '') {
            $def .= ' DEFAULT ' . projectSchemaSqlDefault($default);
        } elseif ($isTimestampAuto) {
            $def .= ' DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        }

        if (!empty($field['auto_increment'])) {
            $def .= ' AUTO_INCREMENT';
        }

        $comment = trim((string)($field['commento'] ?? ''));
        if ($comment !== '') {
            $def .= " COMMENT '" . str_replace("'", "''", $comment) . "'";
        }

        return $def;
    }
}

if (!function_exists('projectSchemaHasIndexOnColumns')) {
    function projectSchemaHasIndexOnColumns(array $indexes, array $columns): bool {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('projectExtractFileVersion')) {
    function projectExtractFileVersion(string $filePath): string {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return '';
        }

        $content = (string)fread($handle, 4096);
        fclose($handle);

        if ($content === '') {
            return '';
        }

        if (preg_match('/^\s*\*?\s*Versione(?:\s+pagina)?\s*:\s*([0-9]+\.[0-9]+)/mi', $content, $matches)) {
            return crudVersionNormalize((string) $matches[1]);
        }

        return '';
    }
}

if (!function_exists('projectSchemaGenerate')) {
    function projectSchemaGenerate($db, int $progettoId, string $progettoNome, bool $writeFiles = true): array {
        $folderName = projectSchemaSanitizeFolderName($progettoNome);
        $projectFolderPath = __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $folderName;
        $fileSqlPath = $projectFolderPath . DIRECTORY_SEPARATOR . 'schema.sql';
        $fileJsonPath = $projectFolderPath . DIRECTORY_SEPARATOR . 'schema.json';

        $tablesData = $db->fetchAll('SELECT * FROM tabelle WHERE IDprogetto = ?', [$progettoId]);
        if (empty($tablesData)) {
            throw new RuntimeException('Nessuna tabella trovata per questo progetto.');
        }

        $tablesByName = [];
        foreach ($tablesData as $table) {
            $tableName = (string)($table['nome'] ?? '');
            if ($tableName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new RuntimeException('Nome tabella non valido nello schema.');
            }
            $tablesByName[$tableName] = $table;
        }

        $allFkDeps = [];
        try {
            $allFkDeps = $db->fetchAll(
                'SELECT t_loc.nome as local_table, t_ref.nome as referenced_table
                 FROM foreign_keys_campi fkc
                 JOIN campi cl ON fkc.IDcampo_locale = cl.id
                 JOIN campi cr ON fkc.IDcampo_referenziato = cr.id
                 JOIN tabelle t_loc ON cl.IDtabella = t_loc.id
                 JOIN tabelle t_ref ON cr.IDtabella = t_ref.id
                 WHERE t_loc.IDprogetto = ? AND t_ref.IDprogetto = ?
                   AND LEFT(cl.nome, 14) <> \'__virtual_pvc_\'
                   AND LEFT(cr.nome, 14) <> \'__virtual_pvc_\'',
                [$progettoId, $progettoId]
            );
        } catch (Throwable $ignored) {
            $allFkDeps = [];
        }

        $adj = [];
        foreach ($tablesByName as $name => $table) {
            $adj[$name] = [];
        }
        foreach ($allFkDeps as $fk) {
            $local = (string)($fk['local_table'] ?? '');
            $ref = (string)($fk['referenced_table'] ?? '');
            if ($local !== '' && $ref !== '' && $local !== $ref && isset($adj[$local])) {
                $adj[$local][] = $ref;
            }
        }

        $visited = [];
        $stack = [];
        $creationOrder = [];
        foreach (array_keys($adj) as $node) {
            if (!isset($visited[$node])) {
                projectSchemaDfsSort($node, $adj, $visited, $stack, $creationOrder);
            }
        }

        $schemaData = [];
        $sql = "-- SQL Script generato per progetto: {$progettoNome}\n";
        $sql .= "-- Generato automaticamente da tabelle.php/cartella_progetto.php il " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($creationOrder as $tableName) {
            $table = $tablesByName[$tableName];
            $fields = $db->fetchAll(
                "SELECT * FROM campi WHERE IDtabella = ? AND LEFT(nome, 14) <> '__virtual_pvc_' ORDER BY ordine",
                [(int)$table['id']]
            );
            if (empty($fields)) {
                continue;
            }

            $schemaData[$tableName] = [
                'fields' => [],
                'primary_key' => [],
                'indexes' => [],
                'foreign_keys' => []
            ];

            $definitions = [];
            $pkCols = [];
            $firstFieldName = null;

            foreach ($fields as $idx => $field) {
                $fieldName = (string)($field['nome'] ?? '');
                if ($idx === 0) {
                    $firstFieldName = $fieldName;
                }

                $definitions[] = projectSchemaColumnDefinition($field);

                $indexType = projectSchemaFieldIndexType($field);
                if ($indexType === 'PRIMARY' || $indexType === 'P' || !empty($field['auto_increment'])) {
                    $pkCols[] = $fieldName;
                } elseif ($indexType === 'UNICO' || $indexType === 'UNIQUE') {
                    $definitions[] = "  UNIQUE KEY `uniq_{$fieldName}` (`{$fieldName}`)";
                    $schemaData[$tableName]['indexes'][] = ['name' => 'uniq_' . $fieldName, 'type' => 'UNIQUE', 'columns' => [$fieldName]];
                } elseif ($indexType === 'INDICE' || $indexType === 'INDEX' || $indexType === 'KEY') {
                    $definitions[] = "  KEY `idx_{$fieldName}` (`{$fieldName}`)";
                    $schemaData[$tableName]['indexes'][] = ['name' => 'idx_' . $fieldName, 'type' => 'INDEX', 'columns' => [$fieldName]];
                }

                $schemaData[$tableName]['fields'][] = [
                    'name' => $fieldName,
                    'type' => (string)($field['tipo'] ?? ''),
                    'length' => $field['lunghezza'] ?? null,
                    'nullable' => !empty($field['nullable']),
                    'auto_increment' => !empty($field['auto_increment']),
                    'default' => $field['default_value'] ?? null,
                    'on_update' => !empty($field['modifica'])
                ];
            }

            if (empty($pkCols) && $firstFieldName) {
                $pkCols[] = $firstFieldName;
            }
            $pkCols = array_values(array_unique(array_filter($pkCols)));
            if (!empty($pkCols)) {
                $definitions[] = '  PRIMARY KEY (' . implode(', ', array_map(function($c) { return '`' . $c . '`'; }, $pkCols)) . ')';
                $schemaData[$tableName]['primary_key'] = $pkCols;
            }

            try {
                $tableIndexes = $db->fetchAll(
                    'SELECT i.id, i.nome, i.tipo
                     FROM indici i
                     WHERE i.IDtabella = ?
                     ORDER BY i.nome',
                    [(int)$table['id']]
                );
            } catch (Throwable $ignored) {
                $tableIndexes = [];
            }

            foreach ($tableIndexes as $indexDefinition) {
                $indexFields = $db->fetchAll(
                    'SELECT c.nome
                     FROM indici_campi ic
                     JOIN campi c ON c.id = ic.IDcampo
                     WHERE ic.IDindice = ?
                       AND LEFT(c.nome, 14) <> \'__virtual_pvc_\'
                     ORDER BY ic.ordine',
                    [(int)$indexDefinition['id']]
                );
                $indexColumns = array_map(fn($row) => (string)$row['nome'], $indexFields);
                if (empty($indexColumns) || projectSchemaHasIndexOnColumns($schemaData[$tableName]['indexes'], $indexColumns)) {
                    continue;
                }

                $indexType = strtoupper((string)($indexDefinition['tipo'] ?? 'INDEX'));
                $indexPrefix = in_array($indexType, ['UNIQUE', 'FULLTEXT', 'SPATIAL'], true) ? $indexType . ' KEY' : 'KEY';
                $indexCols = implode(', ', array_map(function($c) { return '`' . $c . '`'; }, $indexColumns));
                $definitions[] = "  {$indexPrefix} `{$indexDefinition['nome']}` ({$indexCols})";
                $schemaData[$tableName]['indexes'][] = ['name' => (string)$indexDefinition['nome'], 'type' => $indexType ?: 'INDEX', 'columns' => $indexColumns];
            }

            try {
                $tableFks = $db->fetchAll(
                    'SELECT DISTINCT fk.*, t_ref.nome as ref_table
                     FROM foreign_keys fk
                     JOIN foreign_keys_campi fkc ON fk.id = fkc.IDforeign_key
                     JOIN campi cl ON fkc.IDcampo_locale = cl.id
                     JOIN campi cr ON fkc.IDcampo_referenziato = cr.id
                     JOIN tabelle t_ref ON cr.IDtabella = t_ref.id
                     WHERE cl.IDtabella = ?
                       AND LEFT(cl.nome, 14) <> \'__virtual_pvc_\'
                       AND LEFT(cr.nome, 14) <> \'__virtual_pvc_\'',
                    [(int)$table['id']]
                );
            } catch (Throwable $ignored) {
                $tableFks = [];
            }

            foreach ($tableFks as $fk) {
                $fkDetails = $db->fetchAll(
                    'SELECT cl.nome as loc_f, cr.nome as ref_f
                     FROM foreign_keys_campi fkc
                     JOIN campi cl ON fkc.IDcampo_locale = cl.id
                     JOIN campi cr ON fkc.IDcampo_referenziato = cr.id
                     WHERE fkc.IDforeign_key = ?
                       AND LEFT(cl.nome, 14) <> \'__virtual_pvc_\'
                       AND LEFT(cr.nome, 14) <> \'__virtual_pvc_\'
                     ORDER BY fkc.ordine',
                    [(int)$fk['id']]
                );
                if (empty($fkDetails)) {
                    continue;
                }
                $locNames = [];
                $refNames = [];
                foreach ($fkDetails as $detail) {
                    $locNames[] = (string)$detail['loc_f'];
                    $refNames[] = (string)$detail['ref_f'];
                }
                $locCols = implode(', ', array_map(function($c) { return '`' . $c . '`'; }, $locNames));
                $refCols = implode(', ', array_map(function($c) { return '`' . $c . '`'; }, $refNames));
                $fkName = (string)($fk['nome'] ?? ('fk_' . $tableName . '_' . implode('_', $locNames)));
                $refTable = (string)($fk['ref_table'] ?? '');
                $onDelete = strtoupper((string)($fk['on_delete'] ?? 'RESTRICT'));
                $onUpdate = strtoupper((string)($fk['on_update'] ?? 'CASCADE'));

                if (!projectSchemaHasIndexOnColumns($schemaData[$tableName]['indexes'], $locNames)) {
                    $definitions[] = "  KEY `idx_fk_{$fkName}` ({$locCols})";
                    $schemaData[$tableName]['indexes'][] = ['name' => 'idx_fk_' . $fkName, 'type' => 'INDEX', 'columns' => $locNames];
                }
                $definitions[] = "  CONSTRAINT `{$fkName}` FOREIGN KEY ({$locCols}) REFERENCES `{$refTable}` ({$refCols}) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
                $schemaData[$tableName]['foreign_keys'][] = [
                    'name' => $fkName,
                    'columns' => $locNames,
                    'referenced_table' => $refTable,
                    'referenced_columns' => $refNames,
                    'on_delete' => $onDelete,
                    'on_update' => $onUpdate
                ];
            }

            $sql .= "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n";
            $sql .= implode(",\n", $definitions);
            $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n\n";
        }

        $sql .= 'SET FOREIGN_KEY_CHECKS=1;' . PHP_EOL;
        $json = json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }

        $changed = false;
        if ($writeFiles) {
            if (!is_dir($projectFolderPath) && !mkdir($projectFolderPath, 0755, true) && !is_dir($projectFolderPath)) {
                throw new RuntimeException('Impossibile creare la cartella progetto per schema.sql.');
            }
            $oldSql = is_file($fileSqlPath) ? (string)file_get_contents($fileSqlPath) : '';
            if ($oldSql !== $sql) {
                $changed = true;
                if (file_put_contents($fileSqlPath, $sql, LOCK_EX) === false) {
                    throw new RuntimeException('Impossibile scrivere schema.sql.');
                }
            }
            $oldJson = is_file($fileJsonPath) ? (string)file_get_contents($fileJsonPath) : '';
            if ($oldJson !== $json) {
                if (file_put_contents($fileJsonPath, $json, LOCK_EX) === false) {
                    throw new RuntimeException('Impossibile scrivere schema.json.');
                }
            }
        }

        return [
            'sql' => $sql,
            'json' => $json,
            'schema_data' => $schemaData,
            'folder' => $projectFolderPath,
            'sql_path' => $fileSqlPath,
            'json_path' => $fileJsonPath,
            'changed' => $changed,
            'tables' => count($schemaData)
        ];
    }
}

if (!function_exists('projectSchemaGenerateAndMessage')) {
    function projectSchemaGenerateAndMessage($db, int $progettoId, string $progettoNome): string {
        $result = projectSchemaGenerate($db, $progettoId, $progettoNome, true);
        return $result['changed']
            ? ' Schema.sql aggiornato automaticamente.'
            : ' Schema.sql già aggiornato.';
    }
}

/* ============================================================
 * CONFIGURAZIONE DB DESTINATARIO INTEGRATA
 * Ex db_destinatario_lib.php: file esterno eliminato.
 * ============================================================ */
/**
 * Libreria configurazione DB destinatario per progetto.
 * Versione: 1.0 - 22/07/2026
 *
 * Scopo:
 * - salvare una sola volta i parametri DB destinatario legati al progetto;
 * - cifrare la password sul server CRUD;
 * - generare automaticamente db.php ogni volta che serve, ad esempio durante la pubblicazione.
 */

if (!function_exists('destDbEnsureTableColumn')) {
    function destDbEnsureTableColumn($db, string $table, string $column, string $definition): void {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new RuntimeException('Nome tabella o colonna non valido per configurazione DB destinatario.');
        }
        $exists = $db->fetch(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $column]
        );
        if (!$exists) {
            $db->execute("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }
}

if (!function_exists('destDbEnsureConfigTable')) {
    function destDbEnsureConfigTable($db): void {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS progetti_db_destinatario (
                id INT NOT NULL AUTO_INCREMENT,
                IDprogetto INT NOT NULL,
                host VARCHAR(255) NOT NULL DEFAULT 'localhost',
                db_name VARCHAR(255) NOT NULL DEFAULT '',
                db_user VARCHAR(255) NOT NULL DEFAULT '',
                db_pass_cifrata TEXT NULL,
                charset_name VARCHAR(50) NOT NULL DEFAULT 'utf8mb4',
                auto_initialize TINYINT(1) NOT NULL DEFAULT 1,
                auto_apply TINYINT(1) NOT NULL DEFAULT 1,
                modify_columns TINYINT(1) NOT NULL DEFAULT 1,
                drop_extra_columns TINYINT(1) NOT NULL DEFAULT 0,
                drop_extra_tables TINYINT(1) NOT NULL DEFAULT 0,
                make_extra_nullable TINYINT(1) NOT NULL DEFAULT 1,
                sync_schema_files TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_db_php_hash CHAR(64) NULL,
                ultima_generazione DATETIME NULL,
                aggiornato_il TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_db_destinatario_progetto (IDprogetto)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        destDbEnsureTableColumn($db, 'progetti_db_destinatario', 'charset_name', "charset_name VARCHAR(50) NOT NULL DEFAULT 'utf8mb4' AFTER db_pass_cifrata");
        destDbEnsureTableColumn($db, 'progetti_db_destinatario', 'ultimo_db_php_hash', 'ultimo_db_php_hash CHAR(64) NULL AFTER sync_schema_files');
        destDbEnsureTableColumn($db, 'progetti_db_destinatario', 'ultima_generazione', 'ultima_generazione DATETIME NULL AFTER ultimo_db_php_hash');
        destDbEnsureTableColumn($db, 'progetti_db_destinatario', 'drop_extra_tables', 'drop_extra_tables TINYINT(1) NOT NULL DEFAULT 0 AFTER drop_extra_columns');
    }
}

if (!function_exists('destDbKeyPath')) {
    function destDbKeyPath(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'deploy.key';
    }
}

if (!function_exists('destDbEncryptionKey')) {
    function destDbEncryptionKey(): string {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('OpenSSL non è disponibile: impossibile cifrare la password DB destinatario.');
        }
        $keyPath = destDbKeyPath();
        $keyDir = dirname($keyPath);
        if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
            throw new RuntimeException('Impossibile creare la cartella protetta per la chiave.');
        }
        if (!is_file($keyPath)) {
            $key = random_bytes(32);
            if (file_put_contents($keyPath, base64_encode($key), LOCK_EX) === false) {
                throw new RuntimeException('Impossibile salvare la chiave di cifratura.');
            }
            @chmod($keyPath, 0600);
            return $key;
        }
        $key = base64_decode(trim((string)file_get_contents($keyPath)), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Chiave di cifratura non valida.');
        }
        return $key;
    }
}

if (!function_exists('destDbEncryptSecret')) {
    function destDbEncryptSecret(string $secret): string {
        $cipher = 'aes-256-gcm';
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $tag = '';
        $encrypted = openssl_encrypt($secret, $cipher, destDbEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new RuntimeException('Cifratura password DB destinatario non riuscita.');
        }
        return base64_encode($iv . $tag . $encrypted);
    }
}

if (!function_exists('destDbDecryptSecret')) {
    function destDbDecryptSecret(string $payload): string {
        if ($payload === '') return '';
        $raw = base64_decode($payload, true);
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($raw === false || strlen($raw) <= $ivLength + 16) {
            throw new RuntimeException('Password DB destinatario cifrata non valida.');
        }
        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, 16);
        $encrypted = substr($raw, $ivLength + 16);
        $secret = openssl_decrypt($encrypted, $cipher, destDbEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($secret === false) {
            throw new RuntimeException('Impossibile decifrare la password DB destinatario.');
        }
        return $secret;
    }
}

if (!function_exists('destDbLoadConfig')) {
    function destDbLoadConfig($db, int $projectId): ?array {
        destDbEnsureConfigTable($db);
        $row = $db->fetch('SELECT * FROM progetti_db_destinatario WHERE IDprogetto = ? LIMIT 1', [$projectId]);
        if (!$row) return null;
        $row['pass'] = destDbDecryptSecret((string)($row['db_pass_cifrata'] ?? ''));
        return $row;
    }
}

if (!function_exists('destDbNormalizeBool')) {
    function destDbNormalizeBool($value, bool $default = false): int {
        if ($value === null) return $default ? 1 : 0;
        return ($value === '1' || $value === 1 || $value === true || $value === 'on') ? 1 : 0;
    }
}

if (!function_exists('destDbSaveConfig')) {
    function destDbSaveConfig($db, int $projectId, array $data, bool $keepExistingPassword = true): void {
        destDbEnsureConfigTable($db);
        $existing = $db->fetch('SELECT db_pass_cifrata FROM progetti_db_destinatario WHERE IDprogetto = ? LIMIT 1', [$projectId]) ?: [];
        $plainPassword = array_key_exists('pass', $data) ? (string)$data['pass'] : '';
        $encryptedPassword = (string)($existing['db_pass_cifrata'] ?? '');
        if ($plainPassword !== '' || !$keepExistingPassword || $encryptedPassword === '') {
            $encryptedPassword = destDbEncryptSecret($plainPassword);
        }
        $host = trim((string)($data['host'] ?? 'localhost')) ?: 'localhost';
        $dbName = trim((string)($data['db_name'] ?? ''));
        $user = trim((string)($data['user'] ?? ''));
        if ($dbName === '' || $user === '') {
            throw new RuntimeException('Indicare nome database e utente DB destinatario.');
        }
        $db->execute(
            "INSERT INTO progetti_db_destinatario
                (IDprogetto, host, db_name, db_user, db_pass_cifrata, charset_name, auto_initialize, auto_apply, modify_columns, drop_extra_columns, drop_extra_tables, make_extra_nullable, sync_schema_files)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                host = VALUES(host),
                db_name = VALUES(db_name),
                db_user = VALUES(db_user),
                db_pass_cifrata = VALUES(db_pass_cifrata),
                charset_name = VALUES(charset_name),
                auto_initialize = VALUES(auto_initialize),
                auto_apply = VALUES(auto_apply),
                modify_columns = VALUES(modify_columns),
                drop_extra_columns = VALUES(drop_extra_columns),
                drop_extra_tables = VALUES(drop_extra_tables),
                make_extra_nullable = VALUES(make_extra_nullable),
                sync_schema_files = VALUES(sync_schema_files)",
            [
                $projectId,
                $host,
                $dbName,
                $user,
                $encryptedPassword,
                trim((string)($data['charset_name'] ?? 'utf8mb4')) ?: 'utf8mb4',
                destDbNormalizeBool($data['auto_initialize'] ?? 1, true),
                destDbNormalizeBool($data['auto_apply'] ?? 1, true),
                destDbNormalizeBool($data['modify_columns'] ?? 1, true),
                destDbNormalizeBool($data['drop_extra_columns'] ?? 0, false),
                destDbNormalizeBool($data['drop_extra_tables'] ?? 0, false),
                destDbNormalizeBool($data['make_extra_nullable'] ?? 1, true),
                destDbNormalizeBool($data['sync_schema_files'] ?? 1, true),
            ]
        );
    }
}

if (!function_exists('destDbDeleteConfig')) {
    function destDbDeleteConfig($db, int $projectId): void {
        destDbEnsureConfigTable($db);
        $db->execute('DELETE FROM progetti_db_destinatario WHERE IDprogetto = ?', [$projectId]);
    }
}

if (!function_exists('destDbConfigForGeneration')) {
    function destDbConfigForGeneration(array $row, string $appName): array {
        return [
            'host' => (string)($row['host'] ?? 'localhost'),
            'db_name' => (string)($row['db_name'] ?? ''),
            'user' => (string)($row['db_user'] ?? ($row['user'] ?? '')),
            'pass' => (string)($row['pass'] ?? ''),
            'auto_initialize' => !empty($row['auto_initialize']),
            'auto_apply' => !empty($row['auto_apply']),
            'modify_columns' => !empty($row['modify_columns']),
            'drop_extra_columns' => !empty($row['drop_extra_columns']),
            'drop_extra_tables' => !empty($row['drop_extra_tables']),
            'make_extra_nullable' => !empty($row['make_extra_nullable']),
            'sync_schema_files' => !empty($row['sync_schema_files']),
            'app_name' => $appName,
        ];
    }
}

function destDbBuildDbPhp(array $config): string {
    $host = var_export($config['host'], true);
    $dbName = var_export($config['db_name'], true);
    $user = var_export($config['user'], true);
    $pass = var_export($config['pass'], true);
    $appName = var_export($config['app_name'], true);
    $auto = !empty($config['auto_initialize']) ? 'true' : 'false';
    $apply = !empty($config['auto_apply']) ? 'true' : 'false';
    $modify = !empty($config['modify_columns']) ? 'true' : 'false';
    $dropExtra = !empty($config['drop_extra_columns']) ? 'true' : 'false';
    $dropTables = !empty($config['drop_extra_tables']) ? 'true' : 'false';
    $nullableExtra = !empty($config['make_extra_nullable']) ? 'true' : 'false';
    $syncFiles = !empty($config['sync_schema_files']) ? 'true' : 'false';
    $token = var_export(bin2hex(random_bytes(16)), true);
    $generatedAt = date('Y-m-d H:i:s');

    return <<<PHPDB
<?php
/**
 * ============================================================
 * db.php generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : Aggiornamento DB destinatario da schema.sql
 * Versione   : 1.2
 * Creato il  : {$generatedAt}
 *
 * Funzione:
 * - connessione PDO;
 * - confronto automatico tra schema.sql e DB destinatario;
 * - creazione file schema_update.sql;
 * - applicazione automatica CREATE TABLE / ALTER TABLE;
 * - sincronizzazione copie schema.sql nella cartella progetto;
 * - test JSON protetto da token.
 * ============================================================
 */

class Database {
    private PDO \$pdo;

    private const APP_NAME = {$appName};
    private const AUTO_INITIALIZE = {$auto};
    private const AUTO_APPLY_MIGRATION = {$apply};
    private const MODIFY_CHANGED_COLUMNS = {$modify};
    private const DROP_EXTRA_COLUMNS = {$dropExtra};
    private const DROP_EXTRA_TABLES = {$dropTables};
    private const MAKE_EXTRA_NOT_NULL_COLUMNS_NULLABLE = {$nullableExtra};
    private const SYNC_SCHEMA_FILES = {$syncFiles};
    private const SCHEMA_FILE = 'schema.sql';
    private const UPDATE_FILE = 'schema_update.sql';
    private const SYNC_TABLE = '__crud_schema_sync';
    private const IGNORE_TABLES = [self::SYNC_TABLE];
    private const SELF_TEST_TOKEN = {$token};

    public function __construct() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        \$host = {$host};
        \$db   = {$dbName};
        \$user = {$user};
        \$pass = {$pass};
        \$charset = 'utf8mb4';

        \$dsn = "mysql:host={\$host};dbname={\$db};charset={\$charset}";
        \$options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ];

        \$remoteSyncRequest = isset(\$_GET['crud_db_autosync']) && (string)\$_GET['crud_db_autosync'] === '1';
        \$remoteSyncTable = trim((string)(\$_GET['crud_db_autosync_table'] ?? ''));
        \$remoteSyncForce = isset(\$_GET['crud_db_autosync_force']) && (string)\$_GET['crud_db_autosync_force'] === '1';

        try {
            \$this->pdo = new PDO(\$dsn, \$user, \$pass, \$options);

            // La lettura dello schema remoto non deve avviare migrazioni.
            if (defined('CRUD_SCHEMA_INSPECTION_ONLY') && CRUD_SCHEMA_INSPECTION_ONLY === true) {
                return;
            }

            if (isset(\$_GET['crud_db_test']) && hash_equals(self::SELF_TEST_TOKEN, (string)\$_GET['crud_db_test'])) {
                \$this->outputSelfTest();
            }

            if (\$remoteSyncRequest) {
                \$stats = (self::AUTO_INITIALIZE || \$remoteSyncForce)
                    ? \$this->autoSyncSchema(\$remoteSyncTable)
                    : ['message' => 'AUTO_INITIALIZE disattivato.'];
                \$_SESSION['db_sync_report'] = \$stats['operations_report'] ?? [];
                \$_SESSION['db_sync_summary'] = \$stats['message'] ?? '';
                \$this->outputJson(['success' => true, 'message' => 'Aggiornamento DB remoto completato.', 'data' => \$stats], 200);
            }

            if (self::AUTO_INITIALIZE) {
                try {
                    \$stats = \$this->autoSyncSchema();
                    \$_SESSION['db_sync_report'] = \$stats['operations_report'] ?? [];
                    \$_SESSION['db_sync_summary'] = \$stats['message'] ?? '';
                } catch (Throwable \$syncError) {
                    \$_SESSION['db_sync_report'] = [];
                    \$_SESSION['db_sync_summary'] = \$syncError->getMessage();
                    error_log('Errore sincronizzazione schema automatica: ' . \$syncError->getMessage());
                }
            }
        } catch (Throwable \$e) {
            error_log('Errore db.php: ' . \$e->getMessage());
            if (\$remoteSyncRequest) {
                \$this->outputJson(['success' => false, 'message' => \$e->getMessage()], 500);
            }
            if (\$e instanceof PDOException) {
                throw new RuntimeException('Connessione database fallita.');
            }
            throw new RuntimeException('Aggiornamento database da schema.sql non riuscito: ' . \$e->getMessage());
        }
    }

    private function autoSyncSchema(string \$targetTable = ''): array {
        \$schemaPath = __DIR__ . DIRECTORY_SEPARATOR . self::SCHEMA_FILE;
        if (!is_file(\$schemaPath)) return ['message' => 'schema.sql non presente.'];

        \$hash = hash_file('sha256', \$schemaPath);
        if (!is_string(\$hash) || \$hash === '') {
            throw new RuntimeException('Impossibile calcolare SHA-256 di schema.sql.');
        }

        \$this->ensureSyncTable();
        \$lastHash = \$this->fetchColumn(
            'SELECT schema_hash FROM `' . self::SYNC_TABLE . '` WHERE file_name = ? LIMIT 1',
            [self::SCHEMA_FILE]
        );

        \$sql = file_get_contents(\$schemaPath);
        if (!is_string(\$sql) || trim(\$sql) === '') {
            throw new RuntimeException('schema.sql è vuoto o non leggibile.');
        }

        \$schema = \$this->parseSchemaSql(\$sql);
        \$migration = \$this->buildMigrationSql(\$schema, \$targetTable);
        \$migrationSql = trim(implode(";\n\n", \$migration['statements']));
        if (\$migrationSql !== '') \$migrationSql .= ";\n";
        \$forceApply = \$targetTable !== '';

        \$updatePath = __DIR__ . DIRECTORY_SEPARATOR . self::UPDATE_FILE;
        \$header = '-- File generato automaticamente da db.php v1.2' . PHP_EOL
            . '-- App: ' . self::APP_NAME . PHP_EOL
            . '-- Creato il: ' . date('Y-m-d H:i:s') . PHP_EOL
            . '-- SHA-256 schema.sql: ' . \$hash . PHP_EOL . PHP_EOL;
        file_put_contents(\$updatePath, \$header . (\$migrationSql !== '' ? \$migrationSql : '-- Nessuna istruzione necessaria.' . PHP_EOL), LOCK_EX);

        \$executed = 0;
        \$operationReport = [];
        if ((self::AUTO_APPLY_MIGRATION || \$forceApply) && \$migrationSql !== '') {
            foreach (\$migration['statements'] as \$statement) {
                \$statement = trim((string)\$statement);
                if (\$statement === '') continue;
                try {
                    \$this->pdo->exec(\$statement);
                    \$executed++;
                    \$operationReport[] = ['statement' => \$statement, 'status' => 'ok', 'error' => null];
                } catch (Throwable \$statementError) {
                    \$operationReport[] = ['statement' => \$statement, 'status' => 'fail', 'error' => \$statementError->getMessage()];
                    throw \$statementError;
                }
            }
        } else {
            foreach (\$migration['statements'] as \$statement) {
                \$statement = trim((string)\$statement);
                if (\$statement === '') continue;
                \$operationReport[] = ['statement' => \$statement, 'status' => 'pending', 'error' => null];
            }
        }

        \$syncedFiles = self::SYNC_SCHEMA_FILES ? \$this->syncDuplicateSchemaFiles(\$schemaPath) : 0;

        \$message = \$lastHash === \$hash && count(\$migration['statements']) === 0
            ? 'Schema già allineato.'
            : 'Schema verificato e migrazione calcolata.';

        \$this->execute(
            'INSERT INTO `' . self::SYNC_TABLE . '`
                (file_name, schema_hash, applied_at, statements_generated, statements_executed, tables_created, columns_added, columns_modified, columns_dropped, schema_files_synced, last_message)
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                schema_hash = VALUES(schema_hash),
                applied_at = VALUES(applied_at),
                statements_generated = VALUES(statements_generated),
                statements_executed = VALUES(statements_executed),
                tables_created = VALUES(tables_created),
                columns_added = VALUES(columns_added),
                columns_modified = VALUES(columns_modified),
                columns_dropped = VALUES(columns_dropped),
                schema_files_synced = VALUES(schema_files_synced),
                last_message = VALUES(last_message)',
            [
                self::SCHEMA_FILE,
                \$hash,
                count(\$migration['statements']),
                \$executed,
                \$migration['tables_created'],
                \$migration['columns_added'],
                \$migration['columns_modified'],
                \$migration['columns_dropped'],
                \$syncedFiles,
                \$message
            ]
        );

        return [
            'schema_hash' => \$hash,
            'statements_generated' => count(\$migration['statements']),
            'statements_executed' => \$executed,
            'tables_created' => \$migration['tables_created'],
            'columns_added' => \$migration['columns_added'],
            'columns_modified' => \$migration['columns_modified'],
            'columns_dropped' => \$migration['columns_dropped'],
            'tables_dropped' => \$migration['tables_dropped'],
            'schema_files_synced' => \$syncedFiles,
            'operations_report' => \$operationReport,
            'message' => \$message,
        ];
    }

    private function ensureSyncTable(): void {
        \$this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::SYNC_TABLE . '` (
                `file_name` VARCHAR(100) NOT NULL,
                `schema_hash` CHAR(64) NOT NULL,
                `applied_at` DATETIME NOT NULL,
                `statements_generated` INT NOT NULL DEFAULT 0,
                `statements_executed` INT NOT NULL DEFAULT 0,
                `tables_created` INT NOT NULL DEFAULT 0,
                `columns_added` INT NOT NULL DEFAULT 0,
                `columns_modified` INT NOT NULL DEFAULT 0,
                `columns_dropped` INT NOT NULL DEFAULT 0,
                `schema_files_synced` INT NOT NULL DEFAULT 0,
                `last_message` TEXT NULL,
                PRIMARY KEY (`file_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Aggiornamento non distruttivo della tabella tecnica già creata da versioni precedenti.
        \$columns = [
            'applied_at' => '`applied_at` DATETIME NULL',
            'statements_generated' => '`statements_generated` INT NOT NULL DEFAULT 0',
            'statements_executed' => '`statements_executed` INT NOT NULL DEFAULT 0',
            'tables_created' => '`tables_created` INT NOT NULL DEFAULT 0',
            'columns_added' => '`columns_added` INT NOT NULL DEFAULT 0',
            'columns_modified' => '`columns_modified` INT NOT NULL DEFAULT 0',
            'columns_dropped' => '`columns_dropped` INT NOT NULL DEFAULT 0',
            'schema_files_synced' => '`schema_files_synced` INT NOT NULL DEFAULT 0',
            'last_message' => '`last_message` TEXT NULL',
        ];

        foreach (\$columns as \$column => \$definition) {
            \$exists = \$this->fetchColumn(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [self::SYNC_TABLE, \$column]
            );
            if (!\$exists) {
                \$this->pdo->exec('ALTER TABLE `' . self::SYNC_TABLE . '` ADD COLUMN ' . \$definition);
            }
        }
    }

    private function parseSchemaSql(string \$sql): array {
        \$statements = \$this->splitSqlStatements(\$sql);
        \$tables = [];
        foreach (\$statements as \$statement) {
            \$statement = trim(\$statement);
            if (!preg_match('/^CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?`([^`]+)`/i', \$statement, \$m)) continue;
            \$table = \$m[1];
            \$body = \$this->extractCreateBody(\$statement);
            if (\$body === null) continue;
            \$columns = [];
            foreach (\$this->splitTopLevelComma(\$body) as \$part) {
                \$part = trim(\$part);
                if (preg_match('/^`([^`]+)`\\s+(.+)$/s', \$part, \$cm)) {
                    \$columns[\$cm[1]] = '`' . str_replace('`', '``', \$cm[1]) . '` ' . trim(\$cm[2]);
                }
            }
            \$tables[\$table] = ['create' => \$this->ensureCreateIfNotExists(\$statement), 'columns' => \$columns];
        }
        return ['tables' => \$tables];
    }

    private function buildMigrationSql(array \$schema, string \$targetTable = ''): array {
        \$statements = [];
        \$tablesCreated = 0;
        \$columnsAdded = 0;
        \$columnsModified = 0;
        \$columnsDropped = 0;
        \$tablesDropped = 0;

        if (\$targetTable !== '') {
            \$matchedTable = false;
            foreach (\$schema['tables'] as \$tableName => \$tableSchema) {
                if (strcasecmp((string)\$tableName, \$targetTable) !== 0) {
                    continue;
                }
                \$matchedTable = true;
                \$referencingFks = \$this->loadInboundForeignKeys(\$tableName);
                foreach (\$referencingFks as \$fk) {
                    \$statements[] = 'ALTER TABLE `' . str_replace('`', '``', (string)\$fk['table_name']) . '` DROP FOREIGN KEY `' . str_replace('`', '``', (string)\$fk['constraint_name']) . '`';
                }
                if (\$this->tableExists(\$tableName)) {
                    \$statements[] = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', \$tableName) . '`';
                    \$tablesDropped++;
                }
                \$statements[] = \$tableSchema['create'];
                \$tablesCreated++;
                foreach (\$referencingFks as \$fk) {
                    \$addFk = \$this->buildForeignKeyAddStatement(\$fk);
                    if (\$addFk !== '') {
                        \$statements[] = \$addFk;
                    }
                }
                return [
                    'statements' => \$statements,
                    'tables_created' => \$tablesCreated,
                    'columns_added' => \$columnsAdded,
                    'columns_modified' => \$columnsModified,
                    'columns_dropped' => \$columnsDropped,
                    'tables_dropped' => \$tablesDropped,
                ];
            }

            if (!\$matchedTable) {
                \$referencingFks = \$this->loadInboundForeignKeys(\$targetTable);
                foreach (\$referencingFks as \$fk) {
                    \$statements[] = 'ALTER TABLE `' . str_replace('`', '``', (string)\$fk['table_name']) . '` DROP FOREIGN KEY `' . str_replace('`', '``', (string)\$fk['constraint_name']) . '`';
                }
                if (\$this->tableExists(\$targetTable)) {
                    \$statements[] = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', \$targetTable) . '`';
                    \$tablesDropped++;
                }
                foreach (\$referencingFks as \$fk) {
                    \$addFk = \$this->buildForeignKeyAddStatement(\$fk);
                    if (\$addFk !== '') {
                        \$statements[] = \$addFk;
                    }
                }
                return [
                    'statements' => \$statements,
                    'tables_created' => 0,
                    'columns_added' => 0,
                    'columns_modified' => 0,
                    'columns_dropped' => 0,
                    'tables_dropped' => \$tablesDropped,
                ];
            }

            return [
                'statements' => [],
                'tables_created' => 0,
                'columns_added' => 0,
                'columns_modified' => 0,
                'columns_dropped' => 0,
                'tables_dropped' => 0,
            ];
        }

        foreach (\$schema['tables'] as \$tableName => \$tableSchema) {
            if (!\$this->tableExists(\$tableName)) {
                \$statements[] = \$tableSchema['create'];
                \$tablesCreated++;
                continue;
            }

            \$currentColumns = \$this->loadCurrentColumns(\$tableName);
            foreach (\$tableSchema['columns'] as \$columnName => \$definition) {
                if (!isset(\$currentColumns[\$columnName])) {
                    \$statements[] = 'ALTER TABLE `' . str_replace('`', '``', \$tableName) . '` ADD COLUMN ' . \$definition;
                    \$columnsAdded++;
                    \$currentColumns[\$columnName] = [
                        'definition' => \$definition,
                        'column_type' => '',
                        'nullable' => 'YES',
                        'default' => null,
                        'extra' => '',
                    ];
                    continue;
                }

                if (self::MODIFY_CHANGED_COLUMNS) {
                    \$current = \$this->normalizeDefinitionForCompare(\$currentColumns[\$columnName]['definition']);
                    \$desired = \$this->normalizeDefinitionForCompare(\$definition);
                    if (\$current !== \$desired) {
                        \$statements[] = 'ALTER TABLE `' . str_replace('`', '``', \$tableName) . '` MODIFY COLUMN ' . \$definition;
                        \$columnsModified++;
                    }
                }
            }

            foreach (\$currentColumns as \$columnName => \$columnInfo) {
                if (isset(\$tableSchema['columns'][\$columnName])) continue;
                if (self::DROP_EXTRA_COLUMNS) {
                    \$statements[] = 'ALTER TABLE `' . str_replace('`', '``', \$tableName) . '` DROP COLUMN `' . str_replace('`', '``', \$columnName) . '`';
                    \$columnsDropped++;
                } elseif (self::MAKE_EXTRA_NOT_NULL_COLUMNS_NULLABLE && \$columnInfo['nullable'] === 'NO' && \$columnInfo['default'] === null && stripos((string)\$columnInfo['extra'], 'auto_increment') === false) {
                    \$type = \$columnInfo['column_type'];
                    \$statements[] = 'ALTER TABLE `' . str_replace('`', '``', \$tableName) . '` MODIFY COLUMN `' . str_replace('`', '``', \$columnName) . '` ' . \$type . ' NULL DEFAULT NULL';
                    \$columnsModified++;
                }
            }
        }

        if (self::DROP_EXTRA_TABLES) {
            \$schemaTables = array_fill_keys(array_keys(\$schema['tables']), true);
            foreach (\$this->loadCurrentTables() as \$currentTable) {
                if (isset(\$schemaTables[\$currentTable])) continue;
                if (in_array(\$currentTable, self::IGNORE_TABLES, true)) continue;
                \$statements[] = 'DROP TABLE `' . str_replace('`', '``', \$currentTable) . '`';
                \$tablesDropped++;
            }
        }

        return [
            'statements' => \$statements,
            'tables_created' => \$tablesCreated,
            'columns_added' => \$columnsAdded,
            'columns_modified' => \$columnsModified,
            'columns_dropped' => \$columnsDropped,
            'tables_dropped' => \$tablesDropped,
        ];
    }

    private function tableExists(string \$table): bool {
        return (int)\$this->fetchColumn(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [\$table]
        ) > 0;
    }

    private function loadCurrentTables(): array {
        \$rows = \$this->fetchAll(
            'SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = \'BASE TABLE\'
             ORDER BY TABLE_NAME',
            []
        );
        \$tables = [];
        foreach (\$rows as \$row) {
            \$name = (string)(\$row['TABLE_NAME'] ?? '');
            if (\$name === '') continue;
            \$tables[] = \$name;
        }
        return \$tables;
    }

    private function loadCurrentColumns(string \$table): array {
        \$rows = \$this->fetchAll(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [\$table]
        );
        \$columns = [];
        foreach (\$rows as \$row) {
            \$definition = '`' . str_replace('`', '``', (string)\$row['COLUMN_NAME']) . '` ' . (string)\$row['COLUMN_TYPE'];
            \$definition .= ((string)\$row['IS_NULLABLE'] === 'NO') ? ' NOT NULL' : ' NULL';
            if (\$row['COLUMN_DEFAULT'] !== null) {
                \$default = (string)\$row['COLUMN_DEFAULT'];
                if (strtoupper(\$default) === 'CURRENT_TIMESTAMP') {
                    \$definition .= ' DEFAULT CURRENT_TIMESTAMP';
                } else {
                    \$definition .= ' DEFAULT ' . \$this->pdo->quote(\$default);
                }
            }
            if (stripos((string)\$row['EXTRA'], 'on update CURRENT_TIMESTAMP') !== false) {
                \$definition .= ' ON UPDATE CURRENT_TIMESTAMP';
            } elseif (stripos((string)\$row['EXTRA'], 'auto_increment') !== false) {
                \$definition .= ' AUTO_INCREMENT';
            }
            \$columns[(string)\$row['COLUMN_NAME']] = [
                'definition' => \$definition,
                'column_type' => (string)\$row['COLUMN_TYPE'],
                'nullable' => (string)\$row['IS_NULLABLE'],
                'default' => \$row['COLUMN_DEFAULT'],
                'extra' => (string)\$row['EXTRA'],
            ];
        }
        return \$columns;
    }

    private function loadInboundForeignKeys(string \$referencedTable): array {
        \$rows = \$this->fetchAll(
            'SELECT
                tc.TABLE_NAME AS table_name,
                tc.CONSTRAINT_NAME AS constraint_name,
                kcu.COLUMN_NAME AS column_name,
                kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
                kcu.REFERENCED_COLUMN_NAME AS referenced_column_name,
                kcu.ORDINAL_POSITION AS ordinal_position,
                rc.UPDATE_RULE AS update_rule,
                rc.DELETE_RULE AS delete_rule,
                rc.MATCH_OPTION AS match_option
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
             INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
               AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
               AND tc.TABLE_NAME = kcu.TABLE_NAME
             LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON tc.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
               AND tc.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE()
               AND tc.CONSTRAINT_TYPE = \'FOREIGN KEY\'
               AND kcu.REFERENCED_TABLE_NAME = ?
             ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [\$referencedTable]
        );

        \$grouped = [];
        foreach (\$rows as \$row) {
            \$tableName = (string)(\$row['table_name'] ?? '');
            \$constraintName = (string)(\$row['constraint_name'] ?? '');
            if (\$tableName === '' || \$constraintName === '') {
                continue;
            }
            \$key = \$tableName . '::' . \$constraintName;
            if (!isset(\$grouped[\$key])) {
                \$grouped[\$key] = [
                    'table_name' => \$tableName,
                    'constraint_name' => \$constraintName,
                    'columns' => [],
                    'referenced_columns' => [],
                    'referenced_table_name' => (string)(\$row['referenced_table_name'] ?? \$referencedTable),
                    'update_rule' => (string)(\$row['update_rule'] ?? 'RESTRICT'),
                    'delete_rule' => (string)(\$row['delete_rule'] ?? 'RESTRICT'),
                    'match_option' => (string)(\$row['match_option'] ?? 'SIMPLE'),
                ];
            }
            \$grouped[\$key]['columns'][] = (string)(\$row['column_name'] ?? '');
            \$grouped[\$key]['referenced_columns'][] = (string)(\$row['referenced_column_name'] ?? '');
        }

        return array_values(\$grouped);
    }

    private function buildForeignKeyAddStatement(array \$fk): string {
        \$tableName = (string)(\$fk['table_name'] ?? '');
        \$constraintName = (string)(\$fk['constraint_name'] ?? '');
        \$columns = array_values(array_filter(array_map('trim', (array)(\$fk['columns'] ?? [])), static fn(string \$v): bool => \$v !== ''));
        \$referencedColumns = array_values(array_filter(array_map('trim', (array)(\$fk['referenced_columns'] ?? [])), static fn(string \$v): bool => \$v !== ''));
        \$referencedTable = (string)(\$fk['referenced_table_name'] ?? '');
        if (\$tableName === '' || \$constraintName === '' || !\$columns || !\$referencedColumns || \$referencedTable === '') {
            return '';
        }

        \$columnSql = '`' . implode('`,`', array_map(static fn(string \$v): string => str_replace('`', '``', \$v), \$columns)) . '`';
        \$referencedColumnSql = '`' . implode('`,`', array_map(static fn(string \$v): string => str_replace('`', '``', \$v), \$referencedColumns)) . '`';
        \$statement = 'ALTER TABLE `' . str_replace('`', '``', \$tableName) . '` ADD CONSTRAINT `' . str_replace('`', '``', \$constraintName) . '` FOREIGN KEY (' . \$columnSql . ') REFERENCES `' . str_replace('`', '``', \$referencedTable) . '` (' . \$referencedColumnSql . ')';
        \$matchOption = strtoupper(trim((string)(\$fk['match_option'] ?? 'SIMPLE')));
        if (\$matchOption !== '' && \$matchOption !== 'SIMPLE' && \$matchOption !== 'NONE') {
            \$statement .= ' MATCH ' . \$matchOption;
        }
        \$deleteRule = strtoupper(trim((string)(\$fk['delete_rule'] ?? 'RESTRICT')));
        \$updateRule = strtoupper(trim((string)(\$fk['update_rule'] ?? 'RESTRICT')));
        if (\$deleteRule !== '') {
            \$statement .= ' ON DELETE ' . \$deleteRule;
        }
        if (\$updateRule !== '') {
            \$statement .= ' ON UPDATE ' . \$updateRule;
        }
        return \$statement;
    }

    private function syncDuplicateSchemaFiles(string \$masterPath): int {
        \$root = realpath(__DIR__);
        \$master = realpath(\$masterPath);
        if (!\$root || !\$master) return 0;
        \$content = file_get_contents(\$master);
        if (!is_string(\$content)) return 0;
        \$count = 0;
        \$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\$root, FilesystemIterator::SKIP_DOTS));
        foreach (\$iterator as \$file) {
            if (!\$file->isFile() || \$file->getFilename() !== self::SCHEMA_FILE) continue;
            \$path = \$file->getRealPath();
            if (!is_string(\$path) || \$path === \$master) continue;
            if (strpos(\$path, DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR) !== false) continue;
            if (file_put_contents(\$path, \$content, LOCK_EX) !== false) \$count++;
        }
        return \$count;
    }

    private function outputJson(array \$payload, int \$statusCode = 200): void {
        if (!headers_sent()) {
            http_response_code(\$statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(\$payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function outputSelfTest(): void {
        \$schemaPath = __DIR__ . DIRECTORY_SEPARATOR . self::SCHEMA_FILE;
        \$schemaSql = is_file(\$schemaPath) ? (string)file_get_contents(\$schemaPath) : '';
        \$schema = \$schemaSql !== '' ? \$this->parseSchemaSql(\$schemaSql) : ['tables' => []];
        \$migration = \$schemaSql !== '' ? \$this->buildMigrationSql(\$schema) : ['statements'=>[], 'tables_created'=>0, 'columns_added'=>0, 'columns_modified'=>0, 'columns_dropped'=>0];
        \$sync = null;
        try { \$this->ensureSyncTable(); \$sync = \$this->fetch('SELECT * FROM `' . self::SYNC_TABLE . '` WHERE file_name = ? LIMIT 1', [self::SCHEMA_FILE]); } catch (Throwable \$e) {}
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'app' => self::APP_NAME,
            'db_connected' => true,
            'schema_file_exists' => is_file(\$schemaPath),
            'schema_sha256' => is_file(\$schemaPath) ? hash_file('sha256', \$schemaPath) : null,
            'auto_apply' => self::AUTO_APPLY_MIGRATION,
            'modify_changed_columns' => self::MODIFY_CHANGED_COLUMNS,
            'drop_extra_columns' => self::DROP_EXTRA_COLUMNS,
            'drop_extra_tables' => self::DROP_EXTRA_TABLES,
            'make_extra_not_null_columns_nullable' => self::MAKE_EXTRA_NOT_NULL_COLUMNS_NULLABLE,
            'tables_in_schema' => array_keys(\$schema['tables']),
            'pending_statements' => count(\$migration['statements']),
            'migration_stats' => [
                'tables_created' => \$migration['tables_created'],
                'columns_added' => \$migration['columns_added'],
                'columns_modified' => \$migration['columns_modified'],
                'columns_dropped' => \$migration['columns_dropped'],
                'tables_dropped' => \$migration['tables_dropped'],
            ],
            'schema_update_file' => self::UPDATE_FILE,
            'sync_record' => \$sync,
            'time' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function ensureCreateIfNotExists(string \$statement): string {
        return preg_replace('/^CREATE\\s+TABLE\\s+(?!IF\\s+NOT\\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', \$statement, 1) ?: \$statement;
    }

    private function extractCreateBody(string \$statement): ?string {
        \$start = strpos(\$statement, '(');
        if (\$start === false) return null;
        \$level = 0; \$inSingle = false; \$inDouble = false; \$escape = false; \$len = strlen(\$statement);
        for (\$i = \$start; \$i < \$len; \$i++) {
            \$ch = \$statement[\$i];
            if (\$escape) { \$escape = false; continue; }
            if (\$ch === '\\\\') { \$escape = true; continue; }
            if (\$ch === "'" && !\$inDouble) { \$inSingle = !\$inSingle; continue; }
            if (\$ch === '"' && !\$inSingle) { \$inDouble = !\$inDouble; continue; }
            if (\$inSingle || \$inDouble) continue;
            if (\$ch === '(') \$level++;
            if (\$ch === ')') {
                \$level--;
                if (\$level === 0) return substr(\$statement, \$start + 1, \$i - \$start - 1);
            }
        }
        return null;
    }

    private function splitTopLevelComma(string \$text): array {
        \$parts = []; \$buffer = ''; \$level = 0; \$inSingle = false; \$inDouble = false; \$escape = false; \$len = strlen(\$text);
        for (\$i = 0; \$i < \$len; \$i++) {
            \$ch = \$text[\$i]; \$buffer .= \$ch;
            if (\$escape) { \$escape = false; continue; }
            if (\$ch === '\\\\') { \$escape = true; continue; }
            if (\$ch === "'" && !\$inDouble) { \$inSingle = !\$inSingle; continue; }
            if (\$ch === '"' && !\$inSingle) { \$inDouble = !\$inDouble; continue; }
            if (\$inSingle || \$inDouble) continue;
            if (\$ch === '(') \$level++;
            if (\$ch === ')') \$level--;
            if (\$ch === ',' && \$level === 0) { \$parts[] = substr(\$buffer, 0, -1); \$buffer = ''; }
        }
        if (trim(\$buffer) !== '') \$parts[] = \$buffer;
        return \$parts;
    }

    private function splitSqlStatements(string \$sql): array {
        \$sql = preg_replace('/\\/\\*![0-9]{5}\\s*(.*?)\\s*\\*\\//s', '\\1', \$sql);
        \$sql = preg_replace('/\\/\\*.*?\\*\\//s', '', \$sql);
        \$lines = preg_split('/\\R/', \$sql) ?: [];
        \$clean = [];
        foreach (\$lines as \$line) {
            \$trim = ltrim(\$line);
            if (str_starts_with(\$trim, '--') || str_starts_with(\$trim, '#')) continue;
            \$clean[] = \$line;
        }
        \$sql = implode(PHP_EOL, \$clean);
        \$statements = []; \$buffer = ''; \$inSingle = false; \$inDouble = false; \$escape = false; \$len = strlen(\$sql);
        for (\$i = 0; \$i < \$len; \$i++) {
            \$ch = \$sql[\$i]; \$buffer .= \$ch;
            if (\$escape) { \$escape = false; continue; }
            if (\$ch === '\\\\') { \$escape = true; continue; }
            if (\$ch === "'" && !\$inDouble) { \$inSingle = !\$inSingle; continue; }
            if (\$ch === '"' && !\$inSingle) { \$inDouble = !\$inDouble; continue; }
            if (\$ch === ';' && !\$inSingle && !\$inDouble) { \$statements[] = substr(\$buffer, 0, -1); \$buffer = ''; }
        }
        if (trim(\$buffer) !== '') \$statements[] = \$buffer;
        return \$statements;
    }

    private function normalizeDefinitionForCompare(string \$definition): string {
        \$definition = strtolower(trim(preg_replace('/\\s+/', ' ', \$definition) ?: \$definition));
        \$definition = str_replace(['`', ' integer '], ['', ' int '], \$definition);
        return \$definition;
    }

    public function fetchAll(\$sql, \$params = []) { \$stmt = \$this->pdo->prepare(\$sql); \$stmt->execute(\$params); return \$stmt->fetchAll(); }
    public function fetch(\$sql, \$params = []) { \$stmt = \$this->pdo->prepare(\$sql); \$stmt->execute(\$params); return \$stmt->fetch(); }
    public function fetchColumn(\$sql, \$params = []) { \$stmt = \$this->pdo->prepare(\$sql); \$stmt->execute(\$params); return \$stmt->fetchColumn(); }
    public function execute(\$sql, \$params = []) { \$stmt = \$this->pdo->prepare(\$sql); \$stmt->execute(\$params); return \$stmt->rowCount(); }
    public function lastInsertId() { return \$this->pdo->lastInsertId(); }
    public function beginTransaction() { return \$this->pdo->beginTransaction(); }
    public function commit() { return \$this->pdo->commit(); }
    public function rollBack() { if (\$this->pdo->inTransaction()) return \$this->pdo->rollBack(); return false; }
    public function inTransaction() { return \$this->pdo->inTransaction(); }
    public function pdo(): PDO { return \$this->pdo; }
}

\$db = new Database();
PHPDB;
}



if (!function_exists('destDbCleanupDbPhpBackups')) {
    function destDbCleanupDbPhpBackups(string $targetFile, int $keep = 2): void {
        $dir = dirname($targetFile);
        $base = basename($targetFile);
        if (!is_dir($dir)) return;

        $backups = [];
        foreach (new DirectoryIterator($dir) as $item) {
            if (!$item->isFile()) continue;
            $name = $item->getFilename();
            if (!preg_match('/^' . preg_quote($base, '/') . '\.backup_\d{8}_\d{6}$/', $name)) continue;
            $backups[] = [
                'path' => $item->getPathname(),
                'name' => $name,
                'mtime' => $item->getMTime(),
            ];
        }

        usort($backups, function(array $a, array $b): int {
            if ($a['mtime'] === $b['mtime']) {
                return strcmp($b['name'], $a['name']);
            }
            return $b['mtime'] <=> $a['mtime'];
        });

        foreach (array_slice($backups, max(0, $keep)) as $backup) {
            @unlink($backup['path']);
        }
    }
}

if (!function_exists('destDbEnsureGeneratedDbPhp')) {
    function destDbEnsureGeneratedDbPhp($db, int $projectId, string $projectName, string $projectRoot): array {
        destDbEnsureConfigTable($db);
        $schemaPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'schema.sql';
        if (!is_file($schemaPath)) {
            return ['generated' => false, 'message' => 'schema.sql assente: db.php non rigenerato.'];
        }
        $config = destDbLoadConfig($db, $projectId);
        if (!$config) {
            throw new RuntimeException('Parametri DB destinatario non configurati per questo progetto. Aprire Pubblica su Altervista, sezione Database destinatario, e salvarli una sola volta.');
        }
        $appName = strtolower(trim($projectName));
        $appName = str_replace([' ', '.', ',', '!', '?'], '_', $appName);
        $appName = preg_replace('/[^a-z0-9_]/', '', $appName) ?: 'progetto_senza_nome';
        $code = destDbBuildDbPhp(destDbConfigForGeneration($config, $appName));
        $targetFile = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'db.php';
        $hash = hash('sha256', $code);
        $existingHash = is_file($targetFile) ? hash_file('sha256', $targetFile) : '';
        if ($existingHash !== $hash) {
            if (is_file($targetFile)) {
                @copy($targetFile, $targetFile . '.backup_' . date('Ymd_His'));
                destDbCleanupDbPhpBackups($targetFile, 2);
            }
            if (file_put_contents($targetFile, $code, LOCK_EX) === false) {
                throw new RuntimeException('Impossibile aggiornare db.php nella cartella progetto.');
            }
            $db->execute(
                'UPDATE progetti_db_destinatario SET ultimo_db_php_hash = ?, ultima_generazione = NOW() WHERE IDprogetto = ?',
                [$hash, $projectId]
            );
            return ['generated' => true, 'message' => 'db.php rigenerato automaticamente.'];
        }
        $db->execute(
            'UPDATE progetti_db_destinatario SET ultimo_db_php_hash = ?, ultima_generazione = NOW() WHERE IDprogetto = ?',
            [$hash, $projectId]
        );
        destDbCleanupDbPhpBackups($targetFile, 2);
        return ['generated' => false, 'message' => 'db.php già allineato al CRUD.'];
    }
}

/* === FINE CONFIGURAZIONE DB DESTINATARIO INTEGRATA === */


if (!function_exists('sanitizeFolderName')) {
    function sanitizeFolderName($name) {
        $name = strtolower(trim($name));
        $name = str_replace(array(' ', '.', ',', '!', '?'), '_', $name);
        $name = preg_replace('/[^a-z0-9\_]/', '', $name);
        return $name ?: 'progetto_senza_nome';
    }
}

if (!function_exists('deletePathRecursive')) {
    function deletePathRecursive($path) {
        if (is_file($path) || is_link($path)) {
            return unlink($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        $items = array_diff(scandir($path), array('.', '..'));
        foreach ($items as $item) {
            if (!deletePathRecursive($path . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($path);
    }
}

if (!function_exists('resolveProjectPath')) {
    function resolveProjectPath($root_path, $relative_path, $must_exist = true) {
        $relative_path = str_replace('\\', '/', (string)$relative_path);
        $parts = array_filter(explode('/', $relative_path), function($part) {
            return $part !== '' && $part !== '.' && $part !== '..';
        });
        $safe_relative = implode(DIRECTORY_SEPARATOR, $parts);
        $candidate = rtrim($root_path, DIRECTORY_SEPARATOR) . ($safe_relative ? DIRECTORY_SEPARATOR . $safe_relative : '');

        if ($must_exist) {
            $resolved = realpath($candidate);
            $resolved_root = realpath($root_path);
            if (!$resolved || !$resolved_root || ($resolved !== $resolved_root && strpos($resolved, $resolved_root . DIRECTORY_SEPARATOR) !== 0)) {
                return false;
            }
            return $resolved;
        }

        $parent = realpath(dirname($candidate));
        $resolved_root = realpath($root_path);
        if (!$parent || !$resolved_root || ($parent !== $resolved_root && strpos($parent, $resolved_root . DIRECTORY_SEPARATOR) !== 0)) {
            return false;
        }

        return $parent . DIRECTORY_SEPARATOR . basename($candidate);
    }
}

if (!function_exists('redirectCartellaProgetto')) {
    function redirectCartellaProgetto($path = '', $view = '') {
        $query = "index.php?page=cartella_progetto&path=" . urlencode($path);
        $currentView = $view !== '' ? $view : (string)($_GET['view'] ?? '');
        if ($currentView !== '') {
            $query .= "&view=" . urlencode($currentView);
        }
        header("Location: " . $query . "&t=" . time());
        exit;
    }
}

if (!function_exists('pathIsInsideOrSame')) {
    function pathIsInsideOrSame($path, $parent) {
        $path = rtrim((string)$path, DIRECTORY_SEPARATOR);
        $parent = rtrim((string)$parent, DIRECTORY_SEPARATOR);
        return $path === $parent || strpos($path, $parent . DIRECTORY_SEPARATOR) === 0;
    }
}

if (!function_exists('loadLinkedVisualizationPages')) {
    function loadLinkedVisualizationPages($db, $project_id, $target_path) {
        $target_real = realpath($target_path);
        if (!$target_real) {
            return [];
        }

        $target_name = basename($target_real);
        $is_dir = is_dir($target_real);
        $pages = $db->fetchAll(
            "SELECT id, nome_file, percorso_file
             FROM pagine_visualizzazione
             WHERE IDprogetto = ?",
            [$project_id]
        );

        $linked = [];
        foreach ($pages as $page) {
            $stored_path = trim((string)($page['percorso_file'] ?? ''));
            $stored_real = $stored_path !== '' ? realpath($stored_path) : false;
            $matches_path = false;

            if ($stored_real) {
                $matches_path = $is_dir
                    ? pathIsInsideOrSame($stored_real, $target_real)
                    : $stored_real === $target_real;
            }

            $matches_name = !$is_dir && (string)($page['nome_file'] ?? '') === $target_name;

            if ($matches_path || $matches_name) {
                $page['stored_real'] = $stored_real ?: '';
                $linked[] = $page;
            }
        }

        return $linked;
    }
}

if (!function_exists('deleteVisualizationPageRecords')) {
    function deleteVisualizationPageRecords($db, $project_id, $pages) {
        $deletedFiles = [];
        foreach ($pages as $page) {
            $page_id = (int)$page['id'];
            $page_file = trim((string)($page['nome_file'] ?? ''));
            if ($page_file !== '') {
                $deletedFiles[] = $page_file;
            }
            $db->execute(
                "UPDATE pagine_visualizzazione_campi
                 SET link_pagina_id = NULL
                 WHERE link_pagina_id = ?",
                [$page_id]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione_modali
                 WHERE IDpagina = ?",
                [$page_id]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione_campi
                 WHERE IDpagina = ?",
                [$page_id]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione_tabelle
                 WHERE IDpagina = ?",
                [$page_id]
            );
            $db->execute(
                "DELETE FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$page_id, $project_id]
            );
        }

        $deletedFiles = array_values(array_unique(array_filter($deletedFiles)));
        if (!empty($deletedFiles)) {
            $placeholders = implode(', ', array_fill(0, count($deletedFiles), '?'));
            $params = array_merge([$project_id], $deletedFiles);
            $db->execute(
                "DELETE FROM menu_home_voci
                 WHERE IDprogetto = ?
                   AND nome_file IN ($placeholders)",
                $params
            );
        }
    }
}

if (!function_exists('updateVisualizationPageRecordsAfterRename')) {
    function updateVisualizationPageRecordsAfterRename($db, $project_id, $pages, $old_path, $new_path) {
        $old_real = realpath($old_path);
        $is_dir = is_dir($old_path);

        foreach ($pages as $page) {
            $page_id = (int)$page['id'];
            $stored_real = (string)($page['stored_real'] ?? '');
            $new_stored_path = $new_path;

            if ($is_dir && $old_real && $stored_real !== '') {
                $suffix = substr($stored_real, strlen(rtrim($old_real, DIRECTORY_SEPARATOR)));
                $new_stored_path = rtrim($new_path, DIRECTORY_SEPARATOR) . $suffix;
            }

            if ($is_dir) {
                $db->execute(
                    "UPDATE pagine_visualizzazione
                     SET percorso_file = ?
                     WHERE id = ? AND IDprogetto = ?",
                    [$new_stored_path, $page_id, $project_id]
                );
            } else {
                $db->execute(
                    "UPDATE pagine_visualizzazione
                     SET nome_file = ?, percorso_file = ?
                     WHERE id = ? AND IDprogetto = ?",
                    [basename($new_path), $new_stored_path, $page_id, $project_id]
                );
            }
        }
    }
}

if (!function_exists('updateHomeMenuRecordsAfterRename')) {
    function updateHomeMenuRecordsAfterRename($db, $project_id, $old_path, $new_path) {
        $old_file = basename((string)$old_path);
        $new_file = basename((string)$new_path);

        if ($old_file === '' || $new_file === '' || $old_file === $new_file) {
            return 0;
        }

        return $db->execute(
            "UPDATE menu_home_voci
             SET nome_file = ?
             WHERE IDprogetto = ?
               AND tipo = 'PAGINA'
               AND nome_file = ?",
            [$new_file, $project_id, $old_file]
        );
    }
}


if (!function_exists('deployEnsureCsrf')) {
    function deployEnsureCsrf(): string {
        if (empty($_SESSION['deploy_csrf'])) {
            $_SESSION['deploy_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['deploy_csrf'];
    }
}

if (!function_exists('deployVerifyCsrf')) {
    function deployVerifyCsrf(): void {
        $expected = (string)($_SESSION['deploy_csrf'] ?? '');
        $received = (string)($_POST['deploy_csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $received)) {
            throw new RuntimeException('Sessione scaduta o richiesta non valida. Ricaricare la pagina.');
        }
    }
}

if (!function_exists('deployNormalizeUrl')) {
    function deployNormalizeUrl(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('~^https://~i', $url)) {
            throw new RuntimeException('L’URL del ricevitore deve utilizzare HTTPS.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL del ricevitore non valido.');
        }
        return $url;
    }
}

if (!function_exists('deployNormalizeTarget')) {
    function deployNormalizeTarget(string $path): string {
        $path = trim(str_replace('\\', '/', $path));

        // Convenzione stabile:
        // - "." indica la root/base_dir configurata nel receiver.
        // - campo vuoto viene trattato come root solo lato interfaccia, per consentire
        //   la pubblicazione nella radice del sito remoto quando il receiver lo permette.
        if ($path === '' || $path === '.' || $path === '/') {
            return '.';
        }

        $path = trim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..' || !preg_match('/^[a-zA-Z0-9._-]+$/', $part)) {
                throw new RuntimeException('La cartella destinazione contiene caratteri non validi.');
            }
            $parts[] = $part;
        }

        return $parts ? implode('/', $parts) : '.';
    }
}


if (!function_exists('deployGenerateUuidV4')) {
    function deployGenerateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('deployNormalizeUuid')) {
    function deployNormalizeUuid(string $uuid): string {
        $uuid = strtolower(trim($uuid));
        if ($uuid === '') {
            return deployGenerateUuidV4();
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new RuntimeException('UUID progetto non valido.');
        }
        return $uuid;
    }
}

if (!function_exists('deployNormalizeOptionalHttpsUrl')) {
    function deployNormalizeOptionalHttpsUrl(string $url): string {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('~^https://~i', $url)) {
            throw new RuntimeException('Application URL deve utilizzare HTTPS.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Application URL non valido.');
        }
        return $url;
    }
}

if (!function_exists('deployEnsureTableColumn')) {
    function deployEnsureTableColumn($db, string $table, string $column, string $definition): void {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new RuntimeException('Nome tabella o colonna non valido per aggiornamento configurazione deploy.');
        }

        // MySQL non accetta sempre placeholder in SHOW COLUMNS LIKE su hosting condivisi.
        // Usiamo INFORMATION_SCHEMA con parametri, più compatibile con PDO/Altervista.
        $exists = $db->fetch(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $column]
        );

        if (!$exists) {
            $db->execute("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }
}

if (!function_exists('deployFormatReceiverResponseMessage')) {
    function deployFormatReceiverResponseMessage(array $response, string $fallback = 'Operazione completata.'): string {
        $message = (string)($response['message'] ?? $fallback);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        if ($stats) {
            $parts = [];
            foreach (['files_created'=>'creati', 'files_updated'=>'aggiornati', 'files_deleted'=>'eliminati', 'files_in_archive'=>'nel pacchetto'] as $key => $label) {
                if (array_key_exists($key, $stats)) {
                    $parts[] = $label . ': ' . (int)$stats[$key];
                }
            }
            if ($parts) $message .= ' File ' . implode(', ', $parts) . '.';
        }
        if (!empty($data['backup_file'])) {
            $message .= ' Backup: ' . basename((string)$data['backup_file']) . '.';
        }
        return $message;
    }
}


if (!function_exists('deployBuildApplicationBaseUrl')) {
    function deployBuildApplicationBaseUrl(string $applicationUrl, string $receiverUrl, string $deployPath): string {
        $applicationUrl = trim($applicationUrl);
        if ($applicationUrl !== '') {
            $normalized = rtrim($applicationUrl, '/') . '/';
            if ($deployPath !== '' && $deployPath !== '.') {
                $path = parse_url($normalized, PHP_URL_PATH);
                $path = is_string($path) ? trim(str_replace('\\', '/', $path), '/') : '';
                $deployPath = trim(str_replace('\\', '/', $deployPath), '/');

                // Se l'URL applicazione punta alla root del dominio, aggiungiamo la
                // cartella remota del progetto così db.php viene chiamato nel posto giusto.
                if ($path === '') {
                    return $normalized . $deployPath . '/';
                }

                // Evitiamo duplicazioni quando l'utente ha già inserito l'URL completo.
                if ($deployPath !== '' && !str_ends_with($path, $deployPath)) {
                    return $normalized . $deployPath . '/';
                }
            }
            return $normalized;
        }

        $parts = parse_url($receiverUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $base .= ':' . (int)$parts['port'];
        }

        $receiverPath = (string)($parts['path'] ?? '/');
        $receiverDir = trim(str_replace('\\', '/', dirname($receiverPath)), '/');
        $pathParts = [];
        if ($receiverDir !== '' && $receiverDir !== '.') {
            $pathParts[] = $receiverDir;
        }
        if ($deployPath !== '' && $deployPath !== '.') {
            $pathParts[] = trim($deployPath, '/');
        }

        return $base . '/' . ($pathParts ? implode('/', $pathParts) . '/' : '');
    }
}

if (!function_exists('deployTriggerRemoteDbSync')) {
    function deployTriggerRemoteDbSync(string $applicationUrl, string $receiverUrl, string $deployPath, string $targetTable = ''): array {
        $baseUrl = deployBuildApplicationBaseUrl($applicationUrl, $receiverUrl, $deployPath);
        if ($baseUrl === '') {
            throw new RuntimeException('impossibile determinare l’URL dell’applicazione per avviare l’aggiornamento DB remoto. Compilare Application URL.');
        }

        $dbUrl = rtrim($baseUrl, '/') . '/db.php?crud_db_autosync=1';
        if ($targetTable !== '') {
            $dbUrl .= '&crud_db_autosync_table=' . rawurlencode($targetTable);
            $dbUrl .= '&crud_db_autosync_force=1';
        }
        $dbUrl .= '&t=' . rawurlencode((string)time());
        if (!extension_loaded('curl')) {
            throw new RuntimeException('l’estensione PHP cURL non è disponibile per avviare l’aggiornamento DB remoto.');
        }

        $ch = curl_init($dbUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'CRUD-HTTPS-Deploy-DB-Sync/1.4',
            CURLOPT_HTTPHEADER => ['Accept: text/plain, application/json, text/html;q=0.8'],
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('errore cURL durante aggiornamento DB remoto: ' . $curlError);
        }

        $text = trim((string)$body);
        $plain = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));
        $decoded = json_decode($text, true);
        $report = [];
        if (is_array($decoded)) {
            if (!empty($decoded['data']['operations_report']) && is_array($decoded['data']['operations_report'])) {
                $report = $decoded['data']['operations_report'];
            } elseif (!empty($decoded['data']['report']) && is_array($decoded['data']['report'])) {
                $report = $decoded['data']['report'];
            } elseif (!empty($decoded['operations_report']) && is_array($decoded['operations_report'])) {
                $report = $decoded['operations_report'];
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('aggiornamento DB remoto non riuscito (HTTP ' . $status . ')' . ($plain !== '' ? ': ' . mb_substr($plain, 0, 400, 'UTF-8') : '.'));
        }

        $errorPatterns = [
            'Fatal error',
            'Parse error',
            'Warning:',
            'Aggiornamento database da schema.sql non riuscito',
            'Errore di avvio dell\'applicazione',
            'Connessione database fallita',
            'SQLSTATE',
        ];
        foreach ($errorPatterns as $pattern) {
            if (stripos($text, $pattern) !== false || stripos($plain, $pattern) !== false) {
                throw new RuntimeException('aggiornamento DB remoto non riuscito: ' . ($plain !== '' ? mb_substr($plain, 0, 500, 'UTF-8') : $pattern));
            }
        }

        return [
            'status' => $status,
            'body' => $text,
            'plain' => $plain,
            'report' => $report,
        ];
    }
}

if (!function_exists('deployKeyPath')) {
    function deployKeyPath(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'deploy.key';
    }
}

if (!function_exists('deployEncryptionKey')) {
    function deployEncryptionKey(): string {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('OpenSSL non è disponibile: impossibile cifrare il token.');
        }
        $keyPath = deployKeyPath();
        $keyDir = dirname($keyPath);
        if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
            throw new RuntimeException('Impossibile creare la cartella protetta per la chiave.');
        }
        if (!is_file($keyPath)) {
            $key = random_bytes(32);
            if (file_put_contents($keyPath, base64_encode($key), LOCK_EX) === false) {
                throw new RuntimeException('Impossibile salvare la chiave di cifratura.');
            }
            @chmod($keyPath, 0600);
            return $key;
        }
        $key = base64_decode(trim((string)file_get_contents($keyPath)), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Chiave di cifratura non valida.');
        }
        return $key;
    }
}

if (!function_exists('deployEncryptToken')) {
    function deployEncryptToken(string $token): string {
        $cipher = 'aes-256-gcm';
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $tag = '';
        $encrypted = openssl_encrypt($token, $cipher, deployEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) throw new RuntimeException('Cifratura del token non riuscita.');
        return base64_encode($iv . $tag . $encrypted);
    }
}

if (!function_exists('deployDecryptToken')) {
    function deployDecryptToken(string $payload): string {
        if ($payload === '') return '';
        $raw = base64_decode($payload, true);
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($raw === false || strlen($raw) <= $ivLength + 16) {
            throw new RuntimeException('Token cifrato non valido.');
        }
        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, 16);
        $encrypted = substr($raw, $ivLength + 16);
        $token = openssl_decrypt($encrypted, $cipher, deployEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($token === false) throw new RuntimeException('Impossibile decifrare il token salvato.');
        return $token;
    }
}

if (!function_exists('deployRelativeAllowedForDeploy')) {
    function deployRelativeAllowedForDeploy(string $relative): bool {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') return false;

        $base = basename($relative);
        $lower = strtolower($relative);
        $lowerBase = strtolower($base);

        // File tecnici, temporanei, backup e strumenti di debug: non devono finire
        // nello ZIP e quindi non devono essere pubblicati sul sito destinatario.
        $blockedExact = [
            '.deploy.json',
            'deploy_receiver.php',
            'deploy_receiver_config.php',
            'db_schema_receiver.php',
            'schema_receiver_config.php',
            'deploy_reset_check.php',
            'sw.js',
            'schema_update.sql',
            '.ftpquota',
        ];
        if (in_array($lowerBase, $blockedExact, true)) return false;

        $blockedPrefixes = [
            '_deploy/',
            '.git/',
            '.vscode/',
            '__macosx/',
        ];
        foreach ($blockedPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) return false;
        }

        if (preg_match('/(\.backup(_|\.|$)|\.bak(_|\.|$)|~$|\.tmp$|\.temp$|\.old$|\.zip$|\.log$)/i', $lowerBase)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('crudProjectExtractCreateTableMap')) {
    function crudProjectExtractCreateTableMap(string $sql): array {
        $tables = [];
        if ($sql === '') {
            return $tables;
        }
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\((.*?)\)\s*ENGINE\s*=\s*InnoDB\s*DEFAULT\s+CHARSET\s*=\s*utf8mb4.*?;/si', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $table = (string)$match[1];
                $tables[$table] = trim((string)$match[0]);
            }
        }
        return $tables;
    }
}

if (!function_exists('crudProjectBuildAlignmentSql')) {
    function crudProjectBuildAlignmentSql(array $schemaComparison, string $localSql, string $remoteSql): string {
        $localTables = crudProjectExtractCreateTableMap($localSql);
        $remoteTables = crudProjectExtractCreateTableMap($remoteSql);
        $localViews = crudProjectExtractUnifiedSchemaView($localSql);
        $remoteViews = crudProjectExtractUnifiedSchemaView($remoteSql);
        $rows = is_array($schemaComparison['rows'] ?? null) ? $schemaComparison['rows'] : [];
        $sql = [];
        $sql[] = '-- Script di allineamento generato dal confronto';
        $sql[] = '-- Obiettivo: rendere la struttura del DB destinatario identica a schema.sql.';
        $sql[] = '-- Nota: per ottenere l’allineamento completo possono essere usati più passaggi con ALTER, DROP e ricreazione dei componenti.';
        $sql[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $sql[] = '';

        $recreateTables = [];
        $dropTables = [];
        $alterStatements = [];

        foreach ($rows as $row) {
            $table = (string)($row['table'] ?? '');
            $status = (string)($row['status'] ?? '');
            $detail = trim((string)($row['detail'] ?? ''));
            if ($table === '') {
                continue;
            }

            if ($status === 'missing_remote' && isset($localTables[$table])) {
                $recreateTables[$table] = [
                    'action' => 'create',
                    'sql' => $localTables[$table],
                    'detail' => $detail,
                ];
                continue;
            }

            if ($status === 'extra_remote') {
                $dropTables[$table] = [
                    'action' => 'drop',
                    'detail' => $detail,
                ];
                continue;
            }

            if ($status === 'different') {
                $localView = $localViews[$table] ?? [];
                $remoteView = $remoteViews[$table] ?? [];
                $tableAlters = crudProjectBuildTableAlterStatements($table, $localView, $remoteView);
                if ($tableAlters) {
                    $alterStatements[$table] = $tableAlters;
                } else {
                    if (isset($remoteTables[$table])) {
                        $dropTables[$table] = [
                            'action' => 'drop',
                            'detail' => $detail,
                        ];
                    }
                    if (isset($localTables[$table])) {
                        $recreateTables[$table] = [
                            'action' => 'create',
                            'sql' => $localTables[$table],
                            'detail' => $detail,
                        ];
                    }
                }
                continue;
            }
        }

        if ($dropTables) {
            $sql[] = '-- Fase 1: rimozione dei componenti non compatibili o non più previsti';
            foreach ($dropTables as $table => $info) {
                $sql[] = '-- ' . $table . ($info['detail'] !== '' ? ' - ' . $info['detail'] : '');
                $sql[] = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`;';
            }
            $sql[] = '';
        }

        if ($recreateTables) {
            $sql[] = '-- Fase 2: ricreazione dei componenti mancanti o riallineati';
            foreach ($recreateTables as $table => $info) {
                $sql[] = '-- ' . $table . ($info['detail'] !== '' ? ' - ' . $info['detail'] : '');
                $sql[] = $info['sql'];
                $sql[] = '';
            }
        }

        if ($alterStatements) {
            $sql[] = '-- Fase 3: rifinitura con ALTER TABLE per allineare colonne, indici e vincoli residui';
            foreach ($alterStatements as $table => $stmts) {
                $sql[] = '-- ' . $table;
                foreach ($stmts as $stmt) {
                    $sql[] = $stmt;
                }
                $sql[] = '';
            }
        }

        $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';
        return implode(PHP_EOL, $sql);
    }
}

if (!function_exists('crudProjectBuildTableAlignmentSql')) {
    function crudProjectBuildTableAlignmentSql(string $tableName, array $schemaComparison, string $localSql, string $remoteSql): string {
        $tableName = trim($tableName);
        if ($tableName === '') {
            return '';
        }

        $filtered = $schemaComparison;
        $filtered['rows'] = array_values(array_filter(
            is_array($schemaComparison['rows'] ?? null) ? $schemaComparison['rows'] : [],
            static fn(array $row): bool => (string)($row['table'] ?? '') === $tableName
        ));

        $localTables = crudProjectExtractCreateTableMap($localSql);
        $remoteTables = crudProjectExtractCreateTableMap($remoteSql);

        if (isset($localTables[$tableName])) {
            $localSql = $localTables[$tableName];
        }
        if (isset($remoteTables[$tableName])) {
            $remoteSql = $remoteTables[$tableName];
        }

        return crudProjectBuildAlignmentSql($filtered, $localSql, $remoteSql);
    }
}

if (!function_exists('crudProjectBuildTableAlterStatements')) {
    function crudProjectBuildTableAlterStatements(string $tableName, array $localView, array $remoteView): array {
        $sql = [];
        if ($tableName === '') {
            return $sql;
        }

        $extractNamedParts = static function(array $parts): array {
            $map = [];
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^`([^`]+)`\s+/i', $part, $m)) {
                    $map[strtolower((string)$m[1])] = $part;
                } elseif (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX|FULLTEXT\s+KEY|SPATIAL\s+KEY|FOREIGN\s+KEY|CONSTRAINT)\b/i', $part, $m)) {
                    $map[strtolower(trim((string)$m[1] . ' ' . substr(md5($part), 0, 8)))] = $part;
                }
            }
            return $map;
        };

        $localColumnDefs = $extractNamedParts($localView['columns'] ?? []);
        $remoteColumnDefs = $extractNamedParts($remoteView['columns'] ?? []);

        $alterParts = [];

        $remoteColumnNames = array_keys($remoteColumnDefs);
        $localColumnNames = array_keys($localColumnDefs);

        foreach ($remoteColumnDefs as $name => $def) {
            if (!isset($localColumnDefs[$name])) {
                $alterParts[] = 'DROP COLUMN `' . str_replace('`', '``', $name) . '`';
                continue;
            }
            if (crudSchemaNormalizeCreate($localColumnDefs[$name]) !== crudSchemaNormalizeCreate($def)) {
                $alterParts[] = 'MODIFY COLUMN ' . $localColumnDefs[$name];
            }
        }

        foreach ($localColumnDefs as $name => $def) {
            if (!isset($remoteColumnDefs[$name])) {
                $alterParts[] = 'ADD COLUMN ' . $def;
            }
        }

        $localUnique = $localView['unique_keys'] ?? [];
        $remoteUnique = $remoteView['unique_keys'] ?? [];
        $localIdx = $localView['indexes'] ?? [];
        $remoteIdx = $remoteView['indexes'] ?? [];
        $localFk = $localView['foreign_keys'] ?? [];
        $remoteFk = $remoteView['foreign_keys'] ?? [];

        $makeDropName = static function(string $definition): string {
            if (preg_match('/^(?:UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX|FULLTEXT\s+KEY|SPATIAL\s+KEY|FOREIGN\s+KEY|CONSTRAINT)\s+`?([^`\s(]+)`?/i', $definition, $m)) {
                return '`' . str_replace('`', '``', (string)$m[1]) . '`';
            }
            return '`idx_' . substr(md5($definition), 0, 8) . '`';
        };

        $processNamedComponents = static function(array $localItems, array $remoteItems, string $dropKeyword, string $addKeyword = '') use (&$alterParts, $makeDropName): void {
            $localMap = [];
            foreach ($localItems as $item) {
                $item = trim((string)$item);
                if ($item === '') continue;
                $localMap[crudProjectNormalizeComponentSignature('IDX', $item)] = $item;
            }

            $remoteMap = [];
            foreach ($remoteItems as $item) {
                $item = trim((string)$item);
                if ($item === '') continue;
                $remoteMap[crudProjectNormalizeComponentSignature('IDX', $item)] = $item;
            }

            foreach ($remoteMap as $signature => $item) {
                if (!isset($localMap[$signature])) {
                    $alterParts[] = 'DROP ' . $dropKeyword . ' ' . $makeDropName($item);
                }
            }
            foreach ($localMap as $signature => $item) {
                if (!isset($remoteMap[$signature])) {
                    $alterParts[] = 'ADD ' . ($addKeyword !== '' ? $addKeyword . ' ' : '') . $item;
                }
            }
        };

        $processNamedComponents($localUnique, $remoteUnique, 'INDEX');
        $processNamedComponents($localIdx, $remoteIdx, 'INDEX');
        $processNamedComponents($localFk, $remoteFk, 'FOREIGN KEY');

        if ($alterParts) {
            $sql[] = '-- ALTER TABLE mirati per il componente ' . $tableName;
            $sql[] = 'ALTER TABLE `' . str_replace('`', '``', $tableName) . '`' . PHP_EOL . '  ' . implode(',' . PHP_EOL . '  ', $alterParts) . ';';
        }

        return $sql;
    }
}

if (!function_exists('crudProjectExtractUnifiedSchemaView')) {
    function crudProjectExtractUnifiedSchemaView(string $sql): array {
        $tables = [];
        if ($sql === '') {
            return $tables;
        }

        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\((.*?)\)\s*ENGINE\s*=\s*InnoDB.*?;/si', $sql, $matches, PREG_SET_ORDER)) {
            return $tables;
        }

        foreach ($matches as $match) {
            $tableName = (string)$match[1];
            $body = trim((string)$match[2]);
            $parts = preg_split('/\s*,\s*(?![^()]*\))/s', $body) ?: [];

            $columns = [];
            $primaryKeys = [];
            $uniqueKeys = [];
            $indexes = [];
            $foreignKeys = [];
            $options = [];

            foreach ($parts as $part) {
                $line = trim($part);
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^`([^`]+)`\s+([a-zA-Z0-9]+(?:\([^)]+\))?)(.*)$/s', $line, $colMatch)) {
                    $columnName = (string)$colMatch[1];
                    if (str_starts_with(strtolower($columnName), '__virtual_pvc_')) {
                        continue;
                    }
                    $columns[$columnName] = trim($line);
                    continue;
                }

                if (preg_match('/^PRIMARY\s+KEY\s*\((.*?)\)/i', $line, $pkMatch)) {
                    $primaryKeys[] = trim($line);
                    continue;
                }

                if (preg_match('/^(UNIQUE\s+KEY|UNIQUE\s+INDEX|CONSTRAINT\b.*\bUNIQUE\b)/i', $line)) {
                    $uniqueKeys[] = trim($line);
                    continue;
                }

                if (preg_match('/^(KEY|INDEX|FULLTEXT\s+KEY|SPATIAL\s+KEY)\b/i', $line)) {
                    $indexes[] = trim($line);
                    continue;
                }

                if (preg_match('/^(FOREIGN\s+KEY|CONSTRAINT\b.*\bFOREIGN\s+KEY\b)/i', $line)) {
                    $foreignKeys[] = trim($line);
                    continue;
                }

                if (preg_match('/^(ENGINE|DEFAULT\s+CHARSET|CHARSET|COLLATE|COMMENT|ROW_FORMAT)\b/i', $line)) {
                    $options[] = trim($line);
                }
            }

            $tables[$tableName] = [
                'columns' => array_values($columns),
                'primary_keys' => $primaryKeys,
                'unique_keys' => $uniqueKeys,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
                'options' => $options,
            ];
        }

        ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        return $tables;
    }
}

if (!function_exists('crudProjectBuildUnifiedSchemaRows')) {
    function crudProjectBuildUnifiedSchemaRows(array $schemaView): array {
        $rows = [];
        foreach ($schemaView as $tableName => $tableData) {
            foreach (($tableData['columns'] ?? []) as $column) {
                $rows[] = [
                    'table' => $tableName,
                    'type' => 'Campo',
                    'name' => (string)$column,
                ];
            }
            foreach (($tableData['primary_keys'] ?? []) as $pk) {
                $rows[] = [
                    'table' => $tableName,
                    'type' => 'PK',
                    'name' => (string)$pk,
                ];
            }
            foreach (($tableData['unique_keys'] ?? []) as $uq) {
                $rows[] = [
                    'table' => $tableName,
                    'type' => 'UQ',
                    'name' => (string)$uq,
                ];
            }
            foreach (($tableData['indexes'] ?? []) as $idx) {
                $rows[] = [
                    'table' => $tableName,
                    'type' => 'IDX',
                    'name' => (string)$idx,
                ];
            }
            foreach (($tableData['foreign_keys'] ?? []) as $fk) {
                $rows[] = [
                    'table' => $tableName,
                    'type' => 'FK',
                    'name' => (string)$fk,
                ];
            }
            foreach (($tableData['options'] ?? []) as $opt) {
                $rows[] = [
                    'table' => $tableName,
                    'type' => 'OPT',
                    'name' => (string)$opt,
                ];
            }
        }
        return $rows;
    }
}

if (!function_exists('crudProjectBuildTableComparisonMatrix')) {
    function crudProjectBuildTableComparisonMatrix(array $localSchemaView, array $remoteSchemaView): array {
        $tables = array_values(array_unique(array_merge(array_keys($localSchemaView), array_keys($remoteSchemaView))));
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

        $matrix = [];
        foreach ($tables as $tableName) {
            $local = $localSchemaView[$tableName] ?? ['columns' => [], 'primary_keys' => [], 'unique_keys' => [], 'indexes' => [], 'foreign_keys' => [], 'options' => []];
            $remote = $remoteSchemaView[$tableName] ?? ['columns' => [], 'primary_keys' => [], 'unique_keys' => [], 'indexes' => [], 'foreign_keys' => [], 'options' => []];

            $groups = [
                'Campi' => ['local' => array_values($local['columns'] ?? []), 'remote' => array_values($remote['columns'] ?? [])],
                'PK' => ['local' => array_values($local['primary_keys'] ?? []), 'remote' => array_values($remote['primary_keys'] ?? [])],
                'UQ' => ['local' => array_values($local['unique_keys'] ?? []), 'remote' => array_values($remote['unique_keys'] ?? [])],
                'IDX' => ['local' => array_values($local['indexes'] ?? []), 'remote' => array_values($remote['indexes'] ?? [])],
                'FK' => ['local' => array_values($local['foreign_keys'] ?? []), 'remote' => array_values($remote['foreign_keys'] ?? [])],
            ];

            $groupRows = [];
            $rows = [];
            foreach ($groups as $label => $items) {
                $remotePool = [];
                foreach ($items['remote'] as $remoteValue) {
                    $remotePool[crudProjectNormalizeComponentSignature($label, (string)$remoteValue)][] = (string)$remoteValue;
                }

                $groupRows[$label] = [];
                foreach ($items['local'] as $localValue) {
                    $signature = crudProjectNormalizeComponentSignature($label, (string)$localValue);
                    $remoteValue = '';
                    $reason = '';
                    if (!empty($remotePool[$signature])) {
                        $remoteValue = array_shift($remotePool[$signature]);
                        if (empty($remotePool[$signature])) {
                            unset($remotePool[$signature]);
                        }
                        $reason = 'Corrispondenza trovata nel destinatario.';
                    } else {
                        $reason = 'Componente assente nel destinatario o definizione diversa.';
                    }
                    $row = [
                        'label' => $label,
                        'kind' => $label,
                        'local' => (string)$localValue,
                        'remote' => (string)$remoteValue,
                        'match' => $localValue !== '' && $remoteValue !== '',
                        'local_exists' => $localValue !== '',
                        'remote_exists' => $remoteValue !== '',
                        'remote_match' => $remoteValue !== '',
                        'reason' => $reason,
                    ];
                    $groupRows[$label][] = $row;
                    $rows[] = $row;
                }

                foreach ($remotePool as $remainingRemoteValues) {
                    foreach ($remainingRemoteValues as $remoteValue) {
                        $row = [
                            'label' => $label,
                            'kind' => $label,
                            'local' => '',
                            'remote' => (string)$remoteValue,
                            'match' => false,
                            'local_exists' => false,
                            'remote_exists' => true,
                            'remote_match' => true,
                            'reason' => 'Componente presente solo nel destinatario.',
                        ];
                        $groupRows[$label][] = $row;
                        $rows[] = $row;
                    }
                }
            }

            $matrix[$tableName] = [
                'groups' => $groupRows,
                'columns' => $rows,
                'mismatch_count' => array_reduce($rows, static function (int $carry, array $row): int {
                    return $carry + (empty($row['match']) ? 1 : 0);
                }, 0),
            ];
        }

        return $matrix;
    }
}

if (!function_exists('crudProjectNormalizeComponentSignature')) {
    function crudProjectNormalizeComponentSignature(string $label, string $value): string {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        $norm = strtolower($value);

        if ($label === 'Campi') {
            $norm = str_replace('`', '', $norm);
            $norm = preg_replace('/\s+/', ' ', $norm) ?? $norm;
            $norm = preg_replace('/\s*\(\s*/', '(', $norm) ?? $norm;
            $norm = preg_replace('/\s*\)\s*/', ') ', $norm) ?? $norm;
            $norm = preg_replace('/\s*,\s*/', ', ', $norm) ?? $norm;
            $norm = preg_replace('/\bdefault\s+null\b/', 'null', $norm) ?? $norm;
            $norm = preg_replace('/\s+null\b/', ' null', $norm) ?? $norm;
            $norm = preg_replace('/\s+not null\b/', ' not null', $norm) ?? $norm;
            $norm = preg_replace('/\s+auto_increment\b/', ' auto_increment', $norm) ?? $norm;
            $norm = preg_replace('/\s+character set\s+\S+/', '', $norm) ?? $norm;
            $norm = preg_replace('/\s+collate\s+\S+/', '', $norm) ?? $norm;
            $norm = trim($norm);
            return $norm;
        }
        if ($label === 'PK') {
            return preg_replace('/^primary\s+key\s*/', '', $norm) ?? $norm;
        }
        if ($label === 'UQ') {
            $norm = preg_replace('/^unique\s+key\s*/', '', $norm) ?? $norm;
            $norm = preg_replace('/^unique\s+index\s*/', '', $norm) ?? $norm;
            $norm = str_replace('`', '', $norm);
            $norm = preg_replace('/\s*,\s*/', ',', $norm) ?? $norm;
            $norm = preg_replace('/\(\s+/', '(', $norm) ?? $norm;
            $norm = preg_replace('/\s+\)/', ')', $norm) ?? $norm;
            return $norm;
        }
        if ($label === 'IDX') {
            $norm = preg_replace('/^(key|index|fulltext\s+key|spatial\s+key)\s*/', '', $norm) ?? $norm;
            $norm = str_replace('`', '', $norm);
            $norm = preg_replace('/\s*,\s*/', ',', $norm) ?? $norm;
            $norm = preg_replace('/\(\s+/', '(', $norm) ?? $norm;
            $norm = preg_replace('/\s+\)/', ')', $norm) ?? $norm;
            return $norm;
        }
        if ($label === 'FK') {
            $norm = preg_replace('/^foreign\s+key\s*/', '', $norm) ?? $norm;
            $norm = preg_replace('/^constraint\s+`?[^`\s]+`?\s*/', '', $norm) ?? $norm;
            $norm = str_replace('`', '', $norm);
            $norm = preg_replace('/\s*,\s*/', ',', $norm) ?? $norm;
            $norm = preg_replace('/\(\s+/', '(', $norm) ?? $norm;
            $norm = preg_replace('/\s+\)/', ')', $norm) ?? $norm;
            $norm = preg_replace('/\s+foreign\s+key\s*/', ' foreign key ', $norm) ?? $norm;
            $norm = preg_replace('/\s+references\s+/', ' references ', $norm) ?? $norm;
            $norm = preg_replace('/\s+referencing\s+/', ' references ', $norm) ?? $norm;
            $norm = preg_replace('/\s+on\s+(delete|update)\s+/', ' on $1 ', $norm) ?? $norm;
            return $norm;
        }

        return $norm;
    }
}

if (!function_exists('deployCollectReferencedPagesFromIndex')) {
    function deployCollectReferencedPagesFromIndex(string $root): array {
        $index = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) return [];

        $code = (string)file_get_contents($index);
        $pages = [];

        if (preg_match_all('/[?&]page=([^"\'<>\\s&]+\.php)/i', $code, $matches)) {
            foreach ($matches[1] as $page) {
                $page = basename(rawurldecode((string)$page));
                if (preg_match('/^[a-zA-Z0-9._-]+\.php$/', $page)) {
                    $pages[$page] = true;
                }
            }
        }

        if (preg_match_all('/nome_file\s*=>\s*["\']([^"\']+\.php)["\']/i', $code, $matches2)) {
            foreach ($matches2[1] as $page) {
                $page = basename((string)$page);
                if (preg_match('/^[a-zA-Z0-9._-]+\.php$/', $page)) {
                    $pages[$page] = true;
                }
            }
        }

        return array_keys($pages);
    }
}

if (!function_exists('deployCollectRequiredFiles')) {
    function deployCollectRequiredFiles(string $root): array {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $files = [];
        $add = function(string $relative) use (&$files, $root): void {
            $relative = trim(str_replace('\\', '/', $relative), '/');
            if ($relative === '' || !deployRelativeAllowedForDeploy($relative)) return;
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($path)) {
                $files[$relative] = $path;
            }
        };

        // File minimi di funzionamento del sito generato.
        foreach (['index.php', 'db.php', 'schema.sql', 'schema.json', 'manifest.json', 'favicon.ico', '.htaccess', 'db_schema_receiver.php'] as $file) {
            $add($file);
        }

        // Pagine effettivamente richiamate dal menu dell'index.php.
        $referencedPages = deployCollectReferencedPagesFromIndex($root);
        if (!$referencedPages && is_dir($root . DIRECTORY_SEPARATOR . 'pages')) {
            // Fallback prudente: se l'index.php non è leggibile o non contiene link rilevabili,
            // pubblico le pagine PHP presenti per evitare un sito incompleto.
            foreach (glob($root . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $pageFile) {
                $referencedPages[] = basename($pageFile);
            }
        }
        foreach ($referencedPages as $page) {
            $add('pages/' . basename($page));
        }

        // Asset locali eventualmente usati dall'index.php o dalle pagine incluse.
        foreach (['assets', 'css', 'js', 'img', 'images', 'icons', 'fonts', 'uploads', 'vendor'] as $dir) {
            $dirPath = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($dirPath)) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if (!$item->isFile()) continue;
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                $add($relative);
            }
        }

        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }
}

if (!function_exists('deployCreateArchive')) {
    function deployCreateArchive(string $localRoot, string $projectName): array {
        if (!extension_loaded('zip')) throw new RuntimeException('L’estensione ZIP non è disponibile.');
        $root = realpath($localRoot);
        if (!$root || !is_dir($root)) throw new RuntimeException('Cartella locale del progetto non trovata.');

        $requiredFiles = deployCollectRequiredFiles($root);
        if (!$requiredFiles) {
            throw new RuntimeException('Nessun file necessario trovato per la pubblicazione. Generare prima index.php e le pagine del progetto.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'crud_deploy_');
        if ($temp === false) throw new RuntimeException('Impossibile creare il file temporaneo.');
        @unlink($temp);
        $zipPath = $temp . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare lo ZIP temporaneo.');
        }

        $files = 0;
        foreach ($requiredFiles as $relative => $path) {
            if (!$zip->addFile($path, $relative)) {
                $zip->close(); @unlink($zipPath);
                throw new RuntimeException('Impossibile aggiungere allo ZIP: ' . $relative);
            }
            $files++;
        }

        $zip->setArchiveComment('CRUD Deploy smart - ' . $projectName . ' - ' . date('c'));
        $zip->close();
        return ['path'=>$zipPath, 'files'=>$files, 'bytes'=>(int)filesize($zipPath), 'sha256'=>hash_file('sha256', $zipPath)];
    }
}


if (!function_exists('deployHttpsRequest')) {
    function deployHttpsRequest(string $url, string $token, array $fields, ?string $zipPath = null): array {
        if (!extension_loaded('curl')) throw new RuntimeException('L’estensione PHP cURL non è disponibile.');
        if ($zipPath !== null) {
            $fields['archive'] = new CURLFile($zipPath, 'application/zip', basename($zipPath));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Deploy-Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'CRUD-HTTPS-Deploy/1.0',
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Errore cURL: ' . $curlError);
        $json = json_decode($body, true);
        if (!is_array($json)) throw new RuntimeException('Risposta non valida dal ricevitore (HTTP ' . $status . ').');
        if ($status < 200 || $status >= 300 || empty($json['success'])) {
            throw new RuntimeException((string)($json['message'] ?? ('Errore HTTP ' . $status)));
        }
        return $json;
    }
}

$progetto_id = $_SESSION['progetto_id'] ?? null;
$progetto_nome = $_SESSION['progetto_nome'] ?? null;

if ($progetto_id) {
    $folder_name = sanitizeFolderName($progetto_nome);
    $root_path = __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $folder_name;

    if (!is_dir($root_path)) mkdir($root_path, 0755, true);
    // Configurazione HTTPS per progetto. La vecchia configurazione FTP non viene più utilizzata.
    $db->execute(
        "CREATE TABLE IF NOT EXISTS progetti_deploy_https (
            id INT NOT NULL AUTO_INCREMENT,
            IDprogetto INT NOT NULL,
            receiver_url VARCHAR(500) NOT NULL,
            token_cifrato TEXT NOT NULL,
            project_uuid CHAR(36) NULL,
            cartella_destinazione VARCHAR(500) NOT NULL DEFAULT '',
            application_url VARCHAR(500) NOT NULL DEFAULT '',
            crea_backup TINYINT(1) NOT NULL DEFAULT 1,
            backup_da_mantenere INT NOT NULL DEFAULT 5,
            elimina_file_mancanti TINYINT(1) NOT NULL DEFAULT 0,
            ultima_pubblicazione DATETIME NULL,
            ultimo_esito VARCHAR(20) NULL,
            ultimo_messaggio TEXT NULL,
            ultimo_esito_config VARCHAR(20) NULL,
            ultimo_messaggio_config TEXT NULL,
            ultimo_esito_db VARCHAR(20) NULL,
            ultimo_messaggio_db TEXT NULL,
            ultimo_esito_sync VARCHAR(20) NULL,
            ultimo_messaggio_sync TEXT NULL,
            last_archive_sha256 CHAR(64) NULL,
            aggiornato_il TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_deploy_https_progetto (IDprogetto)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'project_uuid', 'project_uuid CHAR(36) NULL AFTER token_cifrato');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'application_url', "application_url VARCHAR(500) NOT NULL DEFAULT '' AFTER cartella_destinazione");
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'ultimo_esito_config', 'ultimo_esito_config VARCHAR(20) NULL AFTER ultimo_messaggio');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'ultimo_messaggio_config', 'ultimo_messaggio_config TEXT NULL AFTER ultimo_esito_config');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'ultimo_esito_db', 'ultimo_esito_db VARCHAR(20) NULL AFTER ultimo_messaggio_config');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'ultimo_messaggio_db', 'ultimo_messaggio_db TEXT NULL AFTER ultimo_esito_db');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'ultimo_esito_sync', 'ultimo_esito_sync VARCHAR(20) NULL AFTER ultimo_messaggio_db');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'ultimo_messaggio_sync', 'ultimo_messaggio_sync TEXT NULL AFTER ultimo_esito_sync');
    deployEnsureTableColumn($db, 'progetti_deploy_https', 'last_archive_sha256', 'last_archive_sha256 CHAR(64) NULL AFTER ultimo_messaggio_sync');

    $deploy_csrf = deployEnsureCsrf();

    $rel_path = $_GET['path'] ?? '';
    $rel_path = str_replace(['..', './', '\\'], ['', '', '/'], $rel_path);
    $current_full_path = resolveProjectPath($root_path, $rel_path);

    if (!$current_full_path || !is_dir($current_full_path)) {
        $current_full_path = realpath($root_path);
        $rel_path = '';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['file_action'] ?? '';
        $deployActions = [
            'save_deploy_config',
            'ping_deploy_receiver',
            'inspect_deploy_target',
            'associate_deploy_target',
            'disassociate_deploy_target',
            'publish_project',
            'save_dest_db_config',
            'delete_dest_db_config',
            'apply_db_schema_alignment',
            'refresh_db_schema_comparison'
        ];
        if (in_array($action, $deployActions, true)) {
            $archive = null;
            try {
                deployVerifyCsrf();
                $savedConfig = $db->fetch(
                    "SELECT * FROM progetti_deploy_https WHERE IDprogetto = ?",
                    [$progetto_id]
                ) ?: [];

                if ($action === 'refresh_db_schema_comparison') {
                    $schemaReceiverUrl = deployNormalizeUrl(
                        (string)($_POST['schema_receiver_url'] ?? '')
                    );

                    $newToken = trim((string)($_POST['deploy_token'] ?? ''));
                    $encryptedToken = (string)($savedConfig['token_cifrato'] ?? '');
                    if ($newToken === '' && $encryptedToken === '') {
                        throw new RuntimeException('Token deploy non disponibile. Salvare prima la configurazione HTTPS.');
                    }
                    $token = $newToken !== '' ? $newToken : deployDecryptToken($encryptedToken);

                    $schemaPath = $root_path . DIRECTORY_SEPARATOR . 'schema.sql';
                    projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome, true);
                    if (!is_file($schemaPath)) {
                        throw new RuntimeException('schema.sql non disponibile.');
                    }

                    $localSql = (string)file_get_contents($schemaPath);
                    $remote = crudSchemaFetchRemote(
                        $schemaReceiverUrl,
                        $token,
                        (string)($savedConfig['cartella_destinazione'] ?? '.'),
                        (string)($savedConfig['project_uuid'] ?? '')
                    );

                    $remoteSql = (string)($remote['data']['schema_sql'] ?? '');
                    if (trim($remoteSql) === '') {
                        throw new RuntimeException('Il sito destinatario non ha restituito la struttura SQL.');
                    }

                    $comparison = crudSchemaCompare($localSql, $remoteSql);
                    $_SESSION['db_schema_panel'] = [
                        'local_sql' => $localSql,
                        'remote_sql' => $remoteSql,
                        'remote_database' => (string)($remote['data']['database'] ?? ''),
                        'remote_generated_at' => (string)($remote['data']['generated_at'] ?? ''),
                        'comparison' => $comparison,
                    ];

                    $_SESSION['success_msg'] = !empty($comparison['is_aligned'])
                        ? 'Struttura DB destinatario allineata a schema.sql.'
                        : 'Confronto DB completato: sono presenti differenze.';
                    redirectCartellaProgetto($rel_path);
                }

                if ($action === 'apply_db_schema_alignment') {
                    $schemaReceiverUrl = deployNormalizeUrl(
                        (string)($_POST['schema_receiver_url'] ?? '')
                    );
                    $targetTable = trim((string)($_POST['schema_table_name'] ?? ''));
                    $receiverUrl = deployNormalizeUrl((string)($_POST['deploy_receiver_url'] ?? ($savedConfig['receiver_url'] ?? '')));
                    $deployPath = deployNormalizeTarget((string)($_POST['deploy_path'] ?? ($savedConfig['cartella_destinazione'] ?? '')));
                    $applicationUrl = deployNormalizeOptionalHttpsUrl((string)($_POST['deploy_application_url'] ?? ($savedConfig['application_url'] ?? '')));

                    $newToken = trim((string)($_POST['deploy_token'] ?? ''));
                    $encryptedToken = (string)($savedConfig['token_cifrato'] ?? '');
                    if ($newToken === '' && $encryptedToken === '') {
                        throw new RuntimeException('Token deploy non disponibile. Salvare prima la configurazione HTTPS.');
                    }
                    $token = $newToken !== '' ? $newToken : deployDecryptToken($encryptedToken);
                    if ($schemaReceiverUrl === '') {
                        throw new RuntimeException('URL rilevatore DB mancante: non è possibile avviare l’allineamento.');
                    }

                    $schemaPath = $root_path . DIRECTORY_SEPARATOR . 'schema.sql';
                    projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome, true);
                    if (!is_file($schemaPath)) {
                        throw new RuntimeException('schema.sql non disponibile.');
                    }

                    $localSql = (string)file_get_contents($schemaPath);
                    $remote = crudSchemaFetchRemote(
                        $schemaReceiverUrl,
                        $token,
                        (string)($savedConfig['cartella_destinazione'] ?? '.'),
                        (string)($savedConfig['project_uuid'] ?? '')
                    );
                    $remoteSql = (string)($remote['data']['schema_sql'] ?? '');
                    if (trim($remoteSql) === '') {
                        throw new RuntimeException('Il sito destinatario non ha restituito la struttura SQL.');
                    }

                    $logRows = [];
                    $logRows[] = ['status' => 'ok', 'statement' => 'Receiver analizzato: struttura DB destinatario acquisita', 'error' => ''];
                    $comparison = crudSchemaCompare($localSql, $remoteSql);
                    $logRows[] = ['status' => 'ok', 'statement' => 'Confronto strutturale eseguito tra schema.sql e DB destinatario', 'error' => ''];
                    $logRows[] = ['status' => 'ok', 'statement' => 'Differenze rilevate: ' . (int)($comparison['summary']['different'] ?? 0) . ', mancanti: ' . (int)($comparison['summary']['missing_remote'] ?? 0) . ', extra: ' . (int)($comparison['summary']['extra_remote'] ?? 0), 'error' => ''];
                    $localViews = crudProjectExtractUnifiedSchemaView($localSql);
                    $remoteViews = crudProjectExtractUnifiedSchemaView($remoteSql);
                    $tableViewLocal = $localViews[$targetTable] ?? [];
                    $tableViewRemote = $remoteViews[$targetTable] ?? [];
                    $tableAlterStatements = $targetTable !== ''
                        ? crudProjectBuildTableAlterStatements($targetTable, $tableViewLocal, $tableViewRemote)
                        : [];
                    $tableMatrix = crudProjectBuildTableComparisonMatrix(
                        $targetTable !== '' ? [$targetTable => $tableViewLocal] : $localViews,
                        $targetTable !== '' ? [$targetTable => $tableViewRemote] : $remoteViews
                    );
                    $tableMatrixRows = $tableMatrix[$targetTable]['columns'] ?? [];
                    if ($targetTable !== '') {
                        $missingComponentStatements = array_values(array_filter($tableAlterStatements, static function (string $line): bool {
                            return (bool)preg_match('/^(ADD\s+COLUMN|ADD\s+UNIQUE\s+KEY|ADD\s+KEY|ADD\s+FOREIGN\s+KEY)\b/i', trim($line));
                        }));
                        if (!empty($missingComponentStatements)) {
                            $logRows[] = [
                                'status' => 'ok',
                                'statement' => 'Analisi componente mancante nella tabella: ' . $targetTable,
                                'error' => '',
                                'sql' => implode(PHP_EOL, $missingComponentStatements),
                            ];
                        }
                        foreach ($tableMatrixRows as $matrixRow) {
                            $localExists = !empty($matrixRow['local_exists']);
                            $remoteExists = !empty($matrixRow['remote_exists']);
                            $match = !empty($matrixRow['match']);
                            if ($match) {
                                $status = 'ok';
                            } elseif ($localExists && !$remoteExists) {
                                $status = 'missing';
                            } elseif (!$localExists && $remoteExists) {
                                $status = 'extra';
                            } else {
                                $status = 'diff';
                            }
                            $componentSql = '';
                            if (!$match) {
                                $componentType = (string)($matrixRow['label'] ?? '');
                                $componentName = trim((string)($matrixRow['local'] ?? ''));
                                if ($componentName !== '' && !empty($tableAlterStatements)) {
                                    foreach ($tableAlterStatements as $stmtLine) {
                                        $stmtLineTrim = trim((string)$stmtLine);
                                        if ($status === 'extra' && preg_match('/^DROP\s+(COLUMN|INDEX|FOREIGN\s+KEY)\b/i', $stmtLineTrim)) {
                                            $componentSql = $stmtLineTrim;
                                            break;
                                        }
                                        if ($componentType === 'Campi' && preg_match('/\b' . preg_quote(trim(explode(' ', $componentName)[0]), '/') . '\b/i', $stmtLineTrim)) {
                                            $componentSql = $stmtLineTrim;
                                            break;
                                        }
                                        if (($componentType === 'PK' || $componentType === 'UQ' || $componentType === 'IDX' || $componentType === 'FK') && stripos($stmtLineTrim, $componentType === 'PK' ? 'PRIMARY KEY' : ($componentType === 'UQ' ? 'UNIQUE KEY' : ($componentType === 'IDX' ? 'KEY' : 'FOREIGN KEY'))) !== false) {
                                            $componentSql = $stmtLineTrim;
                                            break;
                                        }
                                    }
                                }
                            }
                            $logRows[] = [
                                'status' => $status,
                                                'statement' => (string)($matrixRow['label'] ?? '') . ' - ' . trim((string)($matrixRow['local'] ?? '')) . ' | ' . trim((string)($matrixRow['remote'] ?? '')),
                                                'error' => (string)($matrixRow['reason'] ?? ''),
                                                'sql' => $componentSql,
                                            ];
                                        }
                    }
                    $alignmentSql = $targetTable !== ''
                        ? crudProjectBuildTableAlignmentSql($targetTable, $comparison, $localSql, $remoteSql)
                        : crudProjectBuildAlignmentSql($comparison, $localSql, $remoteSql);
                    if (trim($alignmentSql) === '') {
                        throw new RuntimeException('Nessuno script di allineamento disponibile per la tabella selezionata.');
                    }
                    $logRows[] = ['status' => 'ok', 'statement' => $targetTable !== '' ? 'Rettifica impostata per la tabella: ' . $targetTable : 'Rettifica impostata per l’intero schema', 'error' => ''];
                    $receiverUrlForSync = (string)$receiverUrl;
                    $deployPathForSync = (string)$deployPath;
                    $applicationUrlForSync = (string)$applicationUrl;
                    projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome, true);
                    $dbPhpStatus = destDbEnsureGeneratedDbPhp($db, (int)$progetto_id, (string)$progetto_nome, $root_path);
                    $logRows[] = ['status' => 'ok', 'statement' => 'db.php rigenerato e pronto per la pubblicazione remota', 'error' => ''];
                    $syncArchive = deployCreateArchive($root_path, (string)$progetto_nome);
                    $syncFields = [
                        'action' => 'deploy',
                        'protocol_version' => '1',
                        'deploy_path' => $deployPathForSync,
                        'project_uuid' => (string)($savedConfig['project_uuid'] ?? ''),
                        'project_name' => (string)$progetto_nome,
                        'generator_version' => 'cartella_progetto_unico_v1.5',
                        'application_url' => $applicationUrlForSync,
                        'sha256' => $syncArchive['sha256'],
                        'create_backup' => '0',
                        'backup_keep' => (string)($savedConfig['backup_da_mantenere'] ?? 5),
                        'delete_missing' => (string)($savedConfig['elimina_file_mancanti'] ?? 0),
                    ];
                    $syncDeployResponse = deployHttpsRequest($receiverUrlForSync, $token, $syncFields, $syncArchive['path']);
                    if (empty($syncDeployResponse['success'])) {
                        throw new RuntimeException('Pubblicazione DB.php prima dell’allineamento non riuscita.');
                    }
                    $logRows[] = ['status' => 'ok', 'statement' => 'db.php ripubblicato sul sito remoto', 'error' => ''];
                    $dbSyncResponse = deployTriggerRemoteDbSync($applicationUrlForSync, $receiverUrlForSync, $deployPathForSync, $targetTable);
                    $logRows[] = ['status' => !empty($dbSyncResponse['report']) ? 'ok' : 'pending', 'statement' => $targetTable !== '' ? 'Allineamento tabella eseguito: ' . $targetTable : 'Allineamento struttura database eseguito', 'error' => ''];
                    if (!empty($dbSyncResponse['report']) && is_array($dbSyncResponse['report'])) {
                        foreach ($dbSyncResponse['report'] as $row) {
                            $logRows[] = [
                                'status' => (string)($row['status'] ?? 'pending'),
                                'statement' => (string)($row['statement'] ?? ''),
                                'error' => (string)($row['error'] ?? ''),
                                'sql' => (string)($row['sql'] ?? ''),
                            ];
                        }
                    }

                    $remoteAfter = crudSchemaFetchRemote(
                        $schemaReceiverUrl,
                        $token,
                        (string)($savedConfig['cartella_destinazione'] ?? '.'),
                        (string)($savedConfig['project_uuid'] ?? '')
                    );

                    $remoteAfterSql = (string)($remoteAfter['data']['schema_sql'] ?? '');
                    if (trim($remoteAfterSql) === '') {
                        throw new RuntimeException('Verifica post-allineamento non disponibile dal destinatario.');
                    }

                    $comparisonAfter = crudSchemaCompare($localSql, $remoteAfterSql);
                    $logRows[] = ['status' => !empty($comparisonAfter['is_aligned']) ? 'ok' : 'fail', 'statement' => 'Verifica finale eseguita dopo l’aggiornamento', 'error' => !empty($comparisonAfter['is_aligned']) ? '' : 'Persistono differenze strutturali.'];
                    if (!empty($comparisonAfter['is_aligned'])) {
                        $logRows[] = ['status' => 'ok', 'statement' => 'Struttura del DB destinatario uguale a schema.sql', 'error' => ''];
                    } else {
                        $logRows[] = ['status' => 'fail', 'statement' => 'Serve un ulteriore passaggio per uniformare la struttura', 'error' => 'Confrontare nuovamente i componenti residui.'];
                    }
                    $_SESSION['db_schema_panel'] = [
                        'local_sql' => $localSql,
                        'remote_sql' => $remoteAfterSql,
                        'remote_database' => (string)($remoteAfter['data']['database'] ?? ''),
                        'remote_generated_at' => (string)($remoteAfter['data']['generated_at'] ?? ''),
                        'comparison' => $comparisonAfter,
                    ];

                    $_SESSION['db_sync_report'] = $logRows;

                    $_SESSION['success_msg'] = !empty($comparisonAfter['is_aligned'])
                        ? 'Allineamento DB destinatario completato tramite db.php e verificato.'
                        : 'Allineamento DB destinatario eseguito, ma la verifica finale mostra ancora differenze.';
                    redirectCartellaProgetto($rel_path);
                }

                if ($action === 'save_dest_db_config') {
                    destDbSaveConfig($db, (int)$progetto_id, [
                        'host' => $_POST['dest_db_host'] ?? 'localhost',
                        'db_name' => $_POST['dest_db_name'] ?? '',
                        'user' => $_POST['dest_db_user'] ?? '',
                        'pass' => $_POST['dest_db_pass'] ?? '',
                        'charset_name' => $_POST['dest_db_charset'] ?? 'utf8mb4',
                        'auto_initialize' => $_POST['dest_db_auto_initialize'] ?? 1,
                        'auto_apply' => $_POST['dest_db_auto_apply'] ?? 1,
                        'modify_columns' => $_POST['dest_db_modify_columns'] ?? 1,
                        'drop_extra_columns' => $_POST['dest_db_drop_extra_columns'] ?? 0,
                        'drop_extra_tables' => $_POST['dest_db_drop_extra_tables'] ?? 0,
                        'make_extra_nullable' => $_POST['dest_db_make_extra_nullable'] ?? 1,
                        'sync_schema_files' => $_POST['dest_db_sync_schema_files'] ?? 1,
                    ], true);
                    projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome, true);
                    $gen = destDbEnsureGeneratedDbPhp($db, (int)$progetto_id, (string)$progetto_nome, $root_path);
                    $dbMessage = 'Parametri DB destinatario salvati. ' . (string)($gen['message'] ?? '');
                    $db->execute(
                        "UPDATE progetti_deploy_https
                         SET ultimo_esito_db = 'successo', ultimo_messaggio_db = ?
                         WHERE IDprogetto = ?",
                        [$dbMessage, $progetto_id]
                    );
                    $_SESSION['success_msg'] = $dbMessage;
                    redirectCartellaProgetto($rel_path);
                }

                if ($action === 'delete_dest_db_config') {
                    destDbDeleteConfig($db, (int)$progetto_id);
                    $dbMessage = 'Parametri DB destinatario cancellati per questo progetto.';
                    $db->execute(
                        "UPDATE progetti_deploy_https
                         SET ultimo_esito_db = 'successo', ultimo_messaggio_db = ?
                         WHERE IDprogetto = ?",
                        [$dbMessage, $progetto_id]
                    );
                    $_SESSION['success_msg'] = $dbMessage;
                    redirectCartellaProgetto($rel_path);
                }

                $receiverUrl = deployNormalizeUrl((string)($_POST['deploy_receiver_url'] ?? ($savedConfig['receiver_url'] ?? '')));
                $deployPath = deployNormalizeTarget((string)($_POST['deploy_path'] ?? ($savedConfig['cartella_destinazione'] ?? '')));
                $projectUuid = deployNormalizeUuid((string)($_POST['deploy_project_uuid'] ?? ($savedConfig['project_uuid'] ?? '')));
                $applicationUrl = deployNormalizeOptionalHttpsUrl((string)($_POST['deploy_application_url'] ?? ($savedConfig['application_url'] ?? '')));
                $createBackup = 0; // I backup remoti sono disattivati: le copie restano solo nel CRUD/sorgente.
                $keepBackups = max(1, min(30, (int)($_POST['deploy_keep_backups'] ?? ($savedConfig['backup_da_mantenere'] ?? 5))));
                $deleteMissing = isset($_POST['deploy_delete_missing']) ? 1 : 0;
                $newToken = trim((string)($_POST['deploy_token'] ?? ''));
                $encryptedToken = (string)($savedConfig['token_cifrato'] ?? '');

                if ($receiverUrl === '') throw new RuntimeException('Inserire l’URL HTTPS del ricevitore.');
                                if ($newToken !== '') $encryptedToken = deployEncryptToken($newToken);
                if ($encryptedToken === '') throw new RuntimeException('Inserire il token segreto.');
                $token = $newToken !== '' ? $newToken : deployDecryptToken($encryptedToken);

                $db->execute(
                    "INSERT INTO progetti_deploy_https
                        (IDprogetto, receiver_url, token_cifrato, project_uuid, cartella_destinazione, application_url, crea_backup, backup_da_mantenere, elimina_file_mancanti)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        receiver_url = VALUES(receiver_url), token_cifrato = VALUES(token_cifrato),
                        project_uuid = VALUES(project_uuid), cartella_destinazione = VALUES(cartella_destinazione),
                        application_url = VALUES(application_url), crea_backup = VALUES(crea_backup),
                        backup_da_mantenere = VALUES(backup_da_mantenere), elimina_file_mancanti = VALUES(elimina_file_mancanti)",
                    [$progetto_id, $receiverUrl, $encryptedToken, $projectUuid, $deployPath, $applicationUrl, $createBackup, $keepBackups, $deleteMissing]
                );

                $generatorVersion = 'cartella_progetto_unico_v1.5';
                $message = '';
                $lastSha256 = null;

                if ($action === 'save_deploy_config') {
                    $message = 'Configurazione HTTPS salvata correttamente.';
                } elseif ($action === 'ping_deploy_receiver') {
                    $response = deployHttpsRequest($receiverUrl, $token, ['action' => 'ping']);
                    $version = (string)($response['data']['receiver_version'] ?? '');
                    $message = 'Ricevitore raggiungibile' . ($version !== '' ? ' - versione ' . $version : '') . '. ' . (string)($response['message'] ?? '');
                } elseif ($action === 'inspect_deploy_target') {
                    $response = deployHttpsRequest($receiverUrl, $token, [
                        'action' => 'inspect',
                        'deploy_path' => $deployPath,
                        'project_uuid' => $projectUuid,
                    ]);
                    $message = deployFormatReceiverResponseMessage($response, 'Ispezione completata.');
                } elseif ($action === 'associate_deploy_target') {
                    $response = deployHttpsRequest($receiverUrl, $token, [
                        'action' => 'associate',
                        'deploy_path' => $deployPath,
                        'project_uuid' => $projectUuid,
                        'project_name' => (string)$progetto_nome,
                        'application_url' => $applicationUrl,
                    ]);
                    $message = deployFormatReceiverResponseMessage($response, 'Associazione completata.');
                } elseif ($action === 'disassociate_deploy_target') {
                    $response = deployHttpsRequest($receiverUrl, $token, [
                        'action' => 'disassociate',
                        'deploy_path' => $deployPath,
                        'project_uuid' => $projectUuid,
                    ]);
                    $message = deployFormatReceiverResponseMessage($response, 'Disassociazione completata.');
                } else {
                    // Prima di creare lo ZIP rigenera in modo trasparente schema.sql/schema.json
                    // dal metadatabase del CRUD e poi rigenera db.php usando i parametri DB
                    // destinatario salvati una sola volta per il progetto.
                    projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome, true);
                    if (!destDbLoadConfig($db, (int)$progetto_id)
                        && trim((string)($_POST['dest_db_name'] ?? '')) !== ''
                        && trim((string)($_POST['dest_db_user'] ?? '')) !== '') {
                        destDbSaveConfig($db, (int)$progetto_id, [
                            'host' => $_POST['dest_db_host'] ?? 'localhost',
                            'db_name' => $_POST['dest_db_name'] ?? '',
                            'user' => $_POST['dest_db_user'] ?? '',
                            'pass' => $_POST['dest_db_pass'] ?? '',
                            'charset_name' => $_POST['dest_db_charset'] ?? 'utf8mb4',
                            'auto_initialize' => $_POST['dest_db_auto_initialize'] ?? 1,
                            'auto_apply' => $_POST['dest_db_auto_apply'] ?? 1,
                            'modify_columns' => $_POST['dest_db_modify_columns'] ?? 1,
                            'drop_extra_columns' => $_POST['dest_db_drop_extra_columns'] ?? 0,
                            'drop_extra_tables' => $_POST['dest_db_drop_extra_tables'] ?? 0,
                            'make_extra_nullable' => $_POST['dest_db_make_extra_nullable'] ?? 1,
                            'sync_schema_files' => $_POST['dest_db_sync_schema_files'] ?? 1,
                        ], true);
                    }
                    $dbPhpStatus = destDbEnsureGeneratedDbPhp($db, (int)$progetto_id, (string)$progetto_nome, $root_path);

                    $archive = deployCreateArchive($root_path, (string)$progetto_nome);

                    // Salva una copia dell'archivio zip locale come backup sul PC prima della trasmissione
                    if ($action === 'publish_project') {
                        $backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backup';
                        if (!is_dir($backupDir)) {
                            mkdir($backupDir, 0755, true);
                        }
                        $sanitizedProjectName = sanitizeFolderName($progetto_nome);
                        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $sanitizedProjectName . '_' . date('Ymd_His') . '.zip';
                        copy($archive['path'], $backupPath);
                    }
                    $fields = [
                        'action' => 'deploy',
                        'protocol_version' => '1',
                        'deploy_path' => $deployPath,
                        'project_uuid' => $projectUuid,
                        'project_name' => (string)$progetto_nome,
                        'generator_version' => $generatorVersion,
                        'application_url' => $applicationUrl,
                        'sha256' => $archive['sha256'],
                        'create_backup' => '0',
                        'backup_keep' => (string)$keepBackups,
                        'delete_missing' => (string)$deleteMissing,
                    ];
                    $response = deployHttpsRequest($receiverUrl, $token, $fields, $archive['path']);
                    $message = deployFormatReceiverResponseMessage(
                        $response,
                        'Pubblicazione completata.'
                    );
                    if ($action === 'publish_project' && isset($dbPhpStatus['message'])) {
                        $message .= ' ' . (string)$dbPhpStatus['message'] . ' Pacchetto smart: pubblicati solo i file necessari richiamati da index.php.';
                    }
                    $lastSha256 = (string)($response['data']['metadata']['last_archive_sha256'] ?? $archive['sha256']);
                    if ($action === 'publish_project') {
                        $db->execute(
                            "UPDATE progetti_deploy_https
                             SET ultima_pubblicazione = NOW(),
                                 last_archive_sha256 = ?,
                                 ultimo_esito = 'successo',
                                 ultimo_messaggio = ?
                             WHERE IDprogetto = ?",
                            [$lastSha256, $message, $progetto_id]
                        );
                    }
                }

                if ($action !== 'publish_project') {
                    $db->execute(
                        "UPDATE progetti_deploy_https
                         SET ultimo_esito = 'successo',
                             ultimo_messaggio = ?,
                             ultimo_esito_config = 'successo',
                             ultimo_messaggio_config = ?
                         WHERE IDprogetto = ?",
                        [$message, $message, $progetto_id]
                    );
                }
                $_SESSION['success_msg'] = $message;
            } catch (Throwable $e) {
                $message = 'Pubblicazione HTTPS: ' . $e->getMessage();
                try {
                    if (in_array($action, ['save_dest_db_config', 'delete_dest_db_config'], true)) {
                        $db->execute(
                            "UPDATE progetti_deploy_https
                             SET ultimo_esito = 'errore',
                                 ultimo_messaggio = ?,
                                 ultimo_esito_db = 'errore',
                                 ultimo_messaggio_db = ?
                             WHERE IDprogetto = ?",
                            [$message, $message, $progetto_id]
                        );
                    } elseif ($action === 'publish_project') {
                        $db->execute(
                            "UPDATE progetti_deploy_https
                             SET ultimo_esito = 'errore',
                                 ultimo_messaggio = ?
                             WHERE IDprogetto = ?",
                            [$message, $progetto_id]
                        );
                    } else {
                        $db->execute(
                            "UPDATE progetti_deploy_https
                             SET ultimo_esito = 'errore',
                                 ultimo_messaggio = ?,
                                 ultimo_esito_config = 'errore',
                                 ultimo_messaggio_config = ?
                             WHERE IDprogetto = ?",
                            [$message, $message, $progetto_id]
                        );
                    }
                } catch (Throwable $ignored) {}
                unset($_SESSION['db_sync_report']);
                $_SESSION['error_msg'] = $message;
            } finally {
                if (is_array($archive) && !empty($archive['path'])) @unlink($archive['path']);
            }
            redirectCartellaProgetto($rel_path);
        }

        $target_relative = $_POST['target_path'] ?? '';
        $target_path = resolveProjectPath($root_path, $target_relative);

        if (!$target_path || $target_path === realpath($root_path)) {
            $_SESSION['error_msg'] = "Elemento non valido o non selezionabile.";
            redirectCartellaProgetto($rel_path);
        }

        if ($action === 'delete_item') {
            $linked_pages = loadLinkedVisualizationPages($db, $progetto_id, $target_path);

            try {
                $db->beginTransaction();
                deleteVisualizationPageRecords($db, $progetto_id, $linked_pages);

                if (!deletePathRecursive($target_path)) {
                    throw new Exception("Impossibile cancellare l'elemento selezionato.");
                }

                $db->commit();
                $deleted_records = count($linked_pages);
                $_SESSION['success_msg'] = $deleted_records > 0
                    ? "Elemento cancellato correttamente. Record pagina collegati cancellati: " . $deleted_records . "."
                    : "Elemento cancellato correttamente.";
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error_msg'] = "Errore cancellazione: " . $e->getMessage();
            }
            redirectCartellaProgetto($rel_path);
        }

        if ($action === 'rename_item') {
            $new_name = trim($_POST['new_name'] ?? '');
            if ($new_name === '' || basename($new_name) !== $new_name || str_contains($new_name, '/') || str_contains($new_name, '\\') || in_array($new_name, array('.', '..'), true)) {
                $_SESSION['error_msg'] = "Nome non valido. Usa solo il nome, senza percorsi.";
                redirectCartellaProgetto($rel_path);
            }

            $linked_pages = loadLinkedVisualizationPages($db, $progetto_id, $target_path);
            $new_path = resolveProjectPath($root_path, dirname($target_relative) . '/' . $new_name, false);
            if (!$new_path) {
                $_SESSION['error_msg'] = "Destinazione non valida.";
            } elseif (file_exists($new_path)) {
                $_SESSION['error_msg'] = "Esiste già un elemento con questo nome.";
            } else {
                $renamed = false;
                try {
                    $db->beginTransaction();

                    if (!rename($target_path, $new_path)) {
                        throw new Exception("Impossibile rinominare l'elemento selezionato.");
                    }
                    $renamed = true;

                    updateVisualizationPageRecordsAfterRename($db, $progetto_id, $linked_pages, $target_path, $new_path);
                    updateHomeMenuRecordsAfterRename($db, $progetto_id, $target_path, $new_path);

                    $db->commit();
                    $updated_records = count($linked_pages);
                    $_SESSION['success_msg'] = $updated_records > 0
                        ? "Elemento rinominato correttamente. Record pagina collegati aggiornati: " . $updated_records . "."
                        : "Elemento rinominato correttamente.";
                } catch (Exception $e) {
                    $db->rollBack();
                    if ($renamed && file_exists($new_path) && !file_exists($target_path)) {
                        @rename($new_path, $target_path);
                    }
                    $_SESSION['error_msg'] = "Errore rinomina: " . $e->getMessage();
                }
            }

            redirectCartellaProgetto($rel_path);
        }
    }

    $visualization_descriptions_by_path = [];
    $visualization_descriptions_by_name = [];
    $visualization_pages = $db->fetchAll(
        "SELECT nome_file, percorso_file, descrizione
         FROM pagine_visualizzazione
         WHERE IDprogetto = ?",
        [$progetto_id]
    );

    foreach ($visualization_pages as $page) {
        $description = trim((string)($page['descrizione'] ?? ''));
        if ($description === '') {
            continue;
        }

        $stored_path = trim((string)($page['percorso_file'] ?? ''));
        $stored_real = $stored_path !== '' ? realpath($stored_path) : false;
        if ($stored_real) {
            $visualization_descriptions_by_path[$stored_real] = $description;
        }

        $file_name = trim((string)($page['nome_file'] ?? ''));
        if ($file_name !== '') {
            $visualization_descriptions_by_name[$file_name] = $description;
        }
    }
    $deploy_config = $db->fetch(
        "SELECT * FROM progetti_deploy_https WHERE IDprogetto = ?",
        [$progetto_id]
    ) ?: [
        'receiver_url' => '',
        'token_cifrato' => '',
        'project_uuid' => deployGenerateUuidV4(),
        'cartella_destinazione' => $folder_name,
        'application_url' => '',
        'crea_backup' => 1,
        'backup_da_mantenere' => 5,
        'elimina_file_mancanti' => 0,
        'ultima_pubblicazione' => null,
        'ultimo_esito' => null,
        'ultimo_messaggio' => null,
        'ultimo_esito_config' => null,
        'ultimo_messaggio_config' => null,
        'ultimo_esito_db' => null,
        'ultimo_messaggio_db' => null,
        'ultimo_esito_sync' => null,
        'ultimo_messaggio_sync' => null,
        'last_archive_sha256' => null,
    ];
    if (empty($deploy_config['project_uuid'])) {
        $deploy_config['project_uuid'] = deployGenerateUuidV4();
    }
    if ((string)($deploy_config['cartella_destinazione'] ?? '') === '') {
        $deploy_config['cartella_destinazione'] = $folder_name;
    }
    $schema_receiver_url = '';
    if (!empty($deploy_config['receiver_url'])) {
        $receiverUrlParts = parse_url((string)$deploy_config['receiver_url']);
        if (is_array($receiverUrlParts) && !empty($receiverUrlParts['scheme']) && !empty($receiverUrlParts['host'])) {
            $schema_receiver_url = $receiverUrlParts['scheme'] . '://' . $receiverUrlParts['host'];
            if (!empty($receiverUrlParts['port'])) {
                $schema_receiver_url .= ':' . (int)$receiverUrlParts['port'];
            }
            $receiverPath = (string)($receiverUrlParts['path'] ?? '/receiver/deploy_receiver.php');
            $receiverDir = trim(str_replace('\\', '/', dirname($receiverPath)), '/');
            if ($receiverDir !== '' && $receiverDir !== '.') {
                $schema_receiver_url .= '/' . $receiverDir;
            }
            $schema_receiver_url .= '/db_schema_receiver.php';
        }
    }


    /*
     * RILEVAZIONE AUTOMATICA STRUTTURA DB ALL'APERTURA PAGINA
     * Versione routine: 1.0
     *
     * Evita chiamate ripetute impostando un intervallo minimo di 60 secondi
     * per progetto nella sessione corrente. Il pulsante manuale resta disponibile.
     */
    $db_schema_auto_refresh_interval = 60;
    $db_schema_auto_refresh_key = 'db_schema_auto_refresh_' . (int)$progetto_id;
    $db_schema_last_auto_refresh = (int)($_SESSION[$db_schema_auto_refresh_key] ?? 0);
    $db_schema_can_auto_refresh = (time() - $db_schema_last_auto_refresh) >= $db_schema_auto_refresh_interval;

    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
        && $db_schema_can_auto_refresh
        && !empty($deploy_config['receiver_url'])
        && !empty($deploy_config['token_cifrato'])
    ) {
        $_SESSION[$db_schema_auto_refresh_key] = time();

        try {
            $receiverUrl = deployNormalizeUrl((string)$deploy_config['receiver_url']);
            $receiverParts = parse_url($receiverUrl);
            if (!is_array($receiverParts) || empty($receiverParts['scheme']) || empty($receiverParts['host'])) {
                throw new RuntimeException('URL ricevitore non valido per il rilevamento automatico.');
            }

            $schemaReceiverUrl = $receiverParts['scheme'] . '://' . $receiverParts['host'];
            if (!empty($receiverParts['port'])) {
                $schemaReceiverUrl .= ':' . (int)$receiverParts['port'];
            }

            $receiverPath = (string)($receiverParts['path'] ?? '/receiver/deploy_receiver.php');
            $receiverDir = trim(str_replace('\\', '/', dirname($receiverPath)), '/');
            if ($receiverDir !== '' && $receiverDir !== '.') {
                $schemaReceiverUrl .= '/' . $receiverDir;
            }
            $schemaReceiverUrl .= '/db_schema_receiver.php';

            $schemaPath = $root_path . DIRECTORY_SEPARATOR . 'schema.sql';
            if (!is_file($schemaPath)) {
                projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome, true);
            }

            $localSql = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
            if ($localSql === '') {
                throw new RuntimeException('schema.sql locale non disponibile.');
            }

            $token = deployDecryptToken((string)$deploy_config['token_cifrato']);
            $remote = crudSchemaFetchRemote(
                $schemaReceiverUrl,
                $token,
                (string)($deploy_config['cartella_destinazione'] ?? '.'),
                (string)($deploy_config['project_uuid'] ?? '')
            );

            $remoteSql = (string)($remote['data']['schema_sql'] ?? '');
            if ($remoteSql === '') {
                throw new RuntimeException('Il sito destinatario non ha restituito la struttura DB.');
            }

            $comparison = crudSchemaCompare($localSql, $remoteSql);

            $_SESSION['db_schema_panel'] = [
                'local_sql' => $localSql,
                'remote_sql' => $remoteSql,
                'remote_database' => (string)($remote['data']['database'] ?? ''),
                'remote_generated_at' => (string)($remote['data']['generated_at'] ?? ''),
                'comparison' => $comparison,
                'auto_refreshed' => true,
                'auto_refreshed_at' => date(DATE_ATOM),
                'auto_refresh_error' => '',
            ];
        } catch (Throwable $autoSchemaError) {
            $existingPanel = $_SESSION['db_schema_panel'] ?? [];
            if (!is_array($existingPanel)) {
                $existingPanel = [];
            }
            $existingPanel['auto_refreshed'] = false;
            $existingPanel['auto_refreshed_at'] = date(DATE_ATOM);
            $existingPanel['auto_refresh_error'] = $autoSchemaError->getMessage();
            $_SESSION['db_schema_panel'] = $existingPanel;
        }
    }

    $deploy_token_saved = !empty($deploy_config['token_cifrato']);
    try {
        $dest_db_config = destDbLoadConfig($db, (int)$progetto_id) ?: [];
    } catch (Throwable $destLoadError) {
        $dest_db_config = [];
        $_SESSION['error_msg'] = 'Configurazione DB destinatario non leggibile: ' . $destLoadError->getMessage();
    }
    $dest_db_saved = !empty($dest_db_config);

    $db_schema_panel = $_SESSION['db_schema_panel'] ?? [
        'local_sql' => is_file($root_path . DIRECTORY_SEPARATOR . 'schema.sql')
            ? (string)file_get_contents($root_path . DIRECTORY_SEPARATOR . 'schema.sql')
            : '',
        'remote_sql' => '',
        'remote_database' => '',
        'remote_generated_at' => '',
        'comparison' => [
            'summary' => [
                'equal' => 0,
                'different' => 0,
                'missing_remote' => 0,
                'extra_remote' => 0,
            ],
            'rows' => [],
            'is_aligned' => false,
        ],
    ];

    $items = [];
    $scanned = array_diff(scandir($current_full_path), array('.', '..'));
    foreach ($scanned as $item) {
        $full_item_path = $current_full_path . DIRECTORY_SEPARATOR . $item;
        $full_item_real = realpath($full_item_path);
        $description = '';
        if (!is_dir($full_item_path)) {
            $description = ($full_item_real && isset($visualization_descriptions_by_path[$full_item_real]))
                ? $visualization_descriptions_by_path[$full_item_real]
                : ($visualization_descriptions_by_name[$item] ?? '');
        }

        $items[] = [
            'name' => $item,
            'is_dir' => is_dir($full_item_path),
            'description' => $description,
            'version' => is_dir($full_item_path) ? '' : projectExtractFileVersion($full_item_path),
            'size' => is_dir($full_item_path) ? '-' : round(filesize($full_item_path) / 1024, 2) . ' KB',
            'mtime' => date("d/m/Y H:i", filemtime($full_item_path)),
            'rel_link' => ($rel_path ? $rel_path . '/' : '') . $item
        ];
    }

    $file_content = "";
    $cartella_view = strtolower(trim((string)($_GET['view'] ?? 'files')));
    if (!in_array($cartella_view, ['files', 'db'], true)) {
        $cartella_view = 'files';
    }
    $cartella_view_label = $cartella_view === 'db' ? 'Allineamento DB' : 'Allineamento file';
    $cartella_view_is_files = $cartella_view === 'files';
    $cartella_view_is_db = $cartella_view === 'db';
    $selected_file = $_GET['view_file'] ?? null;
    if ($selected_file) {
        $selected_file = str_replace(['..', './'], '', $selected_file);
        $view_path = resolveProjectPath($root_path, $selected_file);
        if ($view_path && file_exists($view_path) && !is_dir($view_path)) {
            $file_content = file_get_contents($view_path);
        }
    }
?>

<style>
    .explorer-container { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
    .table-explorer { margin-bottom: 0; font-size: 14px; }
    .table-explorer tr { cursor: pointer; }
    .table-explorer tr:hover { background-color: #f8f9fa; }
    .icon-folder { color: #ffca28; margin-right: 8px; }
    .icon-file { color: #6c757d; margin-right: 8px; }
    .viewer-box { margin-top: 25px; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .viewer-label { background: #343a40; color: #fff; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; }
    .viewer-label-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .text-container { 
        background: #1e1e1e; color: #d4d4d4; padding: 20px; 
        font-family: 'Consolas', monospace; font-size: 13px; 
        white-space: pre; overflow-x: auto; max-height: 500px;
    }
    .file-actions { width: 120px; text-align: right; }
    .db-schema-code {
        min-height: 360px;
        max-height: 520px;
        overflow: auto;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        background: #1e1e1e;
        color: #d4d4d4;
        padding: 1rem;
        margin: 0;
        font-family: Consolas, monospace;
        font-size: 12px;
    }
    .db-schema-diff-table { max-height: 520px; overflow: auto; }

    @media (max-width: 768px) {
        .file-actions { width: auto; text-align: left; }
        .viewer-label { flex-wrap: wrap; gap: .5rem; }
        .viewer-label-actions { width: 100%; justify-content: flex-start; }
        .db-schema-code {
            min-height: 280px;
            max-height: 420px;
            font-size: 11px;
        }
        .db-schema-diff-table { max-height: 420px; }
    }

    @media (max-width: 576px) {
        .container-fluid.py-3 { padding-left: .75rem !important; padding-right: .75rem !important; }
        .btn { white-space: normal; }
        .d-flex.justify-content-between.align-items-center.mb-3 {
            flex-direction: column;
            align-items: flex-start !important;
            gap: .75rem;
        }
    }
</style>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">
                <?php if ($cartella_view_is_db): ?>
                    <i class="bi bi-database-check me-2 text-primary"></i>Allineamento DB
                <?php else: ?>
                    <i class="bi bi-folder2-open me-2 text-primary"></i>Allineamento file
                <?php endif; ?>
            </h4>
            <small class="text-muted">Progetto: <strong><?= htmlspecialchars($progetto_nome) ?></strong></small>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a href="index.php?page=cartella_progetto&view=files" class="btn btn<?= $cartella_view_is_files ? '' : '-outline' ?>-primary fw-bold shadow-sm">
                <i class="bi bi-folder2-open me-2"></i>Allineamento file
            </a>
            <a href="index.php?page=cartella_progetto&view=db" class="btn btn<?= $cartella_view_is_db ? '' : '-outline' ?>-primary fw-bold shadow-sm">
                <i class="bi bi-database-check me-2"></i>Allineamento DB
            </a>
            <?php if ($cartella_view_is_files): ?>
                <button type="button" class="btn btn-outline-primary fw-bold shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#deployConfigModal">
                    <i class="bi bi-sliders me-2"></i>Configura allineamento
                </button>
                <button type="button" class="btn btn-outline-dark fw-bold shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#destDbConfigModal">
                    <i class="bi bi-database-gear me-2"></i>Configura DB.php
                </button>
                <button type="button" class="btn btn-primary fw-bold shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#deploySyncModal">
                    <i class="bi bi-cloud-arrow-up me-2"></i>Allinea e pubblica
                </button>
                <a href="?page=cartella_progetto&action=export_zip&view=files" class="btn btn-outline-success fw-bold shadow-sm">
                    <i class="bi bi-file-earmark-zip me-2"></i>Esporta Tutto (.ZIP)
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-outline-dark fw-bold shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#destDbConfigModal">
                    <i class="bi bi-database-gear me-2"></i>Configura DB.php
                </button>
                <button type="button" class="btn btn-primary fw-bold shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#deploySyncModal">
                    <i class="bi bi-database-up me-2"></i>Allinea DB
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php
                $successMessage = (string)$_SESSION['success_msg'];
                $report = $_SESSION['db_sync_report'] ?? [];
                $mainMessage = trim((string)preg_replace('/\nReport allineamento DB:.*/s', '', $successMessage));
                $reportSections = [
                    'Analisi' => [],
                    'Rettifica' => [],
                    'Verifica' => [],
                    'Esito finale' => [],
                ];
                if (!empty($report) && is_array($report)) {
                    foreach ($report as $row) {
                        $statement = (string)($row['statement'] ?? '');
                        $targetSection = 'Rettifica';
                        if (
                            str_contains($statement, 'Receiver analizzato')
                            || str_contains($statement, 'Confronto strutturale')
                            || str_contains($statement, 'Differenze rilevate')
                        ) {
                            $targetSection = 'Analisi';
                        } elseif (str_contains($statement, 'Verifica finale')) {
                            $targetSection = 'Verifica';
                        } elseif (
                            str_contains($statement, 'Struttura del DB destinatario')
                            || str_contains($statement, 'Serve un ulteriore passaggio')
                        ) {
                            $targetSection = 'Esito finale';
                        }
                        $reportSections[$targetSection][] = $row;
                    }
                }
            ?>
            <div class="fw-semibold mb-2"><?= htmlspecialchars($mainMessage) ?></div>
            <?php if (!empty($report) && is_array($report)): ?>
                <div class="vstack gap-3 mb-0">
                    <?php foreach ($reportSections as $sectionTitle => $sectionRows): ?>
                        <?php if (empty($sectionRows)) continue; ?>
                        <div class="border rounded-3 bg-white">
                            <div class="d-flex justify-content-between align-items-center border-bottom px-3 py-2 bg-light">
                                <div class="fw-semibold"><?= htmlspecialchars($sectionTitle) ?></div>
                                <div class="small text-muted"><?= count($sectionRows) ?> operazioni</div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 80px;">Esito</th>
                                            <th>Operazione</th>
                                            <th>Errore</th>
                                            <th>SQL suggerito</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sectionRows as $row): ?>
                                            <tr>
                                                <td>
                                                    <?php if (($row['status'] ?? '') === 'ok'): ?>
                                                        <span class="text-success fw-bold">OK</span>
                                                    <?php elseif (($row['status'] ?? '') === 'fail'): ?>
                                                        <span class="text-danger fw-bold">KO</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">..</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?= htmlspecialchars((string)($row['statement'] ?? '')) ?></code></td>
                                                <td class="text-danger small"><?= htmlspecialchars((string)($row['error'] ?? '')) ?></td>
                                                <td class="small"><code><?= htmlspecialchars((string)($row['sql'] ?? '')) ?></code></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($successMessage) && str_contains($successMessage, 'Report allineamento DB:')): ?>
                <div class="alert alert-info mb-0 small">
                    Il report di allineamento è stato generato, ma non contiene righe dettagliate da mostrare.
                </div>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_msg']); ?>
        <?php unset($_SESSION['db_sync_report']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string)$_SESSION['error_msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>

    <?php if (!empty($deploy_config['ultima_pubblicazione'])): ?>
        <div class="alert alert-light border py-2 small">
            <i class="bi bi-clock-history me-1"></i>
            Ultima pubblicazione: <strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$deploy_config['ultima_pubblicazione']))) ?></strong>
            · Esito: <strong><?= htmlspecialchars((string)($deploy_config['ultimo_esito'] ?? '-')) ?></strong>
        </div>
    <?php endif; ?>

    <?php
        $schemaComparison = is_array($db_schema_panel['comparison'] ?? null)
            ? $db_schema_panel['comparison']
            : [];
        $schemaSummary = is_array($schemaComparison['summary'] ?? null)
            ? $schemaComparison['summary']
            : [];
        $localSchemaView = crudProjectExtractUnifiedSchemaView((string)($db_schema_panel['local_sql'] ?? ''));
        $remoteSchemaView = crudProjectExtractUnifiedSchemaView((string)($db_schema_panel['remote_sql'] ?? ''));
        $localSchemaRows = crudProjectBuildUnifiedSchemaRows($localSchemaView);
        $remoteSchemaRows = crudProjectBuildUnifiedSchemaRows($remoteSchemaView);
        $matrixSummary = crudProjectBuildTableComparisonMatrix($localSchemaView, $remoteSchemaView);
        $schemaSummary['equal'] = 0;
        $schemaSummary['different'] = 0;
        $schemaSummary['missing_remote'] = 0;
        $schemaSummary['extra_remote'] = 0;
        foreach ($matrixSummary as $tableData) {
            $mismatchCount = (int)($tableData['mismatch_count'] ?? 0);
            if ($mismatchCount === 0) {
                $schemaSummary['equal']++;
            } else {
                $schemaSummary['different']++;
            }
        }
        $defaultSchemaReceiverUrl = '';
        if (!empty($deploy_config['receiver_url'])) {
            $receiverBase = rtrim(str_replace('\\', '/', dirname((string)$deploy_config['receiver_url'])), '/.');
            $defaultSchemaReceiverUrl = $receiverBase . '/db_schema_receiver.php';
        }
    ?>
    <?php if ($cartella_view_is_db): ?>
    <section class="mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
            <div>
                <h5 class="mb-0">
                    <i class="bi bi-database-check me-2 text-primary"></i>Confronto struttura database
                </h5>
                <div class="small text-muted">
                    Schema prodotto dal CRUD, struttura reale del destinatario e differenze rilevate.
                </div>
            </div>

            <form method="POST" class="d-flex flex-wrap align-items-end gap-2">
                <input type="hidden" name="file_action" value="refresh_db_schema_comparison">
                <input type="hidden" name="deploy_csrf" value="<?= htmlspecialchars($deploy_csrf) ?>">
                <div>
                    <label for="schema_receiver_url" class="form-label small mb-1">URL rilevatore DB</label>
                    <input
                        type="url"
                        class="form-control form-control-sm"
                        id="schema_receiver_url"
                        name="schema_receiver_url"
                        required
                        value="<?= htmlspecialchars($defaultSchemaReceiverUrl) ?>"
                        placeholder="https://sito.altervista.org/receiver/db_schema_receiver.php">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-arrow-repeat me-1"></i>Rileva e confronta
                </button>
            </form>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-6 col-md-3">
                <div class="border rounded bg-light p-2 h-100">
                    <div class="small text-muted">Tabelle allineate</div>
                    <div class="fs-5 fw-bold text-success"><?= (int)($schemaSummary['equal'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded bg-light p-2 h-100">
                    <div class="small text-muted">Solo definizioni diverse</div>
                    <div class="fs-5 fw-bold text-warning"><?= (int)($schemaSummary['different'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded bg-light p-2 h-100">
                    <div class="small text-muted">Mancanti sul destinatario</div>
                    <div class="fs-5 fw-bold text-danger"><?= (int)($schemaSummary['missing_remote'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded bg-light p-2 h-100">
                    <div class="small text-muted">Extra sul destinatario</div>
                    <div class="fs-5 fw-bold text-danger"><?= (int)($schemaSummary['extra_remote'] ?? 0) ?></div>
                </div>
            </div>
        </div>

        <?php if (((int)($schemaSummary['different'] ?? 0) > 0) && ((int)($schemaSummary['missing_remote'] ?? 0) === 0) && ((int)($schemaSummary['extra_remote'] ?? 0) === 0)): ?>
            <div class="alert alert-warning py-2 small mb-2">
                Il destinatario contiene tutte le tabelle previste da `schema.sql`, ma ci sono differenze solo nella struttura dello schema.
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <?php
                $matrix = $matrixSummary;
                uasort($matrix, static function (array $left, array $right): int {
                    $leftMismatch = (int)($left['mismatch_count'] ?? 0);
                    $rightMismatch = (int)($right['mismatch_count'] ?? 0);
                    if ($leftMismatch === $rightMismatch) {
                        return 0;
                    }
                    return $rightMismatch <=> $leftMismatch;
                });
                $tableIndex = 0;
            ?>
            <?php foreach ($matrix as $tableName => $tableData): ?>
                <?php
                    $tableIndex++;
                    $groupRows = $tableData['groups'] ?? [];
                    $rows = $tableData['columns'] ?? [];
                    $tableMismatchCount = (int)($tableData['mismatch_count'] ?? 0);
                    $matchCount = 0;
                    $mismatchCount = 0;
                    foreach ($rows as $rowStat) {
                        if (!empty($rowStat['match'])) {
                            $matchCount++;
                        } else {
                            $mismatchCount++;
                        }
                    }
                ?>
                <div class="col-12">
                    <div class="border rounded overflow-hidden h-100">
                        <div class="bg-light border-bottom px-3 py-2 fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><?= (int)$tableIndex ?>. <code><?= htmlspecialchars($tableName) ?></code></span>
                            <span class="d-flex flex-wrap gap-2 align-items-center small text-muted">
                                <span class="text-success fw-semibold">✓ <?= (int)$matchCount ?></span>
                                <span class="text-danger fw-semibold">✗ <?= (int)$mismatchCount ?></span>
                                <?php if ($tableMismatchCount > 0): ?>
                                    <span class="badge text-bg-danger">Differenze: <?= (int)$tableMismatchCount ?></span>
                                <?php endif; ?>
                                <?php if ($mismatchCount > 0): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="file_action" value="apply_db_schema_alignment">
                                        <input type="hidden" name="deploy_csrf" value="<?= htmlspecialchars($deploy_csrf) ?>">
                                        <input type="hidden" name="schema_receiver_url" value="<?= htmlspecialchars($defaultSchemaReceiverUrl) ?>">
                                        <input type="hidden" name="deploy_receiver_url" value="<?= htmlspecialchars((string)($deploy_config['receiver_url'] ?? '')) ?>">
                                        <input type="hidden" name="deploy_application_url" value="<?= htmlspecialchars((string)($deploy_config['application_url'] ?? '')) ?>">
                                        <input type="hidden" name="deploy_path" value="<?= htmlspecialchars((string)($deploy_config['cartella_destinazione'] ?? '')) ?>">
                                        <input type="hidden" name="deploy_token" value="">
                                        <input type="hidden" name="schema_table_name" value="<?= htmlspecialchars($tableName) ?>">
                                        <button type="submit" class="btn btn-sm btn-warning js-deploy-action" data-action="apply_db_schema_alignment" data-confirm="applyAlignmentTable">
                                            <i class="bi bi-arrow-repeat me-1"></i>Aggiorna questa tabella
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </div>
                    <div class="p-3 vstack gap-3">
                            <?php if (empty($groupRows)): ?>
                                <div class="text-center text-muted py-4">Nessun componente rilevato.</div>
                            <?php else: ?>
                                <?php foreach (['Campi', 'PK', 'UQ', 'IDX', 'FK'] as $groupLabel): ?>
                                    <?php
                                        $groupItems = $groupRows[$groupLabel] ?? [];
                                        if (empty($groupItems)) {
                                            continue;
                                        }
                                    ?>
                                    <div class="border rounded-3 overflow-hidden">
                                        <div class="bg-light border-bottom px-3 py-2 fw-semibold d-flex justify-content-between align-items-center">
                                            <span><?= htmlspecialchars($groupLabel) ?></span>
                                            <span class="small text-muted"><?= count($groupItems) ?> righe</span>
                                        </div>
                                        <div class="table-responsive db-schema-diff-table">
                                            <table class="table table-sm table-bordered align-middle mb-0">
                                                <thead class="table-light sticky-top">
                                                    <tr>
                                                        <th style="width: 34%;">schema.sql</th>
                                                        <th style="width: 34%;">Destinatario</th>
                                                        <th style="width: 10%;">Stato</th>
                                                        <th style="width: 22%;">Motivo</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($groupItems as $row): ?>
                                                        <?php
                                                            $localValue = (string)($row['local'] ?? '');
                                                            $remoteValue = (string)($row['remote'] ?? '');
                                                            $match = !empty($row['match']);
                                                            $localExists = !empty($row['local_exists']);
                                                            $remoteExists = !empty($row['remote_exists']);
                                                            if ($match) {
                                                                $badge = 'bg-success';
                                                                $rowClass = 'table-success';
                                                                $statusLabel = 'OK';
                                                            } elseif ($localExists && !$remoteExists) {
                                                                $badge = 'bg-warning text-dark';
                                                                $rowClass = 'table-warning';
                                                                $statusLabel = 'MANCANTE';
                                                            } elseif (!$localExists && $remoteExists) {
                                                                $badge = 'bg-info text-dark';
                                                                $rowClass = 'table-info';
                                                                $statusLabel = 'EXTRA';
                                                            } else {
                                                                $badge = 'bg-danger';
                                                                $rowClass = 'table-danger';
                                                                $statusLabel = 'KO';
                                                            }
                                                            $reason = (string)($row['reason'] ?? '');
                                                            if ($reason === '') {
                                                                $reason = $match
                                                                    ? 'Corrispondenza trovata nel destinatario.'
                                                                    : ($localExists || $remoteExists ? 'Componente non perfettamente allineato.' : 'Componente assente in entrambi.');
                                                            }
                                                        ?>
                                                        <tr class="<?= $rowClass ?>">
                                                            <td class="small"><code><?= htmlspecialchars($localExists ? ($match ? '✓ ' : '✗ ') . $localValue : '') ?></code></td>
                                                            <td class="small"><code><?= htmlspecialchars($remoteExists ? ($match ? '✓ ' : '✗ ') . $remoteValue : '') ?></code></td>
                                                            <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                                            <td class="small text-muted"><?= htmlspecialchars($reason) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($matrix)): ?>
                <div class="col-12">
                    <div class="alert alert-light border text-center mb-0">Premere “Rileva e confronta”.</div>
                </div>
            <?php endif; ?>
        </div>

    </section>
    <?php endif; ?>

    <?php if ($cartella_view_is_files): ?>
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb border p-2 bg-light rounded shadow-sm">
            <li class="breadcrumb-item"><a href="?page=cartella_progetto&path=">root</a></li>
            <?php 
            $parts = explode('/', trim($rel_path, '/'));
            $built_path = '';
            foreach($parts as $p): 
                if(!$p) continue;
                $built_path .= ($built_path ? '/' : '') . $p;
            ?>
                <li class="breadcrumb-item"><a href="?page=cartella_progetto&path=<?= urlencode($built_path) ?>"><?= htmlspecialchars($p) ?></a></li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div class="explorer-container shadow-sm">
        <div class="p-2 bg-light border-bottom d-flex justify-content-between align-items-center">
            <div>
                <?php if ($rel_path): ?>
                    <a href="?page=cartella_progetto&path=<?= urlencode(dirname($rel_path) == '.' ? '' : dirname($rel_path)) ?>" class="btn btn-sm btn-secondary me-2">
                        <i class="bi bi-arrow-up-short"></i> Su
                    </a>
                <?php endif; ?>
                <span class="small">Cartella: <code>/<?= htmlspecialchars($rel_path) ?></code></span>
            </div>
        </div>

        <table class="table table-explorer align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nome</th>
                    <th>Descrizione</th>
                    <th style="width: 140px;">Versione</th>
                    <th style="width: 120px;">Dimensione</th>
                    <th style="width: 180px;">Modifica</th>
                    <th class="file-actions">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">Cartella vuota.</td></tr>
                <?php else: 
                    usort($items, function($a, $b) { return $b['is_dir'] - $a['is_dir']; });
                    foreach ($items as $item): 
                        $link = $item['is_dir'] 
                                ? "?page=cartella_progetto&path=" . urlencode($item['rel_link']) 
                                : "?page=cartella_progetto&path=".urlencode($rel_path)."&view_file=" . urlencode($item['rel_link']);
                ?>
                    <tr onclick="window.location.href='<?= htmlspecialchars($link) ?>'">
                        <td>
                            <?php if($item['is_dir']): ?>
                                <i class="bi bi-folder-fill icon-folder"></i>
                            <?php else: ?>
                                <i class="bi bi-file-earmark-text icon-file"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($item['name']) ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($item['description'] ?: '-') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($item['version'] ?: '-') ?></td>
                        <td class="text-muted small"><?= $item['size'] ?></td>
                        <td class="text-muted small"><?= $item['mtime'] ?></td>
                        <td class="file-actions" onclick="event.stopPropagation();">
                            <button
                                type="button"
                                class="btn btn-sm text-primary p-1 js-rename-item"
                                title="Rinomina"
                                data-path="<?= htmlspecialchars($item['rel_link']) ?>"
                                data-name="<?= htmlspecialchars($item['name']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <form method="POST" class="d-inline js-delete-item">
                                <input type="hidden" name="file_action" value="delete_item">
                                <input type="hidden" name="target_path" value="<?= htmlspecialchars($item['rel_link']) ?>">
                                <button type="submit" class="btn btn-sm text-danger p-1" title="Cancella">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($selected_file && $file_content !== ""): ?>
        <div class="viewer-box">
            <div class="viewer-label">
                <span><i class="bi bi-file-code me-2"></i> <?= htmlspecialchars(basename($selected_file)) ?></span>
                <div class="viewer-label-actions">
                    <button type="button" class="btn btn-sm btn-outline-light js-copy-code" data-copy-target="fileCodeViewer">
                        <i class="bi bi-clipboard me-1"></i>Copia codice
                    </button>
                    <button type="button" class="btn-close btn-close-white" onclick="this.parentElement.parentElement.parentElement.style.display='none'"></button>
                </div>
            </div>
            <div class="text-container" id="fileCodeViewer"><?= htmlspecialchars($file_content) ?></div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>


<div class="modal fade" id="deployConfigModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content deploy-section-form" id="deployConfigForm">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Configurazione allineamento file e database</h5>
                    <div class="small text-muted">Parametri di collegamento, associazione e destinazione</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="file_action" class="deploy-file-action" value="save_deploy_config">
                <input type="hidden" name="deploy_csrf" value="<?= htmlspecialchars($deploy_csrf) ?>">

                <div class="alert alert-info small">
                    Configura il collegamento HTTPS con il receiver, il progetto remoto e la cartella di destinazione.
                    Questa sezione non pubblica file e non applica modifiche al database.
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label for="cfg_deploy_receiver_url" class="form-label">URL ricevitore HTTPS <span class="text-danger">*</span></label>
                        <input type="url" class="form-control js-receiver-url" id="cfg_deploy_receiver_url" name="deploy_receiver_url" required
                               value="<?= htmlspecialchars((string)$deploy_config['receiver_url']) ?>"
                               placeholder="https://nomesito.altervista.org/receiver/deploy_receiver.php">
                    </div>

                    <div class="col-12">
                        <label for="cfg_schema_receiver_url" class="form-label">Rilevatore struttura DB</label>
                        <input type="url" class="form-control js-schema-receiver-url" id="cfg_schema_receiver_url"
                               value="<?= htmlspecialchars($schema_receiver_url) ?>" readonly>
                        <div class="form-text">Calcolato automaticamente dall’URL del ricevitore HTTPS.</div>
                    </div>

                    <div class="col-12 col-md-7">
                        <label for="cfg_deploy_token" class="form-label">
                            Token segreto <?= $deploy_token_saved ? '<span class="text-muted small">(già salvato)</span>' : '<span class="text-danger">*</span>' ?>
                        </label>
                        <input type="password" class="form-control" id="cfg_deploy_token" name="deploy_token"
                               autocomplete="new-password" <?= $deploy_token_saved ? '' : 'required' ?>
                               placeholder="<?= $deploy_token_saved ? 'Lascia vuoto per mantenere quello salvato' : 'Token del ricevitore' ?>">
                    </div>

                    <div class="col-12 col-md-5">
                        <label for="cfg_deploy_project_uuid" class="form-label">UUID progetto <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="cfg_deploy_project_uuid" name="deploy_project_uuid" required
                               value="<?= htmlspecialchars((string)$deploy_config['project_uuid']) ?>">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="cfg_deploy_path" class="form-label">Deploy path / cartella remota</label>
                        <input type="text" class="form-control" id="cfg_deploy_path" name="deploy_path"
                               value="<?= htmlspecialchars((string)$deploy_config['cartella_destinazione']) ?>"
                               placeholder="es. gestionale oppure . per root">
                        <div class="form-text">Inserisci <code>.</code> per pubblicare nella root del sito.</div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="cfg_application_url" class="form-label">Application URL</label>
                        <input type="url" class="form-control" id="cfg_application_url" name="deploy_application_url"
                               value="<?= htmlspecialchars((string)($deploy_config['application_url'] ?? '')) ?>"
                               placeholder="https://nomesito.altervista.org/gestionale/">
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="cfg_keep_backups" class="form-label">Backup locali da mantenere</label>
                        <input type="number" class="form-control" id="cfg_keep_backups" name="deploy_keep_backups" min="1" max="30"
                               value="<?= (int)$deploy_config['backup_da_mantenere'] ?>">
                    </div>

                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" disabled>
                            <label class="form-check-label text-muted">Backup remoto disattivato</label>
                        </div>
                    </div>

                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="cfg_delete_missing" name="deploy_delete_missing"
                                   <?= !empty($deploy_config['elimina_file_mancanti']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg_delete_missing">Pulisci file inutili sul sito</label>
                        </div>
                    </div>
                </div>

                <div class="alert alert-light border mt-4 mb-0 small">
                    <div><strong>Ultimo esito configurazione:</strong>
                        <?= htmlspecialchars((string)($deploy_config['ultimo_esito_config'] ?? '-')) ?>
                    </div>
                    <div><?= nl2br(htmlspecialchars((string)($deploy_config['ultimo_messaggio_config'] ?? 'Nessuna operazione eseguita.'))) ?></div>
                </div>
            </div>

            <div class="modal-footer d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button type="submit" class="btn btn-outline-primary js-deploy-action" data-action="save_deploy_config">
                    <i class="bi bi-floppy me-1"></i>Salva
                </button>
                <button type="submit" class="btn btn-outline-info js-deploy-action" data-action="ping_deploy_receiver">
                    <i class="bi bi-plug me-1"></i>Verifica ricevitore
                </button>
                <button type="submit" class="btn btn-outline-secondary js-deploy-action" data-action="inspect_deploy_target">
                    <i class="bi bi-search me-1"></i>Ispeziona
                </button>
                <button type="submit" class="btn btn-outline-warning js-deploy-action" data-action="associate_deploy_target" data-confirm="associate">
                    <i class="bi bi-link-45deg me-1"></i>Associa
                </button>
                <button type="submit" class="btn btn-outline-danger js-deploy-action" data-action="disassociate_deploy_target" data-confirm="disassociate">
                    <i class="bi bi-link-45deg me-1"></i>Disassocia
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="destDbConfigModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content deploy-section-form" id="destDbConfigForm">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Configurazione DB.php destinatario</h5>
                    <div class="small text-muted">Parametri di connessione e generazione del file DB.php</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="file_action" class="deploy-file-action" value="save_dest_db_config">
                <input type="hidden" name="deploy_csrf" value="<?= htmlspecialchars($deploy_csrf) ?>">

                <div class="alert alert-light border small">
                    Questi parametri vengono salvati una volta per progetto e usati per generare automaticamente il file <code>db.php</code>.
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label for="db_host" class="form-label">Host DB</label>
                        <input type="text" class="form-control" id="db_host" name="dest_db_host"
                               value="<?= htmlspecialchars((string)($dest_db_config['host'] ?? 'localhost')) ?>">
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="db_name" class="form-label">Nome database</label>
                        <input type="text" class="form-control" id="db_name" name="dest_db_name"
                               value="<?= htmlspecialchars((string)($dest_db_config['db_name'] ?? '')) ?>">
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="db_user" class="form-label">Utente DB</label>
                        <input type="text" class="form-control" id="db_user" name="dest_db_user"
                               value="<?= htmlspecialchars((string)($dest_db_config['db_user'] ?? '')) ?>">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="db_pass" class="form-label">
                            Password DB <?= $dest_db_saved ? '<span class="text-muted small">(già salvata)</span>' : '' ?>
                        </label>
                        <input type="password" class="form-control" id="db_pass" name="dest_db_pass"
                               autocomplete="new-password"
                               placeholder="<?= $dest_db_saved ? 'Lascia vuoto per mantenere quella salvata' : 'Password database' ?>">
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="db_charset" class="form-label">Charset</label>
                        <input type="text" class="form-control" id="db_charset" name="dest_db_charset"
                               value="<?= htmlspecialchars((string)($dest_db_config['charset_name'] ?? 'utf8mb4')) ?>">
                    </div>

                    <input type="hidden" name="dest_db_auto_initialize" value="1">
                    <input type="hidden" name="dest_db_auto_apply" value="<?= (int)($dest_db_config['auto_apply'] ?? 1) ?>">
                    <input type="hidden" name="dest_db_modify_columns" value="<?= (int)($dest_db_config['modify_columns'] ?? 1) ?>">
                    <input type="hidden" name="dest_db_drop_extra_columns" value="<?= (int)($dest_db_config['drop_extra_columns'] ?? 0) ?>">
                    <input type="hidden" name="dest_db_drop_extra_tables" value="<?= (int)($dest_db_config['drop_extra_tables'] ?? 0) ?>">
                    <input type="hidden" name="dest_db_make_extra_nullable" value="<?= (int)($dest_db_config['make_extra_nullable'] ?? 1) ?>">
                    <input type="hidden" name="dest_db_sync_schema_files" value="<?= (int)($dest_db_config['sync_schema_files'] ?? 1) ?>">
                </div>

                <?php if (!empty($dest_db_config['ultima_generazione'])): ?>
                    <div class="alert alert-light border mt-3 small">
                        Ultima generazione db.php:
                        <strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$dest_db_config['ultima_generazione']))) ?></strong>
                    </div>
                <?php endif; ?>

                <div class="alert alert-light border mt-3 mb-0 small">
                    <div><strong>Ultimo esito DB.php:</strong>
                        <?= htmlspecialchars((string)($deploy_config['ultimo_esito_db'] ?? '-')) ?>
                    </div>
                    <div><?= nl2br(htmlspecialchars((string)($deploy_config['ultimo_messaggio_db'] ?? 'Nessuna operazione eseguita.'))) ?></div>
                </div>
            </div>

            <div class="modal-footer d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button type="submit" class="btn btn-outline-dark js-deploy-action" data-action="save_dest_db_config" formnovalidate>
                    <i class="bi bi-database-check me-1"></i>Salva
                </button>
                <?php if ($dest_db_saved): ?>
                    <button type="submit" class="btn btn-outline-danger js-deploy-action"
                            data-action="delete_dest_db_config" data-confirm="deleteDestDb" formnovalidate>
                        <i class="bi bi-database-x me-1"></i>Rimuovi
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="deploySyncModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content deploy-section-form" id="deploySyncForm">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Allineamento e pubblicazione</h5>
                    <div class="small text-muted">Opzioni operative per file e struttura database</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="file_action" class="deploy-file-action" value="publish_project">
                <input type="hidden" name="deploy_csrf" value="<?= htmlspecialchars($deploy_csrf) ?>">

                <!-- Parametri necessari, riportati in forma nascosta -->
                <input type="hidden" name="deploy_receiver_url" value="<?= htmlspecialchars((string)$deploy_config['receiver_url']) ?>">
                <input type="hidden" name="deploy_project_uuid" value="<?= htmlspecialchars((string)$deploy_config['project_uuid']) ?>">
                <input type="hidden" name="deploy_path" value="<?= htmlspecialchars((string)$deploy_config['cartella_destinazione']) ?>">
                <input type="hidden" name="deploy_application_url" value="<?= htmlspecialchars((string)($deploy_config['application_url'] ?? '')) ?>">
                <input type="hidden" name="deploy_keep_backups" value="<?= (int)$deploy_config['backup_da_mantenere'] ?>">
                <input type="hidden" name="deploy_delete_missing" value="<?= !empty($deploy_config['elimina_file_mancanti']) ? '1' : '0' ?>">

                <div class="alert alert-warning small">
                    Le opzioni di questa sezione riguardano solo i file del progetto da pubblicare.
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="alert alert-light border mb-0 small">
                            Verranno pubblicati `index.php`, `db.php`, le pagine generate e gli asset necessari già inclusi nel progetto.
                        </div>
                    </div>
                </div>

                <div class="alert alert-light border mt-4 mb-0 small">
                    <div><strong>Ultimo esito pubblicazione:</strong>
                        <?= htmlspecialchars((string)($deploy_config['ultimo_esito'] ?? '-')) ?>
                    </div>
                    <div><?= nl2br(htmlspecialchars((string)($deploy_config['ultimo_messaggio'] ?? 'Nessuna operazione eseguita.'))) ?></div>
                    <?php if (!empty($deploy_config['last_archive_sha256'])): ?>
                        <div class="text-muted mt-1">
                            SHA-256 ultimo archivio:
                            <code><?= htmlspecialchars((string)$deploy_config['last_archive_sha256']) ?></code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button type="submit" class="btn btn-primary js-deploy-action" data-action="publish_project" data-confirm="publish">
                    <i class="bi bi-cloud-arrow-up me-1"></i>Pubblica
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="renameItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rinomina elemento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="file_action" value="rename_item">
                <input type="hidden" name="target_path" id="rename_target_path">
                <label for="rename_new_name" class="form-label small fw-bold">Nuovo nome</label>
                <input type="text" name="new_name" id="rename_new_name" class="form-control" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">Rinomina</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-receiver-url').forEach(function (receiverUrlInput) {
        const form = receiverUrlInput.closest('form');
        const schemaReceiverUrlInput = form?.querySelector('.js-schema-receiver-url');

        const updateSchemaReceiverUrl = function () {
            if (!schemaReceiverUrlInput) return;

            const rawUrl = receiverUrlInput.value.trim();
            if (rawUrl === '') {
                schemaReceiverUrlInput.value = '';
                return;
            }

            try {
                const url = new URL(rawUrl);
                const pathParts = url.pathname.split('/').filter(Boolean);
                if (pathParts.length > 0) {
                    pathParts[pathParts.length - 1] = 'db_schema_receiver.php';
                } else {
                    pathParts.push('receiver', 'db_schema_receiver.php');
                }
                url.pathname = '/' + pathParts.join('/');
                url.search = '';
                url.hash = '';
                schemaReceiverUrlInput.value = url.toString();
            } catch (error) {
                schemaReceiverUrlInput.value = '';
            }
        };

        receiverUrlInput.addEventListener('input', updateSchemaReceiverUrl);
        receiverUrlInput.addEventListener('change', updateSchemaReceiverUrl);
        updateSchemaReceiverUrl();
    });

    document.querySelectorAll('.js-rename-item').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('rename_target_path').value = button.dataset.path;
            document.getElementById('rename_new_name').value = button.dataset.name;
            new bootstrap.Modal(document.getElementById('renameItemModal')).show();
        });
    });

    document.querySelectorAll('.js-deploy-action').forEach(function (button) {
        button.addEventListener('click', function (event) {
            const action = button.dataset.action;
            const form = button.closest('form');
            form?.querySelector('.deploy-file-action')?.setAttribute('value', action);
            if (button.dataset.confirm) {
                event.preventDefault();
                event.stopPropagation();
                const messages = {
                    associate: 'Associare questa cartella remota al progetto selezionato?',
                    disassociate: 'Disassociare questa cartella remota dal progetto selezionato?',
                    applyAlignmentTable: 'Aggiornare questa tabella con il solo allineamento delle differenze rilevate?',
                    publish: function () {
                        return [
                            'Pubblicare il progetto tramite HTTPS?',
                            '',
                            'Operazioni previste:',
                            '• rigenerare `schema.sql` e `schema.json` dal progetto attivo',
                            '• pubblicare i file necessari sul sito remoto',
                            '• mantenere allineati solo i file del progetto',
                            '',
                            'Questa azione pubblica solo i file del progetto, senza operazioni sul database.'
                        ].join('\n');
                    },
                    deleteDestDb: 'Rimuovere i parametri DB destinatario salvati per questo progetto?'
                };
                const confirmMessage = typeof messages[button.dataset.confirm] === 'function'
                    ? messages[button.dataset.confirm]()
                    : (messages[button.dataset.confirm] || 'Confermare l’operazione?');
                const proceed = function () {
                    form?.querySelector('.deploy-file-action')?.setAttribute('value', action);
                    form?.submit();
                };
                if (typeof window.showConfirmationModal === 'function') {
                    window.showConfirmationModal(confirmMessage, proceed);
                } else if (window.confirm(confirmMessage)) {
                    proceed();
                }
            }
        });
    });

    document.querySelectorAll('.js-delete-item').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            window.showConfirmationModal('Vuoi cancellare definitivamente questo elemento?', function () {
                form.submit();
            });
        });
    });

    document.querySelectorAll('.js-copy-code').forEach(function (button) {
        button.addEventListener('click', async function () {
            const targetId = button.dataset.copyTarget;
            const target = targetId ? document.getElementById(targetId) : null;
            if (!target) {
                return;
            }

            const code = target.textContent || '';
            const originalHtml = button.innerHTML;

            const feedback = function (label, iconClass) {
                button.innerHTML = '<i class="bi ' + iconClass + ' me-1"></i>' + label;
                button.disabled = true;
                setTimeout(function () {
                    button.innerHTML = originalHtml;
                    button.disabled = false;
                }, 1500);
            };

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(code);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = code;
                    textarea.setAttribute('readonly', 'readonly');
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                }
                feedback('Copiato', 'bi-check-lg');
            } catch (error) {
                console.error('Copia codice non riuscita:', error);
                feedback('Errore', 'bi-x-lg');
            }
        });
    });
});
</script>

<?php } else { ?>
    <div class="alert alert-danger m-4">Nessun progetto attivo selezionato.</div>
<?php } ?>
