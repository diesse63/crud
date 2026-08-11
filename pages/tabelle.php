<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
if (!isset($_SESSION['progetto_id'])) {
    header("Location: index.php?page=progetti");
    exit;
}
$progetto_id = $_SESSION['progetto_id'];

$progetto_nome_attivo = $_SESSION['progetto_nome'] ?? 'progetto';

// Funzione per rinominare i campi ID se rinomini la tabella (integrità strutturale)
if (!function_exists('syncProjectIntegrity')) {
    function syncProjectIntegrity($db, $progetto_id) {
        $tabelle = $db->fetchAll("SELECT id, nome FROM tabelle WHERE IDprogetto = ?", [$progetto_id]);
        foreach ($tabelle as $t) {
            $nome_campo_id = "id_" . $t['nome'];
            $db->execute("UPDATE campi SET nome = ? WHERE IDtabella = ? AND auto_increment = 1", [$nome_campo_id, $t['id']]);
        }
    }
}

function refreshTab() {
    if (ob_get_length()) ob_clean(); 
    header("Location: index.php?page=tabelle&t=" . time());
    exit;
}


// --- SETUP DB LOCALE INTEGRATO IN TABELLE.PHP ---
if (!function_exists('setupDbSanitizeFolderName')) {
    function setupDbSanitizeFolderName($name) {
        $name = strtolower(trim((string)$name));
        $name = str_replace(array(' ', '.', ',', '!', '?'), '_', $name);
        $name = preg_replace('/[^a-z0-9\_]/', '', $name);
        return $name ?: 'progetto_senza_nome';
    }
}

if (!function_exists('setupDbKeyPath')) {
    function setupDbKeyPath(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'setup_db.key';
    }
}

if (!function_exists('setupDbEncryptionKey')) {
    function setupDbEncryptionKey(): string {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('OpenSSL non è disponibile: impossibile cifrare la password DB.');
        }
        $keyPath = setupDbKeyPath();
        $keyDir = dirname($keyPath);
        if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
            throw new RuntimeException('Impossibile creare la cartella config per la chiave Setup DB.');
        }
        if (!is_file($keyPath)) {
            $key = random_bytes(32);
            if (file_put_contents($keyPath, base64_encode($key), LOCK_EX) === false) {
                throw new RuntimeException('Impossibile salvare la chiave Setup DB.');
            }
            @chmod($keyPath, 0600);
            return $key;
        }
        $key = base64_decode(trim((string)file_get_contents($keyPath)), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Chiave Setup DB non valida.');
        }
        return $key;
    }
}

if (!function_exists('setupDbEncryptSecret')) {
    function setupDbEncryptSecret(string $secret): string {
        $cipher = 'aes-256-gcm';
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $tag = '';
        $encrypted = openssl_encrypt($secret, $cipher, setupDbEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) throw new RuntimeException('Cifratura password DB non riuscita.');
        return base64_encode($iv . $tag . $encrypted);
    }
}

if (!function_exists('setupDbDecryptSecret')) {
    function setupDbDecryptSecret(string $payload): string {
        if ($payload === '') return '';
        $raw = base64_decode($payload, true);
        $cipher = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($raw === false || strlen($raw) <= $ivLength + 16) {
            throw new RuntimeException('Password DB cifrata non valida.');
        }
        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, 16);
        $encrypted = substr($raw, $ivLength + 16);
        $secret = openssl_decrypt($encrypted, $cipher, setupDbEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($secret === false) throw new RuntimeException('Impossibile decifrare la password DB salvata.');
        return $secret;
    }
}

