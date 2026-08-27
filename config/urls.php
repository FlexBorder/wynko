<?php
/**
 * Single source of truth for every external URL the plugin emits, and the target
 * each link opens in. Plain data only, loaded by Wynko\Urls.
 *
 * A link entry with an empty 'url' has its href supplied at render time; 'rel'
 * is derived from the target and is deliberately not configurable.
 *
 * @package Wynko
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'api_base' => 'https://api.laposta.nl/v2',
	'links'    => array(
		// Linked from the plugin's row on the Plugins screen (Wynko\Admin\PluginLinks).
		'documentation'   => array(
			'url'    => 'https://getwynko.com/docs/?utm_source=wp-plugin&utm_medium=wynko&utm_campaign=plugins-page',
			'target' => '_blank',
		),
		'laposta_docs'    => array(
			'url'    => 'https://docs.laposta.org/article/947-how-do-i-get-an-api-key',
			'target' => '_blank',
		),
		// One list's page in Laposta's own admin. The list id travels as the
		// 'listconfig' query argument, which Urls::laposta_list_url() adds so
		// its name lives here rather than at every call site.
		'laposta_list'    => array(
			'url'    => 'https://app.laposta.nl/c.listconfig/s.browse/',
			'target' => '_blank',
		),
		// Laposta's own support page. A problem with an account, a list, or a
		// field belongs to them, not to this plugin's issue tracker.
		'laposta_support' => array(
			'url'    => 'https://www.laposta.nl/contact',
			'target' => '_blank',
		),
		// Linked from the suggested privacy-policy text (Privacy::register()),
		// and from readme.txt's "Privacy and external services" section — keep
		// both in sync with this URL if Laposta ever moves the page.
		'laposta_privacy' => array(
			'url'    => 'https://www.laposta.nl/en/privacy-statement',
			'target' => '_blank',
		),
		'wp_salt_docs'    => array(
			'url'    => 'https://developer.wordpress.org/reference/functions/wp_salt/',
			'target' => '_blank',
		),
		'mc4wp'           => array(
			'url'    => 'https://wordpress.org/plugins/mailchimp-for-wp/',
			'target' => '_blank',
		),
		'campaign_web'    => array(
			'url'    => '',
			'target' => '_blank',
		),
		'form_terms'      => array(
			'url'    => '',
			'target' => '_blank',
		),
		// Neither destination exists yet, so both are registered empty and
		// turning them on is one line here.
		'support_forum'   => array(
			'url'    => '',
			'target' => '_blank',
		),
		'github_issues'   => array(
			'url'    => '',
			'target' => '_blank',
		),
	),
);
