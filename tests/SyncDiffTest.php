<?php
/**
 * Tests for the sync's set arithmetic.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\SyncDiff;
use PHPUnit\Framework\TestCase;

/** Covers what counts as newly seen, and how identifiers are lifted out of rows. */
final class SyncDiffTest extends TestCase {

	public function test_unseen_returns_only_what_the_snapshot_lacked(): void {
		$this->assertSame( array( 'b' ), SyncDiff::unseen( array( 'a' ), array( 'a', 'b' ) ) );
	}

	public function test_unseen_is_empty_when_nothing_changed(): void {
		$this->assertSame( array(), SyncDiff::unseen( array( 'a', 'b' ), array( 'a', 'b' ) ) );
	}

	public function test_a_removed_identifier_is_not_reported_as_new(): void {
		$this->assertSame( array(), SyncDiff::unseen( array( 'a', 'b' ), array( 'a' ) ) );
	}

	public function test_everything_is_unseen_against_an_empty_snapshot(): void {
		$this->assertSame( array( 'a' ), SyncDiff::unseen( array(), array( 'a' ) ) );
	}

	public function test_ids_lifts_one_key_out_of_each_row(): void {
		$rows = array(
			array(
				'list_id' => 'a',
				'name'    => 'A',
			),
			array(
				'list_id' => 'b',
				'name'    => 'B',
			),
		);

		$this->assertSame( array( 'a', 'b' ), SyncDiff::ids( $rows, 'list_id' ) );
	}

	public function test_ids_skips_a_row_without_the_key(): void {
		$rows = array( array( 'name' => 'A' ), array( 'list_id' => 'b' ) );

		$this->assertSame( array( 'b' ), SyncDiff::ids( $rows, 'list_id' ) );
	}

	public function test_ids_skips_an_empty_identifier(): void {
		$this->assertSame( array(), SyncDiff::ids( array( array( 'list_id' => '' ) ), 'list_id' ) );
	}
}
