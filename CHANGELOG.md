# Changelog

Tutte le modifiche rilevanti del progetto CRUD Generator saranno documentate
in questo file.

Il formato segue i principi di Keep a Changelog.

## [Non rilasciato]

### Aggiunto

- Rinominati i generatori file da `genera_scheda_*` a `scheda_*`, mantenendo invariata la logica di gestione e i collegamenti dal menu e dalla tabella pannellate.

### Modificato

- Isolata in `creatore_pagina.php` la scrittura materiale della pagina generata in una funzione dedicata, mantenendo invariata la costruzione basata sulle opzioni lette e il salvataggio nella cartella progetto.
- In `creatore_pagina.php` la pannellata mostra ora sia la versione del creatore di pagina utilizzato sia la versione pagina nel formato `1.1.1`.
- Rifiniti i layout mobile di `schema_db` e `cartella_progetto` per ridurre l'ingombro su schermi piccoli e migliorare la leggibilita delle sezioni laterali e delle liste file.
- Rifinito il layout mobile dei generatori `genera_pagina_visualizzazione` e `scheda_singola`, limitando l'overflow orizzontale dell'area campi selezionati e rendendo più compatta la spaziatura su schermi piccoli.
- Suddivisa la cartella progetto in due sottovoci: `Allineamento file` e `Allineamento DB`, mantenendo le funzioni attuali e separando la visualizzazione della pannellata.
- Suddivisi in due righe i pulsanti della modale "Pubblica progetto via HTTPS": i comandi DB sono ora separati dagli altri comandi di pubblicazione.
- Rimosso il pulsante "Simula" dalla modale di pubblicazione HTTPS e aggiunto il comando "Disassocia" per scollegare il progetto dalla cartella remota.
- Aggiunto `deploy_receiver.php` v1.2.4 con supporto all'azione remota `disassociate`, che rimuove solo il manifest `.deploy.json` dopo verifica UUID.
- Esteso l'allineamento del DB destinatario alla gestione delle tabelle extra con opzione dedicata di eliminazione e conferma dettagliata prima della pubblicazione.

### Corretto

