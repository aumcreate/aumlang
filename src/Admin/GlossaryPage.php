<?php
/**
 * Admin page for managing glossary term mappings.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Language\LanguageRegistry;
use AumLang\Translation\Glossary\GlossaryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the user pin how specific terms are translated (e.g. "post" -> "文章",
 * or keep a brand name unchanged everywhere). These mappings are injected into
 * the AI prompt so translations stay consistent and on-brand.
 */
class GlossaryPage {

	const SLUG = 'aumlang-glossary';
	const TAB  = 'glossary';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Glossary repository.
	 *
	 * @var GlossaryRepository
	 */
	private $glossary;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry   $languages Language registry.
	 * @param GlossaryRepository $glossary  Glossary repository.
	 */
	public function __construct( LanguageRegistry $languages, GlossaryRepository $glossary ) {
		$this->languages = $languages;
		$this->glossary  = $glossary;
	}

	/**
	 * Register WordPress hooks. The menu + assets are owned by {@see AdminMenu}.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_add' ) );
		add_action( 'admin_init', array( $this, 'handle_delete' ) );
	}

	/**
	 * Non-default languages, for the language selector.
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
	 * Handle the add-term form.
	 *
	 * @return void
	 */
	public function handle_add() {
		if ( empty( $_POST['aumlang_glossary_nonce'] ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_glossary_nonce'] ) ), 'aumlang_glossary_add' ) ) {
			return;
		}

		$source = isset( $_POST['source_term'] ) ? sanitize_text_field( wp_unslash( $_POST['source_term'] ) ) : '';
		$target = isset( $_POST['target_term'] ) ? sanitize_text_field( wp_unslash( $_POST['target_term'] ) ) : '';
		$lang   = isset( $_POST['lang_code'] ) ? sanitize_text_field( wp_unslash( $_POST['lang_code'] ) ) : '';

		$extra = array();

		if ( '' === $source || '' === $target ) {
			$extra['error'] = 1;
		} else {
			$this->glossary->insert(
				array(
					'source_term'    => $source,
					'target_term'    => $target,
					'lang_code'      => '' === $lang ? null : $lang,
					'case_sensitive' => ! empty( $_POST['case_sensitive'] ),
				)
			);
			$extra['added'] = 1;
		}

		wp_safe_redirect( AdminMenu::tab_url( self::TAB, $extra ) );
		exit;
	}

	/**
	 * Handle a delete row action.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( empty( $_GET['aumlang_glossary_action'] ) || 'delete' !== $_GET['aumlang_glossary_action'] ) {
			return;
		}

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! $id || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'aumlang_glossary_delete_' . $id ) ) {
			return;
		}

		$this->glossary->delete( $id );

		wp_safe_redirect( AdminMenu::tab_url( self::TAB, array( 'deleted' => 1 ) ) );
		exit;
	}

	/**
	 * Render the Glossary tab body (no outer wrap — {@see AdminMenu} provides it).
	 *
	 * @return void
	 */
	public function render_tab() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="aml-tab-glossary">
			<p class="aml-tab-intro"><?php esc_html_e( 'Pin how key terms are translated, so AI output stays consistent and on-brand.', 'aumlang' ); ?></p>

			<?php if ( isset( $_GET['added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Term added.', 'aumlang' ); ?></p></div>
			<?php elseif ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Term removed.', 'aumlang' ); ?></p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please enter both a source term and a translation.', 'aumlang' ); ?></p></div>
			<?php endif; ?>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-plus-alt" aria-hidden="true"></span> <?php esc_html_e( 'Add a term', 'aumlang' ); ?></h2>
				<form method="post" class="aml-glossary-form">
					<?php wp_nonce_field( 'aumlang_glossary_add', 'aumlang_glossary_nonce' ); ?>
					<div class="aml-field">
						<label class="aml-label" for="aml-gloss-source"><?php esc_html_e( 'Source term', 'aumlang' ); ?></label>
						<input type="text" id="aml-gloss-source" name="source_term" required placeholder="<?php esc_attr_e( 'e.g. post', 'aumlang' ); ?>" />
					</div>
					<div class="aml-field">
						<label class="aml-label" for="aml-gloss-lang"><?php esc_html_e( 'Language', 'aumlang' ); ?></label>
						<select id="aml-gloss-lang" name="lang_code">
							<option value=""><?php esc_html_e( 'All languages', 'aumlang' ); ?></option>
							<?php foreach ( $this->target_languages() as $language ) : ?>
								<option value="<?php echo esc_attr( $language->code() ); ?>"><?php echo esc_html( $language->name() . ' (' . $language->code() . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="aml-field">
						<label class="aml-label" for="aml-gloss-target"><?php esc_html_e( 'Translation', 'aumlang' ); ?></label>
						<input type="text" id="aml-gloss-target" name="target_term" required placeholder="<?php esc_attr_e( 'e.g. 文章', 'aumlang' ); ?>" />
					</div>
					<label class="aml-switch-row aml-glossary-cs">
						<span class="aml-switch">
							<input type="checkbox" name="case_sensitive" value="1" />
							<span class="aml-track" aria-hidden="true"></span>
						</span>
						<span class="aml-switch-label"><?php esc_html_e( 'Case sensitive', 'aumlang' ); ?></span>
					</label>
					<button type="submit" class="aml-btn aml-btn-primary"><?php esc_html_e( 'Add term', 'aumlang' ); ?></button>
				</form>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e( 'Terms', 'aumlang' ); ?></h2>
				<?php $this->render_list(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the list of glossary entries.
	 *
	 * @return void
	 */
	private function render_list() {
		$rows = $this->glossary->all();

		if ( empty( $rows ) ) {
			echo '<p class="aml-card-desc" style="margin:0">' . esc_html__( 'No terms yet. Add one above to lock its translation.', 'aumlang' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped aumlang-strings-table aumlang-glossary-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source term', 'aumlang' ); ?></th>
					<th><?php esc_html_e( 'Language', 'aumlang' ); ?></th>
					<th><?php esc_html_e( 'Translation', 'aumlang' ); ?></th>
					<th><?php esc_html_e( 'Case', 'aumlang' ); ?></th>
					<th class="aumlang-col-actions"><?php esc_html_e( 'Actions', 'aumlang' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['source_term'] ); ?></strong></td>
						<td>
							<?php
							echo '' === (string) $row['lang_code'] || null === $row['lang_code']
								? esc_html__( 'All languages', 'aumlang' )
								: '<code>' . esc_html( $row['lang_code'] ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
						<td><?php echo esc_html( $row['target_term'] ); ?></td>
						<td><?php echo ! empty( $row['case_sensitive'] ) ? esc_html__( 'Yes', 'aumlang' ) : '—'; ?></td>
						<td class="aumlang-col-actions">
							<a class="aml-link aml-link-danger" href="<?php echo esc_url( $this->delete_url( (int) $row['id'] ) ); ?>" aria-label="<?php esc_attr_e( 'Delete term', 'aumlang' ); ?>">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Build a nonced delete URL for a row.
	 *
	 * @param int $id Row id.
	 * @return string
	 */
	private function delete_url( $id ) {
		$url = AdminMenu::tab_url(
			self::TAB,
			array(
				'aumlang_glossary_action' => 'delete',
				'id'                      => $id,
			)
		);

		return wp_nonce_url( $url, 'aumlang_glossary_delete_' . $id );
	}
}
