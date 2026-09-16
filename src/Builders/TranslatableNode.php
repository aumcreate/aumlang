<?php
/**
 * A single translatable unit extracted from content.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing one extracted node and how it should be handled.
 */
class TranslatableNode {

	const TYPE_TEXT      = 'text';
	const TYPE_HTML      = 'html';
	const TYPE_ATTRIBUTE = 'attribute';
	const TYPE_LINK      = 'link';
	const TYPE_IMAGE     = 'image';

	const DISPOSITION_TRANSLATE         = 'translate';
	const DISPOSITION_SHARE             = 'share';
	const DISPOSITION_SHARE_OVERRIDABLE = 'share_overridable';

	/**
	 * Locator used to write the translation back into the same position.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * Original source text.
	 *
	 * @var string
	 */
	public $text;

	/**
	 * Surrounding context (widget/block/tag) to help the AI.
	 *
	 * @var string
	 */
	public $context;

	/**
	 * Node type (text/html/attribute/link/image).
	 *
	 * @var string
	 */
	public $type;

	/**
	 * How the node is handled (translate/share/share_overridable).
	 *
	 * @var string
	 */
	public $disposition;

	/**
	 * Constructor.
	 *
	 * @param string $path        Locator path.
	 * @param string $text        Source text.
	 * @param string $context     Context hint.
	 * @param string $type        Node type.
	 * @param string $disposition Handling disposition.
	 */
	public function __construct( $path, $text, $context = '', $type = self::TYPE_TEXT, $disposition = self::DISPOSITION_TRANSLATE ) {
		$this->path        = (string) $path;
		$this->text        = (string) $text;
		$this->context     = (string) $context;
		$this->type        = $type;
		$this->disposition = $disposition;
	}

	/**
	 * Export as an associative array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'path'        => $this->path,
			'text'        => $this->text,
			'context'     => $this->context,
			'type'        => $this->type,
			'disposition' => $this->disposition,
		);
	}
}
