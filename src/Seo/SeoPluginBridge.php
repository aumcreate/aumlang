<?php
/**
 * Detects the active SEO plugin(s) and exposes their translatable meta keys.
 *
 * @package AumLang
 */

namespace AumLang\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast, Rank Math and AumViso store the per-post SEO title / meta description /
 * social fields in post meta under their own keys. This bridge reports which
 * keys to translate for whichever plugin(s) are active, so MetaTranslator stays
 * plugin agnostic. Keys from every active SEO plugin are merged, so a site that
 * happens to run more than one still gets all of them translated.
 */
class SeoPluginBridge {

	/**
	 * Per-plugin map of the meta keys whose text should follow the translation.
	 *
	 * @return array<string, string[]>
	 */
	private function key_map() {
		return array(
			'yoast'    => array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_opengraph-title',
				'_yoast_wpseo_opengraph-description',
				'_yoast_wpseo_twitter-title',
				'_yoast_wpseo_twitter-description',
				'_yoast_wpseo_focuskw',
			),
			'rankmath' => array(
				'rank_math_title',
				'rank_math_description',
				'rank_math_facebook_title',
				'rank_math_facebook_description',
				'rank_math_twitter_title',
				'rank_math_twitter_description',
				'rank_math_focus_keyword',
			),
			// AumViso (AumCreate SEO/GEO) — per-post fields from its editor meta
			// box + score panel. Only text fields: title, description, OG title/
			// description and the focus keyword. Canonical/OG image (URLs), robots
			// flags, schema type and IDs are deliberately excluded.
			'aumviso'  => array(
				'_seo_title',
				'_seo_description',
				'_og_title',
				'_og_description',
				'_aumseo_focus_kw',
			),
		);
	}

	/**
	 * Identify every active SEO plugin AumLang knows how to translate.
	 *
	 * @return string[] Any of 'yoast', 'rankmath', 'aumviso'.
	 */
	public function active_plugins() {
		$active = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$active[] = 'rankmath';
		}

		if ( class_exists( 'AumSEO' ) || defined( 'AUM_SEO_VERSION' ) ) {
			$active[] = 'aumviso';
		}

		return $active;
	}

	/**
	 * Identify the primary active SEO plugin (first detected), kept for
	 * backwards compatibility.
	 *
	 * @return string 'yoast' | 'rankmath' | 'aumviso' | ''.
	 */
	public function active_plugin() {
		$active = $this->active_plugins();

		return $active ? $active[0] : '';
	}

	/**
	 * The per-post meta keys whose text should follow the translation, merged
	 * across every active SEO plugin.
	 *
	 * @return string[]
	 */
	public function translatable_meta_keys() {
		$map  = $this->key_map();
		$keys = array();

		foreach ( $this->active_plugins() as $plugin ) {
			if ( isset( $map[ $plugin ] ) ) {
				$keys = array_merge( $keys, $map[ $plugin ] );
			}
		}

		return array_values( array_unique( $keys ) );
	}
}
