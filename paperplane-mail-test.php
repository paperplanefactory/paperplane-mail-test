<?php
/**
 * Plugin Name: PaperPlane Mail Test
 * Description: Monitors mail function on client sites. Requires PaperPlane Mail Test Child installed on each site.
 * Version: 1.1.12
 * Requires at least: 5.9
 * Requires PHP: 8.0
 * Author: Paper Plane Factory
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paperplane-mail-test
 * Domain Path: /languages
 * Update URI: https://github.com/paperplanefactory/paperplane-mail-test/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Auto-aggiornamenti da GitHub ─────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

add_action( 'init', function () {
	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/paperplanefactory/paperplane-mail-test/',
		__FILE__,
		'paperplane-mail-test'
	);
	$checker->setBranch( 'main' );
	// Usa lo zip allegato alla release (cartella paperplane-mail-test/); se manca, ripiega sullo zip sorgente.
	$checker->getVcsApi()->enableReleaseAssets( '/^paperplane-mail-test\.zip$/' );
} );

define( 'PP_MM_OPTION_SITES',          'pp_mm_sites' );
define( 'PP_MM_OPTION_NOTIFY',         'pp_mm_notify_email' );
define( 'PP_MM_CRON_HOOK',             'pp_mm_run_checks' );
define( 'PP_MM_OPTION_WEEKLY_ENABLED', 'pp_mm_weekly_enabled' );
define( 'PP_MM_OPTION_WEEKLY_DAY',     'pp_mm_weekly_day' );
define( 'PP_MM_OPTION_WEEKLY_HOUR',    'pp_mm_weekly_hour' );
define( 'PP_MM_OPTION_WEEKLY_LAST',    'pp_mm_weekly_last_sent' );
define( 'PP_MM_OPTION_SILENT_TEST',   'pp_mm_silent_test_email' );

// ─── Cifratura chiavi segrete ─────────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
	if ( ! extension_loaded( 'openssl' ) ) {
		wp_die( esc_html__( 'PaperPlane Mail Test requires the PHP OpenSSL extension. Please enable it on your server and try again.', 'paperplane-mail-test' ) );
	}
} );

function pp_mm_get_encryption_key(): string {
	$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
	// Non usare siteurl come fallback: è pubblicamente noto e renderebbe la cifratura indovinabile.
	// Se AUTH_KEY non è definita WordPress stesso è rotto; il fallback a '' è coerente col child plugin.
	return hash( 'sha256', $salt . 'pp_mm_v1', true ); // 32 bytes per AES-256
}

function pp_mm_encrypt( string $plaintext ): string {
	if ( ! extension_loaded( 'openssl' ) || $plaintext === '' ) {
		return $plaintext;
	}
	$key = pp_mm_get_encryption_key();
	$iv  = openssl_random_pseudo_bytes( 16 );
	$enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	if ( $enc === false ) {
		return $plaintext;
	}
	return 'enc1:' . base64_encode( $iv . $enc );
}

function pp_mm_decrypt( string $ciphertext ): string {
	if ( ! str_starts_with( $ciphertext, 'enc1:' ) ) {
		return $ciphertext; // plaintext legacy o vuoto
	}
	if ( ! extension_loaded( 'openssl' ) ) {
		return '';
	}
	$key = pp_mm_get_encryption_key();
	$raw = base64_decode( substr( $ciphertext, 5 ) );
	if ( strlen( $raw ) <= 16 ) {
		return '';
	}
	$iv  = substr( $raw, 0, 16 );
	$enc = substr( $raw, 16 );
	$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return $dec !== false ? $dec : '';
}

// Migrazione one-time: cifra i segreti già salvati in chiaro.
add_action( 'admin_init', function () {
	if ( get_option( 'pp_mm_secrets_encrypted', 0 ) ) {
		return;
	}
	$sites = get_option( PP_MM_OPTION_SITES, array() );
	if ( ! empty( $sites ) ) {
		foreach ( $sites as &$site ) {
			$secret = $site['secret'] ?? '';
			if ( $secret && ! str_starts_with( $secret, 'enc1:' ) ) {
				$site['secret'] = pp_mm_encrypt( $secret );
			}
		}
		unset( $site );
		update_option( PP_MM_OPTION_SITES, $sites );
	}
	update_option( 'pp_mm_secrets_encrypted', 1 );
} );

// ─── i18n ─────────────────────────────────────────────────────────────────────

add_action( 'init', function () {
	load_plugin_textdomain( 'paperplane-mail-test', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// ─── Validazione URL siti monitorati ─────────────────────────────────────────

/**
 * Accetta solo URL https:// che non puntino a indirizzi IP privati o riservati.
 * Protezione contro SSRF (Server-Side Request Forgery).
 */
function pp_mm_is_url_allowed( string $url ): bool {
	return pp_mm_check_url( $url )['code'] === '';
}

/**
 * Restituisce il primo IPv4 pubblico dell'host, o '' se l'URL non è consentito.
 */
function pp_mm_resolve_allowed_ip( string $url ): string {
	return pp_mm_check_url( $url )['ip'];
}

/**
 * Valida l'URL e risolve l'host. Restituisce ['ip' => string, 'code' => string]:
 * code è '' se l'URL è consentito, altrimenti il motivo del rifiuto
 * ('https', 'credentials', 'host', 'dns', 'private'; testo in pp_mm_url_error_message()).
 * L'IP restituito va usato per la connessione (pp_mm_remote_post_pinned()): una seconda
 * risoluzione DNS potrebbe dare un altro indirizzo (DNS rebinding) o un IPv6 mai validato.
 */
function pp_mm_check_url( string $url ): array {
	$fail   = function ( string $code ) {
		return array( 'ip' => '', 'code' => $code );
	};
	$parsed = wp_parse_url( $url );
	if ( ! $parsed || ( $parsed['scheme'] ?? '' ) !== 'https' ) {
		return $fail( 'https' );
	}
	// Credenziali nell'URL (https://user:pass@host): verrebbero salvate in chiaro e inviate al sito.
	if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
		return $fail( 'credentials' );
	}
	$host = $parsed['host'] ?? '';
	if ( ! $host ) {
		return $fail( 'https' );
	}
	if ( in_array( strtolower( $host ), array( 'localhost', '::1' ), true ) ) {
		return $fail( 'private' );
	}
	// Solo IPv4: i siti IPv6-only non sono supportati (limitazione nota).
	$ips = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? array( $host ) : gethostbynamel( $host );
	if ( empty( $ips ) ) {
		return $fail( 'dns' );
	}
	foreach ( $ips as $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			continue;
		}
		// 100.64.0.0/10 (CGNAT, RFC 6598) non è coperto dai flag di filter_var() ma è una rete interna (es. Tailscale).
		if ( ( ip2long( $ip ) & 0xFFC00000 ) === 0x64400000 ) {
			continue;
		}
		return array( 'ip' => $ip, 'code' => '' );
	}
	return $fail( 'private' );
}

function pp_mm_url_error_message( string $code ): string {
	switch ( $code ) {
		case 'credentials':
			return __( 'Invalid URL: credentials (user:password@) are not allowed in the address.', 'paperplane-mail-test' );
		case 'dns':
			return __( 'The domain does not resolve (DNS): check that the domain is active and its DNS records are correct.', 'paperplane-mail-test' );
		case 'private':
			return __( 'URL not allowed: the domain points to a private or reserved address. Only public sites can be monitored.', 'paperplane-mail-test' );
		default:
			return __( 'Invalid URL. Only https:// addresses pointing to public sites are accepted.', 'paperplane-mail-test' );
	}
}

/**
 * wp_remote_post() con la connessione forzata sull'IP già validato (CURLOPT_RESOLVE).
 * Hostname, SNI e verifica del certificato restano quelli dell'URL.
 */
function pp_mm_remote_post_pinned( string $url, array $args, string $ip ) {
	$parsed = wp_parse_url( $url );
	$entry  = $parsed['host'] . ':' . ( $parsed['port'] ?? 443 ) . ':' . $ip;
	$pin    = function ( $handle, $request, $request_url ) use ( $url, $entry ) {
		if ( $request_url === $url ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) );
		}
	};
	add_action( 'http_api_curl', $pin, 10, 3 );
	$response = wp_remote_post( $url, $args );
	remove_action( 'http_api_curl', $pin, 10 );
	return $response;
}

// ─── Cron ─────────────────────────────────────────────────────────────────────

