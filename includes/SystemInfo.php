<?php
/**
 * Environment and plugin-state readings for the About tab's system report.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Admin\Forms\FormsListPage;
use Wynko\Integrations\Registry;
use Wynko\Support\Crypto;
use Wynko\Support\Requirements;
use Wynko\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the environment once and returns worded rows carrying a verdict. Both
 * the on-screen table and the downloadable text file render this same array, so
 * a pasted report cannot disagree with what the operator saw.
 *
 * The report is meant to be pasted into a public support thread, so the API key
 * never appears in it — only its source and the cached verdict about it.
 */
final class SystemInfo {

	const REACHABLE_YES     = 'yes';
	const REACHABLE_NO      = 'no';
	const REACHABLE_UNKNOWN = 'unknown';

	/**
	 * Row statuses for a feature that is either on or off, rather than a
	 * version against a threshold — SystemReport::icon() gives these a plain
	 * yes/no icon instead of Requirements::STATUS_*'s check/warning-triangle
	 * pairing, and they carry no weight in SystemInfo::environment()'s
	 * verdict: a signup-form protection an administrator chose to turn off is
	 * not an environment shortfall.
	 */
	const PROTECTION_ENABLED  = 'protection_enabled';
	const PROTECTION_DISABLED = 'protection_disabled';

	/** The connection row's own control: re-probe the API. */
	const ACTION_PING = 'ping';

	/** The API key row's own control, shown only for a stored key that is not encrypted: jump to where the API tab explains why and how to fix it. */
	const ACTION_ENCRYPT_HELP = 'encrypt_help';

	/**
	 * Builds one report row.
	 *
	 * @param string $label  Row label.
	 * @param string $value  Current reading, already worded.
	 * @param string $note   Threshold tail, or ''.
	 * @param string $status A Requirements::STATUS_* constant.
	 * @param string $action A self::ACTION_* slug the row offers, or ''.
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function row( string $label, string $value, string $note = '', string $status = Requirements::STATUS_INFO, string $action = '' ): array {
		return array(
			'label'  => $label,
			'value'  => $value,
			'note'   => $note,
			'status' => $status,
			'action' => $action,
		);
	}

	/**
	 * Returns the threshold tail for a reading that falls short, and '' for one
	 * that does not: a row that meets what it needs has nothing to say about the
	 * bar it cleared, and printing both thresholds beside every reading left the
	 * two impossible to tell apart at a glance.
	 *
	 * @param string $required Required version, or ''.
	 * @param string $advised  Advised version, or ''.
	 * @param string $status   The row's verdict, a Requirements::STATUS_* constant.
	 * @return string
	 */
	private static function note( string $required, string $advised, string $status ): string {
		if ( Requirements::STATUS_BELOW_REQUIRED === $status && '' !== $required ) {
			/* translators: %s: minimum supported version. */
			return sprintf( __( 'requires %s or newer', 'wynko-for-laposta' ), $required );
		}
		if ( Requirements::STATUS_BELOW_ADVISED === $status && '' !== $advised ) {
			/* translators: %s: recommended version. */
			return sprintf( __( '%s or newer is advised', 'wynko-for-laposta' ), $advised );
		}
		return '';
	}

	/**
	 * Returns the versions the plugin header declares. Read from the header
	 * rather than repeated in configuration: WordPress enforces those same two
	 * values from there, and a second copy would drift.
	 *
	 * @return array<string,string> Keyed by the two header names below.
	 */
	private static function declared_minimums(): array {
		$headers = get_file_data(
			WYNKO_FILE,
			array(
				'php'       => 'Requires PHP',
				'wordpress' => 'Requires at least',
			)
		);

		return array(
			'php'       => (string) ( $headers['php'] ?? '' ),
			'wordpress' => (string) ( $headers['wordpress'] ?? '' ),
		);
	}

	/**
	 * Words a yes/no reading once, so every row agrees.
	 *
	 * @param bool $value Reading.
	 * @return string
	 */
	private static function yes_no( bool $value ): string {
		return $value ? __( 'Yes', 'wynko-for-laposta' ) : __( 'No', 'wynko-for-laposta' );
	}

	/**
	 * The word for a reading that could not be taken.
	 *
	 * @return string
	 */
	private static function unknown(): string {
		return __( 'Unknown', 'wynko-for-laposta' );
	}

	/**
	 * Words a reading that may be missing, appending a unit when it is not.
	 *
	 * @param string $value  Raw reading.
	 * @param string $suffix Unit to append, e.g. 's'.
	 * @return string
	 */
	private static function or_unknown( string $value, string $suffix = '' ): string {
		return '' === trim( $value ) ? self::unknown() : $value . $suffix;
	}

	/**
	 * Returns every section of the report, in display order.
	 *
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string,note:string,status:string,action:string}>}>
	 */
	public static function sections(): array {
		$sections = array_merge(
			self::environment_sections(),
			array(
				array(
					'title' => __( 'Plugin', 'wynko-for-laposta' ),
					'rows'  => self::plugin_rows(),
				),
				array(
					'title' => __( 'Security', 'wynko-for-laposta' ),
					'rows'  => self::security_rows(),
				),
			)
		);

		$integrations = self::integration_rows();
		if ( array() !== $integrations ) {
			$sections[] = array(
				'title' => __( 'Integrations', 'wynko-for-laposta' ),
				'rows'  => $integrations,
			);
		}

		return $sections;
	}

