<?php
/**
 * Tests for the Plugins screen row links.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Menu;
use Wynko\Admin\PluginLinks;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Settings action link and the Documentation row-meta link Wynko
 * adds to its own row on the Plugins screen.
 */
final class PluginLinksTest extends TestCase {

	/**
	 * The Settings link is prepended, so it reads ahead of Deactivate rather
	 * than after it.
	 *
	 * @return void
	 */
	public function test_settings_link_is_prepended_to_the_existing_links(): void {
		$links = PluginLinks::action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$first = array_values( $links )[0];
		$this->assertStringContainsString( Menu::url( Menu::PARENT ), $first );
		$this->assertStringContainsString( 'Settings', $first );
	}

	public function test_row_meta_adds_a_documentation_link_only_to_wynkos_own_row(): void {
		$meta = PluginLinks::row_meta( array( 'Version 1.0.0' ), plugin_basename( WYNKO_FILE ) );

		$this->assertCount( 2, $meta );
		$this->assertStringContainsString( 'Documentation', $meta[1] );
		$this->assertStringContainsString( 'getwynko.com/docs', $meta[1] );
		$this->assertStringContainsString( 'target="_blank"', $meta[1] );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $meta[1] );
	}

	public function test_row_meta_leaves_another_plugins_row_untouched(): void {
		$meta = array( 'Version 3.0.0' );

		$this->assertSame( $meta, PluginLinks::row_meta( $meta, 'some-other-plugin/plugin.php' ) );
	}
}
