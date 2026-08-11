<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : CRUD Generator
 * Versione   : 10.2
 * Creato il  : 2026-07-31 00:00:00
 * Modificato il: 2026-08-02 14:42
 * Progetto   : CRUD Generator
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/pannellate_core.php';

$progettoId = isset($_SESSION['progetto_id']) ? (int) $_SESSION['progetto_id'] : 0;
$progettoNome = trim((string) ($_SESSION['progetto_nome'] ?? ''));
$sessionConfigurationId = isset($_SESSION['aggiorna_pannellate_configuration_id'])
    ? (int) $_SESSION['aggiorna_pannellate_configuration_id']
    : 0;
$configurationId = $sessionConfigurationId;
$savedMessage = '';
$errorMessage = '';

try {
    if (!isset($db) || !($db instanceof Database)) {
        $db = new Database();
    }
} catch (Throwable $databaseError) {
    http_response_code(500);
    echo '<div class="alert alert-danger m-3">Errore di connessione al database.</div>';
    return;
}

$pageTypes = $db->fetchAll(
    "SELECT id, codice, descrizione, righe_per_pagina, righe_bloccate
     FROM pagine_visualizzazione_tipo
     ORDER BY id"
);

$pageTypeById = [];
foreach ($pageTypes as $pageType) {
    $pageTypeById[(int) ($pageType['id'] ?? 0)] = $pageType;
}

if ($progettoId <= 0 || $configurationId <= 0) {
    echo '<div class="alert alert-warning m-4">Accesso non consentito. Aprire la pannellata solo dalla tabella di gestione.</div>';
    return;
}

unset($_SESSION['aggiorna_pannellate_configuration_id']);

header('Location: index.php?page=creatore_pagina&configuration_id=' . $configurationId . '&t=' . time());
exit;
?>
