=== PaperPlane Mail Test ===
Contributors: paperplanefactory
Requires at least: 5.9
Tested up to: 6.7
Stable tag: 1.1.8
License: GPLv2 or later

Monitors the mail function on client sites from a central installation. Install on the assistance site.

== Description ==

PaperPlane Mail Test monitors the mail function on your client sites from a central installation. It periodically sends a test email through each site and checks whether `wp_mail()` succeeds. If it fails, you receive an alert immediately — before your client even notices.

Each client site must have the PaperPlane Mail Test Child plugin installed and configured.

== Changelog ==

= 1.1.8 =
* Down management: lo stato di ogni sito viene salvato subito dopo il suo check (prima solo a fine run: se PHP si interrompeva per `max_execution_time` gli stati andavano persi e gli alert venivano ripetuti); limite di esecuzione rinnovato per ogni sito; lock per evitare run sovrapposti
* Down management: un nuovo tentativo dopo 5 secondi per errori di rete, timeout e HTTP 502/503/504 prima di dichiarare KO
* Notifica di ripristino quando un sito torna OK dopo un KO, con data di inizio e durata del KO
* Lista siti: per ogni sito in KO mostra la causa leggibile, i dettagli tecnici (header Server, estratto della risposta) e da quando è in KO; le stesse informazioni sono nell'alert
* Messaggi: distinti DNS che non risolve, URL non consentito, credenziali nell'URL, redirect (con destinazione), autenticazione rifiutata, firewall/plugin di sicurezza, child non attivo, manutenzione, errori server, risposta non valida, wp_mail() fallita; errori specifici anche nei form Aggiungi/Modifica sito
* Chiavi di sicurezza WordPress cambiate: se una chiave salvata non è più decifrabile (sul main o sul child) viene mostrata la causa invece di un generico HTTP 401
* README: sezioni su gestione dei siti down/in errore e rotazione della chiave

= 1.1.7 =
* README: rimosso il riferimento alla costante `PP_MAIL_TEST_SECRET` del child (non più supportata dalla 1.4.9)
* Security: il check si connette all'IP già validato (`CURLOPT_RESOLVE`): chiude DNS rebinding e connessioni IPv6 mai validate (prima veniva controllato solo l'IPv4 e il DNS veniva risolto una seconda volta)
* Security: validati tutti gli IPv4 del dominio (`gethostbynamel()`), non solo il primo
* Security: rifiutati gli URL con credenziali (`https://user:pass@host`)
* Fix: import JSON con campi non stringa (es. `url` come array) non genera più un fatal error; stesso controllo sui form Aggiungi/Modifica sito

= 1.1.6 =
* Release: ogni release include `paperplane-mail-test.zip` (cartella a nome fisso `paperplane-mail-test/`), generato automaticamente da una GitHub Action
* Aggiornamenti: Plugin Update Checker usa lo zip allegato alla release invece dello zip sorgente di GitHub
* README: installare da `paperplane-mail-test.zip`, non da "Source code (zip)"

= 1.1.5 =
* Modifica sito: ora è possibile cambiare anche URL (con validazione SSRF) e chiave segreta (campo vuoto = chiave invariata, mai mostrata)
* Aggiungi sito: nuovo pulsante "Aggiungi e verifica" che salva il sito ed esegue subito il primo check
* Modifica sito: nuovo pulsante "Salva e verifica" che salva ed esegue subito un check; al cambio di URL o chiave lo stato del sito viene azzerato
* Security: `pp_mm_is_url_allowed()` blocca anche la rete CGNAT 100.64.0.0/10 (non coperta dai flag di `filter_var()`)
* Impostazioni: chiarito che l'indirizzo di test silenzioso è opzionale e che, se vuoto, il test viene inviato all'email di amministrazione del sito monitorato
* Security: `pp_mm_check_site()` non segue più i redirect HTTP (un 3xx dal sito monitorato poteva aggirare `pp_mm_is_url_allowed()` e indirizzare la richiesta verso IP privati/interni)

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
