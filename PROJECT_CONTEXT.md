# PROJECT_CONTEXT.md

## Nome progetto

CRUD Generator – PHP / MySQL

## Versione corrente

Versione applicazione: 10.2

Ultimo aggiornamento del contesto: 24 luglio 2026

## Scopo del progetto

Il progetto genera pagine CRUD PHP collegate a database MySQL.

Le pagine generate devono consentire, in base alla configurazione:

* visualizzazione dei dati;
* inserimento;
* modifica;
* cancellazione;
* filtri;
* ordinamento;
* paginazione;
* visualizzazione tabellare;
* visualizzazione a scheda singola;
* modali di dettaglio;
* navigazione tra pagine collegate;
* pubblicazione verso un sito destinatario.

Il generatore deve mantenere separate:

1. l'applicazione di generazione;
2. i file prodotti;
3. il sito destinatario;
4. i file di configurazione del deploy;
5. i backup locali.

---

## Regola principale

Prima di modificare qualsiasi file, leggere `AGENTS.md`.

Le istruzioni contenute in `AGENTS.md` sono vincolanti.

Non eliminare funzioni, controlli, layout o comportamenti esistenti senza una richiesta esplicita.

Le modifiche devono essere limitate al problema indicato.

---

## Ambiente tecnico

### Linguaggi

* PHP
* MySQL
* JavaScript
* HTML
* CSS

### Database

* MySQL 8
* InnoDB
* charset `utf8mb4`

### Hosting

* Altervista

### Ambiente di sviluppo

* Windows
* Visual Studio Code
* chat ed estensione AI integrate in VS Code
* WinSCP o SFTP per la pubblicazione

---

## Struttura principale del progetto

```text
CRUD-Generator/
├── AGENTS.md
├── PROJECT_CONTEXT.md
├── README.md
├── CHANGELOG.md
├── TODO.md
├── DEPLOY.md
├── TESTING.md
├── .gitignore
├── index.php
├── db.php
├── cartella_progetto.php
├── deploy_receiver.php
├── deploy_receiver_config.php
├── pages/
├── assets/
├── sito/
├── backups/
└── logs/
```

---

## File principali

### `index.php`

È il contenitore principale dell'applicazione.

Gestisce:

* menu;
* navigazione;
* progetto attivo;
* sessione;
* caricamento delle pagine;
* messaggi;
* pannellata principale.

### `db.php`

Contiene la classe di accesso al database.

Il file del sito destinatario deve essere compatibile con quello utilizzato dal CRUD Generator.

Le credenziali non devono essere inserite direttamente nei file pubblici se può essere usato un file di configurazione separato.

### `cartella_progetto.php`

Gestisce la cartella del progetto generato.

Può occuparsi di:

* creazione cartelle;
* generazione file;
* esportazione;
* preparazione ZIP;
* gestione struttura del sito destinatario.

### `pages/scheda_singola.php`

Genera pagine CRUD con visualizzazione a scheda singola.

Deve mantenere:

* inserimento;
* modifica;
* cancellazione;
* ritorno alla pannellata;
* gestione campi obbligatori;
* layout mobile;
* modale tabellare collegata, quando configurata;
* navigazione, quando configurata.

### `pages/genera_pagina_visualizzazione.php`

Genera pagine di visualizzazione o pagine tabellari.

Deve gestire:

* campi selezionati;
* filtri;
* ordinamento;
* configurazione layout;
* caricamento configurazioni esistenti;
* pagine collegate;
* eventuali modali;
* salvataggio configurazione.

### `deploy_receiver.php`

Riceve i file pubblicati dal generatore sul sito destinatario.

Deve:

* verificare il progetto;
* validare il percorso;
* impedire scritture fuori dalla cartella autorizzata;
* ricevere solo file previsti;
* restituire risposte JSON valide;
* evitare la pubblicazione di backup e file temporanei.

### `deploy_receiver_config.php`

Contiene la configurazione del ricevitore di deploy.

Può includere:

* nome progetto;
* percorso autorizzato;
* chiave o token;
* versione del ricevitore;
* impostazioni di sicurezza;
* elenco file consentiti o esclusi.

