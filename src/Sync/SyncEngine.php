<?php
/**
 * Coordinates layout sync and per-node overrides across languages.
 *
 * @package AumLang
 */

namespace AumLang\Sync;

use AumLang\Content\ContentLinker;
use AumLang\Language\LanguageRegistry;
use AumLang\Translation\TranslationOrchestrator;

defined( 'ABSPATH' ) || exit;

/**
 * Because translate_content() always rebuilds a translation from the source's
 * current structure (reusing cached node translations), syncing a layout change
 * to every language is simply re-running it per language — unchanged nodes are
 * reused, only changed text is re-translated, and overrides are preserved.
 */
class SyncEngine {

	/**
	 * Translation orchestrator.
	 *
	 * @var TranslationOrchestrator
	 */
	private $orchestrator;

	/**
	 * Override store.
	 *
	 * @var OverrideStore
	 */
	private $overrides;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param TranslationOrchestrator $orchestrator Orchestrator.
	 * @param OverrideStore           $overrides    Override store.
	 * @param ContentLinker           $linker       Content linker.
	 * @param LanguageRegistry        $languages    Language registry.
	 */
	public function __construct(
		TranslationOrchestrator $orchestrator,
		OverrideStore $overrides,
		ContentLinker $linker,
		LanguageRegistry $languages
	) {
		$this->orchestrator = $orchestrator;
		$this->overrides    = $overrides;
		$this->linker       = $linker;
		$this->languages    = $languages;
	}

	/**
	 * Push the source's current structure to every existing translation,
	 * re-translating only changed text (incremental) and keeping overrides.
	 *
	 * @param int $source_id Source post id.
	 * @return array<string, \AumLang\Translation\TranslationResult> Keyed by language.
	 */
	public function sync_structure( $source_id ) {
		$default = $this->languages->get_default_language();
		$results = array();

		foreach ( $this->languages->get_languages( true ) as $language ) {
			if ( $default && $language->code() === $default->code() ) {
				continue;
			}

			if ( ! $this->linker->get_translation( $source_id, $language->code() ) ) {
				continue;
			}

			$results[ $language->code() ] = $this->orchestrator->translate_content( $source_id, $language->code() );
		}

		/**
		 * Fires after a source's structure has been synced to its translations.
		 *
		 * @param int $source_id Source post id.
		 */
		do_action( 'aumlang_structure_synced', (int) $source_id );

		return $results;
	}

	/**
	 * Set a manual override for one node and re-render that language.
	 *
	 * The re-render makes no API calls: the overridden node is reused verbatim
	 * and the rest come from cache.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param string $node_path Node path.
	 * @param string $text      Override text.
	 * @return bool
	 */
	public function set_override( $source_id, $lang, $node_path, $text ) {
		if ( ! $this->overrides->set( $source_id, $lang, $node_path, $text ) ) {
			return false;
		}

		$this->orchestrator->translate_content( $source_id, $lang );

		return true;
	}

	/**
	 * Remove a node override and re-render (the node reverts to machine output).
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param string $node_path Node path.
	 * @return bool
	 */
	public function remove_override( $source_id, $lang, $node_path ) {
		if ( ! $this->overrides->remove( $source_id, $lang, $node_path ) ) {
			return false;
		}

		$this->orchestrator->translate_content( $source_id, $lang );

		return true;
	}
}
