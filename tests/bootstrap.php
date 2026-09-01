<?php
/**
 * PHPUnit bootstrap. It defines lightweight shims for the direct-access guard
 * and the translation functions, so the WordPress-facing classes load and return
 * their English source text unchanged for assertions.
 *
 * @package Wynko
 */

// Satisfy the direct-access guard present in every plugin file.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Plugin root, so Wynko\Config can locate config/settings.php in ConfigTest.
if ( ! defined( 'WYNKO_PATH' ) ) {
	define( 'WYNKO_PATH', dirname( __DIR__ ) . '/' );
}

// Main plugin file, so plugin_basename()/plugins_url() calls can locate it.
if ( ! defined( 'WYNKO_FILE' ) ) {
	define( 'WYNKO_FILE', dirname( __DIR__ ) . '/wynko.php' );
}

// Passthrough translation shim: returns the source string unchanged.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text; }
}

// In-memory options/transients so WordPress-facing classes are unit-testable
// under the plain PHPUnit bootstrap (no WP test suite in this project).
$GLOBALS['wynko_test_options']          = array();
$GLOBALS['wynko_test_transients']       = array();
$GLOBALS['wynko_test_http_queue']       = array();
$GLOBALS['wynko_test_http_calls']       = 0;
$GLOBALS['wynko_test_settings_errors']  = array();
$GLOBALS['wynko_test_last_request']     = null;
$GLOBALS['wynko_test_posts']            = array();
$GLOBALS['wynko_test_post_meta']        = array();
$GLOBALS['wynko_test_post_types']       = array();
$GLOBALS['wynko_test_redirects']        = array();
$GLOBALS['wynko_test_shortcodes']       = array();
$GLOBALS['wynko_test_next_post_id']     = 1;
$GLOBALS['wynko_test_hooks']            = array();
$GLOBALS['wynko_test_callbacks']        = array();
$GLOBALS['wynko_test_is_admin']         = false;
$GLOBALS['wynko_test_multisite']        = false;
$GLOBALS['wynko_test_environment_type'] = 'production';
$GLOBALS['wynko_test_nocache']          = false;
$GLOBALS['wynko_test_nocache_headers']  = wynko_test_default_nocache_headers();
$GLOBALS['wynko_test_mail']             = array();
$GLOBALS['wynko_test_mail_result']      = true;
$GLOBALS['wynko_test_mail_reason']      = 'Invalid address: (From): wordpress@localhost';
$GLOBALS['wynko_test_php_version']      = null;
$GLOBALS['wynko_test_dropins']          = array();

require_once __DIR__ . '/stubs/PhpVersion.php';
require_once __DIR__ . '/stubs/Wpdb.php';

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- There is no WordPress here to override; this bootstrap supplies the $wpdb the report reads.
$GLOBALS['wpdb'] = new WYNKO_Test_Wpdb();

function wynko_test_reset_store(): void {
	$GLOBALS['wynko_test_options']          = array();
	$GLOBALS['wynko_test_transients']       = array();
	$GLOBALS['wynko_test_http_queue']       = array();
	$GLOBALS['wynko_test_http_calls']       = 0;
	$GLOBALS['wynko_test_settings_errors']  = array();
	$GLOBALS['wynko_test_can_manage']       = false;
	$GLOBALS['wynko_test_last_request']     = null;
	$GLOBALS['wynko_test_posts']            = array();
	$GLOBALS['wynko_test_post_meta']        = array();
	$GLOBALS['wynko_test_post_types']       = array();
	$GLOBALS['wynko_test_redirects']        = array();
	$GLOBALS['wynko_test_shortcodes']       = array();
	$GLOBALS['wynko_test_next_post_id']     = 1;
	$GLOBALS['wynko_test_hooks']            = array();
	$GLOBALS['wynko_test_callbacks']        = array();
	$GLOBALS['wynko_test_is_admin']         = false;
	$GLOBALS['wynko_test_multisite']        = false;
	$GLOBALS['wynko_test_enqueued']         = array();
	$GLOBALS['wynko_test_registered']       = array();
	$GLOBALS['wynko_test_rest_routes']      = array();
	$GLOBALS['wynko_test_environment_type'] = 'production';
	$GLOBALS['wynko_test_nocache']          = false;
	$GLOBALS['wynko_test_nocache_headers']  = wynko_test_default_nocache_headers();
	$GLOBALS['wynko_test_wp_version']       = '6.7.1';
	$GLOBALS['wynko_test_is_ssl']           = false;
	$GLOBALS['wynko_test_using_https']      = false;
	$GLOBALS['wynko_test_is_network_admin'] = false;
	$GLOBALS['wynko_test_mail']             = array();
	$GLOBALS['wynko_test_mail_result']      = true;
	$GLOBALS['wynko_test_mail_reason']      = 'Invalid address: (From): wordpress@localhost';
	$GLOBALS['wynko_test_php_version']      = null;
	$GLOBALS['wynko_test_dropins']          = array();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores this bootstrap's own double between tests.
	$GLOBALS['wpdb'] = new WYNKO_Test_Wpdb();
}

