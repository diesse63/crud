<?php
const CREATORE_PAGINA_ERROR_LOG = __DIR__ . '/creatore_pagina_error.log';

function creatorePaginaWriteErrorLog(string $message): void
{
    error_log(
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        3,
        CREATORE_PAGINA_ERROR_LOG
    );
}

$creatorePaginaRequestStartedAt = microtime(true);
$creatorePaginaRequestedAction = (string) ($_GET['action'] ?? $_POST['action'] ?? 'pagina');

if ($creatorePaginaRequestedAction !== 'pagina') {
    creatorePaginaWriteErrorLog(
        'INIZIO action=' . preg_replace('/[^a-zA-Z0-9_-]/', '', $creatorePaginaRequestedAction)
    );

    register_shutdown_function(static function () use (
        $creatorePaginaRequestStartedAt,
        $creatorePaginaRequestedAction
    ): void {
        $lastError = error_get_last();
        if (!is_array($lastError)) {
            return;
        }

        if (!in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        creatorePaginaWriteErrorLog(sprintf(
            'FATALE action=%s durata=%.3fs errore=%s file=%s riga=%d',
            preg_replace('/[^a-zA-Z0-9_-]/', '', $creatorePaginaRequestedAction),
            microtime(true) - $creatorePaginaRequestStartedAt,
            str_replace(["\r", "\n"], ' ', (string) ($lastError['message'] ?? 'Errore sconosciuto')),
            (string) ($lastError['file'] ?? ''),
            (int) ($lastError['line'] ?? 0)
        ));
    });
}

require_once __DIR__ . '/creatore_pagina_fk.php';
require_once __DIR__ . '/creatore_pagina_ui.php';
require_once __DIR__ . '/versioning.php';
require_once __DIR__ . '/pannellate_core.php';
/**
 * scheda_singola.php
 * Generatore Scheda Singola - versione 1.37
 *
 * Pagina interna dell'applicazione CRUD.
 * - Verifica schema.sql nella cartella del progetto attivo.
 * - Carica tabelle, campi, indici e foreign key dal DB della CRUD.
 * - Consente la selezione e l'ordinamento dei campi.
 * - Salva la configurazione nelle tabelle pagine_visualizzazione*.
 * - Genera una singola pagina PHP nella cartella /pages del progetto.
 *
 * AGGIORNAMENTI v1.3:
 * - layout responsive ottimizzato per smartphone;
 * - toolbar, ricerca, pulsanti e navigazione adattati al mobile;
 * - messaggi CRUD temporanei rimossi dai link di navigazione;
 * - pulizia automatica di crud_message dalla barra degli indirizzi;
 * - asterisco rosso accanto ai campi obbligatori NOT NULL;
 * - versione e data di generazione scritte nelle note iniziali
 *   di ogni pagina prodotta e mostrate tramite variabili sincronizzate;
 * - eliminazione di condizioni provvisorie e duplicazioni dei pulsanti.
 *
 * AGGIORNAMENTI v1.3:
 *
 *
 * AGGIORNAMENTI v1.4:
 * - in SCHEDA_SINGOLA con modale tabellare, la tabella collegata
 *   del modale non viene più inserita nella query principale;
 * - il record principale viene mostrato una sola volta;
 * - i record multipli della tabella collegata vengono caricati solo
 *   nel modale in forma tabellare.
 *
 * INTEGRAZIONE:
 * - Questo file va copiato in /membri/dasi/pages/pages/.
 * - Viene incluso da index.php?page=scheda_singola.
 * - Bootstrap 5 e Bootstrap Icons sono caricati dal layout index.php.
 *
 * AGGIORNAMENTI v1.7:
 * - preservate le JOIN della scheda principale quando un campo della tabella
 *   collegata e' selezionato anche prima del salvataggio della pagina;
 * - il filtro anti-duplicazione della modale tabellare rimuove la relazione
 *   collegata solo quando non serve alla SELECT principale.
 *
 * AGGIORNAMENTI v1.8:
 * - confermata la disponibilita' dell'inserimento record collegato nel
 *   modale della scheda singola anche quando non esistono righe associate;
 * - il pulsante di apertura della scheda collegata mantiene il comportamento
 *   di inserimento rapido quando il modale non ha ancora record;
 * - allineata la versione del generatore alla scheda master detail.
 *
 * AGGIORNAMENTI v1.13:
 * - il campo "Nome pagina" compila automaticamente il nome file PHP e il
 *   titolo visualizzato al blur, solo se i campi sono ancora vuoti;
 * - se i campi sono già compilati non vengono sovrascritti.
 * - il campo "righe per pagina" è visibile nella scheda dati e rimane
 *   bloccato a 1 per le pagine di tipo singola (codice = singola).
 * - il valore di righe per pagina segue pagine_visualizzazione_tipo.righe_per_pagina.
 *
 * AGGIORNAMENTI v1.17:
 *
 * AGGIORNAMENTI v1.18:
 * - aggiunta la gestione inline del record collegato per i campi FK,
 *   con possibilità di inserimento e modifica senza uscire dalla pannellata;
 *
 * AGGIORNAMENTI v1.29:
 * - semplificato il caricamento della sezione "Tabelle collegate" per
 *   renderlo stabile sia in apertura modifica sia nel cambio tabella;
 *
 * AGGIORNAMENTI v1.32:
 * - aggiunta nel file destinatario la versione pagina visibile nella
 *   testata della scheda singola, insieme ai pulsanti di navigazione già
 *   previsti dalla configurazione;
 * - isolata la scrittura materiale della pagina generata in una funzione
 *   dedicata, mantenendo la logica di costruzione basata sulle opzioni lette
 *   e il salvataggio nella cartella progetto.
 *
 * AGGIORNAMENTI v1.33:
 * - reso dinamico il titolo del pannello report per distinguere il riepilogo
 *   di caricamento configurazione dalla voce usata nella modalità modifica.
 *
 * AGGIORNAMENTI v1.34:
 * - campi CRUD limitati ai campi della SELECT e alle sole FK usate dalle JOIN;
 * - corretta la generazione degli elenchi FK e dei moduli inserimento/modifica.
 *
 * AGGIORNAMENTI v1.35:
 * - ordine dei controlli CRUD identico all'ordine dei campi nella SELECT;
 * - campi JOIN trasformati in dropdown sulla FK principale con descrizione SQL.
 *
 * AGGIORNAMENTI v1.36:
 * - corretto il nome della tabella principale nella configurazione CRUD;
 * - ripristinata l'editabilità dei campi mostrati nei modali.
 *
 * AGGIORNAMENTI v1.37:
 * - ricerca estesa a tutti i campi della SELECT prima della paginazione;
 * - navigazione della scheda allineata in basso a destra;
 * - layout CSS dei modali uniforme alla pannellata principale.
 *
 * AGGIORNAMENTI v1.28:
 * - introdotta una cache HTML dedicata per la sezione "Tabelle collegate"
 *   così il contenuto caricato in modifica non viene più perso;
 *
 * AGGIORNAMENTI v1.27:
 * - separato lo stato delle tabelle salvate da quello della tabella
 *   principale, per mantenere coerente il caricamento della sezione 3 in
 *   modifica;
 *
 * AGGIORNAMENTI v1.26:
 * - ripristinato il rendering completo della sezione "Tabelle collegate"
 *   in caricamento modifica, con checkbox e selezione join visibili subito;
 *
 * AGGIORNAMENTI v1.25:
 * - allineata la visualizzazione dei campi collegati alle sole tabelle
 *   effettivamente selezionate in modifica;
 *
 * AGGIORNAMENTI v1.24:
 * - agganciato il flag `selezionata` delle tabelle collegate al caricamento
 *   in modifica per rendere affidabile la ricostruzione della selezione;
 *
 * AGGIORNAMENTI v1.23:
 * - corretto l'ordine di caricamento in modifica per mantenere selezione
 *   e visualizzazione delle tabelle collegate durante l'apertura da tabella
 *   pannellate;
 *
 * AGGIORNAMENTI v1.22:
 * - corretto il caricamento da tabella pannellate per mantenere selezione
 *   e visualizzazione coerenti delle tabelle collegate;
 *
 * AGGIORNAMENTI v1.21:
 * - allineati i contatori di versione del creatore pagina e della pagina
 *   generata al progressivo degli interventi eseguiti;
 *
 * AGGIORNAMENTI v1.20:
 * - aggiunta nella pannellata la versione del creatore di pagina utilizzato
 *   e la versione pagina nel formato semantico 1.1;
 *
 * AGGIORNAMENTI v1.19:
 * - resa richiudibile la lista dei "Campi da visualizzare" con freccia;
 * - allineati i valori iniziali dei campi alla tipologia del campo.
 *
 * AGGIORNAMENTI v1.16:
 * AGGIORNAMENTI v1.14:
 * - ripristinato il trascinamento dei campi disponibili e dei campi
 *   selezionati nella sezione 4;
 * - ripristinata la selezione con doppio click dei campi disponibili;
 * - aggiunto l'ordinamento dei campi selezionati con le frecce su/giù.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

$initialConfigurationId = isset($_GET['configuration_id']) ? max(0, (int) $_GET['configuration_id']) : 0;
$saveErrorContext = [];

const SCHEDA_SINGOLA_GENERATOR_VERSION = '1.37';
const SCHEDA_TABELLARE_GENERATOR_VERSION = '1.10';
const MASTER_DETAIL_GENERATOR_VERSION = '1.8';
const GENERATED_PAGE_VERSION = '1.0';

$progettoId   = isset($_SESSION['progetto_id']) ? (int) $_SESSION['progetto_id'] : 0;
$progettoNome = trim((string) ($_SESSION['progetto_nome'] ?? ''));
$creatorPageMode = strtolower(trim((string) ($_GET['mode'] ?? 'edit')));
if (!in_array($creatorPageMode, ['new', 'edit'], true)) {
    $creatorPageMode = 'edit';
}

$pageTypes = [];
$selectedPageTypeId = isset($_GET['tipo_id']) ? (int) $_GET['tipo_id'] : 0;
try {
    if (!isset($db) || !($db instanceof Database)) {
        $db = new Database();
    }

    $pageTypes = $db->fetchAll(
        "SELECT id, codice, descrizione, righe_per_pagina, righe_bloccate, show_modal
         FROM pagine_visualizzazione_tipo
         ORDER BY id"
    );
} catch (Throwable $databaseError) {
    $pageTypes = [];
}

$selectedPageType = null;
foreach ($pageTypes as $pageType) {
    if ((int) ($pageType['id'] ?? 0) === $selectedPageTypeId) {
        $selectedPageType = $pageType;
        break;
    }
}

$pageHeaderDescription = 'Selezionare la tipologia';
if ($selectedPageType && trim((string) ($selectedPageType['descrizione'] ?? '')) !== '') {
    $pageHeaderDescription = pannellateNormalizePageTypeDescription((string) $selectedPageType['descrizione']);
}

$pageTypeDescriptions = [];
$pageTypeCodes = [];
$pageTypeRowsPerPage = [];
$isInitialSinglePageType = false;
$singlePageTypeId = 0;
foreach ($pageTypes as $pageType) {
    $typeId = (int) ($pageType['id'] ?? 0);
    $pageTypeDescriptions[$typeId] = pannellateNormalizePageTypeDescription((string) ($pageType['descrizione'] ?? ''));
    $pageTypeCodes[$typeId] = (string) ($pageType['codice'] ?? '');
    $pageTypeRowsPerPage[$typeId] = max(1, (int) ($pageType['righe_per_pagina'] ?? 25));
    if (in_array((string) ($pageType['codice'] ?? ''), ['singola', 'SCHEDA_SINGOLA'], true)) {
        $singlePageTypeId = $typeId;
    }
    if ($typeId === $selectedPageTypeId) {
        $isInitialSinglePageType = in_array((string) ($pageType['codice'] ?? ''), ['singola', 'SCHEDA_SINGOLA'], true);
    }
}

function sanitizeFolderName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = preg_replace('/[\s\.\,\!\?]+/u', '_', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $name = preg_replace('/[^a-z0-9_]/', '', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_') ?: 'progetto_senza_nome';
}

function formatSaveDuplicateError(Throwable $e, array $context = []): string
{
    $message = $e->getMessage();
    $code = (int) $e->getCode();
    if (!str_contains($message, 'Duplicate entry') && $code !== 1062) {
        return $message;
    }

    $pageName = trim((string) ($context['page_name'] ?? ''));
    $fileName = trim((string) ($context['file_name'] ?? ''));
    $filePath = trim((string) ($context['file_path'] ?? ''));
    $operation = trim((string) ($context['operation'] ?? 'salvataggio'));
    $details = [];

    if ($pageName !== '') {
        $details[] = 'Nome pagina: ' . $pageName;
    }
    if ($fileName !== '') {
        $details[] = 'Nome file: ' . $fileName;
    }
    if ($filePath !== '') {
        $details[] = 'Percorso file: ' . $filePath;
    }

    return 'Salvataggio non riuscito per conflitto di unicità durante ' . $operation . '.'
        . ($details ? ' Dettagli: ' . implode(' | ', $details) . '.' : '');
}

/**
 * Restituisce tutte le FK direttamente collegate alla tabella principale,
 * sia in uscita sia in entrata.
 */

function sqlFieldReference(array $component, array $fieldMap, array $tableMap): string
{
    $fieldId = (int) ($component['field_id'] ?? 0);
    if (!isset($fieldMap[$fieldId])) {
        throw new RuntimeException('Campo usato in espressione non valido.');
    }

    $field = $fieldMap[$fieldId];
    $tableId = (int) $field['IDtabella'];
    if (!isset($tableMap[$tableId])) {
        throw new RuntimeException('Campo usato in espressione non appartiene alle tabelle selezionate.');
    }

    return $tableMap[$tableId]['alias'] . '.' . quoteIdentifier($field['nome']);
}

function buildVirtualFieldExpression(array $selectedField, array $fieldMap, array $tableMap): string
{
    $type = strtoupper((string) ($selectedField['expression_type'] ?? 'FIELD'));
    $components = array_values((array) ($selectedField['components'] ?? []));

    if ($type === 'CONCAT') {
        if (!$components) {
            throw new RuntimeException('Campo concatenato senza campi sorgente.');
        }

        $hasTokenSeparators = false;
        foreach ($components as $component) {
            if (($component['type'] ?? '') === 'separator') {
                $hasTokenSeparators = true;
                break;
            }
        }

        if ($hasTokenSeparators) {
            $parts = [];
            foreach ($components as $component) {
                if (($component['type'] ?? '') === 'separator') {
                    $parts[] = quoteSqlString((string) ($component['value'] ?? ''));
                    continue;
                }

                $parts[] = 'COALESCE(CAST(' . sqlFieldReference($component, $fieldMap, $tableMap) . ' AS CHAR), \'\')';
            }

            return 'CONCAT(' . implode(', ', $parts) . ')';
        }

        $separator = (string) ($selectedField['separator'] ?? ' ');
        $parts = [];
        foreach ($components as $component) {
            $parts[] = 'NULLIF(CAST(' . sqlFieldReference($component, $fieldMap, $tableMap) . ' AS CHAR), \'\')';
        }

        return 'CONCAT_WS(' . quoteSqlString($separator) . ', ' . implode(', ', $parts) . ')';
    }

    if ($type === 'FORMULA') {
        $formula = trim((string) ($selectedField['expression'] ?? ''));
        if ($formula === '') {
            throw new RuntimeException('Formula calcolata vuota.');
        }

        if (!preg_match('/^[0-9+\-*\/%().,\sA-Za-z_{}]+$/', $formula)) {
            throw new RuntimeException('La formula contiene caratteri non ammessi.');
        }

        $references = [];
        foreach ($components as $component) {
            $token = strtoupper(trim((string) ($component['token'] ?? '')));
            if ($token === '' || !preg_match('/^F[0-9]+$/', $token)) {
                continue;
            }
            $references[$token] = sqlFieldReference($component, $fieldMap, $tableMap);
        }

        $allowedFunctions = ['ABS', 'ROUND', 'CEIL', 'CEILING', 'FLOOR', 'POW', 'POWER', 'SQRT'];
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $formula, $matches);
        foreach ($matches[0] as $word) {
            $upperWord = strtoupper($word);
            if (preg_match('/^F[0-9]+$/', $upperWord)) {
                continue;
            }
            if (!in_array($upperWord, $allowedFunctions, true)) {
                throw new RuntimeException('Funzione o parola non ammessa nella formula: ' . $word . '.');
            }
        }

        $sql = preg_replace_callback('/\{(F[0-9]+)\}/i', function (array $match) use ($references): string {
            $token = strtoupper($match[1]);
            if (!isset($references[$token])) {
                throw new RuntimeException('La formula contiene un campo non riconosciuto: {' . $token . '}.');
            }
            return 'CAST(COALESCE(' . $references[$token] . ', 0) AS DECIMAL(30,10))';
        }, $formula);

        return '(' . $sql . ')';
    }

    throw new RuntimeException('Tipo di campo virtuale non supportato.');
}

