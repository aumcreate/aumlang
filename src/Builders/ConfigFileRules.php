<?php
/**
 * Reads translatable-field declarations that themes and plugins ship with themselves.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * 🔴 为什么读这个文件，以及为什么**不是**抄别人的表。
 *
 * 「哪个短代码的哪个属性装着正文」这件事没有自动判断的银弹，只能靠声明。
 * 多语言生态里已经有一个事实标准：主题和插件作者在自己根目录放一个
 * `wpml-config.xml`，里面写明自己哪些字段要翻译。实测三个热门插件有两个自带。
 *
 * 有人维护着一个公共仓库收集这些文件，但那个仓库**没有声明许可证** ——
 * 没有声明就等于保留所有权利，照抄进我们的插件是侵权，不管对方插件本体是不是 GPL。
 *
 * 所以这里做的是另一件事：**读用户自己站上已经装着的那一份**。
 * 文件是作者本人放在那里、专门用来说明这件事的；我们不复制、不分发，
 * 只是在用户的服务器上读它。作者更新了，我们自动跟上，一行代码都不用改。
 *
 * 读不到也不要紧 —— 退回我们自己的内置表和按名字识别。
 */
class ConfigFileRules {

	/**
	 * 解析结果缓存多久。文件只在装插件/换主题时才变。
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * `type` 取这些值时，内容仍然是可以直接翻译的文字。
	 *
	 * 其余的（media-url / media-ids / post-ids / taxonomy-ids / link / product /
	 * attachment …）指的是要重新映射的地址和 ID，不是文字。
	 */
	const TEXT_TYPES = array( 'line', 'area', 'text', 'textarea', 'textfield' );

