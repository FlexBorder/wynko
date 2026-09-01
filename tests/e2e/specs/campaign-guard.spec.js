/**
 * Standing regression test: laposta-client.js's path allowlist must keep
 * throwing on anything outside `field`/`member` — in particular on any
 * campaign-shaped path. This suite must never be able to create or send a
 * Laposta campaign, structurally, not just by convention.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const {
	__assertAllowedPathForTest: assertAllowedPath,
} = require( '../laposta-client' );

test.describe( 'laposta-client.js campaign guard', () => {
	test( 'throws synchronously on a campaign-shaped path', () => {
		expect( () => assertAllowedPath( 'campaign' ) ).toThrow();
		expect( () => assertAllowedPath( 'campaign/123' ) ).toThrow();
		expect( () => assertAllowedPath( 'campaign?list_id=abc' ) ).toThrow();
	} );

	test( 'throws on any path outside the field/member allowlist', () => {
		expect( () => assertAllowedPath( 'list' ) ).toThrow();
		expect( () => assertAllowedPath( 'webhook' ) ).toThrow();
		expect( () => assertAllowedPath( 'account' ) ).toThrow();
	} );

	test( 'does not throw on the allowed field/member paths', () => {
		expect( () => assertAllowedPath( 'field' ) ).not.toThrow();
		expect( () =>
			assertAllowedPath( 'field/abc?list_id=def' )
		).not.toThrow();
		expect( () => assertAllowedPath( 'member' ) ).not.toThrow();
		expect( () =>
			assertAllowedPath( 'member/x@example.test?list_id=abc' )
		).not.toThrow();
	} );
} );
