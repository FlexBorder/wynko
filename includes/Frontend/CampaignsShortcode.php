<?php
/**
 * Campaigns shortcode.
 *
 * @package Wynko
 */

namespace Wynko\Frontend;

use Wynko\Blocks\Campaigns;
use Wynko\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [wynko_campaigns count="5" list="" order_by="date" order="desc" label="subject"]
 * — a thin wrapper over Blocks\Campaigns::render(), the same renderer the block uses.
 */
final class CampaignsShortcode {

	/**
	 * Registers the shortcode under its configured tag.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( Config::campaigns_shortcode(), array( self::class, 'render' ) );
	}

	/**
	 * Maps shortcode attributes onto the block's attribute keys and delegates.
	 * All clamping and enum validation happens inside Campaigns::render(), so
	 * this carries no sanitization logic of its own.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'    => Config::default_for( 'campaign_count' ),
				'list'     => '',
				'order_by' => Config::default_for( 'campaign_order_by' ),
				'order'    => Config::default_for( 'campaign_order' ),
				'label'    => Config::default_for( 'campaign_label' ),
			),
			is_array( $atts ) ? $atts : array(),
			Config::campaigns_shortcode()
		);

		return Campaigns::render(
			array(
				'count'       => $atts['count'],
				'listId'      => $atts['list'],
				'orderBy'     => $atts['order_by'],
				'order'       => $atts['order'],
				'labelFormat' => $atts['label'],
			)
		);
	}
}
