<?php
/**
 * Tests for the /campaign resource.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Api\Campaigns;
use Wynko\Config;
use PHPUnit\Framework\TestCase;

/** Covers the wynko_campaigns filter over the normalized result. */
final class ApiCampaignsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
	}

	public function test_wynko_campaigns_filter_can_modify_the_normalized_result(): void {
		wynko_test_queue_response(
			200,
			'{"data":[{"campaign":{"campaign_id":"1","subject":"A","web":"https://l.nl/a","delivery_started":"2026-01-01 00:00:00"}}]}'
		);
		add_filter(
			'wynko_campaigns',
			static function ( $campaigns ) {
				$campaigns[0]['subject'] = 'Overridden';
				return $campaigns;
			}
		);

		$result = Campaigns::all();

		$this->assertSame( 'Overridden', $result[0]['subject'] );
	}
}
