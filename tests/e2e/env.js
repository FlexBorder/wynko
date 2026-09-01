/**
 * Shared runtime configuration for the e2e suite: paths, the live-key skip
 * marker, and the per-run id used to namespace test data.
 */

const path = require( 'path' );

const ARTIFACTS_DIR =
	process.env.WP_ARTIFACTS_PATH || path.join( process.cwd(), 'artifacts' );

/**
 * Written by global-setup.js when WYNKO_TEST_API_KEY is unset; every spec
 * needing the live key checks for this file and calls test.skip() rather
 * than failing, so a contributor without the key isn't blocked.
 */
const SKIP_MARKER_PATH = path.join( ARTIFACTS_DIR, 'wynko-e2e-skip-live-key' );

/**
 * One run's id, used to namespace test-created data (emails, field labels)
 * so a crashed run's leftovers are easy to spot and sweep.
 */
const RUN_ID =
	process.env.GITHUB_RUN_ID ||
	process.env.WYNKO_E2E_RUN_ID ||
	String( Date.now() );

/**
 * The Laposta list this suite is allowed to add/remove fields and members
 * on ("lijst om te testen"). laposta-client.js deliberately exposes no
 * list-lookup call (its path allowlist is field/member only), so resolving
 * the list's name to its id is out of scope for this harness — the list id
 * itself must be supplied directly.
 */
const TEST_LIST_ID = process.env.WYNKO_TEST_LIST_ID || '';

/**
 * Prefix for Laposta fields this suite creates, so global-teardown.js's
 * best-effort sweep can recognize and remove leftovers by name.
 */
const FIELD_NAME_PREFIX = 'wynko_e2e_';

/**
 * Builds one test-run's unique signup email.
 *
 * @param {string} n A per-spec, per-scenario label (need not be numeric).
 * @return {string} The email address.
 */
function testEmail( n ) {
	return `wynko-e2e-${ RUN_ID }-${ n }@flex-border.com`;
}

module.exports = {
	ARTIFACTS_DIR,
	SKIP_MARKER_PATH,
	RUN_ID,
	TEST_LIST_ID,
	FIELD_NAME_PREFIX,
	testEmail,
};
