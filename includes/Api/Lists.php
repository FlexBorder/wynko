<?php
/**
 * List resource.
 *
 * @package Wynko
 */

namespace Wynko\Api;

use Wynko\Cache;
use Wynko\Config;
use Wynko\Support\Lists as ListData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Laposta /list endpoint. */
final class Lists {

	/**
	 * Fetches the account's lists, normalized.
	 *
	 * @return array<int,array{list_id:string,name:string}>|WP_Error
	 */
	public static function all() {
		$decoded = Client::request( 'GET', 'list' );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return ListData::normalize( $decoded );
	}

	/**
	 * Cached list options for the block inspector. A failure yields no options
	 * and error => true, so the editor degrades to "All lists" with a note that
	 * is cached alongside them.
	 *
	 * @return array{options:array<int,array{value:string,label:string}>,error:bool}
	 */
	public static function for_editor(): array {
		$cached = get_transient( Config::lists_transient_key() );
		if ( is_array( $cached ) && isset( $cached['options'], $cached['error'] ) ) {
			return $cached;
		}

		$lists = self::all();
		if ( is_wp_error( $lists ) ) {
			$failed = array(
				'options' => array(),
				'error'   => true,
			);
			set_transient( Config::lists_transient_key(), $failed, Cache::negative_ttl() );
			Cache::stamp( false );
			return $failed;
		}

		$result = array(
			'options' => array_map(
				static function ( $l ) {
					return array(
						'value' => $l['list_id'],
						'label' => $l['name'],
					);
				},
				$lists
			),
			'error'   => false,
		);

		/**
		 * Filters the list options for the form editor and block inspector, before caching.
		 *
		 * @since 1.1.0
		 * @param array<int,array{value:string,label:string}> $options Laposta list options.
		 */
		$result['options'] = (array) apply_filters( 'wynko_lists', $result['options'] );

		set_transient( Config::lists_transient_key(), $result, Cache::ttl_seconds() );
		Cache::stamp( true );
		return $result;
	}
}
