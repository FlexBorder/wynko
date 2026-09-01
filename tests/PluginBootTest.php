<?php
/**
 * Tests for hook registration.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\Assets;
use Wynko\Admin\Menu;
use Wynko\Plugin;
use PHPUnit\Framework\TestCase;

/** The wiring is easy to forget and invisible until a user hits it. */
final class PluginBootTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
	}

	public function test_the_admin_bundle_loads_only_on_wynko_screens(): void {
		$this->assertTrue( Assets::is_wynko_screen( 'toplevel_page_' . Menu::PARENT ) );
		$this->assertTrue( Assets::is_wynko_screen( 'wynko_page_' . Menu::FORMS ) );
		$this->assertFalse( Assets::is_wynko_screen( 'edit.php' ) );
		$this->assertFalse( Assets::is_wynko_screen( 'plugins.php' ) );
	}

	public function test_the_public_submit_action_is_registered_for_logged_out_visitors_too(): void {
		Plugin::boot();

		$hooks = wynko_test_hooks();
		$this->assertContains( 'admin_post_wynko_submit_form|Wynko\Frontend\FormSubmitHandler::handle', $hooks );
		$this->assertContains( 'admin_post_nopriv_wynko_submit_form|Wynko\Frontend\FormSubmitHandler::handle', $hooks );
	}

	public function test_the_post_type_block_and_shortcode_register_on_init(): void {
		Plugin::boot();

		$hooks = wynko_test_hooks();
		$this->assertContains( 'init|Wynko\Forms\PostType::register', $hooks );
		$this->assertContains( 'init|Wynko\Frontend\Shortcode::register', $hooks );
		$this->assertContains( 'init|Wynko\Blocks\Form::register', $hooks );
	}

	public function test_the_admin_handlers_are_not_registered_on_a_front_end_request(): void {
		Plugin::boot();

		$hooks = wynko_test_hooks();
		$this->assertNotContains( 'admin_post_wynko_save_form|Wynko\Admin\Forms\FormEditPage::handle_save', $hooks );
	}

	public function test_the_admin_handlers_are_registered_in_the_admin(): void {
		$GLOBALS['wynko_test_is_admin'] = true;
		Plugin::boot();
		$GLOBALS['wynko_test_is_admin'] = false;

		$hooks = wynko_test_hooks();
		$this->assertContains( 'admin_post_wynko_system_report|Wynko\Admin\SystemReport::handle_export', $hooks );
		$this->assertContains( 'admin_post_wynko_api_ping|Wynko\Admin\SystemReport::handle_ping', $hooks );
		$this->assertContains( 'admin_post_wynko_notify_test|Wynko\Admin\NotificationsTab::handle_test', $hooks );
		$this->assertContains( 'admin_notices|Wynko\Admin\AlertNotice::render', $hooks );
		$this->assertContains( 'admin_post_wynko_alert_dismiss|Wynko\Admin\AlertNotice::handle_dismiss', $hooks );
		$this->assertContains( 'admin_notices|Wynko\Admin\EnvironmentNotice::render', $hooks );
		$this->assertContains( 'admin_post_wynko_env_dismiss|Wynko\Admin\EnvironmentNotice::handle_dismiss', $hooks );
		$this->assertContains( 'admin_notices|Wynko\Admin\IntegrationAutoDisabledNotice::render', $hooks );
		$this->assertContains( 'admin_post_wynko_integrations_auto_disabled_dismiss|Wynko\Admin\IntegrationAutoDisabledNotice::handle_dismiss', $hooks );
		$this->assertContains( 'admin_post_wynko_new_form|Wynko\Admin\Forms\FormEditPage::handle_new', $hooks );
		$this->assertContains( 'admin_post_wynko_save_form|Wynko\Admin\Forms\FormEditPage::handle_save', $hooks );
		$this->assertContains( 'admin_post_wynko_delete_form|Wynko\Admin\Forms\FormEditPage::handle_delete', $hooks );
		$this->assertContains( 'admin_post_wynko_toggle_integration|Wynko\Admin\IntegrationsPage::handle_toggle', $hooks );
		$this->assertContains( 'admin_post_wynko_bulk_toggle_integration|Wynko\Admin\IntegrationsPage::handle_bulk_toggle', $hooks );
		$this->assertContains( 'admin_post_wynko_cf7_sync|Wynko\Integrations\ContactForm7\ContactForm7Integration::handle_sync', $hooks );
		$this->assertContains( 'admin_post_wynko_hf_sync|Wynko\Integrations\HtmlForms\HtmlFormsIntegration::handle_sync', $hooks );
		$this->assertContains( 'enqueue_block_editor_assets|Wynko\Blocks\Form::enqueue_editor_data', $hooks );
	}

	public function test_the_bundled_integrations_filter_and_boot_dispatch_are_registered(): void {
		Plugin::boot();

		$hooks = wynko_test_hooks();
		$this->assertContains( 'wynko_register_integrations|Wynko\Integrations::register_bundled', $hooks );
		$this->assertContains( 'plugins_loaded|Wynko\Integrations::boot', $hooks );
		$this->assertContains( 'init|Wynko\Integrations::demote_unavailable', $hooks );
	}
}
