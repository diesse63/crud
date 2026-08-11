<?php

/**

 * CRUD Deploy HTTPS Receiver

 * Versione: 1.3.1 (compatibile PHP 8.0)
 * Aggiornato: 2026-08-02
 *
 * Azioni supportate: ping, inspect, associate, disassociate, deploy.
 * Protocollo deploy: protocol_version=1, dry_run opzionale.
 */

declare(strict_types=1);



header('Content-Type: application/json; charset=utf-8');

header('X-Content-Type-Options: nosniff');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');





final class ApiException extends RuntimeException

{

    public function __construct(

        public int $httpStatus,

        public string $apiStatus,

        string $message,

        public array $data = []

    ) {

        parent::__construct($message);

    }

}



function respond(int $httpStatus, bool $success, string $status, string $message, array $data = []): void

{

    http_response_code($httpStatus);

    echo json_encode([

        'success' => $success,

        'status' => $status,

        'message' => $message,

        'data' => $data,

        'time' => date(DATE_ATOM),

    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;

}



function getHeaderValue(string $name): string

{

    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return trim((string)($_SERVER[$key] ?? ''));

}



function ensureDirectory(string $path): void

{

    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {

        throw new RuntimeException('Impossibile creare la cartella: ' . $path);

    }

}



function deleteTree(string $path): void

{

    if (is_file($path) || is_link($path)) {

        if (!@unlink($path)) {

            throw new RuntimeException('Impossibile eliminare: ' . $path);

        }

        return;

    }

    if (!is_dir($path)) {

        return;

    }

    $items = scandir($path);

    if ($items === false) {

        throw new RuntimeException('Impossibile leggere la cartella: ' . $path);

    }

    foreach ($items as $item) {

        if ($item === '.' || $item === '..') {

            continue;

        }

        deleteTree($path . DIRECTORY_SEPARATOR . $item);

    }

    if (!@rmdir($path)) {

        throw new RuntimeException('Impossibile eliminare la cartella: ' . $path);

    }

}



function normalizeDeployPath(string $path): string

{

    $path = trim(str_replace('\\', '/', $path));



    // Convenzione:

    // "." indica la root/base_dir configurata nel receiver.

    // Accettiamo anche campo vuoto o "/" come alias della root.

    if ($path === '' || $path === '.' || $path === '/') {

        return '.';

    }



    $path = trim($path, '/');

    $parts = explode('/', $path);

    foreach ($parts as $part) {

        if ($part === '' || $part === '.' || $part === '..' || !preg_match('/^[a-zA-Z0-9._-]+$/', $part)) {

            throw new ApiException(400, 'invalid_deploy_path', 'Percorso destinazione non valido.');

        }

    }

    return implode('/', $parts);

}



function isAllowedPath(string $path, array $allowedPaths): bool

{

    if ($allowedPaths === []) {

        return true;

    }

    foreach ($allowedPaths as $allowed) {

        $allowed = trim(str_replace('\\', '/', (string)$allowed));

        if ($allowed === '' || $allowed === '.' || $allowed === '/') {

            if ($path === '.') {

                return true;

            }

            continue;

        }



        $allowed = trim($allowed, '/');

        if ($path === $allowed || str_starts_with($path, $allowed . '/')) {

            return true;

        }

    }

    return false;

}



function validateUuid(string $uuid): bool

{

    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;

}



function readDeployMetadata(string $targetDir): ?array

{

    $file = $targetDir . DIRECTORY_SEPARATOR . '.deploy.json';

    if (!is_file($file)) {

        return null;

    }

    $raw = file_get_contents($file);

    if ($raw === false) {

        throw new RuntimeException('Impossibile leggere .deploy.json.');

    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {

        throw new RuntimeException('File .deploy.json non valido.');

    }

    return $data;

}



function writeDeployMetadata(string $targetDir, array $metadata): void
{
    ensureDirectory($targetDir);
    $file = $targetDir . DIRECTORY_SEPARATOR . '.deploy.json';
    $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {

        throw new RuntimeException('Impossibile scrivere .deploy.json.');

    }
}

function deleteDeployMetadata(string $targetDir): void
{
    $file = $targetDir . DIRECTORY_SEPARATOR . '.deploy.json';
    if (!is_file($file)) {
        return;
    }
    if (!@unlink($file)) {
        throw new RuntimeException('Impossibile eliminare .deploy.json.');
    }
}

function validateZipEntries(ZipArchive $zip): void
{
    for ($i = 0; $i < $zip->numFiles; $i++) {

        $name = $zip->getNameIndex($i);

        if ($name === false) {

            throw new RuntimeException('Voce ZIP non valida.');

        }

        $normalized = str_replace('\\', '/', $name);

        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized)) {

            throw new RuntimeException('Archivio non sicuro: percorso non consentito.');

        }

        if (preg_match('#^[a-zA-Z]:/#', $normalized)) {

            throw new RuntimeException('Archivio non sicuro: percorso assoluto.');

        }

    }

}



function shouldSkipRelative(string $relative, array $protectedNames, array $persistentPaths): bool

{

    $relative = trim(str_replace('\\', '/', $relative), '/');

    if ($relative === '') {

        return false;

    }



    $first = explode('/', $relative)[0];

    if (in_array($first, $protectedNames, true) || in_array(basename($relative), $protectedNames, true)) {

        return true;

    }



    foreach ($persistentPaths as $persistent) {

        $persistent = trim(str_replace('\\', '/', (string)$persistent), '/');

        if ($relative === $persistent || str_starts_with($relative, $persistent . '/')) {

            return true;

        }

    }

    return false;

}



function copyTree(string $source, string $target, array $protectedNames, array $persistentPaths): array

{

    $stats = ['files_created' => 0, 'files_updated' => 0, 'directories_created' => 0];

    ensureDirectory($target);



    $iterator = new RecursiveIteratorIterator(

        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),

        RecursiveIteratorIterator::SELF_FIRST

    );



    foreach ($iterator as $item) {

        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));

        if (shouldSkipRelative($relative, $protectedNames, $persistentPaths)) {

            continue;

        }

        $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);



        if ($item->isDir()) {

            if (!is_dir($destination)) {

                ensureDirectory($destination);

                $stats['directories_created']++;

            }

            continue;

        }



        ensureDirectory(dirname($destination));

        $exists = is_file($destination);

        if (!copy($item->getPathname(), $destination)) {

            throw new RuntimeException('Impossibile copiare: ' . $relative);

        }

        $stats[$exists ? 'files_updated' : 'files_created']++;

    }



    return $stats;

}