function ensureVirtualStorageField(Database $db, int $tableId, int $pageId, int $order): int
{
    $name = '__virtual_pvc_' . $pageId . '_' . $order;
    $existingId = (int) $db->fetchColumn(
        'SELECT id FROM campi WHERE IDtabella = ? AND nome = ?',
        [$tableId, $name]
    );

    if ($existingId > 0) {
        return $existingId;
    }

    $nextOrder = (int) $db->fetchColumn(
        'SELECT COALESCE(MAX(ordine), 0) + 1 FROM campi WHERE IDtabella = ?',
        [$tableId]
    );

    $db->execute(
        "INSERT INTO campi (
            IDtabella,
            nome,
            tipo,
            lunghezza,
            nullable,
            default_value,
            indice_tipo,
            auto_increment,
            modifica,
            ordine
         ) VALUES (?, ?, 'varchar', '255', 1, NULL, 'NO', 0, 0, ?)",
        [$tableId, $name, $nextOrder]
    );

    return (int) $db->lastInsertId();
}

function buildSqlPreview(
    Database $db,
    int $projectId,
    int $mainTableId,
    array $selectedTables,
    array $selectedFields
): array {
    $mainTable = pannellateLoadTable($db, $projectId, $mainTableId);
    if (!$mainTable) {
        throw new RuntimeException('Tabella principale non valida.');
    }

    $tableMap = [
        $mainTableId => [
            'id' => $mainTableId,
            'name' => $mainTable['nome'],
            'alias' => 't0',
            'type' => 'PRINCIPALE',
            'fk_id' => null,
            'join_type' => null,
        ]
    ];

    $relations = pannellateLoadRelations($db, $projectId, $mainTableId);
    $relationsByFk = [];
    foreach ($relations as $relation) {
        $relationsByFk[(int) $relation['fk_id']] = $relation;
    }

    $buildTableKey = static function (int $tableId, int $fkId = 0): string {
        return $tableId . ':' . max(0, $fkId);
    };

    $aliasIndex = 1;
    $joins = [];
    foreach ($selectedTables as $selectedTable) {
        $secondaryId = (int) ($selectedTable['table_id'] ?? 0);
        $fkId = (int) ($selectedTable['fk_id'] ?? 0);
        $joinType = strtoupper((string) ($selectedTable['join_type'] ?? 'LEFT'));
        if (!in_array($joinType, ['LEFT', 'INNER'], true)) {
            $joinType = 'LEFT';
        }

        if (!$secondaryId || !$fkId || !isset($relationsByFk[$fkId])) {
            continue;
        }

        $relation = $relationsByFk[$fkId];
        if ((int) $relation['secondary_table_id'] !== $secondaryId) {
            continue;
        }

        $alias = 't' . $aliasIndex++;
        $tableMap[$buildTableKey($secondaryId, $fkId)] = [
            'id' => $secondaryId,
            'name' => $relation['secondary_table_name'],
            'alias' => $alias,
            'type' => 'SECONDARIA',
            'fk_id' => $fkId,
            'join_type' => $joinType,
        ];

        $conditions = [];
        foreach ($relation['pairs'] as $pair) {
            $localFieldName = trim((string) (
                $pair['local']
                ?? $pair['local_field_name']
                ?? ''
            ));
            $referencedFieldName = trim((string) (
                $pair['referenced']
                ?? $pair['referenced_field_name']
                ?? ''
            ));

            if ($localFieldName === '' || $referencedFieldName === '') {
                throw new RuntimeException(
                    'Definizione incompleta della foreign key '
                    . ($relation['fk_nome'] ?? $fkId)
                    . ': nome campo locale o referenziato mancante.'
                );
            }

            if ($relation['direction'] === 'OUT') {
                $conditions[] =
                    't0.' . quoteIdentifier($localFieldName) .
                    ' = ' . $alias . '.' . quoteIdentifier($referencedFieldName);
            } else {
                $conditions[] =
                    $alias . '.' . quoteIdentifier($localFieldName) .
                    ' = t0.' . quoteIdentifier($referencedFieldName);
            }
        }

        if ($conditions) {
            $joins[] =
                $joinType . ' JOIN ' .
                quoteIdentifier($relation['secondary_table_name']) . ' ' . $alias .
                ' ON ' . implode(' AND ', $conditions);
        }
    }

    $fieldIds = [];
    foreach ($selectedFields as $field) {
        $fieldIds[] = (int) ($field['field_id'] ?? 0);
        foreach ((array) ($field['components'] ?? []) as $component) {
            $fieldIds[] = (int) ($component['field_id'] ?? $component['fieldId'] ?? 0);
        }
    }
    $fieldIds = array_values(array_unique(array_filter($fieldIds)));

    if (!$fieldIds) {
        throw new RuntimeException('Selezionare almeno un campo.');
    }

    $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
    $rows = $db->fetchAll(
        "SELECT c.id, c.IDtabella, c.nome, t.nome AS tabella_nome
         FROM campi c
         JOIN tabelle t ON t.id = c.IDtabella
         WHERE c.id IN ($placeholders)
           AND t.IDprogetto = ?",
        [...$fieldIds, $projectId]
    );

    $fieldMap = [];
    foreach ($rows as $row) {
        $fieldMap[(int) $row['id']] = $row;
    }

    $select = [];
    $normalizedFields = [];
    foreach ($selectedFields as $position => $selectedField) {
        $fieldId = (int) ($selectedField['field_id'] ?? 0);
        $expressionType = strtoupper((string) ($selectedField['expression_type'] ?? 'FIELD'));
        $outputAlias = 'c' . ($position + 1);

        if ($expressionType !== 'FIELD') {
            $expression = buildVirtualFieldExpression($selectedField, $fieldMap, $tableMap);
            $select[] = $expression . ' AS ' . quoteIdentifier($outputAlias);
            $storageFieldId = 0;
            $storageTableId = $mainTableId;
            $storageTableName = $mainTable['nome'];
            foreach ((array) ($selectedField['components'] ?? []) as $component) {
                $componentFieldId = (int) ($component['field_id'] ?? 0);
                if (isset($fieldMap[$componentFieldId])) {
                    $storageFieldId = $componentFieldId;
                    break;
                }
            }
            if ($storageFieldId <= 0) {
                throw new RuntimeException('Campo virtuale senza riferimenti validi.');
            }

            $qualified = trim((string) ($selectedField['qualified_name'] ?? ''));
            if ($qualified === '') {
                $qualified = $expressionType === 'FORMULA'
                    ? 'Formula calcolata'
                    : 'Campo concatenato';
            }

            $normalizedFields[] = [
                'field_id' => 0,
                'storage_field_id' => $storageFieldId,
                'table_id' => $storageTableId,
                'table_name' => $storageTableName,
                'field_name' => $qualified,
                'qualified_name' => $qualified,
                'label' => trim((string) ($selectedField['label'] ?? '')) ?: $qualified,
                'order' => $position + 1,
                'visible_table' => !empty($selectedField['visible_table']),
                'visible_card' => !empty($selectedField['visible_card']),
                'visible_modal' => !empty($selectedField['visible_modal']),
                'searchable' => !empty($selectedField['searchable']),
                'sortable' => !empty($selectedField['sortable']),
                'format' => trim((string) ($selectedField['format'] ?? '')),
                'alignment' => normalizeVisualAlignmentCode((string) ($selectedField['alignment'] ?? ($expressionType === 'FORMULA' ? 'DESTRA' : 'SINISTRA'))),
                'width' => trim((string) ($selectedField['width'] ?? '')),
                'bootstrap_col' => in_array((string) ($selectedField['bootstrap_col'] ?? '6'), ['3','4','6','8','12'], true)
                    ? (string) $selectedField['bootstrap_col'] : '6',
                'filter_enabled' => !empty($selectedField['filter_enabled']),
                'filter_type' => (string) ($selectedField['filter_type'] ?? ($expressionType === 'FORMULA' ? 'INTERVALLO_NUMERO' : 'TESTO')),
                'link_page_id' => (int) ($selectedField['link_page_id'] ?? 0),
                'link_parameter' => trim((string) ($selectedField['link_parameter'] ?? '')),
                'link_value_field' => trim((string) ($selectedField['link_value_field'] ?? '')),
                'base_path' => trim((string) ($selectedField['base_path'] ?? '')),
                'output_alias' => $outputAlias,
                'expression_type' => $expressionType,
                'expression' => trim((string) ($selectedField['expression'] ?? '')),
                'separator' => (string) ($selectedField['separator'] ?? ' '),
                'components' => array_values((array) ($selectedField['components'] ?? [])),
            ];
            continue;
        }

        if (!isset($fieldMap[$fieldId])) {
            continue;
        }

        $field = $fieldMap[$fieldId];
        $tableId = (int) $field['IDtabella'];
        $sourceFkId = max(0, (int) ($selectedField['source_fk_id'] ?? 0));
        $relation = $sourceFkId > 0 ? ($relationsByFk[$sourceFkId] ?? null) : null;
        $tableKey = $buildTableKey($tableId, $tableId === $mainTableId ? 0 : $sourceFkId);

        if (!isset($tableMap[$tableKey])) {
            if (isset($tableMap[$tableId]) && $tableId === $mainTableId) {
                $tableKey = $tableId;
            } else {
                continue;
            }
        }

        $sourceAlias = $tableMap[$tableKey]['alias'];
        $select[] =
            $sourceAlias . '.' . quoteIdentifier($field['nome']) .
            ' AS ' . quoteIdentifier($outputAlias);

        $qualified = $tableId === $mainTableId
            ? $field['nome']
            : $field['tabella_nome'] . '.' . $field['nome'];

        $normalizedFields[] = [
            'field_id' => $fieldId,
            'table_id' => $tableId,
            'table_key' => $tableKey,
            'source_fk_id' => $tableId === $mainTableId ? 0 : $sourceFkId,
            'table_name' => $field['tabella_nome'],
            'field_name' => $field['nome'],
            'qualified_name' => $qualified,
            'label' => trim((string) ($selectedField['label'] ?? '')) ?: $qualified,
            'order' => $position + 1,
            'visible_table' => !empty($selectedField['visible_table']),
            'visible_card' => !empty($selectedField['visible_card']),
            'visible_modal' => !empty($selectedField['visible_modal']),
            'searchable' => !empty($selectedField['searchable']),
            'sortable' => !empty($selectedField['sortable']),
            'format' => trim((string) ($selectedField['format'] ?? $selectedField['formato_visualizzazione'] ?? '')),
            'alignment' => normalizeVisualAlignmentCode(
                (($alignmentValue = trim((string) ($selectedField['alignment'] ?? $selectedField['allineamento'] ?? ''))) !== '')
                    ? $alignmentValue
                    : 'SINISTRA'
            ),
            'width' => trim((string) ($selectedField['width'] ?? '')),
            'bootstrap_col' => in_array((string) ($selectedField['bootstrap_col'] ?? '6'), ['3','4','6','8','12'], true)
                ? (string) $selectedField['bootstrap_col'] : '6',
            'filter_enabled' => !empty($selectedField['filter_enabled']),
            'filter_type' => (string) ($selectedField['filter_type'] ?? 'TESTO'),
            'link_page_id' => (int) ($selectedField['link_page_id'] ?? 0),
            'link_parameter' => trim((string) ($selectedField['link_parameter'] ?? '')),
            'link_value_field' => trim((string) ($selectedField['link_value_field'] ?? '')),
            'base_path' => trim((string) ($selectedField['base_path'] ?? '')),
            'output_alias' => $outputAlias,
            'fk' => is_array($relation) ? [
                'referenced_table_name' => (string) ($relation['secondary_table_name'] ?? ''),
                'referenced_field_name' => (string) (
                    $relation['pairs'][0]['referenced_field_name']
                    ?? $relation['pairs'][0]['referenced']
                    ?? ''
                ),
                'description_field_name' => (string) (
                    $relation['description_field_name']
                    ?? $relation['pairs'][0]['description_field_name']
                    ?? $relation['pairs'][0]['description']
                    ?? $field['nome']
                    ?? ''
                ),
            ] : null,
        ];
    }

    if (!$select) {
        throw new RuntimeException('I campi selezionati non appartengono alle tabelle ammesse.');
    }

    $sql = "SELECT\n    " . implode(",\n    ", $select) .
        "\nFROM " . quoteIdentifier($mainTable['nome']) . " t0";

    if ($joins) {
        $sql .= "\n" . implode("\n", $joins);
    }

    return [
        'sql' => $sql,
        'main_table' => $mainTable,
        'tables' => array_values($tableMap),
        'fields' => $normalizedFields,
    ];
}


