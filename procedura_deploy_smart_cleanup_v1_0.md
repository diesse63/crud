# Procedura deploy smart e pulizia sito destinatario

## Obiettivo

La pubblicazione HTTPS deve inviare al sito destinatario solo i file realmente necessari al funzionamento dell’applicazione generata dal CRUD.

La nuova procedura:

1. usa `index.php` come punto di partenza;
2. include solo le pagine PHP richiamate dal menu generato;
3. include `db.php`, `schema.sql`, `schema.json`;
4. esclude backup, ZIP, file temporanei, service worker e file tecnici;
5. non crea backup remoti sul sito destinatario;
6. può eliminare dal sito destinatario i file non più presenti nel pacchetto pubblicato.

---

## File aggiornati

Sul CRUD sorgente sostituire:

```text
/membri/dasi/pages/cartella_progetto.php
```

con:

```text
cartella_progetto_unico_v4_5_0.php
```

rinominandolo in:

```text
cartella_progetto.php
```

Sul sito destinatario sostituire:

```text
/membri/poimanager/deploy_receiver.php
```

con:

```text
deploy_receiver_v1_2_3_php80.php
```

rinominandolo in:

```text
deploy_receiver.php
```

Aggiornare anche:

```text
/membri/poimanager/deploy_receiver_config.php
```

aggiungendo almeno:

```php
'allow_root_delete_missing' => true,
```

---

## File inclusi nel pacchetto smart

La procedura include sempre, se presenti:

```text
index.php
db.php
schema.sql
schema.json
manifest.json
favicon.ico
.htaccess
```

Include inoltre le pagine PHP richiamate da `index.php`, per esempio:

```text
pages/anagrafe.php
pages/ordini.php
pages/prodotti.php
```

Include anche eventuali cartelle statiche locali:

```text
assets/
css/
js/
img/
images/
icons/
fonts/
uploads/
vendor/
```

---

## File esclusi dal pacchetto

Non vengono più caricati sul sito destinatario:

```text
sw.js
schema_update.sql
*.zip
*.log
*.tmp
*.old
*.backup*
*.bak*
deploy_receiver.php
deploy_receiver_config.php
deploy_reset_check.php
.deploy.json
_deploy/
.git/
.vscode/
```

---

## Backup

Il CRUD v4.5.0 invia sempre al receiver:

```text
create_backup = 0
```

Quindi il sito destinatario non crea nuovi backup remoti nella cartella `_deploy/backup`.

Il numero “Backup locali da mantenere” resta solo come promemoria/compatibilità, ma non genera backup sul sito destinatario.

I backup già presenti sul sito destinatario possono essere eliminati manualmente dalla cartella:

```text
/membri/poimanager/_deploy/backup/
```

oppure eliminando tutta la cartella `_deploy`, se non serve mantenere log/backup precedenti.

---

## Pulizia file inutili sul sito destinatario

Nel popup del CRUD attivare:

```text
Pulisci file inutili sul sito
```

Questa opzione invia:

```text
delete_missing = 1
```

Il receiver confronta i file presenti nel sito destinatario con quelli presenti nel nuovo pacchetto smart.

I file presenti sul sito destinatario ma non presenti nel pacchetto vengono eliminati.

Non vengono eliminati i file protetti:

```text
deploy_receiver.php
deploy_receiver_config.php
.deploy.json
_deploy/
.ftpquota
.gitignore
.vscode
```

---

## Configurazione consigliata deploy_receiver_config.php

```php
<?php
return [
    'token' => 'INSERIRE_TOKEN_SEGRETO_LUNGO_ALMENO_32_CARATTERI',

    'base_dir' => __DIR__,
    'work_dir' => __DIR__ . DIRECTORY_SEPARATOR . '_deploy',

    'allowed_paths' => ['.'],
    'allow_root_delete_missing' => true,

    'backup_keep' => 1,
    'max_upload_bytes' => 50 * 1024 * 1024,

    'protected_names' => [
        'deploy_receiver.php',
        'deploy_receiver_config.php',
        '.deploy.json',
        '_deploy',
        '.ftpquota',
        '.gitignore',
        '.vscode',
    ],

    'persistent_paths' => [
        '_deploy',
    ],
];
```

---

## Ordine corretto

Nel popup:

```text
1. Salva DB destinatario
2. Salva
3. Verifica ricevitore
4. Associa
5. attiva Pulisci file inutili sul sito
6. Pubblica
```

---

## Risultato atteso sul sito destinatario

Dopo la pubblicazione devono rimanere solo:

```text
deploy_receiver.php
deploy_receiver_config.php
.deploy.json
_deploy/
index.php
db.php
schema.sql
schema.json
pages/
eventuali asset realmente usati
```

Non devono più rimanere vecchi backup o vecchie pagine non richiamate da `index.php`.
