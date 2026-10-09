<?php
/**
 * Points links inside a translated page at the translated version of their target.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

use AumLang\Content\ContentLinker;
use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * 🔴 为什么非做不可。
 *
 * 翻译只换文字，不换链接。于是日文页里写着 `<a href="/about-us/">`，访客点下去
 * 回到了英文页 —— 他读了一半被踢出语言版本，而站长不会收到任何报错。
 *
 * WordPress 自己生成的固定链接有过滤器可挂（`post_link` 那几个），但**正文里写死的
 * 链接不经过它们**。编辑器里插入的链接、构建器按钮上的链接，全是写死的。
 * 也就是说：站上内链越多，这个洞越大，而它在任何多语言站上都存在。
 *
 * 为什么在渲染时改、而不是翻译时就写进去：
 * 甲页链到乙页，翻甲页时乙页可能还没翻 —— 那一刻没有可指的目标，只能留原链接。
 * 等乙页翻好了，甲页存着的链接已经错了，而没有任何东西会回头去修它。
 * 放在渲染时就不会有这个时间差：乙页一翻好，甲页的链接自己就对了。
 */
class ContentLinkRewriter {

	/**
	 * Translation links.
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
	 * Router, for working out the current language.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * 本次请求内的 URL → URL 结果，省掉重复查库。
	 *
	 * @var array<string, string>
	 */
	private $seen = array();

	/**
	 * Constructor.
	 *
	 * @param ContentLinker    $linker    Translation links.
	 * @param LanguageRegistry $languages Languages.
	 * @param Router           $router    Router.
	 */
	public function __construct( ContentLinker $linker, LanguageRegistry $languages, Router $router ) {
		$this->linker    = $linker;
		$this->languages = $languages;
		$this->router    = $router;
	}

	/**
	 * Register the filters.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( array( 'the_content', 'the_excerpt', 'widget_text' ) as $hook ) {
			add_filter( $hook, array( $this, 'rewrite' ), 20 );
		}
	}

	/**
	 * Rewrite internal links to their translation in the current language.
	 *
	 * @param string $html Rendered content.
	 * @return string
	 */
	public function rewrite( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'href' ) ) {
			return $html;
		}

		$lang = $this->current_language();

		if ( '' === $lang ) {
			return $html;   /* 默认语言下无事可做 */
		}

		return (string) preg_replace_callback(
			'/\bhref\s*=\s*(["\'])(.*?)\1/i',
			function ( $m ) use ( $lang ) {
				return 'href=' . $m[1] . $this->map( $m[2], $lang ) . $m[1];
			},
			$html
		);
	}

	/**
	 * The language being viewed, or '' when it is the default one.
	 *
	 * @return string
	 */
	private function current_language() {
		if ( $this->router->is_default_language() ) {
			return '';
		}

		return (string) $this->router->get_current_code();
	}

	/**
	 * One URL, mapped to its translation when there is one.
	 *
	 * @param string $url  Original URL.
	 * @param string $lang Target language.
	 * @return string
	 */
	private function map( $url, $lang ) {
		$key = $lang . '|' . $url;

		if ( isset( $this->seen[ $key ] ) ) {
			return $this->seen[ $key ];
		}

		$this->seen[ $key ] = $url;

		/* 站外链接、锚点、mailto、tel 一概不动。 */
		if ( '' === $url || preg_match( '/^(#|mailto:|tel:|javascript:|data:)/i', $url ) ) {
			return $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $url;
		}

		/*
		 * 🔴 只缓存「URL → 文章 ID」，不缓存「文章 → 译文」。
		 *
		 * 前者只有改固定链接结构时才会变，缓存很安全；后者会在站长翻好一页的那一刻
		 * 变化，缓存了就等于把「渲染时才算」的好处又丢掉 —— 甲页的链接会继续指着
		 * 原文页，直到缓存过期。这两件事的变化频率不一样，所以不能一起缓存。
		 */
		$cache_key = 'aumlang_url_' . md5( $url );
		$post_id   = get_transient( $cache_key );

		if ( false === $post_id ) {
			$post_id = (int) url_to_postid( $url );
			set_transient( $cache_key, $post_id, DAY_IN_SECONDS );
		}

		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return $url;
		}

		$translation = $this->linker->get_translation( $post_id, $lang );

		if ( ! $translation ) {
			/*
			 * 这一页还没翻。保留原链接 —— 指向一个不存在的译文页，
			 * 比把访客送回原文页更糟。
			 */
			return $url;
		}

		$mapped = get_permalink( $translation );

		if ( ! $mapped ) {
			return $url;
		}

		$this->seen[ $key ] = $mapped;

		return $mapped;
	}
}