function buildCrudConfiguration(
    Database $db,
    int $projectId,
    int $mainTableId,
    array $selectedFields = [],
    array $selectedTables = []
): array {
    $table = pannellateLoadTable($db, $projectId, $mainTableId);
    if (!$table) {
        throw new RuntimeException('Tabella principale CRUD non valida.');
    }

    $fields = pannellateLoadFields($db, $mainTableId);
    $foreignKeys = [];
    foreach ($db->fetchAll(
        "SELECT
            kcu.COLUMN_NAME AS local_field_name,
            kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
            kcu.REFERENCED_COLUMN_NAME AS referenced_field_name,
            rc.UPDATE_RULE AS update_rule,
            rc.DELETE_RULE AS delete_rule
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
         INNER JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
            ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
           AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
           AND tc.TABLE_NAME = kcu.TABLE_NAME
         LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
            ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
           AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         WHERE tc.TABLE_SCHEMA = DATABASE()
           AND tc.TABLE_NAME = ?
           AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
         ORDER BY kcu.ORDINAL_POSITION",
        [$table['nome']]
    ) as $fkRow) {
        $descriptionFieldName = (string) $fkRow['referenced_field_name'];
        $referencedTableId = (int) $db->fetchColumn(
            'SELECT id FROM tabelle WHERE nome = ? AND IDprogetto = ? LIMIT 1',
            [(string) $fkRow['referenced_table_name'], $projectId]
        );
        if ($referencedTableId > 0) {
            $referencedFields = pannellateLoadFields($db, $referencedTableId);
            foreach ($referencedFields as $referencedField) {
                if (!empty($referencedField['is_pk'])) {
                    continue;
                }
                $referencedType = strtolower((string) ($referencedField['tipo'] ?? ''));
                if (in_array($referencedType, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                    $descriptionFieldName = (string) ($referencedField['nome'] ?? $descriptionFieldName);
                    break;
                }
                if ($descriptionFieldName === (string) $fkRow['referenced_field_name']) {
                    $descriptionFieldName = (string) ($referencedField['nome'] ?? $descriptionFieldName);
                }
            }
        }

        $foreignKeys[(string) $fkRow['local_field_name']] = [
            'local_field_name' => (string) $fkRow['local_field_name'],
            'referenced_table_name' => (string) $fkRow['referenced_table_name'],
            'referenced_field_name' => (string) $fkRow['referenced_field_name'],
            'description_field_name' => $descriptionFieldName,
            'update_rule' => (string) ($fkRow['update_rule'] ?? 'RESTRICT'),
            'delete_rule' => (string) ($fkRow['delete_rule'] ?? 'RESTRICT'),
        ];
    }
    $selectedFieldIndex = [];
    foreach ($selectedFields as $selectedField) {
        $fieldId = (int) ($selectedField['field_id'] ?? $selectedField['fieldId'] ?? 0);
        $tableId = (int) ($selectedField['table_id'] ?? $selectedField['tableId'] ?? 0);
        if ($fieldId > 0) {
            $selectedFieldIndex[$fieldId] = $selectedField;
        }
        if ($fieldId > 0 && $tableId > 0) {
            $selectedFieldIndex[$tableId . ':' . $fieldId] = $selectedField;
        }
        foreach ((array) ($selectedField['components'] ?? []) as $component) {
            $componentFieldId = (int) ($component['field_id'] ?? $component['fieldId'] ?? 0);
            $componentTableId = (int) ($component['table_id'] ?? $component['tableId'] ?? 0);
            if ($componentFieldId > 0 && !isset($selectedFieldIndex[$componentFieldId])) {
                $selectedFieldIndex[$componentFieldId] = $component;
            }
            if ($componentFieldId > 0 && $componentTableId > 0) {
                $selectedFieldIndex[$componentTableId . ':' . $componentFieldId] = $component;
            }
        }
    }

    $primaryFields = array_values(array_filter(
        $fields,
        fn(array $field): bool => !empty($field['is_pk'])
    ));

    /*
     * Compatibilità con progetti più vecchi: se nessun indice PRIMARY
     * è registrato, un solo campo AUTO_INCREMENT viene considerato PK.
     */
    if (!$primaryFields) {
        $autoIncrementFields = array_values(array_filter(
            $fields,
            fn(array $field): bool => !empty($field['auto_increment'])
        ));

        if (count($autoIncrementFields) === 1) {
            $autoIncrementFields[0]['is_pk'] = true;
            $primaryFields = $autoIncrementFields;
        }
    }

    if (count($primaryFields) !== 1) {
        return [
            'available' => false,
            'reason' => count($primaryFields) === 0
                ? 'nessuna chiave primaria trovata'
                : 'più chiavi primarie trovate',
        ];
    }

    $crudFields = [];
    $crudSourceFields = $selectedFields ?: $fields;
    foreach ($crudSourceFields as $field) {
        $fieldId = (int) ($field['field_id'] ?? $field['id'] ?? 0);
        $fieldTableId = (int) ($field['table_id'] ?? $field['tableId'] ?? $mainTableId);
        $fieldIndexKey = $fieldTableId . ':' . $fieldId;
        $selectedMeta = $selectedFieldIndex[$fieldIndexKey] ?? $selectedFieldIndex[$fieldId] ?? $field;
        $isPrimaryKeyField = !empty($field['is_pk'])
            || (int) ($field['field_id'] ?? $field['id'] ?? 0) === (int) $primaryFields[0]['id'];

        if ($selectedFieldIndex && !isset($selectedFieldIndex[$fieldIndexKey]) && !isset($selectedFieldIndex[$fieldId]) && !$isPrimaryKeyField) {
            continue;
        }

        $fieldName = (string) ($field['field_name'] ?? $field['nome'] ?? $field['field_name'] ?? '');
        $fieldTableName = (string) ($field['table_name'] ?? $field['tabella_nome'] ?? $table['nome'] ?? '');
        $crudFields[] = [
            'field_id' => $fieldId,
            'table_id' => $fieldTableId,
            'table_name' => $fieldTableName,
            'field_name' => $fieldName,
            'label' => trim((string) ($selectedMeta['label'] ?? $fieldName ?? '')) ?: $fieldName,
            'editable' => !$isPrimaryKeyField && !empty($selectedMeta['editable'] ?? $field['editable'] ?? true),
            'insert_visible' => !$isPrimaryKeyField && !empty($selectedMeta['editable'] ?? $field['editable'] ?? true),
            'update_visible' => !$isPrimaryKeyField && !empty($selectedMeta['editable'] ?? $field['editable'] ?? true),
            'required' => !$isPrimaryKeyField && (
                is_numeric($field['nullable'] ?? null)
                    ? !(bool) $field['nullable']
                    : strtoupper((string) ($field['nullable'] ?? '')) === 'NO'
            ),
            'fk' => $selectedMeta['fk'] ?? $field['fk'] ?? $foreignKeys[$fieldName] ?? null,
            'type' => (string) ($field['tipo'] ?? $field['type'] ?? ''),
            'default_value' => $field['default_value'] ?? null,
            'bootstrap_col' => in_array((string) ($selectedMeta['bootstrap_col'] ?? $selectedMeta['bootstrapCol'] ?? '6'), ['3','4','6','8','12'], true)
                ? (string) ($selectedMeta['bootstrap_col'] ?? $selectedMeta['bootstrapCol'] ?? '6')
                : '6',
        ];
    }

    return [
        'available' => true,
        'reason' => '',
        'table_name' => (string) $table['nome'],
        'primary_key' => [
            'field_id' => (int) $primaryFields[0]['id'],
            'field_name' => (string) $primaryFields[0]['nome'],
        ],
        'fields' => $crudFields,
    ];
}


function resolveGeneratedPageMetadata(string $viewType): array
{
    $viewType = strtoupper(trim($viewType));

    return match ($viewType) {
        'SCHEDA_SINGOLA' => [
            'label' => 'Scheda Singola',
            'version' => SCHEDA_SINGOLA_GENERATOR_VERSION,
            'source' => 'pages/scheda_singola.php',
        ],
        'TABELLA_MODALE' => [
            'label' => 'Scheda Tabellare',
            'version' => SCHEDA_TABELLARE_GENERATOR_VERSION,
            'source' => 'pages/scheda_tabellare.php',
        ],
        'MASTER_DETAIL' => [
            'label' => 'Scheda Master Detail',
            'version' => MASTER_DETAIL_GENERATOR_VERSION,
            'source' => 'pages/scheda_master_detail.php',
        ],
        default => [
            'label' => 'Scheda Singola',
            'version' => SCHEDA_SINGOLA_GENERATOR_VERSION,
            'source' => 'pages/scheda_singola.php',
        ],
    };
}

function normalizeViewTypeCode(string $value): string
{
    $value = strtoupper(trim($value));
    return match ($value) {
        'SINGOLA', 'SCHEDA_SINGOLA' => 'SCHEDA_SINGOLA',
        'TABELLARE', 'TABELLA_MODALE' => 'TABELLA_MODALE',
        'MASTERDETAIL', 'MASTER_DETAIL' => 'MASTER_DETAIL',
        default => $value,
    };
}

function normalizeVisualFormatCode(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return $value;
}

function defaultVisualFormatByType(string $type): string
{
    $type = strtolower(trim($type));

    return match ($type) {
        'decimal', 'float', 'double' => '#.##0,00',
        'int', 'tinyint', 'smallint', 'mediumint', 'bigint' => '#.##0',
        'date' => 'dd/mm/aaaa',
        'datetime', 'timestamp' => 'dd/mm/aaaa hh:mm',
        'time' => 'hh:mm',
        default => '',
    };
}

function normalizeVisualAlignmentCode(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['SINISTRA', 'CENTRO', 'DESTRA'], true) ? $value : 'SINISTRA';
}

function writeGeneratedPageToProjectFolder(string $targetPath, string $generatedCode): void
{
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Impossibile creare la cartella di destinazione della pagina generata.');
    }

    $temporaryPath = $targetPath . '.tmp';
    if (file_put_contents($temporaryPath, $generatedCode, LOCK_EX) === false) {
        throw new RuntimeException('Scrittura del file temporaneo non riuscita.');
    }

    if (!rename($temporaryPath, $targetPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Sostituzione del file PHP non riuscita.');
    }
}

function repairGeneratedDisplayValueBlock(string $generatedCode): string
{
    return $generatedCode;
}

function buildGeneratedPageCode(array $configuration): string
{
    if (function_exists('buildGeneratedPageCodeFromCanonicalTemplate')) {
        return buildGeneratedPageCodeFromCanonicalTemplate($configuration);
    }

    $templatePath = __DIR__ . '/genera_pagina_visualizzazione.php';
    $source = @file_get_contents($templatePath);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Impossibile leggere il template canonico della pagina generata.');
    }

    $signature = 'function generatePagePhp(array $configuration): string';
    $signatureOffset = strpos($source, $signature);
    if ($signatureOffset === false) {
        throw new RuntimeException('Funzione di generazione non trovata nel template canonico.');
    }

    $openingBrace = strpos($source, '{', $signatureOffset + strlen($signature));
    if ($openingBrace === false) {
        throw new RuntimeException('Blocco della funzione di generazione non valido.');
    }

    $length = strlen($source);
    $depth = 0;
    $closingBrace = null;
    $tokens = token_get_all('<?php ' . substr($source, $openingBrace));
    $offset = $openingBrace;

    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if ($text === '<?php ') {
            continue;
        }

        if ($text === '{') {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
            if ($depth === 0) {
                $closingBrace = $offset;
                break;
            }
        }

        $offset += strlen($text);
    }

    if ($closingBrace === null || $closingBrace >= $length) {
        throw new RuntimeException('Chiusura della funzione di generazione non trovata.');
    }

    $functionCode = substr($source, $signatureOffset, $closingBrace - $signatureOffset + 1);
    $functionCode = preg_replace(
        '/^function generatePagePhp\\b/',
        'function buildGeneratedPageCodeFromCanonicalTemplate',
        $functionCode,
        1
    );

    if (!is_string($functionCode)) {
        throw new RuntimeException('Preparazione del template canonico non riuscita.');
    }

    eval($functionCode);
    if (!function_exists('buildGeneratedPageCodeFromCanonicalTemplate')) {
        throw new RuntimeException('Caricamento del template canonico non riuscito.');
    }

    return buildGeneratedPageCodeFromCanonicalTemplate($configuration);
}

function generatePagePhp(array $configuration): string
{
    return buildGeneratedPageCode($configuration);
}

