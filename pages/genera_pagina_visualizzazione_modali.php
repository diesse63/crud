<?php
/**
 * genera_pagina_visualizzazione_modali.php
 *
 * Gestione separata della pannellata secondaria del modale e rendering
 * dei modali nelle pagine generate.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Accesso diretto non consentito.');
}

/**
 * Modale tabellare per una pagina principale a scheda singola.
 * Mostra tutti i record collegati trovati con il filtro configurato.
 */
function generatedSingleCardModalPhp(): string
{
    return <<<'PHP'
            <?php if ($modalEnabled && $modalConfig): ?>
                <?php
                $modalRows = $modalDataByRow[$rowIndex ?? 0] ?? [];
                ?>
                <div class="modal fade"
                     id="singleCardModal"
                     tabindex="-1"
                     aria-labelledby="singleCardModalLabel"
                     aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="singleCardModalLabel">
                                    <?= htmlspecialchars(
                                        (string) ($modalConfig['title'] ?? 'Dati collegati'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h5>
                                <button type="button"
                                        class="btn-close"
                                        data-bs-dismiss="modal"
                                        aria-label="Chiudi"></button>
                            </div>

                            <div class="modal-body">
                                <?php
                                $modalParentValue = $row[$modalConfig['main_value_alias']] ?? null;
                                ?>
                                <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                    <div>
                                        <strong>Record collegati</strong>
                                        <div class="small mb-0">
                                            Visualizzazione dei record collegati.
                                        </div>
                                    </div>
                                </div>

                                <?php if ($modalRows): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <?php foreach ($modalConfig['fields'] as $field): ?>
                                                        <th>
                                                            <?= htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8') ?>
                                                        </th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($modalRows as $modalRow): ?>
                                                    <tr>
                                                        <?php foreach ($modalConfig['fields'] as $field): ?>
                                                            <td>
                                                                <?= displayValue(
                                                                    $modalRow[$field['alias']] ?? null,
                                                                    (string) ($field['format'] ?? 'AUTOMATICO'),
                                                                    (string) ($field['base_path'] ?? '')
                                                                ) ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-secondary mb-0">
                                        Nessun record collegato presente.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="modal-footer">
                                <button type="button"
                                        class="btn btn-secondary"
                                        data-bs-dismiss="modal">
                                    Chiudi
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
PHP;
}

/**
 * Dettaglio interno a riga espansa per una pagina principale tabellare.
 * Mostra il primo record collegato trovato per ciascuna riga principale.
 */
function generatedTableRowCardModalPhp(): string
{
    return <<<'PHP'
                        <?php if ($hasModalDetail): ?>
                                <?php
                                $modalRows = $hasLinkedModalDetail ? ($modalDataByRow[$rowIndex] ?? []) : [];
                                $modalRow = $hasLinkedModalDetail ? ($modalRows[0] ?? null) : $row;
                                $modalParentValue = $hasLinkedModalDetail
                                    ? ($row[$modalConfig['main_value_alias']] ?? null)
                                    : null;
                                $detailFields = $hasLinkedModalDetail
                                    ? $modalConfig['fields']
                                    : $modalVisibleFields;
                                $modalCollapseId = 'recordInline' . $rowIndex;
                                ?>
                                <tr class="collapse table-secondary"
                                    id="<?= htmlspecialchars($modalCollapseId, ENT_QUOTES, 'UTF-8') ?>">
                                    <td colspan="<?= count($visibleFields) + (($crudEnabled && ($crudEdit || $crudDelete)) ? 1 : 0) ?>">
                                    <div class="border rounded p-3 bg-info-subtle my-2">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                            <div>
                                                <strong><?= htmlspecialchars((string) ($modalConfig['title'] ?? 'Dettaglio'), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="small text-muted">Dettaglio record</div>
                                            </div>
                                        </div>

                                        <?php if (!$modalRow): ?>
                                            <div class="alert alert-secondary mb-0">
                                                Nessun dato collegato trovato.
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered align-middle mb-0">
                                                    <tbody>
                                                        <?php foreach ($detailFields as $field): ?>
                                                            <tr>
                                                                <th style="width:35%">
                                                                    <?= htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8') ?>
                                                                </th>
                                                                <td>
                                                                    <?= displayValue(
                                                                        $modalRow[$field['alias'] ?? $field['output_alias']] ?? null,
                                                                        (string) ($field['format'] ?? 'AUTOMATICO'),
                                                                        (string) ($field['base_path'] ?? '')
                                                                    ) ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($modalParentValue !== null && $modalParentValue !== ''): ?>
                                            <div class="small text-muted mt-3">
                                                Collegamento attivo.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    </td>
                                </tr>
                        <?php endif; ?>
PHP;
}

/**
 * Pannellata secondaria per la configurazione del modale.
 */
function renderModalManagementPanel(): void
{
    ?>
    <div class="modal fade"
         id="modalManagementPanel"
         tabindex="-1"
         aria-labelledby="modalManagementPanelLabel"
         aria-hidden="true"
         data-bs-backdrop="static"
         data-bs-keyboard="false">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modalManagementPanelLabel">
                            Gestione visualizzazione modale
                        </h5>
                        <div class="small text-muted" id="modalViewDescription">
                            La forma del modale viene determinata dalla visualizzazione principale.
                        </div>
                    </div>
                    <button type="button"
                            class="btn-close"
                            id="closeModalManagementPanel"
                            aria-label="Chiudi"></button>
                </div>

                <div class="modal-body bg-light">
                    <div id="modalPanelMessage"></div>

                    <div class="row g-3">
                        <div class="col-12 col-xl-4">
                            <section class="generator-step h-100">
                                <div class="generator-step-header">
                                    <strong>1. Tabella e collegamento</strong>
                                </div>
                                <div class="generator-step-body">
                                    <label for="modalLinkedTable" class="form-label">
                                        Tabella collegata
                                    </label>
                                    <select id="modalLinkedTable" class="form-select mb-3">
                                        <option value="">-- selezionare --</option>
                                    </select>

                                    <label for="modalRelationPair" class="form-label">
                                        Campo usato per il filtro
                                    </label>
                                    <select id="modalRelationPair" class="form-select mb-3">
                                        <option value="">-- selezionare collegamento --</option>
                                    </select>

                                    <label for="modalTitle" class="form-label">
                                        Titolo del modale
                                    </label>
                                    <input type="text"
                                           id="modalTitle"
                                           class="form-control"
                                           placeholder="Dati collegati">

                                    <div class="alert alert-info mt-3 mb-0">
                                        <strong>Tipo visualizzazione:</strong>
                                        <span id="modalCalculatedView"></span>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="col-12 col-xl-4">
                            <section class="generator-step h-100">
                                <div class="generator-step-header">
                                    <strong>2. Campi disponibili</strong>
                                </div>
                                <div class="generator-step-body">
                                    <p class="small text-muted">
                                        Trascinare i campi nella colonna di destra o fare doppio click.
                                    </p>
                                    <div id="modalAvailableFields" class="field-list"></div>
                                </div>
                            </section>
                        </div>

                        <div class="col-12 col-xl-4">
                            <section class="generator-step h-100">
                                <div class="generator-step-header">
                                    <strong>3. Campi del modale</strong>
                                </div>
                                <div class="generator-step-body">
                                    <p class="small text-muted">
                                        L’ordine può essere modificato tramite trascinamento.
                                    </p>
                                    <div id="modalSelectedFields" class="selected-list">
                                        <div class="text-muted text-center py-5">
                                            Trascinare qui i campi o fare doppio click.
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <span class="small text-muted me-auto">
                        La chiusura riporta obbligatoriamente alla gestione della pagina principale.
                    </span>
                    <button type="button"
                            class="btn btn-outline-danger"
                            id="clearModalConfiguration">
                        Azzera configurazione modale
                    </button>
                    <button type="button"
                            class="btn btn-primary"
                            id="saveModalConfiguration">
                        <span id="saveModalConfigurationText">
                            Conferma e torna alla pagina
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade"
         id="anagrafeLocalitaExampleModal"
         tabindex="-1"
         aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">Anagrafe - dati collegati</h5>
                        <div class="small text-muted">
                            Modale predisposto per visualizzare e collegare i dati di anagrafe e localita.
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>

                <div class="modal-body bg-white">
                    <div class="row g-3">
                        <div class="col-12 col-lg-4">
                            <label class="form-label">Cognome</label>
                            <input type="text" class="form-control" placeholder="Cognome">
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label">Nome</label>
                            <input type="text" class="form-control" placeholder="Nome">
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label">Località nascita</label>
                            <select class="form-select">
                                <option value="">-- selezionare --</option>
                                <option value="1">Località 1</option>
                                <option value="2">Località 2</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <hr class="my-2">
                        </div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label">Data nascita</label>
                            <input type="date" class="form-control">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label">Località residenza</label>
                            <select class="form-select">
                                <option value="">-- selezionare --</option>
                                <option value="1">Località 1</option>
                                <option value="2">Località 2</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Inserisci
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (() => {
        'use strict';
        const modalState = {
            enabled: false,
            linkedTableId: null,
            linkedTableName: '',
            fkId: null,
            relationPairs: [],
            selectedPairIndex: 0,
            title: '',
            viewType: 'TABELLA',
            crudEnabled: false,
            crudAdd: false,
            crudEdit: false,
            crudDelete: false,
            availableFields: [],
            selectedFields: []
        };

        const panelElement = document.getElementById('modalManagementPanel');
        const linkedTableSelect = document.getElementById('modalLinkedTable');
        const relationPairSelect = document.getElementById('modalRelationPair');
        const availableContainer = document.getElementById('modalAvailableFields');
        const selectedContainer = document.getElementById('modalSelectedFields');
        const titleInput = document.getElementById('modalTitle');
        const calculatedView = document.getElementById('modalCalculatedView');
        const description = document.getElementById('modalViewDescription');
        const message = document.getElementById('modalPanelMessage');
        const saveButtonText = document.getElementById('saveModalConfigurationText');

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function fieldFilterTypes(type) {
            const normalized = String(type || '').toLowerCase();

            if (['int', 'float', 'double', 'decimal'].includes(normalized)) {
                return [
                    ['UGUALE', 'Uguale'],
                    ['INTERVALLO_NUMERO', 'Intervallo numerico']
                ];
            }

            if (['date', 'datetime', 'timestamp'].includes(normalized)) {
                return [
                    ['UGUALE', 'Data uguale'],
                    ['INTERVALLO_DATA', 'Intervallo data']
                ];
            }

            if (['boolean', 'tinyint'].includes(normalized)) {
                return [['BOOLEANO', 'Sì / No']];
            }

            return [
                ['TESTO', 'Contiene testo'],
                ['UGUALE', 'Uguale']
            ];
        }

        function renderAvailable() {
            availableContainer.innerHTML = modalState.availableFields.map(field => {
                const selected = modalState.selectedFields.some(
                    item => Number(item.fieldId) === Number(field.id)
                );

                return `
                    <div class="field-card ${selected ? 'opacity-50' : ''}"
                         draggable="${selected ? 'false' : 'true'}"
                         data-field-id="${Number(field.id)}">
                        <div class="fw-semibold">${escapeHtml(field.nome)}</div>
                        <div class="field-meta">${escapeHtml(field.tipo || '')}</div>
                    </div>
                `;
            }).join('') || '<div class="text-muted">Nessun campo disponibile.</div>';

            availableContainer.querySelectorAll('[draggable="true"]').forEach(card => {
                card.addEventListener('dragstart', event => {
                    event.dataTransfer.effectAllowed = 'copy';
                    event.dataTransfer.setData('text/plain', card.dataset.fieldId);
                });

            });
        }

        function renderSelected() {
            if (!modalState.selectedFields.length) {
                selectedContainer.innerHTML = `
                    <div class="text-muted text-center py-5">
                        Trascinare qui i campi o fare doppio click.
                    </div>
                `;
                return;
            }

            selectedContainer.innerHTML = modalState.selectedFields.map((field, index) => {
                const filterOptions = fieldFilterTypes(field.type);

                return `
                    <div class="selected-item"
                         draggable="true"
                         data-index="${index}">
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <div>
                                <span class="badge text-bg-secondary me-1">${index + 1}</span>
                                <strong>${escapeHtml(field.fieldName)}</strong>
                            </div>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger modal-remove-field"
                                    data-index="${index}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>

                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small mb-1">Etichetta</label>
                                <input type="text"
                                       class="form-control form-control-sm modal-field-option"
                                       data-index="${index}"
                                       data-key="label"
                                       value="${escapeHtml(field.label)}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small mb-1">Formato</label>
                                <select class="form-select form-select-sm modal-field-option"
                                        data-index="${index}"
                                        data-key="format">
                                    ${[
                                        'AUTOMATICO','TESTO','NUMERO','VALUTA','DATA',
                                        'DATA_ORA','BOOLEANO','JSON','IMMAGINE','FILE','URL','EMAIL'
                                    ].map(value => `
                                        <option value="${value}" ${field.format === value ? 'selected' : ''}>
                                            ${value}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small mb-1">Layout scheda</label>
                                <select class="form-select form-select-sm modal-field-option"
                                        data-index="${index}"
                                        data-key="bootstrapCol">
                                    ${[
                                        ['12','Riga intera'],
                                        ['8','Due terzi'],
                                        ['6','Metà'],
                                        ['4','Un terzo'],
                                        ['3','Un quarto']
                                    ].map(([value,label]) => `
                                        <option value="${value}" ${field.bootstrapCol === value ? 'selected' : ''}>
                                            ${label}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small mb-1">Percorso base</label>
                                <input type="text"
                                       class="form-control form-control-sm modal-field-option"
                                       data-index="${index}"
                                       data-key="basePath"
                                       value="${escapeHtml(field.basePath || '')}">
                            </div>

                            <div class="col-md-3 pt-4">
                                <div class="form-check">
                                    <input type="checkbox"
                                           class="form-check-input modal-field-check"
                                           data-index="${index}"
                                           data-key="filterEnabled"
                                           ${field.filterEnabled ? 'checked' : ''}>
                                    <label class="form-check-label small">Filtro</label>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small mb-1">Tipo filtro</label>
                                <select class="form-select form-select-sm modal-field-option"
                                        data-index="${index}"
                                        data-key="filterType"
                                        ${field.filterEnabled ? '' : 'disabled'}>
                                    ${filterOptions.map(([value,label]) => `
                                        <option value="${value}" ${field.filterType === value ? 'selected' : ''}>
                                            ${label}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            selectedContainer.querySelectorAll('.modal-remove-field').forEach(button => {
                button.addEventListener('click', () => {
                    modalState.selectedFields.splice(Number(button.dataset.index), 1);
                    renderAvailable();
                    renderSelected();
                    notifyChange();
                });
            });

            selectedContainer.querySelectorAll('.modal-field-option').forEach(control => {
                control.addEventListener('change', () => {
                    const field = modalState.selectedFields[Number(control.dataset.index)];
                    if (!field) return;
                    field[control.dataset.key] = control.value;
                    notifyChange();
                });
            });

            selectedContainer.querySelectorAll('.modal-field-check').forEach(control => {
                control.addEventListener('change', () => {
                    const field = modalState.selectedFields[Number(control.dataset.index)];
                    if (!field) return;
                    field[control.dataset.key] = control.checked;
                    renderSelected();
                    notifyChange();
                });
            });

            selectedContainer.querySelectorAll('.selected-item').forEach(item => {
                item.addEventListener('dragstart', event => {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('application/x-modal-index', item.dataset.index);
                });

                item.addEventListener('dragover', event => event.preventDefault());

                item.addEventListener('drop', event => {
                    event.preventDefault();
                    const from = Number(event.dataTransfer.getData('application/x-modal-index'));
                    const to = Number(item.dataset.index);

                    if (Number.isNaN(from) || from === to) return;

                    const [moved] = modalState.selectedFields.splice(from, 1);
                    modalState.selectedFields.splice(to, 0, moved);
                    renderSelected();
                    notifyChange();
                });
            });
        }

        function addField(fieldId) {
            if (modalState.selectedFields.some(item => Number(item.fieldId) === fieldId)) {
                return;
            }

            const field = modalState.availableFields.find(
                item => Number(item.id) === fieldId
            );
            if (!field) return;

            const defaultFilter = fieldFilterTypes(field.tipo)[0][0];

            modalState.selectedFields.push({
                fieldId: Number(field.id),
                fieldName: field.nome,
                type: field.tipo,
                label: field.nome,
                format: 'AUTOMATICO',
                bootstrapCol: '6',
                basePath: '',
                filterEnabled: false,
                filterType: defaultFilter
            });

            renderAvailable();
            renderSelected();
            notifyChange();
        }

        function normalizePair(pair, relation) {
            if (pair.main_field_id && pair.linked_field_id) {
                return pair;
            }

            const outgoing = relation?.direction === 'OUT';

            return {
                ...pair,
                main_field_id: Number(
                    outgoing
                        ? (pair.local_field_id || 0)
                        : (pair.referenced_field_id || 0)
                ),
                main_field_name:
                    outgoing
                        ? (pair.local_field_name || pair.local || '')
                        : (pair.referenced_field_name || pair.referenced || ''),
                linked_field_id: Number(
                    outgoing
                        ? (pair.referenced_field_id || 0)
                        : (pair.local_field_id || 0)
                ),
                linked_field_name:
                    outgoing
                        ? (pair.referenced_field_name || pair.referenced || '')
                        : (pair.local_field_name || pair.local || '')
            };
        }

        function renderRelationPairs() {
            relationPairSelect.innerHTML = modalState.relationPairs.map((pair, index) => `
                <option value="${index}" ${index === modalState.selectedPairIndex ? 'selected' : ''}>
                    ${escapeHtml(pair.main_field_name || '')}
                    →
                    ${escapeHtml(pair.linked_field_name || '')}
                </option>
            `).join('') || '<option value="">-- nessun collegamento --</option>';

            if (modalState.relationPairs.length) {
                modalState.selectedPairIndex = Math.min(
                    modalState.selectedPairIndex,
                    modalState.relationPairs.length - 1
                );
                relationPairSelect.value = String(modalState.selectedPairIndex);
            }
        }

        function updateViewDescription(mainViewType) {
            modalState.viewType = mainViewType === 'SCHEDA_SINGOLA'
                ? 'TABELLA'
                : 'SCHEDA_SINGOLA';

            calculatedView.textContent = modalState.viewType === 'TABELLA'
                ? 'Tabellare'
                : 'Scheda singola';

            description.textContent = mainViewType === 'SCHEDA_SINGOLA'
                ? 'Pagina principale a scheda: il modale visualizza una tabella di record collegati.'
                : 'Pagina principale tabellare: il modale visualizza una scheda singola collegata.';
        }

        function notifyChange() {
            window.dispatchEvent(new CustomEvent('modal-config-changed', {
                detail: getConfig()
            }));
        }

        function getConfig() {
            if (!modalState.enabled || !modalState.linkedTableId) {
                return null;
            }

            const pair =
                modalState.relationPairs[modalState.selectedPairIndex]
                || modalState.relationPairs[0]
                || null;

            return {
                enabled: true,
                linked_table_id: modalState.linkedTableId,
                linked_table_name: modalState.linkedTableName,
                fk_id: modalState.fkId,
                title: titleInput.value.trim() || 'Dati collegati',
                view_type: modalState.viewType,
                main_field_id: pair ? Number(pair.main_field_id) : 0,
                main_field_name: pair ? pair.main_field_name : '',
                linked_field_id: pair ? Number(pair.linked_field_id) : 0,
                linked_field_name: pair ? pair.linked_field_name : '',
                fields: modalState.selectedFields.map((field, index) => ({
                    ...field,
                    order: index + 1
                }))
            };
        }

        function clear(silent = false) {
            modalState.enabled = false;
            modalState.linkedTableId = null;
            modalState.linkedTableName = '';
            modalState.fkId = null;
            modalState.relationPairs = [];
            modalState.selectedPairIndex = 0;
            modalState.title = '';
            modalState.availableFields = [];
            modalState.selectedFields = [];

            linkedTableSelect.innerHTML = '<option value="">-- selezionare --</option>';
            relationPairSelect.innerHTML = '<option value="">-- selezionare collegamento --</option>';
            titleInput.value = '';
            renderAvailable();
            renderSelected();

            if (!silent) notifyChange();
        }

        function setConfig(config, context) {
            clear(true);
            syncContext(context);

            if (!config || !config.enabled) {
                return;
            }

            const relation = (context.relations || []).find(
                item => Number(item.secondary_table_id) === Number(config.linked_table_id)
                    && Number(item.fk_id) === Number(config.fk_id)
            ) || (context.relations || []).find(
                item => Number(item.secondary_table_id) === Number(config.linked_table_id)
            );
            if (!relation) return;

            modalState.enabled = true;
            modalState.linkedTableId = Number(config.linked_table_id);
            modalState.linkedTableName = relation.secondary_table_name;
            modalState.fkId = Number(config.fk_id);
            modalState.relationPairs = (relation.pairs || []).map(
                pair => normalizePair(pair, relation)
            );
            modalState.availableFields = relation.fields || [];
            modalState.selectedPairIndex = Math.max(
                0,
                modalState.relationPairs.findIndex(pair =>
                    Number(pair.main_field_id) === Number(config.main_field_id)
                    && Number(pair.linked_field_id) === Number(config.linked_field_id)
                )
            );
            modalState.selectedFields = (config.fields || []).map(field => ({
                fieldId: Number(field.fieldId ?? field.field_id),
                fieldName: field.fieldName ?? field.field_name,
                type: field.type ?? field.field_type,
                label: field.label,
                format: field.format || 'AUTOMATICO',
                bootstrapCol: String(field.bootstrapCol ?? field.bootstrap_col ?? '6'),
                basePath: field.basePath ?? field.base_path ?? '',
                filterEnabled: Boolean(
                    Number(field.filterEnabled ?? field.filter_enabled ?? 0)
                ),
                filterType: field.filterType ?? field.filter_type ?? 'TESTO'
            }));
            titleInput.value = config.title || 'Dati collegati';

            syncContext(context);
            linkedTableSelect.value = String(modalState.linkedTableId);
            renderRelationPairs();
            renderAvailable();
            renderSelected();
            updateViewDescription(context.mainViewType);
        }

        function syncContext(context) {
            updateViewDescription(context.mainViewType);

            const eligibleRelations = (context.relations || []).filter(relation => {
                const relationSelected = context.selectedRelationIds.includes(
                    Number(relation.fk_id)
                );
                const hasMainSelectedField = context.mainSelectedFieldTableIds.includes(
                    Number(relation.secondary_table_id)
                );
                const isCurrentModalRelation =
                    modalState.linkedTableId
                    && Number(relation.secondary_table_id) === Number(modalState.linkedTableId);

                return isCurrentModalRelation || (relationSelected && hasMainSelectedField);
            });

            linkedTableSelect.innerHTML =
                '<option value="">-- selezionare --</option>'
                + eligibleRelations.map(relation => `
                    <option value="${Number(relation.secondary_table_id)}"
                            data-fk-id="${Number(relation.fk_id)}">
                        ${escapeHtml(relation.secondary_table_name)}
                    </option>
                `).join('');

            if (
                modalState.linkedTableId
                && eligibleRelations.some(
                    relation => Number(relation.secondary_table_id) === Number(modalState.linkedTableId)
                )
            ) {
                linkedTableSelect.value = String(modalState.linkedTableId);
            }
        }

        function open(context) {
            syncContext(context);

            if (modalState.linkedTableId) {
                linkedTableSelect.value = String(modalState.linkedTableId);
            }

            saveButtonText.textContent = modalState.enabled
                ? 'Aggiorna pannellata'
                : 'Conferma e torna alla pagina';
            bootstrap.Modal.getOrCreateInstance(panelElement).show();
        }

        linkedTableSelect.addEventListener('change', () => {
            const tableId = Number(linkedTableSelect.value || 0);
            const context = window.getMainPageModalContext?.();
            const relation = (context?.relations || []).find(
                item => Number(item.secondary_table_id) === tableId
                    && context.selectedRelationIds.includes(Number(item.fk_id))
            );

            modalState.enabled = Boolean(relation);
            modalState.linkedTableId = relation ? tableId : null;
            modalState.linkedTableName = relation?.secondary_table_name || '';
            modalState.fkId = relation ? Number(relation.fk_id) : null;
            modalState.relationPairs = (relation?.pairs || []).map(
                pair => normalizePair(pair, relation)
            );
            modalState.selectedPairIndex = 0;
            modalState.availableFields = relation?.fields || [];
            modalState.selectedFields = [];

            renderRelationPairs();
            renderAvailable();
            renderSelected();
            notifyChange();
        });

        relationPairSelect.addEventListener('change', () => {
            modalState.selectedPairIndex = Number(relationPairSelect.value || 0);
            notifyChange();
        });

        titleInput.addEventListener('input', notifyChange);
        crudEnabledInput.addEventListener('change', () => {
            syncCrudControls();
            notifyChange();
        });
        crudOptionInputs.forEach(input => {
            input.addEventListener('change', () => {
                syncCrudControls();
                notifyChange();
            });
        });

        selectedContainer.addEventListener('dragover', event => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });

        selectedContainer.addEventListener('drop', event => {
            if (event.dataTransfer.types.includes('application/x-modal-index')) {
                return;
            }

            event.preventDefault();
            const fieldId = Number(event.dataTransfer.getData('text/plain'));
            if (fieldId) addField(fieldId);
        });

        availableContainer.addEventListener('dblclick', event => {
            const card = event.target.closest('.field-card[draggable="true"]');
            if (!card || !availableContainer.contains(card)) return;

            addField(Number(card.dataset.fieldId));
        });

        document.getElementById('clearModalConfiguration').addEventListener('click', () => {
            if (
                modalState.selectedFields.length
                && !window.confirm('Azzerare tutti i dati della configurazione modale?')
            ) {
                return;
            }

            clear();
            message.innerHTML = '<div class="alert alert-success">Configurazione modale azzerata.</div>';
        });

        document.getElementById('saveModalConfiguration').addEventListener('click', () => {
            const config = getConfig();

            if (!config || !config.linked_table_id) {
                message.innerHTML = '<div class="alert alert-danger">Selezionare la tabella collegata.</div>';
                return;
            }

            if (!config.main_field_id || !config.linked_field_id) {
                message.innerHTML = `
                    <div class="alert alert-danger">
                        Collegamento FK non disponibile. Riselezionare la tabella collegata.
                    </div>
                `;
                return;
            }

            if (!config.fields.length) {
                message.innerHTML = '<div class="alert alert-danger">Selezionare almeno un campo del modale.</div>';
                return;
            }

            modalState.enabled = true;
            notifyChange();
            bootstrap.Modal.getOrCreateInstance(panelElement).hide();
        });

        document.getElementById('closeModalManagementPanel').addEventListener('click', () => {
            bootstrap.Modal.getOrCreateInstance(panelElement).hide();
        });

        window.ModalPageManager = {
            open,
            clear,
            getConfig,
            setConfig,
            syncContext,
            hasSelectedFields() {
                return modalState.selectedFields.length > 0;
            }
        };
    })();
    </script>
    <?php
}

/**
 * Anteprima della pagina principale.
 */
function renderPagePreviewModal(): void
{
    ?>
    <div class="modal fade"
         id="pagePreviewModal"
         tabindex="-1"
         aria-labelledby="pagePreviewModalLabel"
         aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="pagePreviewModalLabel">
                            Anteprima pagina generata
                        </h5>
                        <div class="small text-muted">
                            Anteprima grafica basata sulla configurazione corrente.
                        </div>
                    </div>
                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Chiudi"></button>
                </div>

                <div class="modal-body bg-light">
                    <div id="pagePreviewWarnings"></div>
                    <div class="bg-white border rounded shadow-sm p-3"
                         id="pagePreviewContent">
                        <div class="text-muted text-center py-5">
                            Configurare tabella e campi per visualizzare l’anteprima.
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Chiudi
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
