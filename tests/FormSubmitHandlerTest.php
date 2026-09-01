<?php
/**
 * Tests for the public submission handler.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Forms\FormData;
use Wynko\Frontend\FormSubmitHandler;
use Wynko\Log;
use Wynko\Support\FieldFingerprint;
use Wynko\Support\LapostaErrors;
use Wynko\Support\Sanitizer;
use Wynko\Throttle;
use PHPUnit\Framework\TestCase;

/** Covers the nonce, 404, validation, API-failure, and success paths. */
final class FormSubmitHandlerTest extends TestCase {

	/**
	 * The signup form under test.
	 *
	 * @var int
	 */
	private int $form_id;

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$this->form_id = wynko_test_insert_post(
			array(
				'post_title'  => 'Newsletter signup',
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);
		FormData::load( $this->form_id )->save_list_id( 'list_a' );
	}

	private function queue_fields(): void {
		wynko_test_queue_response(
			200,
			'{"data":[' .
			'{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}},' .
			'{"field":{"field_id":"f_2","name":"Company","custom_name":"company","datatype":"text","required":false}}' .
			']}'
		);
	}

	private function raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'wynko_form_id'                => (string) $this->form_id,
				FormSubmitHandler::NONCE_FIELD => wp_create_nonce( FormSubmitHandler::nonce_action( $this->form_id ) ),
				'wynko_email'                  => 'visitor@example.org',
				'wynko_field'                  => array( 'first_name' => 'Ada' ),
			),
			$overrides
		);
	}

	/**
	 * Submits until the per-IP cap refuses, returning the refused attempt.
	 *
	 * @return array<string,mixed>
	 */
	private function exhaust_ip_cap(): array {
		$result   = array();
		$attempts = Config::throttle_max( 'ip' ) + 1;
		for ( $i = 0; $i <= $attempts; $i++ ) {
			$this->queue_fields();
			wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
			$result = FormSubmitHandler::process( $this->raw() );
		}
		return $result;
	}

	public function test_submissions_past_the_ip_cap_are_throttled(): void {
		$this->assertSame( FormSubmitHandler::STATUS_THROTTLED, $this->exhaust_ip_cap()['status'] );
	}

	public function test_a_throttled_submission_never_reaches_laposta(): void {
		$this->exhaust_ip_cap();
		$before = wynko_test_http_calls();

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( $before, wynko_test_http_calls() );
	}

	/** The cap is a setting, so lowering it has to take effect immediately. */
	public function test_the_ip_cap_is_read_from_the_setting(): void {
		update_option( Config::option_key( 'throttle_ip_max' ), 2 );

		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		FormSubmitHandler::process( $this->raw() );
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_THROTTLED, FormSubmitHandler::process( $this->raw() )['status'] );
	}

	/**
	 * A cap of zero would close every signup form on the site. The settings
	 * screen will not save one, but an option can also arrive from WP-CLI or a
	 * migration, so the read side holds it to the bounds too — up to the
	 * minimum rather than back to the default, which keeps an administrator who
	 * wanted "as strict as possible" strict.
	 */
	public function test_a_cap_of_zero_is_held_at_the_minimum_and_still_accepts_one(): void {
		update_option( Config::option_key( 'throttle_ip_max' ), 0 );

		$this->assertSame( Config::bounds( 'throttle_ip_max' )['min'], Config::throttle_max( 'ip' ) );

		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, FormSubmitHandler::process( $this->raw() )['status'] );
	}

	/** Nonsense in the option falls back to the shipped default. */
	public function test_a_non_numeric_cap_falls_back_to_the_default(): void {
		update_option( Config::option_key( 'throttle_ip_max' ), 'plenty' );

		$this->assertSame( (int) Config::default_for( 'throttle_ip_max' ), Config::throttle_max( 'ip' ) );
	}

	/** The window is stored in minutes and counted in seconds. */
	public function test_the_window_setting_is_minutes(): void {
		update_option( Config::option_key( 'throttle_window' ), 3 );

		$this->assertSame( 180, Config::throttle_window() );
	}

	/**
	 * The escape hatch behind the settings screen's reset button: one option
	 * write has to free every counter, whatever the transient backend is.
	 */
	public function test_bumping_the_epoch_clears_an_exhausted_counter(): void {
		$this->exhaust_ip_cap();

		Throttle::reset();

		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, FormSubmitHandler::process( $this->raw() )['status'] );
	}

	private function total(): int {
		return FormData::load( $this->form_id )->signup_total();
	}

	public function test_a_signup_laposta_accepted_raises_the_forms_lifetime_total(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 1, $this->total() );
	}

	/** The total is a lifetime figure, so it accumulates rather than resets. */
	public function test_every_accepted_signup_adds_one(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->queue_fields();
			wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
			FormSubmitHandler::process( $this->raw() );
		}

		$this->assertSame( 3, $this->total() );
	}

	/** The retry is a signup that went through, so it counts exactly once. */
	public function test_a_signup_that_only_succeeds_after_a_resync_counts_once(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203,"message":"unknown parameter"}}' );
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 1, $this->total() );
	}

	/** Nothing was sent, so nothing was signed up. */
	public function test_a_trapped_bot_does_not_count_as_a_signup(): void {
		FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::HONEYPOT_FIELD => 'https://spam.example' ) ) );

		$this->assertSame( 0, $this->total() );
	}

	/**
	 * The visitor is told it succeeded so the form is not a membership oracle,
	 * but Laposta created nobody: an address already on the list is not a new
	 * signup and must not inflate the total.
	 */
	public function test_a_duplicate_address_does_not_count_as_a_signup(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"exists","code":204,"parameter":"email"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 0, $this->total() );
	}

	public function test_a_failed_signup_does_not_count(): void {
		$this->queue_fields();
		wynko_test_queue_response( 500, '{"error":{"message":"boom"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 0, $this->total() );
	}

	public function test_a_rejected_submission_does_not_count(): void {
		$this->queue_fields();

		FormSubmitHandler::process( $this->raw( array( 'wynko_email' => 'not-an-address' ) ) );

		$this->assertSame( 0, $this->total() );
	}

	public function test_a_throttled_submission_does_not_count(): void {
		update_option( Config::option_key( 'throttle_ip_max' ), Config::bounds( 'throttle_ip_max' )['min'] );
		$accepted = Config::throttle_max( 'ip' );
		for ( $i = 0; $i < $accepted; $i++ ) {
			$this->queue_fields();
			wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
			FormSubmitHandler::process( $this->raw() );
		}

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_THROTTLED, $result['status'] );
		$this->assertSame( $accepted, $this->total() );
	}

	/**
	 * The counters are a rate limit and the total is a record of what happened.
	 * Clearing the first must not rewrite the second.
	 */
	public function test_resetting_the_rate_limits_leaves_the_total_alone(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		FormSubmitHandler::process( $this->raw() );

		Throttle::reset();

		$this->assertSame( 0, Throttle::form_hits( $this->form_id ) );
		$this->assertSame( 1, $this->total() );
	}

	/**
	 * A trapped bot is told it succeeded — anything else and it retries — but
	 * nothing is sent and nothing is logged.
	 */
	public function test_a_filled_honeypot_is_answered_with_success_and_sends_nothing(): void {
		$result = FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::HONEYPOT_FIELD => 'https://spam.example' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_SUCCESS, $result['slug'] );
		$this->assertSame( 0, wynko_test_http_calls() );
		$this->assertSame( array(), Log::all() );
	}

	public function test_an_empty_honeypot_does_not_block_a_real_signup(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		$result = FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::HONEYPOT_FIELD => '  ' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
	}

	public function test_a_wrong_nonce_is_rejected_without_calling_laposta(): void {
		$result = FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'forged' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_BAD_NONCE, $result['status'] );
		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_one_forms_nonce_cannot_submit_another_form(): void {
		$other = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'publish',
			)
		);

		$result = FormSubmitHandler::process(
			$this->raw( array( 'wynko_form_id' => (string) $other ) )
		);

		$this->assertSame( FormSubmitHandler::STATUS_BAD_NONCE, $result['status'] );
	}

	public function test_an_unknown_form_is_not_found(): void {
		$missing = $this->form_id + 999;

		$result = FormSubmitHandler::process(
			array(
				'wynko_form_id'                => (string) $missing,
				FormSubmitHandler::NONCE_FIELD => wp_create_nonce( FormSubmitHandler::nonce_action( $missing ) ),
			)
		);

		$this->assertSame( FormSubmitHandler::STATUS_NOT_FOUND, $result['status'] );
	}

	public function test_an_unpublished_form_is_not_found(): void {
		$draft = wynko_test_insert_post(
			array(
				'post_type'   => Config::form_post_type(),
				'post_status' => 'draft',
			)
		);

		$result = FormSubmitHandler::process(
			array(
				'wynko_form_id'                => (string) $draft,
				FormSubmitHandler::NONCE_FIELD => wp_create_nonce( FormSubmitHandler::nonce_action( $draft ) ),
			)
		);

		$this->assertSame( FormSubmitHandler::STATUS_NOT_FOUND, $result['status'] );
	}

	public function test_a_bad_email_fails_validation_before_the_api_call(): void {
		$this->queue_fields();

		$result = FormSubmitHandler::process( $this->raw( array( 'wynko_email' => 'nope' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_INVALID, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_INVALID_EMAIL, $result['errors']['email'] );
		// One call for the field definitions, none for /member.
		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_a_missing_required_field_fails_validation(): void {
		$this->queue_fields();

		$result = FormSubmitHandler::process( $this->raw( array( 'wynko_field' => array( 'first_name' => '' ) ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_INVALID, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_REQUIRED, $result['errors']['first_name'] );
	}

	public function test_a_pattern_failure_carries_its_own_slug_and_never_reaches_laposta(): void {
		// Its own slug because the renderer shows the pattern's description in
		// its place, which no other invalid value has.
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id' => 'f_1',
					'visible'  => true,
					'attrs'    => array( 'pattern' => '[0-9]{4}' ),
				),
			)
		);
		$this->queue_fields();

		$result = FormSubmitHandler::process( $this->raw( array( 'wynko_field' => array( 'first_name' => 'fsdf' ) ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_INVALID, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_PATTERN, $result['errors']['first_name'] );
		// One call for the field definitions, none for /member.
		$this->assertSame( 1, wynko_test_http_calls() );
	}

	public function test_a_failure_preserves_the_submitted_values_but_never_the_terms_box(): void {
		$this->queue_fields();

		$result = FormSubmitHandler::process(
			$this->raw(
				array(
					'wynko_email' => 'nope',
					'wynko_terms' => '1',
				)
			)
		);

		$this->assertSame( 'nope', $result['values']['email'] );
		$this->assertSame( 'Ada', $result['values']['fields']['first_name'] );
		$this->assertArrayNotHasKey( 'terms', $result['values'] );
	}

	public function test_unchecked_terms_fail_when_the_form_requires_them(): void {
		FormData::load( $this->form_id )->save_settings( array( 'terms_required' => true ) );
		$this->queue_fields();

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( LapostaErrors::SLUG_TERMS, $result['errors']['terms'] );
	}

	public function test_a_hidden_field_is_not_required_even_when_laposta_calls_it_optional(): void {
		FormData::load( $this->form_id )->save_field_overrides(
			array(
				array(
					'field_id'  => 'f_2',
					'visible'   => false,
					'label'     => '',
					'css_class' => '',
				),
			)
		);
		$this->queue_fields();

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertArrayNotHasKey( 'company', $result['errors'] );
	}

	public function test_a_valid_submission_creates_the_member(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_SUCCESS, $result['slug'] );

		$body = wynko_test_last_request()['args']['body'];
		$this->assertSame( 'list_a', $body['list_id'] );
		$this->assertSame( 'visitor@example.org', $body['email'] );
		$this->assertSame( '203.0.113.7', $body['ip'] );
		$this->assertSame( 'Ada', $body['custom_fields']['first_name'] );
	}

	public function test_a_form_with_skip_doi_sends_the_override(): void {
		FormData::load( $this->form_id )->save_settings( array( 'skip_doi' => true ) );
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertTrue( wynko_test_last_request()['args']['body']['options']['ignore_doubleoptin'] );
	}

	/**
	 * The visitor must not be able to tell a duplicate from a new signup, or the
	 * form becomes a membership oracle for any address someone cares to try.
	 */
	public function test_a_duplicate_member_is_indistinguishable_from_a_new_one(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"exists","code":204,"parameter":"email"}}' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_SUCCESS, $result['slug'] );
	}

	/**
	 * The administrator does still get to see it — but as a warning, because
	 * Notifier mails every error onward and this one is not a failure.
	 */
	public function test_a_duplicate_member_is_logged_as_a_warning(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"exists","code":204,"parameter":"email"}}' );

		FormSubmitHandler::process( $this->raw() );

		$levels = array_column( Log::all(), 'level' );
		$this->assertContains( Sanitizer::LEVEL_WARNING, $levels );
		$this->assertNotContains( Sanitizer::LEVEL_ERROR, $levels );
	}

	/**
	 * Turning the setting on knowingly re-opens the oracle; nothing else about
	 * the path changes.
	 */
	public function test_a_form_that_opts_in_tells_the_visitor_about_a_duplicate(): void {
		FormData::load( $this->form_id )->save_settings( array( 'reveal_duplicate' => true ) );
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"exists","code":204,"parameter":"email"}}' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_FAILED, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_DUPLICATE, $result['slug'] );
	}

	public function test_a_revealed_duplicate_is_still_only_a_warning(): void {
		FormData::load( $this->form_id )->save_settings( array( 'reveal_duplicate' => true ) );
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"exists","code":204,"parameter":"email"}}' );

		FormSubmitHandler::process( $this->raw() );

		$levels = array_column( Log::all(), 'level' );
		$this->assertContains( Sanitizer::LEVEL_WARNING, $levels );
		$this->assertNotContains( Sanitizer::LEVEL_ERROR, $levels );
	}

	public function test_an_outage_fails_the_submission_with_the_generic_message(): void {
		$this->queue_fields();
		// There is no offline queue: an unreachable Laposta is a failed
		// submission, not a queued one.
		wynko_test_queue_response( 503, '' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_FAILED, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_GENERIC, $result['slug'] );
	}

	public function test_a_form_with_no_bound_list_fails_rather_than_calling_the_api(): void {
		FormData::load( $this->form_id )->save_list_id( '' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_FAILED, $result['status'] );
		$this->assertSame( 0, wynko_test_http_calls() );
	}

	/**
	 * The fingerprint queue_fields()'s two fields currently hash to, standing
	 * in for what a freshly rendered page would have carried.
	 *
	 * @return string
	 */
	private function current_field_fingerprint(): string {
		return FieldFingerprint::of(
			array(
				array(
					'field_id' => 'f_1',
					'required' => true,
				),
				array(
					'field_id' => 'f_2',
					'required' => false,
				),
			)
		);
	}

	/**
	 * Whether Log::all() carries a stale-render entry — filtered by wording
	 * rather than just level, since a plain successful signup also logs an
	 * unrelated info entry (count_signup()'s "New signup through...").
	 *
	 * @return int
	 */
	private function stale_render_log_count(): int {
		return count(
			array_filter(
				Log::all(),
				static function ( array $entry ): bool {
					return Sanitizer::LEVEL_INFO === $entry['level']
						&& false !== strpos( $entry['message'], 'outdated field fingerprint' );
				}
			)
		);
	}

	public function test_a_submission_carrying_the_current_fingerprint_logs_nothing(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::FIELD_FINGERPRINT_FIELD => $this->current_field_fingerprint() ) ) );

		$this->assertSame( 0, $this->stale_render_log_count() );
	}

	public function test_a_stale_fingerprint_logs_an_info_entry_naming_the_form(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::FIELD_FINGERPRINT_FIELD => 'not-the-current-hash' ) ) );

		$this->assertSame( 1, $this->stale_render_log_count() );

		$stale = array_values(
			array_filter(
				Log::all(),
				static function ( array $entry ): bool {
					return false !== strpos( $entry['message'], 'outdated field fingerprint' );
				}
			)
		);
		$this->assertStringContainsString( 'Newsletter signup', $stale[0]['message'] );
	}

	public function test_a_submission_with_no_fingerprint_field_logs_nothing(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		$raw = $this->raw();
		unset( $raw[ FormSubmitHandler::FIELD_FINGERPRINT_FIELD ] );
		FormSubmitHandler::process( $raw );

		$this->assertSame( 0, $this->stale_render_log_count() );
	}

	public function test_a_second_stale_submission_within_the_cooldown_does_not_log_again(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::FIELD_FINGERPRINT_FIELD => 'stale-hash' ) ) );

		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::FIELD_FINGERPRINT_FIELD => 'stale-hash' ) ) );

		$this->assertSame( 1, $this->stale_render_log_count() );
	}

	public function test_a_stale_fingerprint_is_logged_even_when_validation_fails(): void {
		$this->queue_fields();

		FormSubmitHandler::process(
			$this->raw(
				array(
					FormSubmitHandler::FIELD_FINGERPRINT_FIELD => 'stale-hash',
					'wynko_field' => array(),
				)
			)
		);

		$this->assertSame( 1, $this->stale_render_log_count() );
	}

	private function stored_result( int $form_id ): string {
		return FormSubmitHandler::store_result(
			array(
				'status'  => FormSubmitHandler::STATUS_SUCCESS,
				'form_id' => $form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_SUCCESS,
			)
		);
	}

	public function test_a_result_is_readable_once_and_then_gone(): void {
		$token = $this->stored_result( $this->form_id );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, FormSubmitHandler::take_result( $token, $this->form_id )['status'] );
		$this->assertNull( FormSubmitHandler::take_result( $token, $this->form_id ) );
		$this->assertNull( FormSubmitHandler::take_result( 'made up', $this->form_id ) );
	}

	public function test_another_forms_read_neither_returns_nor_destroys_the_result(): void {
		$token = $this->stored_result( $this->form_id );
		$other = $this->form_id + 1;

		$this->assertNull( FormSubmitHandler::take_result( $token, $other ) );
		// The form the result actually belongs to must still be able to read it:
		// two forms on one page must not consume each other's outcome.
		$this->assertNotNull( FormSubmitHandler::take_result( $token, $this->form_id ) );
	}

	public function test_the_redirect_returns_to_the_submitting_page_with_a_token(): void {
		$url = FormSubmitHandler::redirect_url(
			array(
				'status'  => FormSubmitHandler::STATUS_INVALID,
				'form_id' => $this->form_id,
				'errors'  => array( 'email' => LapostaErrors::SLUG_INVALID_EMAIL ),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_GENERIC,
			),
			'https://example.org/signup/'
		);

		$this->assertStringStartsWith( 'https://example.org/signup/', $url );
		$this->assertStringContainsString( FormSubmitHandler::RESULT_ARG . '=', $url );
	}

	public function test_the_redirect_honours_a_configured_url_on_success(): void {
		// The mode has to say 'url', not just the URL be present: a stored
		// address left behind by an earlier choice must not fire once the
		// admin has switched back to staying on the page.
		FormData::load( $this->form_id )->save_settings(
			array(
				'redirect_type' => 'url',
				'redirect_url'  => 'https://example.org/thanks/',
			)
		);

		$url = FormSubmitHandler::redirect_url(
			array(
				'status'  => FormSubmitHandler::STATUS_SUCCESS,
				'form_id' => $this->form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_SUCCESS,
			),
			'https://example.org/signup/'
		);

		$this->assertSame( 'https://example.org/thanks/', $url );
	}

	public function test_the_redirect_falls_back_home_without_a_referer(): void {
		$url = FormSubmitHandler::redirect_url(
			array(
				'status'  => FormSubmitHandler::STATUS_FAILED,
				'form_id' => $this->form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_GENERIC,
			),
			''
		);

		$this->assertStringStartsWith( home_url( '/' ), $url );
	}

	public function test_a_successful_signup_is_logged_without_the_address(): void {
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw() );

		$entries = Log::all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertStringContainsString( 'Newsletter signup', $entries[0]['message'] );
		$this->assertStringNotContainsString( 'visitor@example.org', $entries[0]['message'] );
	}

	public function test_a_validation_failure_is_logged_as_a_warning(): void {
		$this->queue_fields();

		FormSubmitHandler::process( $this->raw( array( 'wynko_email' => 'not-an-address' ) ) );

		$entries = Log::all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'warning', $entries[0]['level'] );
		$this->assertStringNotContainsString( 'not-an-address', $entries[0]['message'] );
	}

	public function test_an_api_failure_is_logged_as_an_error_carrying_the_reason(): void {
		$this->queue_fields();
		wynko_test_queue_response( 500, '{}' );

		FormSubmitHandler::process( $this->raw() );

		$entries = Log::all();
		$this->assertSame( 'error', $entries[0]['level'] );
		$this->assertStringContainsString( 'Newsletter signup', $entries[0]['message'] );
		$this->assertNotSame( '', trim( str_replace( 'Signup on "Newsletter signup" failed:', '', $entries[0]['message'] ) ) );
	}

	public function test_a_form_without_a_list_is_logged_as_an_error(): void {
		FormData::load( $this->form_id )->save_list_id( '' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 'error', Log::all()[0]['level'] );
		$this->assertStringContainsString( 'no list selected', Log::all()[0]['message'] );
	}

	/**
	 * Both are reachable by anyone. Logging them would let a burst of junk push
	 * the real entries off the end of a bounded log.
	 */
	public function test_probe_traffic_leaves_no_trace(): void {
		FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'forged' ) ) );
		FormSubmitHandler::process( $this->raw( array( 'wynko_form_id' => '999999' ) ) );

		$this->assertSame( array(), Log::all() );
	}
	/**
	 * The stored entries as "level: message", newest first.
	 *
	 * @return array<int,string>
	 */
	private function messages(): array {
		return array_map(
			static function ( array $entry ): string {
				return $entry['level'] . ': ' . $entry['message'];
			},
			Log::all()
		);
	}

	public function test_a_deleted_list_says_so_rather_than_blaming_the_fetch(): void {
		wynko_test_queue_response( 404, '{}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertStringContainsString( 'no longer exists in Laposta', $this->messages()[0] );
	}

	public function test_an_outage_says_laposta_could_not_be_reached(): void {
		wynko_test_queue_response( 500, '{}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertStringContainsString( 'could not be reached', $this->messages()[0] );
	}

	public function test_a_missing_key_names_the_key(): void {
		delete_option( Config::option_key( 'api_key' ) );

		FormSubmitHandler::process( $this->raw() );

		$this->assertStringContainsString( 'no Laposta API key is configured', $this->messages()[0] );
	}
	public function test_a_drifted_field_resyncs_and_the_retry_succeeds(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203,"message":"unknown parameter"}}' );
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
		$this->assertNotEmpty(
			array_filter(
				$this->messages(),
				static function ( string $message ): bool {
					return false !== strpos( $message, 'warning: Fields changed in Laposta' );
				}
			)
		);
	}

	public function test_a_drift_warning_is_written_even_when_the_retry_also_fails(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203}}' );
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203}}' );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_FAILED, $result['status'] );
		$this->assertNotEmpty(
			array_filter(
				$this->messages(),
				static function ( string $message ): bool {
					return false !== strpos( $message, 'Fields changed in Laposta' );
				}
			)
		);
	}

	public function test_the_cooldown_refuses_a_second_forced_refetch(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203}}' );
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203}}' );
		FormSubmitHandler::process( $this->raw() );
		$after_first = wynko_test_http_calls();

		wynko_test_queue_response( 400, '{"error":{"code":203}}' );
		FormSubmitHandler::process( $this->raw() );

		// One create attempt, no forced refetch and no retry.
		$this->assertSame( $after_first + 1, wynko_test_http_calls() );
	}

	public function test_a_validation_error_never_triggers_a_refetch(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":208,"message":"bad address"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 2, wynko_test_http_calls() );
	}
	public function test_a_throttled_submission_is_logged(): void {
		$this->exhaust_ip_cap();

		$this->assertNotEmpty(
			array_filter(
				$this->messages(),
				static function ( string $message ): bool {
					return false !== strpos( $message, 'warning: Signups on "Newsletter signup" are being rate limited' );
				}
			)
		);
	}

	public function test_a_flood_is_logged_once_not_once_per_attempt(): void {
		$this->exhaust_ip_cap();
		Log::clear();

		for ( $i = 0; $i < 25; $i++ ) {
			FormSubmitHandler::process( $this->raw() );
		}

		$this->assertSame( array(), $this->messages() );
	}

	public function test_resetting_the_counters_re_arms_the_entry(): void {
		$this->exhaust_ip_cap();
		Throttle::reset();
		Log::clear();
		$this->exhaust_ip_cap();

		$this->assertNotEmpty(
			array_filter(
				$this->messages(),
				static function ( string $message ): bool {
					return false !== strpos( $message, 'are being rate limited' );
				}
			)
		);
	}
	public function test_a_submission_to_an_unpublished_form_is_a_warning(): void {
		$GLOBALS['wynko_test_posts'][ $this->form_id ]->post_status = 'draft';

		FormSubmitHandler::process( $this->raw() );

		$this->assertStringStartsWith( 'warning: ', $this->messages()[0] );
		$this->assertStringContainsString( 'no longer published', $this->messages()[0] );
	}

	/**
	 * An id matching no post is a scanner walking ids. Logging it would let
	 * anyone outside the site fill a 200-entry log at will.
	 */
	public function test_a_submission_to_no_form_at_all_is_silent(): void {
		FormSubmitHandler::process( $this->raw( array( 'wynko_form_id' => '999999' ) ) );

		$this->assertSame( array(), $this->messages() );
	}

	/** A bot flood must not drown every real entry in the log. */
	public function test_a_honeypot_trip_is_not_logged(): void {
		$result = FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::HONEYPOT_FIELD => 'i am a bot' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
		$this->assertSame( array(), $this->messages() );
	}
	/**
	 * The commonest drift of all: someone adds a *required* field in Laposta.
	 * The plugin never rendered it, so it never sent it, so Laposta refuses with
	 * code 201 — which is not the visitor failing to fill something in, because
	 * FormValidator already passed on every field the plugin knows about.
	 */
	public function test_a_new_required_field_in_laposta_is_drift_not_the_visitor_s_fault(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"type":"invalid_input","message":"Field is required","code":201,"parameter":"birthday"}}' );
		wynko_test_queue_response( 200, $this->grown_fields() );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertNotEmpty(
			array_filter(
				$this->messages(),
				static function ( string $message ): bool {
					return false !== strpos( $message, 'Fields changed in Laposta' );
				}
			),
			'the drift should be reported'
		);
		// Not a flat failure: the field now exists on the form, so the visitor is
		// asked for it rather than told something went wrong.
		$this->assertSame( FormSubmitHandler::STATUS_INVALID, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_REQUIRED, $result['errors']['birthday'] );
	}

	/** The refetch is what makes the new field appear on the next page load. */
	public function test_a_new_required_field_is_in_the_definitions_after_the_drift(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":201,"parameter":"birthday"}}' );
		wynko_test_queue_response(
			200,
			'{"data":[' .
			'{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}},' .
			'{"field":{"field_id":"f_3","name":"Birthday","custom_name":"birthday","datatype":"date","required":true}}' .
			']}'
		);
		wynko_test_queue_response( 400, '{"error":{"code":201,"parameter":"birthday"}}' );
		FormSubmitHandler::process( $this->raw() );

		$after = \Wynko\Api\Fields::for_list( 'list_a' );

		$this->assertSame(
			array( 'first_name', 'birthday' ),
			array_column( $after['fields'], 'custom_name' )
		);
	}

	/**
	 * A visitor who really did leave a known field blank never reaches Laposta —
	 * FormValidator refuses first — so a 201 naming a field the form does show
	 * is not drift and must not spend an API call on a refetch.
	 */
	public function test_a_201_naming_a_field_the_form_shows_is_not_drift(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":201,"parameter":"first_name"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( 2, wynko_test_http_calls() );
		$this->assertSame(
			array(),
			array_filter(
				$this->messages(),
				static function ( string $message ): bool {
					return false !== strpos( $message, 'Fields changed in Laposta' );
				}
			)
		);
	}
	/**
	 * The whole loop the drift path exists to close: a required field is added
	 * in Laposta, the next signup fails and resyncs, and the submission after
	 * that — with the now-rendered field filled in — goes through.
	 */
	public function test_the_next_signup_succeeds_once_the_drift_has_resynced(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":201,"parameter":"birthday"}}' );
		wynko_test_queue_response( 200, $this->grown_fields() );
		FormSubmitHandler::process( $this->raw() );

		// The visitor now sees the new required field and fills it in.
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );
		$result = FormSubmitHandler::process(
			$this->raw(
				array(
					'wynko_field' => array(
						'first_name' => 'Ada',
						'birthday'   => '1815-12-10',
					),
				)
			)
		);

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
	}

	/** A required field is always shown, so a resync is enough to unbreak the form. */
	public function test_a_newly_synced_required_field_is_rendered(): void {
		$fields = \Wynko\Support\Fields::merge_overrides(
			array(
				array(
					'field_id'    => 'f_3',
					'name'        => 'Birthday',
					'custom_name' => 'birthday',
					'type'        => \Wynko\Support\Fields::TYPE_DATE,
					'required'    => true,
					'multiple'    => false,
					'options'     => array(),
					'default'     => '',
				),
			),
			array()
		);

		$this->assertTrue( $fields[0]['visible'] );
	}
	/**
	 * A required field added in Laposta cannot be retried: the visitor was never
	 * shown it, so there is no value to send. Once the resync makes it appear,
	 * the honest answer is to ask them for it — they read it as a field they
	 * overlooked, which is a thing they can act on, rather than a dead end.
	 */
	public function test_a_new_required_field_is_asked_for_rather_than_retried(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":201,"parameter":"birthday"}}' );
		wynko_test_queue_response( 200, $this->grown_fields() );

		$result = FormSubmitHandler::process( $this->raw() );

		$this->assertSame( FormSubmitHandler::STATUS_INVALID, $result['status'] );
		$this->assertSame( LapostaErrors::SLUG_REQUIRED, $result['errors']['birthday'] );
		// No second create attempt: two calls, the failed create and the refetch.
		$this->assertSame( 3, wynko_test_http_calls() );
	}

	public function test_asking_for_a_new_field_does_not_claim_a_retry(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":201,"parameter":"birthday"}}' );
		wynko_test_queue_response( 200, $this->grown_fields() );

		FormSubmitHandler::process( $this->raw() );

		$this->assertCount( 1, $this->messages() );
		$this->assertStringStartsWith( 'warning: ', $this->messages()[0] );
		$this->assertStringNotContainsString( 'retried', $this->messages()[0] );
		$this->assertStringContainsString( 'now asks', $this->messages()[0] );
	}

	/**
	 * The other drift: a field removed in Laposta. The payload has to be rebuilt
	 * from the fresh definitions, or the retry resends the very field that was
	 * just rejected and fails identically.
	 */
	public function test_a_removed_field_is_dropped_from_the_retried_payload(): void {
		$this->queue_fields();
		wynko_test_queue_response( 400, '{"error":{"code":203,"parameter":"company"}}' );
		// The refetch no longer carries first_name's neighbour.
		wynko_test_queue_response(
			200,
			'{"data":[{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}}]}'
		);
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		$result = FormSubmitHandler::process(
			$this->raw(
				array(
					'wynko_field' => array(
						'first_name' => 'Ada',
						'company'    => 'Analytical Engines',
					),
				)
			)
		);

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
		$sent = wynko_test_last_request();
		$this->assertArrayNotHasKey( 'custom_fields[company]', (array) ( $sent['args']['body'] ?? array() ) );
		$this->assertStringNotContainsString( 'Analytical Engines', wp_json_encode( $sent['args']['body'] ?? array() ) );
	}

	/**
	 * The definitions a drift refetch returns, with a required field the form
	 * did not previously know about.
	 *
	 * @return string
	 */
	private function grown_fields(): string {
		return '{"data":[' .
			'{"field":{"field_id":"f_1","name":"First name","custom_name":"first_name","datatype":"text","required":true}},' .
			'{"field":{"field_id":"f_3","name":"Birthday","custom_name":"birthday","datatype":"date","required":true}}' .
			']}';
	}

	public function test_wynko_form_submitted_values_filter_can_change_the_submitted_email(): void {
		add_filter(
			'wynko_form_submitted_values',
			static function ( $values ) {
				$values['email'] = 'filtered@example.org';
				return $values;
			}
		);
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw() );

		$sent = wynko_test_last_request();
		$this->assertStringContainsString( 'filtered@example.org', wp_json_encode( $sent['args']['body'] ) );
	}

	public function test_wynko_form_subscriber_data_filter_can_add_a_custom_field(): void {
		add_filter(
			'wynko_form_subscriber_data',
			static function ( $custom_fields ) {
				$custom_fields['source'] = 'bridge';
				return $custom_fields;
			}
		);
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw() );

		$sent = wynko_test_last_request();
		$this->assertStringContainsString( 'bridge', wp_json_encode( $sent['args']['body'] ) );
	}

	public function test_wynko_form_submit_failed_fires_on_a_failed_submission(): void {
		$fired = false;
		add_action(
			'wynko_form_submit_failed',
			static function ( $form_id ) use ( &$fired ) {
				$fired = $form_id;
			}
		);
		$this->queue_fields();
		wynko_test_queue_response( 503, '' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertSame( $this->form_id, $fired );
	}

	public function test_wynko_form_submitted_fires_on_a_successful_submission(): void {
		$fired = false;
		add_action(
			'wynko_form_submitted',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		FormSubmitHandler::process( $this->raw() );

		$this->assertTrue( $fired );
	}

	public function test_maybe_nocache_result_page_does_nothing_without_a_token(): void {
		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );

		FormSubmitHandler::maybe_nocache_result_page();

		$this->assertFalse( $GLOBALS['wynko_test_nocache'] );
	}

	public function test_maybe_nocache_result_page_sends_no_cache_headers_when_a_token_is_present(): void {
		$_GET[ FormSubmitHandler::RESULT_ARG ] = $this->stored_result( $this->form_id );

		FormSubmitHandler::maybe_nocache_result_page();

		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );
		$this->assertTrue( $GLOBALS['wynko_test_nocache'] );
	}

	public function test_maybe_nocache_result_page_ignores_an_empty_token(): void {
		$_GET[ FormSubmitHandler::RESULT_ARG ] = '';

		FormSubmitHandler::maybe_nocache_result_page();

		unset( $_GET[ FormSubmitHandler::RESULT_ARG ] );
		$this->assertFalse( $GLOBALS['wynko_test_nocache'] );
	}

	/**
	 * The whole reason this exists: a page-caching plugin routinely outlives
	 * core's own default nonce life, and this endpoint is metered by Throttle
	 * too, so the trade is worth it. Scoped so nothing else on the site is
	 * affected.
	 */
	public function test_nonce_life_is_extended_for_this_forms_submit_action(): void {
		$this->assertSame(
			FormSubmitHandler::NONCE_LIFE,
			FormSubmitHandler::nonce_life( DAY_IN_SECONDS, FormSubmitHandler::nonce_action( $this->form_id ) )
		);
	}

	public function test_nonce_life_leaves_an_unrelated_action_untouched(): void {
		$this->assertSame(
			DAY_IN_SECONDS,
			FormSubmitHandler::nonce_life( DAY_IN_SECONDS, 'some_other_plugins_action' )
		);
	}

	public function test_nonce_life_leaves_the_unscoped_default_untouched(): void {
		$this->assertSame( DAY_IN_SECONDS, FormSubmitHandler::nonce_life( DAY_IN_SECONDS ) );
	}

	/**
	 * Off by default: a fresh install must behave exactly as before these
	 * settings existed.
	 */
	public function test_the_nonce_and_throttle_opt_outs_default_to_off(): void {
		$this->assertFalse( Config::form_nonce_disabled() );
		$this->assertFalse( Config::form_throttle_disabled() );

		$this->assertSame(
			FormSubmitHandler::STATUS_BAD_NONCE,
			FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'forged' ) ) )['status']
		);
	}

	public function test_disabling_the_nonce_setting_lets_a_bad_nonce_through(): void {
		update_option( Config::option_key( 'disable_form_nonce' ), true );
		$this->queue_fields();
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1"}}' );

		$result = FormSubmitHandler::process( $this->raw( array( FormSubmitHandler::NONCE_FIELD => 'forged' ) ) );

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
	}

	public function test_disabling_the_throttle_setting_lets_a_metered_visitor_through(): void {
		update_option( Config::option_key( 'disable_form_throttle' ), true );

		$result = $this->exhaust_ip_cap();

		$this->assertSame( FormSubmitHandler::STATUS_SUCCESS, $result['status'] );
	}

	public function test_wynko_form_redirect_url_filter_can_override_the_destination(): void {
		add_filter(
			'wynko_form_redirect_url',
			static function ( $url ) {
				return 'https://example.org/overridden/';
			}
		);

		$url = FormSubmitHandler::redirect_url(
			array(
				'status'  => FormSubmitHandler::STATUS_FAILED,
				'form_id' => $this->form_id,
				'errors'  => array(),
				'values'  => array(),
				'slug'    => LapostaErrors::SLUG_GENERIC,
			),
			'https://example.org/signup/'
		);

		$this->assertSame( 'https://example.org/overridden/', $url );
	}
}
