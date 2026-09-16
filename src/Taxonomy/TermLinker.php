<?php
/**
 * Maintains the relationship between a source term and its translations.
 *
 * @package AumLang
 */

namespace AumLang\Taxonomy;

use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Domain API over term translation groups.
 *
 * Model mirrors ContentLinker: every language version of a term belongs to a
 * group (group_uuid). The source term is authored in the site default language
 * and is implicit (no row); each non-default language is one row linking the
 * source term to its translated term.
 */
class TermLinker {

	/**
	 * Persistence backend.
	 *
	 * @var TermTranslationRepository
	 */
	private $repository;

	/**
	 * Language registry (for the default language code).
	 *
	 * @var LanguageRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param TermTranslationRepository $repository Persistence backend.
	 * @param LanguageRegistry          $registry   Language registry.
	 */
	public function __construct( TermTranslationRepository $repository, LanguageRegistry $registry ) {
		$this->repository = $repository;
		$this->registry   = $registry;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'delete_term', array( $this, 'on_term_deleted' ) );
	}

	/**
	 * Link a translated term to a source term's group in the given language.
	 *
	 * @param int    $source_term_id Source term id (resolved to canonical source).
	 * @param int    $target_term_id Translated term id.
	 * @param string $taxonomy       Taxonomy name.
	 * @param string $lang           Translation language code.
	 * @return string Group UUID, or empty string when nothing was stored.
	 */
	public function link( $source_term_id, $target_term_id, $taxonomy, $lang ) {
		$lang           = $this->normalize_code( $lang );
		$source_term_id = $this->get_source_id( (int) $source_term_id );
		$target_term_id = (int) $target_term_id;

		// The source term's own default-language version is implicit.
		if ( '' === $lang || $lang === $this->default_code() ) {
			return (string) $this->get_group_uuid( $source_term_id );
		}

		$existing = $this->repository->find_one_by_source( $source_term_id );
		$group    = $existing ? $existing['group_uuid'] : wp_generate_uuid4();
		$now      = current_time( 'mysql' );

		$row = $this->repository->find_by_source_and_lang( $source_term_id, $lang );

		if ( $row ) {
			$this->repository->update_by_id(
				(int) $row['id'],
				array(
					'target_term_id' => $target_term_id,
					'taxonomy'       => $taxonomy,
					'updated_at'     => $now,
				)
			);
		} else {
			$this->repository->insert(
				array(
					'taxonomy'       => $taxonomy,
					'source_term_id' => $source_term_id,
					'target_term_id' => $target_term_id,
					'group_uuid'     => $group,
					'lang_code'      => $lang,
					'status'         => 'none',
					'updated_at'     => $now,
				)
			);
		}

		/**
		 * Fires after a translated term is linked to its source.
		 *
		 * @param int    $source_term_id Source term id.
		 * @param int    $target_term_id Translated term id.
		 * @param string $lang           Language code.
		 * @param string $group          Group UUID.
		 */
		do_action( 'aumlang_term_linked', $source_term_id, $target_term_id, $lang, $group );

		return $group;
	}

	/**
	 * Get the translated term id of a term in a language.
	 *
	 * Accepts either the source term id or any translation id. Returns the source
	 * id for the default language.
	 *
	 * @param int    $term_id Any term in the group.
	 * @param string $lang    Target language code.
	 * @return int|null
	 */
	public function get_translation( $term_id, $lang ) {
		$lang   = $this->normalize_code( $lang );
		$source = $this->get_source_id( (int) $term_id );

		if ( $lang === $this->default_code() ) {
			return $source;
		}

		$row = $this->repository->find_by_source_and_lang( $source, $lang );

		return $row ? (int) $row['target_term_id'] : null;
	}

	/**
	 * Get all language versions of a term as lang => term_id (incl. the source).
	 *
	 * @param int $term_id Any term in the group.
	 * @return array<string, int>
	 */
	public function get_all_translations( $term_id ) {
		$source  = $this->get_source_id( (int) $term_id );
		$default = $this->default_code();

		$map = array();

		if ( '' !== $default ) {
			$map[ $default ] = $source;
		}

		foreach ( $this->repository->find_by_source( $source ) as $row ) {
			$map[ $row['lang_code'] ] = (int) $row['target_term_id'];
		}

		return $map;
	}

	/**
	 * Get the group UUID a term belongs to, if any.
	 *
	 * @param int $term_id Term id.
	 * @return string|null
	 */
	public function get_group_uuid( $term_id ) {
		$row = $this->repository->find_owning_row( (int) $term_id );

		return $row ? $row['group_uuid'] : null;
	}

	/**
	 * Resolve any term id to its group's canonical source id.
	 *
	 * A term with no group is treated as its own source.
	 *
	 * @param int $term_id Term id.
	 * @return int
	 */
	public function get_source_id( $term_id ) {
		$row = $this->repository->find_owning_row( (int) $term_id );

		return $row ? (int) $row['source_term_id'] : (int) $term_id;
	}

	/**
	 * Whether a term is a translation (target) of some source.
	 *
	 * @param int $term_id Term id.
	 * @return bool
	 */
	public function is_translation( $term_id ) {
		$row = $this->repository->find_owning_row( (int) $term_id );

		return $row && (int) $row['target_term_id'] === (int) $term_id;
	}

	/**
	 * Term ids to hide from listings in the given language: translated terms of
	 * other languages, plus source terms already translated here.
	 *
	 * @param string $lang Current language code.
	 * @return int[]
	 */
	public function get_terms_to_hide_for_language( $lang ) {
		$lang = $this->normalize_code( $lang );

		$other_language = $this->repository->get_target_ids_not_in_language( $lang );
		$sources_here   = $this->repository->get_source_ids_with_language( $lang );

		return array_values( array_unique( array_merge( $other_language, $sources_here ) ) );
	}

	/**
	 * Get the translation status of a source term/language.
	 *
	 * @param int    $term_id Source or translation term id.
	 * @param string $lang    Language code.
	 * @return string none|machine|reviewed|stale
	 */
	public function get_status( $term_id, $lang ) {
		$source = $this->get_source_id( (int) $term_id );
		$row    = $this->repository->find_by_source_and_lang( $source, $this->normalize_code( $lang ) );

		return $row ? $row['status'] : 'none';
	}

	/**
	 * Update the translation status of a link.
	 *
	 * @param int    $term_id Source or translation term id.
	 * @param string $lang    Language code.
	 * @param string $status  Status: none|machine|reviewed|stale.
	 * @return bool
	 */
	public function set_status( $term_id, $lang, $status ) {
		$source = $this->get_source_id( (int) $term_id );
		$row    = $this->repository->find_by_source_and_lang( $source, $this->normalize_code( $lang ) );

		if ( ! $row ) {
			return false;
		}

		return $this->repository->update_by_id(
			(int) $row['id'],
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Clean up links when a term is deleted.
	 *
	 * @param int $term_id Deleted term id.
	 * @return void
	 */
	public function on_term_deleted( $term_id ) {
		$this->repository->delete_referencing( (int) $term_id );
	}

	/**
	 * Default language code, or empty string when none is configured.
	 *
	 * @return string
	 */
	private function default_code() {
		$default = $this->registry->get_default_language();

		return $default ? $default->code() : '';
	}

	/**
	 * Normalize a language code.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private function normalize_code( $code ) {
		return strtolower( trim( (string) $code ) );
	}
}
