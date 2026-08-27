<?php
/**
 * Tests for the admin menu.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Menu;
use PHPUnit\Framework\TestCase;

/**
 * Covers the slug/URL contract every admin redirect and link depends on.
 */
final class MenuTest extends TestCase {

	/**
	 * The parent screen resolves to the top-level admin URL.
	 *
	 * @return void
	 */
	public function test_url_for_parent_slug(): void {
		$this->assertSame(
			'http://example.org/wp-admin/admin.php?page=wynko-settings',
			Menu::url( Menu::PARENT )
		);
	}

	/**
	 * The forms screen resolves to its own admin URL.
	 *
	 * @return void
	 */
	public function test_url_for_forms_slug(): void {
		$this->assertSame(
			'http://example.org/wp-admin/admin.php?page=wynko-forms',
			Menu::url( Menu::FORMS )
		);
	}

	/**
	 * The log screen resolves to its own admin URL.
	 *
	 * @return void
	 */
	public function test_url_for_log_slug(): void {
		$this->assertSame(
			'http://example.org/wp-admin/admin.php?page=wynko-log',
			Menu::url( Menu::LOG )
		);
	}

	/**
	 * The menu sits below core's last separator (99), which is what puts Wynko
	 * at the bottom of the admin menu with a gap above it rather than crowded
	 * against Settings at 80. The fractional part keeps a plugin that also asks
	 * for 100 from taking the slot.
	 *
	 * @return void
	 */
	public function test_the_menu_sits_below_the_last_core_separator(): void {
		$this->assertTrue( is_numeric( Menu::POSITION ) );
		$this->assertGreaterThan( 99, (float) Menu::POSITION );
		$this->assertNotSame( (float) 100, (float) Menu::POSITION );
	}

	/**
	 * All screens are registered, each with a callable renderer, and the parent
	 * slug comes first because its submenu registration relabels the entry
	 * WordPress creates. Forms sits between Settings and Activity log.
	 *
	 * @return void
	 */
	public function test_screens_expose_all_slugs_with_callable_renderers(): void {
		$screens = Menu::screens();
		$slugs   = array_column( $screens, 'slug' );

		$this->assertSame( array( Menu::PARENT, Menu::FORMS, Menu::LOG ), $slugs );
		foreach ( $screens as $screen ) {
			$this->assertIsCallable( $screen['render'] );
			$this->assertNotSame( '', $screen['title'] );
		}
	}

	/**
	 * Every 'load' entry is a list of callables, not a single one, since more
	 * than one thing may need to run before a screen's output starts.
	 *
	 * @return void
	 */
	public function test_load_callbacks_are_each_callable(): void {
		foreach ( Menu::screens() as $screen ) {
			if ( ! isset( $screen['load'] ) ) {
				continue;
			}

			foreach ( $screen['load'] as $callback ) {
				$this->assertIsCallable( $callback );
			}
		}
	}
}
