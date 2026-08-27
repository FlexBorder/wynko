<?php
/**
 * Signup-form post type.
 *
 * @package Wynko
 */

namespace Wynko\Forms;

use Wynko\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wynko_form post type: storage only, with no public URL and no default post
 * UI, so every capability maps to manage_options. The admin handlers still check
 * the capability themselves; these arguments are defence in depth.
 */
final class PostType {

	const CAP = 'manage_options';

	/**
	 * Registers the post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		$capabilities = array_fill_keys(
			array(
				'edit_post',
				'read_post',
				'delete_post',
				'edit_posts',
				'edit_others_posts',
				'delete_posts',
				'publish_posts',
				'read_private_posts',
			),
			self::CAP
		);

		register_post_type(
			Config::form_post_type(),
			array(
				'labels'              => array(
					'name'          => __( 'Signup forms', 'wynko-for-laposta' ),
					'singular_name' => __( 'Signup form', 'wynko-for-laposta' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'capabilities'        => $capabilities,
				'map_meta_cap'        => false,
			)
		);
	}
}
