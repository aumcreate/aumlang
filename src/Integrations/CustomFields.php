<?php
/**
 * Translates user-registered raw custom field (post meta) values.
 *
 * @package AumLang
 */

namespace AumLang\Integrations;

use AumLang\Builders\ConfigFileRules;
use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * For schemaless custom fields (a theme/plugin's raw post meta with no type
 * info), there is no way to auto-detect whether a value is translatable text.
 * So the user registers the meta keys they want translated (Settings →
 * Translatable content). On translation, those keys are copied across and their
 * string values translated. Hooks `aumlang_after_translate`.
 */
class CustomFields {

	const SETTINGS_KEY = 'translatable_meta_keys';

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
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'aumlang_after_translate', array( $this, 'sync' ), 18, 3 );
	}

	/**
	 * Copy + translate the registered custom fields onto a translation.
	 *
	 * @param int    $source_id   Source post id.
	 * @param int    $target_id   Translation post id.
	 * @param string $target_lang Target language code.
	 * @return void
	 */
	/**
	 * Is this meta value prose, or is it data that merely happens to be a string?
	 *
	 * **"Copy it across" and "send it to a translator" are two different decisions**, and this class used
	 * to make them with one list: every registered key whose value was a non-empty string went to the
	 * provider. Themes and plugins keep a great deal in post meta that is a string and is not language —
	 * `#EFEBE4`, `42`, an attachment id, a URL, a date, a serialized array. Sending those costs money and
	 * risks the model "helpfully" returning a translated colour name or a localised number, which then
	 * **replaces the real value** and breaks the front end of the translated page with no error.
	 *
	 * The rules below only ever refuse; anything that looks like a sentence still goes. A theme that wants
	 * a value translated regardless can say so through the filter.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return bool
	 */
	private static function is_translatable_value( $key, $value ) {
		$trimmed = trim( $value );

		$translatable = true;

		/*
		 * 作者声明成 `action="copy"` 的，一律只复制、不送翻。
		 * 这是他明确表达的意思（商品编号、SKU、尺寸这类），比我们按值的形状猜更可靠。
		 */
		if ( in_array( $key, self::copy_only_keys(), true ) ) {
			return false;
		}

		if ( is_serialized( $trimmed ) || is_numeric( $trimmed ) ) {
			$translatable = false;                                     // ids, counts, prices
		} elseif ( preg_match( '/^#[0-9a-f]{3,8}$/i', $trimmed ) ) {
			$translatable = false;                                     // colours
		} elseif ( preg_match( '#^(https?:)?//#i', $trimmed ) ) {
			$translatable = false;                                     // urls
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}([ T]|$)/', $trimmed ) ) {
			$translatable = false;                                     // dates
		} elseif ( preg_match( '/^[\w.-]+@[\w.-]+\.\w+$/', $trimmed ) ) {
			$translatable = false;                                     // email addresses
		}

		/**
		 * Filters whether a custom field value should be sent for translation.
		 *
		 * The value is copied to the translation either way; this only decides whether a translator sees
		 * it. Return false for anything that is data rather than language.
		 *
		 * @since 1.0.3
		 *
		 * @param bool   $translatable Whether to translate this value.
		 * @param string $key          Meta key.
		 * @param string $value        Meta value.
		 */
		return (bool) apply_filters( 'aumlang_translate_meta_value', $translatable, $key, $value );
	}

	public function sync( $source_id, $target_id, $target_lang ) {
		$keys = $this->keys();

		if ( empty( $keys ) ) {
			return;
		}

		$provider    = $this->providers->get_active();
		$source_lang = $this->source_lang();
		$translating = $provider && $provider->is_configured() && '' !== $source_lang && $source_lang !== $target_lang;

		$strings = array();
		$map     = array();

		foreach ( $keys as $key ) {
			$value = get_post_meta( (int) $source_id, $key, true );

			// Carry the value across; string text is queued for translation.
			update_post_meta( (int) $target_id, $key, $value );

			if ( $translating && is_string( $value ) && '' !== trim( $value ) && self::is_translatable_value( $key, $value ) ) {
				$map[ count( $strings ) ] = $key;
				$strings[]                = $value;
			}
		}

		if ( empty( $strings ) ) {
			return;
		}

		try {
			$translated = $this->batch->translate( $provider, $strings, $source_lang, $target_lang );
		} catch ( \RuntimeException $e ) {
			/*
			 * The values are already copied, so failing here leaves the source text rather than an empty
			 * field — the right outcome. But it must not be *silent*: without a line here the symptom is
			 * "that site's custom fields just never translate" and there is nothing at all to look at.
			 */
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'AumLang: custom fields for post %d not translated to %s — %s',
						(int) $target_id,
						$target_lang,
						$e->getMessage()
					)
				);
			}

			return;
		}

		foreach ( $map as $index => $key ) {
			if ( isset( $translated[ $index ] ) && '' !== $translated[ $index ] ) {
				update_post_meta( (int) $target_id, $key, $translated[ $index ] );
			}
		}
	}

	/**
	 * The registered meta keys.
	 *
	 * @return string[]
	 */
	private function keys() {
		$settings = get_option( 'aumlang_settings', array() );
		$keys     = isset( $settings[ self::SETTINGS_KEY ] ) ? (array) $settings[ self::SETTINGS_KEY ] : array();
		$keys     = array_filter( array_map( 'trim', $keys ) );

		/*
		 * 🔴 站长手填的那份，加上主题/插件自己声明的那份。
		 *
		 * 作者比站长更清楚自己哪个 meta 装着给访客看的文字 —— 他们已经在自己根目录
		 * 那份声明里写明了。不接的话，等于让用户去填一份别人已经填好的表。
		 */
		$declared = ConfigFileRules::custom_fields();

		$keys = array_merge( $keys, $declared['translate'], $declared['copy'] );

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Meta keys that must be copied across but never sent for translation.
	 *
	 * @return string[]
	 */
	private static function copy_only_keys() {
		$declared = ConfigFileRules::custom_fields();

		return $declared['copy'];
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