/**
 * Every REST route registered since the last reset, keyed namespace + route.
 *
 * @return array<string,mixed>
 */
function wynko_test_rest_routes(): array {
	return $GLOBALS['wynko_test_rest_routes'] ?? array();
}

/**
 * Everything enqueued or localized since the last reset.
 *
 * @return array<string,mixed>
 */
function wynko_test_enqueued(): array {
	return $GLOBALS['wynko_test_enqueued'] ?? array();
}

/**
 * The array WordPress core's wp_get_nocache_headers() returns, including the
 * `Last-Modified => false` entry core uses to mean "remove this header".
 * Overwrite $GLOBALS['wynko_test_nocache_headers'] to model what the
 * `nocache_headers` filter lets another plugin add.
 *
 * @return array<string,string|false>
 */
function wynko_test_default_nocache_headers(): array {
	return array(
		'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
		'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
		'Last-Modified' => false,
	);
}

/**
 * Every hook registered since the last reset, as "hook|callback" strings.
 *
 * @return array<int,string>
 */
function wynko_test_hooks(): array {
	return $GLOBALS['wynko_test_hooks'];
}

/**
 * Queues the next HTTP response wp_remote_request() will return.
 *
 * @param int    $code HTTP status code.
 * @param string $body Response body.
 * @return void
 */
function wynko_test_queue_response( int $code, string $body ): void {
	$GLOBALS['wynko_test_http_queue'][] = array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
	);
}

/**
 * Number of wp_remote_request() calls since the last reset.
 *
 * @return int
 */
function wynko_test_http_calls(): int {
	return (int) $GLOBALS['wynko_test_http_calls'];
}

/**
 * The url and args of the most recent wp_remote_request() call, null when
 * there has not been one since the last reset.
 *
 * @return array{url:string,args:array<string,mixed>}|null
 */
function wynko_test_last_request(): ?array {
	return $GLOBALS['wynko_test_last_request'];
}

/**
 * Everything passed to add_settings_error() since the last reset.
 *
 * @return array<int,array<string,string>>
 */
function wynko_test_settings_errors(): array {
	return $GLOBALS['wynko_test_settings_errors'];
}

/**
 * Inserts a post directly into the in-memory store.
 *
 * @param array<string,mixed> $post post_title, post_type, post_status.
 * @return int New post id.
 */
function wynko_test_insert_post( array $post ): int {
	$id = $GLOBALS['wynko_test_next_post_id'];
	++$GLOBALS['wynko_test_next_post_id'];

	$GLOBALS['wynko_test_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_title'  => (string) ( $post['post_title'] ?? '' ),
		'post_type'   => (string) ( $post['post_type'] ?? 'post' ),
		'post_status' => (string) ( $post['post_status'] ?? 'publish' ),
	);
	return $id;
}

/**
 * Every post type registered since the last reset.
 *
 * @return array<string,array<string,mixed>>
 */
function wynko_test_registered_post_types(): array {
	return $GLOBALS['wynko_test_post_types'];
}

/**
 * Every URL wp_safe_redirect() was asked to send, in order.
 *
 * @return array<int,string>
 */
