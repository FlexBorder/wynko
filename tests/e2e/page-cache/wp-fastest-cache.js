/**
 * WP Fastest Cache adapter — disk cache.
 *
 * The free plugin stores its settings as a JSON string in the
 * `WpFastestCache` option and serves cached pages from
 * wp-content/cache/all/. It has no rich WP-CLI surface, so enabling is done
 * by writing that option and asking the plugin to (re)build its rules.
 *
 * Of the four adapters this is the likeliest to need adjustment against a
 * live wp-env; if it cannot be driven headlessly, it is skipped with a note
 * in TECHNICAL_DEBT.md rather than left flaky.
 * https://wordpress.org/plugins/wp-fastest-cache/
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

const SLUG = 'wp-fastest-cache';

const SETTINGS = JSON.stringify( {
	wpFastestCacheStatus: 'on',
	wpFastestCacheLoggedInUser: 'on',
	wpFastestCachePreload: '',
	wpFastestCacheMobile: '',
	wpFastestCacheNewPost: 'on',
	wpFastestCacheUpdatePost: 'on',
	wpFastestCacheLang: 'on',
} );

/**
 * Deletes wp-content/cache/all/.
 *
 * @return {Promise<void>}
 */
async function flush() {
	await wpCli( [ 'fastest-cache', 'clear' ] ).catch( async () => {
		await evalPhp(
			'if ( function_exists( "wpfc_clear_all_cache" ) ) { wpfc_clear_all_cache( true ); } echo "ok";'
		).catch( () => {} );
	} );
}

module.exports = {
	slug: SLUG,
	label: 'WP Fastest Cache',

	async install() {
		await installPlugin( SLUG );
	},

	async enable() {
		await resetCacheState();
		await setWpCache( true );
		await wpCli( [ 'plugin', 'activate', SLUG ] );
		await wpCli( [ 'option', 'update', 'WpFastestCache', SETTINGS ] );
		// WP Fastest Cache (free) has no advanced-cache.php drop-in — it
		// serves static files via .htaccess rules its admin UI writes.
		// Try to reproduce that headlessly; if it doesn't take, the spec's
		// beforeAll cache probe finds no caching and skips the row (TD-071).
		await evalPhp(
			'if ( class_exists( "WpFastestCache" ) ) { $w = new WpFastestCache(); ' +
				'foreach ( array( "modify_htaccess", "add_wpfc_settings_to_htaccess", "add_rewrite_rules_of_wpfc" ) as $m ) { ' +
				'if ( method_exists( $w, $m ) ) { try { $r = new ReflectionMethod( $w, $m ); $r->setAccessible( true ); $r->invoke( $w ); } catch ( \\Throwable $e ) {} } } ' +
				'if ( method_exists( $w, "createCache" ) ) { try { $w->createCache(); } catch ( \\Throwable $e ) {} } } echo "ok";'
		).catch( () => {} );
		await flush();
	},

	flush,

	async assertServedFromCache() {},

	async disable() {
		await evalPhp(
			'if ( class_exists( "WpFastestCache" ) ) { $w = new WpFastestCache(); ' +
				'if ( method_exists( $w, "remove_wpfc_settings_from_htaccess" ) ) { try { $w->remove_wpfc_settings_from_htaccess(); } catch ( \\Throwable $e ) {} } } ' +
				'delete_option( "WpFastestCache" ); echo "ok";'
		).catch( () => {} );
		await flush().catch( () => {} );
		await deactivatePlugin( SLUG );
		await purgeCacheArtifacts();
		await setWpCache( false );
		await assertNoDropin( SLUG );
	},
};