function collectRelativeFiles(string $root, array $protectedNames, array $persistentPaths): array

{

    if (!is_dir($root)) {

        return [];

    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(

        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),

        RecursiveIteratorIterator::LEAVES_ONLY

    );

    foreach ($iterator as $item) {

        if (!$item->isFile()) {

            continue;

        }

        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));

        if (!shouldSkipRelative($relative, $protectedNames, $persistentPaths)) {

            $files[$relative] = true;

        }

    }

    return $files;

}



function removeMissingFiles(string $staging, string $target, array $protectedNames, array $persistentPaths): int

{

    $sourceFiles = collectRelativeFiles($staging, $protectedNames, $persistentPaths);

    $targetFiles = collectRelativeFiles($target, $protectedNames, $persistentPaths);

    $removed = 0;

    foreach ($targetFiles as $relative => $_) {

        if (!isset($sourceFiles[$relative])) {

            $path = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (is_file($path) && @unlink($path)) {

                $removed++;

            }

        }

    }

    return $removed;

}



function createBackup(string $source, string $backupDir, string $deployPath): ?string

{

    if (!is_dir($source)) {

        return null;

    }

    if (!extension_loaded('zip')) {

        throw new RuntimeException('Estensione ZIP non disponibile per il backup.');

    }

    ensureDirectory($backupDir);

    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $deployPath) ?: 'deploy';

    $backupFile = $backupDir . DIRECTORY_SEPARATOR . $safe . '_' . date('Ymd_His') . '.zip';



    $zip = new ZipArchive();

    if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {

        throw new RuntimeException('Impossibile creare il backup.');

    }



    $root = realpath($source);

    if ($root !== false) {

        $iterator = new RecursiveIteratorIterator(

            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),

            RecursiveIteratorIterator::LEAVES_ONLY

        );

        foreach ($iterator as $item) {

            if ($item->isFile()) {

                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));

                $zip->addFile($item->getPathname(), $relative);

            }

        }

    }

    $zip->close();

    return $backupFile;

}



