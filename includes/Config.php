<?php
/**
 * Read-only accessor over config/settings.php.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only accessor over config/settings.php. */
final class Config {

	/**
	 * Prefix every per-setting environment variable and constant carries, so
	 * cache_minutes is set by WYNKO_CACHE_MINUTES — and, on multisite, by
	 * WYNKO_CACHE_MINUTES_{blog_id} for one site alone.
	 */
	const ENV_PREFIX = 'WYNKO_';

	/**
	 * Lazily loaded settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * Loads config/settings.php once per request.
	 *
	 * @return array<string,mixed>
	 */
	private static function data(): array {
		if ( null === self::$data ) {
			self::$data = require WYNKO_PATH . 'config/settings.php';
		}
		return self::$data;
	}

	/**
	 * Returns the stored option key for a setting, or '' when it has none.
	 *
	 * @param string $name Setting name.
	 * @return string
	 */
	public static function option_key( string $name ): string {
		$opts = self::data()['options'];
		return isset( $opts[ $name ]['key'] ) ? (string) $opts[ $name ]['key'] : '';
	}

	/**
	 * Returns the configured default for a setting.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public static function default_for( string $name ) {
		return self::data()['options'][ $name ]['default'] ?? null;
	}

	/**
	 * Returns the min/max bounds for a setting, unbounded when none are configured.
	 *
	 * @param string $name Setting name.
	 * @return array{min:int,max:int}
	 */
	public static function bounds( string $name ): array {
		$b = self::data()['options'][ $name ]['bounds'] ?? array(
			'min' => 0,
			'max' => PHP_INT_MAX,
		);
		return array(
			'min' => (int) $b['min'],
			'max' => (int) $b['max'],
		);
	}

	/**
	 * Returns the permitted values for a setting, empty when it has no enum.
	 *
	 * @param string $name Setting name.
	 * @return array<int,string>
	 */
	public static function allowed_for( string $name ): array {
		$allowed = self::data()['options'][ $name ]['allowed'] ?? array();
		return is_array( $allowed ) ? array_values( array_map( 'strval', $allowed ) ) : array();
	}

	/**
	 * Reads a setting: an environment variable or wp-config.php constant when
	 * one is set for it, otherwise the stored option, otherwise the default.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public static function get( string $name ) {
		$override = self::override( $name );
		if ( 'none' !== $override['source'] ) {
			return $override['value'];
		}
		return get_option( self::option_key( $name ), self::default_for( $name ) );
	}

	/**
	 * Returns the environment/constant names that may supply a setting, most
	 * specific first. Empty for a setting not marked overridable, and for the
	 * API key, which resolves through ApiKey instead.
	 *
	 * @param string $name Setting name.
	 * @return array<int,string>
	 */
	public static function env_names( string $name ): array {
		$env = self::data()['options'][ $name ]['env'] ?? false;
		if ( empty( $env ) ) {
			return array();
		}

		// A string names the suffix instead of deriving it, for the setting
		// whose override is not meant to be guessable from the others.
		$base = self::ENV_PREFIX . ( is_string( $env ) ? $env : strtoupper( $name ) );
		return array( $base . '_' . get_current_blog_id(), $base );
	}

	/**
	 * Resolves a setting's override, highest precedence first: environment
	 * variable for this site, environment variable for the network, then the
	 * matching constants. 'none' means nothing outranks the database.
	 *
	 * A value the setting cannot accept is treated as absent, so a typo in a
	 * deploy cannot silently reconfigure the site.
	 *
	 * @param string $name Setting name.
	 * @return array{source:string,name:string,value:mixed}
	 */
	public static function override( string $name ): array {
		foreach ( array( 'env', 'constant' ) as $source ) {
			foreach ( self::env_names( $name ) as $env_name ) {
				$raw = 'env' === $source
					? getenv( $env_name )
					: ( defined( $env_name ) ? constant( $env_name ) : false );

				if ( false === $raw ) {
					continue;
				}

				$value = self::coerce( $name, $raw );
				if ( null === $value ) {
					continue;
				}

				return array(
					'source' => $source,
					'name'   => $env_name,
					'value'  => $value,
				);
			}
		}

		return array(
			'source' => 'none',
			'name'   => '',
			'value'  => null,
		);
	}

	/**
	 * Whether an environment variable or constant supplies this setting.
	 *
	 * @param string $name Setting name.
	 * @return bool
	 */
	public static function is_overridden( string $name ): bool {
		return 'none' !== self::override( $name )['source'];
	}

