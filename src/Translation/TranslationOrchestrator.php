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
	/**
	 * 一轮最多干多少秒。
	 *
	 * 20 秒的来历：常见网关超时是 60 秒（nginx fastcgi_read_timeout 默认值），
	 * 而收工判断在**开始下一块之前**做，所以最坏还会多跑一次调用（上限 60 秒）。
	 * 20 + 60 = 80 还是会超 —— 但那是最坏情况下的单次慢调用，常态是 20~25 秒。
	 * 想再保险就把这个数调小；调大没有意义，网关不会因此更有耐心。
	 */
	const ROUND_SECONDS = 20;

	/**
	 * 这一篇、这个语言的进度存在哪。
	 *
	 * @param int    $source_id   源文章 ID。
	 * @param string $target_lang 目标语言。
	 * @return string
	 */
	private function progress_key( $source_id, $target_lang ) {
		return 'aumlang_prog_' . (int) $source_id . '_' . substr( md5( (string) $target_lang ), 0, 8 );
	}

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

		/*
		 * 🔴 分批：一轮只干固定时长的活，没干完就存下来、让前端再发一次。
		 *
		 * 为什么必须这样：单次调服务商超时 60 秒、一轮最多 60 次调用，所以一个请求
		 * 最坏要跑一小时，而 nginx 默认 fastcgi_read_timeout 是 60 秒。
		 * 2026-10-07 客户看到的 `502 Bad Gateway` 不是翻译出错，是网关等不下去了 ——
		 * 页面只要够长，这个设计下**必然**502，不是运气问题。
		 *
		 * 进度存在 transient 里而不是节点缓存里，因为节点缓存要先有 group，
		 * 而 group 要先有译文文章 —— 首次翻译时它还不存在。
		 */
		$progress_key = $this->progress_key( $source_id, $target_lang );
		$saved        = get_transient( $progress_key );

		if ( is_array( $saved ) ) {
			foreach ( $items as $i => $item ) {
				if ( isset( $saved[ $item['path'] ] ) ) {
					$result_map[ $item['path'] ] = $saved[ $item['path'] ];
					unset( $to_translate[ $i ] );
				}
			}
		}

		if ( ! empty( $to_translate ) ) {
			$this->batch->set_deadline( time() + self::ROUND_SECONDS );

			try {
				$fresh = $this->batch->translate( $provider, array_values( $to_translate ), $source_lang, $target_lang );
			} catch ( \RuntimeException $e ) {
				return TranslationResult::error( $e->getMessage() );
			}

			/*
			 * 🔴 用 batch 交出来的 settled 判「哪些定下来了」，**不要拿结果和原文比**。
			 * 一个两种语言里本来就相同的字符串（专名、数字、单位）会被永远判成没做完，
			 * 前端就会无限循环重发 —— 那比它要修的 502 更糟。
			 */
			$settled = array_flip( $this->batch->settled );
			$keys    = array_keys( $to_translate );

			foreach ( $keys as $position => $i ) {
				if ( $this->batch->incomplete && ! isset( $settled[ $position ] ) ) {
					continue;   /* 这一轮没轮到，留给下一轮 */
				}
				$result_map[ $items[ $i ]['path'] ] = isset( $fresh[ $position ] ) ? $fresh[ $position ] : $items[ $i ]['text'];
			}

			if ( $this->batch->incomplete ) {
				/* 存进度，别动文章 —— 半篇译文不该落盘。 */
				$store = array();
				foreach ( $items as $item ) {
					if ( isset( $result_map[ $item['path'] ] ) ) {
						$store[ $item['path'] ] = $result_map[ $item['path'] ];
					}
				}
				set_transient( $progress_key, $store, DAY_IN_SECONDS );

				$remaining = count( $items ) - count( $store );

				return TranslationResult::partial(
					count( $store ),
					$remaining,
					sprintf(
						/* translators: 1: strings done so far, 2: strings still to go. */
						__( 'Translating… %1$d of %2$d done.', 'aumlang' ),
						count( $store ),
						count( $items )
					)
				);
			}
		}

		/* 翻完了，进度不再需要。 */
		delete_transient( $progress_key );

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

		/*
		 * 🔴 **「成功但页面没变」要说出来。**
		 *
		 * 1.0.17 之后，只是版面标记的字符串不再送去翻译——这治好了卡死，
		 * 但代价是：用了我们还没读的页面构建器的页面，会**很快「翻译成功」而内容原样**。
		 * 一个不说话的成功，比一个失败更难查。所以当跳过的占了压倒多数时，把它写进
		 * 结果消息，一路送到站长眼前。
		 */
		$note = '';
		$skipped_markup = (int) $this->batch->skipped_markup;
		
		if ( $skipped_markup > 0 && $skipped_markup >= count( $to_translate ) ) {
			$note = sprintf(
				/* translators: %d: number of strings that were page-builder markup. */
				_n(
					'%d block of this page is layout markup, not text, so it was left as it is. If the page looks unchanged, it is probably built with a page builder this plugin does not read yet — tell us which one and we will add it.',
					'%d blocks of this page are layout markup, not text, so they were left as they are. If the page looks unchanged, it is probably built with a page builder this plugin does not read yet — tell us which one and we will add it.',
					$skipped_markup,
					'aumlang'
				),
				$skipped_markup
			);
		}
		
		return TranslationResult::ok(
			$target_id,
			count( $to_translate ),
			$reused,
			sprintf( 'Translated %d, reused %d from cache.', count( $to_translate ), $reused ) . ( '' !== $note ? ' ' . $note : '' )
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
