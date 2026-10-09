<?php
/**
 * Splits text containing shortcodes into translatable and untouchable pieces.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * 🔴 为什么需要这个类。
 *
 * Elementor 和区块编辑器各有解析器；而 Bold Builder、WPBakery、Avada、Divi 这类
 * **短代码构建器**全部落到 ClassicParser，短代码对它就是一团不透明的文字。
 *
 * 问题在于：这类构建器把正文放在短代码的**属性**里 ——
 * `[bt_bb_headline headline="About Us" superheadline="// Our Certifications" ...]`。
 * 标题、副标题、按钮文字，一整页最显眼的内容都在属性里。
 *
 * 2026-10 买家那页实测：三段加起来 5 万字的「标记」里，藏着 38 条这样的正文。
 * 早先把整团当文字发出去 —— 译得乱七八糟，还把整轮拖垮；后来判成纯标记跳过 ——
 * 于是从「译坏」变成「根本不译」。两种都不对，因为两种都把它当成了整体。
 *
 * 正确做法是拆开：短代码的骨架一个字都不动，属性里的正文单独拿去翻。
 */
class ShortcodeText {

	/**
	 * 属性名长什么样算「装着正文」。
	 *
	 * 只靠值的形状不够：`animation="fade_in zoom_out"`、`el_style="display: flex"`
	 * 看起来都像句子。名字才是可靠信号，所以两个条件都要满足。
	 *
	 * 🔴 这串名字是**兜底**，没人声明过的构建器全靠它。实测发现原来漏掉的：
	 *   · `heading`（只认了 `headline`）—— 很多构建器用的是前者
	 *   · `h2` `h3` `h4` —— WPBakery 的 vc_cta 就把标题放在这里
	 *   · `message` —— WPBakery 的 vc_message
	 *   · `tagline` `blurb` `excerpt` `summary` `intro` `body` —— 各家常见的正文字段
	 * 补这一处，比逐条去抄别人的规则表管用：**没见过的构建器也一起受益**。
	 *
	 * 后面这批是量出来的：把一批真实配置里的普通属性名拿来跑，看兜底认不认，
	 * 把「确实是正文却没认出来」的补进来 —— `slogan` `subhead` `cite` `info`
	 * `position` `occupation` `company` `notice` `linktext` `linktitle`。
	 *
	 * 没收进来的，是因为歧义太大、宁可漏不可错：
	 *   `name`（常指模板名、字段名）、`number`、`per`（常是单位/数量）、
	 *   `address` `city`（街道和城市多是专名，翻了往往是错的）、
	 *   `before`/`after`/`prefix`/`suffix`（常是 CSS）。
	 */
	const CONTENT_NAME = '/(^|_)(head(line|ing)|sub_?head(line|ing)|super_?head(line|ing)|title|subtitle|text|content|caption|label|button|alt|placeholder|description|quote|author|message|tagline|blurb|excerpt|summary|intro|body|slogan|subhead|cite|info|infobox|position|occupation|company|notice|linktext|linktitle|h[1-6])(_|$)/i';

	/**
	 * 名字看着像内容、实际只在编辑器里出现的属性。
	 *
	 * Divi 的 `admin_label="Text"` 是给编辑后台看的标签，不会出现在页面上。
	 * 翻它既没用、又占一个调用名额，还可能让构建器的界面变成另一种语言。
	 */
	const EDITOR_ONLY = '/^(admin_label|module_id|module_class|_builder_version)$/i';

