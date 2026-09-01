<?php
/**
 * Tests for the HTML Forms bridge, run with HTML Forms' classes present.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use HTML_Forms\Submission;
use Wynko\Config;
use Wynko\Integrations\HtmlForms\HtmlFormsIntegration;
use Wynko\Log;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/HtmlFormsPlugin.php';
require_once __DIR__ . '/stubs/HtmlFormsSubmission.php';

/** Tests run with HTML Forms' classes present, via the stand-ins above. */
final class HtmlFormsIntegrationAvailableTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
		update_option( 'wynko_throttle_ip_max', 10 );
		unset( $_GET['wynko_hf_list'], $_GET['wynko_hf'], $_POST['wynko_hf_list'] );
	}

	protected function tearDown(): void {
		unset( $_GET['wynko_hf_list'], $_GET['wynko_hf'], $_POST['wynko_hf_list'] );
	}

	private function queue_fields( array $fields ): void {
		wynko_test_queue_response( 200, (string) wp_json_encode( array( 'data' => $fields ) ) );
	}

	private function email_field(): array {
		return array(
			'field' => array(
				'field_id'    => 'f_email',
				'name'        => 'Email',
				'custom_name' => 'email',
				'datatype'    => 'email',
				'required'    => true,
			),
		);
	}

	private function required_field( string $custom_name ): array {
		return array(
			'field' => array(
				'field_id'    => 'f_' . $custom_name,
				'name'        => $custom_name,
				'custom_name' => $custom_name,
				'datatype'    => 'text',
				'required'    => true,
			),
		);
	}

	private function optional_field( string $custom_name ): array {
		return array(
			'field' => array(
				'field_id'    => 'f_' . $custom_name,
				'name'        => $custom_name,
				'custom_name' => $custom_name,
				'datatype'    => 'text',
				'required'    => false,
			),
		);
	}

	/**
	 * Builds a Submission-shaped object the way HTML Forms' own
	 * hf_form_success action carries one: `data` is the sanitized posted
	 * field values, `ip_address` is read straight off the object rather than
	 * fetched from a singleton the way CF7's WPCF7_Submission is.
	 *
	 * @param array<string,mixed> $data Posted field values.
	 * @param string              $ip   Submitting IP.
	 * @return Submission
	 */
	private function submission( array $data, string $ip = '203.0.113.1' ): Submission {
		$submission             = new Submission();
		$submission->data       = $data;
		$submission->ip_address = $ip;
		return $submission;
	}

	/**
	 * Stands in for the submitted HTML_Forms\Form instance whose raw ->markup
	 * declared_field_names() greps — declares exactly the keys $posted
	 * carries, standing in for a form whose pasted markup matches what it
	 * received, the shape most of these tests exercise. Build a narrower one
	 * by hand to test a field the submission carries but the form's own
	 * markup never declared.
	 *
	 * @param array<string,mixed> $posted Posted field values, e.g. from posted().
	 * @return object
	 */
	private function form_for( array $posted ): object {
		$form         = new \stdClass();
		$form->markup = implode(
			"\n",
			array_map(
				static function ( string $name ): string {
					return sprintf( '<input name="%s">', $name );
				},
				array_keys( $posted )
			)
		);
		return $form;
	}

	/**
	 * @param string              $list_id Which list's checkbox is checked, '' for none checked.
	 * @param array<string,mixed> $extra   Additional posted fields.
	 * @param string              $email   The posted wynko-email value, '' to omit it.
	 * @return array<string,mixed>
	 */
	private function posted( string $list_id = 'list-1', array $extra = array(), string $email = 'a@example.org' ): array {
		$posted = array();
		if ( '' !== $email ) {
			$posted[ HtmlFormsIntegration::EMAIL_FIELD ] = $email;
		}
		if ( '' !== $list_id ) {
			$posted[ 'wynko-optin-' . $list_id ] = '1';
		}
		return array_merge( $posted, $extra );
	}

	public function test_it_is_available_once_the_html_forms_class_exists(): void {
		$integration = new HtmlFormsIntegration();

		$this->assertTrue( $integration->is_available() );
	}

	public function test_boot_hooks_the_success_action_only(): void {
		( new HtmlFormsIntegration() )->boot();

		$this->assertContains( 'hf_form_success|Wynko\Integrations\HtmlForms\HtmlFormsIntegration::maybe_subscribe', wynko_test_hooks() );
	}

	public function test_version_matches_the_defined_plugin_version(): void {
		$integration = new HtmlFormsIntegration();

		$this->assertSame( defined( 'WYNKO_VERSION' ) ? (string) WYNKO_VERSION : '', $integration->version() );
	}

	public function test_maybe_subscribe_ignores_a_submission_with_no_checkbox_checked(): void {
		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( '' ) ), $this->form_for( $this->posted( '' ) ) );

		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_uses_the_list_id_carried_by_the_checked_box(): void {
		$this->queue_fields( array( $this->email_field() ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( 'list-9' ) ), $this->form_for( $this->posted( 'list-9' ) ) );

		$last = wynko_test_last_request();
		$this->assertSame( 'list-9', $last['args']['body']['list_id'] );
	}

	public function test_maybe_subscribe_ignores_an_optin_checkbox_not_declared_on_the_submitting_form(): void {
		// The submission carries wynko-optin-list-9, appended by the
		// submitter, but the form's own markup only ever declared list-1 —
		// RISK-001's cross-form injection.
		$submission = $this->submission( $this->posted( 'list-9' ) );
		$form       = $this->form_for( $this->posted( 'list-1' ) );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $submission, $form );

		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_ignores_a_custom_field_not_declared_on_the_submitting_form(): void {
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		// wynko-company is posted but the form's own markup never declared it.
		$posted     = $this->posted( 'list-1', array( 'wynko-company' => 'Acme' ) );
		$submission = $this->submission( $posted );
		$form       = $this->form_for( $this->posted( 'list-1' ) );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $submission, $form );

		$last = wynko_test_last_request();
		$this->assertSame( array(), $last['args']['body']['custom_fields'] ?? array() );
	}

	public function test_maybe_subscribe_reads_the_email_from_the_fixed_field_name(): void {
		$this->queue_fields( array( $this->email_field() ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( 'list-1', array(), 'a@example.org' ) ), $this->form_for( $this->posted( 'list-1', array(), 'a@example.org' ) ) );

		$last = wynko_test_last_request();
		$this->assertSame( 'a@example.org', $last['args']['body']['email'] );
	}

	public function test_maybe_subscribe_does_nothing_when_the_email_field_is_missing(): void {
		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( 'list-1', array(), '' ) ), $this->form_for( $this->posted( 'list-1', array(), '' ) ) );

		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_sends_a_single_choice_field_as_one_string(): void {
		$this->queue_fields(
			array(
				$this->email_field(),
				array(
					'field' => array(
						'field_id'    => 'f_interest',
						'name'        => 'Interest',
						'custom_name' => 'interest',
						'datatype'    => 'select_single',
						'required'    => false,
						'options'     => array( 'News', 'Offers' ),
					),
				),
			)
		);
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( 'list-1', array( 'wynko-interest' => 'News' ) ) ), $this->form_for( $this->posted( 'list-1', array( 'wynko-interest' => 'News' ) ) ) );

		$last = wynko_test_last_request();
		$this->assertSame( array( 'interest' => 'News' ), $last['args']['body']['custom_fields'] );
	}

	public function test_maybe_subscribe_sends_a_multi_choice_field_as_an_array(): void {
		$this->queue_fields(
			array(
				$this->email_field(),
				array(
					'field' => array(
						'field_id'    => 'f_topics',
						'name'        => 'Topics',
						'custom_name' => 'topics',
						'datatype'    => 'select_multiple',
						'required'    => false,
						'options'     => array( 'A', 'B' ),
					),
				),
			)
		);
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( 'list-1', array( 'wynko-topics' => array( 'A', 'B' ) ) ) ), $this->form_for( $this->posted( 'list-1', array( 'wynko-topics' => array( 'A', 'B' ) ) ) ) );

		$last = wynko_test_last_request();
		$this->assertSame( array( 'topics' => array( 'A', 'B' ) ), $last['args']['body']['custom_fields'] );
	}

	public function test_maybe_subscribe_sends_a_mapped_required_text_field_present_on_the_form(): void {
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted( 'list-1', array( 'wynko-first_name' => 'Ada' ) ) ), $this->form_for( $this->posted( 'list-1', array( 'wynko-first_name' => 'Ada' ) ) ) );

		$last = wynko_test_last_request();
		$this->assertSame( array( 'first_name' => 'Ada' ), $last['args']['body']['custom_fields'] );
	}

	public function test_maybe_subscribe_aborts_when_a_required_mapped_field_is_missing(): void {
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted() ), $this->form_for( $this->posted() ) );

		// Only the Fields::for_list() call happened — no Subscribers::create() call.
		$this->assertSame( 1, wynko_test_http_calls() );
		$log = Log::all();
		$this->assertNotEmpty( $log );
		$this->assertSame( 'warning', $log[0]['level'] );
		$this->assertStringContainsString( 'HTML Forms integration:', $log[0]['message'] );
	}

	public function test_maybe_subscribe_proceeds_without_an_optional_mapped_field(): void {
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted() ), $this->form_for( $this->posted() ) );

		$last = wynko_test_last_request();
		$this->assertSame( array(), $last['args']['body']['custom_fields'] ?? array() );
	}

	public function test_maybe_subscribe_does_nothing_when_throttled(): void {
		update_option( 'wynko_throttle_ip_max', 1 );
		$this->queue_fields( array( $this->email_field() ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted() ), $this->form_for( $this->posted() ) ); // Consumes the one allowed hit.
		$integration->maybe_subscribe( $this->submission( $this->posted() ), $this->form_for( $this->posted() ) ); // Throttled.

		// One Fields::for_list() call (cached on the second attempt), plus one
		// Subscribers::create() for the first attempt only.
		$this->assertSame( 2, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_logs_a_duplicate_at_warning_not_error(): void {
		$this->queue_fields( array( $this->email_field() ) );
		wynko_test_queue_response( 422, '{"error":{"type":"invalid_input","message":"Email address already exists","code":204}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted() ), $this->form_for( $this->posted() ) );

		$log = Log::all();
		$this->assertNotEmpty( $log );
		$this->assertSame( 'warning', $log[0]['level'] );
	}

	public function test_maybe_subscribe_logs_a_hard_error_at_warning_not_error(): void {
		$this->queue_fields( array( $this->email_field() ) );
		wynko_test_queue_response( 500, '{"error":{"type":"server_error","message":"Boom","code":999}}' );

		$integration = new HtmlFormsIntegration();
		$integration->maybe_subscribe( $this->submission( $this->posted() ), $this->form_for( $this->posted() ) );

		$log = Log::all();
		$this->assertNotEmpty( $log );
		$this->assertSame( 'warning', $log[0]['level'] );
	}

	private function seed_lists( array $list_ids ): void {
		set_transient(
			Config::lists_transient_key(),
			array(
				'options' => array_map(
					static function ( string $id ): array {
						return array(
							'value' => $id,
							'label' => $id,
						);
					},
					$list_ids
				),
				'error'   => false,
			),
			60
		);
	}

	public function test_handle_sync_requires_the_capability(): void {
		$this->expectException( WpDieException::class );

		HtmlFormsIntegration::handle_sync();
	}

	public function test_sync_redirect_url_carries_the_posted_list_forward(): void {
		$_POST['wynko_hf_list'] = 'list-9';

		$url = HtmlFormsIntegration::sync_redirect_url( 'ok' );

		$this->assertStringContainsString( 'integration=html-forms', $url );
		$this->assertStringContainsString( 'wynko_hf=ok', $url );
		$this->assertStringContainsString( 'wynko_hf_list=list-9', $url );
	}

	public function test_sync_redirect_url_omits_the_list_arg_when_none_was_posted(): void {
		$url = HtmlFormsIntegration::sync_redirect_url( 'ok' );

		$this->assertStringNotContainsString( 'wynko_hf_list', $url );
	}

	public function test_sync_redirect_url_carries_the_error_flag(): void {
		$this->assertStringContainsString( 'wynko_hf=error', HtmlFormsIntegration::sync_redirect_url( 'error' ) );
	}

	public function test_render_settings_shows_a_success_notice_after_a_sync(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf'] = 'ok';

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Synced with Laposta.', $output );
	}

	public function test_render_settings_shows_an_error_notice_after_a_failed_sync(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf'] = 'error';

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Sync failed', $output );
	}

	public function test_render_settings_shows_no_notice_without_a_sync_flag(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-success', $output );
		$this->assertStringNotContainsString( 'notice-error', $output );
	}

	public function test_render_settings_shows_the_picker_but_no_snippets_when_no_list_is_selected(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1', 'list-2' ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="wynko_hf_list"', $output );
		$this->assertStringNotContainsString( 'wynko-optin-', $output );
	}

	public function test_render_settings_shows_the_selected_lists_snippet(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1', 'list-2' ) );
		$_GET['wynko_hf_list'] = 'list-1';

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wynko-optin-list-1', $output );
		$this->assertStringNotContainsString( 'wynko-optin-list-2', $output );
	}

	public function test_render_settings_ignores_a_selection_that_is_not_a_known_list(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'not-a-real-list';

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'wynko-optin-', $output );
	}

	public function test_render_settings_shows_a_message_when_no_lists_are_known(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array() );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No Laposta lists found yet', $output );
	}

	public function test_render_settings_step_four_offers_to_add_a_form_when_none_exist(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( "don't have an HTML Forms form yet", $output );
		$this->assertStringContainsString( 'page=html-forms-add-form', $output );
	}

	public function test_render_settings_step_four_lists_existing_html_forms(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$form_id = wp_insert_post(
			array(
				'post_type'   => 'html-form',
				'post_title'  => 'Signup form',
				'post_status' => 'publish',
			)
		);

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="page" value="html-forms"', $output );
		$this->assertStringContainsString( sprintf( 'value="%d"', $form_id ), $output );
		$this->assertStringContainsString( 'Signup form', $output );
		$this->assertStringContainsString( 'Open form editor', $output );
	}

	/**
	 * Extracts the single-line `<li>...</li>` row containing $needle — the
	 * nearest `<li>` at or before $needle's own position, so it works
	 * regardless of how far into the row $needle sits. The nested HTML-in-
	 * HTML snippet makes a full-row regex too brittle to be worth writing.
	 *
	 * @param string $output Rendered settings screen.
	 * @param string $needle Text the target row contains.
	 * @return string
	 */
	private function row_containing( string $output, string $needle ): string {
		$position = strpos( $output, $needle );
		$start    = strrpos( substr( $output, 0, $position ), '<li>' );
		return substr( $output, $start, strpos( $output, '</li>', $start ) - $start );
	}

	public function test_render_settings_pre_checks_and_disables_a_required_fields_checkbox(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$row = $this->row_containing( $output, 'wynko-first_name' );
		$this->assertStringContainsString( 'checked="checked" disabled="disabled"', $row );
		$this->assertStringContainsString( 'required', $row );
	}

	public function test_render_settings_leaves_an_optional_fields_checkbox_enabled(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$row = $this->row_containing( $output, 'wynko-company' );
		$this->assertStringContainsString( 'checked="checked" />', $row );
		$this->assertStringNotContainsString( 'disabled', $row );
	}

	public function test_render_settings_shows_the_optin_checkbox_as_a_required_row_after_the_fields(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$company_position = strpos( $output, 'wynko-company' );
		$optin_row        = $this->row_containing( $output, 'wynko-optin-list-1' );

		$this->assertNotFalse( $company_position );
		$this->assertGreaterThan( $company_position, strpos( $output, 'wynko-optin-list-1' ) );
		$this->assertStringContainsString( 'checked="checked" disabled="disabled"', $optin_row );
		$this->assertStringContainsString( 'Opt-in checkbox', $optin_row );
	}

	public function test_render_settings_shows_the_email_field_as_a_required_row_first(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field() ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Email address', $output );
		$this->assertLessThan(
			strpos( $output, 'wynko-optin-list-1' ),
			strpos( $output, 'wynko-email' )
		);
	}

	public function test_render_settings_shows_the_last_sync_time(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		update_option(
			'wynko_last_sync',
			array(
				'at' => time(),
				'ok' => true,
			)
		);

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Last refreshed', $output );
	}

	public function test_render_settings_combines_the_optin_email_and_field_snippets_for_copying(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wynko-bridge-combined', $output );
		$this->assertStringContainsString( 'wynko-optin-list-1', $output );
		$this->assertStringContainsString( 'wynko-email', $output );
		$this->assertStringContainsString( 'wynko-first_name', $output );
	}

	public function test_render_settings_suggests_a_select_with_the_default_preselected_for_single_choice(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields(
			array(
				$this->email_field(),
				array(
					'field' => array(
						'field_id'     => 'f_interest',
						'name'         => 'Interest',
						'custom_name'  => 'interest',
						'datatype'     => 'select_single',
						'required'     => false,
						'options'      => array( 'News', 'Offers' ),
						'defaultvalue' => 'Offers',
					),
				),
			)
		);

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( '&lt;select name=&quot;wynko-interest&quot;&gt;', $output );
		$this->assertStringContainsString( 'value=&quot;Offers&quot; selected=&quot;selected&quot;', $output );
	}

	public function test_render_settings_suggests_a_checkbox_group_for_multi_select(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_hf_list'] = 'list-1';
		$this->queue_fields(
			array(
				$this->email_field(),
				array(
					'field' => array(
						'field_id'    => 'f_topics',
						'name'        => 'Topics',
						'custom_name' => 'topics',
						'datatype'    => 'select_multiple',
						'required'    => true,
						'options'     => array( 'A', 'B' ),
					),
				),
			)
		);

		ob_start();
		( new HtmlFormsIntegration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name=&quot;wynko-topics[]&quot; value=&quot;A&quot;', $output );
		$this->assertStringContainsString( 'name=&quot;wynko-topics[]&quot; value=&quot;B&quot;', $output );
	}
}
