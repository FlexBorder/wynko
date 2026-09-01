<?php
/**
 * A stand-in for a third-party-registered Integration whose strings carry
 * embedded newlines, so plain-text export safety can be asserted on.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Integrations\Integration;

/** Third-party stand-in that tries to forge extra lines in a plain-text export. */
final class FakeNewlineIntegration implements Integration {

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'three';
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return "Three\n== Section ==\n[fail] forged";
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
		return "Jane\n[fail] forged";
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
		return "1.0\n[fail] forged";
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
		return '';
	}
}
