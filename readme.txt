=== PaperPlane Mail Test ===
Contributors: paperplanefactory
Requires at least: 5.9
Tested up to: 6.7
Stable tag: 1.1.4
License: GPLv2 or later

Monitors the mail function on client sites from a central installation. Install on the assistance site.

== Description ==

PaperPlane Mail Test monitors the mail function on your client sites from a central installation. It periodically sends a test email through each site and checks whether `wp_mail()` succeeds. If it fails, you receive an alert immediately — before your client even notices.

Each client site must have the PaperPlane Mail Test Child plugin installed and configured.

== Changelog ==

= 1.1.4 =
* Security: `pp_mm_is_url_allowed()` applicato anche durante l'import (il bypass SSRF via JSON era possibile)
* Security: ri-validazione URL al momento della richiesta HTTP in `pp_mm_check_site()` (SSRF TOCTOU / DNS rebinding)
* Security: azioni POST non più leggibili da `$_GET`; aggiunto `sanitize_key()` su `$action`
* Security: chiave di cifratura non usa più `siteurl` come fallback se `AUTH_KEY` è assente

= 1.1.3 =
* Aggiunto token univoco per ogni call: inviato all'endpoint child come pp_check_token, salvato in last_check_token per la futura verifica della consegna

= 1.1.2 =
* Aggiunta modifica inline di nome sito e frequenza direttamente dalla lista siti
* Aggiunta colonna "Prossimo check" nella lista siti
* Formato data aggiornato a YYYY/MM/DD HH:MM in tutta l'interfaccia e nelle email
* Soggetti email aggiornati al formato `[PaperPlane Mail Test ...]`
* Aggiunto contatore siti nell'intestazione della lista
* Fix: le azioni "Controlla ora" e "Rimuovi" agivano sul sito sbagliato quando la lista era ordinata alfabeticamente

= 1.1.1 =
* Soggetti email aggiornati con prefisso `[PaperPlane Mail Test]`

= 1.1.0 =
* Cifratura AES-256-CBC delle chiavi segrete salvate in `wp_options`
* Migrazione automatica one-time delle chiavi salvate in chiaro
* Check all'attivazione: richiede l'estensione PHP OpenSSL
* Export: chiavi decifrate nel file JSON per portabilità
* Import: chiavi ri-cifrate dopo la lettura

= 1.0.9 =
* Aggiunta funzionalità export/import siti monitorati
* Aggiunto indirizzo "Silent test" per i check automatici del cron

= 1.0.0 =
* Prima versione pubblica
