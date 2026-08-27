<?php
/**
 * Wording for the activity log's severity levels.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place a level is put into words. The settings picker, the log
 * screen's filter and hint, and the export header all name the same three
 * levels, so a threshold reads the same wherever an operator meets it — the
 * export used to print the stored value ("info") that no screen offered.
 */
final class LogLevels {

	/**
	 * The wording for each level, most severe first. Kept here rather than in
	 * config/settings.php, which holds plain data and cannot translate.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			Sanitizer::LEVEL_ERROR   => __( 'Errors only', 'wynko-for-laposta' ),
			Sanitizer::LEVEL_WARNING => __( 'Warnings and errors', 'wynko-for-laposta' ),
			Sanitizer::LEVEL_INFO    => __( 'Info, warnings and errors', 'wynko-for-laposta' ),
		);
	}

	/**
	 * The wording for one level, falling back to the raw value so an unknown
	 * level still reads as something.
	 *
	 * @param string $level Level to word.
	 * @return string
	 */
	public static function label( string $level ): string {
		return self::labels()[ $level ] ?? $level;
	}
}
