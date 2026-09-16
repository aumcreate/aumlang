<?php
/**
 * Converts URLs between language variants.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, removes, or swaps the language slug prefix on a URL.
 *
 * Used by the language switcher, hreflang output, and canonical generation.
 * Only internal URLs (same host as the site home) are rewritten; external URLs
 * are returned untouched.
 */
class UrlConverter {

	/**
	 * Router (authority on which languages carry a URL prefix).
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Router            $router   Router instance.
	 * @param LanguageRegistry  $registry Language registry.
	 */
	public function __construct( Router $router, LanguageRegistry $registry ) {
		$this->router   = $router;
		$this->registry = $registry;
	}

	/**
	 * Convert a URL to its variant in the target language.
	 *
	 * @param string $url         Source URL (absolute or relative).
	 * @param string $target_lang Target language code.
	 * @return string
	 */
	public function convert( $url, $target_lang ) {
		$stripped = $this->strip_language_prefix( $url );

		return $this->add_language_prefix( $stripped, $target_lang );
	}

	/**
	 * Remove any leading language prefix from a URL.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	public function strip_language_prefix( $url ) {
		$parts = $this->split_url( $url );

		if ( $this->is_external( $parts['origin'] ) ) {
			return $url;
		}

		$rel = $this->relative_path( $parts['path'] );
		$rel = $this->remove_language_segment( $rel );

		return $this->build( $parts, $rel );
	}

	/**
	 * Add a language prefix to a URL (no-op for an unprefixed default language).
	 *
	 * Assumes the URL has no existing language prefix; use convert() to swap.
	 *
	 * @param string $url  Source URL.
	 * @param string $lang Target language code.
	 * @return string
	 */
	public function add_language_prefix( $url, $lang ) {
		$language = $this->registry->get_language( $lang );

		if ( ! $language ) {
			return $url;
		}

		$parts = $this->split_url( $url );

		if ( $this->is_external( $parts['origin'] ) ) {
			return $url;
		}

		$rel = $this->relative_path( $parts['path'] );

		if ( ! $this->router->language_has_prefix( $language->code() ) ) {
			return $this->build( $parts, $rel );
		}

		$prefix = '/' . $language->slug();
		$rel    = ( '/' === $rel ) ? $prefix . '/' : $prefix . $rel;

		return $this->build( $parts, $rel );
	}

	/**
	 * Split a URL into origin, path, query, and fragment pieces.
	 *
	 * @param string $url URL to split.
	 * @return array{origin:string,path:string,query:string,fragment:string}
	 */
	private function split_url( $url ) {
		$parsed = wp_parse_url( $url );

		$origin = '';

		if ( ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) ) {
			$origin = $parsed['scheme'] . '://' . $parsed['host'];

			if ( ! empty( $parsed['port'] ) ) {
				$origin .= ':' . $parsed['port'];
			}
		}

		return array(
			'origin'   => $origin,
			'path'     => isset( $parsed['path'] ) ? $parsed['path'] : '/',
			'query'    => isset( $parsed['query'] ) ? '?' . $parsed['query'] : '',
			'fragment' => isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '',
		);
	}

	/**
	 * Reassemble a URL from its parts and a new relative path.
	 *
	 * @param array  $parts Output of split_url().
	 * @param string $rel   Path relative to the site home (leading slash).
	 * @return string
	 */
	private function build( array $parts, $rel ) {
		$home = $this->home_path();
		$base = ( '/' === $home ) ? '' : $home;

		$path = preg_replace( '#/+#', '/', $base . $rel );

		return $parts['origin'] . $path . $parts['query'] . $parts['fragment'];
	}

	/**
	 * Reduce an absolute path to the part after the site home, leading slash kept.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function relative_path( $path ) {
		$home = $this->home_path();

		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Drop a leading language slug segment if it maps to a prefixed language.
	 *
	 * @param string $rel Relative path (leading slash).
	 * @return string
	 */
	private function remove_language_segment( $rel ) {
		$has_trailing = ( strlen( $rel ) > 1 && '/' === substr( $rel, -1 ) );

		$segments = explode( '/', trim( $rel, '/' ) );
		$prefixed = $this->router->prefixed_languages();

		if ( isset( $segments[0] ) && '' !== $segments[0] && isset( $prefixed[ $segments[0] ] ) ) {
			array_shift( $segments );
		}

		$out = '/' . implode( '/', $segments );

		if ( $has_trailing && '/' !== $out ) {
			$out .= '/';
		}

		return $out;
	}

	/**
	 * Site home path, e.g. "/" for a root install or "/sub" for a subdirectory.
	 *
	 * @return string
	 */
	private function home_path() {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = trim( $path, '/' );

		return '' === $path ? '/' : '/' . $path;
	}

	/**
	 * Whether an origin points to a different host than the site home.
	 *
	 * @param string $origin Scheme://host[:port], or '' for a relative URL.
	 * @return bool
	 */
	private function is_external( $origin ) {
		if ( '' === $origin ) {
			return false;
		}

		$host      = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return $host !== $home_host;
	}
}
