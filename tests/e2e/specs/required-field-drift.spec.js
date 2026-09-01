/**
 * Cause (b): Laposta gains a new REQUIRED field while Wynko's own field
 * cache still holds the pre-field snapshot. A submission built from that
 * stale snapshot passes Wynko's validation, reaches Laposta, and is rejected
 * there for the missing field; FormSubmitHandler resyncs, re-validates, logs
 * a warning-level entry, and re-renders the form asking for the field — the
 * existing (already-shipped) drift-resync path, not Deliverable 2's quiet
 * fingerprint mechanism (optional-field-drift.spec.js covers that one).
 *
 * The trigger for this path is Wynko's own field-cache staleness, NOT the
 * page cache in front of the site — so unlike optional-field-drift, this is
 * a single run behind one caching plugin (Cache Enabler), not a matrix over
 * all four. A real page cache is still present, so the served page's nonce
 * and markup genuinely come from disk. See TECHNICAL_DEBT.md TD-071.
 *
 * The browser context carries NO stored admin auth: page-caching plugins
 * bypass their own cache for logged-in users, so the default `page` fixture
 * (admin storageState) would defeat the "served from cache" premise.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const { requireLiveKey } = require( '../live-key' );
const { TEST_LIST_ID, FIELD_NAME_PREFIX, testEmail } = require( '../env' );
const {
	createSignupForm,
	createSignupPage,
	deleteSignupForm,
	deleteSignupPage,
} = require( '../fixtures/signup-form' );
const {
	addField,
	deleteFieldAndConfirm,
	listMembers,
} = require( '../laposta-client' );
const { countEntries } = require( '../activity-log' );
const { forceRefresh } = require( '../field-cache' );
const { fillAndSubmit } = require( '../form-fill' );
const { renderCount } = require( '../page-cache' );
const cache = require( '../page-cache/cache-enabler' );

requireLiveKey( test );

test.describe.serial( 'required field drift', () => {
	// The hook budget is set inside beforeAll / afterAll via test.setTimeout()
	// — describe.configure's `timeout` covers only the test body, and it is
	// the hooks (a caching plugin's enable() plus the cache prime, a run of
	// `npx @wordpress/env run` round-trips) that are slow.

	const email = testEmail( 'required-field-drift' );
	const fieldLabel = `${ FIELD_NAME_PREFIX }required_drift`;

	let formId;
	let pagePath;
	let pageId;
	let fieldId;
	let customName;

	test.beforeAll( async ( { browser, request } ) => {
		// describe.configure({ timeout }) does not reach beforeAll; the
		// wp-scripts default of 100s is too tight for enable() + the cache
		// prime under load.
		test.setTimeout( 240_000 );

		const liveKey = process.env.WYNKO_TEST_API_KEY;

		await cache.enable();

		formId = await createSignupForm( 'E2E required field drift' );
		( { pageId, path: pagePath } = await createSignupPage(
			'E2E required field drift page',
			formId
		) );

		// Prime the page cache with the pre-field rendering, and check the
		// render counter the e2e mu-plugin bumps stays put on the second hit
		// (a true cache hit runs no PHP).
		const primeContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const primePage = await primeContext.newPage();
		await primePage.goto( pagePath );
		const primedAt = await renderCount();
		await primePage.goto( pagePath );
		expect( await renderCount() ).toBe( primedAt );
		await primeContext.close();

		// Seed Wynko's own field cache BEFORE the new field exists, so the
		// later submission is built from a field list Wynko does not know is
		// out of date — the only way Laposta ends up the one to reject it.
		await forceRefresh( TEST_LIST_ID );

		const field = await addField( request, liveKey, TEST_LIST_ID, {
			name: fieldLabel,
			type: 'text',
			required: true,
		} );
		fieldId = field.field_id;
		customName = field.custom_name;
	} );

	test.afterAll( async ( { request } ) => {
		test.setTimeout( 240_000 );

		const liveKey = process.env.WYNKO_TEST_API_KEY;
		if ( fieldId ) {
			await deleteFieldAndConfirm(
				request,
				liveKey,
				TEST_LIST_ID,
				fieldId
			).catch( () => {} );
			await forceRefresh( TEST_LIST_ID );
		}
		await deleteSignupPage( pageId );
		await deleteSignupForm( formId );
		await cache.disable();
	} );

	test( 'a missing required field triggers the warning-level resync and re-render', async ( {
		browser,
		request,
	} ) => {
		const liveKey = process.env.WYNKO_TEST_API_KEY;
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();

		try {
			const rendersBefore = await renderCount();
			await page.goto( pagePath );

			// The page the visitor sees came from the cache, not a fresh
			// render — otherwise this proves nothing about caching.
			expect( await renderCount() ).toBe( rendersBefore );

			const logCountBefore = await countEntries(
				'changed in Laposta',
				'warning'
			);

			await fillAndSubmit( page, { email } );

			// The resync path re-renders asking for the field the visitor
			// was never shown, named by Laposta's own custom_name.
			await expect(
				page.locator( `input[name="wynko_field[${ customName }]"]` )
			).toBeVisible();

			expect(
				await countEntries( 'changed in Laposta', 'warning' )
			).toBe( logCountBefore + 1 );

			const members = await listMembers(
				request,
				liveKey,
				TEST_LIST_ID,
				email
			);
			expect( members ).toHaveLength( 0 );
		} finally {
			await context.close();
		}
	} );
} );
