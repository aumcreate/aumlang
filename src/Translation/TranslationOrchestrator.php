<?php
/**
 * Orchestrates translating a piece of content into a target language.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

use AumLang\Builders\MetaContentParser;
use AumLang\Builders\ParserRegistry;
use AumLang\Content\ContentLinker;
use AumLang\Content\NodeTranslationRepository;
use AumLang\Content\SourceHash;
use AumLang\Language\LanguageRegistry;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline: pick parser -> extract nodes -> translate (title + content + excerpt)
 * -> rebuild content -> create/update the translation post -> link and mark status.
 *
 * Node-level caching makes it incremental: a node whose source text is unchanged
 * reuses its cached translation instead of calling the AI again.
 */
class TranslationOrchestrator {

	/**
	 * Parser registry.
	 *
	 * @var ParserRegistry
	 */
	private $parsers;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Batch processor.
	 *
	 * @var BatchProcessor
	 */
	private $batch;

	/**
	 * Node translation cache.
	 *
	 * @var NodeTranslationRepository
	 */
	private $nodes;

	/**
	 * Constructor.
	 *
	 * @param ParserRegistry            $parsers   Parser registry.
	 * @param ProviderRegistry          $providers Provider registry.
	 * @param ContentLinker             $linker    Content linker.
	 * @param LanguageRegistry          $languages Language registry.
	 * @param BatchProcessor            $batch     Batch processor.
	 * @param NodeTranslationRepository $nodes     Node translation cache.
	 */
	public function __construct(
		ParserRegistry $parsers,
		ProviderRegistry $providers,
		ContentLinker $linker,
		LanguageRegistry $languages,
		BatchProcessor $batch,
		NodeTranslationRepository $nodes
	) {
		$this->parsers   = $parsers;
		$this->providers = $providers;
		$this->linker    = $linker;
		$this->languages = $languages;
		$this->batch     = $batch;
		$this->nodes     = $nodes;
	}

	/**
	 * Translate one source post into a target language.
	 *
	 * @param int    $source_id   Source post id.
	 * @param string $target_lang Target language code.
	 * @param bool   $force       Re-translate even unchanged nodes (overrides still kept).
	 * @return TranslationResult
	 */
	public function translate_content( $source_id, $target_lang, $force = false ) {
		$source      = get_post( $source_id );
		$target_lang = strtolower( trim( (string) $target_lang ) );

		if ( ! $source ) {
			return TranslationResult::error( 'Source post not found.' );
		}

		$default = $this->languages->get_default_language();
		$source_lang = $default ? $default->code() : '';

		if ( '' === $target_lang || $target_lang === $source_lang ) {
			return TranslationResult::error( 'Target language must differ from the source language.' );
		}

		if ( ! $this->languages->is_registered( $target_lang ) ) {
			return TranslationResult::error( sprintf( 'Language "%s" is not registered.', $target_lang ) );
		}

		$provider = $this->providers->get_active();

		if ( ! $provider ) {
			return TranslationResult::error( 'No active translation provider.' );
		}

		if ( ! $provider->is_configured() ) {
			return TranslationResult::error( sprintf( 'Provider "%s" is not configured.', $provider->get_id() ) );
		}

		$parser = $this->parsers->get_parser_for( $source_id );

		if ( ! $parser ) {
			return TranslationResult::error( 'No parser available for this content.' );
		}

		$nodes = $parser->extract( $source_id );

		// Build the ordered list of translatable items, each with a stable path.
		$items   = array();
		$items[] = array( 'path' => 'title', 'text' => (string) $source->post_title );

		foreach ( $nodes as $node ) {
			$items[] = array( 'path' => $node->path, 'text' => $node->text );
		}

		if ( '' !== trim( (string) $source->post_excerpt ) ) {
			$items[] = array( 'path' => 'excerpt', 'text' => (string) $source->post_excerpt );
		}

		// Compare against cached node translations to find what actually changed.
		$group = $this->linker->get_group_uuid( $source_id );
		$cache = $group ? $this->nodes->get_map( $group, $target_lang ) : array();

		$result_map   = array();
		$to_translate = array();
		$reused       = 0;

		foreach ( $items as $i => $item ) {
			$hash             = hash( 'sha256', $item['text'] );
			$items[ $i ]['hash'] = $hash;
			$cached           = isset( $cache[ $item['path'] ] ) ? $cache[ $item['path'] ] : null;

			$is_override = $cached && ! empty( $cached['is_override'] );
			$unchanged   = $cached && ! $force && $cached['source_text_hash'] === $hash;

			if ( $cached && ( $is_override || $unchanged ) ) {
				$result_map[ $item['path'] ] = $cached['translated_text'];
				++$reused;
			} else {
				$to_translate[ $i ] = $item['text'];
			}
		}

		if ( ! empty( $to_translate ) ) {
			try {
				$fresh = $this->batch->translate( $provider, array_values( $to_translate ), $source_lang, $target_lang );
			} catch ( \RuntimeException $e ) {
				return TranslationResult::error( $e->getMessage() );
			}

			$position = 0;
			foreach ( array_keys( $to_translate ) as $i ) {
				$result_map[ $items[ $i ]['path'] ] = isset( $fresh[ $position ] ) ? $fresh[ $position ] : $items[ $i ]['text'];
				++$position;
			}
		}

		$node_translations = array();
		foreach ( $nodes as $node ) {
			$node_translations[ $node->path ] = $result_map[ $node->path ];
		}

		$new_content = $parser->rebuild( $source_id, $node_translations );
		$new_title   = isset( $result_map['title'] ) ? $result_map['title'] : $source->post_title;
		$new_excerpt = isset( $result_map['excerpt'] ) ? $result_map['excerpt'] : '';

		$target_id = $this->upsert_translation_post( $source, $target_lang, $new_title, $new_content, $new_excerpt );

		if ( ! $target_id ) {
			return TranslationResult::error( 'Failed to create the translation post.' );
		}

		$this->linker->link( $source_id, $target_id, $target_lang, $this->object_type( $source ) );
		$this->linker->set_status( $source_id, $target_lang, 'machine', $this->source_hash( $source ) );

		// Meta-based builders (Elementor) write their translated content to meta.
		if ( $parser instanceof MetaContentParser ) {
			$parser->persist_meta( $source_id, $target_id, $node_translations );
		}

		// Persist the node cache (group exists now that the link is in place).
		$group = $this->linker->get_group_uuid( $source_id );

		if ( $group ) {
			foreach ( $items as $item ) {
				$this->nodes->upsert( $group, $target_lang, $item['path'], $item['hash'], $result_map[ $item['path'] ] );
			}
		}

		/**
		 * Fires after a piece of content has been translated.
		 *
		 * @param int    $source_id   Source post id.
		 * @param int    $target_id   Translation post id.
		 * @param string $target_lang Target language code.
		 */
		do_action( 'aumlang_after_translate', $source_id, $target_id, $target_lang );

		return TranslationResult::ok(
			$target_id,
			count( $to_translate ),
			$reused,
			sprintf( 'Translated %d, reused %d from cache.', count( $to_translate ), $reused )
		);
	}

