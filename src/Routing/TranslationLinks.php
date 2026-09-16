<?php
/**
 * Makes translation posts use their language-prefixed canonical URL.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * A translation post has its own native slug (e.g. /about-zh/) which carries no
 * language information. This points every link to a translation at its canonical
 * /{lang}/source-slug/ URL, and 301-redirects direct hits on the native slug, so
 * the request is always language-aware (correct locale, query filtering, AJAX).
 */
class TranslationLinks {

	/**
	 * URL converter.
	 *
	 * @var UrlConverter
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param UrlConverter $urls URL converter.
	 */
	public function __construct( UrlConverter $urls ) {
		$this->urls = $urls;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'post_link', array( $this, 'filter_permalink' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_permalink' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 10, 2 );

		add_action( 'template_redirect', array( $this, 'redirect_native_slug' ) );
	}

	/**
	 * Rewrite a translation post's permalink to its canonical language URL.
	 *
	 * @param string      $url  Permalink.
	 * @param \WP_Post|int $post Post or id.
	 * @return string
	 */
	public function filter_permalink( $url, $post ) {
		/*
		 * Elementor builds its editor iframe URL with get_permalink(). A
		 * translated static front page canonically maps to /{language}/, which
		 * resolves as the site front page rather than the translation's own post.
		 * Keep the native target slug for wp-admin so the editor can preview and
		 * edit the actual translated document.
		 */
		if ( is_admin() || $this->is_elementor_preview() ) {
			return $url;
		}

		$canonical = $this->canonical_url( get_post( $post ) );

		return null !== $canonical ? $canonical : $url;
	}

	/**
	 * 301-redirect a direct hit on a translation's native slug to its canonical
	 * language URL.
	 *
	 * @return void
	 */
	public function redirect_native_slug() {
		// Do not redirect Elementor's editor iframe away from its target post.
		if ( is_admin() || $this->is_elementor_preview() || ! is_singular() ) {
			return;
		}

		$canonical = $this->canonical_url( get_queried_object() );

		if ( null === $canonical ) {
			return;
		}

		$current_path = $this->path_of( $this->current_url() );

		if ( $current_path !== $this->path_of( $canonical ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
	}

	/**
	 * Whether the current front-end request is Elementor's editor preview.
	 *
	 * @return bool
	 */
	private function is_elementor_preview() {
		return isset( $_GET['elementor-preview'] ) && '' !== (string) wp_unslash( $_GET['elementor-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
	}

	/**
	 * The canonical language URL for a translation post, or null if not a
	 * translation (or its source is gone).
	 *
	 * @param mixed $post Possible WP_Post.
	 * @return string|null
	 */
	private function canonical_url( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$source_id = (int) get_post_meta( $post->ID, '_aumlang_source_id', true );
		$lang      = (string) get_post_meta( $post->ID, '_aumlang_language', true );

		if ( ! $source_id || '' === $lang ) {
			return null;
		}

		/*
		 * WordPress stores a static front page as an ordinary page with a slug,
		 * but its public canonical URL is the site root. Keeping the ordinary
		 * source permalink here turns a translated homepage into /ru/home/ and
		 * makes themes render it as a normal page (including title/breadcrumb).
		 */
		if ( $this->is_static_front_page_source( $source_id ) ) {
			return $this->urls->convert( home_url( '/' ), $lang );
		}

		// The source is not a translation, so this does not recurse.
		$source_permalink = get_permalink( $source_id );

		if ( ! $source_permalink ) {
			return null;
		}

		return $this->urls->convert( $source_permalink, $lang );
	}

	/**
	 * Whether an ID is the canonical source of the configured static front page.
	 *
	 * `page_on_front` is filtered to the current translation on the front end,
	 * so resolve that configured page back through its source-id meta first.
	 *
	 * @param int $source_id Canonical source post ID.
	 * @return bool
	 */
	private function is_static_front_page_source( $source_id ) {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		$configured_id = (int) get_option( 'page_on_front' );

		if ( ! $configured_id ) {
			return false;
		}

		$configured_source = (int) get_post_meta( $configured_id, '_aumlang_source_id', true );

		return (int) $source_id === ( $configured_source ? $configured_source : $configured_id );
	}

	/**
	 * Absolute URL of the current request.
	 *
	 * @return string
	 */
	private function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $uri;
	}

	/**
	 * The normalized (untrailingslashed) path of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function path_of( $url ) {
		return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}
}
