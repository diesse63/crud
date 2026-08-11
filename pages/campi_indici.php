<?php
/**
 * FILE: campi_indici.php
 * Gestione Indici Composti - Completo
 */

function generaNomeIndiceComposto($tabella_nome, $campi_ids, $tipo_indice) {
    global $db;
    $nomi_campi = [];
    if (!is_array($campi_ids)) $campi_ids = explode(',', (string)$campi_ids);
    foreach ($campi_ids as $cid) {
        $nome_c = $db->fetchColumn("SELECT nome FROM campi WHERE id = ?", [(int)$cid]);
        if ($nome_c) $nomi_campi[] = normalize($nome_c);
    }
    if (empty($nomi_campi)) return null;
    $prefisso = (strtoupper($tipo_indice) === 'UNIQUE') ? 'uk' : 'idx';
    return normalize($prefisso . "_" . implode('_', $nomi_campi));
}

function syncAllIndicesNaming($progetto_id) {
    global $db;
    $indici = $db->fetchAll("SELECT i.id, i.tipo, i.IDtabella, t.nome as tabella_nome FROM indici i JOIN tabelle t ON t.id = i.IDtabella WHERE t.IDprogetto = ?", [$progetto_id]);
    foreach ($indici as $idx) {
        $campi = $db->fetchAll("SELECT IDcampo FROM indici_campi WHERE IDindice = ? ORDER BY ordine", [$idx['id']]);
        $ids = array_column($campi, 'IDcampo');
        if (count($ids) < 1) { $db->execute("DELETE FROM indici WHERE id = ?", [$idx['id']]); continue; }
        $nuovo = generaNomeIndiceComposto($idx['tabella_nome'], $ids, $idx['tipo']);
        if ($nuovo) $db->execute("UPDATE indici SET nome = ? WHERE id = ?", [$nuovo, $idx['id']]);
    }
}

if (isset($_POST["save_indice"])) {
    $ids = array_filter(explode(',', $_POST["campi_idx"] ?? ''));
    if (count($ids) >= 2) {
        $db->beginTransaction();
        try {
            $db->execute("INSERT INTO indici (IDtabella, nome, tipo) VALUES (?, 'temp', ?)", [$tabella_id, $_POST["tipo_indice"]]);
            $idx_id = $db->lastInsertId();
            foreach ($ids as $k => $cid) { $db->execute("INSERT INTO indici_campi (IDindice, IDcampo, ordine) VALUES (?,?,?)", [$idx_id, (int)$cid, $k+1]); }
            syncAllIndicesNaming($progetto_id); 
            $db->commit();
            $_SESSION['success_msg'] = "Indice composto creato con successo.";
        } catch (Exception $e) { 
            if ($db->inTransaction()) $db->rollBack(); 
            $_SESSION['error_msg'] = "Errore durante la creazione dell'indice: " . $e->getMessage(); 
        }
    } else {
        $_SESSION['error_msg'] = "Errore: seleziona almeno 2 campi per creare un indice composto.";
    }
    refreshCampi($tabella_id);
}

