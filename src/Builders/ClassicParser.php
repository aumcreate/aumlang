<?php
/**
 * Parser for classic (HTML) post content.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts translatable units from post_content and rebuilds the HTML with
 * translations applied, leaving every tag, attribute, and structure untouched.
 *
 * This is the universal fallback parser; it supports any post and runs last.
 *
 * 🔴 这里几乎没有自己的逻辑，是故意的。
 *
 * 抽取和回填的实现放在 {@see HtmlTextExtractor}，Elementor 和 Gutenberg 两个解析器
 * 走的是同一份。以前 Classic 自己抄了一套 DOM 处理，于是「怎么切句子」这件事有两处
 * 实现 —— 改进一处、漏掉另一处，就只有一部分用户受益。2026-10 把按文本节点切改成
 * 按块切时，正是这个重复让第一版只修好了三分之一的场景。
 */
class ClassicParser implements BuilderParserInterface {

	/**
	 * Shared HTML helper.
	 *
	 * @var HtmlTextExtractor
	 */
	private $html;

	/**
	 * Constructor.
	 *
	 * @param HtmlTextExtractor $html Shared HTML helper.
	 */
	public function __construct( HtmlTextExtractor $html ) {
		$this->html = $html;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'classic';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Always true: the classic parser is the fallback for any content.
	 */
	public function supports( $post_id ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return array();
		}

		$nodes = array();

		foreach ( $this->html->extract_units( $post->post_content ) as $index => $unit ) {
			$nodes[] = new TranslatableNode(
				(string) $index,
				$unit['text'],
				'',
				/*
				 * 整块的单元里带着行内标签，必须标成 HTML —— 编排器据此决定要不要
				 * 过标签闸。标成 TEXT 的话，标签被译坏了也没人拦得住。
				 */
				$unit['html'] ? TranslatableNode::TYPE_HTML : TranslatableNode::TYPE_TEXT,
				TranslatableNode::DISPOSITION_TRANSLATE
			);
		}

		return $nodes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rebuild( $post_id, array $translations ) {
		$post = get_post( $post_id );

		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return '';
		}

		return $this->html->rebuild( $post->post_content, $translations );
	}
}
