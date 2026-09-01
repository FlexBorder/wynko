/**
 * With JavaScript on, a stale submit nonce (baked into a page a cache kept
 * around past NONCE_LIFE) must self-heal: src/frontend/form.js retries once
 * against a freshly fetched nonce (see form.js's refreshNonce()), so the
 * visitor sees a normal success — no visible error, and nothing in the
 * activity log beyond the ordinary "new signup" entry any successful
 * submission writes (the recovery itself is deliberately unlogged: nothing
 * about it is a problem an admin needs to act on).
 *
 * Runs in a logged-out browser context: the submit nonce is user-scoped, so
 * form.js's nonce-refresh request and its retried submit must resolve to the
 * same user — a real visitor is never authenticated.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const { requireLiveKey } = require( '../live-key' );
const { TEST_LIST_ID, testEmail } = require( '../env' );
const { wpCli } = require( '../wp-cli' );
const {
	createSignupForm,
	createSignupPage,
	deleteSignupForm,
	deleteSignupPage,
} = require( '../fixtures/signup-form' );
const { listMembers, deleteMember } = require( '../laposta-client' );
const { countEntries } = require( '../activity-log' );
const { fillAndSubmit } = require( '../form-fill' );

requireLiveKey( test );

const SHORT_NONCE_LIFE_SECONDS = 12;
const email = testEmail( 'nonce-self-heal' );

let formId;
let pagePath;
let pageId;

test.beforeAll( async () => {
	// Deliberately no "nonce" in the form name: a successful signup logs
	// `New signup through "<form name>"`, and the silence assertion below
	// matches log messages by the substring "nonce".
	formId = await createSignupForm( 'E2E stale-token self-heal' );
	( { pageId, path: pagePath } = await createSignupPage(
		'E2E stale-token self-heal page',
		formId
	) );
	await wpCli( [
		'option',
		'update',
		'wynko_e2e_short_nonce_life',
		String( SHORT_NONCE_LIFE_SECONDS ),
	] );
} );

test.afterAll( async ( { request } ) => {
	await wpCli( [ 'option', 'delete', 'wynko_e2e_short_nonce_life' ] );
	await deleteSignupPage( pageId );
	await deleteSignupForm( formId );

	const liveKey = process.env.WYNKO_TEST_API_KEY;
	const members = await listMembers( request, liveKey, TEST_LIST_ID, email );
	for ( const member of members ) {
		await deleteMember( request, liveKey, TEST_LIST_ID, member.member_id );
	}
} );

test( 'a stale nonce self-heals into a successful signup, silently', async ( {
	browser,
	request,
} ) => {
	// A logged-out context, like the drift specs: a real signup visitor is
	// never authenticated, and the submit nonce is user-scoped — the default
	// `page` fixture's stored admin session would have form.js's nonce-refresh
	// and its retried submit resolve to different users.
	const context = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	const page = await context.newPage();

	try {
		const logCountBefore = await countEntries( 'nonce' );

		await page.goto( pagePath );

		// Wait past the shortened nonce life so the embedded nonce is now stale.
		await page.waitForTimeout( ( SHORT_NONCE_LIFE_SECONDS + 2 ) * 1000 );

		// Every field the form shows, not just the email: the test list
		// carries its own required custom fields, so an email-only submission
		// would fail validation even with a perfectly refreshed nonce.
		await fillAndSubmit( page, { email } );

		await expect(
			page.getByText( 'That form does not exist.' )
		).not.toBeVisible();

		const liveKey = process.env.WYNKO_TEST_API_KEY;
		await expect
			.poll(
				async () =>
					(
						await listMembers(
							request,
							liveKey,
							TEST_LIST_ID,
							email
						)
					).length,
				// The submission is a live round-trip to Laposta; 5s (the
				// default) is too tight once the machine is under load.
				{ timeout: 20_000 }
			)
			.toBe( 1 );

		// Self-heal is a deliberately silent path: the stale-then-refreshed
		// nonce must leave no trace in the activity log (the successful
		// signup itself still logs its own ordinary entry).
		expect( await countEntries( 'nonce' ) ).toBe( logCountBefore );
	} finally {
		await context.close();
	}
} );
