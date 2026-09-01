<?php
/**
 * Plugin bootstrap.
 *
 * @package Wynko
 */

namespace Wynko;

use Wynko\Admin\AlertNotice;
use Wynko\Admin\Assets;
use Wynko\Admin\EnvironmentNotice;
use Wynko\Admin\Forms\FormEditPage;
use Wynko\Admin\IntegrationAutoDisabledNotice;
use Wynko\Admin\IntegrationsPage;
use Wynko\Admin\LogPage;
use Wynko\Admin\Menu;
use Wynko\Admin\NotificationsTab;
use Wynko\Admin\PluginLinks;
use Wynko\Admin\SecurityTab;
use Wynko\Admin\SettingsPage;
use Wynko\Admin\SystemReport;
use Wynko\Blocks\Campaigns as CampaignsBlock;
use Wynko\Blocks\Form as FormBlock;
use Wynko\Forms\PostType;
use Wynko\Frontend\FormSubmitHandler;
use Wynko\Frontend\Shortcode;
use Wynko\Integrations;
use Wynko\Integrations\ContactForm7\ContactForm7Integration;
use Wynko\Integrations\HtmlForms\HtmlFormsIntegration;
use Wynko\Rest\FieldsController;
use Wynko\Rest\Headers;
use Wynko\Rest\NonceController;
use Wynko\Rest\SubmitController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers hooks. Admin-only hooks are gated behind is_admin() so front-end requests skip the admin classes. */
final class Plugin {

	/**
	 * Runs migrations and registers the plugin's hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Migrations::maybe_run();

		add_filter( 'wynko_register_integrations', array( Integrations::class, 'register_bundled' ) );
		// Priority 20: plugins_loaded fires once every active plugin's main
		// file has loaded, but a plugin that itself registers on its own
		// plugins_loaded callback at the default priority (10) needs to run
		// first, or Registry::all() misses it.
		add_action( 'plugins_loaded', array( Integrations::class, 'boot' ), 20 );
		// init, not plugins_loaded: demote_unavailable() logs and translates,
		// and WordPress does not consider a text domain safe to load before
		// init (see Integrations::boot()'s own docblock).
		add_action( 'init', array( Integrations::class, 'demote_unavailable' ) );

		add_action( 'init', array( CampaignsBlock::class, 'register' ) );
		add_action( 'init', array( FormBlock::class, 'register' ) );
		add_action( 'init', array( PostType::class, 'register' ) );
		add_action( 'init', array( Shortcode::class, 'register' ) );

		// Bust the cache when the duration changes (any context, incl. WP-CLI).
		add_action( 'update_option_' . Config::option_key( 'cache_minutes' ), array( Cache::class, 'bust' ) );

		// The only public, unauthenticated handler in the plugin: it verifies a
		// form-scoped nonce and validates every value itself.
		add_action( 'admin_post_' . FormSubmitHandler::ACTION, array( FormSubmitHandler::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . FormSubmitHandler::ACTION, array( FormSubmitHandler::class, 'handle' ) );

		// Scoped to this plugin's own submit actions only — see the method's
		// docblock for why a page-caching plugin makes this worth extending.
		add_filter( 'nonce_life', array( FormSubmitHandler::class, 'nonce_life' ), 10, 2 );

		// Never let a page-caching plugin keep serving one visitor's redisplayed
		// submission to the next.
		add_action( 'template_redirect', array( FormSubmitHandler::class, 'maybe_nocache_result_page' ) );

		// Outside the is_admin() gate: a REST request does not always report as
		// admin, and rest_api_init only fires on REST requests anyway.
		add_action( 'rest_api_init', array( FieldsController::class, 'register' ) );
		add_action( 'rest_api_init', array( SubmitController::class, 'register' ) );
		add_action( 'rest_api_init', array( NonceController::class, 'register' ) );
		add_action( 'rest_api_init', array( Headers::class, 'register' ) );

		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', array( CampaignsBlock::class, 'enqueue_editor_data' ) );
			add_action( 'enqueue_block_editor_assets', array( FormBlock::class, 'enqueue_editor_data' ) );
			add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue' ) );
			add_action( 'admin_menu', array( Menu::class, 'register' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( WYNKO_FILE ), array( PluginLinks::class, 'action_links' ) );
			add_filter( 'plugin_row_meta', array( PluginLinks::class, 'row_meta' ), 10, 2 );
			add_action( 'admin_notices', array( AlertNotice::class, 'render' ) );
			add_action( 'admin_notices', array( SecurityTab::class, 'render_admin_notice' ) );
			add_action( 'admin_notices', array( EnvironmentNotice::class, 'render' ) );
			add_action( 'admin_notices', array( IntegrationAutoDisabledNotice::class, 'render' ) );
			add_action( 'admin_post_' . AlertNotice::ACTION_DISMISS, array( AlertNotice::class, 'handle_dismiss' ) );
			add_action( 'admin_post_' . EnvironmentNotice::ACTION_DISMISS, array( EnvironmentNotice::class, 'handle_dismiss' ) );
			add_action( 'admin_post_' . IntegrationAutoDisabledNotice::ACTION_DISMISS, array( IntegrationAutoDisabledNotice::class, 'handle_dismiss' ) );
			add_action( 'admin_init', array( SettingsPage::class, 'register' ) );
			add_action( 'admin_init', array( Privacy::class, 'register' ) );
			add_action( 'admin_post_wynko_sync', array( SettingsPage::class, 'handle_sync' ) );
			add_action( 'admin_post_' . SecurityTab::ACTION_RESET, array( SecurityTab::class, 'handle_reset' ) );
			add_action( 'admin_post_' . IntegrationsPage::ACTION, array( IntegrationsPage::class, 'handle_toggle' ) );
			add_action( 'admin_post_' . IntegrationsPage::ACTION_BULK, array( IntegrationsPage::class, 'handle_bulk_toggle' ) );
			add_action( 'admin_post_' . ContactForm7Integration::ACTION_SYNC, array( ContactForm7Integration::class, 'handle_sync' ) );
			add_action( 'admin_post_' . HtmlFormsIntegration::ACTION_SYNC, array( HtmlFormsIntegration::class, 'handle_sync' ) );
			add_action( 'admin_post_' . NotificationsTab::ACTION_TEST, array( NotificationsTab::class, 'handle_test' ) );
			add_action( 'admin_post_' . SystemReport::ACTION_EXPORT, array( SystemReport::class, 'handle_export' ) );
			add_action( 'admin_post_' . SystemReport::ACTION_PING, array( SystemReport::class, 'handle_ping' ) );
			add_action( 'admin_post_' . LogPage::ACTION_EXPORT, array( LogPage::class, 'handle_export' ) );
			add_action( 'admin_post_' . LogPage::ACTION_CLEAR, array( LogPage::class, 'handle_clear' ) );
			add_action( 'admin_post_' . FormEditPage::ACTION_NEW, array( FormEditPage::class, 'handle_new' ) );
			add_action( 'admin_post_' . FormEditPage::ACTION_SAVE, array( FormEditPage::class, 'handle_save' ) );
			add_action( 'admin_post_' . FormEditPage::ACTION_RENAME, array( FormEditPage::class, 'handle_rename' ) );
			add_action( 'admin_post_' . FormEditPage::ACTION_DELETE, array( FormEditPage::class, 'handle_delete' ) );
		}
	}
}
