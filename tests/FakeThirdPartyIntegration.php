<?php
/**
 * A stand-in for a third-party-registered Integration, carrying markup in
 * its strings so escaping can be asserted on.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Integrations\Integration;

/** Third-party stand-in with untrusted-looking strings. */
final class FakeThirdPartyIntegration implements Integration {

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'two';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return 'Two <script>alert(1)</script>';
	}

	/**
	 * @inheritDoc
	 */
	public function description(): string {
		return 'Third party.';
	}

	/**
	 * @inheritDoc
	 */
	public function author(): string {
		return 'Jane <script>alert(1)</script>';
	}

	/**
	 * @inheritDoc
	 */
	public function author_uri(): string {
		return 'https://example.org/jane';
	}

	/**
	 * @inheritDoc
	 */
	public function documentation_uri(): string {
		return 'https://example.org/docs';
	}

	/**
	 * @inheritDoc
	 */
	public function version(): string {
		return '1.0 <script>alert(1)</script>';
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function boot(): void {}

	/**
	 * @inheritDoc
	 */
	public function render_settings(): void {}

	/**
	 * @inheritDoc
	 */
	public function deactivation_warning(): string {
		return 'Careful <script>alert(1)</script>';
	}
}
