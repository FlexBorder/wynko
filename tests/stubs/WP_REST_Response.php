<?php
/**
 * Minimal WP_REST_Response stub for the plain PHPUnit bootstrap.
 *
 * Lives in its own file because WPCS forbids mixing function and class
 * declarations, and tests/bootstrap.php is otherwise all functions.
 *
 * @package Wynko
 */

if ( class_exists( 'WP_REST_Response' ) ) {
	return;
}

/**
 * Only the header bag and status the plugin's own code touches. The real class
 * also carries data, links, and matched route/handler; nothing here reads them.
 */
class WP_REST_Response {

	/**
	 * Header name => value.
	 *
	 * @var array<string,string>
	 */
	private $headers = array();

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Response payload.
	 *
	 * @var mixed
	 */
	private $data;

	/**
	 * Builds a response.
	 *
	 * @param mixed                $data    Payload.
	 * @param int                  $status  HTTP status code.
	 * @param array<string,string> $headers Initial headers.
	 */
	public function __construct( $data = null, $status = 200, $headers = array() ) {
		$this->data    = $data;
		$this->status  = (int) $status;
		$this->headers = (array) $headers;
	}

	/**
	 * Sets one header.
	 *
	 * @param string $key     Header name.
	 * @param string $value   Header value.
	 * @param bool   $replace Whether to replace an existing value.
	 * @return void
	 */
	public function header( $key, $value, $replace = true ) {
		if ( ! $replace && isset( $this->headers[ $key ] ) ) {
			return;
		}

		$this->headers[ $key ] = $value;
	}

	/**
	 * All headers.
	 *
	 * @return array<string,string>
	 */
	public function get_headers() {
		return $this->headers;
	}

	/**
	 * The status code.
	 *
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * The payload.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}
}
