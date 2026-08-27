<?php
/**
 * Namespaced override of phpversion(), so tests can stage a PHP version
 * without depending on whichever PHP the test suite happens to run under.
 *
 * PHP resolves an unqualified call inside a namespace to a same-named
 * function in that namespace before falling back to the global one, so this
 * shadows phpversion() only for SystemInfo::php_rows(), which calls it
 * unqualified from within Wynko\.
 *
 * @package Wynko
 */

namespace Wynko;

/**
 * Returns the staged PHP version, or the real one when nothing is staged.
 *
 * @return string
 */
function phpversion(): string {
	return $GLOBALS['wynko_test_php_version'] ?? \phpversion();
}
