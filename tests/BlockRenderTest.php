<?php
/**
 * Tests for the block's server-side rendering.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Blocks\Campaigns;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers list filtering, the filter-then-slice order, and the empty states. */
final class BlockRenderTest extends TestCase {

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

	public function test_no_filter_renders_every_campaign_up_to_the_count(): void {
		$this->seed(
			array(
				'list_a' => 2,
				'list_b' => 2,
			)
		);
		$out = Campaigns::render( array( 'count' => 10 ) );
		$this->assertCount( 4, $this->subjects( $out ) );
	}

	public function test_a_list_filter_renders_only_that_list(): void {
		$this->seed(
			array(
				'list_a' => 2,
				'list_b' => 3,
			)
		);
		$out = Campaigns::render(
			array(
				'count'  => 10,
				'listId' => 'list_b',
			)
		);
		$this->assertSame( array( 'list_b-1', 'list_b-2', 'list_b-3' ), $this->subjects( $out ) );
	}

	/**
	 * The count means "how many matching campaigns", not "how many of the
	 * newest campaigns happen to match": the filter runs before the slice.
	 */
	public function test_the_slice_applies_after_the_filter(): void {
		// list_a's six campaigns come first, so a slice-then-filter order would
		// leave only one list_b campaign in the requested five.
		$this->seed(
			array(
				'list_a' => 6,
				'list_b' => 6,
			)
		);
		$out      = Campaigns::render(
			array(
				'count'  => 5,
				'listId' => 'list_b',
			)
		);
		$subjects = $this->subjects( $out );
		$this->assertCount( 5, $subjects );
		$this->assertSame( 'list_b-1', $subjects[0] );
	}

	public function test_a_non_matching_list_renders_nothing_for_a_visitor(): void {
		$this->seed( array( 'list_a' => 2 ) );
		$out = Campaigns::render(
			array(
				'count'  => 5,
				'listId' => 'list_gone',
			)
		);
		$this->assertSame( '', $out );
	}

	public function test_a_non_matching_list_tells_an_editor_the_filter_is_the_cause(): void {
		$GLOBALS['wynko_test_can_manage'] = true;
		$this->seed( array( 'list_a' => 2 ) );
		$out = Campaigns::render(
			array(
				'count'  => 5,
				'listId' => 'list_gone',
			)
		);
		$this->assertStringContainsString( 'no sent campaigns for the selected list', $out );
	}

	public function test_an_empty_cache_keeps_the_generic_message(): void {
		$GLOBALS['wynko_test_can_manage'] = true;
		set_transient( Config::transient_key(), array(), 60 );
		$out = Campaigns::render( array( 'count' => 5 ) );
		$this->assertStringContainsString( 'no campaigns to show yet', $out );
	}

	/**
	 * Migrations drops the pre-list_ids cache, but the transient is storage we
	 * do not control: a stale shape must not reach the filter.
	 */
	public function test_a_cache_without_list_ids_is_treated_as_a_miss(): void {
		set_transient(
			Config::transient_key(),
			array(
				array(
					'subject' => 'Legacy',
					'web'     => 'https://l.nl/legacy',
				),
			),
			60
		);

		$out = Campaigns::render(
			array(
				'count'  => 5,
				'listId' => 'list_a',
			)
		);

		$this->assertSame( '', $out );
	}

	public function test_the_count_is_clamped_to_the_configured_bounds(): void {
		$this->seed( array( 'list_a' => 12 ) );
		$this->assertCount( 1, $this->subjects( Campaigns::render( array( 'count' => 0 ) ) ) );
		$this->assertCount( 12, $this->subjects( Campaigns::render( array( 'count' => 1000 ) ) ) );
	}

	/**
	 * The editor can hold an out-of-bounds count — `min`/`max` on a number
	 * input are browser hints, not validation. The renderer is what makes an
	 * out-of-bounds attribute harmless, so it is pinned here.
	 *
	 * @return void
	 */
	public function test_a_count_above_the_maximum_is_clamped_to_the_maximum(): void {
		$max = Config::bounds( 'campaign_count' )['max'];
		$this->seed( array( 'list_a' => $max + 5 ) );

		$html = Campaigns::render( array( 'count' => $max + 1 ) );

		$this->assertCount( $max, $this->subjects( $html ) );
	}

	/**
	 * Below the minimum clamps up rather than rendering nothing.
	 *
	 * @return void
	 */
	public function test_a_count_below_the_minimum_is_clamped_to_the_minimum(): void {
		$min = Config::bounds( 'campaign_count' )['min'];
		$this->seed( array( 'list_a' => 5 ) );

		$html = Campaigns::render( array( 'count' => 0 ) );

		$this->assertCount( $min, $this->subjects( $html ) );
	}

	/**
	 * A transient written before sent_at existed is storage we do not control
	 * and cannot sort or label, so it counts as a miss and is refilled. The
	 * assertion is on the outcome, not on an HTTP call: Client::request()
	 * short-circuits to a WP_Error when no API key is configured, so a miss in
	 * this fixture never reaches wp_remote_request().
	 *
	 * @return void
	 */
	public function test_a_cache_entry_without_sent_at_is_a_miss(): void {
		set_transient(
			Config::transient_key(),
			array(
				array(
					'subject'  => 'stale',
					'web'      => 'https://l.nl/stale',
					'list_ids' => array( 'list_a' ),
				),
			),
			60
		);

		$html = Campaigns::render( array( 'count' => 5 ) );

		$this->assertStringNotContainsString( 'stale', $html );
		// The refill failed and negative-cached, overwriting the stale entry.
		$this->assertSame( array(), get_transient( Config::transient_key() ) );
	}

