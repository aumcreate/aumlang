<?php
/**
 * Translation through the AI client that ships with WordPress.
 *
 * Since WordPress 7.0 the site owner connects a provider once, under
 * Settings -> Connectors, and every plugin reuses it. Nothing here handles a
 * key, and nothing here knows which vendor is on the other end.
 *
 * This is the preferred path, but not the only one: the connectors WordPress
 * ships cover Anthropic, Google and OpenAI, and a site whose network cannot
 * reach any of those still needs the direct connection this plugin also
 * offers. Removing that would leave those users with no translation at all.
 *
 * @package AumLang
 */

namespace AumLang\Translation\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress core AI Client provider.
 */
class CoreAiProvider implements ProviderInterface {

	use PromptTrait;

	/**
	 * Provider id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'core';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'WordPress AI Client', 'aumlang' );
	}

	/**
	 * Whether this WordPress even has the client.
	 *
	 * Separate from is_configured() because the two failures need different
	 * answers: a site with no provider connected should still default to this
	 * engine and be told to connect one, while a site whose WordPress predates
	 * the client cannot use it at all and should default elsewhere.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Whether translation can actually run right now.
	 *
	 * The client being present is not enough: it carries no provider of its
	 * own, so it only works once the site owner has connected one. Checking
	 * only for the function would show this provider as ready on a site that
	 * cannot translate a single string.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$builder = wp_ai_client_prompt( 'ping' );

		return is_object( $builder )
			&& method_exists( $builder, 'is_supported_for_text_generation' )
			&& $builder->is_supported_for_text_generation();
	}

	/**
	 * Whether several strings can go in one request.
	 *
	 * @return bool
	 */
	public function supports_batch() {
		return true;
	}

	/**
	 * Strings per request.
	 *
	 * Deliberately lower than the direct provider's: the model behind the core
	 * client is whatever the site connected, so the context window is unknown
	 * and a smaller batch is the safer default.
	 *
	 * @return int
	 */
	public function max_batch_size() {
		return 20;
	}

	/**
	 * Translate a batch of strings.
	 *
	 * @param array  $texts       Strings to translate.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @param array  $options     Translation options.
	 * @return array<int, string>
	 * @throws \RuntimeException When the client is unavailable or the reply cannot be used.
	 */
	public function translate( array $texts, $source_lang, $target_lang, array $options = array() ) {
		$texts = array_values( $texts );

		if ( empty( $texts ) ) {
			return array();
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			throw new \RuntimeException(
				'This site does not provide the WordPress AI Client. Update to WordPress 7.0 or newer, or choose the direct provider under AumLang settings.'
			);
		}

		if ( ! $this->is_configured() ) {
			throw new \RuntimeException(
				'No AI provider is connected to this site. Connect one under Settings > Connectors, or choose the direct provider under AumLang settings.'
			);
		}

		$prompt = $this->system_prompt( $source_lang, $target_lang, $options )
			. "\n\n"
			. wp_json_encode( $texts );

		$result = wp_ai_client_prompt( $prompt )->generate_text();

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}

		$translated = $this->parse_json_array( (string) $result );

		if ( count( $translated ) !== count( $texts ) ) {
			throw new \RuntimeException(
				sprintf(
					'The AI client returned %1$d items for %2$d inputs.',
					count( $translated ),
					count( $texts )
				)
			);
		}

		return $translated;
	}
}
