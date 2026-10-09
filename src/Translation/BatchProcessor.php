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
	 * 本轮要用的块大小；0 表示用 provider 自己的 max_batch_size()。
	 *
	 * 🔴 为什么要能从外面压小。一块失败后的恢复手段是「重试 → 对半拆 → 递归」，
	 * 而只有拆到**单条**才豁免 echo 检查、才一定能过。16 条拆到单条要 4 层、
	 * 每层 2 次调用，按每次 3.9 秒算约 39 秒 —— 一轮 20 秒根本走不到那里。
	 * 于是每一轮都在同一条注定走不完的路上重走，`还剩 16` 永远是 16。
	 * 2026-10-07 买家升到 1.0.25 后连跑 24 轮零进度，就是这个。
	 * 解法是让上一轮的失败**传下去**：没进度就把块减半，直到小到能在一轮内做完。
	 *
	 * @var int
	 */
	private $chunk_size = 0;

	/**
	 * 单条探针是否已经证明「这批内容本来就不该变」。
	 *
	 * 🔴 两道回声防护要说同一套话。下面收尾那道问的是「整份结果是不是和输入一模一样」，
	 * 而一页全是产品型号、网址、纯数字时，答案本来就是「一样」—— 那不是服务商坏了。
	 * 探针已经用**单条**问过同一个问题并得到「确实不变」，收尾那道就不该再推翻它，
	 * 否则一页合法的型号表会被报成「翻译失败」。
	 *
	 * @var bool
	 */
	private $verified_unchanged = false;

	/**
	 * 本轮实际发出的调用数、最后一条失败原因、本轮送检的条数。
	 *
	 * 🔴 给诊断看的。「还剩 2 条」「这一轮 25 秒」两个数摆在一起说不通时，
	 * 缺的就是中间这一段：这 25 秒里到底打了几次、每次为什么失败。
	 * 没有它只能靠推断，而推断在这件事上已经错过三次。
	 *
	 * @var int
	 */
	public $round_calls = 0;

	/** @var string 本轮最后一次失败的原因（没有就是空）。 */
	public $last_reason = '';

	/** @var int 本轮真正送去翻译的条数（已排除纯标记）。 */
	public $sent_count = 0;

	/**
	 * 这一轮的收工时刻（unix 时间戳）；0 表示不限时。
	 *
	 * 🔴 为什么按**时间**而不是按条数收工：条数限不住墙钟。
	 * 单次调服务商超时 60 秒、一轮最多 60 次调用 —— 按条数算，一个请求最坏跑
	 * 一小时，而 nginx 默认 fastcgi_read_timeout 是 60 秒。2026-10-07 客户收到的
	 * 502 就是这么来的：不是翻译出错，是网关等不下去了。
	 *
	 * @var int
	 */
	private $deadline = 0;

	/**
	 * 这一轮是不是因为到点了而提前收工（还有没翻的）。
	 *
	 * @var bool
	 */
	public $incomplete = false;

	/**
	 * 这一轮**已经定下来**的原始下标（含无需翻译的纯标记）。
	 *
	 * 🔴 调用方必须用这个判断「哪些做完了」，**不能拿「结果是否等于原文」去判**：
	 * 一个两种语言里本来就相同的字符串（专名、数字、单位）会被永远判成没做完，
	 * 于是前端会无限循环重发 —— 那比它要修的 502 更糟。
	 *
	 * @var int[]
	 */
	public $settled = array();

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
	/**
	 * 设定这一轮的收工时刻。到点之后不再开始新的一块。
	 *
	 * @param int $timestamp unix 时间戳；0 表示不限。
	 * @return void
	 */
	/**
	 * Force a chunk size for this round, overriding the provider's own.
	 *
	 * @param int $size Strings per request; 0 restores the provider's default.
	 * @return void
	 */
	public function set_chunk_size( $size ) {
		$this->chunk_size = max( 0, (int) $size );
	}

	public function set_deadline( $timestamp ) {
		$this->deadline = max( 0, (int) $timestamp );
	}

	public function translate( ProviderInterface $provider, array $texts, $source_lang, $target_lang, array $options = array() ) {
		$texts = array_values( $texts );

		$this->calls = 0;
		$this->skipped_markup = 0;

		/*
		 * 🔴 这两个也必须重置。生产环境每轮是独立请求、实例是新的，所以漏掉看不出来；
		 * 但同一个请求里调两次（比如测试、或将来有人在一次请求里翻两篇），
		 * 上一轮的 incomplete 会被这一轮继承 —— 于是一个**已经翻完**的轮次
		 * 仍然报 done=false，白跑一轮。2026-10-07 的端到端测试就是这么撞出来的：
		 * 第 3 轮「剩 0」却说没完。
		 */
		$this->incomplete         = false;
		$this->settled            = array();
		$this->verified_unchanged = false;
		$this->calls              = 0;
		$this->round_calls        = 0;
		$this->last_reason        = '';
		$this->sent_count         = 0;

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
			if ( ! self::is_markup_only( $text ) ) {
				$send[ $idx ] = $text;
			}
		}
		$this->skipped_markup = count( $texts ) - count( $send );
		$this->sent_count     = count( $send );


		if ( empty( $send ) ) {
			return $texts;
		}

		if ( empty( $texts ) ) {
			return array();
		}

		$size = $provider->supports_batch() ? max( 1, $provider->max_batch_size() ) : 1;

		if ( $this->chunk_size > 0 ) {
			$size = min( $size, $this->chunk_size );
		}

		$results = array();

		/*
		 * 这一轮见过的**最慢**一块用了多少秒。取最慢而不是平均：宁可早收一轮，
		 * 也不要赌一把然后吃 502 —— 早收只是多跑一个来回，超时是整轮白做。
		 */
		$worst = 0;

		foreach ( array_chunk( array_values( $send ), $size ) as $chunk ) {
			/*
			 * 到点就收工，**在开始下一块之前判**，不是判完再做。
			 * 已经做完的那些照常返回，调用方负责存下来并再发一次。
			 *
			 * 🔴 光判「到点没」不够。一轮只能在块与块之间停，所以判完还要再做完
			 * 一整块才轮得到下一次判断 —— 预算 20 秒、一块 40 秒，这一轮就跑 60 秒，
			 * 预算等于没设。2026-10 有买家在 20 秒就掐连接的主机上一直收到 502，
			 * 就是这么来的：日志里看着每轮都「按时收工」，墙钟上早超了。
			 * 所以还要判「再做一块会不会超点」，用这一轮实测的最慢一块来估。
			 * 第一块没有估值、也必须做 —— 一块都不做就永远不前进。
			 */
			if ( $this->deadline ) {
				$now = time();

				if ( $now >= $this->deadline || ( $worst > 0 && $now + $worst > $this->deadline ) ) {
					$this->incomplete = true;
					break;
				}
			}

			$started = microtime( true );

			try {
				$got = $this->translate_chunk( $provider, $chunk, $source_lang, $target_lang, $options );
			} catch ( DeadlineReached $e ) {
				/* 这一块连一条都没做成就到点了，整块留给下一轮。 */
				$this->incomplete = true;
				break;
			}

			$results = array_merge( $results, $got );

			/*
			 * 🔴 短于整块 = 这一块只做完了前一半，是到点时保下来的**前缀**。
			 * 前缀可以留（位置仍然对得上），但后面的块一个都不能再接上去 ——
			 * 接上去整列就错位了，错位的译文比没译更糟。所以到此为止。
			 */
			if ( count( $got ) < count( $chunk ) ) {
				$this->incomplete = true;
				break;
			}

			$worst = max( $worst, (int) ceil( microtime( true ) - $started ) );
		}

		/* 把译文按原下标放回，没发出去的位置保持原文。 */
		$out = $texts;

		/*
		 * 纯标记的那些这一轮就算定下来了：它们本来就不需要翻译，
		 * 原样留着是正确结果，不是「还没轮到」。
		 */
		$this->settled = array_values( array_diff( array_keys( $texts ), array_keys( $send ) ) );

		foreach ( array_keys( $send ) as $n => $idx ) {
			if ( array_key_exists( $n, $results ) ) {
				$out[ $idx ]      = $results[ $n ];
				$this->settled[] = $idx;
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
		/*
		 * 🔴 提前收工时不许跑这条判断。它问的是「服务商是不是把每一条都原样退回来了」，
		 * 而半截的结果本来就少一截 —— 拿它去判会把一次正常的分批误报成服务商坏了。
		 */
		if ( ! $this->incomplete && ! $this->verified_unchanged && self::is_echo( array_values( $send ), $results ) ) {
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

	/**
	 * Ask the provider to translate one string, and report whether it came back
	 * unchanged.
	 *
	 * 单条请求是豁免回声检查的，所以这里拿到的就是「这段文字在这个服务商眼里
	 * 到底会不会变」的直接答案 —— 不必靠整块拆分去逼问。
	 *
	 * @param ProviderInterface $provider    Provider.
	 * @param string            $text        One source string.
	 * @param string            $source_lang Source language.
	 * @param string            $target_lang Target language.
	 * @param array             $options     Provider options.
	 * @return bool True when the provider returns it unchanged.
	 */
	private function unchanged_is_genuine( ProviderInterface $provider, $text, $source_lang, $target_lang, array $options ) {
		if ( $this->calls > 0 && $this->deadline && time() >= $this->deadline ) {
			return false;   /* 没时间问了，交给下一轮，别在这里赌。 */
		}

		++$this->round_calls;

		if ( ++$this->calls > $this->max_calls ) {
			return false;
		}

		try {
			$one = $provider->translate( array( $text ), $source_lang, $target_lang, $options );
		} catch ( \Throwable $e ) {
			return false;
		}

		return isset( $one[0] ) && (string) $one[0] === (string) $text;
	}

	/**
	 * Whether a node is markup rather than something to translate.
	 *
	 * 🔴 为什么不能只靠 strip_shortcodes()。
	 *
	 * 它只认**已注册**的短代码，而页面构建器的标签要等它自己的插件注册，抽取这一步
	 * 往往还没注册；更根本的是像 `[/bt_bb_text]` 这样的孤立结束标签，它无论如何都匹配
	 * 不到。于是一串构建器标记「还剩字母」，被当成句子发给翻译服务商 —— 白花调用、
	 * 可能被模型改坏，还会让整页翻不完。
	 *
	 * 两道判据，都按**形状**走，不认任何一家构建器的名字：
	 *
	 *   1. 剥掉短代码形状的组（结束标签、带下划线的标签名、带属性赋值）之后还有字母
	 *      → 是正文。`[vc_column_text]We ship worldwide.[/vc_column_text]` 这样标记裹
	 *      着正文的，正文照样留得下来。
	 *   2. 第 1 条没拦住的，再看一次「整条去掉**所有**方括号组后还剩不剩字母」。
	 *      这一道是给 `[row][col]`、`[divider]` 这类既没下划线也没属性的标签准备的 ——
	 *      只按第 1 条写，就只覆盖得了我们恰好见过的那几家。
	 *
	 * 取舍说在前面：整条**只有**一个方括号词（例如一个节点就是 `[Note]`）会被当成标记
	 * 留着不翻。拿它换掉「把构建器标记喂给 AI」，这个方向是对的。
	 *
	 * @param string $text Raw node text.
	 * @return bool True when there is nothing to translate.
	 */
	private static function is_markup_only( $text ) {
		$text = (string) $text;

		$shaped = preg_replace(
			'/\[(?:'
			. '\/[a-zA-Z0-9_\-]+'                        /* [/foo] */
			. '|[a-zA-Z0-9_\-]*_[a-zA-Z0-9_\-]*[^\]]*'   /* [bt_bb_x ...] [vc_row] */
			. '|[a-zA-Z0-9_\-]+[^\]]*=[^\]]*'            /* [foo bar="baz"] */
			. ')\]/',
			' ',
			strip_shortcodes( $text )
		);

		if ( ! preg_match( '/\p{L}/u', wp_strip_all_tags( (string) $shaped ) ) ) {
			return true;
		}

		$bare = preg_replace( '/\[[^\]]*\]/', ' ', $text );

		return ! preg_match( '/\p{L}/u', wp_strip_all_tags( (string) $bare ) );
	}

	private function translate_chunk( ProviderInterface $provider, array $chunk, $source_lang, $target_lang, array $options ) {
		$attempt   = 0;
		$last_error = null;

		while ( $attempt < $this->max_attempts ) {
			++$attempt;

			/*
			 * 🔴 到点要在**这里**判，不能只在块与块之间判。
			 *
			 * 一块失败后会重试、再对半拆、再递归 —— 这一整串都发生在**一个块内部**，
			 * 外层那个「下一块之前判一下」根本轮不到。于是真正卡住一轮的是 max_calls
			 * （60 次），不是时间预算：60 次 × 约 1.7 秒 ≈ 102 秒。2026-10-07 买家升到
			 * 1.0.22 之后，一轮仍然跑了 105.6 秒被 nginx 掐断，就是这么来的 —— 预算写着
			 * 20 秒，从头到尾没起过作用。
			 *
			 * 豁免的是**整轮的第一次调用**（$this->calls === 0），不是「每一层的第一次尝试」。
			 * 写成后者会漏：拆分是递归的，每下一层都算一次「第一次尝试」，于是到点之后
			 * 仍然能不断开新调用 —— 实测预算 20 秒跑到 27.3 秒。留一次豁免是为了保证
			 * 一轮至少做一件事（块减到 1 条时那一条一定过得去，所以一定会前进）。
			 */
			if ( $this->calls > 0 && $this->deadline && time() >= $this->deadline ) {
				throw new DeadlineReached( 'Round budget spent.' );
			}

			try {
				++$this->round_calls;

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
					/*
					 * 🔴 先问一句再下结论。
					 *
					 * 整块原样回来有两种可能：模型偷懒把输入抄了回来（真回声），
					 * 或者这一块本来就是两种语言里长得一样的东西 —— 产品型号、网址、
					 * 纯数字。2026-10 买家那页剩下的 16 条正是后者。
					 *
					 * 旧写法把两者都当回声：重试、对半拆、递归，一路拆到**单条**才罢休
					 * ——而单条是豁免这项检查的，于是最终还是原样收下。也就是说结论没变，
					 * 只是多花了二十几轮、八分钟才走到。实测 16 条型号要 24 轮。
					 *
					 * 所以改成先用**一条**去试：
					 *   这一条也原样回来 → 这块本来就不该变，整块收下；
					 *   这一条翻出来了   → 刚才那次确实是回声，照旧重试和拆分。
					 * 代价是真回声时多一次调用，省下的是二十几轮。
					 */
					if ( $this->unchanged_is_genuine( $provider, $chunk[0], $source_lang, $target_lang, $options ) ) {
						$this->verified_unchanged = true;

						return $result;
					}

					throw new \RuntimeException( 'Provider returned the input unchanged.' );
				}

				return $result;
			} catch ( DeadlineReached $e ) {
				/* 自己喊的停，原样往上抛 —— 不能被下面的「失败就拆开重来」吃掉。 */
				throw $e;
			} catch ( \RuntimeException $e ) {
				$last_error        = $e;
				$this->last_reason = $e->getMessage();
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
		if ( $this->deadline && time() >= $this->deadline ) {
			throw new DeadlineReached( 'Round budget spent before splitting.' );
		}

		if ( count( $chunk ) > 1 ) {
			$middle = (int) ceil( count( $chunk ) / 2 );

			$left = $this->translate_chunk( $provider, array_slice( $chunk, 0, $middle ), $source_lang, $target_lang, $options );

			try {
				$right = $this->translate_chunk( $provider, array_slice( $chunk, $middle ), $source_lang, $target_lang, $options );
			} catch ( DeadlineReached $e ) {
				/*
				 * 左半边已经做完了，别跟着一起丢。返回它就是这一块的前缀，
				 * 调用方认得这个形状，会收下并停在这里。
				 */
				return $left;
			}

			return array_merge( $left, $right );
		}

		throw new \RuntimeException(
			esc_html(
				'Translation failed after ' . $this->max_attempts . ' attempts: '
				. ( $last_error ? $last_error->getMessage() : 'unknown error' )
			)
		);
	}
}