	/**
	 * Create or update the translation post for a source/language.
	 *
	 * @param \WP_Post $source      Source post.
	 * @param string   $target_lang Target language code.
	 * @param string   $title       Translated title.
	 * @param string   $content     Translated content.
	 * @param string   $excerpt     Translated excerpt.
	 * @return int Translation post id, or 0 on failure.
	 */
	private function upsert_translation_post( $source, $target_lang, $title, $content, $excerpt ) {
		$existing_id = $this->linker->get_translation( $source->ID, $target_lang );

		$postarr = array(
			'post_type'      => $source->post_type,
			'post_status'    => $source->post_status,
			'post_title'     => $title,
			'post_content'   => $content,
			'post_excerpt'   => $excerpt,
			'post_parent'    => $source->post_parent,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
		);

		if ( $existing_id && $existing_id !== (int) $source->ID ) {
			$postarr['ID'] = $existing_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			// Give the translation a stable ASCII slug (source slug + language)
			// rather than letting WordPress derive one from the translated title.
			$base_slug           = $source->post_name ? $source->post_name : sanitize_title( $source->post_title );
			$postarr['post_name'] = sanitize_title( $base_slug . '-' . $target_lang );

			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) || ! $result ) {
			return 0;
		}

		update_post_meta( $result, '_aumlang_source_id', (int) $source->ID );
		update_post_meta( $result, '_aumlang_language', $target_lang );

		/*
		 * **The featured image is not language.**
		 *
		 * Nothing else copies it: the custom-field integration only carries keys the user has registered,
		 * and nobody registers `_thumbnail_id` because it is not a field they authored. So a translated
		 * post arrived with no featured image — every card, archive and share preview for the translated
		 * half of the site fell back to a placeholder, and the cause is invisible from the editor because
		 * the *source* post looks fine.
		 *
		 * Copied, not translated: the same picture is the right picture in every language. A site that
		 * genuinely needs a different image per language sets one on the translation, and the guard below
		 * leaves it alone from then on.
		 */
		$thumbnail = (int) get_post_meta( (int) $source->ID, '_thumbnail_id', true );

		if ( $thumbnail && ! get_post_meta( (int) $result, '_thumbnail_id', true ) ) {
			update_post_meta( (int) $result, '_thumbnail_id', $thumbnail );
		}

		return (int) $result;
	}

	/**
	 * Map a post to a content object type.
	 *
	 * @param \WP_Post $source Source post.
	 * @return string
	 */
	private function object_type( $source ) {
		return 'post';
	}

	/**
	 * Compute a hash of the source's translatable fields for staleness tracking.
	 *
	 * @param \WP_Post $source Source post.
	 * @return string
	 */
	private function source_hash( $source ) {
		return SourceHash::of( $source );
	}
}