if (!function_exists('setupDbEnsureTable')) {
    function setupDbEnsureTable($db): void {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS progetti_setup_db (
                id INT NOT NULL AUTO_INCREMENT,
                IDprogetto INT NOT NULL,
                db_host VARCHAR(255) NOT NULL DEFAULT 'localhost',
                db_name VARCHAR(255) NOT NULL DEFAULT '',
                db_user VARCHAR(255) NOT NULL DEFAULT '',
                db_pass_cifrata TEXT NULL,
                ultima_generazione DATETIME NULL,
                ultimo_messaggio TEXT NULL,
                aggiornato_il TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_progetti_setup_db (IDprogetto)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('setupDbValidateIdentifierValue')) {
    function setupDbValidateIdentifierValue(string $value, string $label): string {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException($label . ' obbligatorio.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.$-]+$/', $value)) {
            throw new RuntimeException($label . ' contiene caratteri non validi.');
        }
        return $value;
    }
}

if (!function_exists('setupDbGenerateDbPhp')) {
    function setupDbGenerateDbPhp(string $host, string $dbName, string $dbUser, string $dbPass): string {
        $template = <<<'PHPDB'
<?php
class Database {
    private $pdo;

    public function __construct() {
        $host = {{HOST}};
        $db   = {{DBNAME}};
        $user = {{DBUSER}};
        $pass = {{DBPASS}};
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 4,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            $this->_autoInitialize();
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Connessione database fallita: " . $e->getMessage());
        }
    }

    /**
     * Esegue automaticamente lo script SQL presente nella cartella.
     */
    private function _autoInitialize() {
        $sqlFiles = glob(__DIR__ . DIRECTORY_SEPARATOR . "*.sql");
        if (!empty($sqlFiles)) {
            $sqlPath = $sqlFiles[0];
            $query = file_get_contents($sqlPath);
            if (!empty($query)) {
                $this->pdo->exec($query);
            }
        }
    }

    public function fetchAll($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetch($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function fetchColumn($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollBack() {
        if ($this->pdo && $this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
}

$db = new Database();
PHPDB;

        return str_replace(
            ['{{HOST}}', '{{DBNAME}}', '{{DBUSER}}', '{{DBPASS}}'],
            [var_export($host, true), var_export($dbName, true), var_export($dbUser, true), var_export($dbPass, true)],
            $template
        );
    }
}

if (!function_exists('setupDbProjectPath')) {
    function setupDbProjectPath(string $projectName): string {
        $folderName = setupDbSanitizeFolderName($projectName);
        return __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $folderName;
    }
}

if (!function_exists('setupDbWriteDbPhp')) {
    function setupDbWriteDbPhp(string $projectName, string $code): array {
        $basePath = setupDbProjectPath($projectName);
        if (!is_dir($basePath) && !mkdir($basePath, 0755, true) && !is_dir($basePath)) {
            throw new RuntimeException('Impossibile creare la cartella progetto: ' . basename($basePath));
        }
        $targetFile = $basePath . DIRECTORY_SEPARATOR . 'db.php';
        $changed = true;
        if (is_file($targetFile)) {
            $existing = file_get_contents($targetFile);
            if ($existing === $code) {
                $changed = false;
            } else {
                $backupFile = $targetFile . '.bak_' . date('Ymd_His');
                @copy($targetFile, $backupFile);
            }
        }
        if ($changed && file_put_contents($targetFile, $code, LOCK_EX) === false) {
            throw new RuntimeException('Errore durante la scrittura di db.php. Verifica i permessi della cartella.');
        }
        return ['path' => $targetFile, 'changed' => $changed];
    }
}


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

if (!function_exists('projectSchemaNormalizeNamePart')) {
    function projectSchemaNormalizeNamePart(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim($value, '_');
    }
}

if (!function_exists('projectSchemaBuildFkName')) {
    function projectSchemaBuildFkName(string $tableName, array $locNames, string $refTable, array $existingNames = []): string {
        $locPart = projectSchemaNormalizeNamePart(implode('_', $locNames));
        $refPart = projectSchemaNormalizeNamePart($refTable);
        $base = 'fk_' . projectSchemaNormalizeNamePart($tableName);
        if ($refPart !== '') {
            $base .= '_' . $refPart;
        }
        if ($locPart !== '') {
            $base .= '_' . $locPart;
        }
        $base = trim($base, '_');

        if ($base === 'fk' || $base === 'fk_') {
            $base = 'fk_' . projectSchemaNormalizeNamePart($tableName);
        }

        $taken = [];
        foreach ($existingNames as $existingName) {
            $taken[(string)$existingName] = true;
        }

        if (!isset($taken[$base])) {
            return $base;
        }

        $suffix = 2;
        do {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        } while (isset($taken[$candidate]));

        return $candidate;
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
                 WHERE t_loc.IDprogetto = ? AND t_ref.IDprogetto = ?',
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
            $fields = $db->fetchAll('SELECT * FROM campi WHERE IDtabella = ? ORDER BY ordine', [(int)$table['id']]);
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
                $tableFks = $db->fetchAll(
                    'SELECT fk.*, t_ref.nome as ref_table
                     FROM foreign_keys fk
                     JOIN foreign_keys_campi fkc ON fk.id = fkc.IDforeign_key
                     JOIN campi cl ON fkc.IDcampo_locale = cl.id
                     JOIN campi cr ON fkc.IDcampo_referenziato = cr.id
                     JOIN tabelle t_ref ON cr.IDtabella = t_ref.id
                     WHERE cl.IDtabella = ?
                     GROUP BY fk.id',
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
                $refTable = (string)($fk['ref_table'] ?? '');
                $onDelete = strtoupper((string)($fk['on_delete'] ?? 'RESTRICT'));
                $onUpdate = strtoupper((string)($fk['on_update'] ?? 'CASCADE'));
                $fkName = trim((string)($fk['nome'] ?? ''));
                if ($fkName === '') {
                    $existingFkNames = array_map(
                        static fn(array $row): string => (string)($row['nome'] ?? ''),
                        $tableFks
                    );
                    $fkName = projectSchemaBuildFkName($tableName, $locNames, $refTable, $existingFkNames);
                }

                $definitions[] = "  KEY `idx_{$fkName}` ({$locCols})";
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



// --- INIZIALIZZAZIONE SETUP DB LOCALE ---
setupDbEnsureTable($db);
$setupDbConfig = $db->fetch("SELECT * FROM progetti_setup_db WHERE IDprogetto = ?", [$progetto_id]) ?: [];

// Recupera lo stato del modale e il codice generato dalla sessione
$setupDbGeneratedCode = $_SESSION['setupDbGeneratedCode'] ?? '';
$setupDbOpenModal = $_SESSION['setupDbOpenModal'] ?? false;

// Cancella lo stato del modale dalla sessione se non è una richiesta di preview o save_file
// Questo è importante per evitare che il modale si riapra se l'utente naviga via e torna indietro
if (!isset($_POST['setupdb_preview']) && !isset($_POST['setupdb_save_file'])) {
    unset($_SESSION['setupDbGeneratedCode']);
    unset($_SESSION['setupDbOpenModal']);
    $setupDbOpenModal = false; // Assicurati che il flag sia false se non stiamo processando un preview/save
}
$setupDbFolderName = setupDbSanitizeFolderName($progetto_nome_attivo);

// --- AZIONI SETUP DB LOCALE INTEGRATE ---
if (isset($_POST['clear_setupdb_session'])) {
    unset($_SESSION['setupDbGeneratedCode']);
    unset($_SESSION['setupDbOpenModal']);
    $setupDbOpenModal = false; // Ensure local variable is also updated
    // No refreshTab() needed here as it's handled by JS, or can be a simple page reload
}


if (isset($_POST['setupdb_save_params'])) {
    try {
        $host = setupDbValidateIdentifierValue((string)($_POST['setupdb_host'] ?? 'localhost'), 'Host');
        $dbName = setupDbValidateIdentifierValue((string)($_POST['setupdb_name'] ?? ''), 'Nome database');
        $dbUser = setupDbValidateIdentifierValue((string)($_POST['setupdb_user'] ?? ''), 'Utente database');
        $newPass = (string)($_POST['setupdb_pass'] ?? '');
        $oldEncryptedPass = (string)($setupDbConfig['db_pass_cifrata'] ?? '');
        $encryptedPass = $newPass !== '' ? setupDbEncryptSecret($newPass) : $oldEncryptedPass;

        if ($encryptedPass === '') {
            $encryptedPass = setupDbEncryptSecret('');
        }

        $db->execute(
            "INSERT INTO progetti_setup_db
                (IDprogetto, db_host, db_name, db_user, db_pass_cifrata, ultimo_messaggio)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                db_host = VALUES(db_host),
                db_name = VALUES(db_name),
                db_user = VALUES(db_user),
                db_pass_cifrata = VALUES(db_pass_cifrata),
                ultimo_messaggio = VALUES(ultimo_messaggio)",
            [$progetto_id, $host, $dbName, $dbUser, $encryptedPass, 'Parametri Setup DB salvati.']
        );
        $_SESSION['success_msg'] = 'Parametri Setup DB salvati correttamente.';
        // Cancella lo stato del modale dalla sessione
        unset($_SESSION['setupDbGeneratedCode']);
        unset($_SESSION['setupDbOpenModal']);
    } catch (Throwable $e) {
        $_SESSION['error_msg'] = 'Setup DB: ' . $e->getMessage();
    }
    refreshTab();
}

if (isset($_POST['setupdb_delete_params'])) {
    try {
        $db->execute("DELETE FROM progetti_setup_db WHERE IDprogetto = ?", [$progetto_id]);
        $_SESSION['success_msg'] = 'Parametri Setup DB eliminati.';
        // Cancella lo stato del modale dalla sessione
        unset($_SESSION['setupDbGeneratedCode']);
        unset($_SESSION['setupDbOpenModal']);
    } catch (Throwable $e) {
        $_SESSION['error_msg'] = 'Setup DB: ' . $e->getMessage();
    }
    refreshTab();
}

if (isset($_POST['setupdb_preview'])) {
    try {
        $host = setupDbValidateIdentifierValue((string)($_POST['setupdb_host'] ?? 'localhost'), 'Host');
        $dbName = setupDbValidateIdentifierValue((string)($_POST['setupdb_name'] ?? ''), 'Nome database');
        $dbUser = setupDbValidateIdentifierValue((string)($_POST['setupdb_user'] ?? ''), 'Utente database');
        $dbPass = (string)($_POST['setupdb_pass'] ?? '');
        if ($dbPass === '' && !empty($setupDbConfig['db_pass_cifrata'])) {
            $dbPass = setupDbDecryptSecret((string)$setupDbConfig['db_pass_cifrata']);
        }
        $setupDbGeneratedCode = setupDbGenerateDbPhp($host, $dbName, $dbUser, $dbPass);
        $setupDbConfig = array_merge($setupDbConfig, [
            'db_host' => $host,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass_cifrata' => $setupDbConfig['db_pass_cifrata'] ?? '',
        ]);
        // Salva il codice generato e lo stato del modale nella sessione
        $_SESSION['setupDbGeneratedCode'] = $setupDbGeneratedCode;
        $_SESSION['setupDbOpenModal'] = true;
        $setupDbOpenModal = true; // Aggiorna la variabile locale per il rendering immediato
    } catch (Throwable $e) {
        $_SESSION['error_msg'] = 'Setup DB: ' . $e->getMessage();
        $_SESSION['setupDbOpenModal'] = true; // Mantieni il modale aperto anche in caso di errore
        $setupDbOpenModal = true;
    }
    // Non chiamare refreshTab() qui per evitare un redirect che chiuderebbe il modale
}

if (isset($_POST['setupdb_save_file'])) {
    try {
        $codeToSave = (string)($_POST['setupdb_code_content'] ?? '');
        if (trim($codeToSave) === '') {
            throw new RuntimeException('Generare prima l’anteprima di db.php.');
        }
        $result = setupDbWriteDbPhp($progetto_nome_attivo, $codeToSave);
        $db->execute(
            "UPDATE progetti_setup_db SET ultima_generazione = NOW(), ultimo_messaggio = ? WHERE IDprogetto = ?",
            ['db.php generato in ' . basename(dirname($result['path'])) . '.', $progetto_id]
        );
        $_SESSION['success_msg'] = $result['changed']
            ? 'File db.php creato/aggiornato nella cartella progetto.'
            : 'File db.php già aggiornato: nessuna modifica necessaria.';
        // Cancella lo stato del modale dalla sessione
        unset($_SESSION['setupDbGeneratedCode']);
        unset($_SESSION['setupDbOpenModal']);
    } catch (Throwable $e) {
        $_SESSION['error_msg'] = 'Setup DB: ' . $e->getMessage();
    }
    refreshTab();
}

if (isset($_POST['setupdb_generate_from_saved'])) {
    try {
        if (empty($setupDbConfig)) {
            throw new RuntimeException('Parametri Setup DB non configurati.');
        }
        $dbPass = setupDbDecryptSecret((string)($setupDbConfig['db_pass_cifrata'] ?? ''));
        $code = setupDbGenerateDbPhp(
            (string)$setupDbConfig['db_host'],
            (string)$setupDbConfig['db_name'],
            (string)$setupDbConfig['db_user'],
            $dbPass
        );
        projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome_attivo, true);
        projectSchemaGenerate($db, (int)$progetto_id, (string)$progetto_nome_attivo, true);
        $result = setupDbWriteDbPhp($progetto_nome_attivo, $code);
        $db->execute(
            "UPDATE progetti_setup_db SET ultima_generazione = NOW(), ultimo_messaggio = ? WHERE IDprogetto = ?",
            ['db.php generato in ' . basename(dirname($result['path'])) . '.', $progetto_id]
        );
        $_SESSION['success_msg'] = $result['changed']
            ? 'File db.php generato dai parametri salvati.'
            : 'File db.php già aggiornato: nessuna modifica necessaria.';
    } catch (Throwable $e) {
        $_SESSION['error_msg'] = 'Setup DB: ' . $e->getMessage();
    }
    refreshTab();
}


if (isset($_POST['schema_generate_now'])) {
    try {
        $msg = projectSchemaGenerateAndMessage($db, (int)$progetto_id, (string)$progetto_nome_attivo);
        $_SESSION['success_msg'] = trim($msg);
    } catch (Throwable $e) {
        $_SESSION['error_msg'] = 'Schema DB: ' . $e->getMessage();
    }
    refreshTab();
}

// --- AZIONI ---

// 1. ELIMINAZIONE (Corretta per funzionare con JS)
if (isset($_POST['delete_tabella'])) {
    error_log("Tentativo di eliminazione tabella. POST data: " . print_r($_POST, true)); // Log entry point and POST data
    $id = $_POST['id'];
    try {
        $db->beginTransaction();
        // Eliminiamo prima i campi associati (Vincolo di integrità logica)
        $db->execute("DELETE FROM campi WHERE IDtabella = ?", [$id]);
        // Poi la tabella
        $db->execute("DELETE FROM tabelle WHERE id = ?", [$id]);
        
        $db->commit();
        $schemaMsg = projectSchemaGenerateAndMessage($db, (int)$progetto_id, (string)$progetto_nome_attivo);
        $_SESSION['success_msg'] = "Tabella eliminata con successo." . $schemaMsg;
        refreshTab();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Errore eliminazione tabella: " . $e->getMessage());
        $_SESSION['error_msg'] = "Errore durante l'eliminazione: " . $e->getMessage();
        refreshTab();
    }
}

// 2. AGGIUNTA
if (isset($_POST['add_tabella'])) {
    $nome = strtolower(trim($_POST['nome']));
    $descrizione = trim($_POST['descrizione']);

    if (!empty($nome)) {
        try {
            $db->beginTransaction();
            $db->execute(
                "INSERT INTO tabelle (IDprogetto, nome, descrizione, ordine) VALUES (?, ?, ?, ?)", 
                [$progetto_id, $nome, $descrizione, 0]
            );
            $new_tabella_id = $db->lastInsertId();

            // Creazione automatica del campo ID primario
            $nome_campo_id = "id_" . $nome;
            $db->execute(
                "INSERT INTO campi (IDtabella, nome, tipo, auto_increment, ordine, nullable) 
                 VALUES (?, ?, 'int', 1, 1, 0)",
                [$new_tabella_id, $nome_campo_id]
            );

            $db->commit();
            $schemaMsg = projectSchemaGenerateAndMessage($db, (int)$progetto_id, (string)$progetto_nome_attivo);
            $_SESSION['success_msg'] = "Tabella '$nome' creata correttamente!" . $schemaMsg;
            refreshTab();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['error_msg'] = "Impossibile creare: il nome '$nome' è già in uso.";
            } else {
                $_SESSION['error_msg'] = "Errore tecnico: " . $e->getMessage();
            }
        }
    }
}

// 3. MODIFICA
if (isset($_POST['update_tabella'])) {
    $id = $_POST['id'];
    $nome = strtolower(trim($_POST['nome']));
    $descrizione = trim($_POST['descrizione']);

    if (!empty($nome)) {
        try {
            $db->beginTransaction();
            $db->execute("UPDATE tabelle SET nome = ?, descrizione = ? WHERE id = ?", [$nome, $descrizione, $id]);
            syncProjectIntegrity($db, $progetto_id); 

            $db->commit();
            $schemaMsg = projectSchemaGenerateAndMessage($db, (int)$progetto_id, (string)$progetto_nome_attivo);
            $_SESSION['success_msg'] = "Tabella aggiornata correttamente." . $schemaMsg;
            refreshTab(); 
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['error_msg'] = "Impossibile rinominare: il nome '$nome' esiste già.";
            } else {
                $_SESSION['error_msg'] = "Errore aggiornamento: " . $e->getMessage();
            }
        }
    }
}

// LETTURA DATI
$tabelle = $db->fetchAll("SELECT * FROM tabelle WHERE IDprogetto = ? ORDER BY nome ASC", [$progetto_id]);
?>

<style>
    .fab {
        position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 1050;
        transition: all 0.3s ease;
    }
    .fab:hover { transform: scale(1.1); box-shadow: 0 6px 16px rgba(0,0,0,0.4); }
    .table-row-tabella { cursor: pointer; }
    .table-row-tabella:focus { outline: 2px solid #0d6efd; outline-offset: -2px; }
    .edit-tabella-icon { font-size: 0.875rem; line-height: 1; }
    .code-preview { background: #1e1e1e; color: #dcdcdc; padding: 20px; border-radius: 8px; font-family: 'Consolas', monospace; font-size: 0.82rem; height: 450px; overflow-y: auto; border: 1px solid #333; white-space: pre; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3><i class="bi bi-table me-2 text-primary"></i> Tabelle del Database</h3>
        <p class="text-muted mb-0">Progetto attivo: <strong><?= htmlspecialchars($_SESSION['progetto_nome'] ?? 'N/D') ?></strong></p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
        <button type="button" class="btn btn-outline-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#setupDbModal">
            <i class="bi bi-database-gear me-1"></i>Crea e salva DB.php
        </button>

        <form method="POST" class="d-inline">
            <button type="submit" name="schema_generate_now" class="btn btn-outline-success shadow-sm">
                <i class="bi bi-filetype-sql me-1"></i>Aggiorna schema.sql
            </button>
        </form>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php?page=progetti" class="text-decoration-none">Progetti</a></li>
                <li class="breadcrumb-item active">Tabelle</li>
            </ol>
        </nav>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white py-3">
        <h6 class="mb-0"><i class="bi bi-hdd-stack me-2"></i>Struttura Database Corrente</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nome Tabella</th>
                    <th>Descrizione</th>
                    <th class="text-center">Campi</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tabelle)): ?>
                    <tr><td colspan="4" class="text-center p-5 text-muted">Nessuna tabella presente. Clicca il tasto + per iniziare.</td></tr>
                <?php else: ?>
                    <?php foreach($tabelle as $t): 
                        $num_campi = $db->fetchColumn("SELECT COUNT(*) FROM campi WHERE IDtabella = ?", [$t['id']]);
                        // Assuming foreign keys are also stored in 'campi' table and marked somehow, or need a separate query.
                        // For now, let's just count fields. If 'chiavi' refers to actual foreign key constraints, we'd need more logic.
                        // Let's assume 'chiavi' refers to fields that are primary/foreign keys.
                        // For a more precise count of foreign keys, we'd need to query a table that stores FK relationships.
                        // For simplicity, let's just count fields for now and refine if needed.
                        $num_chiavi = $db->fetchColumn("SELECT COUNT(*) FROM campi WHERE IDtabella = ? AND (auto_increment = 1 OR tipo = 'fk')", [$t['id']]);
                    ?>
                    <tr class="table-row-tabella" 
                        data-href="index.php?page=campi&tabella_id=<?= $t['id'] ?>"
                        tabindex="0"
                        role="link"
                        aria-label="Gestisci i campi della tabella <?= htmlspecialchars($t['nome'], ENT_QUOTES, 'UTF-8') ?>"
                        title="Clicca per gestire i campi">
                        <td>
                            <span class="fw-bold text-decoration-none text-dark">
                                <i class="bi bi-columns-gap me-2"></i><?= htmlspecialchars($t['nome']) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($t['descrizione'] ?: '-') ?></td>
                        <td class="text-center">
                            <a href="index.php?page=campi&tabella_id=<?= $t['id'] ?>"
                               class="btn btn-sm btn-outline-primary"
                               title="Gestisci campi"
                               onclick="event.stopPropagation();">
                                <i class="bi bi-list-columns-reverse me-1"></i><?= (int)$num_campi ?>
                            </a>
                        </td>
                        <td class="text-end">
                            <div class="btn-group shadow-sm">
                                <button class="btn btn-sm btn-outline-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editTabellaModal" 
                                        data-id="<?= $t['id'] ?>" 
                                        data-nome="<?= htmlspecialchars($t['nome'], ENT_QUOTES, 'UTF-8') ?>" 
                                        data-descrizione="<?= htmlspecialchars($t['descrizione'], ENT_QUOTES, 'UTF-8') ?>"
                                        title="Modifica"
                                        onclick="event.stopPropagation();">
                                    <i class="bi bi-pencil edit-tabella-icon"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info"
                                        data-bs-toggle="modal"
                                        data-bs-target="#viewConnectionsModal"
                                        data-id="<?= $t['id'] ?>"
                                        data-nome-tabella="<?= htmlspecialchars($t['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                        title="Visualizza Collegamenti"
                                            onclick="event.stopPropagation();">
                                    <i class="bi bi-diagram-3"></i>
                                    </button>

                                <!-- FORM DI ELIMINAZIONE CORRETTO -->
                                <form method="POST" class="d-inline delete-tabella-form">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <input type="hidden" name="delete_tabella" value="1"> <!-- Indica l'azione a PHP -->
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"
                                            data-num-campi="<?= $num_campi ?>"
                                            data-num-chiavi="<?= $num_chiavi ?>"
                                            onclick="event.stopPropagation();">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                                <?php endif; ?>
            </tbody>
        </table>
                            </div>
                    </div>


<!-- Modal SETUP DB integrato -->
<div class="modal fade" id="setupDbModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-database-gear me-2"></i>Setup DB progetto</h5>
                    <div class="small opacity-75">Parametri salvati una sola volta per il progetto corrente</div>
                    </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    Il file verrà salvato in:
                    <code>pages/sito/<?= htmlspecialchars($setupDbFolderName) ?>/db.php</code>.
                    Dopo questa integrazione il vecchio <code>setup_db.php</code> può essere eliminato dal menu.
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <form method="POST" class="p-3 bg-light border rounded shadow-sm mb-3">
                            <h6 class="fw-bold mb-3 border-bottom pb-2">Parametri connessione</h6>
                            <div class="mb-2">
                                <label class="small fw-bold">Host</label>
                                <input type="text" name="setupdb_host" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars((string)($setupDbConfig['db_host'] ?? 'localhost')) ?>" required>
        </div>
                            <div class="mb-2">
                                <label class="small fw-bold">Nome database</label>
                                <input type="text" name="setupdb_name" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars((string)($setupDbConfig['db_name'] ?? '')) ?>" required>
    </div>
                            <div class="mb-2">
                                <label class="small fw-bold">User</label>
                                <input type="text" name="setupdb_user" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars((string)($setupDbConfig['db_user'] ?? '')) ?>" required>
</div>
                    <div class="mb-3">
                                <label class="small fw-bold">Password</label>
                                <input type="password" name="setupdb_pass" class="form-control form-control-sm"
                                       placeholder="<?= !empty($setupDbConfig['db_pass_cifrata']) ? 'Lascia vuoto per mantenere quella salvata' : '' ?>">
                    </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="setupdb_save_params" class="btn btn-success btn-sm">
                                    <i class="bi bi-floppy me-1"></i>Salva parametri
                                </button>
                                <button type="submit" name="setupdb_preview" class="btn btn-dark btn-sm">
                                    <i class="bi bi-eye me-1"></i>Genera anteprima
                                </button>
                                <button type="submit" name="setupdb_delete_params" class="btn btn-outline-danger btn-sm"
                                        onclick="return confirm('Eliminare i parametri Setup DB salvati per questo progetto?');"
                                        <?= empty($setupDbConfig) ? 'disabled' : '' ?>>
                                    <i class="bi bi-trash me-1"></i>Cancella parametri
                                </button>
                    </div>
                        </form>

                        <?php if (!empty($setupDbConfig)): ?>
                            <div class="alert alert-light border small mb-0">
                                <div><strong>Configurazione salvata</strong></div>
                                <div>DB: <code><?= htmlspecialchars((string)$setupDbConfig['db_name']) ?></code></div>
                                <div>User: <code><?= htmlspecialchars((string)$setupDbConfig['db_user']) ?></code></div>
                                <?php if (!empty($setupDbConfig['ultima_generazione'])): ?>
                                    <div>Ultima generazione: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$setupDbConfig['ultima_generazione']))) ?></div>
        <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-8">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-muted small mb-0">Anteprima <code>db.php</code></label>
                            <span class="badge bg-secondary">Classe Database</span>
                        </div>
                        <div class="code-preview mb-3">
                            <?php if ($setupDbGeneratedCode): ?>
                                <pre><?= htmlspecialchars($setupDbGeneratedCode) ?></pre>
                            <?php else: ?>
                                <div class="h-100 d-flex align-items-center justify-content-center text-muted">
                                    Clicca su “Genera anteprima” per comporre il file.
                                </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="setupdb_code_content" value="<?= htmlspecialchars($setupDbGeneratedCode) ?>">
                            <button type="submit" name="setupdb_save_file" class="btn btn-success w-100 py-3 fw-bold shadow" <?= empty($setupDbGeneratedCode) ? 'disabled' : '' ?>>
                                <i class="bi bi-save2 me-1"></i>Salva db.php nella cartella progetto
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<!-- FAB -->
<button class="btn btn-success fab text-white border-0 shadow" data-bs-toggle="modal" data-bs-target="#addTabellaModal" title="Nuova Tabella">
    <i class="bi bi-plus-lg"></i>
</button>

<!-- Modal NUOVA -->
<div class="modal fade" id="addTabellaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">Crea Nuova Tabella</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome Tabella</label>
                        <input type="text" name="nome" class="form-control" placeholder="es. utenti" required pattern="[a-z0-9_]+">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descrizione</label>
                        <textarea name="descrizione" class="form-control" rows="3" placeholder="Opzionale..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="add_tabella" class="btn btn-success">Crea Tabella</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal MODIFICA -->
<div class="modal fade" id="editTabellaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">Modifica Tabella</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_tab_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome Tabella</label>
                        <input type="text" class="form-control" id="edit_tab_nome" name="nome" required pattern="[a-z0-9_]+">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descrizione</label>
                        <textarea class="form-control" id="edit_tab_desc" name="descrizione" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                    <button type="submit" name="update_tabella" class="btn btn-primary">Salva Modifiche</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($setupDbOpenModal): ?>
            var setupDbModal = new bootstrap.Modal(document.getElementById('setupDbModal'));
            setupDbModal.show();
        <?php endif; ?>

        // Aggiungi un listener per la chiusura del modale tramite il pulsante "Chiudi" nel footer
        var closeModalButton = document.querySelector('#setupDbModal .modal-footer button[data-bs-dismiss="modal"]');
        if (closeModalButton) {
            closeModalButton.addEventListener('click', function() {
                // Invia una richiesta POST per cancellare lo stato del modale dalla sessione
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = ''; // Invia alla stessa pagina
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'clear_setupdb_session'; // Un flag per PHP
                input.value = '1';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            });
        }

        // Aggiungi un listener per la chiusura del modale tramite il pulsante "X" nell'header
        var closeHeaderButton = document.querySelector('#setupDbModal .modal-header button[data-bs-dismiss="modal"]');
        if (closeHeaderButton) {
            closeHeaderButton.addEventListener('click', function() {
                // Invia una richiesta POST per cancellare lo stato del modale dalla sessione
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = ''; // Invia alla stessa pagina
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'clear_setupdb_session'; // Un flag per PHP
                input.value = '1';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            });
        }
    });
</script>


