<?php
/**
 * Persistence for glossary term mappings.
 *
 * @package AumLang
 */

namespace AumLang\Translation\Glossary;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over {prefix}aumlang_glossary.
 *
 * A row pins how a source term must be translated: source_term -> target_term,
 * either for a specific language (lang_code) or for every language (lang_code
 * NULL — e.g. a brand name that must stay identical everywhere).
 */
class GlossaryRepository {

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
		$this->table = $wpdb->prefix . 'aumlang_glossary';
	}

	/**
	 * All glossary entries, newest first.
	 *
	 * @return array[]
	 */
	public function all() {
		$wpdb = $this->wpdb;
		return (array) $wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY id DESC",
			ARRAY_A
		);
	}

	/**
	 * Total number of entries.
	 *
	 * @return int
	 */
	public function count() {
		$wpdb = $this->wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$this->table}" );
	}

	/**
	 * Entries that apply when translating into a language: that language's own
	 * mappings plus the global (all-language) ones.
	 *
	 * @param string $lang Target language code.
	 * @return array[]
	 */
	public function get_for_language( $lang ) {
		$wpdb = $this->wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE lang_code = %s OR lang_code IS NULL OR lang_code = '' ORDER BY id ASC",
				$lang
			),
			ARRAY_A
		);
	}

	/**
	 * Insert a glossary entry.
	 *
	 * @param array $data Column => value (source_term, lang_code|null, target_term, case_sensitive).
	 * @return int Inserted id, or 0 on failure.
	 */
	public function insert( array $data ) {
		$wpdb = $this->wpdb;
		$result = $wpdb->insert(
			$this->table,
			array(
				'source_term'    => isset( $data['source_term'] ) ? $data['source_term'] : '',
				'lang_code'      => isset( $data['lang_code'] ) ? $data['lang_code'] : null,
				'target_term'    => isset( $data['target_term'] ) ? $data['target_term'] : '',
				'case_sensitive' => ! empty( $data['case_sensitive'] ) ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Delete a glossary entry.
	 *
	 * @param int $id Row id.
	 * @return int Rows deleted.
	 */
	public function delete( $id ) {
		$wpdb = $this->wpdb;
		return (int) $wpdb->delete( $this->table, array( 'id' => (int) $id ), array( '%d' ) );
	}
}