if (isset($_POST["update_indice"])) {
    $indice_id = (int)$_POST["indice_id"];
    $ids = array_filter(explode(',', $_POST["campi_idx"] ?? ''));
    if (count($ids) >= 2) {
        $db->beginTransaction();
        try {
            $db->execute("UPDATE indici SET tipo = ? WHERE id = ?", [$_POST["tipo_indice"], $indice_id]);
            $db->execute("DELETE FROM indici_campi WHERE IDindice = ?", [$indice_id]);
            foreach ($ids as $k => $cid) {
                $db->execute("INSERT INTO indici_campi (IDindice, IDcampo, ordine) VALUES (?,?,?)", [$indice_id, (int)$cid, $k+1]);
            }
            syncAllIndicesNaming($progetto_id);
            $db->commit();
            $_SESSION['success_msg'] = "Indice composto aggiornato con successo.";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error_msg'] = "Errore durante l'aggiornamento dell'indice: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Errore: seleziona almeno 2 campi per creare un indice composto.";
    }
    refreshCampi($tabella_id);
}

if (isset($_POST["delete_indice_submit"])) {
    try {
        $db->execute("DELETE FROM indici WHERE id = ?", [(int)$_POST["id_indice_delete"]]);
        $_SESSION['success_msg'] = "Indice eliminato con successo.";
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Errore durante l'eliminazione dell'indice: " . $e->getMessage();
    }
    refreshCampi($tabella_id);
}

function getCampiInIndici($tabella_id) {
    global $db;
    $indici = $db->fetchAll("SELECT i.nome, ic.IDcampo FROM indici i JOIN indici_campi ic ON ic.IDindice = i.id WHERE i.IDtabella = ?", [$tabella_id]);
    $map = []; foreach($indici as $idx) { $map[(int)$idx['IDcampo']][] = $idx['nome']; }
    return $map;
}

function renderIndiciTable($tabella_id) {
    global $db;
    $indici = $db->fetchAll("
        SELECT i.*, GROUP_CONCAT(c.nome ORDER BY ic.ordine SEPARATOR ', ') AS campi_str,
               GROUP_CONCAT(c.id ORDER BY ic.ordine SEPARATOR ',') AS campi_ids_ordered
        FROM indici i
        JOIN indici_campi ic ON ic.IDindice = i.id
        JOIN campi c ON c.id = ic.IDcampo
        WHERE i.IDtabella = ? GROUP BY i.id", [$tabella_id]);
    ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="p-3 border-bottom bg-white"><h6 class="mb-0 fw-bold"><i class="bi bi-layers-half me-2 text-primary"></i>Indici Composti</h6></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
                <thead class="table-light small uppercase"><tr><th>Nome</th><th>Tipo</th><th>Campi</th><th class="text-end pe-4">Azioni</th></tr></thead>
                <tbody>
                    <?php if (empty($indici)): ?><tr><td colspan="4" class="text-center py-3 text-muted">Nessun indice.</td></tr>
                    <?php else: foreach ($indici as $idx): ?>
                        <tr>
                            <td><span class="fw-bold text-primary"><?= $idx["nome"] ?></span></td>
                            <td><?= $idx["tipo"] ?></td>
                            <td><?= $idx["campi_str"] ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm text-warning p-1 btn-edit-indice" data-bs-toggle="modal" data-bs-target="#editIndiceModal"
                                        data-id="<?= $idx["id"] ?>"
                                        data-tipo="<?= $idx["tipo"] ?>"
                                        data-campi="<?= htmlspecialchars($idx["campi_ids_ordered"]) ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="id_indice_delete" value="<?= $idx["id"] ?>"><button type="submit" name="delete_indice_submit" class="btn btn-sm text-danger"><i class="bi bi-trash"></i></button>
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

function renderIndiceModals($campi) { ?>
    <style>
        .drag-field-item {
            cursor: grab;
            user-select: none;
            transition: all 0.2s ease;
        }
        .drag-field-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05) !important;
            background-color: #f8f9fa;
        }
        .drag-field-item:active {
            cursor: grabbing;
        }
        .drag-field-item.dragging {
            opacity: 0.4;
            transform: scale(0.98);
            border: 2px dashed #0d6efd !important;
        }
        .dropzone-list {
            min-height: 250px;
            max-height: 350px;
            overflow-y: auto;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }
        .dropzone-list.dropzone-hover {
            background-color: rgba(13, 110, 253, 0.05) !important;
            border-color: #0d6efd !important;
        }
        .btn-action-field {
            transition: transform 0.2s ease;
        }
        .btn-action-field:hover {
            transform: scale(1.15);
        }
        .select-dropzone {
            border: 2px dashed #dee2e6 !important;
        }
        .select-dropzone.has-items {
            border: 2px dashed #0d6efd !important;
        }
    </style>
    <div class="modal fade" id="indiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg"><form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="fw-bold"><i class="bi bi-layers-half text-primary me-2"></i>Nuovo Indice Composto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <label class="small fw-bold text-secondary mb-1">Tipo di Indice</label>
                    <select name="tipo_indice" class="form-select">
                        <option value="INDEX">INDEX</option>
                        <option value="UNIQUE">UNIQUE</option>
                    </select>
                </div>
                <div class="row">
                    <!-- Column 1: Available fields -->
                    <div class="col-md-6 mb-3">
                        <label class="small fw-bold text-secondary mb-2 d-flex align-items-center">
                            <i class="bi bi-list-task me-2 text-muted"></i>Campi Disponibili
                        </label>
                        <div id="campi_disponibili" class="list-group dropzone-list border p-2 bg-light rounded">
                            <?php foreach($campi as $c): if($c['ordine']>1): ?>
                            <div class="list-group-item drag-field-item d-flex align-items-center justify-content-between p-2 mb-2 border rounded bg-white shadow-sm" draggable="true" data-id="<?= $c['id'] ?>" data-nome="<?= htmlspecialchars($c['nome']) ?>" data-tipo="<?= $c['tipo'] ?>">
                                <span class="d-flex align-items-center">
                                    <i class="bi bi-grip-vertical text-muted me-2 fs-5"></i>
                                    <span class="fw-semibold small text-dark"><?= htmlspecialchars($c['nome']) ?></span>
                                    <span class="badge bg-light text-secondary border ms-2 small" style="font-size: 0.7rem;"><?= strtoupper($c['tipo']) ?></span>
                                </span>
                                <button type="button" class="btn btn-sm btn-link p-0 btn-action-field" title="Aggiungi all'indice">
                                    <i class="bi bi-plus-circle-fill text-primary fs-5 action-icon"></i>
                                </button>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <!-- Column 2: Selected fields -->
                    <div class="col-md-6 mb-3">
                        <label class="small fw-bold text-primary mb-2 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2"></i>Campi nell'Indice (Trascina per ordinare)
                        </label>
                        <div id="campi_selezionati" class="list-group dropzone-list select-dropzone p-2 bg-light rounded">
                            <div id="no_fields_placeholder" class="text-center py-5 text-muted small my-auto">
                                <i class="bi bi-drag-and-drop fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                Trascina qui i campi per ordinarli<br>o usa il pulsante <i class="bi bi-plus-circle-fill text-primary"></i>
                            </div>
                        </div>
                        <input type="hidden" name="campi_idx" id="campi_idx_hidden">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="save_indice" class="btn btn-dark w-100" id="btn_save_idx" disabled>Salva Indice</button>
            </div>
        </form></div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const selectList = document.getElementById('campi_selezionati');
        const availList = document.getElementById('campi_disponibili');
        const hiddenInput = document.getElementById('campi_idx_hidden');
        const saveBtn = document.getElementById('btn_save_idx');
        const placeholder = document.getElementById('no_fields_placeholder');

        function updateOrder() {
            const ids = [];
            selectList.querySelectorAll('.drag-field-item').forEach(item => {
                ids.push(item.dataset.id);
            });
            hiddenInput.value = ids.join(',');
            saveBtn.disabled = ids.length < 2;
            if (ids.length === 0) {
                placeholder.style.display = 'block';
                selectList.classList.remove('has-items');
            } else {
                placeholder.style.display = 'none';
                selectList.classList.add('has-items');
            }
            }

        function moveToSelected(item, targetElement = null) {
            const icon = item.querySelector('.action-icon');
            if (icon) {
                icon.className = 'bi bi-dash-circle-fill text-danger fs-5 action-icon';
            }
            const btn = item.querySelector('.btn-action-field');
            if (btn) {
                btn.title = 'Rimuovi dall\'indice';
            }
            if (targetElement) {
                selectList.insertBefore(item, targetElement);
            } else {
                selectList.appendChild(item);
            }
            updateOrder();
        }

        function moveToAvailable(item) {
            const icon = item.querySelector('.action-icon');
            if (icon) {
                icon.className = 'bi bi-plus-circle-fill text-primary fs-5 action-icon';
            }
            const btn = item.querySelector('.btn-action-field');
            if (btn) {
                btn.title = 'Aggiungi all\'indice';
            }
            availList.appendChild(item);
            updateOrder();
        }

        // Click handler fallback
        document.getElementById('indiceModal').addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-action-field');
            if (!btn) return;
            const item = btn.closest('.drag-field-item');
            if (!item) return;

            if (item.parentNode === availList) {
                moveToSelected(item);
            } else {
                moveToAvailable(item);
            }
        });

        // Drag and Drop
        let draggedItem = null;

        document.getElementById('indiceModal').addEventListener('dragstart', function(e) {
            const item = e.target.closest('.drag-field-item');
            if (!item) return;
            draggedItem = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        document.getElementById('indiceModal').addEventListener('dragend', function(e) {
            const item = e.target.closest('.drag-field-item');
            if (!item) return;
            item.classList.remove('dragging');
            draggedItem = null;

            availList.classList.remove('dropzone-hover');
            selectList.classList.remove('dropzone-hover');
        });

        availList.addEventListener('dragover', function(e) {
            e.preventDefault();
            availList.classList.add('dropzone-hover');
            if (draggedItem && draggedItem.parentNode === selectList) {
                moveToAvailable(draggedItem);
            }
        });

        availList.addEventListener('dragleave', function(e) {
            availList.classList.remove('dropzone-hover');
        });

        availList.addEventListener('drop', function(e) {
            e.preventDefault();
            updateOrder();
        });

        selectList.addEventListener('dragover', function(e) {
            e.preventDefault();
            selectList.classList.add('dropzone-hover');

            const afterElement = getDragAfterElement(selectList, e.clientY);
            if (draggedItem) {
                if (draggedItem.parentNode === availList) {
                    const icon = draggedItem.querySelector('.action-icon');
                    if (icon) icon.className = 'bi bi-dash-circle-fill text-danger fs-5 action-icon';
                    const btn = draggedItem.querySelector('.btn-action-field');
                    if (btn) btn.title = 'Rimuovi dall\'indice';
                }
                if (afterElement == null) {
                    selectList.appendChild(draggedItem);
                } else {
                    selectList.insertBefore(draggedItem, afterElement);
                }
            }
        });

        selectList.addEventListener('dragleave', function(e) {
            selectList.classList.remove('dropzone-hover');
        });

        selectList.addEventListener('drop', function(e) {
            e.preventDefault();
            updateOrder();
        });

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.drag-field-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
    });
    </script>
