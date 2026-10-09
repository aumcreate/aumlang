<?php
/**
 * Bridge: AumLang <-> Aumframe.
 *
 * # Why a bridge, and why it lives here
 *
 * Aumframe is sold on its own and a buyer may run WPML or Polylang instead — so the engine must not know
 * any multilingual plugin by name. It asks a question through a filter; whoever integrates answers it.
 * This file is that answer for our own stack, next to the WooCommerce and AumReserva bridges.
 *
 * With AumLang inactive every function here returns early and the engine keeps its single-language
 * behaviour unchanged. **That is the contract: nothing in here may alter a site that is not multilingual.**
 *
 * # What was actually broken (measured 2026-09-04, not reasoned about)
 *
 * AumLang stores one post per language, linked source -> target. Aumframe binds a template to content by
 * post id (`post_ids`, resolved from the kit's `postSlugs` at import). A translation is a *different*
 * post, so:
 *
 * - `/about/` rendered `page-about`; its translation rendered **`singular-fallback`** — the buyer
 *   translates a page and the design is gone, with no error anywhere.
 * - Every internal link binding (`link.@post:<slug>`) resolved to the **source** language's post, so a
 *   visitor reading the translated site was thrown back into the default language on the next click.
 *
 * @package AumCreate
 * @subpackage Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 🔴 2026-10-06：这座桥从 `themes/aumcreate/inc/aumlang-bridge.php` 搬到了这里。
 *
 * 搬的原因是量出来的，不是觉得应该：实现留在主题里时，一个从 wordpress.org
 * 装了 AumLang + Aumframe、用自己主题的人，在 /ja/ 上拿到的是
 * `aumframe/current_language` → `''`，于是 Aumframe 走 Storage.php 的早退分支，
 * **日语内容配源语言版面**。不报错、不提示，悄悄不生效。
 *
 * 实测（orgcheck，2026-10-06，前台 /ja/ 请求，摘掉桥 vs 不摘，其余不变）：
 *     current_language        带桥 "ja"  / 不带桥 ""
 *     template_post_id        带桥 197   / 不带桥 198
 *     link_post_id            带桥 198   / 不带桥 197
 *     translatable_languages  带桥 有列表 / 不带桥 []
 * 搬进插件之后这四个值必须逐项不变 —— 那才是「搬对了」，而不是「能跑」。
 *
 * 主题里那份只留说明、不再注册。但主题和插件各自升级，所以旧主题仍可能在注册：
 * 下面这个守卫是为那种站写的 —— 旧主题还管着的时候插件让位，避免同一优先级上
 * 两个回调做同一件事；主题更新之后插件自然接管。
 */
if ( function_exists( 'aumcreate_aumlang_template_post_id' ) ) {
	return;
}


/**
 * Is AumLang present?
 *
 * ⚠️ Checked through the plugin's own public entry point (`aumlang()`), never by reflecting into it.
 * Reaching into a private property works right up until the plugin is refactored, and then it fatals on
 * the buyer's site.
 *
 * @return bool
 */
function aumlang_aumframe_active() {
	return function_exists( 'aumlang' ) && class_exists( '\\AumLang\\Core\\Plugin' );
}

/**
 * Match template rules against the original content, not its translation.
 *
 * @param int $post_id Queried post id.
 * @return int
 */
function aumlang_aumframe_template_post_id( $post_id ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 || ! aumlang_aumframe_active() ) {
		return $post_id;
	}

	try {
		$source = (int) aumlang()->content()->get_source_id( $post_id );
	} catch ( \Throwable $e ) {
		return $post_id;
	}

	// get_source_id() gives back the post itself when it is not a translation.
	return $source > 0 ? $source : $post_id;
}
add_filter( 'aumframe/template_post_id', 'aumlang_aumframe_template_post_id' );

/**
 * The current request's language code, or '' on the default language / no plugin.
 *
 * @return string
 */
