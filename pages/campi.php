<?php
ob_start(); 
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/metadata_util.php';

// --- 1. GESTIONE AJAX FK ---
if (isset($_GET["ajax_fk_fields"])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header("Content-Type: application/json; charset=utf-8");
    try {
        $target_table_id = (int)$_GET["ajax_fk_fields"];
        $fields = $db->fetchAll("
            SELECT DISTINCT c.id, c.nome, c.nome_descrittivo, c.tipo, c.lunghezza 
            FROM campi c
            LEFT JOIN indici_campi ic ON c.id = ic.IDcampo
            LEFT JOIN indici i ON ic.IDindice = i.id
            WHERE c.IDtabella = ? 
              AND (c.ordine = 1 OR i.tipo = 'UNICO')
            ORDER BY c.ordine ASC, c.nome ASC
        ", [$target_table_id]);
        echo json_encode(["fields" => $fields ?: []]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION["progetto_id"]) || (!isset($_GET["tabella_id"]) && !isset($_GET["tabella"]))) {
    header("Location: index.php?page=progetti"); exit;
}

$tabella_id = isset($_GET["tabella_id"]) ? (int)$_GET["tabella_id"] : (int)$_GET["tabella"];
$progetto_id = (int)$_SESSION["progetto_id"];
$tabella = $db->fetch("SELECT * FROM tabelle WHERE id = ?", [$tabella_id]);
if (!$tabella) { header("Location: index.php?page=tabelle"); exit; }

// --- 2. FUNZIONI DI UTILITÀ ---
function normalize($v) {
    $v = strtolower(trim((string)$v));
    $v = preg_replace('/[^a-z0-9]+/', '_', $v);
    return trim($v, '_');
}

function defaultFieldLabel($name) {
    $label = str_replace('_', ' ', trim((string)$name));
    $label = preg_replace('/\s+/', ' ', $label);
    return $label;
}

function refreshCampi($tid) {
    header("Location: index.php?page=campi&tabella_id=" . $tid); exit;
}

function getCleanData($p) {
    $t = $p['tipo'];
    $rules = [
        'int'       => ['l'=>false, 'd'=>true,  'ai'=>true,  'm'=>false],
        'varchar'   => ['l'=>true,  'd'=>true,  'ai'=>false, 'm'=>false],
        'text'      => ['l'=>false, 'd'=>false, 'ai'=>false, 'm'=>false],
        'date'      => ['l'=>false, 'd'=>true,  'ai'=>false, 'm'=>false],
        'datetime'  => ['l'=>false, 'd'=>true,  'ai'=>false, 'm'=>true],
        'timestamp' => ['l'=>false, 'd'=>true,  'ai'=>false, 'm'=>true],
        'boolean'   => ['l'=>false, 'd'=>true,  'ai'=>false, 'm'=>false],
        'float'     => ['l'=>false, 'd'=>true,  'ai'=>false, 'm'=>false],
        'decimal'   => ['l'=>true,  'd'=>true,  'ai'=>false, 'm'=>false],
        'json'      => ['l'=>false, 'd'=>false, 'ai'=>false, 'm'=>false]
    ];
    $r = $rules[$t];
    return [
        'nome'      => trim($p['nome']),
        'nome_descrittivo' => trim((string)($p['nome_descrittivo'] ?? '')),
        'tipo'      => $t,
        'lunghezza' => $r['l'] ? $p['lunghezza'] : null,
        'default'   => $r['d'] ? $p['default_value'] : null,
        'null'      => isset($p['nullable']) ? 1 : 0,
        'indice_tipo' => $p['indice_tipo'],
        'ai'        => $r['ai'] && isset($p['auto_increment']) ? 1 : 0,
        'mod'       => $r['m'] && isset($p['modifica']) ? 1 : 0
    ];
}

function syncProjectIntegrity($progetto_id) {
    global $db;
    $tabs = $db->fetchAll("SELECT id, nome FROM tabelle WHERE IDprogetto = ?", [$progetto_id]);
    foreach ($tabs as $t) {
        $db->execute("UPDATE campi SET nome = ? WHERE IDtabella = ? AND ordine = 1", ["id_" . normalize($t['nome']), $t['id']]);
    }
    $fks = $db->fetchAll("SELECT fk.id as fk_id, t_loc.nome as tab_loc, t_ref.nome as tab_ref, fkc.IDcampo_locale, c_loc.nome AS local_field FROM foreign_keys fk JOIN tabelle t_loc ON t_loc.id = fk.IDtabella JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id JOIN campi c_loc ON c_loc.id = fkc.IDcampo_locale JOIN campi c_ref ON c_ref.id = fkc.IDcampo_referenziato JOIN tabelle t_ref ON t_ref.id = c_ref.IDtabella WHERE t_loc.IDprogetto = ?", [$progetto_id]);
    foreach ($fks as $f) {
        $campo_locale = $db->fetch("SELECT nome FROM campi WHERE id = ?", [(int)$f['IDcampo_locale']]);
        $nome_locale_attuale = (string)($campo_locale['nome'] ?? '');
        $is_placeholder = $nome_locale_attuale === '' || str_starts_with($nome_locale_attuale, 'temp_fk_') || str_starts_with($nome_locale_attuale, 'fk_');

        if ($is_placeholder) {
            $base = "id_" . normalize($f['tab_ref']);
            $descrittivo = trim((string)($f['local_field'] ?? ''));
            if ($descrittivo !== '' && !str_starts_with($descrittivo, 'id_')) {
                $base .= '_' . normalize($descrittivo);
            }
            $taken = $db->fetchAll(
                "SELECT nome FROM campi WHERE IDtabella = ? AND nome LIKE ?",
                [(int)$f['IDcampo_locale'], $base . '%']
            );
            $used = [];
            foreach ($taken as $row) {
                $used[(string)($row['nome'] ?? '')] = true;
            }
            $candidate = $base;
            $suffix = 2;
            while (isset($used[$candidate])) {
                $candidate = $base . '_' . $suffix;
                $suffix++;
            }
            $db->execute("UPDATE campi SET nome = ? WHERE id = ?", [$candidate, $f['IDcampo_locale']]);
        }

        $fieldNameForConstraint = (string)($campo_locale['nome'] ?? $f['local_field'] ?? 'id_fk');
        $db->execute(
            "UPDATE foreign_keys SET nome = ? WHERE id = ?",
            ['fk_' . normalize($f['tab_loc']) . '_' . normalize($f['tab_ref']) . '_' . normalize($fieldNameForConstraint), $f['fk_id']]
        );
    }
}

function metadataTableExists($tableName) {
    global $db;
    return (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$tableName]
    ) > 0;
}

function quoteMetadataIdentifier($name) {
    return '`' . str_replace('`', '``', (string)$name) . '`';
}

function getCampoMetadataReferences($campoId) {
    global $db;

    $constraints = $db->fetchAll(
        "SELECT
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            kcu.CONSTRAINT_NAME,
            rc.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE kcu
         JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
           AND kcu.REFERENCED_TABLE_NAME = 'campi'
           AND kcu.REFERENCED_COLUMN_NAME = 'id'
         ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME"
    );

    $references = [];
    foreach ($constraints as $constraint) {
        $table = (string)$constraint['TABLE_NAME'];
        $column = (string)$constraint['COLUMN_NAME'];
        if ($table === '' || $column === '') {
            continue;
        }

        $count = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM " . quoteMetadataIdentifier($table) . " WHERE " . quoteMetadataIdentifier($column) . " = ?",
            [$campoId]
        );
        if ($count <= 0) {
            continue;
        }

        $sample = $db->fetchAll(
            "SELECT * FROM " . quoteMetadataIdentifier($table) . " WHERE " . quoteMetadataIdentifier($column) . " = ? LIMIT 5",
            [$campoId]
        );

        $references[] = [
            'table' => $table,
            'column' => $column,
            'constraint' => (string)$constraint['CONSTRAINT_NAME'],
            'delete_rule' => (string)$constraint['DELETE_RULE'],
            'count' => $count,
            'sample' => $sample,
        ];
    }

    return $references;
}

