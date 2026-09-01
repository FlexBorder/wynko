/**
 * Without JavaScript, a stale submit nonce has no client-side retry: the
 * form posts straight to admin-post.php, FormSubmitHandler::handle()
 * answers a bad nonce and an unknown form identically (404, "That form
 * does not exist."), and nothing retries. This is TD-068's documented,
 * accepted gap — this spec validates that the gap stays exactly as scoped,
 * it is not a bug hunt: a 404 here is the PASSING outcome, not a failure.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const { requireLiveKey } = require( '../live-key' );
const { testEmail } = require( '../env' );
const { wpCli } = require( '../wp-cli' );
const {
	createSignupForm,
	createSignupPage,
	deleteSignupForm,
	deleteSignupPage,
} = require( '../fixtures/signup-form' );

requireLiveKey( test );

const SHORT_NONCE_LIFE_SECONDS = 12;

let formId;
let pagePath;
let pageId;

test.beforeAll( async () => {
	formId = await createSignupForm( 'E2E nonce no-js' );
	( { pageId, path: pagePath } = await createSignupPage(
		'E2E nonce no-js page',
		formId
	) );
	await wpCli( [
		'option',
		'update',
		'wynko_e2e_short_nonce_life',
		String( SHORT_NONCE_LIFE_SECONDS ),
	] );
} );

test.afterAll( async () => {
	await wpCli( [ 'option', 'delete', 'wynko_e2e_short_nonce_life' ] );
	await deleteSignupPage( pageId );
	await deleteSignupForm( formId );
} );

test( 'a stale nonce with no JS gets a 404 and no retry', async ( {
	browser,
} ) => {
	// A fresh no-JS context, rather than page.setJavaScriptEnabled(), so
	// form.js's submit-event listener never attaches in the first place —
	// the same as a real no-JS visitor, not JS that's merely been muted
	// mid-session.
	const context = await browser.newContext( { javaScriptEnabled: false } );
	const page = await context.newPage();

	try {
		await page.goto( pagePath );

		// Wait past the shortened nonce life so the embedded nonce is stale
		// by the time the form is submitted.
		await page.waitForTimeout( ( SHORT_NONCE_LIFE_SECONDS + 2 ) * 1000 );

		await page
			.locator( 'input[name="wynko_email"]' )
			.fill( testEmail( 'nonce-no-js' ) );

		const [ response ] = await Promise.all( [
			page.waitForResponse( ( res ) =>
				res.url().includes( 'admin-post.php' )
			),
			page.locator( '.wynko-form__submit' ).click(),
		] );

		expect( response.status() ).toBe( 404 );
		await expect(
			page.getByText( 'That form does not exist.' )
		).toBeVisible();
	} finally {
		await context.close();
	}
} );
