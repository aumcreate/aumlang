<?php
/**
 * Checks that a translated HTML fragment still carries the tags it went in with.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * 🔴 整块送翻译的前提是这道闸。
 *
 * 按块翻意味着把 `<strong>`、`<a href="…">` 这些标签一起交给模型，指望它原样带回来。
 * 多数时候它会，但指望不是保证：标签可能被丢掉、被改名、链接可能被「翻译」成别的地址。
 * 而这类破坏是**悄无声息**的 —— 页面照样显示，只是加粗没了、链接指向错了，
 * 没有任何报错会提醒站长。
 *
 * 所以收下译文之前先比一次签名：标签名的顺序要一致，href/src 要一字不差。
 * 对不上就整块拒收，退回原文。最坏情况等于「这一块没翻」，
 * 永远不会变成「这一块的结构被译坏了」。
 */
class TagGuard {

	/**
	 * Whether a translation preserved the source fragment's tag structure.
	 *
	 * @param string $source      Source inner HTML.
	 * @param string $translation Translated inner HTML.
	 * @return bool
	 */
	public static function kept( $source, $translation ) {
		return self::signature( $source ) === self::signature( $translation );
	}

	/**
	 * The comparable shape of a fragment: tag names in order, plus the URLs.
	 *
	 * 只看**有哪些**标签和 href/src，**不看先后顺序**。
	 *
	 * 🔴 顺序不能比。日语语序和英语本来就不同：英文「A 提供 B」译成日文常变成
	 * 「B を A が提供」，`<strong>` 和 `<a>` **本就应该换位置**。按顺序比会把大量
	 * 正确译文当成坏的拒掉 —— 那就等于这个功能白做了。
	 *
	 * 丢标签、多标签、链接被改，这三样用集合一样抓得住，而它们才是真正的破坏。
	 * class、style、data-* 不参与比对：模型重排它们不影响页面对不对。
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function signature( $html ) {
		$html = (string) $html;
		$out  = array();

		if ( ! preg_match_all( '/<\s*(\/?)\s*([a-zA-Z0-9]+)([^>]*)>/', $html, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $matches as $m ) {
			$tag = strtolower( $m[2] );

			/* 自闭合与否写法不一，统一成同一个形状。 */
			if ( '/' === $m[1] ) {
				$out[] = '/' . $tag;
				continue;
			}

			$url = '';

			if ( preg_match( '/\b(?:href|src)\s*=\s*("|\')(.*?)\1/i', (string) $m[3], $u ) ) {
				$url = $u[2];
			}

			$out[] = $tag . ( '' !== $url ? '(' . $url . ')' : '' );
		}

		sort( $out );

		return implode( '|', $out );
	}
}