---

## Regole per i file generati

Ogni file generato deve contenere nelle note iniziali:

* nome del generatore;
* versione del generatore;
* data e ora di generazione;
* descrizione sintetica del file;
* eventuale versione dello schema o della configurazione.

Esempio:

```php
<?php
/**
 * File generato automaticamente.
 *
 * Generatore: CRUD Generator
 * Versione: 10.2
 * Creato il: 2026-07-24 19:00:00
 *
 * Non modificare manualmente senza aggiornare anche
 * la configurazione del generatore.
 */
```

---

## Regole JOIN

### Nessuna JOIN

La pagina può essere completamente modificabile.

Sono ammessi:

* inserimento;
* modifica;
* cancellazione;
* visualizzazione.

### INNER JOIN

La pagina può essere modificabile solo sulla tabella principale.

Le tabelle collegate devono essere usate principalmente per:

* visualizzare descrizioni;
* compilare select;
* mostrare dati collegati.

Non aggiornare automaticamente più tabelle con una singola operazione, salvo configurazione esplicita.

### LEFT JOIN

La pagina deve essere considerata principalmente di sola visualizzazione.

Sono ammessi:

* elenco;
* filtri;
* ordinamento;
* dettaglio.

Inserimento, modifica e cancellazione devono essere disabilitati, salvo gestione esplicita e sicura della tabella principale.

---

## Regole dei pulsanti

### Pagina tabellare standard

Mostrare normalmente:

* Modifica
* Cancella

Il pulsante `Dettaglio` deve comparire solo se è configurata una modale o una pagina dettaglio.

Il pulsante `Naviga` deve comparire solo se esiste una configurazione di navigazione valida.

### Scheda singola

Può mostrare:

* Modifica
* Cancella
* Dettaglio
* Naviga
* Apri elenco collegato

Ogni pulsante deve comparire solo quando la funzione corrispondente è configurata.

---

## Regole di navigazione

Dopo un inserimento o una modifica eseguiti da una pagina generata tramite pannellata, il sistema deve tornare alla pannellata o alla pagina prevista dalla configurazione.

Evitare:

* messaggi di conferma ripetuti;
* doppio redirect;
* ritorni a pagine non più esistenti;
* parametri GET duplicati;
* perdita del progetto attivo.

---

## Gestione delle chiavi esterne

Le foreign key devono essere rilevate dal database o dalla configurazione del progetto.

Per ogni foreign key:

* creare un indice esplicito sul campo locale;
* evitare indici duplicati;
* evitare `UNIQUE` automatici sui campi FK;
* verificare compatibilità dei tipi;
* verificare tabella e campo referenziati;
* rispettare `ON DELETE`;
* rispettare `ON UPDATE`.

### Convenzione di naming delle FK

La chiave esterna va nominata con questa forma:

`id_<ruolo>_<entita>`

Regole:

* usare solo lettere minuscole e underscore;
* mantenere `id_` come prefisso tecnico;
* descrivere nel nome il ruolo funzionale del campo;
* aggiungere l'entita solo quando serve a evitare ambiguita;
* se la stessa tabella di riferimento viene usata piu volte, ogni FK deve avere un nome distinto e coerente con il suo ruolo.

### Regola di visualizzazione delle tabelle collegate

* Le relazioni devono essere identificate e mostrate tramite il nome della foreign key.
* Se la stessa tabella e collegata piu volte, ogni collegamento deve restare distinto.
* Il nome della tabella referenziata ha solo valore descrittivo e non deve essere usato come identificatore univoco della relazione.
* La gestione della relazione deve basarsi sulla coppia `fk_id` + `table_id`.
* Esempio obbligatorio di visualizzazione: `id_localita_nascita -> localita`, `id_localita_residenza -> localita`.

Esempi corretti:

* `id_localita_nascita`
* `id_localita_residenza`
* `id_localita_indirizzo`
* `id_cliente_fatturazione`
* `id_cliente_spedizione`

Esempi da evitare:

* `id_localita1`
* `id_localita2`
* `id_fk_localita`
* `localita_id`

Le select collegate devono mostrare valori leggibili, per esempio:

