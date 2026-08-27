<?php
/**
 * Bootstrap for static analysis only. Defines the runtime constants that the
 * plugin's main file sets via define(), so PHPStan can resolve them across
 * files. Not loaded at runtime.
 */

define( 'WYNKO_VERSION', '1.0.0' );
define( 'WYNKO_FILE', __DIR__ . '/wynko.php' );
define( 'WYNKO_PATH', __DIR__ . '/' );
define( 'WYNKO_URL', 'https://example.test/wp-content/plugins/wynko/' );
