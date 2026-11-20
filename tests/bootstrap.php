<?php
/**
 * Carica il plugin senza WordPress: costanti e funzioni WP minime usate a livello di file
 * e dalle funzioni testate. Per mock per-test di funzioni WP aggiungere Brain Monkey.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'AUTH_KEY', 'test-auth-key' );

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}

function __( $text ) {
	return $text;
}

function sanitize_email( $email ) {
	return trim( $email );
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

require_once dirname( __DIR__ ) . '/paperplane-mail-test.php';