require_once 'campi_indici.php'; 
require_once 'campi_fk.php'; 

// --- 3. LOGICA AZIONI POST ---
try {
    if (isset($_POST["add_campo"])) {
        $db->beginTransaction();
        $c = getCleanData($_POST);
        $max = $db->fetchColumn("SELECT MAX(ordine) FROM campi WHERE IDtabella = ?", [$tabella_id]);
        $ordine = ($max < 1) ? 2 : $max + 1;
        $nomeDescrittivo = $c['nome_descrittivo'] !== '' ? $c['nome_descrittivo'] : defaultFieldLabel($c['nome']);
        $db->execute("INSERT INTO campi (IDtabella, nome, nome_descrittivo, tipo, lunghezza, nullable, default_value, indice_tipo, auto_increment, modifica, ordine) VALUES (?,?,?,?,?,?,?,?,?,?,?)", 
            [$tabella_id, $c['nome'], $nomeDescrittivo, $c['tipo'], $c['lunghezza'], $c['null'], $c['default'], $c['indice_tipo'], $c['ai'], $c['mod'], $ordine]);
        syncProjectIntegrity($progetto_id); 
        $db->commit(); 
        $_SESSION['success_msg'] = "Campo '{$c['nome']}' aggiunto con successo.";
        refreshCampi($tabella_id);
    }
    if (isset($_POST["update_campo"])) {
        $db->beginTransaction();
        $c = getCleanData($_POST);
        $nomeDescrittivo = $c['nome_descrittivo'] !== '' ? $c['nome_descrittivo'] : defaultFieldLabel($c['nome']);
        $db->execute("UPDATE campi SET nome=?, nome_descrittivo=?, tipo=?, lunghezza=?, nullable=?, default_value=?, indice_tipo=?, auto_increment=?, modifica=? WHERE id=?", 
            [$c['nome'], $nomeDescrittivo, $c['tipo'], $c['lunghezza'], $c['null'], $c['default'], $c['indice_tipo'], $c['ai'], $c['mod'], (int)$_POST["id"]]);
        syncProjectIntegrity($progetto_id); 
        syncAllIndicesNaming($progetto_id); 
        $db->commit(); 
        $_SESSION['success_msg'] = "Campo '{$c['nome']}' aggiornato con successo.";
        refreshCampi($tabella_id);
    }
    if (isset($_POST["delete_campo_submit"])) {
        $db->beginTransaction();
        $id_campo = (int)$_POST["id"];
        $nome_campo = $db->fetchColumn("SELECT nome FROM campi WHERE id=?", [$id_campo]);
        $c = $db->fetch("SELECT ordine FROM campi WHERE id=?", [$id_campo]);
        if($c) {
            if (metadataTableExists('pagine_visualizzazione_modali')) {
                $db->execute("DELETE FROM pagine_visualizzazione_modali WHERE IDcampo_principale = ? OR IDcampo_collegato = ?", [$id_campo, $id_campo]);
            }
            if (metadataTableExists('pagine_visualizzazione_campi')) {
                $db->execute("DELETE FROM pagine_visualizzazione_campi WHERE IDcampo = ?", [$id_campo]);
            }

            $fk_ids = $db->fetchAll("
                SELECT DISTINCT IDforeign_key
                FROM foreign_keys_campi
                WHERE IDcampo_locale = ? OR IDcampo_referenziato = ?
            ", [$id_campo, $id_campo]);

            $db->execute("DELETE FROM indici_campi WHERE IDcampo = ?", [$id_campo]);
            $db->execute("DELETE FROM foreign_keys_campi WHERE IDcampo_locale = ? OR IDcampo_referenziato = ?", [$id_campo, $id_campo]);
            foreach ($fk_ids as $fk_row) {
                $fk_id = (int)$fk_row['IDforeign_key'];
                $remaining = (int)$db->fetchColumn("SELECT COUNT(*) FROM foreign_keys_campi WHERE IDforeign_key = ?", [$fk_id]);
                if ($remaining === 0) {
                    $db->execute("DELETE FROM foreign_keys WHERE id = ?", [$fk_id]);
                }
            }
            $db->execute("DELETE FROM campi WHERE id=?", [$id_campo]);
            $db->execute("UPDATE campi SET ordine = ordine - 1 WHERE IDtabella = ? AND ordine > ?", [$tabella_id, $c["ordine"]]);
            syncProjectIntegrity($progetto_id); 
            syncAllIndicesNaming($progetto_id); 
            $db->commit();
            $_SESSION['success_msg'] = "Campo '{$nome_campo}' eliminato con successo.";
        }
        refreshCampi($tabella_id);
    }
    if (isset($_POST["sposta_campo"])) {
        $curr = $db->fetch("SELECT ordine, nome FROM campi WHERE id = ?", [(int)$_POST["id"]]);
        $n_ord = ($_POST["direzione"] === 'up') ? $curr["ordine"] - 1 : $curr["ordine"] + 1;
        $target = $db->fetch("SELECT id FROM campi WHERE IDtabella = ? AND ordine = ?", [$tabella_id, $n_ord]);
        if ($target) { 
            $db->beginTransaction();
            $db->execute("UPDATE campi SET ordine = ? WHERE id = ?", [$n_ord, (int)$_POST["id"]]); 
            $db->execute("UPDATE campi SET ordine = ? WHERE id = ?", [$curr["ordine"], $target["id"]]); 
            $db->commit();
            $_SESSION['success_msg'] = "Ordinamento del campo '{$curr['nome']}' modificato.";
        } 
        refreshCampi($tabella_id);
    }
} catch (Exception $e) { 
    if ($db->inTransaction()) $db->rollBack();
    $detail = '';
    if (isset($_POST["delete_campo_submit"], $_POST["id"])) {
        try {
            $refs = getCampoMetadataReferences((int)$_POST["id"]);
            if (!empty($refs)) {
                $parts = [];
                foreach ($refs as $ref) {
                    $parts[] = $ref['table'] . '.' . $ref['column'] . ' (' . $ref['constraint'] . ', righe: ' . $ref['count'] . ', delete: ' . $ref['delete_rule'] . ')';
                }
                $detail = ' Metadati collegati: ' . implode('; ', $parts) . '.';
            }
        } catch (Exception $ignored) {
            $detail = '';
        }
    }
    $_SESSION['error_msg'] = "Errore durante l'operazione: " . $e->getMessage() . $detail;
    refreshCampi($tabella_id);
}

$campi = $db->fetchAll("SELECT * FROM campi WHERE IDtabella = ? ORDER BY ordine ASC", [$tabella_id]);
$fk_map = getFkMap($tabella_id);
$campi_in_indici = getCampiInIndici($tabella_id);
$metadata_references_by_campo = [];
foreach ($campi as $campo_meta_row) {
    $metadata_references_by_campo[(int)$campo_meta_row['id']] = getCampoMetadataReferences((int)$campo_meta_row['id']);
}
$project_tables = $db->fetchAll("SELECT id, nome FROM tabelle WHERE IDprogetto = ? AND id != ? ORDER BY nome ASC", [$progetto_id, $tabella_id]);
$foreign_keys = $db->fetchAll("
        SELECT fk.id, fk.nome, fk.on_delete, fk.on_update, 
               c_loc.id AS campo_locale_id, c_loc.nome AS campo_locale_nome, 
               c_ref.id AS campo_referenziato_id, c_ref.nome AS campo_referenziato_nome, 
               t_ref.id AS tabella_referenziata_id, t_ref.nome AS tabella_referenziata_nome 
        FROM foreign_keys fk 
        JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id 
        JOIN campi c_loc ON c_loc.id = fkc.IDcampo_locale 
        JOIN campi c_ref ON c_ref.id = fkc.IDcampo_referenziato 
        JOIN tabelle t_ref ON t_ref.id = c_ref.IDtabella 
        WHERE fk.IDtabella = ? 
        ORDER BY fk.nome ASC", [$tabella_id]);
$incoming_fks = $db->fetchAll("
        SELECT fk.id, fk.nome, fk.on_delete, fk.on_update,
               t_loc.nome AS tabella_locale_nome, c_loc.nome AS campo_locale_nome,
               c_ref.id AS campo_referenziato_id, c_ref.nome AS campo_referenziato_nome
        FROM foreign_keys fk
        JOIN foreign_keys_campi fkc ON fkc.IDforeign_key = fk.id
        JOIN campi c_loc ON c_loc.id = fkc.IDcampo_locale
        JOIN tabelle t_loc ON t_loc.id = c_loc.IDtabella
        JOIN campi c_ref ON c_ref.id = fkc.IDcampo_referenziato
        WHERE c_ref.IDtabella = ?
        ORDER BY fk.nome ASC", [$tabella_id]);
$tipi_dato_enum = ["int","varchar","text","date","datetime","timestamp","boolean","float","decimal","json"];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
    .table-custom { font-size: 0.875rem; }
    .table-custom thead th { color: #6c757d; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
    .table-id-row { background-color: #f8faff !important; border-left: 4px solid #0d6efd; }
    .btn-move { padding: 0.1rem; line-height: 1; color: #0d6efd; background: none; border: none; font-size: 1.1rem; transition: 0.2s; }
    .btn-move:hover { color: #0a58ca; transform: scale(1.2); }
    .fab-button { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; border-radius: 50%; background-color: #0d6efd; color: white; font-size: 26px; display: flex; justify-content: center; align-items: center; box-shadow: 0 4px 12px rgba(0,0,0,0.3); border: none; z-index: 1050; }
    .type-info-box { font-size: 0.85rem; border-left: 3px solid #0d6efd; }
    .text-mandatory { color: #dc3545; font-weight: bold; margin-left: 2px; }
    .badge-tipo { font-size: 0.7rem; padding: 0.35em 0.65em; }
</style>

<div class="container-fluid mt-4 pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 px-3">
        <h3 class="fw-bold"><i class="bi bi-table text-primary me-2"></i> <?= htmlspecialchars($tabella["nome"]) ?></h3>
        <a href="index.php?page=tabelle" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="p-3 border-bottom d-flex gap-2 bg-white">
            <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#fkModal">Creazione campo FK</button>
            <button class="btn btn-outline-dark btn-sm px-3" data-bs-toggle="modal" data-bs-target="#indiceModal">Indice Composto</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle text-center mb-0 table-custom">
                <thead class="table-light">
                    <tr>
                        <th>Ord. <span class="badge bg-primary">PK</span></th>
                        <th class="text-start">Campo</th>
                        <th>Label</th>
                        <th>Tipo</th>
                        <th>Lungh.</th>
                        <th>Default</th>
                        <th>Null</th>
                        <th>Indicizzato</th>
                        <th>Extra</th>
                        <th>FK</th>
                        <th class="text-end pe-4">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($campi as $idx => $c): 
                        $isPk = ($c["ordine"] == 1); 
                        $isFk = isset($fk_map[(int)$c["id"]]);
                        $metadataRefs = $metadata_references_by_campo[(int)$c['id']] ?? [];
                        
                        $connections = [];
                        foreach ($foreign_keys as $fk) {
                            if ($fk['campo_locale_id'] == $c['id']) {
                                $connections[] = [
                                    'direzione' => 'Uscente (Punta a)',
                                    'tabella_ref' => $fk['tabella_referenziata_nome'],
                                    'campo_ref' => $fk['campo_referenziato_nome'],
                                    'regole' => "ON DELETE: {$fk['on_delete']} / ON UPDATE: {$fk['on_update']}"
                                ];
                            }
                        }
                        foreach ($incoming_fks as $fk) {
                            if ($fk['campo_referenziato_id'] == $c['id']) {
                                $connections[] = [
                                    'direzione' => 'Entrante (Riferito da)',
                                    'tabella_ref' => $fk['tabella_locale_nome'],
                                    'campo_ref' => $fk['campo_locale_nome'],
                                    'regole' => "ON DELETE: {$fk['on_delete']} / ON UPDATE: {$fk['on_update']}"
                                ];
                            }
                        }
                        foreach ($metadataRefs as $ref) {
                            $sampleIds = [];
                            foreach (($ref['sample'] ?? []) as $sampleRow) {
                                if (isset($sampleRow['id'])) {
                                    $sampleIds[] = '#' . $sampleRow['id'];
                                }
                            }
                            $connections[] = [
                                'direzione' => 'Metadata',
                                'tabella_ref' => $ref['table'] . '.' . $ref['column'],
                                'campo_ref' => $ref['constraint'],
                                'regole' => 'DELETE: ' . $ref['delete_rule'] . ' / righe: ' . $ref['count'] . (!empty($sampleIds) ? ' / esempi: ' . implode(', ', $sampleIds) : '')
                            ];
                        }
                    ?>
                    <tr class="<?= $isPk ? 'table-id-row' : '' ?>">
                        <td style="width: 60px;">
                            <?php if($isPk): ?>
                                <span class="badge bg-primary">PK</span>
                            <?php else: ?>
                                <div class="d-flex flex-column align-items-center justify-content-center">
                                    <?php if($c["ordine"] > 2): ?>
                                        <form method="POST" class="m-0 p-0 line-height-1">
                                            <input type="hidden" name="id" value="<?= $c["id"] ?>"><input type="hidden" name="direzione" value="up">
                                            <button type="submit" name="sposta_campo" class="btn-move"><i class="bi bi-caret-up-fill"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if($idx < count($campi)-1): ?>
                                        <form method="POST" class="m-0 p-0 line-height-1">
                                            <input type="hidden" name="id" value="<?= $c["id"] ?>"><input type="hidden" name="direzione" value="down">
                                            <button type="submit" name="sposta_campo" class="btn-move"><i class="bi bi-caret-down-fill"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($c["nome"]) ?></td>
                        <td class="text-start text-muted"><?= htmlspecialchars(($c["nome_descrittivo"] ?? '') !== '' ? $c["nome_descrittivo"] : defaultFieldLabel($c["nome"])) ?></td>
                        <td><span class="badge bg-light text-secondary border fw-normal badge-tipo"><?= strtoupper($c["tipo"]) ?></span></td>
                        <td class="text-muted"><?= htmlspecialchars($c["lunghezza"] ?: '-') ?></td>
                        <td><?= htmlspecialchars(($c["default_value"] === null || $c["default_value"] === '') ? '-' : $c["default_value"]) ?></td>
                        <td><?= $c["nullable"] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' ?></td>
                        <td>
                            <?php if ($c["indice_tipo"] == 'UNICO'): ?><span class="badge text-bg-info">uq</span>
                            <?php elseif ($c["indice_tipo"] == 'INDICE'): ?><span class="badge text-bg-primary">idx</span>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if($c["auto_increment"]): ?><i class="bi bi-lightning-fill text-warning" title="AI"></i><?php endif; ?>
                            <?php if($c["modifica"]): ?><i class="bi bi-arrow-repeat text-info" title="On Update"></i><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($connections)): ?>
                                <button class="btn btn-sm btn-outline-info p-1 px-2 btn-view-connections" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#viewConnectionsModal" 
                                        data-campo-nome="<?= htmlspecialchars($c["nome"]) ?>"
                                        data-connections='<?= htmlspecialchars(json_encode($connections), ENT_QUOTES, 'UTF-8') ?>'
                                        title="Visualizza collegamenti">
                                    <i class="bi bi-diagram-3"></i>
                                </button>
                                <?php if (!empty($metadataRefs)): ?>
                                    <span class="badge text-bg-warning ms-1" title="Metadati collegati"><?= count($metadataRefs) ?> Meta</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <?php if(!$isPk): ?>
                                <?php if($isFk): ?>
                                    <?php 
                                        $fk_data = null;
                                        foreach ($foreign_keys as $fk_item) { if ($fk_item['campo_locale_id'] == $c['id']) { $fk_data = $fk_item; break; } }
                                    ?>
                                    <?php if ($fk_data): ?>
                                        <button class="btn btn-sm text-primary p-1 btn-edit-fk" data-bs-toggle="modal" data-bs-target="#editFkModal" 
                                            data-id="<?= $fk_data["id"] ?>" 
                                            data-on_delete="<?= $fk_data["on_delete"] ?>" 
                                            data-on_update="<?= $fk_data["on_update"] ?>"
                                            data-tabref_id="<?= $fk_data["tabella_referenziata_id"] ?>" 
                                            data-tabref_nome="<?= htmlspecialchars($fk_data["tabella_referenziata_nome"]) ?>" 
                                            data-camporef_id="<?= $fk_data["campo_referenziato_id"] ?>"
                                            data-camporef_nome="<?= htmlspecialchars($fk_data["campo_referenziato_nome"]) ?>"
                                            data-nullable="<?= (int)$c["nullable"] ?>"
                                            data-indice_tipo="<?= htmlspecialchars($c["indice_tipo"] ?? 'INDICE') ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare la relazione e il relativo campo?');">
                                            <input type="hidden" name="id_fk_delete" value="<?= $fk_data["id"] ?>"><button type="submit" name="delete_fk" class="btn btn-sm text-danger p-1"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button class="btn btn-sm text-warning p-1" data-bs-toggle="modal" data-bs-target="#editCampoModal" 
                                        data-id="<?= $c["id"] ?>" data-nome="<?= $c["nome"] ?>" data-nome_descrittivo="<?= htmlspecialchars($c["nome_descrittivo"] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-tipo="<?= $c["tipo"] ?>" 
                                        data-lunghezza="<?= $c["lunghezza"] ?>" data-null="<?= $c["nullable"] ?>" 
                                        data-default="<?= htmlspecialchars($c["default_value"] ?? '') ?>" data-indice_tipo="<?= $c["indice_tipo"] ?>"
                                        data-ai="<?= $c["auto_increment"] ?>" data-mod="<?= $c["modifica"] ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="POST" class="d-inline form-delete-campo" data-indici="<?= implode(', ', ($campi_in_indici[$c['id']] ?? [])) ?>">
                                        <input type="hidden" name="id" value="<?= $c["id"] ?>"><button type="submit" name="delete_campo_submit" class="btn btn-sm text-danger p-1 ms-2"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php renderIndiciTable($tabella_id); renderFkTable($tabella_id); ?>
</div>

<button class="fab-button" data-bs-toggle="modal" data-bs-target="#addCampoModal"><i class="bi bi-plus-lg"></i></button>

<!-- MODALE AGGIUNGI / MODIFICA (Sintetizzati per brevità, mantenendo logica JS) -->
<div class="modal fade" id="addCampoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5>Nuovo Campo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nome Campo *</label><input type="text" name="nome" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Nome descrittivo</label><input type="text" name="nome_descrittivo" class="form-control" placeholder="Usato come label predefinita"></div>
                <div class="row">
                    <div class="col-md-7 mb-3"><label class="form-label">Tipo</label><select name="tipo" id="add_tipo" class="form-select"><?php foreach($tipi_dato_enum as $t): ?><option value="<?= $t ?>"><?= strtoupper($t) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5 mb-3" id="add_len_group"><label class="form-label" id="add_len_label">Lunghezza</label><input type="text" name="lunghezza" id="add_lunghezza" class="form-control"></div>
                </div>
                <div class="mb-3" id="add_def_group"><label class="form-label">Default</label><input type="text" name="default_value" id="add_default" class="form-control"></div>
                <div class="row">
                    <div class="col-6 mb-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="nullable" checked><label class="form-check-label">Nullable</label></div></div>
                    <div class="col-6 mb-2"><label class="form-label small fw-bold">Indicizzato</label><select name="indice_tipo" class="form-select form-select-sm"><option value="NO">NO</option><option value="INDICE">INDICE</option><option value="UNICO">UNICO</option></select></div>
                    <div class="col-6 mb-2" id="add_ai_group"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="auto_increment"><label class="form-check-label">Auto Inc.</label></div></div>
                    <div class="col-6 mb-2" id="add_mod_group"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modifica"><label class="form-check-label">On Update</label></div></div>
                </div>
                <div id="info_add" class="form-text p-2 bg-light type-info-box" style="display:none;"></div>
            </div>
            <div class="modal-footer"><button type="submit" name="add_campo" class="btn btn-primary w-100">Crea Campo</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="editCampoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header bg-warning"><h5>Modifica Campo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Nome descrittivo</label><input type="text" name="nome_descrittivo" id="edit_nome_descrittivo" class="form-control" placeholder="Usato come label predefinita"></div>
                <div class="row">
                    <div class="col-md-7 mb-3"><label class="form-label">Tipo</label><select name="tipo" id="edit_tipo" class="form-select"><?php foreach($tipi_dato_enum as $t): ?><option value="<?= $t ?>"><?= strtoupper($t) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5 mb-3" id="edit_len_group"><label class="form-label" id="edit_len_label">Lunghezza</label><input type="text" name="lunghezza" id="edit_lunghezza" class="form-control"></div>
                </div>
                <div class="mb-3" id="edit_def_group"><label class="form-label">Default</label><input type="text" name="default_value" id="edit_default" class="form-control"></div>
                <div class="row">
                    <div class="col-6 mb-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="nullable" id="edit_null"><label class="form-check-label">Nullable</label></div></div>
                    <div class="col-6 mb-2"><label class="form-label small fw-bold">Indicizzato</label><select name="indice_tipo" id="edit_indice_tipo" class="form-select form-select-sm"><option value="NO">NO</option><option value="INDICE">INDICE</option><option value="UNICO">UNICO</option></select></div>
                    <div class="col-6 mb-2" id="edit_ai_group"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="auto_increment" id="edit_ai"><label class="form-check-label">Auto Inc.</label></div></div>
                    <div class="col-6 mb-2" id="edit_mod_group"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modifica" id="edit_mod"><label class="form-check-label">On Update</label></div></div>
                </div>
                <div id="info_edit" class="form-text p-2 bg-light type-info-box" style="display:none;"></div>
            </div>
            <div class="modal-footer"><button type="submit" name="update_campo" class="btn btn-warning w-100">Salva Modifiche</button></div>
        </form>
    </div>
</div>

<?php renderIndiceModals($campi); renderEditIndiceModal($campi); renderFkModal($project_tables); renderEditFkModal($project_tables); ?>

<!-- Modale Visualizza Collegamenti Campo -->
<div class="modal fade" id="viewConnectionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Collegamenti Campo: <strong id="conn_modal_title"></strong></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Direzione</th>
                                <th>Tabella Collegata</th>
                                <th>Campo Collegato</th>
                                <th>Regole (D/U)</th>
                            </tr>
                        </thead>
                        <tbody id="conn_modal_body">
                            <!-- Popolato via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const rules = {
        "int": { l: false, d: true, ai: true, m: false, i: "Intero (es. 10)" },
        "varchar": { l: true, d: true, ai: false, m: false, i: "Testo breve" },
        "text": { l: false, d: false, ai: false, m: false, i: "Testo lungo" },
        "date": { l: false, d: true, ai: false, m: false, i: "AAAA-MM-GG" },
        "datetime": { l: false, d: true, ai: false, m: true, i: "Data/Ora" },
        "timestamp": { l: false, d: true, ai: false, m: true, i: "Timestamp" },
        "boolean": { l: false, d: true, ai: false, m: false, i: "0 o 1" },
        "float": { l: false, d: true, ai: false, m: false, i: "Decimale" },
        "decimal": { l: true, d: true, ai: false, m: false, i: "Prezzi (10,2)" },
        "json": { l: false, d: false, ai: false, m: false, i: 'JSON' }
    };

    function apply(p, type) {
        const r = rules[type];
        const lenInput = document.getElementById(p+'_lunghezza');
        const def = document.getElementById(p+'_default');
        lenInput.disabled = !r.l; lenInput.required = r.l; if(!r.l) lenInput.value = '';
        def.disabled = !r.d; if(!r.d) def.value = '';
        document.getElementById(p+'_ai_group').style.display = r.ai ? 'block' : 'none';
        document.getElementById(p+'_mod_group').style.display = r.m ? 'block' : 'none';
        const info = document.getElementById('info_'+p);
        info.innerHTML = `<strong>Esempio:</strong> ${r.i}`; info.style.display = 'block';
    }

    ['add', 'edit'].forEach(p => {
        const sel = document.getElementById(p+'_tipo');
        sel.addEventListener('change', () => apply(p, sel.value));
    });

    const em = document.getElementById('editCampoModal');
    em.addEventListener('show.bs.modal', function(e) {
        const b = e.relatedTarget;
        document.getElementById('edit_id').value = b.dataset.id;
        document.getElementById('edit_nome').value = b.dataset.nome;
        document.getElementById('edit_nome_descrittivo').value = b.dataset.nome_descrittivo || '';
        document.getElementById('edit_tipo').value = b.dataset.tipo;
        document.getElementById('edit_lunghezza').value = b.dataset.lunghezza;
        document.getElementById('edit_default').value = b.dataset.default;
        document.getElementById('edit_null').checked = b.dataset.null == "1";
        document.getElementById('edit_indice_tipo').value = b.dataset.indice_tipo;
        document.getElementById('edit_ai').checked = b.dataset.ai == "1";
        document.getElementById('edit_mod').checked = b.dataset.mod == "1";
        apply('edit', b.dataset.tipo);
    });

    const vcm = document.getElementById('viewConnectionsModal');
    if (vcm) {
        vcm.addEventListener('show.bs.modal', function(e) {
            const b = e.relatedTarget;
            const campoNome = b.getAttribute('data-campo-nome');
            const conns = JSON.parse(b.getAttribute('data-connections') || '[]');
            
            document.getElementById('conn_modal_title').textContent = campoNome;
            const tbody = document.getElementById('conn_modal_body');
            tbody.innerHTML = '';
            
            if (conns.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Nessun collegamento presente.</td></tr>';
            } else {
                conns.forEach(c => {
                    const row = document.createElement('tr');
                    const badgeClass = c.direzione.includes('Uscente') ? 'bg-primary text-white' : 'bg-success text-white';
                    row.innerHTML = `
                        <td><span class="badge ${badgeClass}">${c.direzione}</span></td>
                        <td><strong>${c.tabella_ref}</strong></td>
                        <td><code>${c.campo_ref}</code></td>
                        <td class="text-muted small">${c.regole}</td>
                    `;
                    tbody.appendChild(row);
                });
            }
        });
    }
});
</script>
