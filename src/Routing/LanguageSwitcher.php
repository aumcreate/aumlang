<?php
/**
 * Front-end language switcher: links to the current page in each language.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

use AumLang\Content\ContentLinker;
use AumLang\Language\Language;
use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Computes, for the current request, the URL of its equivalent in every active
 * language, and renders a switcher. Exposed as the `[aumlang_language_switcher]`
 * shortcode and the `aumlang_language_switcher()` template function.
 *
 * URL rules mirror HreflangManager: a translated singular maps the source
 * permalink into each language; an untranslated singular falls back to that
 * language's home; any other page maps the current URL by prefix.
 */
class LanguageSwitcher {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * URL converter.
	 *
	 * @var UrlConverter
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param Router           $router    Router.
	 * @param LanguageRegistry $languages Language registry.
	 * @param ContentLinker    $linker    Content linker.
	 * @param UrlConverter     $urls      URL converter.
	 */
	public function __construct( Router $router, LanguageRegistry $languages, ContentLinker $linker, UrlConverter $urls ) {
		$this->router    = $router;
		$this->languages = $languages;
		$this->linker    = $linker;
		$this->urls      = $urls;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'aumlang_language_switcher', array( $this, 'render_shortcode' ) );
		add_shortcode( 'aumlang_switcher', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_footer', array( $this, 'render_floating' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'maybe_append_to_menu' ), 10, 2 );
	}

	/**
	 * Resolved switcher display settings.
	 *
	 * @return array{floating:bool,position:string,show:string,menu_location:string}
	 */
	public function settings() {
		$all     = get_option( 'aumlang_settings', array() );
		$current = isset( $all['switcher'] ) ? (array) $all['switcher'] : array();

		return wp_parse_args(
			$current,
			array(
				'floating'      => false,
				'position'      => 'bottom-right',
				'show'          => 'name',
				'menu_location' => '',
				'menu_label'    => '',
			)
		);
	}