<?php }

function renderEditIndiceModal($campi) { ?>
    <div class="modal fade" id="editIndiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg"><form method="POST" class="modal-content">
            <input type="hidden" name="indice_id" id="edit_indice_id">
            <div class="modal-header bg-warning">
                <h5 class="fw-bold"><i class="bi bi-layers-half text-white me-2"></i>Modifica Indice Composto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <label class="small fw-bold text-secondary mb-1">Tipo di Indice</label>
                    <select name="tipo_indice" id="edit_indice_tipo" class="form-select">
                        <option value="INDEX">INDEX</option>
                        <option value="UNIQUE">UNIQUE</option>
                    </select>
                </div>
                <div class="row">
                    <!-- Column 1: Available fields -->
                    <div class="col-md-6 mb-3">
                        <label class="small fw-bold text-secondary mb-2 d-flex align-items-center">
                            <i class="bi bi-list-task me-2 text-muted"></i>Campi Disponibili
                        </label>
                        <div id="edit_campi_disponibili" class="list-group dropzone-list border p-2 bg-light rounded">
                            <?php foreach($campi as $c): if($c['ordine']>1): ?>
                            <div class="list-group-item drag-field-item d-flex align-items-center justify-content-between p-2 mb-2 border rounded bg-white shadow-sm" draggable="true" data-id="<?= $c['id'] ?>" data-nome="<?= htmlspecialchars($c['nome']) ?>" data-tipo="<?= $c['tipo'] ?>">
                                <span class="d-flex align-items-center">
                                    <i class="bi bi-grip-vertical text-muted me-2 fs-5"></i>
                                    <span class="fw-semibold small text-dark"><?= htmlspecialchars($c['nome']) ?></span>
                                    <span class="badge bg-light text-secondary border ms-2 small" style="font-size: 0.7rem;"><?= strtoupper($c['tipo']) ?></span>
                                </span>
                                <button type="button" class="btn btn-sm btn-link p-0 btn-action-field" title="Aggiungi all'indice">
                                    <i class="bi bi-plus-circle-fill text-primary fs-5 action-icon"></i>
                                </button>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <!-- Column 2: Selected fields -->
                    <div class="col-md-6 mb-3">
                        <label class="small fw-bold text-primary mb-2 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2"></i>Campi nell'Indice (Trascina per ordinare)
                        </label>
                        <div id="edit_campi_selezionati" class="list-group dropzone-list select-dropzone p-2 bg-light rounded">
                            <div id="edit_no_fields_placeholder" class="text-center py-5 text-muted small my-auto">
                                <i class="bi bi-drag-and-drop fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                Trascina qui i campi per ordinarli<br>o usa il pulsante <i class="bi bi-plus-circle-fill text-primary"></i>
                            </div>
                        </div>
                        <input type="hidden" name="campi_idx" id="edit_campi_idx_hidden">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="update_indice" class="btn btn-warning w-100" id="btn_update_idx" disabled>Salva Modifiche</button>
            </div>
        </form></div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const editModal = document.getElementById('editIndiceModal');
        const selectList = document.getElementById('edit_campi_selezionati');
        const availList = document.getElementById('edit_campi_disponibili');
        const hiddenInput = document.getElementById('edit_campi_idx_hidden');
        const saveBtn = document.getElementById('btn_update_idx');
        const placeholder = document.getElementById('edit_no_fields_placeholder');
        const editIndiceTipo = document.getElementById('edit_indice_tipo');

        function updateEditOrder() {
            const ids = [];
            selectList.querySelectorAll('.drag-field-item').forEach(item => {
                ids.push(item.dataset.id);
            });
            hiddenInput.value = ids.join(',');
            saveBtn.disabled = ids.length < 2;
            if (ids.length === 0) {
                placeholder.style.display = 'block';
                selectList.classList.remove('has-items');
            } else {
                placeholder.style.display = 'none';
                selectList.classList.add('has-items');
            }
        }

        function moveEditToSelected(item, targetElement = null) {
            const icon = item.querySelector('.action-icon');
            if (icon) {
                icon.className = 'bi bi-dash-circle-fill text-danger fs-5 action-icon';
            }
            const btn = item.querySelector('.btn-action-field');
            if (btn) {
                btn.title = 'Rimuovi dall\'indice';
            }
            if (targetElement) {
                selectList.insertBefore(item, targetElement);
            } else {
                selectList.appendChild(item);
            }
            updateEditOrder();
        }

        function moveEditToAvailable(item) {
            const icon = item.querySelector('.action-icon');
            if (icon) {
                icon.className = 'bi bi-plus-circle-fill text-primary fs-5 action-icon';
            }
            const btn = item.querySelector('.btn-action-field');
            if (btn) {
                btn.title = 'Aggiungi all\'indice';
            }
            availList.appendChild(item);
            updateEditOrder();
        }

        editModal.addEventListener('show.bs.modal', function(e) {
            const button = e.relatedTarget; // Button that triggered the modal
            const indiceId = button.dataset.id;
            const indiceTipo = button.dataset.tipo;
            const campiIds = button.dataset.campi ? button.dataset.campi.split(',') : [];

            document.getElementById('edit_indice_id').value = indiceId;
            editIndiceTipo.value = indiceTipo;

            // Clear existing lists
            availList.innerHTML = '';
            selectList.innerHTML = '';
            placeholder.style.display = 'block';
            selectList.classList.remove('has-items');

            // Populate available fields by cloning from the original modal's fields
            // This assumes the original #indiceModal has all possible fields initially
            const allFieldsTemplate = document.querySelectorAll('#indiceModal #campi_disponibili .drag-field-item');
            const allFieldsMap = {};
            allFieldsTemplate.forEach(item => {
                const clonedItem = item.cloneNode(true);
                allFieldsMap[clonedItem.dataset.id] = clonedItem;
                moveEditToAvailable(clonedItem); // Initially move all to available
            });

            // Then move selected fields to the selected list in the correct order
            campiIds.forEach(id => {
                const item = allFieldsMap[id];
                if (item) {
                    moveEditToSelected(item);
                }
            });
            updateEditOrder();
        });

        editModal.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-action-field');
            if (!btn) return;
            const item = btn.closest('.drag-field-item');
            if (!item) return;

            if (item.parentNode === availList) {
                moveEditToSelected(item);
            } else {
                moveEditToAvailable(item);
            }
        });

        let draggedEditItem = null;

        editModal.addEventListener('dragstart', function(e) {
            const item = e.target.closest('.drag-field-item');
            if (!item) return;
            draggedEditItem = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        editModal.addEventListener('dragend', function(e) {
            const item = e.target.closest('.drag-field-item');
            if (!item) return;
            item.classList.remove('dragging');
            draggedEditItem = null;

            availList.classList.remove('dropzone-hover');
            selectList.classList.remove('dropzone-hover');
        });

        availList.addEventListener('dragover', function(e) {
            e.preventDefault();
            availList.classList.add('dropzone-hover');
            if (draggedEditItem && draggedEditItem.parentNode === selectList) {
                moveEditToAvailable(draggedEditItem);
            }
        });

        availList.addEventListener('dragleave', function(e) {
            availList.classList.remove('dropzone-hover');
        });

        availList.addEventListener('drop', function(e) {
            e.preventDefault();
            updateEditOrder();
        });

        selectList.addEventListener('dragover', function(e) {
            e.preventDefault();
            selectList.classList.add('dropzone-hover');

            const afterElement = getDragAfterElement(selectList, e.clientY);
            if (draggedEditItem) {
                if (draggedEditItem.parentNode === availList) {
                    const icon = draggedEditItem.querySelector('.action-icon');
                    if (icon) icon.className = 'bi bi-dash-circle-fill text-danger fs-5 action-icon';
                    const btn = draggedEditItem.querySelector('.btn-action-field');
                    if (btn) btn.title = 'Rimuovi dall\'indice';
                }
                if (afterElement == null) {
                    selectList.appendChild(draggedEditItem);
                } else {
                    selectList.insertBefore(draggedEditItem, afterElement);
                }
            }
        });

        selectList.addEventListener('dragleave', function(e) {
            selectList.classList.remove('dropzone-hover');
        });

        selectList.addEventListener('drop', function(e) {
            e.preventDefault();
            updateEditOrder();
        });

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.drag-field-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
    });
    </script>
<?php }
