<?php
// Eseguito da WordPress quando il plugin viene eliminato dalla dashboard.
// Non viene eseguito alla semplice disattivazione.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pp_mm_sites' );
delete_option( 'pp_mm_notify_email' );
delete_option( 'pp_mm_weekly_enabled' );
delete_option( 'pp_mm_weekly_day' );
delete_option( 'pp_mm_weekly_hour' );
delete_option( 'pp_mm_weekly_last_sent' );