```sql
CONCAT(cognome, ' ', nome)
```

invece del solo ID numerico.

---

## Regole SQL

Gli identificatori devono essere protetti con backtick tramite una funzione dedicata.

Non concatenare direttamente dati utente nelle query.

Usare query preparate con parametri.

Evitare:

* nomi colonna non verificati;
* alias non definiti;
* colonne inesistenti;
* duplicazione di `CREATE TABLE`;
* `IF NOT EXISTS` non supportati sugli indici;
* `AUTO_INCREMENT` su campi non indicizzati;
* eliminazione di tabelle prima delle FK dipendenti.

Per la generazione della struttura:

1. disabilitare temporaneamente i controlli FK, se necessario;
2. ordinare le tabelle in base alle dipendenze;
3. creare prima le tabelle principali;
4. creare indici e vincoli senza duplicazioni;
5. riattivare i controlli FK.

---

## Progetto di test principale

Nome progetto: `gestionale`

### Tabella `citta`

Campi principali:

* `id_citta`
* `nome`
* `ultima_modifica`

Vincoli:

* chiave primaria su `id_citta`;
* `nome` univoco.

### Tabella `anagrafica`

Campi principali:

* `id_anagrafica`
* `nome`
* `cognome`
* `id_citta`
* `Telefono`
* `ultima_modifica`

Relazioni:

* `id_citta` collega `anagrafica` a `citta`.

La selezione della persona deve poter mostrare:

```sql
CONCAT(cognome, ' ', nome)
```

### Tabella `prodotti`

Campi principali:

* `id_prodotti`
* `nome`
* `prezzo`

### Tabella `ordini`

Campi principali:

* `id_ordini`
* `data_ordine`
* `numero_ordine`
* `id_anagrafica`
* `ultima_modifica`

Vincoli:

* univocità composta su `data_ordine` e `numero_ordine`.

### Tabella `riga_ordine`

Campi principali:

* `id_riga_ordine`
* `id_prodotti`
* `quantita`
* `id_ordini`

Relazioni:

* `id_prodotti` collega `riga_ordine` a `prodotti`;
* `id_ordini` collega `riga_ordine` a `ordini`.

---

## Pubblicazione sul sito destinatario

La pubblicazione deve basarsi sui file realmente necessari al funzionamento del sito.

Non devono essere caricati:

* backup;
* log locali;
* file temporanei;
* archivi ZIP;
* configurazioni di sviluppo;
* file del generatore non usati dal sito destinatario;
* copie obsolete;
* credenziali locali;
* file di test non richiesti.

Devono essere caricati soltanto:

* `index.php`;
* `db.php`, se richiesto;
* pagine generate;
* asset richiesti;
* file CSS e JavaScript utilizzati;
* configurazioni necessarie;
* receiver e configurazione, se il deploy diretto è attivo.

Prima di eliminare file remoti, verificare che non siano referenziati da:

* `index.php`;
* include PHP;
* menu;
* asset;
* pagine generate;
* service worker;
* manifest;
* configurazioni.

---

## Backup

I backup devono rimanere sul PC.

Il numero dei backup locali può essere superiore a quello mostrato nel pannello.

I backup non devono essere pubblicati sul sito destinatario.

Prima di modifiche importanti:

1. creare una copia del file;
2. annotare versione e data;
3. verificare il diff;
4. eseguire test locali;
5. pubblicare solo dopo il controllo.

---

## Sicurezza

Applicare sempre:

* `realpath()` per verificare i percorsi;
* whitelist delle cartelle consentite;
* validazione dei nomi file;
* esclusione di `..`;
* esclusione dei percorsi assoluti non autorizzati;
* query preparate;
* escaping HTML;
* verifica sessione;
* verifica progetto attivo;
* controllo permessi prima di modifiche o cancellazioni;
* risposte JSON senza output HTML precedente.

Non mostrare in produzione:

```php
ini_set('display_errors', 1);
```

Gli errori devono essere scritti nei log.

---

## Problemi già riscontrati

### PHP

