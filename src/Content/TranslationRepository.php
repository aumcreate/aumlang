<?php
/**
 * Persistence for the translation linkage table.
 *
 * @package AumLang
 */

namespace AumLang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over {prefix}aumlang_translations.
 *
 * A row links a source object to its translation (target) in one language;
 * all language versions of the same content share a group_uuid. The source's
 * own (default-language) version is implicit and has no row.
 */
class TranslationRepository {

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
		'object_type'   => '%s',
		'source_id'     => '%d',
		'target_id'     => '%d',
		'group_uuid'    => '%s',
		'lang_code'     => '%s',
		'source_hash'   => '%s',
		'status'        => '%s',
		'translated_at' => '%s',
		'updated_at'    => '%s',
	);

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'aumlang_translations';
	}

	/**
	 * Find the translation row for a given source and language.
	 *
	 * @param int    $source_id Source object id.
	 * @param string $lang      Language code.
	 * @return array|null
	 */
	public function find_by_source_and_lang( $source_id, $lang ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_id = %d AND lang_code = %s",
				$source_id,
				$lang
			),
			ARRAY_A
		);
	}

	/**
	 * Find any one row belonging to a source (used to discover its group).
	 *
	 * @param int $source_id Source object id.
	 * @return array|null
	 */
	public function find_one_by_source( $source_id ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_id = %d LIMIT 1",
				$source_id
			),
			ARRAY_A
		);
	}

	/**
	 * Find the row that references a post as either source or target.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public function find_owning_row( $post_id ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_id = %d OR target_id = %d LIMIT 1",
				$post_id,
				$post_id
			),
			ARRAY_A
		);
	}

	/**
	 * All translation rows for a source.
	 *
	 * @param int $source_id Source object id.
	 * @return array[]
	 */
	public function find_by_source( $source_id ) {
		$wpdb = $this->wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source_id = %d ORDER BY lang_code ASC",
				$source_id
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
	 * Target (translation) post ids whose language is NOT the given one.
	 *
	 * @param string $lang Language code.
	 * @return int[]
	 */
	public function get_target_ids_not_in_language( $lang ) {
		$wpdb = $this->wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT target_id FROM {$this->table} WHERE lang_code <> %s", $lang )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Distinct source ids that have a translation in the given language.
	 *
	 * @param string $lang Language code.
	 * @return int[]
	 */
	public function get_source_ids_with_language( $lang ) {
		$wpdb = $this->wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT source_id FROM {$this->table} WHERE lang_code = %s", $lang )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A page of translation rows for the review screen, newest first.
	 *
	 * @param string $lang   Language filter, or '' for all.
	 * @param string $status Status filter, or '' for all.
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @return array[]
	 */
	public function get_filtered( $lang, $status, $offset, $limit ) {
		$wpdb = $this->wpdb;
		$where = array( 'object_type = %s' );
		$args  = array( 'post' );

		if ( '' !== $lang ) {
			$where[] = 'lang_code = %s';
			$args[]  = $lang;
		}

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$args[] = (int) $limit;
		$args[] = (int) $offset;

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only the literal fragments written above; every value is a placeholder filled by prepare().
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/**
	 * Count translation rows matching the review filters.
	 *
	 * @param string $lang   Language filter, or '' for all.
	 * @param string $status Status filter, or '' for all.
	 * @return int
	 */
	public function count_filtered( $lang, $status ) {
		$wpdb = $this->wpdb;
		$where = array( 'object_type = %s' );
		$args  = array( 'post' );

		if ( '' !== $lang ) {
			$where[] = 'lang_code = %s';
			$args[]  = $lang;
		}

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$sql = "SELECT COUNT(1) FROM {$this->table} WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above: literal fragments only, values are placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Mark rows as reviewed by id.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows updated.
	 */
	public function mark_reviewed( array $ids ) {
		$wpdb = $this->wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( current_time( 'mysql' ) ), $ids );

		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$this->table} SET status = 'reviewed', updated_at = %s WHERE id IN ( {$placeholders} )", $args )
		);
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
	 * Delete the row for a target post.
	 *
	 * @param int $target_id Target post id.
	 * @return int Rows deleted.
	 */
	public function delete_by_target( $target_id ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->delete( $this->table, array( 'target_id' => $target_id ), array( '%d' ) );
	}

	/**
	 * Delete every row referencing a post (as source or target).
	 *
	 * @param int $post_id Post id.
	 * @return int Rows deleted.
	 */
	public function delete_referencing( $post_id ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE source_id = %d OR target_id = %d",
				$post_id,
				$post_id
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
