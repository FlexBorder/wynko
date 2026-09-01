<?php
/**
 * Test-only helper mu-plugin for the Playwright e2e suite.
 *
 * Mapped into wp-env by tests/e2e/playwright.config.js's `.wp-env.json`
 * `mappings` entry; never ships (the whole `tests/` tree is excluded by
 * `.distignore`, and this file additionally lives one level further inside
 * `tests/e2e/` for good measure).
 *
 * `wp eval` runs as a one-shot CLI process, so it cannot change a *later*
 * HTTP request's `nonce_life`. This mu-plugin instead hooks `nonce_life` at
 * priority 20 — after Wynko\Frontend\FormSubmitHandler::nonce_life()'s own
 * priority-10 hook (see Wynko\Plugin::boot()) — and shortens it only while a
 * test-only option is truthy, mirroring that method's own action-prefix
 * guard exactly so this can never touch any nonce outside Wynko's signup
 * submissions.
 *
 * @package Wynko
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The option a spec sets to force a short-lived submit nonce, and clears
 * (or leaves for the next test to overwrite) afterwards.
 */
const WYNKO_E2E_SHORT_NONCE_LIFE_OPTION = 'wynko_e2e_short_nonce_life';

/**
 * Shortens Wynko's own submit-form nonce life to the number of seconds held
 * in the wynko_e2e_short_nonce_life option, so nonce-self-heal.spec.js and
 * nonce-no-js.spec.js can wait past expiry deterministically instead of
 * waiting out the real three-day NONCE_LIFE.
 *
 * @param int    $life   The life core (or Wynko's own filter) would otherwise use.
 * @param string $action The nonce action being ticked, '' when unscoped.
 * @return int
 */
function wynko_e2e_short_nonce_life( int $life, string $action = '' ): int {
	// Mirrors FormSubmitHandler::nonce_life()'s own guard exactly: this must
	// never touch a nonce outside Wynko's own signup-submit actions.
	if ( 0 !== strpos( $action, 'wynko_submit_form_' ) ) {
		return $life;
	}

	$seconds = (int) get_option( WYNKO_E2E_SHORT_NONCE_LIFE_OPTION, 0 );
	return $seconds > 0 ? $seconds : $life;
}
add_filter( 'nonce_life', 'wynko_e2e_short_nonce_life', 20, 2 );

/**
 * The option holding a monotonic count of real front-end renders of a page
 * that contains a Wynko signup form. The caching specs read it either side
 * of a page load to tell a genuine cache hit (count unchanged) from a live
 * re-render — a probe that works the same for every caching plugin, without
 * depending on any one plugin's cache-hit marker.
 */
const WYNKO_E2E_RENDER_COUNT_OPTION = 'wynko_e2e_render_count';

/**
 * Set for the rest of the request once the `wynko_form` shortcode actually
 * renders. A page served from a full-page cache never executes PHP, so the
 * shortcode callback — and this flag — never fire for it.
 *
 * @var bool
 */
$GLOBALS['wynko_e2e_form_rendered'] = false;

/**
 * Notes that the signup-form shortcode rendered on this request.
 *
 * @param string $output The shortcode's HTML.
 * @param string $tag    The shortcode tag being processed.
 * @return string The unmodified output.
 */
function wynko_e2e_note_form_render( string $output, string $tag ): string {
	if ( 'wynko_form' === $tag ) {
		$GLOBALS['wynko_e2e_form_rendered'] = true;
	}
	return $output;
}
add_filter( 'do_shortcode_tag', 'wynko_e2e_note_form_render', 10, 2 );

/**
 * Bumps the render counter once per front-end request that actually rendered
 * the signup form in PHP.
 *
 * @return void
 */
function wynko_e2e_bump_render_count(): void {
	if ( is_admin() || empty( $GLOBALS['wynko_e2e_form_rendered'] ) ) {
		return;
	}

	$count = (int) get_option( WYNKO_E2E_RENDER_COUNT_OPTION, 0 );
	update_option( WYNKO_E2E_RENDER_COUNT_OPTION, $count + 1, false );
}
add_action( 'shutdown', 'wynko_e2e_bump_render_count' );