function wynko_test_redirects(): array {
	return $GLOBALS['wynko_test_redirects'];
}

/**
 * Sets what current_user_can() answers.
 *
 * @param bool $can Whether the current user may manage options.
 * @return void
 */
function wynko_test_set_can_manage( bool $can ): void {
	$GLOBALS['wynko_test_can_manage'] = $can;
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['wynko_test_options'] ) ? $GLOBALS['wynko_test_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['wynko_test_options'][ $key ] = $value;
		return true;
	}
	function delete_option( $key ) {
		unset( $GLOBALS['wynko_test_options'][ $key ] );
		return true;
	}
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['wynko_test_transients'] ) ? $GLOBALS['wynko_test_transients'][ $key ] : false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['wynko_test_transients'][ $key ] = $value;
		return true;
	}
	function delete_transient( $key ) {
		unset( $GLOBALS['wynko_test_transients'][ $key ] );
		return true;
	}
	function get_current_blog_id() {
		return isset( $GLOBALS['wynko_test_blog_id'] ) ? (int) $GLOBALS['wynko_test_blog_id'] : 1;
	}
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'http://example.org/wp-admin/' . ltrim( (string) $path, '/' );
	}
	// Covers the two forms the plugin uses: add_query_arg( $key, $value, $url )
	// and add_query_arg( array $pairs, $url ).
	function add_query_arg( $key, $value = '', $url = '' ) {
		$pairs = $key;
		if ( ! is_array( $pairs ) ) {
			$pairs = array( $key => $value );
		} else {
			$url = $value;
		}

		foreach ( $pairs as $name => $one ) {
			$separator = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
			$url       = $url . $separator . rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $one );
		}
		return $url;
	}
}

