<?php
/**
 * Filters the main query and locale by the current language.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

use AumLang\Content\ContentLinker;

defined( 'ABSPATH' ) || exit;

/**
 * Three responsibilities on the front end:
 *  - switch the WP locale to the current language;
 *  - on every listing query (main AND secondary, e.g. block query loops or
 *    related-posts widgets), hide posts that don't belong to the current
 *    language, via a merge-safe post__not_in;
 *  - on a singular request, swap a source post for its translation when one
 *    exists in the current language.
 */
class QueryFilter {

	/**
	 * Post types never filtered by language (menus, templates, attachments).
	 *
	 * @var string[]
	 */
	private $skip_post_types = array(
		'nav_menu_item',
		'attachment',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
	);

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
	 * Constructor.
	 *
	 * @param Router        $router Router instance.
	 * @param ContentLinker $linker Content linker.
	 */
	public function __construct( Router $router, ContentLinker $linker ) {
		$this->router = $router;
		$this->linker = $linker;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'locale', array( $this, 'filter_locale' ) );
		add_filter( 'option_page_on_front', array( $this, 'filter_front_page_id' ) );
		add_filter( 'request', array( $this, 'resolve_language_front_page_request' ) );

		/*
		 * 🔴 **Core's canonical redirect sends every prefixed URL back to the unprefixed one.**
		 *
		 * `redirect_canonical()` rebuilds what it believes the address for the resolved query should be,
		 * and it knows nothing about the language prefix — so `/{lang}/` was answered with a 301 to `/`,
		 * and a visitor could not reach the translated front page at all. Measured 2026-09-04.
		 *
		 * Only prefixed requests are exempted; on the default language core keeps doing its job
		 * (trailing slashes, `?p=123` → permalink, and the rest), which is the behaviour a site without
		 * this plugin has and must keep.
		 */
		add_filter( 'redirect_canonical', array( $this, 'allow_language_prefix' ), 10, 2 );