add_action( 'init', 'pp_mm_schedule_cron' );

function pp_mm_schedule_cron() {
	if ( ! wp_next_scheduled( PP_MM_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', PP_MM_CRON_HOOK );
	}
}

add_action( PP_MM_CRON_HOOK, 'pp_mm_run_checks' );

/**
 * Controlla i siti la cui frequenza corrisponde all'orario corrente.
 * - "hourly"  → ogni run
 * - "daily"   → solo se non già controllato nelle ultime 23 ore
 */
function pp_mm_run_checks() {
	$sites = get_option( PP_MM_OPTION_SITES, array() );
	if ( empty( $sites ) ) {
		return;
	}

	// Evita due run sovrapposti (WP-Cron può partire due volte): alert doppi e stati sovrascritti.
	if ( get_transient( 'pp_mm_checks_running' ) ) {
		return;
	}
	set_transient( 'pp_mm_checks_running', 1, 15 * MINUTE_IN_SECONDS );

	$now         = time();
	$silent_test = get_option( PP_MM_OPTION_SILENT_TEST, '' );

	try {
		foreach ( $sites as $i => &$site ) {
			if ( ! pp_mm_is_due( $site, $now ) ) {
				continue;
			}

			// Ogni sito può richiedere fino a ~35 s (timeout + nuovo tentativo): il limite di
			// esecuzione riparte per ogni sito, così molti siti giù non interrompono il run.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 );
			}

			pp_mm_apply_result( $site, pp_mm_check_site( $site, $silent_test ) );

			// Salvataggio dopo ogni sito: se PHP si interrompe, i siti già controllati restano
			// aggiornati e gli alert già inviati non vengono ripetuti al run successivo.
			update_option( PP_MM_OPTION_SITES, $sites );
		}
		unset( $site );

		pp_mm_maybe_send_weekly_report();
	} finally {
		delete_transient( 'pp_mm_checks_running' );
	}
}

/**
 * Tempo minimo dall'ultimo check perché un sito sia di nuovo da controllare.
 * Il cron parte ogni ora: la soglia "oraria" è 50 minuti, non 60, perché last_check è registrato
 * a fine check e WP-Cron può partire con qualche secondo o minuto di ritardo. Con la soglia esatta
 * di 60 minuti il sito veniva saltato un giro su due (controllo di fatto ogni 2 ore).
 */
function pp_mm_check_threshold( array $site ): int {
	return ( $site['frequency'] ?? 'daily' ) === 'hourly' ? 50 * MINUTE_IN_SECONDS : 23 * HOUR_IN_SECONDS;
}

function pp_mm_is_due( array $site, int $now ): bool {
	return ( $now - (int) ( $site['last_check'] ?? 0 ) ) >= pp_mm_check_threshold( $site );
}

/**
 * Aggiorna lo stato del sito con l'esito del check e invia le notifiche al cambio di stato:
 * alert al passaggio a KO (o primo KO), notifica di ripristino al ritorno da KO a OK.
 */
function pp_mm_apply_result( array &$site, array $result ): void {
	$prev_status = $site['last_status'] ?? '';

	$site['last_check']       = time();
	$site['last_status']      = $result['success'] ? 'ok' : 'error';
	$site['last_message']     = $result['message'] ?? '';
	$site['last_detail']      = $result['detail'] ?? '';
	$site['last_check_token'] = $result['token'] ?? '';

	if ( ! $result['success'] ) {
		if ( $prev_status !== 'error' ) {
			$site['error_since'] = $site['last_check'];
			pp_mm_send_alert( $site, $result['message'] );
		}
		return;
	}

	if ( $prev_status === 'error' ) {
		pp_mm_send_recovery( $site );
	}
	$site['error_since'] = 0;
}

function pp_mm_missing_key_message(): string {
	return __( 'No secret key saved for this site: enter it with Edit.', 'paperplane-mail-test' );
}

/**
 * Testo firmato con HMAC-SHA256: deve coincidere con pp_mt_signature_payload() del child.
 */
function pp_mm_signature_payload( string $ts, string $nonce, string $token, string $test_email ): string {
	return implode( "\n", array( 'pp-mail-test-v1', $ts, $nonce, $token, $test_email ) );
}

/**
 * Body della richiesta firmata (child ≥ 1.4.11): la chiave segreta non viene inviata.
 * Timestamp e nonce monouso impediscono di ripetere una richiesta intercettata (replay).
 */
function pp_mm_signed_body( string $secret, string $token, string $test_email ): array {
	$ts    = (string) time();
	$nonce = wp_generate_password( 32, false );
	$body  = array(
		'pp_ts'          => $ts,
		'pp_nonce'       => $nonce,
		'pp_check_token' => $token,
		'pp_sig'         => hash_hmac( 'sha256', pp_mm_signature_payload( $ts, $nonce, $token, $test_email ), $secret ),
	);
	if ( $test_email !== '' ) {
		$body['test_email'] = $test_email;
	}
	return $body;
}

/**
 * Chiama l'endpoint del sito e restituisce array ['success', 'message', 'detail', 'token'].
 * message: causa leggibile; detail: informazioni tecniche (header server, estratto della risposta).
 */
function pp_mm_check_site( array $site, string $test_email = '' ): array {
	$url    = trailingslashit( $site['url'] ) . 'wp-json/pp-mail-test/v1/check';
	$token  = wp_generate_password( 16, false );
	$result = function ( bool $success, string $message, string $detail = '' ) use ( $token ) {
		return array( 'success' => $success, 'message' => $message, 'detail' => $detail, 'token' => $token );
	};

	// Ri-valida l'URL al momento della richiesta (il DNS potrebbe essere cambiato dopo il salvataggio)
	// e connette proprio all'IP validato.
	$url_check = pp_mm_check_url( $site['url'] );
	if ( $url_check['code'] !== '' ) {
		return $result( false, pp_mm_url_error_message( $url_check['code'] ) );
	}

	// Nessuna chiave (es. import senza chiavi): inutile chiamare il sito.
	$stored = $site['secret'] ?? '';
	if ( $stored === '' ) {
		return $result( false, pp_mm_missing_key_message() );
	}
	// Chiave salvata ma non più decifrabile: le chiavi di sicurezza di WordPress (AUTH_KEY) di questo sito sono cambiate.
	$secret = pp_mm_decrypt( $stored );
	if ( $stored !== '' && $secret === '' ) {
		return $result( false, __( 'The secret key saved here can no longer be decrypted (the WordPress security keys of this site have changed). Re-enter the key with Edit.', 'paperplane-mail-test' ) );
	}

	// Nessun redirect: un 3xx porterebbe la richiesta verso URL non validati da pp_mm_check_url() (SSRF).
	// Il body è firmato a ogni tentativo (nonce nuovo): il child rifiuterebbe un nonce già usato.
	$args = array(
		'timeout'     => 15,
		'redirection' => 0,
		'body'        => pp_mm_signed_body( $secret, $token, $test_email ),
	);

	$response = pp_mm_remote_post_pinned( $url, $args, $url_check['ip'] );
	// Un nuovo tentativo per gli errori probabilmente temporanei (rete, timeout, 502/503/504),
	// così un singolo problema di rete passeggero non genera un alert.
	if ( is_wp_error( $response ) || in_array( wp_remote_retrieve_response_code( $response ), array( 502, 503, 504 ), true ) ) {
		sleep( 5 );
		$args['body'] = pp_mm_signed_body( $secret, $token, $test_email );
		$response     = pp_mm_remote_post_pinned( $url, $args, $url_check['ip'] );
	}

	if ( is_wp_error( $response ) ) {
		return $result( false, sprintf( __( 'Site not reachable: %s', 'paperplane-mail-test' ), $response->get_error_message() ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$json = json_decode( $raw, true );

	if ( $code !== 200 ) {
		return $result( false, pp_mm_describe_http_error( $code, $response, is_array( $json ) ? $json : array() ), pp_mm_response_detail( $response ) );
	}
	if ( ! is_array( $json ) ) {
		return $result( false, __( 'Invalid response: HTTP 200 but not the expected data (cache plugin, maintenance page or PHP output).', 'paperplane-mail-test' ), pp_mm_response_detail( $response ) );
	}
	if ( empty( $json['success'] ) ) {
		return $result( false, sprintf( __( 'wp_mail() failed on the client site: check its mail/SMTP configuration. (%s)', 'paperplane-mail-test' ), (string) ( $json['message'] ?? '' ) ) );
	}
	return $result( true, (string) ( $json['message'] ?? '' ) );
}

/**
 * Causa leggibile di una risposta HTTP diversa da 200.
 */
function pp_mm_describe_http_error( int $code, $response, array $json ): string {
	$rest_code = (string) ( $json['code'] ?? '' );

	if ( $rest_code === 'pp_mt_clock_skew' ) {
		$text = __( 'The clocks of this server and the client site differ by more than 5 minutes: check the time (NTP) on both servers.', 'paperplane-mail-test' );
	} elseif ( $rest_code === 'pp_mt_replay' ) {
		$text = __( 'Request rejected as already received (replay protection). If it happens again, check the server clocks.', 'paperplane-mail-test' );
	} elseif ( $rest_code === 'pp_mt_key_unreadable' ) {
		$text = __( 'The secret key on the client site can no longer be decrypted (its WordPress security keys have changed). Generate a new key on the client site and update it here with Edit.', 'paperplane-mail-test' );
	} elseif ( $code >= 300 && $code < 400 ) {
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$text     = sprintf( __( 'The site redirects to %s: update the site URL with Edit.', 'paperplane-mail-test' ), $location !== '' ? $location : '?' );
	} elseif ( $code === 401 && $rest_code === 'rest_forbidden' ) {
		$text = __( 'Authentication refused: wrong secret key, too many calls in a short time, or child plugin older than 1.4.11 (update it).', 'paperplane-mail-test' );
	} elseif ( $code === 401 || $code === 403 ) {
		$text = __( 'Access denied: a firewall or security plugin is blocking the REST API.', 'paperplane-mail-test' );
	} elseif ( $code === 404 && $rest_code === 'rest_no_route' ) {
		$text = __( 'Endpoint not found: the PaperPlane Mail Test Child plugin is not active on the site.', 'paperplane-mail-test' );
	} elseif ( $code === 404 ) {
		$text = __( 'REST API not reachable: disabled or blocked on the site.', 'paperplane-mail-test' );
	} elseif ( $code === 503 ) {
		$text = __( 'Site unavailable: maintenance mode or overloaded server.', 'paperplane-mail-test' );
	} elseif ( $code === 502 || $code === 504 ) {
		$text = __( 'Gateway error: the server or proxy in front of the site is not responding.', 'paperplane-mail-test' );
	} elseif ( $code >= 500 ) {
		$text = __( 'Server error on the client site (for example a PHP error).', 'paperplane-mail-test' );
	} else {
		$text = __( 'Unexpected response.', 'paperplane-mail-test' );
	}
	return 'HTTP ' . $code . ' — ' . $text;
}

/**
 * Dettaglio tecnico della risposta: header Server e un breve estratto del contenuto.
 */
function pp_mm_response_detail( $response ): string {
	$parts  = array();
	$server = (string) wp_remote_retrieve_header( $response, 'server' );
	if ( $server !== '' ) {
		$parts[] = 'Server: ' . $server;
	}
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) ) );
	if ( $text !== '' ) {
		$parts[] = mb_strimwidth( $text, 0, 160, '…' );
	}
	return implode( ' · ', $parts );
}

