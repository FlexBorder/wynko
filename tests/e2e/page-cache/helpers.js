/**
 * Shared plumbing for the page-cache adapters. Everything here routes
 * through WP-CLI against the wp-env container — no adapter touches the
 * filesystem or the network directly.
 */

const { wpCli, evalPhp } = require( '../wp-cli' );

/**
 * Reads the monotonic render counter the e2e mu-plugin
 * (tests/e2e/mu-plugins/wynko-e2e.php) bumps once per request that actually
 * renders the signup form in PHP. A page served from a full-page cache
 * never runs PHP, so a genuine cache hit leaves this unchanged.
 *
 * @return {Promise<number>} The current count.
 */
async function renderCount() {
	const out = await evalPhp(
		"echo (int) get_option( 'wynko_e2e_render_count', 0 );"
	);
	return parseInt( out, 10 ) || 0;
}

/**
 * Throws unless `wp-content/advanced-cache.php` is absent. The drop-in is a
 * single global file shared by every caching plugin; a leaked one would run
 * the next matrix row under the wrong plugin, silently.
 *
 * @param {string} slug The adapter slug, for the error message.
 * @return {Promise<void>}
 */
async function assertNoDropin( slug ) {
	const out = await evalPhp(
		"echo file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'present' : 'absent';"
	);
	if ( out.trim() !== 'absent' ) {
		throw new Error(
			`page-cache[${ slug }]: wp-content/advanced-cache.php survived disable() — ` +
				'the next caching plugin would run under this drop-in. Aborting.'
		);
	}
}

/**
 * Removes the advanced-cache.php drop-in and the wp-content/cache directory,
 * so a stale drop-in or cache dir from one matrix row cannot serve the next.
 *
 * @return {Promise<void>}
 */
async function purgeCacheArtifacts() {
	await evalPhp(
		"$d = WP_CONTENT_DIR . '/advanced-cache.php'; if ( file_exists( $d ) ) { @unlink( $d ); } " +
			"$c = WP_CONTENT_DIR . '/cache'; if ( is_dir( $c ) ) { " +
			'$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $c, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); ' +
			'foreach ( $it as $p ) { $p->isDir() ? @rmdir( $p->getPathname() ) : @unlink( $p->getPathname() ); } @rmdir( $c ); } ' +
			"echo 'ok';"
	);
}

/**
 * Sets or clears the WP_CACHE constant in the container's wp-config.php.
 * Done here (not in .wp-env.json) so ordinary wp-env sessions used for
 * manual verification are never silently cached.
 *
 * @param {boolean} on Whether page caching should be possible at all.
 * @return {Promise<void>}
 */
async function setWpCache( on ) {
	if ( on ) {
		await wpCli( [
			'config',
			'set',
			'WP_CACHE',
			'true',
			'--raw',
			'--type=constant',
		] );
		return;
	}
	await wpCli( [ 'config', 'delete', 'WP_CACHE' ] ).catch( () => {
		// Already absent.
	} );
}

/**
 * Installs a plugin by slug, tolerating "already installed".
 *
 * @param {string} slug Plugin slug.
 * @return {Promise<void>}
 */
async function installPlugin( slug ) {
	await wpCli( [ 'plugin', 'install', slug ] ).catch( () => {
		// Already present from a previous run.
	} );
}

/**
 * Deactivates a plugin by slug, tolerating "already inactive / not installed".
 *
 * @param {string} slug Plugin slug.
 * @return {Promise<void>}
 */
async function deactivatePlugin( slug ) {
	await wpCli( [ 'plugin', 'deactivate', slug ] ).catch( () => {} );
}

/**
 * Every caching plugin this suite drives. `advanced-cache.php` and WP_CACHE
 * are global, so a matrix row must not inherit another plugin's drop-in or
 * an active competitor — even if the previous row's disable() half-failed.
 *
 * @type {string[]}
 */
const ALL_CACHE_SLUGS = [
	'cache-enabler',
	'wp-super-cache',
	'w3-total-cache',
	'wp-fastest-cache',
];

/**
 * Brings the site to a known no-cache state: every caching plugin off, the
 * drop-in and cache directory gone, WP_CACHE cleared. Called at the top of
 * every adapter's enable() so a row starts clean regardless of how the
 * previous row's teardown went.
 *
 * @return {Promise<void>}
 */
async function resetCacheState() {
	// One `wp plugin deactivate a b c d` instead of four round-trips: every
	// `npx @wordpress/env run` costs seconds, and this runs in each adapter's
	// enable(), i.e. once per matrix row. Already-inactive slugs are a
	// tolerated warning, not a failure.
	await wpCli( [ 'plugin', 'deactivate', ...ALL_CACHE_SLUGS ] ).catch(
		() => {}
	);
	await purgeCacheArtifacts();
	await setWpCache( false );
}

module.exports = {
	renderCount,
	assertNoDropin,
	purgeCacheArtifacts,
	resetCacheState,
	setWpCache,
	installPlugin,
	deactivatePlugin,
};
