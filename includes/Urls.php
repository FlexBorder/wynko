<?php
/**
 * Read-only accessor over config/urls.php.
 *
 * @package Wynko
 */

namespace Wynko;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only accessor over config/urls.php. */
final class Urls {

	/**
	 * The target assumed for a link that registers none.
	 *
	 * @var string
	 */
	const DEFAULT_TARGET = '_self';

	/**
	 * The registered name of one list's page in Laposta's own admin, which
	 * three callers need and laposta_list_url() builds on.
	 *
	 * @var string
	 */
	const LAPOSTA_LIST = 'laposta_list';

	/**
	 * Lazily loaded registry.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * Loads config/urls.php once per request.
	 *
	 * @return array<string,mixed>
	 */
	private static function data(): array {
		if ( null === self::$data ) {
			self::$data = require WYNKO_PATH . 'config/urls.php';
		}
		return self::$data;
	}

	/**
	 * Returns a registered link, empty when the name is unknown.
	 *
	 * @param string $name Link name.
	 * @return array<string,string>
	 */
	private static function link( string $name ): array {
		$link = self::data()['links'][ $name ] ?? array();
		return is_array( $link ) ? $link : array();
	}

	/**
	 * Returns the Laposta API base URL. Fixed by the registry rather than by an
	 * option or a filter, because it is the plugin's only outbound host.
	 *
	 * @return string
	 */
	public static function api_base(): string {
		return (string) self::data()['api_base'];
	}

	/**
	 * Returns a registered URL, '' when the name is unknown or the link takes
	 * its href at render time.
	 *
	 * @param string $name Link name.
	 * @return string
	 */
	public static function url( string $name ): string {
		return (string) ( self::link( $name )['url'] ?? '' );
	}

	/**
	 * Returns the browsing context a link opens in.
	 *
	 * @param string $name Link name.
	 * @return string
	 */
	public static function target( string $name ): string {
		$target = (string) ( self::link( $name )['target'] ?? '' );
		return '' === $target ? self::DEFAULT_TARGET : $target;
	}

	/**
	 * Returns the rel attribute a link needs, '' when it needs none. Derived
	 * from the target rather than registered, so no _blank link can be added
	 * without the pairing that keeps the opener out of the new context's reach.
	 *
	 * @param string $name Link name.
	 * @return string
	 */
	public static function rel( string $name ): string {
		return '_blank' === self::target( $name ) ? 'noopener noreferrer' : '';
	}

	/**
	 * One list's page in Laposta's own admin, '' without a list id.
	 *
	 * The query argument's name lives here rather than at each call site, so a
	 * URL Laposta changes stays one edit.
	 *
	 * @param string $list_id Laposta list id.
	 * @return string
	 */
	public static function laposta_list_url( string $list_id ): string {
		$base = self::url( self::LAPOSTA_LIST );
		if ( '' === $list_id || '' === $base ) {
			return '';
		}

		return add_query_arg( 'listconfig', rawurlencode( $list_id ), $base );
	}
}
