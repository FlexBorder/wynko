<?php
/**
 * Tests for the alert recipient list's parsing rules.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Recipients;
use PHPUnit\Framework\TestCase;

/**
 * Covers splitting, trimming, de-duplication, and the cap. Validity is not
 * tested here: deciding whether an address is deliverable is is_email()'s job,
 * and is_email() is a WordPress function this class may not call.
 */
final class RecipientsTest extends TestCase {

	public function test_it_splits_on_commas_and_trims(): void {
		$this->assertSame(
			array( 'a@example.org', 'b@example.org' ),
			Recipients::parse( ' a@example.org , b@example.org ', 10 )
		);
	}

	public function test_it_also_splits_on_semicolons_and_newlines(): void {
		$this->assertSame(
			array( 'a@example.org', 'b@example.org', 'c@example.org' ),
			Recipients::parse( "a@example.org;b@example.org\nc@example.org", 10 )
		);
	}

	public function test_it_drops_empty_pieces(): void {
		$this->assertSame( array( 'a@example.org' ), Recipients::parse( ',,a@example.org, ,', 10 ) );
	}

	public function test_it_deduplicates_case_insensitively_keeping_the_first_spelling(): void {
		$this->assertSame(
			array( 'Ops@Example.org' ),
			Recipients::parse( 'Ops@Example.org, ops@example.org', 10 )
		);
	}

	public function test_it_caps_the_list(): void {
		$this->assertSame(
			array( 'a@example.org', 'b@example.org' ),
			Recipients::parse( 'a@example.org,b@example.org,c@example.org', 2 )
		);
	}

	public function test_a_non_positive_cap_yields_nothing(): void {
		$this->assertSame( array(), Recipients::parse( 'a@example.org', 0 ) );
	}

	public function test_empty_input_yields_an_empty_list(): void {
		$this->assertSame( array(), Recipients::parse( '   ', 10 ) );
	}

	public function test_join_round_trips_through_parse(): void {
		$joined = Recipients::join( array( 'a@example.org', 'b@example.org' ) );

		$this->assertSame( 'a@example.org, b@example.org', $joined );
		$this->assertSame( array( 'a@example.org', 'b@example.org' ), Recipients::parse( $joined, 10 ) );
	}
}