	/**
	 * Output a floating switcher in the footer when enabled.
	 *
	 * @return void
	 */
	public function render_floating() {
		$settings = $this->settings();

		if ( empty( $settings['floating'] ) ) {
			return;
		}

		/*
		 * A theme that places the switcher itself can stand this one aside. Without
		 * this the two overlap: both are fixed to the same corner, and neither knows
		 * about the other, so the site ends up with two switchers on top of each
		 * other and no way to turn just one of them off.
		 */
		if ( ! apply_filters( 'aumlang_render_floating_switcher', true, $settings ) ) {
			return;
		}

		$html = $this->render_dropdown( array( 'show' => $settings['show'] ) );

		if ( '' === $html ) {
			return;
		}

		$position = preg_replace( '/[^a-z-]/', '', (string) $settings['position'] );

		echo '<div class="aml-lang-floating aml-pos-' . esc_attr( $position ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
	}

	/**
	 * Append language links to a configured nav menu location.
	 *
	 * @param string $items Menu items HTML.
	 * @param object $args  wp_nav_menu args.
	 * @return string
	 */
	public function maybe_append_to_menu( $items, $args ) {
		$settings = $this->settings();

		if ( '' === $settings['menu_location'] ) {
			return $items;
		}

		$location = isset( $args->theme_location ) ? $args->theme_location : '';

		if ( $location !== $settings['menu_location'] ) {
			return $items;
		}

		$links = $this->get_links();

		if ( count( $links ) < 2 ) {
			return $items;
		}

		$label    = trim( (string) $settings['menu_label'] );
		$parent   = esc_html( $label );
		$children = '';
		$woodmart = $this->is_woodmart();

		foreach ( $links as $link ) {
			// With no custom label the parent is the current language and the
			// submenu lists the others; with a label, the submenu lists them all.
			if ( '' === $label && $link['current'] ) {
				$parent = $this->display( $link, $settings['show'] );
				continue;
			}

			$item_class = 'menu-item aml-lang-menu-item' . ( $link['current'] ? ' current-menu-item' : '' );

			if ( $woodmart ) {
				$item_class .= ' item-level-1 wd-event-hover';
			}

			$link_class = $woodmart ? ' class="woodmart-nav-link"' : '';
			$children  .= '<li class="' . esc_attr( $item_class ) . '"><a' . $link_class . ' href="' . esc_url( $link['url'] ) . '">' . $this->display( $link, $settings['show'] ) . '</a></li>';
		}

		if ( '' === $parent ) {
			$parent = esc_html__( 'Languages', 'aumlang' );
		}

		$parent_class = 'menu-item menu-item-has-children aml-lang-menu-parent';
		$link_class   = '';

		if ( $woodmart ) {
			$parent_class .= ' item-level-0 menu-simple-dropdown wd-event-hover';
			$link_class    = ' class="woodmart-nav-link"';
			$children      = '<div class="color-scheme-dark wd-design-default wd-dropdown-menu wd-dropdown">'
				. '<div class="container wd-entry-content"><ul class="wd-sub-menu color-scheme-dark">' . $children . '</ul></div></div>';
		} else {
			$children = '<ul class="sub-menu">' . $children . '</ul>';
		}

		$items .= '<li class="' . esc_attr( $parent_class ) . '">'
			. '<a' . $link_class . ' href="#" aria-haspopup="true"><span class="nav-link-text">' . $parent . '</span></a>'
			. $children
			. '</li>';

		return $items;
	}

	/**
	 * Whether the active site uses Woodmart's header walker markup.
	 *
	 * Woodmart wraps a submenu in .wd-dropdown-menu rather than rendering a
	 * direct ul.sub-menu. Matching that structure keeps a generated language
	 * menu folded below the header instead of expanding its height.
	 *
	 * @return bool
	 */
	private function is_woodmart() {
		return function_exists( 'woodmart_get_opt' );
	}

	/**
	 * Register the classic widget.
	 *
	 * @return void
	 */
	public function register_widget() {
		register_widget( SwitcherWidget::class );
	}

	/**
	 * Register the dynamic block.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'aumlang-switcher-block',
			AUMLANG_URL . 'src/assets/js/switcher-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			AUMLANG_VERSION,
			true
		);

		register_block_type(
			'aumlang/language-switcher',
			array(
				'editor_script'   => 'aumlang-switcher-block',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'show'        => array( 'type' => 'string', 'default' => 'name' ),
					'hideCurrent' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	/**
	 * Render callback for the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		return $this->render(
			array(
				'show'         => isset( $attributes['show'] ) ? $attributes['show'] : 'name',
				'hide_current' => ! empty( $attributes['hideCurrent'] ),
			)
		);
	}

	/**
	 * Register the front-end stylesheet.
	 *
	 * @return void
	 */
	public function enqueue() {
		$css = AUMLANG_DIR . 'src/assets/css/switcher.css';
		$js  = AUMLANG_DIR . 'src/assets/js/switcher.js';

		wp_enqueue_style( 'aumlang-switcher', AUMLANG_URL . 'src/assets/css/switcher.css', array(), file_exists( $css ) ? filemtime( $css ) : AUMLANG_VERSION );
		wp_enqueue_script( 'aumlang-switcher', AUMLANG_URL . 'src/assets/js/switcher.js', array(), file_exists( $js ) ? filemtime( $js ) : AUMLANG_VERSION, true );
	}

	/**
	 * Render the switcher as a dropdown: the current language is a toggle, the
	 * others fold into a menu. Scales to many languages without overflowing.
	 *
	 * @param array $args show (name|code|both|flag|flag_name), class (string).
	 * @return string
	 */
	public function render_dropdown( $args = array() ) {
		$args = wp_parse_args( $args, array( 'show' => 'name', 'class' => '' ) );

		$links = $this->get_links();

		if ( count( $links ) < 2 ) {
			return '';
		}

		$current = null;
		$others  = array();

		foreach ( $links as $link ) {
			if ( $link['current'] && null === $current ) {
				$current = $link;
			} else {
				$others[] = $link;
			}
		}

		if ( null === $current ) {
			$current = array_shift( $others );
		}

		$out = '<div class="aml-lang-dropdown ' . esc_attr( $args['class'] ) . '">'
			. '<button type="button" class="aml-lang-toggle" aria-haspopup="true" aria-expanded="false">'
			. $this->display( $current, $args['show'] )
			. ' <span class="aml-lang-caret" aria-hidden="true"></span></button>'
			. '<ul class="aml-lang-menu">';

		foreach ( $others as $link ) {
			$hreflang = '' !== $link['locale'] ? str_replace( '_', '-', $link['locale'] ) : $link['code'];
			$out     .= '<li><a href="' . esc_url( $link['url'] ) . '" lang="' . esc_attr( $hreflang ) . '" hreflang="' . esc_attr( $hreflang ) . '">' . $this->display( $link, $args['show'] ) . '</a></li>';
		}

		return $out . '</ul></div>';
	}

	/**
	 * Build the switch links for the current request.
	 *
	 * @return array<int, array{code:string,name:string,locale:string,url:string,current:bool,is_default:bool}>
	 */
	public function get_links() {
		$current = $this->router->get_current_code();
		$links   = array();

		foreach ( $this->languages->get_languages( true ) as $language ) {
			$links[] = array(
				'code'       => $language->code(),
				'name'       => $language->name(),
				'locale'     => $language->locale(),
				'url'        => $this->url_for( $language ),
				'current'    => $language->code() === $current,
				'is_default' => $language->is_default(),
			);
		}

		return $links;
	}

	/**
	 * The URL of the current content in a given language.
	 *
	 * @param Language $language Target language.
	 * @return string
	 */
	private function url_for( Language $language ) {
		$code = $language->code();

		/*
		 * A static front page is served at the site root, not at its ordinary
		 * page slug. Resolve it directly here: on a translated home page the
		 * query context can expose that ordinary slug (for example /fr/home/),
		 * which would otherwise make every language link point back to the same
		 * page instead of to each language's root URL.
		 */
		$queried_id    = (int) get_queried_object_id();
		$front_page_id = (int) get_option( 'page_on_front' );

		/*
		 * page_on_front is filtered to the current language while the request is
		 * being served. Comparing the two IDs directly therefore fails when one
		 * side is the source home page and the other is its translation. Compare
		 * their canonical source IDs instead, so every member of the homepage
		 * translation group is recognised as the homepage.
		 */
		$is_translated_front_page = $front_page_id
			&& $queried_id
			&& $this->linker->get_source_id( $queried_id ) === $this->linker->get_source_id( $front_page_id );

		if ( is_front_page() || $is_translated_front_page ) {
			return $this->language_root_url( $language );
		}

		if ( is_singular() ) {
			$object = get_queried_object();

			if ( $object instanceof \WP_Post ) {
				$translations = $this->linker->get_all_translations( $object->ID );

				if ( isset( $translations[ $code ] ) ) {
					$source_permalink = get_permalink( $this->linker->get_source_id( $object->ID ) );

					if ( $source_permalink ) {
						return $this->urls->convert( $source_permalink, $code );
					}
				}

				// No translation in this language — send them to its home.
				return $this->urls->convert( home_url( '/' ), $code );
			}
		}

		return $this->urls->convert( $this->current_url(), $code );
	}

	/**
	 * Public root URL for a language.
	 *
	 * This deliberately uses the stored site home rather than home_url(). Some
	 * themes/plugins filter home_url() to the *current* language. That is useful
	 * for ordinary links, but on a language switcher it can make every homepage
	 * entry inherit the current prefix (for example all four becoming /fr/).
	 *
	 * @param Language $language Target language.
	 * @return string
	 */
	private function language_root_url( Language $language ) {
		$home = (string) get_option( 'home' );

		if ( '' === $home ) {
			$home = home_url( '/' );
		}

		$parts  = wp_parse_url( $home );
		$origin = '';

		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$origin = $parts['scheme'] . '://' . $parts['host'];

			if ( ! empty( $parts['port'] ) ) {
				$origin .= ':' . $parts['port'];
			}
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		/*
		 * Do not ask Router whether a language has a prefix here. During a
		 * translated front-page request some themes cause the router's request
		 * state to be reused for every item, which makes every root URL inherit
		 * the current prefix. The rule is static and belongs to the language
		 * record plus this one setting: non-default languages always use their
		 * slug; the default does only when the setting enables it.
		 */
		$settings       = (array) get_option( 'aumlang_settings', array() );
		$should_prefix  = ! $language->is_default() || ! empty( $settings['default_language_has_prefix'] );

		if ( $should_prefix ) {
			$path = trim( $path . '/' . $language->slug(), '/' );
		}

		return $origin . ( '' === $path ? '/' : '/' . $path . '/' );
	}

	/**
	 * Render the switcher markup.
	 *
	 * @param array $args show (name|code|both), hide_current (bool), class (string).
	 * @return string
	 */
	public function render( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'show'         => 'name',
				'hide_current' => false,
				'class'        => '',
			)
		);

