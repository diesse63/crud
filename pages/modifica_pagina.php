<?php
/**
 * ============================================================
 * File generato automaticamente dall'applicazione CRUD.
 *
 * Generatore : CRUD Generator
 * Versione   : 10.2
 * Creato il  : 2026-08-04 00:00
 * Modificato il: 2026-08-05 00:00
 * Progetto   : CRUD Generator
 * ============================================================
 */

if (!isset($_GET['mode'])) {
    $_GET['mode'] = 'edit';
}

// Regola di base: `visibile_modale` va utilizzato come flag informativo,
// ma non deve essere considerato per il modale di inserimento e modifica.
require_once __DIR__ . '/creatore_pagina.php';
