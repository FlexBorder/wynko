<?php
/**
 * Tests for the campaign list logic.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Campaigns;
use PHPUnit\Framework\TestCase;

/** Covers normalization, ordering, and the new-campaign diff. */
final class CampaignsTest extends TestCase {

	private function sample(): array {
		// Mirrors the Laposta shape: data is a list of { campaign: {...} }.
		return array(
			'data' => array(
				array(
					'campaign' => array(
						'campaign_id'      => '1',
						'subject'          => 'Older sent',
						'web'              => 'https://l.nl/older',
						'delivery_started' => '2026-01-01 10:00:00',
					),
				),
				array(
					'campaign' => array(
						'campaign_id'      => '2',
						'subject'          => 'Draft no web',
						'web'              => '',
						'delivery_started' => '',
					),
				),
				array(
					'campaign' => array(
						'campaign_id'      => '3',
						'subject'          => 'Newer sent',
						'web'              => 'https://l.nl/newer',
						'delivery_started' => '2026-06-01 10:00:00',
					),
				),
			),
		);
	}

	public function test_normalize_filters_drafts_and_sorts_newest_first(): void {
		$out = Campaigns::normalize( $this->sample() );
		$this->assertCount( 2, $out );
		$this->assertSame( 'Newer sent', $out[0]['subject'] );
		$this->assertSame( 'https://l.nl/newer', $out[0]['web'] );
		$this->assertSame( 'Older sent', $out[1]['subject'] );
	}

	public function test_normalize_supports_flat_data_shape(): void {
		$flat = array(
			'data' => array(
				array(
					'subject'          => 'A',
					'web'              => 'https://l.nl/a',
					'delivery_started' => '2026-02-02 00:00:00',
				),
			),
		);
		$out  = Campaigns::normalize( $flat );
		$this->assertSame( 'A', $out[0]['subject'] );
	}

	public function test_normalize_handles_missing_data_key(): void {
		$this->assertSame( array(), Campaigns::normalize( array() ) );
	}

	public function test_normalize_keeps_the_internal_name(): void {
		$in  = array(
			'data' => array(
				array(
					'subject'          => 'Subject line',
					'name'             => 'Internal name',
					'web'              => 'https://l.nl/n',
					'delivery_started' => '2026-04-04 00:00:00',
				),
			),
		);
		$out = Campaigns::normalize( $in );
		$this->assertSame( 'Internal name', $out[0]['name'] );
		$this->assertSame( 'Subject line', $out[0]['subject'] );
	}

	public function test_normalize_defaults_a_missing_name_to_an_empty_string(): void {
		$out = Campaigns::normalize( $this->sample() );
		$this->assertSame( '', $out[0]['name'] );
	}

	public function test_normalize_prefers_the_iso_timestamp(): void {
		$in  = array(
			'data' => array(
				array(
					'subject'              => 'X',
					'web'                  => 'https://l.nl/x',
					'delivery_started'     => '2026-04-04 00:00:00',
					'delivery_started_iso' => '2026-04-04T00:00:00+02:00',
				),
			),
		);
		$out = Campaigns::normalize( $in );
		$this->assertSame( '2026-04-04T00:00:00+02:00', $out[0]['sent_at'] );
	}

	public function test_sent_at_falls_back_from_started_to_ended_to_requested(): void {
		$in  = array(
			'data' => array(
				array(
					'subject'            => 'ended',
					'web'                => 'https://l.nl/e',
					'delivery_started'   => '',
					'delivery_ended'     => '2026-05-05 00:00:00',
					'delivery_requested' => '2026-04-04 00:00:00',
				),
				array(
					'subject'            => 'requested',
					'web'                => 'https://l.nl/r',
					'delivery_requested' => '2026-06-06 00:00:00',
				),
			),
		);
		$out = Campaigns::normalize( $in );
		$by  = array_column( $out, 'sent_at', 'subject' );
		$this->assertSame( '2026-05-05 00:00:00', $by['ended'] );
		$this->assertSame( '2026-06-06 00:00:00', $by['requested'] );
	}

