<?php
/**
 * Translatable site text that lives in the database rather than in code.
 *
 * @package AumLang
 */

namespace AumLang\Strings;

use AumLang\Support\Str;

use AumLang\Builders\ConfigFileRules;
use AumLang\Routing\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Collects and serves strings stored as data, not as code.
 *
 * 🔴 为什么 gettext 那条管道不够。
 *
 * {@see StringTranslator} 挂在 gettext 过滤器上，所以主题和插件里用 `__()` 包起来
 * 的硬编码文字都覆盖得到。但站点上还有一大类文字**从来不经过 gettext**，因为它们
 * 不在代码里 —— 它们是站长自己填进数据库的：
 *
 *   · 菜单里手打的标签（存在 nav_menu_item 这个文章类型的 post_title 上）
 *   · 小工具里的标题和正文（存在 wp_options 的 widget_* 里）
 *   · 主题设置里的文案 —— 页脚版权行、首页大标题（存在 wp_options 里，
 *     由各家主题用 `<admin-texts>` 声明；我抽样真实配置文件时 62% 都带这一段）
 *
 * 2026-10-09 实测一位买家的日文页：导航菜单 47 项只有 8 项是日文。那 8 项恰好是
 * **没有自定义标签**、WordPress 直接拿页面标题来显示的；另外 39 项他在菜单里手打过
 * 标签，于是永远停在源语言。链接是对的（指向 /ja/…），只有文字没翻 —— 所以看起来
 * 像「菜单翻了一半」，而真相是这一整类字符串我们压根没有入口。
 *
 * 他没报这件事。是 `tools/coverage-audit.php` 在修完表格之后顺手量出来的。
 */
class DataStrings {

	/**
	 * Context for menu item labels.
	 */
	const CTX_MENU = 'aumlang-menu';

	/**
	 * Context for option values declared as site text.
	 */
	const CTX_OPTION = 'aumlang-option';

	/**
	 * Context for widget titles and bodies.
	 */
	const CTX_WIDGET = 'aumlang-widget';

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private $strings;

	/**
	 * Translation map for the current language, loaded once per request.
	 *
	 * @var array<string, string>|null
	 */
	private $map = null;

	/**
	 * Constructor.
	 *
	 * @param Router           $router  Router.
	 * @param StringRepository $strings String repository.
	 */
	public function __construct( Router $router, StringRepository $strings ) {
		$this->router  = $router;
		$this->strings = $strings;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * 只在前台、且当前不是默认语言时才挂 —— 后台要看见的是原文，
	 * 否则站长编辑菜单时会把译文存成原文，那是不可逆的数据损坏。
	 *
	 * @return void
	 */
	public function register() {
		if ( is_admin() || $this->router->is_default_language() ) {
			return;
		}

		/*
		 * 菜单：优先级 20，排在 MenuTranslator（10）之后。
		 * 它负责把链接换成译文页、并在标签恰好等于页面标题时顺带换掉标签；
		 * 我们补的是它换不了的那一部分 —— 站长手打的标签。
		 */
		add_filter( 'wp_nav_menu_objects', array( $this, 'translate_menu' ), 20, 2 );

		add_filter( 'widget_title', array( $this, 'translate_string' ), 10, 1 );

		/*
		 * 🔴 只给**声明过的**键各挂一个过滤器，不去拦所有 option 读取。
		 *
		 * 拦全部等于插到每一次 get_option 上，那是整站范围的性能和兼容风险；
		 * 而声明过的键是有界的（一个站通常几十个），逐个挂既精确又便宜。
		 */
		foreach ( ConfigFileRules::admin_texts() as $path ) {
			$root = explode( '/', $path )[0];

			add_filter( 'option_' . $root, array( $this, 'translate_option' ), 10, 2 );
		}
	}

	/**
	 * Swap menu item labels the site owner typed by hand.
	 *
	 * @param array  $items Menu item objects.
	 * @param object $args  wp_nav_menu args (unused).
	 * @return array
	 */
	public function translate_menu( $items, $args = null ) {
		unset( $args );

		foreach ( (array) $items as $item ) {
			if ( ! isset( $item->title ) || '' === trim( (string) $item->title ) ) {
				continue;
			}

			$item->title = $this->lookup( self::CTX_MENU, (string) $item->title );

			if ( isset( $item->attr_title ) && '' !== trim( (string) $item->attr_title ) ) {
				$item->attr_title = $this->lookup( self::CTX_MENU, (string) $item->attr_title );
			}
		}

		return $items;
	}

	/**
	 * Swap a widget title or body.
	 *
	 * @param mixed $value Current value.
	 * @return mixed
	 */
	public function translate_string( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}

		return $this->lookup( self::CTX_WIDGET, $value );
	}