	/**
	 * Per-shortcode rules: which attributes of a given shortcode hold text.
	 *
	 * 🔴 为什么要有这张表，而不是只靠名字猜。
	 *
	 * 名字规律（`headline` / `title` / `text` …）覆盖得了大多数构建器，但永远有长尾：
	 * 某家用 `heading_text`、某家用 `h2`、某家把正文塞进 `content_a`。WPML 的做法是
	 * 让每个构建器、每个主题作者在 `wpml-config.xml` 里**声明**自己的可翻译属性，
	 * 他们再预置一大批常见配置 —— 这件事没有"自动判断"的银弹，只有"认识的照表走、
	 * 不认识的按规律猜"。
	 *
	 * 所以这里两层：这张表里有的短代码，**只翻表里列的属性**（精确，不会误翻设置项）；
	 * 表里没有的，退回按名字规律猜（能覆盖没见过的构建器）。
	 * 两层都能被 `aumlang_shortcode_rules` 过滤器改写。
	 *
	 * @return array{tags: array<string, string[]>, pattern: string, editor_only: string}
	 */
	public static function rules() {
		$rules = array(
			/* 表在 builders.xml 里，和第三方声明走同一个解析器，只有一份实现。 */
			'tags'    => array(),
			'pattern' => self::CONTENT_NAME,
			'editor_only' => self::EDITOR_ONLY,
		);

		/*
		 * 站上已装的主题/插件自己声明的规则优先于我们的内置表 ——
		 * 作者比我们更清楚自己哪个字段装着正文。
		 */
		foreach ( ConfigFileRules::collect() as $tag => $attributes ) {
			$rules['tags'][ $tag ] = $attributes;
		}

		/**
		 * Filter which shortcode attributes hold translatable text.
		 *
		 * 主题或插件作者可以挂这个过滤器声明自己的短代码，不必等我们认识它：
		 *
		 *     add_filter( 'aumlang_shortcode_rules', function ( $rules ) {
		 *         $rules['tags']['my_banner'] = array( 'heading', 'blurb' );
		 *         return $rules;
		 *     } );
		 *
		 * @param array $rules Tag map, name pattern, and editor-only pattern.
		 */
		return (array) apply_filters( 'aumlang_shortcode_rules', $rules );
	}

	/**
	 * Whether a given attribute of a given shortcode holds translatable text.
	 *
	 * @param string $tag       Shortcode tag, '' when unknown.
	 * @param string $attribute Attribute name.
	 * @return bool
	 */
	public static function is_content_attribute( $tag, $attribute ) {
		$rules = self::rules();

		if ( preg_match( $rules['editor_only'], $attribute ) ) {
			return false;
		}

		$tag = strtolower( (string) $tag );

		if ( '' !== $tag && isset( $rules['tags'][ $tag ] ) ) {
			/* 认识这个短代码 —— 只认表里列的，设置项一个都不碰。 */
			return in_array( strtolower( $attribute ), array_map( 'strtolower', $rules['tags'][ $tag ] ), true );
		}

		return (bool) preg_match( $rules['pattern'], $attribute );
	}

	/**
	 * Whether an attribute value is prose rather than a technical setting.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function is_prose( $value ) {
		$value = (string) $value;

		/*
		 * 🔴 用 \p{L} 而不是 [A-Za-z]。
		 *
		 * 写成 [A-Za-z]{2} 的话，`headline="关于我们"` 会被判成「没有字母、不是正文」——
		 * 于是任何以中文、日文、韩文、俄文、阿拉伯文为源语言的站点，短代码里的内容
		 * 一条都翻不出来。这种缺陷不会报错，只会让整类用户觉得插件没用。
		 */
		if ( ! preg_match( '/\p{L}{2}/u', $value ) ) {
			return false;
		}

		if ( false !== strpos( $value, ',;,' ) ) {
			return false;                                   /* 响应式语法 extra_large,;,,;, */
		}

		if ( preg_match( '#^https?://#i', $value ) ) {
			return false;                                   /* 网址 */
		}

		if ( preg_match( '/^[A-Za-z0-9+\/=]{40,}$/', $value ) ) {
			return false;                                   /* base64 之类的配置块 */
		}

		if ( preg_match( '/^[a-z0-9_\-]+$/', $value ) ) {
			return false;                                   /* yes / boxed_1200 / no_animation */
		}

		if ( preg_match( '/^[a-z0-9_\-]+(\s+[a-z0-9_\-]+)*$/', $value ) && ! preg_match( '/[A-Z]/', $value )
			&& preg_match( '/_/', $value ) ) {
			return false;                                   /* fade_in zoom_out */
		}

		if ( preg_match( '/^[\d.]+$/', $value ) ) {
			return false;                                   /* 版本号 */
		}

		if ( preg_match( '/^rgba?\(|^#[0-9a-f]{3,8}$/i', $value ) ) {
			return false;                                   /* rgba(0,0,0,0) / #1a1a1a */
		}

		if ( preg_match( '/^[a-z\-]+\s*:\s*[^;]+;?/', $value ) ) {
			return false;                                   /* display: flex; align-items: center; */
		}

