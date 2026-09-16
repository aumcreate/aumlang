<?php
/**
 * Resolves which post types and taxonomies are translatable, from settings.
 *
 * @package AumLang
 */

namespace AumLang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for "what can be translated". Drives the
 * `aumlang_translatable_post_types` / `aumlang_translatable_taxonomies` filters
 * from the user's checkbox selection in settings; until the user saves a
 * selection, each filter's own default is kept.
 */
class TranslatableTypes {

	const POST_TYPES_KEY = 'translatable_post_types';
	const TAXONOMIES_KEY = 'translatable_taxonomies';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'aumlang_translatable_post_types', array( $this, 'filter_post_types' ), 5 );
		add_filter( 'aumlang_translatable_taxonomies', array( $this, 'filter_taxonomies' ), 5 );
	}

	/**
	 * Override the translatable post types with the saved selection, if any.
	 *
	 * @param string[] $default Caller's default list.
	 * @return string[]
	 */
	public function filter_post_types( $default ) {
		$saved = $this->saved( self::POST_TYPES_KEY );

		return null === $saved ? $default : $saved;
	}

	/**
	 * Override the translatable taxonomies with the saved selection, if any.
	 *
	 * @param string[] $default Caller's default list.
	 * @return string[]
	 */
	public function filter_taxonomies( $default ) {
		$saved = $this->saved( self::TAXONOMIES_KEY );

		return null === $saved ? $default : $saved;
	}

	/**
	 * Public post types a user may choose to translate (excludes attachments).
	 *
	 * @return array<string, string> name => label.
	 */
	public function available_post_types() {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			// Real, browsable content sets show_in_nav_menus=true (post, page,
			// product…). Media and builder-internal types (attachment, Elementor's
			// library / floating buttons) set it false — skip those.
			if ( 'attachment' === $post_type->name || ! $post_type->show_ui || ! $post_type->show_in_nav_menus ) {
				continue;
			}

			$out[ $post_type->name ] = $post_type->labels->name ? $post_type->labels->name : $post_type->name;
		}

		return $out;
	}

	/**
	 * Public taxonomies a user may choose to translate (excludes post_format).
	 *
	 * @return array<string, string> name => label.
	 */
	public function available_taxonomies() {
		$out = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			// Skip post formats and internal/logistics taxonomies (e.g. Woo's
			// product_shipping_class) — real, browsable taxonomies set
			// show_in_nav_menus, like categories/tags/product_cat/product_brand.
			if ( 'post_format' === $taxonomy->name || ! $taxonomy->show_in_nav_menus ) {
				continue;
			}

			$out[ $taxonomy->name ] = $taxonomy->labels->name ? $taxonomy->labels->name : $taxonomy->name;
		}

		return $out;
	}

	/**
	 * The default post-type selection used until the user saves their own.
	 *
	 * @return string[]
	 */
	public function default_post_types() {
		$available = array_keys( $this->available_post_types() );

		return array_values( array_intersect( array( 'post', 'page' ), $available ) );
	}

	/**
	 * The default taxonomy selection used until the user saves their own.
	 *
	 * @return string[]
	 */
	public function default_taxonomies() {
		return array_keys( $this->available_taxonomies() );
	}

	/**
	 * Currently selected post types (saved selection, or the default).
	 *
	 * @return string[]
	 */
	public function selected_post_types() {
		$saved = $this->saved( self::POST_TYPES_KEY );

		return null === $saved ? $this->default_post_types() : $saved;
	}

	/**
	 * Currently selected taxonomies (saved selection, or the default).
	 *
	 * @return string[]
	 */
	public function selected_taxonomies() {
		$saved = $this->saved( self::TAXONOMIES_KEY );

		return null === $saved ? $this->default_taxonomies() : $saved;
	}

	/**
	 * Read a saved selection array, or null when never configured.
	 *
	 * @param string $key Settings key.
	 * @return string[]|null
	 */
	private function saved( $key ) {
		$settings = get_option( 'aumlang_settings', array() );

		if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
			return null;
		}

		return array_values( $settings[ $key ] );
	}
}
