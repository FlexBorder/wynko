<?php
/**
 * Campaign resource.
 *
 * @package Wynko
 */

namespace Wynko\Api;

use Wynko\Support\Campaigns as CampaignData;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Laposta /campaign endpoint. */
final class Campaigns {

	/**
	 * Fetches the campaign list, normalized for the block and the cache.
	 *
	 * @param string $key Optional API key override (used to validate a key before storing it).
	 * @return array<int,array{subject:string,name:string,web:string,sent_at:string,list_ids:array<int,string>}>|WP_Error
	 */
	public static function all( string $key = '' ) {
		$decoded = Client::request( 'GET', 'campaign', '' !== $key ? array( 'key' => $key ) : array() );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return CampaignData::normalize( $decoded );
	}
}
