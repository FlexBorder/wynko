<?php
/**
 * Tests for the integration registry.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Integrations\Integration;
use Wynko\Integrations\Registry;
use PHPUnit\Framework\TestCase;

/** Tests for Registry::all(). */
final class IntegrationsRegistryTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_it_collects_integrations_registered_through_the_filter(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'one' );
				return $integrations;
			}
		);

		$all = Registry::all();

		$this->assertArrayHasKey( 'one', $all );
		$this->assertInstanceOf( Integration::class, $all['one'] );
	}

	public function test_it_drops_anything_that_is_not_an_integration(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = 'not an integration';
				$integrations[] = new FakeIntegration( 'real' );
				return $integrations;
			}
		);

		$all = Registry::all();

		$this->assertCount( 1, $all );
		$this->assertArrayHasKey( 'real', $all );
	}

	public function test_the_first_registration_for_a_slug_wins(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'dup', 'First' );
				$integrations[] = new FakeIntegration( 'dup', 'Second' );
				return $integrations;
			}
		);

		$all = Registry::all();

		$this->assertCount( 1, $all );
		$this->assertSame( 'First', $all['dup']->name() );
	}

	public function test_an_empty_slug_is_dropped(): void {
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( '' );
				return $integrations;
			}
		);

		$this->assertSame( array(), Registry::all() );
	}
}
