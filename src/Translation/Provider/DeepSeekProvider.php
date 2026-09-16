<?php
/**
 * DeepSeek translation provider (OpenAI-compatible chat completions API).
 *
 * @package AumLang
 */

namespace AumLang\Translation\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Sends batches of strings to DeepSeek and parses a JSON array back.
 */
class DeepSeekProvider implements ProviderInterface {

	use PromptTrait;

	const DEFAULT_ENDPOINT = 'https://api.deepseek.com/chat/completions';
	const DEFAULT_MODEL    = 'deepseek-chat';

	/**
	 * Provider configuration (api_key, model, endpoint).
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param array $config Provider configuration.
	 */
	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'deepseek';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		// The endpoint is configurable, so the class name is not the service
		// the site is actually talking to. Naming the host keeps an error like
		// "returned HTTP 401" attached to the account it came from.
		$host = wp_parse_url( $this->endpoint(), PHP_URL_HOST );

		return $host ? $host : 'Direct connection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return '' !== $this->api_key();
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_batch() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_batch_size() {
		return 50;
	}

	/**
	 * {@inheritDoc}
	 */
	public function translate( array $texts, $source_lang, $target_lang, array $options = array() ) {
		$texts = array_values( $texts );

		if ( empty( $texts ) ) {
			return array();
		}

		if ( ! $this->is_configured() ) {
			throw new \RuntimeException( 'DeepSeek provider is not configured: missing API key.' );
		}

		$content = $this->request(
			array(
				array(
					'role'    => 'system',
					'content' => $this->system_prompt( $source_lang, $target_lang, $options ),
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $texts ),
				),
			),
			$options
		);

		$translated = $this->parse_json_array( $content );

		if ( count( $translated ) !== count( $texts ) ) {
			throw new \RuntimeException(
				sprintf(
					'DeepSeek returned %d items for %d inputs.',
					count( $translated ),
					count( $texts )
				)
			);
		}

		return $translated;
	}

	/**
	 * Perform the chat completion request and return the message content.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Options (model override, temperature).
	 * @return string
	 * @throws \RuntimeException On transport or API errors.
	 */
	private function request( array $messages, array $options ) {
		$body = array(
			'model'       => isset( $options['model'] ) ? $options['model'] : $this->model(),
			'messages'    => $messages,
			'temperature' => isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.2,
			'stream'      => false,
		);

		$response = wp_remote_post(
			$this->endpoint(),
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( sprintf( '%s request failed: %s', $this->get_label(), $response->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			throw new \RuntimeException( esc_html( sprintf( '%s returned HTTP %d: %s', $this->get_label(), $code, $raw ) ) );
		}

		$data = json_decode( $raw, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			throw new \RuntimeException( 'DeepSeek response missing message content.' );
		}

		return (string) $data['choices'][0]['message']['content'];
	}

	/**
	 * Resolve the API key from configuration.
	 *
	 * @return string
	 */
	private function api_key() {
		return isset( $this->config['api_key'] ) ? trim( (string) $this->config['api_key'] ) : '';
	}

	/**
	 * Resolve the model name.
	 *
	 * @return string
	 */
	private function model() {
		return ! empty( $this->config['model'] ) ? (string) $this->config['model'] : self::DEFAULT_MODEL;
	}

	/**
	 * Resolve the API endpoint.
	 *
	 * @return string
	 */
	private function endpoint() {
		return ! empty( $this->config['endpoint'] ) ? (string) $this->config['endpoint'] : self::DEFAULT_ENDPOINT;
	}
}