* errori di sintassi con virgolette;
* `unexpected token`;
* `unexpected endif`;
* variabili non definite;
* funzioni duplicate;
* parametri null passati a funzioni SQL;
* errore 500 senza risposta JSON;
* inclusioni con percorsi errati.

### JavaScript

* tentativo di leggere proprietà di select inesistenti;
* JSON non valido perché preceduto da HTML;
* errori `Unexpected token <`;
* eventi collegati più volte;
* elementi cercati prima del caricamento DOM.

### MySQL

* colonne mancanti;
* foreign key non presenti;
* eliminazione di tabelle bloccata da vincoli;
* `AUTO_INCREMENT` non associato a chiave;
* indici duplicati;
* vincoli univoci non desiderati;
* incompatibilità tra tipi FK;
* assenza di accesso a `information_schema`.

### Deploy

* progetto non associato;
* percorso di deploy errato;
* metodo HTTP non consentito;
* file non necessari caricati;
* backup caricati per errore;
* cartelle remote non allineate.

---

## Procedura richiesta alla chat di VS Code

Prima di eseguire una modifica, la chat deve:

1. leggere `AGENTS.md`;
2. leggere `PROJECT_CONTEXT.md`;
3. identificare i file coinvolti;
4. cercare funzioni e riferimenti collegati;
5. descrivere brevemente il problema;
6. modificare solo i file necessari;
7. non eliminare funzionalità esistenti;
8. aggiornare versione, data e changelog del file;
9. eseguire controlli di sintassi;
10. mostrare il riepilogo finale.

---

## Controlli minimi dopo ogni modifica

### PHP

```bash
php -l percorso/file.php
```

### JavaScript

Controllare:

* console del browser;
* errori di sintassi;
* listener duplicati;
* elementi DOM null;
* risposte AJAX.

### CRUD

Verificare:

* caricamento pagina;
* elenco dati;
* inserimento;
* modifica;
* cancellazione;
* filtri;
* paginazione;
* ordinamento;
* ritorno alla pannellata;
* funzionamento mobile.

### Database

Verificare:

* query generate;
* alias;
* chiavi primarie;
* foreign key;
* indici;
* campi obbligatori;
* transazioni, quando necessarie.

### Deploy

Verificare:

* elenco file inviati;
* file esclusi;
* percorso remoto;
* risposta JSON;
* funzionamento del sito dopo la pubblicazione.

---

## Priorità attuali

### Priorità alta

* stabilizzare il generatore di scheda singola;
* correggere errori PHP e JavaScript nelle pagine generate;
* mantenere corretti i pulsanti `Dettaglio` e `Naviga`;
* garantire il ritorno alla pannellata;
* pubblicare solo i file necessari;
* mantenere allineato `db.php` del sito destinatario.

### Priorità media

* completare la gestione delle JOIN;
* migliorare le select basate sulle foreign key;
* migliorare il caricamento delle configurazioni esistenti;
* consolidare il sistema di deploy;
* completare la documentazione utente.

### Da evitare

* riscrittura completa dei file;
* eliminazione di funzioni senza verifica;
* modifica contemporanea di molti file non necessari;
* pubblicazione diretta senza controllo diff;
* cambiamenti di layout non richiesti.

---

## Prossimo obiettivo

Analizzare il file del generatore attualmente interessato dal problema, verificando:

* errori di sintassi;
* funzioni duplicate;
* regressioni;
* dipendenze con altri file;
* comportamento delle pagine generate;
* compatibilità con il sito destinatario.

Prima dell'intervento deve essere prodotto un breve piano delle modifiche.

---

## Prompt consigliato per VS Code

```text
Leggi prima AGENTS.md e PROJECT_CONTEXT.md.

Considera entrambi i file vincolanti.

Analizza il file indicato e tutti i suoi riferimenti nel progetto.
Non eliminare funzioni, controlli o layout esistenti.

Correggi esclusivamente il problema richiesto.

Prima della modifica:
- descrivi la causa;
- indica i file coinvolti;
- indica i rischi di regressione.

Dopo la modifica:
- aggiorna versione, data e note iniziali;
- esegui il controllo di sintassi;
- riepiloga i file modificati;
- indica i test da eseguire.
```
