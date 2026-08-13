<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : CRUD Generator
 * Versione   : 10.2
 * Creato il  : 2026-08-04 00:00
 * Modificato il: 2026-08-13 00:00
 * Progetto   : CRUD Generator
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configurationId = isset($_GET['configuration_id']) ? max(0, (int) $_GET['configuration_id']) : 0;

if ($configurationId > 0) {
    $_SESSION['aggiorna_pannellate_configuration_id'] = $configurationId;
    require_once __DIR__ . '/aggiorna_pannellate.php';
    return;
}

if (!isset($_GET['mode'])) {
    $_GET['mode'] = 'edit';
}

// Regola di base: `visibile_modale` va utilizzato come flag informativo,
// ma non deve essere considerato per il modale di inserimento e modifica.
require_once __DIR__ . '/creatore_pagina.php';
