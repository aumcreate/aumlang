<?php
/**
 * Advanced Custom Fields integration.
 *
 * Named AcfFields rather than Acf so the file cannot be mistaken for a bundled
 * copy of Advanced Custom Fields. It contains none of that plugin's code: it
 * reads post meta and asks ACF, when ACF is present, what type each field is.
 *
 * @package AumLang
 */

namespace AumLang\Integrations;

use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a post's ACF fields onto its translation, translating only the text
 * field types and copying the rest. ACF stores every field value — including
 * those nested in repeaters / groups / flexible content — as a flat post-meta
 * row with a companion `_{name}` row holding the field key, so the field type
 * can be looked up per value and every nesting level is handled with no
 * recursion. Numbers, selects, images, dates, relationships, etc. are copied
 * verbatim; text / textarea / wysiwyg are translated. Hooks
 * `aumlang_after_translate`.
 */
class AcfFields {

	/**
	 * ACF field types whose value is translatable text.
	 *
	 * @var string[]
	 */
	private $text_types = array( 'text', 'textarea', 'wysiwyg' );

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
	 * @param LanguageRegistry $languages Language registry.
	 * @param ProviderRegistry $providers Provider registry.
	 * @param BatchProcessor   $batch     Batch processor.
	 */
	public function __construct( LanguageRegistry $languages, ProviderRegistry $providers, BatchProcessor $batch ) {
		$this->languages = $languages;
		$this->providers = $providers;
		$this->batch     = $batch;
	}

	/**
	 * Register WordPress hooks (only when ACF is active).
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return;
		}

		add_action( 'aumlang_after_translate', array( $this, 'sync_fields' ), 15, 3 );
	}

	/**
	 * Copy + translate ACF fields from a source post onto its translation.
	 *
	 * @param int    $source_id   Source post id.
	 * @param int    $target_id   Translation post id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	public function sync_fields( $source_id, $target_id, $target_lang ) {
		$meta = get_post_meta( (int) $source_id );

		if ( empty( $meta ) ) {
			return;
		}

		$source_lang = $this->source_lang();
		$can_provide = $source_lang !== $target_lang && '' !== $source_lang
			&& $this->providers->get_active() && $this->providers->get_active()->is_configured();

		$strings = array();
		$map     = array();

		foreach ( $meta as $key => $values ) {
			if ( '' === $key || '_' === $key[0] || ! isset( $meta[ '_' . $key ] ) ) {
				continue; // not an ACF value (or it's the companion row itself)
			}

			$field_key = maybe_unserialize( $meta[ '_' . $key ][0] );

			if ( ! is_string( $field_key ) || 0 !== strpos( $field_key, 'field_' ) ) {
				continue; // companion is not an ACF field key — not ACF-managed
			}

			$value = maybe_unserialize( $values[0] );
			$field = acf_get_field( $field_key );
			$type  = is_array( $field ) && isset( $field['type'] ) ? $field['type'] : '';

			// Always carry the value + its field-key reference across.
			update_post_meta( (int) $target_id, $key, $value );
			update_post_meta( (int) $target_id, '_' . $key, $field_key );

			if ( $can_provide && in_array( $type, $this->text_types, true ) && is_string( $value ) && '' !== trim( $value ) ) {
				$map[ count( $strings ) ] = $key;
				$strings[]                = $value;
			}
		}

		if ( empty( $strings ) ) {
			return;
		}

		try {
			$translated = $this->batch->translate( $this->providers->get_active(), $strings, $source_lang, $target_lang );
		} catch ( \RuntimeException $e ) {
			return;
		}

		foreach ( $map as $index => $key ) {
			if ( isset( $translated[ $index ] ) && '' !== $translated[ $index ] ) {
				update_post_meta( (int) $target_id, $key, $translated[ $index ] );
			}
		}
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
