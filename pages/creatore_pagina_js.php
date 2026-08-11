<?php
/**
 * creatore_pagina_js.php
 *
 * Comportamento client-side minimo per la sezione iniziale di creatore_pagina.
 *
 * Versione: 10.75
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Accesso diretto non consentito.');
}
?>
<script>
(function () {
    let mainTableFieldState = {
        tableName: '',
        fields: [],
        selectedFields: [],
    };
    let mainTableRelationState = {
        relations: [],
        selectedRelationIds: [],
    };
    let selectedFieldCollapseState = {};
    let sqlPreviewRefreshTimer = null;
    function normalizeWithUnderscores(value) {
        return String(value || '')
            .trim()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\.php$/i, '')
            .replace(/[^a-zA-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .replace(/_+/g, '_')
            .toLowerCase();
    }

    function normalizePageAndFileFields(pageNameField, fileNameField, pageTitleField, sourceValue) {
        const rawValue = String(sourceValue ?? pageNameField?.value ?? fileNameField?.value ?? '').trim();
        const normalizedValue = normalizeWithUnderscores(rawValue);

        if (pageNameField && !pageNameField.readOnly && normalizedValue) {
            pageNameField.value = normalizedValue;
        }

        if (fileNameField && !fileNameField.readOnly && normalizedValue) {
            fileNameField.value = normalizedValue + '.php';
        }

        if (pageTitleField && !pageTitleField.readOnly && String(pageTitleField.value || '').trim() === '') {
            pageTitleField.value = rawValue;
        }
    }

    function scheduleSqlPreviewRefresh() {
        if (sqlPreviewRefreshTimer) {
            window.clearTimeout(sqlPreviewRefreshTimer);
        }

        sqlPreviewRefreshTimer = window.setTimeout(() => {
            sqlPreviewRefreshTimer = null;
            refreshSqlPreview();
        }, 0);
    }

    window.creatorePaginaSyncDataFields = function (sourceField, isBlur = false) {
        const pageName = document.getElementById('pageName');
        const fileName = document.getElementById('fileName');
        if (!(sourceField instanceof HTMLInputElement)) {
            return;
        }

        if (sourceField.id === 'pageName') {
            if (isBlur) {
                normalizePageAndFileFields(pageName, fileName, document.getElementById('pageTitle'), sourceField.value);
                return;
            }

            const rawValue = String(sourceField.value || '');
            if (fileName && !fileName.readOnly) {
                const normalized = normalizeWithUnderscores(rawValue);
                fileName.value = normalized ? normalized + '.php' : '';
            }
            return;
        }

        if (sourceField.id === 'fileName') {
            const pageTitleField = document.getElementById('pageTitle');
            if (pageTitleField && !pageTitleField.readOnly && String(pageTitleField.value || '').trim() === '') {
                pageTitleField.value = fileName.value;
            }
            normalizePageAndFileFields(pageName, fileName, pageTitleField, fileName.value);
        }
    };

    function updateRowsPerPageByType(tipoSelect, rowsInput) {
        if (!tipoSelect || !rowsInput) return;
        const selectedOption = tipoSelect.options[tipoSelect.selectedIndex];
        const rowsValue = Number(
            selectedOption?.getAttribute('data-rows-per-page')
            ?? selectedOption?.dataset?.rowsPerPage
            ?? 25
        );
        const blocked = String(
            selectedOption?.getAttribute('data-rows-blocked')
            ?? selectedOption?.dataset?.rowsBlocked
            ?? '0'
        ) === '1';
        rowsInput.value = String(rowsValue > 0 ? rowsValue : 25);
        rowsInput.readOnly = blocked;
        rowsInput.disabled = blocked;
        rowsInput.classList.toggle('bg-light', blocked);
        rowsInput.classList.toggle('text-muted', blocked);

        const rowsHelp = document.getElementById('rowsPerPageHelp');
        if (rowsHelp) {
            rowsHelp.classList.toggle('d-none', !blocked);
        }
    }

    function syncPageHeaderAndTitle(tipoSelect) {
        const pageHeaderDescription = document.getElementById('pageHeaderDescription');
        const selectedOption = tipoSelect && tipoSelect.options ? tipoSelect.options[tipoSelect.selectedIndex] : null;

        if (pageHeaderDescription && selectedOption) {
            pageHeaderDescription.textContent = selectedOption.textContent?.trim() || 'Selezionare la tipologia';
        }
    }

    function updateMainTableFieldsLabel(mainTable) {
        const label = document.getElementById('mainTableFieldsLabel');
        const card = document.getElementById('mainTableCard');
        if (!label) return;

        const selectedOption = mainTable && mainTable.options ? mainTable.options[mainTable.selectedIndex] : null;
        label.textContent = selectedOption ? String(selectedOption.textContent || '').trim() : '';
        if (card) {
            card.classList.toggle('d-none', !label.textContent);
        }
    }

    function removeSelectedFieldsBySourceRelation(sourceRelationId) {
        const normalizedSourceRelationId = Number(sourceRelationId || 0);
        if (!normalizedSourceRelationId) return false;

        const beforeCount = mainTableFieldState.selectedFields.length;
        mainTableFieldState.selectedFields = mainTableFieldState.selectedFields.filter((field) => {
            return Number(field?.source_fk_id || 0) !== normalizedSourceRelationId;
        });

        if (beforeCount !== mainTableFieldState.selectedFields.length) {
            selectedFieldCollapseState = {};
            return true;
        }

        return false;
    }

    function ensureRelationSelected(sourceRelationId) {
        const normalizedSourceRelationId = Number(sourceRelationId || 0);
        if (!normalizedSourceRelationId) return;

        if (!mainTableRelationState.selectedRelationIds.includes(normalizedSourceRelationId)) {
            mainTableRelationState.selectedRelationIds = [
                ...mainTableRelationState.selectedRelationIds,
                normalizedSourceRelationId,
            ];
        }
    }

    function getFieldSelectionKey(fieldId, relationId = 0) {
        return `${Number(relationId || 0)}:${Number(fieldId || 0)}`;
    }

    function getSelectedFieldSelectionKey(field) {
        return String(field?.selection_key || getFieldSelectionKey(field?.id || 0, field?.source_fk_id || 0));
    }

    function buildRelationSourceLabel(relation) {
        const relationFkName = String(
            relation?.local_field_name
            || relation?.fk_nome
            || relation?.local_field_code
            || ''
        ).trim();
        const relationFkDesc = String(relation?.local_field_descrittivo || relation?.fk_nome_descrittivo || '').trim();
        const relationTableName = String(relation?.secondary_table_name || relation?.table_name || '').trim();
        const labelBase = relationFkName || 'FK';
        return `${labelBase}${relationFkDesc ? ' · ' + relationFkDesc : ''} -> ${relationTableName}`;
    }

    function findSelectedFieldBySelectionKey(selectionKey) {
        const normalizedSelectionKey = String(selectionKey || '');
        return mainTableFieldState.selectedFields.find((entry) => getSelectedFieldSelectionKey(entry) === normalizedSelectionKey);
    }

    function syncSelectedFieldFlagsFromDom() {
        const selectedList = document.getElementById('mainTableSelectedList');
        if (!selectedList) return;

        selectedList.querySelectorAll('[data-field-prop]').forEach((control) => {
            const fieldKey = String(control.getAttribute('data-field-key') || '');
            const prop = String(control.getAttribute('data-field-prop') || '');
            if (!['visible_table', 'visible_card', 'visible_modal'].includes(prop)) {
                return;
            }

            const target = findSelectedFieldBySelectionKey(fieldKey);
            if (!target || control.tagName !== 'INPUT' || control.type !== 'checkbox') {
                return;
            }

            target[prop] = control.checked === true;
        });
    }

    function renderMainTableRelations(relations) {
        const relationsContainer = document.getElementById('relationsContainer');
        if (!relationsContainer) return;

        mainTableRelationState.relations = Array.isArray(relations) ? relations : [];
        const selectedIds = new Set(mainTableRelationState.selectedRelationIds.map((id) => Number(id)));

        const relationCards = Array.isArray(relations) && relations.length
            ? relations.map((relation) => {
                const relationId = Number(relation?.fk_id || relation?.id || 0);
                const relationFkName = escapeHtml(relation?.local_field_name || relation?.fk_nome || '');
                const relationFkDesc = escapeHtml(relation?.local_field_descrittivo || relation?.fk_nome_descrittivo || '');
                const relationTableName = escapeHtml(relation?.secondary_table_name || relation?.table_name || '');
                const relationType = escapeHtml(String(relation?.join_type || relation?.tipo_join || 'LEFT').toUpperCase());
                const relationFields = Array.isArray(relation?.fields) ? relation.fields : [];
                const currentJoinType = String(relation?.join_type || relation?.tipo_join || 'LEFT').toUpperCase() === 'INNER'
                    ? 'INNER'
                    : 'LEFT';
                const fieldsMarkup = relationFields.length
                    ? `<div class="small text-muted mt-2">${relationFields.map((field) => escapeHtml(field?.nome || '')).join(', ')}</div>`
                    : '<div class="small text-muted mt-2">Nessun campo collegato.</div>';
                const checked = selectedIds.has(relationId) ? 'checked' : '';

                return `
                    <div class="card mb-2 border-secondary-subtle">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" data-relation-id="${relationId}" ${checked}>
                                    <label class="form-check-label fw-semibold">${relationFkName || 'FK'}${relationFkDesc ? ' · ' + relationFkDesc : ''} -> ${relationTableName}</label>
                                </div>
                                <div style="min-width: 130px;">
                                    <select class="form-select form-select-sm" data-relation-id="${relationId}" data-join-type-select="1">
                                        <option value="LEFT" ${currentJoinType === 'LEFT' ? 'selected' : ''}>LEFT JOIN</option>
                                        <option value="INNER" ${currentJoinType === 'INNER' ? 'selected' : ''}>INNER JOIN</option>
                                    </select>
                                </div>
                            </div>
                            <div class="small text-muted mt-1">FK: ${relationFkName}${relationFkDesc ? ' · ' + relationFkDesc : ''}</div>
                            <div class="small text-muted">Tabella: ${relationTableName}</div>
                            ${fieldsMarkup}
                        </div>
                    </div>`;
            }).join('')
            : '<div class="text-muted">Nessuna tabella collegata disponibile.</div>';

        relationsContainer.innerHTML = relationCards;

        relationsContainer.querySelectorAll('input[data-relation-id]').forEach((checkbox) => {
            if (checkbox.type === 'checkbox') {
                checkbox.addEventListener('change', () => {
                    const relationId = Number(checkbox.getAttribute('data-relation-id') || 0);
                    if (!relationId) return;

                    const relation = mainTableRelationState.relations.find((entry) =>
                        Number(entry?.fk_id || entry?.id || 0) === relationId
                    );
                    const selected = new Set(mainTableRelationState.selectedRelationIds.map((id) => Number(id)));
                    if (checkbox.checked) {
                        selected.add(relationId);
                    } else {
                        selected.delete(relationId);
                        if (relationId) {
                            removeSelectedFieldsBySourceRelation(relationId);
                        }
                    }
                    mainTableRelationState.selectedRelationIds = Array.from(selected);
                    renderMainTableRelations(mainTableRelationState.relations);
                    renderMainTableFieldLists();
                });
                return;
            }
        });

        relationsContainer.querySelectorAll('select[data-join-type-select="1"]').forEach((select) => {
            select.addEventListener('change', () => {
                const relationId = Number(select.getAttribute('data-relation-id') || 0);
                const joinType = String(select.value || 'LEFT').toUpperCase();
                if (!relationId || !['LEFT', 'INNER'].includes(joinType)) return;

                mainTableRelationState.relations = mainTableRelationState.relations.map((relation) => {
                    const currentRelationId = Number(relation?.fk_id || relation?.id || 0);
                    if (currentRelationId !== relationId) {
                        return relation;
                    }
                    return {
                        ...relation,
                        join_type: joinType,
                        tipo_join: joinType,
                    };
                });

                renderMainTableRelations(mainTableRelationState.relations);
                renderMainTableFieldLists();
            });
        });

    }

    function renderMainTableFieldLists() {
        const availableFields = document.getElementById('availableFields');
        const selectedFields = document.getElementById('selectedFields');
        const selectedBadge = document.getElementById('selectedFieldsCountBadge');
        const tableName = escapeHtml(mainTableFieldState.tableName || '');
        const selectedCount = mainTableFieldState.selectedFields.length;

        if (selectedBadge) {
            selectedBadge.textContent = String(selectedCount);
        }

        if (!availableFields || !selectedFields) {
            return;
        }

        const formatFieldBadges = (field) => [
            field?.is_pk ? '<span class="badge text-bg-dark">PK</span>' : '',
            field?.is_fk ? '<span class="badge text-bg-primary">FK</span>' : '',
            field?.is_index ? '<span class="badge text-bg-info">IDX</span>' : '',
            field?.is_unique ? '<span class="badge text-bg-success">UQ</span>' : '',
        ].filter(Boolean).join('');

        const renderReadOnlyField = (field, relationId = 0) => {
            const name = escapeHtml(field?.nome || '');
            const type = escapeHtml(String(field?.field_type || field?.tipo || '').toLowerCase());
            const badges = formatFieldBadges(field);
            const fieldId = Number(field?.id || 0);
            const selectionKey = getFieldSelectionKey(fieldId, relationId);
            const isAlreadySelected = mainTableFieldState.selectedFields.some((selected) => getSelectedFieldSelectionKey(selected) === selectionKey);

            return `
                <div class="list-group-item px-0 py-2 ${isAlreadySelected ? 'd-none' : ''}"
                     data-linked-field-id="${fieldId}"
                     data-source-relation-id="${Number(relationId || 0)}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">${name}</div>
                            <div class="small text-muted">${type}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 justify-content-end align-items-start">
                            ${badges}
                        </div>
                    </div>
                </div>`;
        };

        const renderLinkedTableCard = (relation) => {
            const relationId = Number(relation?.fk_id || relation?.id || 0);
            const relationLabelRaw = String(relation?.relation_label || '').trim();
            const relationFkNameRaw = String(
                relation?.local_field_name
                || relation?.fk_nome
                || relation?.local_field_code
                || ''
            );
            const relationFkDescRaw = String(relation?.local_field_descrittivo || relation?.fk_nome_descrittivo || '');
            const relationFkName = escapeHtml(relationFkNameRaw);
            const relationFkDesc = escapeHtml(relationFkDescRaw);
            const relationTableName = escapeHtml(relation?.secondary_table_name || relation?.table_name || '');
            const relationTitle = relationLabelRaw.includes('->')
                ? escapeHtml(relationLabelRaw)
                : `${relationFkName || 'FK'}${relationFkDesc ? ' · ' + relationFkDesc : ''} -> ${relationTableName}`;
            const relationFields = Array.isArray(relation?.fields) ? relation.fields : [];
            const relationType = escapeHtml(String(relation?.join_type || relation?.tipo_join || 'LEFT').toUpperCase());
            const relationFieldsMarkup = relationFields.length
                ? relationFields.map((field) => renderReadOnlyField(field, relationId)).join('')
                : '<div class="text-muted small py-2">Nessun campo disponibile.</div>';

            return `
                <div class="card border-info-subtle bg-white mb-2">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge text-bg-info">Tabella collegata</span>
                                <div class="fw-bold text-info fs-6 mb-0">${relationTitle}</div>
                            </div>
                            <span class="badge text-bg-secondary">${relationType}</span>
                        </div>
                        <div class="small text-muted mb-2">Tabella collegata: ${relationTableName}</div>
                        <div class="list-group list-group-flush">
                            ${relationFieldsMarkup}
                        </div>
                    </div>
                </div>`;
        };

        const renderFieldItem = (field, variant) => {
            const name = escapeHtml(field?.nome || '');
            const rawType = String(field?.field_type || field?.tipo || '').toLowerCase();
            const type = escapeHtml(rawType);
            const trueBadges = formatFieldBadges(field);
            const fieldId = Number(field?.id || 0);
            const fieldSelectionKey = getSelectedFieldSelectionKey(field);
            const isCollapsed = Boolean(selectedFieldCollapseState[fieldSelectionKey]);
            const collapseIcon = isCollapsed ? '▸' : '▾';
            const moveButtons = variant === 'selected'
                ? `
                    <div class="btn-group btn-group-sm" role="group" aria-label="Riordina campo">
                        <button type="button" class="btn btn-outline-secondary" data-toggle-field-collapse="1" data-field-key="${fieldSelectionKey}" aria-label="Apri o chiudi dettagli">
                            <span data-collapse-icon="${fieldSelectionKey}">${collapseIcon}</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-move="up" data-field-key="${fieldSelectionKey}" aria-label="Sposta su">↑</button>
                        <button type="button" class="btn btn-outline-secondary" data-move="down" data-field-key="${fieldSelectionKey}" aria-label="Sposta giù">↓</button>
                        <button type="button" class="btn btn-outline-danger" data-remove-field="1" data-field-key="${fieldSelectionKey}" aria-label="Rimuovi campo">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>`
                : '';
            const fieldLabel = escapeHtml(field?.label || field?.nome || '');
            const fieldSourceTable = escapeHtml(field?.source_table_name || '');
            const fieldSourceFk = escapeHtml(field?.source_fk_name || '');
            const fieldSourceFkDesc = escapeHtml(field?.source_fk_descrittivo || '');
            const fieldSourceRelationLabel = escapeHtml(field?.source_relation_label || '');
            const fieldDisplayName = variant === 'selected' && fieldSourceRelationLabel
                ? fieldSourceRelationLabel
                : name;
            const fieldSelectedTitle = fieldSourceRelationLabel
                || (fieldSourceFk
                    ? `${fieldSourceFk}${fieldSourceFkDesc ? ' · ' + fieldSourceFkDesc : ''} -> ${fieldSourceTable || tableName}`
                    : fieldSourceTable || tableName);
            const fieldVisibleTable = field?.visible_table === true ? 'checked' : '';
            const fieldVisibleCard = field?.visible_card === true ? 'checked' : '';
            const fieldVisibleModal = field?.visible_modal === true ? 'checked' : '';
            const fieldFormat = String(field?.format || 'AUTOMATICO').toUpperCase();
            const formatMap = (() => {
                if (['int', 'integer', 'smallint', 'mediumint', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'].includes(rawType)) {
                    return [
                        'AUTOMATICO',
                        'NUMERO',
                        'NUMERO_MIGLIAIA',
                        'NUMERO_1',
                        'NUMERO_2',
                        'NUMERO_3',
                        'DATA_UNIX',
                    ];
                }

                if (['date', 'datetime', 'timestamp', 'time'].includes(rawType)) {
                    return [
                        'AUTOMATICO',
                        'DATA_BREVE',
                        'DATA_ESTESA',
                        'DATA_ITA',
                        'DATA_ENG',
                        'DATA_UNIX',
                    ];
                }

                return [
                    'AUTOMATICO',
                    'TESTO',
                    'TESTO_MAIUSCOLO',
                    'TESTO_MINUSCOLO',
                    'TESTO_TITOLO',
                    'MAIL',
                    'URL',
                    'FILE',
                ];
            })();
            const formatOptions = formatMap
                .map((format) => `<option value="${format}" ${fieldFormat === format ? 'selected' : ''}>${format}</option>`)
                .join('');

            return `
                <div class="list-group-item list-group-item-action px-0 py-2 ${variant === 'selected' ? 'bg-white' : ''}"
                     data-field-id="${escapeHtml(field?.id || '')}"
                     data-field-key="${escapeHtml(fieldSelectionKey)}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">${fieldDisplayName}</div>
                            <div class="small text-muted">${type}</div>
                            ${variant === 'selected' && (fieldSourceFk || fieldSourceFkDesc || fieldSourceTable)
                                ? `<div class="small text-info">${fieldSelectedTitle}</div>`
                                : ''}
                        </div>
                        <div class="d-flex flex-wrap gap-1 justify-content-end align-items-start">
                            ${trueBadges}
                            ${moveButtons}
                        </div>
                    </div>
                    ${variant === 'selected' ? `
                        <div class="row g-2 mt-2 ${selectedFieldCollapseState[fieldSelectionKey] ? 'd-none' : ''}" data-field-details="${fieldSelectionKey}">
                            <div class="col-12 col-md-6">
                                <label class="form-label form-label-sm mb-1">Etichetta</label>
                                <input type="text" class="form-control form-control-sm" data-field-prop="label" data-field-id="${fieldId}" data-field-key="${fieldSelectionKey}" value="${fieldLabel}">
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="row g-2">
                                    <div class="col-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" data-field-prop="visible_table" data-field-id="${fieldId}" data-field-key="${fieldSelectionKey}" ${fieldVisibleTable}>
                                            <label class="form-check-label">Scheda</label>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" data-field-prop="visible_card" data-field-id="${fieldId}" data-field-key="${fieldSelectionKey}" ${fieldVisibleCard}>
                                            <label class="form-check-label">Tabella</label>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" data-field-prop="visible_modal" data-field-id="${fieldId}" data-field-key="${fieldSelectionKey}" ${fieldVisibleModal}>
                                            <label class="form-check-label">Modale</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label form-label-sm mb-1">Formato</label>
                                <select class="form-select form-select-sm" data-field-prop="format" data-field-id="${fieldId}" data-field-key="${fieldSelectionKey}">
                                    ${formatOptions}
                                </select>
                            </div>
                        </div>
                    ` : ''}
                </div>`;
        };

        const availableItems = mainTableFieldState.fields
            .filter((field) => !mainTableFieldState.selectedFields.some((selected) =>
                Number(selected.id) === Number(field.id) && Number(selected.source_fk_id || 0) === 0
            ))
            .map((field) => renderFieldItem(field, 'available'))
            .join('');

        const selectedRelationCards = mainTableRelationState.relations
            .filter((relation) => mainTableRelationState.selectedRelationIds.includes(Number(relation?.fk_id || relation?.id || 0)))
            .map((relation) => renderLinkedTableCard(relation))
            .join('');

        const selectedItems = mainTableFieldState.selectedFields
            .map((field) => renderFieldItem(field, 'selected'))
            .join('');

        availableFields.innerHTML = `
            <div class="card border-primary-subtle bg-light" id="mainTableCard">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-primary">Tabella principale</span>
                        <div class="fw-bold text-primary fs-6 mb-0" id="mainTableFieldsLabel">${tableName}</div>
                    </div>
                    <div class="list-group list-group-flush" id="mainTableAvailableList">
                        ${availableItems || '<div class="text-muted small py-2">Nessun campo disponibile.</div>'}
                    </div>
                </div>
            </div>
            ${selectedRelationCards}
        `;

        selectedFields.innerHTML = `
            <div class="list-group list-group-flush" id="mainTableSelectedList">
                ${selectedItems || '<div class="text-muted text-center py-5" id="selectedPlaceholder">Trascinare qui i campi o fare doppio click.</div>'}
            </div>
        `;

        const availableList = document.getElementById('mainTableAvailableList');
        if (availableList) {
            availableList.querySelectorAll('[data-field-id]').forEach((item) => {
                item.addEventListener('dblclick', () => {
                    const fieldId = Number(item.getAttribute('data-field-id') || 0);
                    const field = mainTableFieldState.fields.find((entry) => Number(entry.id) === fieldId);
                    if (!field) return;

                    if (!mainTableFieldState.selectedFields.some((selected) => Number(selected.id) === fieldId)) {
                        mainTableFieldState.selectedFields = [...mainTableFieldState.selectedFields, {
                            ...field,
                            label: field?.label || field?.nome || '',
                            visible_table: field?.visible_table === true,
                            visible_card: field?.visible_card === true,
                            visible_modal: field?.visible_modal === true,
                            format: field?.format || 'AUTOMATICO',
                            source_table_name: mainTableFieldState.tableName || '',
                            table_id: Number(field?.table_id || field?.IDtabella || 0),
                        }];
                        renderMainTableFieldLists();
                    }
                });
            });
        }

        if (availableFields) {
            availableFields.querySelectorAll('[data-linked-field-id]').forEach((item) => {
                item.addEventListener('dblclick', () => {
                    const fieldId = Number(item.getAttribute('data-linked-field-id') || 0);
                    const sourceRelationId = Number(item.getAttribute('data-source-relation-id') || 0);
                    const field = mainTableRelationState.relations
                        .flatMap((relation) => Array.isArray(relation?.fields) ? relation.fields : [])
                        .find((entry) => Number(entry.id) === fieldId);
                    if (!field) return;

                    const selectionKey = getFieldSelectionKey(fieldId, sourceRelationId);
                    if (!mainTableFieldState.selectedFields.some((selected) => getSelectedFieldSelectionKey(selected) === selectionKey)) {
                        const sourceRelation = mainTableRelationState.relations.find((relation) =>
                            Number(relation?.fk_id || relation?.id || 0) === sourceRelationId
                        );
                        const sourceRelationLabel = buildRelationSourceLabel(sourceRelation);
                        ensureRelationSelected(sourceRelationId);
                        mainTableFieldState.selectedFields = [...mainTableFieldState.selectedFields, {
                            ...field,
                            label: field?.label || field?.nome || '',
                            visible_table: field?.visible_table === true,
                            visible_card: field?.visible_card === true,
                            visible_modal: field?.visible_modal === true,
                            format: field?.format || 'AUTOMATICO',
                            source_table_name: sourceRelation?.secondary_table_name || sourceRelation?.table_name || '',
                            source_fk_name: sourceRelation?.local_field_name || sourceRelation?.fk_nome || '',
                            source_fk_descrittivo: sourceRelation?.local_field_descrittivo || sourceRelation?.fk_nome_descrittivo || '',
                            source_relation_label: sourceRelationLabel,
                            source_fk_id: sourceRelationId,
                            selection_key: selectionKey,
                            table_id: Number(sourceRelation?.secondary_table_id || sourceRelation?.IDtabella || 0),
                        }];
                        renderMainTableFieldLists();
                    }
                });
            });
        }

        const selectedList = document.getElementById('mainTableSelectedList');
        if (selectedList) {
            selectedList.querySelectorAll('[data-field-prop]').forEach((control) => {
                const fieldKey = String(control.getAttribute('data-field-key') || '');
                const prop = String(control.getAttribute('data-field-prop') || '');
                const target = findSelectedFieldBySelectionKey(fieldKey);
                if (!target) return;

                if (control.tagName === 'INPUT' && control.type === 'text' && prop === 'label') {
                    control.addEventListener('input', () => {
                        target.label = control.value;
                        scheduleSqlPreviewRefresh();
                    });
                }

                if (control.tagName === 'INPUT' && control.type === 'checkbox' && ['visible_table', 'visible_card', 'visible_modal'].includes(prop)) {
                    control.addEventListener('change', () => {
                        target[prop] = control.checked;
                        scheduleSqlPreviewRefresh();
                    });
                }

                if (control.tagName === 'SELECT' && prop === 'format') {
                    control.addEventListener('change', () => {
                        target.format = control.value;
                        scheduleSqlPreviewRefresh();
                    });
                }
            });

            selectedList.querySelectorAll('button[data-move]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const move = String(button.getAttribute('data-move') || '');
                    const fieldKey = String(button.getAttribute('data-field-key') || '');
                    const index = mainTableFieldState.selectedFields.findIndex((entry) => getSelectedFieldSelectionKey(entry) === fieldKey);
                    if (index < 0) return;

                    if (move === 'up' && index > 0) {
                        const updated = [...mainTableFieldState.selectedFields];
                        [updated[index - 1], updated[index]] = [updated[index], updated[index - 1]];
                        mainTableFieldState.selectedFields = updated;
                        renderMainTableFieldLists();
                        scheduleSqlPreviewRefresh();
                    }

                    if (move === 'down' && index < mainTableFieldState.selectedFields.length - 1) {
                        const updated = [...mainTableFieldState.selectedFields];
                        [updated[index + 1], updated[index]] = [updated[index], updated[index + 1]];
                        mainTableFieldState.selectedFields = updated;
                        renderMainTableFieldLists();
                        scheduleSqlPreviewRefresh();
                    }
                });
            });

            selectedList.querySelectorAll('button[data-toggle-field-collapse="1"]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const fieldKey = String(button.getAttribute('data-field-key') || '');
                    if (!fieldKey) return;

                    const nextState = !selectedFieldCollapseState[fieldKey];
                    selectedFieldCollapseState = {
                        ...selectedFieldCollapseState,
                        [fieldKey]: nextState,
                    };
                    renderMainTableFieldLists();
                });
            });

            selectedList.querySelectorAll('button[data-remove-field="1"]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const fieldKey = String(button.getAttribute('data-field-key') || '');
                    if (!fieldKey) return;

                    mainTableFieldState.selectedFields = mainTableFieldState.selectedFields.filter(
                        (entry) => getSelectedFieldSelectionKey(entry) !== fieldKey
                    );
                    delete selectedFieldCollapseState[fieldKey];
                    renderMainTableFieldLists();
                    scheduleSqlPreviewRefresh();
                });
            });
        }

        scheduleSqlPreviewRefresh();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildPreviewPayload() {
        const mainTable = document.getElementById('mainTable');
        const tableId = Number(mainTable?.value || 0);
        const selectedTables = mainTableRelationState.relations
            .filter((relation) => mainTableRelationState.selectedRelationIds.includes(Number(relation?.fk_id || relation?.id || 0)))
            .map((relation) => ({
                table_id: Number(relation?.secondary_table_id || 0),
                fk_id: Number(relation?.fk_id || 0),
                join_type: String(relation?.join_type || relation?.tipo_join || 'LEFT').toUpperCase(),
            }));

        return {
            main_table_id: tableId,
            tables: selectedTables,
            fields: mainTableFieldState.selectedFields.map((field, index) => ({
                field_id: Number(field?.id || 0),
                label: String(field?.label || field?.nome || ''),
                visible_table: field?.visible_table === true ? 1 : 0,
                visible_card: field?.visible_card === true ? 1 : 0,
                visible_modal: field?.visible_modal === true ? 1 : 0,
                searchable: 1,
                sortable: 1,
                format: String(field?.format || 'AUTOMATICO'),
                order: index + 1,
                source_table_name: String(field?.source_table_name || ''),
                table_id: Number(field?.table_id || field?.source_table_id || 0),
                source_fk_id: Number(field?.source_fk_id || 0),
            })),
        };
    }

    function buildSavePayload() {
        syncSelectedFieldFlagsFromDom();

        const pageName = document.getElementById('pageName');
        const fileName = document.getElementById('fileName');
        const pageTitle = document.getElementById('pageTitle');
        const description = document.getElementById('pageDescription');
        const rowsPerPage = document.getElementById('rowsPerPage');
        const searchEnabled = document.getElementById('searchEnabled');
        const sortEnabled = document.getElementById('sortEnabled');
        const paginationEnabled = document.getElementById('paginationEnabled');
        const crudEnabled = document.getElementById('crudEnabled');
        const crudAdd = document.getElementById('crudAdd');
        const crudEdit = document.getElementById('crudEdit');
        const crudDelete = document.getElementById('crudDelete');
        const tipoId = document.getElementById('tipoId');
        const selectedOption = tipoId && tipoId.options ? tipoId.options[tipoId.selectedIndex] : null;

        return {
            page_name: String(pageName?.value || '').trim(),
            file_name: String(fileName?.value || '').trim(),
            title: String(pageTitle?.value || '').trim(),
            description: String(description?.value || '').trim(),
            view_type: String(selectedOption?.dataset?.code || '').toUpperCase() || 'TABELLA_MODALE',
            IDtipo: Number(tipoId?.value || 0),
            rows_per_page: Number(rowsPerPage?.value || 25),
            search_enabled: Boolean(searchEnabled?.checked),
            sort_enabled: Boolean(sortEnabled?.checked),
            pagination_enabled: Boolean(paginationEnabled?.checked),
            crud_enabled: Boolean(crudEnabled?.checked),
            crud_add: Boolean(crudAdd?.checked),
            crud_edit: Boolean(crudEdit?.checked),
            crud_delete: Boolean(crudDelete?.checked),
            main_table_id: Number(document.getElementById('mainTable')?.value || 0),
            tables: mainTableRelationState.relations
                .filter((relation) => mainTableRelationState.selectedRelationIds.includes(Number(relation?.fk_id || relation?.id || 0)))
                .map((relation) => ({
                    table_id: Number(relation?.secondary_table_id || 0),
                    fk_id: Number(relation?.fk_id || 0),
                    join_type: String(relation?.join_type || relation?.tipo_join || 'LEFT').toUpperCase(),
                })),
            fields: mainTableFieldState.selectedFields.map((field, index) => ({
                field_id: Number(field?.id || 0),
                label: String(field?.label || field?.nome || ''),
                visible_table: field?.visible_table === true ? 1 : 0,
                visible_card: field?.visible_card === true ? 1 : 0,
                visible_modal: field?.visible_modal === true ? 1 : 0,
                searchable: field?.searchable === true ? 1 : 0,
                sortable: field?.sortable === true ? 1 : 0,
                format: String(field?.format || 'AUTOMATICO'),
                order: index + 1,
                source_table_name: String(field?.source_table_name || ''),
                table_id: Number(field?.table_id || field?.source_table_id || 0),
                source_fk_id: Number(field?.source_fk_id || 0),
            })),
        };
    }

    async function refreshSqlPreview() {
        const sqlPreview = document.getElementById('sqlPreview');
        if (!sqlPreview) return;

        if (!buildPreviewPayload().main_table_id || !mainTableFieldState.selectedFields.length) {
            sqlPreview.textContent = 'Selezionare tabella e campi.';
            return;
        }

        sqlPreview.textContent = 'Generazione anteprima...';

        try {
            const previewUrl = new URL(window.location.href);
            previewUrl.searchParams.set('action', 'preview');

            const response = await fetch(previewUrl.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(buildPreviewPayload()),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data?.message || 'Impossibile generare l\'anteprima.');
            }

            sqlPreview.textContent = data.sql || '';
        } catch (error) {
            sqlPreview.textContent = String(error?.message || error);
        }
    }

    async function loadMainTableFields(mainTable) {
        const availableFields = document.getElementById('availableFields');
        const selectedOption = mainTable && mainTable.options ? mainTable.options[mainTable.selectedIndex] : null;
        const tableId = String(mainTable?.value || '').trim();
        const endpointUrl = new URL('pages/creatore_pagina.php', window.location.href);

        if (!availableFields) return null;

        if (!tableId) {
            updateMainTableFieldsLabel(mainTable);
            return {
                tableName: '',
                fields: [],
                relations: [],
            };
        }

        try {
            const response = await fetch(`${endpointUrl.toString()}?action=table_details&table_id=${encodeURIComponent(tableId)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            if (!data || !data.ok) {
                throw new Error(data?.message || 'Impossibile caricare i campi.');
            }

            const tableName = String(data.table?.nome || selectedOption?.textContent || '').trim();
            const fields = Array.isArray(data.fields) ? data.fields : [];
            mainTableFieldState = {
                tableName,
                fields,
                selectedFields: [],
            };
            selectedFieldCollapseState = {};
            mainTableRelationState.selectedRelationIds = [];
            mainTableRelationState.relations = Array.isArray(data.relations) ? data.relations : [];
            renderMainTableRelations(mainTableRelationState.relations);
            renderMainTableFieldLists();
            return {
                tableName,
                fields,
                relations: Array.isArray(data.relations) ? data.relations : [],
            };
        } catch (error) {
            availableFields.innerHTML = `
                <div class="card border-danger-subtle bg-light" id="mainTableCard">
                    <div class="card-body py-2 px-3">
                        <div class="fw-semibold text-danger" id="mainTableFieldsLabel">Errore caricamento tabella</div>
                        <div class="text-danger small mt-2">${String(error?.message || error)}</div>
                    </div>
                </div>
            `;
            return {
                tableName: '',
                fields: [],
                relations: [],
            };
        }
    }

    function bindPageDataSyncFields() {
        const pageName = document.getElementById('pageName');
        const fileName = document.getElementById('fileName');
        if (pageName && pageName.dataset.boundSync !== '1') {
            pageName.dataset.boundSync = '1';
            pageName.addEventListener('input', () => {
                if (fileName && !fileName.readOnly) {
                    const normalized = normalizeWithUnderscores(pageName.value);
                    fileName.value = normalized ? normalized + '.php' : '';
                }
            });
            pageName.addEventListener('blur', () => {
                normalizePageAndFileFields(pageName, fileName, document.getElementById('pageTitle'), pageName.value);
            });
        }

        if (fileName && fileName.dataset.boundSync !== '1') {
            fileName.dataset.boundSync = '1';
            fileName.addEventListener('blur', () => {
                normalizePageAndFileFields(pageName, fileName, document.getElementById('pageTitle'), fileName.value);
            });
        }
    }

    function bindTipoIdSelect() {
        const tipoId = document.getElementById('tipoId');
        const rowsPerPage = document.getElementById('rowsPerPage');
        if (!tipoId) return;

        const handleTipoChange = () => {
            syncPageHeaderAndTitle(tipoId);
            updateRowsPerPageByType(tipoId, rowsPerPage);
        };

        if (!tipoId.dataset.boundTypeSync) {
            tipoId.dataset.boundTypeSync = '1';
            tipoId.addEventListener('change', handleTipoChange);
        }

        handleTipoChange();
    }

    function bindMainTableSelect() {
        const mainTable = document.getElementById('mainTable');
        if (!mainTable || mainTable.dataset.boundMainTable === '1') return;

        mainTable.dataset.boundMainTable = '1';
        mainTable.addEventListener('change', () => {
            loadMainTableFields(mainTable);
        });

        loadMainTableFields(mainTable);
    }

    function bindRefreshPreviewButton() {
        const button = document.getElementById('refreshPreview');
        if (!button || button.dataset.boundRefreshPreview === '1') return;

        button.dataset.boundRefreshPreview = '1';
        button.addEventListener('click', () => {
            refreshSqlPreview();
        });
    }

    async function postCreatorAction(action, payload) {
        const endpoint = new URL(window.location.href);
        endpoint.searchParams.set('action', action);

        const response = await fetch(endpoint.toString(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const responseText = await response.text();
        let data = null;
        try {
            data = responseText ? JSON.parse(responseText) : null;
        } catch (_parseError) {
            data = null;
        }

        if (!response.ok || !data?.ok) {
            const backendMessage = String(data?.message || '').trim();
            const fallbackMessage = responseText.trim();
            throw new Error(
                backendMessage
                || fallbackMessage
                || `Operazione non riuscita (HTTP ${response.status}).`
            );
        }

        return data;
    }

    function setResultMessage(html) {
        const resultMessage = document.getElementById('resultMessage');
        if (resultMessage) {
            resultMessage.innerHTML = html;
        }
    }

    function getLoadDebugStorageKey() {
        const configId = Number(window.creatorePaginaContext?.configurationId || 0);
        return `creatore_pagina_load_debug_${configId > 0 ? configId : 'new'}`;
    }

    function saveLoadDebugSteps() {
        if (!window.sessionStorage) return;
        try {
            window.sessionStorage.setItem(
                getLoadDebugStorageKey(),
                JSON.stringify(window.__creatorePaginaLoadDebugSteps || [])
            );
        } catch (error) {
            // Persistenza diagnostica non bloccante.
        }
    }

    function restoreLoadDebugSteps() {
        if (!window.sessionStorage) return [];
        try {
            const raw = window.sessionStorage.getItem(getLoadDebugStorageKey());
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function clearLoadDebugSteps() {
        window.__creatorePaginaLoadDebugSteps = [];
        if (window.sessionStorage) {
            try {
                window.sessionStorage.removeItem(getLoadDebugStorageKey());
            } catch (error) {
                // Persistenza diagnostica non bloccante.
            }
        }

        const panel = document.getElementById('loadDebugPanel');
        const list = document.getElementById('loadDebugList');
        if (panel) {
            panel.className = 'alert alert-light border small mb-3';
        }
        if (list) {
            list.innerHTML = '';
        }
    }

    function setLoadReportSummary(lines = []) {
        const panel = document.getElementById('loadReportPanel');
        const title = document.getElementById('loadReportTitle');
        const list = document.getElementById('loadReportList');
        if (!panel || !list) return;

        if (title) {
            const contextMode = String(window.creatorePaginaContext?.mode || '').toLowerCase();
            title.textContent = contextMode === 'new'
                ? 'Riepilogo nuova configurazione'
                : 'Riepilogo caricamento configurazione';
        }

        const items = Array.isArray(lines)
            ? lines.filter((line) => String(line || '').trim() !== '')
            : [];

        list.innerHTML = items.length
            ? items.map((line, index) => (
                `<div class="${index === 0 ? 'fw-semibold' : ''} mb-1">${escapeHtml(String(line))}</div>`
            )).join('')
            : '<div class="text-muted">Nessun riepilogo disponibile.</div>';
    }

    function summarizeArray(values, maxItems = 8) {
        const items = Array.isArray(values) ? values : [];
        const preview = items.slice(0, maxItems).map((value) => {
            if (value === null || value === undefined) return 'null';
            if (typeof value === 'object') return JSON.stringify(value);
            return String(value);
        });
        const suffix = items.length > maxItems ? ` ... (+${items.length - maxItems})` : '';
        return `[${preview.join(', ')}]${suffix}`;
    }

    function summarizeObject(value) {
        if (!value || typeof value !== 'object') {
            return String(value ?? '');
        }

        return Object.keys(value)
            .slice(0, 16)
            .map((key) => `${key}=${typeof value[key] === 'object' ? JSON.stringify(value[key]) : String(value[key])}`)
            .join(' | ');
    }

    function summarizeFieldDetail(field) {
        return [
            `id=${Number(field?.id || 0)}`,
            `label=${String(field?.label || '')}`,
            `name=${String(field?.nome || '')}`,
            `tableId=${Number(field?.table_id || field?.source_table_id || 0)}`,
            `sourceFk=${Number(field?.source_fk_id || 0)}`,
            `visTable=${field?.visible_table === true ? 1 : 0}`,
            `visCard=${field?.visible_card === true ? 1 : 0}`,
            `search=${field?.searchable === true ? 1 : 0}`,
            `sort=${field?.sortable === true ? 1 : 0}`,
            `pk=${field?.is_pk === true ? 1 : 0}`,
            `fk=${field?.is_fk === true ? 1 : 0}`,
            `idx=${field?.is_index === true ? 1 : 0}`,
            `uq=${field?.is_unique === true ? 1 : 0}`,
        ].join(' | ');
    }

    function summarizeRelationDetail(relation) {
        return [
            `fkId=${Number(relation?.fk_id || relation?.id || 0)}`,
            `tableId=${Number(relation?.secondary_table_id || relation?.IDtabella || 0)}`,
            `table=${String(relation?.secondary_table_name || relation?.table_name || '')}`,
            `fk=${String(relation?.local_field_name || relation?.fk_nome || '')}`,
            `fkDesc=${String(relation?.local_field_descrittivo || relation?.fk_nome_descrittivo || '')}`,
            `join=${String(relation?.join_type || relation?.tipo_join || 'LEFT').toUpperCase()}`,
            `fields=${Array.isArray(relation?.fields) ? relation.fields.length : 0}`,
        ].join(' | ');
    }

    function summarizeTableDetail(table) {
        return [
            `tableId=${Number(table?.IDtabella || table?.table_id || 0)}`,
            `fkId=${Number(table?.IDforeign_key || table?.fk_id || 0)}`,
            `selected=${Number(table?.selezionata || 0)}`,
            `join=${String(table?.tipo_join || table?.join_type || 'LEFT').toUpperCase()}`,
            `table=${String(table?.tabella_nome || table?.secondary_table_name || '')}`,
        ].join(' | ');
    }

    async function copyLoadDebugSteps() {
        const steps = Array.isArray(window.__creatorePaginaLoadDebugSteps)
            ? window.__creatorePaginaLoadDebugSteps
            : [];
        const text = steps
            .slice()
            .reverse()
            .map((entry) => {
                const stamp = String(entry.timestamp || '').trim();
                const prefix = stamp ? `${stamp} ` : '';
                return `${prefix}[${String(entry.type || 'light').toUpperCase()}] ${String(entry.message || '')}`;
            })
            .join('\n');

        if (!text) return;

        try {
            await navigator.clipboard.writeText(text);
        } catch (error) {
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', 'readonly');
            helper.style.position = 'fixed';
            helper.style.left = '-9999px';
            document.body.appendChild(helper);
            helper.select();
            document.execCommand('copy');
            helper.remove();
        }
    }

    function setLoadDebug(message, type = 'light') {
        const panel = document.getElementById('loadDebugPanel');
        const list = document.getElementById('loadDebugList');
        if (!panel) return;

        const classes = {
            light: 'alert alert-light border small mb-3',
            info: 'alert alert-info small mb-3',
            success: 'alert alert-success small mb-3',
            warning: 'alert alert-warning small mb-3',
            danger: 'alert alert-danger small mb-3',
        };

        panel.className = classes[type] || classes.light;
        if (list) {
            if (!window.__creatorePaginaLoadDebugSteps) {
                window.__creatorePaginaLoadDebugSteps = [];
            }
            if (message) {
                window.__creatorePaginaLoadDebugSteps.push({
                    type,
                    message,
                    timestamp: new Date().toISOString().replace('T', ' ').replace('Z', ''),
                });
                saveLoadDebugSteps();
            }

            list.innerHTML = (window.__creatorePaginaLoadDebugSteps || [])
                .slice()
                .reverse()
                .map((entry, index) => {
                    const label = String(entry.type || 'light').toUpperCase();
                    const total = (window.__creatorePaginaLoadDebugSteps || []).length;
                    const isCompletion = entry.type === 'success';
                    const rowClass = isCompletion ? 'fw-semibold' : '';
                    const timestamp = entry.timestamp ? `<span class="text-muted me-2">${escapeHtml(entry.timestamp)}</span>` : '';
                    return `<div class="mb-1 ${rowClass}"><span class="badge text-bg-secondary me-2">${total - index}</span><span class="badge text-bg-${entry.type === 'success' ? 'success' : entry.type === 'danger' ? 'danger' : entry.type === 'warning' ? 'warning' : entry.type === 'info' ? 'info' : 'secondary'} me-2">${label}</span>${timestamp}${escapeHtml(entry.message || '')}</div>`;
                })
                .join('');
        } else {
            panel.textContent = message;
        }
    }

    function renderSaveReport(data, payload) {
        const verification = data?.verification || {};
        const analysis = Array.isArray(verification.analysis) ? verification.analysis : [];
        setLoadReportSummary([
            'Verifica configurazione eseguita.',
            `Configurazione ID: ${String(data?.configuration_id || payload?.configuration_id || window.creatorePaginaContext?.configurationId || '')}.`,
            `Stato verifica: ${String(verification.status || 'SALVATAGGIO_COMPLETATO')}.`,
            `Campi analizzati: ${analysis.length}.`,
        ]);
        const rows = analysis.length
            ? analysis.map((item) => `
                <tr>
                    <td>${escapeHtml(item.field_name || item.label || '')}</td>
                    <td>${escapeHtml(item.label || '')}</td>
                    <td class="text-center">${Number(item.visible_table || 0)}</td>
                    <td class="text-center">${Number(item.visible_card || 0)}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4" class="text-center text-muted">Nessun campo analizzato.</td></tr>';

        setResultMessage(`
            <div class="alert alert-success">
                <strong>Salvataggio completato e verificato</strong><br>
                Nome scheda: <code>${escapeHtml(data.file_name || payload.page_name || '')}</code><br>
                Configurazione ID: <code>${escapeHtml(data.configuration_id || '')}</code><br>
                Stato: <code>${escapeHtml(verification.status || 'SALVATAGGIO_COMPLETATO')}</code>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Campo</th>
                                <th>Etichetta</th>
                                <th class="text-center">Tabella</th>
                                <th class="text-center">Scheda</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows}
                        </tbody>
                    </table>
                </div>
            </div>
        `);
    }

    function bindSaveConfigButton() {
        const button = document.getElementById('saveConfigButton');
        if (!button || button.dataset.boundSaveConfig === '1') return;

        button.dataset.boundSaveConfig = '1';
        button.addEventListener('click', async () => {
            const tipoId = document.getElementById('tipoId');
            const selectedTipoOption = tipoId && tipoId.options ? tipoId.options[tipoId.selectedIndex] : null;
            const selectedTipoId = Number(tipoId?.value || 0);
            const selectedTipoCode = String(selectedTipoOption?.dataset?.code || '').trim();

            setLoadReportSummary([
                'Richiesta di salvataggio avviata.',
                'Controllo preliminare dei dati in corso.',
                'In attesa della conferma utente e della risposta del server.',
            ]);
            setLoadDebug('Avvio flusso Salva e verifica dati.', 'info');
            setLoadDebug(`Controllo tipo scheda: ID=${selectedTipoId} | code=${selectedTipoCode || 'non definito'}.`, 'info');

            if (!selectedTipoId || !selectedTipoCode) {
                setLoadReportSummary([
                    'Salvataggio non eseguito.',
                    'Manca la selezione del tipo di scheda.',
                    'Correggere il valore prima di riprovare.',
                ]);
                setResultMessage('<div class="alert alert-danger">Selezionare il tipo di scheda prima di salvare la configurazione.</div>');
                setLoadDebug('Blocco salvataggio: tipo scheda non selezionato.', 'danger');
                return;
            }

            const payload = buildSavePayload();
            setLoadDebug(`Payload costruito: page_name=${payload.page_name} | file_name=${payload.file_name} | main_table_id=${payload.main_table_id} | fields=${payload.fields.length} | tables=${payload.tables.length}.`, 'info');
            setLoadDebug(`Regole impostate: search=${Number(payload.search_enabled ? 1 : 0)} | sort=${Number(payload.sort_enabled ? 1 : 0)} | pagination=${Number(payload.pagination_enabled ? 1 : 0)} | crud=${Number(payload.crud_enabled ? 1 : 0)}.`, 'info');
            if (!payload.page_name) {
                setLoadReportSummary([
                    'Salvataggio non eseguito.',
                    'Manca il nome della pagina.',
                    'Correggere il valore prima di riprovare.',
                ]);
                setResultMessage('<div class="alert alert-danger">Indicare il nome della pagina.</div>');
                setLoadDebug('Blocco salvataggio: nome pagina mancante.', 'danger');
                return;
            }
            if (!payload.main_table_id) {
                setLoadReportSummary([
                    'Salvataggio non eseguito.',
                    'Manca la tabella principale.',
                    'Correggere il valore prima di riprovare.',
                ]);
                setResultMessage('<div class="alert alert-danger">Selezionare la tabella principale.</div>');
                setLoadDebug('Blocco salvataggio: tabella principale non selezionata.', 'danger');
                return;
            }
            if (!payload.fields.length) {
                setLoadReportSummary([
                    'Salvataggio non eseguito.',
                    'Nessun campo selezionato.',
                    'Correggere la selezione prima di riprovare.',
                ]);
                setResultMessage('<div class="alert alert-danger">Selezionare almeno un campo.</div>');
                setLoadDebug('Blocco salvataggio: nessun campo selezionato.', 'danger');
                return;
            }

            if (!window.confirm('Confermi il salvataggio e la verifica dei dati?')) {
                setLoadReportSummary([
                    'Salvataggio annullato.',
                    'La conferma utente non è stata accettata.',
                    'Nessuna modifica inviata al server.',
                ]);
                setLoadDebug('Salvataggio annullato dall’utente.', 'warning');
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Salvataggio...';
            setLoadDebug('Conferma ricevuta. Invio della configurazione al server in corso...', 'info');
            setLoadReportSummary([
                'Salvataggio confermato.',
                'Invio configurazione al server in corso.',
                'Verifica dati attesa dopo la risposta backend.',
            ]);

            try {
                const data = await postCreatorAction('save_configuration', payload);
                setLoadDebug(`Risposta backend ricevuta: configuration_id=${String(data?.configuration_id || '')} | status=${String(data?.verification?.status || '')}.`, 'info');
                if (window.creatorePaginaContext) {
                    window.creatorePaginaContext.configurationId = Number(data.configuration_id || window.creatorePaginaContext.configurationId || 0);
                }
                renderSaveReport(data, payload);
            } catch (error) {
                setLoadReportSummary([
                    'Salvataggio non completato.',
                    'Il server ha restituito un errore.',
                    'Controllare il blocco Debug per il dettaglio tecnico.',
                ]);
                setLoadDebug(`Errore durante salvataggio/verifica: ${String(error?.message || error)}`, 'danger');
                setResultMessage(`<div class="alert alert-danger">${escapeHtml(error?.message || error)}</div>`);
            } finally {
                button.disabled = false;
                button.innerHTML = originalHtml;
                setLoadDebug('Ripristino stato pulsante Salva e verifica dati completato.', 'info');
            }
        });
    }

    function bindGenerateButton() {
        const button = document.getElementById('generateButton');
        if (!button || button.dataset.boundGenerate === '1') return;

        button.dataset.boundGenerate = '1';
        button.addEventListener('click', async () => {
            const resultMessage = document.getElementById('resultMessage');
            if (resultMessage) {
                resultMessage.innerHTML = '';
            }

            const configurationId = Number(window.creatorePaginaContext?.configurationId || 0);
            if (!configurationId) {
                setLoadReportSummary([
                    'Generazione non eseguita.',
                    'Nessuna configurazione salvata disponibile.',
                    'Salvare prima con il primo pulsante.',
                ]);
                setLoadDebug('Blocco generazione: configuration_id assente.', 'danger');
                setResultMessage('<div class="alert alert-danger">Prima salvare e verificare la configurazione con il primo bottone.</div>');
                return;
            }

            if (!window.confirm('Confermi la lettura della configurazione salvata e la generazione del file PHP?')) {
                setLoadReportSummary([
                    'Generazione annullata.',
                    'La conferma utente non è stata accettata.',
                    'Nessun file è stato letto o scritto.',
                ]);
                setLoadDebug('Generazione annullata dall’utente prima del caricamento configurazione.', 'warning');
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generazione...';
            setLoadReportSummary([
                `Generazione avviata per configurazione ${configurationId}.`,
                'Lettura configurazione da generare in corso.',
                'Preparazione del payload di generazione in corso.',
            ]);
            setLoadDebug(`Avvio flusso Genera PHP e salva il file per configuration_id=${configurationId}.`, 'info');

            try {
                const loadEndpoint = new URL(window.location.href);
                loadEndpoint.searchParams.set('action', 'load_configuration');
                loadEndpoint.searchParams.set('configuration_id', String(configurationId));
                setLoadDebug(`Richiesta lettura configurazione inviata a ${loadEndpoint.pathname}?action=load_configuration&configuration_id=${configurationId}`, 'info');

                const loadResponse = await fetch(loadEndpoint.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                setLoadDebug(`Risposta lettura configurazione ricevuta: HTTP ${loadResponse.status} ${loadResponse.statusText}.`, 'info');
                const loadedData = await loadResponse.json();
                if (!loadResponse.ok || !loadedData?.ok) {
                    setLoadReportSummary([
                        `Generazione non completata per configurazione ${configurationId}.`,
                        'Impossibile leggere la configurazione salvata.',
                        'Controllare il debug per il dettaglio tecnico.',
                    ]);
                    setLoadDebug(`Lettura configurazione fallita: ${String(loadedData?.message || 'risposta non valida')}`, 'danger');
                    throw new Error(loadedData?.message || 'Impossibile leggere la configurazione salvata.');
                }
                setLoadDebug('Configurazione salvata letta correttamente. Costruzione payload di generazione in corso...', 'info');

                const loadedPayload = {
                    configuration_id: configurationId,
                    page_name: String(loadedData.page?.nome_pagina || ''),
                    file_name: String(loadedData.page?.nome_file || ''),
                    title: String(loadedData.page?.titolo_pagina || ''),
                    description: String(loadedData.page?.descrizione || ''),
                    rows_per_page: Number(loadedData.page?.righe_per_pagina || 25),
                    search_enabled: Number(loadedData.page?.ricerca_abilitata || 0) === 1,
                    sort_enabled: Number(loadedData.page?.ordinamento_abilitato || 0) === 1,
                    pagination_enabled: Number(loadedData.page?.paginazione_abilitata || 0) === 1,
                    crud_enabled: Number(loadedData.page?.crud_abilitato || 0) === 1,
                    crud_add: Number(loadedData.page?.crud_aggiungi || 0) === 1,
                    crud_edit: Number(loadedData.page?.crud_modifica || 0) === 1,
                    crud_delete: Number(loadedData.page?.crud_cancella || 0) === 1,
                    view_type: String(loadedData.page?.tipo_visualizzazione || ''),
                    IDtipo: Number(loadedData.page?.IDtipo || 0),
                    main_table_id: Number(loadedData.page?.IDtabella_principale || 0),
                    tables: Array.isArray(loadedData.tables)
                        ? loadedData.tables
                            .filter((table) => Number(table?.selezionata || 0) === 1)
                            .map((table) => ({
                                table_id: Number(table?.IDtabella || table?.table_id || 0),
                                fk_id: Number(table?.IDforeign_key || table?.fk_id || 0),
                                join_type: String(table?.tipo_join || table?.join_type || 'LEFT').toUpperCase(),
                            }))
                        : [],
                    fields: Array.isArray(loadedData.fields)
                        ? loadedData.fields.map((field) => ({
                            field_id: Number(field?.IDcampo || field?.id || 0),
                            fieldId: Number(field?.IDcampo || field?.id || 0),
                            label: String(field?.etichetta || field?.label || field?.campo_nome || ''),
                            visible_table: Number(field?.visibile_tabella ?? field?.visible_table ?? 0) === 1 ? 1 : 0,
                            visible_card: Number(field?.visibile_scheda ?? field?.visible_card ?? 0) === 1 ? 1 : 0,
                            searchable: Number(field?.ricercabile ?? field?.searchable ?? 0) === 1 ? 1 : 0,
                            sortable: Number(field?.ordinabile ?? field?.sortable ?? 0) === 1 ? 1 : 0,
                            format: String(field?.formato_visualizzazione || field?.format || 'AUTOMATICO'),
                            order: Number(field?.ordine || field?.order || 0),
                            source_table_name: String(field?.tabella_nome || field?.table_name || ''),
                            table_id: Number(field?.IDtabella || field?.table_id || 0),
                            source_fk_id: Number(field?.source_fk_id || 0),
                    }))
                        : [],
                };
                setLoadDebug(`Payload generazione costruito: page_name=${loadedPayload.page_name} | file_name=${loadedPayload.file_name} | main_table_id=${loadedPayload.main_table_id} | fields=${loadedPayload.fields.length} | tables=${loadedPayload.tables.length}.`, 'info');
                setLoadDebug(`Regole di generazione: search=${Number(loadedPayload.search_enabled ? 1 : 0)} | sort=${Number(loadedPayload.sort_enabled ? 1 : 0)} | pagination=${Number(loadedPayload.pagination_enabled ? 1 : 0)} | CRUD=${Number(loadedPayload.crud_enabled ? 1 : 0)}.`, 'info');
                setLoadReportSummary([
                    `Configurazione ${configurationId} letta correttamente.`,
                    `Campi nel payload: ${loadedPayload.fields.length}.`,
                    `Relazioni nel payload: ${loadedPayload.tables.length}.`,
                ]);

                const data = await postCreatorAction('save_generate', loadedPayload);
                setLoadDebug(`Risposta generazione ricevuta: configuration_id=${String(data?.configuration_id || '')} | file_name=${String(data?.file_name || '')} | file_path=${String(data?.file_path || '')}.`, 'info');
                setResultMessage(`
                    <div class="alert alert-success">
                        <strong>Generazione completata</strong><br>
                        Nome scheda: <code>${escapeHtml(data.file_name || loadedPayload.page_name || '')}</code><br>
                        Versione: <code>${escapeHtml(data.generated_page_version || '')}</code><br>
                        Percorso: <code>${escapeHtml(data.file_path || '')}</code>
                    </div>
                `);
                setLoadReportSummary([
                    `Generazione completata per configurazione ${configurationId}.`,
                    `File creato: ${String(data?.file_name || loadedPayload.page_name || '')}.`,
                    `Percorso file: ${String(data?.file_path || '') || 'non disponibile'}.`,
                ]);
                setLoadDebug('File PHP generato e salvataggio completato con successo.', 'success');
            } catch (error) {
                setLoadReportSummary([
                    `Generazione non completata per configurazione ${configurationId}.`,
                    'Il salvataggio del file non è riuscito.',
                    'Controllare il blocco Debug per il dettaglio tecnico.',
                ]);
                setLoadDebug(`Errore durante generazione/salvataggio file: ${String(error?.message || error)}`, 'danger');
                setResultMessage(`<div class="alert alert-danger">${escapeHtml(error?.message || error)}</div>`);
            } finally {
                button.disabled = false;
                button.innerHTML = originalHtml;
                setLoadDebug('Ripristino stato pulsante Genera PHP e salva il file completato.', 'info');
            }
        });
    }

    function syncCrudCheckboxDefaults() {
        const crudEnabled = document.getElementById('crudEnabled');
        const crudAdd = document.getElementById('crudAdd');
        const crudEdit = document.getElementById('crudEdit');
        const crudDelete = document.getElementById('crudDelete');

        if (crudEnabled && !crudEnabled.checked) {
            crudAdd.checked = false;
            crudEdit.checked = false;
            crudDelete.checked = false;
            return;
        }

        if (crudEnabled && !crudEnabled.dataset.initialized) {
            crudEnabled.dataset.initialized = '1';
            crudAdd.checked = Boolean(crudAdd?.checked);
            crudEdit.checked = Boolean(crudEdit?.checked);
            crudDelete.checked = Boolean(crudDelete?.checked);
        }
    }

    function resetCreatorStateOnNewMode() {
        const contextMode = String(window.creatorePaginaContext?.mode || '').toLowerCase();
        if (contextMode !== 'new') {
            return;
        }

        const crudEnabled = document.getElementById('crudEnabled');
        const crudAdd = document.getElementById('crudAdd');
        const crudEdit = document.getElementById('crudEdit');
        const crudDelete = document.getElementById('crudDelete');
        const pageName = document.getElementById('pageName');
        const fileName = document.getElementById('fileName');
        const pageTitle = document.getElementById('pageTitle');
        const pageDescription = document.getElementById('pageDescription');
        const mainTable = document.getElementById('mainTable');
        const tipoId = document.getElementById('tipoId');
        const rowsPerPage = document.getElementById('rowsPerPage');

        if (pageName) pageName.value = '';
        if (fileName) fileName.value = '';
        if (pageTitle) pageTitle.value = '';
        if (pageDescription) pageDescription.value = '';
        if (mainTable) mainTable.selectedIndex = 0;
        if (tipoId) tipoId.selectedIndex = 0;
        if (rowsPerPage) rowsPerPage.value = rowsPerPage.getAttribute('value') || '25';

        if (crudEnabled) crudEnabled.checked = false;
        if (crudAdd) crudAdd.checked = false;
        if (crudEdit) crudEdit.checked = false;
        if (crudDelete) crudDelete.checked = false;
    }

    async function loadCreatorConfigurationOnEditMode() {
        const contextMode = String(window.creatorePaginaContext?.mode || '').toLowerCase();
        const configurationId = Number(window.creatorePaginaContext?.configurationId || 0);
        if (contextMode !== 'edit' || configurationId <= 0) {
            setLoadReportSummary([
                'Modalità nuova o configurazione assente.',
                'Nessun caricamento eseguito.',
            ]);
            setLoadDebug('Modalità nuova o configurazione assente. Nessun caricamento eseguito.', 'info');
            return;
        }

        window.__creatorePaginaLoadDebugSteps = restoreLoadDebugSteps();
        setLoadReportSummary([
            `Avvio caricamento configurazione ${configurationId}.`,
            'Richiesta in corso al server.',
            'In attesa della risposta e dell’applicazione dei dati.',
        ]);
        setLoadDebug(`Avvio caricamento configurazione ${configurationId}...`, 'info');
        const endpoint = new URL(window.location.href);
        endpoint.searchParams.set('action', 'load_configuration');
        endpoint.searchParams.set('configuration_id', String(configurationId));
        setLoadDebug(`Richiesta inviata a ${endpoint.pathname}?action=load_configuration&configuration_id=${configurationId}`, 'info');

        const abortController = new AbortController();
        const timeoutId = window.setTimeout(() => abortController.abort(), 15000);

        let response;
        try {
            response = await fetch(endpoint.toString(), {
            headers: { 'Accept': 'application/json' },
                signal: abortController.signal,
            });
        } catch (networkError) {
            if (abortController.signal.aborted) {
                setLoadDebug('Timeout durante la richiesta di caricamento configurazione (15 secondi).', 'danger');
                throw new Error('Timeout durante il caricamento della configurazione.');
            }

            setLoadDebug(`Errore di rete durante il caricamento: ${String(networkError?.message || networkError)}`, 'danger');
            throw networkError;
        } finally {
            window.clearTimeout(timeoutId);
        }
        setLoadDebug(`Risposta HTTP ricevuta: ${response.status} ${response.statusText}`, 'info');
        let data;
        try {
            data = await response.json();
        } catch (parseError) {
            setLoadDebug('Risposta non valida dal server durante il caricamento.', 'danger');
            setLoadReportSummary([
                `Lettura configurazione ${configurationId} non completata.`,
                'La risposta del server non è valida.',
                'Controllare il debug per i dettagli tecnici.',
            ]);
            throw new Error('Risposta non valida durante il caricamento della configurazione.');
        }
        if (!response.ok || !data?.ok) {
            setLoadReportSummary([
                `Lettura configurazione ${configurationId} non completata.`,
                `Stato HTTP: ${response.status} ${response.statusText}.`,
                'Controllare il debug per i dettagli dell’errore.',
            ]);
            setLoadDebug(data?.message || 'Errore nel caricamento della configurazione.', 'danger');
            throw new Error(data?.message || 'Impossibile caricare la configurazione.');
        }

        setLoadDebug('Configurazione letta correttamente. Applicazione dei dati in corso...', 'info');
        setLoadDebug(`Chiavi risposta: ${summarizeArray(Object.keys(data || {}))}`, 'info');

        const page = data.page || {};
        const tables = Array.isArray(data.tables) ? data.tables : [];
        const fields = Array.isArray(data.fields) ? data.fields : [];
        setLoadDebug(`Page summary: ${summarizeObject(page)}`, 'info');
        setLoadDebug(`Numero tabelle lette: ${tables.length}`, 'info');
        setLoadDebug(`Numero campi letti: ${fields.length}`, 'info');
        setLoadDebug(`Prime 5 tabelle: ${summarizeArray(tables.slice(0, 5).map(summarizeTableDetail), 5)}`, 'info');
        setLoadDebug(`Primi 5 campi grezzi: ${summarizeArray(fields.slice(0, 5).map(summarizeObject), 5)}`, 'info');

        const pageName = document.getElementById('pageName');
        const fileName = document.getElementById('fileName');
        const pageTitle = document.getElementById('pageTitle');
        const pageDescription = document.getElementById('pageDescription');
        const rowsPerPage = document.getElementById('rowsPerPage');
        const tipoId = document.getElementById('tipoId');
        const mainTable = document.getElementById('mainTable');
        const searchEnabled = document.getElementById('searchEnabled');
        const sortEnabled = document.getElementById('sortEnabled');
        const paginationEnabled = document.getElementById('paginationEnabled');
        const crudEnabled = document.getElementById('crudEnabled');
        const crudAdd = document.getElementById('crudAdd');
        const crudEdit = document.getElementById('crudEdit');
        const crudDelete = document.getElementById('crudDelete');

        if (pageName) pageName.value = String(page.nome_pagina || '');
        if (fileName) fileName.value = String(page.nome_file || '');
        if (pageTitle) pageTitle.value = String(page.titolo_pagina || '');
        if (pageDescription) pageDescription.value = String(page.descrizione || '');
        if (rowsPerPage) rowsPerPage.value = String(page.righe_per_pagina || 25);
        if (searchEnabled) searchEnabled.checked = Number(page.ricerca_abilitata || 0) === 1;
        if (sortEnabled) sortEnabled.checked = Number(page.ordinamento_abilitato || 0) === 1;
        if (paginationEnabled) paginationEnabled.checked = Number(page.paginazione_abilitata || 0) === 1;
        if (crudEnabled) crudEnabled.checked = Number(page.crud_abilitato || 0) === 1;
        if (crudAdd) crudAdd.checked = Number(page.crud_aggiungi || 0) === 1;
        if (crudEdit) crudEdit.checked = Number(page.crud_modifica || 0) === 1;
        if (crudDelete) crudDelete.checked = Number(page.crud_cancella || 0) === 1;

        const selectedTypeId = Number(page.IDtipo || page.tipo_id || 0);
        if (tipoId && selectedTypeId) {
            tipoId.value = String(selectedTypeId);
            setLoadDebug(`Tipo scheda impostato su ID ${selectedTypeId}.`, 'info');
        }
        setLoadDebug(`Campi base applicati: pageName=${String(pageName?.value || '')} | fileName=${String(fileName?.value || '')} | rowsPerPage=${String(rowsPerPage?.value || '')}.`, 'info');

        let loadedMainTableState = {
            tableName: '',
            fields: [],
            relations: [],
        };

        if (mainTable && Number(page.IDtabella_principale || 0) > 0) {
            mainTable.value = String(page.IDtabella_principale);
            try {
                setLoadDebug(`Caricamento tabella principale ID ${page.IDtabella_principale}...`, 'info');
                loadedMainTableState = await loadMainTableFields(mainTable) || loadedMainTableState;
                setLoadDebug(`Risultato tabella principale: tableName=${loadedMainTableState.tableName || ''} | fields=${Array.isArray(loadedMainTableState.fields) ? loadedMainTableState.fields.length : 0} | relations=${Array.isArray(loadedMainTableState.relations) ? loadedMainTableState.relations.length : 0}`, 'info');
                setLoadDebug('Tabella principale caricata. Ricostruzione campi e relazioni in corso...', 'info');
            } catch (tableError) {
                setLoadDebug(`Tabella principale caricata con avviso: ${String(tableError?.message || tableError)}`, 'warning');
                loadedMainTableState = loadedMainTableState || {
                    tableName: '',
                    fields: [],
                    relations: [],
                };
            }
        }

        const selectedFieldRowIds = new Set(
            fields.map((field) => Number(field?.field_row_id || field?.IDpagina_tabella || field?.id || 0))
        );
        const selectedRelationIds = new Set(
            tables
                .filter((table) => Number(table?.selezionata || 0) === 1)
                .map((table) => Number(table?.IDforeign_key || table?.fk_id || 0))
                .filter((id) => id > 0)
        );

        const relationsByFk = new Map(
            (loadedMainTableState.relations || []).map((relation) => [
                Number(relation?.fk_id || relation?.id || 0),
                relation,
            ])
        );

        mainTableRelationState.selectedRelationIds = Array.from(selectedRelationIds);
        setLoadDebug(`Relazioni selezionate lette dal DB: ${mainTableRelationState.selectedRelationIds.length}.`, 'info');
        mainTableRelationState.relations = tables
            .filter((table) => String(table?.tipo_tabella || '').toUpperCase() !== 'PRINCIPALE')
            .map((table) => {
            const fkId = Number(table?.IDforeign_key || table?.fk_id || 0);
            const baseRelation = relationsByFk.get(fkId) || {};
            return {
                ...baseRelation,
                ...table,
                fk_id: fkId,
                secondary_table_id: Number(table?.IDtabella || table?.secondary_table_id || baseRelation?.secondary_table_id || 0),
                secondary_table_name: String(table?.tabella_nome || table?.secondary_table_name || baseRelation?.secondary_table_name || ''),
                local_field_name: String(table?.fk_nome || baseRelation?.local_field_name || ''),
                local_field_descrittivo: String(table?.fk_nome_descrittivo || baseRelation?.local_field_descrittivo || ''),
                relation_label: String(table?.relation_label || baseRelation?.relation_label || ''),
                join_type: String(table?.tipo_join || table?.join_type || baseRelation?.join_type || 'LEFT').toUpperCase(),
                fields: Array.isArray(baseRelation?.fields) ? baseRelation.fields : (Array.isArray(table?.fields) ? table.fields : []),
            };
        });
        setLoadDebug(`Relazioni ricostruite per il rendering: ${mainTableRelationState.relations.length}.`, 'info');
        setLoadDebug(`Relazioni selezionate: ${summarizeArray(Array.from(mainTableRelationState.selectedRelationIds))}`, 'info');
        setLoadDebug(`Dettaglio relazioni: ${summarizeArray(mainTableRelationState.relations.slice(0, 10).map(summarizeRelationDetail), 10)}`, 'info');

        mainTableFieldState.selectedFields = fields
            .filter((field) => selectedFieldRowIds.has(Number(field?.field_row_id || field?.id || 0)))
            .map((field, index) => ({
                source_fk_id: Number(field?.source_fk_id || field?.IDforeign_key || 0),
                source_relation_label: String(
                    field?.source_relation_label
                    || relationsByFk.get(Number(field?.source_fk_id || field?.IDforeign_key || 0))?.relation_label
                    || relationsByFk.get(Number(field?.source_fk_id || field?.IDforeign_key || 0))?.local_field_name
                    || relationsByFk.get(Number(field?.source_fk_id || field?.IDforeign_key || 0))?.fk_nome
                    || ''
                ),
                id: Number(field?.IDcampo || field?.id || 0),
                label: String(field?.etichetta || field?.label || field?.campo_nome || ''),
                nome: String(field?.campo_nome || field?.nome || ''),
                field_type: String(field?.campo_tipo || field?.field_type || field?.tipo || ''),
                tipo: String(field?.campo_tipo || field?.field_type || field?.tipo || ''),
                format: String(field?.formato_visualizzazione || field?.format || 'AUTOMATICO'),
                visible_table: Number(field?.visibile_tabella ?? field?.visible_table ?? 0) === 1,
                visible_card: Number(field?.visibile_scheda ?? field?.visible_card ?? 0) === 1,
                source_table_name: String(field?.tabella_nome || field?.source_table_name || ''),
                source_table_id: Number(field?.IDtabella || field?.table_id || 0),
                table_id: Number(field?.IDtabella || field?.table_id || 0),
                selection_key: String(field?.source_fk_id || field?.IDforeign_key || 0) + ':' + Number(field?.IDcampo || field?.id || 0),
                field_row_id: Number(field?.field_row_id || field?.id || 0),
                is_pk: Number(field?.is_pk || 0) === 1,
                is_fk: Number(field?.is_fk || 0) === 1,
                is_index: Number(field?.is_index || 0) === 1,
                is_unique: Number(field?.is_unique || 0) === 1,
                order: index + 1,
            }));
        setLoadDebug(`Campi selezionati letti dal DB: ${mainTableFieldState.selectedFields.length}.`, 'info');
        setLoadDebug(`Campi selezionati: ${summarizeArray(mainTableFieldState.selectedFields.map((field) => `${field.id}:${field.label}`), 12)}`, 'info');
        setLoadDebug(`Dettaglio campi selezionati: ${summarizeArray(mainTableFieldState.selectedFields.slice(0, 12).map(summarizeFieldDetail), 12)}`, 'info');

        mainTableFieldState.selectedFields.forEach((field) => {
            if (Number(field?.source_fk_id || 0) > 0) {
                ensureRelationSelected(field.source_fk_id);
            }
        });

        if (loadedMainTableState.fields && loadedMainTableState.fields.length) {
            const selectedMainFieldIds = new Set(mainTableFieldState.selectedFields.map((field) => Number(field.id)));
            mainTableFieldState.fields = loadedMainTableState.fields.map((field) => ({
                ...field,
                source_table_name: String(field?.source_table_name || loadedMainTableState.tableName || ''),
                source_fk_id: Number(field?.source_fk_id || 0),
                visible_table: field?.visible_table === true,
                visible_card: field?.visible_card === true,
            }));
            setLoadDebug(`Campi tabella principale caricati: ${mainTableFieldState.fields.length}. Selezionati nella tabella: ${selectedMainFieldIds.size}.`, 'info');
            setLoadDebug(`Dettaglio campi tabella principale: ${summarizeArray(mainTableFieldState.fields.slice(0, 12).map(summarizeFieldDetail), 12)}`, 'info');
        }

        selectedFieldCollapseState = {};
        renderMainTableRelations(mainTableRelationState.relations);
        renderMainTableFieldLists();
        setLoadDebug(`Rendering completato: ${mainTableRelationState.relations.length} relazioni disponibili, ${mainTableFieldState.fields.length || 0} campi tabella principale.`, 'info');
        setLoadDebug(`Stato finale rendering: selectedFields=${mainTableFieldState.selectedFields.length} | availableFields=${mainTableFieldState.fields.length || 0} | selectedRelations=${mainTableRelationState.selectedRelationIds.length}`, 'info');
        syncCrudCheckboxDefaults();
        if (tipoId) {
            syncPageHeaderAndTitle(tipoId);
            updateRowsPerPageByType(tipoId, rowsPerPage);
        }
        setLoadDebug(
            `Caricamento completato: ${mainTableFieldState.selectedFields.length} campi selezionati, ${mainTableRelationState.selectedRelationIds.length} relazioni attive.`,
            'success'
        );
        setLoadDebug(`Persistenza report aggiornata: chiave ${getLoadDebugStorageKey()}.`, 'info');
        saveLoadDebugSteps();
        setLoadReportSummary([
            `Configurazione ${configurationId} letta con successo.`,
            `Campi selezionati: ${mainTableFieldState.selectedFields.length}.`,
            `Relazioni attive: ${mainTableRelationState.selectedRelationIds.length}.`,
        ]);
    }

    async function bootstrapCreatorPage() {
        if (window.__creatorePaginaBootstrapped) {
            return;
        }
        window.__creatorePaginaBootstrapped = true;

        window.__creatorePaginaLoadDebugSteps = restoreLoadDebugSteps();
        setLoadDebug('Bootstrap avviato.', 'info');
        resetCreatorStateOnNewMode();
        setLoadDebug('Stato nuova configurazione verificato.', 'info');
        bindPageDataSyncFields();
        bindTipoIdSelect();
        bindMainTableSelect();
        bindRefreshPreviewButton();
        bindSaveConfigButton();
        bindGenerateButton();
        syncCrudCheckboxDefaults();
        setLoadDebug('Eventi UI collegati.', 'info');

        const loadDebugResetButton = document.getElementById('loadDebugResetButton');
        if (loadDebugResetButton && loadDebugResetButton.dataset.boundDebugReset !== '1') {
            loadDebugResetButton.dataset.boundDebugReset = '1';
            loadDebugResetButton.addEventListener('click', () => {
                clearLoadDebugSteps();
                setLoadDebug('Report azzerato.', 'info');
            });
        }

        const loadDebugCopyButton = document.getElementById('loadDebugCopyButton');
        if (loadDebugCopyButton && loadDebugCopyButton.dataset.boundDebugCopy !== '1') {
            loadDebugCopyButton.dataset.boundDebugCopy = '1';
            loadDebugCopyButton.addEventListener('click', async () => {
                try {
                    await copyLoadDebugSteps();
                    setLoadDebug('Report copiato negli appunti.', 'success');
                } catch (error) {
                    setLoadDebug(`Impossibile copiare il report: ${String(error?.message || error)}`, 'danger');
                }
            });
        }

        try {
            await loadCreatorConfigurationOnEditMode();
        } catch (error) {
            const resultMessage = document.getElementById('resultMessage');
            if (resultMessage) {
                resultMessage.innerHTML = `<div class="alert alert-danger">${escapeHtml(error?.message || error)}</div>`;
            }
            setLoadDebug(`Errore bootstrap: ${String(error?.message || error)}`, 'danger');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        bootstrapCreatorPage();
    }, { once: true });

    window.addEventListener('load', () => {
        bootstrapCreatorPage();
    }, { once: true });

    window.setTimeout(() => {
        bootstrapCreatorPage();
    }, 0);
})();
</script>
