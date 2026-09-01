<?php
/**
 * Public signup submission handler.
 *
 * @package Wynko
 */

namespace Wynko\Frontend;

use Wynko\Api\Fields;
use Wynko\Api\Subscribers;
use Wynko\Cache;
use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Log;
use Wynko\Support\Fields as FieldData;
use Wynko\Support\FieldFingerprint;
use Wynko\Support\FormValidator;
use Wynko\Support\LapostaErrors;
use Wynko\Throttle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wynko_submit_form admin-post action, the plugin's only unauthenticated
 * state-changing endpoint. Every request is nonce-verified against the specific
 * form it claims to submit, validated server-side against the live field
 * definitions, and answered with a redirect so a refresh cannot re-subscribe.
 *
 * Every request is also metered by Wynko\Throttle before the form is loaded,
 * per IP and per form. Outcomes reach the activity log, except for the three
 * anyone can provoke — a bad nonce, an unknown form, a throttled request — and
 * no entry ever carries the submitted address or field values.
 */
final class FormSubmitHandler {

	const ACTION     = 'wynko_submit_form';
	const RESULT_ARG = 'wynko_result';

	/**
	 * The nonce field's name, deliberately not `_wpnonce`: the REST layer checks
	 * any request carrying that field against the `wp_rest` action before
	 * routing, and would reject a form-scoped nonce 403.
	 */
	const NONCE_FIELD = 'wynko_nonce';

	/**
	 * The bot trap. Named like something a form scraper wants to fill and hidden
	 * in CSS rather than with type="hidden" or display:none, both of which the
	 * better bots skip.
	 */
	const HONEYPOT_FIELD = 'wynko_website';

	/**
	 * The field-set fingerprint's name. FormRenderer stamps it into the
	 * markup at render time; process() compares it against Wynko's current
	 * view of the same list's fields to notice a rendered page a cache is
	 * still serving after the field set it was built from has moved on.
	 * See maybe_log_stale_render().
	 */
	const FIELD_FINGERPRINT_FIELD = 'wynko_ffp';

	const STATUS_SUCCESS   = 'success';
	const STATUS_INVALID   = 'invalid';
	const STATUS_FAILED    = 'failed';
	const STATUS_BAD_NONCE = 'bad_nonce';
	const STATUS_NOT_FOUND = 'not_found';
	const STATUS_THROTTLED = 'throttled';

	/**
	 * How long a submit nonce stays valid, in place of core's default (one day,
	 * across a rolling two-tick window). A page-caching plugin routinely keeps
	 * a page around longer than that, and this endpoint is already gated by
	 * Throttle too, so trading a few extra days of a reusable-but-scoped token
	 * for fewer cache-staleness failures is a reasonable trade. See nonce_life().
	 */
	const NONCE_LIFE = 3 * DAY_IN_SECONDS;

	/**
	 * How long maybe_log_stale_render() withholds a repeat log entry for the
	 * same form, once it has logged one. A dedicated constant rather than
	 * Cache::negative_ttl(): that one bounds a different thing (a forced
	 * field refetch's own retry window), and tying this to it would make a
	 * future change to one silently change the other. This is a diagnostic
	 * signal for an administrator to notice once, not an amplification
	 * control, so an hour is generous rather than tight.
	 */
	const STALE_RENDER_LOG_COOLDOWN = HOUR_IN_SECONDS;

	/**
	 * The nonce action for one form. Scoped to the id so one form's token
	 * cannot submit another's.
	 *
	 * @param int $form_id Form post id.
	 * @return string
	 */
	public static function nonce_action( int $form_id ): string {
		return self::ACTION . '_' . $form_id;
	}

	/**
	 * Filters `nonce_life` for this plugin's own submit actions only, leaving
	 * core's and every other plugin's nonces untouched. Hooked in Plugin::boot().
	 *
	 * @param int    $life   The life core would otherwise use.
	 * @param string $action The nonce action being ticked, '' when unscoped.
	 * @return int
	 */
	public static function nonce_life( int $life, string $action = '' ): int {
		return 0 === strpos( $action, self::ACTION . '_' ) ? self::NONCE_LIFE : $life;
	}

