<?php
/**
 * A minimal stand-in Integration, used by tests that only need identity, not
 * behavior.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Integrations\Integration;

/** Minimal stand-in for a registered integration. */
final class FakeIntegration implements Integration {

	/** @var string */
	private $slug;

	/** @var string */
	private $name;

	/** @var bool */
	private $has_settings;

	/** @var bool */
	private $available;

	/** @var string */
	private $deactivation_warning;

	/**
	 * @param string $slug                 Integration slug.
	 * @param string $name                 Display name.
	 * @param bool   $has_settings         Whether render_settings() prints anything.
	 * @param bool   $available            Value is_available() returns.
	 * @param string $deactivation_warning Value deactivation_warning() returns.
	 */
	public function __construct( string $slug, string $name = 'Fake', bool $has_settings = false, bool $available = true, string $deactivation_warning = '' ) {
		$this->slug                 = $slug;
		$this->name                 = $name;
		$this->has_settings         = $has_settings;
		$this->available            = $available;
		$this->deactivation_warning = $deactivation_warning;
	}

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * @inheritDoc
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * @inheritDoc
	 */
	public function description(): string {
		return 'A fake integration.';
	}

	/**
	 * @inheritDoc
	 */
	public function author(): string {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function author_uri(): string {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function documentation_uri(): string {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function version(): string {
		return '1.2.3';
	}

	/**
	 * @inheritDoc
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * @inheritDoc
	 */
	public function boot(): void {}

	/**
	 * @inheritDoc
	 */
	public function render_settings(): void {
		if ( $this->has_settings ) {
			echo 'Fake settings.';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function deactivation_warning(): string {
		return $this->deactivation_warning;
	}
}
