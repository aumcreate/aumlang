<?php
/**
 * Registers /{lang}/ rewrite rules for prefixed languages.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors every core rewrite rule under each language slug prefix, injecting the
 * language query var. Because the prefix is a literal (no capture group), the
 * original rules' $matches indices are preserved.
 */
class RewriteRules {

	/**
	 * Router (authority on which languages carry a URL prefix).
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Constructor.
	 *
	 * @param Router $router Router instance.
	 */
	public function __construct( Router $router ) {
		$this->router = $router;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'rewrite_rules_array', array( $this, 'add_language_rules' ) );

		// Re-flush rules whenever the language set or default changes.
		add_action( 'aumlang_language_registered', array( $this, 'schedule_flush' ) );
		add_action( 'aumlang_language_unregistered', array( $this, 'schedule_flush' ) );
		add_action( 'aumlang_default_language_changed', array( $this, 'schedule_flush' ) );
	}

	/**
	 * Prepend language-prefixed copies of all rewrite rules.
	 *
	 * @param array $rules Existing rewrite rules (pattern => query).
	 * @return array
	 */
	public function add_language_rules( $rules ) {
		$prefixed = $this->router->prefixed_languages();

		if ( empty( $prefixed ) ) {
			return $rules;
		}

		$new = array();

		foreach ( $prefixed as $slug => $code ) {
			foreach ( $rules as $pattern => $query ) {
				$new[ $slug . '/' . $pattern ] = $this->append_lang( $query, $code );
			}

			// Bare /{slug}/ resolves to that language's front page.
			$new[ $slug . '/?$' ] = 'index.php?' . Router::QUERY_VAR . '=' . $code;
		}

		return array_merge( $new, $rules );
	}

	/**
	 * Append the language query var to a rewrite target query string.
	 *
	 * @param string $query Rewrite target, e.g. "index.php?pagename=$matches[1]".
	 * @param string $code  Language code.
	 * @return string
	 */
	private function append_lang( $query, $code ) {
		$separator = ( false !== strpos( $query, '?' ) ) ? '&' : '?';

		return $query . $separator . Router::QUERY_VAR . '=' . $code;
	}

	/**
	 * Flag a one-time rewrite flush on the next init.
	 *
	 * @return void
	 */
	public function schedule_flush() {
		update_option( 'aumlang_flush_rewrite', 1 );
	}
}
