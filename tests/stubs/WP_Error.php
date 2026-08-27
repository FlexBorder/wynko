<?php
/**
 * Minimal WP_Error stub for the plain PHPUnit bootstrap.
 *
 * Lives in its own file because WPCS forbids mixing function and class
 * declarations, and tests/bootstrap.php is otherwise all functions.
 *
 * @package Wynko
 */

if ( class_exists( 'WP_Error' ) ) {
	return;
}

/**
 * Only the single-code shape the plugin actually constructs and reads. The
 * real class supports multiple codes, data payloads, and merging; nothing here
 * uses any of it.
 */
class WP_Error {

	/**
	 * Error code => list of messages.
	 *
	 * @var array<string,array<int,string>>
	 */
	private $errors = array();

	/**
	 * Error code => data payload.
	 *
	 * @var array<string,mixed>
	 */
	private $error_data = array();

	/**
	 * Creates an error, or an empty container when no code is given.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param mixed  $data    Optional payload.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = $message;
			if ( null !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	/**
	 * Returns the first error code, or '' when empty.
	 *
	 * @return string
	 */
	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes ? (string) $codes[0] : '';
	}

	/**
	 * Returns the first message for the first code, or '' when empty.
	 *
	 * @return string
	 */
	public function get_error_message() {
		$code = $this->get_error_code();
		return '' === $code ? '' : $this->errors[ $code ][0];
	}

	/**
	 * Returns the payload for a code, null when there is none.
	 *
	 * @param string $code Error code, '' for the first one.
	 * @return mixed
	 */
	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->error_data[ $code ] ?? null;
	}
}
