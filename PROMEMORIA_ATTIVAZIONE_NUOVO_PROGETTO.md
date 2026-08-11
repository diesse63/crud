# Promemoria attivazione nuovo progetto

## Obiettivo

Usare questa checklist quando si attiva un nuovo progetto CRUD, così da non dimenticare i passaggi essenziali di configurazione e i file necessari.

## Verifiche iniziali

- Confermare il nome del progetto.
- Verificare che il database sia disponibile.
- Verificare che il sito destinatario sia raggiungibile.
- Controllare che la struttura delle tabelle sia completa.
- Verificare che eventuali foreign key abbiano indici dedicati.

## Configurazione progetto

- Impostare il progetto attivo.
- Verificare `db.php` ufficiale del sito destinatario.
- Controllare `deploy_receiver.php` e `deploy_receiver_config.php` se il deploy diretto è attivo.
- Verificare il percorso autorizzato di pubblicazione.
- Controllare eventuali chiavi, token o parametri di sicurezza.
- Verificare che le opzioni di generazione siano coerenti con lo schema del database.

## File da controllare

- `index.php`
- `db.php`
- `deploy_receiver.php`
- `deploy_receiver_config.php`
- file delle pagine generate
- file CSS e JavaScript realmente usati
- eventuali configurazioni del progetto attivo

## File da non pubblicare

- backup locali
- log
- file temporanei
- archivi ZIP
- file di test non richiesti
- configurazioni di sviluppo non necessarie al sito destinatario

## Controlli finali

- Verificare che il progetto si apra correttamente.
- Controllare inserimento, modifica, cancellazione e visualizzazione.
- Verificare filtri, ordinamento e paginazione.
- Controllare il ritorno alla pannellata.
- Verificare la pubblicazione solo dei file necessari.
- Controllare eventuali errori PHP o JavaScript.

## Nota operativa

Se il progetto è nuovo, conviene sempre:

1. partire dal database reale;
2. controllare le tabelle principali e le relazioni;
3. verificare i file generati prima del deploy;
4. pubblicare solo dopo un controllo del diff.
