<?php
/**
 * Flags for languages.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a locale into a flag.
 *
 * # 🔴 A flag is not a language
 *
 * Worth stating because it decides the markup: Belgium has three official languages and one flag,
 * Spanish is spoken in twenty countries, and a flag on its own tells a screen reader nothing. So the
 * flag is **always decorative** (`aria-hidden`) and the language name is always what gets announced.
 * A switcher showing flags *only* still carries the name for assistive technology.
 *
 * # Where the country comes from
 *
 * From the **locale**, never the language code. `en` is either 🇬🇧 or 🇺🇸 and there is no correct
 * answer; `en_US` and `en_GB` are unambiguous. The locale is already stored per language, so this needs
 * no guessing and no table of exceptions.
 *
 * # What draws it: bundled SVGs, and why not emoji
 *
 * The obvious implementation is the Unicode regional indicator pair — 🇨🇳 is two code points and no
 * asset at all. It was the first implementation here, and it fails in two ways that are not fixable
 * from inside this class:
 *
 * - 🔴 **Windows ships no flag emoji font.** It renders the pair as the two letters "CN". More than
 *   half the desktop web sees letters where the flags are supposed to be.
 * - 🔴 **WordPress rewrites emoji into images hosted on s.w.org.** That covers Windows, but at the
 *   price of a third-party request per flag on every page view — the visitor's IP goes to a CDN they
 *   never chose — and a broken image on any site with no route to the internet. Measured 2026-09-05
 *   on a local install: `1f1e8-1f1f3.svg`, failing to load, next to every flag. And plenty of
 *   optimisation plugins switch the emoji script off, which silently puts Windows back to letters.
 *
 * So the flags are **files in this plugin**: `src/assets/flags/xx.svg`, from flag-icons 7.2.3 (MIT),
 * unmodified, 4x3, one per ISO 3166-1 country. Every platform draws the same flag, nothing is fetched
 * from anywhere else, and a site with no internet works. Only the flags a page actually shows are
 * requested, so the 2.8MB on disk is not 2.8MB on the wire — a two-language page fetches two files of
 * a few KB each.
 *
 * ⚠️ **`aumlang_flag_html` still wraps the result**, so a site can swap in its own images without
 * touching the switcher.
 */
class Flags {

	/**
	 * The ISO 3166-1 alpha-2 country in a locale, or '' when there is none.
	 *
	 * @param string $locale e.g. `zh_CN`, `pt-BR`, `en`.
	 * @return string Upper-case two-letter country code.
	 */
	public static function country( $locale ) {
		$locale = str_replace( '-', '_', trim( (string) $locale ) );

		if ( '' === $locale || false === strpos( $locale, '_' ) ) {
			return '';
		}

		$parts = explode( '_', $locale );

		foreach ( array_slice( $parts, 1 ) as $part ) {
			// Skip a script subtag such as the `Hans` in `zh_Hans_CN`; the country is the 2-letter one.
			if ( preg_match( '/^[A-Za-z]{2}$/', $part ) ) {
				return strtoupper( $part );
			}
		}

		return '';
	}

	/**
	 * The URL of the flag file for a country, or '' when there is no such file.
	 *
	 * ⚠️ The `file_exists` is not defensive noise. flag-icons covers the ISO 3166-1 list, but a locale
	 * can carry a two-letter subtag that is not a country at all, and a site can register one. Emitting
	 * an `<img>` for a file that is not there gives every visitor a broken image; returning '' lets the
	 * caller fall back to the language's name, which is what it does.
	 *
	 * @param string $country Two-letter country code.
	 * @return string
	 */
	public static function url( $country ) {
		$country = strtolower( trim( (string) $country ) );

		if ( ! preg_match( '/^[a-z]{2}$/', $country ) ) {
			return '';
		}

		if ( ! file_exists( AUMLANG_DIR . 'src/assets/flags/' . $country . '.svg' ) ) {
			return '';
		}

		return AUMLANG_URL . 'src/assets/flags/' . $country . '.svg';
	}

	/**
	 * The flag markup for a locale.
	 *
	 * @param string $locale Locale.
	 * @param string $label  The language's name, for assistive technology and as a title.
	 * @return string HTML, or '' when there is no flag to draw.
	 */
	public static function html( $locale, $label = '' ) {
		$country = self::country( $locale );
		$url     = self::url( $country );

		/*
		 * The `alt` is empty on purpose, and the span is `aria-hidden`: this image says nothing a screen
		 * reader needs. The language's name is emitted alongside it by the switcher — see the note at the
		 * top of this class about a flag not being a language.
		 */
		$html = '' === $url
			? ''
			: '<span class="aml-flag" aria-hidden="true" data-country="' . esc_attr( $country ) . '">'
				. '<img src="' . esc_url( $url ) . '" alt="" width="24" height="18" loading="lazy" decoding="async">'
				. '</span>';

		/**
		 * Filters the flag markup for a locale.
		 *
		 * The switcher only ever calls this, so a different set of flags — a client's own artwork, a
		 * different style, images from a CDN the site already uses — is a filter rather than a change to
		 * the switcher.
		 *
		 * ⚠️ Whatever is returned must stay **decorative**: keep `aria-hidden="true"`. The language name
		 * is what a screen reader should announce, because a flag is a country and not a language.
		 *
		 * @since 1.0.6
		 *
		 * @param string $html    Default markup (may be empty).
		 * @param string $locale  Locale.
		 * @param string $country Two-letter country code, '' when it could not be determined.
		 * @param string $label   The language's name.
		 */
		return (string) apply_filters( 'aumlang_flag_html', $html, $locale, $country, $label );
	}
}
