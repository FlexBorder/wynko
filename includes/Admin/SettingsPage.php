<?php
/**
 * Plugin settings screen.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Api\Campaigns as CampaignsApi;
use Wynko\ApiKey;
use Wynko\Cache;
use Wynko\Config;
use Wynko\KeyStatus;
use Wynko\Log;
use Wynko\Support\Crypto;
use Wynko\Support\Sanitizer;
use Wynko\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Wynko → Settings screen. Every state-changing path is guarded by a
 * capability check and a nonce: the Settings API form for saves,
 * check_admin_referer() for sync.
 */
final class SettingsPage {

	const PAGE              = Menu::PARENT;
	const GROUP             = 'wynko_settings';
	const UNREADABLE_NOTICE = 'wynko_unreadable_logged';
	const TAB_API           = 'api';
	const TAB_ABOUT         = 'about';

	// The Notifications and Security tabs get their own group and page slug
	// rather than sharing the ones above. wp-admin/options.php writes every
	// option registered to the submitted group, passing null for anything the
	// form did not post, so one shared group would mean saving any one tab reset
	// the others' settings.
	const TAB_NOTIFICATIONS   = 'notifications';
	const GROUP_NOTIFICATIONS = 'wynko_notifications';
	const PAGE_NOTIFICATIONS  = Menu::PARENT . '-notifications';

	const TAB_SECURITY   = 'security';
	const GROUP_SECURITY = 'wynko_security';
	const PAGE_SECURITY  = Menu::PARENT . '-security';

	/**
	 * Result of this request's first sanitize_key() call, null before it runs.
	 *
	 * Unkeyed on purpose: update_option() hands add_option() the value the first
	 * sanitize returned, so a memo keyed on the submitted value would miss
	 * exactly when it is needed.
	 *
	 * @var string|null
	 */
	private static ?string $key_memo = null;

