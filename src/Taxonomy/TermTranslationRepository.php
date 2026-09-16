<?php
/**
 * Persistence for the term (taxonomy) translation linkage table.
 *
 * @package AumLang
 */

namespace AumLang\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over {prefix}aumlang_term_translations.
 *
 * A row links a source term to its translation (target) term in one language;
 * all language versions of the same term share a group_uuid. The source's own
 * (default-language) version is implicit and has no row. Mirrors
 * TranslationRepository but for terms, with its own table so post and term ids
 * never collide.
 */
class TermTranslationRepository {

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
	 * Column => printf format map for prepared writes.
	 *
	 * @var array<string, string>
	 */
	private $column_formats = array(
		'taxonomy'       => '%s',
		'source_term_id' => '%d',
		'target_term_id' => '%d',
		'group_uuid'     => '%s',
		'lang_code'      => '%s',
		'status'         => '%s',
		'updated_at'     => '%s',
	);

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'aumlang_term_translations';
	}

	/**
	 * Find the translation row for a given source term and language.
	 *
	 * @param int    $source_term_id Source term id.
	 * @param string $lang           Language code.
	 * @return array|null
	 */
	public function find_by_source_and_lang( $source_term_id, $lang ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_term_id = %d AND lang_code = %s",
				$source_term_id,
				$lang
			),
			ARRAY_A
		);
	}

	/**
	 * Find any one row belonging to a source term (used to discover its group).
	 *
	 * @param int $source_term_id Source term id.
	 * @return array|null
	 */
	public function find_one_by_source( $source_term_id ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_term_id = %d LIMIT 1",
				$source_term_id
			),
			ARRAY_A
		);
	}

	/**
	 * Find the row that references a term as either source or target.
	 *
	 * @param int $term_id Term id.
	 * @return array|null
	 */
	public function find_owning_row( $term_id ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_term_id = %d OR target_term_id = %d LIMIT 1",
				$term_id,
				$term_id
			),
			ARRAY_A
		);
	}

	/**
	 * All translation rows for a source term.
	 *
	 * @param int $source_term_id Source term id.
	 * @return array[]
	 */
	public function find_by_source( $source_term_id ) {
		$wpdb = $this->wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_term_id = %d ORDER BY lang_code ASC",
				$source_term_id
			),
			ARRAY_A
		);
	}

	/**
	 * All rows in a group.
	 *
	 * @param string $group_uuid Group identifier.
	 * @return array[]
	 */
	public function find_by_group( $group_uuid ) {
		$wpdb = $this->wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE group_uuid = %s ORDER BY lang_code ASC",
				$group_uuid
			),
			ARRAY_A
		);
	}

	/**
	 * Target (translation) term ids whose language is NOT the given one.
	 *
	 * @param string $lang Language code.
	 * @return int[]
	 */
	public function get_target_ids_not_in_language( $lang ) {
		$wpdb = $this->wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT target_term_id FROM {$this->table} WHERE lang_code <> %s", $lang )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Distinct source term ids that have a translation in the given language.
	 *
	 * @param string $lang Language code.
	 * @return int[]
	 */
	public function get_source_ids_with_language( $lang ) {
		$wpdb = $this->wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT source_term_id FROM {$this->table} WHERE lang_code = %s", $lang )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Insert a row.
	 *
	 * @param array $data Column => value.
	 * @return int Inserted id, or 0 on failure.
	 */
	public function insert( array $data ) {
		$wpdb = $this->wpdb;
		$result = $wpdb->insert( $this->table, $data, $this->formats_for( $data ) );

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row by id.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value.
	 * @return bool
	 */
	public function update_by_id( $id, array $data ) {
		$wpdb = $this->wpdb;
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			$this->formats_for( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete the row for a target term.
	 *
	 * @param int $target_term_id Target term id.
	 * @return int Rows deleted.
	 */
	public function delete_by_target( $target_term_id ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->delete( $this->table, array( 'target_term_id' => $target_term_id ), array( '%d' ) );
	}

	/**
	 * Delete every row referencing a term (as source or target).
	 *
	 * @param int $term_id Term id.
	 * @return int Rows deleted.
	 */
	public function delete_referencing( $term_id ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE source_term_id = %d OR target_term_id = %d",
				$term_id,
				$term_id
			)
		);
	}

	/**
	 * Build a format list matching the provided columns.
	 *
	 * @param array $data Column => value.
	 * @return string[]
	 */
	private function formats_for( array $data ) {
		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = isset( $this->column_formats[ $column ] ) ? $this->column_formats[ $column ] : '%s';
		}

		return $formats;
	}
}
