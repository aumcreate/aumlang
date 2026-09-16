<?php
/**
 * Language-prefixes term archive URLs.
 *
 * @package AumLang
 */

namespace AumLang\Taxonomy;

use AumLang\Routing\UrlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * A translated term archive lives under its language prefix (e.g.
 * /zh/category/uncategorized-zh/). This filters `term_link` so a translated
 * term's URL carries its own language prefix; source terms keep the default
 * (unprefixed) URL.
 */
class TermLinks {

	/**
	 * URL converter.
	 *
	 * @var UrlConverter
	 */
	private $converter;

	/**
	 * Constructor.
	 *
	 * @param UrlConverter $converter URL converter.
	 */
	public function __construct( UrlConverter $converter ) {
		$this->converter = $converter;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'term_link', array( $this, 'localize' ), 10, 3 );
	}

	/**
	 * Prefix a term archive URL with the term's language.
	 *
	 * @param string   $url      Term archive URL.
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy name.
	 * @return string
	 */
	public function localize( $url, $term, $taxonomy ) {
		if ( ! $term instanceof \WP_Term ) {
			return $url;
		}

		$lang = (string) get_term_meta( $term->term_id, '_aumlang_language', true );

		if ( '' === $lang ) {
			return $url;
		}

		return $this->converter->convert( $url, $lang );
	}
}
