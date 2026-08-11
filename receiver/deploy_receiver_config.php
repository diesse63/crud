<?php
/**
 * CRUD Generator – PHP MySQL
 * Configurazione centralizzata receiver HTTPS
 * Versione: 1.3.0
 * Aggiornato il: 2026-07-26
 *
 * Posizione prevista:
 * /receiver/deploy_receiver_config.php
 */

declare(strict_types=1);

return [
    // Deve essere identico al token inserito nel pannello del CRUD.
    // Usare almeno 32 caratteri casuali.
    'token' => '12345678901234567890123456789012',

    // Root reale del sito destinatario. Poiché questo file è in /receiver,
    // dirname(__DIR__) corrisponde alla root del sito.
    'base_dir' => dirname(__DIR__),

    // Area tecnica privata usata dal receiver per staging, log e backup.
    'work_dir' => __DIR__ . DIRECTORY_SEPARATOR . '_deploy',

    /*
     * Percorsi pubblicabili e ispezionabili.
     * "." indica la root del sito.
     * Aggiungere eventuali applicazioni in sottocartella, per esempio:
     * ['.', 'gestionale', 'poi', 'spese']
     */
    'allowed_paths' => ['.'],

    // Necessario per consentire la pulizia quando Deploy path = ".".
    'allow_root_delete_missing' => true,

    // Backup remoto mantenuto soltanto per compatibilità.
    'backup_keep' => 1,
    'max_upload_bytes' => 50 * 1024 * 1024,

    /*
     * Nomi che non devono mai essere sovrascritti o cancellati.
     * "receiver" protegge tutta la cartella tecnica centralizzata.
     */
    'protected_names' => [
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
    ],

    // Percorsi persistenti esclusi dalla pulizia delete_missing.
    'persistent_paths' => [
        'receiver',
    ],

    // Tabella tecnica esclusa dall'esportazione della struttura applicativa.
    'schema_ignore_tables' => [
        '__crud_schema_sync',
    ],
];
