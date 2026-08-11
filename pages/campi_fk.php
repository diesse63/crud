<?php
/**
 * FILE: campi_fk.php
 * Gestione Foreign Keys - Versione Completa Integrale
 * - Crea campi FK come NOT NULL di default, con opzione nullable
 * - Consente la scelta del tipo indice locale tra idx e uq
 * - Risolto caricamento dati modale modifica
 * - Include riordinamento campi e sincronizzazione nomi
 */

// --- 1. LOGICA AZIONI POST ---

if (!function_exists('normalizeFkNamingPart')) {
    function normalizeFkNamingPart(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim($value, '_');
    }
}

if (!function_exists('buildFkFieldName')) {
    function buildFkFieldName($db, int $tabellaId, string $referencedTableName, string $descriptiveName): string {
        $baseSource = trim($referencedTableName) !== '' ? $referencedTableName : 'fk';
        if (trim($descriptiveName) !== '') {
            $baseSource .= '_' . $descriptiveName;
        }
        $base = 'id_' . normalizeFkNamingPart($baseSource);
        if ($base === 'id_') {
            $base = 'id_fk';
        }

        $existingNames = $db->fetchAll(
            "SELECT nome FROM campi WHERE IDtabella = ? AND nome LIKE ?",
            [$tabellaId, $base . '%']
        );
        $taken = [];
        foreach ($existingNames as $row) {
            $taken[(string)($row['nome'] ?? '')] = true;
        }

        if (!isset($taken[$base])) {
            return $base;
        }

        $suffix = 2;
        do {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        } while (isset($taken[$candidate]));

        return $candidate;
    }
}

if (!function_exists('buildFkConstraintName')) {
    function buildFkConstraintName(string $localTableName, string $referencedTableName, string $localFieldName): string {
        $parts = [
            'fk',
            normalizeFkNamingPart($localTableName),
            normalizeFkNamingPart($referencedTableName),
            normalizeFkNamingPart($localFieldName),
        ];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        return implode('_', $parts);
    }
}

// SALVATAGGIO NUOVA FK
if (isset($_POST["save_fk"])) {
    $db->beginTransaction();
    try {
        $rc = $db->fetch("SELECT * FROM campi WHERE id = ?", [(int)$_POST["campo_riferimento"]]);
        if ($rc) {
            // Calcolo ordine: se è il primo campo dopo la PK inizia da 2
            $max = $db->fetchColumn("SELECT MAX(ordine) FROM campi WHERE IDtabella = ?", [$tabella_id]);
            $ordine_nuovo = ($max < 1) ? 2 : $max + 1;
            $descriptiveName = trim((string)($_POST["fk_nome_descrittivo"] ?? ''));
            if ($descriptiveName === '') {
                throw new RuntimeException('Inserire un nome descrittivo per il campo FK.');
            }
            $referencedTable = $db->fetch("SELECT nome FROM tabelle WHERE id = ?", [(int)$rc['IDtabella']]);
            $temp_name = buildFkFieldName(
                $db,
                (int)$tabella_id,
                (string)($referencedTable['nome'] ?? 'fk'),
                $descriptiveName
            );
            $localTable = $db->fetch("SELECT nome FROM tabelle WHERE id = ?", [(int)$tabella_id]);
            $fk_name = buildFkConstraintName(
                (string)($localTable['nome'] ?? 'tabella'),
                (string)($referencedTable['nome'] ?? 'referenza'),
                $temp_name
            );
            
            $nullable = isset($_POST["nullable"]) ? 1 : 0;
            $indice_tipo = (($_POST["indice_tipo"] ?? 'INDICE') === 'UNICO') ? 'UNICO' : 'INDICE';

            // 1. Crea il campo locale fisico: NOT NULL di default, ma configurabile
            $db->execute("INSERT INTO campi (IDtabella, nome, nome_descrittivo, tipo, lunghezza, nullable, default_value, indice_tipo, auto_increment, modifica, ordine) 
                          VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 0, 0, ?)", 
                [$tabella_id, $temp_name, $descriptiveName !== '' ? $descriptiveName : defaultFieldLabel($temp_name), $rc["tipo"], $rc["lunghezza"], $nullable, $indice_tipo, $ordine_nuovo]);
            
            $new_cid = $db->lastInsertId();
            
            // 2. Crea la definizione della Foreign Key
            $db->execute("INSERT INTO foreign_keys (IDtabella, nome, on_delete, on_update) VALUES (?, ?, ?, ?)", 
                [$tabella_id, $fk_name, $_POST["on_delete"], $_POST["on_update"]]);
            $fk_id = $db->lastInsertId();
            
            // 3. Lega il campo locale al campo referenziato
            $db->execute("INSERT INTO foreign_keys_campi (IDforeign_key, IDcampo_locale, IDcampo_referenziato, ordine) VALUES (?, ?, ?, 1)", 
                [$fk_id, $new_cid, $rc["id"]]);
            
            // Sincronizza i nomi in base alla convenzione aggiornata
            syncProjectIntegrity($progetto_id);
            $syncedName = $db->fetchColumn("SELECT nome FROM campi WHERE id = ?", [$new_cid]);
            $db->execute("UPDATE campi SET nome_descrittivo = ? WHERE id = ?", [defaultFieldLabel($syncedName), $new_cid]);
        }
        $db->commit(); 
        $_SESSION['success_msg'] = "Relazione creata con successo.";
        refreshCampi($tabella_id);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error_msg'] = "Errore nel salvataggio della relazione: " . $e->getMessage();
        refreshCampi($tabella_id);
    }
}