	/**
	 * A modification date is not a send date. Presenting one as "date sent"
	 * would be a lie the new label formats make visible, so a campaign with no
	 * delivery timestamp reports none.
	 */
	public function test_sent_at_ignores_modified_and_created(): void {
		$in  = array(
			'data' => array(
				array(
					'subject'  => 'X',
					'web'      => 'https://l.nl/x',
					'modified' => '2026-03-03 00:00:00',
					'created'  => '2026-02-02 00:00:00',
				),
			),
		);
		$out = Campaigns::normalize( $in );
		$this->assertSame( '', $out[0]['sent_at'] );
	}

	public function test_normalize_sorts_undated_campaigns_last(): void {
		$in  = array(
			'data' => array(
				array(
					'subject' => 'undated',
					'web'     => 'https://l.nl/u',
					'created' => '2029-01-01 00:00:00',
				),
				array(
					'subject'          => 'dated',
					'web'              => 'https://l.nl/d',
					'delivery_started' => '2020-01-01 00:00:00',
				),
			),
		);
		$out = Campaigns::normalize( $in );
		$this->assertSame( 'dated', $out[0]['subject'] );
		$this->assertSame( 'undated', $out[1]['subject'] );
	}

	/**
	 * Wraps one campaign in the API's response shape.
	 *
	 * @param array<string,mixed> $campaign Raw campaign fields.
	 * @return array<string,mixed>
	 */
	private function wrap( array $campaign ): array {
		return array( 'data' => array( array( 'campaign' => $campaign ) ) );
	}

	public function test_normalize_takes_list_ids_from_the_map_shape(): void {
		$out = Campaigns::normalize(
			$this->wrap(
				array(
					'subject'  => 'M',
					'web'      => 'https://l.nl/m',
					'list_ids' => array(
						'list_a' => array(),
						'list_b' => array( 'seg_1' ),
					),
				)
			)
		);
		$this->assertSame( array( 'list_a', 'list_b' ), $out[0]['list_ids'] );
	}

	public function test_normalize_takes_list_ids_from_the_plain_list_shape(): void {
		$out = Campaigns::normalize(
			$this->wrap(
				array(
					'subject'  => 'P',
					'web'      => 'https://l.nl/p',
					'list_ids' => array( 'list_a', 'list_b' ),
				)
			)
		);
		$this->assertSame( array( 'list_a', 'list_b' ), $out[0]['list_ids'] );
	}

