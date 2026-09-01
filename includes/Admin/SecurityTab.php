<?php
/**
 * The settings screen's Security tab.
 *
 * @package Wynko
 */

namespace Wynko\Admin;

use Wynko\Admin\Forms\FormsListPage;
use Wynko\Admin\Forms\Screen;
use Wynko\Config;
use Wynko\Throttle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where an administrator meters the public signup endpoint.
 *
 * Its settings register into their own group rather than the API tab's, because
 * options.php writes every option in the submitted group — sharing one across
 * two tabs would make saving either reset the other's values.
 */
final class SecurityTab {

	const ACTION_RESET = 'wynko_reset_throttle';

	/**
	 * The settings this tab owns, in registration order, each with the
	 * sanitizer it registers with. Every setting keeps its own named callback
	 * because register_setting() hands the sanitizer nothing but the value —
	 * which option it belongs to is carried by the callback and nowhere else.
	 *
	 * @return array<string,string>
	 */
	public static function settings(): array {
		return array(
			'disable_form_throttle' => 'sanitize_disable_throttle',
			'throttle_window'       => 'sanitize_window',
			'throttle_ip_max'       => 'sanitize_ip_max',
			'throttle_form_max'     => 'sanitize_form_max',
			'disable_form_nonce'    => 'sanitize_disable_nonce',
		);
	}

