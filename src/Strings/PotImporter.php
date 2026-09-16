<?php
/**
 * Imports translatable strings from theme/plugin .pot files.
 *
 * @package AumLang
 */

namespace AumLang\Strings;

use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Scans the active theme and plugins for .pot catalogs, parses the source
 * strings, and registers each one (untranslated) for every enabled non-default
 * language so they can be translated from the admin — no Poedit, and every
 * language at once.
 */
class PotImporter {

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private $repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param StringRepository $repository String repository.
	 * @param LanguageRegistry $languages  Language registry.
	 */
	public function __construct( StringRepository $repository, LanguageRegistry $languages ) {
		$this->repository = $repository;
		$this->languages  = $languages;
	}

	/**
	 * Scan and import all .pot strings for every non-default language.
	 *
	 * @return array{files:int,strings:int} Summary counts.
	 */
	public function import() {
		$langs = $this->target_languages();

		if ( empty( $langs ) ) {
			return array( 'files' => 0, 'strings' => 0 );
		}

		$files   = $this->find_pot_files();
		$strings = 0;

		foreach ( $files as $file ) {
			$domain = $this->domain_from_file( $file );

			foreach ( $this->parse( $file ) as $source ) {
				foreach ( $langs as $lang ) {
					$this->repository->register_source( $domain, $source, $lang );
				}

				++$strings;
			}
		}

		return array(
			'files'   => count( $files ),
			'strings' => $strings,
		);
	}

	/**
	 * Active non-default language codes.
	 *
	 * @return string[]
	 */
	private function target_languages() {
		$default = $this->languages->get_default_language();
		$codes   = array();

		foreach ( $this->languages->get_languages( true ) as $language ) {
			if ( ! $default || $language->code() !== $default->code() ) {
				$codes[] = $language->code();
			}
		}

		return $codes;
	}

	/**
	 * Locate .pot files in the active theme and plugins.
	 *
	 * @return string[]
	 */
	private function find_pot_files() {
		$dirs = array(
			get_template_directory() . '/languages',
			get_stylesheet_directory() . '/languages',
		);

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$dir = dirname( $plugin );

			if ( '.' !== $dir ) {
				$dirs[] = WP_PLUGIN_DIR . '/' . $dir . '/languages';
			}
		}

		$files = array();

		foreach ( array_unique( $dirs ) as $dir ) {
			foreach ( (array) glob( $dir . '/*.pot' ) as $file ) {
				$files[] = $file;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Derive the text domain from a .pot file name.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	private function domain_from_file( $file ) {
		return basename( $file, '.pot' );
	}

	/**
	 * Parse a .pot file into a list of source strings.
	 *
	 * @param string $file File path.
	 * @return string[]
	 */
	private function parse( $file ) {
		require_once ABSPATH . 'wp-includes/pomo/po.php';

		$po = new \PO();

		if ( ! $po->import_from_file( $file ) ) {
			return array();
		}

		$sources = array();

		foreach ( $po->entries as $entry ) {
			if ( ! empty( $entry->singular ) ) {
				$sources[ $entry->singular ] = true;
			}
		}

		return array_keys( $sources );
	}
}