	/**
	 * Casts a raw environment or constant value to the shape the setting
	 * declares, or null when it does not fit one.
	 *
	 * @param string $name Setting name.
	 * @param mixed  $raw  Value as supplied.
	 * @return mixed|null
	 */
	private static function coerce( string $name, $raw ) {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return null;
		}

		$allowed = self::allowed_for( $name );
		$text    = trim( (string) ( is_bool( $raw ) ? (int) $raw : $raw ) );

		// An exported-but-empty variable is a deployment accident, not an
		// instruction to blank the setting — the same reading ApiKey takes.
		if ( '' === $text ) {
			return null;
		}

		if ( array() !== $allowed ) {
			return in_array( $text, $allowed, true ) ? $text : null;
		}

		$default = self::default_for( $name );

		if ( is_bool( $default ) ) {
			return Sanitizer::truthy( $text );
		}

		if ( is_int( $default ) ) {
			$bounds = self::bounds( $name );
			return is_numeric( $text )
				? Sanitizer::clamp_int( $text, $bounds['min'], $bounds['max'], (int) $default )
				: null;
		}

		return $text;
	}

	/**
	 * Returns the transient key holding the cached campaign list.
	 *
	 * @return string
	 */
	public static function transient_key(): string {
		return (string) self::data()['transient'];
	}

	/**
	 * Returns the transient key holding the cached list index.
	 *
	 * @return string
	 */
	public static function lists_transient_key(): string {
		return (string) self::data()['lists_transient'];
	}

	/**
	 * Returns the transient key holding the cached field definitions.
	 *
	 * @return string
	 */
	public static function fields_transient_key(): string {
		return (string) self::data()['fields_transient'];
	}

	/**
	 * Returns the signup-form post type.
	 *
	 * @return string
	 */
	public static function form_post_type(): string {
		return (string) self::data()['forms']['post_type'];
	}

	/**
	 * Returns the signup-form shortcode tag.
	 *
	 * @return string
	 */
	public static function form_shortcode(): string {
		return (string) self::data()['forms']['shortcode'];
	}

	/**
	 * Returns a form's post-meta key, '' when the name is not declared.
	 *
	 * @param string $name One of list_id, fields, messages, settings, button.
	 * @return string
	 */
	public static function form_meta_key( string $name ): string {
		$meta = self::data()['forms']['meta'];
		return isset( $meta[ $name ] ) ? (string) $meta[ $name ] : '';
	}

	/**
	 * Returns the default per-form settings.
	 *
	 * @return array{redirect_type:string,redirect_page_id:string,redirect_url:string,label_mode:string,hide_after_submit:bool,skip_doi:bool,reveal_duplicate:bool,terms_required:bool,terms_text:string,terms_link_type:string,terms_page_id:string,terms_url:string}
	 */
	public static function form_settings_defaults(): array {
		/**
		 * Defaults, typed to match the method's return annotation.
		 *
		 * @var array{redirect_type:string,redirect_page_id:string,redirect_url:string,label_mode:string,hide_after_submit:bool,skip_doi:bool,reveal_duplicate:bool,terms_required:bool,terms_text:string,terms_link_type:string,terms_page_id:string,terms_url:string} $defaults
		 */
		$defaults = self::data()['forms']['settings_defaults'];
		return $defaults;
	}

	/**
	 * Returns the default signup button, stored shape.
	 *
	 * @return array{label:string,css_class:string}
	 */
	public static function form_button_defaults(): array {
		/**
		 * Defaults, typed to match the method's return annotation.
		 *
		 * @var array{label:string,css_class:string} $defaults
		 */
		$defaults = self::data()['forms']['button_defaults'];
		return $defaults;
	}

	/**
	 * Returns the transient key holding one submission's outcome.
	 *
	 * @param string $token Opaque one-shot token.
	 * @return string
	 */
	public static function form_result_transient_key( string $token ): string {
		return (string) self::data()['forms']['result_transient_prefix'] . $token;
	}

	/**
	 * Returns how long a submission outcome survives the redirect, in seconds.
	 *
	 * @return int
	 */
	public static function form_result_ttl(): int {
		return (int) self::data()['forms']['result_ttl'];
	}

	/**
	 * Returns the transient prefix for one submission-rate counter.
	 *
	 * @param string $which 'ip' or 'form'.
	 * @return string
	 */
	public static function throttle_transient_prefix( string $which ): string {
		return (string) self::data()['throttle'][ $which . '_transient' ];
	}

	/**
	 * Returns the transient marking that this window's rate-limit entry is
	 * already written.
	 *
	 * @param int $form_id Form post id.
	 * @param int $epoch   The counter epoch the flag belongs to.
	 * @return string
	 */
	public static function throttle_logged_key( int $form_id, int $epoch ): string {
		return (string) self::data()['throttle']['logged_transient'] . $epoch . '_' . $form_id;
	}

	/**
	 * Returns the cooldown transient guarding one list's forced field refetch.
	 *
	 * The list id is hashed only to bound the key length; it is not a secret,
	 * so no hashing guarantee is being claimed here.
	 *
	 * @param string $list_id Laposta list id.
	 * @return string
	 */
	public static function resync_transient_key( string $list_id ): string {
		return (string) self::data()['resync']['transient_prefix'] . md5( $list_id );
	}

	/**
	 * Returns the transient marking that one form's near-cap warning has
	 * already been given. Carries the epoch every counter key carries, so
	 * clearing the counters re-arms the warning along with them.
	 *
	 * @param int $form_id Form post id.
	 * @param int $epoch   The counter epoch the flag belongs to.
	 * @return string
	 */
	public static function throttle_pressure_key( int $form_id, int $epoch ): string {
		return (string) self::data()['throttle']['pressure_transient'] . $epoch . '_' . $form_id;
	}

	/**
	 * Returns the transient holding the names the near-cap admin notice reads.
	 *
	 * @return string
	 */
	public static function throttle_pressure_notice_key(): string {
		return (string) self::data()['throttle']['pressure_transient'] . 'notice';
	}

	/**
	 * Returns how long a near-cap warning stands before the next one, in
	 * seconds.
	 *
	 * @return int
	 */
	public static function throttle_pressure_interval(): int {
		return (int) self::data()['throttle']['pressure_interval'];
	}

	/**
	 * Returns the signup count at which one form is called nearly full: a
	 * percentage of the per-form cap, rounded up, and never above the cap
	 * itself.
	 *
	 * @return int
	 */
	public static function throttle_pressure_threshold(): int {
		$max = self::throttle_max( 'form' );

		return (int) min( $max, max( 1, (int) ceil( $max * (int) self::data()['throttle']['pressure_percent'] / 100 ) ) );
	}

	/**
	 * Returns the option name holding the throttle epoch.
	 *
	 * @return string
	 */
	public static function throttle_epoch_option(): string {
		return (string) self::data()['throttle']['epoch_option'];
	}

	/**
	 * Returns the submission-rate window in seconds, from a setting stored in
	 * minutes — the counters count seconds, the screen asks for minutes.
	 *
	 * @return int
	 */
	public static function throttle_window(): int {
		return self::clamped_int( 'throttle_window' ) * 60;
	}

	/**
	 * Returns the submissions allowed per window for one counter.
	 *
	 * @param string $which 'ip' or 'form'.
	 * @return int
	 */
	public static function throttle_max( string $which ): int {
		return self::clamped_int( 'throttle_' . $which . '_max' );
	}

	/**
	 * Reads a bounded integer setting, held to its bounds on the way out.
	 *
	 * The settings screen clamps on save, but an option can also arrive from a
	 * migration or WP-CLI, and a throttle setting reading back as 0 would close
	 * every signup form on the site.
	 *
	 * @param string $name Setting name.
	 * @return int
	 */
	private static function clamped_int( string $name ): int {
		$bounds = self::bounds( $name );

		return Sanitizer::clamp_int( self::get( $name ), $bounds['min'], $bounds['max'], (int) self::default_for( $name ) );
	}

	/**
	 * Returns the Laposta API base URL, which the URL registry owns.
	 *
	 * @return string
	 */
	public static function api_base(): string {
		return Urls::api_base();
	}

	/**
	 * Returns the maximum number of retained activity-log entries.
	 *
	 * @return int
	 */
	public static function log_max(): int {
		return (int) self::data()['log_max'];
	}

	/**
	 * Returns the transient key that throttles critical-email alerts.
	 *
	 * @return string
	 */
	public static function notify_transient_key(): string {
		return (string) self::data()['notify']['transient'];
	}

	/**
	 * Returns the minimum gap between two critical-email alerts, in seconds.
	 *
	 * @return int
	 */
	public static function notify_interval(): int {
		return (int) self::data()['notify']['interval'];
	}

	/**
	 * Returns the maximum number of alert recipients that may be stored.
	 *
	 * @return int
	 */
	public static function notify_max_recipients(): int {
		return (int) self::data()['notify']['max_recipients'];
	}

	/**
	 * Returns the system report's thresholds: advised versions, the database
	 * floors, and the module lists.
	 *
	 * @return array<string,mixed>
	 */
	public static function requirements(): array {
		$requirements = self::data()['requirements'] ?? array();
		return is_array( $requirements ) ? $requirements : array();
	}
}
