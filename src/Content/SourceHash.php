<?php
/**
 * Computes a stable hash of a post's translatable fields.
 *
 * @package AumLang
 */

namespace AumLang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the staleness hash, shared by the orchestrator
 * (writes it when translating) and the staleness tracker (compares against it).
 */
class SourceHash {

	/**
	 * Hash the translatable fields of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function of( $post ) {
		return hash(
			'sha256',
			$post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt
		);
	}
}
