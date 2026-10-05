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
	 * 一次 translate() 里最多允许多少次 provider 调用。
	 *
	 * 🔴 2026-10-05 一个真实站点（Bold Page Builder）上首页翻译永远不返回。
	 * 原因不是慢，是**拆分是递归的**：一块判为回声 → 重试 max_attempts 次 →
	 * 二分成两半 → 每一半再各自重试、再各自拆。一块 N 条最坏要 max_attempts × (2N−1)
	 * 次调用；N=20、max_attempts=2 时约 78 次，而每次调用可能等几十秒。
	 * 页面上这样的块有几十个，于是一个 AJAX 请求跑几十分钟，PHP 中途被杀，
	 * 浏览器那边既等不到成功也等不到错误。
	 *
	 * 所以这里卡的是**调用次数**，不是单次时长：CoreAiProvider 走 WordPress 自带的
	 * AI 客户端，单次超时不在我们手里。超了预算就抛错——**一个说得清的失败，
	 * 比一个永远 pending 的请求有用得多**。
	 */
	private $max_calls;

	/** @var int 本次 translate() 已经发出的 provider 调用数。 */
	private $calls = 0;

	/**
	 * 上一次 translate() 里，因为「整条只是标记」而没有发出去的条数。
	 *
	 * 它不是错误。但当它占压倒多数时，说明这个页面的正文其实是某个我们还没读的
	 * 页面构建器的版面——**翻译会「成功」，而页面几乎没变**。那种成功比失败更难查，
	 * 所以这个数要一路传到站长眼前。
	 */
	public $skipped_markup = 0;

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Attempts per chunk (>= 1).
	 */
	public function __construct( $max_attempts = 2, $max_calls = 60 ) {
		$this->max_attempts = max( 1, (int) $max_attempts );
		$this->max_calls    = max( 1, (int) $max_calls );
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

		$this->calls = 0;
		$this->skipped_markup = 0;

		/*
		 * 🔴 **整条只是短代码或标记的字符串，一个字也不该发出去。**
		 *
		 * 页面构建器（Bold Page Builder、WPBakery…）把版面留在 post_content 里，
		 * 没有适配的解析器时它们会被当成正文抽出来。模型照原样返回是**正确行为**，
		 * 但 is_echo() 把「原样返回」判成回声 → 重试 → 递归拆分。
		 * 也就是说：没适配编辑器这件事，不是「少翻一点」，而是**直接触发那条爆炸路径**。
		 *
		 * 判据是「去掉短代码和标签之后还剩不剩字母」——`[button text="Buy now"]` 这种
		 * 属性里有真文案的，strip_shortcodes 会把整条抹掉、剩不下字母，所以也会被放行；
		 * 那是**保守方向**：少翻一条，好过让整页翻不完。
		 */
		$send = array();
		foreach ( $texts as $idx => $text ) {
			$bare = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
			if ( preg_match( '/\p{L}/u', $bare ) ) {
				$send[ $idx ] = $text;
			}
		}
		$this->skipped_markup = count( $texts ) - count( $send );


		if ( empty( $send ) ) {
			return $texts;
		}

		if ( empty( $texts ) ) {
			return array();
		}

		$size = $provider->supports_batch() ? max( 1, $provider->max_batch_size() ) : 1;

		$results = array();

		foreach ( array_chunk( array_values( $send ), $size ) as $chunk ) {
			$results = array_merge( $results, $this->translate_chunk( $provider, $chunk, $source_lang, $target_lang, $options ) );
		}

		/* 把译文按原下标放回，没发出去的位置保持原文。 */
		$out = $texts;
		foreach ( array_keys( $send ) as $n => $idx ) {
			if ( array_key_exists( $n, $results ) ) {
				$out[ $idx ] = $results[ $n ];
			}
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
		if ( self::is_echo( array_values( $send ), $results ) ) {
			throw new \RuntimeException( 'Provider returned every string unchanged.' );
		}

		return $out;
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
				if ( ++$this->calls > $this->max_calls ) {
					/*
					 * 预算用完。这里**不是**超时，是递归拆分把调用次数撑爆了——
					 * 抛出去让上层把它变成一条站长看得懂的错误，而不是让请求继续跑到
					 * PHP 被杀、浏览器永远 pending。
					 */
					throw new \RuntimeException(
						esc_html(
							sprintf(
								/* translators: %d: number of provider requests allowed for one translation run. */
								__( 'Stopped after %d translation requests for this one page. This usually means the page contains a lot of text the model keeps returning unchanged — often a page builder\'s own markup. Translate the page in smaller pieces, or tell us which builder this site uses.', 'aumlang' ),
								(int) $this->max_calls
							)
						)
					);
				}

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
