<?php
/**
 * A stand-in Integration that records whether boot() was called, without
 * touching any real hook.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Integrations\Integration;

/** Records boot() calls for IntegrationsBootTest. */
final class RecordingIntegration implements Integration {

	/** @var string */
	private $slug;

	/** @var bool */
	private $available;

	/** @var array<int,string> */
	private $booted;

	/**
	 * @param string            $slug      Integration slug.
	 * @param bool              $available Value is_available() returns.
	 * @param array<int,string> $booted    Reference-shared log of booted slugs.
	 */
	public function __construct( string $slug, bool $available, array &$booted ) {
		$this->slug      = $slug;
		$this->available = $available;
		$this->booted    = &$booted;
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
		return $this->slug;
	}

	/**
	 * @inheritDoc
	 */
	public function description(): string {
		return '';
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
		return '1.0.0';
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
	public function boot(): void {
		$this->booted[] = $this->slug;
	}

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
