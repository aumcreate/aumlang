<?php
/**
 * Request router: determines the current language from the URL.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

use AumLang\Language\Language;
use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the active language for the current request and exposes the set of
 * languages that carry a URL prefix (shared with RewriteRules).
 */
class Router {

	/**
	 * Public query var that carries the resolved language code.
	 */
	const QUERY_VAR = 'aumlang_lang';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $registry;

	/**
	 * Resolved language for this request.
	 *
	 * @var Language|null
	 */
	private $current = null;

	/**
	 * Whether the current language has been resolved yet.
	 *
	 * @var bool
	 */
	private $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $registry Language registry.
	 */
	public function __construct( LanguageRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
	}

	/**
	 * Whitelist the language query var.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Resolve the current language from the request URL, falling back to default.
	 *
	 * @return Language|null
	 */
	public function detect_current_language() {
		$language = $this->language_from_path();

		if ( ! $language ) {
			$language = $this->registry->get_default_language();
		}

		return $language;
	}

	/**
	 * Get the resolved current language (memoized for the request).
	 *
	 * @return Language|null
	 */
	public function get_current_language() {
		if ( ! $this->resolved ) {
			$this->current  = $this->detect_current_language();
			$this->resolved = true;
		}

		return $this->current;
	}

	/**
	 * Get the current language code, or empty string if none.
	 *
	 * @return string
	 */
	public function get_current_code() {
		$language = $this->get_current_language();

		return $language ? $language->code() : '';
	}

	/**
	 * Whether the current request is in the default language.
	 *
	 * @return bool
	 */
	public function is_default_language() {
		$current = $this->get_current_language();
		$default = $this->registry->get_default_language();

		if ( ! $current || ! $default ) {
			return true;
		}

		return $current->code() === $default->code();
	}

	/**
	 * Languages that appear as a URL prefix, keyed by slug => code.
	 *
	 * The default language is excluded unless the settings opt it into a prefix.
	 *
	 * @return array<string, string>
	 */
	public function prefixed_languages() {
		$settings           = get_option( 'aumlang_settings', array() );
		$default_has_prefix = ! empty( $settings['default_language_has_prefix'] );

		$prefixed = array();

		foreach ( $this->registry->get_languages( true ) as $language ) {
			if ( $language->is_default() && ! $default_has_prefix ) {
				continue;
			}

			$prefixed[ $language->slug() ] = $language->code();
		}

		return $prefixed;
	}

	/**
	 * Whether a language code is served under a URL prefix.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function language_has_prefix( $code ) {
		return in_array( $code, $this->prefixed_languages(), true );
	}

	/**
	 * Whether this is a front-end AJAX request (admin-ajax invoked from a public
	 * page, not from wp-admin). Such requests still need language behavior.
	 *
	 * @return bool
	 */
	public function is_frontend_ajax() {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		$referer = wp_get_referer();

		if ( ! $referer ) {
			return false;
		}

		$admin_path   = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
		$referer_path = (string) wp_parse_url( $referer, PHP_URL_PATH );

		return '' === $admin_path || false === strpos( $referer_path, $admin_path );
	}

	/**
	 * The URI to resolve the language from.
	 *
	 * For AJAX requests the URL is /wp-admin/admin-ajax.php (no language prefix),
	 * so the language is taken from the referring page instead — this lets the
	 * language carry into front-end AJAX (e.g. post-grid widgets) automatically.
	 *
	 * @return string
	 */
	private function effective_request_uri() {
		if ( wp_doing_ajax() ) {
			$referer = wp_get_referer();

			if ( $referer ) {
				return $referer;
			}
		}

		return isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Match the first path segment against the prefixed languages.
	 *
	 * Works at any hook timing because it reads the request URI directly rather
	 * than relying on parsed query vars.
	 *
	 * @return Language|null
	 */
	private function language_from_path() {
		$request = $this->effective_request_uri();
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$home    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		$path = trim( $path, '/' );
		$home = trim( $home, '/' );

		// Strip a subdirectory install path so the language slug is the first segment.
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		if ( '' === $path ) {
			return null;
		}

		$segments = explode( '/', $path );
		$first    = sanitize_title( $segments[0] );

		if ( '' === $first ) {
			return null;
		}

		$prefixed = $this->prefixed_languages();

		if ( isset( $prefixed[ $first ] ) ) {
			return $this->registry->get_language( $prefixed[ $first ] );
		}

		return null;
	}
}
