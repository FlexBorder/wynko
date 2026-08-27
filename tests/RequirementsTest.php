<?php
/**
 * Tests for the pure requirements comparison layer.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Support\Requirements;
use PHPUnit\Framework\TestCase;

/** Covers version verdicts, module diffing, byte parsing, and database identification. */
final class RequirementsTest extends TestCase {

	/**
	 * A version at or above the advised one draws no complaint.
	 *
	 * @return void
	 */
	public function test_at_or_above_advised_is_ok(): void {
		$this->assertSame( Requirements::STATUS_OK, Requirements::classify( '8.2.0', '8.0', '8.2' ) );
		$this->assertSame( Requirements::STATUS_OK, Requirements::classify( '8.3.1', '8.0', '8.2' ) );
	}

	/**
	 * Meeting the floor but not the recommendation is its own verdict.
	 *
	 * @return void
	 */
	public function test_meeting_required_but_below_advised(): void {
		$this->assertSame( Requirements::STATUS_BELOW_ADVISED, Requirements::classify( '8.0.30', '8.0', '8.2' ) );
	}

	/**
	 * Below the floor is the only verdict that means "this does not work".
	 *
	 * @return void
	 */
	public function test_below_required(): void {
		$this->assertSame( Requirements::STATUS_BELOW_REQUIRED, Requirements::classify( '7.4.33', '8.0', '8.2' ) );
	}

	/**
	 * A patch release of the advised minor is not behind it.
	 *
	 * @return void
	 */
	public function test_a_patch_release_of_the_advised_minor_is_ok(): void {
		$this->assertSame( Requirements::STATUS_OK, Requirements::classify( '6.7.1', '6.4', '6.7' ) );
	}

	/**
	 * A reading we could not take is unknown, not a failure.
	 *
	 * @return void
	 */
	public function test_an_unreadable_current_version_is_unknown(): void {
		$this->assertSame( Requirements::STATUS_UNKNOWN, Requirements::classify( '', '8.0', '8.2' ) );
	}

	/**
	 * An undeclared threshold is skipped rather than failed against.
	 *
	 * @return void
	 */
	public function test_an_absent_threshold_is_skipped(): void {
		$this->assertSame( Requirements::STATUS_OK, Requirements::classify( '7.4', '', '' ) );
		$this->assertSame( Requirements::STATUS_BELOW_REQUIRED, Requirements::classify( '7.4', '8.0', '' ) );
	}

	/**
	 * The missing list keeps the order in which things were wanted.
	 *
	 * @return void
	 */
	public function test_missing_reports_only_what_is_absent_in_order(): void {
		$this->assertSame(
			array( 'sodium', 'curl' ),
			Requirements::missing( array( 'json', 'sodium', 'mbstring', 'curl' ), array( 'mbstring', 'json' ) )
		);
		$this->assertSame( array(), Requirements::missing( array( 'json' ), array( 'json' ) ) );
	}

	/**
	 * The php.ini suffixes are binary multiples, not decimal ones.
	 *
	 * @return void
	 */
	public function test_bytes_from_ini_reads_the_suffixes(): void {
		$this->assertSame( 268435456, Requirements::bytes_from_ini( '256M' ) );
		$this->assertSame( 1073741824, Requirements::bytes_from_ini( '1G' ) );
		$this->assertSame( 131072, Requirements::bytes_from_ini( '128k' ) );
		$this->assertSame( 512, Requirements::bytes_from_ini( '512' ) );
	}

	/**
	 * Unlimited and unreadable are different answers.
	 *
	 * @return void
	 */
	public function test_bytes_from_ini_keeps_unlimited_and_rejects_nonsense(): void {
		$this->assertSame( -1, Requirements::bytes_from_ini( '-1' ) );
		$this->assertSame( 0, Requirements::bytes_from_ini( '' ) );
		$this->assertSame( 0, Requirements::bytes_from_ini( 'plenty' ) );
	}

	/**
	 * Sizes scale to the largest unit that leaves a readable number.
	 *
	 * @return void
	 */
	public function test_format_bytes_scales_and_trims(): void {
		$this->assertSame( '256 MB', Requirements::format_bytes( 268435456 ) );
		$this->assertSame( '1 GB', Requirements::format_bytes( 1073741824 ) );
		$this->assertSame( '1.5 MB', Requirements::format_bytes( 1572864 ) );
		$this->assertSame( '512 B', Requirements::format_bytes( 512 ) );
	}

	/**
	 * Wording "unlimited" is the caller's job, not this layer's.
	 *
	 * @return void
	 */
	public function test_format_bytes_leaves_unlimited_to_the_caller(): void {
		$this->assertSame( '', Requirements::format_bytes( -1 ) );
	}

	/**
	 * A bare version number is MySQL.
	 *
	 * @return void
	 */
	public function test_database_server_identifies_mysql(): void {
		$this->assertSame(
			array(
				'name'    => 'MySQL',
				'version' => '8.0.36',
			),
			Requirements::database_server( '8.0.36' )
		);
	}

	/**
	 * Both MariaDB banner shapes read as the same version.
	 *
	 * @return void
	 */
	public function test_database_server_identifies_mariadb_in_both_shapes(): void {
		$this->assertSame(
			array(
				'name'    => 'MariaDB',
				'version' => '10.6.12',
			),
			Requirements::database_server( '10.6.12-MariaDB-1:10.6.12+maria~deb11' )
		);
		// MariaDB reports a 5.5.5- prefix to clients that predate its versioning.
		$this->assertSame(
			array(
				'name'    => 'MariaDB',
				'version' => '10.4.28',
			),
			Requirements::database_server( '5.5.5-10.4.28-MariaDB' )
		);
	}

	/**
	 * An unreadable banner reports nothing rather than guessing.
	 *
	 * @return void
	 */
	public function test_database_server_gives_up_cleanly(): void {
		$this->assertSame(
			array(
				'name'    => '',
				'version' => '',
			),
			Requirements::database_server( 'nonsense' )
		);
	}

	/**
	 * @dataProvider openssl_banners
	 *
	 * @param string $banner   Raw banner.
	 * @param string $expected Version the banner yields.
	 */
	public function test_an_openssl_banner_yields_a_comparable_version( string $banner, string $expected ): void {
		$this->assertSame( $expected, Requirements::openssl_version( $banner ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function openssl_banners(): array {
		return array(
			'OPENSSL_VERSION_TEXT'      => array( 'OpenSSL 3.0.13 30 Jan 2024', '3.0.13' ),
			'a letter-suffixed release' => array( 'OpenSSL 1.1.1w  11 Sep 2023', '1.1.1' ),
			'curl_version()'            => array( 'OpenSSL/3.2.1', '3.2.1' ),
			// LibreSSL numbers its releases on its own scale, where 3.3 is not
			// newer than OpenSSL 3.0. Comparing across the two would invent a
			// verdict, so it reads as unknown instead.
			'LibreSSL'                  => array( 'LibreSSL/3.3.6', '' ),
			'BoringSSL'                 => array( 'BoringSSL', '' ),
			'nothing at all'            => array( '', '' ),
			'no version in it'          => array( 'OpenSSL', '' ),
		);
	}
}
