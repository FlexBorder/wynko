<?php
/**
 * Tests for the URL registry accessor.
 *
 * @package Wynko
 */

namespace Wynko\Tests;

use Wynko\Urls;
use PHPUnit\Framework\TestCase;

/**
 * Locks the registry contract: what each name resolves to, what an unknown
 * name does, and the rule that ties rel to target. Like Config, these
 * accessors are WordPress-free, so they run under the plain bootstrap.
 */
final class UrlsTest extends TestCase {

	/**
	 * Every registered link, so a new entry is covered without editing tests.
	 *
	 * @return array<string,array{string}>
	 */
	public function link_names(): array {
		$registry = require dirname( __DIR__ ) . '/config/urls.php';
		$cases    = array();
		foreach ( array_keys( $registry['links'] ) as $name ) {
			$cases[ $name ] = array( (string) $name );
		}
		return $cases;
	}

	public function test_api_base_is_the_laposta_v2_host(): void {
		$this->assertSame( 'https://api.laposta.nl/v2', Urls::api_base() );
	}

	public function test_registered_urls(): void {
		$this->assertSame( 'https://docs.laposta.org/article/947-how-do-i-get-an-api-key', Urls::url( 'laposta_docs' ) );
		$this->assertSame( 'https://wordpress.org/plugins/mailchimp-for-wp/', Urls::url( 'mc4wp' ) );
	}

	/** Linked from the Plugins screen row meta (Wynko\Admin\PluginLinks). */
	public function test_the_documentation_link_is_registered(): void {
		$this->assertSame(
			'https://getwynko.com/docs/?utm_source=wp-plugin&utm_medium=wynko&utm_campaign=plugins-page',
			Urls::url( 'documentation' )
		);
		$this->assertSame( '_blank', Urls::target( 'documentation' ) );
		$this->assertSame( 'noopener noreferrer', Urls::rel( 'documentation' ) );
	}

	/** The wp_salt() reference explains the term the settings copy uses. */
	public function test_the_wp_salt_reference_is_registered(): void {
		$this->assertSame( 'https://developer.wordpress.org/reference/functions/wp_salt/', Urls::url( 'wp_salt_docs' ) );
		$this->assertSame( '_blank', Urls::target( 'wp_salt_docs' ) );
		$this->assertSame( 'noopener noreferrer', Urls::rel( 'wp_salt_docs' ) );
	}

	/** The block supplies each campaign's href, so the registry holds only its target. */
	public function test_a_render_time_href_registers_no_url(): void {
		$this->assertSame( '', Urls::url( 'campaign_web' ) );
		$this->assertSame( '_blank', Urls::target( 'campaign_web' ) );
	}

	public function test_an_unknown_name_resolves_to_nothing_openable_in_place(): void {
		$this->assertSame( '', Urls::url( 'does_not_exist' ) );
		$this->assertSame( '_self', Urls::target( 'does_not_exist' ) );
		$this->assertSame( '', Urls::rel( 'does_not_exist' ) );
	}

	/**
	 * @dataProvider link_names
	 */
	public function test_a_blank_target_carries_the_opener_guard( string $name ): void {
		$expected = '_blank' === Urls::target( $name ) ? 'noopener noreferrer' : '';
		$this->assertSame( $expected, Urls::rel( $name ) );
	}

	public function test_the_provider_actually_found_links(): void {
		$this->assertNotEmpty( $this->link_names() );
	}

	public function test_the_terms_link_target_is_registered(): void {
		$this->assertSame( '_blank', Urls::target( 'form_terms' ) );
		$this->assertStringContainsString( 'noopener', Urls::rel( 'form_terms' ) );
	}

	public function test_a_list_url_carries_the_list_id_as_its_query_argument(): void {
		$url = Urls::laposta_list_url( 'azu4ujdpjw' );

		$this->assertStringStartsWith( Urls::url( Urls::LAPOSTA_LIST ), $url );
		$this->assertStringContainsString( 'listconfig=azu4ujdpjw', $url );
		$this->assertSame( '_blank', Urls::target( Urls::LAPOSTA_LIST ) );
	}

	public function test_a_list_url_without_a_list_is_empty_rather_than_the_bare_base(): void {
		$this->assertSame( '', Urls::laposta_list_url( '' ) );
	}

	public function test_the_support_links_are_registered_even_before_they_exist(): void {
		// An unregistered name falls back to _self, so a _blank target is proof
		// the entry is in the registry rather than merely absent.
		$this->assertSame( '_blank', Urls::target( 'support_forum' ) );
		$this->assertSame( '_blank', Urls::target( 'github_issues' ) );
		$this->assertSame( '', Urls::url( 'support_forum' ) );
		$this->assertSame( '', Urls::url( 'github_issues' ) );
	}
}
