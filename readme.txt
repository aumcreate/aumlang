=== AumLang – AI Multilingual Translation & SEO ===
Contributors: aumcreate
Tags: multilingual, translation, multilanguage, hreflang, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.10
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Builder-friendly, SEO-complete AI multilingual translation. One-click page translation that preserves your Elementor and Gutenberg layouts.

== Description ==

AumLang turns your WordPress site multilingual with one-click AI translation that keeps your page builder layouts intact. It uses the AI client that ships with WordPress 7.0, so on a site that already has a provider connected there is nothing to configure and no key to enter.

**Highlights**

* **Builder-aware translation.** Elementor and Gutenberg pages are translated automatically — text only, layout and styles untouched. Custom widgets and repeaters are detected from the builder's own type system, with zero per-widget configuration.
* **One-click, fully automatic.** Translate a post, page or product and AumLang handles the title, content, excerpt, categories, tags and more.
* **SEO complete.** Per-language URLs (/zh/…), hreflang alternates, per-language canonical, and a multilingual XML sitemap.
* **Glossary.** Pin how key terms are translated (e.g. keep a brand name, force "post" → a chosen term) so AI output stays consistent and on-brand.
* **Interface string translation.** Capture the theme/plugin interface strings your visitors actually see and translate them on demand — and pull official WordPress.org language packs automatically.
* **Taxonomy translation.** Categories, tags and custom taxonomies get per-language versions, with translated archives and language-filtered term lists.
* **Language switcher.** Shortcode, template tag, block and widget — drop it anywhere.
* **Navigation menus.** One menu serves every language; items auto-switch to their translation.
* **WooCommerce.** Products, product categories, tags, brands and attributes are translated, and the full product data (price, stock, gallery) is carried over so the translation stays a working product.

Either way the AI account is yours, so translation cost is under your control — no per-translation fees, no quotas from us, and no account with us of any kind.


= Translation engines =

AumLang prefers the AI client that ships with WordPress 7.0, and new installs use it by default. The site owner connects a provider once under **Settings > Connectors**; this plugin then sends only the text to be translated, holds no API key of its own, and shares that one connection with every other plugin on the site.

A direct connection is also offered, because the connectors WordPress ships cover Anthropic, Google and OpenAI, and a site whose network cannot reach any of those still needs a way to translate. It is entirely opt-in and requires your own key.

= External Services =

Nothing leaves your site until you pick an engine and start a translation. The plugin has no account with us, sends no telemetry, and makes no request on its own.

*Using the WordPress AI Client:* the text being translated goes to whichever provider the site owner connected under Settings > Connectors. WordPress owns that connection and its credentials; the terms that apply are those of the provider chosen there.

*Using the direct connection:* the plugin is a client for the OpenAI-compatible chat API, so it can talk to any service implementing it. It sends the strings being translated together with the source and target language, and nothing else. You supply the endpoint, the model and the key; presets are offered purely to fill the endpoint and model fields for services people commonly use:

* DeepSeek - https://api.deepseek.com - [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html) | [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)
* OpenAI - https://api.openai.com - [Terms](https://openai.com/policies/row-terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy)
* OpenRouter - https://openrouter.ai - [Terms](https://openrouter.ai/terms) | [Privacy](https://openrouter.ai/privacy)

Choosing a preset changes two text fields; it does not contact anything. Any other compatible endpoint can be entered by hand, in which case its operator's terms are the ones that apply.

Text that has already been translated is served from your own database and never leaves the site again.

== Installation ==

1. Upload the `aumlang` folder to `/wp-content/plugins/`, or install the plugin ZIP via Plugins → Add New → Upload.
2. Activate the plugin through the Plugins screen.
3. Make sure pretty permalinks are enabled (Settings → Permalinks) — language URLs like `/zh/` require them.
4. Go to the AumLang settings screen and add your languages. Leave the translation engine on "WordPress AI Client" if a provider is already connected under Settings > Connectors; otherwise switch it to the direct connection and enter your own endpoint, model and key.
5. Open any post, page or product and use the AumLang panel to translate it.

== Frequently Asked Questions ==

= Do I need an API key? =
Not if WordPress already has one. AumLang's default engine is the AI client built into WordPress 7.0, which uses the provider connected under Settings > Connectors — that connection belongs to WordPress, is shared with every other plugin, and this plugin never sees the key.

You need your own key only for the second engine, the direct connection. It exists because the connectors WordPress ships cover Anthropic, Google and OpenAI, and a site whose network cannot reach any of those still needs a way to translate. It speaks the OpenAI-compatible chat API, so any service implementing it will do; you supply the endpoint, the model and the key, and the AI account is yours either way.

= Does it work with Elementor and Gutenberg? =
Yes. AumLang reads each builder's own structure, translating only the text and leaving layout, styles and design untouched — including custom widgets.

= Will it translate WooCommerce products? =
Yes — product title, description, short description, categories, tags, brands and attributes, while carrying over price, stock and gallery so the translation remains a complete product. Multi-currency is intentionally out of scope; pair AumLang with a dedicated currency plugin if you need it.

