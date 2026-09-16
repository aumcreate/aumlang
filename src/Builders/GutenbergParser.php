<?php
/**
 * Parser for Gutenberg (block) content.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the block tree with parse_blocks(), extracts translatable text from each
 * leaf block's HTML (and whitelisted attributes), and rebuilds with
 * serialize_blocks() so block delimiters and structure stay intact — only text
 * changes. This keeps the layout shared across languages.
 */
class GutenbergParser implements BuilderParserInterface {

	/**
	 * HTML text helper.
	 *
	 * @var HtmlTextExtractor
	 */
	private $html;

	/**
	 * Constructor.
	 *
	 * @param HtmlTextExtractor $html HTML text helper.
	 */
	public function __construct( HtmlTextExtractor $html ) {
		$this->html = $html;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'gutenberg';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Handles any content that contains blocks.
	 */
	public function supports( $post_id ) {
		$post = get_post( $post_id );

		return $post ? has_blocks( $post->post_content ) : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return array();
		}

		$nodes = array();
		$this->collect( parse_blocks( $post->post_content ), '', $nodes );

		return $nodes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function rebuild( $post_id, array $translations ) {
		$post = get_post( $post_id );

		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return '';
		}

		$blocks = parse_blocks( $post->post_content );
		$this->apply( $blocks, '', $translations );

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively collect translatable nodes from a list of blocks.
	 *
	 * @param array  $blocks Block list.
	 * @param string $path   Path prefix.
	 * @param array  $nodes  Accumulator (by reference).
	 * @return void
	 */
	private function collect( $blocks, $path, array &$nodes ) {
		foreach ( $blocks as $index => $block ) {
			$block_path = ( '' === $path ) ? (string) $index : $path . '/' . $index;

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_attrs( $block, $block_path, $nodes );
				$this->collect( $block['innerBlocks'], $block_path, $nodes );
				continue;
			}

			$html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( '' !== trim( $html ) ) {
				$context = ! empty( $block['blockName'] ) ? $block['blockName'] : 'html';

				foreach ( $this->html->extract( $html ) as $text_index => $text ) {
					$nodes[] = new TranslatableNode(
						$block_path . ':h:' . $text_index,
						$text,
						$context,
						TranslatableNode::TYPE_TEXT,
						TranslatableNode::DISPOSITION_TRANSLATE
					);
				}
			}

			$this->collect_attrs( $block, $block_path, $nodes );
		}
	}

	/**
	 * Collect whitelisted text attributes of a block.
	 *
	 * @param array  $block      Block.
	 * @param string $block_path Path.
	 * @param array  $nodes      Accumulator (by reference).
	 * @return void
	 */
	private function collect_attrs( $block, $block_path, array &$nodes ) {
		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		foreach ( $this->text_attributes( $block_name ) as $key ) {
			if ( ! empty( $block['attrs'][ $key ] ) && is_string( $block['attrs'][ $key ] ) ) {
				$nodes[] = new TranslatableNode(
					$block_path . ':a:' . $key,
					$block['attrs'][ $key ],
					$block_name . '/' . $key,
					TranslatableNode::TYPE_ATTRIBUTE,
					TranslatableNode::DISPOSITION_TRANSLATE
				);
			}
		}
	}

	/**
	 * Recursively apply translations back into a list of blocks.
	 *
	 * @param array  $blocks       Block list (by reference).
	 * @param string $path         Path prefix.
	 * @param array  $translations Path => translated text.
	 * @return void
	 */
	private function apply( array &$blocks, $path, array $translations ) {
		foreach ( $blocks as $index => &$block ) {
			$block_path = ( '' === $path ) ? (string) $index : $path . '/' . $index;

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->apply_attrs( $block, $block_path, $translations );
				$this->apply( $block['innerBlocks'], $block_path, $translations );
				continue;
			}

			$html = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( '' !== trim( $html ) ) {
				$map         = $this->collect_html_map( $block_path, $translations );
				$attr_update = $this->collect_attr_updates( $block, $block_path, $translations );

				if ( ! empty( $map ) || ! empty( $attr_update ) ) {
					$new_html              = $this->html->rebuild( $html, $map, $attr_update );
					$block['innerHTML']    = $new_html;
					$block['innerContent'] = array( $new_html );
				}
			}

			$this->apply_attrs( $block, $block_path, $translations );
		}

		unset( $block );
	}

	/**
	 * Apply whitelisted attribute translations to a block.
	 *
	 * @param array  $block        Block (by reference).
	 * @param string $block_path   Path.
	 * @param array  $translations Translations.
	 * @return void
	 */
	private function apply_attrs( array &$block, $block_path, array $translations ) {
		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		foreach ( $this->text_attributes( $block_name ) as $key ) {
			$translation_key = $block_path . ':a:' . $key;

			if ( isset( $translations[ $translation_key ] ) ) {
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = array();
				}

				$block['attrs'][ $key ] = $translations[ $translation_key ];
			}
		}
	}

	/**
	 * Build attribute updates for a leaf block whose whitelisted attribute is
	 * mirrored in its HTML (e.g. image alt lives in both the block attrs and the
	 * <img> tag). The block attribute name is used as the HTML attribute name.
	 *
	 * @param array  $block        Block.
	 * @param string $block_path   Path.
	 * @param array  $translations Translations.
	 * @return array<string, string>
	 */
	private function collect_attr_updates( $block, $block_path, array $translations ) {
		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$updates    = array();

		foreach ( $this->text_attributes( $block_name ) as $key ) {
			$translation_key = $block_path . ':a:' . $key;

			if ( isset( $translations[ $translation_key ] ) ) {
				$updates[ $key ] = $translations[ $translation_key ];
			}
		}

		return $updates;
	}

	/**
	 * Build an index => text map for a leaf block's HTML translations.
	 *
	 * @param string $block_path   Block path.
	 * @param array  $translations Translations.
	 * @return array<int, string>
	 */
	private function collect_html_map( $block_path, array $translations ) {
		$prefix = $block_path . ':h:';
		$map    = array();

		foreach ( $translations as $key => $value ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$map[ (int) substr( $key, strlen( $prefix ) ) ] = $value;
			}
		}

		return $map;
	}

	/**
	 * Whitelisted translatable attributes per block type.
	 *
	 * @param string $block_name Block name.
	 * @return string[]
	 */
	private function text_attributes( $block_name ) {
		$map = array(
			'core/image' => array( 'alt' ),
		);

		$attributes = isset( $map[ $block_name ] ) ? $map[ $block_name ] : array();

		/**
		 * Filter the translatable attributes for a Gutenberg block.
		 *
		 * @param string[] $attributes Attribute keys.
		 * @param string   $block_name Block name.
		 */
		return (array) apply_filters( 'aumlang_gutenberg_text_attributes', $attributes, $block_name );
	}
}