/* =========================
 * ENDPOINT AJAX
 * ========================= */

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($action !== '') {
    pannellateRequireProjectOrJson($progettoId, $progettoNome);
    $paths = pannellateProjectPaths($progettoNome);

    try {
        if ($action === 'project_status') {
            pannellateJsonResponse([
                'ok' => true,
                'project_id' => $progettoId,
                'project_name' => $progettoNome,
                'project_folder' => $paths['folder'],
                'schema_exists' => pannellateSafeIsFile($paths['schema']),
                'schema_path' => $paths['schema'],
                'pages_path' => $paths['pages'],
            ]);
        }

        if (!pannellateSafeIsFile($paths['schema'])) {
            pannellateJsonResponse([
                'ok' => false,
                'message' => 'schema.sql non trovato. Crearlo dalla voce dedicata del menu prima di proseguire.'
            ], 409);
        }


        if ($action === 'list_configurations') {
            $rows = $db->fetchAll(
                "SELECT
                    pv.id,
                    pv.nome_pagina,
                    pv.nome_file,
                    pv.descrizione,
                    pv.titolo_pagina,
                    pt.codice AS tipo_visualizzazione,
                    pv.stato,
                    pv.data_modifica,
                    pv.data_generazione,
                    t.nome AS tabella_principale
                 FROM pagine_visualizzazione pv
                 LEFT JOIN pagine_visualizzazione_tipo pt ON pt.id = pv.IDtipo
                 JOIN tabelle t ON t.id = pv.IDtabella_principale
                 WHERE pv.IDprogetto = ?
                 ORDER BY pv.data_modifica DESC, pv.nome_pagina",
                [$progettoId]
            );

            pannellateJsonResponse([
                'ok' => true,
                'configurations' => $rows,
            ]);
        }

        if ($action === 'load_configuration') {
            $configurationId = (int) ($_GET['configuration_id'] ?? 0);

            $page = $db->fetch(
                "SELECT pv.*, pt.codice AS tipo_visualizzazione
                 FROM pagine_visualizzazione pv
                 LEFT JOIN pagine_visualizzazione_tipo pt ON pt.id = pv.IDtipo
                 WHERE pv.id = ? AND pv.IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            $mainTableId = (int) ($page['IDtabella_principale'] ?? 0);
            $baseRelations = $mainTableId > 0
                ? pannellateLoadRelations($db, $progettoId, $mainTableId)
                : [];

            $savedTables = $db->fetchAll(
                "SELECT
                    pvt.IDtabella,
                    pvt.tipo_tabella,
                    pvt.alias_sql,
                    pvt.IDforeign_key,
                    pvt.tipo_join,
                    pvt.ordine_join,
                    pvt.selezionata
                 FROM pagine_visualizzazione_tabelle pvt
                 WHERE pvt.IDpagina = ?
                 ORDER BY pvt.ordine_join, pvt.id",
                [$configurationId]
            );

            $savedTablesByFk = [];
            foreach ($savedTables as $savedTable) {
                $fkId = (int) ($savedTable['IDforeign_key'] ?? 0);
                if ($fkId > 0) {
                    $savedTablesByFk[$fkId] = $savedTable;
                }
            }

            $tables = [];
            foreach ($baseRelations as $relation) {
                $fkId = (int) ($relation['fk_id'] ?? 0);
                $savedTable = $savedTablesByFk[$fkId] ?? null;
                $secondaryTableId = (int) ($relation['secondary_table_id'] ?? 0);
                $localFieldName = (string) (
                    $relation['local_field_name']
                    ?? $relation['fk_nome']
                    ?? $relation['fk_name']
                    ?? ''
                );
                $localFieldDescrittivo = trim((string) ($relation['local_field_descrittivo'] ?? ''));
            $tables[] = [
                'IDtabella' => $secondaryTableId,
                'tipo_tabella' => (string) ($relation['type'] ?? 'SECONDARIA'),
                'alias_sql' => (string) ($relation['alias'] ?? ''),
                'IDforeign_key' => $fkId,
                    'tipo_join' => (string) ($savedTable['tipo_join'] ?? $relation['join_type'] ?? 'LEFT'),
                    'ordine_join' => (int) ($savedTable['ordine_join'] ?? 0),
                    'selezionata' => $savedTable ? (int) ($savedTable['selezionata'] ?? 0) : 0,
                    'tabella_nome' => (string) ($relation['secondary_table_name'] ?? ''),
                    'fk_nome' => $localFieldName,
                    'fk_nome_descrittivo' => $localFieldDescrittivo,
                    'relation_label' => trim(
                        (string) ($localFieldName !== '' ? $localFieldName : 'FK')
                        . ($localFieldDescrittivo !== '' ? ' · ' . $localFieldDescrittivo : '')
                        . ' -> ' . (string) ($relation['secondary_table_name'] ?? '')
                    ),
                    'fields' => $secondaryTableId > 0
                        ? pannellateLoadFields($db, $secondaryTableId)
                        : [],
                ];
            }

            $fields = $db->fetchAll(
                "SELECT
                    pvc.id AS field_row_id,
                    pvc.IDpagina_tabella,
                    pvc.IDcampo,
                    pvc.ordine,
                    pvc.etichetta,
                    pvc.nome_qualificato,
                    pvc.visibile_tabella,
                    pvc.visibile_scheda,
                    pvc.visibile_modale,
                    pvc.ordinabile,
                    pvc.ricercabile,
                    pvc.allineamento,
                    pvc.formato_visualizzazione,
                    pvc.larghezza_colonna,
                    pvc.larghezza_bootstrap,
                    pvc.filtro_abilitato,
                    pvc.tipo_filtro,
                    pvc.link_pagina_id,
                    pvc.link_parametro,
                    pvc.link_campo_valore,
                    pvc.percorso_base,
                    pvt.IDforeign_key AS source_fk_id,
                    c.IDtabella,
                    c.tipo AS campo_tipo,
                    c.nome AS campo_nome,
                    t.nome AS tabella_nome
                 FROM pagine_visualizzazione_campi pvc
                 LEFT JOIN pagine_visualizzazione_tabelle pvt ON pvt.id = pvc.IDpagina_tabella
                 JOIN campi c ON c.id = pvc.IDcampo
                 JOIN tabelle t ON t.id = c.IDtabella
                WHERE pvc.IDpagina = ?
                 ORDER BY pvc.ordine, pvc.id",
                [$configurationId]
            );

            foreach ($fields as &$field) {
                // Regola di base: `visibile_modale` va utilizzato come flag informativo,
                // ma non deve essere considerato per il modale di inserimento e modifica.
                $field['field_row_id'] = (int) ($field['field_row_id'] ?? 0);
                $field['table_id'] = (int) ($field['IDtabella'] ?? 0);
                $field['source_table_id'] = (int) ($field['IDtabella'] ?? 0);
                $field['table_name'] = (string) ($field['tabella_nome'] ?? '');
                $field['field_name'] = (string) ($field['campo_nome'] ?? '');
                $basePath = (string) ($field['percorso_base'] ?? '');
                if (!str_starts_with($basePath, '__VIRTUAL_FIELD__')) {
                    continue;
                }

                $virtualConfig = json_decode(
                    substr($basePath, strlen('__VIRTUAL_FIELD__')),
                    true
                );
                if (!is_array($virtualConfig)) {
                    continue;
                }

                $field['expression_type'] = strtoupper((string) ($virtualConfig['expression_type'] ?? 'FIELD'));
                $field['expression'] = (string) ($virtualConfig['expression'] ?? '');
                $field['separator'] = (string) ($virtualConfig['separator'] ?? ' ');
                $field['components'] = array_values((array) ($virtualConfig['components'] ?? []));
                $field['storage_field_id'] = (int) ($field['IDcampo'] ?? 0);
            }
            unset($field);

            $modal = $db->fetch(
                "SELECT *
                 FROM pagine_visualizzazione_modali
                 WHERE IDpagina = ?",
                [$configurationId]
            );

            if ($modal) {
                $modalStoredConfig = json_decode(
                    (string) ($modal['configurazione_campi'] ?? '[]'),
                    true
                ) ?: [];
                $modalStoredIsList = $modalStoredConfig === []
                    || array_keys($modalStoredConfig) === range(0, count($modalStoredConfig) - 1);
                $modalStoredFields = $modalStoredIsList
                    ? $modalStoredConfig
                    : (array) ($modalStoredConfig['fields'] ?? []);

                $modal['enabled'] = true;
                $modal['linked_table_id'] = (int) $modal['IDtabella_collegata'];
                $modal['fk_id'] = (int) $modal['IDforeign_key'];
                $modal['title'] = (string) ($modal['titolo_modale'] ?? 'Dati collegati');
                $modal['view_type'] = (string) ($modal['tipo_visualizzazione'] ?? 'TABELLA');
                $modal['main_field_id'] = (int) $modal['IDcampo_principale'];
                $modal['linked_field_id'] = (int) $modal['IDcampo_collegato'];
                $modal['fields'] = $modalStoredFields;
                $modal['crud_enabled'] = false;
                $modal['crud_add'] = false;
                $modal['crud_edit'] = false;
                $modal['crud_delete'] = false;
            }

            pannellateJsonResponse([
                'ok' => true,
                'page' => $page,
                'tables' => $tables,
                'fields' => $fields,
                'crud' => [
                    'enabled' => !empty($page['crud_abilitato']),
                    'add' => !empty($page['crud_aggiungi']),
                    'edit' => !empty($page['crud_modifica']),
                    'delete' => !empty($page['crud_cancella']),
                ],
                'modal' => $modal ?: null,
            ]);
        }

        if ($action === 'delete_configuration') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $configurationId = (int) ($payload['configuration_id'] ?? 0);

            if ($configurationId <= 0) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non valida.'
                ], 422);
            }

            $page = $db->fetch(
                "SELECT id, nome_pagina, nome_file, percorso_file
                 FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            /*
             * Controllo preventivo del file. Se esiste ma non è eliminabile,
             * la cancellazione non parte, per evitare configurazioni orfane.
             */
            $storedPath = trim((string) ($page['percorso_file'] ?? ''));
            $safeFilePath = null;

            if ($storedPath !== '') {
                $realPages = realpath($paths['pages']);
                $realFile = realpath($storedPath);

                if ($realPages !== false && $realFile !== false) {
                    $allowedPrefix = rtrim($realPages, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR;

                    if (!str_starts_with($realFile, $allowedPrefix)) {
                        pannellateJsonResponse([
                            'ok' => false,
                            'message' => 'Il file generato non appartiene alla cartella pages del progetto.'
                        ], 409);
                    }

                    if (is_file($realFile) && !is_writable($realFile)) {
                        pannellateJsonResponse([
                            'ok' => false,
                            'message' => 'Il file PHP generato non è eliminabile: controllare i permessi.'
                        ], 409);
                    }

                    $safeFilePath = $realFile;
                } elseif (is_file($storedPath)) {
                    pannellateJsonResponse([
                        'ok' => false,
                        'message' => 'Il percorso del file generato non è considerato sicuro.'
                    ], 409);
                }
            }

            $db->beginTransaction();

            try {
                /*
                 * Cancellazione esplicita di tutti i dati collegati.
                 * Non dipende dalla presenza di ON DELETE CASCADE.
                 */
                $deletedModal = $db->execute(
                    "DELETE FROM pagine_visualizzazione_modali
                     WHERE IDpagina = ?",
                    [$configurationId]
                );

                $deletedFields = $db->execute(
                    "DELETE FROM pagine_visualizzazione_campi
                     WHERE IDpagina = ?",
                    [$configurationId]
                );

                $deletedTables = $db->execute(
                    "DELETE FROM pagine_visualizzazione_tabelle
                     WHERE IDpagina = ?",
                    [$configurationId]
                );

                /*
                 * I collegamenti provenienti da altre pagine vengono
                 * disattivati prima di eliminare la pagina destinazione.
                 */
                $removedLinks = $db->execute(
                    "UPDATE pagine_visualizzazione_campi
                     SET link_pagina_id = NULL,
                         link_parametro = NULL,
                         link_campo_valore = NULL
                     WHERE link_pagina_id = ?",
                    [$configurationId]
                );

                $deletedPage = $db->execute(
                    "DELETE FROM pagine_visualizzazione
                     WHERE id = ? AND IDprogetto = ?",
                    [$configurationId, $progettoId]
                );

                if (!$deletedPage) {
                    throw new RuntimeException('La pagina non è stata eliminata.');
                }

                $fileDeleted = false;
                if ($safeFilePath !== null && is_file($safeFilePath)) {
                    if (!unlink($safeFilePath)) {
                        throw new RuntimeException(
                            'Impossibile eliminare il file PHP generato. Nessun dato è stato cancellato.'
                        );
                    }
                    $fileDeleted = true;
                }

                $db->commit();

                pannellateJsonResponse([
                    'ok' => true,
                    'message' => 'Pagina, dati collegati e file PHP eliminati correttamente.',
                    'file_deleted' => $fileDeleted,
                    'deleted' => [
                        'modal' => (int) $deletedModal,
                        'fields' => (int) $deletedFields,
                        'tables' => (int) $deletedTables,
                        'incoming_links' => (int) $removedLinks,
                    ],
                ]);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }

        if ($action === 'rename_configuration') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $configurationId = (int) ($payload['configuration_id'] ?? 0);
            $newPageName = trim((string) ($payload['new_page_name'] ?? ''));
            $newFileName = sanitizeFileName((string) ($payload['new_file_name'] ?? ''));

            if ($configurationId <= 0 || $newPageName === '') {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Indicare un nome pagina valido.'
                ], 422);
            }

            if ($newFileName === '') {
                $newFileName = sanitizeFileName($newPageName);
            }

            $page = $db->fetch(
                "SELECT id, nome_pagina, nome_file, percorso_file
                 FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            $duplicate = (int) $db->fetchColumn(
                "SELECT COUNT(*)
                 FROM pagine_visualizzazione
                 WHERE IDprogetto = ?
                   AND id <> ?
                   AND (LOWER(nome_pagina) = LOWER(?) OR LOWER(nome_file) = LOWER(?))",
                [$progettoId, $configurationId, $newPageName, $newFileName]
            );

            if ($duplicate > 0) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Esiste già una pagina con lo stesso nome o nome file.'
                ], 409);
            }

            $oldFileName = (string) $page['nome_file'];
            $oldStoredPath = trim((string) ($page['percorso_file'] ?? ''));
            $newPath = rtrim($paths['pages'], DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . $newFileName;

            if (
                $oldFileName !== $newFileName
                && is_file($newPath)
            ) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Nella cartella pages esiste già il file ' . $newFileName . '.'
                ], 409);
            }

            $oldSafePath = null;
            if ($oldStoredPath !== '') {
                $realPages = realpath($paths['pages']);
                $realOldFile = realpath($oldStoredPath);

                if ($realPages !== false && $realOldFile !== false) {
                    $allowedPrefix = rtrim($realPages, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR;

                    if (!str_starts_with($realOldFile, $allowedPrefix)) {
                        pannellateJsonResponse([
                            'ok' => false,
                            'message' => 'Il file attuale non appartiene alla cartella pages del progetto.'
                        ], 409);
                    }

                    $oldSafePath = $realOldFile;
                }
            }

            /*
             * Individua prima tutti i file generati che collegano questa pagina.
             * Il loro contenuto contiene il nome file risolto in fase di generazione.
             */
            $referencingPages = $db->fetchAll(
                "SELECT DISTINCT pv.id, pv.percorso_file
                 FROM pagine_visualizzazione_campi pvc
                 JOIN pagine_visualizzazione pv ON pv.id = pvc.IDpagina
                 WHERE pvc.link_pagina_id = ?
                   AND pv.IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            $modifiedLinkFiles = [];
            $renamedPhysicalFile = false;

            $db->beginTransaction();

            try {
                if (
                    $oldSafePath !== null
                    && is_file($oldSafePath)
                    && $oldFileName !== $newFileName
                ) {
                    if (!rename($oldSafePath, $newPath)) {
                        throw new RuntimeException('Impossibile rinominare il file PHP generato.');
                    }
                    $renamedPhysicalFile = true;
                }

                foreach ($referencingPages as $reference) {
                    $referencePath = trim((string) ($reference['percorso_file'] ?? ''));
                    if ($referencePath === '' || !is_file($referencePath)) {
                        continue;
                    }

                    $content = file_get_contents($referencePath);
                    if ($content === false) {
                        throw new RuntimeException(
                            'Impossibile leggere un file che contiene collegamenti alla pagina.'
                        );
                    }

                    $updatedContent = str_replace(
                        [
                            var_export($oldFileName, true),
                            '"' . $oldFileName . '"',
                            "'" . $oldFileName . "'",
                        ],
                        [
                            var_export($newFileName, true),
                            '"' . $newFileName . '"',
                            "'" . $newFileName . "'",
                        ],
                        $content
                    );

                    if ($updatedContent !== $content) {
                        if (file_put_contents($referencePath, $updatedContent, LOCK_EX) === false) {
                            throw new RuntimeException(
                                'Impossibile aggiornare un file che collega la pagina rinominata.'
                            );
                        }
                        $modifiedLinkFiles[] = $referencePath;
                    }
                }

                $db->execute(
                    "UPDATE pagine_visualizzazione
                     SET nome_pagina = ?,
                         nome_file = ?,
                         percorso_file = ?,
                         data_modifica = CURRENT_TIMESTAMP
                     WHERE id = ? AND IDprogetto = ?",
                    [
                        $newPageName,
                        $newFileName,
                        $oldSafePath !== null ? $newPath : $oldStoredPath,
                        $configurationId,
                        $progettoId,
                    ]
                );

                $db->commit();

                pannellateJsonResponse([
                    'ok' => true,
                    'message' => 'Pagina rinominata e collegamenti aggiornati correttamente.',
                    'new_page_name' => $newPageName,
                    'new_file_name' => $newFileName,
                    'file_renamed' => $renamedPhysicalFile,
                    'updated_link_files' => count($modifiedLinkFiles),
                ]);
            } catch (Throwable $e) {
                $db->rollBack();

                /*
                 * Ripristino del nome fisico in caso di errore successivo.
                 */
                if (
                    $renamedPhysicalFile
                    && is_file($newPath)
                    && !is_file((string) $oldSafePath)
                ) {
                    @rename($newPath, (string) $oldSafePath);
                }

                /*
                 * Ripristina nei file già modificati il vecchio collegamento.
                 */
                foreach ($modifiedLinkFiles as $modifiedPath) {
                    if (!is_file($modifiedPath)) continue;
                    $content = file_get_contents($modifiedPath);
                    if ($content === false) continue;
                    $content = str_replace($newFileName, $oldFileName, $content);
                    @file_put_contents($modifiedPath, $content, LOCK_EX);
                }

                $saveErrorContext = [
                    'operation' => 'rinomina pagina',
                    'page_name' => $newPageName,
                    'file_name' => $newFileName,
                    'file_path' => $oldSafePath !== null ? $newPath : $oldStoredPath,
                ];

                throw $e;
            }
        }

        if ($action === 'copy_configuration') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            $configurationId = (int) ($payload['configuration_id'] ?? 0);

            if ($configurationId <= 0) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non valida.'
                ], 422);
            }

            $page = $db->fetch(
                "SELECT id, nome_pagina, nome_file, percorso_file, descrizione, titolo_pagina, IDtipo, IDtabella_principale,
                        righe_per_pagina, ricerca_abilitata, ordinamento_abilitato, paginazione_abilitata,
                        mostra_dettaglio_modale, crud_abilitato, crud_aggiungi, crud_modifica, crud_cancella,
                        sql_generata
                 FROM pagine_visualizzazione
                 WHERE id = ? AND IDprogetto = ?",
                [$configurationId, $progettoId]
            );

            if (!$page) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Configurazione non trovata.'
                ], 404);
            }

            $oldFileName = (string) $page['nome_file'];
            $oldStoredPath = trim((string) ($page['percorso_file'] ?? ''));
            $baseName = preg_replace('/\.php$/i', '', $oldFileName);
            $copyFileName = sanitizeFileName($baseName . '_copia');
            if ($copyFileName === '') {
                $copyFileName = sanitizeFileName((string) ($page['nome_pagina'] ?? 'copia_pagina'));
            }

            if ($copyFileName === $oldFileName) {
                $copyFileName = sanitizeFileName($baseName . '_copy');
            }

            $copyPath = rtrim($paths['pages'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $copyFileName;
            if (is_file($copyPath)) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Esiste già un file con lo stesso nome nella cartella pages.'
                ], 409);
            }

            $duplicate = (int) $db->fetchColumn(
                "SELECT COUNT(*)
                 FROM pagine_visualizzazione
                 WHERE IDprogetto = ?
                   AND (LOWER(nome_pagina) = LOWER(?) OR LOWER(nome_file) = LOWER(?))",
                [$progettoId, (string) ($page['nome_pagina'] ?? '') . ' copia', $copyFileName]
            );
            if ($duplicate > 0) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'Esiste già una configurazione copiata con lo stesso nome.'
                ], 409);
            }

            $sourcePath = null;
            if ($oldStoredPath !== '') {
                $realPages = realpath($paths['pages']);
                $realOldFile = realpath($oldStoredPath);
                if ($realPages !== false && $realOldFile !== false) {
                    $allowedPrefix = rtrim($realPages, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    if (!str_starts_with($realOldFile, $allowedPrefix)) {
                        pannellateJsonResponse([
                            'ok' => false,
                            'message' => 'Il file sorgente non appartiene alla cartella pages del progetto.'
                        ], 409);
                    }
                    $sourcePath = $realOldFile;
                }
            }

            if ($sourcePath === null) {
                $sourcePath = rtrim($paths['pages'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $oldFileName;
            }

            if (!is_file($sourcePath)) {
                pannellateJsonResponse([
                    'ok' => false,
                    'message' => 'File sorgente non trovato.'
                ], 404);
            }

            $copyPageName = (string) ($page['nome_pagina'] ?? '') . ' copia';
            $copyTitle = trim((string) ($page['titolo_pagina'] ?? '')) !== ''
                ? (string) $page['titolo_pagina'] . ' copia'
                : $copyPageName;

            $db->beginTransaction();
            try {
                if (!copy($sourcePath, $copyPath)) {
                    throw new RuntimeException('Impossibile copiare il file PHP generato.');
                }

                $db->execute(
                    "INSERT INTO pagine_visualizzazione (
                        IDprogetto,
                        nome_pagina,
                        nome_file,
                        descrizione,
                        titolo_pagina,
                        IDtipo,
                        IDtabella_principale,
                        righe_per_pagina,
                        ricerca_abilitata,
                        ordinamento_abilitato,
                        paginazione_abilitata,
                        mostra_dettaglio_modale,
                        crud_abilitato,
                        crud_aggiungi,
                        crud_modifica,
                        crud_cancella,
                        percorso_file,
                        sql_generata,
                        stato,
                        data_generazione,
                        data_modifica
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'GENERATA', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    [
                        $progettoId,
                        $copyPageName,
                        $copyFileName,
                        $page['descrizione'] ?? null,
                        $copyTitle,
                        $page['IDtipo'] ?? null,
                        $page['IDtabella_principale'] ?? null,
                        $page['righe_per_pagina'] ?? null,
                        $page['ricerca_abilitata'] ?? 0,
                        $page['ordinamento_abilitato'] ?? 0,
                        $page['paginazione_abilitata'] ?? 0,
                        $page['mostra_dettaglio_modale'] ?? 0,
                        $page['crud_abilitato'] ?? 0,
                        $page['crud_aggiungi'] ?? 0,
                        $page['crud_modifica'] ?? 0,
                        $page['crud_cancella'] ?? 0,
                        $copyPath,
                        $page['sql_generata'] ?? null,
                    ]
                );

                $newPageId = (int) $db->lastInsertId();

                $pageTables = $db->fetchAll(
                    "SELECT * FROM pagine_visualizzazione_tabelle WHERE IDpagina = ? ORDER BY ordine_join, id",
                    [$configurationId]
                );
                foreach ($pageTables as $row) {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_tabelle (
                            IDpagina, IDtabella, tipo_tabella, alias_sql, IDforeign_key, tipo_join, ordine_join, selezionata
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $newPageId,
                            $row['IDtabella'],
                            $row['tipo_tabella'],
                            $row['alias_sql'],
                            $row['IDforeign_key'],
                            $row['tipo_join'],
                            $row['ordine_join'],
                            $row['selezionata'],
                        ]
                    );
                }

                $pageFields = $db->fetchAll(
                    "SELECT * FROM pagine_visualizzazione_campi WHERE IDpagina = ? ORDER BY ordine, id",
                    [$configurationId]
                );
                foreach ($pageFields as $row) {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_campi (
                            IDpagina, IDpagina_tabella, IDcampo, ordine, etichetta, nome_qualificato,
                            visibile_tabella, visibile_scheda, visibile_modale, ordinabile, ricercabile,
                            allineamento, formato_visualizzazione, larghezza_colonna, larghezza_bootstrap,
                            filtro_abilitato, tipo_filtro, link_pagina_id, link_parametro, link_campo_valore, percorso_base
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $newPageId,
                            $row['IDpagina_tabella'],
                            $row['IDcampo'],
                            $row['ordine'],
                            $row['etichetta'],
                            $row['nome_qualificato'],
                            $row['visibile_tabella'],
                            $row['visibile_scheda'],
                            $row['visibile_modale'],
                            $row['ordinabile'],
                            $row['ricercabile'],
                            $row['allineamento'],
                            $row['formato_visualizzazione'],
                            $row['larghezza_colonna'],
                            $row['larghezza_bootstrap'],
                            $row['filtro_abilitato'],
                            $row['tipo_filtro'],
                            $row['link_pagina_id'],
                            $row['link_parametro'],
                            $row['link_campo_valore'],
                            $row['percorso_base'],
                        ]
                    );
                }

                $pageModal = $db->fetchAll(
                    "SELECT * FROM pagine_visualizzazione_modali WHERE IDpagina = ?",
                    [$configurationId]
                );
                foreach ($pageModal as $row) {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_modali (
                            IDpagina, IDtabella_collegata, IDforeign_key, IDcampo_principale, IDcampo_collegato,
                            titolo_modale, tipo_visualizzazione, configurazione_campi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $newPageId,
                            $row['IDtabella_collegata'],
                            $row['IDforeign_key'],
                            $row['IDcampo_principale'],
                            $row['IDcampo_collegato'],
                            $row['titolo_modale'],
                            $row['tipo_visualizzazione'],
                            $row['configurazione_campi'],
                        ]
                    );
                }

                $db->commit();

                pannellateJsonResponse([
                    'ok' => true,
                    'message' => 'Configurazione copiata correttamente.',
                    'configuration_id' => $newPageId,
                    'file_name' => $copyFileName,
                    'file_path' => $copyPath,
                ]);
            } catch (Throwable $e) {
                $db->rollBack();
                if (is_file($copyPath)) {
                    @unlink($copyPath);
                }
                throw $e;
            }
        }

        if ($action === 'table_details') {
            $tableId = (int) ($_GET['table_id'] ?? 0);
            $table = pannellateLoadTable($db, $progettoId, $tableId);

            if (!$table) {
                pannellateJsonResponse(['ok' => false, 'message' => 'Tabella non valida.'], 404);
            }

            $relations = pannellateLoadRelations($db, $progettoId, $tableId);
            foreach ($relations as &$relation) {
                $relation['fields'] = pannellateLoadFields($db, (int) $relation['secondary_table_id']);
            }
            unset($relation);

            pannellateJsonResponse([
                'ok' => true,
                'table' => $table,
                'fields' => pannellateLoadFields($db, $tableId),
                'relations' => $relations,
            ]);
        }

        if ($action === 'preview') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                pannellateJsonResponse(['ok' => false, 'message' => 'Dati non validi.'], 400);
            }

            $built = buildSqlPreview(
                $db,
                $progettoId,
                (int) ($payload['main_table_id'] ?? 0),
                (array) ($payload['tables'] ?? []),
                (array) ($payload['fields'] ?? [])
            );

            pannellateJsonResponse(['ok' => true, 'sql' => $built['sql']]);
        }

        if ($action === 'save_generate' || $action === 'save_configuration') {
            $saveOnly = $action === 'save_configuration';
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                pannellateJsonResponse(['ok' => false, 'message' => 'Dati non validi.'], 400);
            }

            $pageName = trim((string) ($payload['page_name'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));
            $title = trim((string) ($payload['title'] ?? $pageName));
            $fileName = pannellateSanitizePhpFileName((string) ($payload['file_name'] ?? $pageName));
            $typeId = max(0, (int) ($payload['IDtipo'] ?? $payload['tipo_id'] ?? 0));
            $viewType = normalizeViewTypeCode((string) ($payload['view_type'] ?? ''));
            if ($pageName === '') {
                pannellateJsonResponse(['ok' => false, 'message' => 'Indicare il nome della pagina.'], 422);
            }
            if ($viewType === '') {
                if ($typeId > 0) {
                    $typeRow = $db->fetch(
                        'SELECT codice FROM pagine_visualizzazione_tipo WHERE id = ?',
                        [$typeId]
                    );
                    if ($typeRow) {
                        $viewType = normalizeViewTypeCode((string) ($typeRow['codice'] ?? ''));
                    }
                }
            }

            if (!in_array($viewType, ['SCHEDA_SINGOLA', 'TABELLA_MODALE', 'MASTER_DETAIL'], true)) {
                $viewType = 'TABELLA_MODALE';
            }

            $existingId = max(0, (int) ($payload['configuration_id'] ?? 0));

            $selectedFieldsForBuild = (array) ($payload['fields'] ?? []);
            $selectedTablesForBuild = (array) ($payload['tables'] ?? []);

            $crudRequested = !empty($payload['crud_enabled']);
            $crudConfiguration = buildCrudConfiguration(
                $db,
                $progettoId,
                (int) ($payload['main_table_id'] ?? 0)
            );

            if ($crudRequested && empty($crudConfiguration['available'])) {
                throw new RuntimeException(
                    'CRUD non disponibile: ' . ($crudConfiguration['reason'] ?? 'configurazione non valida')
                );
            }

            if ($crudRequested) {
                $crudPrimaryId = (int) $crudConfiguration['primary_key']['field_id'];
                $primaryPresent = false;

                foreach ($selectedFieldsForBuild as $selectedField) {
                    if ((int) ($selectedField['field_id'] ?? 0) === $crudPrimaryId) {
                        $primaryPresent = true;
                        break;
                    }
                }

                if (!$primaryPresent) {
                    $selectedFieldsForBuild[] = [
                        'field_id' => $crudPrimaryId,
                        'label' => $crudConfiguration['primary_key']['field_name'],
                        'visible_table' => 0,
                        'visible_card' => 0,
                        'visible_modal' => 0,
                        'searchable' => 0,
                        'sortable' => 0,
                        'technical_hidden' => 1,
                    ];
                }
            }

            // Sezione modale rimossa: la generazione si basa solo sulla query e sui campi selezionati.

            $built = buildSqlPreview(
                $db,
                $progettoId,
                (int) ($payload['main_table_id'] ?? 0),
                $selectedTablesForBuild,
                $selectedFieldsForBuild
            );

            if ($crudRequested) {
                $mainTableId = (int) ($payload['main_table_id'] ?? 0);
                $mainTableName = trim((string) (
                    $built['main_table']['nome']
                    ?? $built['main_table']['name']
                    ?? ''
                ));
                if ($mainTableName === '') {
                    throw new RuntimeException('Nome della tabella principale CRUD non disponibile.');
                }
                $mainTableCrudFields = pannellateLoadFields($db, $mainTableId);
                $mainFieldsById = [];
                $mainFieldsByName = [];
                foreach ($mainTableCrudFields as $mainField) {
                    $mainFieldsById[(int) ($mainField['id'] ?? 0)] = $mainField;
                    $mainFieldsByName[(string) ($mainField['nome'] ?? '')] = $mainField;
                }

                $relationsByFkId = [];
                foreach (pannellateLoadRelations($db, $progettoId, $mainTableId) as $relation) {
                    $relationsByFkId[(int) ($relation['fk_id'] ?? 0)] = $relation;
                }

                $primaryKey = $crudConfiguration['primary_key'];
                $primaryField = $mainFieldsById[(int) $primaryKey['field_id']] ?? null;
                if (!$primaryField) {
                    throw new RuntimeException('Metadati della chiave primaria CRUD non disponibili.');
                }

                $orderedCrudFields = [[
                    'field_id' => (int) $primaryField['id'],
                    'table_id' => $mainTableId,
                    'table_name' => $mainTableName,
                    'field_name' => (string) $primaryField['nome'],
                    'label' => (string) $primaryField['nome'],
                    'editable' => false,
                    'insert_visible' => false,
                    'update_visible' => false,
                    'required' => false,
                    'fk' => null,
                            'type' => (string) ($primaryField['tipo'] ?? ''),
                            'default_value' => $primaryField['default_value'] ?? null,
                            'bootstrap_col' => '6',
                        ]];
                $addedCrudFields = [(string) $primaryField['nome'] => true];
                $primaryKeyAlias = '';

                foreach ($built['fields'] as $builtField) {
                    if ((int) ($builtField['field_id'] ?? 0) === (int) $primaryKey['field_id']) {
                        $primaryKeyAlias = (string) ($builtField['output_alias'] ?? '');
                        continue;
                    }

                    $sourceFkId = (int) ($builtField['source_fk_id'] ?? 0);
                    if ($sourceFkId === 0 && (int) ($builtField['table_id'] ?? 0) === $mainTableId) {
                        $mainField = $mainFieldsById[(int) ($builtField['field_id'] ?? 0)] ?? null;
                        if (!$mainField) continue;

                        $fieldName = (string) ($mainField['nome'] ?? '');
                        if ($fieldName === '' || isset($addedCrudFields[$fieldName])) continue;

                        $orderedCrudFields[] = [
                            'field_id' => (int) ($mainField['id'] ?? 0),
                            'table_id' => $mainTableId,
                            'table_name' => $mainTableName,
                            'field_name' => $fieldName,
                            'label' => (string) ($builtField['label'] ?? $fieldName),
                            'editable' => true,
                            'insert_visible' => true,
                            'update_visible' => true,
                            'required' => !(bool) ($mainField['nullable'] ?? false),
                            'fk' => null,
                            'type' => (string) ($mainField['tipo'] ?? ''),
                            'default_value' => $mainField['default_value'] ?? null,
                            'bootstrap_col' => in_array((string) ($builtField['bootstrap_col'] ?? '6'), ['3','4','6','8','12'], true)
                                ? (string) $builtField['bootstrap_col']
                                : '6',
                        ];
                        $addedCrudFields[$fieldName] = true;
                        continue;
                    }

                    $relation = $relationsByFkId[$sourceFkId] ?? null;
                    if (!$relation || ($relation['direction'] ?? '') !== 'OUT') continue;

                    $pair = (array) (($relation['pairs'][0] ?? null) ?: []);
                    $localFieldName = trim((string) ($pair['local'] ?? $pair['local_field_name'] ?? ''));
                    $referencedFieldName = trim((string) ($pair['referenced'] ?? $pair['referenced_field_name'] ?? ''));
                    $mainField = $mainFieldsByName[$localFieldName] ?? null;
                    if (!$mainField || $referencedFieldName === '' || isset($addedCrudFields[$localFieldName])) continue;

                    $orderedCrudFields[] = [
                        'field_id' => (int) ($mainField['id'] ?? 0),
                        'table_id' => $mainTableId,
                        'table_name' => $mainTableName,
                        'field_name' => $localFieldName,
                        'label' => (string) ($builtField['label'] ?? $localFieldName),
                        'editable' => true,
                        'insert_visible' => true,
                        'update_visible' => true,
                        'required' => !(bool) ($mainField['nullable'] ?? false),
                        'fk' => [
                            'referenced_table_name' => (string) ($relation['secondary_table_name'] ?? ''),
                            'referenced_field_name' => $referencedFieldName,
                            'description_field_name' => (string) ($builtField['field_name'] ?? ''),
                        ],
                        'type' => (string) ($mainField['tipo'] ?? ''),
                        'default_value' => $mainField['default_value'] ?? null,
                        'bootstrap_col' => in_array((string) ($builtField['bootstrap_col'] ?? '6'), ['3','4','6','8','12'], true)
                            ? (string) $builtField['bootstrap_col']
                            : '6',
                    ];
                    $addedCrudFields[$localFieldName] = true;
                }

                if ($primaryKeyAlias === '') {
                    throw new RuntimeException('Impossibile includere la chiave primaria nella query CRUD.');
                }

                $crudConfiguration = [
                    'available' => true,
                    'reason' => '',
                    'table_name' => $mainTableName,
                    'primary_key' => $primaryKey,
                    'fields' => $orderedCrudFields,
                    'primary_key_alias' => $primaryKeyAlias,
                ];
            }

            $fieldAliasByQualifiedName = [];
            foreach ($built['fields'] as $builtField) {
                $fieldAliasByQualifiedName[$builtField['qualified_name']] = $builtField['output_alias'];
            }

            foreach ($built['fields'] as &$builtField) {
                $builtField['link_target_file'] = '';
                $builtField['link_value_alias'] = '';

                if (!empty($builtField['link_page_id'])) {
                    $linkedPage = $db->fetch(
                        "SELECT nome_file FROM pagine_visualizzazione
                         WHERE id = ? AND IDprogetto = ?",
                        [$builtField['link_page_id'], $progettoId]
                    );
                    if ($linkedPage) {
                        $builtField['link_target_file'] = $linkedPage['nome_file'];
                        $valueField = $builtField['link_value_field'] ?: $builtField['qualified_name'];
                        $builtField['link_value_alias'] = $fieldAliasByQualifiedName[$valueField] ?? '';
                    }
                }
            }
            unset($builtField);

            $modalConfig = null;

            $generatorMetadata = resolveGeneratedPageMetadata($viewType);

            $configuration = [
                'title' => $title ?: $pageName,
                'description' => $description,
                'view_type' => $viewType,
                'generator_version' => $generatorMetadata['version'],
                'sql' => $built['sql'],
                'fields' => $built['fields'],
                'rows_per_page' => max(1, min(500, (int) ($payload['rows_per_page'] ?? 25))),
                'search_enabled' => !empty($payload['search_enabled']),
                'sort_enabled' => !empty($payload['sort_enabled']),
                'pagination_enabled' => !empty($payload['pagination_enabled']),
                'modal_enabled' => false,
                'modal_config' => null,
                'modal_crud_config' => [],
                'crud_enabled' => $crudRequested,
                'crud_add' => $crudRequested && !empty($payload['crud_add']),
                'crud_edit' => $crudRequested && !empty($payload['crud_edit']),
                'crud_delete' => $crudRequested && !empty($payload['crud_delete']),
                'crud_config' => $crudConfiguration,
            ];

            $targetPath = $paths['pages'] . DIRECTORY_SEPARATOR . $fileName;
            $configuration['generated_page_version'] = resolveNextGeneratedPageVersion($targetPath);
            $generatedCode = $saveOnly ? '' : repairGeneratedDisplayValueBlock(generatePagePhp($configuration));

            $db->beginTransaction();

            try {
                $saveErrorContext = [
                    'operation' => ((int) ($payload['configuration_id'] ?? 0)) > 0
                        ? 'aggiornamento pagina'
                        : 'nuova pagina',
                    'page_name' => $pageName,
                    'file_name' => $fileName,
                    'file_path' => $targetPath,
                ];

                $existingId = max(0, (int) ($payload['configuration_id'] ?? 0));
                if ($existingId <= 0) {
                    $existingId = (int) $db->fetchColumn(
                        "SELECT id
                         FROM pagine_visualizzazione
                         WHERE IDprogetto = ?
                           AND (
                               LOWER(nome_pagina) = LOWER(?)
                               OR LOWER(nome_file) = LOWER(?)
                               OR LOWER(percorso_file) = LOWER(?)
                           )
                         ORDER BY id DESC
                         LIMIT 1",
                        [$progettoId, $pageName, $fileName, $targetPath]
                    );
                }

                if ($existingId > 0) {
                    $exists = $db->fetchColumn(
                        'SELECT COUNT(*) FROM pagine_visualizzazione WHERE id = ? AND IDprogetto = ?',
                        [$existingId, $progettoId]
                    );
                    if (!$exists) {
                        throw new RuntimeException('Configurazione da aggiornare non trovata.');
                    }

                    $db->execute(
                        "UPDATE pagine_visualizzazione
                         SET nome_pagina = ?,
                              nome_file = ?,
                              descrizione = ?,
                              IDtipo = ?,
                              IDtabella_principale = ?,
                              titolo_pagina = ?,
                             righe_per_pagina = ?,
                             ricerca_abilitata = ?,
                             ordinamento_abilitato = ?,
                             paginazione_abilitata = ?,
                             mostra_dettaglio_modale = ?,
                             crud_abilitato = ?,
                             crud_aggiungi = ?,
                             crud_modifica = ?,
                             crud_cancella = ?,
                             percorso_file = ?,
                             sql_generata = ?,
                             stato = 'GENERATA',
                             data_generazione = CURRENT_TIMESTAMP
                         WHERE id = ? AND IDprogetto = ?",
                        [
                             $pageName,
                             $fileName,
                             $description,
                             $typeId > 0 ? $typeId : null,
                             (int) $built['main_table']['id'],
                             $configuration['title'],
                            $configuration['rows_per_page'],
                            (int) $configuration['search_enabled'],
                            (int) $configuration['sort_enabled'],
                            (int) $configuration['pagination_enabled'],
                            (int) $configuration['modal_enabled'],
                            (int) $configuration['crud_enabled'],
                            (int) $configuration['crud_add'],
                            (int) $configuration['crud_edit'],
                            (int) $configuration['crud_delete'],
                            $targetPath,
                            $built['sql'],
                            $existingId,
                            $progettoId,
                        ]
                    );
                    $pageId = $existingId;

                    $db->execute(
                        'DELETE FROM pagine_visualizzazione_campi WHERE IDpagina = ?',
                        [$pageId]
                    );
                    $db->execute(
                        'DELETE FROM pagine_visualizzazione_tabelle WHERE IDpagina = ?',
                        [$pageId]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione (
                            IDprogetto,
                            nome_pagina,
                            nome_file,
                            descrizione,
                            IDtipo,
                            IDtabella_principale,
                            titolo_pagina,
                            righe_per_pagina,
                            ricerca_abilitata,
                            ordinamento_abilitato,
                            paginazione_abilitata,
                            mostra_dettaglio_modale,
                            crud_abilitato,
                            crud_aggiungi,
                            crud_modifica,
                            crud_cancella,
                            percorso_file,
                            sql_generata,
                            stato,
                            data_generazione
                          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'GENERATA', CURRENT_TIMESTAMP)",
                        [
                            $progettoId,
                            $pageName,
                            $fileName,
                            $description,
                            $typeId > 0 ? $typeId : null,
                            (int) $built['main_table']['id'],
                            $configuration['title'],
                            $configuration['rows_per_page'],
                            (int) $configuration['search_enabled'],
                            (int) $configuration['sort_enabled'],
                            (int) $configuration['pagination_enabled'],
                            (int) $configuration['modal_enabled'],
                            (int) $configuration['crud_enabled'],
                            (int) $configuration['crud_add'],
                            (int) $configuration['crud_edit'],
                            (int) $configuration['crud_delete'],
                            $targetPath,
                            $built['sql'],
                        ]
                    );
                    $pageId = (int) $db->lastInsertId();
                }

                $pageTableIds = [];

                foreach ($built['tables'] as $order => $table) {
                    $tableKey = (int) $table['id'] . ':' . max(0, (int) ($table['fk_id'] ?? 0));
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_tabelle (
                            IDpagina,
                            IDtabella,
                            tipo_tabella,
                            alias_sql,
                            IDforeign_key,
                            tipo_join,
                            ordine_join,
                            selezionata
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                        [
                            $pageId,
                            (int) $table['id'],
                            $table['type'],
                            $table['alias'],
                            $table['fk_id'],
                            $table['join_type'] ?: 'LEFT',
                            $order,
                        ]
                    );
                    $pageTableIds[$tableKey] = (int) $db->lastInsertId();
                }

                foreach ($built['fields'] as $field) {
                    $fieldTableKey = (int) $field['table_id'] . ':' . max(0, (int) ($field['source_fk_id'] ?? 0));
                    $pageTableId = $pageTableIds[$fieldTableKey] ?? 0;
                    if (!$pageTableId) {
                        continue;
                    }

                    $isVirtualField = !empty($field['expression_type'])
                        && $field['expression_type'] !== 'FIELD';
                    $fieldIdForStorage = $isVirtualField
                        ? ensureVirtualStorageField(
                            $db,
                            (int) $field['table_id'],
                            $pageId,
                            (int) $field['order']
                        )
                        : (int) ($field['field_id'] ?: ($field['storage_field_id'] ?? 0));
                    if ($fieldIdForStorage <= 0) {
                        continue;
                    }

                    $basePathForStorage = $field['base_path'] ?: null;
                    if ($isVirtualField) {
                        $basePathForStorage = '__VIRTUAL_FIELD__' . json_encode([
                            'expression_type' => $field['expression_type'],
                            'expression' => $field['expression'] ?? '',
                            'separator' => $field['separator'] ?? ' ',
                            'components' => $field['components'] ?? [],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }

                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_campi (
                            IDpagina,
                            IDpagina_tabella,
                            IDcampo,
                            ordine,
                            etichetta,
                            nome_qualificato,
                            visibile_tabella,
                            visibile_scheda,
                            visibile_modale,
                            ordinabile,
                            ricercabile,
                            allineamento,
                            formato_visualizzazione,
                            larghezza_colonna,
                            larghezza_bootstrap,
                            filtro_abilitato,
                            tipo_filtro,
                            link_pagina_id,
                            link_parametro,
                            link_campo_valore,
                            percorso_base
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $pageId,
                            $pageTableId,
                            $fieldIdForStorage,
                            (int) $field['order'],
                            $field['label'],
                            $field['qualified_name'],
                            (int) $field['visible_table'],
                            (int) $field['visible_card'],
                            (int) $field['visible_modal'],
                            (int) $field['sortable'],
                            (int) $field['searchable'],
                            $field['alignment'],
                            (($formatValue = trim((string) ($field['format'] ?? ''))) !== '')
                                ? $formatValue
                                : (defaultVisualFormatByType((string) ($field['type'] ?? $field['field_type'] ?? '')) ?: null),
                            $field['width'] !== '' ? $field['width'] : null,
                            $field['bootstrap_col'],
                            (int) $field['filter_enabled'],
                            $field['filter_type'],
                            $field['link_page_id'] ?: null,
                            $field['link_parameter'] ?: null,
                            $field['link_value_field'] ?: null,
                            $basePathForStorage,
                        ]
                    );
                }

                $savedFieldRows = $db->fetchAll(
                    "SELECT
                        pvc.IDpagina_tabella,
                        pvc.IDcampo,
                        pvc.ordine,
                        pvc.visibile_tabella,
                        pvc.visibile_scheda,
                        pvc.visibile_modale,
                        pvc.ordinabile,
                        pvc.ricercabile,
                        pvc.etichetta,
                        pvc.nome_qualificato
                     FROM pagine_visualizzazione_campi pvc
                     WHERE pvc.IDpagina = ?
                     ORDER BY pvc.ordine, pvc.id",
                    [$pageId]
                );

                $expectedFieldRows = [];
                foreach ($built['fields'] as $field) {
                    $expectedFieldRows[] = [
                        'IDpagina_tabella' => (int) ($pageTableIds[(int) $field['table_id'] . ':' . max(0, (int) ($field['source_fk_id'] ?? 0))] ?? 0),
                        'IDcampo' => (int) ($field['field_id'] ?: ($field['storage_field_id'] ?? 0)),
                        'ordine' => (int) $field['order'],
                        'visibile_tabella' => (int) ($field['visible_table'] ?? 0),
                        'visibile_scheda' => (int) ($field['visible_card'] ?? 0),
                        'visibile_modale' => (int) ($field['visible_modal'] ?? 0),
                        'ordinabile' => (int) ($field['sortable'] ?? 0),
                        'ricercabile' => (int) ($field['searchable'] ?? 0),
                        'etichetta' => (string) ($field['label'] ?? ''),
                        'nome_qualificato' => (string) ($field['qualified_name'] ?? ''),
                    ];
                }

                if (count($savedFieldRows) !== count($expectedFieldRows)) {
                    throw new RuntimeException('Verifica salvataggio fallita: numero campi non coerente.');
                }

                foreach ($expectedFieldRows as $index => $expectedRow) {
                    $savedRow = $savedFieldRows[$index] ?? null;
                    if (!$savedRow) {
                        throw new RuntimeException('Verifica salvataggio fallita: riga campo mancante.');
                    }

                    foreach ([
                        'IDpagina_tabella',
                        'IDcampo',
                        'ordine',
                        'visibile_tabella',
                        'visibile_scheda',
                        'visibile_modale',
                        'ordinabile',
                        'ricercabile',
                    ] as $column) {
                        if ((int) ($savedRow[$column] ?? -1) !== (int) ($expectedRow[$column] ?? -2)) {
                            throw new RuntimeException(
                                'Verifica salvataggio fallita: valore non coerente per ' . $column . '.'
                            );
                        }
                    }

                    if ((string) ($savedRow['etichetta'] ?? '') !== (string) ($expectedRow['etichetta'] ?? '')) {
                        throw new RuntimeException('Verifica salvataggio fallita: etichetta non coerente.');
                    }
                    if ((string) ($savedRow['nome_qualificato'] ?? '') !== (string) ($expectedRow['nome_qualificato'] ?? '')) {
                        throw new RuntimeException('Verifica salvataggio fallita: nome qualificato non coerente.');
                    }
                }

                $db->execute(
                    "DELETE FROM pagine_visualizzazione_modali WHERE IDpagina = ?",
                    [$pageId]
                );

                if ($configuration['modal_enabled'] && $modalConfig) {
                    $db->execute(
                        "INSERT INTO pagine_visualizzazione_modali (
                            IDpagina,
                            IDtabella_collegata,
                            IDforeign_key,
                            IDcampo_principale,
                            IDcampo_collegato,
                            titolo_modale,
                            tipo_visualizzazione,
                            configurazione_campi
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $pageId,
                            $modalConfig['linked_table_id'],
                            $modalConfig['fk_id'],
                            $modalConfig['main_field_id'],
                            $modalConfig['linked_field_id'],
                            $modalConfig['title'],
                            $modalConfig['view_type'],
                            json_encode(
                                [
                                    'fields' => $modalConfig['fields'],
                                ],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                        ]
                    );
                }

                if (!$saveOnly) {
                    writeGeneratedPageToProjectFolder($targetPath, $generatedCode);
                }

                $db->commit();

                if ($saveOnly) {
                    $analysisRows = [];
                    foreach (array_reverse($built['fields']) as $field) {
                        $analysisRows[] = [
                            'field_name' => (string) ($field['field_name'] ?? ''),
                            'label' => (string) ($field['label'] ?? ''),
                            'visible_table' => (int) ($field['visible_table'] ?? 0),
                            'visible_card' => (int) ($field['visible_card'] ?? 0),
                            'visible_modal' => (int) ($field['visible_modal'] ?? 0),
                        ];
                    }

                    pannellateJsonResponse([
                        'ok' => true,
                        'message' => 'Configurazione salvata e verificata.',
                        'configuration_id' => $pageId,
                        'file_name' => $fileName,
                        'generated_page_version' => $configuration['generated_page_version'] ?? null,
                        'verification' => [
                            'status' => 'SALVATAGGIO_COMPLETATO',
                            'analysis' => $analysisRows,
                        ],
                    ]);
                }

                pannellateJsonResponse([
                    'ok' => true,
                    'message' => 'Configurazione salvata e pagina PHP generata.',
                    'configuration_id' => $pageId,
                    'file_name' => $fileName,
                    'file_path' => $targetPath,
                    'generated_page_version' => $configuration['generated_page_version'] ?? null,
                    'sql' => $built['sql'],
                ]);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }

        pannellateJsonResponse(['ok' => false, 'message' => 'Azione non riconosciuta.'], 404);
    } catch (Throwable $e) {
        creatorePaginaWriteErrorLog(sprintf(
            'ECCEZIONE action=%s errore=%s file=%s riga=%d trace=%s',
            preg_replace('/[^a-zA-Z0-9_-]/', '', $action),
            str_replace(["\r", "\n"], ' ', $e->getMessage()),
            $e->getFile(),
            $e->getLine(),
            str_replace(["\r", "\n"], ' ', $e->getTraceAsString())
        ));

        $message = $e->getMessage();
        if (
            str_contains($message, "Unknown column 'pvc.") ||
            str_contains($message, 'larghezza_bootstrap') ||
            str_contains($message, 'filtro_abilitato') ||
            str_contains($message, 'tipo_filtro') ||
            str_contains($message, 'link_pagina_id')
        ) {
            $message =
                'Database metadati non aggiornato. '
                . 'Eseguire una sola volta migrazione_visualizzazione_altervista_v7_3.sql.';
        } elseif (str_contains($message, 'Duplicate entry') || (int) $e->getCode() === 1062) {
            $message = formatSaveDuplicateError($e, $saveErrorContext);
        }

        pannellateJsonResponse([
            'ok' => false,
            'message' => $message,
        ], 500);
    }
}

