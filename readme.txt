=== PaperPlane Mail Test ===
Contributors: paperplanefactory
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitors the mail function on client sites from a central installation. Install on the assistance site.

== Description ==

PaperPlane Mail Test monitors the mail function on your client sites from a central installation. It periodically sends a test email through each site and checks whether `wp_mail()` succeeds. If it fails, you receive an alert immediately — before your client even notices.

Each client site must have the PaperPlane Mail Test Child plugin installed and configured.

== Changelog ==

= 1.2.0 =
* Archivio: i siti sono in una tabella dedicata, una riga per sito con ID stabile (prima un'unica opzione con tutto l'elenco, caricata a ogni pagina). Migrazione automatica dall'opzione `pp_mm_sites` in un'unica transazione; l'elenco precedente resta come copia di sicurezza
* Fix: un giro del cron non sovrascrive più le modifiche fatte nell'admin mentre è in corso (prima una chiave cambiata o un sito aggiunto durante il giro andavano persi)
* Fix: le azioni (Controlla ora, Modifica, Rimuovi) usano l'ID del sito invece della posizione nella lista: da una pagina rimasta aperta non agiscono più sul sito sbagliato
* Se un sito viene modificato o eliminato durante il suo check, l'esito (relativo alla configurazione precedente) viene scartato
* Cron ogni 5 minuti con tempo massimo per giro (circa un minuto): con molti siti il carico si distribuisce su più giri; i siti più in ritardo vengono controllati per primi. Soglie: orario 55 minuti, giornaliero 23 ore e 55 minuti. Lock atomico contro giri sovrapposti
* Avviso nella lista siti se i check non vengono eseguiti da più di 15 minuti; README con le istruzioni per il cron di sistema
* Lista siti: ricerca per nome o URL, filtri per stato con conteggi, ordinamento per nome, ultimo check o stato, pagine da 50 siti
* Storico dei check per sito (90 giorni): ultimi check con causa, numero di check e KO e percentuale di successo negli ultimi 30 giorni
* La disinstallazione rimuove anche le tabelle

= 1.1.12 =
* Fix: i siti impostati "ogni ora" venivano controllati di fatto ogni 2 ore. Il sito risultava da controllare solo dopo 60 minuti esatti dall'ultimo check, registrato a fine check: al giro successivo del cron mancavano pochi secondi e il sito veniva saltato. Ora la soglia oraria è 50 minuti (giornaliera invariata, 23 ore)
* Colonna "Prossimo check": per i siti giornalieri indica +24 ore (prima +23, un'ora prima del check reale)
* Header `License` / `License URI` (GPLv2 or later)

= 1.1.11 =
* Lista siti: righe alternate bianco/grigio per sito (prima la classe "striped" contava anche la riga nascosta del form Modifica e tutti i siti risultavano dello stesso colore) e bordo inferiore su ogni sito
* Accessibilità WCAG 2.2 AA (verificata con axe-core, 0 violazioni): contrasto dei colori, pulsanti Controlla ora/Modifica/Rimuovi con il nome del sito per gli screen reader, stato aperto/chiuso del form Modifica con gestione del focus, didascalia e intestazioni della tabella, gruppi di opzioni con legenda nelle impostazioni
* Security: header `Update URI`: gli aggiornamenti arrivano solo da GitHub, un eventuale plugin omonimo su wordpress.org non può sostituire il main
* Compatibilità: Tested up to 7.1, Requires PHP 8.0 (anche nell'header del plugin, così WordPress blocca l'attivazione su PHP 7)

= 1.1.10 =
* Security: richieste firmate. La chiave segreta non viene più inviata: ogni richiesta ha timestamp, nonce monouso e firma HMAC-SHA256 calcolata con la chiave; il child rifiuta firme errate, timestamp fuori dai 5 minuti e nonce già usati (protezione replay). Richiede il child 1.4.11 o successivo: aggiornare prima tutti i child
* Messaggi: orologi dei server sfasati, richiesta già ricevuta (replay), child precedente alla 1.4.11
* Security: l'export non contiene più le chiavi in chiaro. Due modalità: chiavi cifrate con password (AES-256-GCM, chiave derivata con PBKDF2-SHA256 a 600.000 iterazioni, minimo 12 caratteri) oppure export senza chiavi
* Import: richiede la password per gli export protetti; con password errata o file modificato non importa nulla; limite sulle iterazioni del file contro file costruiti per bloccare il server; gli export precedenti (chiavi in chiaro) restano importabili
* Import senza chiavi: i siti risultano KO con la causa "nessuna chiave salvata" senza inviare alert, finché non si inserisce la chiave con Modifica

= 1.1.9 =
* Fix: nome del sito con apostrofi o virgolette salvato con backslash (es. "Studio d\'Arte"): aggiunto `wp_unslash()` nei form Aggiungi/Modifica sito

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
