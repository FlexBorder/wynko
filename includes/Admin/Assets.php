<?php
/**
 * Admin asset registration.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class that enqueues the plugin's admin bundle. Loading it on every
 * admin screen would put Wynko's CSS in front of every other plugin's, so the
 * hook suffix is matched against the screens Menu registers.
 */
final class Assets {

	const HANDLE = 'wynko-admin';

	/**
	 * Whether the current admin page is one of the plugin's own.
	 *
	 * WordPress builds a hook suffix from the menu slug, prefixed by either
	 * toplevel_page_ or the sanitized parent title. Matching the trailing slug
	 * avoids depending on how that title sanitizes.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return bool
	 */
	public static function is_wynko_screen( string $hook_suffix ): bool {
		foreach ( Menu::screens() as $screen ) {
			if ( '' !== $screen['slug'] && str_ends_with( $hook_suffix, '_page_' . $screen['slug'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueues the admin bundle on the plugin's own screens.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! self::is_wynko_screen( $hook_suffix ) || ! current_user_can( Menu::CAP ) ) {
			return;
		}

		$asset = self::asset_meta();

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/admin/forms.js', WYNKO_FILE ),
			array_merge( $asset['dependencies'], array( 'jquery-ui-sortable' ) ),
			$asset['version'],
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'build/admin/forms.css', WYNKO_FILE ),
			array(),
			$asset['version']
		);

		wp_localize_script(
			self::HANDLE,
			'wynkoAdmin',
			array(
				'restRoot' => esc_url_raw( rest_url( 'wynko/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				// The admin bundle has no wp_set_script_translations(), so a
				// __() inside it would be extracted and never translated at
				// runtime. Its strings are handed over here instead.
				'i18n'     => array(
					'copied'         => __( 'Copied', 'wynko-for-laposta' ),
					'loadFail'       => __( 'Could not load the fields for this list. Check the API key on the Settings screen.', 'wynko-for-laposta' ),
					'discardWork'    => __( 'This replaces the fields below, and you have changes that are not saved yet. Continue and lose them?', 'wynko-for-laposta' ),
					// Same sentence FieldRows puts on a read-only placeholder box;
					// this is the copy the script applies when the mode changes
					// without a reload.
					'placeholderOff' => __( 'Set "Field labels" above to show a placeholder before typing one.', 'wynko-for-laposta' ),
					// The mirror of the line above, for a label the form will
					// not display; same copy FieldRows puts on the read-only box.
					'labelOff'       => __( 'This form shows placeholders instead of labels, so this label is not displayed.', 'wynko-for-laposta' ),
				),
			)
		);
	}

	/**
	 * The build's dependency list and cache-busting version, or empty values
	 * when the bundle has not been built.
	 *
	 * @return array{dependencies:array<int,string>,version:string|false}
	 */
	private static function asset_meta(): array {
		$file = plugin_dir_path( WYNKO_FILE ) . 'build/admin/forms.asset.php';

		/**
		 * Typed to match the method's return annotation.
		 *
		 * @var array{dependencies:array<int,string>,version:string|false} $meta
		 */
		$meta = is_readable( $file ) ? require $file : array(
			'dependencies' => array(),
			'version'      => false,
		);

		return $meta;
	}
}
