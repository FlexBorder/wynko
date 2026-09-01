/**
 * Runs once after the whole suite: removes the injected API key constant and
 * does a best-effort sweep for any Laposta field left behind by a crashed
 * run (per-spec afterEach/afterAll hooks are the primary cleanup path; this
 * is only the safety net). All Laposta calls route through laposta-client.js
 * — this file never talks to api.laposta.nl itself.
 */

const fs = require( 'fs' );
const { request } = require( '@playwright/test' );

const { wpCli } = require( './wp-cli' );
const {
	SKIP_MARKER_PATH,
	TEST_LIST_ID,
	FIELD_NAME_PREFIX,
} = require( './env' );
const { listFields, deleteField } = require( './laposta-client' );
const { pageCaches } = require( './page-cache' );

module.exports = async function globalTeardown() {
	await wpCli( [ 'config', 'delete', 'WYNKO_API_KEY' ] ).catch( () => {
		// Never set (no live key this run) — nothing to remove.
	} );

	// Safety net: a spec that crashed mid-matrix-row may have left a caching
	// plugin active and its drop-in in place. Per-spec afterAll is the
	// primary path; this makes sure no plugin (and no advanced-cache.php)
	// outlives the run.
	for ( const cache of pageCaches ) {
		await cache.disable().catch( () => {} );
	}

	if ( fs.existsSync( SKIP_MARKER_PATH ) ) {
		fs.unlinkSync( SKIP_MARKER_PATH );
	}

	const liveKey = process.env.WYNKO_TEST_API_KEY || '';
	if ( '' === liveKey || '' === TEST_LIST_ID ) {
		return;
	}

	const requestContext = await request.newContext();
	try {
		const fields = await listFields(
			requestContext,
			liveKey,
			TEST_LIST_ID
		);
		for ( const field of fields ) {
			const name = String( field?.name || '' );
			if ( name.startsWith( FIELD_NAME_PREFIX ) ) {
				await deleteField(
					requestContext,
					liveKey,
					TEST_LIST_ID,
					field.field_id
				).catch( () => {
					// Best-effort: a field another parallel run already
					// removed is not this sweep's problem.
				} );
			}
		}
	} catch ( error ) {
		// Best-effort sweep only — a network hiccup here must not fail the
		// whole suite after every real assertion has already run.
	} finally {
		await requestContext.dispose();
	}
};
