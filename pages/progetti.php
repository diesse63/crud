<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- CONFIGURAZIONE E VERIFICA PERCORSI ---
$baseDirName = 'sito'; 
$basePath = __DIR__ . DIRECTORY_SEPARATOR . $baseDirName . DIRECTORY_SEPARATOR;

// Verifica accesso/esistenza della cartella base 'sito'
if (!is_dir($basePath)) {
    if (!mkdir($basePath, 0755, true)) {
        $_SESSION['error_msg'] = "Errore critico: Impossibile creare la cartella di deposito '$baseDirName'. Verifica i permessi su AlterVista.";
    }
} elseif (!is_writable($basePath)) {
    $_SESSION['error_msg'] = "Errore: La cartella '$baseDirName' non ha permessi di scrittura.";
}

/**
 * Funzione per il refresh pulito.
 */
function refreshPage($target = 'progetti') {
    if (ob_get_length()) ob_clean();
    header("Location: index.php?page=$target&t=" . time());
    exit;
}

/**
 * Trasforma il nome del progetto in un nome cartella valido
 */
function sanitizeFolderName($name) {
    $name = strtolower(trim($name));
    $name = str_replace(array(' ', '.', ',', '!', '?'), '_', $name); // Sostituzioni comuni
    $name = preg_replace('/[^a-z0-9\_]/', '', $name); // Rimuove tutto ciò che non è alfanumerico o underscore
    return $name ?: 'progetto_senza_nome';
}

/**
 * Elimina una cartella e tutto il suo contenuto (necessaria per rmdir)
 */