/* =========================
 * RENDER PAGINA
 * ========================= */

/*
 * Il rendering completo deve avvenire dal layout principale.
 * L'accesso diretto è consentito soltanto agli endpoint AJAX gestiti sopra.
 */
if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__)) {
    header('Location: ../index.php?page=scheda_singola');
    exit;
}

$paths = pannellateProjectPaths($progettoNome);
$schemaExists = $progettoId > 0 && $progettoNome !== '' && pannellateSafeIsFile($paths['schema']);

$tables = [];
if ($progettoId > 0) {
    $tables = $db->fetchAll(
        'SELECT id, nome, descrizione
         FROM tabelle
         WHERE IDprogetto = ?
         ORDER BY ordine, nome',
        [$progettoId]
    );
}
?>

<style>
.generator-shell { max-width: 1600px; margin: 0 auto; }
.generator-step { border: 1px solid #dee2e6; border-radius: .75rem; background: #fff; }
.generator-step + .generator-step { margin-top: 1rem; }
.generator-step-header { padding: .9rem 1rem; border-bottom: 1px solid #dee2e6; background: #f8f9fa; border-radius: .75rem .75rem 0 0; }
.generator-step-body { padding: 1rem; }
.generator-fab {
    border-radius: 999px;
    box-shadow: 0 .75rem 1.75rem rgba(0, 0, 0, .18);
    padding: .9rem 1.2rem;
}
.generator-fab i {
    font-size: 1.05rem;
}
.generator-actions {
    display: flex;
    flex-direction: column;
    gap: .75rem;
}
.generator-actions .generator-fab {
    position: static;
    width: 100%;
}
.field-list { min-height: 180px; max-height: 460px; overflow: auto; }
.field-card { border: 1px solid #dee2e6; border-radius: .5rem; padding: .65rem; background: #fff; cursor: grab; }
.field-card + .field-card { margin-top: .5rem; }
.field-card.dragging { opacity: .45; }
.field-meta { font-size: .75rem; color: #6c757d; }
.selected-field-list { min-height: 180px; max-height: none; overflow-y: hidden; overflow-x: visible; border: 2px dashed #adb5bd; border-radius: .75rem; padding: .75rem; background: #f8f9fa; }
.selected-item {
    display: block;
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: .6rem;
    background: #fff;
    padding: 1rem;
    margin-bottom: .85rem;
    overflow: visible;
    box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.selected-item.drag-over { border-color: #0d6efd; }
.concat-builder-list { min-height: 280px; max-height: 430px; overflow: auto; border: 1px solid #dee2e6; border-radius: .6rem; padding: .75rem; background: #f8f9fa; }
.concat-choice { border: 1px solid #dee2e6; border-radius: .5rem; padding: .55rem .65rem; background: #fff; cursor: grab; }
.concat-choice + .concat-choice { margin-top: .5rem; }
.concat-choice.is-used { opacity: .45; cursor: not-allowed; }
.concat-choice.dragging { opacity: .45; }
.concat-choice-type { font-size: .72rem; color: #6c757d; text-transform: uppercase; }
.concat-selected-list { min-height: 280px; border: 2px dashed #adb5bd; border-radius: .6rem; padding: .75rem; background: #fff; }
.concat-token { border: 1px solid #ced4da; border-radius: .5rem; padding: .55rem .65rem; background: #fff; cursor: grab; }
.concat-token + .concat-token { margin-top: .5rem; }
.concat-token.drag-over { border-color: #0d6efd; }
.concat-token-separator { background: #fff8e1; }
.concat-separator-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
.concat-example { min-height: 48px; border: 1px solid #dee2e6; border-radius: .6rem; padding: .75rem; background: #fff; overflow-wrap: anywhere; }
.sql-preview { min-height: 220px; max-height: 420px; overflow: auto; white-space: pre; background: #111827; color: #e5e7eb; border-radius: .65rem; padding: 1rem; font-family: Consolas, monospace; font-size: .82rem; }
.relation-card { border: 1px solid #dee2e6; border-radius: .6rem; padding: .75rem; margin-bottom: .65rem; }
.relation-join-description { max-width: 280px; }
.schema-stop { border-left: 5px solid #dc3545; }
.sticky-summary { position: sticky; top: 1rem; }


/* Layout dinamico - Campi da visualizzare */
#selectedFields{
    width: 100%;
    max-width: 100%;
    height: auto;
    max-height: none !important;
    box-sizing: border-box;
    overflow-y: visible !important;
    overflow-x: visible !important;
    -webkit-overflow-scrolling: touch;
}

.selected-item-chevron{
    transition: transform .2s ease;
}

.selected-item-chevron{
    font-size: .95rem;
}

.selected-item-header[aria-expanded="true"] .selected-item-chevron{
    transform: rotate(180deg);
}

@media (max-width:1200px){
    #selectedFields{
        height: auto !important;
        max-height: none !important;
        overflow-x: visible !important;
        overflow-y: visible !important;
    }
}

@media (max-width:575.98px){
    .generator-shell{
        padding-left: .75rem;
        padding-right: .75rem;
    }

    .generator-step-body{
        padding: .85rem;
    }

    .generator-fab{
        width: 100%;
        justify-content: center;
    }

    .generator-actions{
        gap: .6rem;
    }
}
</style>

<div class="container-fluid py-3 generator-shell">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="mb-1">
                <i class="bi bi-layout-text-window-reverse me-2"></i>
                <span id="pageHeaderDescription"><?= htmlspecialchars($pageHeaderDescription, ENT_QUOTES, 'UTF-8') ?></span>
            </h3>
            <div class="mt-2">
                <span class="badge text-bg-secondary">
                    Generatore Scheda Singola - versione <?= htmlspecialchars(
                        SCHEDA_SINGOLA_GENERATOR_VERSION,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
            </div>
        </div>
        <span class="badge <?= $schemaExists ? 'text-bg-success' : 'text-bg-danger' ?> fs-6">
            <?= $schemaExists ? 'schema.sql presente' : 'schema.sql assente' ?>
        </span>
    </div>

    <?php if ($progettoId <= 0 || $progettoNome === ''): ?>
        <div class="alert alert-danger">Nessun progetto attivo selezionato.</div>
    <?php elseif (!$schemaExists): ?>
        <div class="alert alert-danger schema-stop">
            <h5 class="alert-heading">Procedura bloccata</h5>
            <p class="mb-2">
                Il file <code>schema.sql</code> non è stato individuato nei percorsi verificati:
            </p>
            <ul class="mb-3">
                <?php foreach ($paths['candidates'] as $candidate): ?>
                    <li>
                        <code><?= htmlspecialchars(
                            $candidate . DIRECTORY_SEPARATOR . 'schema.sql',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></code>
                    </li>
                <?php endforeach; ?>
            </ul>
            <hr>
            <p class="mb-0">
                Se il file esiste, controllare che il progetto attivo in sessione corrisponda alla cartella
                <strong><?= htmlspecialchars($paths['folder'], ENT_QUOTES, 'UTF-8') ?></strong>.
            </p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-12">
                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>Tipologia pannellata</strong>
                    </div>
                    <div class="generator-step-body">
                        <?php if ($progettoId > 0 && $progettoNome !== ''): ?>
                            <div class="alert alert-primary mb-3">
                                Progetto attivo: <strong><?= htmlspecialchars($progettoNome, ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger mb-3">
                                Nessun progetto attivo selezionato.
                            </div>
                        <?php endif; ?>

                        <?php if ($progettoId <= 0 || $progettoNome === ''): ?>
                            <div class="alert alert-warning mb-0">Seleziona prima un progetto dal menu principale.</div>
                        <?php elseif (!$pageTypes): ?>
                            <div class="alert alert-warning mb-0">Nessuna tipologia caricata.</div>
                        <?php else: ?>
                            <label for="tipoId" class="form-label fw-semibold">Tipo pannellata</label>
                            <select id="tipoId"
                                    name="tipo_id"
                                    class="form-select"
                                    onchange="(function(sel){var h=document.getElementById('pageHeaderDescription');if(!sel||!sel.options||sel.selectedIndex<0)return;var o=sel.options[sel.selectedIndex];var txt=(o&&((o.dataset&&o.dataset.description)||o.textContent))||'Selezionare la tipologia';txt=String(txt).trim();if(h)h.textContent=txt;})(this)">
                                <option value="">scegli tipologia di pannellata</option>
                                <?php foreach ($pageTypes as $pageType): ?>
                                    <?php
                                    $typeId = (int) ($pageType['id'] ?? 0);
                                    $typeCode = (string) ($pageType['codice'] ?? '');
                                    $typeDescription = (string) ($pageType['descrizione'] ?? '');
                                    $typeLabel = trim($typeDescription) !== '' ? $typeDescription : $typeCode;
                                    $isSelected = $selectedPageTypeId > 0 && $selectedPageTypeId === $typeId;
                                    ?>
                                    <option value="<?= $typeId ?>"
                                            data-code="<?= htmlspecialchars($typeCode, ENT_QUOTES, 'UTF-8') ?>"
                                            data-rows-per-page="<?= (int) ($pageType['righe_per_pagina'] ?? 25) ?>"
                                            data-rows-blocked="<?= !empty($pageType['righe_bloccate']) ? '1' : '0' ?>"
                                            data-show-modal="<?= !empty($pageType['show_modal']) ? '1' : '0' ?>"
                                            data-description="<?= htmlspecialchars($typeDescription, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>1. Dati della scheda</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="pageName" class="form-label">Nome pagina</label>
                                <input type="text" id="pageName" class="form-control" placeholder="Es. Elenco clienti"
                                       onblur="if (window.creatorePaginaSyncDataFields) { window.creatorePaginaSyncDataFields(this, true); }">
                                <div class="form-text d-none" id="pageNameReadonlyNote">
                                    In modifica questo campo è in sola lettura.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="fileName" class="form-label">Nome file PHP</label>
                                <input type="text" id="fileName" class="form-control" placeholder="elenco_clienti.php">
                                <div class="form-text d-none" id="fileNameReadonlyNote">
                                    In modifica questo campo è in sola lettura.
                                </div>
                            </div>
                            <div class="col-md-8">
                                <label for="pageTitle" class="form-label">Titolo visualizzato</label>
                                <input type="text" id="pageTitle" class="form-control" placeholder="Elenco clienti"
                                       oninput="if (this.value !== '') { this.dataset.manual='1'; } else { delete this.dataset.manual; }">
                            </div>
                            <div class="col-12">
                                <label for="pageDescription" class="form-label">Descrizione</label>
                                <textarea id="pageDescription"
                                          class="form-control"
                                          rows="3"
                                          maxlength="2000"
                                          placeholder="Descrizione, finalità o note della pagina"></textarea>
                                <div class="form-text">
                                    Informazione interna associata alla configurazione della pagina.
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="rowsPerPage" class="form-label">Righe per pagina</label>
                                <input type="number"
                                       id="rowsPerPage"
                                       class="form-control"
                                       min="1"
                                       step="1"
                                       value="25">
                                <div id="rowsPerPageHelp" class="form-text d-none">
                                    Valore bloccato per la tipologia selezionata.
                                </div>
                            </div>
                            <div class="col-12 d-none">
                                <input type="radio"
                                       name="viewType"
                                       id="viewCard"
                                       value="SCHEDA_SINGOLA"
                                       checked>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>2. Tabella principale</strong>
                    </div>
                    <div class="generator-step-body">
                        <label for="mainTable" class="form-label">Selezionare la tabella principale</label>
                        <select id="mainTable" class="form-select">
                            <option value="">-- selezionare --</option>
                            <?php foreach ($tables as $table): ?>
                                <option value="<?= (int) $table['id'] ?>">
                                    <?= htmlspecialchars($table['nome'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>3. Campi disponibili e campi da visualizzare</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-3">
                            <div class="col-12 col-xl-7">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h6 class="mb-0">Campi disponibili</h6>
                                </div>
                                <div id="availableFields">
                                    <div class="card border-primary-subtle bg-light" id="mainTableCard">
                                        <div class="card-body py-2 px-3">
                                            <div class="fw-semibold" id="mainTableFieldsLabel"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-xl-5">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                    <h6 class="mb-0">Campi da visualizzare</h6>
                                    <span class="badge text-bg-light" id="selectedFieldsCountBadge">0</span>
                                </div>
                                <div id="selectedFields" class="selected-field-list" style="height: auto !important; max-height: none !important; overflow-y: visible !important; overflow-x: visible !important;">
                                    <div class="text-muted text-center py-5" id="selectedPlaceholder">
                                        Trascinare qui i campi o fare doppio click.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>4. Tabelle collegate</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div class="small text-muted">
                                Selezionare una o più tabelle collegate per includerle nei campi disponibili.
                            </div>
                        </div>
                        <div id="relationsContainer" class="text-muted">
                            Selezionare prima la tabella principale.
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>5. Opzioni generali</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="row g-3">
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="searchEnabled" checked>
                                <label class="form-check-label" for="searchEnabled">Ricerca</label>
                            </div>
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="sortEnabled" checked>
                                <label class="form-check-label" for="sortEnabled">Ordinamento</label>
                            </div>
                            <div class="col-md-3 form-check ms-3">
                                <input class="form-check-input" type="checkbox" id="paginationEnabled" checked>
                                <label class="form-check-label" for="paginationEnabled">Paginazione</label>
                            </div>
                            <!-- Modale disattivato -->
                            <div class="col-12"><hr class="my-2"></div>
                            <div class="col-12">
                                <div class="fw-semibold mb-2">Funzioni CRUD sulla tabella principale</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="crudEnabled">
                                        <label class="form-check-label" for="crudEnabled">Abilita CRUD</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input crud-option" type="checkbox" id="crudAdd">
                                        <label class="form-check-label" for="crudAdd">Aggiungi / Inserisci</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input crud-option" type="checkbox" id="crudEdit">
                                        <label class="form-check-label" for="crudEdit">Modifica</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input crud-option" type="checkbox" id="crudDelete">
                                        <label class="form-check-label" for="crudDelete">Cancella</label>
                                    </div>
                                </div>
                                <div class="form-text">
                                    Il CRUD opera esclusivamente sulla tabella principale.
                                    Le foreign key vengono mostrate come menu a discesa con descrizione leggibile.
                                </div>
                            </div>

                            <!-- Gestione modale rimossa -->
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header d-flex justify-content-between align-items-center">
                        <strong>Anteprima SQL</strong>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="refreshPreview">
                            Aggiorna
                        </button>
                    </div>
                    <div class="generator-step-body">
                        <pre id="sqlPreview" class="sql-preview">Selezionare tabella e campi.</pre>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>Generazione</strong>
                    </div>
                    <div class="generator-step-body">
                        <div class="generator-actions">
                            <button type="button"
                                    class="btn btn-outline-primary fw-bold generator-fab"
                                    id="saveConfigButton"
                                    aria-label="Salva e verifica configurazione">
                                <i class="bi bi-database-check me-2"></i>
                                Salva e verifica dati
                            </button>
                            <button type="button"
                                    class="btn btn-success fw-bold generator-fab"
                                    id="generateButton"
                                    aria-label="Genera PHP e salva il file">
                                <i class="bi bi-file-earmark-code me-2"></i>
                                Genera PHP e salva il file
                            </button>
                        </div>
                    </div>
                </section>

                <section class="generator-step">
                    <div class="generator-step-header">
                        <strong>Report</strong>
                    </div>
                    <div class="generator-step-body">
                        <div id="loadReportPanel" class="alert alert-light border small mb-3">
                            <div class="fw-semibold mb-2" id="loadReportTitle">Riepilogo caricamento configurazione</div>
                            <div id="loadReportList" class="small"></div>
                        </div>
                        <div class="fw-semibold mb-2">Debug</div>
                        <div id="loadDebugPanel" class="alert alert-light border small mb-3" style="max-height: 320px; overflow-y: auto;">
                            <div id="loadDebugList" class="small"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="loadDebugResetButton">
                                Reset
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="loadDebugCopyButton">
                                Copia
                            </button>
                        </div>
                        <div id="resultMessage"></div>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
window.creatorePaginaContext = {
    mode: <?= json_encode($creatorPageMode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    configurationId: <?= (int) $initialConfigurationId ?>,
};
</script>

<?php
renderPagePreviewModal();
?>

<div class="modal fade" id="concatFieldModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-link-45deg me-2"></i>
                    <span id="virtualFieldModalTitle">Campo concatenato</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0" id="virtualFieldAvailableTitle">Campi da selezionare</h6>
                            <span class="badge text-bg-light" id="concatAvailableCount">0</span>
                        </div>
                        <div id="concatAvailableFields" class="concat-builder-list"></div>
                    </div>
                    <div class="col-lg-3">
                        <h6 class="mb-2" id="virtualFieldToolsTitle">Separatori</h6>
                        <div id="concatSeparators" class="concat-builder-list">
                            <div class="concat-separator-grid"></div>
                            <div id="formulaNumberTool" class="mt-3 d-none">
                                <label for="formulaNumberValue" class="form-label small mb-1">Numero</label>
                                <div class="input-group input-group-sm">
                                    <input type="text"
                                           id="formulaNumberValue"
                                           class="form-control"
                                           inputmode="decimal"
                                           placeholder="1.10">
                                    <button type="button"
                                            class="btn btn-outline-primary"
                                            id="formulaNumberAddButton">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0" id="virtualFieldSelectedTitle">Campi e separatori scelti</h6>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="concatClearButton">
                                <i class="bi bi-x-circle me-1"></i>
                                Svuota
                            </button>
                        </div>
                        <div id="concatSelectedItems" class="concat-selected-list">
                            <div class="text-muted text-center py-5">
                                Trascinare qui campi e separatori.
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="concatFieldLabel" class="form-label">Etichetta</label>
                        <input type="text" id="concatFieldLabel" class="form-control" placeholder="Es. Nome completo">
                    </div>
                    <div class="col-12">
                        <h6 class="mb-2">Esempio</h6>
                        <div id="concatExample" class="concat-example text-muted">
                            Selezionare almeno un campo.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-primary" id="concatConfirmButton">
                    <i class="bi bi-check2 me-1"></i>
                    <span id="virtualFieldConfirmLabel">Aggiungi campo</span>
                </button>
            </div>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/creatore_pagina_js.php'; ?>


