<?php
/**
 * Authenticated encryption for values stored in the database.
 *
 * @package Wynko
 */

namespace Wynko\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-free authenticated encryption, with the key material supplied
 * by the caller. Sealed values carry a version prefix so the algorithm can be
 * changed without guessing at what is already stored.
 *
 * This protects a value against database-only exposure, not against anyone who
 * can read wp-config.php.
 */
final class Crypto {

	const PREFIX = 'wynko:v1:';

	/**
	 * Whether libsodium's secretbox is available (core since PHP 7.2).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Whether a stored value is one of ours rather than a plaintext secret.
	 *
	 * @param string $stored Value read from storage.
	 * @return bool
	 */
	public static function is_envelope( string $stored ): bool {
		return 0 === strncmp( $stored, self::PREFIX, strlen( self::PREFIX ) );
	}

	/**
	 * Derives a secretbox key from arbitrary-length material.
	 *
	 * @param string $material Caller-supplied secret.
	 * @return string 32 raw bytes.
	 */
	private static function derive( string $material ): string {
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Seals a value. Returns the plaintext unchanged when sealing is
	 * impossible — storing the secret the way it is stored today beats
	 * refusing to store it at all.
	 *
	 * @param string $plaintext Value to seal.
	 * @param string $material  Caller-supplied secret; '' disables sealing.
	 * @return string
	 */
	public static function encrypt( string $plaintext, string $material ): string {
		if ( '' === $plaintext || '' === $material || ! self::available() ) {
			return $plaintext;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$boxed = sodium_crypto_secretbox( $plaintext, $nonce, self::derive( $material ) );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext needs a text-safe encoding to survive wp_options, not obfuscation.
		return self::PREFIX . base64_encode( $nonce . $boxed );
	}

	/**
	 * Opens a sealed value, returning null on wrong material, truncation,
	 * tampering, or a value that was never sealed. Callers must treat null as "no
	 * value", never as the value itself.
	 *
	 * @param string $stored   Value read from storage.
	 * @param string $material Caller-supplied secret.
	 * @return string|null
	 */
	public static function decrypt( string $stored, string $material ): ?string {
		if ( ! self::is_envelope( $stored ) || '' === $material || ! self::available() ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverses the storage encoding applied in encrypt().
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $raw ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$opened = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::derive( $material )
		);

		return false === $opened ? null : $opened;
	}
}
