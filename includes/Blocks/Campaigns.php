<?php
/**
 * Campaigns block.
 *
 * @package Wynko
 */

namespace Wynko\Blocks;

use Wynko\Api\Lists;
use Wynko\Cache;
use Wynko\Config;
use Wynko\Support\Campaigns as CampaignData;
use Wynko\Support\Sanitizer;
use Wynko\Urls;
use WP_Block_Type;
use WP_Block_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The wynko/campaigns block: a server-rendered list of links to sent campaigns. Ships no CSS. */
final class Campaigns {

	/**
	 * Registers the block and its script translations.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = register_block_type(
			WYNKO_PATH . 'build/block',
			array( 'render_callback' => array( self::class, 'render' ) )
		);

		if ( $type instanceof WP_Block_Type ) {
			foreach ( $type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'wynko-for-laposta', WYNKO_PATH . 'languages' );
			}
		}
	}

	/**
	 * Hands the editor its bounds and the account's lists. Hooked to
	 * enqueue_block_editor_assets rather than init: init also runs on front-end
	 * requests, which have no use for either and must not pay for the /list call.
	 *
	 * @return void
	 */
	public static function enqueue_editor_data(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'wynko/campaigns' );
		if ( ! $type instanceof WP_Block_Type ) {
			return;
		}
		$lists = Lists::for_editor();
		foreach ( $type->editor_script_handles as $handle ) {
			wp_add_inline_script(
				$handle,
				'window.wynkoBlockData = ' . wp_json_encode(
					array(
						'countBounds'  => Config::bounds( 'campaign_count' ),
						'countDefault' => (int) Config::default_for( 'campaign_count' ),
						'orderBy'      => array(
							'allowed' => Config::allowed_for( 'campaign_order_by' ),
							'default' => (string) Config::default_for( 'campaign_order_by' ),
						),
						'order'        => array(
							'allowed' => Config::allowed_for( 'campaign_order' ),
							'default' => (string) Config::default_for( 'campaign_order' ),
						),
						'labelFormat'  => array(
							'allowed' => Config::allowed_for( 'campaign_label' ),
							'default' => (string) Config::default_for( 'campaign_label' ),
						),
						'lists'        => $lists['options'],
						'listsError'   => $lists['error'],
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Renders the campaign list; empty for visitors when there is nothing cached.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render( array $attributes ): string {
		$bounds  = Config::bounds( 'campaign_count' );
		$count   = Sanitizer::clamp_int( $attributes['count'] ?? Config::default_for( 'campaign_count' ), $bounds['min'], $bounds['max'], (int) Config::default_for( 'campaign_count' ) );
		$list_id = sanitize_text_field( (string) ( $attributes['listId'] ?? '' ) );

		$order_by = Sanitizer::enum( $attributes['orderBy'] ?? '', Config::allowed_for( 'campaign_order_by' ), (string) Config::default_for( 'campaign_order_by' ) );
		$order    = Sanitizer::enum( $attributes['order'] ?? '', Config::allowed_for( 'campaign_order' ), (string) Config::default_for( 'campaign_order' ) );
		$label    = Sanitizer::enum( $attributes['labelFormat'] ?? '', Config::allowed_for( 'campaign_label' ), (string) Config::default_for( 'campaign_label' ) );

		// Filter before slicing, so count means "matching campaigns" rather than
		// "however many of the newest happen to match".
		$campaigns = Cache::get();
		if ( '' !== $list_id ) {
			$campaigns = array_values(
				array_filter(
					$campaigns,
					static function ( $c ) use ( $list_id ) {
						return in_array( $list_id, $c['list_ids'], true );
					}
				)
			);
		}
		$campaigns = array_slice( $campaigns, 0, $count );

		// The block lists *recent* campaigns: the slice picks the newest, and
		// the display sort only reorders what it picked.
		$campaigns = CampaignData::sort_by( $campaigns, $order_by, $order );

		if ( empty( $campaigns ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				if ( '' !== $list_id ) {
					return '<p>' . esc_html__( 'Wynko: no sent campaigns for the selected list.', 'wynko-for-laposta' ) . '</p>';
				}
				return '<p>' . esc_html__( 'Wynko: no campaigns to show yet. Check the Wynko menu.', 'wynko-for-laposta' ) . '</p>';
			}
			return '';
		}

		$items = '';
		foreach ( $campaigns as $c ) {
			$text = self::label( $c, $label );
			if ( empty( $c['web'] ) || '' === $text ) {
				continue;
			}
			$items .= sprintf(
				'<li><a href="%s" target="%s" rel="%s">%s</a></li>',
				esc_url( $c['web'] ),
				esc_attr( Urls::target( 'campaign_web' ) ),
				esc_attr( Urls::rel( 'campaign_web' ) ),
				esc_html( $text )
			);
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-wynko-campaigns"';
		return sprintf( '<ul %s>%s</ul>', $wrapper, $items );
	}

	/**
	 * Formats a send date in the site's locale and date format, '' when the
	 * campaign has none. Formatting is a WordPress concern, so it lives here
	 * rather than in the pure Support layer that produced the raw value.
	 *
	 * @param string $sent_at Raw timestamp from the normalized campaign.
	 * @return string
	 */
	private static function format_date( string $sent_at ): string {
		if ( '' === $sent_at ) {
			return '';
		}
		$timestamp = strtotime( $sent_at );
		if ( false === $timestamp ) {
			return '';
		}
		return (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $timestamp );
	}

	/**
	 * Builds a list item's text. Every format degrades to something non-empty:
	 * a missing name or date falls back to the subject rather than rendering a
	 * blank link or a dangling separator.
	 *
	 * @param array<string,mixed> $campaign Normalized campaign.
	 * @param string              $format   One of the campaign_label values.
	 * @return string
	 */
	private static function label( array $campaign, string $format ): string {
		$subject = (string) ( $campaign['subject'] ?? '' );
		$name    = (string) ( $campaign['name'] ?? '' );
		$date    = self::format_date( (string) ( $campaign['sent_at'] ?? '' ) );

		if ( 'date' === $format ) {
			return '' !== $date ? $date : $subject;
		}

		$text = ( in_array( $format, array( 'name', 'name_date' ), true ) && '' !== $name ) ? $name : $subject;

		if ( in_array( $format, array( 'subject_date', 'name_date' ), true ) && '' !== $date ) {
			if ( '' === $text ) {
				return $date;
			}
			/* translators: 1: campaign subject or name, 2: date the campaign was sent. */
			return sprintf( __( '%1$s — %2$s', 'wynko-for-laposta' ), $text, $date );
		}

		return $text;
	}
}
