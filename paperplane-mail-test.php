<?php
/**
 * Plugin Name: PaperPlane Mail Test
 * Description: Monitors mail function on client sites. Requires PaperPlane Mail Test Child installed on each site.
 * Version: 1.0.9
 * Author: Paper Plane Factory
 * Text Domain: paperplane-mail-test
 * Domain Path: /languages
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
	if ( ! $salt ) {
		$salt = get_option( 'siteurl', 'pp_mm_fallback' );
	}
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
	$parsed = wp_parse_url( $url );
	if ( ! $parsed || ( $parsed['scheme'] ?? '' ) !== 'https' ) {
		return false;
	}
	$host = $parsed['host'] ?? '';
	if ( ! $host ) {
		return false;
	}
	if ( in_array( strtolower( $host ), array( 'localhost', '::1' ), true ) ) {
		return false;
	}
	$ip = gethostbyname( $host );
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
		return false;
	}
	return true;
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

	$now     = time();
	$updated = false;

	foreach ( $sites as $i => &$site ) {
		$freq       = $site['frequency'] ?? 'daily';
		$last_check = (int) ( $site['last_check'] ?? 0 );

		$is_due = false;
		if ( $freq === 'hourly' ) {
			$is_due = ( $now - $last_check ) >= HOUR_IN_SECONDS;
		} else {
			$is_due = ( $now - $last_check ) >= 23 * HOUR_IN_SECONDS;
		}

		if ( ! $is_due ) {
			continue;
		}

		$silent_test = get_option( PP_MM_OPTION_SILENT_TEST, '' );
		$result      = pp_mm_check_site( $site, $silent_test );

		$prev_status          = $site['last_status'] ?? '';
		$site['last_check']   = $now;
		$site['last_status']  = $result['success'] ? 'ok' : 'error';
		$site['last_message'] = $result['message'] ?? '';
		$updated              = true;

		// Notifica solo al passaggio da OK a KO (o primo KO)
		if ( ! $result['success'] && $prev_status !== 'error' ) {
			pp_mm_send_alert( $site, $result['message'] );
		}
	}
	unset( $site );

	if ( $updated ) {
		update_option( PP_MM_OPTION_SITES, $sites );
	}

	pp_mm_maybe_send_weekly_report();
}

/**
 * Chiama l'endpoint del sito e restituisce array ['success', 'message'].
 */
function pp_mm_check_site( array $site, string $test_email = '' ) {
	$url    = trailingslashit( $site['url'] ) . 'wp-json/pp-mail-test/v1/check';
	$secret = pp_mm_decrypt( $site['secret'] ?? '' );

	$body = array( 'pp_secret' => $secret );
	if ( $test_email ) {
		$body['test_email'] = $test_email;
	}
	$response = wp_remote_post( $url, array(
		'timeout' => 15,
		'body'    => $body,
	) );

	if ( is_wp_error( $response ) ) {
		return array( 'success' => false, 'message' => $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		return array( 'success' => false, 'message' => 'HTTP ' . $code );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return array( 'success' => false, 'message' => __( 'Invalid response', 'paperplane-mail-test' ) );
	}

	return array(
		'success' => ! empty( $body['success'] ),
		'message' => $body['message'] ?? '',
	);
}

function pp_mm_send_alert( array $site, string $message ) {
	$notify = get_option( PP_MM_OPTION_NOTIFY, '' );
	if ( ! $notify ) {
		return;
	}
	$recipients = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $notify ) ) ) );
	if ( empty( $recipients ) ) {
		return;
	}
	$label   = $site['label'] ?: $site['url'];
	$subject = sprintf( __( '[Assistance] Mail KO: %s', 'paperplane-mail-test' ), $label );
	$body    = '<p>' . sprintf( __( 'The mail function test on %s failed.', 'paperplane-mail-test' ), '<strong>' . esc_html( $label ) . '</strong>' ) . '</p>'
		. '<p><strong>' . __( 'URL:', 'paperplane-mail-test' ) . '</strong> ' . esc_html( $site['url'] ) . '</p>'
		. '<p><strong>' . __( 'Error:', 'paperplane-mail-test' ) . '</strong> ' . esc_html( $message ) . '</p>'
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
			$status_html = '<span style="color:#46b450;font-weight:bold">&#10004; OK</span>';
		} elseif ( $status === 'error' ) {
			$ko_count++;
			$status_html = '<span style="color:#d63638;font-weight:bold">&#10006; KO</span>';
			if ( $site['last_message'] ) {
				$status_html .= '<br><span style="font-size:.9em;font-weight:normal">' . esc_html( $site['last_message'] ) . '</span>';
			}
		} else {
			$status_html = '<span style="color:#999">—</span>';
		}

		$rows .= '<tr>'
			. '<td style="padding:8px 12px;border-bottom:1px solid #eee"><strong>' . esc_html( $label ) . '</strong><br>'
			. '<span style="color:#999;font-size:.9em">' . esc_html( $site['url'] ) . '</span></td>'
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
		__( '[Weekly Report] PaperPlane Mail Monitor — %s', 'paperplane-mail-test' ),
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
		. '<p style="color:#999;font-size:.85em;margin-top:16px">' . esc_html( wp_date( 'Y/m/d H:i' ) ) . '</p>'
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

