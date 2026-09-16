<?php
/**
 * Outcome of a translation operation.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Simple value object describing what a translate_content() call produced.
 */
class TranslationResult {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * The translation post id (0 when none).
	 *
	 * @var int
	 */
	public $target_id;

	/**
	 * Number of strings sent to the provider.
	 *
	 * @var int
	 */
	public $translated_count;

	/**
	 * Number of strings reused from cache (incremental).
	 *
	 * @var int
	 */
	public $skipped_count;

	/**
	 * Human-readable message (error detail or summary).
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param bool   $success          Success flag.
	 * @param int    $target_id        Translation post id.
	 * @param int    $translated_count Strings translated.
	 * @param int    $skipped_count    Strings reused from cache.
	 * @param string $message          Message.
	 */
	public function __construct( $success, $target_id = 0, $translated_count = 0, $skipped_count = 0, $message = '' ) {
		$this->success          = (bool) $success;
		$this->target_id        = (int) $target_id;
		$this->translated_count = (int) $translated_count;
		$this->skipped_count    = (int) $skipped_count;
		$this->message          = (string) $message;
	}

	/**
	 * Build a success result.
	 *
	 * @param int    $target_id        Translation post id.
	 * @param int    $translated_count Strings translated.
	 * @param int    $skipped_count    Strings reused from cache.
	 * @param string $message          Message.
	 * @return TranslationResult
	 */
	public static function ok( $target_id, $translated_count = 0, $skipped_count = 0, $message = '' ) {
		return new self( true, $target_id, $translated_count, $skipped_count, $message );
	}

	/**
	 * Build an error result.
	 *
	 * @param string $message Error message.
	 * @return TranslationResult
	 */
	public static function error( $message ) {
		return new self( false, 0, 0, 0, $message );
	}
}