	/**
	 * Returns the sections that describe the environment rather than the
	 * plugin's own state.
	 *
	 * The Plugin section is left out because its connection verdict fails on a
	 * wrong key, which is an API problem rather than an environment one and
	 * would otherwise raise the requirements notice on every admin screen.
	 *
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string,note:string,status:string,action:string}>}>
	 */
	private static function environment_sections(): array {
		return array(
			array(
				'title' => __( 'WordPress', 'wynko-for-laposta' ),
				'rows'  => self::wordpress_rows(),
			),
			array(
				'title' => __( 'PHP', 'wynko-for-laposta' ),
				'rows'  => self::php_rows(),
			),
			array(
				'title' => __( 'Database', 'wynko-for-laposta' ),
				'rows'  => self::database_rows(),
			),
			array(
				'title' => __( 'PHP modules', 'wynko-for-laposta' ),
				'rows'  => self::module_rows(),
			),
			array(
				'title' => __( 'Server', 'wynko-for-laposta' ),
				'rows'  => self::server_rows(),
			),
		);
	}

	/**
	 * Returns what the environment amounts to: the worst verdict it carries, the
	 * rows that earned it, and a digest of the whole reading.
	 *
	 * All three come back from one gather because the caller runs on every admin
	 * page, and two calls would mean two db_server_info() round trips and two
	 * ini_get_all() reads per load.
	 *
	 * Rows are named "Section — Label" because the labels repeat across
	 * sections. The digest covers every environment row rather than only the
	 * failing ones, so a dismissed notice returns as soon as the environment
	 * changes at all.
	 *
	 * @return array{status:string,items:array<int,array{name:string,value:string,note:string,status:string}>,fingerprint:string}
	 */
	public static function environment(): array {
		$status = Requirements::STATUS_OK;
		$items  = array();
		$parts  = array();

		foreach ( self::environment_sections() as $section ) {
			foreach ( $section['rows'] as $row ) {
				$parts[] = $row['label'] . '=' . $row['value'] . '/' . $row['status'];

				if ( ! self::is_shortfall( $row['status'] ) ) {
					continue;
				}

				if ( Requirements::STATUS_BELOW_REQUIRED === $row['status'] ) {
					$status = Requirements::STATUS_BELOW_REQUIRED;
				} elseif ( Requirements::STATUS_OK === $status ) {
					$status = Requirements::STATUS_BELOW_ADVISED;
				}

				$items[] = array(
					'name'   => sprintf(
						/* translators: 1: report section, e.g. "PHP"; 2: row label, e.g. "Version". */
						__( '%1$s — %2$s', 'wynko-for-laposta' ),
						$section['title'],
						$row['label']
					),
					'value'  => $row['value'],
					'note'   => $row['note'],
					'status' => $row['status'],
				);
			}
		}

		return array(
			'status'      => $status,
			'items'       => $items,
			'fingerprint' => hash( 'sha256', implode( '|', $parts ) ),
		);
	}

	/**
	 * Whether a verdict is one the notice should speak up about. UNKNOWN is not:
	 * a reading that could not be taken is not evidence of a problem, and a
	 * notice raised on every host that disables ini_get_all() would be noise.
	 *
	 * @param string $status A Requirements::STATUS_* constant.
	 * @return bool
	 */
	private static function is_shortfall( string $status ): bool {
		return Requirements::STATUS_BELOW_REQUIRED === $status
			|| Requirements::STATUS_BELOW_ADVISED === $status;
	}

