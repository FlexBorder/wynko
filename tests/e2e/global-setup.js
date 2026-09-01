/**
 * Runs once before the whole suite: authenticates against wp-env the same
 * way the wp-scripts package's own default global setup does, activates
 * Wynko and a permalink structure on the *tests* instance (which wp-env
 * leaves bare), downloads each caching plugin (activated per-spec, never
 * here), and injects the live Laposta test key.
 */

const fs = require( 'fs' );
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

const { wpCli } = require( './wp-cli' );
const { ARTIFACTS_DIR, SKIP_MARKER_PATH, TEST_LIST_ID } = require( './env' );
const { pageCaches } = require( './page-cache' );

/**
 * @param {import('@playwright/test').FullConfig} config Playwright's resolved config.
 * @return {Promise<void>}
 */
module.exports = async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );
	await requestUtils.setupRest();
	await requestContext.dispose();

	fs.mkdirSync( ARTIFACTS_DIR, { recursive: true } );

	// wp-env activates mapped plugins on the development instance only; the
	// tests instance this suite drives starts bare.
	await wpCli( [ 'plugin', 'activate', 'wynko-for-laposta' ] );

	// A named permalink structure so form pages get clean URLs and the
	// caching plugins that key their cache / .htaccess rules off the path
	// (WP Fastest Cache, W3 Total Cache) behave as they would in production.
	await wpCli( [ 'rewrite', 'structure', '/%postname%/', '--hard' ] );
	await wpCli( [ 'rewrite', 'flush', '--hard' ] );

	// Download-only: each caching plugin is activated per-spec (the two
	// drift specs), bracketed to just the matrix row that needs it, and
	// deactivated again afterwards.
	for ( const cache of pageCaches ) {
		await cache.install();
	}

	const liveKey = process.env.WYNKO_TEST_API_KEY || '';
	if ( '' === liveKey ) {
		fs.writeFileSync(
			SKIP_MARKER_PATH,
			'No WYNKO_TEST_API_KEY in the environment; live-key specs skip.\n'
		);
		return;
	}

	if ( fs.existsSync( SKIP_MARKER_PATH ) ) {
		fs.unlinkSync( SKIP_MARKER_PATH );
	}

	// Deliberately WYNKO_API_KEY, not a per-blog constant: this is a single-
	// site wp-env instance for this suite. Lands only in the container's
	// wp-config.php, never in the repo. See ApiKey::resolve()'s precedence —
	// a constant beats the stored option. No --raw: the key is an arbitrary
	// string and must be written quoted, or wp-config.php stops parsing
	// (a leading-digit key like "27e6…" reads as a malformed float literal).
	await wpCli( [
		'config',
		'set',
		'WYNKO_API_KEY',
		liveKey,
		'--type=constant',
	] );

	// Warm Wynko's field-definitions transient once, up front. Every spec
	// renders a form, and a cold transient makes the render fetch fields
	// synchronously from inside the web container — a call that is slow and
	// occasionally fails there, producing an empty "could not load fields"
	// form. A production site's cache is warm; this mirrors that.
	if ( '' !== TEST_LIST_ID ) {
		await wpCli( [
			'eval',
			`\\Wynko\\Api\\Fields::for_list( '${ TEST_LIST_ID.replace(
				/'/g,
				"\\'"
			) }', true );`,
		] );
	}
};
