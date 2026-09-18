<?php
/**
 * Tests for the campaigns shortcode.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Frontend\CampaignsShortcode;
use PHPUnit\Framework\TestCase;

/** The shortcode is a thin wrapper: it maps atts onto block attributes and delegates. */
final class CampaignsShortcodeTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	/**
	 * Seeds the campaign cache with $count campaigns on $list_id.
	 *
	 * @param array<string,int> $per_list Number of campaigns per list id.
	 * @return void
	 */
	private function seed( array $per_list ): void {
		$campaigns = array();
		foreach ( $per_list as $list_id => $count ) {
			for ( $i = 1; $i <= $count; $i++ ) {
				$campaigns[] = array(
					'subject'  => $list_id . '-' . $i,
					'name'     => '',
					'web'      => 'https://l.nl/' . $list_id . '/' . $i,
					'sent_at'  => '',
					'list_ids' => array( $list_id ),
				);
			}
		}
		set_transient( Config::transient_key(), $campaigns, 60 );
	}

	private function subjects( string $html ): array {
		preg_match_all( '#<a [^>]*>([^<]+)</a>#', $html, $m );
		return $m[1];
	}

	public function test_no_attributes_renders_up_to_the_default_count(): void {
		$this->seed( array( 'list_a' => 2 ) );

		$html = CampaignsShortcode::render( array() );

		$this->assertCount( 2, $this->subjects( $html ) );
	}

	public function test_the_count_attribute_limits_how_many_render(): void {
		$this->seed( array( 'list_a' => 5 ) );

		$html = CampaignsShortcode::render( array( 'count' => '2' ) );

		$this->assertCount( 2, $this->subjects( $html ) );
	}

	public function test_the_list_attribute_maps_onto_the_list_filter(): void {
		$this->seed(
			array(
				'list_a' => 2,
				'list_b' => 3,
			)
		);

		$html = CampaignsShortcode::render(
			array(
				'count' => '10',
				'list'  => 'list_b',
			)
		);

		$this->assertSame( array( 'list_b-1', 'list_b-2', 'list_b-3' ), $this->subjects( $html ) );
	}

	public function test_the_order_by_and_order_attributes_map_onto_the_display_sort(): void {
		$this->seed( array( 'list_a' => 1 ) );
		set_transient(
			Config::transient_key(),
			array(
				array(
					'subject'  => 'Zebra',
					'name'     => '',
					'web'      => 'https://l.nl/z',
					'sent_at'  => '',
					'list_ids' => array( 'list_a' ),
				),
				array(
					'subject'  => 'Apple',
					'name'     => '',
					'web'      => 'https://l.nl/a',
					'sent_at'  => '',
					'list_ids' => array( 'list_a' ),
				),
			),
			60
		);

		$html = CampaignsShortcode::render(
			array(
				'count'    => '10',
				'order_by' => 'subject',
				'order'    => 'asc',
			)
		);

		$this->assertSame( array( 'Apple', 'Zebra' ), $this->subjects( $html ) );
	}

	public function test_the_label_attribute_maps_onto_the_label_format(): void {
		$this->seed( array( 'list_a' => 1 ) );
		set_transient(
			Config::transient_key(),
			array(
				array(
					'subject'  => 'Subject line',
					'name'     => 'Internal name',
					'web'      => 'https://l.nl/a',
					'sent_at'  => '',
					'list_ids' => array( 'list_a' ),
				),
			),
			60
		);

		$html = CampaignsShortcode::render(
			array(
				'count' => '10',
				'label' => 'name',
			)
		);

		$this->assertSame( array( 'Internal name' ), $this->subjects( $html ) );
	}

	public function test_registering_uses_the_configured_tag(): void {
		CampaignsShortcode::register();

		$this->assertArrayHasKey( Config::campaigns_shortcode(), $GLOBALS['wynko_test_shortcodes'] );
	}
}