	/**
	 * @return void
	 */
	public function test_a_cache_entry_with_sent_at_is_served_without_a_refetch(): void {
		set_transient(
			Config::transient_key(),
			array(
				array(
					'subject'  => 'fresh',
					'name'     => '',
					'web'      => 'https://l.nl/fresh',
					'sent_at'  => '2026-05-05T00:00:00+02:00',
					'list_ids' => array( 'list_a' ),
				),
			),
			60
		);

		$html = Campaigns::render( array( 'count' => 5 ) );

		$this->assertSame( 0, wynko_test_http_calls() );
		$this->assertStringContainsString( 'fresh', $html );
	}

	/**
	 * Seeds three fully-shaped campaigns: newest first, one without a name and
	 * one without a send date.
	 *
	 * @return void
	 */
	private function seed_shaped(): void {
		set_transient(
			Config::transient_key(),
			array(
				array(
					'subject'  => 'Zebra subject',
					'name'     => 'internal-zebra',
					'web'      => 'https://l.nl/z',
					'sent_at'  => '2026-05-05T00:00:00+00:00',
					'list_ids' => array( 'list_a' ),
				),
				array(
					'subject'  => 'Apple subject',
					'name'     => '',
					'web'      => 'https://l.nl/a',
					'sent_at'  => '2026-03-03T00:00:00+00:00',
					'list_ids' => array( 'list_a' ),
				),
				array(
					'subject'  => 'Mango subject',
					'name'     => 'internal-mango',
					'web'      => 'https://l.nl/m',
					'sent_at'  => '',
					'list_ids' => array( 'list_a' ),
				),
			),
			60
		);
	}

	/**
	 * @return void
	 */
	public function test_the_default_label_is_the_subject(): void {
		$this->seed_shaped();

		$html = Campaigns::render( array( 'count' => 3 ) );

		$this->assertSame(
			array( 'Zebra subject', 'Apple subject', 'Mango subject' ),
			$this->subjects( $html )
		);
	}

	/**
	 * @return void
	 */
	public function test_the_date_label_renders_the_formatted_send_date(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'date',
			)
		);

		$labels = $this->subjects( $html );
		$this->assertSame( '2026-05-05', $labels[0] );
		$this->assertSame( '2026-03-03', $labels[1] );
	}

	/**
	 * @return void
	 */
	public function test_a_date_label_falls_back_to_the_subject_when_undated(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'date',
			)
		);

		$this->assertSame( 'Mango subject', $this->subjects( $html )[2] );
	}

	/**
	 * @return void
	 */
	public function test_the_subject_date_label_joins_both(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'subject_date',
			)
		);

		$this->assertSame( 'Zebra subject — 2026-05-05', $this->subjects( $html )[0] );
	}

	/**
	 * No dangling separator when the campaign has no send date.
	 *
	 * @return void
	 */
	public function test_a_composite_label_drops_the_separator_when_undated(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'subject_date',
			)
		);

		$this->assertSame( 'Mango subject', $this->subjects( $html )[2] );
	}

	/**
	 * @return void
	 */
	public function test_the_name_label_renders_the_internal_name(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'name',
			)
		);

		$this->assertSame( 'internal-zebra', $this->subjects( $html )[0] );
	}

	/**
	 * A campaign with no internal name shows its subject rather than nothing.
	 *
	 * @return void
	 */
	public function test_the_name_label_falls_back_to_the_subject(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'name',
			)
		);

		$this->assertSame( 'Apple subject', $this->subjects( $html )[1] );
	}

	/**
	 * @return void
	 */
	public function test_the_name_date_label_joins_both(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'labelFormat' => 'name_date',
			)
		);

		$this->assertSame( 'internal-zebra — 2026-05-05', $this->subjects( $html )[0] );
	}

	/**
	 * @return void
	 */
	public function test_ordering_by_subject_ascending(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'   => 3,
				'orderBy' => 'subject',
				'order'   => 'asc',
			)
		);

		$this->assertSame(
			array( 'Apple subject', 'Mango subject', 'Zebra subject' ),
			$this->subjects( $html )
		);
	}

	/**
	 * @return void
	 */
	public function test_ordering_by_date_ascending_puts_the_undated_last(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'   => 3,
				'orderBy' => 'date',
				'order'   => 'asc',
			)
		);

		$this->assertSame(
			array( 'Apple subject', 'Zebra subject', 'Mango subject' ),
			$this->subjects( $html )
		);
	}

	/**
	 * The block lists *recent* campaigns: the count picks the newest, and the
	 * display sort only reorders what was picked. Sorting the whole cache first
	 * would make an A-Z block show the same items forever.
	 *
	 * @return void
	 */
	public function test_the_display_sort_applies_after_the_slice(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'   => 2,
				'orderBy' => 'subject',
				'order'   => 'asc',
			)
		);

		// The two newest are Zebra and Apple; Mango is undated and drops out
		// before the alphabetical sort runs.
		$this->assertSame( array( 'Apple subject', 'Zebra subject' ), $this->subjects( $html ) );
	}

	/**
	 * @return void
	 */
	public function test_unknown_attribute_values_fall_back_to_the_defaults(): void {
		$this->seed_shaped();

		$html = Campaigns::render(
			array(
				'count'       => 3,
				'orderBy'     => 'rand(); DROP',
				'order'       => 'sideways',
				'labelFormat' => '<script>',
			)
		);

		$this->assertSame(
			array( 'Zebra subject', 'Apple subject', 'Mango subject' ),
			$this->subjects( $html )
		);
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
