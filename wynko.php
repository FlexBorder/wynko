<?php
/**
 * Plugin bootstrap file.
 *
 * @package Wynko
 *
 * @wordpress-plugin
 * Plugin Name:       Wynko for Laposta
 * Plugin URI:        https://getwynko.com/?utm_source=wp-plugin&utm_medium=wynko&utm_campaign=plugins-page
 * Description:       Create native signup forms connected to your Laposta lists, and display your latest campaigns anywhere on your site.
 * Version:           1.1.0
 * Author:            FlexBorder
 * Author URI:        https://flex-border.com
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wynko-for-laposta
 * Domain Path:       /languages
 *

Copyright (C) 2026, roydg - hi@roydg.me

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WYNKO_VERSION', '1.1.0' );
define( 'WYNKO_FILE', __FILE__ );
define( 'WYNKO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WYNKO_URL', plugin_dir_url( __FILE__ ) );

$wynko_autoload = WYNKO_PATH . 'vendor/autoload.php';
if ( ! is_readable( $wynko_autoload ) ) {
	// Dependencies missing (run `composer install --no-dev`). Don't fatal; log for the operator.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator diagnostic; the autoloader (and any logging abstraction) is unavailable at this point.
	error_log( 'Wynko: vendor/autoload.php missing — run "composer install --no-dev". Plugin not loaded.' );
	return;
}
require_once $wynko_autoload;

Wynko\Plugin::boot();
