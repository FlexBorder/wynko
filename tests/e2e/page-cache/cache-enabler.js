/**
 * Cache Enabler (KeyCDN) adapter. Pure-PHP drop-in cache, no rewrite rules —
 * the simplest of the four, used as the canary that the adapter contract and
 * the render-count probe are wired correctly.
 *
 * Enable path: activating the plugin with WP_CACHE truthy is enough for it
 * to install its advanced-cache.php drop-in and start caching with default
 * settings. https://wordpress.org/plugins/cache-enabler/
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

const SLUG = 'cache-enabler';

/**
 * Clears every cached page.
 *
 * @return {Promise<void>}
 */
async function flush() {
	await wpCli( [ 'cache-enabler', 'clear' ] ).catch( async () => {
		await evalPhp(
			'if ( class_exists( "Cache_Enabler" ) ) { Cache_Enabler::clear_complete_cache(); } echo "ok";'
		);
	} );
}

module.exports = {
	slug: SLUG,
	label: 'Cache Enabler',

	async install() {
		await installPlugin( SLUG );
	},

	async enable() {
		await resetCacheState();
		await setWpCache( true );
		await wpCli( [ 'plugin', 'activate', SLUG ] );
		// Force the drop-in in case activation did not (re)write it.
		await evalPhp(
			'if ( class_exists( "Cache_Enabler_Disk" ) && method_exists( "Cache_Enabler_Disk", "setup" ) ) { Cache_Enabler_Disk::setup(); } echo "ok";'
		);
		await flush();
	},

	flush,

	async assertServedFromCache() {},

	async disable() {
		await flush().catch( () => {} );
		await deactivatePlugin( SLUG );
		await purgeCacheArtifacts();
		await setWpCache( false );
		await assertNoDropin( SLUG );
	},
};
