<?php
/**
 * Makes the core WordPress sitemap multilingual.
 *
 * @package AumLang
 */

namespace AumLang\Seo;

use AumLang\Language\LanguageRegistry;
use AumLang\Routing\UrlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Includes every language version in wp-sitemap.xml with the correct URL.
 *
 * WordPress core only exposes per-entry filtering for posts, so rather than
 * excluding-then-re-adding, this lets all posts (sources and translations) into
 * the sitemap query and rewrites each entry's URL: sources to the default-
 * language URL, translations to their /{lang}/source-slug URL.
 */
class SitemapManager {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * URL converter.
	 *
	 * @var UrlConverter
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Language registry.
	 * @param UrlConverter     $urls      URL converter.
	 */
	public function __construct( LanguageRegistry $languages, UrlConverter $urls ) {
		$this->languages = $languages;
		$this->urls      = $urls;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'include_all_languages' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'localize_entry' ), 10, 2 );
	}

	/**
	 * Translatable post types (shared definition).
	 *
	 * @return string[]
	 */
	private function post_types() {
		return (array) apply_filters( 'aumlang_translatable_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Keep translation posts in the sitemap query (bypass our language filter).
	 *
	 * @param array  $args      WP_Query args.
	 * @param string $post_type Post type being listed.
	 * @return array
	 */
	public function include_all_languages( $args, $post_type ) {
		if ( in_array( $post_type, $this->post_types(), true ) ) {
			$args['aumlang_skip_lang'] = true;
		}

		return $args;
	}

	/**
	 * Rewrite each sitemap entry's URL to its correct language URL.
	 *
	 * @param array         $entry Sitemap entry (with 'loc').
	 * @param \WP_Post|null $post  Post for this entry (null for the home entry).
	 * @return array
	 */
	public function localize_entry( $entry, $post ) {
		if ( ! $post instanceof \WP_Post || empty( $entry['loc'] ) ) {
			return $entry;
		}

		if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
			return $entry;
		}

		$source_id = get_post_meta( $post->ID, '_aumlang_source_id', true );

		if ( $source_id ) {
			$lang             = (string) get_post_meta( $post->ID, '_aumlang_language', true );
			$source_permalink = get_permalink( (int) $source_id );

			if ( $source_permalink && '' !== $lang ) {
				$entry['loc'] = $this->urls->convert( $source_permalink, $lang );
			}

			return $entry;
		}

		$default = $this->languages->get_default_language();

		if ( $default ) {
			$entry['loc'] = $this->urls->convert( $entry['loc'], $default->code() );
		}

		return $entry;
	}
}
