<?php
/**
 * Privacy-policy disclosure.
 *
 * @package Wynko
 */

namespace Wynko;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggests a paragraph on Settings → Privacy disclosing that signup data is
 * sent to Laposta. WordPress's Privacy page pulls this from every active
 * plugin via wp_add_privacy_policy_content(); readme.txt's "Privacy and
 * external services" section carries the same disclosure for WordPress.org
 * review — keep the two in step if either changes.
 */
final class Privacy {

	/**
	 * Registers the suggested privacy-policy text.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'Wynko', self::content() );
	}

	/**
	 * The suggested text itself.
	 *
	 * @return string
	 */
	private static function content(): string {
		$privacy_link = sprintf(
			'<a href="%s" target="%s" rel="%s">%s</a>',
			esc_url( Urls::url( 'laposta_privacy' ) ),
			esc_attr( Urls::target( 'laposta_privacy' ) ),
			esc_attr( Urls::rel( 'laposta_privacy' ) ),
			esc_html__( "Laposta's privacy policy", 'wynko-for-laposta' )
		);

		return sprintf(
			'<p class="wp-policy-help">%s</p><p>%s</p><p>%s</p>',
			esc_html__( 'This suggested text is based on how Wynko is configured for your site — it applies whenever a visitor submits a signup form.', 'wynko-for-laposta' ),
			esc_html__( 'Wynko connects this site to Laposta. When a visitor submits a signup form, whatever they typed in — including their email address — is sent to Laposta to add them to the list you configured. Wynko itself does not store signups.', 'wynko-for-laposta' ),
			sprintf(
				/* translators: %s: linked text "Laposta's privacy policy". */
				esc_html__( 'For more detail, see %s.', 'wynko-for-laposta' ),
				$privacy_link
			)
		);
	}
}
