<?php
/**
 * Extracts and re-applies visible text nodes within an HTML fragment.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * DOM-based text helper: pulls the ordered text nodes out of an HTML fragment
 * and writes translations back into the same positions, leaving tags,
 * attributes, and inline structure untouched.
 */
class HtmlTextExtractor {

	const ROOT_ID = 'aumlang-html-root';

	/**
	 * Ordered list of trimmed, non-empty text strings in the fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string[]
	 */
	public function extract( $html ) {
		$dom  = $this->load( $html );
		$list = array();

		foreach ( $this->text_nodes( $dom ) as $node ) {
			$list[] = trim( $node->nodeValue );
		}

		return $list;
	}

	/**
	 * Apply translations back into the fragment.
	 *
	 * @param string $html              HTML fragment.
	 * @param array  $translations      Map of text-node index => translated text.
	 * @param array  $attribute_updates Map of attribute name => value, applied to
	 *                                  the first element carrying that attribute
	 *                                  (e.g. an image's "alt").
	 * @return string
	 */
	public function rebuild( $html, array $translations, array $attribute_updates = array() ) {
		$dom = $this->load( $html );

		foreach ( $this->text_nodes( $dom ) as $index => $node ) {
			if ( ! isset( $translations[ $index ] ) ) {
				continue;
			}

			preg_match( '/^(\s*).*?(\s*)$/su', $node->nodeValue, $matches );
			$lead  = isset( $matches[1] ) ? $matches[1] : '';
			$trail = isset( $matches[2] ) ? $matches[2] : '';

			$node->nodeValue = $lead . $translations[ $index ] . $trail;
		}

		if ( ! empty( $attribute_updates ) ) {
			$xpath = new \DOMXPath( $dom );

			foreach ( $attribute_updates as $attribute => $value ) {
				$elements = $xpath->query( '//*[@' . $attribute . ']' );

				if ( $elements && $elements->length > 0 ) {
					$elements->item( 0 )->setAttribute( $attribute, $value );
				}
			}
		}

		return $this->inner_html( $dom );
	}

	/**
	 * Load an HTML fragment into a UTF-8 DOM document under a wrapper element.
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMDocument
	 */
	private function load( $html ) {
		$dom = new \DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"?><div id="' . self::ROOT_ID . '">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom;
	}

	/**
	 * Ordered translatable text nodes (non-empty, outside script/style).
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
