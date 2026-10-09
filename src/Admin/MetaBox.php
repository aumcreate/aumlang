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
				/* translators: %d is how many strings are still to translate. */
				'stillToGo'    => __( 'Translating… %d left', 'aumlang' ),
				'noProgress'   => __( 'the server stopped making progress on this page. Nothing was changed; try again, or translate the page in smaller sections.', 'aumlang' ),
				'errorPrefix'  => __( 'Translation failed: ', 'aumlang' ),
				/* translators: %d is an HTTP status code, %s the server's status text. */
				'httpFail'     => __( 'the server cut the request off (HTTP %1$d %2$s).', 'aumlang' ),
				'timeoutFail'  => __( 'the request ran past the server’s time limit before translation finished. A very long page can do this — try translating it in smaller pieces, or raise max_execution_time.', 'aumlang' ),
				'abortFail'    => __( 'the connection was lost before the server answered.', 'aumlang' ),
				'unknownFail'  => __( 'no reason reported by the server.', 'aumlang' ),
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

		/*
		 * 🔴 这里接 \Throwable 而不只是 \RuntimeException。编排器内部只接后者，所以
		 * 一个 \TypeError / \Error 会穿过去变成 500 —— 而 admin-ajax 的 500 到了浏览器
		 * 就是 jQuery 的 fail 分支，用户只看得到「Translation failed:」加一片空白。
		 * 2026-10-06 一个用户就卡在这个形状上。在 AJAX 边界上把任何失败都变成一条
		 * 读得懂的 JSON 错误，是这一层该做的事：异常原文照样带出去，不吞掉。
		 */
		try {
			$result = $this->orchestrator->translate_content( $post_id, $lang );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		if ( ! $result->success ) {
			/* 失败必须给得出原因：空消息等于界面承诺了原因却交了空。 */
			$why = trim( (string) $result->message );
			if ( '' === $why ) {
				$why = __( 'the translation did not complete, and no reason was recorded.', 'aumlang' );
			}
			wp_send_json_error( array( 'message' => $why ) );
		}

		/*
		 * 🔴 没翻完：不要去拿状态和编辑链接 —— 译文文章还不存在。
		 * 前端拿到 done=false 就再发一次，直到 true。整篇翻完才落盘，
		 * 所以中途失败不会在站上留下半篇译文。
		 */
		if ( ! $result->done ) {
			wp_send_json_success(
				array(
					'done'      => false,
					'remaining' => (int) $result->remaining,
					'notice'    => (string) $result->message,
				)
			);
		}

		$status = $this->linker->get_status( $post_id, $lang );

		wp_send_json_success(
			array(
				'done'        => true,
				'status'      => $status,
				'statusLabel' => $this->status_badge( $status ),
				'editLink'    => (string) get_edit_post_link( $result->target_id, 'url' ),
				'button'      => __( 'Re-translate', 'aumlang' ),
				/* 成功也可能有话要说——「翻完了但页面没变」就是那种话。 */
				'notice'      => (string) $result->message,
			)
		);
	}
}
