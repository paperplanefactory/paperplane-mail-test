<?php

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase {

	public function test_encrypt_round_trip(): void {
		$enc = pp_mm_encrypt( 'segreto' );
		$this->assertStringStartsWith( 'enc1:', $enc );
		$this->assertNotSame( pp_mm_encrypt( 'segreto' ), $enc, 'IV casuale' );
		$this->assertSame( 'segreto', pp_mm_decrypt( $enc ) );
	}

	public function test_decrypt_passes_legacy_plaintext_and_rejects_garbage(): void {
		$this->assertSame( 'legacy', pp_mm_decrypt( 'legacy' ) );
		$this->assertSame( '', pp_mm_encrypt( '' ) );
		$this->assertSame( '', pp_mm_decrypt( 'enc1:' . base64_encode( 'short' ) ) );
	}

	public function test_export_encrypt_round_trip_and_tamper_detection(): void {
		$key  = pp_mm_export_key( 'password-lunga', 'salt', 1000 );
		$blob = pp_mm_export_encrypt( 'dati', $key );

		$this->assertSame( 'dati', pp_mm_export_decrypt( $blob, $key ) );
		$this->assertFalse( pp_mm_export_decrypt( $blob, pp_mm_export_key( 'sbagliata', 'salt', 1000 ) ) );

		$raw     = base64_decode( $blob );
		$raw[-1] = chr( ord( $raw[-1] ) ^ 1 );
		$this->assertFalse( pp_mm_export_decrypt( base64_encode( $raw ), $key ) );
		$this->assertFalse( pp_mm_export_decrypt( 'non base64 !', $key ) );
		$this->assertFalse( pp_mm_export_decrypt( base64_encode( 'corto' ), $key ) );
	}

	public function test_is_due_thresholds(): void {
		$now = 1000000;

		$ko = array(
			'last_status' => 'error',
			'last_check'  => $now - 10 * 60,
		);
		$this->assertTrue( pp_mm_is_due( $ko, $now ) );
		$this->assertFalse( pp_mm_is_due( $ko, $now - 1 ) );

		$hourly = array(
			'frequency'  => 'hourly',
			'last_check' => $now - 55 * 60,
		);
		$this->assertTrue( pp_mm_is_due( $hourly, $now ) );
		$this->assertFalse( pp_mm_is_due( $hourly, $now - 1 ) );

		$daily = array( 'last_check' => $now - ( 86400 - 5 * 60 ) );
		$this->assertTrue( pp_mm_is_due( $daily, $now ) );
		$this->assertFalse( pp_mm_is_due( $daily, $now - 1 ) );

		$this->assertTrue( pp_mm_is_due( array(), $now ), 'mai controllato' );
	}

	public function test_parse_client_emails(): void {
		$parsed = pp_mm_parse_client_emails( ' a@x.it, A@X.it , no-email, ,b@x.it,c@x.it,d@x.it,e@x.it,f@x.it' );

		$this->assertSame( array( 'a@x.it', 'b@x.it', 'c@x.it', 'd@x.it', 'e@x.it' ), $parsed['valid'] );
		$this->assertSame( 2, $parsed['skipped'] ); // no-email + f@x.it oltre il limite.
	}

	public function test_client_detail_hides_email_addresses(): void {
		$this->assertSame( '550 rejected […]', pp_mm_client_detail( ' 550 rejected test@example.com ' ) );
	}

	public function test_sanitize_client_locale(): void {
		$this->assertSame( 'en_US', pp_mm_sanitize_client_locale( 'en_US' ) );
		$this->assertSame( 'it_IT', pp_mm_sanitize_client_locale( 'de_DE' ) );
	}

	public function test_signature_payload_matches_child_format(): void {
		$this->assertSame( "pp-mail-test-v1\n123\nn\nt\nx@y.it", pp_mm_signature_payload( '123', 'n', 't', 'x@y.it' ) );
	}
}
