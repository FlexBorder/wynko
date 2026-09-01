/**
 * Whether a spec needing the live Laposta key (and the test list id) may
 * run. Specs call requireLiveKey( test ) at the top of a spec file (module
 * scope, not inside a test body), so a missing key produces a clean skip
 * of the whole file, never a failure.
 */

const fs = require( 'fs' );

const { SKIP_MARKER_PATH, TEST_LIST_ID } = require( './env' );

/**
 * Skips every test in the calling file when the live key (or the test list
 * id) isn't configured. Uses the callback-condition form of test.skip(),
 * since a plain boolean is only valid inside a running test's own body —
 * this runs at file/module scope, before any test has started.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').test} test Playwright's test object.
 * @return {void}
 */
function requireLiveKey( test ) {
	test.skip(
		() => fs.existsSync( SKIP_MARKER_PATH ),
		'WYNKO_TEST_API_KEY is not set — skipping specs that need the live Laposta account.'
	);
	test.skip(
		() => '' === TEST_LIST_ID,
		'WYNKO_TEST_LIST_ID is not set — skipping specs that need "lijst om te testen".'
	);
}

module.exports = { requireLiveKey };
