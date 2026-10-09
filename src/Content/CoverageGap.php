<?php
/**
 * How much of a source page has no translation stored yet.
 *
 * @package AumLang
 */

namespace AumLang\Content;

use AumLang\Builders\ParserRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the pieces of a source page that have never been translated.
 *
 * 🔴 为什么需要这个，而不是靠「源页变了就标过期」。
 *
 * 过期判断看的是源页内容的哈希 —— 源页没动，就什么都不亮。但有一类情况源页
 * 一个字没动，译文却确实变得不完整了：**插件自己变得能看见更多内容。**
 *
 * 2026-10-09 就是这样。1.4.2 开始能读出藏在短代码属性里的 base64 表格，一位
 * 买家页面上因此多出 588 个可翻译单元。他升级了插件，然后去看页面 —— 表格还是
 * 英文。后台显示那一页「已翻译」，没有任何地方告诉他需要再翻一次，所以他看不出
 * 还有事要做，只看到「表格还是不翻译」。他是第三次回来说同一件事了。
 *
 * 问题不在他。装了新版本不会自动重翻，这是对的（翻译要花钱，不能擅自花）；
 * 但**不告诉他有东西可翻，是我们的错**。
 *
 * 所以这里不去猜「升级了没有」，而是直接问一个永远成立的问题：
 * 现在能抽出来的单元里，有多少条从来没存过译文？答案大于零就该提示。
 * 这样以后不管抽取能力又变宽了多少次，都不需要谁记得去提醒用户。
 */
class CoverageGap {

	/**
	 * Parser registry.
	 *
	 * @var ParserRegistry
	 */
	private $parsers;

	/**
	 * Node translation repository.
	 *
	 * @var NodeTranslationRepository
	 */
	private $nodes;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Constructor.
	 *
	 * @param ParserRegistry            $parsers Parser registry.
	 * @param NodeTranslationRepository $nodes   Node translation repository.
	 * @param ContentLinker             $linker  Content linker.
	 */
	public function __construct( ParserRegistry $parsers, NodeTranslationRepository $nodes, ContentLinker $linker ) {
		$this->parsers = $parsers;
		$this->nodes   = $nodes;
		$this->linker  = $linker;
	}

	/**
	 * Pieces of this source with no stored translation in this language.
	 *
	 * 抽取一次不便宜 —— 实测他那个十七万字的页面 97ms（热缓存 4.7ms），而文章列表
	 * 一屏可能有二十行，
	 * 所以结果缓存起来。缓存键里带着源页哈希**和插件版本**：源页改了要重算，
	 * 插件升级了也要重算 —— 升级恰恰是这个数字会变的那一刻。
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Target language code.
	 * @return int Number of untranslated pieces, zero when nothing is missing.
	 */
	public function count( $source_id, $lang ) {
		$source_id = (int) $source_id;
		$lang      = (string) $lang;
		$post      = get_post( $source_id );

		if ( ! $post ) {
			return 0;
		}

		$group = $this->linker->get_group_uuid( $source_id );

		if ( ! $group ) {
			return 0;
		}

		$stored = $this->nodes->get_map( $group, $lang );

		/* 一条都没存过 = 这页根本没翻过。那是「未翻译」，不是「不完整」。 */
		if ( empty( $stored ) ) {
			return 0;
		}

		/*
		 * 🔴 缓存键里必须带上**已存译文的条数**。
		 *
		 * 只带源页哈希和插件版本是不够的：翻译并不改源页，所以他把剩下的翻完之后
		 * 键一点没变，缓存会继续报「还缺 570 条」—— 一个永远不消失的提示，
		 * 比没有提示更坏。条数一变键就变，这样它自己会好，不依赖谁记得去清缓存。
		 */
		$key = 'aumlang_gap_' . md5(
			$source_id . '|' . $lang . '|' . SourceHash::of( $post )
			. '|' . AUMLANG_VERSION . '|' . count( $stored )
		);

		$hit = get_transient( $key );

		if ( false !== $hit ) {
			return (int) $hit;
		}

		$count = $this->measure( $source_id, $stored );

		set_transient( $key, $count, DAY_IN_SECONDS );

		return $count;
	}

	/**
	 * The uncached half of {@see count()}.
	 *
	 * @param int                  $source_id Source post id.
	 * @param array<string, mixed> $stored    Stored translations, keyed by node path.
	 * @return int
	 */
	private function measure( $source_id, array $stored ) {
		$parser = $this->parsers->get_parser_for( $source_id );

		if ( ! $parser ) {
			return 0;
		}

		try {
			$nodes = $parser->extract( $source_id );
		} catch ( \Throwable $e ) {
			/* 数不出来就不要乱报 —— 报错的数字比没有数字更坏。 */
			return 0;
		}

		$missing = 0;

		foreach ( $nodes as $node ) {
			if ( ! isset( $stored[ $node->path ] ) ) {
				++$missing;
			}
		}

		return $missing;
	}

}
