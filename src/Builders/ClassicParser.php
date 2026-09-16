<?php
/**
 * Parser for classic (HTML) post content.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts visible text nodes from post_content and rebuilds the HTML with
 * translations applied, leaving every tag, attribute, and structure untouched.
 *
 * This is the universal fallback parser; it supports any post and runs last.
 */
class ClassicParser implements BuilderParserInterface {

	/**
	 * Wrapper id used to isolate the content fragment inside a DOM document.
	 */
	const ROOT_ID = 'aumlang-root';

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'classic';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Always true: the classic parser is the fallback for any content.
	 */
	public function supports( $post_id ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return array();
		}

		$dom   = $this->load( $post->post_content );
		$nodes = array();

		foreach ( $this->text_nodes( $dom ) as $index => $text_node ) {
			$nodes[] = new TranslatableNode(
				(string) $index,
				trim( $text_node->nodeValue ),
				$text_node->parentNode ? $text_node->parentNode->nodeName : '',
				TranslatableNode::TYPE_TEXT,
				TranslatableNode::DISPOSITION_TRANSLATE
			);
		}

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

		$dom = $this->load( $post->post_content );

		foreach ( $this->text_nodes( $dom ) as $index => $text_node ) {
			$key = (string) $index;

			if ( ! isset( $translations[ $key ] ) ) {
				continue;
			}

			// Preserve the original leading/trailing whitespace around the text.
			preg_match( '/^(\s*).*?(\s*)$/su', $text_node->nodeValue, $matches );
			$lead  = isset( $matches[1] ) ? $matches[1] : '';
			$trail = isset( $matches[2] ) ? $matches[2] : '';

			// Assigning nodeValue escapes special characters automatically.
			$text_node->nodeValue = $lead . $translations[ $key ] . $trail;
		}

		return $this->inner_html( $dom );
	}

	/**
	 * Load an HTML fragment into a DOM document, forced to UTF-8.
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMDocument
	 */
	private function load( $html ) {
		$dom = new \DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );

		$wrapped = '<?xml encoding="UTF-8"?><div id="' . self::ROOT_ID . '">' . $html . '</div>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom;
	}

	/**
	 * Ordered list of translatable text nodes (non-empty, outside script/style).
	 *
	 * The order is deterministic for the same source, so a node's position is a
	 * stable path between extract() and rebuild().
	 *
	 * @param \DOMDocument $dom DOM document.
	 * @return \DOMNode[]
	 */
	private function text_nodes( \DOMDocument $dom ) {
		$xpath = new \DOMXPath( $dom );
		$query = $xpath->query( '//text()[not(ancestor::script) and not(ancestor::style)]' );

		$nodes = array();

		if ( false === $query ) {
			return $nodes;
		}

		foreach ( $query as $node ) {
			if ( '' !== trim( $node->nodeValue ) ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Serialize the inner HTML of the wrapper element.
	 *
	 * @param \DOMDocument $dom DOM document.
	 * @return string
	 */
	private function inner_html( \DOMDocument $dom ) {
		$root = $dom->documentElement;

		if ( ! $root ) {
			return '';
		}

		$html = '';

		foreach ( $root->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		return $html;
	}
}
