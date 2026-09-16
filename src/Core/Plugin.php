<?php
/**
 * Main plugin class: holds the container, wires services, boots hooks.
 *
 * @package AumLang
 */

namespace AumLang\Core;

defined( 'ABSPATH' ) || exit;

use AumLang\Admin\AdminColumns;
use AumLang\Admin\AdminMenu;
use AumLang\Admin\MetaBox;
use AumLang\Admin\ReviewPage;
use AumLang\Admin\SettingsPage;
use AumLang\Admin\GlossaryPage;
use AumLang\Admin\StringsPage;
use AumLang\Admin\SwitcherPage;
use AumLang\Admin\TermAdminColumns;
use AumLang\Builders\ClassicParser;
use AumLang\Builders\ElementorParser;
use AumLang\Builders\GutenbergParser;
use AumLang\Builders\HtmlTextExtractor;
use AumLang\Builders\ParserRegistry;
use AumLang\Content\ContentLinker;
use AumLang\Content\NodeTranslationRepository;
use AumLang\Content\StalenessTracker;
use AumLang\Content\TranslatableTypes;
use AumLang\Content\TranslationRepository;
use AumLang\Integrations\AcfFields;
use AumLang\Integrations\CustomFields;
use AumLang\Integrations\WooCommerce;
use AumLang\Language\LanguagePackInstaller;
use AumLang\Language\LanguageRegistry;
use AumLang\Language\LanguageRepository;
use AumLang\Routing\QueryFilter;
use AumLang\Routing\RewriteRules;
use AumLang\Seo\CanonicalManager;
use AumLang\Seo\HreflangManager;
use AumLang\Seo\MetaTranslator;
use AumLang\Seo\SeoPluginBridge;
use AumLang\Seo\SitemapManager;
use AumLang\Strings\PotImporter;
use AumLang\Strings\StringRepository;
use AumLang\Strings\StringTranslator;
use AumLang\Sync\OverrideStore;
use AumLang\Sync\SyncEngine;
use AumLang\Taxonomy\TermLinker;
use AumLang\Taxonomy\TermLinks;
use AumLang\Taxonomy\TermQueryFilter;
use AumLang\Taxonomy\TermTranslationRepository;
use AumLang\Taxonomy\TermTranslator;
use AumLang\Routing\LanguageSwitcher;
use AumLang\Routing\MenuTranslator;
use AumLang\Routing\Router;
use AumLang\Routing\TranslationLinks;
use AumLang\Routing\UrlConverter;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Glossary\GlossaryRepository;
use AumLang\Translation\PromptBuilder;
use AumLang\Translation\Provider\DeepSeekProvider;
use AumLang\Translation\Provider\CoreAiProvider;
use AumLang\Translation\Provider\ProviderRegistry;
use AumLang\Translation\TranslationOrchestrator;

/**
 * Singleton entry point for the plugin runtime.
 */
