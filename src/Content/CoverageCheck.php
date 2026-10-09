<?php
/**
 * Finds text that stayed in the source language after a translation ran.
 *
 * @package AumLang
 */

namespace AumLang\Content;

use AumLang\Support\Str;

use AumLang\Builders\ShortcodeText;

defined( 'ABSPATH' ) || exit;

/**
 * 🔴 为什么要有这个，以及它为什么**不能**复用抽取器。
 *
 * 2026-10 为一位买家连发了六个版本，每一版都对，每一版都只修好一层：
 * 整页一次请求 → 一轮超时 → 半成品被丢弃 → 构建器标记被当文字 → 标记被整个跳过 →
 * 短代码属性没抽 → 单引号属性没认 → 非拉丁文字被判成「不是文字」。
 *
 * 每一层都是**等买家发现、截图、反馈**才知道的。插件自己从头到尾不知道自己漏了什么。
 * 抽取永远有长尾（WPML 也一样，他们靠一份份 wpml-config.xml 管着），
 * 所以真正该补的不是「抽得更全」，而是「漏了会自己说」。
 *
 * 关键在于它**不走抽取器那条路**：抽取器漏掉的，它必须还能看见。
 * 所以这里直接扫原始内容的两类候选 —— 标签之间的文字、以及所有 `属性="值"` ——
 * 两边各扫一遍，凡是「在译文里和原文一字不差」的正文，就报出来。
 * 上面那八层里的每一层，这一招都会当场照出来。
 */
class CoverageCheck {

	/**
	 * 短于这个长度的就不报了。
	 *
	 * 「ISO」「PCB」「2010」这类在哪种语言里都一样，报出来只会把真问题淹掉。
	 * 取 12 个字符，大致是三四个英文单词。
	 */
	const MIN_LENGTH = 12;

	/**
	 * 中日韩这类不用空格分词的文字，门槛要低得多 —— 一个字顶一个词。
	 */
	const MIN_LENGTH_DENSE = 5;

	/**
	 * Compare a source post against its translation.
	 *
	 * @param string $source      Source post_content.
	 * @param string $translation Translated post_content.
	 * @param int    $limit       Most items to return.
	 * @return array{missed:string[], checked:int}
	 */
	public static function compare( $source, $translation, $limit = 25 ) {
		$from = self::candidates( (string) $source );
		$to   = self::candidates( (string) $translation );

		$missed = array();

		/*
		 * 🔴 取键，不是取值。$from 是以文本为**键**的查找表，值全是 true；
		 * 写成 `foreach ( $from as $text )` 会拿到一串 true，于是永远一条都报不出来 ——
		 * 一个永远说「一切正常」的自检比没有自检更糟，因为它让人放心。
		 */
		foreach ( array_keys( $from ) as $text ) {
			if ( ! isset( $to[ $text ] ) ) {
				continue;   /* 译文里没有这一条 —— 说明翻过了 */
			}

			$missed[] = $text;

			if ( count( $missed ) >= $limit ) {
				break;
			}
		}

		return array(
			'missed'  => $missed,
			'checked' => count( $from ),
		);
	}

	/**
	 * Every piece of prose a page contains, however it is stored.
	 *
	 * 两类来源：标签之间的文字，和属性里的值。构建器把标题放在属性里，
	 * 所以只看前者就会漏掉一整页的标题 —— 那正是这次踩的坑。
	 *
	 * @param string $content Raw post content.
	 * @return array<string, true> Prose strings, as a lookup.
	 */
	private static function candidates( $content ) {
		$out = array();

		/* 标签之间的文字 */
		$text = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $content );
		$text = (string) preg_replace( '/<[^>]*>/', "\n", (string) $text );

		foreach ( preg_split( '/\n+/', $text ) as $line ) {
			/* 短代码留给下面按属性拆，这里只看短代码之外的文字 */
			foreach ( ShortcodeText::segments( $line ) as $segment ) {
				if ( 'keep' === $segment['kind'] ) {
					continue;
				}

				self::add( $out, $segment['text'] );
			}
		}

		/* 属性值：HTML 的和短代码的都在这个形状里 */
		if ( preg_match_all( '/[a-zA-Z_][\w\-]*\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$value = '' !== $pair[1] ? $pair[1] : ( isset( $pair[2] ) ? $pair[2] : '' );
				self::add( $out, $value );
			}
		}

		return $out;
	}

	/**
	 * Keep one candidate if it reads like prose.
	 *
	 * @param array<string, true> $out  Collector.
	 * @param string              $text Candidate.
	 * @return void
	 */
	private static function add( array &$out, $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

		/*
		 * 🔴 长度门槛要分文字体系。
		 *
		 * 12 个字符是按英文定的（约三四个词）。但中日韩一个字顶一个词 ——
		 * 「我们每周发货到全球」只有 9 个字，已经是完整一句，却会被当成太短而漏报。
		 * 和之前「至少两段字母」杀掉 CJK 是同一类错误：拿拉丁文的尺子去量别的文字。
		 */
		$dense = preg_match( '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text );

		if ( Str::len( $text ) < ( $dense ? self::MIN_LENGTH_DENSE : self::MIN_LENGTH ) ) {
			return;
		}

		/*
		 * 用和抽取那边同一套「这是不是正文」的判断。两边各写一套的话，
		 * 自检就会按自己的标准去挑刺，报一堆本来就不该翻的东西。
		 */
		if ( ! ShortcodeText::is_prose( $text ) ) {
			return;
		}

		/*
		 * 🔴 降噪只放在这一侧。
		 *
		 * 「ISO 13485:2016」「IATF 16949:2016」这类编号在哪种语言里都一样，
		 * 报出来只会把真问题淹掉。但**抽取那一侧不能用这条**：中文整句只算一段字母，
		 * 拿它去筛会把中日韩源站的内容全判成「不是正文」。
		 *
		 * 所以这里额外要求「至少两段字母」，而且只在拉丁字母的情况下要求 ——
		 * 不带空格的文字（中日韩泰）本来就数不出第二段。
		 */
		if ( ! preg_match( '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0E00}-\x{0E7F}]/u', $text )
			&& preg_match_all( '/\p{L}{2,}/u', $text ) < 2 ) {
			return;
		}

		/*
		 * 型号也不报。`TU-872/SLKSP`、`RO4835 + IT180A` 这类在哪种语言里都一样，
		 * 出现在「仍未翻译」的清单里会让站长以为出了问题，而其实什么问题都没有。
		 * 判据：没有小写字母、却有数字 —— 正常句子不会长这样。
		 */
		if ( ! preg_match( '/\p{Ll}/u', $text ) && preg_match( '/\d/', $text ) ) {
			return;
		}

		$out[ $text ] = true;
	}
}
