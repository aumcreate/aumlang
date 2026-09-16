<?php
/**
 * Persistence for node-level translation cache.
 *
 * @package AumLang
 */

namespace AumLang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over {prefix}aumlang_node_translations.
 *
 * Each row caches one node's translation for a group/language, keyed by a stable
 * node_path. The source_text_hash drives incremental translation: when a node's
 * source text is unchanged, its cached translation is reused instead of calling
 * the AI again. Rows flagged is_override are user edits and are never overwritten
 * by machine translation.
 */
class NodeTranslationRepository {

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
		$this->table = $wpdb->prefix . 'aumlang_node_translations';
	}

	/**
	 * Cached nodes for a group/language, keyed by node_path.
	 *
	 * @param string $group_uuid Group identifier.
	 * @param string $lang       Language code.
	 * @return array<string, array> node_path => row.
	 */
	public function get_map( $group_uuid, $lang ) {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_path, source_text_hash, translated_text, is_override
				 FROM {$this->table} WHERE group_uuid = %s AND lang_code = %s",
				$group_uuid,
				$lang
			),
			ARRAY_A
		);

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ $row['node_path'] ] = $row;
		}

		return $map;
	}

	/**
	 * Insert or update a cached node translation.
	 *
	 * Never changes the is_override flag (preserves manual overrides).
	 *
	 * @param string $group_uuid Group identifier.
	 * @param string $lang       Language code.
	 * @param string $node_path  Node path.
	 * @param string $hash       Source text hash.
	 * @param string $text       Translated text.
	 * @return void
	 */
	public function upsert( $group_uuid, $lang, $node_path, $hash, $text ) {
		$wpdb = $this->wpdb;
		$now = current_time( 'mysql' );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE group_uuid = %s AND lang_code = %s AND node_path = %s",
				$group_uuid,
				$lang,
				$node_path
			)
		);

		if ( $id ) {
			$wpdb->update(
				$this->table,
				array(
					'source_text_hash' => $hash,
					'translated_text'  => $text,
					'updated_at'       => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return;
		}

		$wpdb->insert(
			$this->table,
			array(
				'group_uuid'       => $group_uuid,
				'lang_code'        => $lang,
				'node_path'        => $node_path,
				'source_text_hash' => $hash,
				'translated_text'  => $text,
				'is_override'      => 0,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Find a single cached node row.
	 *
	 * @param string $group_uuid Group identifier.
	 * @param string $lang       Language code.
	 * @param string $node_path  Node path.
	 * @return array|null
	 */
	public function find( $group_uuid, $lang, $node_path ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE group_uuid = %s AND lang_code = %s AND node_path = %s",
				$group_uuid,
				$lang,
				$node_path
			),
			ARRAY_A
		);
	}

	/**
	 * Mark a node as a manual override with the given text.
	 *
	 * @param string $group_uuid Group identifier.
	 * @param string $lang       Language code.
	 * @param string $node_path  Node path.
	 * @param string $text       Override text.
	 * @return void
	 */
	public function set_override( $group_uuid, $lang, $node_path, $text ) {
		$wpdb = $this->wpdb;
		$now = current_time( 'mysql' );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE group_uuid = %s AND lang_code = %s AND node_path = %s",
				$group_uuid,
				$lang,
				$node_path
			)
		);

		if ( $id ) {
			$wpdb->update(
				$this->table,
				array(
					'translated_text' => $text,
					'is_override'     => 1,
					'updated_at'      => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			return;
		}

		$wpdb->insert(
			$this->table,
			array(
				'group_uuid'       => $group_uuid,
				'lang_code'        => $lang,
				'node_path'        => $node_path,
				'source_text_hash' => '',
				'translated_text'  => $text,
				'is_override'      => 1,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Delete a single cached node (e.g. to revert an override to machine output).
	 *
	 * @param string $group_uuid Group identifier.
	 * @param string $lang       Language code.
	 * @param string $node_path  Node path.
	 * @return int Rows deleted.
	 */
	public function delete_node( $group_uuid, $lang, $node_path ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->delete(
			$this->table,
			array(
				'group_uuid' => $group_uuid,
				'lang_code'  => $lang,
				'node_path'  => $node_path,
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete all cached nodes for a group/language.
	 *
	 * @param string $group_uuid Group identifier.
	 * @param string $lang       Language code.
	 * @return int Rows deleted.
	 */
	public function delete_for_language( $group_uuid, $lang ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->delete(
			$this->table,
			array(
				'group_uuid' => $group_uuid,
				'lang_code'  => $lang,
			),
			array( '%s', '%s' )
		);
	}
}
