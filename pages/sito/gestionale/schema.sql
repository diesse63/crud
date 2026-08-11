-- SQL Script generato per progetto: gestionale
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `citta` (
  `id_citta` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(30) NOT NULL,
  UNIQUE KEY `uniq_nome` (`nome`),
  `ultima_modifica` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_citta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `anagrafica` (
  `id_anagrafica` INT NOT NULL AUTO_INCREMENT,
  `ultima_modifica` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nome` VARCHAR(30) NOT NULL,
  `cognome` VARCHAR(30) NOT NULL,
  `id_citta` INT NOT NULL,
  KEY `idx_id_citta` (`id_citta`),
  `test` INT NULL,
  PRIMARY KEY (`id_anagrafica`),
  KEY `idx_fk_fk_anagrafica_citta` (`id_citta`),
  CONSTRAINT `fk_anagrafica_citta` FOREIGN KEY (`id_citta`) REFERENCES `citta` (`id_citta`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ordini` (
  `id_ordini` INT NOT NULL AUTO_INCREMENT,
  `data_ordine` DATE NOT NULL,
  `numero_ordine` INT NOT NULL,
  `id_anagrafica` INT NOT NULL,
  KEY `idx_id_anagrafica` (`id_anagrafica`),
  `ultima_modifica` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_ordini`),
  KEY `idx_fk_fk_ordini_anagrafica` (`id_anagrafica`),
  CONSTRAINT `fk_ordini_anagrafica` FOREIGN KEY (`id_anagrafica`) REFERENCES `anagrafica` (`id_anagrafica`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `prodotti` (
  `id_prodotti` INT NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(30) NOT NULL,
  UNIQUE KEY `uniq_nome` (`nome`),
  `prezzo` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id_prodotti`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `riga_ordine` (
  `id_riga_ordine` INT NOT NULL AUTO_INCREMENT,
  `id_prodotti` INT NOT NULL,
  KEY `idx_id_prodotti` (`id_prodotti`),
  `quantita` INT NOT NULL,
  `id_ordini` INT NOT NULL,
  KEY `idx_id_ordini` (`id_ordini`),
  PRIMARY KEY (`id_riga_ordine`),
  KEY `idx_fk_fk_riga_ordine_prodotti` (`id_prodotti`),
  CONSTRAINT `fk_riga_ordine_prodotti` FOREIGN KEY (`id_prodotti`) REFERENCES `prodotti` (`id_prodotti`) ON DELETE RESTRICT ON UPDATE CASCADE,
  KEY `idx_fk_fk_riga_ordine_ordini` (`id_ordini`),
  CONSTRAINT `fk_riga_ordine_ordini` FOREIGN KEY (`id_ordini`) REFERENCES `ordini` (`id_ordini`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;