		// Front-end requests and front-end AJAX (e.g. post-grid widgets loaded
		// from admin-ajax) both need language filtering.
		if ( ! is_admin() || $this->router->is_frontend_ajax() ) {
			add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
			add_filter( 'the_posts', array( $this, 'swap_singular_translation' ), 10, 2 );

			// Previous/next post navigation uses get_adjacent_post (separate SQL),
			// so it needs its own language exclusion.
			add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_where' ), 10, 5 );
			add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_where' ), 10, 5 );
		}
	}

	/**
	 * Resolve the static front page to its translation before WordPress builds
	 * the main query. Replacing it later in the_posts is sufficient for normal
	 * singular pages, but an Elementor preview of /{language}/ still retains
	 * the source front page as its queried object and can load the wrong theme
	 * template as a result.
	 *
	 * @param mixed $front_page_id Configured static front page post ID.
	 * @return mixed Translated front page ID when the current URL has a
	 *               non-default language prefix; otherwise the original value.
	 */
	public function filter_front_page_id( $front_page_id ) {
		if ( is_admin() && ! $this->router->is_frontend_ajax() ) {
			return $front_page_id;
		}

		if ( ! $front_page_id || $this->router->is_default_language() ) {
			return $front_page_id;
		}

		$translation_id = $this->linker->get_translation( (int) $front_page_id, $this->router->get_current_code() );

		return $translation_id ? $translation_id : $front_page_id;
	}

	/**
	 * Turn a bare language root such as /fr/ into a singular query for that
	 * language's static front page. The rewrite rule deliberately only supplies
	 * aumlang_lang; without an explicit page_id WordPress can still treat the
	 * request as the posts archive on installations with complex theme routing.
	 *
	 * @param array $query_vars Parsed public query vars.
	 * @return array
	 */
	/**
	 * Keep core's canonical redirect away from language-prefixed URLs.
	 *
	 * @param string|false $redirect_url  Where core wants to send the request.
	 * @param string       $requested_url The address that was asked for.
	 * @return string|false
	 */
	public function allow_language_prefix( $redirect_url, $requested_url ) {
		if ( $this->router->is_default_language() ) {
			return $redirect_url;
		}

		$language = $this->router->get_current_language();

		if ( ! $language ) {
			return $redirect_url;
		}

		$path = (string) wp_parse_url( (string) $requested_url, PHP_URL_PATH );
		$slug = trim( (string) $language->slug(), '/' );

		// Only step aside for the prefix this plugin put there.
		if ( '' !== $slug && ( $path === '/' . $slug || 0 === strpos( $path, '/' . $slug . '/' ) ) ) {
			return false;
		}

		return $redirect_url;
	}

	public function resolve_language_front_page_request( $query_vars ) {
		if ( is_admin()
			|| empty( $query_vars[ Router::QUERY_VAR ] )
			|| 'page' !== get_option( 'show_on_front' ) ) {
			return $query_vars;
		}

		/*
		 * 🔴 **Only a bare /{lang}/ is the language front page.**
		 *
		 * This used to guard on `page_id` / `pagename` / `name` alone, which are the vars a *singular*
		 * request carries — so every other prefixed URL fell through and had the front page id forced
		 * onto it. `/{lang}/{post-type-archive}/` then arrived as `post_type=finish&page_id=123`:
		 * WordPress resolves that as a page, `is_post_type_archive()` is false, the archive never runs
		 * and the request 404s. The same applied to taxonomy, date and author archives and to search —
		 * i.e. **everything except single posts and pages was broken under a language prefix**, while
		 * `/{lang}/` and `/{lang}/{page}/` looked fine, which is why it survived.
		 *
		 * Inverting the test is what makes it correct: the front page is what you get when the language
		 * var is *all there is*, so anything else present means this is not the front page. Listing what
		 * may accompany it is a closed set; listing what may not is open-ended and will be wrong again
		 * the next time a query var is added.
		 */
		$ignorable = array( Router::QUERY_VAR, 'preview', 'preview_id', 'preview_nonce', 'page' );

		foreach ( $query_vars as $key => $value ) {
			if ( in_array( $key, $ignorable, true ) ) {
				continue;
			}

			if ( '' !== $value && null !== $value && array() !== $value ) {
				return $query_vars;
			}
		}

		// filter_front_page_id() resolves this to the translated front page.
		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $front_page_id ) {
			$query_vars['page_id'] = $front_page_id;
		}

		return $query_vars;
	}

	/**
	 * Exclude other-language posts from previous/next post navigation.
	 *
	 * @param string         $where          The adjacent-post WHERE clause.
	 * @param bool           $in_same_term   Unused.
	 * @param int[]|string   $excluded_terms Unused.
	 * @param string         $taxonomy       Unused.
	 * @param \WP_Post|null  $post           Current post.
	 * @return string
	 */
	public function filter_adjacent_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		unset( $in_same_term, $excluded_terms, $taxonomy, $post );

		$hide = $this->linker->get_posts_to_hide_for_language( $this->router->get_current_code() );

		if ( empty( $hide ) ) {
			return $where;
		}

		$ids = implode( ',', array_map( 'intval', $hide ) );

		return $where . " AND p.ID NOT IN ( {$ids} )";
	}

	/**
	 * Switch the locale to the current language's locale.
	 *
	 * @param string $locale Incoming locale.
	 * @return string
	 */
	public function filter_locale( $locale ) {
		$language = $this->router->get_current_language();

		if ( $language && '' !== $language->locale() ) {
			return $language->locale();
		}

		return $locale;
	}

	/**
	 * Hide posts that don't belong to the current language from a listing query.
	 *
	 * Applies to the main query and to secondary queries (block query loops,
	 * related-posts, widgets) alike. Uses post__not_in so it merges safely with
	 * any IDs the original query already excludes.
	 *
	 * @param \WP_Query $query Query being prepared.
	 * @return void
	 */
	public function filter_query( $query ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Skip admin screens, but allow front-end AJAX (post-grid widgets).
		if ( is_admin() && ! $this->router->is_frontend_ajax() ) {
			return;
		}

		// Singular requests are resolved/swapped via the_posts, not filtered here.
		if ( $query->is_singular() ) {
			return;
		}

		if ( ! $this->is_filterable_post_type( $query->get( 'post_type' ) ) ) {
			return;
		}

		// Explicit opt-out for callers that manage language themselves.
		if ( $query->get( 'aumlang_skip_lang' ) ) {
			return;
		}

		$hide = $this->linker->get_posts_to_hide_for_language( $this->router->get_current_code() );

		if ( empty( $hide ) ) {
			return;
		}

		$existing = (array) $query->get( 'post__not_in' );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $hide ) ) ) );
	}

	/**
	 * Whether a query's post type(s) should be language-filtered.
	 *
	 * @param string|string[] $post_type Query post_type value.
	 * @return bool
	 */
	private function is_filterable_post_type( $post_type ) {
		if ( empty( $post_type ) ) {
			return true;
		}

		foreach ( (array) $post_type as $type ) {
			if ( in_array( $type, $this->skip_post_types, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * On a singular request, replace a source post with its translation.
	 *
	 * @param \WP_Post[] $posts Posts found by the main query.
	 * @param \WP_Query  $query The query.
	 * @return \WP_Post[]
	 */
	public function swap_singular_translation( $posts, $query ) {
		if ( is_admin() || ! $query->is_main_query() || $this->router->is_default_language() ) {
			return $posts;
		}

		if ( 1 !== count( $posts ) ) {
			return $posts;
		}

		$source         = $posts[0];
		$translation_id = $this->linker->get_translation( $source->ID, $this->router->get_current_code() );

		if ( $translation_id && $translation_id !== (int) $source->ID ) {
			$translation = get_post( $translation_id );

			if ( $translation instanceof \WP_Post ) {
				/*
				 * 🔴 **Swap the queried object too, not just the posts array.**
				 *
				 * `the_posts` changes what the loop renders. It does **not** change what
				 * `get_queried_object()` answers — and that is what the document title, `rel=canonical`,
				 * `body_class()` and **every SEO plugin** read. Leaving it behind produced a translated
				 * page whose body was in the target language while its `<title>` was in the source
				 * language, and — far worse — whose canonical pointed at the source page, telling search
				 * engines the translation is a duplicate and should not be indexed. The translated half
				 * of a site would quietly never rank. (Measured 2026-09-05: `<title>` "About" on a page
				 * whose H1 read 关于我们, canonical `/about/` on `/zh/about/`.)
				 *
				 * Both properties are set: `get_queried_object()` returns the cached object when it is
				 * already set and otherwise rebuilds it from the query vars — which would land back on
				 * the source.
				 */
				$query->queried_object    = $translation;
				$query->queried_object_id = (int) $translation->ID;

				return array( $translation );
			}
		}

		return $posts;
	}
}
