<?php
/**
 * Deactivation routine. Flushes rewrite rules; never deletes data.
 *
 * @package AumLang
 */

namespace AumLang\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation.
 */
class Deactivator {

	/**
	 * Entry point registered with register_deactivation_hook().
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clear any custom rewrite rules this plugin added.
		flush_rewrite_rules();

		delete_option( 'aumlang_flush_rewrite' );
	}
}
