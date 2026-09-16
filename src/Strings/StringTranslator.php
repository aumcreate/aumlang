<?php
/**
 * Serves and captures string translations through the gettext filters.
 *
 * @package AumLang
 */

namespace AumLang\Strings;

use AumLang\Routing\Router;

defined( 'ABSPATH' ) || exit;

/**
 * On the front end in a non-default language this does two things via the
 * gettext filters:
 *
 *  - SERVE: return AumLang's stored translation for a string (overriding /
 *    supplementing the theme's .mo), so any __() string can be translated with
 *    no theme work. Always on.
 *
 *  - CAPTURE (discovery mode, off by default): collect strings the visitor's
 *    page actually renders, so they appear in the admin to translate. Two
 *    filters keep this to genuinely visible text, not the thousands of __()
 *    calls WordPress core / builders fire during init:
 *      1. the WordPress core ("default") text domain is skipped entirely —
 *         core already ships its own translation for every locale;
 *      2. a candidate is only registered if its text actually appears in the
 *         rendered page HTML (checked via an output buffer at the end of the
 *         request) — so __()'d-but-not-displayed labels are dropped.
 */
class StringTranslator {

	/**
	 * Maximum source length to capture (skip huge blobs).
	 */
	const MAX_CAPTURE_LENGTH = 2000;

	/**
	 * Option flag that enables discovery (capture) mode.
	 */
	const DISCOVERY_OPTION = 'aumlang_string_discovery';

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private $repository;

	/**
	 * Whether serve/capture applies this request (null until resolved).
	 *
	 * @var bool|null
	 */
	private $active = null;

	/**
	 * Whether discovery (capture) mode is enabled.
	 *
	 * @var bool
	 */
	private $discovery = false;

	/**
	 * Translations map for the current language ("domain\x1fsource" => text).
	 *
	 * @var array<string, string>
	 */
	private $translations = array();

	/**
	 * Known string keys (already registered), to avoid re-capturing.
	 *
	 * @var array<string, bool>
	 */
	private $known = array();

