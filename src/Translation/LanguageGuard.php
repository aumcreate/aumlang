<?php
/**
 * Is what came back even in the language we asked for?
 *
 * @package AumLang
 */

namespace AumLang\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects a translation written in a script the target language does not use.
 *
 * # 🔴 Why this exists
 *
 * 2026-10-13, a buyer's site: pages translated from English **into Italian** came back containing
 * Chinese, and retrying produced the same. Their diagnostic report caught the provider in the act —
 * this is one real response, to a request for Italian:
 *
 *     {"content":"[\"プロトタイプPCBアセンブリサービス | 高速・高品質PCBAプロトタイプ …\"]"}
 *
 * **Japanese.** The item count was right, the JSON parsed, nothing threw — and we stored it as the
 * Italian translation. The visitor then reads an Italian page with Japanese headings, and the owner
 * cannot retry his way out of it because the stored value looks like a finished translation.
 *
 * {@see BatchProcessor} already catches the neighbouring failure — the model echoing its input —
 * with the same reasoning: a provider's own checks (item count, JSON shape) pass perfectly on a
 * wrong answer. **Returning the wrong language is the same class and had no check at all.**
 *
 * # Why script, and not language detection
 *
 * Telling Portuguese from Spanish needs a language model and gets it wrong often enough to be
 * dangerous. Telling Japanese from Italian needs the Unicode block, and is never wrong.
 *
 * So this only judges what it can judge: **a script the target language simply never uses.** Italian
 * with kana in it is wrong with certainty; Italian that is really Spanish sails straight through, and
 * that is the correct trade — a check that is sometimes wrong would be worse than none, because
 * rejecting a good translation costs the buyer a retry and teaches everyone to ignore it.
 *
 * ⚠️ **A language this does not know is never rejected.** Not knowing what scripts a language uses is
 * not evidence that the answer is wrong, and guessing would reproduce the exact mistake this guards
 * against. The honest outcome of ignorance is "I cannot tell", never "this is wrong".
 */
class LanguageGuard {

	/**
	 * How much of a text may be in a foreign script before it is judged the wrong language.
	 *
	 * Not zero. A genuine Italian translation can legitimately quote a Japanese product name, and a
	 * German one can cite a Chinese supplier. One such word in a paragraph is ordinary; a heading
	 * that is **entirely** kana is not.
	 */
	const MAX_FOREIGN_RATIO = 0.2;

	/**
	 * Shortest text worth judging, in characters that belong to some script.
	 *
	 * Below this the ratio is noise: a two-character string that happens to be a Han product code
	 * would be "100% foreign" and rejected, which is how a check starts costing more than it saves.
	 */
	const MIN_SCRIPTED = 12;

	/**
	 * Which languages legitimately write in each script.
	 *
	 * Keyed by script; the value is the set of language codes (matched on the part before any `_`
	 * or `-`) that use it. A language missing from every list is simply unknown to this guard.
	 *
	 * @var array<string, string[]>
	 */
	const SCRIPT_LANGS = array(
		'han'      => array( 'zh', 'ja', 'ko', 'yue' ),
		'kana'     => array( 'ja' ),
		'hangul'   => array( 'ko' ),
		'cyrillic' => array( 'ru', 'uk', 'bg', 'sr', 'mk', 'be', 'kk', 'ky', 'mn', 'tg' ),
		'arabic'   => array( 'ar', 'fa', 'ur', 'ps', 'ku', 'sd' ),
		'hebrew'   => array( 'he', 'yi' ),
		'greek'    => array( 'el' ),
		'thai'     => array( 'th' ),
		'devanagari' => array( 'hi', 'mr', 'ne', 'sa' ),
		'hangeul'  => array( 'ko' ),
	);

	/**
	 * Character ranges per script.
	 *
	 * @var array<string, string>
	 */
	const RANGES = array(
		'han'        => '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}',
		'kana'       => '\x{3040}-\x{309F}\x{30A0}-\x{30FF}',
		'hangul'     => '\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}',
		'cyrillic'   => '\x{0400}-\x{04FF}',
		'arabic'     => '\x{0600}-\x{06FF}\x{0750}-\x{077F}',
		'hebrew'     => '\x{0590}-\x{05FF}',
		'greek'      => '\x{0370}-\x{03FF}',
		'thai'       => '\x{0E00}-\x{0E7F}',
		'devanagari' => '\x{0900}-\x{097F}',
	);

	/**
	 * The base language code: `pt_BR` and `pt-BR` both become `pt`.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	private static function base( $lang ) {
		$lang = strtolower( trim( (string) $lang ) );
		$lang = str_replace( '-', '_', $lang );
		$parts = explode( '_', $lang );

		return $parts[0];
	}

	/**
	 * Scripts this language never writes in — or null when the language is unknown here.
	 *
	 * @param string $lang Target language.
	 * @return string[]|null
	 */
	public static function forbidden_scripts( $lang ) {
		$base  = self::base( $lang );
		$known = false;
		$out   = array();

		foreach ( self::SCRIPT_LANGS as $script => $langs ) {
			if ( in_array( $base, $langs, true ) ) {
				$known = true;
			}
		}

		/*
		 * A Latin-script language is not in any of those lists, so "not found" is ambiguous: it means
		 * either "writes in Latin" or "we have never heard of it". Resolve it the only honest way —
		 * require the code to look like a language we ship. {@see \AumLang\Language\LanguageCatalog}
		 * is the list this plugin offers, and anything in it that is not listed above writes Latin.
		 */
		if ( ! $known && ! self::is_known_language( $base ) ) {
			return null;
		}

		foreach ( self::SCRIPT_LANGS as $script => $langs ) {
			if ( ! in_array( $base, $langs, true ) && isset( self::RANGES[ $script ] ) ) {
				$out[] = $script;
			}
		}

		return $out;
	}

	/**
	 * Whether this plugin knows the language at all.
	 *
	 * @param string $base Base language code.
	 * @return bool
	 */
	private static function is_known_language( $base ) {
		if ( ! class_exists( '\AumLang\Language\LanguageCatalog' ) ) {
			return false;
		}

		foreach ( \AumLang\Language\LanguageCatalog::all() as $code => $_info ) {
			if ( self::base( $code ) === $base ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this text is written in a script the target language never uses.
	 *
	 * @param string $text   Candidate translation.
	 * @param string $lang   Target language.
	 * @return bool True when the text is certainly not in the target language.
	 */
	public static function is_wrong_script( $text, $lang ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return false;
		}

		$forbidden = self::forbidden_scripts( $lang );

		if ( null === $forbidden || ! $forbidden ) {
			return false;
		}

		/* Count only characters that belong to some writing system; digits, punctuation and
		   placeholders like %s say nothing about language. */
		$scripted = preg_match_all( '/\p{L}/u', $text );

		if ( $scripted < self::MIN_SCRIPTED ) {
			return false;
		}

		foreach ( $forbidden as $script ) {
			$n = preg_match_all( '/[' . self::RANGES[ $script ] . ']/u', $text );

			if ( $n && ( $n / $scripted ) > self::MAX_FOREIGN_RATIO ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The items of a result that are in the wrong script, as indexes.
	 *
	 * @param string[] $texts Returned translations.
	 * @param string   $lang  Target language.
	 * @return int[]
	 */
	public static function wrong_items( array $texts, $lang ) {
		$out = array();

		foreach ( array_values( $texts ) as $i => $text ) {
			if ( is_string( $text ) && self::is_wrong_script( $text, $lang ) ) {
				$out[] = $i;
			}
		}

		return $out;
	}
}
