/**
 * W3 Total Cache (BoldGrid) adapter — page cache, disk-basic method.
 *
 * W3TC ships a `wp w3-total-cache` command. Turning on `pgcache.enabled`
 * (with `pgcache.engine file`) and flushing is enough for it to write its
 * advanced-cache.php drop-in and start serving cached pages; no direct
 * poking at its config classes is needed (an earlier version tried, via
 * `\W3TC\Dispatcher`, and hung).
 * https://github.com/BoldGrid/w3-total-cache
 */

const { wpCli } = require( '../wp-cli' );
const {
	assertNoDropin,
	purgeCacheArtifacts,
	resetCacheState,
	setWpCache,
	installPlugin,
	deactivatePlugin,
} = require( './helpers' );

const SLUG = 'w3-total-cache';

/**
 * Runs a `wp w3-total-cache` subcommand.
 *
 * @param {string[]} args Subcommand and its arguments.
 * @return {Promise<string>} WP-CLI stdout.
 */
function w3( args ) {
	return wpCli( [ 'w3-total-cache', ...args ] );
}

/**
 * Flushes all W3TC caches.
 *
 * @return {Promise<void>}
 */
async function flush() {
	await w3( [ 'flush', 'all' ] ).catch( () => {} );
}

module.exports = {
	slug: SLUG,
	label: 'W3 Total Cache',

	async install() {
		await installPlugin( SLUG );
	},

	async enable() {
		await resetCacheState();
		await setWpCache( true );
		await wpCli( [ 'plugin', 'activate', SLUG ] );
		await w3( [
			'option',
			'set',
			'pgcache.enabled',
			'true',
			'--type=boolean',
		] );
		await w3( [ 'option', 'set', 'pgcache.engine', 'file' ] );
		await flush();
	},

	flush,

	async assertServedFromCache() {},

	async disable() {
		await w3( [
			'option',
			'set',
			'pgcache.enabled',
			'false',
			'--type=boolean',
		] ).catch( () => {} );
		await flush().catch( () => {} );
		await deactivatePlugin( SLUG );
		await purgeCacheArtifacts();
		await setWpCache( false );
		await assertNoDropin( SLUG );
	},
};
