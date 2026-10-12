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
	 * Keys whose values are machinery, not prose — never translated, never collected.
	 *
	 * 这些名字是 WordPress / HTML / CSS 的通用词汇，不是哪一个主题或插件的私有约定；
	 * 所以这张表对任何人写的小工具都成立。
	 *
	 * @var string
	 */
	const SKIP_KEY = '/(^|_)(url|uri|link|href|target|rel|id|ids|slug|key|type|class|classes|style|css|icon|color|colour|bg|background|image|img|src|srcset|media|attachment|thumb|thumbnail|size|width|height|align|alignment|position|order|orderby|sortby|number|count|limit|offset|taxonomy|term|terms|cat|category|tag|tags|post_type|nav_menu|menu|format|date_format|time_format|lang|locale|email|mail|phone|tel|version|mode|layout|template|anchor|shortcode|embed|script|html_id|wrapper|container)(_|$)/i';

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
		 * 🔴 `widget_title` 只在小工具**自己调用**它时才触发，而那是约定，不是规则。
		 *
		 * 核心的小工具都调用；第三方的常常不调用 —— 它们直接 `echo $instance['title']`。
		 * 对那些小工具我们是全瞎的：标题停在源语言，而我们这边没有任何迹象说明漏了。
		 * 2026-10-12 实测一位买家的页头联系栏（第三方小工具，三个实例）：
		 * `title` 字段原封不动是英文，站长在我们界面里也找不到这几句。
		 *
		 * `widget_display_callback` 不一样 —— **WordPress 自己**在渲染每一个小工具之前触发它，
		 * 并把整个 $instance 交过来。不需要知道是谁写的小工具，也不需要它配合。
		 * 这里走的是和 option 相同的那条深走逻辑：只换字符串叶子，结构原样保留。
		 */
		add_filter( 'widget_display_callback', array( $this, 'translate_widget_instance' ), 10, 1 );

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
	 * Swap the text fields of any widget, whatever wrote it.
	 *
	 * 返回 false 会让 WordPress 整个不渲染这个小工具，所以任何意外都必须原样返回 $instance。
	 *
	 * @param mixed $instance Widget settings.
	 * @return mixed
	 */
	public function translate_widget_instance( $instance ) {
		if ( ! is_array( $instance ) ) {
			return $instance;
		}

		return $this->translate_deep( $instance, 0, self::CTX_WIDGET );
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
	private function translate_deep( $value, $depth = 0, $context = self::CTX_OPTION ) {
		if ( $depth > 6 ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value ) ? $value : $this->lookup( $context, $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				/*
				 * 🔴 按键跳过，在查表之前。
				 *
				 * 这些键装的是 URL、ID、图标名、CSS 类 —— 把它们换成译文会**弄坏小工具**，
				 * 而且坏法是沉默的：链接指向不存在的地址，图标变成空白。
				 * worth_translating() 在采集那一端已经挡掉大部分，但那一关是按「值长什么样」判断的，
				 * 而 `icon = "mail"` 这种值看上去和一个普通单词没有区别。键名是更硬的证据。
				 */
				if ( is_string( $k ) && preg_match( self::SKIP_KEY, $k ) ) {
					continue;
				}

				$value[ $k ] = $this->translate_deep( $v, $depth + 1, $context );
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
		 * 🔴 以前这里写死了八个**核心**小工具的 option 名，而且只取 `title`。
		 *
		 * 两个口子都漏：第三方小工具的 option 名我们猜不到（它由小工具类的 id_base 决定，
		 * 等于「任何字符串」），而正文字段里装着按钮文案、副标题、短说明这些读者天天看见的字。
		 * 2026-10-12 实测一位买家的页头：三个第三方小工具的 `title` 和一个按钮的 `text`
		 * 全部停在源语言，而且**在我们的字符串列表里根本不出现** —— 站长连「漏了」都看不到。
		 *
		 * 所以改成扫 `widget_` 开头的全部 option。这不是放宽标准，是把判断从
		 * 「这个 option 名我认不认识」换成「这个值像不像读者看的文字」：
		 *   · SKIP_KEY 按键名挡掉 url / icon / class 这类机器用的字段
		 *   · worth_translating() 按值的样子挡掉颜色、ID、序列化数据、带标签的 HTML
		 * 原来那条「不收正文」的顾虑由后者兜着 —— 带 `<tag` 的一律落选，
		 * 所以页面构建器的骨架仍然走抽取器那条路，不会整段送去翻。
		 */
		foreach ( $this->widget_option_names() as $option ) {
			$instances = get_option( $option, array() );

			if ( ! is_array( $instances ) ) {
				continue;
			}

			foreach ( $this->flatten_widget_fields( $instances ) as $text ) {
				if ( $this->worth_translating( $text ) ) {
					$out[ $text ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * Every option name that holds widget instances.
	 *
	 * WordPress 没有「列出所有小工具 option」的 API，但它们的名字有硬规则：
	 * `WP_Widget` 把实例存在 `widget_{$id_base}` 里。所以按前缀查 option 表是
	 * 对这条规则的直接查询，不是启发式猜测。
	 *
	 * @return string[]
	 */
	private function widget_option_names() {
		global $wpdb;

		$like = $wpdb->esc_like( 'widget_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 没有列出 option 名的 API；每次采集跑一次。
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		return array_map( 'strval', (array) $names );
	}

	/**
	 * Text leaves of a widget option, with the machinery keys left out.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 * @return string[]
	 */
	private function flatten_widget_fields( $value, $depth = 0 ) {
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

		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && preg_match( self::SKIP_KEY, $k ) ) {
				continue;
			}

			foreach ( $this->flatten_widget_fields( $v, $depth + 1 ) as $leaf ) {
				$out[] = $leaf;
			}
		}

		return $out;
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

		/*
		 * 邮箱和电话。SKIP_KEY 挡的是叫 email/phone 的字段，但这两样常常装在叫 text 的
		 * 字段里（「联系我们」那种小工具就是这么存的）。翻译它们等于把联系方式改错。
		 */
		if ( is_email( $text ) || preg_match( '/^[\+\(]?[\d][\d\s\-\(\)\.]{6,}$/', $text ) ) {
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