require_once __DIR__ . '/stubs/WP_Error.php';

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function wp_remote_request( $url, $args = array() ) {
		++$GLOBALS['wynko_test_http_calls'];
		$GLOBALS['wynko_test_last_request'] = array(
			'url'  => (string) $url,
			'args' => is_array( $args ) ? $args : array(),
		);
		if ( empty( $GLOBALS['wynko_test_http_queue'] ) ) {
			return new WP_Error( 'wynko_test_no_response', 'No queued response for ' . $url );
		}
		return array_shift( $GLOBALS['wynko_test_http_queue'] );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
	function sanitize_email( $value ) {
		return preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.@-]/', '', (string) $value );
	}
	function wp_strip_all_tags( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This shim *is* wp_strip_all_tags(); deferring to it would recurse.
		return strip_tags( (string) $value );
	}
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['wynko_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
	}
	function current_time( $type = 'mysql' ) {
		return '2026-08-12 00:00:00';
	}
	// Fixed-format stand-in: the tests assert that a date appears and where,
	// not WordPress's locale formatting.
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		return gmdate( 'Y-m-d', (int) $timestamp );
	}
	// Escaping passthroughs: the block render tests assert structure, not encoding.
	function esc_url( $value ) {
		return (string) $value;
	}
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr( $value ) {
		return (string) $value;
	}
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
	function esc_html_x( $text, $context, $domain = 'default' ) {
		return $text;
	}
	function esc_html_e( $text, $domain = 'default' ) {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping passthrough, as above.
	}
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
		return add_query_arg( $name, wp_create_nonce( $action ), (string) $url );
	}
	// Permissive stand-in: these tests assert what the plugin *emits*, and the real
	// wp_kses() filtering is WordPress's own well-tested behaviour, not ours.
	// Joins with the locale's list separator; the shim uses the English one,
	// which is what the assertions read.
	function wp_sprintf_l( $pattern, $args ) {
		$args = array_values( (array) $args );
		if ( array() === $args ) {
			return '';
		}
		if ( 1 === count( $args ) ) {
			$list = $args[0];
		} else {
			$last = array_pop( $args );
			$list = implode( ', ', $args ) . ' and ' . $last;
		}
		return str_replace( '%l', $list, $pattern );
	}
	function wp_kses( $html, $allowed = array(), $protocols = array() ) {
		return $html;
	}
	function get_block_wrapper_attributes( $extra = array() ) {
		return 'class="wp-block-wynko-campaigns"';
	}
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['wynko_test_can_manage'] );
	}
	function register_post_type( $post_type, $args = array() ) {
		$GLOBALS['wynko_test_post_types'][ $post_type ] = is_array( $args ) ? $args : array();
		return (object) array( 'name' => $post_type );
	}
	function wp_insert_post( $post, $wp_error = false ) {
		return wynko_test_insert_post( is_array( $post ) ? $post : array() );
	}
	function wp_update_post( $post, $wp_error = false ) {
		$id = (int) ( $post['ID'] ?? 0 );
		if ( ! isset( $GLOBALS['wynko_test_posts'][ $id ] ) ) {
			return 0;
		}
		foreach ( array( 'post_title', 'post_status' ) as $field ) {
			if ( isset( $post[ $field ] ) ) {
				$GLOBALS['wynko_test_posts'][ $id ]->$field = (string) $post[ $field ];
			}
		}
		return $id;
	}
	function wp_delete_post( $id, $force = false ) {
		$id = (int) $id;
		unset( $GLOBALS['wynko_test_posts'][ $id ], $GLOBALS['wynko_test_post_meta'][ $id ] );
		return true;
	}
	function get_post( $id = null ) {
		return $GLOBALS['wynko_test_posts'][ (int) $id ] ?? null;
	}
	// Covers only the arguments the plugin passes: post_type, post_status,
	// numberposts, orderby/order by title.
	function get_posts( $args = array() ) {
		$type   = (string) ( $args['post_type'] ?? 'post' );
		$status = (string) ( $args['post_status'] ?? 'publish' );
		$out    = array();
		foreach ( $GLOBALS['wynko_test_posts'] as $post ) {
			if ( $post->post_type !== $type ) {
				continue;
			}
			if ( 'any' !== $status && $post->post_status !== $status ) {
				continue;
			}
			$out[] = $post;
		}
		if ( 'title' === ( $args['orderby'] ?? '' ) ) {
			$descending = 'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) );
			usort(
				$out,
				static function ( $a, $b ) use ( $descending ) {
					$result = strcasecmp( $a->post_title, $b->post_title );
					return $descending ? -$result : $result;
				}
			);
		}
		$numberposts = (int) ( $args['numberposts'] ?? -1 );
		if ( $numberposts >= 0 ) {
			$out = array_slice( $out, 0, $numberposts );
		}
		return $out;
	}
	function get_post_meta( $id, $key = '', $single = false ) {
		$value = $GLOBALS['wynko_test_post_meta'][ (int) $id ][ $key ] ?? null;
		if ( null === $value ) {
			return $single ? '' : array();
		}
		return $single ? $value : array( $value );
	}
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['wynko_test_post_meta'][ (int) $id ][ $key ] = $value;
		return true;
	}
	function wp_create_nonce( $action = -1 ) {
		return 'nonce:' . $action;
	}
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return ( 'nonce:' . $action === $nonce ) ? 1 : false;
	}
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test shim; this function *is* the nonce verification, and the raw value is only compared, never used unsanitized.
		$nonce = isset( $_POST[ $query_arg ] ) ? (string) $_POST[ $query_arg ] : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( 'Bad nonce', '', array( 'response' => 403 ) );
		}
		return 1;
	}
	// Stands in for the two Settings API calls a tab's form makes. The nonce is
	// what the real settings_fields() contributes and what tests assert on; the
	// registered sections are not reproduced, so a tab's own markup is what a
	// render test sees.
	function settings_fields( $group ) {
		printf( '<input type="hidden" name="option_page" value="%s" />', esc_attr( (string) $group ) );
		wp_nonce_field( (string) $group . '-options' );
	}
	function do_settings_sections( $page ) {
		printf( '<!-- settings sections: %s -->', esc_html( (string) $page ) );
	}
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$html = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce( $action ) . '" />';
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim; the value is built here.
		}
		return $html;
	}
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
	function esc_textarea( $value ) {
		return (string) $value;
	}
	function esc_url_raw( $value ) {
		return (string) $value;
	}
	function sanitize_html_class( $class, $fallback = '' ) {
		$class = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $class );
		return '' === $class ? $fallback : $class;
	}
	function is_email( $value ) {
		return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? (string) $value : false;
	}
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['wynko_test_mail'][] = array(
			'to'      => (array) $to,
			'subject' => (string) $subject,
			'body'    => (string) $message,
		);
		if ( ! $GLOBALS['wynko_test_mail_result'] ) {
			// Core fires this before returning false, and the reason it carries
			// is the only account of why a send failed.
			do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', (string) $GLOBALS['wynko_test_mail_reason'] ) );
			return false;
		}
		return true;
	}
	function wp_specialchars_decode( $value, $quote_style = ENT_NOQUOTES ) {
		return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['wynko_test_redirects'][] = (string) $location;
		return true;
	}
	function wp_get_referer() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test shim standing in for core's own wp_get_referer(); the value is only read back, never trusted as verified input.
		return isset( $_POST['_wp_http_referer'] ) ? (string) $_POST['_wp_http_referer'] : false;
	}
	function wp_validate_redirect( $location, $fallback = '' ) {
		return (string) $location;
	}
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 4 ), 0, (int) $length );
	}
	// Core keys this with the site's salts; a fixed key is enough here, because
	// what the callers need is only that one input maps to one 32-char digest.
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', (string) $data, 'wynko-test-salt' );
	}
	function wp_die( $message = '', $title = '', $args = array() ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test shim; the message becomes an exception message, never HTML output.
		throw new \Wynko\Tests\WpDieException( is_string( $message ) ? $message : '', (int) ( $args['response'] ?? 500 ) );
	}
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, (int) $options, (int) $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This shim is wp_json_encode(); deferring to it would recurse.
	}
	function home_url( $path = '', $scheme = null ) {
		return 'https://example.org/' . ltrim( (string) $path, '/' );
	}
	// Returns false for a post that does not exist, as WordPress does — the
	// difference is what tells a live redirect page from a deleted one.
	function get_permalink( $post = 0 ) {
		$id = (int) ( is_object( $post ) ? $post->ID : $post );
		return null === get_post( $id ) ? false : 'https://example.org/?p=' . $id;
	}
	function wp_dropdown_pages( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		$html = '<select name="' . ( $args['name'] ?? 'page_id' ) . '"><option value="'
			. ( $args['option_none_value'] ?? '0' ) . '">' . ( $args['show_option_none'] ?? '' ) . '</option></select>';

		if ( ! isset( $args['echo'] ) || $args['echo'] ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim; escaping passthroughs above.
			return '';
		}
		return $html;
	}
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['wynko_test_shortcodes'][ $tag ] = $callback;
	}
	function checked( $checked, $current = true, $display = true ) {
		$html = ( (string) $checked === (string) $current || ( $checked && $current ) ) ? ' checked="checked"' : '';
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim; the value is one of two literals.
		}
		return $html;
	}
	function selected( $selected, $current = true, $display = true ) {
		$html = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim; the value is one of two literals.
		}
		return $html;
	}
	function wp_kses_post( $html ) {
		return (string) $html;
	}
	function esc_attr__( $text, $domain = 'default' ) {
		return $text;
	}
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
	function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
		echo '<button type="submit" name="' . $name . '">' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim standing in for core's own escaping.
	}
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$name                          = is_array( $callback ) ? ( ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1] ) : ( is_string( $callback ) ? $callback : 'closure' );
		$GLOBALS['wynko_test_hooks'][] = $hook . '|' . $name;
		// Registered as well as recorded: the recorded name is what PluginBootTest
		// asserts on, the callback is what do_action() below actually runs.
		$GLOBALS['wynko_test_callbacks'][ $hook ][] = $callback;
		return true;
	}
	function remove_action( $hook, $callback, $priority = 10 ) {
		foreach ( (array) ( $GLOBALS['wynko_test_callbacks'][ $hook ] ?? array() ) as $index => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['wynko_test_callbacks'][ $hook ][ $index ] );
			}
		}
		return true;
	}
	function do_action( $hook, ...$args ) {
		foreach ( (array) ( $GLOBALS['wynko_test_callbacks'][ $hook ] ?? array() ) as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_action( $hook, $callback, $priority, $args );
	}
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( (array) ( $GLOBALS['wynko_test_callbacks'][ $hook ] ?? array() ) as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
	function is_admin() {
		return ! empty( $GLOBALS['wynko_test_is_admin'] );
	}
	function is_multisite() {
		return ! empty( $GLOBALS['wynko_test_multisite'] );
	}
	function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
		return true;
	}
	function plugin_basename( $file ) {
		return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
	}
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = is_array( $atts ) ? $atts : array();
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		$GLOBALS['wynko_test_enqueued']['scripts'][ $handle ] = compact( 'src', 'deps', 'ver' );
	}
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['wynko_test_enqueued']['styles'][ $handle ] = compact( 'src', 'deps', 'ver' );
	}
	function wp_register_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['wynko_test_registered']['styles'][ $handle ] = compact( 'src', 'deps', 'ver' );
		return true;
	}
	function wp_style_is( $handle, $status = 'enqueued' ) {
		$bucket = 'registered' === $status ? 'wynko_test_registered' : 'wynko_test_enqueued';
		return isset( $GLOBALS[ $bucket ]['styles'][ $handle ] );
	}
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['wynko_test_enqueued']['localized'][ $handle ][ $name ] = $data;
		return true;
	}
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.org/wp-content/plugins/wynko/' . ltrim( (string) $path, '/' );
	}
	function plugin_dir_path( $file ) {
		return rtrim( dirname( (string) $file ), '/' ) . '/';
	}
	function rest_url( $path = '' ) {
		return 'https://example.org/wp-json/' . ltrim( (string) $path, '/' );
	}
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
		$GLOBALS['wynko_test_rest_routes'][ $namespace . $route ] = $args;
		return true;
	}
	function wp_get_environment_type() {
		return $GLOBALS['wynko_test_environment_type'] ?? 'production';
	}
	function get_dropins() {
		return $GLOBALS['wynko_test_dropins'] ?? array();
	}
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		$values = array(
			'version'  => $GLOBALS['wynko_test_wp_version'] ?? '6.7.1',
			'language' => 'en_US',
			'url'      => 'https://example.test',
			'name'     => 'Example',
		);
		return $values[ $show ] ?? '';
	}
	function get_file_data( $file, $headers, $context = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test shim reading a local source file, not a remote resource.
		$source = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$found  = array();
		foreach ( $headers as $field => $label ) {
			$found[ $field ] = ( 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $source, $match ) )
				? trim( $match[1] )
				: '';
		}
		return $found;
	}
	function human_time_diff( $from, $to = 0 ) {
		$to   = $to ? $to : time();
		$mins = max( 1, (int) round( abs( $to - $from ) / 60 ) );
		return $mins . ' mins';
	}
	function nocache_headers() {
		$GLOBALS['wynko_test_nocache'] = true;
	}
	function wp_get_nocache_headers() {
		return $GLOBALS['wynko_test_nocache_headers'];
	}
	function sanitize_file_name( $name ) {
		$clean = preg_replace( '/[^a-zA-Z0-9._-]/', '-', (string) $name );
		return is_string( $clean ) ? trim( $clean, '-' ) : '';
	}
	function wp_timezone_string() {
		return 'UTC';
	}
	function is_ssl() {
		return (bool) ( $GLOBALS['wynko_test_is_ssl'] ?? false );
	}
	function wp_is_using_https() {
		return (bool) ( $GLOBALS['wynko_test_using_https'] ?? false );
	}
	function is_network_admin() {
		return (bool) ( $GLOBALS['wynko_test_is_network_admin'] ?? false );
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, (int) $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This shim is wp_parse_url(); deferring to it would recurse.
	}
}

require_once __DIR__ . '/stubs/WpDieException.php';
require_once __DIR__ . '/stubs/WP_List_Table.php';
require_once __DIR__ . '/stubs/WP_REST_Response.php';

require __DIR__ . '/../vendor/autoload.php';
