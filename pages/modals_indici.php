
<!-- MODAL NUOVO INDICE COMPOSTO -->
<div class="modal fade" id="indiceModal" tabindex="-1" aria-labelledby="indiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="indiceModalLabel">Nuovo Indice Composto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Tipo Indice</label>
                    <select name="tipo_indice" class="form-select" required>
                        <option value="INDEX">INDEX</option>
                        <option value="UNIQUE">UNIQUE</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Campi Disponibili</label>
                    <ul id="availableCampi" class="list-group mb-3 sortable-list" style="min-height: 50px; border: 1px solid #dee2e6; border-radius: 0.25rem;">
                        <?php foreach ($campi as $c): ?>
                            <li class="list-group-item" data-id="<?= $c["id"] ?>"><?= htmlspecialchars($c["nome"]) ?> (<?= $c["tipo"] ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Campi nell'Indice (Trascina qui)</label>
                    <ul id="selectedCampi" class="list-group mb-3 sortable-list" style="min-height: 50px; border: 1px solid #dee2e6; border-radius: 0.25rem;">
                    </ul>
                    <input type="hidden" name="campi_idx" id="campi_idx_input">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" name="save_indice" class="btn btn-primary">Crea Indice</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL MODIFICA INDICE COMPOSTO -->
<div class="modal fade" id="editIndiceModal" tabindex="-1" aria-labelledby="editIndiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editIndiceModalLabel">Modifica Indice Composto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_indice_update" id="edit_indice_id">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Tipo Indice</label>
                    <select name="tipo_indice" id="edit_indice_tipo" class="form-select" required>
                        <option value="INDEX">INDEX</option>
                        <option value="UNIQUE">UNIQUE</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Campi Disponibili</label>
                    <ul id="editAvailableCampi" class="list-group mb-3 sortable-list" style="min-height: 50px; border: 1px solid #dee2e6; border-radius: 0.25rem;">
                        <?php foreach ($campi as $c): ?>
                            <li class="list-group-item" data-id="<?= $c["id"] ?>"><?= htmlspecialchars($c["nome"]) ?> (<?= $c["tipo"] ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Campi nell'Indice (Trascina qui)</label>
                    <ul id="editSelectedCampi" class="list-group mb-3 sortable-list" style="min-height: 50px; border: 1px solid #dee2e6; border-radius: 0.25rem;">
                    </ul>
                    <input type="hidden" name="campi_idx" id="edit_campi_idx_input">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" name="update_indice" class="btn btn-primary">Salva Modifiche</button>
            </div>
        </form>
    </div>
</div>

<script>
    const allCampi = <?php echo json_encode($campi); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // SortableJS for New Index Modal
    const availableCampi = document.getElementById('availableCampi');
    const selectedCampi = document.getElementById('selectedCampi');
    const campiIdxInput = document.getElementById('campi_idx_input');

    console.log('availableCampi:', availableCampi);
    console.log('selectedCampi:', selectedCampi);

    const updateCampiIdxInput = () => {
        const selectedIds = Array.from(selectedCampi.children).map(item => item.dataset.id);
        campiIdxInput.value = selectedIds.join(',');
    };

    if (availableCampi && selectedCampi) {
        console.log('Initializing Sortable for New Index Modal...');
        new Sortable(availableCampi, {
            group: 'shared',
            animation: 150,
            draggable: '.list-group-item',
            onEnd: function (evt) { updateCampiIdxInput(); }
        });

        new Sortable(selectedCampi, {
            group: 'shared',
            animation: 150,
            draggable: '.list-group-item',
            onEnd: function (evt) { updateCampiIdxInput(); }
        });
    }

    // SortableJS for Edit Index Modal
    const editAvailableCampi = document.getElementById('editAvailableCampi');
    const editSelectedCampi = document.getElementById('editSelectedCampi');
    const editCampiIdxInput = document.getElementById('edit_campi_idx_input');

    console.log('editAvailableCampi:', editAvailableCampi);
    console.log('editSelectedCampi:', editSelectedCampi);

    const updateEditCampiIdxInput = () => {
        const selectedIds = Array.from(editSelectedCampi.children).map(item => item.dataset.id);
        editCampiIdxInput.value = selectedIds.join(',');
    };

    if (editAvailableCampi && editSelectedCampi) {
        console.log('Initializing Sortable for Edit Index Modal...');
        new Sortable(editAvailableCampi, {
            group: 'editShared',
            animation: 150,
            draggable: '.list-group-item',
            onEnd: function (evt) { updateEditCampiIdxInput(); }
        });

        new Sortable(editSelectedCampi, {
            group: 'editShared',
            animation: 150,
            draggable: '.list-group-item',
            onEnd: function (evt) { updateEditCampiIdxInput(); }
        });

        // Populate Edit Indice Modal
        const editIndiceModal = document.getElementById('editIndiceModal');
        editIndiceModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const tipo = button.getAttribute('data-tipo');
            const campiIds = button.getAttribute('data-campi-ids');

            editIndiceModal.querySelector('#edit_indice_id').value = id;
            editIndiceModal.querySelector('#edit_indice_tipo').value = tipo;

            // Clear previous selections
            editAvailableCampi.innerHTML = '';
            editSelectedCampi.innerHTML = '';

            // Re-populate available fields
            allCampi.forEach(c => {
                let li = document.createElement('li');
                li.className = 'list-group-item';
                li.dataset.id = c.id;
                li.textContent = `${c.nome} (${c.tipo})`;
                editAvailableCampi.appendChild(li);
            });

            // Move selected fields to the 'selected' list
            if (campiIds) {
                const selectedIdsArray = campiIds.split(',');
                selectedIdsArray.forEach(selectedId => {
                    const item = editAvailableCampi.querySelector(`[data-id="${selectedId}"]`);
                    if (item) {
                        editSelectedCampi.appendChild(item);
                    }
                });
            }
            updateEditCampiIdxInput();
        });
    }

    // Handle delete-indice-form submission
    const formsDeleteIndice = document.querySelectorAll(".delete-indice-form");
    formsDeleteIndice.forEach(form => {
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            const formElement = this;
            const indexName = formElement.getAttribute("data-index-name");
            showConfirmationModal(`Eliminare definitivamente l'indice composto '${indexName}'?`, function() {
                formElement.submit();
            });
        });
    });
});
</script>