- In `cartella_progetto.php` la rinomina di un file aggiorna ora anche i riferimenti nel menu Home gestito da `genera_home.php`, quando il file è presente come voce di pagina.
- Corretto nel HEREDOC di `buildGeneratedPageCode` l'inserimento di `{$dropdownCode}`, così il file generato espande davvero il blocco SQL dei dropdown FK invece di scrivere testo letterale.
- Nel file generato la sezione `$crudDropdowns = [];` riceve ora un blocco costruito dal generatore che popola i dropdown FK partendo da `source_fk_id` e dai metadati `fk`.
- Centralizzata nel generatore la costruzione dei dropdown FK con `crudBuildDropdownOptions`, così i modali della scheda singola leggono sempre `option_value` e `option_label` dalla tabella referenziata.
- Unificato il rendering dei campi collegati nei modali della scheda singola al componente dropdown già usato dal campo principale, mantenendo la descrizione letta dalla SQL di base.
- Rimosso dal template di `buildGeneratedPageCode` il duplicato di `renderInsertModalField`, evitando la ridefinizione di funzioni nel file generato e il conseguente fatal error.
- Aggiornata la generazione della scheda singola per usare `visible_table` / `visible_card` anche nel salvataggio POST e per popolare i dropdown FK dai campi con `source_fk_id`, con redirect relativo che preserva `?page=...`.
- Nel generatore `creatore_pagina.php` i modali di inserimento e modifica della scheda singola ora usano la visibilità reale del campo (`visible_table` / `visible_card`) invece del metadato `editable`, evitando modali vuoti quando `editable` non è presente nel payload.
- Allineato il template generato della scheda singola a una sorgente unica basata su `$fields`, con modali insert/update che usano `fields[...]` e non più la struttura CRUD separata.
- Reso indipendente dal criterio `insert_visible` e `update_visible` il rendering dei modali CRUD generati in `creatore_pagina.php`, che ora usa solo `editable`, tipo campo, obbligatorietà e foreign key.
- Forzata nel creatore `creatore_pagina.php` la generazione dei flag `insert_visible` e `update_visible` a partire dallo stato editabile del campo, così i modali CRUD del file destinatario non risultano vuoti quando la visibilità modale era disattivata in configurazione.
- Spostata la definizione di `renderPagePreviewModal()` nel supporto UI di `creatore_pagina.php` e rimosso il fallback inline, eliminando il fatal error in caricamento modifica.
- Aggiunto un timeout di sicurezza al caricamento modifica di `creatore_pagina.php`, così il `Report caricamento` mostra anche i blocchi di rete o di risposta appesa.
- Esteso il `Report caricamento` con dettagli forensi di tabelle, relazioni e campi, includendo i primi elementi e i riepiloghi tecnici delle strutture lette.
- Potenziato al massimo il `Report caricamento` di `creatore_pagina.php`: ora registra chiavi risposta, riepiloghi dei dati letti, conteggi completi e stato finale del bootstrap.
- Aggiunti al `Report caricamento` i pulsanti `Reset` e `Copia`, mantenendo il log in ordine discendente e persistente in `creatore_pagina.php`.
- Reso persistente in `creatore_pagina.php` il report di caricamento modifica tramite `sessionStorage`, così l'ultimo debug resta visibile anche dopo refresh della pagina.
- Allineata la sidebar di `index.php` per mantenere aperta anche la voce `Cartella` quando si è in modifica pagina tramite `creatore_pagina`.
- Corretto in `genera_home.php` il controllo delle pagine mancanti nel salvataggio, ripristinando una funzione ricorsiva valida che non interrompe il caricamento della configurazione.
- Normalizzati in `genera_home.php` i nomi dei file pagina caricati e salvati, così i record con percorso non canonico non bloccano il caricamento della configurazione e del menu.
- Stabilizzato in `genera_home.php` il refresh della lista delle pagine disponibili, distruggendo la precedente istanza Sortable prima di ricrearla così le voci non scompaiono in modo intermittente.
- Ripristinato in `tabella_pannellate.php` il caricamento diretto delle pagine salvate dalla lista, usando i dati completi della configurazione e aprendo la modifica dal record selezionato.
- Forzata la selezione della relazione corrispondente quando si aggiunge un campo di una tabella collegata nella sezione dei campi da visualizzare, così la JOIN resta inclusa nel file generato e i campi collegati continuano a comparire nel sito destinatario.
- Aggiunta una convenzione di naming per le foreign key nel contesto progetto, basata su `id_<ruolo>_<entita>` per supportare in modo coerente piu collegamenti alla stessa tabella.
- Unificata la gestione del dropdown `tipoId` in `creatore_pagina.php`: ora un solo punto di verità aggiorna intestazione, righe per pagina e visibilità di `Dettaglio modale`.
- Corretto il blocco della sezione `Dettaglio modale`: la visibilità ora segue esclusivamente `show_modal`, evitando che il campo resti visibile quando il valore è `0`.
- Reso visibile o nascosto il campo `Dettaglio modale` in base a `show_modal`, senza forzare il valore del checkbox.
- Allineato il valore iniziale del checkbox `Dettaglio modale` in `creatore_pagina.php` al campo `show_modal` della tipologia selezionata, e ripreso lo stesso valore anche in `applyLoadedConfiguration`.
- Allineata la query di apertura di `creatore_pagina.php` alla nuova struttura di `pagine_visualizzazione_tipo`, includendo anche `show_modal`; `modifica_pagina.php` eredita la stessa query tramite `require_once`.
- Allineata la selezione della tipologia in `creatore_pagina.php` con il campo `Righe per pagina`, che ora eredita `righe_per_pagina` dalla tipologia selezionata anche al caricamento iniziale.
- Separato il flusso di creazione da quello di modifica delle pagine: `tabella_pannellate` apre ora `modifica_pagina.php` per la visualizzazione e l'update, mentre `creatore_pagina.php` resta dedicato alla nuova configurazione.
- Corretto il template di `creatore_pagina.php` per evitare che alcune variabili non escape-ate nel `heredoc` generassero pagine PHP corrotte con sintassi invalida.
- Agganciato `modal_config` al salvataggio di `creatore_pagina.php` usando lo stato reale della pannellata modale.
- Ripristinato il pulsante `Salva e genera pagina PHP` in `creatore_pagina.php`, con invio del payload al ramo `save_generate` e generazione del file PHP.
- Nel modale collegato di `genera_pagina_visualizzazione_modali.php` inserimento e modifica condividono ora la stessa finestra con tab separate, eliminando la modale sopra modale.
- In `creatore_pagina.php` i campi FK generano ora una pannellata inline che consente inserimento e modifica del record collegato senza uscire dalla scheda.
- Disattivata l'opzione di creazione del modale in `creatore_pagina.php` quando la tipologia selezionata è `scheda singola`.
- Quando una pannellata viene cancellata da `tabella_pannellate` o da `cartella_progetto`, viene ora eliminata anche la voce collegata in `menu_home_voci`, così il collegamento sparisce da `genera_home`.
- Allineato il dropdown tipologia di `creatore_pannellate.php` alla tabella `pagine_visualizzazione_tipo`: la voce visibile ora usa `codice`, il titolo mostrato usa `descrizione` e il salvataggio scrive `IDtipo`.
- Resi progressivi i blocchi di `creatore_pannellate.php`: ogni sezione compare solo dopo la compilazione dei valori obbligatori del blocco precedente.
- Resa progressiva la visualizzazione di `creatore_pannellate.php`: all'avvio resta visibile solo il dropdown `scegli voce` con le descrizioni lette da `pagine_visualizzazione_tipo`, e il resto della pannellata compare dopo la selezione.
- Allineata la pagina `tabella_pannellate` allo schema attuale usando il tipo pagina da `pagine_visualizzazione_tipo`, evitando il riferimento alla colonna non presente `tipo_visualizzazione`.
- Nel creatore pannellate il dropdown dei tipi pagina legge ora `pagine_visualizzazione_tipo.descrizione` tramite `IDTipo`, e il salvataggio usa `IDTipo` invece della colonna rimossa `tipo_visualizzazione`.
- Aggiunto nella nuova pannellata tabellare il pulsante `Elimina`, con cancellazione completa della pagina e del relativo file generato.
- Aggiunto nella nuova pannellata tabellare anche il pulsante `Apri pagina`, che apre in una nuova scheda la pagina generata del sito destinatario.
- Aggiunto nella nuova pannellata tabellare il pulsante `Visualizza` per ogni record, con apertura diretta della pannellata di modifica corretta in base al tipo scheda.
- Aggiunta la nuova pannellata `genera_pannellata_tabellare.php` con colonne nome scheda, nome file, titolo visualizzato e tipo scheda, collegata al menu del sito.
- Ripristinata in `scheda_tabellare.php` la scelta delle righe per pagina con default a 25 e resa la vista una tabella semplice a righe e colonne con dettaglio collapse sulla riga selezionata.
- Corretto il flusso della scheda tabellare generata: la vista principale torna tabellare con paginazione normale e il dettaglio collegato viene aperto internamente alla tabella tramite collapse.
- Trasformato il dettaglio della scheda tabellare in una riga espansa interna alla tabella, senza modale separata e senza freccia, mantenendo i dati della tabella primaria in forma tabellare.
- Allineata la vista tabellare di `scheda_tabellare.php`: la pagina principale usa solo i campi della tabella primaria e la SELECT principale diventa `DISTINCT` quando sono presenti tabelle collegate.
- Normalizzati in `scheda_tabellare.php` i flag di visibilità letti dalle configurazioni esistenti, così i campi con `visible_table = false` non vengono più trattati come visibili quando la pagina viene rigenerata.
- Ripristinata la logica della sidebar in `index.php` con stato esplicito e comportamento lineare, eliminando l'inversione che causava apertura e chiusura immediate su PC e mobile.
- Semplificata la logica JavaScript della sidebar in `index.php`, mantenendo il comportamento richiesto ma riducendo i rami e le funzioni intermedie.
- Reso il menu hamburger di `index.php` conforme al comportamento richiesto: apertura e chiusura solo dal bottone, chiusura alla selezione di una voce, senza chiusure automatiche da overlay o altri trigger.
- Rimosso il riallineamento della sidebar mobile su `resize` in `index.php`, mantenendo solo il reset iniziale e quello su `orientationchange` per evitare la chiusura immediata su browser mobili.
- Rafforzata la sidebar mobile di `index.php` con uscita reale dal viewport su schermi piccoli, per evitare che un'area invisibile continui a intercettare i tocchi e blocchi l'app.
- Sistemato il menu hamburger su mobile in `index.php`: l'overlay non blocca piu il bottone di apertura/chiusura e lo stato della sidebar viene riallineato automaticamente su resize/orientamento.
- Aggiornata la preview di `scheda_tabellare` per mostrare in modo dinamico le righe per pagina selezionate.
- Aggiunta nel generatore `scheda_tabellare` la selezione delle righe per pagina, con default impostato a 25.
- Invertito il nuovo generatore `scheda_tabellare`: vista principale tabellare e modale facoltativo in forma scheda singola.
- Aggiunto il nuovo generatore `scheda_tabellare`, duplicato da `scheda_singola` e agganciato al menu del sito.
- Forzato il posizionamento a destra del FAB di `genera_home` con stile esplicito sul bottone, per evitare che venga renderizzato a sinistra.
- Uniformato il markup del FAB di `genera_home` al FAB dell'applicazione, rimuovendo varianti non necessarie e mantenendo l'ancoraggio a destra.
- Uniformato il FAB `Genera index.php` di `genera_home` al comportamento del FAB di `scheda_singola`, con ancoraggio a destra e scroll coerente.
- Riportato a destra e reso sticky il pulsante `Genera index.php` in `genera_home`, così segue lo scroll della pagina invece di restare fisso sul viewport.
- Reso davvero flottante il pulsante `Genera index.php` in `genera_home`, con posizionamento fisso più coerente su desktop e mobile.
- In `cartella_progetto.php` il viewer dei file mostra ora un pulsante per copiare il codice negli appunti.
- Aggiornata la scheda master detail: il pulsante collegati ora mostra "Visualizza collegati" quando esistono record e "Inserisci collegati" quando la relazione è vuota ma la modale consente l'inserimento.
- Separato il flusso `Allinea e pubblica` dalla gestione DB: la pubblicazione HTTPS ora riguarda solo i file del progetto e non avvia più la sincronizzazione del database.
- Evitato l'errore di `Duplicate column name` durante l'allineamento DB remoto: i campi aggiunti vengono ora registrati subito nello stato di migrazione, così lo stesso `ADD COLUMN` non viene rieseguito nello stesso passaggio.
- Allineato il confronto per corrispondenza di contenuto e non per posizione, così il destinatario può presentare gli stessi componenti in ordine diverso mantenendo il match corretto.
- Riorganizzato il confronto in blocchi per tabella con righe allineate per componente, mostrando per ogni riga CRUD e destinatario con spunta o motivo della differenza.
- Resa riga-per-riga la vista della struttura DB, raggruppando i componenti per tabella e distinguendo campi, PK, UQ, IDX, FK e opzioni.
- Uniformata la vista dello schema di confronto: ora `schema.sql` e la struttura del destinatario sono esposti nella stessa forma tabellare con campi, PK, UQ, indici, FK e opzioni.
- Migliorato lo script SQL di allineamento mostrato nel pannello: ora include note più complete su tabelle mancanti, extra e definizioni diverse.
- Sostituita la visualizzazione degli script SQL di confronto con tabelle più leggibili e aggiunto un contenitore dedicato allo script SQL di allineamento.
- Reso canonico il confronto delle `CREATE TABLE` per evitare falsi `0` nelle tabelle allineate quando cambiano solo ordine o formattazione dei componenti.
- Rafforzato il confronto struttura DB con dettaglio per componenti reali della `CREATE TABLE`: colonne, `PRIMARY KEY`, `UQ`, indici, `FOREIGN KEY`, `CHECK` e opzioni tabella.
- Evidenziati anche i vincoli `UQ` nel riepilogo delle differenze di struttura, così i vincoli univoci risultano immediatamente riconoscibili nel confronto.
- Resi più leggibili i dettagli del confronto struttura DB: le tabelle con differenze di definizione mostrano ora un riepilogo sintetico dei frammenti CREATE TABLE non coincidenti.
- Chiarita nella pagina di confronto la distinzione tra tabelle allineate e differenze solo di definizione CREATE TABLE, con messaggio esplicativo quando il destinatario contiene tutte le tabelle ma le definizioni non coincidono.
- Aggiunto un fallback visibile nel report di allineamento DB quando il receiver non restituisce una lista dettagliata di operazioni, così il messaggio non resta vuoto.
- Riformulato il flusso di pubblicazione HTTPS: la conferma mostra le operazioni da eseguire prima del deploy, mentre il messaggio finale riporta dopo verifica l'esito di ogni operazione con spunte e croci.
- Aggiornato il messaggio di conferma della pubblicazione HTTPS con checklist delle operazioni previste e report finale con segni di spunta per le attività completate e verificate.
- Reso più robusto l'URL usato per l'auto-sync remoto del DB: se `Application URL` punta alla root del dominio, ora viene aggiunta la cartella del progetto prima di chiamare `db.php`.
- Reso robusto il report di allineamento DB nella cartella progetto: ora viene mostrato anche quando il receiver restituisce una variante del payload o un esito senza righe dettagliate.
- Corretta la generazione SQL della scheda singola: al salvataggio non vengono piu rimosse le JOIN verso tabelle collegate quando i loro campi sono selezionati nella scheda principale.
- Corretta l'apertura delle modali di modifica nella scheda singola generata: i form di modifica principale e collegata ora si aprono in una modale Bootstrap separata e si chiudono dopo conferma o annullamento.
- Allineata la pagina generata `pages/sito/gestionale/pages/anagrafe.php` alla gestione aggiornata della modale di modifica.
- Reso sempre presente nel sorgente generato lo script `openEditModal` quando la modifica CRUD è abilitata, anche prima dell'apertura di un record in modifica.
- Limitati a 2 i backup locali di `db.php` nella cartella progetto, eliminando automaticamente le versioni piu vecchie.

### Rimosso

### Sicurezza

### Corretto
- Resa piu robusta la generazione della modifica CRUD nella scheda singola: il record in modifica viene ora recuperato anche tramite `modal_edit` e il redirect pulisce correttamente il parametro, evitando stati bloccati dopo il salvataggio.

## [10.2.0] - 2026-07-25

### Aggiunto

- Inserita la gestione separata delle pagine a scheda singola.
- Aggiunto il controllo di visibilità dei campi.
- Aggiunto il collegamento opzionale alla modale tabellare.

### Modificato

- Migliorato il layout per dispositivi mobili.
- Modificato il comportamento dei pulsanti Dettaglio e Naviga.

### Corretto

- Corretta la gestione del ritorno alla pannellata generatrice.
- Evitata la ripetizione del messaggio di conferma durante la navigazione.
