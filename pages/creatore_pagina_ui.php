<?php
/**
 * creatore_pagina_ui.php
 *
 * Rendering di supporto per la pagina creatore_pagina.
 */

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Accesso diretto non consentito.');
}

function renderCreatorePaginaCrudField(
    array $field,
    mixed $value,
    array $dropdowns
): void {
    $name = (string) $field['field_name'];
    $label = trim((string) ($field['label'] ?? '')) !== ''
        ? (string) $field['label']
        : $name;
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeValue = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    $required = !empty($field['required']) ? 'required' : '';

    $requiredMark = !empty($field['required'])
        ? '<span class="required-mark" title="Campo obbligatorio" aria-label="obbligatorio">*</span>'
        : '';

    echo '<label class="form-label">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . $requiredMark
        . '</label>';

    if (!empty($field['fk'])) {
        $fkTable = htmlspecialchars(
            normalizeRelatedTableName((string) ($field['fk']['referenced_table_name'] ?? '')),
            ENT_QUOTES,
            'UTF-8'
        );
        $fkValueField = htmlspecialchars((string) ($field['fk']['referenced_field_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $fkLabelField = htmlspecialchars((string) ($field['fk']['description_field_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $fkPanelId = 'fkRelatedPanel_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $name);

        echo '<div class="fk-field-wrap">';
        echo '<select class="form-select fk-select" '
            . 'name="crud[' . $safeName . ']" '
            . 'data-fk-field="' . $safeName . '" '
            . 'data-fk-table="' . $fkTable . '" '
            . 'data-fk-value-field="' . $fkValueField . '" '
            . 'data-fk-label-field="' . $fkLabelField . '" '
            . 'data-fk-panel="' . htmlspecialchars($fkPanelId, ENT_QUOTES, 'UTF-8') . '" '
            . $required . '>';
        echo '<option value="">-- selezionare --</option>';
        foreach ($dropdowns[$name] ?? [] as $option) {
            $selected = (string) ($option['option_value'] ?? '') === (string) ($value ?? '')
                ? ' selected'
                : '';
            echo '<option value="'
                . htmlspecialchars((string) ($option['option_value'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '"' . $selected . '>'
                . htmlspecialchars((string) ($option['option_label'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        echo '</select>';
        echo '<div class="d-flex flex-wrap gap-2 mt-2">';
        echo '<button type="button" class="btn btn-outline-success btn-sm js-fk-inline-create">'
            . '<i class="bi bi-plus-lg me-1"></i>Nuovo collegato'
            . '</button>';
        echo '<button type="button" class="btn btn-outline-warning btn-sm js-fk-inline-edit" disabled>'
            . '<i class="bi bi-pencil me-1"></i>Modifica selezionato'
            . '</button>';
        echo '</div>';
        echo '<div class="fk-related-inline-panel border rounded p-3 mt-2 d-none" id="'
            . htmlspecialchars($fkPanelId, ENT_QUOTES, 'UTF-8')
            . '"></div>';
        echo '</div>';
        return;
    }

    $type = (string) $field['field_type'];

    if ($type === 'text' || $type === 'json') {
        echo '<textarea class="form-control" rows="3" name="crud[' . $safeName . ']" '
            . $required . '>' . $safeValue . '</textarea>';
        return;
    }

    if ($type === 'boolean' || $type === 'tinyint') {
        echo '<select class="form-select" name="crud[' . $safeName . ']" ' . $required . '>';
        echo '<option value="">-- selezionare --</option>';
        echo '<option value="1"' . ((string) $value === '1' ? ' selected' : '') . '>Sì</option>';
        echo '<option value="0"' . ((string) $value === '0' ? ' selected' : '') . '>No</option>';
        echo '</select>';
        return;
    }

    $inputType = match ($type) {
        'date' => 'date',
        'datetime', 'timestamp' => 'datetime-local',
        'int', 'smallint', 'bigint', 'decimal', 'float', 'double' => 'number',
        default => 'text',
    };

    if (in_array($type, ['datetime', 'timestamp'], true) && $value) {
        $timestamp = strtotime((string) $value);
        if ($timestamp) {
            $safeValue = date('Y-m-d\TH:i', $timestamp);
        }
    }

    $step = in_array($type, ['decimal', 'float', 'double'], true)
        ? ' step="any"'
        : '';

    echo '<input type="' . $inputType . '" class="form-control" name="crud['
        . $safeName . ']" value="' . $safeValue . '"' . $step . ' ' . $required . '>';
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
                        <?php if ($modalEnabled && $modalConfig): ?>
                            <?php foreach ($rows as $rowIndex => $row): ?>
                                <?php
                                $modalRows = $modalDataByRow[$rowIndex] ?? [];
                                $modalRow = $modalRows[0] ?? null;
                                $modalParentValue = $row[$modalConfig['main_value_alias']] ?? null;
                                $modalCollapseId = 'recordInline' . $rowIndex;
                                ?>
                                <tr class="table-secondary">
                                    <td colspan="<?= count($visibleFields) + (($hasModalDetail || ($crudEnabled && ($crudEdit || $crudDelete))) ? 1 : 0) ?>">
                                        <div class="collapse mt-3" id="<?= htmlspecialchars($modalCollapseId, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="border rounded p-3 bg-info-subtle">
                                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                                    <div>
                                                        <strong><?= htmlspecialchars((string) ($modalConfig['title'] ?? 'Scheda collegata'), ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <div class="small text-muted">Dettaglio record collegati</div>
                                                    </div>
                                                </div>

                                                <?php if (!$modalRow): ?>
                                                    <div class="alert alert-secondary mb-0">
                                                        Nessun dato collegato trovato.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-bordered table-striped align-middle mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <?php foreach ($modalConfig['fields'] as $field): ?>
                                                                        <th><?= htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8') ?></th>
                                                                    <?php endforeach; ?>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
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
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
PHP;
}
