<?php
/**
 * Contract for pluggable AI translation providers.
 *
 * @package AumLang
 */

namespace AumLang\Translation\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * A translation provider connects to one AI vendor.
 *
 * Implementations are stateless request clients: orchestration, caching, and
 * rate limiting live in higher layers.
 */
interface ProviderInterface {

	/**
	 * Stable machine id, e.g. "deepseek".
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human-readable label for settings UI.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether the provider has the configuration it needs to run (e.g. an API key).
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Translate a list of strings.
	 *
	 * Returns a list of the same length and order. Implementations must preserve
	 * HTML tags, placeholders, shortcodes, and URLs.
	 *
	 * @param string[] $texts       Strings to translate.
	 * @param string   $source_lang Source language code.
	 * @param string   $target_lang Target language code.
	 * @param array    $options     Provider options (e.g. instructions, model).
	 * @return string[]
	 * @throws \RuntimeException On API or response errors.
	 */
	public function translate( array $texts, $source_lang, $target_lang, array $options = array() );

	/**
	 * Whether multiple strings can be sent in one request.
	 *
	 * @return bool
	 */
	public function supports_batch();

	/**
	 * Maximum number of strings per request.
	 *
	 * @return int
	 */
	public function max_batch_size();
}
