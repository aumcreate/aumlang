<?php
/**
 * Contract for builder/content parsers.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * A parser extracts translatable nodes from a post's content and rebuilds the
 * content with translations applied, leaving structure and non-text data intact.
 */
interface BuilderParserInterface {

	/**
	 * Stable machine id, e.g. "classic".
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Whether this parser can handle the given post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function supports( $post_id );

	/**
	 * Extract translatable nodes from the post.
	 *
	 * @param int $post_id Post id.
	 * @return TranslatableNode[]
	 */
	public function extract( $post_id );

	/**
	 * Rebuild the content with translations applied.
	 *
	 * @param int   $post_id      Post id.
	 * @param array $translations Map of node path => translated text.
	 * @return string|array New content (string for classic, array for field-based).
	 */
	public function rebuild( $post_id, array $translations );
}
