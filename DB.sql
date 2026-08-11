-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Creato il: Ago 05, 2026 alle 07:35
-- Versione del server: 8.0.45
-- Versione PHP: 8.0.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `my_dasi`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `campi`
--

CREATE TABLE `campi` (
  `id` int NOT NULL,
  `IDtabella` int NOT NULL,
  `nome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `nome_descrittivo` varchar(150) DEFAULT NULL,
  `tipo` enum('int','varchar','text','date','datetime','timestamp','boolean','float','decimal','json') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `lunghezza` varchar(20) DEFAULT NULL,
  `default_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `indice_tipo` enum('NO','INDICE','UNICO') NOT NULL DEFAULT 'NO',
  `nullable` tinyint(1) DEFAULT '0',
  `auto_increment` tinyint(1) DEFAULT NULL,
  `modifica` tinyint(1) NOT NULL DEFAULT '0',
  `ordine` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `foreign_keys`
--

CREATE TABLE `foreign_keys` (
  `id` int NOT NULL,
  `IDtabella` int NOT NULL,
  `nome` varchar(100) NOT NULL,
  `on_delete` enum('RESTRICT','CASCADE') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'RESTRICT',
  `on_update` enum('RESTRICT','CASCADE') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'CASCADE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `foreign_keys_campi`
--

CREATE TABLE `foreign_keys_campi` (
  `id` int NOT NULL,
  `IDforeign_key` int NOT NULL,
  `IDcampo_locale` int NOT NULL,
  `IDcampo_referenziato` int NOT NULL,
  `ordine` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `indici`
--

CREATE TABLE `indici` (
  `id` int NOT NULL,
  `IDtabella` int NOT NULL,
  `nome` varchar(100) NOT NULL,
  `tipo` enum('INDEX','UNIQUE','FULLTEXT','SPATIAL') NOT NULL DEFAULT 'INDEX'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `indici_campi`
--

CREATE TABLE `indici_campi` (
  `id` int NOT NULL,
  `IDindice` int NOT NULL,
  `IDcampo` int NOT NULL,
  `ordine` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `menu_home_config`
--

CREATE TABLE `menu_home_config` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `titolo_sito` varchar(200) NOT NULL,
  `descrizione_home` text,
  `data_modifica` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `menu_home_voci`
--

CREATE TABLE `menu_home_voci` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `IDpadre` int DEFAULT NULL,
  `tipo` enum('PAGINA','GRUPPO') NOT NULL DEFAULT 'PAGINA',
  `nome_file` varchar(255) DEFAULT NULL,
  `label` varchar(200) NOT NULL,
  `icona` varchar(100) NOT NULL DEFAULT 'bi-file-earmark',
  `visibile` tinyint(1) NOT NULL DEFAULT '1',
  `ordine` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pagine_visualizzazione`
--

CREATE TABLE `pagine_visualizzazione` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `IDtipo` int DEFAULT NULL,
  `nome_pagina` varchar(150) NOT NULL,
  `nome_file` varchar(150) NOT NULL,
  `descrizione` text,
  `IDtabella_principale` int NOT NULL,
  `titolo_pagina` varchar(200) NOT NULL,
  `righe_per_pagina` int NOT NULL DEFAULT '25',
  `ricerca_abilitata` tinyint(1) NOT NULL DEFAULT '1',
  `ordinamento_abilitato` tinyint(1) NOT NULL DEFAULT '1',
  `paginazione_abilitata` tinyint(1) NOT NULL DEFAULT '1',
  `mostra_dettaglio_modale` tinyint(1) NOT NULL DEFAULT '1',
  `crud_abilitato` tinyint(1) NOT NULL DEFAULT '0',
  `crud_aggiungi` tinyint(1) NOT NULL DEFAULT '0',
  `crud_modifica` tinyint(1) NOT NULL DEFAULT '0',
  `crud_cancella` tinyint(1) NOT NULL DEFAULT '0',
  `percorso_file` varchar(500) DEFAULT NULL,
  `sql_generata` longtext,
  `stato` enum('BOZZA','GENERATA') NOT NULL DEFAULT 'BOZZA',
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_modifica` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `data_generazione` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pagine_visualizzazione_campi`
--

CREATE TABLE `pagine_visualizzazione_campi` (
  `id` int NOT NULL,
  `IDpagina` int NOT NULL,
  `IDpagina_tabella` int NOT NULL,
  `IDcampo` int NOT NULL,
  `ordine` int NOT NULL DEFAULT '0',
  `etichetta` varchar(150) DEFAULT NULL,
  `nome_qualificato` varchar(255) NOT NULL,
  `visibile_tabella` tinyint(1) NOT NULL DEFAULT '1',
  `visibile_scheda` tinyint(1) NOT NULL DEFAULT '1',
  `visibile_modale` tinyint(1) NOT NULL DEFAULT '1',
  `ordinabile` tinyint(1) NOT NULL DEFAULT '1',
  `ricercabile` tinyint(1) NOT NULL DEFAULT '1',
  `allineamento` enum('SINISTRA','CENTRO','DESTRA') NOT NULL DEFAULT 'SINISTRA',
  `formato_visualizzazione` enum('AUTOMATICO','TESTO','NUMERO','VALUTA','DATA','DATA_ORA','BOOLEANO','JSON','IMMAGINE','FILE','URL','EMAIL') NOT NULL DEFAULT 'AUTOMATICO',
  `larghezza_colonna` varchar(20) DEFAULT NULL,
  `larghezza_bootstrap` varchar(2) NOT NULL DEFAULT '6',
  `filtro_abilitato` tinyint(1) NOT NULL DEFAULT '0',
  `tipo_filtro` enum('TESTO','UGUALE','INTERVALLO_NUMERO','INTERVALLO_DATA','BOOLEANO') NOT NULL DEFAULT 'TESTO',
  `link_pagina_id` int DEFAULT NULL,
  `link_parametro` varchar(100) DEFAULT NULL,
  `link_campo_valore` varchar(255) DEFAULT NULL,
  `percorso_base` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pagine_visualizzazione_modali`
--

CREATE TABLE `pagine_visualizzazione_modali` (
  `id` int NOT NULL,
  `IDpagina` int NOT NULL,
  `IDtabella_collegata` int NOT NULL,
  `IDforeign_key` int DEFAULT NULL,
  `IDcampo_principale` int NOT NULL,
  `IDcampo_collegato` int NOT NULL,
  `titolo_modale` varchar(255) NOT NULL DEFAULT 'Dati collegati',
  `tipo_visualizzazione` enum('TABELLA','SCHEDA_SINGOLA') NOT NULL,
  `configurazione_campi` longtext NOT NULL,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_modifica` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pagine_visualizzazione_tabelle`
--

CREATE TABLE `pagine_visualizzazione_tabelle` (
  `id` int NOT NULL,
  `IDpagina` int NOT NULL,
  `IDtabella` int NOT NULL,
  `tipo_tabella` enum('PRINCIPALE','SECONDARIA') NOT NULL DEFAULT 'SECONDARIA',
  `alias_sql` varchar(50) DEFAULT NULL,
  `IDforeign_key` int DEFAULT NULL,
  `tipo_join` enum('INNER','LEFT') NOT NULL DEFAULT 'LEFT',
  `ordine_join` int NOT NULL DEFAULT '0',
  `selezionata` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `pagine_visualizzazione_tipo`
--

CREATE TABLE `pagine_visualizzazione_tipo` (
  `id` int NOT NULL,
  `codice` enum('singola','tabellare','masterdetail') NOT NULL,
  `descrizione` varchar(255) NOT NULL DEFAULT '',
  `righe_per_pagina` int NOT NULL DEFAULT '25',
  `righe_bloccate` tinyint(1) NOT NULL DEFAULT '0',
  `ordine` int NOT NULL DEFAULT '0',
  `attivo` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `progetti`
--

CREATE TABLE `progetti` (
  `id` int NOT NULL,
  `nome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `descrizione` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `data_creazione` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `progetti_db_destinatario`
--

CREATE TABLE `progetti_db_destinatario` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `host` varchar(255) NOT NULL DEFAULT 'localhost',
  `db_name` varchar(255) NOT NULL DEFAULT '',
  `db_user` varchar(255) NOT NULL DEFAULT '',
  `db_pass_cifrata` text,
  `charset_name` varchar(50) NOT NULL DEFAULT 'utf8mb4',
  `auto_initialize` tinyint(1) NOT NULL DEFAULT '1',
  `auto_apply` tinyint(1) NOT NULL DEFAULT '1',
  `modify_columns` tinyint(1) NOT NULL DEFAULT '1',
  `drop_extra_columns` tinyint(1) NOT NULL DEFAULT '0',
  `drop_extra_tables` tinyint(1) NOT NULL DEFAULT '0',
  `make_extra_nullable` tinyint(1) NOT NULL DEFAULT '1',
  `sync_schema_files` tinyint(1) NOT NULL DEFAULT '1',
  `ultimo_db_php_hash` char(64) DEFAULT NULL,
  `ultima_generazione` datetime DEFAULT NULL,
  `aggiornato_il` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `progetti_deploy_ftp`
--

CREATE TABLE `progetti_deploy_ftp` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `host` varchar(255) NOT NULL,
  `porta` int NOT NULL DEFAULT '21',
  `username` varchar(255) NOT NULL,
  `password_cifrata` text NOT NULL,
  `cartella_remota` varchar(500) NOT NULL DEFAULT '/',
  `usa_ftps` tinyint(1) NOT NULL DEFAULT '0',
  `modalita_passiva` tinyint(1) NOT NULL DEFAULT '1',
  `ultima_pubblicazione` datetime DEFAULT NULL,
  `ultimo_esito` varchar(20) DEFAULT NULL,
  `ultimo_messaggio` text,
  `aggiornato_il` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `progetti_deploy_https`
--

CREATE TABLE `progetti_deploy_https` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `receiver_url` varchar(500) NOT NULL,
  `token_cifrato` text NOT NULL,
  `project_uuid` char(36) DEFAULT NULL,
  `cartella_destinazione` varchar(500) NOT NULL DEFAULT '',
  `application_url` varchar(500) NOT NULL DEFAULT '',
  `crea_backup` tinyint(1) NOT NULL DEFAULT '1',
  `backup_da_mantenere` int NOT NULL DEFAULT '5',
  `elimina_file_mancanti` tinyint(1) NOT NULL DEFAULT '0',
  `ultima_pubblicazione` datetime DEFAULT NULL,
  `ultimo_esito` varchar(20) DEFAULT NULL,
  `ultimo_messaggio` text,
  `ultimo_esito_config` varchar(20) DEFAULT NULL,
  `ultimo_messaggio_config` text,
  `ultimo_esito_db` varchar(20) DEFAULT NULL,
  `ultimo_messaggio_db` text,
  `ultimo_esito_sync` varchar(20) DEFAULT NULL,
  `ultimo_messaggio_sync` text,
  `last_archive_sha256` char(64) DEFAULT NULL,
  `aggiornato_il` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `progetti_setup_db`
--

CREATE TABLE `progetti_setup_db` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `db_host` varchar(255) NOT NULL DEFAULT 'localhost',
  `db_name` varchar(255) NOT NULL DEFAULT '',
  `db_user` varchar(255) NOT NULL DEFAULT '',
  `db_pass_cifrata` text,
  `ultima_generazione` datetime DEFAULT NULL,
  `ultimo_messaggio` text,
  `aggiornato_il` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `tabelle`
--

CREATE TABLE `tabelle` (
  `id` int NOT NULL,
  `IDprogetto` int NOT NULL,
  `nome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `descrizione` text,
  `ordine` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `campi`
--
ALTER TABLE `campi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_campi_tabella_nome` (`IDtabella`,`nome`),
  ADD KEY `idx_IDtabella_nome` (`IDtabella`,`nome`) USING BTREE;

--
-- Indici per le tabelle `foreign_keys`
--
ALTER TABLE `foreign_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_fk_nome` (`IDtabella`,`nome`),
  ADD KEY `idx_fk_tabella` (`IDtabella`);

--
-- Indici per le tabelle `foreign_keys_campi`
--
ALTER TABLE `foreign_keys_campi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_fk_campi` (`IDforeign_key`,`ordine`),
  ADD KEY `idx_fkcampo_fk` (`IDforeign_key`),
  ADD KEY `idx_fkcampo_locale` (`IDcampo_locale`),
  ADD KEY `idx_fkcampo_ref` (`IDcampo_referenziato`);

--
-- Indici per le tabelle `indici`
--
ALTER TABLE `indici`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_indici_nome` (`IDtabella`,`nome`),
  ADD KEY `idx_indici_tabella` (`IDtabella`);

--
-- Indici per le tabelle `indici_campi`
--
ALTER TABLE `indici_campi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_indice_campo` (`IDindice`,`IDcampo`),
  ADD UNIQUE KEY `uk_indice_ordine` (`IDindice`,`ordine`),
  ADD KEY `idx_ic_indice` (`IDindice`),
  ADD KEY `idx_ic_campo` (`IDcampo`);

--
-- Indici per le tabelle `menu_home_config`
--
ALTER TABLE `menu_home_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_menu_home_progetto` (`IDprogetto`);

--
-- Indici per le tabelle `menu_home_voci`
--
ALTER TABLE `menu_home_voci`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_menu_home_progetto` (`IDprogetto`),
  ADD KEY `idx_menu_home_padre` (`IDpadre`),
  ADD KEY `idx_menu_home_ordine` (`IDprogetto`,`IDpadre`,`ordine`);

--
-- Indici per le tabelle `pagine_visualizzazione`
--
ALTER TABLE `pagine_visualizzazione`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_pagine_visualizzazione_file` (`IDprogetto`,`nome_file`),
  ADD KEY `idx_pv_progetto` (`IDprogetto`),
  ADD KEY `idx_pv_tabella_principale` (`IDtabella_principale`),
  ADD KEY `fk_pv_idtipo` (`IDtipo`);

--
-- Indici per le tabelle `pagine_visualizzazione_campi`
--
ALTER TABLE `pagine_visualizzazione_campi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_pvc_pagina_campo` (`IDpagina`,`IDpagina_tabella`,`IDcampo`),
  ADD UNIQUE KEY `uk_pvc_pagina_ordine` (`IDpagina`,`ordine`),
  ADD KEY `idx_pvc_pagina` (`IDpagina`),
  ADD KEY `idx_pvc_pagina_tabella` (`IDpagina_tabella`),
  ADD KEY `idx_pvc_campo` (`IDcampo`),
  ADD KEY `idx_pvc_link_pagina` (`link_pagina_id`);

--
-- Indici per le tabelle `pagine_visualizzazione_modali`
--
ALTER TABLE `pagine_visualizzazione_modali`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_pvm_pagina` (`IDpagina`),
  ADD KEY `idx_pvm_tabella` (`IDtabella_collegata`),
  ADD KEY `idx_pvm_fk` (`IDforeign_key`),
  ADD KEY `idx_pvm_campo_principale` (`IDcampo_principale`),
  ADD KEY `idx_pvm_campo_collegato` (`IDcampo_collegato`);

--
-- Indici per le tabelle `pagine_visualizzazione_tabelle`
--
ALTER TABLE `pagine_visualizzazione_tabelle`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_pvt_pagina_tabella_fk` (`IDpagina`,`IDtabella`,`IDforeign_key`),
  ADD KEY `idx_pvt_pagina` (`IDpagina`),
  ADD KEY `idx_pvt_tabella` (`IDtabella`),
  ADD KEY `idx_pvt_fk` (`IDforeign_key`);

--
-- Indici per le tabelle `pagine_visualizzazione_tipo`
--
ALTER TABLE `pagine_visualizzazione_tipo`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_pv_tipo_codice` (`codice`),
  ADD KEY `idx_pv_tipo_attivo_ordine` (`attivo`,`ordine`);

--
-- Indici per le tabelle `progetti`
--
ALTER TABLE `progetti`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Indici per le tabelle `progetti_db_destinatario`
--
ALTER TABLE `progetti_db_destinatario`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_db_destinatario_progetto` (`IDprogetto`);

--
-- Indici per le tabelle `progetti_deploy_ftp`
--
ALTER TABLE `progetti_deploy_ftp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_deploy_progetto` (`IDprogetto`);

--
-- Indici per le tabelle `progetti_deploy_https`
--
ALTER TABLE `progetti_deploy_https`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_deploy_https_progetto` (`IDprogetto`);

--
-- Indici per le tabelle `progetti_setup_db`
--
ALTER TABLE `progetti_setup_db`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_progetti_setup_db` (`IDprogetto`);

--
-- Indici per le tabelle `tabelle`
--
ALTER TABLE `tabelle`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tabelle_progetto_nome` (`IDprogetto`,`nome`),
  ADD KEY `idx_tabelle_progetto` (`IDprogetto`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `campi`
--
ALTER TABLE `campi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `foreign_keys`
--
ALTER TABLE `foreign_keys`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `foreign_keys_campi`
--
ALTER TABLE `foreign_keys_campi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `indici`
--
ALTER TABLE `indici`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `indici_campi`
--
ALTER TABLE `indici_campi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `menu_home_config`
--
ALTER TABLE `menu_home_config`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `menu_home_voci`
--
ALTER TABLE `menu_home_voci`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pagine_visualizzazione`
--
ALTER TABLE `pagine_visualizzazione`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pagine_visualizzazione_campi`
--
ALTER TABLE `pagine_visualizzazione_campi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pagine_visualizzazione_modali`
--
ALTER TABLE `pagine_visualizzazione_modali`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pagine_visualizzazione_tabelle`
--
ALTER TABLE `pagine_visualizzazione_tabelle`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pagine_visualizzazione_tipo`
--
ALTER TABLE `pagine_visualizzazione_tipo`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `progetti`
--
ALTER TABLE `progetti`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `progetti_db_destinatario`
--
ALTER TABLE `progetti_db_destinatario`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `progetti_deploy_ftp`
--
ALTER TABLE `progetti_deploy_ftp`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `progetti_deploy_https`
--
ALTER TABLE `progetti_deploy_https`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `progetti_setup_db`
--
ALTER TABLE `progetti_setup_db`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `tabelle`
--
ALTER TABLE `tabelle`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `campi`
--
ALTER TABLE `campi`
  ADD CONSTRAINT `fk_campi_tabelle` FOREIGN KEY (`IDtabella`) REFERENCES `tabelle` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Limiti per la tabella `foreign_keys`
--
ALTER TABLE `foreign_keys`
  ADD CONSTRAINT `fk_foreign_keys_tabella` FOREIGN KEY (`IDtabella`) REFERENCES `tabelle` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `foreign_keys_campi`
--
ALTER TABLE `foreign_keys_campi`
  ADD CONSTRAINT `fk_fkcampi_fk` FOREIGN KEY (`IDforeign_key`) REFERENCES `foreign_keys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fkcampi_locale` FOREIGN KEY (`IDcampo_locale`) REFERENCES `campi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fkcampi_ref` FOREIGN KEY (`IDcampo_referenziato`) REFERENCES `campi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `indici`
--
ALTER TABLE `indici`
  ADD CONSTRAINT `fk_indici_tabella` FOREIGN KEY (`IDtabella`) REFERENCES `tabelle` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `indici_campi`
--
ALTER TABLE `indici_campi`
  ADD CONSTRAINT `fk_ic_campo` FOREIGN KEY (`IDcampo`) REFERENCES `campi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ic_indice` FOREIGN KEY (`IDindice`) REFERENCES `indici` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `menu_home_config`
--
ALTER TABLE `menu_home_config`
  ADD CONSTRAINT `fk_menu_home_config_progetto` FOREIGN KEY (`IDprogetto`) REFERENCES `progetti` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `menu_home_voci`
--
ALTER TABLE `menu_home_voci`
  ADD CONSTRAINT `fk_menu_home_voci_padre` FOREIGN KEY (`IDpadre`) REFERENCES `menu_home_voci` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_menu_home_voci_progetto` FOREIGN KEY (`IDprogetto`) REFERENCES `progetti` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `pagine_visualizzazione`
--
ALTER TABLE `pagine_visualizzazione`
  ADD CONSTRAINT `fk_pv_idtipo` FOREIGN KEY (`IDtipo`) REFERENCES `pagine_visualizzazione_tipo` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pv_progetto` FOREIGN KEY (`IDprogetto`) REFERENCES `progetti` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pv_tabella_principale` FOREIGN KEY (`IDtabella_principale`) REFERENCES `tabelle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Limiti per la tabella `pagine_visualizzazione_campi`
--
ALTER TABLE `pagine_visualizzazione_campi`
  ADD CONSTRAINT `fk_pvc_campo` FOREIGN KEY (`IDcampo`) REFERENCES `campi` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvc_pagina` FOREIGN KEY (`IDpagina`) REFERENCES `pagine_visualizzazione` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvc_pagina_tabella` FOREIGN KEY (`IDpagina_tabella`) REFERENCES `pagine_visualizzazione_tabelle` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `pagine_visualizzazione_modali`
--
ALTER TABLE `pagine_visualizzazione_modali`
  ADD CONSTRAINT `fk_pvm_campo_collegato` FOREIGN KEY (`IDcampo_collegato`) REFERENCES `campi` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvm_campo_principale` FOREIGN KEY (`IDcampo_principale`) REFERENCES `campi` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvm_foreign_key` FOREIGN KEY (`IDforeign_key`) REFERENCES `foreign_keys` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvm_pagina` FOREIGN KEY (`IDpagina`) REFERENCES `pagine_visualizzazione` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvm_tabella_collegata` FOREIGN KEY (`IDtabella_collegata`) REFERENCES `tabelle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Limiti per la tabella `pagine_visualizzazione_tabelle`
--
ALTER TABLE `pagine_visualizzazione_tabelle`
  ADD CONSTRAINT `fk_pvt_foreign_key` FOREIGN KEY (`IDforeign_key`) REFERENCES `foreign_keys` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvt_pagina` FOREIGN KEY (`IDpagina`) REFERENCES `pagine_visualizzazione` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pvt_tabella` FOREIGN KEY (`IDtabella`) REFERENCES `tabelle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Limiti per la tabella `tabelle`
--
ALTER TABLE `tabelle`
  ADD CONSTRAINT `fk_tabelle_progetti` FOREIGN KEY (`IDprogetto`) REFERENCES `progetti` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