// ─── Gestione azioni POST ─────────────────────────────────────────────────────

add_action( 'admin_init', 'pp_mm_handle_actions' );

function pp_mm_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$action = $_POST['pp_mm_action'] ?? $_GET['pp_mm_action'] ?? '';

	// ── Aggiungi sito ──────────────────────────────────────────────────────────
	if ( $action === 'add_site' && check_admin_referer( 'pp_mm_add' ) ) {
		$raw_url = trim( $_POST['pp_mm_url'] ?? '' );
		if ( ! pp_mm_is_url_allowed( $raw_url ) ) {
			wp_safe_redirect( add_query_arg( 'pp_mm_url_error', '1', admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
			exit;
		}
		$sites   = get_option( PP_MM_OPTION_SITES, array() );
		$sites[] = array(
			'label'        => sanitize_text_field( $_POST['pp_mm_label'] ?? '' ),
			'url'          => esc_url_raw( $raw_url ),
			'secret'       => pp_mm_encrypt( sanitize_text_field( $_POST['pp_mm_secret'] ?? '' ) ),
			'frequency'    => in_array( $_POST['pp_mm_frequency'] ?? '', array( 'hourly', 'daily' ), true )
				? $_POST['pp_mm_frequency']
				: 'daily',
			'last_check'   => 0,
			'last_status'  => '',
			'last_message' => '',
		);
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
			$result                        = pp_mm_check_site( $sites[ $idx ], get_option( PP_MM_OPTION_NOTIFY, '' ) );
			$prev_status                   = $sites[ $idx ]['last_status'] ?? '';
			$sites[ $idx ]['last_check']   = time();
			$sites[ $idx ]['last_status']  = $result['success'] ? 'ok' : 'error';
			$sites[ $idx ]['last_message'] = $result['message'] ?? '';
			if ( ! $result['success'] && $prev_status !== 'error' ) {
				pp_mm_send_alert( $sites[ $idx ], $result['message'] );
			}
			update_option( PP_MM_OPTION_SITES, $sites );
		}
		wp_safe_redirect( add_query_arg( 'pp_mm_checked', $idx, admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
		exit;
	}

	// ── Export siti ────────────────────────────────────────────────────────────
	if ( $action === 'export_sites' && check_admin_referer( 'pp_mm_export' ) ) {
		$sites        = get_option( PP_MM_OPTION_SITES, array() );
		$export_sites = array_map( function( $site ) {
			return array(
				'label'     => $site['label'] ?? '',
				'url'       => $site['url'] ?? '',
				'secret'    => pp_mm_decrypt( $site['secret'] ?? '' ),
				'frequency' => $site['frequency'] ?? 'daily',
			);
		}, $sites );

		$export = array(
			'version'     => '1.0',
			'exported_at' => wp_date( 'c' ),
			'plugin'      => 'paperplane-mail-test',
			'sites'       => $export_sites,
		);

		$filename = 'pp-mail-monitor-export-' . wp_date( 'Y-m-d' ) . '.json';
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

		foreach ( $data['sites'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$url    = esc_url_raw( trim( $raw['url'] ?? '' ) );
			$parsed = wp_parse_url( $url );
			if ( ! $parsed || ( $parsed['scheme'] ?? '' ) !== 'https' || empty( $parsed['host'] ) ) {
				continue;
			}
			$freq = $raw['frequency'] ?? 'daily';
			if ( ! in_array( $freq, array( 'hourly', 'daily' ), true ) ) {
				$freq = 'daily';
			}
			$imported[] = array(
				'label'        => sanitize_text_field( $raw['label'] ?? '' ),
				'url'          => $url,
				'secret'       => pp_mm_encrypt( sanitize_text_field( $raw['secret'] ?? '' ) ),
				'frequency'    => $freq,
				'last_check'   => 0,
				'last_status'  => '',
				'last_message' => '',
			);
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

		wp_safe_redirect( add_query_arg( 'pp_mm_imported', count( $imported ), admin_url( 'admin.php?page=pp-mail-monitor' ) ) );
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
		<?php if ( isset( $_GET['pp_mm_url_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid URL. Only https:// addresses pointing to public sites are accepted.', 'paperplane-mail-test' ); ?></p></div>
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

		<h2><?php esc_html_e( 'Sites', 'paperplane-mail-test' ); ?></h2>

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
		<table class="widefat striped" style="margin-bottom:24px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'paperplane-mail-test' ); ?></th>
					<th><?php esc_html_e( 'Frequency', 'paperplane-mail-test' ); ?></th>
					<th><?php esc_html_e( 'Last check', 'paperplane-mail-test' ); ?></th>
					<th><?php esc_html_e( 'Next check', 'paperplane-mail-test' ); ?></th>
					<th><?php esc_html_e( 'Status', 'paperplane-mail-test' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'paperplane-mail-test' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $sites as $i => $site ) :
				$status     = $site['last_status'] ?? '';
				$last_check = $site['last_check'] ? wp_date( 'Y/m/d H:i', $site['last_check'] ) : '—';
				$color      = $status === 'ok' ? '#46b450' : ( $status === 'error' ? '#d63638' : '#999' );
				$label_st   = $status === 'ok' ? '✔ OK' : ( $status === 'error' ? '✖ KO' : '—' );

				$last_ts   = (int) ( $site['last_check'] ?? 0 );
				$interval  = $site['frequency'] === 'hourly' ? HOUR_IN_SECONDS : 23 * HOUR_IN_SECONDS;
				if ( ! $last_ts ) {
					$next_check = esc_html__( 'Pending', 'paperplane-mail-test' );
				} elseif ( $last_ts + $interval <= time() ) {
					$next_check = esc_html__( 'Soon', 'paperplane-mail-test' );
				} else {
					$next_check = wp_date( 'Y/m/d H:i', $last_ts + $interval );
				}
			?>
				<tr>
					<td>
						<strong><?php echo esc_html( $site['label'] ?: $site['url'] ); ?></strong><br>
						<span style="color:#999;font-size:.9em"><?php echo esc_html( $site['url'] ); ?></span>
					</td>
					<td><?php echo $site['frequency'] === 'hourly' ? esc_html__( 'Every hour', 'paperplane-mail-test' ) : esc_html__( 'Every day', 'paperplane-mail-test' ); ?></td>
					<td><?php echo esc_html( $last_check ); ?></td>
					<td><?php echo esc_html( $next_check ); ?></td>
					<td style="color:<?php echo $color; ?>;font-weight:bold">
						<?php echo $label_st; ?>
						<?php if ( $status === 'error' && $site['last_message'] ) : ?>
							<br><span style="font-weight:normal;font-size:.85em"><?php echo esc_html( $site['last_message'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'pp_mm_check' ); ?>
							<input type="hidden" name="pp_mm_action" value="check_now">
							<input type="hidden" name="pp_mm_idx" value="<?php echo (int) $site['_orig_idx']; ?>">
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Check now', 'paperplane-mail-test' ); ?></button>
						</form>
						&nbsp;
						<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this site?', 'paperplane-mail-test' ) ); ?>')">
							<?php wp_nonce_field( 'pp_mm_delete' ); ?>
							<input type="hidden" name="pp_mm_action" value="delete_site">
							<input type="hidden" name="pp_mm_idx" value="<?php echo (int) $site['_orig_idx']; ?>">
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove', 'paperplane-mail-test' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
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
			<?php submit_button( __( 'Add site', 'paperplane-mail-test' ) ); ?>
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
	);
	$import_error_key = sanitize_key( $_GET['pp_mm_import_error'] ?? '' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mail Monitor — Settings', 'paperplane-mail-test' ); ?></h1>

		<?php if ( $import_error_key && isset( $import_errors[ $import_error_key ] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $import_errors[ $import_error_key ] ); ?></p></div>
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
						<p class="description"><?php esc_html_e( 'Address that receives automatic cron test emails. Use a dedicated mailbox with a delete-all rule — this prevents inbox noise while keeping wp_mail() fully tested on each site.', 'paperplane-mail-test' ); ?></p>
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
		<p><?php esc_html_e( 'Downloads a JSON file with all monitored sites and their secret keys. Keep this file safe.', 'paperplane-mail-test' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'pp_mm_export' ); ?>
			<input type="hidden" name="pp_mm_action" value="export_sites">
			<?php submit_button( __( 'Download export file', 'paperplane-mail-test' ), 'secondary' ); ?>
		</form>

		<h3><?php esc_html_e( 'Import', 'paperplane-mail-test' ); ?></h3>
		<p><?php esc_html_e( 'Upload an export file generated by this plugin. The secret keys in the file must already be configured on the respective client sites.', 'paperplane-mail-test' ); ?></p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'pp_mm_import' ); ?>
			<input type="hidden" name="pp_mm_action" value="import_sites">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="pp_mm_import_file"><?php esc_html_e( 'Export file (.json)', 'paperplane-mail-test' ); ?></label></th>
					<td><input type="file" id="pp_mm_import_file" name="pp_mm_import_file" accept=".json"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Import mode', 'paperplane-mail-test' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="pp_mm_import_mode" value="replace" checked>
							<?php esc_html_e( 'Replace all existing sites', 'paperplane-mail-test' ); ?>
						</label>
						<label>
							<input type="radio" name="pp_mm_import_mode" value="merge">
							<?php esc_html_e( 'Add to existing sites', 'paperplane-mail-test' ); ?>
						</label>
						<p class="description" style="color:#d63638"><?php esc_html_e( '"Replace" will permanently delete all currently monitored sites.', 'paperplane-mail-test' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Import', 'paperplane-mail-test' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}
