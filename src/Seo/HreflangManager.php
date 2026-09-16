<?php
/**
 * Outputs hreflang alternate links for the current page.
 *
 * @package AumLang
 */

namespace AumLang\Seo;

use AumLang\Content\ContentLinker;
use AumLang\Language\Language;
use AumLang\Language\LanguageRegistry;
use AumLang\Routing\Router;
use AumLang\Routing\UrlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Emits <link rel="alternate" hreflang="..."> tags (plus x-default) so search
 * engines understand the language variants of each page.
 *
 * Singular pages list per-translation URLs; other pages (home, archives, search)
 * map the current URL into each language by prefix.
 */
class HreflangManager {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * URL converter.
	 *
	 * @var UrlConverter
	 */
	private $urls;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param Router           $router    Router.
	 * @param ContentLinker    $linker    Content linker.
	 * @param UrlConverter     $urls      URL converter.
	 * @param LanguageRegistry $languages Language registry.
	 */
	public function __construct( Router $router, ContentLinker $linker, UrlConverter $urls, LanguageRegistry $languages ) {
		$this->router    = $router;
		$this->linker    = $linker;
		$this->urls      = $urls;
		$this->languages = $languages;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'output' ), 1 );
	}

	/**
	 * Print the hreflang link tags.
	 *
	 * @return void
	 */
	public function output() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		$alternates = $this->build_alternates();

		if ( count( $alternates ) < 2 ) {
			return;
		}

		$default = $this->languages->get_default_language();

		foreach ( $alternates as $hreflang => $url ) {
			$this->print_tag( $hreflang, $url );
		}

		if ( $default && isset( $alternates[ $this->hreflang_value( $default ) ] ) ) {
			$this->print_tag( 'x-default', $alternates[ $this->hreflang_value( $default ) ] );
		}
	}

	/**
	 * Build a map of hreflang value => absolute URL for the current page.
	 *
	 * @return array<string, string>
	 */
	private function build_alternates() {
		$languages = $this->languages->get_languages( true );

		if ( is_singular() ) {
			return $this->singular_alternates( $languages );
		}

		return $this->generic_alternates( $languages );
	}

	/**
	 * Alternates for a singular post: source permalink mapped into each language
	 * that has a version. Using the source slug keeps every language URL stable
	 * and consistent with the switcher and canonical (e.g. /zh/source-slug/),
	 * regardless of the translation post's own slug.
	 *
	 * @param Language[] $languages Active languages.
	 * @return array<string, string>
	 */
	private function singular_alternates( array $languages ) {
		$object = get_queried_object();

		if ( ! $object instanceof \WP_Post ) {
			return array();
		}

		$source_permalink = get_permalink( $this->linker->get_source_id( $object->ID ) );

		if ( ! $source_permalink ) {
			return array();
		}

		$translations = $this->linker->get_all_translations( $object->ID );
		$alternates   = array();

		foreach ( $languages as $language ) {
			if ( ! isset( $translations[ $language->code() ] ) ) {
				continue;
			}

			$alternates[ $this->hreflang_value( $language ) ] = $this->urls->convert( $source_permalink, $language->code() );
		}

		return $alternates;
	}

	/**
	 * Alternates for non-singular pages: current URL mapped into each language.
	 *
	 * @param Language[] $languages Active languages.
	 * @return array<string, string>
	 */
	private function generic_alternates( array $languages ) {
		$current    = $this->current_url();
		$alternates = array();

		foreach ( $languages as $language ) {
			$alternates[ $this->hreflang_value( $language ) ] = $this->urls->convert( $current, $language->code() );
		}

		return $alternates;
	}

	/**
	 * Build the absolute URL of the current request.
	 *
	 * @return string
	 */
	private function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $uri;
	}

	/**
	 * Print a single alternate link tag.
	 *
	 * @param string $hreflang hreflang value.
	 * @param string $url      Absolute URL.
	 * @return void
	 */
	private function print_tag( $hreflang, $url ) {
		printf(
			'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
			esc_attr( $hreflang ),
			esc_url( $url )
		);
	}

	/**
	 * Derive a BCP-47 hreflang value from a language (locale preferred).
	 *
	 * @param Language $language Language.
	 * @return string
	 */
	private function hreflang_value( Language $language ) {
		$locale = $language->locale();

		if ( '' !== $locale ) {
			return str_replace( '_', '-', $locale );
		}

		return $language->code();
	}
}