	/**
	 * Returns the WordPress version, install shape, and configured behaviour.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function wordpress_rows(): array {
		$requirements = Config::requirements();
		$advised      = (string) ( $requirements['wordpress']['advised'] ?? '' );
		$required     = self::declared_minimums()['wordpress'];
		$current      = (string) get_bloginfo( 'version' );
		$status       = Requirements::classify( $current, $required, $advised );

		return array(
			self::row(
				__( 'Version', 'wynko-for-laposta' ),
				self::or_unknown( $current ),
				self::note( $required, $advised, $status ),
				$status
			),
			self::row( __( 'Multisite', 'wynko-for-laposta' ), self::yes_no( is_multisite() ) ),
			self::row( __( 'Environment type', 'wynko-for-laposta' ), (string) wp_get_environment_type() ),
			self::row( __( 'Debug mode', 'wynko-for-laposta' ), self::yes_no( defined( 'WP_DEBUG' ) && WP_DEBUG ) ),
			self::row( __( 'Site language', 'wynko-for-laposta' ), (string) get_bloginfo( 'language' ) ),
			self::caching_row(),
		);
	}

	/**
	 * Returns whether a page-caching plugin is active, and which one where
	 * that can be told. Read from the same mechanism WordPress core itself
	 * uses to decide whether to load one: get_dropins() only reports
	 * advanced-cache.php when WP_CACHE is on and the file exists, so its
	 * declared name is a stronger signal than checking the constant alone —
	 * every mainstream page-caching plugin (WP Super Cache, WP Rocket, W3
	 * Total Cache, LiteSpeed Cache, and the rest) writes that drop-in with a
	 * proper plugin header for exactly this purpose.
	 *
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function caching_row(): array {
		if ( ! function_exists( 'get_dropins' ) ) {
			return self::row( __( 'Page caching', 'wynko-for-laposta' ), self::unknown() );
		}

		$dropins = get_dropins();
		if ( ! isset( $dropins['advanced-cache.php'] ) ) {
			return self::row( __( 'Page caching', 'wynko-for-laposta' ), self::yes_no( false ) );
		}

		$name = self::dropin_name( 'advanced-cache.php', (string) ( $dropins['advanced-cache.php']['Name'] ?? '' ) );

		return self::row( __( 'Page caching', 'wynko-for-laposta' ), '' === $name ? self::yes_no( true ) : $name );
	}

	/**
	 * Returns one active drop-in's real name — its declared plugin header
	 * where it has one, otherwise a guess from a short list of well-known
	 * signature strings inside the file itself, '' when neither identifies
	 * it.
	 *
	 * The header alone is not enough: get_plugin_data() falls back to the
	 * bare filename when a drop-in carries no real header, which is true of
	 * most page-caching plugins' advanced-cache.php — it is a thin,
	 * auto-generated loader rather than the plugin's own file (confirmed
	 * against WP Super Cache's, which opens with a bare `// WP SUPER CACHE`
	 * comment and nothing a plugin-header parser recognises). A name that
	 * turns out to just be the filename is treated as no name at all.
	 *
	 * @param string $file        Drop-in filename, e.g. 'advanced-cache.php'.
	 * @param string $header_name Its get_dropins() 'Name', '' if it has none.
	 * @return string
	 */
	private static function dropin_name( string $file, string $header_name ): string {
		$header_name = trim( $header_name );
		if ( '' !== $header_name && $file !== $header_name ) {
			return $header_name;
		}

		if ( ! defined( 'WP_CONTENT_DIR' ) || ! is_readable( WP_CONTENT_DIR . '/' . $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local drop-in file WordPress itself already reported as active, not a remote resource.
		$head = (string) file_get_contents( WP_CONTENT_DIR . '/' . $file, false, null, 0, 4096 );

		$signals = array(
			'WP SUPER CACHE'    => 'WP Super Cache',
			'WP Rocket'         => 'WP Rocket',
			'W3 Total Cache'    => 'W3 Total Cache',
			'W3TC'              => 'W3 Total Cache',
			'LiteSpeed Cache'   => 'LiteSpeed Cache',
			'Cache Enabler'     => 'Cache Enabler',
			'WP Fastest Cache'  => 'WP Fastest Cache',
			'Comet Cache'       => 'Comet Cache',
			'Swift Performance' => 'Swift Performance',
			'Breeze'            => 'Breeze',
			'SG Optimizer'      => 'SiteGround Optimizer',
			'WP-Optimize'       => 'WP-Optimize',
		);

		foreach ( $signals as $needle => $vendor ) {
			if ( false !== stripos( $head, $needle ) ) {
				return $vendor;
			}
		}

		return '';
	}

	/**
	 * Returns the PHP version, interface, and the memory it is allowed and
	 * using.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function php_rows(): array {
		$requirements = Config::requirements();
		$advised      = (string) ( $requirements['php']['advised'] ?? '' );
		$required     = self::declared_minimums()['php'];
		$current      = (string) phpversion();
		$status       = Requirements::classify( $current, $required, $advised );

		return array(
			self::row(
				__( 'Version', 'wynko-for-laposta' ),
				self::or_unknown( $current ),
				self::note( $required, $advised, $status ),
				$status
			),
			self::row( __( 'Server interface', 'wynko-for-laposta' ), (string) php_sapi_name() ),
			self::memory_row(),
			self::row(
				__( 'WordPress memory limit', 'wynko-for-laposta' ),
				defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : self::unknown()
			),
			self::row(
				__( 'WordPress admin memory limit', 'wynko-for-laposta' ),
				defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : self::unknown()
			),
			self::row(
				__( 'Max execution time', 'wynko-for-laposta' ),
				self::or_unknown( self::configured_ini( 'max_execution_time' ), 's' )
			),
		);
	}

	/**
	 * Returns what php.ini configures for one directive, rather than what this
	 * request has since ini_set() it to.
	 *
	 * This keeps the two renderings of the report in agreement: admin.php raises
	 * the memory limit on the way in and admin-post.php does not, so the same row
	 * would otherwise read 256M on screen and 128M in the downloaded file. Every
	 * reading a request can change is taken from here, bar the verdict-free
	 * exception request_rows() argues for.
	 *
	 * @param string $directive php.ini directive name.
	 * @return string '' when the directive cannot be read.
	 */
	private static function configured_ini( string $directive ): string {
		if ( function_exists( 'ini_get_all' ) ) {
			$settings = ini_get_all( 'core', true );
			$global   = is_array( $settings ) ? ( $settings[ $directive ]['global_value'] ?? null ) : null;
			if ( is_scalar( $global ) ) {
				return (string) $global;
			}
		}

		// ini_get_all() is a common disable_functions entry; where it is gone the
		// live value is all there is.
		return (string) ini_get( $directive );
	}

	/**
	 * Returns the memory-limit row, from what php.ini configures rather than
	 * from what this request runs with — see configured_ini(). A negative limit
	 * is "unlimited", which clears any threshold; zero means the value could
	 * not be read at all, which is not the same as a limit that is too low.
	 *
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function memory_row(): array {
		$requirements   = Config::requirements();
		$advised_memory = (string) ( $requirements['memory']['advised'] ?? '' );
		$advised_bytes  = Requirements::bytes_from_ini( $advised_memory );
		$limit          = Requirements::bytes_from_ini( self::configured_ini( 'memory_limit' ) );

		if ( 0 === $limit ) {
			$status = Requirements::STATUS_UNKNOWN;
			$value  = self::unknown();
		} elseif ( $limit < 0 ) {
			$status = Requirements::STATUS_OK;
			$value  = __( 'Unlimited', 'wynko-for-laposta' );
		} else {
			$status = ( 0 < $advised_bytes && $limit < $advised_bytes )
				? Requirements::STATUS_BELOW_ADVISED
				: Requirements::STATUS_OK;
			$value  = Requirements::format_bytes( $limit );
		}

		return self::row(
			__( 'Memory limit', 'wynko-for-laposta' ),
			$value,
			self::note( '', $advised_memory, $status ),
			$status
		);
	}

	/**
	 * Returns which database server this is, and whether it is new enough.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function database_rows(): array {
		global $wpdb;

		$banner = ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) )
			? (string) $wpdb->db_server_info()
			: '';
		$server = Requirements::database_server( $banner );

		if ( '' === $server['name'] ) {
			return array( self::row( __( 'Server', 'wynko-for-laposta' ), self::unknown(), '', Requirements::STATUS_UNKNOWN ) );
		}

		$thresholds = Config::requirements()['database'][ strtolower( $server['name'] ) ] ?? array();
		$required   = (string) ( $thresholds['required'] ?? '' );
		$advised    = (string) ( $thresholds['advised'] ?? '' );
		$status     = Requirements::classify( $server['version'], $required, $advised );

		return array(
			self::row(
				__( 'Server', 'wynko-for-laposta' ),
				$server['name'] . ' ' . $server['version'],
				self::note( $required, $advised, $status ),
				$status
			),
		);
	}

	/**
	 * Returns one row per module the plugin needs or recommends, plus the
	 * missing list.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function module_rows(): array {
		$modules  = Config::requirements()['modules'] ?? array();
		$required = array_map( 'strval', (array) ( $modules['required'] ?? array() ) );
		$advised  = array_map( 'strval', (array) ( $modules['advised'] ?? array() ) );

		$rows   = array();
		$loaded = array();
		foreach ( array_merge( $required, $advised ) as $module ) {
			$present = extension_loaded( $module );
			if ( $present ) {
				$loaded[] = $module;
			}

			$is_required = in_array( $module, $required, true );
			$rows[]      = self::row(
				$module,
				$present ? __( 'Loaded', 'wynko-for-laposta' ) : __( 'Missing', 'wynko-for-laposta' ),
				self::module_note( $present, $is_required ),
				self::module_status( $present, $is_required )
			);
		}

		$missing = Requirements::missing( array_merge( $required, $advised ), $loaded );
		$rows[]  = self::row(
			__( 'Missing', 'wynko-for-laposta' ),
			array() === $missing ? __( 'None', 'wynko-for-laposta' ) : implode( ', ', $missing ),
			'',
			array() === $missing ? Requirements::STATUS_OK : Requirements::STATUS_INFO
		);
		$rows[]  = self::transport_row();

		return $rows;
	}

	/**
	 * Returns whether an outbound HTTPS request can be made at all.
	 *
	 * A row of its own rather than an entry on the required-modules list, because
	 * it is a condition on a pair: curl links its own TLS library, and only the
	 * streams fallback needs the openssl extension. Losing both is what breaks
	 * every call the plugin makes.
	 *
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function transport_row(): array {
		$curl    = extension_loaded( 'curl' );
		$openssl = extension_loaded( 'openssl' );

		if ( $curl || $openssl ) {
			return self::row(
				__( 'Outbound HTTPS', 'wynko-for-laposta' ),
				$curl ? __( 'Available (curl)', 'wynko-for-laposta' ) : __( 'Available (openssl)', 'wynko-for-laposta' ),
				'',
				Requirements::STATUS_OK
			);
		}

		return self::row(
			__( 'Outbound HTTPS', 'wynko-for-laposta' ),
			__( 'Unavailable', 'wynko-for-laposta' ),
			__( 'every call to Laposta needs one of curl or openssl; with neither, nothing this plugin does can reach the API', 'wynko-for-laposta' ),
			Requirements::STATUS_BELOW_REQUIRED
		);
	}

	/**
	 * Returns the note for one module row. A module that is present says
	 * nothing about which list it came from — the same rule the version rows
	 * follow, so the report reads as a list of problems rather than of
	 * thresholds.
	 *
	 * @param bool $present     Whether the extension is loaded.
	 * @param bool $is_required Whether it is on the required list.
	 * @return string
	 */
	private static function module_note( bool $present, bool $is_required ): string {
		if ( $present ) {
			return '';
		}
		return $is_required
			? __( 'required by this plugin', 'wynko-for-laposta' )
			: __( 'advised', 'wynko-for-laposta' );
	}

	/**
	 * Returns the verdict for one module: a missing required module is a
	 * failure, a missing advised one is not.
	 *
	 * @param bool $present     Whether the extension is loaded.
	 * @param bool $is_required Whether it is on the required list.
	 * @return string
	 */
	private static function module_status( bool $present, bool $is_required ): string {
		if ( $present ) {
			return Requirements::STATUS_OK;
		}
		return $is_required ? Requirements::STATUS_BELOW_REQUIRED : Requirements::STATUS_BELOW_ADVISED;
	}

	/**
	 * Returns what is serving the site, how it is secured, and what this
	 * particular request came in over.
	 *
	 * Only two of these carry a verdict; the rest are readings PHP can take but
	 * cannot vouch for, as request_rows() explains.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function server_rows(): array {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';

		return array_merge(
			array(
				self::row( __( 'Software', 'wynko-for-laposta' ), '' === $software ? self::unknown() : $software ),
				self::cdn_row(),
				self::https_row(),
				self::tls_library_row(),
			),
			self::request_rows()
		);
	}

	/**
	 * Returns which CDN or reverse proxy this request passed through, guessed
	 * from headers vendors are each known to add. Informational rather than a
	 * verdict — a site is not wrong for using one, or for not using one — and
	 * the note says outright that the guess is unverified: none of these
	 * headers are authenticated, so a client that reaches the origin directly
	 * can send any of them itself.
	 *
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function cdn_row(): array {
		$found = self::detected_cdn();

		return self::row(
			__( 'CDN / proxy', 'wynko-for-laposta' ),
			'' === $found ? self::yes_no( false ) : $found,
			'' === $found ? '' : __( 'guessed from request headers — unverified, and any of them can be spoofed', 'wynko-for-laposta' )
		);
	}

	/**
	 * Matches this request's headers against the identifying header each
	 * vendor is known to add, most specific first, so two that both set
	 * X-Forwarded-For still resolve to the one that actually named itself.
	 * '' when nothing matches, including a bare forwarding header with
	 * nothing more specific behind it — which only says some proxy is in
	 * front, not which one, so it falls through to "Unidentified reverse
	 * proxy" rather than a guessed vendor name.
	 *
	 * @return string
	 */
	private static function detected_cdn(): string {
		$signals = array(
			'HTTP_CF_RAY'           => 'Cloudflare',
			'HTTP_CF_CONNECTING_IP' => 'Cloudflare',
			'HTTP_X_AMZ_CF_ID'      => 'Amazon CloudFront',
			'HTTP_FASTLY_CLIENT_IP' => 'Fastly',
			'HTTP_X_SUCURI_ID'      => 'Sucuri',
			'HTTP_X_SUCURI_CACHE'   => 'Sucuri',
			'HTTP_X_AZURE_REF'      => 'Azure Front Door',
			'HTTP_X_KEYCDN_SHIELD'  => 'KeyCDN',
			'HTTP_X_MAXCDN_CACHE'   => 'StackPath (MaxCDN)',
		);

		foreach ( $signals as $header => $name ) {
			if ( '' !== self::server_value( $header ) ) {
				return $name;
			}
		}

		if ( false !== stripos( self::server_value( 'HTTP_VIA' ), 'varnish' ) ) {
			return 'Varnish';
		}

		if ( '' !== self::server_value( 'HTTP_X_FORWARDED_FOR' ) || '' !== self::server_value( 'HTTP_VIA' ) ) {
			return __( 'Unidentified reverse proxy', 'wynko-for-laposta' );
		}

		return '';
	}

	/**
	 * Reads one $_SERVER value the way every reading in this class does:
	 * sanitized, and only ever read for what it is — a header that arrived
	 * with this name — never trusted as authenticated fact. One place for the
	 * isset/unslash/sanitize dance server_rows() and cdn detection both need,
	 * rather than repeating it per header.
	 *
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	private static function server_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only environment/header reading, not submitted form input; no state changes here.
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	/**
	 * Returns whether the site is served over HTTPS.
	 *
	 * Read from the site's own address rather than from is_ssl(), which answers
	 * only for the request in hand and so misreads both FORCE_SSL_ADMIN and a
	 * proxy that terminates TLS without forwarding the scheme.
	 *
	 * Advised rather than required, and not judged at all on a local or
	 * development install, where plain HTTP is normal and a permanent warning
	 * would stop being read.
	 *
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function https_row(): array {
		$secure = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : is_ssl();
		$local  = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		if ( $secure ) {
			return self::row( __( 'HTTPS', 'wynko-for-laposta' ), self::yes_no( true ), '', Requirements::STATUS_OK );
		}

		return self::row(
			__( 'HTTPS', 'wynko-for-laposta' ),
			self::yes_no( false ),
			$local
				? __( 'not counted against a local or development install', 'wynko-for-laposta' )
				: __( 'signup forms post a name and an email address; without HTTPS those travel in the clear', 'wynko-for-laposta' ),
			$local ? Requirements::STATUS_INFO : Requirements::STATUS_BELOW_ADVISED
		);
	}

	/**
	 * Returns the TLS library PHP will make outbound calls with.
	 *
	 * This is the one TLS reading that is both knowable and this plugin's
	 * business: every call to Laposta is an outbound HTTPS request, and a host
	 * whose OpenSSL predates TLS 1.2 fails those at the handshake with an error
	 * that reads like a bug in the plugin.
	 *
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function tls_library_row(): array {
		$advised = (string) ( Config::requirements()['openssl']['advised'] ?? '' );
		$banner  = self::tls_banner();
		$version = Requirements::openssl_version( $banner );

		if ( '' === $version ) {
			return self::row(
				__( 'TLS library', 'wynko-for-laposta' ),
				'' === $banner ? self::unknown() : $banner,
				'',
				Requirements::STATUS_UNKNOWN
			);
		}

		$status = Requirements::classify( $version, '', $advised );

		return self::row(
			__( 'TLS library', 'wynko-for-laposta' ),
			$banner,
			self::note( '', $advised, $status ),
			$status
		);
	}

	/**
	 * Returns the TLS banner, preferring curl's because that is the transport
	 * WordPress reaches for first; PHP's own is the fallback, and matters when
	 * curl is absent and the streams transport does the work.
	 *
	 * @return string
	 */
	private static function tls_banner(): string {
		if ( function_exists( 'curl_version' ) ) {
			$curl = curl_version();
			if ( is_array( $curl ) && isset( $curl['ssl_version'] ) && is_string( $curl['ssl_version'] ) ) {
				return $curl['ssl_version'];
			}
		}

		return defined( 'OPENSSL_VERSION_TEXT' ) ? (string) OPENSSL_VERSION_TEXT : '';
	}

	/**
	 * Returns what this request came in over: the HTTP version and, where the
	 * server bothers to say, the TLS version.
	 *
	 * Both are informational on purpose: SERVER_PROTOCOL is what PHP was handed
	 * rather than what the browser negotiated, and SSL_PROTOCOL is absent outside
	 * Apache's mod_ssl, so a verdict drawn from either would be wrong on plenty
	 * of correctly configured hosts.
	 *
	 * They are also the one exception to configured_ini()'s rule, tolerable only
	 * because neither carries a verdict: the screen and the downloaded file may
	 * legitimately disagree about them.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function request_rows(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the server's own description of the connection, not submitted input; no state changes here.
		$protocol = isset( $_SERVER['SERVER_PROTOCOL'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) )
			: '';
		$tls      = isset( $_SERVER['SSL_PROTOCOL'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SSL_PROTOCOL'] ) )
			: '';

		return array(
			self::row(
				__( 'Request protocol', 'wynko-for-laposta' ),
				'' === $protocol ? self::unknown() : $protocol,
				__( 'as PHP was handed it; a proxy or CDN may serve a newer version to browsers', 'wynko-for-laposta' )
			),
			self::row(
				__( 'Request TLS version', 'wynko-for-laposta' ),
				'' === $tls ? self::unknown() : $tls,
				'' === $tls ? __( 'not reported by this server; most stacks other than Apache do not set it', 'wynko-for-laposta' ) : ''
			),
		);
	}

	/**
	 * Returns one row per registered integration, or an empty array when none
	 * are registered — sections() leaves the whole "Integrations" section out
	 * in that case rather than printing an empty one. Informational rather
	 * than a verdict, so it carries no status icon: a third-party integration
	 * being disabled is not a problem this report should flag.
	 *
	 * The name()/author()/version() values can originate from a third-party plugin or
	 * theme's Integration implementation rather than from Wynko itself; the
	 * HTML rendering path gets newline safety for free from WordPress's own
	 * output escaping, but these rows also feed SystemReport's plain-text
	 * export, which has no such escaping — so each is run through
	 * Sanitizer::single_line() here to stop a forged multi-line value from
	 * injecting fake report content there.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function integration_rows(): array {
		$rows = array();
		foreach ( Registry::all() as $slug => $integration ) {
			$name    = Sanitizer::single_line( $integration->name() );
			$author  = Sanitizer::single_line( $integration->author() );
			$version = Sanitizer::single_line( $integration->version() );
			$rows[]  = self::row(
				$name,
				Integrations::is_enabled( $slug )
					? __( 'enabled', 'wynko-for-laposta' )
					: __( 'disabled', 'wynko-for-laposta' ),
				'' === $author
					? sprintf(
						/* translators: %s: version number. */
						__( 'version %s', 'wynko-for-laposta' ),
						$version
					)
					: sprintf(
						/* translators: 1: version number, 2: integration author or plugin name. */
						__( 'version %1$s — provided by %2$s', 'wynko-for-laposta' ),
						$version,
						$author
					)
			);
		}
		return $rows;
	}

	/**
	 * Returns the plugin's own state: version, where its key comes from, and
	 * the last cached verdict about that key.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function plugin_rows(): array {
		$source  = ApiKey::source();
		$cached  = KeyStatus::cached( ApiKey::resolve() );
		$verdict = is_array( $cached ) ? $cached : array(
			'ok'      => false,
			'message' => '',
			'code'    => '',
		);

		return array(
			self::row( __( 'Plugin version', 'wynko-for-laposta' ), defined( 'WYNKO_VERSION' ) ? (string) WYNKO_VERSION : self::unknown() ),
			self::api_key_row( $source ),
			self::row(
				__( 'Cache duration', 'wynko-for-laposta' ),
				sprintf(
					/* translators: %d: number of minutes. */
					__( '%d minutes', 'wynko-for-laposta' ),
					(int) Config::get( 'cache_minutes' )
				)
			),
			self::row( __( 'Last sync', 'wynko-for-laposta' ), self::last_sync_label() ),
			self::row(
				__( 'Connection status', 'wynko-for-laposta' ),
				self::connection_label( $source, $verdict ),
				self::reachable_note( self::reachability( $verdict, $source ), (bool) $verdict['ok'] ),
				self::connection_status( $source, $verdict ),
				self::ACTION_PING
			),
		);
	}

	/**
	 * Returns the configured signup rate limit as one line: the two caps and
	 * the window they are counted over. Read from the options rather than the
	 * defaults — a report that showed what the plugin ships with would be
	 * silent about exactly the setting an operator changed.
	 *
	 * @return string
	 */
	private static function throttle_label(): string {
		return sprintf(
			/* translators: 1: signups allowed from one visitor, 2: signups allowed on one form, 3: number of minutes in the window. */
			__( '%1$d per visitor, %2$d per form, per %3$d minutes', 'wynko-for-laposta' ),
			(int) Config::get( 'throttle_ip_max' ),
			(int) Config::get( 'throttle_form_max' ),
			(int) Config::get( 'throttle_window' )
		);
	}

	/**
	 * Returns the submit endpoint's two protections and rate-limit standing:
	 * whether the nonce check and the throttle are switched on, the
	 * configured caps, and how much of the per-form cap the open window has
	 * actually used — the setting alone says nothing about whether it is
	 * anywhere near being tested.
	 *
	 * @return array<int,array{label:string,value:string,note:string,status:string,action:string}>
	 */
	private static function security_rows(): array {
		$nonce_enabled    = ! Config::form_nonce_disabled();
		$throttle_enabled = ! Config::form_throttle_disabled();

		return array(
			self::row(
				__( 'Nonce verification', 'wynko-for-laposta' ),
				self::yes_no( $nonce_enabled ),
				'',
				$nonce_enabled ? self::PROTECTION_ENABLED : self::PROTECTION_DISABLED
			),
			self::row(
				__( 'Rate limiting', 'wynko-for-laposta' ),
				self::yes_no( $throttle_enabled ),
				'',
				$throttle_enabled ? self::PROTECTION_ENABLED : self::PROTECTION_DISABLED
			),
			self::row( __( 'Signup rate limit', 'wynko-for-laposta' ), self::throttle_label() ),
			self::row( __( 'Signups in the current window', 'wynko-for-laposta' ), self::throttle_usage_label() ),
		);
	}

	/**
	 * Returns how much of the per-form cap each published form has actually
	 * used in the window that is open now, one figure per form — the setting
	 * alone says what the ceiling is, not whether traffic is anywhere near it.
	 *
	 * Joined with newlines rather than commas: SystemReport::render() reads a
	 * newline in a value as "one item per line" and renders it as a list, and
	 * SystemReport::text() prints one indented line per item, both preferable
	 * to a single long comma-separated line once a site has more than a
	 * couple of forms.
	 *
	 * @return string
	 */
	private static function throttle_usage_label(): string {
		$forms = FormsListPage::forms();
		if ( array() === $forms ) {
			return __( 'No signup forms yet', 'wynko-for-laposta' );
		}

		$max   = Config::throttle_max( 'form' );
		$parts = array();
		foreach ( $forms as $form ) {
			$parts[] = sprintf( '%1$s: %2$d/%3$d', $form->display_name(), Throttle::form_hits( $form->id() ), $max );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Returns where the key is coming from, in the operator's terms rather than
	 * the resolver's slugs. An unreadable stored key outranks the source: the
	 * key is in the database, it just cannot be opened.
	 *
	 * @param string $source ApiKey::source() slug.
	 * @return string
	 */
	private static function key_source_label( string $source ): string {
		if ( 'unreadable' === ApiKey::stored_state() ) {
			return __( 'Database — unreadable (the security salts changed)', 'wynko-for-laposta' );
		}
		if ( 'env' === $source ) {
			/* translators: %s: environment variable name. */
			return sprintf( __( 'Environment variable (%s)', 'wynko-for-laposta' ), ApiKey::source_name() );
		}
		if ( 'constant' === $source ) {
			/* translators: %s: wp-config.php constant name. */
			return sprintf( __( 'wp-config.php constant (%s)', 'wynko-for-laposta' ), ApiKey::source_name() );
		}
		if ( 'option' === $source ) {
			return __( 'Database', 'wynko-for-laposta' );
		}
		return __( 'No API key configured', 'wynko-for-laposta' );
	}

	/**
	 * Returns the API key source row, with an encrypted/not-encrypted reading
	 * appended when the key is actually in the database — the only place that
	 * question means anything, since an environment variable or wp-config.php
	 * constant was never in the database to begin with.
	 *
	 * @param string $source ApiKey::source() slug.
	 * @return array{label:string,value:string,note:string,status:string,action:string}
	 */
	private static function api_key_row( string $source ): array {
		$label = self::key_source_label( $source );

		// An unreadable envelope is already its own message from
		// key_source_label() above; it is not cleanly "encrypted" (it is
		// sealed) or "not encrypted" (it once opened), so it is left alone
		// rather than forced into either reading.
		if ( 'option' !== $source || 'ok' !== ApiKey::stored_state() ) {
			return self::row( __( 'API key source', 'wynko-for-laposta' ), $label );
		}

		$encrypted = Crypto::is_envelope( trim( (string) Config::get( 'api_key' ) ) );

		return self::row(
			__( 'API key source', 'wynko-for-laposta' ),
			sprintf(
				/* translators: 1: where the key comes from, e.g. "Database"; 2: "Encrypted" or "Not encrypted". */
				__( '%1$s — %2$s', 'wynko-for-laposta' ),
				$label,
				$encrypted ? __( 'Encrypted', 'wynko-for-laposta' ) : __( 'Not encrypted', 'wynko-for-laposta' )
			),
			'',
			$encrypted ? self::PROTECTION_ENABLED : self::PROTECTION_DISABLED,
			$encrypted ? '' : self::ACTION_ENCRYPT_HELP
		);
	}

	/**
	 * Returns when campaigns were last fetched, in the site's timezone.
	 *
	 * @return string
	 */
	private static function last_sync_label(): string {
		$last = Cache::last_sync();
		if ( null === $last ) {
			return __( 'Never', 'wynko-for-laposta' );
		}

		$stamp = sprintf(
			/* translators: 1: relative time, e.g. "12 mins"; 2: absolute date and time. */
			__( '%1$s ago (%2$s)', 'wynko-for-laposta' ),
			human_time_diff( $last['at'], time() ),
			(string) wp_date( 'Y-m-d H:i', $last['at'] )
		);

		return $last['ok'] ? $stamp : sprintf(
			/* translators: %s: the time of the failed sync. */
			__( '%s — failed', 'wynko-for-laposta' ),
			$stamp
		);
	}

	/**
	 * Returns the connection verdict, worded as the API tab's Status row words
	 * it.
	 *
	 * @param string                                    $source  ApiKey::source() slug.
	 * @param array{ok:bool,message:string,code:string} $verdict Cached verdict.
	 * @return string
	 */
	private static function connection_label( string $source, array $verdict ): string {
		if ( 'unreadable' === ApiKey::stored_state() ) {
			return __( 'The stored API key could not be read', 'wynko-for-laposta' );
		}
		if ( 'none' === $source ) {
			return __( 'No API key configured', 'wynko-for-laposta' );
		}
		if ( $verdict['ok'] ) {
			return __( 'Connected', 'wynko-for-laposta' );
		}
		if ( '' === $verdict['message'] ) {
			return __( 'Not checked yet', 'wynko-for-laposta' );
		}
		return sprintf(
			/* translators: %s: reason the connection failed. */
			__( 'Not connected — %s', 'wynko-for-laposta' ),
			$verdict['message']
		);
	}

	/**
	 * Returns the verdict for the connection row.
	 *
	 * @param string                                    $source  ApiKey::source() slug.
	 * @param array{ok:bool,message:string,code:string} $verdict Cached verdict.
	 * @return string
	 */
	private static function connection_status( string $source, array $verdict ): string {
		if ( 'unreadable' === ApiKey::stored_state() ) {
			return Requirements::STATUS_BELOW_REQUIRED;
		}
		if ( 'none' === $source ) {
			return Requirements::STATUS_UNKNOWN;
		}
		if ( $verdict['ok'] ) {
			return Requirements::STATUS_OK;
		}
		return '' === $verdict['message'] ? Requirements::STATUS_UNKNOWN : Requirements::STATUS_BELOW_REQUIRED;
	}

	/**
	 * Returns whether Laposta answered, read from the code of the last failure
	 * rather than its message. No request is made here: a diagnostic screen
	 * that probes the network on every load is its own problem.
	 *
	 * @param array{ok:bool,message:string,code:string} $verdict Cached verdict.
	 * @param string                                    $source  ApiKey::source() slug.
	 * @return string One of self::REACHABLE_* .
	 */
	public static function reachability( array $verdict, string $source ): string {
		if ( ! empty( $verdict['ok'] ) ) {
			return self::REACHABLE_YES;
		}
		if ( 'none' === $source ) {
			return self::REACHABLE_UNKNOWN;
		}

		$code = (string) $verdict['code'];
		if ( 'wynko_http' === $code ) {
			return self::REACHABLE_NO;
		}
		if ( 'wynko_status' === $code || 'wynko_parse' === $code ) {
			return self::REACHABLE_YES;
		}
		return self::REACHABLE_UNKNOWN;
	}

	/**
	 * Words a reachability verdict as the connection row's note, answering the
	 * one question the verdict alone cannot: whether Laposta was reached at all.
	 * A connection that works, or was never tried, gets no note, because the
	 * value already says so.
	 *
	 * @param string $reachable One of self::REACHABLE_* .
	 * @param bool   $ok        Whether the last verdict was a success.
	 * @return string
	 */
	private static function reachable_note( string $reachable, bool $ok ): string {
		if ( $ok ) {
			return '';
		}
		if ( self::REACHABLE_NO === $reachable ) {
			return __( 'Laposta was not reached — the request did not complete', 'wynko-for-laposta' );
		}
		if ( self::REACHABLE_YES === $reachable ) {
			return __( 'Laposta answered and turned the request down', 'wynko-for-laposta' );
		}
		return '';
	}
}