		if ( preg_match( '/^[a-z0-9_\-]+(\s*,\s*[a-z0-9_\-]+)+$/', $value ) ) {
			return false;                                   /* latin,latin-ext */
		}

		/*
		 * 🔴 这里**不**要求「至少两段字母」。
		 *
		 * 曾经加过那一条来过滤「ISO 13485:2016」这类编号，结果连坐两样真内容：
		 *   · `title="Download"` —— 按钮上的单词，是真要翻的
		 *   · `headline="关于我们"` —— 中文没有词间空格，整句只算**一段**，
		 *     于是中日韩源站的内容全被判成「不是正文」，一条都翻不出来
		 * 第二条尤其糟：它不报错，只会让整类用户觉得插件没用。
		 *
		 * 降噪该放在**报告**那一侧（CoverageCheck），不是抽取这一侧 ——
		 * 两者目标相反：抽取宁可多翻一条，报告宁可少吵一句。
		 */
		return true;
	}

	/**
	 * The translatable strings inside an encoded-JSON attribute value, in order.
	 *
	 * 🔴 为什么要单独处理这种值。
	 *
	 * 有些构建器把一整组内容塞进一个属性里，先转成 JSON、再 urlencode，
	 * 像 `values="%5B%7B%22title%22%3A%22Our%20Services%22%7D%5D"`。
	 * 里面是真正的标题和正文，但从外面看就是一串乱码 —— 要么整条跳过（内容丢了），
	 * 要么整条发出去（模型照着乱码翻，译文和结构一起坏）。两种我们都遇到过。
	 *
	 * 抽取和回填都调这同一个函数，所以两边数出来的条数和顺序一定一致；
	 * 不一致的话译文就会错位，那比不翻更糟。
	 *
	 * @param string $value Raw attribute value.
	 * @return array{strings: string[], data: mixed}|null Null when it is not encoded JSON.
	 */
	public static function encoded_json( $value ) {
		$value = (string) $value;

		if ( false === strpos( $value, '%' ) && false === strpos( $value, '{' ) && false === strpos( $value, '[' ) ) {
			return null;
		}

		$decoded = rawurldecode( $value );

		if ( '' === trim( $decoded ) || ( '[' !== $decoded[0] && '{' !== $decoded[0] ) ) {
			return null;
		}

		$data = json_decode( $decoded, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$strings = array();
		self::walk_json( $data, $strings );

		return $strings ? array( 'strings' => $strings, 'data' => $data ) : null;
	}

	/**
	 * Collect prose strings from a decoded structure, depth first.
	 *
	 * @param mixed    $node    Decoded value.
	 * @param string[] $strings Collector.
	 * @return void
	 */
	private static function walk_json( $node, array &$strings ) {
		if ( is_string( $node ) ) {
			if ( self::is_prose( $node ) ) {
				$strings[] = $node;
			}

			return;
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $child ) {
			self::walk_json( $child, $strings );
		}
	}

	/**
	 * Put translations back into a decoded structure, in the same order.
	 *
	 * @param mixed    $node         Decoded value.
	 * @param string[] $translations Replacements, consumed in order.
	 * @param int      $cursor       Position in $translations.
	 * @return mixed
	 */
	public static function refill_json( $node, array $translations, &$cursor ) {
		if ( is_string( $node ) ) {
			if ( ! self::is_prose( $node ) ) {
				return $node;
			}

			$out = array_key_exists( $cursor, $translations ) ? $translations[ $cursor ] : $node;
			++$cursor;

			return $out;
		}

		if ( ! is_array( $node ) ) {
			return $node;
		}

		foreach ( $node as $key => $child ) {
			$node[ $key ] = self::refill_json( $child, $translations, $cursor );
		}

		return $node;
	}

	/**
	 * The HTML hidden inside a base64 attribute value, or null.
	 *
	 * 🔴 为什么要拆这种值。
	 *
	 * 有的构建器把一整块 HTML 先 base64 编码，再塞进短代码属性里 ——
	 * Bold Builder 的 `[bt_bb_raw_content raw_content="PHRhYmxl..."]` 就是。
	 * 从外面看那是一串乱码，我们的「像不像正文」判断会（正确地）拒绝它；
	 * 但里面装的可能是整张规格表。
	 *
	 * 2026-10-09 实测：一位买家的页面上 8 个这样的元素，里面是 8 张表、604 个单元格，
	 * 全部没有被翻译 —— 而页面上别的地方翻得好好的，所以他只看到「表格不翻译」，
	 * 看不出是整类元素被跳过了。
	 *
	 * @param string $value Raw attribute value.
	 * @return string|null Decoded HTML, or null when it is not base64 HTML.
	 */
	public static function encoded_html( $value ) {
		$value = trim( (string) $value );

		/* base64 的形状：够长、只有 base64 字符、长度是 4 的倍数。 */
		if ( strlen( $value ) < 40 || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $value ) || 0 !== strlen( $value ) % 4 ) {
			return null;
		}

		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! is_string( $decoded ) || '' === trim( $decoded ) ) {
			return null;
		}

		/* 解出来得是 UTF-8 的 HTML，而且真有标签 —— 否则当它不是。 */
		if ( ! mb_check_encoding( $decoded, 'UTF-8' ) || ! preg_match( '/<[a-zA-Z][^>]*>/', $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Shortcodes whose attributes could not be parsed, for {@see pcre_failures()}.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $pcre_failures = array();

	/**
	 * Shortcodes the attribute regex failed on during this request.
	 *
	 * 平时是空的。非空就说明有内容被整段跳过了 —— 这是个真实发生过的坏法，
	 * 而且不会自己报出来，所以留个口子让 doctor 问。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pcre_failures() {
		return self::$pcre_failures;
	}

	/**
	 * Break a string into ordered segments.
	 *
	 * 同一个切分函数给抽取和回填两头用 —— 两头各写一套迟早会走偏，而一旦走偏，
	 * 译文就会被塞进错误的位置。
	 *
	 * 每段是：
	 *   ['kind' => 'keep', 'text' => …]   原样保留
	 *   ['kind' => 'text', 'text' => …]   短代码之外的正文
	 *   ['kind' => 'attr', 'text' => …,
	 *    'before' => …, 'after' => …]     属性里的正文，前后是它在原串里的包裹
	 *
	 * @param string $input Raw text.
	 * @return array<int, array<string, string>>
	 */
	public static function segments( $input ) {
		$input    = (string) $input;
		$segments = array();
		$offset   = 0;

		if ( ! preg_match_all( '/\[[^\]]*\]/', $input, $matches, PREG_OFFSET_CAPTURE ) ) {
			if ( '' !== trim( $input ) ) {
				$segments[] = array( 'kind' => 'text', 'text' => $input );
			}

			return $segments;
		}

		foreach ( $matches[0] as $match ) {
			$code  = $match[0];
			$start = $match[1];

			if ( $start > $offset ) {
				$between = substr( $input, $offset, $start - $offset );

				$segments[] = array(
					'kind' => preg_match( '/[A-Za-z]/', $between ) ? 'text' : 'keep',
					'text' => $between,
				);
			}

			foreach ( self::split_shortcode( $code ) as $piece ) {
				$segments[] = $piece;
			}

			$offset = $start + strlen( $code );
		}

		if ( $offset < strlen( $input ) ) {
			$tail = substr( $input, $offset );

			$segments[] = array(
				'kind' => preg_match( '/[A-Za-z]/', $tail ) ? 'text' : 'keep',
				'text' => $tail,
			);
		}

		return $segments;
	}

	/**
	 * Split one shortcode into its skeleton and its prose attribute values.
	 *
	 * @param string $code One `[...]` group.
	 * @return array<int, array<string, string>>
	 */
	private static function split_shortcode( $code ) {
		$out    = array();
		$offset = 0;

		/* 短代码名字，用来在注册表里查它的规则。 */
		$tag = preg_match( '/^\[\/?\s*([a-zA-Z_][\w\-]*)/', $code, $t ) ? $t[1] : '';

		/*
		 * 三种写法都要认：`a="x"`、`a='x'`、`a=x`。
		 * 只认双引号的话 WPBakery（`text='Our Services'`）整家都抽不到 ——
		 * 而它是用得最多的短代码构建器之一。转义引号也要跳过，不然值会被截断。
		 *
		 * 🔴 引号里那段必须写成「展开式」，不能写 `(?:[^"\\]|\\.)*`。
		 *
		 * 后者是带选择分支的量词，PCRE 会为每个字符留回溯点；值一长（约两千字以上）
		 * 就撑爆 JIT 栈，preg_match_all 返回 false —— 不抛错、不报警，整条短代码
		 * 被当成「原样保留」，**它所有属性一起消失**。
		 *
		 * 2026-10-09 实测：一位买家页面上的表格是 18972 字的 base64 存在属性里，
		 * preg_last_error() = 6（JIT stack limit exhausted）。他只看到「表格不翻译」，
		 * 而真相是那一整个短代码从没被解析过。同页另外 7 个一千多字的表格是好的，
		 * 所以这个坏法会随值的长短忽隐忽现，最难查。
		 *
		 * 展开式 `[^"\\]*(?:\\.[^"\\]*)*` 没有歧义分支，是线性的，多长都不会爆。
		 */
		$pattern = '/([a-zA-Z_][\w\-]*)\s*=\s*(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|([^\s\]"\']+))/';

		$matched = preg_match_all( $pattern, $code, $attrs, PREG_OFFSET_CAPTURE );

		/*
		 * 正则本身出错和「这段没有属性」长得一样 —— 都是假值。分不清的话，
		 * 上面那种 bug 还会再来一次而我们还是看不见。所以单独记一笔，
		 * 让 doctor 能把它报出来。
		 */
		if ( false === $matched && PREG_NO_ERROR !== preg_last_error() ) {
			self::$pcre_failures[] = array(
				'tag'    => $tag,
				'length' => strlen( $code ),
				'error'  => preg_last_error_msg(),
			);
		}

		if ( ! $matched ) {
			return array( array( 'kind' => 'keep', 'text' => $code ) );
		}

		foreach ( $attrs[0] as $i => $whole ) {
			$name = $attrs[1][ $i ][0];

			/* 哪一种引号命中了，值就在对应那一组里。 */
			$value       = '';
			$value_start = -1;

			foreach ( array( 2, 3, 4 ) as $group ) {
				if ( isset( $attrs[ $group ][ $i ] ) && $attrs[ $group ][ $i ][1] >= 0 ) {
					$value       = $attrs[ $group ][ $i ][0];
					$value_start = $attrs[ $group ][ $i ][1];
					break;
				}
			}

			if ( $value_start < 0 ) {
				continue;
			}

			/*
			 * 🔴 编码过的 JSON 要在名字那一关**之前**判。
			 *
			 * 这类值的属性名通常是 `values`、`items`、`tabs` —— 名字本身说明不了里面
			 * 装的是配置还是正文，按名字筛会把它整条挡掉。但解开之后一眼就看得出来。
			 * 这里按内容判，而里面每一条仍各自过 is_prose，所以 `fa-star`、`modern`
			 * 这类配置值不会被翻。
			 */
			$json = self::encoded_json( $value );
			$b64  = $json ? null : self::encoded_html( $value );

			if ( ! $json && ! $b64 && ( ! self::is_content_attribute( $tag, $name ) || ! self::is_prose( $value ) ) ) {
				continue;
			}

			$out[] = array( 'kind' => 'keep', 'text' => substr( $code, $offset, $value_start - $offset ) );

			/*
			 * 🔴 存成百分号编码的值，要先解码再翻。
			 *
			 * 有些构建器把属性值 urlencode 之后存，`title="Our%20Services"` 这种。
			 * 不解码就直接发出去，模型看到的是一串掺着 %20 的乱码 —— 它会照着翻，
			 * 译文自然也是坏的。而且这种坏法不报错，页面照样显示。
			 */
			if ( $json ) {
				$out[] = array( 'kind' => 'json', 'text' => $value, 'count' => count( $json['strings'] ) );

				$offset = $value_start + strlen( $value );
				continue;
			}

			if ( $b64 ) {
				$out[] = array( 'kind' => 'b64html', 'text' => $value );

				$offset = $value_start + strlen( $value );
				continue;
			}

			$decoded = rawurldecode( $value );
			$encoded = ( $decoded !== $value );

			$out[] = array(
				'kind'     => 'attr',
				'text'     => $encoded ? $decoded : $value,
				'encoding' => $encoded ? 'url' : '',
			);

			$offset = $value_start + strlen( $value );
		}

		$out[] = array( 'kind' => 'keep', 'text' => substr( $code, $offset ) );

		return $out;
	}
}