function pp_mm_alert_recipients(): array {
	$notify = get_option( PP_MM_OPTION_NOTIFY, '' );
	return $notify ? array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $notify ) ) ) ) : array();
}

function pp_mm_send_alert( array $site, string $message ) {
	$recipients = pp_mm_alert_recipients();
	if ( empty( $recipients ) ) {
		return;
	}
	$label   = $site['label'] ?: $site['url'];
	$subject = sprintf( __( '[PaperPlane Mail Test issue] Mail KO: %s', 'paperplane-mail-test' ), $label );
	$body    = '<p>' . sprintf( __( 'The mail function test on %s failed.', 'paperplane-mail-test' ), '<strong>' . esc_html( $label ) . '</strong>' ) . '</p>'
		. '<p><strong>' . __( 'URL:', 'paperplane-mail-test' ) . '</strong> ' . esc_html( $site['url'] ) . '</p>'
		. '<p><strong>' . __( 'Error:', 'paperplane-mail-test' ) . '</strong> ' . esc_html( $message ) . '</p>'
		. ( ! empty( $site['last_detail'] ) ? '<p><strong>' . __( 'Details:', 'paperplane-mail-test' ) . '</strong> ' . esc_html( $site['last_detail'] ) . '</p>' : '' )
		. '<p><strong>' . __( 'Date:', 'paperplane-mail-test' ) . '</strong> ' . date_i18n( 'Y/m/d H:i' ) . '</p>';
	wp_mail( $recipients, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
 * Notifica di ripristino: il sito torna OK dopo un KO.
 */
function pp_mm_send_recovery( array $site ) {
	$recipients = pp_mm_alert_recipients();
	if ( empty( $recipients ) ) {
		return;
	}
	$label   = $site['label'] ?: $site['url'];
	$since   = (int) ( $site['error_since'] ?? 0 );
	$subject = sprintf( __( '[PaperPlane Mail Test resolved] Mail OK: %s', 'paperplane-mail-test' ), $label );
	$body    = '<p>' . sprintf( __( 'The mail function on %s works again.', 'paperplane-mail-test' ), '<strong>' . esc_html( $label ) . '</strong>' ) . '</p>'
		. '<p><strong>' . __( 'URL:', 'paperplane-mail-test' ) . '</strong> ' . esc_html( $site['url'] ) . '</p>'
		. ( $since ? '<p><strong>' . __( 'KO since:', 'paperplane-mail-test' ) . '</strong> ' . wp_date( 'Y/m/d H:i', $since ) . ' (' . human_time_diff( $since, time() ) . ')</p>' : '' )
		. '<p><strong>' . __( 'Date:', 'paperplane-mail-test' ) . '</strong> ' . date_i18n( 'Y/m/d H:i' ) . '</p>';
	wp_mail( $recipients, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

// ─── Report settimanale ───────────────────────────────────────────────────────

function pp_mm_maybe_send_weekly_report() {
	if ( ! get_option( PP_MM_OPTION_WEEKLY_ENABLED, 0 ) ) {
		return;
	}

	$day  = (int) get_option( PP_MM_OPTION_WEEKLY_DAY, 1 );
	$hour = (int) get_option( PP_MM_OPTION_WEEKLY_HOUR, 8 );
	$last = (int) get_option( PP_MM_OPTION_WEEKLY_LAST, 0 );

	if ( ( time() - $last ) < 6 * DAY_IN_SECONDS ) {
		return;
	}

	$tz       = wp_timezone();
	$now_dt   = new DateTime( 'now', $tz );
	$cur_day  = (int) $now_dt->format( 'N' ); // 1=lunedì … 7=domenica
	$cur_hour = (int) $now_dt->format( 'G' ); // 0–23

	if ( $cur_day !== $day || $cur_hour !== $hour ) {
		return;
	}

	pp_mm_send_weekly_report();
	update_option( PP_MM_OPTION_WEEKLY_LAST, time() );
}

function pp_mm_send_weekly_report() {
	$notify = get_option( PP_MM_OPTION_NOTIFY, '' );
	if ( ! $notify ) {
		return;
	}
	$recipients = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $notify ) ) ) );
	if ( empty( $recipients ) ) {
		return;
	}

	$sites = get_option( PP_MM_OPTION_SITES, array() );
	usort( $sites, function( $a, $b ) {
		return strcasecmp( $a['label'] ?: $a['url'], $b['label'] ?: $b['url'] );
	} );

	$ok_count = 0;
	$ko_count = 0;
	$rows     = '';

	foreach ( $sites as $site ) {
		$label      = $site['label'] ?: $site['url'];
		$status     = $site['last_status'] ?? '';
		$last_check = $site['last_check'] ? wp_date( 'Y/m/d H:i', $site['last_check'] ) : '—';
		$freq       = $site['frequency'] === 'hourly'
			? __( 'Every hour', 'paperplane-mail-test' )
			: __( 'Every day', 'paperplane-mail-test' );

		if ( $status === 'ok' ) {
			$ok_count++;
			$status_html = '<span style="color:#007017;font-weight:bold">&#10004; OK</span>';
		} elseif ( $status === 'error' ) {
			$ko_count++;
			$status_html = '<span style="color:#b32d2e;font-weight:bold">&#10006; KO</span>';
			if ( $site['last_message'] ) {
				$status_html .= '<br><span style="font-size:.9em;font-weight:normal">' . esc_html( $site['last_message'] ) . '</span>';
			}
		} else {
			$status_html = '<span style="color:#646970">—</span>';
		}

		$rows .= '<tr>'
			. '<td style="padding:8px 12px;border-bottom:1px solid #eee"><strong>' . esc_html( $label ) . '</strong><br>'
			. '<span style="color:#646970;font-size:.9em">' . esc_html( $site['url'] ) . '</span></td>'
			. '<td style="padding:8px 12px;border-bottom:1px solid #eee">' . esc_html( $freq ) . '</td>'
			. '<td style="padding:8px 12px;border-bottom:1px solid #eee">' . esc_html( $last_check ) . '</td>'
			. '<td style="padding:8px 12px;border-bottom:1px solid #eee">' . $status_html . '</td>'
			. '</tr>';
	}

	$total   = count( $sites );
	$summary = sprintf(
		/* translators: 1: total sites, 2: OK count, 3: KO count */
		__( '%1$d sites monitored — %2$d OK, %3$d KO', 'paperplane-mail-test' ),
		$total, $ok_count, $ko_count
	);

	$subject = sprintf(
		__( '[PaperPlane Mail Test Weekly Report] PaperPlane Mail Monitor — %s', 'paperplane-mail-test' ),
		wp_date( 'Y/m/d' )
	);

	$body = '<div style="font-family:sans-serif;max-width:700px;color:#1d2327">'
		. '<h2>' . esc_html__( 'Weekly Mail Monitor Report', 'paperplane-mail-test' ) . '</h2>'
		. '<p>' . esc_html( $summary ) . '</p>'
		. '<table style="width:100%;border-collapse:collapse;border:1px solid #eee">'
		. '<thead><tr style="background:#f6f7f7">'
		. '<th style="padding:8px 12px;text-align:left">' . esc_html__( 'Site', 'paperplane-mail-test' ) . '</th>'
		. '<th style="padding:8px 12px;text-align:left">' . esc_html__( 'Frequency', 'paperplane-mail-test' ) . '</th>'
		. '<th style="padding:8px 12px;text-align:left">' . esc_html__( 'Last check', 'paperplane-mail-test' ) . '</th>'
		. '<th style="padding:8px 12px;text-align:left">' . esc_html__( 'Status', 'paperplane-mail-test' ) . '</th>'
		. '</tr></thead>'
		. '<tbody>' . $rows . '</tbody>'
		. '</table>'
		. '<p style="color:#646970;font-size:.85em;margin-top:16px">' . esc_html( wp_date( 'Y/m/d H:i' ) ) . '</p>'
		. '</div>';

	wp_mail( $recipients, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

// ─── Admin menu ───────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'pp_mm_register_page' );

function pp_mm_register_page() {
	add_menu_page(
		__( 'Mail Monitor', 'paperplane-mail-test' ),
		__( 'Mail Monitor', 'paperplane-mail-test' ),
		'manage_options',
		'pp-mail-monitor',
		'pp_mm_render_sites_page',
		'dashicons-email-alt',
		30
	);
	add_submenu_page(
		'pp-mail-monitor',
		__( 'Monitored Sites', 'paperplane-mail-test' ),
		__( 'Monitored Sites', 'paperplane-mail-test' ),
		'manage_options',
		'pp-mail-monitor',
		'pp_mm_render_sites_page'
	);
	add_submenu_page(
		'pp-mail-monitor',
		__( 'Settings', 'paperplane-mail-test' ),
		__( 'Settings', 'paperplane-mail-test' ),
		'manage_options',
		'pp-mail-monitor-settings',
		'pp_mm_render_settings_page'
	);
}

add_action( 'admin_init', 'pp_mm_register_settings' );

function pp_mm_register_settings() {
	register_setting( 'pp_mm_settings', PP_MM_OPTION_NOTIFY, array(
		'sanitize_callback' => 'sanitize_text_field',
	) );
	register_setting( 'pp_mm_settings', PP_MM_OPTION_SILENT_TEST, array(
		'sanitize_callback' => 'sanitize_email',
	) );
	register_setting( 'pp_mm_settings', PP_MM_OPTION_WEEKLY_ENABLED, array(
		'sanitize_callback' => 'absint',
	) );
	register_setting( 'pp_mm_settings', PP_MM_OPTION_WEEKLY_DAY, array(
		'sanitize_callback' => function( $v ) {
			$v = (int) $v;
			return ( $v >= 1 && $v <= 7 ) ? $v : 1;
		},
	) );
	register_setting( 'pp_mm_settings', PP_MM_OPTION_WEEKLY_HOUR, array(
		'sanitize_callback' => function( $v ) {
			$v = (int) $v;
			return ( $v >= 0 && $v <= 23 ) ? $v : 8;
		},
	) );
}

// ─── Export cifrato ──────────────────────────────────────────────────────────
// Le chiavi segrete nell'export sono cifrate con una password scelta da chi esporta:
// AES-256-GCM con chiave derivata dalla password (PBKDF2-SHA256, salt casuale).

define( 'PP_MM_EXPORT_KDF_ITERATIONS', 600000 );
define( 'PP_MM_EXPORT_MIN_PASSWORD', 12 );

function pp_mm_export_key( string $password, string $salt, int $iterations ): string {
	return hash_pbkdf2( 'sha256', $password, $salt, $iterations, 32, true );
}

function pp_mm_export_encrypt( string $plaintext, string $key ): string {
	$iv  = random_bytes( 12 );
	$tag = '';
	$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	return base64_encode( $iv . $tag . $ct );
}

/**
 * Restituisce il testo in chiaro, o false se password errata o dati alterati (tag GCM non valido).
 */
function pp_mm_export_decrypt( string $blob, string $key ) {
	$raw = base64_decode( $blob, true );
	if ( $raw === false || strlen( $raw ) < 28 ) {
		return false;
	}
	return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
}

// ─── Gestione azioni POST ─────────────────────────────────────────────────────

/**
 * Check manuale ("Controlla ora", "Salva e verifica"): il test va al destinatario degli alert.
 * Aggiorna lo stato del sito (con alert e notifica di ripristino) e salva la lista.
 */
function pp_mm_manual_check( array &$sites, int $idx ): void {
	pp_mm_apply_result( $sites[ $idx ], pp_mm_check_site( $sites[ $idx ], get_option( PP_MM_OPTION_NOTIFY, '' ) ) );
	update_option( PP_MM_OPTION_SITES, $sites );
}

add_action( 'admin_init', 'pp_mm_handle_actions' );

function pp_mm_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$action = sanitize_key( $_POST['pp_mm_action'] ?? '' );

	// ── Aggiungi sito ──────────────────────────────────────────────────────────
	if ( $action === 'add_site' && check_admin_referer( 'pp_mm_add' ) ) {
		$raw_url = trim( (string) wp_unslash( is_string( $_POST['pp_mm_url'] ?? null ) ? $_POST['pp_mm_url'] : '' ) );
		$url_error = pp_mm_check_url( $raw_url )['code'];
		if ( $url_error !== '' ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_url_error', $url_error, admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
			exit;
		}
		$sites   = get_option( PP_MM_OPTION_SITES, array() );
		$sites[] = array(
			'label'        => sanitize_text_field( wp_unslash( $_POST['pp_mm_label'] ?? '' ) ),
			'url'          => esc_url_raw( $raw_url ),
			'secret'       => pp_mm_encrypt( sanitize_text_field( wp_unslash( $_POST['pp_mm_secret'] ?? '' ) ) ),
			'frequency'    => in_array( $_POST['pp_mm_frequency'] ?? '', array( 'hourly', 'daily' ), true )
				? $_POST['pp_mm_frequency']
				: 'daily',
			'last_check'   => 0,
			'last_status'  => '',
			'last_message' => '',
		);
		if ( ! empty( $_POST['pp_mm_verify'] ) ) {
			$idx = array_key_last( $sites );
			pp_mm_manual_check( $sites, $idx );
			wp_safe_redirect( add_query_arg( array( 'pp_mm_saved' => '1', 'pp_mm_checked' => $idx ), admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
			exit;
		}
		update_option( PP_MM_OPTION_SITES, $sites );
		wp_safe_redirect( add_query_arg( 'pp_mm_saved', '1', admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
		exit;
	}

	// ── Elimina sito ───────────────────────────────────────────────────────────
	if ( $action === 'delete_site' && check_admin_referer( 'pp_mm_delete' ) ) {
		$idx   = (int) ( $_POST['pp_mm_idx'] ?? -1 );
		$sites = get_option( PP_MM_OPTION_SITES, array() );
		if ( isset( $sites[ $idx ] ) ) {
			array_splice( $sites, $idx, 1 );
			update_option( PP_MM_OPTION_SITES, array_values( $sites ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=pp-mail-monitor' ) );
		exit;
	}

	// ── Controlla ora ──────────────────────────────────────────────────────────
	if ( $action === 'check_now' && check_admin_referer( 'pp_mm_check' ) ) {
		$idx   = (int) ( $_POST['pp_mm_idx'] ?? -1 );
		$sites = get_option( PP_MM_OPTION_SITES, array() );
		if ( isset( $sites[ $idx ] ) ) {
			pp_mm_manual_check( $sites, $idx );
		}
		wp_safe_redirect( add_query_arg( 'pp_mm_checked', $idx, admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
		exit;
	}

	// ── Modifica sito ─────────────────────────────────────────────────────────
	if ( $action === 'edit_site' && check_admin_referer( 'pp_mm_edit' ) ) {
		$idx      = (int) ( $_POST['pp_mm_idx'] ?? -1 );
		$sites    = get_option( PP_MM_OPTION_SITES, array() );
		$page_url = admin_url( 'admin.php?page=pp-mail-monitor' );
		if ( ! isset( $sites[ $idx ] ) ) {
			wp_safe_redirect( $page_url );
			exit;
		}

		// URL: validato come in fase di inserimento (SSRF). Campo vuoto = URL invariato.
		$raw_url = trim( (string) wp_unslash( is_string( $_POST['pp_mm_url'] ?? null ) ? $_POST['pp_mm_url'] : '' ) );
		$new_url = $raw_url !== '' ? esc_url_raw( $raw_url ) : $sites[ $idx ]['url'];
		$url_error = $new_url !== $sites[ $idx ]['url'] ? pp_mm_check_url( $new_url )['code'] : '';
		if ( $url_error !== '' ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_url_error', $url_error, $page_url ) );
			exit;
		}

		// Chiave: mai mostrata nel form. Campo vuoto = chiave invariata.
		$new_secret = sanitize_text_field( wp_unslash( $_POST['pp_mm_new_secret'] ?? '' ) );

		$sites[ $idx ]['label']     = sanitize_text_field( wp_unslash( $_POST['pp_mm_label'] ?? '' ) );
		$sites[ $idx ]['frequency'] = in_array( $_POST['pp_mm_frequency'] ?? '', array( 'hourly', 'daily' ), true )
			? $_POST['pp_mm_frequency']
			: $sites[ $idx ]['frequency'];

		if ( $new_url !== $sites[ $idx ]['url'] || $new_secret !== '' ) {
			$sites[ $idx ]['url'] = $new_url;
			if ( $new_secret !== '' ) {
				$sites[ $idx ]['secret'] = pp_mm_encrypt( $new_secret );
			}
			// Stato azzerato: un sito già in KO non rimanderebbe l'alert se la nuova configurazione fosse errata.
			$sites[ $idx ]['last_check']       = 0;
			$sites[ $idx ]['last_status']      = '';
			$sites[ $idx ]['last_message']     = '';
			$sites[ $idx ]['last_check_token'] = '';
			$sites[ $idx ]['last_detail']      = '';
			$sites[ $idx ]['error_since']      = 0;
		}

		if ( ! empty( $_POST['pp_mm_verify'] ) ) {
			pp_mm_manual_check( $sites, $idx );
			wp_safe_redirect( add_query_arg( array( 'pp_mm_updated' => '1', 'pp_mm_checked' => $idx ), $page_url ) );
			exit;
		}

		update_option( PP_MM_OPTION_SITES, $sites );
		wp_safe_redirect( add_query_arg( 'pp_mm_updated', '1', $page_url ) );
		exit;
	}

	// ── Export siti ────────────────────────────────────────────────────────────
	// Due modalità: chiavi cifrate con password (predefinita) oppure senza chiavi.
	if ( $action === 'export_sites' && check_admin_referer( 'pp_mm_export' ) ) {
		$settings_url = admin_url( 'admin.php?page=pp-mail-monitor-settings' );
		$with_keys    = ( $_POST['pp_mm_export_mode'] ?? 'password' ) !== 'nokeys';

		if ( $with_keys ) {
			// Password non sanitizzata: è usata solo per derivare la chiave, mai salvata o mostrata.
			$password = is_string( $_POST['pp_mm_export_password'] ?? null ) ? wp_unslash( $_POST['pp_mm_export_password'] ) : '';
			$confirm  = is_string( $_POST['pp_mm_export_password2'] ?? null ) ? wp_unslash( $_POST['pp_mm_export_password2'] ) : '';
			if ( mb_strlen( $password ) < PP_MM_EXPORT_MIN_PASSWORD ) {
				wp_safe_redirect( add_query_arg( 'pp_mm_export_error', 'short', $settings_url ) );
				exit;
			}
			if ( ! hash_equals( $password, $confirm ) ) {
				wp_safe_redirect( add_query_arg( 'pp_mm_export_error', 'mismatch', $settings_url ) );
				exit;
			}
			$salt = random_bytes( 16 );
			$key  = pp_mm_export_key( $password, $salt, PP_MM_EXPORT_KDF_ITERATIONS );
		}

		$export_sites = array_map( function( $site ) use ( $with_keys, &$key ) {
			$row = array(
				'label'     => $site['label'] ?? '',
				'url'       => $site['url'] ?? '',
				'frequency' => $site['frequency'] ?? 'daily',
			);
			if ( $with_keys ) {
				$row['secret_enc'] = pp_mm_export_encrypt( pp_mm_decrypt( $site['secret'] ?? '' ), $key );
			}
			return $row;
		}, get_option( PP_MM_OPTION_SITES, array() ) );

		$export = array(
			'version'     => '2.0',
			'exported_at' => wp_date( 'c' ),
			'plugin'      => 'paperplane-mail-test',
			'keys'        => $with_keys ? 'encrypted' : 'none',
		);
		if ( $with_keys ) {
			$export['cipher'] = 'aes-256-gcm';
			$export['kdf']    = array(
				'alg'        => 'pbkdf2-sha256',
				'iterations' => PP_MM_EXPORT_KDF_ITERATIONS,
				'salt'       => base64_encode( $salt ),
			);
		}
		$export['sites'] = $export_sites;

		$filename = 'pp-mail-monitor-export-' . wp_date( 'Y-m-d' ) . ( $with_keys ? '' : '-senza-chiavi' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	// ── Import siti ────────────────────────────────────────────────────────────
	if ( $action === 'import_sites' && check_admin_referer( 'pp_mm_import' ) ) {
		$settings_url = admin_url( 'admin.php?page=pp-mail-monitor-settings' );
		$file         = $_FILES['pp_mm_import_file'] ?? null;

		if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'upload', $settings_url ) );
			exit;
		}
		if ( $file['size'] > 256 * 1024 ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'size', $settings_url ) );
			exit;
		}
		if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'json' ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'type', $settings_url ) );
			exit;
		}

		$content = file_get_contents( $file['tmp_name'] );
		$data    = json_decode( $content, true );

		if (
			! is_array( $data ) ||
			( $data['plugin'] ?? '' ) !== 'paperplane-mail-test' ||
			! isset( $data['sites'] ) ||
			! is_array( $data['sites'] )
		) {
			wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'format', $settings_url ) );
			exit;
		}

		$mode     = in_array( $_POST['pp_mm_import_mode'] ?? '', array( 'replace', 'merge' ), true )
			? $_POST['pp_mm_import_mode']
			: 'replace';
		$imported = array();

		// Chiavi nel file: 'encrypted' (password), 'none' (senza chiavi), 'plain' (export ≤ 1.1.9, in chiaro).
		$keys = $data['keys'] ?? 'plain';
		if ( ! in_array( $keys, array( 'encrypted', 'none', 'plain' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'format', $settings_url ) );
			exit;
		}
		if ( $keys === 'encrypted' ) {
			$kdf        = is_array( $data['kdf'] ?? null ) ? $data['kdf'] : array();
			$salt       = base64_decode( (string) ( $kdf['salt'] ?? '' ), true );
			$iterations = (int) ( $kdf['iterations'] ?? 0 );
			// Limiti sulle iterazioni: un file costruito ad arte non deve bloccare il server.
			if ( ( $kdf['alg'] ?? '' ) !== 'pbkdf2-sha256' || ( $data['cipher'] ?? '' ) !== 'aes-256-gcm'
				|| $salt === false || strlen( $salt ) < 16 || $iterations < 100000 || $iterations > 5000000 ) {
				wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'format', $settings_url ) );
				exit;
			}
			$password = is_string( $_POST['pp_mm_import_password'] ?? null ) ? wp_unslash( $_POST['pp_mm_import_password'] ) : '';
			if ( $password === '' ) {
				wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'nopassword', $settings_url ) );
				exit;
			}
			$import_key = pp_mm_export_key( $password, $salt, $iterations );
		}

		foreach ( $data['sites'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			// Il JSON può contenere qualsiasi tipo: i campi non stringa diventano '' (un array in trim() è un fatal error).
			$raw = array_map( function ( $v ) {
				return is_string( $v ) ? $v : '';
			}, $raw );
			$url    = esc_url_raw( trim( $raw['url'] ?? '' ) );
			if ( ! pp_mm_is_url_allowed( $url ) ) {
				continue;
			}
			$freq = $raw['frequency'] ?? 'daily';
			if ( ! in_array( $freq, array( 'hourly', 'daily' ), true ) ) {
				$freq = 'daily';
			}
			if ( $keys === 'encrypted' ) {
				$secret = pp_mm_export_decrypt( $raw['secret_enc'] ?? '', $import_key );
				// Password errata o file alterato: si annulla tutto l'import, nessun sito viene salvato.
				if ( $secret === false ) {
					wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'password', $settings_url ) );
					exit;
				}
			} else {
				$secret = $keys === 'plain' ? ( $raw['secret'] ?? '' ) : '';
			}
			$site = array(
				'label'        => sanitize_text_field( $raw['label'] ?? '' ),
				'url'          => $url,
				'secret'       => pp_mm_encrypt( sanitize_text_field( $secret ) ),
				'frequency'    => $freq,
				'last_check'   => 0,
				'last_status'  => '',
				'last_message' => '',
			);
			// Sito senza chiave: già in KO con la causa, così il cron non manda un alert per ogni sito importato.
			if ( $site['secret'] === '' ) {
				$site['last_status']  = 'error';
				$site['last_message'] = pp_mm_missing_key_message();
				$site['error_since']  = time();
			}
			$imported[] = $site;
		}

		if ( empty( $imported ) ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_import_error', 'empty', $settings_url ) );
			exit;
		}

		if ( $mode === 'replace' ) {
			update_option( PP_MM_OPTION_SITES, $imported );
		} else {
			$existing = get_option( PP_MM_OPTION_SITES, array() );
			update_option( PP_MM_OPTION_SITES, array_merge( $existing, $imported ) );
		}

		wp_safe_redirect( add_query_arg( array( 'pp_mm_imported' => count( $imported ), 'pp_mm_imported_nokeys' => $keys === 'none' ? 1 : null ), admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
		exit;
	}
}

