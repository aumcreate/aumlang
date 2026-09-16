<?php
/**
 * Marks translations stale when their source content changes.
 *
 * @package AumLang
 */

namespace AumLang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * On save_post of a source, compares the stored source hash of each translation
 * against the post's current hash and flags changed ones as "stale" so the
 * dashboard can prompt a re-translation.
 */
class StalenessTracker {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $repository Translation repository.
	 */
	public function __construct( TranslationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'save_post', array( $this, 'handle_save_post' ), 20, 1 );
	}

	/**
	 * save_post handler: ignore autosaves, revisions, and translation posts.
	 *
	 * @param int $post_id Saved post id.
	 * @return void
	 */
	public function handle_save_post( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Translation posts carry this meta; only originals drive staleness.
		if ( get_post_meta( $post_id, '_aumlang_source_id', true ) ) {
			return;
		}

		$this->on_source_updated( (int) $post_id );
	}

	/**
	 * Flag every translation of a source as stale when the source hash changed.
	 *
	 * @param int $source_id Source post id.
	 * @return void
	 */
	public function on_source_updated( $source_id ) {
		$post = get_post( $source_id );

		if ( ! $post ) {
			return;
		}

		$current_hash = SourceHash::of( $post );

		foreach ( $this->repository->find_by_source( $source_id ) as $row ) {
			if ( $row['source_hash'] === $current_hash || 'stale' === $row['status'] ) {
				continue;
			}

			$this->repository->update_by_id(
				(int) $row['id'],
				array(
					'status'     => 'stale',
					'updated_at' => current_time( 'mysql' ),
				)
			);

			/**
			 * Fires when a translation becomes stale.
			 *
			 * @param int    $source_id Source post id.
			 * @param string $lang      Language code of the stale translation.
			 */
			do_action( 'aumlang_translation_stale', (int) $source_id, $row['lang_code'] );
		}
	}

	/**
	 * Whether a source's translation in a language is stale.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @return bool
	 */
	public function is_stale( $source_id, $lang ) {
		$row = $this->repository->find_by_source_and_lang( (int) $source_id, strtolower( trim( (string) $lang ) ) );

		return $row && 'stale' === $row['status'];
	}

	/**
	 * Mark a source's translation in a language as reviewed (no longer stale).
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @return bool
	 */
	public function mark_reviewed( $source_id, $lang ) {
		$row = $this->repository->find_by_source_and_lang( (int) $source_id, strtolower( trim( (string) $lang ) ) );

		if ( ! $row ) {
			return false;
		}

		return $this->repository->update_by_id(
			(int) $row['id'],
			array(
				'status'     => 'reviewed',
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}
}