	/**
	 * Shortcode rules declared by the themes and plugins installed on this site.
	 *
	 * @return array<string, string[]> Tag => attribute names.
	 */
	public static function collect() {
		$cached = get_transient( 'aumlang_config_rules' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rules = array();

		foreach ( self::files() as $file ) {
			foreach ( self::parse( $file ) as $tag => $attributes ) {
				$rules[ $tag ] = isset( $rules[ $tag ] )
					? array_values( array_unique( array_merge( $rules[ $tag ], $attributes ) ) )
					: $attributes;
			}
		}

		set_transient( 'aumlang_config_rules', $rules, self::TTL );

		return $rules;
	}

	/**
	 * Custom fields declared by the themes and plugins installed on this site.
	 *
	 * 🔴 为什么这件事值得接。
	 *
	 * 主题和插件把不少给访客看的文字存在 post meta 里 —— 副标题、按钮文案、规格说明。
	 * 哪些该翻、哪些只该原样复制（商品编号、尺寸），只有写它的人清楚，
	 * 而他们已经在自己根目录那份声明里写明了。
	 *
	 * 我们本来就有这个能力，只是列表要站长一个个手填 —— 等于让用户去做插件作者
	 * 已经做完的事。接上之后装了就对，不用问。
	 *
	 * 返回两组，因为「复制过去」和「送去翻译」本来就是两个决定（这个类原本就这么分）：
	 *   translate —— 复制，并且允许送去翻译
	 *   copy      —— 只复制，永远不送翻（编号、SKU、日期这类）
	 * `action="ignore"` 的两组都不进，等于不管它。
	 *
	 * @return array{translate: string[], copy: string[]}
	 */
	public static function custom_fields() {
		$cached = get_transient( 'aumlang_config_fields' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array(
			'translate' => array(),
			'copy'      => array(),
		);

		foreach ( self::files() as $file ) {
			$found = self::parse_fields( $file );

			$out['translate'] = array_merge( $out['translate'], $found['translate'] );
			$out['copy']      = array_merge( $out['copy'], $found['copy'] );
		}

		$out['copy'] = array_values( array_unique( $out['copy'] ) );

		/*
		 * 同一个键被两处声明成不同动作时，以「只复制」为准。
		 * 少翻一个字段是缺口，把编号翻掉是事故 —— 保守的那一侧才是对的。
		 */
		$out['translate'] = array_values( array_diff( array_unique( $out['translate'] ), $out['copy'] ) );

		set_transient( 'aumlang_config_fields', $out, self::TTL );

		return $out;
	}

	/**
	 * Pull the custom-field declarations out of one file.
	 *
	 * @param string $file Absolute path.
	 * @return array{translate: string[], copy: string[]}
	 */
	private static function parse_fields( $file ) {
		$xml = self::load( $file );
		$out = array(
			'translate' => array(),
			'copy'      => array(),
		);

		if ( ! $xml || ! isset( $xml->{'custom-fields'} ) ) {
			return $out;
		}

		foreach ( $xml->{'custom-fields'}->{'custom-field'} as $field ) {
			$name = trim( (string) $field );

			if ( '' === $name ) {
				continue;
			}

			$action = isset( $field['action'] ) ? strtolower( trim( (string) $field['action'] ) ) : 'translate';

			if ( 'translate' === $action ) {
				$out['translate'][] = $name;
			} elseif ( 'copy' === $action || 'copy-once' === $action ) {
				$out['copy'][] = $name;
			}

			/* 其余（ignore / nothing）两组都不进。 */
		}

		return $out;
	}

	/**
	 * Post types and taxonomies the installed themes and plugins say are translatable.
	 *
	 * 这些声明只影响**默认值** —— 站长自己在设置里勾过之后，以他的选择为准。
	 * 作者说「我这个商品类型是要翻的」，是个合理的起点，不是不可推翻的命令。
	 *
	 * @return array{post_types: string[], taxonomies: string[]}
	 */
	public static function translatable_types() {
		$cached = get_transient( 'aumlang_config_types' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array(
			'post_types' => array(),
			'taxonomies' => array(),
		);

		foreach ( self::files() as $file ) {
			$xml = self::load( $file );

			if ( ! $xml ) {
				continue;
			}

			if ( isset( $xml->{'custom-types'} ) ) {
				foreach ( $xml->{'custom-types'}->{'custom-type'} as $node ) {
					$name = trim( (string) $node );

					if ( '' !== $name && self::wants_translation( $node ) ) {
						$out['post_types'][] = $name;
					}
				}
			}

			if ( isset( $xml->taxonomies ) ) {
				foreach ( $xml->taxonomies->taxonomy as $node ) {
					$name = trim( (string) $node );

					if ( '' !== $name && self::wants_translation( $node ) ) {
						$out['taxonomies'][] = $name;
					}
				}
			}
		}

		$out['post_types'] = array_values( array_unique( $out['post_types'] ) );
		$out['taxonomies'] = array_values( array_unique( $out['taxonomies'] ) );

		set_transient( 'aumlang_config_types', $out, self::TTL );

		return $out;
	}

	/**
	 * Whether a declaration asks for translation rather than just registration.
	 *
	 * 写法不统一：有的写 `translate="1"`，有的写 `translate="2"`（表示「每种语言各一份」），
	 * 有的干脆不写。不写时按「要翻」处理 —— 作者特地把它列进来，本意就是要我们管它；
	 * 明确写 0 的才跳过。
	 *
	 * @param \SimpleXMLElement $node Declaration node.
	 * @return bool
	 */
	private static function wants_translation( $node ) {
		if ( ! isset( $node['translate'] ) ) {
			return true;
		}

		return '0' !== trim( (string) $node['translate'] );
	}

	/**
	 * Candidate files: the active theme, its parent, and every active plugin.
	 *
	 * @return string[]
	 */
	private static function files() {
		$files = array(
			/*
			 * 我们自己那份，和第三方的走同一个解析器 —— 格式一样，就只会有一条代码路径。
			 * 加一个构建器只要改这个文件，不用碰 PHP。
			 */
			AUMLANG_DIR . 'builders.xml',
			get_stylesheet_directory() . '/wpml-config.xml',
			get_template_directory() . '/wpml-config.xml',
		);

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$dir = dirname( (string) $plugin );

			if ( '.' === $dir || '' === $dir ) {
				continue;
			}

			$files[] = WP_PLUGIN_DIR . '/' . $dir . '/wpml-config.xml';
		}

		return array_values( array_unique( array_filter( $files, 'is_readable' ) ) );
	}

	/**
	 * Pull the shortcode declarations out of one file.
	 *
	 * 文件是第三方写的，什么格式都可能遇到 —— 解析失败就当没有这个文件，
	 * 绝不能让一份坏 XML 把整个翻译流程打断。
	 *
	 * @param string $file Absolute path.
	 * @return array<string, string[]>
	 */
	private static function load( $file ) {
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $size <= 0 || $size > 512000 ) {
			return null;
		}

		$body = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $xml ? $xml : null;
	}

	/**
	 * Pull the shortcode declarations out of one file.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, string[]>
	 */
	private static function parse( $file ) {
		$xml = self::load( $file );

		if ( ! $xml || ! isset( $xml->shortcodes ) ) {
			return array();
		}

		$rules = array();

		foreach ( $xml->shortcodes->shortcode as $shortcode ) {
			$tag = isset( $shortcode->tag ) ? strtolower( trim( (string) $shortcode->tag ) ) : '';

			if ( '' === $tag || ! isset( $shortcode->attributes ) ) {
				continue;
			}

			$attributes = array();

			foreach ( $shortcode->attributes->attribute as $attribute ) {
				$name = trim( (string) $attribute );

				if ( '' === $name ) {
					continue;
				}

				/*
				 * 🔴 带 type / encoding 的声明一律跳过，不要当普通属性收下。
				 *
				 * 这个格式里 `type` 说的往往**不是文字**：`media-url` 是图片地址、
				 * `post-ids` 和 `taxonomy-ids` 是 ID、`link` 是要重新映射的链接。
				 * 把它们当普通属性读，就会把图片地址和 ID 当句子送去翻译 ——
				 * 回来的是被「翻译」过的 URL 和乱掉的 ID，图片 404、链接失效，
				 * 而页面照常显示、不报任何错。
				 *
				 * `encoding`（base64、各家自己的编码）同理：值不是明文，翻了就是坏的。
				 *
				 * 等我们真支持某一种 type 时，再把它从这里放行 —— 在那之前，
				 * **少翻一个字段**远好过**翻坏一个字段**。
				 */
				$type     = isset( $attribute['type'] ) ? strtolower( trim( (string) $attribute['type'] ) ) : '';
				$encoding = isset( $attribute['encoding'] ) ? trim( (string) $attribute['encoding'] ) : '';

				if ( '' !== $encoding ) {
					continue;
				}

				if ( '' !== $type && ! in_array( $type, self::TEXT_TYPES, true ) ) {
					continue;
				}

				$attributes[] = $name;
			}

			if ( $attributes ) {
				$rules[ $tag ] = $attributes;
			}
		}

		return $rules;
	}
}