	/**
	 * Swap a declared option value, walking into arrays.
	 *
	 * @param mixed  $value  Option value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function translate_option( $value, $option = '' ) {
		unset( $option );

		return $this->translate_deep( $value );
	}

	/**
	 * Recursive half of {@see translate_option()}.
	 *
	 * 数组里只换字符串叶子，结构一律原样保留 —— 主题读回去的必须还是它存的那个形状。
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private function translate_deep( $value, $depth = 0 ) {
		if ( $depth > 6 ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value ) ? $value : $this->lookup( self::CTX_OPTION, $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->translate_deep( $v, $depth + 1 );
			}
		}

		return $value;
	}

	/**
	 * The stored translation for one string, or the original.
	 *
	 * @param string $context Context.
	 * @param string $text    Source text.
	 * @return string
	 */
	private function lookup( $context, $text ) {
		if ( null === $this->map ) {
			$this->map = $this->strings->get_map( $this->router->get_current_code() );
		}

		$key = $context . StringRepository::KEY_SEP . $text;

		return isset( $this->map[ $key ] ) ? $this->map[ $key ] : $text;
	}

	/**
	 * Every data-stored string on this site, with its context.
	 *
	 * 采集和供给必须认同一套 context 和同一段原文，否则存进去的译文查不出来。
	 * 所以两边都只走这一个函数和 {@see lookup()}。
	 *
	 * @return array<int, array{context:string,text:string}>
	 */
	public function collect() {
		$out = array();

		foreach ( $this->menu_labels() as $text ) {
			$out[] = array( 'context' => self::CTX_MENU, 'text' => $text );
		}

		foreach ( $this->option_texts() as $text ) {
			$out[] = array( 'context' => self::CTX_OPTION, 'text' => $text );
		}

		foreach ( $this->widget_texts() as $text ) {
			$out[] = array( 'context' => self::CTX_WIDGET, 'text' => $text );
		}

		return $out;
	}

	/**
	 * Register every data string that has no row yet for this language.
	 *
	 * @param string $lang Language code.
	 * @return int How many were newly registered.
	 */
	public function discover( $lang ) {
		$added = 0;

		foreach ( $this->collect() as $one ) {
			if ( $this->strings->find( $one['context'], $one['text'], $lang ) ) {
				continue;
			}

			$this->strings->register_source( $one['context'], $one['text'], $lang );
			++$added;
		}

		return $added;
	}

	/**
	 * Menu item labels the owner typed by hand.
	 *
	 * @return string[]
	 */
	private function menu_labels() {
		$out = array();

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );

