<?php
/**
 * Persistence for language configuration.
 *
 * @package AumLang
 */

namespace AumLang\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes language rows in the {prefix}aumlang_languages table.
 */
class LanguageRepository {

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Fully-qualified table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'aumlang_languages';
	}

	/**
	 * Fetch all languages, ordered by sort order then code.
	 *
	 * @param bool $active_only Restrict to active languages.
	 * @return Language[]
	 */
	public function all( $active_only = false ) {
		$wpdb = $this->wpdb;
		$sql = "SELECT * FROM {$this->table}";

		if ( $active_only ) {
			$sql .= ' WHERE active = 1';
		}

		$sql .= ' ORDER BY sort_order ASC, code ASC';

		// Table name is trusted; no user input is interpolated.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $this->map_rows( (array) $rows );
	}

	/**
	 * Find a language by its code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function find( $code ) {
		$wpdb = $this->wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE code = %s", $code ),
			ARRAY_A
		);

		return $row ? Language::from_row( $row ) : null;
	}

	/**
	 * Return the default language, if one is set.
	 *
	 * @return Language|null
	 */
	public function get_default() {
		$wpdb = $this->wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE is_default = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? Language::from_row( $row ) : null;
	}

	/**
	 * Insert a new language row.
	 *
	 * @param Language $language Language to insert.
	 * @return int Inserted row id, or 0 on failure.
	 */
	public function insert( Language $language ) {
		$wpdb = $this->wpdb;
		$result = $wpdb->insert(
			$this->table,
			$this->to_row( $language ),
			$this->formats()
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an existing language identified by its code.
	 *
	 * @param Language $language Language to persist.
	 * @return bool Whether the query ran without error.
	 */
	public function update( Language $language ) {
		$wpdb = $this->wpdb;
		$result = $wpdb->update(
			$this->table,
			$this->to_row( $language ),
			array( 'code' => $language->code() ),
			$this->formats(),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete a language by code.
	 *
	 * @param string $code Language code.
	 * @return bool Whether a row was removed.
	 */
	public function delete( $code ) {
		$wpdb = $this->wpdb;
		$result = $wpdb->delete(
			$this->table,
			array( 'code' => $code ),
			array( '%s' )
		);

		return (bool) $result;
	}

	/**
	 * Clear the default flag on every language.
	 *
	 * @return void
	 */
	public function clear_default() {
		$wpdb = $this->wpdb;
		// Table name is trusted; no user input is interpolated.
		$wpdb->query( "UPDATE {$this->table} SET is_default = 0 WHERE is_default = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Map an array of rows into Language objects.
	 *
	 * @param array $rows Raw rows.
	 * @return Language[]
	 */
	private function map_rows( array $rows ) {
		$languages = array();

		foreach ( $rows as $row ) {
			$languages[] = Language::from_row( $row );
		}

		return $languages;
	}

	/**
	 * Convert a Language into a column => value row for writes.
	 *
	 * @param Language $language Language object.
	 * @return array
	 */
	private function to_row( Language $language ) {
		return array(
			'code'       => $language->code(),
			'locale'     => $language->locale(),
			'name'       => $language->name(),
			'slug'       => $language->slug(),
			'is_default' => $language->is_default() ? 1 : 0,
			'is_rtl'     => $language->is_rtl() ? 1 : 0,
			'sort_order' => $language->sort_order(),
			'active'     => $language->is_active() ? 1 : 0,
		);
	}

	/**
	 * Column formats matching to_row(), for $wpdb prepared writes.
	 *
	 * @return string[]
	 */
	private function formats() {
		return array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' );
	}
}