function deleteFolderRecursive($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteFolderRecursive("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

// --- LOGICA AZIONI ---

// 1. AGGIUNTA PROGETTO
if (isset($_POST['add_progetto'])) {
    $nome = trim($_POST['nome']);
    $desc = trim($_POST['descrizione']);
    
    if (!empty($nome)) {
        $esiste = $db->fetchColumn("SELECT COUNT(*) FROM progetti WHERE nome = ?", [$nome]);
        
        if ($esiste > 0) {
            $_SESSION['error_msg'] = "Errore: Esiste già un progetto chiamato '$nome'.";
        } else {
            $db->execute("INSERT INTO progetti (nome, descrizione, data_creazione) VALUES (?, ?, NOW())", [$nome, $desc]);
            $new_id = $db->lastInsertId();

            // CREAZIONE CARTELLA FISICA
            $folderName = sanitizeFolderName($nome);
            if (!is_dir($basePath . $folderName)) {
                mkdir($basePath . $folderName, 0755, true);
            }

            $_SESSION['progetto_id'] = $new_id;
            $_SESSION['progetto_nome'] = $nome;
            $_SESSION['success_msg'] = "Progetto '$nome' creato e cartella predisposta.";
            refreshPage();
        }
    } else {
        $_SESSION['error_msg'] = "Errore: Il nome del progetto non può essere vuoto.";
        refreshPage();
    }
}

// 2. UPDATE PROGETTO
if (isset($_POST['update_progetto'])) {
    $id = $_POST['id'];
    $nome = trim($_POST['nome']);
    $desc = trim($_POST['descrizione']);

    // Recupero il vecchio nome per gestire la cartella
    $vecchioNome = $db->fetchColumn("SELECT nome FROM progetti WHERE id = ?", [$id]);

    if ($vecchioNome !== $nome) {
        $oldPath = $basePath . sanitizeFolderName($vecchioNome);
        $newPath = $basePath . sanitizeFolderName($nome);
        
        // Se la vecchia cartella esiste, la rinomino
        if (is_dir($oldPath)) {
            rename($oldPath, $newPath);
        } elseif (!is_dir($newPath)) {
            // Se non esisteva (magari cancellata a mano), ne creo una nuova
            mkdir($newPath, 0755, true);
        }
    }

    $db->execute("UPDATE progetti SET nome = ?, descrizione = ? WHERE id = ?", [$nome, $desc, $id]);
    
    if (isset($_SESSION['progetto_id']) && $_SESSION['progetto_id'] == $id) {
        $_SESSION['progetto_nome'] = $nome;
    }
    
    $_SESSION['success_msg'] = "Progetto '$nome' e relativa cartella aggiornati.";
    refreshPage();
}

// 3. ELIMINAZIONE PROGETTO
if (isset($_POST['delete_progetto'])) {
    $id = $_POST['id'];
    $nome = $_POST['nome_progetto'];

    try {
        $db->beginTransaction();

        $paginaIds = [];
        $pagine = $db->fetchAll("SELECT id FROM pagine_visualizzazione WHERE IDprogetto = ?", [$id]);
        foreach ($pagine as $pagina) {
            $paginaIds[] = (int)$pagina['id'];
        }

        if (!empty($paginaIds)) {
            $paginaIdList = implode(',', array_fill(0, count($paginaIds), '?'));

            $db->execute("DELETE FROM pagine_visualizzazione_campi WHERE IDpagina IN ($paginaIdList)", $paginaIds);
            $db->execute("DELETE FROM pagine_visualizzazione_modali WHERE IDpagina IN ($paginaIdList)", $paginaIds);
            $db->execute("DELETE FROM pagine_visualizzazione_tabelle WHERE IDpagina IN ($paginaIdList)", $paginaIds);
            $db->execute("DELETE FROM pagine_visualizzazione WHERE id IN ($paginaIdList)", $paginaIds);
        }

        $tabelle = $db->fetchAll("SELECT id FROM tabelle WHERE IDprogetto = ?", [$id]);
        $tabellaIds = [];
        foreach ($tabelle as $tab) {
            $tabellaIds[] = (int)$tab['id'];
        }

        if (!empty($tabellaIds)) {
            $tabellaIdList = implode(',', array_fill(0, count($tabellaIds), '?'));

            $db->execute("DELETE FROM foreign_keys_campi WHERE IDforeign_key IN (SELECT id FROM foreign_keys WHERE IDtabella IN ($tabellaIdList))", $tabellaIds);
            $db->execute("DELETE FROM foreign_keys WHERE IDtabella IN ($tabellaIdList)", $tabellaIds);
            $db->execute("DELETE FROM indici_campi WHERE IDindice IN (SELECT id FROM indici WHERE IDtabella IN ($tabellaIdList))", $tabellaIds);
            $db->execute("DELETE FROM indici WHERE IDtabella IN ($tabellaIdList)", $tabellaIds);
            $db->execute("DELETE FROM campi WHERE IDtabella IN ($tabellaIdList)", $tabellaIds);
        }

        $db->execute("DELETE FROM tabelle WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM progetti_setup_db WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM progetti_db_destinatario WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM progetti_deploy_ftp WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM progetti_deploy_https WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM menu_home_voci WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM menu_home_config WHERE IDprogetto = ?", [$id]);
        $db->execute("DELETE FROM progetti WHERE id = ?", [$id]);

        if (isset($_SESSION['progetto_id']) && $_SESSION['progetto_id'] == $id) {
            unset($_SESSION['progetto_id'], $_SESSION['progetto_nome']);
        }

        // ELIMINAZIONE FISICA CARTELLA
        $folderPath = $basePath . sanitizeFolderName($nome);
        deleteFolderRecursive($folderPath);

        $db->commit();
        $_SESSION['success_msg'] = "Progetto e file rimossi con successo.";
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_msg'] = "Errore eliminazione: " . $e->getMessage();
    }
    refreshPage();
}

// --- LETTURA DATI ---
$progetti_raw = $db->fetchAll("SELECT * FROM progetti ORDER BY id DESC");
$progetti = [];
foreach ($progetti_raw as $pr) {
    $pr['num_tabelle'] = $db->fetchColumn("SELECT COUNT(*) FROM tabelle WHERE IDprogetto = ?", [$pr['id']]);
    $pr['num_campi'] = $db->fetchColumn("
        SELECT COUNT(c.id) 
        FROM campi c
        JOIN tabelle t ON c.IDtabella = t.id
        WHERE t.IDprogetto = ?
    ", [$pr['id']]);
    
    // Controllo se la cartella esiste per visualizzare uno stato
    $pr['cartella_ok'] = is_dir($basePath . sanitizeFolderName($pr['nome']));
    
    $progetti[] = $pr;
}
?>

<style>
    .fab { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); z-index: 1000; transition: transform 0.2s; }
    .fab:hover { transform: scale(1.1); }
    .project-row { cursor: pointer; transition: background 0.2s; }
    .project-row:hover { background-color: #f8f9fa !important; }
</style>

<div class="mb-4">
    <h3><i class="bi bi-folder2-open"></i> Gestione Progetti</h3>
    <p class="text-muted">Fai <strong>doppio click</strong> su una riga per gestire le tabelle.</p>
</div>

<!-- Alert se ci sono problemi di permessi -->
<?php if (isset($_SESSION['error_msg'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i> <?= $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white py-3">
                <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Database Esistenti</h6>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($progetti)): ?>
                    <div class="p-5 text-center text-muted">Nessun progetto trovato. Clicca il tasto + per iniziare.</div>
                <?php endif; ?>

                <?php foreach($progetti as $pr): ?>
                <?php $isActive = (isset($_SESSION['progetto_id']) && $_SESSION['progetto_id'] == $pr['id']); ?>
                <div class="list-group-item p-3 <?= $isActive ? 'border-start border-4 border-info bg-light' : '' ?> project-row" 
                     data-id="<?= $pr['id'] ?>" 
                     title="Doppio click per aprire">
                    
                    <div class="d-flex justify-content-between align-items-center">
                        <div style="flex: 1;">
                            <h5 class="mb-1 text-primary">
                                <?= htmlspecialchars($pr['nome']) ?>
                                <?php if($isActive): ?> <span class="badge bg-info text-dark ms-2">ATTIVO</span> <?php endif; ?>
                            </h5>
                            <p class="mb-2 text-secondary small"><?= htmlspecialchars($pr['descrizione'] ?: 'Nessuna descrizione') ?></p>
                            <small class="text-muted">
                                <i class="bi bi-table"></i> <?= $pr['num_tabelle'] ?> tab | 
                                <i class="bi bi-cpu"></i> <?= $pr['num_campi'] ?> campi |
                                <i class="bi bi-folder-check <?= $pr['cartella_ok'] ? 'text-success' : 'text-danger' ?>"></i> Cartella |
                                <i class="bi bi-clock"></i> <?= date('d/m/Y', strtotime($pr['data_creazione'])) ?>
                            </small>
                        </div>
                        
                        <div class="btn-group" onclick="event.stopPropagation();">
                            <button class="btn btn-sm btn-outline-warning" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editProjectModal" 
                                    data-id="<?= $pr['id'] ?>" 
                                    data-nome="<?= htmlspecialchars($pr['nome']) ?>" 
                                    data-descrizione="<?= htmlspecialchars($pr['descrizione']) ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#deleteProjectConfirmModal" 
                                    data-id="<?= $pr['id'] ?>" 
                                    data-nome="<?= htmlspecialchars($pr['nome']) ?>"
                                    data-tabelle="<?= $pr['num_tabelle'] ?>"
                                    data-campi="<?= $pr['num_campi'] ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- I MODAL RIMANGONO GLI STESSI DEL TUO CODICE ORIGINALE -->
<!-- [Pulsante FAB e Modal Inseriti qui...] -->

<!-- FAB -->
<button class="btn btn-primary fab" data-bs-toggle="modal" data-bs-target="#newProjectModal"><i class="bi bi-plus-lg"></i></button>

<!-- MODAL: NUOVO -->
<div class="modal fade" id="newProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Nuovo Progetto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome Progetto</label>
                        <input type="text" name="nome" class="form-control" required placeholder="es. Gestionale Magazzino">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descrizione</label>
                        <textarea name="descrizione" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_progetto" class="btn btn-success">Crea Progetto</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: MODIFICA -->
<div class="modal fade" id="editProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Modifica Progetto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome Progetto</label>
                        <input type="text" name="nome" id="edit_nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Descrizione</label>
                        <textarea name="descrizione" id="edit_desc" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="update_progetto" class="btn btn-primary">Salva Modifiche</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ELIMINA -->
<div class="modal fade" id="deleteProjectConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Conferma Eliminazione</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="delete_id">
                    <input type="hidden" name="nome_progetto" id="delete_nome_hidden">
                    <p>Vuoi eliminare definitivamente il progetto "<strong id="delete_nome_display"></strong>"?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Verrà eliminata anche la <strong>cartella fisica</strong> con tutti i file contenuti e:
                        <ul class="mb-0">
                            <li><span id="delete_info_tabelle"></span> tabelle</li>
                            <li><span id="delete_info_campi"></span> campi</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="delete_progetto" class="btn btn-danger">Sì, Elimina tutto</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.project-row').forEach(row => {
        row.addEventListener('dblclick', function() {
            const id = this.getAttribute('data-id');
            window.location.href = 'index.php?page=tabelle&set_progetto=' + id;
        });
    });

    const editModal = document.getElementById('editProjectModal');
    editModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        editModal.querySelector('#edit_id').value = btn.getAttribute('data-id');
        editModal.querySelector('#edit_nome').value = btn.getAttribute('data-nome');
        editModal.querySelector('#edit_desc').value = btn.getAttribute('data-descrizione');
    });

    const deleteModal = document.getElementById('deleteProjectConfirmModal');
    deleteModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const nome = btn.getAttribute('data-nome');
        deleteModal.querySelector('#delete_id').value = btn.getAttribute('data-id');
        deleteModal.querySelector('#delete_nome_hidden').value = nome;
        deleteModal.querySelector('#delete_nome_display').textContent = nome;
        deleteModal.querySelector('#delete_info_tabelle').textContent = btn.getAttribute('data-tabelle');
        deleteModal.querySelector('#delete_info_campi').textContent = btn.getAttribute('data-campi');
    });
});
</script>
