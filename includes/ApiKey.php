<?php
/**
 * Laposta API key resolution.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Support\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the Laposta API key, preferring an environment variable or a
 * wp-config.php constant over the stored option so the secret can stay out of
 * the database. The per-blog name keeps that workable on multisite, where each
 * site has its own Laposta account.
 *
 * Precedence, highest first:
 *   env WYNKO_API_KEY_{blog_id} -> env WYNKO_API_KEY
 *   -> const WYNKO_API_KEY_{blog_id} -> const WYNKO_API_KEY -> option.
 */
final class ApiKey {

	const NETWORK_CONSTANT = 'WYNKO_API_KEY';

	/** Text WordPress ships in an ungenerated wp-config.php; not a secret. */
	const SALT_PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Resolves the key and where it came from, highest-precedence source first.
	 * An empty or whitespace-only value counts as absent at every tier.
	 *
	 * @return array{source:string,name:string,key:string}
	 */
	private static function resolved(): array {
		$names = array(
			self::NETWORK_CONSTANT . '_' . get_current_blog_id(),
			self::NETWORK_CONSTANT,
		);

		foreach ( $names as $name ) {
			$value = trim( (string) getenv( $name ) );
			if ( '' !== $value ) {
				return array(
					'source' => 'env',
					'name'   => $name,
					'key'    => $value,
				);
			}
		}

		foreach ( $names as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}
			$value = trim( (string) constant( $name ) );
			if ( '' !== $value ) {
				return array(
					'source' => 'constant',
					'name'   => $name,
					'key'    => $value,
				);
			}
		}

		$stored = self::stored();
		return array(
			'source' => '' === $stored ? 'none' : 'option',
			'name'   => '',
			'key'    => $stored,
		);
	}

	/**
	 * Returns the name of the defined key constant, or '' when none is set or
	 * an environment variable outranks it.
	 *
	 * @return string
	 */
	public static function constant_name(): string {
		$resolved = self::resolved();
		return 'constant' === $resolved['source'] ? $resolved['name'] : '';
	}

	/**
	 * Returns the name of the environment variable or constant supplying the
	 * key, or '' when it comes from the database or is unset.
	 *
	 * @return string
	 */
	public static function source_name(): string {
		return self::resolved()['name'];
	}

	/**
	 * Returns the material used to seal the stored key, or '' when the site
	 * has no usable salts. SECURE_AUTH_* is preferred; AUTH_* is the fallback.
	 *
	 * @return string
	 */
	public static function key_material(): string {
		$pairs = array(
			array( 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ),
			array( 'AUTH_KEY', 'AUTH_SALT' ),
		);

		foreach ( $pairs as $pair ) {
			if ( ! defined( $pair[0] ) || ! defined( $pair[1] ) ) {
				continue;
			}
			$material = (string) constant( $pair[0] ) . (string) constant( $pair[1] );
			if ( '' !== trim( $material ) && false === strpos( $material, self::SALT_PLACEHOLDER ) ) {
				return $material;
			}
		}

		return '';
	}

	/**
	 * Returns the value to persist for a key: sealed when this site can seal,
	 * plaintext when it cannot.
	 *
	 * @param string $plaintext Raw API key.
	 * @return string
	 */
	public static function store( string $plaintext ): string {
		return Crypto::encrypt( trim( $plaintext ), self::key_material() );
	}

	/**
	 * Returns the raw stored value, envelope and all.
	 *
	 * @return string
	 */
	private static function stored_raw(): string {
		return trim( (string) Config::get( 'api_key' ) );
	}

	/**
	 * Returns the stored key in the clear, or '' when absent or unopenable.
	 * Never returns ciphertext — an unopenable value is not a key.
	 *
	 * @return string
	 */
	public static function stored(): string {
		$raw = self::stored_raw();
		if ( '' === $raw || ! Crypto::is_envelope( $raw ) ) {
			return $raw;
		}
		return (string) Crypto::decrypt( $raw, self::key_material() );
	}

	/**
	 * Reports the health of the stored value: 'ok', 'empty', or 'unreadable'.
	 * 'unreadable' means the site's security salts changed after the key was
	 * sealed.
	 *
	 * @return string
	 */
	public static function stored_state(): string {
		$raw = self::stored_raw();
		if ( '' === $raw ) {
			return 'empty';
		}
		if ( Crypto::is_envelope( $raw ) && null === Crypto::decrypt( $raw, self::key_material() ) ) {
			return 'unreadable';
		}
		return 'ok';
	}

	/**
	 * Returns the resolved API key, or '' when none is configured.
	 *
	 * @return string
	 */
	public static function resolve(): string {
		return self::resolved()['key'];
	}

	/**
	 * Reports where the key came from: 'env', 'constant', 'option', or 'none'.
	 *
	 * @return string
	 */
	public static function source(): string {
		return self::resolved()['source'];
	}
}