	/**
	 * A campaign whose recipients we cannot parse matches no filter, rather
	 * than leaking into every filtered block.
	 *
	 * @dataProvider unparsable_list_ids
	 * @param mixed $value Whatever the API put in list_ids.
	 */
	public function test_normalize_yields_no_list_ids_for_unusable_values( $value ): void {
		$out = Campaigns::normalize(
			$this->wrap(
				array(
					'subject'  => 'U',
					'web'      => 'https://l.nl/u',
					'list_ids' => $value,
				)
			)
		);
		$this->assertSame( array(), $out[0]['list_ids'] );
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public function unparsable_list_ids(): array {
		return array(
			'scalar' => array( 'list_a' ),
			'zero'   => array( 0 ),
			'null'   => array( null ),
			'empty'  => array( array() ),
		);
	}

	public function test_normalize_yields_no_list_ids_when_the_key_is_absent(): void {
		$out = Campaigns::normalize(
			$this->wrap(
				array(
					'subject' => 'A',
					'web'     => 'https://l.nl/a',
				)
			)
		);
		$this->assertSame( array(), $out[0]['list_ids'] );
	}

	public function test_diff_new_returns_only_unseen_web_urls(): void {
		$old  = array(
			array(
				'subject' => 'A',
				'web'     => 'https://l.nl/a',
			),
		);
		$new  = array(
			array(
				'subject' => 'A',
				'web'     => 'https://l.nl/a',
			),
			array(
				'subject' => 'B',
				'web'     => 'https://l.nl/b',
			),
		);
		$diff = Campaigns::diff_new( $old, $new );
		$this->assertCount( 1, $diff );
		$this->assertSame( 'https://l.nl/b', $diff[0]['web'] );
	}

	/**
	 * Four campaigns covering every sort key, including one with no date and
	 * one with no name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sortable(): array {
		return array(
			array(
				'subject' => 'Beta',
				'name'    => 'zulu-internal',
				'web'     => 'https://l.nl/b',
				'sent_at' => '2026-03-01T10:00:00+01:00',
			),
			array(
				'subject' => 'alpha',
				'name'    => 'Mike-internal',
				'web'     => 'https://l.nl/a',
				'sent_at' => '2026-05-01T10:00:00+01:00',
			),
			array(
				'subject' => 'Gamma',
				'name'    => '',
				'web'     => 'https://l.nl/g',
				'sent_at' => '',
			),
			array(
				'subject' => 'delta',
				'name'    => 'alfa-internal',
				'web'     => 'https://l.nl/d',
				'sent_at' => '2026-01-01T10:00:00+01:00',
			),
		);
	}

	/**
	 * Reduces a sorted result to its subjects, for readable assertions.
	 *
	 * @param array<int,array<string,mixed>> $campaigns Sorted campaigns.
	 * @return array<int,string>
	 */
	private function subjects( array $campaigns ): array {
		return array_map(
			static function ( $c ) {
				return $c['subject'];
			},
			$campaigns
		);
	}

	public function test_sort_by_date_descending_is_newest_first(): void {
		$out = Campaigns::sort_by( $this->sortable(), 'date', 'desc' );
		$this->assertSame( array( 'alpha', 'Beta', 'delta', 'Gamma' ), $this->subjects( $out ) );
	}

	public function test_sort_by_date_ascending_is_oldest_first(): void {
		$out = Campaigns::sort_by( $this->sortable(), 'date', 'asc' );
		$this->assertSame( array( 'delta', 'Beta', 'alpha', 'Gamma' ), $this->subjects( $out ) );
	}

	/**
	 * A campaign with no sent date is missing data, not the oldest campaign:
	 * it sinks in both directions rather than leading the ascending list.
	 */
	public function test_a_missing_date_sorts_last_in_both_directions(): void {
		$asc  = Campaigns::sort_by( $this->sortable(), 'date', 'asc' );
		$desc = Campaigns::sort_by( $this->sortable(), 'date', 'desc' );
		$this->assertSame( 'Gamma', $asc[3]['subject'] );
		$this->assertSame( 'Gamma', $desc[3]['subject'] );
	}

	public function test_sort_by_subject_is_case_insensitive(): void {
		$out = Campaigns::sort_by( $this->sortable(), 'subject', 'asc' );
		$this->assertSame( array( 'alpha', 'Beta', 'delta', 'Gamma' ), $this->subjects( $out ) );
	}

	public function test_sort_by_subject_descending_reverses(): void {
		$out = Campaigns::sort_by( $this->sortable(), 'subject', 'desc' );
		$this->assertSame( array( 'Gamma', 'delta', 'Beta', 'alpha' ), $this->subjects( $out ) );
	}

	public function test_sort_by_name_uses_the_internal_name(): void {
		$out = Campaigns::sort_by( $this->sortable(), 'name', 'asc' );
		// Gamma has no name, so it sinks; the rest go alfa, Mike, zulu.
		$this->assertSame( array( 'delta', 'alpha', 'Beta', 'Gamma' ), $this->subjects( $out ) );
	}

	public function test_a_missing_name_sorts_last_in_both_directions(): void {
		$asc  = Campaigns::sort_by( $this->sortable(), 'name', 'asc' );
		$desc = Campaigns::sort_by( $this->sortable(), 'name', 'desc' );
		$this->assertSame( 'Gamma', $asc[3]['subject'] );
		$this->assertSame( 'Gamma', $desc[3]['subject'] );
	}

	public function test_sort_by_keeps_incoming_order_for_ties(): void {
		$tied = array(
			array(
				'subject' => 'first',
				'name'    => 'same',
				'sent_at' => '2026-01-01T00:00:00+01:00',
			),
			array(
				'subject' => 'second',
				'name'    => 'same',
				'sent_at' => '2026-01-01T00:00:00+01:00',
			),
		);
		$this->assertSame( array( 'first', 'second' ), $this->subjects( Campaigns::sort_by( $tied, 'name', 'asc' ) ) );
		$this->assertSame( array( 'first', 'second' ), $this->subjects( Campaigns::sort_by( $tied, 'name', 'desc' ) ) );
	}

	public function test_sort_by_tolerates_campaigns_missing_the_keys(): void {
		$legacy = array(
			array( 'subject' => 'only subject' ),
			array( 'subject' => 'also only subject' ),
		);
		$this->assertCount( 2, Campaigns::sort_by( $legacy, 'date', 'desc' ) );
		$this->assertCount( 2, Campaigns::sort_by( $legacy, 'name', 'asc' ) );
	}
}
