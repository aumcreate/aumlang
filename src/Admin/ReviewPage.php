<?php
/**
 * Admin page: review translations and mark them as approved.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Content\TranslationRepository;
use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every post translation with its status (machine / reviewed / stale),
 * filterable by language and status, so the user can proofread and mark
 * translations as reviewed in bulk.
 */
class ReviewPage {

	const SLUG     = 'aumlang-review';
	const TAB      = 'review';
	const PER_PAGE = 30;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepository
	 */
	private $translations;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry      $languages    Language registry.
	 * @param TranslationRepository $translations Translation repository.
	 */
	public function __construct( LanguageRegistry $languages, TranslationRepository $translations ) {
		$this->languages    = $languages;
		$this->translations = $translations;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_bulk' ) );
	}

	/**
	 * Handle the "mark selected as reviewed" bulk action.
	 *
	 * @return void
	 */
	public function handle_bulk() {
		if ( empty( $_POST['aumlang_review_nonce'] ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_review_nonce'] ) ), 'aumlang_review_bulk' ) ) {
			return;
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_values( array_filter( $ids ) );

		if ( ! empty( $ids ) ) {
			$this->translations->mark_reviewed( $ids );
		}

		wp_safe_redirect(
			AdminMenu::tab_url(
				self::TAB,
				array(
					'lang'     => isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '',
					'status'   => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
					'paged'    => isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1,
					'reviewed' => count( $ids ),
				)
			)
		);
		exit;
	}

	/**
	 * Render the Review tab body (no outer wrap — {@see AdminMenu} provides it).
	 *
	 * @return void
	 */
	public function render_tab() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lang   = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total  = $this->translations->count_filtered( $lang, $status );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$rows   = $this->translations->get_filtered( $lang, $status, $offset, self::PER_PAGE );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		?>
		<div class="aml-tab-review">
			<p class="aml-tab-intro"><?php esc_html_e( 'Proofread machine translations and mark them as reviewed.', 'aumlang' ); ?></p>

			<?php if ( isset( $_GET['reviewed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: number of translations marked reviewed. */
						esc_html__( 'Marked %d translation(s) as reviewed.', 'aumlang' ),
						(int) $_GET['reviewed'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="aml-card">
				<form method="get" class="aml-strings-bar">
					<input type="hidden" name="page" value="<?php echo esc_attr( SettingsPage::MENU_SLUG ); ?>" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>" />
					<div class="aml-strings-filter">
						<select name="lang" onchange="this.form.submit()">
							<option value=""><?php esc_html_e( 'All languages', 'aumlang' ); ?></option>
							<?php foreach ( $this->languages->get_languages( true ) as $language ) : ?>
								<option value="<?php echo esc_attr( $language->code() ); ?>" <?php selected( $language->code(), $lang ); ?>><?php echo esc_html( $language->name() ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="status" onchange="this.form.submit()">
							<?php
							$statuses = array(
								''         => __( 'All statuses', 'aumlang' ),
								'machine'  => __( 'Machine', 'aumlang' ),
								'reviewed' => __( 'Reviewed', 'aumlang' ),
								'stale'    => __( 'Outdated', 'aumlang' ),
							);
							foreach ( $statuses as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $status ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</form>

				<form method="post">
					<?php wp_nonce_field( 'aumlang_review_bulk', 'aumlang_review_nonce' ); ?>
					<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
					<input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>" />

					<p class="aumlang-strings-bulk">
						<button type="submit" class="aml-btn"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Mark selected as reviewed', 'aumlang' ); ?></button>
					</p>

					<table class="widefat striped aumlang-strings-table">
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" id="aumlang-review-all" /></td>
								<th><?php esc_html_e( 'Original', 'aumlang' ); ?></th>
								<th><?php esc_html_e( 'Translation', 'aumlang' ); ?></th>
								<th><?php esc_html_e( 'Language', 'aumlang' ); ?></th>
								<th><?php esc_html_e( 'Status', 'aumlang' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No translations match this filter.', 'aumlang' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" class="aumlang-review-cb" name="ids[]" value="<?php echo esc_attr( $row['id'] ); ?>" /></th>
									<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['source_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $row['source_id'] ) ); ?></a></td>
									<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['target_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $row['target_id'] ) ); ?></a></td>
									<td><code><?php echo esc_html( strtoupper( $row['lang_code'] ) ); ?></code></td>
									<td><span class="aumlang-chip aumlang-chip-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $this->status_label( $row['status'] ) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $paged,
									'total'   => $pages,
								)
							)
						);
						?>
					</div></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
		switch ( $status ) {
			case 'reviewed':
				return __( 'Reviewed', 'aumlang' );
			case 'stale':
				return __( 'Outdated', 'aumlang' );
			case 'machine':
				return __( 'Machine', 'aumlang' );
			default:
				return __( 'Not translated', 'aumlang' );
		}
	}
}
