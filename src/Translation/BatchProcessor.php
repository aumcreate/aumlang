<?php
/**
 * Splits translation work into provider-sized batches with simple retry.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

use AumLang\Translation\Provider\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Chunks a flat list of strings by the provider's max batch size and translates
 * each chunk, retrying once on failure. If a model returns an incomplete
 * batch, the failed chunk is split and retried in smaller pieces. This keeps
 * a malformed model response from blocking an otherwise valid translation.
 * Returns translations in input order.
 */
class BatchProcessor {

	/**
	 * Number of attempts per chunk before giving up.
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Attempts per chunk (>= 1).
	 */
	public function __construct( $max_attempts = 2 ) {
		$this->max_attempts = max( 1, (int) $max_attempts );
	}

	/**
	 * Translate a list of strings, preserving order.
	 *
	 * @param ProviderInterface $provider    Provider.
	 * @param string[]          $texts       Strings to translate (a flat list).
	 * @param string            $source_lang Source language code.
	 * @param string            $target_lang Target language code.
	 * @param array             $options     Provider options.
	 * @return string[]
	 * @throws \RuntimeException If a chunk and its smaller fallback batches fail.
	 */
	public function translate( ProviderInterface $provider, array $texts, $source_lang, $target_lang, array $options = array() ) {
		$texts = array_values( $texts );

		if ( empty( $texts ) ) {
			return array();
		}

		$size = $provider->supports_batch() ? max( 1, $provider->max_batch_size() ) : 1;

		$results = array();

		foreach ( array_chunk( $texts, $size ) as $chunk ) {
			$results = array_merge( $results, $this->translate_chunk( $provider, $chunk, $source_lang, $target_lang, $options ) );
		}

		/*
		 * 🔴 **Last line of defence against an echo.**
		 *
		 * `translate_chunk()` also checks, and on an echo it retries and then splits the chunk — which is
		 * worth doing, because a model that echoes a batch of four often translates the same strings one
		 * at a time. But a chunk of **one** cannot be judged (a proper noun legitimately comes back
		 * unchanged), so once the split reaches single items an echo would sail through and be stored as
		 * the translation.
		 *
		 * Checking the finished set closes that: it is judged as a whole, where "nothing changed at all"
		 * is unambiguous. Throwing here means the caller keeps the source text **on purpose** rather than
		 * believing it translated something.
		 */
		if ( self::is_echo( $texts, $results ) ) {
			throw new \RuntimeException( 'Provider returned every string unchanged.' );
		}

		return $results;
	}

	/**
	 * Translate one chunk with retry.
	 *
	 * @param ProviderInterface $provider    Provider.
	 * @param string[]          $chunk       Chunk of strings.
	 * @param string            $source_lang Source language code.
	 * @param string            $target_lang Target language code.
	 * @param array             $options     Provider options.
	 * @return string[]
	 * @throws \RuntimeException If every attempt, including smaller fallback
	 *                            batches, fails.
	 */
	/**
	 * Did every string come back exactly as it went in?
	 *
	 * @param string[] $in  What was sent.
	 * @param string[] $out What came back.
	 * @return bool
	 */
	private static function is_echo( array $in, array $out ) {
		if ( count( $in ) !== count( $out ) ) {
			return false;
		}

		$comparable = 0;

		foreach ( array_values( $in ) as $i => $text ) {
			$source = trim( (string) $text );
			$result = trim( (string) ( array_values( $out )[ $i ] ?? '' ) );

			// Strings with no letters (numbers, punctuation, a bare url) are the same in every
			// language, so they say nothing about whether translation happened.
			if ( ! preg_match( '/\p{L}/u', $source ) ) {
				continue;
			}

			++$comparable;

			if ( $source !== $result ) {
				return false;
			}
		}

		return $comparable > 1;
	}

	private function translate_chunk( ProviderInterface $provider, array $chunk, $source_lang, $target_lang, array $options ) {
		$attempt   = 0;
		$last_error = null;

		while ( $attempt < $this->max_attempts ) {
			++$attempt;

			try {
				$result = $provider->translate( $chunk, $source_lang, $target_lang, $options );

				/*
				 * 🔴 **The model sometimes echoes the input, and nothing else notices.**
				 *
				 * A provider checks that the number of items came back right — and an echo passes that
				 * check perfectly. The untranslated strings are then stored *as the translation*: the
				 * buyer gets a page in the target language whose fields are still in the source one, with
				 * no error anywhere and nothing to retry from. Measured 2026-09-05 against DeepSeek: the
				 * same four strings echoed on one call and translated on the next, so it is not something
				 * a better prompt alone removes.
				 *
				 * Every item identical is the signal. One item identical is ordinary — a proper noun, a
				 * product code, a string already in the target language — so a single-item chunk is left
				 * alone; a whole chunk coming back untouched is not something a real translation does.
				 *
				 * Treated as a failed attempt so the existing retry runs. If the retries are also echoes
				 * the exception propagates, and the callers that copy-without-translating end up leaving
				 * the source text — the same outcome, but arrived at deliberately.
				 */
				if ( count( $chunk ) > 1 && self::is_echo( $chunk, $result ) ) {
					throw new \RuntimeException( 'Provider returned the input unchanged.' );
				}

				return $result;
			} catch ( \RuntimeException $e ) {
				$last_error = $e;
			}
		}

		/*
		 * Chat models occasionally combine adjacent strings or omit an item even
		 * when explicitly asked for JSON of a fixed length. Retrying the exact
		 * same large request is not enough in that case. Split it after the normal
		 * retries so each response has less structure for the model to lose. A
		 * single item cannot be mapped to the wrong source string because the
		 * provider still verifies its response count before returning it.
		 */
		if ( count( $chunk ) > 1 ) {
			$middle = (int) ceil( count( $chunk ) / 2 );

			return array_merge(
				$this->translate_chunk( $provider, array_slice( $chunk, 0, $middle ), $source_lang, $target_lang, $options ),
				$this->translate_chunk( $provider, array_slice( $chunk, $middle ), $source_lang, $target_lang, $options )
			);
		}

		throw new \RuntimeException(
			esc_html(
				'Translation failed after ' . $this->max_attempts . ' attempts: '
				. ( $last_error ? $last_error->getMessage() : 'unknown error' )
			)
		);
	}
}
