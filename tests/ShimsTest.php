<?php
/**
 * Tests for the bootstrap's WordPress shims.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The shims stand in for WordPress in every later test, so a wrong one would
 * turn a green suite into a lie. These prove the handful with real behaviour.
 */
final class ShimsTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_a_post_round_trips_through_the_store(): void {
		$id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
			)
		);

		$post = get_post( $id );

		$this->assertSame( 'Newsletter signup', $post->post_title );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertNull( get_post( $id + 1000 ) );
	}

	public function test_get_posts_filters_by_type_and_status(): void {
		wynko_test_insert_post(
			array(
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
			)
		);
		wynko_test_insert_post(
			array(
				'post_type'   => 'wynko_form',
				'post_status' => 'draft',
			)
		);
		wynko_test_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$this->assertCount(
			2,
			get_posts(
				array(
					'post_type'   => 'wynko_form',
					'post_status' => 'any',
				)
			)
		);
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'wynko_form',
					'post_status' => 'publish',
				)
			)
		);
	}

	public function test_get_posts_caps_results_and_orders_descending(): void {
		wynko_test_insert_post(
			array(
				'post_title'  => 'Alpha',
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
			)
		);
		wynko_test_insert_post(
			array(
				'post_title'  => 'Bravo',
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
			)
		);
		wynko_test_insert_post(
			array(
				'post_title'  => 'Charlie',
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
			)
		);

		$capped = get_posts(
			array(
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
				'numberposts' => 2,
				'orderby'     => 'title',
				'order'       => 'DESC',
			)
		);

		$this->assertSame( array( 'Charlie', 'Bravo' ), array_column( $capped, 'post_title' ) );

		$unlimited = get_posts(
			array(
				'post_type'   => 'wynko_form',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		$this->assertCount( 3, $unlimited );
	}

	public function test_meta_round_trips_and_defaults_to_empty_string(): void {
		$id = wynko_test_insert_post( array( 'post_type' => 'wynko_form' ) );

		update_post_meta( $id, '_wynko_list_id', 'list_a' );

		$this->assertSame( 'list_a', get_post_meta( $id, '_wynko_list_id', true ) );
		$this->assertSame( '', get_post_meta( $id, '_wynko_missing', true ) );
	}

	public function test_wp_delete_post_removes_the_post_and_its_meta(): void {
		$id = wynko_test_insert_post( array( 'post_type' => 'wynko_form' ) );
		update_post_meta( $id, '_wynko_list_id', 'list_a' );

		wp_delete_post( $id, true );

		$this->assertNull( get_post( $id ) );
		$this->assertSame( '', get_post_meta( $id, '_wynko_list_id', true ) );
	}

	public function test_a_nonce_only_verifies_against_its_own_action(): void {
		$nonce = wp_create_nonce( 'wynko_submit_form_7' );

		$this->assertSame( 1, wp_verify_nonce( $nonce, 'wynko_submit_form_7' ) );
		$this->assertFalse( wp_verify_nonce( $nonce, 'wynko_submit_form_8' ) );
		$this->assertFalse( wp_verify_nonce( 'made up', 'wynko_submit_form_7' ) );
	}

	public function test_wp_die_throws_with_the_status_as_the_code(): void {
		$this->expectException( WpDieException::class );
		$this->expectExceptionCode( 404 );

		wp_die( 'Not found', '', array( 'response' => 404 ) );
	}

	public function test_redirects_are_recorded_rather_than_sent(): void {
		wp_safe_redirect( 'https://example.org/thanks/' );

		$this->assertSame( array( 'https://example.org/thanks/' ), wynko_test_redirects() );
	}

	public function test_registered_post_types_are_recorded(): void {
		register_post_type( 'wynko_demo', array( 'public' => false ) );

		$types = wynko_test_registered_post_types();
		$this->assertArrayHasKey( 'wynko_demo', $types );
		$this->assertFalse( $types['wynko_demo']['public'] );
	}

	public function test_is_email_accepts_and_rejects(): void {
		$this->assertSame( 'visitor@example.org', is_email( 'visitor@example.org' ) );
		$this->assertFalse( is_email( 'nope' ) );
	}
}
