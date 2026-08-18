<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : CRUD Generator
 * Versione creatore : 1.0
 * Versione pagina   : 1.0
 * Creato il  : 2026-08-18 00:00:00
 * Modificato il: 2026-08-18 00:00:00
 * Progetto   : gestionale
 * Descrizione: Receiver schema DB
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function receiverJson(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function receiverSplitStatements(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", trim($sql));
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $escape = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
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
        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
            continue;
        }
        if ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            continue;
        }
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $statement = trim(substr($buffer, 0, -1));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function receiverLoadDb(): PDO
{
    if (!file_exists(__DIR__ . '/db.php')) {
        throw new RuntimeException('File db.php non trovato.');
    }
    require_once __DIR__ . '/db.php';
    if (!isset($db) || !$db instanceof PDO) {
        throw new RuntimeException('Connessione database non disponibile.');
    }
    return $db;
}

try {
    if (!isset($_SERVER['HTTP_X_DEPLOY_TOKEN']) || trim((string)$_SERVER['HTTP_X_DEPLOY_TOKEN']) === '') {
        receiverJson(false, 'Token deploy mancante.', [], 401);
    }

    $action = (string)($_POST['action'] ?? '');
    $db = receiverLoadDb();

    if ($action === 'export_schema') {
        $schemaPath = __DIR__ . '/schema.sql';
        if (!is_file($schemaPath)) {
            receiverJson(false, 'schema.sql non trovato.', [], 404);
        }
        receiverJson(true, 'Schema esportato correttamente.', [
            'schema_sql' => (string)file_get_contents($schemaPath),
            'database' => (string)($db->query('SELECT DATABASE()')->fetchColumn() ?: ''),
            'generated_at' => date(DATE_ATOM),
        ]);
    }

    if ($action === 'apply_alignment') {
        $alignmentSql = (string)($_POST['alignment_sql'] ?? '');
        if (trim($alignmentSql) === '') {
            receiverJson(false, 'Script di allineamento mancante.', [], 422);
        }

        $report = [];
        foreach (receiverSplitStatements($alignmentSql) as $statement) {
            $trim = trim($statement);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }

            try {
                $db->exec($trim);
                $report[] = [
                    'status' => 'ok',
                    'statement' => $trim,
                    'error' => '',
                ];
            } catch (Throwable $e) {
                $report[] = [
                    'status' => 'fail',
                    'statement' => $trim,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $_SESSION['db_sync_report'] = $report;
        $failed = array_filter($report, static fn(array $row): bool => ($row['status'] ?? '') !== 'ok');
        receiverJson(
            empty($failed),
            empty($failed) ? 'Allineamento eseguito correttamente.' : 'Allineamento eseguito con errori.',
            [
                'operations_report' => $report,
                'database' => (string)($db->query('SELECT DATABASE()')->fetchColumn() ?: ''),
                'generated_at' => date(DATE_ATOM),
            ],
            empty($failed) ? 200 : 422
        );
    }

    receiverJson(false, 'Azione non supportata.', [], 400);
} catch (Throwable $e) {
    receiverJson(false, $e->getMessage(), [], 500);
}
