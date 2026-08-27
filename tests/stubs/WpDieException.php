<?php
/**
 * Stand-in for wp_die()'s halt, so tests can assert on it.
 *
 * Lives in its own file because WPCS forbids mixing function and class
 * declarations, and tests/bootstrap.php is otherwise all functions.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use RuntimeException;

/** Thrown by the shimmed wp_die(); the exception code is the HTTP status. */
final class WpDieException extends RuntimeException {
}
