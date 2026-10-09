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
	private static function parse( $file ) {
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $size <= 0 || $size > 512000 ) {
			return array();
		}

		$body = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

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
