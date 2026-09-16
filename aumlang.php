<?php
/**
 * Plugin Name:       AumLang – AI Multilingual Translation & SEO
 * Description:        Builder-friendly, SEO-complete multilingual translation for WordPress. By AumCreate.
 * Version:           1.0.11
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
define( 'AUMLANG_VERSION', '1.0.11' );
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
