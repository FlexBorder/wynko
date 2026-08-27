<?php
/**
 * Tests for the settings accessor.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Config;
use PHPUnit\Framework\TestCase;

/**
 * Locks the config contract the rest of the plugin depends on: the option keys,
 * defaults, and bounds sourced from config/settings.php, plus the API base URL
 * that config/urls.php owns and this accessor passes through. These
 * accessors are WordPress-free (they do not call get_option), so they run under
 * the plain PHPUnit bootstrap.
 */
final class ConfigTest extends TestCase {

	public function test_option_keys(): void {
		$this->assertSame( 'wynko_api_key', Config::option_key( 'api_key' ) );
		$this->assertSame( 'wynko_cache_minutes', Config::option_key( 'cache_minutes' ) );
		$this->assertSame( 'wynko_log', Config::option_key( 'log' ) );
		$this->assertSame( '', Config::option_key( 'does_not_exist' ) );
	}

	public function test_cache_minutes_bounds_and_default(): void {
		$this->assertSame(
			array(
				'min' => 1,
				'max' => 1440,
			),
			Config::bounds( 'cache_minutes' )
		);
		$this->assertSame( 60, Config::default_for( 'cache_minutes' ) );
	}

	public function test_campaign_count_bounds_and_default(): void {
		$this->assertSame(
			array(
				'min' => 1,
				'max' => 100,
			),
			Config::bounds( 'campaign_count' )
		);
		$this->assertSame( 5, Config::default_for( 'campaign_count' ) );
	}

	public function test_transient_api_base_and_log_max(): void {
		$this->assertSame( 'wynko_campaigns', Config::transient_key() );
		$this->assertSame( 'wynko_lists', Config::lists_transient_key() );
		$this->assertSame( 'https://api.laposta.nl/v2', Config::api_base() );
		$this->assertSame( 200, Config::log_max() );
	}

	public function test_log_level_option(): void {
		$this->assertSame( 'wynko_log_level', Config::option_key( 'log_level' ) );
		$this->assertSame( 'info', Config::default_for( 'log_level' ) );
		$this->assertSame( array( 'error', 'warning', 'info' ), Config::allowed_for( 'log_level' ) );
	}

	public function test_allowed_for_returns_the_configured_values(): void {
		$this->assertSame( array( 'date', 'subject', 'name' ), Config::allowed_for( 'campaign_order_by' ) );
		$this->assertSame( array( 'asc', 'desc' ), Config::allowed_for( 'campaign_order' ) );
		$this->assertSame(
			array( 'subject', 'date', 'subject_date', 'name', 'name_date' ),
			Config::allowed_for( 'campaign_label' )
		);
	}

	public function test_allowed_for_is_empty_for_a_setting_without_an_enum(): void {
		$this->assertSame( array(), Config::allowed_for( 'campaign_count' ) );
		$this->assertSame( array(), Config::allowed_for( 'no_such_setting' ) );
	}

	public function test_block_attribute_defaults_are_permitted_values(): void {
		// A default outside its own enum would make every render fall back.
		foreach ( array( 'campaign_order_by', 'campaign_order', 'campaign_label' ) as $name ) {
			$this->assertContains( Config::default_for( $name ), Config::allowed_for( $name ), $name );
		}
	}

	public function test_the_fields_transient_key_is_declared(): void {
		$this->assertSame( 'wynko_fields', Config::fields_transient_key() );
	}

	public function test_the_form_post_type_and_shortcode_are_declared(): void {
		$this->assertSame( 'wynko_form', Config::form_post_type() );
		$this->assertSame( 'wynko_form', Config::form_shortcode() );
	}

	public function test_every_form_meta_key_is_declared_and_prefixed(): void {
		foreach ( array( 'list_id', 'fields', 'messages', 'settings' ) as $name ) {
			$key = Config::form_meta_key( $name );
			$this->assertNotSame( '', $key, $name . ' has no meta key' );
			$this->assertStringStartsWith( '_wynko_', $key );
		}
	}

	public function test_an_unknown_form_meta_name_has_no_key(): void {
		$this->assertSame( '', Config::form_meta_key( 'nope' ) );
	}

	public function test_the_form_settings_defaults_carry_every_key(): void {
		$defaults = Config::form_settings_defaults();

		$this->assertSame(
			array(
				'redirect_type',
				'redirect_page_id',
				'redirect_url',
				'label_mode',
				'hide_after_submit',
				'skip_doi',
				'reveal_duplicate',
				'terms_required',
				'terms_text',
				'terms_link_type',
				'terms_page_id',
				'terms_url',
			),
			array_keys( $defaults )
		);
		$this->assertFalse( $defaults['skip_doi'] );
		$this->assertSame( '', $defaults['redirect_url'] );
		$this->assertSame( '', $defaults['redirect_type'] );
	}

	public function test_the_result_transient_key_is_prefixed_and_short_lived(): void {
		$this->assertSame( 'wynko_form_result_abc123', Config::form_result_transient_key( 'abc123' ) );
		$this->assertSame( 300, Config::form_result_ttl() );
	}

	public function test_requirements_block_carries_the_advised_thresholds(): void {
		$requirements = Config::requirements();

		$this->assertSame( '8.4', $requirements['php']['advised'] );
		$this->assertSame( '7.0.4', $requirements['wordpress']['advised'] );
		$this->assertSame( '8.4', $requirements['database']['mysql']['advised'] );
		$this->assertSame( '11.8', $requirements['database']['mariadb']['advised'] );
		$this->assertSame( '5.7', $requirements['database']['mysql']['required'] );
		$this->assertSame( '10.4', $requirements['database']['mariadb']['required'] );
		$this->assertContains( 'json', $requirements['modules']['required'] );
		$this->assertContains( 'openssl', $requirements['modules']['advised'] );
		$this->assertContains( 'sodium', $requirements['modules']['advised'] );
		$this->assertSame( '128M', $requirements['memory']['advised'] );
		$this->assertSame( '1.1.1', $requirements['openssl']['advised'] );
	}

	public function test_the_last_sync_option_has_a_key_and_an_empty_default(): void {
		$this->assertSame( 'wynko_last_sync', Config::option_key( 'last_sync' ) );
		$this->assertSame( array(), Config::default_for( 'last_sync' ) );
	}

	public function test_notification_option_keys_and_defaults(): void {
		$this->assertSame( 'wynko_notify_enabled', Config::option_key( 'notify_enabled' ) );
		$this->assertSame( 'wynko_notify_emails', Config::option_key( 'notify_emails' ) );
		$this->assertFalse( Config::default_for( 'notify_enabled' ) );
		$this->assertSame( '', Config::default_for( 'notify_emails' ) );
	}

	public function test_notification_transient_interval_and_cap(): void {
		$this->assertSame( 'wynko_notify_sent', Config::notify_transient_key() );
		$this->assertSame( 3600, Config::notify_interval() );
		$this->assertSame( 10, Config::notify_max_recipients() );
	}
}
