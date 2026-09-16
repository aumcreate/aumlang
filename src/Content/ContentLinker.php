<?php
/**
 * Maintains the relationship between a source object and its translations.
 *
 * @package AumLang
 */

namespace AumLang\Content;

use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Domain API over translation groups.
 *
 * Model: every language version of a piece of content belongs to a group
 * (group_uuid). The source object is authored in the site default language and
 * is implicit (no row of its own); each non-default language is one row linking
 * the source to its translation post.
 */
class ContentLinker {

	/**
	 * Persistence backend.
	 *
	 * @var TranslationRepository
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
	 * @param TranslationRepository $repository Persistence backend.
	 * @param LanguageRegistry      $registry   Language registry.
	 */
	public function __construct( TranslationRepository $repository, LanguageRegistry $registry ) {
		$this->repository = $repository;
		$this->registry   = $registry;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );
	}

	/**
	 * Link a translation post to a source's group in the given language.
	 *
	 * Creates the group on first link. Re-linking the same source/language
	 * updates the target. Returns the group UUID.
	 *
	 * @param int    $source_id   Source object id (resolved to its canonical source).
	 * @param int    $target_id   Translation post id.
	 * @param string $lang        Translation language code.
	 * @param string $object_type Object type: post|term|menu|string.
	 * @return string Group UUID, or empty string when nothing was stored.
	 */
	public function link( $source_id, $target_id, $lang, $object_type = 'post' ) {
		$lang      = $this->normalize_code( $lang );
		$source_id = $this->get_source_id( (int) $source_id );
		$target_id = (int) $target_id;

		// The source's own default-language version is implicit; nothing to store.
		if ( '' === $lang || $lang === $this->default_code() ) {
			return (string) $this->get_group_uuid( $source_id );
		}

		$existing = $this->repository->find_one_by_source( $source_id );
		$group    = $existing ? $existing['group_uuid'] : wp_generate_uuid4();
		$now      = current_time( 'mysql' );

		$row = $this->repository->find_by_source_and_lang( $source_id, $lang );

		if ( $row ) {
			$this->repository->update_by_id(
				(int) $row['id'],
				array(
					'target_id'   => $target_id,
					'object_type' => $object_type,
					'updated_at'  => $now,
				)
			);
		} else {
			$this->repository->insert(
				array(
					'object_type' => $object_type,
					'source_id'   => $source_id,
					'target_id'   => $target_id,
					'group_uuid'  => $group,
					'lang_code'   => $lang,
					'source_hash' => '',
					'status'      => 'none',
					'updated_at'  => $now,
				)
			);
		}

		/**
		 * Fires after a translation is linked to its source.
		 *
		 * @param int    $source_id Source object id.
		 * @param int    $target_id Translation post id.
		 * @param string $lang      Language code.
		 * @param string $group     Group UUID.
		 */
		do_action( 'aumlang_content_linked', $source_id, $target_id, $lang, $group );

		return $group;
	}

	/**
	 * Get the translation post id of a piece of content in a language.
	 *
	 * Accepts either the source id or any translation id. Returns the source id
	 * for the default language.
	 *
	 * @param int    $post_id Any post in the group.
	 * @param string $lang    Target language code.
	 * @return int|null
	 */
	public function get_translation( $post_id, $lang ) {
		$lang   = $this->normalize_code( $lang );
		$source = $this->get_source_id( (int) $post_id );

		if ( $lang === $this->default_code() ) {
			return $source;
		}

		$row = $this->repository->find_by_source_and_lang( $source, $lang );

		return $row ? (int) $row['target_id'] : null;
	}

	/**
	 * Get all language versions of a piece of content as lang => post_id.
	 *
	 * Includes the source under the default language.
	 *
	 * @param int $post_id Any post in the group.
	 * @return array<string, int>
	 */
	public function get_all_translations( $post_id ) {
		$source  = $this->get_source_id( (int) $post_id );
		$default = $this->default_code();

		$map = array();

		if ( '' !== $default ) {
			$map[ $default ] = $source;
		}

		foreach ( $this->repository->find_by_source( $source ) as $row ) {
			$map[ $row['lang_code'] ] = (int) $row['target_id'];
		}

		return $map;
	}

	/**
	 * Get all language versions of a group as lang => post_id.
	 *
	 * @param string $group_uuid Group identifier.
	 * @return array<string, int>
	 */
	public function get_group( $group_uuid ) {
		$rows = $this->repository->find_by_group( $group_uuid );

		if ( empty( $rows ) ) {
			return array();
		}

		$default = $this->default_code();
		$map     = array();

		if ( '' !== $default ) {
			$map[ $default ] = (int) $rows[0]['source_id'];
		}

		foreach ( $rows as $row ) {
			$map[ $row['lang_code'] ] = (int) $row['target_id'];
		}

		return $map;
	}

	/**
	 * Get the group UUID a post belongs to, if any.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public function get_group_uuid( $post_id ) {
		$row = $this->repository->find_owning_row( (int) $post_id );

		return $row ? $row['group_uuid'] : null;
	}

	/**
	 * Resolve any post id to its group's canonical source id.
	 *
	 * A post with no group is treated as its own source.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public function get_source_id( $post_id ) {
		$row = $this->repository->find_owning_row( (int) $post_id );

		return $row ? (int) $row['source_id'] : (int) $post_id;
	}

	/**
	 * Post ids that should be hidden from listings in the given language.
	 *
	 * These are translation posts belonging to other languages, plus source
	 * posts that already have a translation in this language (their translated
	 * version is shown instead). Source posts with no translation here are left
	 * visible as a fallback.
	 *
	 * @param string $lang Current language code.
	 * @return int[]
	 */
	public function get_posts_to_hide_for_language( $lang ) {
		$lang = $this->normalize_code( $lang );

		$other_language_translations = $this->repository->get_target_ids_not_in_language( $lang );
		$sources_translated_here     = $this->repository->get_source_ids_with_language( $lang );

		return array_values( array_unique( array_merge( $other_language_translations, $sources_translated_here ) ) );
	}

	/**
	 * Get the translation status of a source/language, or "none" if unlinked.
	 *
	 * @param int    $post_id Source or translation post id.
	 * @param string $lang    Language code.
	 * @return string none|machine|reviewed|stale
	 */
	public function get_status( $post_id, $lang ) {
		$source = $this->get_source_id( (int) $post_id );
		$row    = $this->repository->find_by_source_and_lang( $source, $this->normalize_code( $lang ) );

		return $row ? $row['status'] : 'none';
	}

	/**
	 * Update the translation status (and optionally source hash) of a link.
	 *
	 * @param int         $post_id     Source or translation post id.
	 * @param string      $lang        Language code.
	 * @param string      $status      Status: none|machine|reviewed|stale.
	 * @param string|null $source_hash Source content hash, or null to leave it.
	 * @return bool
	 */
	public function set_status( $post_id, $lang, $status, $source_hash = null ) {
		$source = $this->get_source_id( (int) $post_id );
		$lang   = $this->normalize_code( $lang );

		$row = $this->repository->find_by_source_and_lang( $source, $lang );

		if ( ! $row ) {
			return false;
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'status'        => $status,
			'translated_at' => $now,
			'updated_at'    => $now,
		);

		if ( null !== $source_hash ) {
			$data['source_hash'] = (string) $source_hash;
		}

		return $this->repository->update_by_id( (int) $row['id'], $data );
	}

	/**
	 * Remove a single translation link by its target post.
	 *
	 * @param int $target_id Translation post id.
	 * @return bool
	 */
	public function unlink( $target_id ) {
		return $this->repository->delete_by_target( (int) $target_id ) > 0;
	}

	/**
	 * Clean up links when a post is permanently deleted.
	 *
	 * @param int $post_id Deleted post id.
	 * @return void
	 */
	public function on_post_deleted( $post_id ) {
		$this->repository->delete_referencing( (int) $post_id );
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
