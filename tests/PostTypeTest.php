<?php
/**
 * Tests for the signup-form post type.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\PostType;
use PHPUnit\Framework\TestCase;

/** The CPT is internal: no public URLs, no default post UI, admin-only caps. */
final class PostTypeTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		PostType::register();
	}

	private function args(): array {
		return wynko_test_registered_post_types()[ Config::form_post_type() ];
	}

	public function test_it_registers_under_the_configured_slug(): void {
		$this->assertArrayHasKey( Config::form_post_type(), wynko_test_registered_post_types() );
	}

	public function test_it_is_invisible_to_the_public_and_to_the_post_editor(): void {
		$args = $this->args();

		$this->assertFalse( $args['public'] );
		$this->assertFalse( $args['show_ui'] );
		$this->assertFalse( $args['publicly_queryable'] );
		$this->assertFalse( $args['has_archive'] );
		$this->assertFalse( $args['show_in_rest'] );
		$this->assertTrue( $args['exclude_from_search'] );
	}

	public function test_every_capability_requires_manage_options(): void {
		foreach ( $this->args()['capabilities'] as $capability ) {
			$this->assertSame( PostType::CAP, $capability );
		}
		$this->assertFalse( $this->args()['map_meta_cap'] );
	}

	public function test_it_supports_only_a_title(): void {
		$this->assertSame( array( 'title' ), $this->args()['supports'] );
	}
}
