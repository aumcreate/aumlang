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

	/** 一个翻译单元是「整块」还是「光秃秃的一段文字」。 */
	const UNIT_BLOCK = 'block';
	const UNIT_TEXT  = 'text';

	/**
	 * 可以整块拿去翻译的元素。
	 *
	 * 🔴 为什么要按块、而不是按文本节点。
	 *
	 * 旧写法用 `//text()` 把每个 DOM 文本节点各当一条。于是
	 * `<p><strong>A 公司</strong> 为客户提供 <a href="…">B 服务</a>，范围从…</p>`
	 * 会被切成「A 公司」「为客户提供」「B 服务」「，范围从」四条分别送去翻译 ——
	 * 句子在每个行内标签处断开。
	 *
	 * 拿买家真实页面实测（英译日）：碎片拼回去**读得通**（它们在同一批里，模型看得到
	 * 前后文），但接缝上一直出错 —— 括号开了不闭、凭空多出句号、日语语序迁就不了。
	 * 整句一起翻则完全正确。接缝问题修不了，只能不切。
	 *
	 * 所以：块里只有行内内容（没有块级后代）时，整块的 innerHTML 作为一条。
	 */
	const BLOCKS = array(
		'p', 'li', 'dt', 'dd', 'td', 'th', 'caption', 'figcaption', 'blockquote',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'summary', 'legend',
	);

	/**
	 * Ordered list of translation units in the fragment.
	 *
	 * 🔴 这是三个解析器（Classic / Elementor / Gutenberg）共用的那一份。
	 * 以前它们都靠 `//text()` 逐个 DOM 文本节点取，于是
	 * `<p><strong>A</strong> 提供 <a href="…">B</a></p>` 会被切成三条分别翻译 ——
	 * 句子在每个行内标签处断开。实测买家页面英译日：碎片拼回去括号开了不闭、
	 * 凭空多出句号；整块一起翻完全正确。
	 *
	 * 改在这里而不是各改各的：Elementor 和 Gutenberg 走的是同一个助手，
	 * 只修 Classic 等于只修了三分之一的用户。
	 *
	 * @param string $html HTML fragment.
	 * @return array<int, array{text:string,html:bool}>
	 */
	public function extract_units( $html ) {
		$dom  = $this->load( $html );
		$list = array();

		foreach ( $this->units( $dom ) as $unit ) {
			if ( self::UNIT_BLOCK === $unit['kind'] ) {
				$list[] = array( 'text' => $this->inner_of( $unit['node'] ), 'html' => true );
				continue;
			}

			/*
			 * 一段光秃秃的文字里可能埋着短代码，而短代码的属性里装着正文
			 * （`[bt_bb_headline headline="About Us" …]`）。整段送出去会把骨架也交给
			 * 模型，整段跳过又会把标题漏掉 —— 所以按段拆开，只把属性里的正文拿出来。
			 */
			foreach ( ShortcodeText::segments( $unit['node']->nodeValue ) as $segment ) {
				if ( 'keep' === $segment['kind'] ) {
					continue;
				}

				if ( 'json' === $segment['kind'] ) {
					/* 一个属性里裹着一组内容，展开成多条；条数由同一个函数算出，
					   所以回填那边数出来一定一样。 */
					$json = ShortcodeText::encoded_json( $segment['text'] );

					foreach ( ( $json ? $json['strings'] : array() ) as $string ) {
						$list[] = array( 'text' => trim( $string ), 'html' => false );
					}

					continue;
				}

				$list[] = array( 'text' => trim( $segment['text'] ), 'html' => false );
			}
		}

		return $list;
	}

	/**
	 * Ordered list of translation units.
	 *
	 * 遍历一遍 DOM，边走边决定：
	 *   · 碰到块级元素且它里面没有别的块级元素 → 整块算一个单元，不再往里走；
	 *   · 否则继续往下走；
	 *   · 走到任何不在块里的文字 → 它自己算一个单元（和旧行为一样）。
	 *
	 * 顺序只取决于文档结构，所以单元的序号在 extract() 和 rebuild() 两次之间是稳定的
	 * —— 和旧写法依赖文本节点序号是同一个道理。
	 *
	 * @param \DOMDocument $dom DOM document.
	 * @return array<int, array{kind:string,node:\DOMNode}>
	 */
	private function units( \DOMDocument $dom ) {
		$root = $dom->documentElement;

		if ( ! $root ) {
			return array();
		}

		$units = array();
		$this->walk( $root, $units );

		return $units;
	}

	/**
	 * Recursive half of {@see units()}.
	 *
	 * @param \DOMNode                                   $node  Current node.
	 * @param array<int, array{kind:string,node:\DOMNode}> $units Collected units.
	 * @return void
	 */
	private function walk( \DOMNode $node, array &$units ) {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( $child->nodeValue ) ) {
					$units[] = array( 'kind' => self::UNIT_TEXT, 'node' => $child );
				}
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$name = strtolower( $child->nodeName );

			if ( 'script' === $name || 'style' === $name ) {
				continue;
			}

			if ( in_array( $name, self::BLOCKS, true ) && ! $this->has_block_child( $child ) ) {
				if ( '' !== trim( $child->textContent ) ) {
					$units[] = array( 'kind' => self::UNIT_BLOCK, 'node' => $child );
				}
				continue;
			}

			$this->walk( $child, $units );
		}
	}

	/**
	 * Whether an element contains another block-level element.
	 *
	 * 嵌套的块不能整块翻：`<li>` 里还套着 `<p>` 时，整条拿去翻会把内层结构也交给模型，
	 * 风险白担。这种就继续往里走，让内层各自成为单元。
	 *
	 * @param \DOMNode $node Element.
	 * @return bool
	 */
	private function has_block_child( \DOMNode $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( in_array( strtolower( $child->nodeName ), self::BLOCKS, true ) || $this->has_block_child( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Serialize an element's children (its inner HTML).
	 *
	 * @param \DOMNode $node Element.
	 * @return string
	 */
	private function inner_of( \DOMNode $node ) {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}

		return trim( $html );
	}

	/**
	 * Replace an element's children with parsed HTML.
	 *
	 * 译文里带着行内标签，所以要当 HTML 解析回去、而不是当纯文字塞进去 —— 塞进去
	 * 会把 `<strong>` 变成页面上可见的「&lt;strong&gt;」。解析不了就原样保留，
	 * 宁可留下原文，也不要把结构弄坏。
	 *
	 * @param \DOMNode $node Element to refill.
	 * @param string   $html Translated inner HTML.
	 * @return void
	 */
	private function set_inner( \DOMNode $node, $html ) {
		$doc = $node->ownerDocument;

		$fragment = $doc->createDocumentFragment();

		$previous = libxml_use_internal_errors( true );
		$ok       = @$fragment->appendXML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok || ! $fragment->hasChildNodes() ) {
			/* appendXML 要求格式良好的 XML，而译文是 HTML。退回用一个临时文档来解析。 */
			$tmp = new \DOMDocument( '1.0', 'UTF-8' );

			$previous = libxml_use_internal_errors( true );
			$loaded   = $tmp->loadHTML(
				'<?xml encoding="UTF-8"?><div id="' . self::ROOT_ID . '-frag">' . $html . '</div>',
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded || ! $tmp->documentElement ) {
				return;
			}

			$fragment = $doc->createDocumentFragment();

			foreach ( $tmp->documentElement->childNodes as $child ) {
				$fragment->appendChild( $doc->importNode( $child, true ) );
			}
		}

		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}

		$node->appendChild( $fragment );
	}

	/**
	 * Ordered list of trimmed, non-empty text strings in the fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string[]
	 */
	public function extract( $html ) {
		$list = array();

		foreach ( $this->extract_units( $html ) as $unit ) {
			$list[] = $unit['text'];
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
		$dom   = $this->load( $html );
		$index = 0;

		foreach ( $this->units( $dom ) as $unit ) {
			if ( self::UNIT_BLOCK !== $unit['kind'] ) {
				/*
				 * 和抽取那头用**同一个** segments()，否则两边对单元的编号迟早错开，
				 * 译文就会被塞进别的位置 —— 那比没翻还糟。
				 */
				$rebuilt = '';

				foreach ( ShortcodeText::segments( $unit['node']->nodeValue ) as $segment ) {
					if ( 'keep' === $segment['kind'] ) {
						$rebuilt .= $segment['text'];
						continue;
					}

					if ( 'json' === $segment['kind'] ) {
						$json = ShortcodeText::encoded_json( $segment['text'] );

						if ( ! $json ) {
							$rebuilt .= $segment['text'];
							continue;
						}

						$picked = array();

						foreach ( array_keys( $json['strings'] ) as $n ) {
							$picked[ $n ] = isset( $translations[ $index + $n ] )
								? $translations[ $index + $n ]
								: $json['strings'][ $n ];
						}

						$cursor  = 0;
						$refilled = ShortcodeText::refill_json( $json['data'], $picked, $cursor );
						$encoded  = wp_json_encode( $refilled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

						/* 原来怎么存的就怎么存回去：编码过的再编码回去。 */
						$rebuilt .= ( false !== strpos( $segment['text'], '%' ) )
							? rawurlencode( (string) $encoded )
							: (string) $encoded;

						$index += count( $json['strings'] );
						continue;
					}

					if ( isset( $translations[ $index ] ) ) {
						preg_match( '/^(\s*).*?(\s*)$/su', $segment['text'], $m );
						$value = ( isset( $m[1] ) ? $m[1] : '' )
							. $translations[ $index ]
							. ( isset( $m[2] ) ? $m[2] : '' );

						/* 原来是编码存的，就照样编码回去，构建器才解得出来。 */
						$rebuilt .= ( ! empty( $segment['encoding'] ) && 'url' === $segment['encoding'] )
							? rawurlencode( $value )
							: $value;
					} else {
						/* 没翻的那条也要还原成原来的存法。 */
						$rebuilt .= ( ! empty( $segment['encoding'] ) && 'url' === $segment['encoding'] )
							? rawurlencode( $segment['text'] )
							: $segment['text'];
					}

					++$index;
				}

				$unit['node']->nodeValue = $rebuilt;
				continue;
			}

			if ( isset( $translations[ $index ] ) ) {
				$this->set_inner( $unit['node'], (string) $translations[ $index ] );
			}

			++$index;
			continue;
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
		/*
		 * 🔴 把不构成标签的 `<` 先转义掉。
		 *
		 * 规格表里「小于」常直接写成 `<0.1%`、`<5μm`。这不是合法 HTML，而 DOM 解析器
		 * 会把 `<0` 当成一个坏标签的开头，连同后面的内容一起吞掉 —— 结果是**翻译一遍，
		 * 这个数值就没了**，单元格变成空的，而且不报任何错。
		 * 2026-10-08 在一位买家的 PCB 参数表上实测到：`<td><0.1%</td>` 回填后成了 `<td></td>`。
		 *
		 * 判据是形状：`<` 后面跟字母、`/`、`!`、`?` 才可能是标签，跟数字或空格的一定不是。
		 */
		$html = preg_replace( '/<(?![a-zA-Z\/!?])/', '&lt;', (string) $html );

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
