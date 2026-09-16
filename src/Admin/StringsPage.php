<?php
/**
 * Admin page for translating captured strings.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Language\LanguageRegistry;
use AumLang\Strings\PotImporter;
use AumLang\Strings\StringRepository;
use AumLang\Strings\StringTranslator;
use AumLang\Translation\BatchProcessor;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists front-end strings AumLang captured (per language) and translates them in
 * bulk via AI, with inline editing. The capture happens automatically as the
 * site is browsed; this page is where they get translated.
 */
class StringsPage {

	const SLUG     = 'aumlang-strings';
	const TAB      = 'strings';
	const PER_PAGE = 50;
	const BATCH    = 40;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private $strings;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Batch processor.
	 *
	 * @var BatchProcessor
	 */
	private $batch;

	/**
	 * Pot importer.
	 *
	 * @var PotImporter
	 */
	private $importer;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Language registry.
	 * @param StringRepository $strings   String repository.
	 * @param ProviderRegistry $providers Provider registry.
	 * @param BatchProcessor   $batch     Batch processor.
	 * @param PotImporter      $importer  Pot importer.
	 */
	public function __construct(
		LanguageRegistry $languages,
		StringRepository $strings,
		ProviderRegistry $providers,
		BatchProcessor $batch,
		PotImporter $importer
	) {
		$this->languages = $languages;
		$this->strings   = $strings;
		$this->providers = $providers;
		$this->batch     = $batch;
		$this->importer  = $importer;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_aumlang_strings_translate', array( $this, 'ajax_translate' ) );
		add_action( 'wp_ajax_aumlang_strings_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_aumlang_strings_delete', array( $this, 'ajax_delete' ) );
		add_action( 'admin_init', array( $this, 'handle_import' ) );
		add_action( 'admin_init', array( $this, 'handle_toggle_discovery' ) );
	}

	/**
	 * Non-default languages (the ones with strings to translate).
	 *
	 * @return \AumLang\Language\Language[]
	 */
	private function target_languages() {
		$default   = $this->languages->get_default_language();
		$languages = array();

		foreach ( $this->languages->get_languages( true ) as $language ) {
			if ( ! $default || $language->code() !== $default->code() ) {
				$languages[] = $language;
			}
		}

		return $languages;
	}

	/**
	 * The language currently selected in the UI.
	 *
	 * @return string
	 */
	private function current_lang() {
		$languages = $this->target_languages();

		if ( empty( $languages ) ) {
			return '';
		}

		$requested = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( $languages as $language ) {
			if ( $language->code() === $requested ) {
				return $requested;
			}
		}

		return $languages[0]->code();
	}