function pruneBackups(string $backupDir, int $keep): void

{

    if (!is_dir($backupDir)) {

        return;

    }

    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.zip') ?: [];

    usort($files, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

    foreach (array_slice($files, max(1, $keep)) as $file) {

        @unlink($file);

    }

}



function appendLog(string $logFile, array $entry): void

{

    ensureDirectory(dirname($logFile));

    @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

}



function postString(string $key, ?string $default = null): ?string

{

    if (!array_key_exists($key, $_POST)) {

        return $default;

    }

    return trim((string)$_POST[$key]);

}



function countStagingFiles(string $root, array $protectedNames, array $persistentPaths): array

{

    $files = collectRelativeFiles($root, $protectedNames, $persistentPaths);

    return [

        'files_in_archive' => count($files),

        'directories_in_archive' => is_dir($root) ? iterator_count(new RecursiveIteratorIterator(

            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),

            RecursiveIteratorIterator::SELF_FIRST

        )) - count($files) : 0,

    ];

}



$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'deploy_receiver_config.php';

if (!is_file($configFile)) {

    respond(500, false, 'config_missing', 'File deploy_receiver_config.php mancante.');

}

$config = require $configFile;

if (!is_array($config)) {

    respond(500, false, 'config_invalid', 'Configurazione non valida.');

}



$token = (string)($config['token'] ?? '');

$baseDir = (string)($config['base_dir'] ?? __DIR__);

$workDir = (string)($config['work_dir'] ?? (__DIR__ . DIRECTORY_SEPARATOR . '_deploy'));

$allowedPaths = array_values(array_filter((array)($config['allowed_paths'] ?? []), 'is_string'));

$persistentPaths = array_values(array_filter((array)($config['persistent_paths'] ?? []), 'is_string'));

$protectedNames = array_values(array_filter((array)($config['protected_names'] ?? []), 'is_string'));

$receiverFolderName = basename(__DIR__);

$protectedNames = array_values(array_unique(array_merge($protectedNames, [
    $receiverFolderName,
    'receiver',
    'deploy_receiver.php',
    'deploy_receiver_config.php',
    'db_schema_receiver.php',
    '.htaccess',
    '.deploy.json',
    '_deploy',
    '.ftpquota',
    '.gitignore',
    '.vscode',
])));

$persistentPaths = array_values(array_unique(array_merge(
    $persistentPaths,
    [$receiverFolderName, 'receiver']
)));

$allowRootDeleteMissing = !empty($config['allow_root_delete_missing']);

$maxUploadBytes = max(1, (int)($config['max_upload_bytes'] ?? 50 * 1024 * 1024));

$backupKeepDefault = max(1, (int)($config['backup_keep'] ?? 5));



