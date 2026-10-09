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
	public static function partial( $translated_count, $remaining, $message = '', array $pending = array(), array $diag = array() ) {
		$r = new self( true, 0, (int) $translated_count, 0, (string) $message );
		$r->done      = false;
		$r->remaining = (int) $remaining;
		$r->pending   = $pending;
		$r->diag      = $diag;
		return $r;
	}

	/**
	 * 还没翻成的原文样本（截断过），只给诊断用。
	 *
	 * 🔴 为什么要带着它走：一轮轮卡住不动时，光知道「还剩 16 条」没用 ——
	 * 16 条是产品型号、是网址、还是普通句子，对应的修法完全不同。看不到它们
	 * 就只能猜，而猜出来的修法会改错地方。
	 *
	 * @var string[]
	 */
	public $pending = array();

	/**
	 * 这一轮的仪表读数：发了几条、打了几次服务商、最后一条失败原因。
	 *
	 * 🔴 「还剩 2 条」和「这一轮 25 秒」摆在一起说不通时，缺的就是这一段。
	 * 没有它只能靠推断，而推断在这件事上已经错过三次。
	 *
	 * @var array<string, mixed>
	 */
	public $diag = array();

	/**
	 * 翻完之后仍然和原文一字不差的正文（截断过）。
	 *
	 * 🔴 这是这个插件从「半成品」变成「成品」的那一条。
	 *
	 * 抽取永远有长尾 —— WPML 也一样，他们靠一份份配置文件管着。真正的差别不在于
	 * 漏不漏，而在于**漏了之后谁先知道**。2026-10 为一位买家连发六个版本，每一版
	 * 都是等他发现、截图、反馈才知道还有一层。有了这个，同样那六层一次就全看见了。
	 *
	 * @var string[]
	 */
	public $untranslated = array();

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
