<?php
/**
 * Activation routine: create tables, seed defaults, flag a rewrite flush.
 *
 * @package AumLang
 */

namespace AumLang\Core;

use AumLang\Language\Language;
use AumLang\Language\LanguageCatalog;
use AumLang\Language\LanguageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
class Activator {

	/**
	 * Entry point registered with register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::seed_settings();
		self::seed_default_language();

		update_option( 'aumlang_db_version', AUMLANG_DB_VERSION );

		// Rewrite rules are registered by a later module; defer the flush to the
		// next init so any future rules exist before we flush.
		update_option( 'aumlang_flush_rewrite', 1 );
	}

	/**
	 * Run pending schema upgrades on load when the stored DB version is behind.
	 *
	 * dbDelta is idempotent, so re-running create_tables() only adds what is
	 * missing (e.g. a table introduced in a later version).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current_version = (int) get_option( 'aumlang_db_version', 0 );

		if ( $current_version >= (int) AUMLANG_DB_VERSION ) {
			return;
		}

		self::create_tables();

		if ( $current_version < 3 ) {
			self::migrate_seeded_default_language_name();
		}

		update_option( 'aumlang_db_version', AUMLANG_DB_VERSION );
	}

	/**
	 * Create or upgrade the plugin's custom tables via dbDelta().
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'aumlang_';

		$schemas = array();

		// 3.1 Language configuration.
		$schemas[] = "CREATE TABLE {$prefix}languages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(10) NOT NULL,
			locale VARCHAR(20) NOT NULL DEFAULT '',
			name VARCHAR(100) NOT NULL DEFAULT '',
			slug VARCHAR(50) NOT NULL DEFAULT '',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_rtl TINYINT(1) NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY active (active)
		) {$charset_collate};";

		// 3.2 Source <-> translation linkage.
		$schemas[] = "CREATE TABLE {$prefix}translations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			group_uuid CHAR(36) NOT NULL DEFAULT '',
			lang_code VARCHAR(10) NOT NULL DEFAULT '',
			source_hash CHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'none',
			translated_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY group_uuid (group_uuid),
			KEY source_lang (source_id, lang_code),
			KEY status (status)
		) {$charset_collate};";

		// 3.3 Node-level translation cache (incremental translation).
		$schemas[] = "CREATE TABLE {$prefix}node_translations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_uuid CHAR(36) NOT NULL DEFAULT '',
			lang_code VARCHAR(10) NOT NULL DEFAULT '',
			node_path VARCHAR(255) NOT NULL DEFAULT '',
			source_text_hash CHAR(64) NOT NULL DEFAULT '',
			translated_text LONGTEXT NULL,
			is_override TINYINT(1) NOT NULL DEFAULT 0,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY node (group_uuid, lang_code, node_path(180))
		) {$charset_collate};";

		// 3.4 Glossary.
		$schemas[] = "CREATE TABLE {$prefix}glossary (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_term VARCHAR(255) NOT NULL DEFAULT '',
			lang_code VARCHAR(10) NULL DEFAULT NULL,
			target_term VARCHAR(255) NOT NULL DEFAULT '',
			case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY lang_code (lang_code)
		) {$charset_collate};";

		// 3.6 Term (taxonomy) translation linkage. Kept separate from the post
		// `translations` table because term ids share no sequence with post ids,
		// so a single id-keyed table would conflate the two.
		$schemas[] = "CREATE TABLE {$prefix}term_translations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			taxonomy VARCHAR(32) NOT NULL DEFAULT '',
			source_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			group_uuid CHAR(36) NOT NULL DEFAULT '',
			lang_code VARCHAR(10) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'none',
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_lang (source_term_id, lang_code),
			KEY target (target_term_id),
			KEY group_uuid (group_uuid)
		) {$charset_collate};";

		// 3.5 Standalone string translations.
		$schemas[] = "CREATE TABLE {$prefix}strings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			string_context VARCHAR(191) NOT NULL DEFAULT '',
			source_text LONGTEXT NULL,
			source_hash CHAR(64) NOT NULL DEFAULT '',
			lang_code VARCHAR(10) NOT NULL DEFAULT '',
			translated_text LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'none',
			is_override TINYINT(1) NOT NULL DEFAULT 0,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY ctx_lang (string_context, lang_code),
			KEY source_hash (source_hash)
		) {$charset_collate};";

		foreach ( $schemas as $schema ) {
			dbDelta( $schema );
		}
	}

	/**
	 * Write default settings if none exist yet.
	 *
	 * @return void
	 */
	private static function seed_settings() {
		if ( false !== get_option( 'aumlang_settings', false ) ) {
			return;
		}

		add_option(
			'aumlang_settings',
			array(
				'default_language_has_prefix' => false,
				'delete_data_on_uninstall'    => false,
			)
		);
	}

	/**
	 * Seed the site's current locale as the default language when the table is empty.
	 *
	 * @return void
	 */
	private static function seed_default_language() {
		global $wpdb;

		$repository = new LanguageRepository( $wpdb );

		if ( ! empty( $repository->all() ) ) {
			return;
		}

		$locale = get_locale();
		$code   = strtok( $locale, '_' );

		if ( empty( $code ) ) {
			$code = 'en';
		}

		$catalog = LanguageCatalog::get( $code );

		$repository->insert(
			new Language(
				array(
					'code'       => $code,
					'locale'     => $locale,
					'name'       => $catalog ? $catalog['name'] : $code,
					'slug'       => $code,
					'is_default' => true,
					'is_rtl'     => $catalog ? $catalog['rtl'] : false,
					'sort_order' => 0,
					'active'     => true,
				)
			)
		);
	}

	/**
	 * Repair the default language created by versions that used its code as its
	 * display name (for example, "en" instead of "English"). Only an untouched
	 * seeded value is changed, so custom names are preserved.
	 *
	 * @return void
	 */
	private static function migrate_seeded_default_language_name() {
		global $wpdb;

		$repository = new LanguageRepository( $wpdb );
		$language   = $repository->get_default();

		if ( ! $language || $language->name() !== $language->code() ) {
			return;
		}

		$catalog = LanguageCatalog::get( $language->code() );

		if ( ! $catalog ) {
			return;
		}

		$data           = $language->to_array();
		$data['name']   = $catalog['name'];
		$data['is_rtl'] = $catalog['rtl'];

		$repository->update( new Language( $data ) );
	}
}
