<?php
/**
 * Verifica che vendor/ versionato contenga solo le dipendenze di produzione.
 *
 * vendor/ è nel repository perché il plugin usa plugin-update-checker a runtime.
 * Un "composer install" senza --no-dev aggiunge i pacchetti di sviluppo (PHPUnit, PHPStan...)
 * e riscrive vendor/composer/autoload_*.php: se finissero in un commit, in produzione
 * l'autoloader cercherebbe file che non sono nello zip della release.
 *
 * Uso (dalla root del plugin, PRIMA di composer install): php .github/scripts/check-vendor.php
 * Se fallisce: composer install --no-dev, poi ricontrolla git status.
 */

$installed = require __DIR__ . '/../../vendor/composer/installed.php';
$composer  = json_decode( (string) file_get_contents( __DIR__ . '/../../composer.json' ), true );

// Pacchetti richiesti in produzione (esclusi i requisiti di piattaforma: php, ext-*).
$required = array_filter(
	array_keys( $composer['require'] ?? array() ),
	static fn( $name ) => $name !== 'php' && ! str_starts_with( $name, 'ext-' )
);
$present  = array_diff( array_keys( $installed['versions'] ?? array() ), array( $installed['root']['name'] ?? '__root__' ) );

$errors = array();
if ( ( $installed['root']['dev'] ?? null ) !== false ) {
	$errors[] = 'vendor/ was built in dev mode.';
}
$extra = array_diff( $present, $required );
if ( $extra ) {
	$errors[] = 'Unexpected packages in vendor/: ' . implode( ', ', $extra ) . '.';
}
$missing = array_diff( $required, $present );
if ( $missing ) {
	$errors[] = 'Missing packages in vendor/: ' . implode( ', ', $missing ) . '.';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\nRun `composer install --no-dev` and commit vendor/ again.\n" );
	exit( 1 );
}
echo "vendor/ OK: production dependencies only (" . implode( ', ', $present ) . ").\n";
