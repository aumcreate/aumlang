<?php
/**
 * High-level API for managing configured languages.
 *
 * @package AumLang
 */

namespace AumLang\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Validates input, enforces invariants (single default), and caches reads.
 *
 * This is the façade the rest of the plugin uses; it never talks to the
 * database directly, delegating persistence to the repository.
 */
class LanguageRegistry {

	/**
	 * Persistence backend.
	 *
	 * @var LanguageRepository
	 */
	private $repository;

	/**
	 * In-memory cache of all languages, or null when not yet loaded.
	 *
	 * @var Language[]|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepository $repository Persistence backend.
	 */
	public function __construct( LanguageRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Get configured languages.
	 *
	 * @param bool $active_only Restrict to active languages.
	 * @return Language[]
	 */
	public function get_languages( $active_only = false ) {
		if ( null === $this->cache ) {
			$this->cache = $this->repository->all();
		}

		if ( ! $active_only ) {
			return $this->cache;
		}

		return array_values(
			array_filter(
				$this->cache,
				static function ( Language $language ) {
					return $language->is_active();
				}
			)
		);
	}

	/**
	 * Get a single language by code.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function get_language( $code ) {
		$code = $this->normalize_code( $code );

		foreach ( $this->get_languages() as $language ) {
			if ( $language->code() === $code ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Get the default language, if configured.
	 *
	 * @return Language|null
	 */
	public function get_default_language() {
		foreach ( $this->get_languages() as $language ) {
			if ( $language->is_default() ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Whether a language code is registered.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function is_registered( $code ) {
		return null !== $this->get_language( $code );
	}

	/**
	 * Register a new language or update an existing one (matched by code).
	 *
	 * @param array $data Language attributes; "code" is required.
	 * @return Language|\WP_Error The stored language, or an error on invalid input.
	 */
	public function register( array $data ) {
		$code = $this->normalize_code( isset( $data['code'] ) ? $data['code'] : '' );

		if ( '' === $code ) {
			return new \WP_Error(
				'aumlang_invalid_code',
				__( 'A language code is required.', 'aumlang' )
			);
		}

		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code ) ) {
			return new \WP_Error(
				'aumlang_invalid_code',
				__( 'The language code format is invalid.', 'aumlang' )
			);
		}

		$existing = $this->get_language( $code );

		$attributes = array(
			'code'       => $code,
			'locale'     => isset( $data['locale'] ) ? sanitize_text_field( $data['locale'] ) : ( $existing ? $existing->locale() : '' ),
			'name'       => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : ( $existing ? $existing->name() : $code ),
			'slug'       => $this->normalize_slug( isset( $data['slug'] ) ? $data['slug'] : ( $existing ? $existing->slug() : $code ) ),
			'is_default' => isset( $data['is_default'] ) ? ! empty( $data['is_default'] ) : ( $existing ? $existing->is_default() : false ),
			'is_rtl'     => isset( $data['is_rtl'] ) ? ! empty( $data['is_rtl'] ) : ( $existing ? $existing->is_rtl() : false ),
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : ( $existing ? $existing->sort_order() : 0 ),
			'active'     => isset( $data['active'] ) ? ! empty( $data['active'] ) : ( $existing ? $existing->is_active() : true ),
		);

		$language = new Language( $attributes );

		if ( $existing ) {
			$this->repository->update( $language );
		} else {
			$this->repository->insert( $language );
		}

		$this->flush_cache();

		// Enforce a single default if this language claims it.
		if ( $language->is_default() ) {
			$this->set_default( $code );
		}

		/**
		 * Fires after a language is registered or updated.
		 *
		 * @param string $code     Language code.
		 * @param array  $attributes Stored attributes.
		 */
		do_action( 'aumlang_language_registered', $code, $attributes );

		return $this->get_language( $code );
	}

	/**
	 * Remove a language. The default language cannot be removed.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error True on success, error when blocked.
	 */
	public function unregister( $code ) {
		$code     = $this->normalize_code( $code );
		$language = $this->get_language( $code );

		if ( ! $language ) {
			return false;
		}

		if ( $language->is_default() ) {
			return new \WP_Error(
				'aumlang_default_locked',
				__( 'The default language cannot be removed. Set another default first.', 'aumlang' )
			);
		}

		$deleted = $this->repository->delete( $code );
		$this->flush_cache();

		if ( $deleted ) {
			/**
			 * Fires after a language is removed.
			 *
			 * @param string $code Language code.
			 */
			do_action( 'aumlang_language_unregistered', $code );
		}

		return $deleted;
	}

	/**
	 * Make a registered language the single default.
	 *
	 * @param string $code Language code.
	 * @return bool|\WP_Error True on success, error when the code is unknown.
	 */
	public function set_default( $code ) {
		$code     = $this->normalize_code( $code );
		$language = $this->get_language( $code );

		if ( ! $language ) {
			return new \WP_Error(
				'aumlang_unknown_language',
				__( 'Cannot set an unregistered language as default.', 'aumlang' )
			);
		}

		$this->repository->clear_default();

		$attributes               = $language->to_array();
		$attributes['is_default'] = true;
		$attributes['active']     = true;
		$this->repository->update( new Language( $attributes ) );

		$this->flush_cache();

		/**
		 * Fires after the default language changes.
		 *
		 * @param string $code Language code.
		 */
		do_action( 'aumlang_default_language_changed', $code );

		return true;
	}

	/**
	 * Drop the in-memory cache so the next read reloads from storage.
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Normalize a language code to lowercase without surrounding whitespace.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private function normalize_code( $code ) {
		return strtolower( trim( (string) $code ) );
	}

	/**
	 * Normalize a URL slug.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private function normalize_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );

		return '' !== $slug ? $slug : '';
	}
}
