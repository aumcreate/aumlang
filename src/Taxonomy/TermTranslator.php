<?php
/**
 * Translates taxonomy terms and keeps a translated post on translated terms.
 *
 * @package AumLang
 */

namespace AumLang\Taxonomy;

use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * When a post is translated (via the `aumlang_after_translate` action), this
 * translates each of the source post's terms into the target language — creating
 * and linking a translated term the first time — and assigns those translated
 * terms to the translation post. So a translated article lands in the translated
 * category/tag, not the source's.
 */
class TermTranslator {

	/**
	 * Term linker.
	 *
	 * @var TermLinker
	 */
	private $linker;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Batch processor.
	 *
	 * @var BatchProcessor
	 */
	private $batch;

	/**
	 * Constructor.
	 *
	 * @param TermLinker       $linker    Term linker.
	 * @param LanguageRegistry $languages Language registry.
	 * @param ProviderRegistry $providers Provider registry.
	 * @param BatchProcessor   $batch     Batch processor.
	 */
	public function __construct(
		TermLinker $linker,
		LanguageRegistry $languages,
		ProviderRegistry $providers,
		BatchProcessor $batch
	) {
		$this->linker    = $linker;
		$this->languages = $languages;
		$this->providers = $providers;
		$this->batch     = $batch;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'aumlang_after_translate', array( $this, 'on_after_translate' ), 10, 3 );
	}

	/**
	 * Sync a freshly translated post's terms.
	 *
	 * @param int    $source_id   Source post id.
	 * @param int    $target_id   Translation post id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	public function on_after_translate( $source_id, $target_id, $target_lang ) {
		$this->sync_post_terms( (int) $source_id, (int) $target_id, (string) $target_lang );
	}

	/**
	 * Translate the source post's terms and assign the translations to the target.
	 *
	 * @param int    $source_id   Source post id.
	 * @param int    $target_id   Translation post id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	public function sync_post_terms( $source_id, $target_id, $target_lang ) {
		$provider = $this->providers->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			return;
		}

		$source = get_post( $source_id );

		if ( ! $source ) {
			return;
		}

		foreach ( $this->translatable_taxonomies( $source->post_type ) as $taxonomy ) {
			$source_term_ids = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $source_term_ids ) || empty( $source_term_ids ) ) {
				continue;
			}

			$target_term_ids = array();

			foreach ( $source_term_ids as $source_term_id ) {
				$translated = $this->translate_term( (int) $source_term_id, $taxonomy, $target_lang );

				if ( $translated ) {
					$target_term_ids[] = (int) $translated;
				}
			}

			if ( ! empty( $target_term_ids ) ) {
				wp_set_object_terms( $target_id, $target_term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Ensure a term is translated into a language, returning the translated id.
	 *
	 * Reuses an existing translation unless $force. Hierarchical parents are
	 * translated first so the translated term keeps the translated parent.
	 *
	 * @param int    $source_term_id Source term id.
	 * @param string $taxonomy       Taxonomy name.
	 * @param string $target_lang    Target language code.
	 * @param bool   $force          Re-translate even if a translation exists.
	 * @return int|null Translated term id, or null on failure.
	 */
	public function translate_term( $source_term_id, $taxonomy, $target_lang, $force = false ) {
		$source_term_id = $this->linker->get_source_id( (int) $source_term_id );
		$existing       = $this->linker->get_translation( $source_term_id, $target_lang );

		if ( $existing && ! $force ) {
			return (int) $existing;
		}

		$term = get_term( $source_term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$provider = $this->providers->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			return null;
		}

		$source_lang = $this->source_lang();

		if ( '' === $source_lang || $source_lang === $target_lang ) {
			return null;
		}

		// Translate the parent first (hierarchical taxonomies).
		$parent_target = 0;

		if ( $term->parent ) {
			$translated_parent = $this->translate_term( (int) $term->parent, $taxonomy, $target_lang, $force );

			if ( $translated_parent ) {
				$parent_target = (int) $translated_parent;
			}
		}

		$has_description = '' !== trim( (string) $term->description );
		$texts           = array( $term->name );

		if ( $has_description ) {
			$texts[] = $term->description;
		}

		try {
			$translated = $this->batch->translate( $provider, $texts, $source_lang, $target_lang );
		} catch ( \RuntimeException $e ) {
			return null;
		}

		$name        = ( isset( $translated[0] ) && '' !== $translated[0] ) ? $translated[0] : $term->name;
		$description = ( $has_description && isset( $translated[1] ) ) ? $translated[1] : '';
		$slug        = sanitize_title( ( $term->slug ? $term->slug : sanitize_title( $term->name ) ) . '-' . $target_lang );

		$target_id = $existing ? (int) $existing : 0;

		if ( $target_id ) {
			wp_update_term(
				$target_id,
				$taxonomy,
				array(
					'name'        => $name,
					'description' => $description,
					'parent'      => $parent_target,
				)
			);
		} else {
			$result = wp_insert_term(
				$name,
				$taxonomy,
				array(
					'slug'        => $slug,
					'description' => $description,
					'parent'      => $parent_target,
				)
			);

			if ( is_wp_error( $result ) ) {
				// A term with this name already exists — reuse it.
				if ( 'term_exists' === $result->get_error_code() ) {
					$target_id = (int) $result->get_error_data();
				} else {
					return null;
				}
			} else {
				$target_id = (int) $result['term_id'];
			}
		}

		if ( ! $target_id ) {
			return null;
		}

		update_term_meta( $target_id, '_aumlang_source_term', $source_term_id );
		update_term_meta( $target_id, '_aumlang_language', $target_lang );

		$this->linker->link( $source_term_id, $target_id, $taxonomy, $target_lang );
		$this->linker->set_status( $source_term_id, $target_lang, 'machine' );

		return $target_id;
	}

	/**
	 * Public, translatable taxonomies for a post type (skips post_format),
	 * intersected with the user's "translatable content" selection.
	 *
	 * @param string $post_type Post type.
	 * @return string[]
	 */
	private function translatable_taxonomies( $post_type ) {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name || ! $taxonomy->public ) {
				continue;
			}

			$taxonomies[] = $taxonomy->name;
		}

		/** This filter is documented in src/Admin/TermAdminColumns.php */
		$allowed = (array) apply_filters( 'aumlang_translatable_taxonomies', $taxonomies );

		return array_values( array_intersect( $taxonomies, $allowed ) );
	}

	/**
	 * Default (source) language code.
	 *
	 * @return string
	 */
	private function source_lang() {
		$default = $this->languages->get_default_language();

		return $default ? $default->code() : '';
	}
}
