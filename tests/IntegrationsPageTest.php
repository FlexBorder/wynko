<?php
/**
 * Tests for the Integrations admin screen.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Admin\IntegrationsPage;
use Wynko\Integrations;
use PHPUnit\Framework\TestCase;

/** Tests for IntegrationsPage. */
final class IntegrationsPageTest extends TestCase {

	protected function setUp(): void {
		wynko_test_reset_store();
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'one', 'One' );
				$integrations[] = new FakeThirdPartyIntegration();
				return $integrations;
			}
		);
	}

	public function test_render_page_requires_manage_options(): void {
		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_render_page_lists_every_registered_integration(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'One', $output );
		$this->assertStringContainsString( 'A fake integration.', $output );
	}

	public function test_render_page_escapes_third_party_strings(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_render_page_labels_a_bundled_integration_as_wynko(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Wynko', $output );
	}

	public function test_handle_toggle_requires_the_capability(): void {
		$this->expectException( WpDieException::class );

		IntegrationsPage::handle_toggle();
	}

	public function test_toggle_enables_a_known_integration(): void {
		IntegrationsPage::toggle( 'one' );

		$this->assertTrue( Integrations::is_enabled( 'one' ) );
	}

	public function test_toggle_disables_an_already_enabled_integration(): void {
		update_option( 'wynko_integrations_enabled', array( 'one' ) );

		IntegrationsPage::toggle( 'one' );

		$this->assertFalse( Integrations::is_enabled( 'one' ) );
	}

	public function test_toggle_ignores_an_unknown_slug(): void {
		IntegrationsPage::toggle( 'not-registered' );

		$this->assertFalse( Integrations::is_enabled( 'not-registered' ) );
		$this->assertSame( array(), Integrations::enabled() );
	}

	public function test_set_enabled_turns_a_known_integration_on(): void {
		IntegrationsPage::set_enabled( 'one', true );

		$this->assertTrue( Integrations::is_enabled( 'one' ) );
	}

	public function test_set_enabled_turns_an_integration_off(): void {
		update_option( 'wynko_integrations_enabled', array( 'one' ) );

		IntegrationsPage::set_enabled( 'one', false );

		$this->assertFalse( Integrations::is_enabled( 'one' ) );
	}

	public function test_set_enabled_ignores_an_unknown_slug(): void {
		IntegrationsPage::set_enabled( 'not-registered', true );

		$this->assertSame( array(), Integrations::enabled() );
	}

	public function test_render_page_shows_the_version_and_author_line(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Version 1.2.3 | Provided by Wynko', $output );
	}

	public function test_render_page_shows_a_third_party_author_in_the_version_line(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Version 1.0', $output );
		$this->assertStringContainsString( '| By <a', $output );
		$this->assertStringContainsString( 'Jane', $output );
	}

	public function test_render_page_highlights_an_enabled_row(): void {
		wynko_test_set_can_manage( true );
		update_option( 'wynko_integrations_enabled', array( 'one' ) );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wynko-integration-row--enabled', $output );
		$this->assertStringContainsString( 'wynko-integration-row--disabled', $output );
	}

	public function test_render_page_shows_a_row_action_link_matching_the_state(): void {
		wynko_test_set_can_manage( true );
		update_option( 'wynko_integrations_enabled', array( 'one' ) );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Deactivate', $output );
		$this->assertStringContainsString( 'Activate', $output );
	}

	public function test_render_page_shows_settings_only_for_an_enabled_integration_with_settings(): void {
		wynko_test_set_can_manage( true );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'three', 'Three', true );
				return $integrations;
			}
		);

		ob_start();
		IntegrationsPage::render_page();
		$disabled_output = ob_get_clean();

		update_option( 'wynko_integrations_enabled', array( 'three' ) );
		ob_start();
		IntegrationsPage::render_page();
		$enabled_output = ob_get_clean();

		$this->assertStringNotContainsString( 'Settings', $disabled_output );
		$this->assertStringContainsString( 'Settings', $enabled_output );
	}

	public function test_render_page_links_the_name_to_settings_only_when_enabled_with_settings(): void {
		wynko_test_set_can_manage( true );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'three', 'Three', true );
				return $integrations;
			}
		);
		$expected_href = 'integration=three';

		ob_start();
		IntegrationsPage::render_page();
		$disabled_output = ob_get_clean();

		update_option( 'wynko_integrations_enabled', array( 'three' ) );
		ob_start();
		IntegrationsPage::render_page();
		$enabled_output = ob_get_clean();

		$this->assertStringNotContainsString( '<a href="http://example.org/wp-admin/admin.php?page=wynko-integrations&' . $expected_href . '">Three</a>', $disabled_output );
		$this->assertStringContainsString( '<a href="http://example.org/wp-admin/admin.php?page=wynko-integrations&' . $expected_href . '">Three</a>', $enabled_output );
	}

	public function test_render_page_links_the_author_to_its_author_uri(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<a href="https://example.org/jane" target="_blank" rel="noopener noreferrer">Jane', $output );
	}

	public function test_render_page_shows_a_documentation_link_when_one_is_given(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<a href="https://example.org/docs" target="_blank" rel="noopener noreferrer">View documentation</a>', $output );
	}


	public function test_render_page_includes_bulk_actions(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="bulk_action"', $output );
		$this->assertStringContainsString( 'name="slugs[]"', $output );
		$this->assertStringContainsString( 'wynko_bulk_toggle_integration', $output );
	}

	public function test_handle_bulk_toggle_requires_the_capability(): void {
		$this->expectException( WpDieException::class );

		IntegrationsPage::handle_bulk_toggle();
	}

	public function test_render_page_hides_activate_for_an_unavailable_integration(): void {
		wynko_test_set_can_manage( true );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'unavailable', 'Unavailable', false, false );
				return $integrations;
			}
		);

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'slug=unavailable', $output );
	}

	public function test_render_page_shows_the_unavailable_notice_once(): void {
		wynko_test_set_can_manage( true );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'unavailable-one', 'Unavailable One', false, false );
				$integrations[] = new FakeIntegration( 'unavailable-two', 'Unavailable Two', false, false );
				return $integrations;
			}
		);

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $output, 'Integrations can only be activated once the plugin they depend on is active.' )
		);
	}

	public function test_render_page_omits_the_unavailable_notice_when_all_are_available(): void {
		wynko_test_set_can_manage( true );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Integrations can only be activated once the plugin they depend on is active.', $output );
	}

	public function test_render_page_confirms_deactivation_with_the_integration_own_warning(): void {
		wynko_test_set_can_manage( true );
		add_filter(
			'wynko_register_integrations',
			function ( array $integrations ) {
				$integrations[] = new FakeIntegration( 'warns', 'Warns', false, true, 'Specific consequence.' );
				return $integrations;
			}
		);
		update_option( 'wynko_integrations_enabled', array( 'warns' ) );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'onclick="return confirm("Specific consequence.");"', $output );
	}

	public function test_render_page_confirms_deactivation_with_a_generic_warning_by_default(): void {
		wynko_test_set_can_manage( true );
		update_option( 'wynko_integrations_enabled', array( 'one' ) );

		ob_start();
		IntegrationsPage::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'may stop a form that relies on it from working as expected', $output );
	}
}