		$links = $this->get_links();

		if ( count( $links ) < 2 ) {
			return '';
		}

		$out = '<ul class="aml-lang-switcher ' . esc_attr( $args['class'] ) . '">';

		foreach ( $links as $link ) {
			if ( $args['hide_current'] && $link['current'] ) {
				continue;
			}

			$li_class = 'aml-lang-item aml-lang-' . $link['code'] . ( $link['current'] ? ' is-current' : '' );
			$hreflang = '' !== $link['locale'] ? str_replace( '_', '-', $link['locale'] ) : $link['code'];

			/*
			 * The flag is **decorative**, and the text next to it is what a screen reader announces.
			 * A flag is a country, not a language — Belgium has one flag and three official languages —
			 * so `show: 'flag'` still emits the name, visually hidden. A switcher that announces "flag"
			 * and nothing else is unusable without sight.
			 */
			$inner = $this->display( $link, $args['show'] );

			$out .= '<li class="' . esc_attr( $li_class ) . '">';

			if ( $link['current'] ) {
				$out .= '<span aria-current="true" lang="' . esc_attr( $hreflang ) . '">' . $inner . '</span>';
			} else {
				$out .= '<a href="' . esc_url( $link['url'] ) . '" lang="' . esc_attr( $hreflang ) . '" hreflang="' . esc_attr( $hreflang ) . '">' . $inner . '</a>';
			}

			$out .= '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'show'         => 'name',
				'hide_current' => '0',
				'class'        => '',
			),
			$atts,
			'aumlang_language_switcher'
		);

		return $this->render(
			array(
				'show'         => $atts['show'],
				'hide_current' => in_array( strtolower( (string) $atts['hide_current'] ), array( '1', 'true', 'yes' ), true ),
				'class'        => $atts['class'],
			)
		);
	}

	/**
	 * Build a link's label per display mode.
	 *
	 * @param array  $link Link data.
	 * @param string $show Display mode: name|code|both.
	 * @return string
	 */
	private function label( $link, $show ) {
		switch ( $show ) {
			case 'code':
				return strtoupper( $link['code'] );
			case 'both':
				return $link['name'] . ' (' . strtoupper( $link['code'] ) . ')';
			case 'flag':
				return '';                                  // Flag only; the name goes in visually hidden.
			case 'flag_name':
				return $link['name'];
			default:
				return $link['name'];
		}
	}

	/**
	 * Does this display mode draw a flag?
	 *
	 * @param string $show Display mode.
	 * @return bool
	 */
	private function wants_flag( $show ) {
		return in_array( $show, array( 'flag', 'flag_name' ), true );
	}

	/**
	 * One language, ready to drop inside a link: the flag (if the display mode
	 * asks for one) followed by the escaped label.
	 *
	 * The flag is **decorative** and the text beside it is what a screen reader
	 * announces. A flag is a country, not a language — Belgium flies one flag
	 * over three official languages — so `flag` still emits the language name,
	 * visually hidden. A switcher that announces nothing but "flag" is unusable
	 * without sight.
	 *
	 * @param array  $link One entry from get_links().
	 * @param string $show Display mode.
	 * @return string HTML.
	 */
	private function display( $link, $show ) {
		$label = $this->label( $link, $show );
		$flag  = $this->wants_flag( $show ) ? Flags::html( $link['locale'], $link['name'] ) : '';

		if ( '' === $flag ) {
			// No flag for this locale (or none asked for): never render an empty
			// link — fall back to the name so the item stays clickable.
			return esc_html( '' === $label ? $link['name'] : $label );
		}

		if ( '' === $label ) {
			return $flag . '<span class="screen-reader-text">' . esc_html( $link['name'] ) . '</span>';
		}

		return $flag . '<span class="aml-lang-text">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Absolute URL of the current request.
	 *
	 * @return string
	 */
	private function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $uri;
	}
}
