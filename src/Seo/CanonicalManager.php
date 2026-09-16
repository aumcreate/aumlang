<?php
/**
 * Corrects the canonical URL for translated singular pages.
 *
 * @package AumLang
 */

namespace AumLang\Seo;

use AumLang\Content\ContentLinker;
use AumLang\Routing\Router;
use AumLang\Routing\UrlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress derives the canonical URL from a post's own permalink, which has no
 * language prefix and may use the translation's own slug. This rewrites it to
 * the stable source-slug URL in the current language (e.g. /zh/source-slug/).
 */
class CanonicalManager {

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
	 * Constructor.
	 *
	 * @param Router        $router Router.
	 * @param ContentLinker $linker Content linker.
	 * @param UrlConverter  $urls   URL converter.
	 */
	public function __construct( Router $router, ContentLinker $linker, UrlConverter $urls ) {
		$this->router = $router;
		$this->linker = $linker;
		$this->urls   = $urls;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 10, 2 );
	}

	/**
	 * Rewrite a post's canonical URL into the current language.
	 *
	 * @param string   $canonical The canonical URL.
	 * @param \WP_Post $post      The post.
	 * @return string
	 */
	public function filter_canonical( $canonical, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $canonical;
		}

		$source_id = $this->linker->get_source_id( $post->ID );

		if ( $this->is_static_front_page_source( $source_id ) ) {
			return $this->urls->convert( home_url( '/' ), $this->router->get_current_code() );
		}

		$source_permalink = get_permalink( $source_id );

		if ( ! $source_permalink ) {
			return $canonical;
		}

		return $this->urls->convert( $source_permalink, $this->router->get_current_code() );
	}

	/**
	 * Whether an ID belongs to the configured static front-page group.
	 *
	 * @param int $source_id Canonical source post ID.
	 * @return bool
	 */
	private function is_static_front_page_source( $source_id ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		$configured_id = (int) get_option( 'page_on_front' );

		return $configured_id
			&& (int) $source_id === $this->linker->get_source_id( $configured_id );
	}
}
