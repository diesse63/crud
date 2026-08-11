<?php
function loadRawMetadata($db, $progetto_id) {
    $progetto = $db->fetch('SELECT * FROM progetti WHERE id = ?', [$progetto_id]);
    $tabelle = $db->fetchAll('SELECT * FROM tabelle WHERE IDprogetto = ? ORDER BY ordine ASC', [$progetto_id]);

    $campi_per_tabella = [];
    $indici_per_tabella = [];
    $fk_per_tabella = [];
    $campi_per_id = [];

    foreach ($tabelle as &$tabella) { // Added & to modify $tabella by reference
        $tid = (int)$tabella['id'];

        // Check if the table actually exists in the database before querying for its structure
        $table_exists_result = $db->fetch("SELECT COUNT(*) as count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$tabella['nome']]);
        $table_exists = ($table_exists_result['count'] ?? 0) > 0;

        if ($table_exists) {
            // Fetch engine, charset, collation, and description
            $create_table_sql_result = $db->fetch("SHOW CREATE TABLE `{$tabella['nome']}`");
            $create_table_sql = $create_table_sql_result['Create Table'] ?? '';

            // $description is already in $tabella['descrizione']

            if (preg_match('/ENGINE=(\w+)/', $create_table_sql, $matches)) {
                $tabella['engine'] = $matches[1];
            } else {
                $tabella['engine'] = '';
            }
            if (preg_match('/DEFAULT CHARACTER SET (\w+)/', $create_table_sql, $matches)) {
                $tabella['charset'] = $matches[1];
            } else {
                $tabella['charset'] = '';
            }
            if (preg_match('/COLLATE=(\w+)/', $create_table_sql, $matches)) {
                $tabella['collation'] = $matches[1];
            } else {
                $tabella['collation'] = '';
            }

            // Fetch check constraints
            $check_constraints = $db->fetchAll("
                SELECT
                    CONSTRAINT_NAME,
                    CHECK_CLAUSE
                FROM
                    information_schema.CHECK_CONSTRAINTS
                WHERE
                    CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ", [$tabella['nome']]);
            $tabella['check_constraints'] = $check_constraints;
        } else {
            // If table doesn't exist, set default/empty values
            $tabella['engine'] = '';
            $tabella['charset'] = '';
            $tabella['collation'] = '';
            $tabella['check_constraints'] = [];
        }

        $campi = $db->fetchAll('SELECT * FROM campi WHERE IDtabella = ? ORDER BY ordine ASC', [$tid]);
        $campi_per_tabella[$tid] = $campi;

        foreach ($campi as $campo) {
            $campi_per_id[(int)$campo['id']] = [
                'tabella_id' => $tid,
                'tabella_nome' => $tabella['nome'],
                'campo' => $campo
            ];
        }

        $indici = $db->fetchAll('SELECT * FROM indici WHERE IDtabella = ?', [$tid]);
        foreach ($indici as &$idx) {
            $idx['campi'] = $db->fetchAll('SELECT c.nome FROM indici_campi ic JOIN campi c ON c.id = ic.IDcampo WHERE ic.IDindice = ? ORDER BY ic.ordine ASC', [$idx['id']]);
        }
        $indici_per_tabella[$tid] = $indici;

        $fks = $db->fetchAll('SELECT * FROM foreign_keys WHERE IDtabella = ?', [$tid]);
        foreach ($fks as &$fk) {
            $fk['campi'] = $db->fetchAll('SELECT * FROM foreign_keys_campi WHERE IDforeign_key = ? ORDER BY ordine ASC', [$fk['id']]);
        }
        $fk_per_tabella[$tid] = $fks;
    }

    $formatted_tabelle = [];
    foreach ($tabelle as $tabella) {
        $tid = (int)$tabella['id'];
        $tabella['campi'] = $campi_per_tabella[$tid] ?? [];
        $tabella['indici'] = $indici_per_tabella[$tid] ?? [];
        $tabella['foreign_keys'] = $fk_per_tabella[$tid] ?? [];
        $formatted_tabelle[] = $tabella;
    }

    return [
        'progetto' => $progetto,
        'tabelle' => $formatted_tabelle,
        'campi_per_id' => $campi_per_id
    ];
}