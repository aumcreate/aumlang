<?php
/**
 * Predefined catalog of selectable languages.
 *
 * @package AumLang
 */

namespace AumLang\Language;

defined( 'ABSPATH' ) || exit;

/**
 * A curated list of languages so users pick from a dropdown instead of typing
 * codes/locales by hand. Each entry: code => [ name (native), locale, rtl ].
 */
class LanguageCatalog {

	/**
	 * The catalog, ordered roughly by relevance for Asian / cross-border sites.
	 *
	 * @return array<string, array{name:string,locale:string,rtl:bool}>
	 */
	public static function all() {
		return array(
			'en'    => array( 'name' => 'English', 'locale' => 'en_US', 'rtl' => false ),
			'zh'    => array( 'name' => '简体中文', 'locale' => 'zh_CN', 'rtl' => false ),
			'zh-tw' => array( 'name' => '繁體中文', 'locale' => 'zh_TW', 'rtl' => false ),
			'ja'    => array( 'name' => '日本語', 'locale' => 'ja', 'rtl' => false ),
			'ko'    => array( 'name' => '한국어', 'locale' => 'ko_KR', 'rtl' => false ),
			'vi'    => array( 'name' => 'Tiếng Việt', 'locale' => 'vi', 'rtl' => false ),
			'th'    => array( 'name' => 'ไทย', 'locale' => 'th', 'rtl' => false ),
			'id'    => array( 'name' => 'Bahasa Indonesia', 'locale' => 'id_ID', 'rtl' => false ),
			'ms'    => array( 'name' => 'Bahasa Melayu', 'locale' => 'ms_MY', 'rtl' => false ),
			'hi'    => array( 'name' => 'हिन्दी', 'locale' => 'hi_IN', 'rtl' => false ),
			'es'    => array( 'name' => 'Español', 'locale' => 'es_ES', 'rtl' => false ),
			'pt'    => array( 'name' => 'Português', 'locale' => 'pt_PT', 'rtl' => false ),
			'pt-br' => array( 'name' => 'Português do Brasil', 'locale' => 'pt_BR', 'rtl' => false ),
			'fr'    => array( 'name' => 'Français', 'locale' => 'fr_FR', 'rtl' => false ),
			'de'    => array( 'name' => 'Deutsch', 'locale' => 'de_DE', 'rtl' => false ),
			'it'    => array( 'name' => 'Italiano', 'locale' => 'it_IT', 'rtl' => false ),
			'nl'    => array( 'name' => 'Nederlands', 'locale' => 'nl_NL', 'rtl' => false ),
			'ru'    => array( 'name' => 'Русский', 'locale' => 'ru_RU', 'rtl' => false ),
			'uk'    => array( 'name' => 'Українська', 'locale' => 'uk', 'rtl' => false ),
			'pl'    => array( 'name' => 'Polski', 'locale' => 'pl_PL', 'rtl' => false ),
			'tr'    => array( 'name' => 'Türkçe', 'locale' => 'tr_TR', 'rtl' => false ),
			'ar'    => array( 'name' => 'العربية', 'locale' => 'ar', 'rtl' => true ),
			'he'    => array( 'name' => 'עברית', 'locale' => 'he_IL', 'rtl' => true ),
			'fa'    => array( 'name' => 'فارسی', 'locale' => 'fa_IR', 'rtl' => true ),
			'ur'    => array( 'name' => 'اردو', 'locale' => 'ur', 'rtl' => true ),

			// European.
			'sv'    => array( 'name' => 'Svenska', 'locale' => 'sv_SE', 'rtl' => false ),
			'da'    => array( 'name' => 'Dansk', 'locale' => 'da_DK', 'rtl' => false ),
			'nb'    => array( 'name' => 'Norsk bokmål', 'locale' => 'nb_NO', 'rtl' => false ),
			'fi'    => array( 'name' => 'Suomi', 'locale' => 'fi', 'rtl' => false ),
			'is'    => array( 'name' => 'Íslenska', 'locale' => 'is_IS', 'rtl' => false ),
			'cs'    => array( 'name' => 'Čeština', 'locale' => 'cs_CZ', 'rtl' => false ),
			'sk'    => array( 'name' => 'Slovenčina', 'locale' => 'sk_SK', 'rtl' => false ),
			'sl'    => array( 'name' => 'Slovenščina', 'locale' => 'sl_SI', 'rtl' => false ),
			'hu'    => array( 'name' => 'Magyar', 'locale' => 'hu_HU', 'rtl' => false ),
			'ro'    => array( 'name' => 'Română', 'locale' => 'ro_RO', 'rtl' => false ),
			'bg'    => array( 'name' => 'Български', 'locale' => 'bg_BG', 'rtl' => false ),
			'el'    => array( 'name' => 'Ελληνικά', 'locale' => 'el', 'rtl' => false ),
			'hr'    => array( 'name' => 'Hrvatski', 'locale' => 'hr', 'rtl' => false ),
			'sr'    => array( 'name' => 'Српски', 'locale' => 'sr_RS', 'rtl' => false ),
			'mk'    => array( 'name' => 'Македонски', 'locale' => 'mk_MK', 'rtl' => false ),
			'sq'    => array( 'name' => 'Shqip', 'locale' => 'sq', 'rtl' => false ),
			'et'    => array( 'name' => 'Eesti', 'locale' => 'et', 'rtl' => false ),
			'lv'    => array( 'name' => 'Latviešu', 'locale' => 'lv', 'rtl' => false ),
			'lt'    => array( 'name' => 'Lietuvių', 'locale' => 'lt_LT', 'rtl' => false ),
			'ca'    => array( 'name' => 'Català', 'locale' => 'ca', 'rtl' => false ),
			'eu'    => array( 'name' => 'Euskara', 'locale' => 'eu', 'rtl' => false ),
			'gl'    => array( 'name' => 'Galego', 'locale' => 'gl_ES', 'rtl' => false ),
			'ga'    => array( 'name' => 'Gaeilge', 'locale' => 'ga', 'rtl' => false ),

			// South & Southeast Asian.
			'bn'    => array( 'name' => 'বাংলা', 'locale' => 'bn_BD', 'rtl' => false ),
			'ta'    => array( 'name' => 'தமிழ்', 'locale' => 'ta_IN', 'rtl' => false ),
			'te'    => array( 'name' => 'తెలుగు', 'locale' => 'te', 'rtl' => false ),
			'ml'    => array( 'name' => 'മലയാളം', 'locale' => 'ml_IN', 'rtl' => false ),
			'mr'    => array( 'name' => 'मराठी', 'locale' => 'mr', 'rtl' => false ),
			'gu'    => array( 'name' => 'ગુજરાતી', 'locale' => 'gu', 'rtl' => false ),
			'pa'    => array( 'name' => 'ਪੰਜਾਬੀ', 'locale' => 'pa_IN', 'rtl' => false ),
			'ne'    => array( 'name' => 'नेपाली', 'locale' => 'ne_NP', 'rtl' => false ),
			'si'    => array( 'name' => 'සිංහල', 'locale' => 'si_LK', 'rtl' => false ),
			'my'    => array( 'name' => 'မြန်မာ', 'locale' => 'my_MM', 'rtl' => false ),
			'km'    => array( 'name' => 'ខ្មែរ', 'locale' => 'km', 'rtl' => false ),
			'lo'    => array( 'name' => 'ລາວ', 'locale' => 'lo', 'rtl' => false ),
			'fil'   => array( 'name' => 'Filipino', 'locale' => 'fil', 'rtl' => false ),

			// Central Asian, Caucasus & others.
			'ka'    => array( 'name' => 'ქართული', 'locale' => 'ka_GE', 'rtl' => false ),
			'hy'    => array( 'name' => 'Հայերեն', 'locale' => 'hy', 'rtl' => false ),
			'az'    => array( 'name' => 'Azərbaycan', 'locale' => 'az', 'rtl' => false ),
			'kk'    => array( 'name' => 'Қазақ', 'locale' => 'kk', 'rtl' => false ),
			'uz'    => array( 'name' => 'Oʻzbek', 'locale' => 'uz_UZ', 'rtl' => false ),
			'sw'    => array( 'name' => 'Kiswahili', 'locale' => 'sw', 'rtl' => false ),
			'af'    => array( 'name' => 'Afrikaans', 'locale' => 'af', 'rtl' => false ),
			'am'    => array( 'name' => 'አማርኛ', 'locale' => 'am', 'rtl' => false ),
		);
	}

	/**
	 * Get a single catalog entry by code, or null.
	 *
	 * @param string $code Language code.
	 * @return array{name:string,locale:string,rtl:bool}|null
	 */
	public static function get( $code ) {
		$all = self::all();

		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}
}