// ─── Pulizia alla disattivazione ──────────────────────────────────────────────

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( PP_MM_CRON_HOOK );
} );

// ─── Pagina: Siti monitorati ──────────────────────────────────────────────────

function pp_mm_render_sites_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'paperplane-mail-test' ) );
	}

	$sites   = get_option( PP_MM_OPTION_SITES, array() );
	$checked = isset( $_GET['pp_mm_checked'] ) ? (int) $_GET['pp_mm_checked'] : -1;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Monitored Sites', 'paperplane-mail-test' ); ?></h1>
		<p><?php printf( __( 'Periodically checks that the mail function works on each site. Requires the %s plugin installed on each monitored site.', 'paperplane-mail-test' ), '<strong>PaperPlane Mail Test Child</strong>' ); ?></p>

		<?php if ( isset( $_GET['pp_mm_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site added.', 'paperplane-mail-test' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['pp_mm_updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site updated.', 'paperplane-mail-test' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['pp_mm_url_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( pp_mm_url_error_message( sanitize_key( $_GET['pp_mm_url_error'] ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['pp_mm_imported_nokeys'] ) ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The sites were imported without secret keys: enter the key of each site with Edit (copy it from Tools → PaperPlane Mail Test on the client site, or generate a new one there).', 'paperplane-mail-test' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['pp_mm_imported'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php printf(
					/* translators: %d number of imported sites */
					__( '%d sites imported successfully.', 'paperplane-mail-test' ),
					(int) $_GET['pp_mm_imported']
				); ?>
			</p></div>
		<?php endif; ?>
		<?php
		// Il notice usa l'indice originale (prima dell'ordinamento)
		if ( $checked >= 0 && isset( $sites[ $checked ] ) ) :
			$s = $sites[ $checked ]; ?>
			<div class="notice notice-<?php echo $s['last_status'] === 'ok' ? 'success' : 'error'; ?> is-dismissible">
				<p>Check <strong><?php echo esc_html( $s['label'] ?: $s['url'] ); ?></strong>:
				<?php echo $s['last_status'] === 'ok' ? '✔ OK' : '✖ KO — ' . esc_html( $s['last_message'] ); ?></p>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Sites', 'paperplane-mail-test' ); ?> <span style="font-weight:normal;color:#646970">(<?php echo count( $sites ); ?>)</span></h2>

		<?php
		// Conserva l'indice originale prima di ordinare, così i form passano
		// sempre l'indice corretto rispetto a wp_options.
		foreach ( $sites as $orig_idx => &$s ) {
			$s['_orig_idx'] = $orig_idx;
		}
		unset( $s );
		if ( ! empty( $sites ) ) {
			usort( $sites, function( $a, $b ) {
				return strcasecmp( $a['label'] ?: $a['url'], $b['label'] ?: $b['url'] );
			} );
		}
		?>
		<?php if ( ! empty( $sites ) ) : ?>
		<style>
			/* Righe alternate per sito (non per <tr>: ogni sito ha anche la riga nascosta del form Modifica). */
			.pp-mm-sites tbody tr.pp-mm-row-even { background: #fff; }
			.pp-mm-sites tbody tr.pp-mm-row-odd { background: #f6f7f7; }
			.pp-mm-sites tbody tr.pp-mm-site > td,
			.pp-mm-sites tbody tr.pp-mm-edit-row > td { border-bottom: 1px solid #dcdcde; }
			/* Form Modifica aperto: il bordo va sotto il form, sito e form restano un unico blocco. */
			.pp-mm-sites tbody tr.pp-mm-site.pp-mm-editing > td { border-bottom: 0; }
		</style>
		<table class="widefat pp-mm-sites" style="margin-bottom:24px">
			<caption class="screen-reader-text"><?php esc_html_e( 'Monitored Sites', 'paperplane-mail-test' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'paperplane-mail-test' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Frequency', 'paperplane-mail-test' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last check', 'paperplane-mail-test' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next check', 'paperplane-mail-test' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'paperplane-mail-test' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'paperplane-mail-test' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$row_n = 0;
			foreach ( $sites as $i => $site ) :
				$row_class  = ( $row_n++ % 2 === 0 ) ? 'pp-mm-row-even' : 'pp-mm-row-odd';
				$site_name  = $site['label'] ?: $site['url'];
				$edit_id    = 'pp-mm-edit-' . (int) $site['_orig_idx'];
				$status     = $site['last_status'] ?? '';
				$last_check = $site['last_check'] ? wp_date( 'Y/m/d H:i', $site['last_check'] ) : '—';
				$color      = $status === 'ok' ? '#007017' : ( $status === 'error' ? '#b32d2e' : '#646970' );
				$label_st   = $status === 'ok' ? '✔ OK' : ( $status === 'error' ? '✖ KO' : '—' );

				$last_ts   = (int) ( $site['last_check'] ?? 0 );
				// Orario indicativo del prossimo check: il cron orario dopo la soglia di pp_mm_is_due().
				$interval  = $site['frequency'] === 'hourly' ? HOUR_IN_SECONDS : 24 * HOUR_IN_SECONDS;
				if ( ! $last_ts ) {
					$next_check = esc_html__( 'Pending', 'paperplane-mail-test' );
				} elseif ( pp_mm_is_due( $site, time() ) ) {
					$next_check = esc_html__( 'Soon', 'paperplane-mail-test' );
				} else {
					$next_check = wp_date( 'Y/m/d H:i', $last_ts + $interval );
				}
			?>
				<tr class="pp-mm-site <?php echo esc_attr( $row_class ); ?>">
					<td>
						<strong><?php echo esc_html( $site['label'] ?: $site['url'] ); ?></strong><br>
						<span style="color:#646970;font-size:.9em"><?php echo esc_html( $site['url'] ); ?></span>
					</td>
					<td><?php echo $site['frequency'] === 'hourly' ? esc_html__( 'Every hour', 'paperplane-mail-test' ) : esc_html__( 'Every day', 'paperplane-mail-test' ); ?></td>
					<td><?php echo esc_html( $last_check ); ?></td>
					<td><?php echo esc_html( $next_check ); ?></td>
					<td style="color:<?php echo $color; ?>;font-weight:bold">
						<?php echo $label_st; ?>
						<?php if ( $status === 'error' ) : ?>
							<?php if ( $site['last_message'] ) : ?>
								<br><span style="font-weight:normal;font-size:.85em"><?php echo esc_html( $site['last_message'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $site['last_detail'] ) ) : ?>
								<br><span style="font-weight:normal;font-size:.8em;color:#646970"><?php echo esc_html( $site['last_detail'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $site['error_since'] ) ) : ?>
								<br><span style="font-weight:normal;font-size:.8em;color:#646970"><?php printf( esc_html__( 'KO since %1$s (%2$s)', 'paperplane-mail-test' ), esc_html( wp_date( 'Y/m/d H:i', (int) $site['error_since'] ) ), esc_html( human_time_diff( (int) $site['error_since'], time() ) ) ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'pp_mm_check' ); ?>
							<input type="hidden" name="pp_mm_action" value="check_now">
							<input type="hidden" name="pp_mm_idx" value="<?php echo (int) $site['_orig_idx']; ?>">
							<button type="submit" class="button button-secondary" aria-label="<?php echo esc_attr( __( 'Check now', 'paperplane-mail-test' ) . ': ' . $site_name ); ?>"><?php esc_html_e( 'Check now', 'paperplane-mail-test' ); ?></button>
						</form>
						&nbsp;
						<button type="button" class="button button-secondary pp-mm-edit-btn" data-row="<?php echo esc_attr( $edit_id ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $edit_id ); ?>" aria-label="<?php echo esc_attr( __( 'Edit', 'paperplane-mail-test' ) . ': ' . $site_name ); ?>"><?php esc_html_e( 'Edit', 'paperplane-mail-test' ); ?></button>
						&nbsp;
						<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this site?', 'paperplane-mail-test' ) ); ?>')">
							<?php wp_nonce_field( 'pp_mm_delete' ); ?>
							<input type="hidden" name="pp_mm_action" value="delete_site">
							<input type="hidden" name="pp_mm_idx" value="<?php echo (int) $site['_orig_idx']; ?>">
							<button type="submit" class="button button-link-delete" aria-label="<?php echo esc_attr( __( 'Remove', 'paperplane-mail-test' ) . ': ' . $site_name ); ?>"><?php esc_html_e( 'Remove', 'paperplane-mail-test' ); ?></button>
						</form>
					</td>
				</tr>
				<tr id="<?php echo esc_attr( $edit_id ); ?>" class="pp-mm-edit-row <?php echo esc_attr( $row_class ); ?>" style="display:none">
					<td colspan="6" style="padding:12px 16px">
						<form method="post" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
							<?php wp_nonce_field( 'pp_mm_edit' ); ?>
							<input type="hidden" name="pp_mm_action" value="edit_site">
							<input type="hidden" name="pp_mm_idx" value="<?php echo (int) $site['_orig_idx']; ?>">
							<label style="display:flex;align-items:center;gap:6px">
								<strong><?php esc_html_e( 'Name / label', 'paperplane-mail-test' ); ?></strong>
								<input type="text" name="pp_mm_label" class="regular-text" value="<?php echo esc_attr( $site['label'] ); ?>">
							</label>
							<label style="display:flex;align-items:center;gap:6px">
								<strong><?php esc_html_e( 'Site URL', 'paperplane-mail-test' ); ?></strong>
								<input type="url" name="pp_mm_url" class="regular-text" value="<?php echo esc_attr( $site['url'] ); ?>" required>
							</label>
							<label style="display:flex;align-items:center;gap:6px">
								<strong><?php esc_html_e( 'New secret key', 'paperplane-mail-test' ); ?></strong>
								<input type="password" name="pp_mm_new_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr__( 'Leave empty to keep the current key', 'paperplane-mail-test' ); ?>">
							</label>
							<label style="display:flex;align-items:center;gap:6px">
								<strong><?php esc_html_e( 'Frequency', 'paperplane-mail-test' ); ?></strong>
								<select name="pp_mm_frequency">
									<option value="daily" <?php selected( $site['frequency'], 'daily' ); ?>><?php esc_html_e( 'Every day', 'paperplane-mail-test' ); ?></option>
									<option value="hourly" <?php selected( $site['frequency'], 'hourly' ); ?>><?php esc_html_e( 'Every hour', 'paperplane-mail-test' ); ?></option>
								</select>
							</label>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'paperplane-mail-test' ); ?></button>
							<button type="submit" name="pp_mm_verify" value="1" class="button button-secondary"><?php esc_html_e( 'Save and verify', 'paperplane-mail-test' ); ?></button>
							<button type="button" class="button button-secondary pp-mm-cancel-btn" data-row="<?php echo esc_attr( $edit_id ); ?>"><?php esc_html_e( 'Cancel', 'paperplane-mail-test' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<script>
		document.querySelectorAll('.pp-mm-edit-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var row = document.getElementById(btn.dataset.row);
				var open = row.style.display === 'none';
				row.style.display = open ? '' : 'none';
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				btn.closest('tr').classList.toggle('pp-mm-editing', open);
				// Focus sul primo campo del form appena aperto (utenti tastiera e screen reader).
				if ( open ) {
					row.querySelector('input:not([type=hidden])').focus();
				}
			});
		});
		document.querySelectorAll('.pp-mm-cancel-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var row     = document.getElementById(btn.dataset.row);
				var editBtn = row.previousElementSibling.querySelector('.pp-mm-edit-btn');
				row.style.display = 'none';
				row.previousElementSibling.classList.remove('pp-mm-editing');
				// Il focus torna al pulsante Modifica che ha aperto il form.
				editBtn.setAttribute('aria-expanded', 'false');
				editBtn.focus();
			});
		});
		</script>
		<?php else : ?>
			<p><?php esc_html_e( 'No sites configured.', 'paperplane-mail-test' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Add site', 'paperplane-mail-test' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'pp_mm_add' ); ?>
			<input type="hidden" name="pp_mm_action" value="add_site">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="pp_mm_label"><?php esc_html_e( 'Name / label', 'paperplane-mail-test' ); ?></label></th>
					<td><input type="text" id="pp_mm_label" name="pp_mm_label" class="regular-text" placeholder="Es. Client Site"></td>
				</tr>
				<tr>
					<th><label for="pp_mm_url"><?php esc_html_e( 'Site URL', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<input type="url" id="pp_mm_url" name="pp_mm_url" class="regular-text" placeholder="https://esempio.it">
						<p class="description"><?php esc_html_e( 'Must start with https://.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_secret"><?php esc_html_e( 'Secret key', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<input type="text" id="pp_mm_secret" name="pp_mm_secret" class="regular-text" placeholder="<?php echo esc_attr__( 'Copy from Tools → Mail Test on the site', 'paperplane-mail-test' ); ?>">
						<p class="description"><?php printf( __( 'Available in %s on the site to monitor.', 'paperplane-mail-test' ), '<strong>Tools → Mail Test</strong>' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_frequency"><?php esc_html_e( 'Check frequency', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<select id="pp_mm_frequency" name="pp_mm_frequency">
							<option value="daily"><?php esc_html_e( 'Every day', 'paperplane-mail-test' ); ?></option>
							<option value="hourly"><?php esc_html_e( 'Every hour', 'paperplane-mail-test' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add site', 'paperplane-mail-test' ); ?></button>
				<button type="submit" name="pp_mm_verify" value="1" class="button button-secondary"><?php esc_html_e( 'Add and verify', 'paperplane-mail-test' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

// ─── Pagina: Impostazioni ─────────────────────────────────────────────────────

function pp_mm_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'paperplane-mail-test' ) );
	}

	$notify         = get_option( PP_MM_OPTION_NOTIFY, '' );
	$silent_test    = get_option( PP_MM_OPTION_SILENT_TEST, '' );
	$weekly_enabled = (int) get_option( PP_MM_OPTION_WEEKLY_ENABLED, 0 );
	$weekly_day     = (int) get_option( PP_MM_OPTION_WEEKLY_DAY, 1 );
	$weekly_hour    = (int) get_option( PP_MM_OPTION_WEEKLY_HOUR, 8 );

	$import_errors = array(
		'upload' => __( 'Upload error. Please try again.', 'paperplane-mail-test' ),
		'size'   => __( 'File too large. Maximum size is 256 KB.', 'paperplane-mail-test' ),
		'type'   => __( 'Invalid file type. Please upload a .json file.', 'paperplane-mail-test' ),
		'format' => __( 'Invalid file format. The file does not appear to be a valid PaperPlane Mail Monitor export.', 'paperplane-mail-test' ),
		'empty'  => __( 'No valid sites found in the file.', 'paperplane-mail-test' ),
		'nopassword' => __( 'This export is password-protected: enter its password to import it.', 'paperplane-mail-test' ),
		'password'   => __( 'Wrong password, or the file has been modified. No sites were imported.', 'paperplane-mail-test' ),
	);
	$import_error_key = sanitize_key( $_GET['pp_mm_import_error'] ?? '' );
	$export_errors    = array(
		'short'    => sprintf( __( 'The export password must be at least %d characters long.', 'paperplane-mail-test' ), PP_MM_EXPORT_MIN_PASSWORD ),
		'mismatch' => __( 'The two passwords do not match.', 'paperplane-mail-test' ),
	);
	$export_error_key = sanitize_key( $_GET['pp_mm_export_error'] ?? '' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mail Monitor — Settings', 'paperplane-mail-test' ); ?></h1>

		<?php if ( $import_error_key && isset( $import_errors[ $import_error_key ] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $import_errors[ $import_error_key ] ); ?></p></div>
		<?php endif; ?>
		<?php if ( $export_error_key && isset( $export_errors[ $export_error_key ] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $export_errors[ $export_error_key ] ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'KO notification email', 'paperplane-mail-test' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'pp_mm_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="pp_mm_notify"><?php esc_html_e( 'Alert recipient', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<input type="text" id="pp_mm_notify"
							name="<?php echo esc_attr( PP_MM_OPTION_NOTIFY ); ?>"
							value="<?php echo esc_attr( $notify ); ?>"
							class="regular-text"
							placeholder="uno@esempio.it, due@esempio.it">
						<p class="description"><?php esc_html_e( 'Multiple addresses separated by comma.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_silent_test"><?php esc_html_e( 'Silent test address', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<input type="email" id="pp_mm_silent_test"
							name="<?php echo esc_attr( PP_MM_OPTION_SILENT_TEST ); ?>"
							value="<?php echo esc_attr( $silent_test ); ?>"
							class="regular-text"
							placeholder="paperplane-mail-test@example.com">
						<p class="description"><?php esc_html_e( 'Optional. Address that receives automatic cron test emails. If empty, each monitored site sends the test to its own admin email (Settings → General). A dedicated mailbox with a delete-all rule is recommended to avoid inbox noise.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Weekly report', 'paperplane-mail-test' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable', 'paperplane-mail-test' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( PP_MM_OPTION_WEEKLY_ENABLED ); ?>" value="1" <?php checked( $weekly_enabled, 1 ); ?>>
							<?php esc_html_e( 'Send a weekly summary email to alert recipients', 'paperplane-mail-test' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_weekly_day"><?php esc_html_e( 'Day of the week', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<select id="pp_mm_weekly_day" name="<?php echo esc_attr( PP_MM_OPTION_WEEKLY_DAY ); ?>">
							<?php
							$days = array(
								1 => __( 'Monday', 'paperplane-mail-test' ),
								2 => __( 'Tuesday', 'paperplane-mail-test' ),
								3 => __( 'Wednesday', 'paperplane-mail-test' ),
								4 => __( 'Thursday', 'paperplane-mail-test' ),
								5 => __( 'Friday', 'paperplane-mail-test' ),
								6 => __( 'Saturday', 'paperplane-mail-test' ),
								7 => __( 'Sunday', 'paperplane-mail-test' ),
							);
							foreach ( $days as $num => $name ) :
							?>
								<option value="<?php echo $num; ?>" <?php selected( $weekly_day, $num ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_weekly_hour"><?php esc_html_e( 'Time', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<select id="pp_mm_weekly_hour" name="<?php echo esc_attr( PP_MM_OPTION_WEEKLY_HOUR ); ?>">
							<?php for ( $h = 0; $h <= 23; $h++ ) : ?>
								<option value="<?php echo $h; ?>" <?php selected( $weekly_hour, $h ); ?>><?php echo sprintf( '%02d:00', $h ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Based on the timezone set in Settings → General.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save', 'paperplane-mail-test' ) ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Export / Import', 'paperplane-mail-test' ); ?></h2>
		<p><?php esc_html_e( 'Use export and import to move the list of monitored sites to another installation.', 'paperplane-mail-test' ); ?></p>

		<h3><?php esc_html_e( 'Export', 'paperplane-mail-test' ); ?></h3>
		<p><?php esc_html_e( 'Downloads a JSON file with all monitored sites. Secret keys are never exported in clear.', 'paperplane-mail-test' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'pp_mm_export' ); ?>
			<input type="hidden" name="pp_mm_action" value="export_sites">
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Secret keys', 'paperplane-mail-test' ); ?></th>
					<td>
						<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Secret keys', 'paperplane-mail-test' ); ?></legend>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="pp_mm_export_mode" value="password" checked>
							<?php esc_html_e( 'Protect with a password (recommended)', 'paperplane-mail-test' ); ?>
						</label>
						<label>
							<input type="radio" name="pp_mm_export_mode" value="nokeys">
							<?php esc_html_e( 'Do not export the keys', 'paperplane-mail-test' ); ?>
						</label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Without keys, after importing you will have to enter the key of each site with Edit.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_export_password"><?php esc_html_e( 'Password', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<input type="password" id="pp_mm_export_password" name="pp_mm_export_password" class="regular-text" autocomplete="new-password">
						<p class="description"><?php printf( esc_html__( 'At least %d characters. It will be asked when importing: keep it separate from the file (for example in a password manager), it cannot be recovered.', 'paperplane-mail-test' ), PP_MM_EXPORT_MIN_PASSWORD ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pp_mm_export_password2"><?php esc_html_e( 'Confirm password', 'paperplane-mail-test' ); ?></label></th>
					<td><input type="password" id="pp_mm_export_password2" name="pp_mm_export_password2" class="regular-text" autocomplete="new-password"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Download export file', 'paperplane-mail-test' ), 'secondary' ); ?>
		</form>

		<h3><?php esc_html_e( 'Import', 'paperplane-mail-test' ); ?></h3>
		<p><?php esc_html_e( 'Upload an export file generated by this plugin. The secret keys in the file must still be the ones active on the respective client sites.', 'paperplane-mail-test' ); ?></p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'pp_mm_import' ); ?>
			<input type="hidden" name="pp_mm_action" value="import_sites">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="pp_mm_import_file"><?php esc_html_e( 'Export file (.json)', 'paperplane-mail-test' ); ?></label></th>
					<td><input type="file" id="pp_mm_import_file" name="pp_mm_import_file" accept=".json"></td>
				</tr>
				<tr>
					<th><label for="pp_mm_import_password"><?php esc_html_e( 'Export password', 'paperplane-mail-test' ); ?></label></th>
					<td>
						<input type="password" id="pp_mm_import_password" name="pp_mm_import_password" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Only for password-protected exports.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Import mode', 'paperplane-mail-test' ); ?></th>
					<td>
						<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Import mode', 'paperplane-mail-test' ); ?></legend>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="pp_mm_import_mode" value="replace" checked>
							<?php esc_html_e( 'Replace all existing sites', 'paperplane-mail-test' ); ?>
						</label>
						<label>
							<input type="radio" name="pp_mm_import_mode" value="merge">
							<?php esc_html_e( 'Add to existing sites', 'paperplane-mail-test' ); ?>
						</label>
						</fieldset>
						<p class="description" style="color:#b32d2e"><?php esc_html_e( '"Replace" will permanently delete all currently monitored sites.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Import', 'paperplane-mail-test' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}
