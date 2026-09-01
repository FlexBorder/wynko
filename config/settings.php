<?php
/**
 * Single source of truth for option keys, defaults, and bounds; URLs live in
 * config/urls.php. Plain data only, loaded by Wynko\Config.
 *
 * @package Wynko
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'transient'        => 'wynko_campaigns',
	'lists_transient'  => 'wynko_lists',
	'fields_transient' => 'wynko_fields',
	// Wide enough that automatic syncs cannot push a day-old error off the end;
	// the level threshold and the screen's filter are what keep it readable.
	'log_max'          => 200,
	// Critical-email alerts. The interval is a plain int rather than
	// HOUR_IN_SECONDS, because this file is data loaded before any WordPress
	// constant is guaranteed.
	'notify'           => array(
		'transient'      => 'wynko_notify_sent',
		'interval'       => 3600,
		'max_recipients' => 10,
	),
	// Thresholds for the About tab's system report. The 'required' values
	// WordPress itself declares are absent, being read from the plugin header at
	// runtime.
	//
	// 'advised' is what the plugin is tested against, so below it is untested
	// rather than broken and warns instead of blocking.
	'requirements'     => array(
		'php'       => array( 'advised' => '8.4' ),
		'wordpress' => array( 'advised' => '7.0.4' ),
		'database'  => array(
			'mysql'   => array(
				'required' => '5.7',
				'advised'  => '8.4',
			),
			'mariadb' => array(
				'required' => '10.4',
				'advised'  => '11.8',
			),
		),
		// json is required, since every API response is decoded; sodium is only
		// advised, because without it the key is stored as plain text and the
		// settings page says so.
		//
		// curl and openssl are advised individually because neither is needed on
		// its own, only one of the pair, which SystemInfo::transport_row()
		// checks.
		'modules'   => array(
			'required' => array( 'json' ),
			'advised'  => array( 'sodium', 'mbstring', 'curl', 'openssl' ),
		),
		'memory'    => array( 'advised' => '128M' ),
		// OpenSSL 1.1.1 is the first release that speaks TLS 1.3, and anything
		// before 1.0.2 cannot reliably negotiate TLS 1.2 against a modern
		// endpoint — which is every call this plugin makes.
		'openssl'   => array( 'advised' => '1.1.1' ),
	),
	// Rate limiting for the public signup endpoint. The caps themselves are
	// administrator-settable options below; what lives here is only the
	// plumbing they are stored under.
	'throttle'         => array(
		'ip_transient'       => 'wynko_throttle_ip_',
		'form_transient'     => 'wynko_throttle_form_',
		'epoch_option'       => 'wynko_throttle_epoch',
		'logged_transient'   => 'wynko_throttle_logged_',
		// How full a form's window has to be before the plugin says so, as a
		// percentage of the per-form cap. Below 100 on purpose: an operator
		// who only hears about the cap once it has already turned signups away
		// is being told after the damage.
		'pressure_percent'   => 80,
		// One warning per form per day, however many windows fill up.
		'pressure_transient' => 'wynko_throttle_pressure_',
		'pressure_interval'  => 86400,
	),
	// One forced field refetch per list per window, for the drift retry a
	// public signup can trigger. The window is Cache::negative_ttl(), already
	// this plugin's "do not hammer the API" bound.
	'resync'           => array( 'transient_prefix' => 'wynko_resync_' ),
	// One stale-rendered-page log entry per form per cooldown window — see
	// FormSubmitHandler::STALE_RENDER_LOG_COOLDOWN, a deliberately separate
	// window from resync's above.
	'stale_render'     => array( 'transient_prefix' => 'wynko_stale_render_' ),
	// Signup forms. The CPT is internal (no default post UI), so its slug, its
	// meta keys, and the shortcode tag are configuration like any option key.
	'forms'            => array(
		'post_type'               => 'wynko_form',
		'shortcode'               => 'wynko_form',
		'result_transient_prefix' => 'wynko_form_result_',
		// Long enough for a redirect plus a slow page load, short enough that a
		// submission's values do not linger in the options table.
		'result_ttl'              => 300,
		'meta'                    => array(
			'list_id'  => '_wynko_list_id',
			'fields'   => '_wynko_fields',
			'messages' => '_wynko_messages',
			'settings' => '_wynko_settings',
			'button'   => '_wynko_button',
			// Signups this form has actually placed in Laposta, counted for
			// its whole life. Deliberately not one of Throttle's transients:
			// those are a sliding window and are wiped by "Reset signup
			// limits", neither of which a lifetime total may inherit.
			'signups'  => '_wynko_signups',
		),
		// The signup button's own opinions. Both empty: an empty label means
		// the built-in wording, which is translated and so cannot live here.
		'button_defaults'         => array(
			'label'     => '',
			'css_class' => '',
		),
		'settings_defaults'       => array(
			// How a successful signup ends: '' stays on the page, 'page' goes to
			// a chosen post id, 'url' goes to a typed address. The two
			// destinations are stored side by side so switching modes does not
			// discard what was configured for the other.
			'redirect_type'     => '',
			'redirect_page_id'  => '',
			'redirect_url'      => '',
			// How every field in this form is named: 'label', 'both', or
			// 'placeholder'. A new form starts on 'both', because the placeholder
			// column is only editable on a form that shows placeholders.
			'label_mode'        => 'both',
			'hide_after_submit' => false,
			'skip_doi'          => false,
			// Whether a signup from an address already on the list says so. Off
			// by default, because telling an anonymous caller lets anyone with
			// the form's URL test whether an address is subscribed.
			'reveal_duplicate'  => false,
			'terms_required'    => false,
			'terms_text'        => '',
			// Where the terms checkbox links: '' does not link, 'page' links to
			// a chosen post id, 'url' to a typed address. Stored side by side
			// for the same reason the redirect pair is.
			'terms_link_type'   => '',
			'terms_page_id'     => '',
			'terms_url'         => '',
		),
	),
	'options'          => array(
		'api_key'                    => array(
			'key'     => 'wynko_api_key',
			'default' => '',
		),
		'cache_minutes'              => array(
			'key'     => 'wynko_cache_minutes',
			'env'     => true,
			'default' => 60,
			'bounds'  => array(
				'min' => 1,
				'max' => 1440,
			),
		),
		'log'                        => array(
			'key'     => 'wynko_log',
			'default' => array(),
		),
		// The lowest severity that gets recorded. Ordered most severe first, to
		// match Support\Sanitizer::LOG_LEVELS, which owns the ranking.
		'log_level'                  => array(
			'key'     => 'wynko_log_level',
			'env'     => true,
			'default' => 'info',
			'allowed' => array( 'error', 'warning', 'info' ),
		),
		// How long one signup rate-limit window lasts, in minutes. Stored in
		// minutes because that is what the settings screen asks for; the
		// counters work in seconds, and Config converts.
		'throttle_window'            => array(
			'key'     => 'wynko_throttle_window',
			'env'     => true,
			'default' => 10,
			'bounds'  => array(
				'min' => 1,
				'max' => 1440,
			),
		),
		// Signups allowed from one address per window, generous because one
		// office or school shares a single REMOTE_ADDR. The floor is 1, since a
		// cap of zero would close every form on the site.
		'throttle_ip_max'            => array(
			'key'     => 'wynko_throttle_ip_max',
			'env'     => true,
			'default' => 15,
			'bounds'  => array(
				'min' => 1,
				'max' => 1000,
			),
		),
		// Signups allowed on one form per window, from everyone together. A
		// catastrophe backstop, not a first line, and set high on purpose:
		// exhausting a tight form cap blocks every legitimate signup on that
		// form for the window, from any address — which is a cheaper attack
		// than the spam the cap exists to stop.
		'throttle_form_max'          => array(
			'key'     => 'wynko_throttle_form_max',
			'env'     => true,
			'default' => 400,
			'bounds'  => array(
				'min' => 1,
				'max' => 100000,
			),
		),
		// Site-owner escape hatches for the submit endpoint's two protections,
		// off by default so nothing changes until an admin opts out. Every
		// hosting setup is different — a caching layer or proxy neither of us
		// anticipated may need one switched off to test — but disabling either
		// is a real trade-off, which is why SecurityTab shows a standing warning
		// whenever one is on.
		'disable_form_nonce'         => array(
			'key'     => 'wynko_disable_form_nonce',
			'env'     => true,
			'default' => false,
		),
		'disable_form_throttle'      => array(
			'key'     => 'wynko_disable_form_throttle',
			'env'     => true,
			'default' => false,
		),
		// Critical-email alerts, off until somebody opts in: an update must
		// never start sending mail on its own. Deploying notify_emails is an
		// opt-in too — see Notifier::forced().
		'notify_enabled'             => array(
			'key'     => 'wynko_notify_enabled',
			'env'     => true,
			'default' => false,
		),
		// Comma-separated addresses, stored already normalised by the
		// settings-page sanitiser.
		'notify_emails'              => array(
			'key'     => 'wynko_notify_emails',
			'env'     => true,
			'default' => '',
		),
		// When campaigns were last fetched: array{at:int,ok:bool}, written by
		// Cache::fill() on both the silent and the explicit path.
		'last_sync'                  => array(
			'key'     => 'wynko_last_sync',
			'default' => array(),
		),
		// The last critical-email alert that could not be sent, and which one was
		// dismissed: array{seq:int,at:int,message:string,dismissed:int}. The
		// ordering is a counter rather than the clock, so a failure recorded in
		// the same second as a dismissal is still shown.
		'alert_failure'              => array(
			'key'     => 'wynko_alert_failure',
			'default' => array(),
		),
		// The environment fingerprint the operator last dismissed the
		// requirements notice for. A fingerprint rather than a flag: dismissing
		// silences this environment, and a host upgrade or downgrade produces a
		// different one, which raises the notice again on its own.
		'env_dismissed'              => array(
			'key'     => 'wynko_env_dismissed',
			'default' => '',
		),
		// The identifiers the last sync saw, so the next one can tell what is
		// genuinely new. An option rather than a transient on purpose: the
		// campaign transient expiring is precisely what triggers a sync, so
		// diffing against it would report the whole account as new every time.
		'seen'                       => array(
			'key'     => 'wynko_seen',
			'default' => array(),
		),
		// The last known name of every list a published signup form is bound
		// to, so a list that vanishes can be named rather than reported as an
		// opaque id.
		'list_names'                 => array(
			'key'     => 'wynko_list_names',
			'default' => array(),
		),
		// List ids already reported as removed from Laposta, so the alarm fires
		// once rather than on every sync for as long as the list stays gone.
		'gone_lists'                 => array(
			'key'     => 'wynko_gone_lists',
			'default' => array(),
		),
		// Per-block attribute, not a stored option; kept here so the bound lives in one place.
		'campaign_count'             => array(
			'default' => 5,
			'bounds'  => array(
				'min' => 1,
				'max' => 100,
			),
		),
		// Per-block attributes; the renderer's whitelist and the editor's
		// controls share these values, so they live here once.
		'campaign_order_by'          => array(
			'default' => 'date',
			'allowed' => array( 'date', 'subject', 'name' ),
		),
		'campaign_order'             => array(
			'default' => 'desc',
			'allowed' => array( 'asc', 'desc' ),
		),
		'campaign_label'             => array(
			'default' => 'subject',
			'allowed' => array( 'subject', 'date', 'subject_date', 'name', 'name_date' ),
		),
		// Slugs of integrations an administrator has switched on. Availability
		// (e.g. Contact Form 7 itself being active) is checked separately at
		// boot time — this option only records intent.
		'integrations_enabled'       => array(
			'key'     => 'wynko_integrations_enabled',
			'default' => array(),
		),
		// Integrations demoted at boot time because their own dependency
		// vanished: array<int,array{slug:string,name:string}>, drained by
		// IntegrationAutoDisabledNotice the next time an admin sees it.
		'integrations_auto_disabled' => array(
			'key'     => 'wynko_integrations_auto_disabled',
			'default' => array(),
		),
	),
);
