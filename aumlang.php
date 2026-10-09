<?php
/**
 * Plugin Name:       AumLang – AI Multilingual Translation & SEO
 * Plugin URI:       https://aumcreate.com/plugins/aumlang
 * Description:       AI translation for Elementor, Gutenberg and WooCommerce that keeps layouts intact, with per-language URLs, hreflang, canonicals and sitemaps handled.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            AumCreate
 * Author URI:        https://aumcreate.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aumlang
 * Domain Path:       /languages
 *
 * @package AumLang
 */

defined( 'ABSPATH' ) || exit;

/*
 * -------------------------------------------------------------------------
 * Constants.
 * -------------------------------------------------------------------------
 */
/*
 * 🔴 从插件头读，不写死。两处写同一个数字迟早会漂，而这个常量是功能键：
 * 它进 wp_enqueue_* 的版本参数（缓存键），也可能被当成升级判据。
 * 2026-10-07 aumnexcart 和 aumreserva 就是这么漂的 —— 头 1.3.1 / 常量 1.2.0，
 * 买家更新后浏览器继续发旧 CSS/JS，而两边日志都干净。
 * 一处定义，而不是两处再加一道闸去盯着它们。
 */
define( 'AUMLANG_VERSION', (string) ( get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] ?: '0' ) );
define( 'AUMLANG_DB_VERSION', '3' );
define( 'AUMLANG_FILE', __FILE__ );
define( 'AUMLANG_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUMLANG_URL', plugin_dir_url( __FILE__ ) );
define( 'AUMLANG_BASENAME', plugin_basename( __FILE__ ) );
define( 'AUMLANG_MIN_PHP', '7.4' );

/*
 * -------------------------------------------------------------------------
 * Autoloading.
 *
 * Prefer Composer's autoloader when present (development / built package);
 * otherwise fall back to a lightweight PSR-4 loader so the plugin runs
 * without a `composer install` step.
 * -------------------------------------------------------------------------
 */
if ( is_readable( AUMLANG_DIR . 'vendor/autoload.php' ) ) {
	require AUMLANG_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'AumLang\\';
			$length = strlen( $prefix );

			if ( strncmp( $prefix, $class, $length ) !== 0 ) {
				return;
			}

			$relative = substr( $class, $length );
			$path     = AUMLANG_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

/*
 * -------------------------------------------------------------------------
 * Activation / deactivation hooks.
 * -------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( '\AumLang\Core\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\AumLang\Core\Deactivator', 'deactivate' ) );

/*
 * Aumframe 桥：procedural，不走 PSR-4 自动加载，所以显式 require。
 *
 * 🔴 **必须等到 after_setup_theme 之后再 require，不能在这里直接 require。**
 * 插件比主题先加载，所以在插件启动时判断「主题里有没有旧版的桥」永远得到
 * 「没有」—— 然后主题再注册一遍，同一优先级上就有了两个回调做同一件事。
 * 这不是推断：2026-10-06 我把旧主题那份放回 orgcheck 靶测，p10 上确实出现了
 * 两个回调，我自己写的那个守卫被加载顺序绕过去了。延迟到 20 优先级之后，
 * 主题的 functions.php 已经跑完，这时判断才问得到真话。
 *
 * 这几个 filter 都在前台渲染时或后台页面上才触发，远晚于 after_setup_theme，
 * 所以延迟注册不会漏掉任何一次调用。
 */
add_action(
	'after_setup_theme',
	function () {
		if ( function_exists( 'aumcreate_aumlang_template_post_id' ) ) {
			return;
		}
		require_once AUMLANG_DIR . 'src/Integrations/aumframe-bridge.php';
	},
	20
);

/**
 * Main plugin accessor.
 *
 * @return \AumLang\Core\Plugin
 */
function aumlang() {
	return \AumLang\Core\Plugin::instance();
}

/**
 * Return the language switcher markup for the current page.
 *
 * @param array $args show (name|code|both), hide_current (bool), class (string).
 * @return string
 */
function aumlang_get_language_switcher( $args = array() ) {
	return aumlang()->switcher()->render( $args );
}

/**
 * Echo the language switcher (theme template tag).
 *
 * @param array $args See aumlang_get_language_switcher().
 * @return void
 */
function aumlang_language_switcher( $args = array() ) {
	echo aumlang_get_language_switcher( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
}

/*
 * -------------------------------------------------------------------------
 * Boot once all plugins are loaded.
 * -------------------------------------------------------------------------
 */
add_action( 'plugins_loaded', static function () {
	aumlang()->run();
} );
