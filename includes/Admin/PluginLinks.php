<?php
/**
 * Wynko's own row on the Plugins screen.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Settings action link and a Documentation row-meta link to Wynko's
 * entry on the Plugins screen. Registered against the plugin's own basename,
 * so it never touches another plugin's row.
 */
final class PluginLinks {

	/**
	 * Prepends a Settings link, so it reads "Settings | Deactivate" ahead of
	 * the links WordPress already built for this row.
	 *
	 * @param array<int,string> $links Existing action links.
	 * @return array<int,string>
	 */
	public static function action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Menu::url( Menu::PARENT ) ),
			esc_html__( 'Settings', 'wynko-for-laposta' )
		);

		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Appends a Documentation link to Wynko's row meta.
	 *
	 * @param array<int,string> $meta Existing row-meta links.
	 * @param string            $file Basename of the plugin the row belongs to.
	 * @return array<int,string>
	 */
	public static function row_meta( array $meta, string $file ): array {
		if ( plugin_basename( WYNKO_FILE ) !== $file ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s" target="%s" rel="%s">%s</a>',
			esc_url( Urls::url( 'documentation' ) ),
			esc_attr( Urls::target( 'documentation' ) ),
			esc_attr( Urls::rel( 'documentation' ) ),
			esc_html__( 'Documentation', 'wynko-for-laposta' )
		);

		return $meta;
	}
}
