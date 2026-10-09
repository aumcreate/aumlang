<?php
/**
 * Translates data-stored site text alongside the page the owner asked for.
 *
 * @package AumLang
 */

namespace AumLang\Strings;

use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Fills in menu labels, widget text and declared option text without being asked.
 *
 * 🔴 为什么不做成一个按钮。
 *
 * 字符串页上已经有「全部翻译」—— 但那要求站长知道有这么一页、知道自己需要点它。
 * 2026-10-09 的教训正是这个：一位买家的页面升级后多出 588 条可翻内容，后台显示
 * 「已翻译」，他看不出有事要做，于是第三次回来说同一个问题。**把判断交给用户，
 * 用户就会用「它坏了」来回答。**
 *
 * 所以这里挂在 `aumlang_after_translate` 上：他翻译某一页的时候，顺带把这个语言
 * 缺的站点文字也翻掉。菜单通常几十条、约两次 API 调用，一次性，之后按原文哈希缓存。
 * 他什么都不用点，下次打开就是译好的菜单。
 *
 * 几道自我约束：
 *   · 一次只做一批，不把他那一次翻译拖长（整轮预算是 20 秒）；
 *   · 加锁，因为那个动作每一轮都会触发，而发现+翻译不需要每轮都来一遍；
 *   · 失败只是安静跳过 —— 他要的是那一页翻好，不能让菜单的问题把页面也带倒。
 */
class DataStringFiller {

	/**
	 * How many strings to translate in one page-translation request.
	 */
	const BATCH = 50;

	/**
	 * How long before this runs again for the same language.
	 */
	const LOCK = 120;

	/**
	 * Data string collector.
	 *
	 * @var DataStrings
	 */
	private $data;

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private $strings;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Batch processor.
	 *
	 * @var BatchProcessor
	 */
	private $batch;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param DataStrings       $data      Collector.
	 * @param StringRepository  $strings   String repository.
	 * @param ProviderRegistry  $providers Provider registry.
	 * @param BatchProcessor    $batch     Batch processor.
	 * @param LanguageRegistry  $languages Language registry.
	 */
	public function __construct(
		DataStrings $data,
		StringRepository $strings,
		ProviderRegistry $providers,
		BatchProcessor $batch,
		LanguageRegistry $languages
	) {
		$this->data      = $data;
		$this->strings   = $strings;
		$this->providers = $providers;
		$this->batch     = $batch;
		$this->languages = $languages;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'aumlang_after_translate', array( $this, 'fill' ), 10, 3 );
	}

	/**
	 * Translate a batch of this language's missing data strings.
	 *
	 * @param int    $source_id   Source post id (unused).
	 * @param int    $target_id   Translation post id (unused).
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	public function fill( $source_id, $target_id, $target_lang ) {
		unset( $source_id, $target_id );

		$lang = (string) $target_lang;

		if ( '' === $lang ) {
			return;
		}

		$lock = 'aumlang_datastr_' . md5( $lang );

		if ( get_transient( $lock ) ) {
			return;
		}

		set_transient( $lock, 1, self::LOCK );

		try {
			$this->data->discover( $lang );

			$rows = $this->strings->get_untranslated_batch_in(
				$lang,
				self::BATCH,
				array( DataStrings::CTX_MENU, DataStrings::CTX_OPTION, DataStrings::CTX_WIDGET )
			);

			if ( empty( $rows ) ) {
				return;
			}

			$provider = $this->providers->get_active();

			if ( ! $provider || ! $provider->is_configured() ) {
				return;
			}

			$default = $this->languages->get_default_language();
			$texts   = array();

			foreach ( $rows as $row ) {
				$texts[] = $row['source_text'];
			}

			$translated = $this->batch->translate(
				$provider,
				$texts,
				$default ? $default->code() : '',
				$lang
			);

			foreach ( $rows as $index => $row ) {
				$text = isset( $translated[ $index ] ) ? (string) $translated[ $index ] : '';

				if ( '' !== $text && $text !== (string) $row['source_text'] ) {
					$this->strings->set_translation_by_id( (int) $row['id'], $text, 'machine' );
				}
			}
		} catch ( \Throwable $e ) {
			/*
			 * 安静跳过。他点的是「翻译这一页」，那件事必须成；
			 * 站点文字下一次翻译时会再试一遍。
			 */
			return;
		}
	}
}