	/**
	 * Clears the per-request memo. Tests only — one request is one memo.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$key_memo = null;
	}

	/**
	 * Registers the settings, section, and fields.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Config::option_key( 'api_key' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_key' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Config::option_key( 'cache_minutes' ),
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_minutes' ),
				'default'           => Config::default_for( 'cache_minutes' ),
			)
		);
		register_setting(
			self::GROUP,
			Config::option_key( 'log_level' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_log_level' ),
				'default'           => Config::default_for( 'log_level' ),
			)
		);

		register_setting(
			self::GROUP_SECURITY,
			Config::option_key( 'throttle_window' ),
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( SecurityTab::class, 'sanitize_window' ),
				'default'           => Config::default_for( 'throttle_window' ),
			)
		);
		register_setting(
			self::GROUP_SECURITY,
			Config::option_key( 'throttle_ip_max' ),
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( SecurityTab::class, 'sanitize_ip_max' ),
				'default'           => Config::default_for( 'throttle_ip_max' ),
			)
		);
		register_setting(
			self::GROUP_SECURITY,
			Config::option_key( 'throttle_form_max' ),
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( SecurityTab::class, 'sanitize_form_max' ),
				'default'           => Config::default_for( 'throttle_form_max' ),
			)
		);

		register_setting(
			self::GROUP_NOTIFICATIONS,
			Config::option_key( 'notify_enabled' ),
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( NotificationsTab::class, 'sanitize_enabled' ),
				'default'           => Config::default_for( 'notify_enabled' ),
			)
		);
		register_setting(
			self::GROUP_NOTIFICATIONS,
			Config::option_key( 'notify_emails' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => array( NotificationsTab::class, 'sanitize_emails' ),
				'default'           => Config::default_for( 'notify_emails' ),
			)
		);

		// Titles are escaped here, not at output: do_settings_sections() and
		// do_settings_fields() echo them raw so that plugins may pass markup,
		// which makes escaping the caller's job. A translation is the only way
		// anything but our own literal reaches these, and language packs come
		// from translate.wordpress.org rather than this repository.
		add_settings_section( 'wynko_main', esc_html__( 'Connection', 'wynko-for-laposta' ), '__return_false', self::PAGE );
		add_settings_field( Config::option_key( 'api_key' ), esc_html__( 'API key', 'wynko-for-laposta' ), array( self::class, 'field_key' ), self::PAGE, 'wynko_main' );
		// Display-only, so its id is a plain literal rather than a Config option key: nothing about it is submitted or sanitized.
		add_settings_field( 'wynko_status', esc_html__( 'Status', 'wynko-for-laposta' ), array( self::class, 'field_status' ), self::PAGE, 'wynko_main' );
		add_settings_field( Config::option_key( 'cache_minutes' ), esc_html__( 'Cache duration (minutes)', 'wynko-for-laposta' ), array( self::class, 'field_ttl' ), self::PAGE, 'wynko_main' );
		add_settings_field( Config::option_key( 'log_level' ), esc_html__( 'Activity log detail', 'wynko-for-laposta' ), array( self::class, 'field_log_level' ), self::PAGE, 'wynko_main' );

		add_settings_section( 'wynko_throttle', esc_html__( 'Signup rate limits', 'wynko-for-laposta' ), array( SecurityTab::class, 'intro' ), self::PAGE_SECURITY );
		add_settings_field( Config::option_key( 'throttle_window' ), esc_html__( 'Window (minutes)', 'wynko-for-laposta' ), array( SecurityTab::class, 'field_window' ), self::PAGE_SECURITY, 'wynko_throttle' );
		add_settings_field( Config::option_key( 'throttle_ip_max' ), esc_html__( 'Per visitor', 'wynko-for-laposta' ), array( SecurityTab::class, 'field_ip_max' ), self::PAGE_SECURITY, 'wynko_throttle' );
		add_settings_field( Config::option_key( 'throttle_form_max' ), esc_html__( 'Per form', 'wynko-for-laposta' ), array( SecurityTab::class, 'field_form_max' ), self::PAGE_SECURITY, 'wynko_throttle' );

		add_settings_section( 'wynko_notify', esc_html__( 'Alerts', 'wynko-for-laposta' ), '__return_false', self::PAGE_NOTIFICATIONS );
		// The recipients have no row of their own: they are printed by
		// field_enabled(), nested under the checkbox that decides whether
		// anything is ever sent to them.
		add_settings_field( Config::option_key( 'notify_enabled' ), esc_html__( 'Critical email', 'wynko-for-laposta' ), array( NotificationsTab::class, 'field_enabled' ), self::PAGE_NOTIFICATIONS, 'wynko_notify' );
	}

	/**
	 * Prints the connection verdict as the value of the Status row.
	 *
	 * The unreadable check comes first because resolve() returns '' for a key it
	 * cannot open, which would otherwise report a bare "Not connected" and send
	 * the operator hunting a key that is fine.
	 *
	 * @return void
	 */
	public static function field_status(): void {
		if ( 'unreadable' === ApiKey::stored_state() ) {
			printf(
				'<span class="dashicons dashicons-warning" style="color:#d63638;"></span> <strong>%s</strong>',
				esc_html__( 'The stored API key could not be read — it was encrypted with this site\'s security salts, which have since changed. Re-enter it above.', 'wynko-for-laposta' )
			);
			self::log_unreadable_once();
			return;
		}

		if ( 'none' === ApiKey::source() ) {
			printf( '<strong>%s</strong>', esc_html__( 'No API key configured', 'wynko-for-laposta' ) );
			return;
		}

		$status = KeyStatus::current();
		if ( $status['ok'] ) {
			printf(
				'<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> <strong>%s</strong>',
				esc_html__( 'Connected', 'wynko-for-laposta' )
			);
			return;
		}

		printf(
			'<span class="dashicons dashicons-warning" style="color:#d63638;"></span> <strong>%s</strong>',
			esc_html(
				sprintf(
					/* translators: %s: reason the connection failed. */
					__( 'Not connected — %s', 'wynko-for-laposta' ),
					$status['message']
				)
			)
		);
	}