	/**
	 * Strings seen this request, pending an appears-in-HTML check.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private $candidates = array();


	/**
	 * Constructor.
	 *
	 * @param Router           $router     Router.
	 * @param StringRepository $repository String repository.
	 */
	public function __construct( Router $router, StringRepository $repository ) {
		$this->router     = $router;
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'gettext', array( $this, 'filter_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_gettext_with_context' ), 10, 4 );
		add_action( 'template_redirect', array( $this, 'maybe_hook_capture' ) );
	}

	/**
	 * Translate / collect a string via gettext.
	 *
	 * @param string $translation Current translation (from .mo or original).
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext( $translation, $text, $domain ) {
		$this->ensure_loaded();

		if ( ! $this->active ) {
			return $translation;
		}

		$key = $domain . StringRepository::KEY_SEP . $text;

		if ( isset( $this->translations[ $key ] ) ) {
			return $this->translations[ $key ];
		}

		// Collect a capture candidate (filtered against the HTML at request end).
		// Only strings that no .mo already translated ($translation === $text):
		// anything a theme/plugin/core .mo handles is covered for free.
		if ( $this->discovery
			&& 'default' !== $domain
			&& (string) $translation === (string) $text
			&& ! isset( $this->known[ $key ] )
			&& ! isset( $this->candidates[ $key ] )
			&& $this->is_capturable( $text ) ) {
			$this->candidates[ $key ] = array( $domain, $text );
		}

		return $translation;
	}

	/**
	 * Translate a string with gettext context (context ignored for lookup).
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source text.
	 * @param string $context     Gettext context (unused).
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_gettext_with_context( $translation, $text, $context, $domain ) {
		return $this->filter_gettext( $translation, $text, $domain );
	}

	/**
	 * Ask WordPress for the rendered page so captured candidates can be filtered
	 * to those that actually render. Front-end page views only, discovery only.
	 *
	 * The buffer belongs to WordPress: since 6.9 core opens one around the
	 * template whenever something has subscribed to it, hands the output to this
	 * filter, and closes it itself. This plugin opens no buffer of its own, so
	 * there is none of its making left on the stack for anything else to trip on.
	 *
	 * @return void
	 */
	public function maybe_hook_capture() {
		$this->ensure_loaded();

		// Only an admin browsing the front end populates the list, so a
		// left-on discovery toggle can't let visitors pollute it.
		if ( ! $this->active || ! $this->discovery || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::capture_supported() ) {
			return;
		}

		// Added on template_redirect, which is before the template is included
		// and therefore before core decides whether to open the buffer at all.
		add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'capture_from_output' ) );
	}

	/**
	 * Whether this WordPress can hand the rendered template to a filter.
	 *
	 * @return bool
	 */
	public static function capture_supported() {
		return function_exists( 'wp_should_output_buffer_template_for_enhancement' );
	}

	/**
	 * Register only the candidates whose text appears in the rendered HTML.
	 *
	 * Returns the markup untouched: this reads the page, it does not rewrite it.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string
	 */
	public function capture_from_output( $html ) {
		if ( empty( $this->candidates ) ) {
			return $html;
		}

		$lang    = $this->router->get_current_code();
		$visible = $this->visible_text( $html );

		foreach ( $this->candidates as $entry ) {
			if ( $this->appears_in_text( $entry[1], $visible ) ) {
				$this->repository->register_source( $entry[0], $entry[1], $lang );
			}
		}

		$this->candidates = array();

		return $html;
	}

	/**
	 * Load serve map (+ known keys when capturing) for the current language, once.
	 *
	 * @return void
	 */
	private function ensure_loaded() {
		if ( null !== $this->active ) {
			return;
		}

		$this->active = ! ( ( is_admin() && ! $this->router->is_frontend_ajax() ) || $this->router->is_default_language() );

		if ( ! $this->active ) {
			return;
		}

		$this->discovery = (bool) get_option( self::DISCOVERY_OPTION, false );
		$lang            = $this->router->get_current_code();

		if ( $this->discovery ) {
			$state              = $this->repository->get_state( $lang );
			$this->translations = $state['translations'];
			$this->known        = $state['known'];
		} else {
			$this->translations = $this->repository->get_map( $lang );
		}
	}

	/**
	 * Whether a source string is worth capturing.
	 *
	 * @param string $text Source text.
	 * @return bool
	 */
	private function is_capturable( $text ) {
		$trimmed = trim( $text );

		if ( '' === $trimmed || strlen( $text ) > self::MAX_CAPTURE_LENGTH ) {
			return false;
		}

		// Must contain a letter (skip purely numeric / symbolic).
		if ( ! preg_match( '/\p{L}/u', $trimmed ) ) {
			return false;
		}

		// Skip URLs and bare domains/paths (not user-facing text).
		if ( preg_match( '#^https?://#i', $trimmed ) ) {
			return false;
		}

		if ( false === strpos( $trimmed, ' ' ) && preg_match( '#^/?[\w.-]+\.[a-z]{2,}(/\S*)?$#i', $trimmed ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract the visible text of a page: drop <script>/<style> blocks and all
	 * tags/attributes (so class names, JS config and markup don't count as
	 * "displayed"), then decode entities so raw source text matches.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function visible_text( $html ) {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Whether a source string is displayed in the page's visible text.
	 *
	 * Plain strings must appear verbatim. printf-style strings ("%s comments",
	 * "Logged in as %s.") never appear verbatim because the placeholder is
	 * filled in at render time, so we require their longest literal segment to
	 * appear instead.
	 *
	 * @param string $source  Source text.
	 * @param string $visible Visible page text.
	 * @return bool
	 */
	private function appears_in_text( $source, $visible ) {
		$needle = trim( $source );

		if ( '' === $needle ) {
			return false;
		}

		if ( false !== strpos( $visible, $needle ) ) {
			return true;
		}

		// No placeholder => a miss is definitive.
		if ( false === strpos( $needle, '%' ) ) {
			return false;
		}

		// Split off printf placeholders (%s, %d, %1$s, %.2f, %%) and match on the
		// longest literal run — short fragments would match almost anything.
		$segments = preg_split( '/%(?:\d+\$)?[-+ 0]*[\d.]*[bcdeEfFgGosuxX]|%%/', $needle );
		$longest  = '';

		foreach ( (array) $segments as $segment ) {
			$segment = trim( $segment );

			if ( strlen( $segment ) > strlen( $longest ) ) {
				$longest = $segment;
			}
		}

		return strlen( $longest ) >= 4 && false !== strpos( $visible, $longest );
	}
}
