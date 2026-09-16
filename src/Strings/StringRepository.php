<?php
/**
 * Persistence for standalone string translations (theme/plugin strings).
 *
 * @package AumLang
 */

namespace AumLang\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over {prefix}aumlang_strings. A row is one source string (in a text
 * domain / context) and its translation in one language.
 */
class StringRepository {

	/**
	 * Separator for building lookup keys.
	 */
	const KEY_SEP = "\x1f";

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
		$this->table = $wpdb->prefix . 'aumlang_strings';
	}

	/**
	 * Translation lookup map for a language: "context\x1fsource" => translation.
	 *
	 * Only rows with a non-empty translation are returned.
	 *
	 * @param string $lang Language code.
	 * @return array<string, string>
	 */
	public function get_map( $lang ) {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT string_context, source_text, translated_text
				 FROM {$this->table}
				 WHERE lang_code = %s AND translated_text <> ''",
				$lang
			),
			ARRAY_A
		);

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ $row['string_context'] . self::KEY_SEP . $row['source_text'] ] = $row['translated_text'];
		}

		return $map;
	}

	/**
	 * Load the request state for a language: translations map + known-keys set.
	 *
	 * @param string $lang Language code.
	 * @return array{translations:array<string,string>,known:array<string,bool>}
	 */
	public function get_state( $lang ) {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT string_context, source_text, translated_text FROM {$this->table} WHERE lang_code = %s",
				$lang
			),
			ARRAY_A
		);

		$translations = array();
		$known        = array();

		foreach ( (array) $rows as $row ) {
			$key           = $row['string_context'] . self::KEY_SEP . $row['source_text'];
			$known[ $key ] = true;

			if ( '' !== $row['translated_text'] ) {
				$translations[ $key ] = $row['translated_text'];
			}
		}

		return array(
			'translations' => $translations,
			'known'        => $known,
		);
	}

	/**
	 * Find a single string row.
	 *
	 * @param string $context Text domain / context.
	 * @param string $source  Source text.
	 * @param string $lang    Language code.
	 * @return array|null
	 */
	public function find( $context, $source, $lang ) {
		$wpdb = $this->wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE string_context = %s AND source_text = %s AND lang_code = %s",
				$context,
				$source,
				$lang
			),
			ARRAY_A
		);
	}

	/**
	 * Register a source string for a language (no-op if it already exists).
	 *
	 * Used during discovery so the string appears in the admin list with an
	 * empty translation, ready to be translated.
	 *
	 * @param string $context Text domain / context.
	 * @param string $source  Source text.
	 * @param string $lang    Language code.
	 * @return void
	 */
	public function register_source( $context, $source, $lang ) {
		$wpdb = $this->wpdb;
		if ( $this->find( $context, $source, $lang ) ) {
			return;
		}

		$wpdb->insert(
			$this->table,
			array(
				'string_context'  => $context,
				'source_text'     => $source,
				'source_hash'     => hash( 'sha256', $source ),
				'lang_code'       => $lang,
				'translated_text' => '',
				'status'          => 'none',
				'is_override'     => 0,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Count strings for a language (optionally matching a search term).
	 *
	 * @param string $lang   Language code.
	 * @param string $search Optional search term.
	 * @return int
	 */
	public function count( $lang, $search = '' ) {
		$wpdb = $this->wpdb;
		if ( '' === $search ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(1) FROM {$this->table} WHERE lang_code = %s", $lang )
			);
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$this->table} WHERE lang_code = %s AND ( source_text LIKE %s OR translated_text LIKE %s )",
				$lang,
				$like,
				$like
			)
		);
	}

	/**
	 * Count untranslated strings for a language.
	 *
	 * @param string $lang Language code.
	 * @return int
	 */
	public function count_untranslated( $lang ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(1) FROM {$this->table} WHERE lang_code = %s AND translated_text = ''", $lang )
		);
	}

	/**
	 * A page of strings for the admin list.
	 *
	 * @param string $lang   Language code.
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @param string $search Optional search term.
	 * @return array[]
	 */
	public function get_page( $lang, $offset, $limit, $search = '' ) {
		$wpdb = $this->wpdb;
		if ( '' === $search ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE lang_code = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$lang,
				$limit,
				$offset
			);
		} else {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE lang_code = %s AND ( source_text LIKE %s OR translated_text LIKE %s ) ORDER BY id ASC LIMIT %d OFFSET %d",
				$lang,
				$like,
				$like,
				$limit,
				$offset
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare() in both branches above.
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * A batch of untranslated strings to send to the AI.
	 *
	 * @param string $lang  Language code.
	 * @param int    $limit Batch size.
	 * @return array[] Rows with id, string_context, source_text.
	 */
	public function get_untranslated_batch( $lang, $limit ) {
		$wpdb = $this->wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, string_context, source_text FROM {$this->table} WHERE lang_code = %s AND translated_text = '' ORDER BY id ASC LIMIT %d",
				$lang,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Set a translation by row id.
	 *
	 * @param int    $id     Row id.
	 * @param string $text   Translation.
	 * @param string $status Status.
	 * @return void
	 */
	public function set_translation_by_id( $id, $text, $status = 'machine' ) {
		$wpdb = $this->wpdb;
		$wpdb->update(
			$this->table,
			array(
				'translated_text' => $text,
				'status'          => $status,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete strings by id.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Number of rows deleted.
	 */
	public function delete_ids( array $ids ) {
		$wpdb = $this->wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table} WHERE id IN ( {$placeholders} )", $ids )
		);
	}

	/**
	 * Set the translation of a string.
	 *
	 * @param string $context Text domain / context.
	 * @param string $source  Source text.
	 * @param string $lang    Language code.
	 * @param string $text    Translation.
	 * @param string $status  Status: machine|reviewed.
	 * @return void
	 */
	public function set_translation( $context, $source, $lang, $text, $status = 'machine' ) {
		$wpdb = $this->wpdb;
		$now      = current_time( 'mysql' );
		$existing = $this->find( $context, $source, $lang );

		if ( $existing ) {
			$wpdb->update(
				$this->table,
				array(
					'translated_text' => $text,
					'status'          => $status,
					'updated_at'      => $now,
				),
				array( 'id' => $existing['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return;
		}

		$wpdb->insert(
			$this->table,
			array(
				'string_context'  => $context,
				'source_text'     => $source,
				'source_hash'     => hash( 'sha256', $source ),
				'lang_code'       => $lang,
				'translated_text' => $text,
				'status'          => $status,
				'is_override'     => 0,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
