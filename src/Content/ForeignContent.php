<?php
/**
 * Translations for a page rendered outside the query that asked for it.
 *
 * @package AumLang
 */

namespace AumLang\Content;

use AumLang\Routing\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Swaps in the translation when a theme renders one post's content inside another's request.
 *
 * # 🔴 The hole this closes
 *
 * {@see \AumLang\Routing\QueryFilter::swap_singular_translation()} starts with
 * `! $query->is_main_query()` and returns. That is correct for what it does — it replaces the post
 * the page *is* — but it means **a page rendered as part of another page is never swapped**.
 *
 * Every builder-driven theme does exactly that. The pattern, reduced:
 *
 *     $id      = <some id or slug from a theme option>;
 *     $page    = get_post( $id );
 *     $content = apply_filters( 'the_content', $page->post_content );
 *
 * `get_post()` runs no `WP_Query`, so `pre_get_posts` and `the_posts` never fire and nothing this
 * plugin had hooked could see it. The visitor gets a translated article wrapped in a header and
 * footer that are still in the source language, on **every page of the site**, and the site owner
 * cannot fix it from our screens: the entry is in the list, they translate it, and the front end does
 * not change. Measured 2026-10-12 on a live site — the footer page was translated and the footer
 * still rendered English.
 *
 * # Why `the_content` and not the post lookup
 *
 * There is no filter on `get_post()`. Themes reach the content by half a dozen routes —
 * `get_post()`, `WP_Query`, `get_posts()`, a stored option holding raw markup — and hooking each one
 * means knowing which theme is installed. But they almost all end at the same place, because
 * `the_content` is how WordPress tells everyone "this is body copy": shortcodes, embeds, `wpautop`
 * and every content plugin hang off it. So that is where this listens, and it does not care how the
 * caller got there.
 *
 * ⚠️ **A theme that calls only `do_shortcode()` and never `the_content` is still not covered.** That
 * is not a silent failure: {@see \AumLang\Content\CoverageCheck} compares the rendered page against
 * the source and reports the leftover text, so it shows up as a gap rather than as nothing.
 *
 * # Matching on the content itself
 *
 * The filter is handed a string and nothing else — no ID, no post. So identity comes from the string:
 * `md5()` of the exact content, looked up against the source posts that have a translation in the
 * current language. An exact hash match cannot collide with a different page in any way that matters;
 * two posts with byte-identical content are interchangeable by definition.
 *
 * The current post's own content is skipped before any of this, so the main loop — already handled
 * upstream — costs one string comparison and nothing else.
 */
class ForeignContent {

	/**
	 * Transient prefix for the per-hash resolution cache.
	 */
	const CACHE_PREFIX = 'aumlang_fc_';

	/**
	 * How long a resolution (hit or miss) stays cached.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Content shorter than this is not worth a lookup.
	 *
	 * A global block is a block; an excerpt or a one-line notice is not what this is for, and the
	 * shorter the string the likelier two unrelated posts share it.
	 */
	const MIN_LENGTH = 80;

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Links a source post to its translations.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Translation rows, for the one query the linker does not expose.
	 *
	 * @var TranslationRepository
	 */
	private $repository;

	/**
	 * Hash → translated post ID, for this request.
	 *
	 * @var array<string, array<string, int>>
	 */
	private $resolved = array();

	/**
	 * Guards against a translation's own content being looked up again.
	 *
	 * @var bool
	 */
	private $swapping = false;

	/**
	 * Constructor.
	 *
	 * @param Router                $router     Router.
	 * @param ContentLinker         $linker     Content linker.
	 * @param TranslationRepository $repository Translation rows.
	 */
	public function __construct( Router $router, ContentLinker $linker, TranslationRepository $repository ) {
		$this->router     = $router;
		$this->linker     = $linker;
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		/*
		 * 🔴 失效钩子要**无条件**挂，而且必须在下面那两个 return 之前。
		 *
		 * 改源页面发生在后台、默认语言下 —— 正是下面两个 return 命中的情形。
		 * 把 flush 挂在它们后面，等于「改了内容但缓存永不更新」：前台继续按旧哈希
		 * 匹配，站长改完页脚看不到变化，而且查不出原因（页面是新的，缓存是旧的，
		 * 两边都「正常」）。缓存最长 6 小时，所以这种错会以「有时对有时不对」出现。
		 */
		add_action( 'save_post', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'aumlang_after_translate', array( __CLASS__, 'flush' ) );

		if ( is_admin() && ! $this->router->is_frontend_ajax() ) {
			return;
		}

		if ( $this->router->is_default_language() ) {
			return;
		}

		/*
		 * Priority 1: before `do_shortcode` (11), before `wpautop` (10) and before anything that
		 * rewrites the markup. What we hand back has to go through the same pipeline the source
		 * content would have, or the translation renders differently from the original.
		 */
		add_filter( 'the_content', array( $this, 'swap' ), 1 );
	}