// CANCELLAZIONE FK
if (isset($_POST["delete_fk"])) {
    $db->beginTransaction();
    try {
        $fk_id_to_delete = (int)$_POST["id_fk_delete"];
        
        // Recuperiamo info sul campo per gestire il riordino
        $campo_info = $db->fetch("
            SELECT c.id, c.ordine 
            FROM campi c 
            JOIN foreign_keys_campi fkc ON fkc.IDcampo_locale = c.id 
            WHERE fkc.IDforeign_key = ?
        ", [$fk_id_to_delete]);

        if ($campo_info) {
            $db->execute("DELETE FROM foreign_keys_campi WHERE IDforeign_key = ?", [$fk_id_to_delete]);
            $db->execute("DELETE FROM foreign_keys WHERE id = ?", [$fk_id_to_delete]);
            $db->execute("DELETE FROM campi WHERE id = ?", [$campo_info['id']]);
            
            // Scaliamo l'ordine dei campi successivi per non lasciare buchi
            $db->execute("UPDATE campi SET ordine = ordine - 1 WHERE IDtabella = ? AND ordine > ?", 
                [$tabella_id, $campo_info['ordine']]);
        }
        
        syncProjectIntegrity($progetto_id);
        $db->commit(); 
        $_SESSION['success_msg'] = "Relazione eliminata con successo.";
        refreshCampi($tabella_id);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error_msg'] = "Errore nella cancellazione della relazione: " . $e->getMessage();
        refreshCampi($tabella_id);
    }
}

// AGGIORNAMENTO REGOLE FK (Cascade/Restrict)
if (isset($_POST["update_fk"])) {
    try {
        $fkRow = $db->fetch(
            "SELECT fk.id, t_loc.nome AS local_table, t_ref.nome AS referenced_table, c_loc.nome AS local_field
             FROM foreign_keys fk
             JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id
             JOIN campi c_loc ON c_loc.id = fkc.IDcampo_locale
             JOIN tabelle t_loc ON t_loc.id = fk.IDtabella
             JOIN campi c_ref ON c_ref.id = fkc.IDcampo_referenziato
             JOIN tabelle t_ref ON t_ref.id = c_ref.IDtabella
             WHERE fk.id = ?",
            [(int)$_POST["fk_id"]]
        );
        $db->execute("UPDATE foreign_keys SET on_delete = ?, on_update = ? WHERE id = ?", 
            [$_POST["on_delete"], $_POST["on_update"], (int)$_POST["fk_id"]]);
        $db->execute(
            "UPDATE campi c
             JOIN foreign_keys_campi fkc ON fkc.IDcampo_locale = c.id
             SET c.nullable = ?
             WHERE fkc.IDforeign_key = ?",
            [isset($_POST["nullable"]) ? 1 : 0, (int)$_POST["fk_id"]]
        );
        $db->execute(
            "UPDATE campi c
             JOIN foreign_keys_campi fkc ON fkc.IDcampo_locale = c.id
             SET c.indice_tipo = ?
             WHERE fkc.IDforeign_key = ?",
            [(($_POST["indice_tipo"] ?? 'INDICE') === 'UNICO') ? 'UNICO' : 'INDICE', (int)$_POST["fk_id"]]
        );
        $newDescriptiveName = trim((string)($_POST["fk_nome_descrittivo"] ?? ''));
        if ($newDescriptiveName === '') {
            throw new RuntimeException('Inserire un nome descrittivo per il campo FK.');
        }
        $newFieldName = buildFkFieldName(
            $db,
            (int)$tabella_id,
            (string)($fkRow['referenced_table'] ?? 'fk'),
            $newDescriptiveName
        );
        $db->execute(
            "UPDATE campi c
             JOIN foreign_keys_campi fkc ON fkc.IDcampo_locale = c.id
             SET c.nome = ?, c.nome_descrittivo = ?
             WHERE fkc.IDforeign_key = ?",
            [$newFieldName, $newDescriptiveName, (int)$_POST["fk_id"]]
        );
        if ($fkRow) {
            $db->execute(
                "UPDATE foreign_keys SET nome = ? WHERE id = ?",
                [buildFkConstraintName((string)$fkRow['local_table'], (string)$fkRow['referenced_table'], (string)$fkRow['local_field']), (int)$fkRow['id']]
            );
        }
        $_SESSION['success_msg'] = "Regole della relazione aggiornate con successo.";
        refreshCampi($tabella_id);
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Errore nell'aggiornamento delle regole: " . $e->getMessage();
        refreshCampi($tabella_id);
    }
}

// --- 2. FUNZIONI DI UTILITÀ ---

/**
 * Mappa i campi della tabella che sono attualmente chiavi esterne
 */
function getFkMap($tabella_id) {
    global $db;
    $fk_list = $db->fetchAll("SELECT fkc.IDcampo_locale FROM foreign_keys_campi fkc JOIN foreign_keys fk ON fk.id = fkc.IDforeign_key WHERE fk.IDtabella = ?", [$tabella_id]);
    $map = []; 
    foreach($fk_list as $f) { $map[(int)$f["IDcampo_locale"]] = true; }
    return $map;
}

// --- 3. RENDERING ---

/**
 * Tabella riassuntiva delle FK
 */
function renderFkTable($tabella_id) {
    global $db;
    $foreign_keys = $db->fetchAll("
        SELECT fk.id, fk.nome, fk.on_delete, fk.on_update, 
               c_loc.id AS campo_locale_id, c_loc.nome AS campo_locale_nome, c_loc.nome_descrittivo AS campo_locale_descrittivo,
               c_ref.id AS campo_referenziato_id, c_ref.nome AS campo_referenziato_nome, 
               t_ref.id AS tabella_referenziata_id, t_ref.nome AS tabella_referenziata_nome 
        FROM foreign_keys fk 
        JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id 
        JOIN campi c_loc ON c_loc.id = fkc.IDcampo_locale 
        JOIN campi c_ref ON c_ref.id = fkc.IDcampo_referenziato 
        JOIN tabelle t_ref ON t_ref.id = c_ref.IDtabella 
        WHERE fk.IDtabella = ? 
        ORDER BY fk.nome ASC", [$tabella_id]);
    ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="p-3 border-bottom bg-white d-flex align-items-center">
            <h6 class="mb-0 fw-bold"><i class="bi bi-link-45deg me-2 text-primary"></i>Foreign Keys (Relazioni)</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
                <thead class="table-light small text-uppercase">
                    <tr>
                        <th class="ps-4">Nome Vincolo</th>
                        <th class="text-center">Campo Locale</th>
                        <th>Riferimento</th>
                        <th class="text-center">Regole (D/U)</th>
                        <th class="text-end pe-4">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($foreign_keys)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">Nessuna relazione definita.</td></tr>
                    <?php else: foreach ($foreign_keys as $fk): ?>
                        <tr>
                            <td class="ps-4"><span class="fw-bold text-primary"><?= htmlspecialchars($fk["nome"]) ?></span></td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary border fw-normal"><?= htmlspecialchars($fk["campo_locale_nome"]) ?></span>
                                <div class="small text-muted mt-1">Tecnico: <?= htmlspecialchars($fk["campo_locale_nome"]) ?></div>
                                <?php if (!empty($fk["campo_locale_descrittivo"])): ?>
                                    <div class="small text-muted mt-1">Descrittivo: <?= htmlspecialchars($fk["campo_locale_descrittivo"]) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-bold"><?= htmlspecialchars($fk["tabella_referenziata_nome"]) ?></span>
                                <span class="text-muted small">(<?= htmlspecialchars($fk["campo_referenziato_nome"]) ?>)</span>
                            </td>
                            <td class="text-center small text-muted text-uppercase"><?= $fk["on_delete"] ?> / <?= $fk["on_update"] ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm text-primary p-1 btn-edit-fk" data-bs-toggle="modal" data-bs-target="#editFkModal" 
                                    data-id="<?= $fk["id"] ?>" 
                                    data-on_delete="<?= $fk["on_delete"] ?>" 
                                    data-on_update="<?= $fk["on_update"] ?>"
                                    data-tabref_id="<?= $fk["tabella_referenziata_id"] ?>" 
                                    data-tabref_nome="<?= htmlspecialchars($fk["tabella_referenziata_nome"]) ?>" 
                                    data-camporef_id="<?= $fk["campo_referenziato_id"] ?>"
                                    data-camporef_nome="<?= htmlspecialchars($fk["campo_referenziato_nome"]) ?>"
                                    data-campoloc_desc="<?= htmlspecialchars((string)($fk["campo_locale_descrittivo"] ?? '')) ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare la relazione e il relativo campo?');">
                                    <input type="hidden" name="id_fk_delete" value="<?= $fk["id"] ?>">
                                    <button type="submit" name="delete_fk" class="btn btn-sm text-danger p-1"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * Modale Creazione FK
 */
function renderFkModal($project_tables) { ?>
    <div class="modal fade" id="fkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white"><h5>Nuova Relazione (FK)</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Tabella Referenziata</label>
                    <select id="fk_target_table" class="form-select" required>
                        <option value="">Seleziona tabella...</option>
                        <?php foreach ($project_tables as $pt): ?><option value="<?= $pt["id"] ?>"><?= htmlspecialchars($pt["nome"]) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Campo Referenziato (PK o Unique)</label>
                    <select name="campo_riferimento" id="fk_target_field" class="form-select" required disabled><option value="">Scegli prima la tabella...</option></select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Nome descrittivo campo FK</label>
                    <input type="text" name="fk_nome_descrittivo" class="form-control" placeholder="es. localita nascita" required>
                    <div class="form-text">Usato per costruire il nome tecnico del campo senza ricorrere a suffissi automatici.</div>
                </div>
                <div class="row">
                    <div class="col-6"><label class="small fw-bold">ON DELETE</label><select name="on_delete" class="form-select"><option value="RESTRICT">RESTRICT</option><option value="CASCADE">CASCADE</option><option value="SET NULL">SET NULL</option></select></div>
                    <div class="col-6"><label class="small fw-bold">ON UPDATE</label><select name="on_update" id="on_update" class="form-select"><option value="CASCADE">CASCADE</option><option value="RESTRICT">RESTRICT</option></select></div>
                </div>
                <div class="row mt-3">
                    <div class="col-6">
                        <label class="small fw-bold">Indice Locale</label>
                        <select name="indice_tipo" class="form-select">
                            <option value="INDICE">INDICE</option>
                            <option value="UNICO">UNICO</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="nullable" id="fk_nullable">
                        <label class="form-check-label small fw-bold" for="fk_nullable">Nullable</label>
                    </div>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Di default il campo locale viene creato come NOT NULL.</div>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" name="save_fk" class="btn btn-primary w-100">Crea Relazione</button></div>
        </form></div>
    </div>
    <script>
    document.getElementById("fk_target_table").addEventListener("change", function() {
        const val = this.value; const fs = document.getElementById("fk_target_field");
        if(!val) { fs.disabled = true; fs.innerHTML = '<option value="">Scegli tabella...</option>'; return; }
        fs.innerHTML = '<option value="">Caricamento...</option>';
        fetch(`index.php?page=campi&ajax_fk_fields=${val}`).then(r => r.json()).then(data => {
            fs.innerHTML = data.fields.map(f => `<option value="${f.id}">${f.nome} (${f.tipo.toUpperCase()})</option>`).join('');
            fs.disabled = false;
        }).catch(err => { fs.innerHTML = '<option value="">Errore caricamento</option>'; });
    });
    </script>
<?php }

/**
 * Modale Modifica FK
 */
function renderEditFkModal($project_tables) { ?>
    <div class="modal fade" id="editFkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="POST" class="modal-content">
            <input type="hidden" name="fk_id" id="edit_fk_id">
            <div class="modal-header bg-primary text-white"><h5>Modifica Regole Relazione</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Tabella Referenziata</label>
                    <span id="edit_fk_target_table_display" class="form-control-plaintext fw-bold"></span>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Campo Referenziato</label>
                    <span id="edit_fk_target_field_display" class="form-control-plaintext fw-bold"></span>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Nome descrittivo campo FK</label>
                    <input type="text" name="fk_nome_descrittivo" id="edit_fk_nome_descrittivo" class="form-control" placeholder="es. localita nascita" required>
                    <div class="form-text">Aggiorna il nome tecnico del campo con una descrizione leggibile.</div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-6"><label class="small fw-bold">ON DELETE</label><select name="on_delete" id="edit_fk_on_delete" class="form-select"><option value="RESTRICT">RESTRICT</option><option value="CASCADE">CASCADE</option><option value="SET NULL">SET NULL</option></select></div>
                    <div class="col-6"><label class="small fw-bold">ON UPDATE</label><select name="on_update" id="edit_fk_on_update" class="form-select"><option value="CASCADE">CASCADE</option><option value="RESTRICT">RESTRICT</option></select></div>
                </div>
                <div class="row mt-3">
                    <div class="col-6">
                        <label class="small fw-bold">Indice Locale</label>
                        <select name="indice_tipo" id="edit_fk_indice_tipo" class="form-select">
                            <option value="INDICE">INDICE</option>
                            <option value="UNICO">UNICO</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="nullable" id="edit_fk_nullable">
                        <label class="form-check-label small fw-bold" for="edit_fk_nullable">Nullable</label>
                    </div>
                    <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Di default il campo locale resta NOT NULL.</div>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" name="update_fk" class="btn btn-primary w-100">Aggiorna Regole</button></div>
        </form></div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const modal = document.getElementById('editFkModal');
        modal.addEventListener('show.bs.modal', function(e) {
            const b = e.relatedTarget;
            // Recupero dati dagli attributi data-*
            const fkId = b.getAttribute('data-id');
            const onDelete = b.getAttribute('data-on_delete');
            const onUpdate = b.getAttribute('data-on_update');
            const tabRefId = b.getAttribute('data-tabref_id');
            const tabRefNome = b.getAttribute('data-tabref_nome'); // New
            const campoRefId = b.getAttribute('data-camporef_id');
            const campoRefNome = b.getAttribute('data-camporef_nome'); // New
            const campoLocDesc = b.getAttribute('data-campoloc_desc') || '';
            const nullable = b.getAttribute('data-nullable');
            const indiceTipo = b.getAttribute('data-indice_tipo') || 'INDICE';

            // Popolamento campi
            document.getElementById('edit_fk_id').value = fkId;
            document.getElementById('edit_fk_on_delete').value = onDelete;
            document.getElementById('edit_fk_on_update').value = onUpdate;
            document.getElementById('edit_fk_nullable').checked = nullable == "1";
            document.getElementById('edit_fk_indice_tipo').value = indiceTipo;
            document.getElementById('edit_fk_nome_descrittivo').value = campoLocDesc || '';
            
            // Popola la tabella referenziata
            document.getElementById('edit_fk_target_table_display').textContent = tabRefNome;

            // Popola il campo referenziato
            document.getElementById('edit_fk_target_field_display').textContent = campoRefNome;
        });
    });
    </script>
<?php }
