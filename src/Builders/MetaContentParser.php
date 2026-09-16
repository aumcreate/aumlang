<?php
/**
 * Optional capability for parsers whose content lives in post meta.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by parsers (e.g. Elementor) that store their content in post meta
 * rather than post_content. After the orchestrator creates the translation post,
 * it calls persist_meta() so the parser can write its translated meta.
 */
interface MetaContentParser {

	/**
	 * Write translated meta-based content from a source onto a translation post.
	 *
	 * @param int   $source_id    Source post id.
	 * @param int   $target_id    Translation post id.
	 * @param array $translations Map of node path => translated text.
	 * @return void
	 */
	public function persist_meta( $source_id, $target_id, array $translations );
}
