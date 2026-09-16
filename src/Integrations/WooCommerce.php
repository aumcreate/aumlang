<?php
/**
 * WooCommerce integration: keep a translated product a full, valid product.
 *
 * @package AumLang
 */

namespace AumLang\Integrations;

use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The core translator already handles a product's title / description / short
 * description (post fields) and its categories/tags (taxonomies). This copies
 * the rest of the WooCommerce product data (price, stock, SKU, gallery,
 * attributes…) onto the translation so it stays a working product, and
 * translates the text bits of that data (purchase note, custom attribute
 * names/values). Hooks `aumlang_after_translate`.
 */
class WooCommerce {

	/**
	 * Meta keys never copied onto a translation.
	 *
	 * @var string[]
	 */
	private $skip_meta = array(
		'_aumlang_source_id',
		'_aumlang_language',
		'_edit_lock',
		'_edit_last',
	);

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
	 * Register WordPress hooks (only when WooCommerce is active).
	 *
	 * @return void
	 */
	public function register() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'aumlang_after_translate', array( $this, 'sync_product' ), 20, 3 );

		// A translation legitimately shares the source's SKU — don't flag it.
		add_filter( 'wc_product_has_unique_sku', array( $this, 'allow_translation_sku' ), 10, 2 );
	}

	/**
	 * Copy + translate WooCommerce data onto a freshly translated product.
	 *
	 * @param int    $source_id   Source product id.
	 * @param int    $target_id   Translation product id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	public function sync_product( $source_id, $target_id, $target_lang ) {
		if ( 'product' !== get_post_type( $source_id ) ) {
			return;
		}

		$this->copy_meta( (int) $source_id, (int) $target_id );
		$this->copy_internal_terms( (int) $source_id, (int) $target_id );
		$this->translate_text_meta( (int) $source_id, (int) $target_id, (string) $target_lang );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( (int) $target_id );
		}
	}

	/**
	 * Mirror WooCommerce's internal (non-public, untranslated) product
	 * taxonomies onto the translation so it keeps its type/visibility/shipping.
	 *
	 * @param int $source Source product id.
	 * @param int $target Translation product id.
	 * @return void
	 */
	private function copy_internal_terms( $source, $target ) {
		foreach ( array( 'product_type', 'product_visibility', 'product_shipping_class' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $source, $taxonomy, array( 'fields' => 'ids' ) );

			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $target, array_map( 'intval', $terms ), $taxonomy );
			}
		}
	}

	/**
	 * Don't block a translation product on a duplicate SKU (it shares its
	 * source's intentionally).
	 *
	 * @param bool $unique     Whether the SKU is considered unique.
	 * @param int  $product_id Product being checked.
	 * @return bool
	 */
	public function allow_translation_sku( $unique, $product_id ) {
		if ( get_post_meta( (int) $product_id, '_aumlang_source_id', true ) ) {
			return true;
		}

		return $unique;
	}

	/**
	 * Copy every product meta (except AumLang/edit keys) from source to target.
	 *
	 * @param int $source Source product id.
	 * @param int $target Translation product id.
	 * @return void
	 */
	private function copy_meta( $source, $target ) {
		$meta = get_post_meta( $source );

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $this->skip_meta, true ) ) {
				continue;
			}

			delete_post_meta( $target, $key );

			foreach ( (array) $values as $value ) {
				add_post_meta( $target, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Translate the text parts of the product data (purchase note + custom,
	 * non-taxonomy attribute names and values).
	 *
	 * @param int    $source      Source product id.
	 * @param int    $target      Translation product id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	private function translate_text_meta( $source, $target, $target_lang ) {
		$provider = $this->providers->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			return;
		}

		$source_lang = $this->source_lang();

		if ( '' === $source_lang || $source_lang === $target_lang ) {
			return;
		}

		$strings    = array();
		$note_index = null;
		$attr_map   = array();

		$note = (string) get_post_meta( $source, '_purchase_note', true );

		if ( '' !== trim( $note ) ) {
			$note_index = count( $strings );
			$strings[]  = $note;
		}

		$attributes = get_post_meta( $source, '_product_attributes', true );

		if ( is_array( $attributes ) ) {
			foreach ( $attributes as $attr_key => $attr ) {
				if ( ! empty( $attr['is_taxonomy'] ) ) {
					continue; // taxonomy attributes (pa_*) are translated as terms
				}

				if ( ! empty( $attr['name'] ) ) {
					$attr_map[ count( $strings ) ] = array( $attr_key, 'name', null );
					$strings[]                     = $attr['name'];
				}

				if ( isset( $attr['value'] ) && '' !== $attr['value'] ) {
					foreach ( array_map( 'trim', explode( '|', $attr['value'] ) ) as $vi => $val ) {
						if ( '' !== $val ) {
							$attr_map[ count( $strings ) ] = array( $attr_key, 'value', $vi );
							$strings[]                     = $val;
						}
					}
				}
			}
		}

		if ( empty( $strings ) ) {
			return;
		}

		try {
			$translated = $this->batch->translate( $provider, $strings, $source_lang, $target_lang );
		} catch ( \RuntimeException $e ) {
			return;
		}

		if ( null !== $note_index && isset( $translated[ $note_index ] ) ) {
			update_post_meta( $target, '_purchase_note', $translated[ $note_index ] );
		}

		$this->apply_attribute_translations( $source, $target, $attributes, $attr_map, $translated );
	}

	/**
	 * Write translated custom-attribute names/values back to the target.
	 *
	 * @param int   $source     Source product id.
	 * @param int   $target     Translation product id.
	 * @param array $attributes Source `_product_attributes`.
	 * @param array $attr_map   String-index => [attr_key, field, value_index].
	 * @param array $translated Translated strings (index-aligned).
	 * @return void
	 */
	private function apply_attribute_translations( $source, $target, $attributes, $attr_map, $translated ) {
		if ( empty( $attr_map ) || ! is_array( $attributes ) ) {
			return;
		}

		$target_attrs = get_post_meta( $target, '_product_attributes', true );

		if ( ! is_array( $target_attrs ) ) {
			$target_attrs = $attributes;
		}

		$value_groups = array();

		foreach ( $attr_map as $index => $info ) {
			if ( ! isset( $translated[ $index ] ) ) {
				continue;
			}

			list( $attr_key, $field, $value_index ) = $info;

			if ( 'name' === $field ) {
				if ( isset( $target_attrs[ $attr_key ] ) ) {
					$target_attrs[ $attr_key ]['name'] = $translated[ $index ];
				}
			} else {
				$value_groups[ $attr_key ][ $value_index ] = $translated[ $index ];
			}
		}

		foreach ( $value_groups as $attr_key => $values ) {
			$original = isset( $attributes[ $attr_key ]['value'] ) ? array_map( 'trim', explode( '|', $attributes[ $attr_key ]['value'] ) ) : array();

			foreach ( $values as $value_index => $value ) {
				$original[ $value_index ] = $value;
			}

			if ( isset( $target_attrs[ $attr_key ] ) ) {
				$target_attrs[ $attr_key ]['value'] = implode( ' | ', $original );
			}
		}

		update_post_meta( $target, '_product_attributes', $target_attrs );
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
