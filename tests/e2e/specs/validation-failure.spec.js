/**
 * A submission missing its required email never reaches Laposta:
 * FormValidator rejects it server-side, FormSubmitHandler answers
 * STATUS_INVALID, and the form re-renders the offending field marked
 * aria-invalid (FormRenderer::notice() deliberately renders no banner for
 * STATUS_INVALID — see its docblock) — no member is ever created.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const { requireLiveKey } = require( '../live-key' );
const { TEST_LIST_ID, testEmail } = require( '../env' );
const {
	createSignupForm,
	createSignupPage,
	deleteSignupForm,
	deleteSignupPage,
} = require( '../fixtures/signup-form' );
const { listMembers } = require( '../laposta-client' );

requireLiveKey( test );

// Never actually submitted — used only to prove no member was created under
// it despite this spec touching the form at all.
const email = testEmail( 'validation-failure' );

let formId;
let pagePath;
let pageId;

test.beforeAll( async () => {
	formId = await createSignupForm( 'E2E validation failure' );
	( { pageId, path: pagePath } = await createSignupPage(
		'E2E validation failure page',
		formId
	) );
} );

test.afterAll( async () => {
	await deleteSignupPage( pageId );
	await deleteSignupForm( formId );
} );

test( 'a missing required email is rejected and never reaches Laposta', async ( {
	page,
	request,
} ) => {
	await page.goto( pagePath );

	// The email field is left empty; the form carries novalidate="novalidate"
	// (see FormRenderer::render_with_result()) so the browser's own
	// constraint validation never intercepts this — the server-side rejection
	// is what this spec exercises.
	await page.locator( '.wynko-form__submit' ).click();

	await expect(
		page.locator( 'input[name="wynko_email"][aria-invalid="true"]' )
	).toBeVisible();
	await expect(
		page.getByText( 'That form does not exist.' )
	).not.toBeVisible();

	const liveKey = process.env.WYNKO_TEST_API_KEY;
	const members = await listMembers( request, liveKey, TEST_LIST_ID, email );
	expect( members ).toHaveLength( 0 );
} );
