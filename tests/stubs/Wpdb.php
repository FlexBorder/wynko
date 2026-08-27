<?php
/**
 * Minimal $wpdb double.
 *
 * @package Wynko
 */

/** The system report reads only the server banner, so that is all this carries. */
class WYNKO_Test_Wpdb {

	/**
	 * The banner db_server_info() reports.
	 *
	 * @var string
	 */
	public $server_info = '8.0.36';

	/**
	 * Returns the database server banner.
	 *
	 * @return string
	 */
	public function db_server_info() {
		return $this->server_info;
	}
}
