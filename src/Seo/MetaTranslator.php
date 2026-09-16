<?php
/**
 * Translates a post's SEO meta (title/description/social) onto its translation.
 *
 * @package AumLang
 */

namespace AumLang\Seo;

use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * When a post is translated, copies the Yoast / Rank Math SEO title and meta
 * description (and the social title/description + focus keyword) across,
 * translated, so the translated page carries proper SEO metadata. Hooks
 * `aumlang_after_translate`. Values that are empty (the SEO plugin falls back to
 * the already-translated post title) or that contain template variables like
 * %%title%% are left alone.
 */
class MetaTranslator {

	/**
	 * SEO plugin bridge.
	 *
	 * @var SeoPluginBridge
	 */
	private $bridge;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Batch processor.
	 *
	 * @var BatchProcessor
	 */
	private $batch;

	/**
	 * Constructor.
	 *
	 * @param SeoPluginBridge  $bridge    SEO plugin bridge.
	 * @param LanguageRegistry $languages Language registry.
	 * @param ProviderRegistry $providers Provider registry.
	 * @param BatchProcessor   $batch     Batch processor.
	 */
	public function __construct( SeoPluginBridge $bridge, LanguageRegistry $languages, ProviderRegistry $providers, BatchProcessor $batch ) {
		$this->bridge    = $bridge;
		$this->languages = $languages;
		$this->providers = $providers;
		$this->batch     = $batch;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'aumlang_after_translate', array( $this, 'sync_meta' ), 30, 3 );
	}

	/**
	 * Translate the SEO meta of a source post onto its translation.
	 *
	 * @param int    $source_id   Source post id.
	 * @param int    $target_id   Translation post id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	public function sync_meta( $source_id, $target_id, $target_lang ) {
		$keys = $this->bridge->translatable_meta_keys();

		if ( empty( $keys ) ) {
			return;
		}

		$provider = $this->providers->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			return;
		}

		$source_lang = $this->source_lang();

		if ( '' === $source_lang || $source_lang === $target_lang ) {
			return;
		}

		$strings = array();
		$map     = array();

		foreach ( $keys as $key ) {
			$value = (string) get_post_meta( (int) $source_id, $key, true );

			if ( '' === trim( $value ) ) {
				continue;
			}

			// Protect SEO-plugin variables (Yoast %%title%%, Rank Math %title%)
			// so only the literal text gets translated and the variables survive.
			list( $tokenized, $tokens ) = $this->protect_variables( $value );

			// Nothing but variables/punctuation left — the plugin will rebuild it
			// from the already-translated post title, so skip.
			if ( ! preg_match( '/\p{L}/u', preg_replace( '/\{\d+\}/', '', $tokenized ) ) ) {
				continue;
			}

			$map[ count( $strings ) ] = array(
				'key'    => $key,
				'tokens' => $tokens,
			);
			$strings[] = $tokenized;
		}

		if ( empty( $strings ) ) {
			return;
		}

		try {
			$translated = $this->batch->translate( $provider, $strings, $source_lang, $target_lang );
		} catch ( \RuntimeException $e ) {
			return;
		}

		foreach ( $map as $index => $info ) {
			if ( isset( $translated[ $index ] ) && '' !== $translated[ $index ] ) {
				$restored = strtr( $translated[ $index ], $info['tokens'] );
				update_post_meta( (int) $target_id, $info['key'], $restored );
			}
		}
	}

	/**
	 * Replace SEO-plugin template variables with numbered {N} placeholders the
	 * translation model is told to preserve, returning the tokenized string and
	 * the {N} => original-variable map.
	 *
	 * @param string $value Raw meta value.
	 * @return array{0:string,1:array<string,string>}
	 */
	private function protect_variables( $value ) {
		$tokens = array();
		$index  = 0;

		$tokenized = preg_replace_callback(
			'/%%[^%\s]+%%|%[a-z][a-z0-9_-]*(?:\([^)]*\))?%/i',
			static function ( $matches ) use ( &$tokens, &$index ) {
				$placeholder            = '{' . $index . '}';
				$tokens[ $placeholder ] = $matches[0];
				++$index;
				return $placeholder;
			},
			$value
		);

		return array( $tokenized, $tokens );
	}

	/**
	 * Default (source) language code.
	 *
	 * @return string
	 */
	private function source_lang() {
		$default = $this->languages->get_default_language();

		return $default ? $default->code() : '';
	}
}