	/**
	 * Records the undecryptable-key error at most once an hour. The state
	 * persists until someone re-enters the key, and this runs on every render
	 * of the screen they would use to do it — without the guard the log fills
	 * with one repeated message.
	 *
	 * @return void
	 */
	private static function log_unreadable_once(): void {
		if ( get_transient( self::UNREADABLE_NOTICE ) ) {
			return;
		}
		Log::error( __( 'Stored API key could not be decrypted; the site security salts have changed.', 'wynko-for-laposta' ) );
		set_transient( self::UNREADABLE_NOTICE, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Prints, in place of a field, the environment variable or constant that
	 * supplies the setting — and returns true when it did, so the caller can
	 * skip its own control.
	 *
	 * The stored value rides along in a hidden input, because options.php writes
	 * every option in the submitted group and would otherwise clear what the
	 * database holds as soon as any other field on the tab is saved.
	 *
	 * @param string $name Setting name.
	 * @return bool
	 */
	public static function render_override( string $name ): bool {
		$override = Config::override( $name );
		if ( 'none' === $override['source'] ) {
			return false;
		}

		$value = $override['value'];
		printf(
			'<p><code>%s</code> %s</p><p class="description">%s</p>',
			esc_html( $override['name'] ),
			esc_html(
				'env' === $override['source']
					? __( '— supplied by an environment variable and used as-is. Unset it to manage this setting from this page.', 'wynko-for-laposta' )
					: __( '— defined in wp-config.php and used as-is. Remove it there to manage this setting from this page.', 'wynko-for-laposta' )
			),
			esc_html(
				sprintf(
					/* translators: %s: the value currently in effect for this setting. */
					__( 'In effect: %s', 'wynko-for-laposta' ),
					is_bool( $value ) ? self::on_off( $value ) : (string) $value
				)
			)
		);
		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( Config::option_key( $name ) ),
			esc_attr( (string) get_option( Config::option_key( $name ), '' ) )
		);

		return true;
	}

	/**
	 * A boolean setting's value in words, for the override note.
	 *
	 * @param bool $value Value in effect.
	 * @return string
	 */
	private static function on_off( bool $value ): string {
		return $value ? __( 'on', 'wynko-for-laposta' ) : __( 'off', 'wynko-for-laposta' );
	}

	/**
	 * Clamps the submitted cache duration to its configured bounds.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function sanitize_minutes( $value ): int {
		$bounds = Config::bounds( 'cache_minutes' );
		return Sanitizer::clamp_int( $value, $bounds['min'], $bounds['max'], (int) Config::default_for( 'cache_minutes' ) );
	}

	/**
	 * Clamps one bounded integer setting to its configured range. Public
	 * because the Security tab's sanitizers clamp against the same bounds and
	 * Config is the only place those live.
	 *
	 * @param string $name  Setting name.
	 * @param mixed  $value Submitted value.
	 * @return int
	 */
	public static function clamp_setting( string $name, $value ): int {
		$bounds = Config::bounds( $name );
		return Sanitizer::clamp_int( $value, $bounds['min'], $bounds['max'], (int) Config::default_for( $name ) );
	}

	/**
	 * Prints the API key field, or a notice naming the environment variable or
	 * constant that outranks it.
	 *
	 * @return void
	 */
	public static function field_key(): void {
		$source = ApiKey::source();
		if ( 'env' === $source || 'constant' === $source ) {
			printf(
				'<p><code>%s</code> %s</p>',
				esc_html( ApiKey::source_name() ),
				esc_html(
					'env' === $source
						? __( '— supplied by an environment variable and used as-is. Unset it to manage the key from this page.', 'wynko-for-laposta' )
						: __( '— defined in wp-config.php and used as-is. Remove it there to manage the key from this page.', 'wynko-for-laposta' )
				)
			);
			return;
		}

		$has_key = 'empty' !== ApiKey::stored_state();
		printf(
			'<input type="password" name="%s" value="" autocomplete="off" class="regular-text" placeholder="%s" />',
			esc_attr( Config::option_key( 'api_key' ) ),
			esc_attr( $has_key ? __( '••••••••  (saved — leave blank to keep)', 'wynko-for-laposta' ) : __( 'Enter your Laposta API key', 'wynko-for-laposta' ) )
		);
		printf(
			'<p class="description">%s</p>',
			wp_kses( self::key_description(), self::allowed_link_html() )
		);

		$warning = self::plaintext_warning();
		if ( '' !== $warning ) {
			echo wp_kses( $warning, self::allowed_storage_html() );
			echo wp_kses( self::storage_options( true ), self::allowed_storage_html() );
			return;
		}

		printf( '<p class="description">%s</p>', wp_kses( self::storage_note(), self::allowed_link_html() ) );
		echo wp_kses( self::storage_options(), self::allowed_storage_html() );
	}

	/**
	 * Whether this site can seal a stored key. False means the fallback is in
	 * effect: the key is saved, but in the clear.
	 *
	 * @return bool
	 */
	public static function can_encrypt(): bool {
		return '' !== ApiKey::key_material() && Crypto::available();
	}

	/**
	 * What saving does, in one sentence. The caveat about what encryption at
	 * rest does not cover belongs with the alternatives it argues for, so it
	 * lives in storage_options() rather than here.
	 *
	 * @return string
	 */
	public static function storage_note(): string {
		return sprintf(
			/* translators: %s: the words "security salts", linked to the wp_salt() reference. */
			esc_html__( 'Saving a new key verifies it with Laposta first, then stores it in the database, encrypted with this site\'s %s — see below for ways to keep it out of the database entirely.', 'wynko-for-laposta' ),
			self::salts_link( esc_html_x( 'security salts', 'link text in the storage note', 'wynko-for-laposta' ) )
		);
	}

	/**
	 * One anchor to the wp_salt() reference. The link text is passed in, and
	 * already escaped, so each caller can give its own case-marked wording —
	 * a language that inflects after a negation needs different words in the
	 * warning than in the note.
	 *
	 * @param string $text Escaped link text.
	 * @return string
	 */
	private static function salts_link( string $text ): string {
		return sprintf(
			'<a href="%s" target="%s" rel="%s">%s</a>',
			esc_url( Urls::url( 'wp_salt_docs' ) ),
			esc_attr( Urls::target( 'wp_salt_docs' ) ),
			esc_attr( Urls::rel( 'wp_salt_docs' ) ),
			$text
		);
	}

	/**
	 * The routes that keep the key out of the database altogether. Rendered in
	 * every state rather than only the failure one, and collapsed by default so
	 * the field stays scannable.
	 *
	 * @param bool $open Whether the disclosure renders expanded.
	 * @return string
	 */
	public static function storage_options( bool $open = false ): string {
		$environment = esc_html__( 'Set the WYNKO_API_KEY environment variable — in your .env file, your web server config, or your container definition. The key then never reaches the database.', 'wynko-for-laposta' );
		if ( is_multisite() ) {
			$environment .= ' ' . esc_html(
				sprintf(
					/* translators: %s: the environment variable name for this site, e.g. WYNKO_API_KEY_3. */
					__( 'For this site alone, name it %s instead.', 'wynko-for-laposta' ),
					'WYNKO_API_KEY_' . get_current_blog_id()
				)
			);
		}

		$items = array(
			$environment,
			esc_html__( 'Or define it in wp-config.php, above the line that says "That\'s all, stop editing". Same effect, kept in a file instead:', 'wynko-for-laposta' )
			. self::wp_config_example(),
		);

		// list-style-type, not the list-style shorthand: safecss_filter_attr()
		// allowlists the former and silently strips the latter, which would
		// leave the routes-out list without bullets.
		return sprintf(
			'<details%s><summary>%s</summary><ul style="list-style-type:disc;margin-left:1.5em;">%s</ul><p class="description">%s</p></details>',
			$open ? ' open' : '',
			esc_html__( 'Safer ways to store this key', 'wynko-for-laposta' ),
			'<li>' . implode( '</li><li>', $items ) . '</li>',
			esc_html__( 'Encrypting the key in the database protects it in a database dump, but not against anyone who can read wp-config.php. Either route above avoids that.', 'wynko-for-laposta' )
		);
	}

	/**
	 * The wp-config.php lines to copy, as a selectable block rather than as
	 * prose describing them. On multisite the per-site constant is spelled out
	 * with this site's own id filled in, because {blog_id} is the part an
	 * operator is most likely to get wrong and the screen already knows it.
	 *
	 * @return string
	 */
	public static function wp_config_example(): string {
		$lines = array( "define( 'WYNKO_API_KEY', 'your-api-key-here' );" );

		if ( is_multisite() ) {
			$lines[] = '';
			$lines[] = '// ' . __( 'Or, for this site only:', 'wynko-for-laposta' );
			$lines[] = sprintf( "define( 'WYNKO_API_KEY_%d', 'your-api-key-here' );", get_current_blog_id() );
		}

		return sprintf(
			'<pre class="wynko-code"><code>%s</code></pre>',
			esc_html( implode( "\n", $lines ) )
		);
	}

	/**
	 * The plain-text-storage warning, or '' when the site can encrypt. It informs
	 * rather than blocks, and stays visible rather than hiding behind a
	 * disclosure.
	 *
	 * @return string
	 */
	public static function plaintext_warning(): string {
		if ( self::can_encrypt() ) {
			return '';
		}

		$headline = sprintf(
			/* translators: %s: the words "security salts", linked to the wp_salt() reference. */
			esc_html__( 'This site has no usable %s, so the API key is stored in the database as plain text. It still works — but anyone who can read the database can read the key.', 'wynko-for-laposta' ),
			self::salts_link( esc_html_x( 'security salts', 'link text in the no-salts warning', 'wynko-for-laposta' ) )
		);

		$remedy = sprintf(
			/* translators: %s: "security salts", linked to the wp_salt() reference. */
			esc_html__( 'Add %s to wp-config.php. That turns encryption at rest back on for a key kept in the database.', 'wynko-for-laposta' ),
			self::salts_link( esc_html_x( 'security salts', 'link text in the no-salts warning remedy', 'wynko-for-laposta' ) )
		);

		return sprintf(
			'<div class="notice notice-warning inline"><p><strong>%s</strong></p><p>%s</p></div>',
			$headline,
			$remedy
		);
	}

	/**
	 * The HTML allowlist for descriptions that carry a link. Narrow on purpose:
	 * the only markup any of this copy needs is one anchor.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_link_html(): array {
		return array(
			'a' => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);
	}

	/**
	 * The HTML allowlist for the copy under the key field: the warning notice
	 * and its list, the storage disclosure, the wp-config.php block, and the
	 * anchor allowed everywhere else.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_storage_html(): array {
		return array_merge(
			self::allowed_link_html(),
			array(
				'div'     => array( 'class' => true ),
				'p'       => array( 'class' => true ),
				'strong'  => array(),
				'ul'      => array( 'style' => true ),
				'li'      => array(),
				'details' => array( 'open' => true ),
				'summary' => array(),
				'pre'     => array( 'class' => true ),
				'code'    => array(),
			)
		);
	}

	/**
	 * What the field is for, and where to get a key. The URL comes from the
	 * registry rather than from inside the translated string, so a translation
	 * cannot break the link; only the anchor text is translated.
	 *
	 * @return string
	 */
	public static function key_description(): string {
		return sprintf(
			'%s <a href="%s" target="%s" rel="%s">%s</a>',
			esc_html__( 'The API key to connect with your Laposta account.', 'wynko-for-laposta' ),
			esc_url( Urls::url( 'laposta_docs' ) ),
			esc_attr( Urls::target( 'laposta_docs' ) ),
			esc_attr( Urls::rel( 'laposta_docs' ) ),
			esc_html__( 'Learn how to create an API key', 'wynko-for-laposta' )
		);
	}

	/**
	 * Prints the cache-duration field.
	 *
	 * @return void
	 */
	public static function field_ttl(): void {
		if ( self::render_override( 'cache_minutes' ) ) {
			self::print_last_refresh();
			return;
		}

		$bounds = Config::bounds( 'cache_minutes' );
		printf(
			'<input type="number" min="%d" max="%d" name="%s" value="%d" class="small-text" />',
			(int) $bounds['min'],
			(int) $bounds['max'],
			esc_attr( Config::option_key( 'cache_minutes' ) ),
			(int) Config::get( 'cache_minutes' )
		);
		self::print_last_refresh();
	}

	/**
	 * Prints when the cache was last filled, under the duration it is filled
	 * for. The two answer one question together: how stale what you are
	 * looking at can be, and how stale it currently is.
	 *
	 * @return void
	 */
	private static function print_last_refresh(): void {
		printf( '<p class="description">%s</p>', esc_html( Cache::last_refresh_sentence() ) );
	}

	/**
	 * Prints the activity-log threshold picker.
	 *
	 * @return void
	 */
	public static function field_log_level(): void {
		if ( self::render_override( 'log_level' ) ) {
			return;
		}

		$current = Log::threshold();

		printf( '<select name="%s">', esc_attr( Config::option_key( 'log_level' ) ) );
		foreach ( Config::allowed_for( 'log_level' ) as $value ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $value, $current, false ),
				esc_html( LogLevels::label( (string) $value ) )
			);
		}
		echo '</select>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Applies when an event happens, so it decides what gets recorded rather than what is shown. Entries already stored stay visible.', 'wynko-for-laposta' )
		);
	}

	/**
	 * Constrains the submitted threshold to a known level.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_log_level( $value ): string {
		return Sanitizer::log_level( $value, (string) Config::default_for( 'log_level' ) );
	}

	/**
	 * Validates against the API before storing; blank input keeps the existing
	 * key; short-circuits when a constant is in effect.
	 *
	 * Runs twice per save, since update_option() delegates to add_option() when
	 * the row does not exist yet, so the memo is what makes the API call, the log
	 * entry, and the notice happen once. Every "keep what is there" path returns
	 * the raw stored value, so the sanitizer never rewrites what it did not
	 * change.
	 *
	 * @param mixed $value Submitted key.
	 * @return string
	 */
	public static function sanitize_key( $value ): string {
		if ( null !== self::$key_memo ) {
			return self::$key_memo;
		}

		// Our own output coming back round. Nobody types an envelope into the
		// field, and treating one as a submitted key would send the ciphertext
		// to Laposta and then overwrite the value we just sealed.
		if ( Crypto::is_envelope( (string) $value ) ) {
			return (string) $value;
		}

		$stored   = (string) get_option( Config::option_key( 'api_key' ), '' );
		$previous = ApiKey::stored();
		$value    = sanitize_text_field( (string) $value );

		if ( 'option' !== ApiKey::source() && 'none' !== ApiKey::source() ) {
			if ( '' !== $value ) {
				add_settings_error(
					Config::option_key( 'api_key' ),
					'wynko_key_external',
					esc_html(
						sprintf(
							/* translators: %s: name of the environment variable or PHP constant supplying the key. */
							__( 'API key not saved: %s takes precedence.', 'wynko-for-laposta' ),
							ApiKey::source_name()
						)
					),
					'error'
				);
			}
			self::$key_memo = $stored;
			return $stored;
		}

		if ( '' === $value ) {
			self::$key_memo = $stored;
			return $stored;
		}

		$result = CampaignsApi::all( $value );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			/* translators: %s: error message. */
			add_settings_error( Config::option_key( 'api_key' ), 'wynko_key_invalid', esc_html( sprintf( __( 'API key not saved: %s', 'wynko-for-laposta' ), $message ) ), 'error' );
			/* translators: %s: error message. */
			Log::error( sprintf( __( 'API key rejected on save: %s', 'wynko-for-laposta' ), $message ) );
			// Fingerprint the key that resolve() will return now this save is
			// rejected; recording the rejected one leaves field_status() with
			// no usable verdict and it probes the API again.
			KeyStatus::record( $previous, false, $message, (string) $result->get_error_code() );
			self::$key_memo = $stored;
			return $stored;
		}

		Cache::bust();
		KeyStatus::record( $value, true );
		set_transient( Config::transient_key(), $result, Cache::ttl_seconds() );
		// Verifying the key fetched the campaigns, so the data is as fresh as
		// a sync would have left it and the stamp has to say so.
		Cache::stamp( true );
		/* translators: %d: number of campaigns. */
		add_settings_error( Config::option_key( 'api_key' ), 'wynko_key_ok', esc_html( sprintf( __( 'API key verified — %d campaigns loaded.', 'wynko-for-laposta' ), count( $result ) ) ), 'updated' );
		// The campaign count belongs to the sync, which reports it; KeyStatus
		// already records the connection itself.
		Log::info( __( 'API key saved and verified.', 'wynko-for-laposta' ) );

		$sealed         = ApiKey::store( $value );
		self::$key_memo = $sealed;
		return $sealed;
	}

	/**
	 * Handles the "Sync now" post and redirects back with a result flag.
	 *
	 * @return void
	 */
	public static function handle_sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wynko_sync' );

		Cache::bust();
		$result = Cache::refresh();
		self::record_sync_verdict( $result );

		$flag = is_wp_error( $result ) ? 'error' : 'ok';
		wp_safe_redirect( self::sync_redirect_url( $flag ) );
		exit;
	}

	/**
	 * Records what a sync just proved about the key, code as well as message: the
	 * About tab's connection row reads the code to tell "Laposta never answered"
	 * from "Laposta rejected the key". Extracted from handle_sync(), which ends
	 * in exit and so cannot be tested directly.
	 *
	 * @param array<int,mixed>|\WP_Error $result Outcome of the refresh.
	 * @return void
	 */
	public static function record_sync_verdict( $result ): void {
		KeyStatus::record(
			ApiKey::resolve(),
			! is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_message() : '',
			is_wp_error( $result ) ? (string) $result->get_error_code() : ''
		);
	}

	/**
	 * Where "Sync now" returns to. Extracted from handle_sync() so the target
	 * is testable without shimming wp_safe_redirect() and exit.
	 *
	 * @param string $flag Result flag, 'ok' or 'error'.
	 * @return string
	 */
	public static function sync_redirect_url( string $flag ): string {
		return add_query_arg( 'wynko_sync', $flag, self::tab_url( self::TAB_API ) );
	}

	/**
	 * The screen's tabs, slug to label, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			self::TAB_API           => __( 'API', 'wynko-for-laposta' ),
			self::TAB_SECURITY      => __( 'Security', 'wynko-for-laposta' ),
			self::TAB_NOTIFICATIONS => __( 'Notifications', 'wynko-for-laposta' ),
			self::TAB_ABOUT         => __( 'About', 'wynko-for-laposta' ),
		);
	}

	/**
	 * Resolves a requested tab against the known list. Unknown input is not an
	 * error worth a message — it lands on the default tab.
	 *
	 * @param string $requested Raw tab argument.
	 * @return string
	 */
	public static function current_tab( string $requested ): string {
		return array_key_exists( $requested, self::tabs() ) ? $requested : self::TAB_API;
	}

	/**
	 * Admin URL for one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		return add_query_arg( 'tab', self::current_tab( $tab ), Menu::url( self::PAGE ) );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation argument, validated against a known list by current_tab(); no state change on display.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tab       = self::current_tab( $requested );

		echo '<div class="wrap"><h1>' . esc_html__( 'Wynko', 'wynko-for-laposta' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::tab_url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( self::TAB_ABOUT === $tab ) {
			AboutTab::render();
			echo '</div>';
			return;
		}

		if ( self::TAB_NOTIFICATIONS === $tab ) {
			NotificationsTab::render();
			echo '</div>';
			return;
		}

		if ( self::TAB_SECURITY === $tab ) {
			SecurityTab::render();
			echo '</div>';
			return;
		}

		self::render_api_tab();
		echo '</div>';
	}

	/**
	 * The API tab: the sync result notice, the settings form, and the Sync now
	 * button that shares its action row.
	 *
	 * @return void
	 */
	private static function render_api_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flag set by handle_sync()'s own wp_safe_redirect; no state change on display.
		$sync = isset( $_GET['wynko_sync'] ) ? sanitize_text_field( wp_unslash( $_GET['wynko_sync'] ) ) : '';
		if ( '' !== $sync ) {
			$ok = ( 'ok' === $sync );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				esc_html( $ok ? __( 'Sync complete.', 'wynko-for-laposta' ) : __( 'Sync failed — see the activity log.', 'wynko-for-laposta' ) )
			);
		}

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		echo '<div class="wynko-actions">';
		submit_button( __( 'Save changes', 'wynko-for-laposta' ), 'primary', 'submit', false );
		// Sync posts to its own form, declared below, so the two stay separate
		// forms while their buttons share a row.
		printf(
			'<button type="submit" form="wynko-sync" class="button button-secondary">%s</button>',
			esc_html__( 'Sync now', 'wynko-for-laposta' )
		);
		echo '</div>';
		// "Sync now" rather than "Flush cache" because it does both, and the
		// fetch is the half worth naming: flushing alone would leave the screen
		// with no data and nothing said about why.
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Sync now clears the cached campaigns, lists, and fields, then fetches them from Laposta again.', 'wynko-for-laposta' )
		);
		echo '</form>';

		echo '<form id="wynko-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wynko_sync" />';
		wp_nonce_field( 'wynko_sync' );
		echo '</form>';
	}
}