function aumlang_aumframe_current_code() {
	if ( ! aumlang_aumframe_active() ) {
		return '';
	}

	try {
		$router = aumlang()->router();

		if ( $router->is_default_language() ) {
			return '';
		}

		return (string) $router->get_current_code();
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * Point a link binding at the current language's version of the content.
 *
 * @param int $post_id Post named by the binding.
 * @return int
 */
function aumlang_aumframe_link_post_id( $post_id ) {
	$post_id = (int) $post_id;
	$lang    = aumlang_aumframe_current_code();

	if ( $post_id <= 0 || '' === $lang ) {
		return $post_id;
	}

	try {
		$translated = (int) aumlang()->content()->get_translation( $post_id, $lang );
	} catch ( \Throwable $e ) {
		return $post_id;
	}

	/*
	 * ⚠️ No translation yet is **not** an error, and must not blank the link. The visitor gets the
	 * default-language page, which is a working page — far better than a dead link on a site that is
	 * halfway translated, which is the normal state of every multilingual site while it is being built.
	 */
	return $translated > 0 ? $translated : $post_id;
}
add_filter( 'aumframe/link_post_id', 'aumlang_aumframe_link_post_id' );

/**
 * Same for a term archive link.
 *
 * @param int $term_id Term named by the binding.
 * @return int
 */
function aumlang_aumframe_link_term_id( $term_id ) {
	$term_id = (int) $term_id;
	$lang    = aumlang_aumframe_current_code();

	if ( $term_id <= 0 || '' === $lang ) {
		return $term_id;
	}

	try {
		$translated = (int) aumlang()->terms()->get_translation( $term_id, $lang );
	} catch ( \Throwable $e ) {
		return $term_id;
	}

	return $translated > 0 ? $translated : $term_id;
}
add_filter( 'aumframe/link_term_id', 'aumlang_aumframe_link_term_id' );

/**
 * Add the language prefix to addresses that have no object to swap.
 *
 * A content type archive is the case: `/finishes/` is the same archive in every language, and only the
 * prefix distinguishes them. Post and term links do not come through here needing anything — the plugin
 * already filters `post_link` / `term_link`, so the ids swapped above resolve to prefixed addresses.
 *
 * @param string $url  Resolved address.
 * @param string $expr Binding expression.
 * @return string
 */
function aumlang_aumframe_link_url( $url, $expr ) {
	$lang = aumlang_aumframe_current_code();

	if ( '' === $lang || 0 !== strpos( (string) $expr, 'link.archive.' ) ) {
		return $url;
	}

	try {
		return (string) aumlang()->urls()->convert( (string) $url, $lang );
	} catch ( \Throwable $e ) {
		return $url;
	}
}
add_filter( 'aumframe/link_url', 'aumlang_aumframe_link_url', 10, 2 );

/* ── The kit's `aumlang` settings block ───────────────────────────── */

/**
 * Claim the `aumlang` block of a kit's `pluginSettings`.
 *
 * Same division of labour as `nexcart-kit.php` / `reserva-kit.php` / the AumViso block: **Aumframe does not
 * recognise AumLang** and only broadcasts `pluginSettings`; whoever recognises a block applies it.
 *
 * ── Why a kit has to be able to set this ──────────────────────────
 *
 * AumLang copies a translation's custom fields only for the meta keys the site owner has registered, and
 * the list starts empty — a sane default, because it cannot guess which of a theme's meta keys are content.
 * But a kit *does* know: it authored those fields. Without seeding them, a buyer translates a product page
 * and gets a page with no specifications, no price note and no dimensions — **the page is there and its
 * data is gone**, which reads as "the translation is broken" rather than "a setting is unset".
 *
 * ⚠️ A kit is data out of a downloaded package, not trusted input: take only this one key, and accept only
 * a flat list of plain meta-key strings.
 */
add_action( 'aumframe/kit/plugin_settings', function ( $settings ) {
	if ( ! aumlang_aumframe_active() ) {
		return;                                   // Not installed: nothing to do
	}

	$mine = is_object( $settings ) ? ( $settings->aumlang ?? null ) : null;

	if ( ! is_object( $mine ) && ! is_array( $mine ) ) {
		return;
	}

	$mine = (array) $mine;

	/*
	 * 🔴 Three keys, not one (2026-09-25).
	 *
	 * Until today this bridge read `translatable_meta_keys` and nothing else, while kits had started
	 * shipping `translatable_post_types` / `translatable_taxonomies` as well. Nothing on the site read
	 * them, so a kit could say "translate the products" and the products stayed monolingual — and every
	 * layer in between reported success: the package built, the import warned about nothing, the option
	 * row was written. Only the result was empty.
	 *
	 * That is the same shape as the bug those keys were added to fix ("the kit says which FIELDS to
	 * translate but never says the TYPE may be translated"). A contract with a writer and no reader
	 * fails silently at exactly the place nobody looks.
	 *
	 * Each key is handled independently: a kit that ships only one of the three still works, and a kit
	 * that ships a key this theme version does not know is ignored rather than fatal.
	 */
	$known = array(
		'translatable_meta_keys'   => '/^[A-Za-z0-9_\-]{1,255}$/',   // meta keys: WP allows long identifiers
		'translatable_post_types'  => '/^[A-Za-z0-9_\-]{1,20}$/',    // register_post_type() caps at 20
		'translatable_taxonomies'  => '/^[A-Za-z0-9_\-]{1,32}$/',    // register_taxonomy() caps at 32
	);

	$options = get_option( 'aumlang_settings', array() );
	$options = is_array( $options ) ? $options : array();
	$dirty   = false;

	foreach ( $known as $option_key => $pattern ) {
		if ( ! isset( $mine[ $option_key ] ) || ! is_array( $mine[ $option_key ] ) ) {
			continue;
		}

		$clean = array();

		foreach ( $mine[ $option_key ] as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$value = trim( $value );

			/*
			 * These are plain identifiers. Anything else is not one, whatever the package says.
			 * ⚠️ A kit is data out of a downloaded package, not trusted input.
			 */
			if ( '' !== $value && preg_match( $pattern, $value ) ) {
				$clean[] = $value;
			}
		}

		if ( ! $clean ) {
			continue;
		}

		$existing = isset( $options[ $option_key ] ) && is_array( $options[ $option_key ] )
			? $options[ $option_key ]
			: array();

		/*
		 * ⚠️ Merge, never replace. The buyer may have registered keys, types or taxonomies of their own
		 * before importing, and a kit quietly deleting them would be indistinguishable from
		 * "translation stopped working".
		 */
		$merged = array_values( array_unique( array_merge( $existing, $clean ) ) );

		if ( $merged !== $existing ) {
			$options[ $option_key ] = $merged;
			$dirty                  = true;
		}
	}

	if ( $dirty ) {
		update_option( 'aumlang_settings', $options );
	}
} );

/**
 * Tell Aumframe which language to render layouts in.
 *
 * Empty means "the source language", which is both the answer on the default language and the answer on
 * a site with no multilingual plugin — so the engine's overlay costs nothing until there is something to
 * overlay.
 *
 * @param string $lang Current value.
 * @return string
 */
function aumlang_aumframe_current_layout_language( $lang ) {
	$code = aumlang_aumframe_current_code();

	return '' !== $code ? $code : $lang;
}
add_filter( 'aumframe/current_language', 'aumlang_aumframe_current_layout_language' );

/**
 * Translate a batch of layout strings with AumLang's provider.
 *
 * Aumframe extracts and applies; **the actual translating is not the engine's business** — it raises
 * this filter and takes what comes back. A site on WPML answers it from `multilingual-bridge.php`
 * instead, and the engine never knows the difference.
 *
 * ⚠️ Order is the contract: the batch translator returns a list in the order it was given, so the keys
 * are re-attached by position. Losing that alignment would put the footer's text in a button, which is
 * the kind of wrong that looks like a translation bug rather than a plumbing one.
 *
 * ⚠️ A failure returns **nothing at all**, not partial guesses. `TemplateI18n::prepare()` then leaves
 * those strings in the source language and the page still renders — a page in the wrong language beats
 * a page with the wrong words in it.
 *
 * @param array  $translations Map so far (path => text).
 * @param array  $strings      path => source text.
 * @param string $lang         Target language code.
 * @return array
 */
function aumlang_aumframe_translate_strings( $translations, $strings, $lang ) {
	if ( ! aumlang_aumframe_active() || empty( $strings ) ) {
		return $translations;
	}

	try {
		$provider = aumlang()->providers()->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			return $translations;
		}

		$default = aumlang()->languages()->get_default_language();
		$source  = $default ? $default->code() : '';

		if ( '' === $source || $source === $lang ) {
			return $translations;
		}

		$paths  = array_keys( $strings );
		$values = array_values( $strings );

		$c         = \AumLang\Core\Plugin::instance();
		$container = $c->container();
		$batch     = $container->get( 'batch_processor' );

		$out = $batch->translate( $provider, $values, $source, $lang );

		foreach ( $paths as $i => $path ) {
			if ( isset( $out[ $i ] ) && is_string( $out[ $i ] ) && '' !== trim( $out[ $i ] ) ) {
				$translations[ $path ] = $out[ $i ];
			}
		}
	} catch ( \Throwable $e ) {
		/*
		 * Including the echo guard added in AumLang 1.0.5 — a provider that hands the input straight back
		 * throws here, and leaving the layout in the source language is exactly the right outcome.
		 */
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'AumCreate: layout strings not translated to %s — %s', $lang, $e->getMessage() )
			);
		}
	}

	return $translations;
}
add_filter( 'aumframe/translate_strings', 'aumlang_aumframe_translate_strings', 10, 3 );

/**
 * Which languages layouts can be translated into.
 *
 * ⚠️ The engine keeps **no language table of its own** and must not grow one — languages belong to the
 * multilingual plugin, and a second list is how two of them start disagreeing about what is installed.
 * The default language is excluded: that one is the layout as it was authored.
 *
 * @param array $languages code => label.
 * @return array
 */
function aumlang_aumframe_translatable_languages( $languages ) {
	if ( ! aumlang_aumframe_active() ) {
		return $languages;
	}

	try {
		$default = aumlang()->languages()->get_default_language();
		$default = $default ? $default->code() : '';

		foreach ( aumlang()->languages()->get_languages( true ) as $language ) {
			if ( $language->code() === $default ) {
				continue;
			}

			$languages[ $language->code() ] = $language->name() ? $language->name() : $language->code();
		}
	} catch ( \Throwable $e ) {
		return $languages;
	}

	return $languages;
}
add_filter( 'aumframe/translatable_languages', 'aumlang_aumframe_translatable_languages' );
