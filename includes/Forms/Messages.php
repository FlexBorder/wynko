<?php
/**
 * Built-in wording for form messages.
 *
 * @package Wynko
 */

namespace Wynko\Forms;

use Wynko\Support\LapostaErrors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The prose behind each message slug. Support\LapostaErrors decides which slug
 * applies; this class is where that slug becomes a sentence, which keeps the
 * translation calls out of the WordPress-free layer.
 */
final class Messages {

	/**
	 * The built-in wording, one entry per slug. The success wording promises
	 * nothing about email, because whether Laposta sends a confirmation depends
	 * on the list's double opt-in setting and the account's plan.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			LapostaErrors::SLUG_SUCCESS       => __( 'Thanks for signing up.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_GENERIC       => __( 'Sorry, something went wrong. Please try again later.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_DUPLICATE     => __( 'This email address is already subscribed.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_INVALID_EMAIL => __( 'Please enter a valid email address.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_REQUIRED      => __( 'Please fill in this field.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_INVALID_VALUE => __( 'Please check this value.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_PATTERN       => __( 'Please match the format this field asks for.', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_TERMS         => __( 'Please agree to the terms before subscribing.', 'wynko-for-laposta' ),
		);
	}

	/**
	 * The slugs that only ever render as the notice above the form, where
	 * there is room for a sentence with a link in it.
	 *
	 * Every other slug can be attached to a single field by FormValidator and
	 * renders beside it, so it stays plain text — markup in the space next to
	 * an input is not a formatting choice, it is a broken layout.
	 *
	 * @return array<int,string>
	 */
	public static function notice_slugs(): array {
		return array(
			LapostaErrors::SLUG_SUCCESS,
			LapostaErrors::SLUG_GENERIC,
			LapostaErrors::SLUG_DUPLICATE,
		);
	}

	/**
	 * Whether one slug's wording may carry markup.
	 *
	 * @param string $slug One of LapostaErrors::SLUG_* .
	 * @return bool
	 */
	public static function allows_html( string $slug ): bool {
		return in_array( $slug, self::notice_slugs(), true );
	}

	/**
	 * The markup a message may carry: a link, the two emphases, and a break.
	 * `target` is not on the list, because Urls derives it from what a link
	 * points at and a typed one would arrive without its matching `rel`.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'a'      => array(
				'href'  => true,
				'title' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	/**
	 * The admin-facing name of one slug, for the Messages tab. '' for a slug
	 * that is not ours.
	 *
	 * @param string $slug One of LapostaErrors::SLUG_* .
	 * @return string
	 */
	public static function label( string $slug ): string {
		$labels = array(
			LapostaErrors::SLUG_SUCCESS       => __( 'Success', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_GENERIC       => __( 'Something went wrong', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_DUPLICATE     => __( 'Already subscribed', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_INVALID_EMAIL => __( 'Invalid email address', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_REQUIRED      => __( 'Required field left empty', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_INVALID_VALUE => __( 'Invalid value', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_PATTERN       => __( 'Pattern not matched', 'wynko-for-laposta' ),
			LapostaErrors::SLUG_TERMS         => __( 'Terms not agreed', 'wynko-for-laposta' ),
		);
		return $labels[ $slug ] ?? '';
	}

	/**
	 * The wording a form shows for one slug.
	 *
	 * @param FormData $form One form.
	 * @param string   $slug One of LapostaErrors::SLUG_* .
	 * @return string
	 */
	public static function resolve( FormData $form, string $slug ): string {
		$defaults = self::defaults();
		if ( ! isset( $defaults[ $slug ] ) ) {
			return $defaults[ LapostaErrors::SLUG_GENERIC ];
		}

		$custom = $form->message( $slug );
		return '' !== $custom ? $custom : $defaults[ $slug ];
	}
}
