<?php
/**
 * The About tab.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Support\Sbom;
use Wynko\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static content: who this plugin is not affiliated with, what it was modelled
 * on, and what it depends on. The dependency list is read from the CycloneDX
 * documents in sbom/ rather than written out here — a prose copy would drift
 * from the inventory, and the inventory is the thing that ships.
 */
final class AboutTab {

	/**
	 * The CycloneDX documents that travel with the plugin.
	 *
	 * @return array<int,string> Absolute paths.
	 */
	private static function sbom_files(): array {
		$files = glob( WYNKO_PATH . 'sbom/*.cdx.json' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Every runtime component the shipped archive declares, across both
	 * documents, deduplicated by name and version.
	 *
	 * @return array<int,array{name:string,version:string,license:string}>
	 */
	public static function dependencies(): array {
		$seen = array();
		foreach ( self::sbom_files() as $file ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file the plugin ships, not a remote resource; WP_Filesystem is an admin-credentials abstraction for writes.
			if ( false === $contents ) {
				continue;
			}
			foreach ( Sbom::components( $contents ) as $component ) {
				$seen[ $component['name'] . '@' . $component['version'] ] = $component;
			}
		}

		ksort( $seen );
		return array_values( $seen );
	}

	/**
	 * Prints the About panel.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<h2>' . esc_html__( 'About Wynko', 'wynko-for-laposta' ) . '</h2>';

		echo '<h3>' . esc_html__( 'Independence', 'wynko-for-laposta' ) . '</h3>';
		echo '<p>' . esc_html__( 'Wynko is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Laposta B.V. "Laposta" is a trademark of its respective owner.', 'wynko-for-laposta' ) . '</p>';
		printf(
			'<p>%s</p>',
			wp_kses( self::laposta_support_note(), SettingsPage::allowed_link_html() )
		);

		echo '<h3>' . esc_html__( 'Inspiration', 'wynko-for-laposta' ) . '</h3>';
		printf(
			'<p>%s</p>',
			wp_kses( self::inspiration_note(), SettingsPage::allowed_link_html() )
		);

		self::render_dependencies();
		self::render_help();
		self::render_server_notes();

		SystemReport::render_ping_notice();
		SystemReport::render();
		SystemReport::render_actions();
	}

	/**
	 * Where a problem that is Laposta's rather than this plugin's goes. Being
	 * independent cuts both ways: it is also why an account, a list, or a field
	 * behaving oddly is not something this plugin can fix for you.
	 *
	 * @return string
	 */
	public static function laposta_support_note(): string {
		return sprintf(
			'%s %s',
			esc_html__( 'That independence also sets the limit of what this plugin can do for you: if something is wrong with your Laposta account, your lists, or your fields, it has to be solved on the Laposta side.', 'wynko-for-laposta' ),
			sprintf(
				/* translators: %s: link to Laposta's support page. */
				esc_html__( 'Contact %s.', 'wynko-for-laposta' ),
				self::link( 'laposta_support', esc_html__( 'Laposta support', 'wynko-for-laposta' ) )
			)
		);
	}

	/**
	 * What this plugin was modelled on, and where to go instead when the list
	 * is not in Laposta at all.
	 *
	 * @return string
	 */
	public static function inspiration_note(): string {
		return sprintf(
			/* translators: 1: the name "MC4WP — Mailchimp for WordPress", linked to the plugin; 2: the name "MC4WP", linked to the plugin. */
			esc_html__( 'Wynko was inspired by %1$s. If the list you want to connect lives in Mailchimp rather than Laposta, use %2$s instead.', 'wynko-for-laposta' ),
			self::link( 'mc4wp', esc_html__( 'MC4WP — Mailchimp for WordPress', 'wynko-for-laposta' ) ),
			self::link( 'mc4wp', esc_html__( 'MC4WP', 'wynko-for-laposta' ) )
		);
	}

	/**
	 * One anchor from the URL registry, with already-escaped link text. The
	 * URL never travels inside the translated string: a translation cannot
	 * then break the link, and only the words are translated.
	 *
	 * @param string $name Registered URL name.
	 * @param string $text Escaped link text.
	 * @return string
	 */
	private static function link( string $name, string $text ): string {
		return sprintf(
			'<a href="%s" target="%s" rel="%s">%s</a>',
			esc_url( Urls::url( $name ) ),
			esc_attr( Urls::target( $name ) ),
			esc_attr( Urls::rel( $name ) ),
			$text
		);
	}

	/**
	 * Prints where to take a problem. Each destination renders as a link when
	 * its URL is registered and as plain text when it is not — an empty href
	 * would reload the admin screen and read as broken.
	 *
	 * @return void
	 */
	private static function render_help(): void {
		echo '<h3>' . esc_html__( 'Getting help', 'wynko-for-laposta' ) . '</h3>';
		// list-style-type, for the same reason render_dependencies() carries it.
		echo '<ul style="list-style-type:disc;margin-left:1.5em;">';
		self::render_help_item(
			'support_forum',
			__( 'Support requests', 'wynko-for-laposta' ),
			__( 'the WordPress.org plugin support forum', 'wynko-for-laposta' )
		);
		self::render_help_item(
			'github_issues',
			__( 'Bugs and ideas', 'wynko-for-laposta' ),
			__( 'the GitHub issue tracker', 'wynko-for-laposta' )
		);
		echo '</ul>';
	}

	/**
	 * Prints one "Getting help" entry.
	 *
	 * @param string $name  Registered URL name.
	 * @param string $label What the destination is for.
	 * @param string $text  The destination, as prose.
	 * @return void
	 */
	private static function render_help_item( string $name, string $label, string $text ): void {
		$url = Urls::url( $name );

		if ( '' === $url ) {
			printf(
				'<li><strong>%s</strong> — %s <em>%s</em></li>',
				esc_html( $label ),
				esc_html( $text ),
				esc_html__( '(not available yet)', 'wynko-for-laposta' )
			);
			return;
		}

		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html( $label ),
			wp_kses(
				sprintf(
					'<a href="%s" target="%s" rel="%s">%s</a>',
					esc_url( $url ),
					esc_attr( Urls::target( $name ) ),
					esc_attr( Urls::rel( $name ) ),
					esc_html( $text )
				),
				SettingsPage::allowed_link_html()
			)
		);
	}

	/**
	 * Prints the host recommendations the report cannot check for itself.
	 *
	 * Prose rather than rows, because HTTP/2, the accepted TLS versions and the
	 * presence of an object cache are things PHP cannot see reliably from behind
	 * a proxy. Turning readings that unreliable into verdicts would raise alarms
	 * on correctly configured hosts.
	 *
	 * @return void
	 */
	private static function render_server_notes(): void {
		echo '<h3>' . esc_html__( 'Recommended server configuration', 'wynko-for-laposta' ) . '</h3>';
		echo '<p>' . esc_html__( 'None of these are checked by the report below — a plugin cannot see past a reverse proxy to tell how your server is configured. They are what a host should be doing anyway.', 'wynko-for-laposta' ) . '</p>';

		// list-style-type, for the same reason render_dependencies() carries it.
		echo '<ul style="list-style-type:disc;margin-left:1.5em;">';
		self::render_note(
			__( 'TLS 1.2 or 1.3, with TLS 1.0 and 1.1 disabled', 'wynko-for-laposta' ),
			__( 'the older versions are deprecated and refused outright by modern API endpoints, so a server still relying on them will see calls to Laposta fail at the handshake. They also leave your visitors negotiating cipher suites with known weaknesses — and this plugin\'s signup forms carry a name and an email address.', 'wynko-for-laposta' )
		);
		self::render_note(
			__( 'HTTP/2 or HTTP/3', 'wynko-for-laposta' ),
			__( 'these carry many requests over one connection instead of queueing them. It changes nothing about what this plugin does, but the settings screen and the block editor each pull a good number of small files, and on HTTP/1.1 those wait in line. General hygiene rather than a requirement.', 'wynko-for-laposta' )
		);
		self::render_note(
			__( 'A persistent object cache', 'wynko-for-laposta' ),
			__( 'this plugin leans on transients — for cached campaigns, for the connection verdict, for the signup rate limits. Without a persistent cache those are rows in the options table; with one they stay in memory.', 'wynko-for-laposta' )
		);
		self::render_note(
			__( 'Current versions of everything', 'wynko-for-laposta' ),
			__( 'the versions the report advises are the newest that have been out long enough to be widely deployed. Running behind them is not necessarily broken — it is untested, which is a different thing and the reason the plugin warns rather than refuses.', 'wynko-for-laposta' )
		);
		echo '</ul>';
	}

	/**
	 * Prints one recommendation.
	 *
	 * @param string $label What is recommended.
	 * @param string $why   Why it is worth doing.
	 * @return void
	 */
	private static function render_note( string $label, string $why ): void {
		printf( '<li><strong>%s</strong> — %s</li>', esc_html( $label ), esc_html( $why ) );
	}

	/**
	 * Prints the dependency section, or nothing at all when the inventory
	 * cannot be read — an unverifiable claim about dependencies is worse than
	 * no claim.
	 *
	 * @return void
	 */
	private static function render_dependencies(): void {
		if ( array() === self::sbom_files() ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Dependencies', 'wynko-for-laposta' ) . '</h3>';

		$dependencies = self::dependencies();
		if ( array() === $dependencies ) {
			echo '<p>' . esc_html__( 'This plugin bundles no third-party runtime dependencies. It requires only PHP and WordPress itself.', 'wynko-for-laposta' ) . '</p>';
			return;
		}

		// list-style-type, matching SettingsPage::plaintext_warning(), where the
		// shorthand would be stripped by wp_kses() — kept the same here so the
		// two lists don't disagree.
		echo '<ul style="list-style-type:disc;margin-left:1.5em;">';
		foreach ( $dependencies as $dependency ) {
			printf(
				'<li><code>%s</code> %s%s</li>',
				esc_html( $dependency['name'] ),
				esc_html( $dependency['version'] ),
				'' === $dependency['license'] ? '' : ' — ' . esc_html( $dependency['license'] )
			);
		}
		echo '</ul>';
	}
}