class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether run() has already executed.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Build the container and register core services.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register service factories on the container.
	 *
	 * @return void
	 */
	private function register_services() {
		$this->container->set(
			'language_repository',
			static function () {
				global $wpdb;
				return new LanguageRepository( $wpdb );
			}
		);

		$this->container->set(
			'language_registry',
			static function ( Container $c ) {
				return new LanguageRegistry( $c->get( 'language_repository' ) );
			}
		);

		$this->container->set(
			'router',
			static function ( Container $c ) {
				return new Router( $c->get( 'language_registry' ) );
			}
		);

		$this->container->set(
			'rewrite_rules',
			static function ( Container $c ) {
				return new RewriteRules( $c->get( 'router' ) );
			}
		);

		$this->container->set(
			'url_converter',
			static function ( Container $c ) {
				return new UrlConverter( $c->get( 'router' ), $c->get( 'language_registry' ) );
			}
		);

		$this->container->set(
			'translation_links',
			static function ( Container $c ) {
				return new TranslationLinks( $c->get( 'url_converter' ) );
			}
		);

		$this->container->set(
			'language_switcher',
			static function ( Container $c ) {
				return new LanguageSwitcher(
					$c->get( 'router' ),
					$c->get( 'language_registry' ),
					$c->get( 'content_linker' ),
					$c->get( 'url_converter' )
				);
			}
		);

		$this->container->set(
			'menu_translator',
			static function ( Container $c ) {
				return new MenuTranslator(
					$c->get( 'router' ),
					$c->get( 'content_linker' ),
					$c->get( 'term_linker' ),
					$c->get( 'url_converter' )
				);
			}
		);

		$this->container->set(
			'translation_repository',
			static function () {
				global $wpdb;
				return new TranslationRepository( $wpdb );
			}
		);

		$this->container->set(
			'content_linker',
			static function ( Container $c ) {
				return new ContentLinker( $c->get( 'translation_repository' ), $c->get( 'language_registry' ) );
			}
		);

		$this->container->set(
			'node_translation_repository',
			static function () {
				global $wpdb;
				return new NodeTranslationRepository( $wpdb );
			}
		);

		$this->container->set(
			'staleness_tracker',
			static function ( Container $c ) {
				return new StalenessTracker( $c->get( 'translation_repository' ) );
			}
		);

		$this->container->set(
			'language_pack_installer',
			static function () {
				return new LanguagePackInstaller();
			}
		);

		$this->container->set(
			'translatable_types',
			static function () {
				return new TranslatableTypes();
			}
		);

		$this->container->set(
			'settings_page',
			static function ( Container $c ) {
				return new SettingsPage(
					$c->get( 'language_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'language_pack_installer' ),
					$c->get( 'translatable_types' )
				);
			}
		);



		$this->container->set(
			'admin_menu',
			static function ( Container $c ) {
				return new AdminMenu(
					$c->get( 'settings_page' ),
					$c->get( 'switcher_page' ),
					$c->get( 'strings_page' ),
					$c->get( 'glossary_page' ),
					$c->get( 'review_page' )
				);
			}
		);

		$this->container->set(
			'meta_box',
			static function ( Container $c ) {
				return new MetaBox(
					$c->get( 'language_registry' ),
					$c->get( 'content_linker' ),
					$c->get( 'translation_orchestrator' ),
					$c->get( 'provider_registry' )
				);
			}
		);

		$this->container->set(
			'admin_columns',
			static function ( Container $c ) {
				return new AdminColumns( $c->get( 'language_registry' ), $c->get( 'content_linker' ) );
			}
		);

		$this->container->set(
			'query_filter',
			static function ( Container $c ) {
				return new QueryFilter( $c->get( 'router' ), $c->get( 'content_linker' ) );
			}
		);

		$this->container->set(
			'provider_registry',
			static function () {
				$settings = get_option( 'aumlang_settings', array() );
				$configs  = isset( $settings['providers'] ) ? (array) $settings['providers'] : array();

				$registry = new ProviderRegistry();
				$registry->register( new CoreAiProvider() );
				$registry->register(
					new DeepSeekProvider( isset( $configs['deepseek'] ) ? (array) $configs['deepseek'] : array() )
				);

				// New installs prefer the client WordPress ships; anything
				// already configured keeps the provider it was set to.
				$registry->set_active(
					! empty( $settings['active_provider'] ) ? $settings['active_provider'] : ( CoreAiProvider::is_available() ? 'core' : 'deepseek' )
				);

				return $registry;
			}
		);

		$this->container->set(
			'parser_registry',
			static function () {
				$registry = new ParserRegistry();
				$html     = new HtmlTextExtractor();
				// Builder parsers run first; classic is the universal fallback.
				$registry->register( new ElementorParser( $html ), 10 );
				$registry->register( new GutenbergParser( $html ), 20 );
				$registry->register( new ClassicParser(), 100 );

				/**
				 * Fires so other plugins can add a content parser.
				 *
				 * A builder that keeps its content somewhere other than `post_content` is invisible to
				 * every parser above: Gutenberg and the classic fallback both read `post_content`, and the
				 * Elementor parser reads the one meta key Elementor uses. A page builder storing its
				 * layout in its own table or its own meta therefore translates as an empty page, with no
				 * error anywhere — the reason `ElementorParser` exists at all is that Elementor is exactly
				 * this case, and it will not be the last one.
				 *
				 * Register with a priority **below 100** to be considered before the classic fallback,
				 * which supports any post with non-empty `post_content` and so would otherwise always win.
				 *
				 * A parser must not assume it is the only listener, and `supports()` should return false
				 * fast for posts it does not own.
				 *
				 *     add_action( 'aumlang_register_parsers', function ( $registry, $html ) {
				 *         $registry->register( new My_Parser( $html ), 30 );
				 *     }, 10, 2 );
				 *
				 * @since 1.0.1
				 *
				 * @param ParserRegistry     $registry Registry to add parsers to.
				 * @param HtmlTextExtractor  $html     Shared extractor, so a parser handling HTML
				 *                                     fragments does not reimplement the text walk.
				 */
				do_action( 'aumlang_register_parsers', $registry, $html );

				return $registry;
			}
		);

		$this->container->set(
			'batch_processor',
			static function () {
				return new BatchProcessor();
			}
		);

		$this->container->set(
			'translation_orchestrator',
			static function ( Container $c ) {
				return new TranslationOrchestrator(
					$c->get( 'parser_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'content_linker' ),
					$c->get( 'language_registry' ),
					$c->get( 'batch_processor' ),
					$c->get( 'node_translation_repository' )
				);
			}
		);

		$this->container->set(
			'override_store',
			static function ( Container $c ) {
				return new OverrideStore( $c->get( 'node_translation_repository' ), $c->get( 'content_linker' ) );
			}
		);

		$this->container->set(
			'sync_engine',
			static function ( Container $c ) {
				return new SyncEngine(
					$c->get( 'translation_orchestrator' ),
					$c->get( 'override_store' ),
					$c->get( 'content_linker' ),
					$c->get( 'language_registry' )
				);
			}
		);

		$this->container->set(
			'hreflang_manager',
			static function ( Container $c ) {
				return new HreflangManager(
					$c->get( 'router' ),
					$c->get( 'content_linker' ),
					$c->get( 'url_converter' ),
					$c->get( 'language_registry' )
				);
			}
		);

		$this->container->set(
			'canonical_manager',
			static function ( Container $c ) {
				return new CanonicalManager(
					$c->get( 'router' ),
					$c->get( 'content_linker' ),
					$c->get( 'url_converter' )
				);
			}
		);

		$this->container->set(
			'sitemap_manager',
			static function ( Container $c ) {
				return new SitemapManager( $c->get( 'language_registry' ), $c->get( 'url_converter' ) );
			}
		);

		$this->container->set(
			'string_repository',
			static function () {
				global $wpdb;
				return new StringRepository( $wpdb );
			}
		);

		$this->container->set(
			'string_translator',
			static function ( Container $c ) {
				return new StringTranslator( $c->get( 'router' ), $c->get( 'string_repository' ) );
			}
		);

		$this->container->set(
			'pot_importer',
			static function ( Container $c ) {
				return new PotImporter( $c->get( 'string_repository' ), $c->get( 'language_registry' ) );
			}
		);

		$this->container->set(
			'term_translation_repository',
			static function () {
				global $wpdb;
				return new TermTranslationRepository( $wpdb );
			}
		);

		$this->container->set(
			'term_linker',
			static function ( Container $c ) {
				return new TermLinker( $c->get( 'term_translation_repository' ), $c->get( 'language_registry' ) );
			}
		);

		$this->container->set(
			'term_translator',
			static function ( Container $c ) {
				return new TermTranslator(
					$c->get( 'term_linker' ),
					$c->get( 'language_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'batch_processor' )
				);
			}
		);

		$this->container->set(
			'term_query_filter',
			static function ( Container $c ) {
				return new TermQueryFilter( $c->get( 'router' ), $c->get( 'term_linker' ) );
			}
		);

		$this->container->set(
			'term_links',
			static function ( Container $c ) {
				return new TermLinks( $c->get( 'url_converter' ) );
			}
		);

		$this->container->set(
			'glossary_repository',
			static function () {
				global $wpdb;
				return new GlossaryRepository( $wpdb );
			}
		);

		$this->container->set(
			'glossary_page',
			static function ( Container $c ) {
				return new GlossaryPage( $c->get( 'language_registry' ), $c->get( 'glossary_repository' ) );
			}
		);

		$this->container->set(
			'review_page',
			static function ( Container $c ) {
				return new ReviewPage( $c->get( 'language_registry' ), $c->get( 'translation_repository' ) );
			}
		);

		$this->container->set(
			'switcher_page',
			static function () {
				return new SwitcherPage();
			}
		);

		$this->container->set(
			'prompt_builder',
			static function ( Container $c ) {
				return new PromptBuilder( $c->get( 'glossary_repository' ) );
			}
		);

		$this->container->set(
			'woocommerce',
			static function ( Container $c ) {
				return new WooCommerce(
					$c->get( 'language_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'batch_processor' )
				);
			}
		);

		$this->container->set(
			'acf',
			static function ( Container $c ) {
				return new AcfFields(
					$c->get( 'language_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'batch_processor' )
				);
			}
		);

		$this->container->set(
			'custom_fields',
			static function ( Container $c ) {
				return new CustomFields(
					$c->get( 'language_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'batch_processor' )
				);
			}
		);

		$this->container->set(
			'seo_plugin_bridge',
			static function () {
				return new SeoPluginBridge();
			}
		);

		$this->container->set(
			'meta_translator',
			static function ( Container $c ) {
				return new MetaTranslator(
					$c->get( 'seo_plugin_bridge' ),
					$c->get( 'language_registry' ),
					$c->get( 'provider_registry' ),
					$c->get( 'batch_processor' )
				);
			}
		);

		$this->container->set(
			'term_admin_columns',
			static function ( Container $c ) {
				return new TermAdminColumns(
					$c->get( 'language_registry' ),
					$c->get( 'term_linker' ),
					$c->get( 'term_translator' )
				);
			}
		);

		$this->container->set(
			'strings_page',
			static function ( Container $c ) {
				return new StringsPage(
					$c->get( 'language_registry' ),
					$c->get( 'string_repository' ),
					$c->get( 'provider_registry' ),
					$c->get( 'batch_processor' ),
					$c->get( 'pot_importer' )
				);
			}
		);
	}

	/**
	 * Boot the plugin: register WordPress hooks. Safe to call once.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Apply any pending schema upgrade (e.g. tables added in a new version).
		Activator::maybe_upgrade();

		// Routing hooks must be registered before WP parses the request.
		$this->container->get( 'router' )->register();
		$this->container->get( 'rewrite_rules' )->register();
		$this->container->get( 'translation_links' )->register();
		$this->container->get( 'language_switcher' )->register();
		$this->container->get( 'menu_translator' )->register();
		$this->container->get( 'content_linker' )->register();
		$this->container->get( 'term_linker' )->register();
		$this->container->get( 'term_translator' )->register();
		$this->container->get( 'term_query_filter' )->register();
		$this->container->get( 'term_links' )->register();
		$this->container->get( 'staleness_tracker' )->register();
		$this->container->get( 'query_filter' )->register();
		$this->container->get( 'hreflang_manager' )->register();
		$this->container->get( 'canonical_manager' )->register();
		$this->container->get( 'sitemap_manager' )->register();
		$this->container->get( 'string_translator' )->register();
		$this->container->get( 'translatable_types' )->register();
		$this->container->get( 'prompt_builder' )->register();
		$this->container->get( 'woocommerce' )->register();
		$this->container->get( 'acf' )->register();
		$this->container->get( 'custom_fields' )->register();
		$this->container->get( 'meta_translator' )->register();

		if ( is_admin() ) {
			$this->container->get( 'admin_menu' )->register();
			$this->container->get( 'meta_box' )->register();
			$this->container->get( 'admin_columns' )->register();
			$this->container->get( 'strings_page' )->register();
			$this->container->get( 'glossary_page' )->register();
			$this->container->get( 'review_page' )->register();
			$this->container->get( 'switcher_page' )->register();
			$this->container->get( 'term_admin_columns' )->register();
		}

		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );

		/**
		 * Fires after AumLang has booted and registered its core hooks.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'aumlang_loaded', $this );
	}

	/**
	 * Flush rewrite rules once after activation, when flagged.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite() {
		if ( get_option( 'aumlang_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'aumlang_flush_rewrite' );
		}
	}

	/**
	 * Access the service container.
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Convenience accessor for the language registry.
	 *
	 * @return LanguageRegistry
	 */
	public function languages() {
		return $this->container->get( 'language_registry' );
	}

	/**
	 * Convenience accessor for the router.
	 *
	 * @return Router
	 */
	public function router() {
		return $this->container->get( 'router' );
	}

	/**
	 * Convenience accessor for the URL converter.
	 *
	 * @return UrlConverter
	 */
	public function urls() {
		return $this->container->get( 'url_converter' );
	}

	/**
	 * Convenience accessor for the content linker.
	 *
	 * @return ContentLinker
	 */
	public function content() {
		return $this->container->get( 'content_linker' );
	}

	/**
	 * Convenience accessor for the language switcher.
	 *
	 * @return LanguageSwitcher
	 */
	public function switcher() {
		return $this->container->get( 'language_switcher' );
	}

	/**
	 * Convenience accessor for the term linker.
	 *
	 * @return TermLinker
	 */
	public function terms() {
		return $this->container->get( 'term_linker' );
	}

	/**
	 * Convenience accessor for the provider registry.
	 *
	 * @return ProviderRegistry
	 */
	public function providers() {
		return $this->container->get( 'provider_registry' );
	}

	/**
	 * Convenience accessor for the parser registry.
	 *
	 * @return ParserRegistry
	 */
	public function parsers() {
		return $this->container->get( 'parser_registry' );
	}

	/**
	 * Convenience accessor for the translation orchestrator.
	 *
	 * @return TranslationOrchestrator
	 */
	public function translator() {
		return $this->container->get( 'translation_orchestrator' );
	}

	/**
	 * Convenience accessor for the staleness tracker.
	 *
	 * @return StalenessTracker
	 */
	public function staleness() {
		return $this->container->get( 'staleness_tracker' );
	}

	/**
	 * Convenience accessor for the sync engine.
	 *
	 * @return SyncEngine
	 */
	public function sync() {
		return $this->container->get( 'sync_engine' );
	}

	/**
	 * Convenience accessor for the string repository.
	 *
	 * @return StringRepository
	 */
	public function strings() {
		return $this->container->get( 'string_repository' );
	}
}