	/**
	 * Handle the "import from .pot" action.
	 *
	 * @return void
	 */
	public function handle_import() {
		if ( empty( $_POST['aumlang_import_nonce'] ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_import_nonce'] ) ), 'aumlang_import_strings' ) ) {
			return;
		}

		$result = $this->importer->import();

		wp_safe_redirect(
			AdminMenu::tab_url(
				self::TAB,
				array(
					'lang'     => $this->current_lang(),
					'imported' => (int) $result['strings'],
				)
			)
		);
		exit;
	}

	/**
	 * Handle the discovery-mode on/off toggle.
	 *
	 * @return void
	 */
	public function handle_toggle_discovery() {
		if ( empty( $_POST['aumlang_discovery_nonce'] ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_discovery_nonce'] ) ), 'aumlang_toggle_discovery' ) ) {
			return;
		}

		update_option( StringTranslator::DISCOVERY_OPTION, empty( $_POST['aumlang_discovery_target'] ) ? 0 : 1 );

		wp_safe_redirect( AdminMenu::tab_url( self::TAB, array( 'lang' => $this->current_lang() ) ) );
		exit;
	}

	/**
	 * Enqueue this tab's script + localized data. Called by {@see AdminMenu}
	 * once the shared AumLang screen is confirmed; the stylesheet is enqueued
	 * centrally there.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_script( 'aumlang-strings', AUMLANG_URL . 'src/Admin/assets/js/strings.js', array( 'jquery' ), AUMLANG_VERSION, true );
		wp_localize_script(
			'aumlang-strings',
			'AumLangStrings',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'aumlang_strings' ),
				'lang'         => $this->current_lang(),
				'translating'  => __( 'Translating…', 'aumlang' ),
				'done'         => __( 'Done.', 'aumlang' ),
				'saved'        => __( 'Saved', 'aumlang' ),
				'confirmDelete' => __( 'Delete the selected string(s)?', 'aumlang' ),
			)
		);
	}

	/**
	 * Render the Strings tab body (no outer wrap — {@see AdminMenu} provides it).
	 *
	 * @return void
	 */
	public function render_tab() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$languages = $this->target_languages();
		$lang      = $this->current_lang();
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="aml-tab-strings">
			<p class="aml-tab-intro"><?php esc_html_e( 'Translate theme & plugin interface text captured from your front end.', 'aumlang' ); ?></p>

			<?php $discovery = (bool) get_option( StringTranslator::DISCOVERY_OPTION, false ); ?>
			<div class="aml-card aml-discovery-card">
				<div>
					<h2 class="aml-card-h" style="margin:0"><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Discovery mode', 'aumlang' ); ?></h2>
					<p class="aml-card-desc" style="margin:6px 0 0">
						<?php
						if ( ! StringTranslator::capture_supported() ) {
							// Collecting visible strings means reading the rendered page,
							// and the only way to do that without this plugin holding an
							// output buffer of its own is the one WordPress 6.9 added.
							$discovery_note = __( 'Unavailable on this WordPress. Collecting visible strings needs WordPress 6.9 or newer. You can still import strings from a .pot file below, and language packs are installed automatically.', 'aumlang' );
						} else {
							$discovery_note = $discovery
								? __( 'On — browse your front end in a non-default language to collect the strings it actually displays, then turn it off.', 'aumlang' )
								: __( 'Off. Turn it on, browse your front end to collect visible strings, then turn it off.', 'aumlang' );
						}
						echo esc_html( $discovery_note );
						?>
					</p>
				</div>
				<form method="post">
					<?php wp_nonce_field( 'aumlang_toggle_discovery', 'aumlang_discovery_nonce' ); ?>
					<label class="aml-switch">
						<input type="checkbox" name="aumlang_discovery_target" value="1" <?php checked( $discovery ); ?> <?php disabled( ! StringTranslator::capture_supported() ); ?> onchange="this.form.submit()" />
						<span class="aml-track" aria-hidden="true"></span>
					</label>
				</form>
			</div>

			<?php if ( isset( $_GET['imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: number of strings imported. */
						esc_html__( 'Imported %d strings from .pot files.', 'aumlang' ),
						(int) $_GET['imported'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $languages ) ) : ?>
				<div class="aml-card"><p class="aml-card-desc" style="margin:0"><?php esc_html_e( 'Add a non-default language first.', 'aumlang' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$total        = $this->strings->count( $lang, $search );
			$untranslated = $this->strings->count_untranslated( $lang );
			?>
			<div class="aml-card">
				<div class="aml-strings-bar">
					<form method="get" class="aml-strings-filter">
						<input type="hidden" name="page" value="<?php echo esc_attr( SettingsPage::MENU_SLUG ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>" />
						<select name="lang" onchange="this.form.submit()">
							<?php foreach ( $languages as $language ) : ?>
								<option value="<?php echo esc_attr( $language->code() ); ?>" <?php selected( $language->code(), $lang ); ?>>
									<?php echo esc_html( $language->name() . ' (' . $language->code() . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search strings', 'aumlang' ); ?>" />
						<button type="submit" class="aml-btn"><?php esc_html_e( 'Search', 'aumlang' ); ?></button>
					</form>
					<div class="aml-strings-ops">
						<button type="button" class="aml-btn aml-btn-primary" id="aumlang-translate-all"
							data-untranslated="<?php echo esc_attr( $untranslated ); ?>">
							<?php
							printf(
								/* translators: %d: number of untranslated strings. */
								esc_html__( 'AI-translate all untranslated (%d)', 'aumlang' ),
								(int) $untranslated
							);
							?>
						</button>
						<span class="aumlang-progress" id="aumlang-progress"></span>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'aumlang_import_strings', 'aumlang_import_nonce' ); ?>
							<button type="submit" class="aml-btn"><?php esc_html_e( 'Import all from .pot', 'aumlang' ); ?></button>
						</form>
					</div>
				</div>

				<?php $this->render_table( $lang, $search, $paged, $total ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the strings table with pagination.
	 *
	 * @param string $lang   Language code.
	 * @param string $search Search term.
	 * @param int    $paged  Current page.
	 * @param int    $total  Total rows.
	 * @return void
	 */
	private function render_table( $lang, $search, $paged, $total ) {
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$rows   = $this->strings->get_page( $lang, $offset, self::PER_PAGE, $search );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		?>
		<p class="aumlang-strings-bulk">
			<button type="button" class="aml-btn" id="aumlang-delete-selected" disabled>
				<span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Delete selected', 'aumlang' ); ?>
			</button>
		</p>

		<table class="widefat striped aumlang-strings-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="aumlang-check-all" /></td>
					<th><?php esc_html_e( 'Source', 'aumlang' ); ?></th>
					<th><?php esc_html_e( 'Translation', 'aumlang' ); ?></th>
					<th class="aumlang-col-actions"><?php esc_html_e( 'Actions', 'aumlang' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No strings yet — turn on discovery mode and browse the front end in this language to capture them, or import from .pot.', 'aumlang' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr data-id="<?php echo esc_attr( $row['id'] ); ?>">
						<th scope="row" class="check-column"><input type="checkbox" class="aumlang-cb" value="<?php echo esc_attr( $row['id'] ); ?>" /></th>
						<td><code><?php echo esc_html( $row['string_context'] ); ?></code><br /><?php echo esc_html( $row['source_text'] ); ?></td>
						<td>
							<input type="text" class="large-text aumlang-string-input" value="<?php echo esc_attr( $row['translated_text'] ); ?>" />
							<span class="aumlang-saved-flag"></span>
						</td>
						<td class="aumlang-col-actions">
							<button type="button" class="button-link aumlang-delete" title="<?php esc_attr_e( 'Delete', 'aumlang' ); ?>">
								<span class="dashicons dashicons-trash"></span>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX: translate the next batch of untranslated strings.
	 *
	 * @return void
	 */
	public function ajax_translate() {
		check_ajax_referer( 'aumlang_strings', 'nonce' );

		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$lang     = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$provider = $this->providers->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'No translation provider is configured.', 'aumlang' ) ) );
		}

		$default     = $this->languages->get_default_language();
		$source_lang = $default ? $default->code() : '';
		$rows        = $this->strings->get_untranslated_batch( $lang, self::BATCH );

		if ( empty( $rows ) ) {
			wp_send_json_success( array( 'done' => 0, 'remaining' => 0 ) );
		}

		$texts = array();
		foreach ( $rows as $row ) {
			$texts[] = $row['source_text'];
		}

		try {
			$translated = $this->batch->translate( $provider, $texts, $source_lang, $lang );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		foreach ( $rows as $index => $row ) {
			$text = isset( $translated[ $index ] ) ? $translated[ $index ] : '';
			if ( '' !== $text ) {
				$this->strings->set_translation_by_id( (int) $row['id'], $text, 'machine' );
			}
		}

		wp_send_json_success(
			array(
				'done'      => count( $rows ),
				'remaining' => $this->strings->count_untranslated( $lang ),
			)
		);
	}

	/**
	 * AJAX: save one manually-edited string.
	 *
	 * @return void
	 */
	public function ajax_save() {
		check_ajax_referer( 'aumlang_strings', 'nonce' );

		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error();
		}

		$id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$text = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : '';

		if ( $id ) {
			$this->strings->set_translation_by_id( $id, $text, 'reviewed' );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: delete one or more captured strings.
	 *
	 * @return void
	 */
	public function ajax_delete() {
		check_ajax_referer( 'aumlang_strings', 'nonce' );

		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error();
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_values( array_filter( $ids ) );

		$deleted = empty( $ids ) ? 0 : $this->strings->delete_ids( $ids );

		wp_send_json_success( array( 'deleted' => $deleted ) );
	}
}
