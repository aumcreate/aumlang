<?php
/**
 * Translates navigation menu items for the current language.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

use AumLang\Content\ContentLinker;
use AumLang\Taxonomy\TermLinker;

defined( 'ABSPATH' ) || exit;

/**
 * On a non-default-language request, rewrites each nav menu item to its
 * translation: post/term items point to the translated post/term (and adopt its
 * translated title when the menu uses the default label); custom links and
 * archive links get the language URL prefix. One menu serves every language —
 * no need to build a separate menu per language.
 */
class MenuTranslator {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Term linker.
	 *
	 * @var TermLinker
	 */
	private $terms;

	/**
	 * URL converter.
	 *
	 * @var UrlConverter
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param Router        $router Router.
	 * @param ContentLinker $linker Content linker.
	 * @param TermLinker    $terms  Term linker.
	 * @param UrlConverter  $urls   URL converter.
	 */
	public function __construct( Router $router, ContentLinker $linker, TermLinker $terms, UrlConverter $urls ) {
		$this->router = $router;
		$this->linker = $linker;
		$this->terms  = $terms;
		$this->urls   = $urls;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_nav_menu_objects', array( $this, 'translate_items' ), 10, 2 );
	}

	/**
	 * Swap each menu item to its current-language translation.
	 *
	 * @param array $items Menu item objects.
	 * @param object $args  wp_nav_menu args (unused).
	 * @return array
	 */
	public function translate_items( $items, $args ) {
		unset( $args );

		if ( $this->router->is_default_language() ) {
			return $items;
		}

		$lang = $this->router->get_current_code();

		foreach ( $items as $item ) {
			$this->translate_item( $item, $lang );
		}

		return $items;
	}

	/**
	 * Translate a single menu item in place.
	 *
	 * @param object $item Menu item object.
	 * @param string $lang Current language code.
	 * @return void
	 */
	private function translate_item( $item, $lang ) {
		switch ( $item->type ) {
			case 'post_type':
				$object_id  = (int) $item->object_id;
				$translated = $this->linker->get_translation( $object_id, $lang );

				if ( $translated && $translated !== $object_id ) {
					$item->url = (string) get_permalink( $translated );

					if ( (string) $item->title === (string) get_the_title( $object_id ) ) {
						$item->title = get_the_title( $translated );
					}
				}
				break;

			case 'taxonomy':
				$object_id  = (int) $item->object_id;
				$translated = $this->terms->get_translation( $object_id, $lang );

				if ( $translated && $translated !== $object_id ) {
					$term = get_term( $translated, $item->object );

					if ( $term instanceof \WP_Term ) {
						$item->url = (string) get_term_link( $term );

						$source = get_term( $object_id, $item->object );
						if ( $source instanceof \WP_Term && (string) $item->title === (string) $source->name ) {
							$item->title = $term->name;
						}
					}
				}
				break;

			case 'post_type_archive':
			case 'custom':
				$item->url = $this->urls->convert( (string) $item->url, $lang );
				break;
		}
	}
}
