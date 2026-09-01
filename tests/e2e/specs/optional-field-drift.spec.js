/**
 * Cause (c) + Deliverable 2: Laposta gains a new OPTIONAL field. Nothing
 * about a submission fails on that alone, so the loud drift-resync path
 * (required-field-drift.spec.js) never fires. Wynko's own field cache is
 * force-refreshed (Wynko itself is current), but the page cache in front of
 * the site is still serving the page rendered before the field appeared —
 * FormRenderer's embedded field-set fingerprint (Support\FieldFingerprint)
 * is now stale, and FormSubmitHandler::maybe_log_stale_render() notices and
 * logs exactly one info-level entry, rate-limited to once an hour per form.
 *
 * Runs once per caching plugin (tests/e2e/page-cache). Here the stale page
 * cache is the sole cause — Wynko is current — so the render-count probe
 * (unchanged across a page load == served from cache) is load-bearing, not a
 * sanity check. The cooldown assertion submits a SECOND page that was also
 * loaded from cache before the field changed; a fresh render would carry the
 * current fingerprint and prove nothing.
 *
 * NO stored admin auth, for the same reason required-field-drift.spec.js has
 * none: page-caching plugins bypass their own cache for logged-in users.
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
	deleteMember,
} = require( '../laposta-client' );
const { countEntries } = require( '../activity-log' );
const { forceRefresh } = require( '../field-cache' );
const { fillAndSubmit } = require( '../form-fill' );
const { pageCaches, renderCount } = require( '../page-cache' );

requireLiveKey( test );

const STALE_RENDER_MESSAGE_SUBSTRING = 'outdated field fingerprint';

for ( const cache of pageCaches ) {
	const suiteTitle = `optional field drift behind ${ cache.label }`;

	test.describe.serial( suiteTitle, () => {
		// One retry absorbs a transient live-API/network hiccup without
		// hiding a real regression. The hook budget is set inside beforeAll /
		// afterAll themselves via test.setTimeout(): describe.configure's
		// `timeout` only covers the test body, never the hooks, and the
		// hooks are the slow part (a caching plugin's enable() plus the
		// cache probe, a dozen `npx @wordpress/env run` round-trips).
		test.describe.configure( { retries: 1 } );

		// Per-plugin label so a failed row's leftover field can't pollute the
		// next row's list (see required-field-drift.spec.js).
		const fieldLabel = `${ FIELD_NAME_PREFIX }optional_drift_${ cache.slug.replace(
			/-/g,
			''
		) }`;

		// See required-field-drift.spec.js: ok:true once the plugin is proven
		// to cache in this environment, otherwise the test skips.
		const cacheStatus = { ok: false, reason: '' };

		let formId;
		let pagePath;
		let pageId;
		let fieldId;

		test.beforeAll( async ( { browser } ) => {
			// describe.configure({ timeout }) does not reach beforeAll — set
			// the hook budget here. enable() + the cache probe are ~15 `npx
			// @wordpress/env run` calls at 3–8s each; 100s (the wp-scripts
			// default) is genuinely too tight under load.
			test.setTimeout( 240_000 );

			try {
				await cache.enable();
			} catch ( error ) {
				cacheStatus.reason = `enable() threw: ${ error.message }`;
				return;
			}

			formId = await createSignupForm(
				`E2E optional field drift (${ cache.label })`
			);
			( { pageId, path: pagePath } = await createSignupPage(
				`E2E optional field drift page (${ cache.label })`,
				formId
			) );

			// Warm Wynko's field transient before the first render, so the
			// page the cache stores is a fully-rendered form and not the
			// "could not load this list's fields" note a cold cache would
			// produce if the web container's own call to Laposta is slow.
			await forceRefresh( TEST_LIST_ID );

			// Confirm this plugin actually serves a request from cache in this
			// environment: the render counter the e2e mu-plugin bumps stays
			// put on a true hit. Some plugins (WP Super Cache) only start
			// serving statically after the first request has warmed the
			// cache, so give it a few passes before concluding it cannot.
			const probe = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );
			const probePage = await probe.newPage();
			// Bound the probe by wall clock, not just attempt count: a plugin
			// that never caches headlessly (WP Fastest Cache, sometimes WP
			// Super Cache) must land in the skip path below, not burn the
			// whole hook budget and fail the row. Each pass is 2 goto + 2
			// renderCount, ~12s; 60s is ~4 passes.
			const probeDeadline = Date.now() + 60_000;
			while ( ! cacheStatus.ok && Date.now() < probeDeadline ) {
				await probePage.goto( pagePath );
				const before = await renderCount();
				await probePage.goto( pagePath );
				cacheStatus.ok = ( await renderCount() ) === before;
			}
			await probe.close();
			if ( ! cacheStatus.ok ) {
				cacheStatus.reason =
					'did not serve any request from cache in this environment';
			}
		} );

		test.afterAll( async ( { request } ) => {
			// Same reasoning as beforeAll: disable() alone is ~6 round-trips.
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

		test( `a stale cached page produces one info log entry, then a cooldown (${ cache.slug })`, async ( {
			browser,
			request,
		} ) => {
			test.skip(
				! cacheStatus.ok,
				`${ cache.label }: ${ cacheStatus.reason }`
			);

			const liveKey = process.env.WYNKO_TEST_API_KEY;
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );

			try {
				// 1. Prime the page cache with the CURRENT field set (no
				// optional field yet). Two independent pages, both loaded now
				// while the rendering is fresh — each holds the pre-field
				// fingerprint for its own later submission.
				const pageA = await context.newPage();
				const pageB = await context.newPage();
				await pageA.goto( pagePath );
				await pageB.goto( pagePath );

				// 2. Laposta gains a new OPTIONAL field.
				const field = await addField( request, liveKey, TEST_LIST_ID, {
					name: fieldLabel,
					type: 'text',
					required: false,
				} );
				fieldId = field.field_id;

				// 3. Wynko's own field cache is force-refreshed — Wynko is now
				// current; only the cached page is behind.
				await forceRefresh( TEST_LIST_ID );

				const logCountBefore = await countEntries(
					STALE_RENDER_MESSAGE_SUBSTRING,
					'info'
				);

				// 4. Re-load page A from the still-cached copy (no purge) and
				// confirm it really was cache-served before submitting.
				const rendersBeforeA = await renderCount();
				await pageA.goto( pagePath );
				expect( await renderCount() ).toBe( rendersBeforeA );
				await cache.assertServedFromCache( pageA );

				const emailA = testEmail(
					`optional-field-drift-${ cache.slug }-a`
				);
				await fillAndSubmit( pageA, { email: emailA } );

				// 20s, not the 5s expect default: each attempt is a `wp eval`
				// round-trip and the entry is written on a live signup POST
				// that itself calls Laposta. Matches nonce-self-heal.spec.js.
				await expect
					.poll(
						() =>
							countEntries(
								STALE_RENDER_MESSAGE_SUBSTRING,
								'info'
							),
						{ timeout: 20_000 }
					)
					.toBe( logCountBefore + 1 );

				// The signup itself still succeeds — this signal is silent to
				// the visitor by design. Live Laposta can take several seconds
				// to reflect a new member.
				await expect
					.poll(
						async () =>
							(
								await listMembers(
									request,
									liveKey,
									TEST_LIST_ID,
									emailA
								)
							).length,
						{ timeout: 20_000 }
					)
					.toBe( 1 );

				// 5. A second stale submission (page B, also loaded pre-field
				// and re-served from cache) within the hour cooldown adds
				// nothing more to the log.
				const rendersBeforeB = await renderCount();
				await pageB.goto( pagePath );
				expect( await renderCount() ).toBe( rendersBeforeB );

				const emailB = testEmail(
					`optional-field-drift-${ cache.slug }-b`
				);
				await fillAndSubmit( pageB, { email: emailB } );

				await expect
					.poll(
						async () =>
							(
								await listMembers(
									request,
									liveKey,
									TEST_LIST_ID,
									emailB
								)
							).length,
						{ timeout: 20_000 }
					)
					.toBe( 1 );

				expect(
					await countEntries( STALE_RENDER_MESSAGE_SUBSTRING, 'info' )
				).toBe( logCountBefore + 1 );

				// Clean up the two members this scenario created.
				for ( const email of [ emailA, emailB ] ) {
					for ( const member of await listMembers(
						request,
						liveKey,
						TEST_LIST_ID,
						email
					) ) {
						await deleteMember(
							request,
							liveKey,
							TEST_LIST_ID,
							member.member_id
						);
					}
				}
			} finally {
				await context.close();
			}
		} );
	} );
}
