<?php
/**
 * Tests for the at-rest encryption envelope.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Crypto;
use PHPUnit\Framework\TestCase;

/** Covers round-tripping, envelope detection, and every way opening can fail. */
final class CryptoTest extends TestCase {

	private const MATERIAL = 'some-secure-auth-key-and-salt';

	public function test_round_trips_a_value(): void {
		$sealed = Crypto::encrypt( 'my-api-key', self::MATERIAL );

		$this->assertSame( 'my-api-key', Crypto::decrypt( $sealed, self::MATERIAL ) );
	}

	public function test_the_sealed_value_does_not_contain_the_plaintext(): void {
		$sealed = Crypto::encrypt( 'my-api-key', self::MATERIAL );

		$this->assertStringNotContainsString( 'my-api-key', $sealed );
	}

	public function test_sealed_values_carry_the_versioned_prefix(): void {
		$sealed = Crypto::encrypt( 'my-api-key', self::MATERIAL );

		$this->assertStringStartsWith( 'wynko:v1:', $sealed );
		$this->assertTrue( Crypto::is_envelope( $sealed ) );
	}

	public function test_plaintext_is_not_an_envelope(): void {
		$this->assertFalse( Crypto::is_envelope( 'my-api-key' ) );
		$this->assertFalse( Crypto::is_envelope( '' ) );
	}

	public function test_the_nonce_is_not_reused(): void {
		$this->assertNotSame(
			Crypto::encrypt( 'my-api-key', self::MATERIAL ),
			Crypto::encrypt( 'my-api-key', self::MATERIAL )
		);
	}

	public function test_decrypting_with_different_material_returns_null(): void {
		$sealed = Crypto::encrypt( 'my-api-key', self::MATERIAL );

		$this->assertNull( Crypto::decrypt( $sealed, 'rotated-material' ) );
	}

	public function test_a_tampered_ciphertext_returns_null(): void {
		$sealed = Crypto::encrypt( 'my-api-key', self::MATERIAL );
		$body   = substr( $sealed, strlen( Crypto::PREFIX ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reaches into the envelope to corrupt one byte of ciphertext.
		$raw     = base64_decode( $body, true );
		$raw[10] = ( "\x00" === $raw[10] ) ? "\x01" : "\x00";

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Re-wraps the corrupted bytes in the envelope encoding.
		$this->assertNull( Crypto::decrypt( Crypto::PREFIX . base64_encode( $raw ), self::MATERIAL ) );
	}

	public function test_a_truncated_payload_returns_null(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds an envelope whose payload is too short to hold a nonce.
		$this->assertNull( Crypto::decrypt( Crypto::PREFIX . base64_encode( 'short' ), self::MATERIAL ) );
	}

	public function test_a_payload_that_is_not_base64_returns_null(): void {
		$this->assertNull( Crypto::decrypt( Crypto::PREFIX . '!!! not base64 !!!', self::MATERIAL ) );
	}

	public function test_decrypting_a_non_envelope_returns_null(): void {
		$this->assertNull( Crypto::decrypt( 'my-api-key', self::MATERIAL ) );
	}

	public function test_encrypting_without_material_returns_the_plaintext(): void {
		$this->assertSame( 'my-api-key', Crypto::encrypt( 'my-api-key', '' ) );
	}

	public function test_encrypting_an_empty_value_returns_it_unchanged(): void {
		$this->assertSame( '', Crypto::encrypt( '', self::MATERIAL ) );
	}

	public function test_libsodium_is_available_on_this_php(): void {
		$this->assertTrue( Crypto::available(), 'libsodium is core in PHP 7.2+; the plugin requires 8.0+.' );
	}
}
