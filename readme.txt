=== AumLang – AI Multilingual Translation & SEO ===
Contributors: aumcreate
Tags: hreflang, ai translation, woocommerce, elementor, language switcher
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI translation for Elementor, Gutenberg and WooCommerce that keeps layouts intact, with per-language URLs, hreflang, canonicals and sitemaps handled.

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

= Source code =

The released source is on GitHub at [github.com/aumcreate/aumlang](https://github.com/aumcreate/aumlang) — bug reports and pull requests are welcome there.

= More from AumCreate =

What it translates, how layouts are kept intact, and how hreflang is emitted: [aumcreate.com/plugins/aumlang](https://aumcreate.com/plugins/aumlang)

Also free from AumCreate: llms.txt and schema for AI search, AI crawler control, digital-goods checkout for WooCommerce, and a hosted chat widget: [aumcreate.com/plugins](https://aumcreate.com/plugins)

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

* Source: [github.com/lipis/flag-icons](https://github.com/lipis/flag-icons)

The plugin makes **no requests to any external service** to render a switcher: the flags are served
from this plugin's own directory.

== Screenshots ==

1. Languages, and which post types, taxonomies and custom fields get translated.
2. Translating from the editor — one box, one row per language, no separate screen.
3. The Review tab: every machine translation beside its original, ready to proofread and mark as reviewed.
4. Translation provider — the WordPress AI Client, or your own key against any OpenAI-compatible API.
5. The glossary pins how key terms are translated, so the AI stays consistent and on-brand.
6. The front-end switcher: add it to a menu, float it on every page, or place it with a shortcode or block.

== Changelog ==

= 1.4.3 =
* Fixed: a page that had already been translated kept showing as translated even when the plugin had
  since become able to read more of it. Updating the plugin does not re-translate anything by itself —
  that would spend your credit without asking — but nothing said there was anything left to do, so a
  page could sit with hundreds of untranslated pieces while the screen said it was done. The editor and
  the page list now say "Incomplete", give the number of pieces that have never been translated, and
  offer "Translate the rest". The notice works itself out: it is counted from what is actually stored,
  so it appears whenever the plugin learns to see more, and disappears once the rest is translated.

= 1.4.2 =
* Fixed: tables and other content that a page builder stores encoded inside a shortcode attribute were
  never translated. Some builders keep a block of raw HTML as one long encoded attribute value; from the
  outside it looks like a meaningless string, so it was skipped. On one site that was eight tables and
  six hundred cells, while the rest of the page translated normally — so it looked as though tables in
  particular were unsupported. Such a value is now read, its text translated piece by piece, and stored
  back the way the builder expects. Detection is by shape rather than by builder name, so it applies to
  any builder that does this.
* Fixed: a shortcode with a very long attribute value had **all** of its attributes skipped. The pattern
  used to read attributes could exceed a limit inside PHP's regular-expression engine; when that happened
  it reported no match, which is indistinguishable from "this shortcode has no attributes". The text was
  still there and the page still displayed correctly — it simply never reached the translator, and nothing
  reported a problem. Long values are now read without that limit applying, and if the engine ever does
  fail, it is recorded instead of passing silently.
* Changed: a round that finishes now also reports how much of the page it could see. "Sent 3, finished"
  reads as success, and can be hiding six hundred untranslated table cells; the two numbers only mean
  something together.

= 1.4.1 =
* Fixed: a table cell containing a "less than" sign written as plain text, such as the "<0.1%" common in
  specification tables, lost its contents when the page was translated. That is not valid HTML, and the
  parser treated the "<" as the start of a tag and swallowed the value with it — the cell came back empty
  and nothing reported a problem. Such a value is now kept, written the proper way so it displays exactly
  as before.
* Fixed: the report of text left untranslated listed part numbers such as "TU-872/SLKSP", which read the
  same in every language. Three of those in a report make it look as though something went wrong when
  nothing did.
* Fixed: short sentences in Chinese, Japanese and Korean were missing from that report. The minimum length
  for mentioning something was set for languages that put spaces between words; writing that does not has
  a whole sentence in the space of three English words.

= 1.4.0 =
* Fixed: the list of custom fields suggested on the settings screen was mostly things that must never be
  translated. It offered whatever was stored against a page — a builder's saved layout, a page-view
  counter, a version fingerprint — because it looked only at the names. Adding one of those would have
  sent it to be translated and the translated value would have replaced the real one, quietly breaking
  the page. Suggestions are now judged by what a field actually contains, so a subtitle or a button
  label is offered and a colour, a count or a fingerprint is not.
* Added: when a theme or plugin already states which of its stored fields hold text, AumLang follows it.
  Many of them ship such a statement for multilingual tools, and the person who wrote the theme knows
  which of its fields are a subtitle and which are a part number. Fields it marks as text are translated;
  fields it marks as "copy" are carried across untouched, even when the value reads like a sentence,
  because the author saying so is better evidence than the value's appearance. Until now this list had to
  be typed in by hand, which meant asking the site owner a question the theme's author had already
  answered.
* Added: post types and taxonomies a theme or plugin declares as translatable are translatable by default.
  Install a products plugin that says so and its products can be translated without going to look for the
  setting. This only sets the starting point — a choice saved in Settings always wins.

= 1.3.2 =
* Added: more builder fields are recognised, chosen by measurement rather than by guesswork. A large set of
  field declarations published across the multilingual ecosystem was checked against AumLang's own
  recognition, and the names it was genuinely missing — slogan, subhead, cite, info, position, occupation,
  company, notice and a few more — were added. Recognition of those declarations went from 72% to 82%
  without adding a single rule for a specific builder. Names that look like text but usually are not, such
  as a street address or a quantity, were deliberately left out.
* Added: toggle and tab headings for WPBakery, Divi and Avada.

= 1.3.1 =
* Fixed: text in Chinese, Japanese, Korean or Thai inside a page builder's fields was not translated. A
  check meant to ignore product codes asked for at least two separate words, and writing without spaces
  between words never has two. Single words in any language, such as a button reading "Download", were
  being skipped for the same reason. The check now only applies where it belongs, which is deciding what
  to mention in the coverage report, not deciding what to translate.
* Fixed: a field declared with a type or an encoding this version does not understand is now left alone
  rather than treated as ordinary text. Several of those types describe image addresses and record numbers
  rather than words, and translating one produces a broken image or a dead link while the page still looks
  fine. Not translating a field is a gap; translating the wrong one is a fault.
* Added: more of the field names builders actually use are recognised — heading, subheading, message,
  tagline, blurb, excerpt, summary, intro, body and h1 through h6 among them. This matters most for
  builders nobody has described to AumLang, which is the common case.
* Added: four more builder fields, each checked against real pages rather than guessed.

= 1.3.0 =
* Fixed: links inside a translated page sent readers back to the original language. A Japanese page
  linking to "About us" pointed at the English About us, so a visitor reading in Japanese was dropped out
  of their own language with nothing to warn anybody. WordPress's own link filters do not reach links
  written into the content, which is where most internal links live — the editor puts them there, and so
  does every page builder. Those links now point at the translation of the page they lead to, worked out
  as the page is shown rather than when it is translated, so a link starts working the moment its target
  is translated. A page with no translation yet keeps its original link, since sending someone to a page
  that does not exist would be worse. Measured on a real page: no difference in load time.
* Added: content that a builder stores packed inside one field is now translated. Some builders keep a
  whole set of tabs or slides as encoded data in a single attribute — the headings and body text are in
  there, but from outside it looks like one unreadable string, so it was skipped and those parts of the
  page stayed in the original language. The packed content is now unpacked, the text inside it translated,
  and everything else put back exactly as it was.
* Changed: the list of which builder fields hold text now lives in builders.xml inside the plugin instead
  of in the code. Adding a builder is four lines in a file. It uses the same format themes and plugins
  already ship for multilingual tools, so a file written for those works here unchanged.
* Fixed: a field stored in encoded form was translated as if the encoding were part of the sentence.
  Some builders save text as `Our%20Services`, and that whole string was being sent off to be translated,
  so what came back was wrong and the page showed it without complaint. Encoded fields are now decoded
  before translation and stored back the way they were found.
* Added: if a theme or plugin on your site already says which of its fields hold text, AumLang reads that
  and uses it. Many themes and plugins ship a file in their own folder declaring this for multilingual
  tools, and whoever wrote the theme knows better than we can guess. Nothing is sent anywhere and nothing
  is copied into AumLang — the file is read where it already sits, on your own site, so it stays correct
  when the theme is updated. A theme that says nothing still works, through AumLang's own list and by
  recognising fields from their names.

= 1.1.3 =
* Added: AumLang now checks its own work. After a page is translated it reads the finished page back and
  lists any text that is still word for word the same as the original. It does this by looking at the raw
  page rather than by asking the part of the plugin that pulled the text out in the first place — so text
  that was never picked up is found too, which is exactly the kind of thing that used to be noticed only
  when somebody looked at the live site. Whatever it finds is reported with the translation and in the
  Diagnostics tab.
* Added: which shortcode attributes hold text is now a list that can be added to, rather than something
  guessed from the attribute's name alone. Bold Builder, WPBakery, Divi, Avada and the WordPress caption
  shortcode are described out of the box; a shortcode that is described is trusted exactly, so its layout
  settings are never sent for translation. Anything not described still falls back to recognising text by
  the attribute's name, so an unknown builder is not left out. Theme and plugin authors can declare their
  own with the `aumlang_shortcode_rules` filter.
* Fixed: colour values, CSS declarations, font subsets and certification numbers were being treated as
  sentences. They cost a request each and, worse, they crowded the real problems out of the report.

= 1.1.2 =
* Fixed: shortcode attributes written with single quotes or no quotes were not translated. Builders do not
  agree on this — one writes `text="A"`, another `text='A'`, another `title=A` — and only the first was
  being read, so an entire builder's worth of headings could be missed. All three forms are now read, and
  a quote escaped inside a value no longer cuts the value short.
* Fixed: text in a language that does not use the Latin alphabet was treated as if it were not text at all.
  The check for "does this look like a sentence" asked for Latin letters, so a Chinese, Japanese, Korean,
  Russian or Arabic source site had none of its shortcode content translated. It now recognises letters in
  any writing system.
* Fixed: attributes that exist only for the page builder's own editor, such as Divi's admin_label, are no
  longer translated. They never appear on the page.

= 1.1.1 =
* Fixed: on sites built with a shortcode page builder, most of the visible text was never translated.
  Builders such as Bold Builder, WPBakery, Avada and Divi keep headings, sub-headings, captions and button
  labels inside shortcode attributes — `[section headline="About Us" ...]` — and AumLang treated a run of
  shortcodes as markup and skipped it whole. On one real page that was 38 pieces of text, including every
  heading on the page. Shortcode attributes that hold text are now translated, while the shortcode itself,
  its layout settings and its technical values are left untouched. An attribute counts as text only when
  both its name and its value say so, so `headline="About Us"` is translated and `layout="boxed_1200"`,
  `animation="fade_in zoom_out"` and `el_style="display: flex"` are not.

= 1.1.0 =
* Changed: a paragraph is now translated as a paragraph. Until now every piece of text between two tags
  was translated on its own, so a sentence like "<strong>Acme Ltd</strong> ships from <a>Shenzhen</a>
  every Tuesday" went to the translation service as four separate pieces. They came back readable, because
  the pieces travel together and the model can see them in order, but the joins were wrong: brackets opened
  and never closed, sentences broken where the original had none, and word order that a language like
  Japanese cannot honour across a seam. Whole paragraphs, list items, headings and table cells now go as
  one piece with their formatting intact. Measured on a real page: 74 pieces became 13, one request instead
  of eight, and "PCBA processing (including SMT and DIP)" came back correct instead of with the bracket
  left open.
* Added: a translated paragraph is checked before it is kept. Sending formatting to the translation service
  means trusting it to come back, and when it does not — a link dropped, an address rewritten — the page
  still displays, just wrongly, with nothing to warn you. Tags and link addresses are now compared before
  and after, and a paragraph that does not match is kept in the original language instead. Translations may
  reorder formatting freely, because languages do.
* Fixed: the three builders no longer each decide how to split text. Elementor, the block editor and plain
  HTML content share one implementation, so this applies to all of them rather than to whichever one was
  edited last.

= 1.0.27 =
* Fixed: page-builder markup was being sent to the translation service as if it were a sentence. Deciding
  what counts as text relied on WordPress's own strip_shortcodes(), which removes only shortcodes that have
  already been registered — a page builder registers its own much later than this, and a stray closing tag
  is never matched by it at all. Whole runs of builder markup therefore still had letters in them and were
  treated as prose: wasted requests, output the builder may not survive, and pages that could not finish.
  Markup is now recognised by its shape rather than by any builder's name, so this covers the shortcode
  builders generally rather than the one it was found on. Text in square brackets that is really text,
  such as "[see note] for details", is still translated, as is text wrapped in builder tags.

* Added: every translation round now records how many pieces of text it sent, how many requests it made
  and the last error it hit, and the Diagnostics tab prints them. A page reporting "2 left" while each
  round takes 25 seconds cannot be explained from those two numbers alone, and what sat between them was
  not being measured at all.
* Fixed: the count of half-finished translations shown in Diagnostics included an internal bookkeeping
  entry, so one unfinished page was reported as two.

= 1.0.26 =
* Fixed: translation could stop making progress. 1.0.25 made each round end on time, but a round that
  ran out of time threw away everything it had done, and the next round started over at the same size —
  so a page could report "16 still to go" for twenty-four rounds in a row without ever moving. When a
  batch comes back wrong the plugin halves it and tries again, and only single strings are guaranteed to
  go through; reaching single strings from sixteen takes about forty seconds, which never fits in a
  twenty-second round. Now a round keeps whatever it finished, and a round that finishes nothing halves
  the batch size for the next one, down to single strings if that is what it takes. Progress is kept
  either way, so a page always moves forward.
* Added: when text will not translate, the Diagnostics tab now shows that text. Knowing that sixteen
  strings are left says nothing; seeing that they are product codes rather than sentences says what to do.

= 1.0.25 =
* Fixed: "502 Bad Gateway" could still happen on 1.0.22 and 1.0.24, because the twenty-second round budget
  was only checked between batches. When a batch comes back with the wrong number of translations the
  plugin retries it and then splits it in half, again and again — all of that happens inside one batch,
  where the budget was never looked at. What actually ended a round was the sixty-request ceiling, which
  at roughly two seconds a request is about two minutes, long past the point where a server gives up.
  Measured on a site that kept failing: a round budgeted at twenty seconds ran for 102 seconds and made
  sixty requests. The budget is now checked before every request to the translation service, including
  the retries and the splits, so the round ends when it says it will. The same case now ends after 20.4
  seconds and twelve requests, and whatever is unfinished carries over to the next round.

= 1.0.24 =
* Fixed: on a server that cuts requests off early, translation could still fail with "502 Bad Gateway"
  even though 1.0.22 split the work into short rounds. A round can only stop between calls to the
  translation service, so it finished whatever call it had already started — a round budgeted at twenty
  seconds could run for a minute if one call was slow. Each round now also checks whether there is time
  for another call before starting one, using how long the slowest call of that round took. Rounds do a
  little less each time and there are a few more of them, which is the right trade: one extra round costs
  a second, a cut-off connection costs the whole round.
* Added: a Diagnostics tab. It measures how long your server waits before cutting a request off, how long
  one call to your translation provider takes, and then translates a page you choose round by round until
  it finishes or fails. The last part matters: a server can answer a request held open for seventy seconds
  and still cut off a real translation, because one page is dozens of calls rather than one. Measuring the
  server alone would have called that site healthy.

= 1.0.23 =
* Fixed: on the Switcher tab, the explanation under "Menu parent label" was drawn on top of the input box.
  The spacing rule it used was written for an explanation that sits directly under a card title, and it
  pulled this one up by six pixels into the field above it.

= 1.0.22 =
* Fixed: translating a long page failed with "502 Bad Gateway". The whole page was translated in one
  request, and on a page with a lot of text that request ran for minutes — longer than most servers will
  wait before cutting it off. Translation now runs in short rounds: each round does about twenty seconds
  of work, saves what it finished, and the browser asks for the next one, so the page size no longer
  decides whether it works. The box counts down as it goes.
* A page is only saved once the whole translation is finished, so an interrupted run leaves nothing
  half-translated behind.

= 1.0.21 =
* Aumframe layout translation no longer needs the AumCreate theme. The code that tells Aumframe which
  languages a site has, which layout a translated page should use, and where its links should point,
  lived in that theme — so on any other theme a Japanese page was served with the source-language
  layout, silently and with no error. That code now ships with this plugin and works on any theme.
  Sites running the AumCreate theme behave exactly as before.

= 1.0.20 =
* Fixed: when a translation failed because the server never answered — a timeout, a 500, a gateway
  error — the box said only "Translation failed:" and nothing after it. It now says what happened:
  the HTTP status, the first line of a server error, or, for a timeout, that the page may be too long
  to translate in one request.
* Fixed: a failure that was not an ordinary translation error could end the request with no answer at
  all. Any failure is now reported with its reason, and a failure can no longer report an empty one.

= 1.0.19 =
* Fixed: the language switcher block's own panel — its name, its description and the Display options — stayed in English
  whatever language the admin was in. Translators had already translated those strings and translate.wordpress.org showed
  them as done; nothing was loading them in the browser.
* Fixed: 18 strings added over the last few releases had never been collected for translation, so volunteers could not see
  them at all. The translation template is rebuilt from the current code.

= 1.0.18 =
* The custom fields box now lists the meta keys this site actually uses, with how often each one appears. Carrying a theme's own setting — a "hide the title" checkbox, a page template — across to a translation no longer means guessing its internal name.
* A translation that finished but left the page looking unchanged now says so, and says why: the page is probably built with a page builder this plugin does not read yet.

= 1.0.17 =
* Fixed: translating a page built with a page builder we do not read yet could run for many minutes and never finish. The builder's own markup was being sent for translation, came back unchanged, and was retried and split again and again. Markup-only strings are now left alone, and a run stops with a clear message instead of hanging.

= 1.0.16 =
* Fixed: the menu item could disappear from the admin when the AumCreate theme moved its own page out of the top level. The parent menu is now asked for, not assumed.

= 1.0.15 =
* The links in the description are now real links. They were plain text, because wordpress.org does not turn a bare address into a link.

= 1.0.14 =
* Added a short section pointing to the plugin's own page on aumcreate.com and to the other free AumCreate plugins. No code changes.

= 1.0.13 =
* The link at the foot of the settings screen now goes to the plugin's own page on aumcreate.com instead of the site's front page.

= 1.0.12 =
* The plugin's own description in the Plugins list now matches the one on WordPress.org.
* Added a link from the Plugins list to the plugin's page on aumcreate.com.

= 1.0.11 =
* Tags and summary now name what people search for. No functional change to the plugin.
* A line at the foot of the settings screen linking to the rest of the AumCreate ecosystem.

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