= How do visitors switch language? =
Add the language switcher via the `[aumlang_language_switcher]` shortcode, the block, the widget, or the `aumlang_language_switcher()` template tag.

== External and bundled resources ==

This plugin bundles **flag-icons** 7.2.3 by Panayiotis Lipiridis, unmodified, as the flag images in
`src/assets/flags/`. It is licensed MIT; the licence text ships alongside them in
`src/assets/flags/LICENSE.txt`.

* Source: https://github.com/lipis/flag-icons

The plugin makes **no requests to any external service** to render a switcher: the flags are served
from this plugin's own directory.

== Changelog ==

= 1.0.10 =
* Renamed for the plugin directory: the listing title now says what the plugin does. No functional change.

= 1.0.9 =
* Renamed the ACF integration class and its file from `Acf` to `AcfFields`, so a file called `Acf.php` can no longer be read as a bundled copy of Advanced Custom Fields. No behaviour changes: the class has always been ours, and it still does nothing unless ACF is active.

= 1.0.8 =
* Interface-string discovery no longer opens an output buffer of its own. It subscribes to the template enhancement buffer WordPress 6.9 added, which core opens and closes itself, so this plugin leaves nothing on the buffer stack and never touches a buffer belonging to something else. On WordPress older than 6.9 the discovery toggle is disabled and says so; importing from a .pot file and the automatic language packs are unaffected.
* Removed the Moonshot (Kimi) preset. Its published terms and privacy pages now redirect to an unrelated page, so there is no document left to link to. Moonshot remains usable through the custom endpoint like any other OpenAI-compatible service.

= 1.0.7 =
* The readme described a plugin that needs your own API key, which stopped being true when the WordPress AI Client became the default engine. Rewritten so the description, the install steps and the FAQ all say the same thing: the client built into WordPress 7.0 is the default and needs no key, and the direct connection is the opt-in fallback for a site that cannot reach the providers WordPress ships connectors for.
* The output buffer used by interface-string discovery is now closed explicitly on `shutdown` instead of being left for PHP to flush at the end of the request. Nothing about discovery changes; the buffer stack the plugin hands back is now the one it was given.

= 1.0.6 =
* The switcher can show flags. Two new display styles, "Flag only" and "Flag + language name", in every place the switcher appears: the floating overlay, the menu location, the widget, the block and the shortcode.
* The flag comes from the locale's country, not from the language code — `en` is neither the British nor the American flag, but `en_GB` and `en_US` are. A language registered without a country simply shows no flag rather than a guessed one.
* The flags are SVG files bundled with the plugin, not emoji. Windows ships no flag emoji font and renders them as two letters, and letting WordPress substitute its own emoji images means a request to s.w.org for every flag on every page view — and a broken image on a site with no route to the internet. Nothing is fetched from anywhere but this plugin.
* A flag is decorative: it is `aria-hidden` and the language name is still announced, because a flag is a country and not a language. New `aumlang_flag_html` filter for sites that want to supply their own images.
* New `aumlang_render_floating_switcher` filter so a theme that places the switcher itself can stand the floating one aside. Without it the two are fixed to the same corner and overlap.

= 1.0.5 =
* Fixed: a translation could be saved with the source text in it, silently. Models sometimes echo their input back, and an echo passes every structural check — the right number of strings, in the right order, valid JSON — so untranslated text was written into the translation with no error anywhere. Echoed batches are now treated as a failure and retried, and the prompt says so explicitly.
* A custom field that failed to translate now says so in the log instead of being swallowed.

= 1.0.4 =
* Fixed: on a translated page the document title, `rel=canonical` and `body_class()` still described the source post — the body was in one language and the `<title>` in another, and the canonical told search engines the translation was a duplicate of the original. Anything reading the queried object, including every SEO plugin, was affected.

= 1.0.3 =
* The featured image is now copied to the translation. Nothing carried it before, so translated posts lost their image in every card, archive and share preview.
* Custom field values that are data rather than language — colours, ids, urls, dates, email addresses, serialized arrays — are copied but no longer sent to the translator. New `aumlang_translate_meta_value` filter to override per key.

= 1.0.2 =
* Fixed: with a language prefix in the URL, everything except single posts and pages 404'd. Post type archives, taxonomy, date and author archives and search were all given the front page's id and resolved as a page. `/{lang}/` and `/{lang}/{page}/` worked, which is why this was easy to miss.
* Fixed: `/{lang}/` was answered with a 301 to the unprefixed home page, so the translated front page could not be reached. Core's canonical redirect now stands aside for language-prefixed URLs only.

= 1.0.1 =
* Added the `aumlang_register_parsers` action so other plugins can add a content parser. A builder that stores its layout outside `post_content` is invisible to the built-in parsers and translates as an empty page; this is the supported way to teach AumLang about one.
* A parser that throws while deciding whether it supports a post is now skipped instead of taking the translation screen down with it.

= 1.0.0 =
* Initial release: builder-aware AI translation, SEO (URLs/hreflang/canonical/sitemap), glossary, interface-string translation, taxonomy translation, language switcher, menu translation, and WooCommerce product translation.
