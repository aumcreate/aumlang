<?php
/**
 * Downloads official WordPress.org translation packs for a locale.
 *
 * @package AumLang
 */

namespace AumLang\Language;

defined( 'ABSPATH' ) || exit;

/**
 * When a language is added, fetch the official .mo packs for WordPress core and
 * every installed theme/plugin that has a translation on WordPress.org — so the
 * site is mostly translated for free (human, official) before AumLang's capture
 * + AI has to fill any remaining gaps. Components not hosted on WordPress.org
 * (premium/custom themes) are skipped silently; they rely on their bundled .mo
 * or AumLang.
 */
class LanguagePackInstaller {

	/**
	 * Install core + theme + plugin language packs for a locale.
	 *
	 * @param string $locale Target locale, e.g. "zh_CN".
	 * @return array{core:bool,themes:int,plugins:int,error:string}
	 */
	public function install( $locale ) {
		$summary = array(
			'core'    => false,
			'themes'  => 0,
			'plugins' => 0,
			'error'   => '',
		);

		if ( '' === $locale || 'en_US' === $locale ) {
			return $summary;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( ! function_exists( 'wp_download_language_pack' ) || ! function_exists( 'translations_api' ) ) {
			$summary['error'] = __( 'Translation install API is unavailable.', 'aumlang' );
			return $summary;
		}

		// Downloads can take a while across many components.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		// Core (returns the locale on success, false otherwise).
		$summary['core'] = (bool) wp_download_language_pack( $locale );

		$translations = array_values(
			array_merge(
				$this->collect( 'themes', $this->theme_components(), $locale ),
				$this->collect( 'plugins', $this->plugin_components(), $locale )
			)
		);

		if ( empty( $translations ) ) {
			return $summary;
		}

		$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
		$results  = (array) $upgrader->bulk_upgrade( $translations );

		foreach ( $translations as $i => $translation ) {
			$result = isset( $results[ $i ] ) ? $results[ $i ] : null;

			if ( ! $result || is_wp_error( $result ) ) {
				continue;
			}

			if ( 'theme' === $translation->type ) {
				++$summary['themes'];
			} else {
				++$summary['plugins'];
			}
		}

		return $summary;
	}

	/**
	 * Build language-pack update objects for the given components.
	 *
	 * @param string                $type   "themes" or "plugins".
	 * @param array<string, string> $slugs  slug => version.
	 * @param string                $locale Target locale.
	 * @return object[]
	 */
	private function collect( $type, $slugs, $locale ) {
		$out      = array();
		$obj_type = 'themes' === $type ? 'theme' : 'plugin';

		foreach ( $slugs as $slug => $version ) {
			// Silence the W.org HTTP warning translations_api emits on failure
			// (e.g. local TLS issues); we handle the error return ourselves.
			$api = @translations_api( $type, array( 'slug' => $slug, 'version' => $version ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( is_wp_error( $api ) || empty( $api['translations'] ) ) {
				continue;
			}

			foreach ( $api['translations'] as $translation ) {
				if ( ! isset( $translation['language'] ) || $translation['language'] !== $locale ) {
					continue;
				}

				$out[] = (object) array(
					'type'       => $obj_type,
					'slug'       => $slug,
					'language'   => $translation['language'],
					'version'    => isset( $translation['version'] ) ? $translation['version'] : $version,
					'updated'    => isset( $translation['updated'] ) ? $translation['updated'] : '',
					'package'    => isset( $translation['package'] ) ? $translation['package'] : '',
					'autoupdate' => true,
				);
			}
		}

		return $out;
	}

	/**
	 * Installed themes as slug => version.
	 *
	 * @return array<string, string>
	 */
	private function theme_components() {
		$components = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$components[ $slug ] = (string) $theme->get( 'Version' );
		}

		return $components;
	}

	/**
	 * Installed plugins as slug => version (slug = plugin directory).
	 *
	 * @return array<string, string>
	 */
	private function plugin_components() {
		$components = array();

		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file );

			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}

			$components[ $slug ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}

		return $components;
	}
}
