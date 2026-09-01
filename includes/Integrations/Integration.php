<?php
/**
 * The contract an integration implements to register with Wynko.
 *
 * @package Wynko
 */

namespace Wynko\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Any plugin or theme — including Wynko's own bundled code — implements
 * this and adds an instance to the `wynko_register_integrations` filter.
 * `boot()` is the only method Wynko ever calls without a capability check
 * already having run; it is called at most once, only when the integration
 * is both enabled and `is_available()`.
 */
interface Integration {

	/**
	 * Stable identifier, e.g. 'contact-form-7'. Used as the enabled-state key,
	 * so it must not change across releases of whatever registers it.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Display name shown in the Integrations list.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * One-sentence description shown in the Integrations list.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * '' means bundled with Wynko itself; otherwise the author or plugin name
	 * shown in the "Provided by" column.
	 *
	 * @return string
	 */
	public function author(): string;

	/**
	 * Where the author's name links to — their own site or WordPress.org
	 * profile, say. '' means author() is shown as plain text, not a link.
	 * Ignored when author() itself is ''.
	 *
	 * @return string
	 */
	public function author_uri(): string;

	/**
	 * Where "View documentation" links to. '' means no documentation link is
	 * shown at all.
	 *
	 * @return string
	 */
	public function documentation_uri(): string;

	/**
	 * Version shown in the Integrations list and Settings → About. A bundled
	 * integration returns WYNKO_VERSION, since it ships and releases with the
	 * plugin itself; a third-party one returns its own plugin/theme version.
	 *
	 * @return string
	 */
	public function version(): string;

	/**
	 * Whether this integration's own dependency (e.g. Contact Form 7 itself)
	 * is present. Checked before `boot()` is ever called, and again before
	 * `render_settings()` touches anything that dependency provides.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Wires this integration's own hooks. Called once, only when enabled and
	 * `is_available()`. Wynko's own framework never reaches into whatever this
	 * hooks into on the integration's behalf.
	 *
	 * @return void
	 */
	public function boot(): void;

	/**
	 * Prints this integration's own settings screen. No-op if it has none.
	 * Any state-changing action this renders is this integration's own
	 * responsibility to gate with a capability check and a nonce — except for
	 * Wynko's own bundled integrations, which owe the same contract as every
	 * other admin-post action in this plugin.
	 *
	 * @return void
	 */
	public function render_settings(): void;

	/**
	 * The sentence shown in the confirmation dialog before an admin turns
	 * this integration off, naming the concrete thing that stops working —
	 * e.g. "the sign-up checkbox already pasted into a Contact Form 7 form
	 * will stop subscribing anyone." '' falls back to a generic warning.
	 *
	 * @return string
	 */
	public function deactivation_warning(): string;
}
