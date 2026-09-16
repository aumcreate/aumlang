<?php
/**
 * Registers the admin menu, assets, and notices, and renders the unified
 * tabbed settings screen.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the AumLang admin screen into WordPress.
 *
 * All AumLang admin features live on a single page (slug {@see SettingsPage::MENU_SLUG})
 * split into tabs via a `?tab=` query arg. Each tab keeps its own independent
 * form, nonce, and save handler, so saving one tab never resets another. This
 * mirrors how the AumCreate theme and AUM SEO settings pages are built.
 *
 * When the AumCreate theme is active the page is hooked as a submenu under the
 * theme's own menu; otherwise it is a standalone top-level menu.
 */
class AdminMenu {

	/**
	 * Settings tab (default).
	 *
	 * @var SettingsPage
	 */
	private $settings;

	/**
	 * Switcher tab.
	 *
	 * @var SwitcherPage
	 */
	private $switcher;

	/**
	 * Strings tab.
	 *
	 * @var StringsPage
	 */
	private $strings;

	/**
	 * Glossary tab.
	 *
	 * @var GlossaryPage
	 */
	private $glossary;

	/**
	 * Review tab.
	 *
	 * @var ReviewPage
	 */
	private $review;


	/**
	 * Menu page hook suffix, for targeted asset loading.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Constructor.
	 *
	 * @param SettingsPage $settings Settings tab.
	 * @param SwitcherPage $switcher Switcher tab.
	 * @param StringsPage  $strings  Strings tab.
	 * @param GlossaryPage $glossary Glossary tab.
	 * @param ReviewPage   $review   Review tab.
	 */
	public function __construct(
		SettingsPage $settings,
		SwitcherPage $switcher,
		StringsPage $strings,
		GlossaryPage $glossary,
		ReviewPage $review
	) {
		$this->settings = $settings;
		$this->switcher = $switcher;
		$this->strings  = $strings;
		$this->glossary = $glossary;
		$this->review   = $review;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this->settings, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'permalink_notice' ) );
		add_action( 'wp_ajax_aumlang_test_provider', array( $this->settings, 'ajax_test_provider' ) );
		add_action( 'wp_ajax_aumlang_test_reachability', array( $this->settings, 'ajax_test_reachability' ) );
	}

	/**
	 * Whether the AumCreate theme (parent or child) is active.
	 *
	 * @return bool
	 */
	private function is_aumcreate_theme() {
		$theme = wp_get_theme();

		return 'aumcreate' === $theme->get_template()
			|| false !== stripos( (string) $theme->get( 'Name' ), 'aumcreate' );
	}

	/**
	 * Add the menu page — under the AumCreate theme menu when that theme is
	 * active, otherwise as a standalone top-level menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		if ( $this->is_aumcreate_theme() ) {
			$this->hook = add_submenu_page(
				'aumcreate-settings',
				__( 'AumLang', 'aumlang' ),
				__( 'Translation', 'aumlang' ),
				SettingsPage::CAPABILITY,
				SettingsPage::MENU_SLUG,
				array( $this, 'render' )
			);

			return;
		}

		$this->hook = add_menu_page(
			__( 'AumLang', 'aumlang' ),
			__( 'AumLang', 'aumlang' ),
			SettingsPage::CAPABILITY,
			SettingsPage::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-translation',
			58
		);
	}

	/**
	 * The tabs, in display order: key => [ label, dashicon ].
	 *
	 * @return array<string, array{label:string,icon:string}>
	 */
	private function tabs() {
		return array(
			'settings' => array( 'label' => __( 'Settings', 'aumlang' ), 'icon' => 'dashicons-admin-settings' ),
			'switcher' => array( 'label' => __( 'Switcher', 'aumlang' ), 'icon' => 'dashicons-translation' ),
			'strings'  => array( 'label' => __( 'Strings', 'aumlang' ), 'icon' => 'dashicons-editor-spellcheck' ),
			'glossary' => array( 'label' => __( 'Glossary', 'aumlang' ), 'icon' => 'dashicons-book-alt' ),
			'review'   => array( 'label' => __( 'Review', 'aumlang' ), 'icon' => 'dashicons-yes-alt' ),
		);
	}

	/**
	 * The currently selected tab, validated against {@see tabs()}.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		return isset( $tabs[ $tab ] ) ? $tab : 'settings';
	}

	/**
	 * Build a URL to a tab on this page.
	 *
	 * @param string               $tab   Tab key.
	 * @param array<string, mixed> $extra Extra query args.
	 * @return string
	 */
	public static function tab_url( $tab, array $extra = array() ) {
		$args = array( 'page' => SettingsPage::MENU_SLUG );

		if ( '' !== $tab && 'settings' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the unified tabbed screen: shared shell + active tab body.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab();
		?>
		<div class="wrap aumlang-app aml-app">
			<div class="aml-header">
				<span class="aml-logo" aria-hidden="true"><span class="dashicons dashicons-translation"></span></span>
				<div class="aml-header-text">
					<h1 class="aml-title">
						<?php esc_html_e( 'AumLang', 'aumlang' ); ?>
						<span class="aml-badge">v<?php echo esc_html( AUMLANG_VERSION ); ?></span>
					</h1>
					<p class="aml-subtitle"><?php esc_html_e( 'Builder-friendly, SEO-complete multilingual translation.', 'aumlang' ); ?></p>
				</div>
			</div>
			<hr class="wp-header-end">

			<nav class="aml-tabs" aria-label="<?php esc_attr_e( 'AumLang sections', 'aumlang' ); ?>">
				<?php
				foreach ( $tabs as $key => $tab ) :
					?>
					<a class="aml-tab<?php echo $key === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( $key ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="aml-tab-body">
				<?php
				switch ( $current ) {
					case 'switcher':
						$this->switcher->render_tab();
						break;
					case 'strings':
						$this->strings->render_tab();
						break;
					case 'glossary':
						$this->glossary->render_tab();
						break;
					case 'review':
						$this->review->render_tab();
						break;
					default:
						$this->settings->render_tab();
				}
				?>
			</div>
			<?php $this->render_ecosystem_note(); ?>
		</div>
		<?php
	}

	/**
	 * One line at the foot of the settings screen pointing at the rest of the
	 * AumCreate ecosystem. Plain text with a link, no tracking beyond the UTM
	 * tags in the URL itself.
	 */
	private function render_ecosystem_note() {
		$url = 'https://aumcreate.com/?utm_source=plugin&utm_medium=aumlang&utm_campaign=settings';
		echo '<p class="aum-ecosystem-note" style="margin:24px 0 0;color:#646970;font-size:12px">';
		printf(
			/* translators: %s: link to aumcreate.com */
			esc_html__( 'Part of the AumCreate ecosystem — themes and templates built around it. %s', 'aumlang' ),
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">aumcreate.com</a>'
		);
		echo '</p>';
	}

	/**
	 * Enqueue admin styles + scripts only on the AumLang screen. All tabs share
	 * one hook, so every tab's assets are loaded here.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}

		$css = AUMLANG_DIR . 'src/Admin/assets/css/admin.css';
		$js  = AUMLANG_DIR . 'src/Admin/assets/js/settings.js';

		wp_enqueue_style(
			'aumlang-admin',
			AUMLANG_URL . 'src/Admin/assets/css/admin.css',
			array(),
			file_exists( $css ) ? filemtime( $css ) : AUMLANG_VERSION
		);

		wp_enqueue_script(
			'aumlang-settings',
			AUMLANG_URL . 'src/Admin/assets/js/settings.js',
			array( 'jquery' ),
			file_exists( $js ) ? filemtime( $js ) : AUMLANG_VERSION,
			true
		);

		wp_localize_script(
			'aumlang-settings',
			'AumLangSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aumlang_test_provider' ),
				'testing' => __( 'Testing…', 'aumlang' ),
				'failed'  => __( 'Request failed.', 'aumlang' ),
			)
		);

		$review_js = AUMLANG_DIR . 'src/Admin/assets/js/review.js';

		wp_enqueue_script(
			'aumlang-review',
			AUMLANG_URL . 'src/Admin/assets/js/review.js',
			array(),
			file_exists( $review_js ) ? filemtime( $review_js ) : AUMLANG_VERSION,
			true
		);

		// The Strings tab brings its own script + localized data.
		$this->strings->enqueue_assets();
	}

	/**
	 * Warn when pretty permalinks are disabled (required for language routing).
	 *
	 * @return void
	 */
	public function permalink_notice() {
		if ( get_option( 'permalink_structure' ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || false === strpos( (string) $screen->id, SettingsPage::MENU_SLUG ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'AumLang needs pretty permalinks for language URLs like /zh/ to work. Plain permalinks are currently active.', 'aumlang' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Change permalink settings', 'aumlang' )
		);
	}
}
