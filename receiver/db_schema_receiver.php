<?php
/**
 * CRUD Generator – PHP MySQL
 * Receiver centralizzato per rilevare la struttura DB destinatario
 * Versione: 1.1.0
 * Aggiornato il: 2026-07-26
 *
 * Posizione prevista:
 * /receiver/db_schema_receiver.php
 *
 * Non modifica il database. Usa la stessa configurazione e lo stesso token
 * di deploy_receiver.php.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function schemaRespond(
    int $httpStatus,
    bool $success,
    string $status,
    string $message,
    array $data = []
): never {
    http_response_code($httpStatus);
    echo json_encode([
        'success' => $success,
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'time' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function schemaHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function schemaNormalizeDeployPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path === '.' || $path === '/') {
        return '.';
    }

    $path = trim($path, '/');
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if (
            $part === ''
            || $part === '.'
            || $part === '..'
            || !preg_match('/^[a-zA-Z0-9._-]+$/', $part)
        ) {
            throw new RuntimeException('Percorso applicazione non valido.');
        }
    }

    return implode('/', $parts);
}

function schemaIsAllowedPath(string $path, array $allowedPaths): bool
{
    if ($allowedPaths === []) {
        return true;
    }

    foreach ($allowedPaths as $allowed) {
        $allowed = trim(str_replace('\\', '/', (string)$allowed));
        if ($allowed === '' || $allowed === '.' || $allowed === '/') {
            if ($path === '.') return true;
            continue;
        }

        $allowed = trim($allowed, '/');
        if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
            return true;
        }
    }

    return false;
}

function schemaValidateUuid(string $uuid): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $uuid
    ) === 1;
}

function schemaReadMetadata(string $targetDir): ?array
{
    $file = $targetDir . DIRECTORY_SEPARATOR . '.deploy.json';
    if (!is_file($file)) return null;

    $raw = file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function schemaQuoteIdentifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function schemaNormalizeCreate(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('/\s+AUTO_INCREMENT=\d+\b/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+ROW_FORMAT=\w+\b/i', '', $sql) ?? $sql;
    return $sql;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        schemaRespond(405, false, 'method_not_allowed', 'Usare una richiesta POST.');
    }

    $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'deploy_receiver_config.php';
    if (!is_file($configFile)) {
        schemaRespond(500, false, 'config_missing', 'Configurazione receiver mancante.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        schemaRespond(500, false, 'config_invalid', 'Configurazione receiver non valida.');
    }

    $expectedToken = (string)($config['token'] ?? '');
    $receivedToken = schemaHeader('X-Deploy-Token');

    if (
        strlen($expectedToken) < 32
        || $receivedToken === ''
        || !hash_equals($expectedToken, $receivedToken)
    ) {
        schemaRespond(401, false, 'unauthorized', 'Token non valido.');
    }

    if (trim((string)($_POST['action'] ?? '')) !== 'export_schema') {
        schemaRespond(400, false, 'invalid_action', 'Azione non valida.');
    }

    $baseDir = rtrim((string)($config['base_dir'] ?? dirname(__DIR__)), DIRECTORY_SEPARATOR);
    $allowedPaths = array_values(array_filter(
        (array)($config['allowed_paths'] ?? []),
        'is_string'
    ));
    $ignoreTables = array_values(array_filter(
        (array)($config['schema_ignore_tables'] ?? ['__crud_schema_sync']),
        'is_string'
    ));

    $deployPath = schemaNormalizeDeployPath((string)($_POST['deploy_path'] ?? '.'));
    if (!schemaIsAllowedPath($deployPath, $allowedPaths)) {
        schemaRespond(403, false, 'path_not_allowed', 'Applicazione non autorizzata.', [
            'deploy_path' => $deployPath,
        ]);
    }

    $targetDir = $deployPath === '.'
        ? $baseDir
        : $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $deployPath);

    $realBase = realpath($baseDir);
    $realTarget = realpath($targetDir);
    if (
        $realBase === false
        || $realTarget === false
        || ($realTarget !== $realBase
            && !str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR))
    ) {
        schemaRespond(404, false, 'application_not_found', 'Cartella applicazione non trovata.');
    }

    $projectUuid = strtolower(trim((string)($_POST['project_uuid'] ?? '')));
    if ($projectUuid !== '') {
        if (!schemaValidateUuid($projectUuid)) {
            schemaRespond(400, false, 'invalid_project_uuid', 'UUID progetto non valido.');
        }

        $metadata = schemaReadMetadata($realTarget);
        $remoteUuid = strtolower((string)($metadata['project_uuid'] ?? ''));
        if (
            $metadata === null
            || !schemaValidateUuid($remoteUuid)
            || !hash_equals($remoteUuid, $projectUuid)
        ) {
            schemaRespond(409, false, 'project_mismatch', 'Applicazione associata a un altro progetto.');
        }
    }

    $dbFile = $realTarget . DIRECTORY_SEPARATOR . 'db.php';
    if (!is_file($dbFile)) {
        schemaRespond(404, false, 'db_file_missing', 'db.php non trovato nell’applicazione.');
    }

    /*
     * Evita l'auto-allineamento nello speciale db.php generato dal CRUD:
     * durante questa richiesta la struttura deve essere soltanto letta.
     */
    if (!defined('CRUD_SCHEMA_INSPECTION_ONLY')) {
        define('CRUD_SCHEMA_INSPECTION_ONLY', true);
    }

    require_once $dbFile;

    if (!isset($db) || !is_object($db) || !method_exists($db, 'pdo')) {
        throw new RuntimeException('db.php non espone il metodo pubblico pdo().');
    }

    /** @var PDO $pdo */
    $pdo = $db->pdo();
    $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

    $tableRows = $pdo
        ->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_NUM);

    $tables = [];
    $schemaSql = "-- Struttura DB destinatario: {$databaseName}\n";
    $schemaSql .= "-- Applicazione: {$deployPath}\n";
    $schemaSql .= "-- Rilevata in tempo reale il: " . date('Y-m-d H:i:s') . "\n";
    $schemaSql .= "-- Generatore: db_schema_receiver.php v1.1.0\n\n";
    $schemaSql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tableRows as $row) {
        $tableName = (string)($row[0] ?? '');
        if ($tableName === '' || in_array($tableName, $ignoreTables, true)) {
            continue;
        }

        $quoted = schemaQuoteIdentifier($tableName);
        $statement = $pdo->query("SHOW CREATE TABLE {$quoted}");
        $createRow = $statement->fetch(PDO::FETCH_ASSOC);
        $createSql = (string)($createRow['Create Table'] ?? '');

        if ($createSql === '') continue;

        $createSql = schemaNormalizeCreate($createSql);
        $tables[$tableName] = $createSql;

        $schemaSql .= "DROP TABLE IF EXISTS {$quoted};\n";
        $schemaSql .= $createSql . ";\n\n";
    }

    ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    $schemaSql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    schemaRespond(200, true, 'schema_exported', 'Struttura DB rilevata correttamente.', [
        'deploy_path' => $deployPath,
        'database' => $databaseName,
        'table_count' => count($tables),
        'generated_at' => date(DATE_ATOM),
        'sha256' => hash('sha256', $schemaSql),
        'schema_sql' => $schemaSql,
        'tables' => $tables,
    ]);
} catch (Throwable $e) {
    error_log('db_schema_receiver.php: ' . $e->getMessage());
    schemaRespond(500, false, 'schema_export_error', 'Impossibile rilevare la struttura DB.');
}
