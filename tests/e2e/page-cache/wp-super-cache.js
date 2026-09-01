/**
 * WP Super Cache (Automattic) adapter, PHP caching mode (no mod_rewrite).
 *
 * The plugin ships no `wp super-cache` WP-CLI command (a long-dead
 * third-party package once did), so enable/flush/disable drive its own
 * internal functions through `wp eval`: wp_cache_enable(), wp_cache_disable()
 * and wpsc_delete_files() are all defined once the plugin is active.
 * https://github.com/Automattic/wp-super-cache
 */

const { wpCli, evalPhp } = require( '../wp-cli' );
const {
	assertNoDropin,
	purgeCacheArtifacts,
	resetCacheState,
	setWpCache,
	installPlugin,
	deactivatePlugin,
} = require( './helpers' );

const SLUG = 'wp-super-cache';

/**
 * Deletes every cached page.
 *
 * @return {Promise<void>}
 */
async function flush() {
	await evalPhp(
		'if ( function_exists( "wp_cache_clean_cache" ) ) { global $file_prefix; wp_cache_clean_cache( $file_prefix, true ); } ' +
			'elseif ( function_exists( "wpsc_delete_files" ) ) { wpsc_delete_files( get_supercache_dir() ); } echo "ok";'
	);
}

module.exports = {
	slug: SLUG,
	label: 'WP Super Cache',

	async install() {
		await installPlugin( SLUG );
	},

	async enable() {
		await resetCacheState();
		await setWpCache( true );
		await wpCli( [ 'plugin', 'activate', SLUG ] );
		await evalPhp(
			'global $wp_cache_mod_rewrite, $cache_enabled, $super_cache_enabled; ' +
				'$wp_cache_mod_rewrite = 0; ' +
				'if ( function_exists( "wp_cache_create_advanced_cache" ) ) { wp_cache_create_advanced_cache(); } ' +
				'if ( function_exists( "wp_cache_enable" ) ) { wp_cache_enable(); } ' +
				'if ( function_exists( "wp_super_cache_enable" ) ) { wp_super_cache_enable(); } ' +
				'if ( function_exists( "wp_cache_setting" ) ) { wp_cache_setting( "wp_cache_mod_rewrite", 0 ); } ' +
				'echo "ok";'
		);
		await flush();
	},

	flush,

	async assertServedFromCache() {},

	async disable() {
		await evalPhp(
			'if ( function_exists( "wp_cache_disable" ) ) { wp_cache_disable(); } ' +
				'if ( function_exists( "wp_super_cache_disable" ) ) { wp_super_cache_disable(); } echo "ok";'
		).catch( () => {} );
		await flush().catch( () => {} );
		await deactivatePlugin( SLUG );
		await purgeCacheArtifacts();
		await setWpCache( false );
		await assertNoDropin( SLUG );
	},
};
