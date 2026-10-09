<?php
/**
 * Outcome of a translation operation.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Simple value object describing what a translate_content() call produced.
 */
class TranslationResult {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * The translation post id (0 when none).
	 *
	 * @var int
	 */
	public $target_id;

	/**
	 * Number of strings sent to the provider.
	 *
	 * @var int
	 */
	public $translated_count;

	/**
	 * Number of strings reused from cache (incremental).
	 *
	 * @var int
	 */
	public $skipped_count;

	/**
	 * Human-readable message (error detail or summary).
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param bool   $success          Success flag.
	 * @param int    $target_id        Translation post id.
	 * @param int    $translated_count Strings translated.
	 * @param int    $skipped_count    Strings reused from cache.
	 * @param string $message          Message.
	 */
	public function __construct( $success, $target_id = 0, $translated_count = 0, $skipped_count = 0, $message = '' ) {
		$this->success          = (bool) $success;
		$this->target_id        = (int) $target_id;
		$this->translated_count = (int) $translated_count;
		$this->skipped_count    = (int) $skipped_count;
		$this->message          = (string) $message;
		$this->done             = true;
	}

	/**
	 * Build a success result.
	 *
	 * @param int    $target_id        Translation post id.
	 * @param int    $translated_count Strings translated.
	 * @param int    $skipped_count    Strings reused from cache.
	 * @param string $message          Message.
	 * @return TranslationResult
	 */
	public static function ok( $target_id, $translated_count = 0, $skipped_count = 0, $message = '' ) {
		return new self( true, $target_id, $translated_count, $skipped_count, $message );
	}

	/**
	 * Build an error result.
	 *
	 * @param string $message Error message.
	 * @return TranslationResult
	 */
	/**
	 * 这一轮是否把整篇翻完了。
	 *
	 * 🔴 为什么需要它：一次点击要翻的量可能远超任何网关的超时。
	 * 实测 2026-10-07：单次调服务商超时 60 秒、一次最多 60 次调用，
	 * 所以一个请求最坏要跑一小时，而 nginx 默认 fastcgi_read_timeout 是 60 秒。
	 * 客户看到的 502 不是运气不好，是这个设计必然的结果。
	 * 所以一轮只干固定时长的活，剩下的靠前端再发一次 —— done=false 就是「还有」。
	 *
	 * @var bool
	 */
	public $done = true;

	/**
	 * 这一轮没翻完：已完成的已经存下来了，调用方应该再发一次。
	 *
	 * @param int    $translated_count 到目前为止翻好的条数。
	 * @param int    $remaining        还剩多少条。
	 * @param string $message          给用户看的话。
	 * @return self
	 */
	public static function partial( $translated_count, $remaining, $message = '' ) {
		$r = new self( true, 0, (int) $translated_count, 0, (string) $message );
		$r->done      = false;
		$r->remaining = (int) $remaining;
		return $r;
	}

	/**
	 * 还剩多少条没翻（只在 done=false 时有意义）。
	 *
	 * @var int
	 */
	public $remaining = 0;

	public static function error( $message ) {
		return new self( false, 0, 0, 0, $message );
	}
}
