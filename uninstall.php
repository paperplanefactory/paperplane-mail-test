<?php
// Eseguito da WordPress quando il plugin viene eliminato dalla dashboard.
// Non viene eseguito alla semplice disattivazione.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Tabelle dei siti e dello storico (dalla 1.2.0). Nome fisso costruito dal prefisso di WordPress.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pp_mm_sites' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pp_mm_checks' );

foreach ( array(
	'pp_mm_sites',
	'pp_mm_sites_backup_v1',
	'pp_mm_notify_email',
	'pp_mm_weekly_enabled',
	'pp_mm_weekly_day',
	'pp_mm_weekly_hour',
	'pp_mm_weekly_last_sent',
	'pp_mm_silent_test_email',
	'pp_mm_secrets_encrypted',
	'pp_mm_db_version',
	'pp_mm_db_upgrading',
	'pp_mm_cron_lock',
	'pp_mm_last_cron_run',
	'pp_mm_history_purged',
) as $option ) {
	delete_option( $option );
}
wp_clear_scheduled_hook( 'pp_mm_run_checks' );
