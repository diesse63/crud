# AGENTS.md

# CRUD Generator

Versione progetto: 10.2
Ultimo aggiornamento: 24/07/2026

## Scopo

Questo progetto genera automaticamente applicazioni CRUD PHP/MySQL partendo dai metadati del database.

L'obiettivo è produrre codice:

* semplice
* leggibile
* modificabile
* compatibile con PHP 8.x
* compatibile con MySQL 8.x
* senza dipendenze esterne

---

# Regole generali

L'agente NON deve mai:

* eliminare funzionalità esistenti
* modificare il layout senza richiesta
* cambiare il comportamento dell'applicazione
* rinominare file senza autorizzazione
* eliminare commenti utili

Ogni modifica deve essere compatibile con il codice già presente.

---

# Struttura progetto

/pages
/sito
/assets
/css
/js

db.php
index.php

I file generati devono mantenere la struttura esistente.

---

# Standard PHP

Usare sempre:

require_once

prepared statement PDO

try/catch

htmlspecialchars()

password_hash()

password_verify()

Mai usare query SQL concatenate.

---

# Standard SQL

Generare SQL compatibile con MySQL 8.

Usare:

PRIMARY KEY

FOREIGN KEY

INDEX

UNIQUE

ON UPDATE CURRENT_TIMESTAMP

Le FK devono sempre avere anche un indice.

---

# Standard HTML

Bootstrap 5.3

Bootstrap Icons

Layout responsive.

---

# Standard Javascript

Vanilla Javascript.

Non usare jQuery.

---

# Header di ogni file generato

Ogni file creato deve iniziare con:

Nome generatore

Versione generatore

Data e ora

Nome progetto

Esempio:

Generato da:
CRUD Generator v10.2

Data:
2026-07-24 18:00

Progetto:
gestionale

---

# Deploy

Il deploy utilizza:

deploy_receiver.php

deploy_receiver_config.php

Non modificare il funzionamento senza autorizzazione.

---

# Database

Il file db.php del sito destinatario è sempre quello ufficiale.

Non creare copie.

Non modificare i parametri DB.

---

# Backup

I backup rimangono sul PC.

Non devono essere caricati sul sito.

Durante il deploy devono essere inviati solo i file necessari al funzionamento.

---

# Compatibilità

Compatibilità minima:

PHP 8.0

MySQL 8.0

Bootstrap 5.3

---

# Regole per le modifiche

Quando viene richiesta una modifica:

1. analizzare il codice esistente

2. mantenere la compatibilità

3. modificare il minimo indispensabile

4. non duplicare funzioni

5. riutilizzare il codice esistente

6. evitare regressioni

7. uniformare sempre la procedura di acquisizione e gestione dati in scheda_singola, scheda_tabellare e scheda_master_detail.

## Vincolo di ambito

Quando il richiedente chiede di intervenire solo sulla generazione del codice, la modifica deve essere limitata esclusivamente alla costruzione del codice prodotto.

Restano esclusi, salvo autorizzazione esplicita e separata:

* salvataggio su disco;
* persistenza nel database;
* aggiornamenti della cartella progetto;
* deploy;
* interfaccia utente;
* navigazione;
* logiche collaterali non richieste.

Prima di eseguire qualsiasi intervento, rileggere sempre `AGENTS.md` e attenersi a questo vincolo di ambito.

---

# Priorità

1. Correttezza

2. Compatibilità

3. Manutenibilità

4. Prestazioni

5. Pulizia del codice

---

# Output atteso

Il codice prodotto deve essere immediatamente utilizzabile senza ulteriori modifiche.

Prima di proporre nuove funzioni verificare sempre se esiste già una funzione equivalente nel progetto.

## Contesto obbligatorio

Prima di analizzare o modificare il progetto, leggere:

1. `PROJECT_CONTEXT.md`
2. `CHANGELOG.md`, quando serve conoscere le modifiche precedenti

Usare:

- `AGENTS.md` per le regole operative;
- `PROJECT_CONTEXT.md` per lo stato attuale;

Non modificare questi documenti automaticamente, salvo richiesta esplicita.