	/**
	 * Prints the tab: the reset notice, then the caps.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_notice();

		echo '<form method="post" action="options.php">';
		settings_fields( SettingsPage::GROUP_SECURITY );
		do_settings_sections( SettingsPage::PAGE_SECURITY );
		echo '<div class="wynko-actions">';
		submit_button( __( 'Save changes', 'wynko-for-laposta' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form>';

		// A sibling of the form above, never nested inside it — the button
		// that submits this one lives inside the per-form counts table
		// (print_form_counts()), reaching it by its form="" attribute rather
		// than by DOM position.
		echo '<form id="wynko-reset-throttle" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_RESET ) );
		wp_nonce_field( self::ACTION_RESET );
		echo '</form>';
	}

	/**
	 * Prints the result of a reset, if this request carries one.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cosmetic flag set by handle_reset()'s own wp_safe_redirect; no state change on display.
		if ( ! isset( $_GET['wynko_throttle'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Signup rate limits cleared.', 'wynko-for-laposta' )
		);
	}

	/**
	 * Warns right beside the one field it is about, rather than in a single
	 * banner above the whole tab — a warning an administrator has to trace
	 * back to a checkbox somewhere below it is easy to misread as applying to
	 * everything on the screen instead of the one thing it actually costs.
	 *
	 * Reuses core's own notice styling (the same "notice notice-warning"
	 * classes a banner at the top of wp-admin carries) rather than a custom
	 * treatment, so it still reads as a warning at a glance; "inline" is what
	 * keeps that styling from assuming it is sitting at the top of the page.
	 *
	 * @param string $sentence Already-translated warning text.
	 * @return void
	 */
	private static function render_inline_warning( string $sentence ): void {
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html( $sentence )
		);
	}

	/**
	 * Clears every signup rate-limit counter on this site.
	 *
	 * The escape hatch for the case the limits exist to survive: a shared
	 * office address or a proxy that collapses a building onto one REMOTE_ADDR
	 * and locks real visitors out of a form.
	 *
	 * @return void
	 */
	public static function handle_reset(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wynko-for-laposta' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_RESET );

		Throttle::reset();

		wp_safe_redirect( self::reset_redirect_url() );
		exit;
	}

	/**
	 * Where a reset returns to. Extracted from handle_reset() so the target is
	 * testable without shimming wp_safe_redirect() and exit.
	 *
	 * @return string
	 */
	public static function reset_redirect_url(): string {
		return add_query_arg( 'wynko_throttle', 'reset', SettingsPage::tab_url( SettingsPage::TAB_SECURITY ) );
	}

	/**
	 * Explains what the three caps are for, since raising them is a security
	 * decision and lowering them can lock real visitors out.
	 *
	 * @return void
	 */
	public static function intro(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Signup forms are public, so they are metered. Every submission a form accepts is counted, whether or not Laposta went on to accept it, and the counts are kept over a rolling stretch of time called the window: a submission stops counting once it is older than the window. A visitor who exceeds either cap is asked to wait; nothing is sent to Laposta. Raise the per-visitor cap if an office or campus shares one address, and use "Reset signup limits" below to clear the counters now.', 'wynko-for-laposta' )
		);
	}

	/**
	 * Prints the rate-limiting on/off checkbox, and the caps it gates nested
	 * underneath it — the same pattern NotificationsTab::field_enabled() uses
	 * for the alert recipients: one settings row owns the switch and
	 * everything that switch controls, so they appear, disappear, and read as
	 * one unit rather than as a checkbox followed by unrelated-looking rows.
	 *
	 * On by default; every hosting setup is different, and an administrator
	 * who runs into trouble with a caching layer or proxy neither of us
	 * anticipated should be able to test with this off rather than being
	 * stuck. The caps below are hidden by form.js while this is unchecked —
	 * a value that no longer applies is not worth leaving on screen — and the
	 * warning for turning it off prints right underneath the checkbox, not in
	 * a banner somewhere else on the tab.
	 *
	 * @return void
	 */
	public static function field_throttle_enabled(): void {
		self::print_checkbox_field(
			'disable_form_throttle',
			'wynko-throttle-enabled',
			__( 'Enable rate limiting on signup submissions', 'wynko-for-laposta' ),
			__( 'On by default. Turn off only to test whether this is the cause of submissions failing.', 'wynko-for-laposta' ),
			! Config::form_throttle_disabled()
		);

		if ( Config::form_throttle_disabled() ) {
			self::render_inline_warning( __( 'Rate limiting is off: nothing caps how fast a script can flood your lists with junk signups.', 'wynko-for-laposta' ) );
		}

		self::field_throttle_fields();
	}

	/**
	 * Prints the three caps and the per-form counts table, indented under the
	 * checkbox that decides whether any of it means anything.
	 *
	 * @return void
	 */
	public static function field_throttle_fields(): void {
		printf(
			'<div class="wynko-throttle-fields wynko-nested-fields%s">',
			Config::form_throttle_disabled() ? ' wynko-hidden' : ''
		);

		printf( '<p><label for="wynko-throttle-window">%s</label></p>', esc_html__( 'Window (minutes)', 'wynko-for-laposta' ) );
		self::print_number_field(
			'throttle_window',
			'wynko-throttle-window',
			__( 'How long a submission keeps counting towards the caps below. Both caps are measured over this stretch of time.', 'wynko-for-laposta' )
		);

		printf( '<p><label for="wynko-throttle-ip-max">%s</label></p>', esc_html__( 'Per visitor', 'wynko-for-laposta' ) );
		self::print_number_field(
			'throttle_ip_max',
			'wynko-throttle-ip-max',
			__( 'How many signups one visitor may submit within the window, counted per address across all your forms.', 'wynko-for-laposta' )
		);

		printf( '<p><label for="wynko-throttle-form-max">%s</label></p>', esc_html__( 'Per form', 'wynko-for-laposta' ) );
		self::print_number_field(
			'throttle_form_max',
			'wynko-throttle-form-max',
			__( 'How many signups one form may take within the window, counting every visitor together rather than one at a time. Keep it well above real traffic: it is a backstop, and a form that reaches it accepts nobody until the window passes.', 'wynko-for-laposta' )
		);
		self::print_form_counts();

		echo '</div>';
	}

	/**
	 * Prints the nonce on/off checkbox, and the warning for its cost directly
	 * beneath it while it is off. Nothing is nested under this one — a bad
	 * nonce fails the whole submission outright, there is no dependent field
	 * to show or hide alongside it.
	 *
	 * On by default; every hosting setup is different, and an administrator
	 * who runs into trouble with a caching layer or proxy neither of us
	 * anticipated should be able to test with this off rather than being
	 * stuck.
	 *
	 * @return void
	 */
	public static function field_nonce_enabled(): void {
		self::print_checkbox_field(
			'disable_form_nonce',
			'wynko-nonce-enabled',
			__( 'Enable nonce verification on signup submissions', 'wynko-for-laposta' ),
			__( 'On by default. Turn off only to test whether a caching or proxy layer is the cause of submissions failing.', 'wynko-for-laposta' ),
			! Config::form_nonce_disabled()
		);

		if ( Config::form_nonce_disabled() ) {
			self::render_inline_warning( __( 'Nonce verification is off: any external site can silently submit signups — and trigger Laposta API calls — on a visitor\'s behalf, without their knowledge.', 'wynko-for-laposta' ) );
		}
	}

	/**
	 * Prints what each form has taken in the window that is open now, so the
	 * cap above can be judged against traffic rather than guessed at, and the
	 * reset button that clears those counts as the table's own footer row —
	 * the button belongs to this table, not to the screen at large.
	 *
	 * @return void
	 */
	private static function print_form_counts(): void {
		$forms = FormsListPage::forms();
		if ( array() === $forms ) {
			return;
		}

		echo '<table class="widefat striped wynko-form-counts"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Form', 'wynko-for-laposta' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Signups in this window', 'wynko-for-laposta' ) );
		echo '</tr></thead><tbody>';

		foreach ( $forms as $form ) {
			echo '<tr>';
			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url( Screen::edit_url( $form->id() ) ),
				esc_html( $form->display_name() )
			);
			printf( '<td>%s</td>', wp_kses( self::signup_count_cell( $form->id() ), self::allowed_count_html() ) );
			echo '</tr>';
		}

		echo '</tbody><tfoot><tr><td colspan="2">';
		printf(
			'<button type="submit" form="wynko-reset-throttle" class="button button-secondary" onclick="return confirm(%s);">%s</button>',
			esc_attr( (string) wp_json_encode( __( 'Clear the signup rate limits? Every form starts counting again from zero.', 'wynko-for-laposta' ) ) ),
			esc_html__( 'Reset signup limits', 'wynko-for-laposta' )
		);
		echo '</td></tr></tfoot></table>';
	}

	/**
	 * One form's signups in the open window, against the cap, marked when it is
	 * close to it. This screen's number: the forms list shows each form's
	 * lifetime total instead, which is why every cell here carries the period
	 * it counts.
	 *
	 * @param int $form_id Form post id.
	 * @return string
	 */
	public static function signup_count_cell( int $form_id ): string {
		$hits  = Throttle::form_hits( $form_id );
		$max   = Config::throttle_max( 'form' );
		$tight = $hits >= Config::throttle_pressure_threshold();

		return sprintf(
			'<span title="%s"%s>%s</span>',
			esc_attr( self::window_sentence() ),
			$tight ? ' class="wynko-count-tight"' : '',
			esc_html(
				sprintf(
					/* translators: 1: signups counted in the open window, 2: the per-form cap. */
					__( '%1$d of %2$d', 'wynko-for-laposta' ),
					$hits,
					$max
				)
			)
		);
	}

	/**
	 * What the count in that cell measures. A number with no period attached
	 * reads as a lifetime total, which this is not.
	 *
	 * @return string
	 */
	public static function window_sentence(): string {
		return sprintf(
			/* translators: %d: window length in minutes. */
			_n(
				'Signups counted over the last %d minute; older ones no longer count towards the per-form limit.',
				'Signups counted over the last %d minutes; older ones no longer count towards the per-form limit.',
				(int) round( Config::throttle_window() / MINUTE_IN_SECONDS ),
				'wynko-for-laposta'
			),
			(int) round( Config::throttle_window() / MINUTE_IN_SECONDS )
		);
	}

	/**
	 * The markup one count cell is allowed to carry.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_count_html(): array {
		return array(
			'span' => array(
				'title' => true,
				'class' => true,
			),
		);
	}

	/**
	 * Prints a warning on every admin screen while a form is close to its cap, so
	 * the operator who can raise it need not be on this tab to find out.
	 *
	 * It reads the names Throttle wrote rather than recounting, since this runs
	 * on every admin page. Not in network admin, where the per-site tab it points
	 * at does not exist.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( Menu::CAP ) || is_network_admin() ) {
			return;
		}

		$tight = Throttle::pressured();
		if ( array() === $tight ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			esc_html__( 'Wynko:', 'wynko-for-laposta' ),
			esc_html(
				sprintf(
					/* translators: %s: form names, comma-separated. */
					_n(
						'the signup form %s is close to the number of signups it allows per window.',
						'these signup forms are close to the number of signups they allow per window: %s.',
						count( $tight ),
						'wynko-for-laposta'
					),
					wp_sprintf_l( '%l', $tight )
				)
			),
			wp_kses(
				sprintf(
					/* translators: %s: URL of the Security tab. */
					__( 'Once the cap is reached the form turns every signup away until the window passes. Review the <a href="%s">signup rate limits</a>.', 'wynko-for-laposta' ),
					esc_url( SettingsPage::tab_url( SettingsPage::TAB_SECURITY ) )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	/**
	 * Clamps the submitted window to its configured bounds.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function sanitize_window( $value ): int {
		return SettingsPage::clamp_setting( 'throttle_window', $value );
	}

	/**
	 * Clamps the submitted per-visitor cap to its configured bounds.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function sanitize_ip_max( $value ): int {
		return SettingsPage::clamp_setting( 'throttle_ip_max', $value );
	}

	/**
	 * Clamps the submitted per-form cap to its configured bounds.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function sanitize_form_max( $value ): int {
		return SettingsPage::clamp_setting( 'throttle_form_max', $value );
	}

	/**
	 * Normalises the submitted nonce checkbox. Inverted relative to the
	 * option it writes, the same way sanitize_disable_throttle() is: the box
	 * reads "Enable nonce verification" and is checked by default, but the
	 * stored setting is disable_form_nonce — checked means false, and an
	 * absent (unchecked) box means true.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function sanitize_disable_nonce( $value ): bool {
		return '1' !== (string) $value;
	}

	/**
	 * Normalises the submitted rate-limiting checkbox. Inverted relative to
	 * the option it writes: the box reads "Enable rate limiting" and is
	 * checked by default, but the stored setting is disable_form_throttle —
	 * checked means false, and an unchecked box (absent from the submission
	 * entirely) means true.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function sanitize_disable_throttle( $value ): bool {
		return '1' !== (string) $value;
	}

	/**
	 * Prints one checkbox field with its description.
	 *
	 * @param string $name        Setting name.
	 * @param string $id          Element id, so form.js can find this box.
	 * @param string $label       Already-translated label beside the checkbox.
	 * @param string $description Already-translated help text.
	 * @param bool   $checked     Whether the box should render checked.
	 * @return void
	 */
	private static function print_checkbox_field( string $name, string $id, string $label, string $description, bool $checked ): void {
		if ( SettingsPage::render_override( $name ) ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
			return;
		}

		printf(
			'<label><input type="checkbox" id="%s" name="%s" value="1"%s /> %s</label> <p class="description">%s</p>',
			esc_attr( $id ),
			esc_attr( Config::option_key( $name ) ),
			checked( $checked, true, false ),
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * Prints one bounded integer input with its description.
	 *
	 * @param string $name        Setting name.
	 * @param string $id          Element id, matched by the label printed
	 *                            before it.
	 * @param string $description Already-translated help text.
	 * @return void
	 */
	private static function print_number_field( string $name, string $id, string $description ): void {
		if ( SettingsPage::render_override( $name ) ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
			return;
		}

		$bounds = Config::bounds( $name );
		printf(
			'<input type="number" id="%s" min="%d" max="%d" name="%s" value="%d" class="small-text" /> <p class="description">%s</p>',
			esc_attr( $id ),
			(int) $bounds['min'],
			(int) $bounds['max'],
			esc_attr( Config::option_key( $name ) ),
			(int) Config::get( $name ),
			esc_html( $description )
		);
	}
}
