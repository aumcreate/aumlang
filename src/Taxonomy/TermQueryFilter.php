<?php
/**
 * Hides terms of other languages from front-end term listings.
 *
 * @package AumLang
 */

namespace AumLang\Taxonomy;

use AumLang\Routing\Router;

defined( 'ABSPATH' ) || exit;

/**
 * The taxonomy counterpart to the post QueryFilter: on the front end in a
 * non-default language, term listings (category widgets, tag clouds,
 * wp_list_categories, menus) show only the current language's terms —
 * excluding other languages' translated terms and source terms that have a
 * translation here (the translated term is shown instead). A source term with
 * no translation in this language stays visible as a fallback.
 */
class TermQueryFilter {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Term linker.
	 *
	 * @var TermLinker
	 */
	private $linker;

	/**
	 * Constructor.
	 *
	 * @param Router     $router Router.
	 * @param TermLinker $linker Term linker.
	 */
	public function __construct( Router $router, TermLinker $linker ) {
		$this->router = $router;
		$this->linker = $linker;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Front-end requests and front-end AJAX both need term filtering.
		if ( ! is_admin() || $this->router->is_frontend_ajax() ) {
			add_filter( 'get_terms_args', array( $this, 'filter_args' ), 10, 2 );
		}
	}

	/**
	 * Inject the current language's hidden term ids into a term query's exclude.
	 *
	 * @param array    $args       get_terms() arguments.
	 * @param string[] $taxonomies Taxonomies being queried.
	 * @return array
	 */
	public function filter_args( $args, $taxonomies ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $args;
		}

		if ( is_admin() && ! $this->router->is_frontend_ajax() ) {
			return $args;
		}

		if ( $this->router->is_default_language() ) {
			return $args;
		}

		$hide = $this->linker->get_terms_to_hide_for_language( $this->router->get_current_code() );

		if ( empty( $hide ) ) {
			return $args;
		}

		$existing = isset( $args['exclude'] ) ? $args['exclude'] : array();

		if ( ! is_array( $existing ) ) {
			$existing = array_filter( array_map( 'intval', explode( ',', (string) $existing ) ) );
		} else {
			$existing = array_map( 'intval', $existing );
		}

		$args['exclude'] = array_values( array_unique( array_merge( $existing, $hide ) ) );

		return $args;
	}
}
