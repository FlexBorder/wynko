<?php
/**
 * Tests for the Contact Form 7 bridge, run with CF7's classes present.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use Wynko\Integrations\ContactForm7\ContactForm7Integration;
use Wynko\Log;
use PHPUnit\Framework\TestCase;
use WPCF7_ContactForm;
use WPCF7_Submission;

require_once __DIR__ . '/stubs/WPCF7ContactForm.php';
require_once __DIR__ . '/stubs/WPCF7Submission.php';

/** Tests run with CF7's classes present, via the stand-ins above. */
final class ContactForm7IntegrationAvailableTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		update_option( Config::option_key( 'api_key' ), 'test-key' );
		update_option( 'wynko_throttle_ip_max', 10 );
		WPCF7_Submission::clear_test_instance();
		unset( $_GET['wynko_cf7_list'], $_GET['wynko_cf7'], $_POST['wynko_cf7_list'] );
	}

	protected function tearDown(): void {
		unset( $_GET['wynko_cf7_list'], $_GET['wynko_cf7'], $_POST['wynko_cf7_list'] );
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
	 * A CF7 `checkbox` tag posts an array of the checked item's own label
	 * text — this stands in for that shape. The opt-in field name carries
	 * the list id, per the [checkbox wynko-optin-{list_id} ...] convention.
	 *
	 * @param string              $list_id          Which list's checkbox is checked, '' for none checked.
	 * @param array<string,mixed> $extra            Additional posted fields.
	 * @param string              $email_field_name The posted email field's name.
	 * @return array<string,mixed>
	 */
	private function submitted( string $list_id = 'list-1', array $extra = array(), string $email_field_name = 'your-email' ): array {
		$posted = array( $email_field_name => 'a@example.org' );
		if ( '' !== $list_id ) {
			$posted[ 'wynko-optin-' . $list_id ] = array( 'Sign up for our newsletter' );
		}
		return array_merge( $posted, $extra );
	}

	public function test_it_is_available_once_the_cf7_class_exists(): void {
		$integration = new ContactForm7Integration();

		$this->assertTrue( $integration->is_available() );
	}

	public function test_boot_hooks_the_mail_action_only(): void {
		( new ContactForm7Integration() )->boot();

		$this->assertContains( 'wpcf7_before_send_mail|Wynko\Integrations\ContactForm7\ContactForm7Integration::maybe_subscribe', wynko_test_hooks() );
	}

	public function test_version_matches_the_defined_plugin_version(): void {
		$integration = new ContactForm7Integration();

		$this->assertSame( defined( 'WYNKO_VERSION' ) ? (string) WYNKO_VERSION : '', $integration->version() );
	}

	public function test_maybe_subscribe_ignores_a_submission_with_no_checkbox_checked(): void {
		WPCF7_Submission::set_test_instance( $this->submitted( '' ), '203.0.113.1' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_uses_the_list_id_carried_by_the_checked_box(): void {
		$this->queue_fields( array( $this->email_field() ) );
		WPCF7_Submission::set_test_instance( $this->submitted( 'list-9' ), '203.0.113.2' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		$last = wynko_test_last_request();
		$this->assertSame( 'list-9', $last['args']['body']['list_id'] );
	}

	public function test_maybe_subscribe_ignores_an_optin_checkbox_not_declared_on_the_submitting_form(): void {
		// The submission carries wynko-optin-list-9, appended by the
		// submitter, but the form's own template only ever declared list-1 —
		// RISK-001's cross-form injection.
		WPCF7_Submission::set_test_instance( $this->submitted( 'list-9' ), '203.0.113.20' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5, 'your-email', array( 'your-email', 'wynko-optin-list-1' ) ) );

		$this->assertSame( 0, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_ignores_a_custom_field_not_declared_on_the_submitting_form(): void {
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		// wynko-company is posted but the form's own template never declared it.
		WPCF7_Submission::set_test_instance(
			$this->submitted( 'list-1', array( 'wynko-company' => 'Acme' ) ),
			'203.0.113.21'
		);

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5, 'your-email', array( 'your-email', 'wynko-optin-list-1' ) ) );

		$last = wynko_test_last_request();
		$this->assertSame( array(), $last['args']['body']['custom_fields'] ?? array() );
	}

	public function test_maybe_subscribe_reads_the_email_from_whichever_field_cf7_reports_as_email_type(): void {
		$this->queue_fields( array( $this->email_field() ) );
		WPCF7_Submission::set_test_instance( $this->submitted( 'list-1', array(), 'newsletter-address' ), '203.0.113.3' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		// The form itself reports "newsletter-address" as its email-type field.
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5, 'newsletter-address' ) );

		$last = wynko_test_last_request();
		$this->assertSame( 'a@example.org', $last['args']['body']['email'] );
	}

	public function test_maybe_subscribe_does_nothing_when_the_form_has_no_email_type_field(): void {
		WPCF7_Submission::set_test_instance( $this->submitted(), '203.0.113.10' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5, '' ) );

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
		WPCF7_Submission::set_test_instance( $this->submitted( 'list-1', array( 'wynko-interest' => array( 'News' ) ) ), '203.0.113.12' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

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
		WPCF7_Submission::set_test_instance( $this->submitted( 'list-1', array( 'wynko-topics' => array( 'A', 'B' ) ) ), '203.0.113.13' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		$last = wynko_test_last_request();
		$this->assertSame( array( 'topics' => array( 'A', 'B' ) ), $last['args']['body']['custom_fields'] );
	}

	public function test_maybe_subscribe_sends_a_mapped_required_text_field_present_on_the_form(): void {
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );
		WPCF7_Submission::set_test_instance( $this->submitted( 'list-1', array( 'wynko-first_name' => 'Ada' ) ), '203.0.113.6' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		$last = wynko_test_last_request();
		$this->assertSame( array( 'first_name' => 'Ada' ), $last['args']['body']['custom_fields'] );
	}

	public function test_maybe_subscribe_aborts_when_a_required_mapped_field_is_missing(): void {
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );
		WPCF7_Submission::set_test_instance( $this->submitted(), '203.0.113.7' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		// Only the Fields::for_list() call happened — no Subscribers::create() call.
		$this->assertSame( 1, wynko_test_http_calls() );
		$log = Log::all();
		$this->assertNotEmpty( $log );
		$this->assertSame( 'warning', $log[0]['level'] );
		$this->assertStringContainsString( 'Contact Form 7 integration:', $log[0]['message'] );
	}

	public function test_maybe_subscribe_proceeds_without_an_optional_mapped_field(): void {
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );
		WPCF7_Submission::set_test_instance( $this->submitted(), '203.0.113.8' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		$last = wynko_test_last_request();
		$this->assertSame( array(), $last['args']['body']['custom_fields'] ?? array() );
	}

	public function test_maybe_subscribe_does_nothing_when_throttled(): void {
		update_option( 'wynko_throttle_ip_max', 1 );
		$this->queue_fields( array( $this->email_field() ) );
		WPCF7_Submission::set_test_instance( $this->submitted(), '203.0.113.3' );
		wynko_test_queue_response( 201, '{"member":{"member_id":"m_1","email":"a@example.org"}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) ); // Consumes the one allowed hit.
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) ); // Throttled.

		// One Fields::for_list() call (cached on the second attempt), plus one
		// Subscribers::create() for the first attempt only.
		$this->assertSame( 2, wynko_test_http_calls() );
	}

	public function test_maybe_subscribe_logs_a_duplicate_at_warning_not_error(): void {
		$this->queue_fields( array( $this->email_field() ) );
		WPCF7_Submission::set_test_instance( $this->submitted(), '203.0.113.4' );
		wynko_test_queue_response( 422, '{"error":{"type":"invalid_input","message":"Email address already exists","code":204}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

		$log = Log::all();
		$this->assertNotEmpty( $log );
		$this->assertSame( 'warning', $log[0]['level'] );
	}

	public function test_maybe_subscribe_logs_a_hard_error_at_warning_not_error(): void {
		$this->queue_fields( array( $this->email_field() ) );
		WPCF7_Submission::set_test_instance( $this->submitted(), '203.0.113.5' );
		wynko_test_queue_response( 500, '{"error":{"type":"server_error","message":"Boom","code":999}}' );

		$integration = new ContactForm7Integration();
		$integration->maybe_subscribe( new WPCF7_ContactForm( 5 ) );

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

		ContactForm7Integration::handle_sync();
	}

	public function test_sync_redirect_url_carries_the_posted_list_forward(): void {
		$_POST['wynko_cf7_list'] = 'list-9';

		$url = ContactForm7Integration::sync_redirect_url( 'ok' );

		$this->assertStringContainsString( 'integration=contact-form-7', $url );
		$this->assertStringContainsString( 'wynko_cf7=ok', $url );
		$this->assertStringContainsString( 'wynko_cf7_list=list-9', $url );
	}

	public function test_sync_redirect_url_omits_the_list_arg_when_none_was_posted(): void {
		$url = ContactForm7Integration::sync_redirect_url( 'ok' );

		$this->assertStringNotContainsString( 'wynko_cf7_list', $url );
	}

	public function test_sync_redirect_url_carries_the_error_flag(): void {
		$this->assertStringContainsString( 'wynko_cf7=error', ContactForm7Integration::sync_redirect_url( 'error' ) );
	}

	public function test_render_settings_shows_a_success_notice_after_a_sync(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7'] = 'ok';

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Synced with Laposta.', $output );
	}

	public function test_render_settings_shows_an_error_notice_after_a_failed_sync(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7'] = 'error';

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Sync failed', $output );
	}

	public function test_render_settings_shows_no_notice_without_a_sync_flag(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-success', $output );
		$this->assertStringNotContainsString( 'notice-error', $output );
	}

	public function test_render_settings_shows_the_picker_but_no_tags_when_no_list_is_selected(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1', 'list-2' ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="wynko_cf7_list"', $output );
		$this->assertStringNotContainsString( '[checkbox wynko-optin-', $output );
	}

	public function test_render_settings_shows_the_selected_lists_tag(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1', 'list-2' ) );
		$_GET['wynko_cf7_list'] = 'list-1';

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( '[checkbox wynko-optin-list-1 use_label_element', $output );
		$this->assertStringNotContainsString( '[checkbox wynko-optin-list-2 use_label_element', $output );
	}

	public function test_render_settings_ignores_a_selection_that_is_not_a_known_list(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'not-a-real-list';

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '[checkbox wynko-optin-', $output );
	}

	public function test_render_settings_shows_a_message_when_no_lists_are_known(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array() );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No Laposta lists found yet', $output );
	}

	public function test_render_settings_step_four_offers_to_add_a_form_when_none_exist(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( "don't have a Contact Form 7 form yet", $output );
		$this->assertStringContainsString( 'page=wpcf7-new', $output );
	}

	public function test_render_settings_step_four_lists_existing_cf7_forms(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$form_id = wp_insert_post(
			array(
				'post_type'   => 'wpcf7_contact_form',
				'post_title'  => 'Contact form 1',
				'post_status' => 'publish',
			)
		);

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="page" value="wpcf7"', $output );
		$this->assertStringContainsString( sprintf( 'value="%d"', $form_id ), $output );
		$this->assertStringContainsString( 'Contact form 1', $output );
		$this->assertStringContainsString( 'Open form editor', $output );
	}

	public function test_render_settings_pre_checks_and_disables_a_required_fields_checkbox(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/data-tag="\[text\* wynko-first_name\]" checked="checked" disabled="disabled"/',
			$output
		);
	}

	public function test_render_settings_leaves_an_optional_fields_checkbox_enabled(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/data-tag="\[text wynko-company\]" checked="checked" \/>/',
			$output
		);
	}

	public function test_render_settings_shows_the_optin_checkbox_as_a_required_row_after_the_fields(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->optional_field( 'company' ) ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$company_position = strpos( $output, 'data-tag="[text wynko-company]"' );
		$optin_row_start  = strpos( $output, '<li>', strpos( $output, 'wynko-optin-list-1' ) - 200 );
		$optin_row        = substr( $output, $optin_row_start, strpos( $output, '</li>', $optin_row_start ) - $optin_row_start );

		$this->assertNotFalse( $company_position );
		$this->assertGreaterThan( $company_position, strpos( $output, 'wynko-optin-list-1' ) );
		$this->assertStringContainsString( 'checked="checked" disabled="disabled"', $optin_row );
		$this->assertStringContainsString( 'Opt-in checkbox', $optin_row );
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
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Last refreshed', $output );
	}

	public function test_render_settings_combines_the_optin_and_field_tags_for_copying(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'list-1';
		$this->queue_fields( array( $this->email_field(), $this->required_field( 'first_name' ) ) );

		ob_start();
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wynko-bridge-combined', $output );
		$this->assertStringContainsString( '[checkbox wynko-optin-list-1 use_label_element', $output );
		$this->assertStringContainsString( '[text* wynko-first_name]', $output );
	}

	public function test_render_settings_suggests_an_exclusive_checkbox_tag_with_default_for_single_choice(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'list-1';
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
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'[checkbox wynko-interest use_label_element exclusive &quot;News&quot; &quot;Offers&quot; default:2]',
			$output
		);
	}

	public function test_render_settings_suggests_a_required_non_exclusive_checkbox_tag_for_multi_select(): void {
		wynko_test_set_can_manage( true );
		$this->seed_lists( array( 'list-1' ) );
		$_GET['wynko_cf7_list'] = 'list-1';
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
		( new ContactForm7Integration() )->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'[checkbox* wynko-topics use_label_element &quot;A&quot; &quot;B&quot;]',
			$output
		);
	}
}
