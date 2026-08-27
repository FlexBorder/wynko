<?php
/**
 * Signup form block.
 *
 * @package Wynko
 */

namespace Wynko\Blocks;

use Wynko\Admin\Forms\FormsListPage;
use Wynko\Admin\Forms\Screen;
use Wynko\Frontend\FormRenderer;
use WP_Block_Type;
use WP_Block_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wynko/form block: a server-rendered signup form, sharing every byte of
 * its markup with the shortcode via FormRenderer — including its stylesheet,
 * which block.json names by handle so the editor's iframe gets the same
 * structural CSS the visitor does. The block adds none of its own.
 */
final class Form {

	const NAME = 'wynko/form';

	/**
	 * Registers the block and its script translations.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Before the block: block.json names this handle, and a handle WordPress
		// cannot resolve at registration time is one it silently drops.
		FormRenderer::register_style();

		$type = register_block_type(
			WYNKO_PATH . 'build/block-form',
			array( 'render_callback' => array( self::class, 'render' ) )
		);

		if ( $type instanceof WP_Block_Type ) {
			foreach ( $type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'wynko-for-laposta', WYNKO_PATH . 'languages' );
			}
		}
	}

	/**
	 * The published forms, as select options for the inspector.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function editor_options(): array {
		$options = array();
		foreach ( FormsListPage::forms() as $form ) {
			if ( ! $form->is_published() ) {
				continue;
			}
			$options[] = array(
				'value' => (string) $form->id(),
				'label' => '' !== $form->name() ? $form->name() : sprintf( '#%d', $form->id() ),
			);
		}
		return $options;
	}

	/**
	 * What the editor is given: the published forms, where each one is edited,
	 * and where a new one is made.
	 *
	 * The editor links to those screens rather than only naming them, so an
	 * admin who has no form yet — or wants to change the one they picked — is
	 * one click away instead of hunting through the menu.
	 *
	 * @return array{forms:array<int,array{value:string,label:string}>,editUrls:array<string,string>,listUrl:string}
	 */
	public static function editor_data(): array {
		$forms     = self::editor_options();
		$edit_urls = array();
		foreach ( $forms as $option ) {
			$edit_urls[ $option['value'] ] = Screen::edit_url( (int) $option['value'] );
		}

		return array(
			'forms'    => $forms,
			'editUrls' => $edit_urls,
			'listUrl'  => Screen::list_url(),
		);
	}

	/**
	 * Hands the editor the list of forms, hooked to enqueue_block_editor_assets
	 * rather than init. Names and ids only, since editor data is readable by
	 * everyone who can edit content.
	 *
	 * @return void
	 */
	public static function enqueue_editor_data(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::NAME );
		if ( ! $type instanceof WP_Block_Type ) {
			return;
		}
		foreach ( $type->editor_script_handles as $handle ) {
			wp_add_inline_script(
				$handle,
				'window.wynkoFormBlockData = ' . wp_json_encode( self::editor_data() ) . ';',
				'before'
			);
		}
	}

	/**
	 * Renders the chosen form.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render( array $attributes ): string {
		$form_id = absint( $attributes['formId'] ?? 0 );
		if ( $form_id <= 0 ) {
			return current_user_can( 'manage_options' )
				? '<p>' . esc_html__( 'Wynko: choose a signup form in the block settings.', 'wynko-for-laposta' ) . '</p>'
				: '';
		}

		return FormRenderer::render( $form_id );
	}
}