try {

    if (strlen($token) < 32) {

        throw new RuntimeException('Token non configurato o troppo corto.');

    }

    ensureDirectory($baseDir);

    ensureDirectory($workDir);



    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

        respond(405, false, 'method_not_allowed', 'Usare una richiesta POST.');

    }



    $receivedToken = getHeaderValue('X-Deploy-Token');

    if ($receivedToken === '' || !hash_equals($token, $receivedToken)) {

        respond(401, false, 'unauthorized', 'Token non valido.');

    }



    $action = strtolower(trim((string)($_POST['action'] ?? '')));



    if ($action === 'ping') {

        respond(200, true, 'ready', 'Ricevitore disponibile.', [

            'receiver_version' => '1.3.0',
            'php_version' => PHP_VERSION,

            'zip_available' => extension_loaded('zip'),

            'max_upload_bytes' => $maxUploadBytes,

            'allow_root_delete_missing' => $allowRootDeleteMissing,

        ]);

    }



    if (!in_array($action, ['inspect', 'associate', 'disassociate', 'deploy'], true)) {
        respond(400, false, 'invalid_action', 'Azione non valida.');
    }


    $deployPath = normalizeDeployPath((string)($_POST['deploy_path'] ?? ''));

    if (!isAllowedPath($deployPath, $allowedPaths)) {

        respond(403, false, 'path_not_allowed', 'Cartella destinazione non autorizzata.', ['deploy_path' => $deployPath]);

    }



    $projectUuid = strtolower(trim((string)($_POST['project_uuid'] ?? '')));

    if (!validateUuid($projectUuid)) {

        respond(400, false, 'invalid_project_uuid', 'UUID progetto non valido.');

    }



    $projectName = trim((string)($_POST['project_name'] ?? ''));

    if ($action === 'associate' && $projectName === '') {

        respond(400, false, 'invalid_project_name', 'Nome progetto non specificato.');

    }



    $targetDir = $deployPath === '.'

        ? rtrim($baseDir, DIRECTORY_SEPARATOR)

        : rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $deployPath);

    $metadata = readDeployMetadata($targetDir);



    if ($action === 'inspect') {

        if ($metadata === null) {

            respond(200, true, 'unassigned', 'La cartella non è associata ad alcun progetto.', [

                'deploy_path' => $deployPath,

                'directory_exists' => is_dir($targetDir),

                'manifest_exists' => false,

            ]);

        }



        $remoteUuid = strtolower((string)($metadata['project_uuid'] ?? ''));

        if (!validateUuid($remoteUuid) || !hash_equals($remoteUuid, $projectUuid)) {

            respond(409, false, 'project_mismatch', 'La cartella è associata a un altro progetto.', [

                'deploy_path' => $deployPath,

                'remote_project_uuid' => $remoteUuid,

                'remote_project_name' => (string)($metadata['project_name'] ?? ''),

            ]);

        }



        respond(200, true, 'assigned', 'La cartella è associata al progetto.', [

            'project_uuid' => $remoteUuid,

            'project_name' => (string)($metadata['project_name'] ?? ''),

            'deploy_path' => $deployPath,

            'application_url' => (string)($metadata['application_url'] ?? ''),

            'generator_version' => $metadata['generator_version'] ?? null,

            'associated_at' => $metadata['associated_at'] ?? ($metadata['created_at'] ?? null),

            'last_publish' => $metadata['last_publish'] ?? null,

            'last_archive_sha256' => $metadata['last_archive_sha256'] ?? null,

            'manifest_exists' => true,

        ]);

    }



    if ($action === 'associate') {
        if ($metadata !== null) {
            $remoteUuid = strtolower((string)($metadata['project_uuid'] ?? ''));
            if (!validateUuid($remoteUuid) || !hash_equals($remoteUuid, $projectUuid)) {
                respond(409, false, 'project_mismatch', 'La cartella è già associata a un altro progetto.', [
                    'remote_project_uuid' => $remoteUuid,

                    'remote_project_name' => (string)($metadata['project_name'] ?? ''),

                ]);

            }

            respond(200, true, 'associated', 'La cartella era già associata al progetto.', ['metadata' => $metadata]);

        }



        $now = date(DATE_ATOM);

        $metadata = [

            'schema_version' => 1,

            'project_uuid' => $projectUuid,

            'project_name' => $projectName,

            'deploy_path' => $deployPath,

            'application_url' => trim((string)($_POST['application_url'] ?? '')),

            'generator_version' => (($value = trim((string)($_POST['generator_version'] ?? ''))) !== '') ? $value : null,

            'associated_at' => $now,

            'last_publish' => null,

        ];

        writeDeployMetadata($targetDir, $metadata);
        respond(200, true, 'associated', 'Cartella associata al progetto.', ['metadata' => $metadata]);
    }

    if ($action === 'disassociate') {
        if ($metadata === null) {
            respond(200, true, 'unassigned', 'La cartella non era associata ad alcun progetto.', [
                'deploy_path' => $deployPath,
                'directory_exists' => is_dir($targetDir),
                'manifest_exists' => false,
            ]);
        }

        $remoteUuid = strtolower((string)($metadata['project_uuid'] ?? ''));
        $mismatch = !validateUuid($remoteUuid) || !hash_equals($remoteUuid, $projectUuid);
        if ($mismatch) {
            // La disassociazione serve proprio a riallineare la cartella quando
            // il manifest remoto è rimasto agganciato a un UUID precedente.
            // In questo caso eliminiamo comunque il manifest, ma segnaliamo il dato
            // precedente nella risposta per trasparenza.
            deleteDeployMetadata($targetDir);
            respond(200, true, 'disassociated', 'Cartella disassociata dal progetto precedente.', [
                'deploy_path' => $deployPath,
                'project_uuid' => $projectUuid,
                'remote_project_uuid' => $remoteUuid,
                'remote_project_name' => (string)($metadata['project_name'] ?? ''),
                'metadata_removed' => true,
            ]);
        }

        deleteDeployMetadata($targetDir);
        respond(200, true, 'disassociated', 'Cartella disassociata dal progetto.', [
            'deploy_path' => $deployPath,
            'project_uuid' => $projectUuid,
            'metadata_removed' => true,
        ]);
    }

    if ($metadata === null) {
        respond(409, false, 'unassigned', 'La cartella deve essere associata prima della pubblicazione.');
    }
    $remoteUuid = strtolower((string)($metadata['project_uuid'] ?? ''));

    if (!hash_equals($remoteUuid, $projectUuid)) {

        respond(409, false, 'project_mismatch', 'UUID remoto differente da quello del progetto.');

    }



    $protocolVersion = (int)($_POST['protocol_version'] ?? 0);

    if ($protocolVersion !== 1) {

        respond(400, false, 'invalid_protocol_version', 'Versione protocollo mancante o non supportata.', [

            'expected_protocol_version' => 1,

            'received_protocol_version' => $protocolVersion,

        ]);

    }



    $dryRun = filter_var($_POST['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    $dryRun = $dryRun ?? false;



    if (!extension_loaded('zip')) {

        respond(500, false, 'zip_unavailable', 'Estensione ZIP non disponibile sul server destinatario.');

    }

    if (!isset($_FILES['archive']) || !is_array($_FILES['archive'])) {

        respond(400, false, 'archive_missing', 'Archivio non ricevuto.');

    }

    $upload = $_FILES['archive'];

    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {

        respond(400, false, 'archive_missing', 'Errore durante il caricamento dello ZIP.', ['upload_error' => (int)($upload['error'] ?? -1)]);

    }

    $size = (int)($upload['size'] ?? 0);

    if ($size <= 0 || $size > $maxUploadBytes) {

        respond(413, false, 'archive_too_large', 'Dimensione archivio non consentita.', ['size' => $size]);

    }



    $expectedHash = strtolower(trim((string)($_POST['sha256'] ?? '')));

    if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {

        respond(400, false, 'hash_missing', 'Hash SHA-256 mancante o non valido.');

    }

    $tmpUpload = (string)($upload['tmp_name'] ?? '');

    $actualHash = hash_file('sha256', $tmpUpload);

    if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {

        respond(400, false, 'hash_mismatch', 'Verifica SHA-256 non superata.');

    }



    $jobId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    $stagingDir = $workDir . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $jobId;

    $backupDir = $workDir . DIRECTORY_SEPARATOR . 'backup';

    $logFile = $workDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'deploy.log';

    ensureDirectory($stagingDir);



    $zip = new ZipArchive();

    if ($zip->open($tmpUpload) !== true) {

        throw new RuntimeException('Impossibile aprire lo ZIP ricevuto.');

    }

    validateZipEntries($zip);

    if (!$zip->extractTo($stagingDir)) {

        $zip->close();

        throw new RuntimeException('Impossibile estrarre lo ZIP.');

    }

    $zip->close();



    $backupEnabled = filter_var($_POST['create_backup'] ?? ($_POST['backup_enabled'] ?? true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    $backupEnabled = $backupEnabled ?? true;

    $backupKeep = max(1, min(50, (int)($_POST['backup_keep'] ?? $backupKeepDefault)));

    $deleteMissing = filter_var($_POST['delete_missing'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    $deleteMissing = $deleteMissing ?? false;



    if ($deployPath === '.' && $deleteMissing && !$allowRootDeleteMissing) {

        deleteTree($stagingDir);

        respond(400, false, 'root_delete_missing_not_allowed', 'delete_missing in root richiede allow_root_delete_missing=true in deploy_receiver_config.php.');

    }



    if ($dryRun) {

        $stats = countStagingFiles($stagingDir, $protectedNames, $persistentPaths);

        deleteTree($stagingDir);



        appendLog($logFile, [

            'time' => date(DATE_ATOM),

            'status' => 'dry_run_ok',

            'project_uuid' => $projectUuid,

            'project_name' => $projectName,

            'deploy_path' => $deployPath,

            'archive_sha256' => $expectedHash,

            'stats' => $stats,

            'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),

        ]);



        respond(200, true, 'dry_run_ok', 'Simulazione completata: il deploy può essere eseguito.', [

            'deploy_path' => $deployPath,

            'project_uuid' => $projectUuid,

            'sha256' => $expectedHash,

            'stats' => $stats,

            'metadata_unchanged' => true,

        ]);

    }



    $backupFile = null;

    if ($backupEnabled) {

        $backupFile = createBackup($targetDir, $backupDir, $deployPath);

        pruneBackups($backupDir, $backupKeep);

    }



    $stats = copyTree($stagingDir, $targetDir, $protectedNames, $persistentPaths);

    $stats['files_deleted'] = $deleteMissing

        ? removeMissingFiles($stagingDir, $targetDir, $protectedNames, $persistentPaths)

        : 0;



    if ($projectName !== '') {

        $metadata['project_name'] = $projectName;

    }

    $applicationUrl = postString('application_url', null);

    if ($applicationUrl !== null) {

        $metadata['application_url'] = $applicationUrl;

    }

    $generatorVersion = postString('generator_version', null);

    if ($generatorVersion !== null) {

        $metadata['generator_version'] = ($generatorVersion !== '') ? $generatorVersion : null;

    }

    $metadata['last_publish'] = date(DATE_ATOM);

    $metadata['last_archive_sha256'] = $expectedHash;

    writeDeployMetadata($targetDir, $metadata);



    deleteTree($stagingDir);



    $logEntry = [

        'time' => date(DATE_ATOM),

        'status' => 'published',

        'project_uuid' => $projectUuid,

        'project_name' => $projectName,

        'deploy_path' => $deployPath,

        'stats' => $stats,

        'backup' => $backupFile ? basename($backupFile) : null,

        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),

    ];

    appendLog($logFile, $logEntry);



    respond(200, true, 'published', 'Pubblicazione completata.', [

        'deploy_path' => $deployPath,

        'stats' => $stats,

        'backup_created' => $backupFile !== null,

        'backup_file' => $backupFile ? basename($backupFile) : null,

        'metadata' => $metadata,

    ]);

} catch (ApiException $e) {

    respond($e->httpStatus, false, $e->apiStatus, $e->getMessage(), $e->data);

} catch (Throwable $e) {

    respond(500, false, 'internal_error', 'Errore interno del ricevitore.');

}