	/**
	 * Replace foreign source content with its translation.
	 *
	 * @param string $content Post content being filtered.
	 * @return string
	 */
	public function swap( $content ) {
		if ( $this->swapping || ! is_string( $content ) || strlen( $content ) < self::MIN_LENGTH ) {
			return $content;
		}

		/*
		 * The post this request is about is already the translation — QueryFilter swapped it. Compare
		 * and leave, so the common case never reaches the database.
		 */
		$current = get_post();

		if ( $current instanceof \WP_Post && $current->post_content === $content ) {
			return $content;
		}

		$target = $this->resolve( md5( $content ), $this->router->get_current_code() );

		if ( ! $target ) {
			return $content;
		}

		$translation = get_post( $target );

		if ( ! $translation instanceof \WP_Post || '' === trim( $translation->post_content ) ) {
			return $content;
		}

		return $translation->post_content;
	}

	/**
	 * The translated post whose source has this content hash, or 0.
	 *
	 * Misses are cached as `0` on purpose. A theme that renders an untranslated global block does it
	 * on every page of the site; without a negative cache that is one index build per page view
	 * forever, and the cost of being wrong here is paid by every visitor.
	 *
	 * 🔴 语言是参数，不是从 router 现取的。
	 *
	 * 第一版在这里自己调 `$this->router->get_current_code()`，于是这个方法只有在
	 * 「正在处理一个该语言的前台请求」时才给得出正确答案 —— 测试里直接调它永远返回 0，
	 * 而 0 和「没有译文」是同一个值，看不出区别。隐式依赖让一个方法无法单独证明对错；
	 * 把它变成参数之后，调用方只有一个地方要想清楚语言从哪来，而这里可以被直接检验。
	 *
	 * @param string $hash md5 of the content.
	 * @param string $lang Language code to resolve in.
	 * @return int
	 */
	private function resolve( $hash, $lang ) {
		if ( '' === $lang ) {
			return 0;
		}

		if ( isset( $this->resolved[ $lang ][ $hash ] ) ) {
			return $this->resolved[ $lang ][ $hash ];
		}

		$key = self::CACHE_PREFIX . $lang . '_' . $hash;

		$cached = get_transient( $key );

		if ( false !== $cached ) {
			$this->resolved[ $lang ][ $hash ] = (int) $cached;

			return (int) $cached;
		}

		$target = 0;

		foreach ( $this->index( $lang ) as $source_hash => $translation_id ) {
			if ( $source_hash === $hash ) {
				$target = (int) $translation_id;
				break;
			}
		}

		set_transient( $key, $target, self::CACHE_TTL );
		$this->resolved[ $lang ][ $hash ] = $target;

		return $target;
	}

	/**
	 * Hash of each source post's content → the id of its translation in this language.
	 *
	 * Built from the posts that **already have a translation**, which is a far smaller set than "all
	 * posts" on every site this matters to, and shrinks the work to the only rows that could produce
	 * a hit anyway.
	 *
	 * @param string $lang Language code.
	 * @return array<string, int>
	 */
	private function index( $lang ) {
		$key   = self::CACHE_PREFIX . 'idx_' . $lang;
		$index = get_transient( $key );

		if ( is_array( $index ) ) {
			return $index;
		}

		$index = array();

		foreach ( $this->repository->get_source_ids_with_language( $lang ) as $source_id ) {
			$source_id = (int) $source_id;
			$target_id = (int) $this->linker->get_translation( $source_id, $lang );

			if ( ! $target_id || $target_id === $source_id ) {
				continue;
			}

			$content = get_post_field( 'post_content', $source_id );

			if ( ! is_string( $content ) || strlen( $content ) < self::MIN_LENGTH ) {
				continue;
			}

			$index[ md5( $content ) ] = $target_id;
		}

		set_transient( $key, $index, self::CACHE_TTL );

		return $index;
	}

	/**
	 * Drop every cached resolution for every language.
	 *
	 * 🔴 Editing a source page changes its hash, which makes every cached answer about it wrong —
	 * a miss that is now a hit, or a hit pointing at the previous wording. Translating a page for the
	 * first time does the same. So the caches are dropped whenever either happens, and this is called
	 * from the places that write.
	 *
	 * @return void
	 */
	public static function flush() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting the cache itself; there is no API for "every transient with this prefix".
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}
}
