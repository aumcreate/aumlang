<?php
/**
 * Translation meta box on the post/page editor.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Content\ContentLinker;
use AumLang\Language\LanguageRegistry;
use AumLang\Translation\Provider\ProviderRegistry;
use AumLang\Translation\TranslationOrchestrator;

defined( 'ABSPATH' ) || exit;

/**
 * Shows each language's translation status with one-click translate buttons,
 * and handles the AJAX translate request.
 */
class MetaBox {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Content linker.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Translation orchestrator.
	 *
	 * @var TranslationOrchestrator
	 */
	private $orchestrator;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry        $languages    Language registry.
	 * @param ContentLinker           $linker       Content linker.
	 * @param TranslationOrchestrator $orchestrator Orchestrator.
	 * @param ProviderRegistry        $providers    Provider registry.
	 */
	public function __construct(
		LanguageRegistry $languages,
		ContentLinker $linker,
		TranslationOrchestrator $orchestrator,
		ProviderRegistry $providers
	) {
		$this->languages    = $languages;
		$this->linker       = $linker;
		$this->orchestrator = $orchestrator;
		$this->providers    = $providers;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_aumlang_translate', array( $this, 'ajax_translate' ) );
	}

	/**
	 * Post types that get the translation meta box.
	 *
	 * @return string[]
	 */
	private function post_types() {
		/**
		 * Filter the post types AumLang can translate.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return (array) apply_filters( 'aumlang_translatable_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Register the meta box on translatable post types.
	 *
	 * @return void
	 */
	public function add() {
		foreach ( $this->post_types() as $type ) {
			add_meta_box(
				'aumlang-translations',
				__( 'AumLang Translations', 'aumlang' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Enqueue the meta box script on edit screens for translatable types.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'aumlang-admin',
			AUMLANG_URL . 'src/Admin/assets/css/admin.css',
			array(),
			AUMLANG_VERSION
		);

		wp_enqueue_script(
			'aumlang-metabox',
			AUMLANG_URL . 'src/Admin/assets/js/metabox.js',
			array( 'jquery' ),
			AUMLANG_VERSION,
			true
		);

		wp_localize_script(
			'aumlang-metabox',
			'AumLangMetaBox',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'translating'  => __( 'Translating…', 'aumlang' ),
				'errorPrefix'  => __( 'Translation failed: ', 'aumlang' ),
			)
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( $post ) {
		$source_id = get_post_meta( $post->ID, '_aumlang_source_id', true );

		if ( $source_id ) {
			$this->render_translation_note( (int) $source_id, $post );
			return;
		}

		if ( ! $this->providers->get_active() || ! $this->providers->get_active()->is_configured() ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'No translation provider is configured.', 'aumlang' ),
				esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ),
				esc_html__( 'Open settings', 'aumlang' )
			);
			return;
		}

		$default   = $this->languages->get_default_language();
		$languages = $this->languages->get_languages( true );

		wp_nonce_field( 'aumlang_translate', 'aumlang_translate_nonce' );
		echo '<ul class="aumlang-metabox" data-post="' . esc_attr( $post->ID ) . '">';

		foreach ( $languages as $language ) {
			if ( $default && $language->code() === $default->code() ) {
				continue;
			}

			$this->render_row( $post->ID, $language );
		}

		echo '</ul>';
	}

	/**
	 * Render one language row.
	 *
	 * @param int                          $post_id  Source post id.
	 * @param \AumLang\Language\Language    $language Language.
	 * @return void
	 */
	private function render_row( $post_id, $language ) {
		$code   = $language->code();
		$status = $this->linker->get_status( $post_id, $code );
		$badge  = $this->status_badge( $status );
		$target = $this->linker->get_translation( $post_id, $code );
		$button = ( 'none' === $status )
			? __( 'Translate', 'aumlang' )
			: ( 'stale' === $status ? __( 'Update', 'aumlang' ) : __( 'Re-translate', 'aumlang' ) );

		echo '<li class="aumlang-row" data-lang="' . esc_attr( $code ) . '">';
		echo '<span class="aumlang-lang">' . esc_html( $language->name() ) . '</span> ';
		echo '<span class="aumlang-status aumlang-status-' . esc_attr( $status ) . '">' . esc_html( $badge ) . '</span>';
		echo '<span class="aumlang-actions">';
		echo '<button type="button" class="button button-small aumlang-translate">' . esc_html( $button ) . '</button>';

		$edit_link = $target ? get_edit_post_link( $target ) : '';
		printf(
			' <a class="aumlang-edit" href="%1$s"%2$s>%3$s</a>',
			esc_url( (string) $edit_link ),
			$edit_link ? '' : ' style="display:none"',
			esc_html__( 'Edit', 'aumlang' )
		);

		echo '</span>';
		echo '</li>';
	}

	/**
	 * Note shown when editing a translation post itself.
	 *
	 * @param int      $source_id Source post id.
	 * @param \WP_Post $post      Current (translation) post.
	 * @return void
	 */
	private function render_translation_note( $source_id, $post ) {
		$lang      = (string) get_post_meta( $post->ID, '_aumlang_language', true );
		$edit_link = get_edit_post_link( $source_id );

		echo '<p>';
		printf(
			/* translators: %s: language code. */
			esc_html__( 'This is the %s translation.', 'aumlang' ),
			'<code>' . esc_html( $lang ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</p>';

		if ( $edit_link ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $edit_link ),
				esc_html__( 'Edit the original', 'aumlang' )
			);
		}
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_badge( $status ) {
		switch ( $status ) {
			case 'machine':
				return __( 'Machine', 'aumlang' );
			case 'reviewed':
				return __( 'Reviewed', 'aumlang' );
			case 'stale':
				return __( 'Outdated', 'aumlang' );
			default:
				return __( 'Not translated', 'aumlang' );
		}
	}

	/**
	 * AJAX: translate a post into one language.
	 *
	 * @return void
	 */
	public function ajax_translate() {
		check_ajax_referer( 'aumlang_translate', 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$result = $this->orchestrator->translate_content( $post_id, $lang );

		if ( ! $result->success ) {
			wp_send_json_error( array( 'message' => $result->message ) );
		}

		$status = $this->linker->get_status( $post_id, $lang );

		wp_send_json_success(
			array(
				'status'      => $status,
				'statusLabel' => $this->status_badge( $status ),
				'editLink'    => (string) get_edit_post_link( $result->target_id, 'url' ),
				'button'      => __( 'Re-translate', 'aumlang' ),
			)
		);
	}
}
