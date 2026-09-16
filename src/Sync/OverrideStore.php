<?php
/**
 * Per-language, per-node manual translation overrides.
 *
 * @package AumLang
 */

namespace AumLang\Sync;

use AumLang\Content\ContentLinker;
use AumLang\Content\NodeTranslationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stores manual edits to individual translation nodes. An override is flagged in
 * the node cache (is_override) so the orchestrator reuses it verbatim and never
 * overwrites it with machine output during re-translation or layout sync.
 */
class OverrideStore {

	/**
	 * Node translation cache.
	 *
	 * @var NodeTranslationRepository
	 */
	private $nodes;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Constructor.
	 *
	 * @param NodeTranslationRepository $nodes  Node translation cache.
	 * @param ContentLinker             $linker Content linker.
	 */
	public function __construct( NodeTranslationRepository $nodes, ContentLinker $linker ) {
		$this->nodes  = $nodes;
		$this->linker = $linker;
	}

	/**
	 * Set a manual override for a node.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param string $node_path Node path.
	 * @param string $text      Override text.
	 * @return bool False when the content has no translation group yet.
	 */
	public function set( $source_id, $lang, $node_path, $text ) {
		$group = $this->linker->get_group_uuid( $source_id );

		if ( ! $group ) {
			return false;
		}

		$this->nodes->set_override( $group, $this->normalize( $lang ), (string) $node_path, (string) $text );

		return true;
	}

	/**
	 * Remove an override so the node reverts to machine translation on next sync.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param string $node_path Node path.
	 * @return bool
	 */
	public function remove( $source_id, $lang, $node_path ) {
		$group = $this->linker->get_group_uuid( $source_id );

		if ( ! $group ) {
			return false;
		}

		$this->nodes->delete_node( $group, $this->normalize( $lang ), (string) $node_path );

		return true;
	}

	/**
	 * Get the override text for a node, or null when not overridden.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param string $node_path Node path.
	 * @return string|null
	 */
	public function get( $source_id, $lang, $node_path ) {
		$group = $this->linker->get_group_uuid( $source_id );

		if ( ! $group ) {
			return null;
		}

		$row = $this->nodes->find( $group, $this->normalize( $lang ), (string) $node_path );

		return ( $row && ! empty( $row['is_override'] ) ) ? $row['translated_text'] : null;
	}

	/**
	 * Whether a node has a manual override.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $lang      Language code.
	 * @param string $node_path Node path.
	 * @return bool
	 */
	public function has( $source_id, $lang, $node_path ) {
		return null !== $this->get( $source_id, $lang, $node_path );
	}

	/**
	 * Normalize a language code.
	 *
	 * @param string $lang Raw code.
	 * @return string
	 */
	private function normalize( $lang ) {
		return strtolower( trim( (string) $lang ) );
	}
}