			foreach ( (array) $items as $item ) {
				foreach ( array( 'title', 'attr_title' ) as $field ) {
					$text = isset( $item->$field ) ? trim( (string) $item->$field ) : '';

					if ( '' !== $text && $this->worth_translating( $text ) ) {
						$out[ $text ] = true;
					}
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * Values behind the option paths declared as site text.
	 *
	 * @return string[]
	 */
	private function option_texts() {
		$out = array();

		foreach ( ConfigFileRules::admin_texts() as $path ) {
			$parts = explode( '/', $path );
			$value = get_option( array_shift( $parts ), null );

			/* 嵌套路径：一层一层走下去，走不通就算没有。 */
			foreach ( $parts as $step ) {
				if ( is_array( $value ) && array_key_exists( $step, $value ) ) {
					$value = $value[ $step ];
					continue;
				}

				$value = null;
				break;
			}

			foreach ( $this->flatten( $value ) as $text ) {
				if ( $this->worth_translating( $text ) ) {
					$out[ $text ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * Titles and bodies of the text-bearing widgets.
	 *
	 * @return string[]
	 */
	private function widget_texts() {
		$out = array();

		/*
		 * 🔴 只收**标题**，不收正文。
		 *
		 * 小工具的正文是 HTML 或区块标记（`<!-- wp:group -->…`）。整段送出去
		 * 等于把骨架交给模型 —— 这正是 1.0.17 之前把页面构建器标记当正文翻、
		 * 结果触发回声重试和递归拆分的那条爆炸路径。
		 *
		 * 要做对得按块拆、再按块回填，走 HtmlTextExtractor 那一套，而回填又要
		 * 写回 option 里的正确位置。那是另一件事，不塞进这一版。
		 * 标题是纯文本，现在就能做对，而它恰好是侧栏上最显眼的那行字。
		 */
		foreach ( array( 'widget_text', 'widget_custom_html', 'widget_media_image', 'widget_nav_menu', 'widget_archives', 'widget_categories', 'widget_recent-posts', 'widget_search' ) as $option ) {
			$instances = get_option( $option, array() );

			if ( ! is_array( $instances ) ) {
				continue;
			}

			foreach ( $instances as $instance ) {
				if ( ! is_array( $instance ) || ! isset( $instance['title'] ) ) {
					continue;
				}

				$text = trim( (string) $instance['title'] );

				if ( '' !== $text && $this->worth_translating( $text ) ) {
					$out[ $text ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * String leaves of an arbitrarily nested value.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 * @return string[]
	 */
	private function flatten( $value, $depth = 0 ) {
		if ( $depth > 6 ) {
			return array();
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value ) ? array() : array( trim( $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $item ) {
			foreach ( $this->flatten( $item, $depth + 1 ) as $leaf ) {
				$out[] = $leaf;
			}
		}

		return $out;
	}

	/**
	 * Whether a stored value looks like prose a reader sees.
	 *
	 * 这些 option 里混着的东西比正文多得多 —— 颜色、开关、ID、地址、CSS。
	 * 把它们送去翻译不只是浪费钱，译文写回去会**弄坏主题设置**。
	 * 所以这一关要严，宁可漏一条文案，不能翻一个配置值。
	 *
	 * @param string $text Candidate.
	 * @return bool
	 */
	private function worth_translating( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text || Str::len( $text ) > 2000 ) {
			return false;
		}

		/* 至少要有两个连着的字母，否则是数字、符号或空壳。 */
		if ( ! preg_match( '/\p{L}{2,}/u', $text ) ) {
			return false;
		}

		/* 开关、数字、颜色、ID 列表。 */
		if ( preg_match( '/^(?:on|off|yes|no|true|false|enabled?|disabled?|none|default|left|right|center|top|bottom)$/i', $text ) ) {
			return false;
		}

		if ( preg_match( '#^(?:https?://|//|/|\#|[a-z]+://)#i', $text ) ) {
			return false;
		}

		if ( preg_match( '/^#?[0-9a-f]{3,8}$/i', $text ) || preg_match( '/^rgba?\(/i', $text ) ) {
			return false;
		}

		/* 全小写加下划线/连字符的标识符：post_type、aumlang-menu。 */
		if ( preg_match( '/^[a-z0-9_\-]+$/', $text ) ) {
			return false;
		}

		/* CSS 片段、序列化数据、JSON。 */
		if ( preg_match( '/^[a-z\-]+\s*:\s*[^;]+;?$/i', $text )
			|| preg_match( '/^[aOs]:\d+:[{\"]/', $text )
			|| preg_match( '/^[\[{]/', $text ) ) {
			return false;
		}

		/* 文件名和类名。 */
		if ( preg_match( '/\.(?:php|css|js|png|jpe?g|svg|gif|webp|woff2?)$/i', $text ) ) {
			return false;
		}

		/*
		 * 带标签或区块注释的，交给抽取器那条路，不在这里当一整段文字翻。
		 * 这一关是兜底 —— 采集的地方已经只收纯文本字段了，但 option 里什么都可能有。
		 */
		if ( preg_match( '/<[a-zA-Z\/!]/', $text ) ) {
			return false;
		}

		return true;
	}
}
