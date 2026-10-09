<?php
/**
 * String helpers that work without the mbstring extension.
 *
 * @package AumLang
 */

namespace AumLang\Support;

defined( 'ABSPATH' ) || exit;

/**
 * UTF-8 string operations with no extension requirement.
 *
 * 🔴 mbstring 不是必装的，而我们把它当成了必装的。
 *
 * 2026-10-09：1.4.2 在一位买家的站上**翻译直接崩掉** ——
 * `Call to undefined function AumLang\Builders\mb_check_encoding()`。
 * 他的 PHP 没有 mbstring。而 1.4.2 新加的 base64 识别里每遇到一个长属性
 * 就会调一次它，所以那一版一装上去，他连一个字都翻不了 —— 比原来的毛病更重。
 *
 * 更要紧的是这类依赖**在我们自己的机器上永远不会暴露**：开发机、Local、
 * aumtest 全都装着 mbstring，所有闸门也都跑在这些机器上。它只在买家那里炸。
 * 所以这里一律用纯 PHP 实现，不做「有就用、没有就退」的分支 —— 分支意味着
 * 两条路径，而我们只测得到其中一条。
 *
 * WordPress 自己也是这么处理的：核心有 `_mb_substr()` 作为兜底，
 * 但它**不提供** `mb_strlen` / `mb_check_encoding` 的替代，所以得自己写。
 */
class Str {

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * `preg_match` 带 `u` 修饰符时，遇到非法 UTF-8 会匹配失败并把
	 * `preg_last_error()` 置为 PREG_BAD_UTF8_ERROR —— 正是我们要的判断。
	 *
	 * @param string $text Candidate.
	 * @return bool
	 */
	public static function is_utf8( $text ) {
		return 1 === preg_match( '//u', (string) $text );
	}

	/**
	 * Length in characters, not bytes.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function len( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return 0;
		}

		$count = preg_match_all( '/./us', $text );

		/* 非法 UTF-8 时 preg_match_all 返回 false —— 退回字节数，总比崩了好。 */
		return false === $count ? strlen( $text ) : (int) $count;
	}

	/**
	 * Substring by characters, not bytes.
	 *
	 * @param string   $text   Text.
	 * @param int      $start  Start offset in characters.
	 * @param int|null $length Length in characters, or null for the rest.
	 * @return string
	 */
	public static function sub( $text, $start, $length = null ) {
		$text  = (string) $text;
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $chars ) ) {
			return null === $length ? substr( $text, $start ) : substr( $text, $start, $length );
		}

		$slice = null === $length
			? array_slice( $chars, $start )
			: array_slice( $chars, $start, $length );

		return implode( '', $slice );
	}
}
