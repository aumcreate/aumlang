<?php
/**
 * Language value object.
 *
 * @package AumLang
 */

namespace AumLang\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a configured language.
 */
class Language {

	/**
	 * Row id (0 when not yet persisted).
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Short language code, e.g. "en" or "zh".
	 *
	 * @var string
	 */
	private $code;

	/**
	 * WordPress locale, e.g. "en_US" or "zh_CN".
	 *
	 * @var string
	 */
	private $locale;

	/**
	 * Human-readable display name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * URL prefix slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Whether this is the site's default language.
	 *
	 * @var bool
	 */
	private $is_default;

	/**
	 * Whether the language is right-to-left.
	 *
	 * @var bool
	 */
	private $is_rtl;

	/**
	 * Sort order in the switcher.
	 *
	 * @var int
	 */
	private $sort_order;

	/**
	 * Whether the language is active.
	 *
	 * @var bool
	 */
	private $active;

	/**
	 * Build a language from an associative array of attributes.
	 *
	 * @param array $data Attributes; "code" is required.
	 */
	public function __construct( array $data ) {
		$this->id         = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->code       = isset( $data['code'] ) ? (string) $data['code'] : '';
		$this->locale     = isset( $data['locale'] ) ? (string) $data['locale'] : '';
		$this->name       = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->slug       = isset( $data['slug'] ) ? (string) $data['slug'] : '';
		$this->is_default = ! empty( $data['is_default'] );
		$this->is_rtl     = ! empty( $data['is_rtl'] );
		$this->sort_order = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
		$this->active     = isset( $data['active'] ) ? ! empty( $data['active'] ) : true;
	}

	/**
	 * Build a language from a raw database row.
	 *
	 * @param array $row Database row.
	 * @return Language
	 */
	public static function from_row( array $row ) {
		return new self( $row );
	}

	/**
	 * Row id.
	 *
	 * @return int
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Language code.
	 *
	 * @return string
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * WordPress locale.
	 *
	 * @return string
	 */
	public function locale() {
		return $this->locale;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * URL slug.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Whether this is the default language.
	 *
	 * @return bool
	 */
	public function is_default() {
		return $this->is_default;
	}

	/**
	 * Whether the language is right-to-left.
	 *
	 * @return bool
	 */
	public function is_rtl() {
		return $this->is_rtl;
	}

	/**
	 * Sort order.
	 *
	 * @return int
	 */
	public function sort_order() {
		return $this->sort_order;
	}

	/**
	 * Whether the language is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->active;
	}

	/**
	 * Export as an associative array (includes id).
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'         => $this->id,
			'code'       => $this->code,
			'locale'     => $this->locale,
			'name'       => $this->name,
			'slug'       => $this->slug,
			'is_default' => $this->is_default,
			'is_rtl'     => $this->is_rtl,
			'sort_order' => $this->sort_order,
			'active'     => $this->active,
		);
	}
}