	/**
	 * The hooked callback: process, stash the outcome, redirect.
	 *
	 * @return void
	 */
	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() verifies the form-scoped nonce before reading anything else; every value it reads is sanitized there.
		$raw = wp_unslash( $_POST );

		$result = self::process( $raw );

		if ( self::STATUS_NOT_FOUND === $result['status'] || self::STATUS_BAD_NONCE === $result['status'] ) {
			// Deliberately the same answer for both: a probe learns nothing
			// about which check it failed.
			wp_die( esc_html__( 'That form does not exist.', 'wynko-for-laposta' ), '', array( 'response' => 404 ) );
		}

		// Also short of the redirect, which would write a one-shot result
		// transient per attempt — an options row on the very path being metered.
		if ( self::STATUS_THROTTLED === $result['status'] ) {
			wp_die( esc_html__( 'Too many submissions. Please wait a few minutes and try again.', 'wynko-for-laposta' ), '', array( 'response' => 429 ) );
		}

		wp_safe_redirect( self::redirect_url( $result, isset( $raw['_wp_http_referer'] ) ? esc_url_raw( (string) $raw['_wp_http_referer'] ) : '' ) );
		exit;
	}

	/**
	 * Stops a page carrying a one-shot result token from being cached, so a
	 * later visitor to the exact same URL never gets served someone else's
	 * redisplayed field values or outcome message.
	 *
	 * Hooked on template_redirect rather than called from FormRenderer, which
	 * renders far too late — after wp_head(), with headers already sent, where
	 * nocache_headers() silently no-ops. DONOTCACHEPAGE is set alongside it
	 * because most page-cache plugins (WP Super Cache, W3 Total Cache, WP
	 * Rocket, LiteSpeed) decide inside their own advanced-cache.php and act on
	 * that constant rather than on response headers.
	 *
	 * @return void
	 */
	public static function maybe_nocache_result_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: only decides whether this response may be cached, changes nothing.
		if ( ! isset( $_GET[ self::RESULT_ARG ] ) || '' === $_GET[ self::RESULT_ARG ] ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Not this plugin's own global: a de facto standard constant that page-caching plugins (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed) check for by this exact name; prefixing it would make it invisible to every one of them.
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/**
	 * Where the visitor lands afterwards: the form's configured redirect on
	 * success, otherwise back where they came from carrying a one-shot token.
	 *
	 * @param array<string,mixed> $result    process() output.
	 * @param string              $return_to Submitted referer.
	 * @return string
	 */
	public static function redirect_url( array $result, string $return_to ): string {
		$form = FormData::load( (int) $result['form_id'] );
		$url  = null;

		if ( null !== $form && self::STATUS_SUCCESS === $result['status'] ) {
			$redirect = $form->redirect_url();
			if ( '' !== $redirect ) {
				$url = $redirect;
			}
		}

		if ( null === $url ) {
			$base = '' !== $return_to ? $return_to : home_url( '/' );
			$url  = add_query_arg( self::RESULT_ARG, self::store_result( $result ), $base );
		}

		/**
		 * Filters the URL a visitor is redirected to after submitting a form.
		 *
		 * @since 1.1.0
		 * @param string               $url    The computed redirect URL.
		 * @param array<string,mixed>  $result process() output.
		 */
		return (string) apply_filters( 'wynko_form_redirect_url', $url, $result );
	}

	/**
	 * Validates and submits one form post.
	 *
	 * @param array<string,mixed> $raw Unslashed request data.
	 * @return array{status:string,form_id:int,errors:array<string,string>,values:array<string,mixed>,slug:string}
	 */
	public static function process( array $raw ): array {
		$form_id = isset( $raw['wynko_form_id'] ) ? absint( $raw['wynko_form_id'] ) : 0;
		$nonce   = isset( $raw[ self::NONCE_FIELD ] ) ? sanitize_text_field( (string) $raw[ self::NONCE_FIELD ] ) : '';

		// Off by default; see Config::form_nonce_disabled()'s docblock and
		// SecurityTab, which shows a standing warning while this is on.
		if ( ! Config::form_nonce_disabled() && ! wp_verify_nonce( $nonce, self::nonce_action( $form_id ) ) ) {
			return self::result( self::STATUS_BAD_NONCE, $form_id, array(), array(), LapostaErrors::SLUG_GENERIC );
		}

		// Above FormData::load() on purpose, so a refused submission costs a
		// counter read and nothing else. A nonce is no throttle: it is reusable,
		// and every anonymous visitor holds the same one.
		if ( ! Config::form_throttle_disabled() && ! Throttle::allows( $form_id, self::client_ip() ) ) {
			// The name lookup sits inside the branch rather than before the
			// check: loading a post for every hostile request is exactly the
			// cost the throttle exists to avoid, and should_log() holds this to
			// once per form per window.
			if ( Throttle::should_log( $form_id ) ) {
				$throttled = FormData::load( $form_id );
				Log::warning(
					sprintf(
						/* translators: 1: form name, 2: submissions allowed, 3: window length in minutes. */
						__( 'Signups on "%1$s" are being rate limited: more than %2$d in %3$d minutes.', 'wynko-for-laposta' ),
						null !== $throttled ? $throttled->name() : (string) $form_id,
						Config::throttle_max( 'ip' ),
						(int) ( Config::throttle_window() / MINUTE_IN_SECONDS )
					)
				);
			}
			return self::result( self::STATUS_THROTTLED, $form_id, array(), array(), LapostaErrors::SLUG_GENERIC );
		}

		// A bot must be told it succeeded, or it simply tries again.
		if ( '' !== trim( isset( $raw[ self::HONEYPOT_FIELD ] ) ? (string) $raw[ self::HONEYPOT_FIELD ] : '' ) ) {
			return self::result( self::STATUS_SUCCESS, $form_id, array(), array(), LapostaErrors::SLUG_SUCCESS );
		}

		$form = FormData::load( $form_id );
		if ( null === $form || ! $form->is_published() ) {
			// A form that exists but is not published is someone's page still
			// embedding it — a real misconfiguration with a real remedy. An id
			// matching no post at all is a scanner trying ids, and logging that
			// would let anyone fill the log from outside.
			if ( null !== $form ) {
				Log::warning(
					sprintf(
						/* translators: %s: form name. */
						__( 'A signup was submitted to "%s", which is no longer published.', 'wynko-for-laposta' ),
						$form->name()
					)
				);
			}
			return self::result( self::STATUS_NOT_FOUND, $form_id, array(), array(), LapostaErrors::SLUG_GENERIC );
		}

		$email    = isset( $raw['wynko_email'] ) ? sanitize_email( (string) $raw['wynko_email'] ) : '';
		$posted   = isset( $raw['wynko_field'] ) && is_array( $raw['wynko_field'] ) ? $raw['wynko_field'] : array();
		$settings = $form->settings();
		$values   = array(
			'email'  => $email,
			'fields' => self::sanitize_values( $posted ),
		);

		/**
		 * Filters a submission's sanitized values before validation.
		 *
		 * @since 1.1.0
		 * @param array{email:string,fields:array<string,mixed>} $values  Sanitized email and field values.
		 * @param int                                            $form_id Form post id.
		 */
		/**
		 * Typed to match the array shape built above; a filter may return anything.
		 *
		 * @var array{email:string,fields:array<string,mixed>} $values
		 */
		$values = (array) apply_filters( 'wynko_form_submitted_values', $values, $form_id );
		$email  = (string) $values['email'];

		$list_id = $form->list_id();
		if ( '' === $list_id ) {
			/* translators: %s: form name. */
			Log::error( sprintf( __( 'Signup failed on "%s": the form has no list selected.', 'wynko-for-laposta' ), $form->name() ) );
			return self::result( self::STATUS_FAILED, $form_id, array(), $values, LapostaErrors::SLUG_GENERIC );
		}

		$definitions = Fields::for_list( $list_id );
		if ( $definitions['error'] ) {
			Log::error( sprintf( self::fetch_failure_message( (string) $definitions['reason'] ), $form->name() ) );
			return self::result( self::STATUS_FAILED, $form_id, array(), $values, LapostaErrors::SLUG_GENERIC );
		}

		// Independent of whatever this submission's own outcome turns out to
		// be: a stale rendering is a fact about the page, not about whether
		// the visitor happened to fill it in correctly.
		self::maybe_log_stale_render( $form, $raw, $definitions['fields'] );

		// Without the synthetic email row: FormValidator checks the address via
		// KEY_EMAIL and Laposta takes it as a top-level parameter, so leaving it
		// in would report one bad address twice and send a bogus custom field.
		$visible = $form->visible_custom_fields( $definitions['fields'] );

		$submitted                             = $values['fields'];
		$submitted[ FormValidator::KEY_EMAIL ] = $email;
		$submitted[ FormValidator::KEY_TERMS ] = isset( $raw['wynko_terms'] ) ? sanitize_text_field( (string) $raw['wynko_terms'] ) : '';

		$errors = FormValidator::validate( $visible, $submitted, (bool) $settings['terms_required'] );
		if ( array() !== $errors ) {
			Log::warning(
				sprintf(
					/* translators: 1: form name, 2: number of fields that failed validation. */
					__( 'Signup on "%1$s" rejected: %2$d field(s) failed validation.', 'wynko-for-laposta' ),
					$form->name(),
					count( $errors )
				)
			);
			return self::result(
				self::STATUS_INVALID,
				$form_id,
				array_map( array( FormValidator::class, 'slug_for' ), $errors ),
				$values,
				LapostaErrors::SLUG_GENERIC
			);
		}

		$custom_fields = self::custom_fields( $visible, $values['fields'] );

		/**
		 * Filters the custom-field payload sent to Laposta for one submission.
		 *
		 * This is the hook a bridge from another plugin's own form (e.g. a
		 * Contact Form 7 "Sign up for Newsletter" checkbox) uses to hand its own
		 * field data into Wynko's existing submit pipeline.
		 *
		 * @since 1.1.0
		 * @param array<string,mixed> $custom_fields Values keyed by custom_name.
		 * @param int                 $form_id       Form post id.
		 * @param string              $email         Submitted address.
		 * @param string              $list_id       Laposta list id.
		 */
		$custom_fields = (array) apply_filters( 'wynko_form_subscriber_data', $custom_fields, $form_id, $email, $list_id );

		// What the form actually put in front of the visitor. A Laposta complaint
		// about anything outside this set is about a field they never saw.
		$shown = array_merge(
			array( FormValidator::KEY_EMAIL ),
			array_map( 'strval', array_column( $visible, 'custom_name' ) )
		);

		$response = self::create( $list_id, $email, $custom_fields, $raw, (bool) $settings['skip_doi'] );

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			$data = is_array( $data ) ? $data : array();

			// A field changed in Laposta and the cached definitions have not
			// caught up. Refetch them and decide what the drift actually means
			// for this submission.
			$fresh = LapostaErrors::is_field_drift( $data, $shown ) && self::may_resync( $list_id )
				? Fields::for_list( $list_id, true )
				: null;

			if ( null !== $fresh && ! $fresh['error'] ) {
				$visible = $form->visible_custom_fields( $fresh['fields'] );

				// Re-validating against the definitions we just fetched answers
				// the only question that matters: has the form grown a field the
				// visitor was never asked for?
				$missing = FormValidator::validate( $visible, $submitted, (bool) $settings['terms_required'] );
				if ( array() !== $missing ) {
					// It has, and there is nothing to retry — the value was never
					// collected. The form now renders that field, so asking for it
					// is something the visitor can act on; a generic failure is not.
					Log::warning(
						sprintf(
							/* translators: 1: form name, 2: number of fields. */
							_n(
								'Fields changed in Laposta for "%1$s"; the form now asks for %2$d field the visitor had not been shown.',
								'Fields changed in Laposta for "%1$s"; the form now asks for %2$d fields the visitor had not been shown.',
								count( $missing ),
								'wynko-for-laposta'
							),
							$form->name(),
							count( $missing )
						)
					);

					return self::result(
						self::STATUS_INVALID,
						$form_id,
						array_map( array( FormValidator::class, 'slug_for' ), $missing ),
						$values,
						LapostaErrors::SLUG_GENERIC
					);
				}

				// Nothing new to ask for, so the drift was a field removed or
				// renamed. The payload is rebuilt from the fresh definitions —
				// retrying the old one would resend exactly what was rejected.
				$response = self::create( $list_id, $email, self::custom_fields( $visible, $values['fields'] ), $raw, (bool) $settings['skip_doi'] );
				if ( ! is_wp_error( $response ) ) {
					Log::warning(
						sprintf(
							/* translators: %s: form name. */
							__( 'Fields changed in Laposta for "%s"; the form was resynced and the signup went through.', 'wynko-for-laposta' ),
							$form->name()
						)
					);
					self::count_signup( $form );
					return self::result( self::STATUS_SUCCESS, $form_id, array(), array(), LapostaErrors::SLUG_SUCCESS );
				}

				Log::warning(
					sprintf(
						/* translators: %s: form name. */
						__( 'Fields changed in Laposta for "%s"; resyncing did not fix the signup.', 'wynko-for-laposta' ),
						$form->name()
					)
				);
				$data = is_array( $response->get_error_data() ) ? $response->get_error_data() : array();
			}

			// Drift is never something the visitor can act on, so it must not
			// borrow their wording — a 201 for a field the form never rendered
			// would otherwise tell them to fill in a box that is not there. This
			// covers the cooldown-blocked case too, where no resync ran.
			$slug = LapostaErrors::is_field_drift( $data, $shown )
				? LapostaErrors::SLUG_GENERIC
				: LapostaErrors::slug_for_error( $data );

			// An address already on the list is answered like a new one unless
			// this form says otherwise, because saying so lets anyone test
			// whether an address is subscribed.
			//
			// The log entry is written either way, as a warning rather than an
			// error: Notifier mails every error onward, and the visitor was told
			// this succeeded.
			if ( LapostaErrors::SLUG_DUPLICATE === $slug ) {
				/* translators: %s: form name. */
				Log::warning( sprintf( __( 'Signup through "%s": the address was already subscribed.', 'wynko-for-laposta' ), $form->name() ) );

				if ( empty( $settings['reveal_duplicate'] ) ) {
					return self::result( self::STATUS_SUCCESS, $form_id, array(), array(), LapostaErrors::SLUG_SUCCESS );
				}

				return self::result( self::STATUS_FAILED, $form_id, array(), $values, $slug );
			}

			Log::error(
				sprintf(
					/* translators: 1: form name, 2: error message. */
					__( 'Signup on "%1$s" failed: %2$s', 'wynko-for-laposta' ),
					$form->name(),
					$response->get_error_message()
				)
			);

			/**
			 * Fires when a signup submission fails to reach Laposta.
			 *
			 * @since 1.1.0
			 * @param int       $form_id  Form post id.
			 * @param \WP_Error $response The failed Laposta response.
			 */
			do_action( 'wynko_form_submit_failed', $form_id, $response );

			return self::result( self::STATUS_FAILED, $form_id, array(), $values, $slug );
		}

		self::count_signup( $form );
		return self::result( self::STATUS_SUCCESS, $form_id, array(), array(), LapostaErrors::SLUG_SUCCESS );
	}

	/**
	 * Records a signup Laposta accepted, in the log and in the form's total.
	 *
	 * The two travel together so that no later success path can pick up one
	 * without the other. The paths that answer success without Laposta creating
	 * anything — the honeypot, and an address already subscribed — reach
	 * neither.
	 *
	 * @param FormData $form The form submitted to.
	 * @return void
	 */
	private static function count_signup( FormData $form ): void {
		/* translators: %s: form name. */
		Log::info( sprintf( __( 'New signup through "%s".', 'wynko-for-laposta' ), $form->name() ) );
		$form->record_signup();

		/**
		 * Fires after a signup is confirmed successful.
		 *
		 * @since 1.1.0
		 * @param FormData $form The form submitted to.
		 */
		do_action( 'wynko_form_submitted', $form );
	}

	/**
	 * The custom-field payload for one submission: every shown field the visitor
	 * actually answered. Rebuilt rather than reused after a resync, so a field
	 * Laposta has dropped stops being sent.
	 *
	 * @param array<int,array<string,mixed>> $visible The fields the form renders.
	 * @param array<string,mixed>            $values  Sanitized submitted values.
	 * @return array<string,mixed>
	 */
	private static function custom_fields( array $visible, array $values ): array {
		$payload = array();
		foreach ( $visible as $field ) {
			$key = (string) $field['custom_name'];
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] && array() !== $values[ $key ] ) {
				$payload[ $key ] = $values[ $key ];
			}
		}
		return $payload;
	}

	/**
	 * One call to Laposta's subscriber endpoint. Extracted so the drift retry
	 * repeats the call rather than a copy of it.
	 *
	 * @param string              $list_id       Laposta list id.
	 * @param string              $email         Submitted address.
	 * @param array<string,mixed> $custom_fields Submitted custom values.
	 * @param array<string,mixed> $raw           The raw submission, for the referer.
	 * @param bool                $skip_doi      Whether to skip double opt-in.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function create( string $list_id, string $email, array $custom_fields, array $raw, bool $skip_doi ) {
		return Subscribers::create(
			$list_id,
			$email,
			self::client_ip(),
			isset( $raw['_wp_http_referer'] ) ? esc_url_raw( (string) $raw['_wp_http_referer'] ) : '',
			$custom_fields,
			$skip_doi
		);
	}

	/**
	 * Whether this list's forced refetch is off cooldown, claiming it when it
	 * is. The claim is written before the fetch, so a burst cannot pass twice.
	 *
	 * This is the control on an amplification surface: an anonymous failing
	 * submission is what triggers the refetch, so the ceiling has to hold
	 * whatever volume arrives. It allows one extra outbound call per list per
	 * window, to the same fixed host every other call goes to.
	 *
	 * @param string $list_id Laposta list id.
	 * @return bool
	 */
	private static function may_resync( string $list_id ): bool {
		$key = Config::resync_transient_key( $list_id );
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), Cache::negative_ttl() );
		return true;
	}

	/**
	 * Notices a visitor's rendered page carrying an outdated field-set
	 * fingerprint — the tell that a page cache or CDN served a copy of the
	 * form from before Wynko's own view of the list's fields last changed.
	 * Detection only: it changes nothing about the submission and makes no
	 * extra remote call (it reuses $fields, already fetched for validation),
	 * it only tells an administrator their cache may be worth purging.
	 *
	 * @param FormData                       $form   The form submitted to.
	 * @param array<string,mixed>            $raw    Unslashed request data.
	 * @param array<int,array<string,mixed>> $fields Current cached field definitions.
	 * @return void
	 */
	private static function maybe_log_stale_render( FormData $form, array $raw, array $fields ): void {
		$carried = isset( $raw[ self::FIELD_FINGERPRINT_FIELD ] )
			? sanitize_text_field( (string) $raw[ self::FIELD_FINGERPRINT_FIELD ] )
			: '';

		// '' covers a page cached before this feature shipped, which carries
		// no fingerprint field at all — not evidence of drift, just silence.
		if ( '' === $carried || FieldFingerprint::of( $fields ) === $carried ) {
			return;
		}

		if ( ! self::may_log_stale_render( $form->id() ) ) {
			return;
		}

		// info, not warning: the field-drift log lines above are warnings
		// because Laposta rejected a submission over them — a real,
		// visitor-facing hiccup. This one is purely diagnostic: nothing
		// failed, nobody was asked to do anything twice.
		Log::info(
			sprintf(
				/* translators: %s: form name. */
				__( 'A signup on "%s" carried an outdated field fingerprint; its rendered page appears to be a stale cached copy.', 'wynko-for-laposta' ),
				$form->name()
			)
		);
	}

	/**
	 * Whether maybe_log_stale_render() may write another entry for this
	 * form, claiming it when it does. One entry is the point — an
	 * administrator needs to see it once, not once per visitor on a busy
	 * stale page.
	 *
	 * @param int $form_id Form post id.
	 * @return bool
	 */
	private static function may_log_stale_render( int $form_id ): bool {
		$key = Config::stale_render_transient_key( $form_id );
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), self::STALE_RENDER_LOG_COOLDOWN );
		return true;
	}

	/**
	 * The log wording for a field fetch that produced nothing. The visitor sees
	 * the form's generic error in every case — which of these it was is the
	 * administrator's business, and each one has a different remedy.
	 *
	 * @param string $reason One of Support\Fields::FETCH_* .
	 * @return string A format string taking the form name.
	 */
	private static function fetch_failure_message( string $reason ): string {
		if ( FieldData::FETCH_GONE === $reason ) {
			/* translators: %s: form name. */
			return __( 'Signup failed on "%s": its list no longer exists in Laposta.', 'wynko-for-laposta' );
		}
		if ( FieldData::FETCH_NO_KEY === $reason ) {
			/* translators: %s: form name. */
			return __( 'Signup failed on "%s": no Laposta API key is configured.', 'wynko-for-laposta' );
		}
		/* translators: %s: form name. */
		return __( 'Signup failed on "%s": Laposta could not be reached.', 'wynko-for-laposta' );
	}

	/**
	 * Stashes an outcome for the redirect to pick up.
	 *
	 * @param array<string,mixed> $result process() output.
	 * @return string One-shot token.
	 */
	public static function store_result( array $result ): string {
		$token = wp_generate_password( 24, false );
		set_transient( Config::form_result_transient_key( $token ), $result, Config::form_result_ttl() );
		return $token;
	}

	/**
	 * Reads an outcome and destroys it, so a shared or bookmarked URL cannot
	 * replay someone else's submission back at them.
	 *
	 * The ownership check comes before the delete on purpose: a page can hold
	 * two forms, and whichever rendered first would otherwise consume the
	 * other's outcome and leave the submitted form silent.
	 *
	 * @param string $token   One-shot token.
	 * @param int    $form_id The form asking.
	 * @return array<string,mixed>|null
	 */
	public static function take_result( string $token, int $form_id ): ?array {
		if ( '' === $token ) {
			return null;
		}
		$key    = Config::form_result_transient_key( $token );
		$result = get_transient( $key );

		if ( ! is_array( $result ) || ! isset( $result['status'], $result['form_id'] ) || (int) $result['form_id'] !== $form_id ) {
			return null;
		}

		delete_transient( $key );
		return $result;
	}

	/**
	 * Builds a result array.
	 *
	 * @param string               $status  One of self::STATUS_* .
	 * @param int                  $form_id Form post id.
	 * @param array<string,string> $errors  Field key => message slug.
	 * @param array<string,mixed>  $values  Values to redisplay.
	 * @param string               $slug    Form-level message slug.
	 * @return array{status:string,form_id:int,errors:array<string,string>,values:array<string,mixed>,slug:string}
	 */
	private static function result( string $status, int $form_id, array $errors, array $values, string $slug ): array {
		return array(
			'status'  => $status,
			'form_id' => $form_id,
			'errors'  => $errors,
			'values'  => $values,
			'slug'    => $slug,
		);
	}

	/**
	 * Sanitizes the submitted custom-field values, one level of nesting deep —
	 * a multi-select posts an array, nothing posts an array of arrays.
	 *
	 * @param array<string,mixed> $posted Raw wynko_field values.
	 * @return array<string,mixed>
	 */
	private static function sanitize_values( array $posted ): array {
		$clean = array();
		foreach ( $posted as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = array_values(
					array_map(
						static function ( $one ) {
							return is_scalar( $one ) ? sanitize_text_field( (string) $one ) : '';
						},
						$value
					)
				);
				continue;
			}
			$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		return $clean;
	}

	/**
	 * The submitting IP, which Laposta requires. '' when it is unreadable or
	 * not an IP at all, rather than passing whatever a proxy header claimed.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
