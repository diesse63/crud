<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db.php';

/**
 * FUNZIONI ESISTENTI (Invariate)
 */
if (!function_exists('sanitizeFolderName')) {
    function sanitizeFolderName($name) {
        $name = strtolower(trim($name));
        $name = str_replace(array(' ', '.', ',', '!', '?'), '_', $name);
        $name = preg_replace('/[^a-z0-9\_]/', '', $name);
        return $name ?: 'progetto_senza_nome';
    }
}

if (!function_exists('dfs_sort')) {
    function dfs_sort($node, &$adj, &$visited, &$stack, &$sorted) {
        $visited[$node] = true;
        $stack[$node] = true;
        if (isset($adj[$node])) {
            foreach ($adj[$node] as $dep) {
                if (!isset($visited[$dep])) {
                    dfs_sort($dep, $adj, $visited, $stack, $sorted);
                }
            }
        }
        unset($stack[$node]);
        $sorted[] = $node;
    }
}

if (!function_exists('sqlIdentifier')) {
    function sqlIdentifier($name) {
        return '`' . str_replace('`', '``', (string)$name) . '`';
    }
}

if (!function_exists('sqlString')) {
    function sqlString($value) {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}

if (!function_exists('sqlDefaultValue')) {
    function sqlDefaultValue($value) {
        $value = (string)$value;
        $upper = strtoupper(trim($value));
        if (in_array($upper, ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
            return $upper;
        }
        return is_numeric($value) ? $value : sqlString($value);
    }
}

if (!function_exists('sqlColumnList')) {
    function sqlColumnList(array $columns) {
        return implode(", ", array_map('sqlIdentifier', $columns));
    }
}

if (!function_exists('schemaHasIndexOnColumns')) {
    function schemaHasIndexOnColumns(array $indexes, array $columns) {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('isVirtualSchemaField')) {
    function isVirtualSchemaField($name) {
        return str_starts_with((string)$name, '__virtual_pvc_');
    }
}

$db = new Database();
$sql_script = "";
$analysis_report = [];
$schema_data = []; 

$progetto_id = $_SESSION['progetto_id'] ?? null;
$progetto_nome = $_SESSION['progetto_nome'] ?? 'Nessun Progetto Selezionato';

if ($progetto_id) {
    $folder_name = sanitizeFolderName($progetto_nome);
    $project_folder_path = __DIR__ . DIRECTORY_SEPARATOR . 'sito' . DIRECTORY_SEPARATOR . $folder_name;
    $file_sql_path = $project_folder_path . DIRECTORY_SEPARATOR . 'schema.sql';
    $project_folder_exists = is_dir($project_folder_path);

    try {
        $tables_data = $db->fetchAll("SELECT * FROM tabelle WHERE IDprogetto = ?", [$progetto_id]);
        $tables_by_name = [];
        foreach ($tables_data as $t) $tables_by_name[$t['nome']] = $t;

        if (empty($tables_data)) throw new Exception("Nessuna tabella trovata per questo progetto.");

        // --- 1. COSTRUZIONE GRAFO DIPENDENZE ---
        $all_fk_deps = $db->fetchAll("
            SELECT t_loc.nome as local_table, t_ref.nome as referenced_table
            FROM foreign_keys_campi fkc
            JOIN campi cl ON fkc.IDcampo_locale = cl.id
            JOIN campi cr ON fkc.IDcampo_referenziato = cr.id
            JOIN tabelle t_loc ON cl.IDtabella = t_loc.id
            JOIN tabelle t_ref ON cr.IDtabella = t_ref.id
            WHERE t_loc.IDprogetto = ? AND t_ref.IDprogetto = ?
              AND LEFT(cl.nome, 14) <> '__virtual_pvc_'
              AND LEFT(cr.nome, 14) <> '__virtual_pvc_'
        ", [$progetto_id, $progetto_id]);

        $adj = [];
        foreach ($tables_by_name as $name => $t) $adj[$name] = [];
        foreach ($all_fk_deps as $fk) {
            if ($fk['local_table'] !== $fk['referenced_table']) {
                $adj[$fk['local_table']][] = $fk['referenced_table'];
            }
        }

        $visited = []; $stack = []; $sorted_table_names = [];
        foreach (array_keys($adj) as $node) {
            if (!isset($visited[$node])) dfs_sort($node, $adj, $visited, $stack, $sorted_table_names);
        }
        $creation_order = $sorted_table_names;

        // --- 2. GENERAZIONE SQL (Sempre eseguita per mantenere aggiornato lo schema_data JSON) ---
        $current_sql = "-- SQL Script generato per progetto: {$progetto_nome}\n";
        $current_sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($creation_order as $tname) {
            $table = $tables_by_name[$tname];
            $fields = $db->fetchAll("SELECT * FROM campi WHERE IDtabella = ? AND LEFT(nome, 14) <> '__virtual_pvc_' ORDER BY ordine", [$table['id']]);
            $schema_data[$tname] = ['fields' => [], 'primary_key' => [], 'indexes' => [], 'foreign_keys' => []];
            
            $current_sql .= "CREATE TABLE IF NOT EXISTS " . sqlIdentifier($tname) . " (\n";
            $definitions = []; $pk_cols = []; $first_field_name = null;

            foreach ($fields as $idx => $f) {
                if ($idx === 0) $first_field_name = $f['nome'];
                $def = "  " . sqlIdentifier($f['nome']) . " " . strtoupper($f['tipo']);
                if (!empty($f['lunghezza'])) $def .= "({$f['lunghezza']})";
                $def .= ($f['nullable'] ? " NULL" : " NOT NULL");
                if ($f['default_value'] !== null && $f['default_value'] !== '') {
                    $def .= " DEFAULT " . sqlDefaultValue($f['default_value']);
                } elseif ($f['tipo'] === 'timestamp' && $f['modifica']) {
                    $def .= " DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
                }
                if ($f['auto_increment']) $def .= " AUTO_INCREMENT";
                if (!empty($f['commento'])) $def .= " COMMENT " . sqlString($f['commento']);
                $definitions[] = $def;

                if ($f['indice_tipo'] === 'PRIMARY' || $f['indice_tipo'] === 'P' || $f['auto_increment']) $pk_cols[] = $f['nome'];
                if ($f['indice_tipo'] === 'UNICO') {
                    $definitions[] = "  UNIQUE KEY " . sqlIdentifier("uniq_{$f['nome']}") . " (" . sqlColumnList([$f['nome']]) . ")";
                    $schema_data[$tname]['indexes'][] = ['name' => "uniq_{$f['nome']}", 'type' => 'UNIQUE', 'columns' => [$f['nome']]];
                }
                if ($f['indice_tipo'] === 'INDICE') {
                    $definitions[] = "  KEY " . sqlIdentifier("idx_{$f['nome']}") . " (" . sqlColumnList([$f['nome']]) . ")";
                    $schema_data[$tname]['indexes'][] = ['name' => "idx_{$f['nome']}", 'type' => 'INDEX', 'columns' => [$f['nome']]];
                }

                $schema_data[$tname]['fields'][] = ['name' => $f['nome'], 'type' => $f['tipo'], 'length' => $f['lunghezza'], 'nullable' => (bool)$f['nullable'], 'auto_increment' => (bool)$f['auto_increment'], 'default' => $f['default_value']];
            }
            if (empty($pk_cols) && $first_field_name) $pk_cols[] = $first_field_name;
            $pk_cols = array_unique($pk_cols);
            if (!empty($pk_cols)) {
                $definitions[] = "  PRIMARY KEY (" . sqlColumnList($pk_cols) . ")";
                $schema_data[$tname]['primary_key'] = array_values($pk_cols);
            }

            // Indici composti definiti nella pannellata Indici
            $table_indexes = $db->fetchAll("
                SELECT i.id, i.nome, i.tipo
                FROM indici i
                WHERE i.IDtabella = ?
                ORDER BY i.nome
            ", [$table['id']]);
            foreach ($table_indexes as $idx_def) {
                $idx_fields = $db->fetchAll("
                    SELECT c.nome
                    FROM indici_campi ic
                    JOIN campi c ON c.id = ic.IDcampo
                    WHERE ic.IDindice = ?
                      AND LEFT(c.nome, 14) <> '__virtual_pvc_'
                    ORDER BY ic.ordine
                ", [$idx_def['id']]);
                $idx_cols = array_map(fn($c) => $c['nome'], $idx_fields);
                if (empty($idx_cols) || schemaHasIndexOnColumns($schema_data[$tname]['indexes'], $idx_cols)) continue;

                $idx_type = strtoupper((string)$idx_def['tipo']);
                $prefix = in_array($idx_type, ['UNIQUE', 'FULLTEXT', 'SPATIAL'], true) ? $idx_type . " KEY" : "KEY";
                $definitions[] = "  {$prefix} " . sqlIdentifier($idx_def['nome']) . " (" . sqlColumnList($idx_cols) . ")";
                $schema_data[$tname]['indexes'][] = ['name' => $idx_def['nome'], 'type' => $idx_type ?: 'INDEX', 'columns' => $idx_cols];
            }
            
            // Foreign Keys
            $table_fks = $db->fetchAll("SELECT DISTINCT fk.*, t_ref.nome as ref_table FROM foreign_keys fk JOIN foreign_keys_campi fkc ON fk.id = fkc.IDforeign_key JOIN campi cl ON fkc.IDcampo_locale = cl.id JOIN campi cr ON fkc.IDcampo_referenziato = cr.id JOIN tabelle t_ref ON cr.IDtabella = t_ref.id WHERE cl.IDtabella = ? AND LEFT(cl.nome, 14) <> '__virtual_pvc_' AND LEFT(cr.nome, 14) <> '__virtual_pvc_'", [$table['id']]);
            foreach ($table_fks as $fk) {
                $fk_details = $db->fetchAll("SELECT cl.nome as loc_f, cr.nome as ref_f FROM foreign_keys_campi fkc JOIN campi cl ON fkc.IDcampo_locale = cl.id JOIN campi cr ON fkc.IDcampo_referenziato = cr.id WHERE fkc.IDforeign_key = ? AND LEFT(cl.nome, 14) <> '__virtual_pvc_' AND LEFT(cr.nome, 14) <> '__virtual_pvc_' ORDER BY fkc.ordine", [$fk['id']]);
                $loc_names = array_map(fn($d) => $d['loc_f'], $fk_details);
                $ref_names = array_map(fn($d) => $d['ref_f'], $fk_details);
                if (empty($loc_names) || empty($ref_names)) continue;
                $loc_cols = sqlColumnList($loc_names);
                $ref_cols = sqlColumnList($ref_names);
                if (!schemaHasIndexOnColumns($schema_data[$tname]['indexes'], $loc_names)) {
                    $definitions[] = "  KEY " . sqlIdentifier("idx_fk_{$fk['nome']}") . " ({$loc_cols})";
                    $schema_data[$tname]['indexes'][] = ['name' => "idx_fk_{$fk['nome']}", 'type' => 'INDEX', 'columns' => $loc_names];
                }
                $definitions[] = "  CONSTRAINT " . sqlIdentifier($fk['nome']) . " FOREIGN KEY ({$loc_cols}) REFERENCES " . sqlIdentifier($fk['ref_table']) . " ({$ref_cols}) ON DELETE {$fk['on_delete']} ON UPDATE {$fk['on_update']}";
                $schema_data[$tname]['foreign_keys'][] = ['name' => $fk['nome'], 'columns' => $loc_names, 'referenced_table' => $fk['ref_table'], 'referenced_columns' => $ref_names, 'on_delete' => $fk['on_delete'], 'on_update' => $fk['on_update']];
            }
            $current_sql .= implode(",\n", $definitions) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n\n";
        }
        $current_sql .= "SET FOREIGN_KEY_CHECKS=1;";
        
        $sql_script = $current_sql;

    } catch (Exception $e) {
        $sql_script = "-- Errore: " . $e->getMessage();
    }

    // --- 3. LOGICA DI ANALISI ---
    if (isset($_POST['analyze_script'])) {
        $analysis_report = [
            'sections' => [
                'tabelle' => ['status' => 'OK', 'elements' => count($tables_data), 'details' => []],
                'validazione' => ['status' => 'OK', 'elements' => count($creation_order), 'details' => ["Ordinamento completato."]]
            ]
        ];
    }

    // --- 4. DOWNLOAD E SALVATAGGIO ---
    if (isset($_POST['download_script'])) {
        if (!is_dir($project_folder_path)) @mkdir($project_folder_path, 0777, true);
        @file_put_contents($file_sql_path, $current_sql); // Salvataggio fisico

        $format = $_POST['format'] ?? 'sql';
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="schema.json"');
            echo json_encode($schema_data, JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="schema.sql"');
            echo $current_sql;
        }
        exit;
    }
?>

<!-- LAYOUT (Invariato) -->
<style>
    .sql-view { background: #272822; color: #f8f8f2; border: 1px solid #ddd; padding: 20px; font-family: 'Consolas', 'Courier New', monospace; white-space: pre; max-height: 500px; overflow: auto; margin-top: 15px; border-radius: 8px; font-size: 0.9rem; border-left: 6px solid #a6e22e; }
    .folder-info { padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem; border: 1px solid transparent; }
    .folder-info.exists { background: #e8f5e9; border-color: #c8e6c9; color: #2e7d32; }
    .folder-info.not-exists { background: #ffebee; border-color: #ffcdd2; color: #c62828; }
</style>

<div class="container-fluid p-0">
    <h2>Script e Analisi Database</h2>
    <div class="project-card mb-3">Progetto Attivo: <strong><?= htmlspecialchars($progetto_nome) ?></strong></div>

    <div class="folder-info <?= $project_folder_exists ? 'exists' : 'not-exists' ?>">
        <i class="bi <?= $project_folder_exists ? 'bi-folder-check' : 'bi-folder-x' ?> me-2"></i>
        Percorso: <code><?= htmlspecialchars($project_folder_path) ?></code> 
        (<strong><?= $project_folder_exists ? 'Trovato' : 'Non creato' ?></strong>)
    </div>

    <!-- Visualizzazione SQL -->
    <div class="sql-view" id="sqlDisplay"><?= htmlspecialchars($sql_script) ?></div>

    <div class="btn-group mt-4 mb-5">
        <form method="post" style="display: contents;">
            <button type="submit" name="analyze_script" class="btn btn-primary">Analisi</button>
            <select name="format" class="form-select" style="width: auto;">
                <option value="sql">SQL</option>
                <option value="json">JSON</option>
            </select>
            <button type="submit" name="download_script" class="btn btn-success">Genera, Salva e Scarica</button>
        </form>
        <button type="button" class="btn btn-info" onclick="copyToClipboard()">Copia SQL</button>
    </div>
</div>

<script>
function copyToClipboard() {
    const sqlDisplay = document.getElementById('sqlDisplay');
    const textToCopy = sqlDisplay.textContent;

    navigator.clipboard.writeText(textToCopy).then(() => {
        alert('SQL script copiato negli appunti!');
    }).catch(err => {
        console.error('Errore durante la copia: ', err);
        alert('Impossibile copiare lo script SQL. Si prega di copiare manualmente.');
    });
}
</script>

<?php } else { ?>
    <div class="alert alert-danger">Nessun progetto selezionato.</div>
<?php } ?>
