<?php
if(!isset($_SESSION['progetto_id'])) {
    $_SESSION['error_msg'] = "Seleziona un progetto prima di esportare.";
    header("Location: index.php?page=progetti");
    exit;
}

$progetto_id = $_SESSION['progetto_id'];
$tabelle = $db->fetchAll("SELECT * FROM tabelle WHERE progetto_id = ? ORDER BY ordine", [$progetto_id]);

$sql_final = "-- SQL GENERATO PER ALTERVISTA (MySQL 8.0)\n";
$sql_final .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSTART TRANSACTION;\nSET time_zone = \"+00:00\";\n\n";

foreach($tabelle as $t) {
    $sql_final .= "-- Struttura tabella `{$t['nome']}`\n";
    $sql_final .= "CREATE TABLE `{$t['nome']}` (\n";
    
    $campi = $db->fetchAll("
        SELECT c.*, t.nome as tipo_nome 
        FROM campi c 
        JOIN tipi_dato t ON c.tipo_id = t.id 
        WHERE c.tabella_id = ? ORDER BY c.ordine", [$t['id']]);
    
    $righe = [];
    $primaria = [];

    foreach($campi as $c) {
        $riga = "  `{$c['nome']}` {$c['tipo_nome']}";
        if($c['lunghezza']) $riga .= "({$c['lunghezza']})";
        $riga .= ($c['nullable']) ? " DEFAULT NULL" : " NOT NULL";
        if($c['auto_increment']) $riga .= " AUTO_INCREMENT";
        if($c['default_value']) $riga .= " DEFAULT '{$c['default_value']}'";
        
        $righe[] = $riga;
        
        // Verifica se è PK (dovresti avere una logica nella tabella chiavi_campi)
        // Per ora ipotizziamo una logica semplificata:
        $is_pk = $db->fetchColumn("SELECT COUNT(*) FROM chiavi_campi cc 
                                   JOIN chiavi k ON cc.chiave_id = k.id 
                                   WHERE cc.campo_id = ? AND k.tipo = 'PRIMARY'", [$c['id']]);
        if($is_pk) $primaria[] = "`{$c['nome']}`";
    }

    if(!empty($primaria)) {
        $righe[] = "  PRIMARY KEY (" . implode(", ", $primaria) . ")";
    }

    $sql_final .= implode(",\n", $righe);
    $sql_final .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n\n";
}

$sql_final .= "COMMIT;";
?>

<h3>Export SQL</h3>
<div class="card">
    <div class="card-body">
        <textarea class="form-control font-monospace" rows="15" readonly><?= $sql_final ?></textarea>
        <button class="btn btn-dark mt-3" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(() => showToast('success', 'SQL copiato negli appunti!'))">Copia SQL</button>
    </div>
</div>