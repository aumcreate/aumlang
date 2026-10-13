<?php
/**
 * Translate many posts into one language from the posts list.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Content\TranslatableTypes;
use AumLang\Language\LanguageRegistry;
use AumLang\Translation\BulkQueue;
use AumLang\Translation\BulkRunner;

defined( 'ABSPATH' ) || exit;

/**
 * The bulk action, the confirmation, and the progress screen.
 *
 * # 🔴 Why a confirmation screen and not just "go"
 *
 * Every other bulk action in WordPress is free and reversible. This one **spends the owner's money**
 * and cannot be undone by unchecking a box. Pressing Apply therefore does not start anything: it
 * lands on a screen that says how many posts will actually be sent, how many are already translated
 * and will be skipped, and into which language — and *that* screen has the button.
 *
 * The count is computed when the screen is drawn, never cached. An estimate from a minute ago can
 * describe a different site, and the owner would be agreeing to a number that is no longer true.
 *
 * # Why progress has its own screen
 *
 * A run is minutes long. Driving it from the list page would mean the owner cannot navigate away
 * without stopping it, and WordPress gives no way to show per-row progress there. A screen of its
 * own can show what finished, what was skipped and why, and offer **Stop** — and because the queue
 * is stored, closing it hands the work to cron rather than losing it.
 */
class BulkTranslate {

	/**
	 * Screen slug.
	 */
	const PAGE = 'aumlang-bulk';

	/**
	 * Prefix for the per-language bulk action values.
	 */
	const ACTION_PREFIX = 'aumlang_translate_';

	/**
	 * Languages.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Which post types this plugin translates.
	 *
	 * @var TranslatableTypes
	 */
	private $types;

	/**
	 * Queue.
	 *
	 * @var BulkQueue
	 */
	private $queue;

	/**
	 * Runner.
	 *
	 * @var BulkRunner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry  $languages Languages.
	 * @param TranslatableTypes $types     Translatable types.
	 * @param BulkQueue         $queue     Queue.
	 * @param BulkRunner        $runner    Runner.
	 */
	public function __construct( LanguageRegistry $languages, TranslatableTypes $types, BulkQueue $queue, BulkRunner $runner ) {
		$this->languages = $languages;
		$this->types     = $types;
		$this->queue     = $queue;
		$this->runner    = $runner;
	}

	/**
	 * Register hooks.
	 *
	 * 🔴 `bulk_actions-edit-{$type}` and `handle_bulk_actions-edit-{$type}` are **dynamic hook
	 * names**. A wrong type here does not raise anything — `add_filter()` succeeds against a name
	 * nobody fires, and the action simply never appears in the dropdown. So the types come from
	 * {@see TranslatableTypes::selected_post_types()}, the same list the rest of the plugin acts on,
	 * never from a literal written here.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'wp_ajax_aumlang_bulk_round', array( $this, 'ajax_round' ) );
		add_action( 'wp_ajax_aumlang_bulk_stop', array( $this, 'ajax_stop' ) );

		foreach ( (array) $this->types->selected_post_types() as $type ) {
			add_filter( 'bulk_actions-edit-' . $type, array( $this, 'add_bulk_actions' ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, array( $this, 'handle_bulk' ), 10, 3 );
		}
	}

	/**
	 * Non-default languages, as code => name.
	 *
	 * @return array<string, string>
	 */
	private function target_languages() {
		$default = $this->languages->get_default_language();
		$dcode   = $default && method_exists( $default, 'code' ) ? $default->code() : '';
		$out     = array();

		foreach ( (array) $this->languages->get_languages( true ) as $language ) {
			$code = method_exists( $language, 'code' ) ? $language->code() : '';

			if ( '' !== $code && $code !== $dcode ) {
				$out[ $code ] = method_exists( $language, 'name' ) ? $language->name() : $code;
			}
		}

		return $out;
	}

	/**
	 * Add one entry per language to the bulk dropdown.
	 *
	 * One entry per language rather than a single entry plus a language picker: the dropdown is the
	 * only control WordPress gives here, and a picker that appears after Apply would be a second
	 * decision on a screen the owner thinks is confirming the first.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		foreach ( $this->target_languages() as $code => $name ) {
			/* translators: %s: language name. */
			$actions[ self::ACTION_PREFIX . $code ] = sprintf( __( 'AumLang: translate into %s', 'aumlang' ), $name );
		}

		return $actions;
	}

	/**
	 * Turn the chosen rows into a confirmation screen.
	 *
	 * @param string $redirect Where WordPress would go next.
	 * @param string $action   The chosen bulk action.
	 * @param int[]  $ids      Selected post ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( (string) $action, self::ACTION_PREFIX ) ) {
			return $redirect;
		}

		$lang = substr( (string) $action, strlen( self::ACTION_PREFIX ) );

		if ( ! $this->languages->is_registered( $lang ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return $redirect;
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		if ( ! $ids ) {
			return $redirect;
		}

		return add_query_arg(
			array(
				'page'     => self::PAGE,
				'lang'     => rawurlencode( $lang ),
				'ids'      => implode( ',', $ids ),
				'_wpnonce' => wp_create_nonce( 'aumlang_bulk_confirm' ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Register the screen.
	 *
	 * Hidden from the menu: it is only ever reached from a bulk action or from a run already in
	 * progress, and a menu entry that does nothing without a selection is a dead end.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'',
			__( 'Bulk translation', 'aumlang' ),
			__( 'Bulk translation', 'aumlang' ),
			SettingsPage::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Draw the confirmation or the progress screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'aumlang' ) );
		}

		$running = $this->queue->is_running();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides which read-only screen to draw; the start button below carries its own nonce.
		$has_selection = isset( $_GET['ids'], $_GET['lang'] );

		if ( ! $running && $has_selection
			&& isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'aumlang_bulk_confirm' ) ) {
			$this->render_confirm();

			return;
		}

		$this->render_progress();
	}

	/**
	 * The "this will spend money" screen.
	 *
	 * @return void
	 */
	private function render_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by the caller.
		$lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by the caller.
		$raw  = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		$ids  = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );

		$language = $this->languages->get_language( $lang );
		$name     = $language && method_exists( $language, 'name' ) ? $language->name() : $lang;
		$estimate = $this->runner->estimate( $ids, $lang );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bulk translation', 'aumlang' ) . '</h1>';

		if ( 0 === $estimate['translate'] ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html( sprintf(
					/* translators: %1$d: number of posts, %2$s: language name. */
					_n( 'The %1$d post you selected already has a %2$s translation. Nothing to do.', 'All %1$d posts you selected already have a %2$s translation. Nothing to do.', count( $ids ), 'aumlang' ),
					count( $ids ),
					$name
				) )
				. '</p><p>'
				. esc_html__( 'To translate one of them again, open it and use the button in the editor — it says what will happen before it happens.', 'aumlang' )
				. '</p></div>';
			echo '</div>';

			return;
		}

		echo '<p>' . esc_html( sprintf(
			/* translators: %1$d: number of posts, %2$s: language name. */
			_n( '%1$d post will be translated into %2$s.', '%1$d posts will be translated into %2$s.', $estimate['translate'], 'aumlang' ),
			$estimate['translate'],
			$name
		) ) . '</p>';

		if ( $estimate['skip'] > 0 ) {
			/*
			 * 🔴 「已有译文」不足以描述被跳过的那些。
			 *
			 * 列表里译文和原文混在一起，所以被跳过的有两类：已经翻过的原文，
			 * 以及**本身就是译文的副本**。只说前者，站主看到数字对不上会以为是算错了 ——
			 * 而真正的原因（他全选时把副本也选进来了）他看不见。
			 */
			echo '<p>' . esc_html( sprintf(
				/* translators: %d: number of posts. */
				_n( '%d is left alone — it already has a translation, or is itself a translation.', '%d are left alone — they already have a translation, or are themselves translations.', $estimate['skip'], 'aumlang' ),
				$estimate['skip']
			) ) . '</p>';
		}

		echo '<p><strong>' . esc_html__( 'This sends content to your translation provider and spends whatever they charge for it.', 'aumlang' ) . '</strong> '
			. esc_html__( 'You can stop the run at any point; posts already finished stay translated.', 'aumlang' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">';
		wp_nonce_field( 'aumlang_bulk_start' );
		echo '<input type="hidden" name="aumlang_bulk_lang" value="' . esc_attr( $lang ) . '">';
		echo '<input type="hidden" name="aumlang_bulk_ids" value="' . esc_attr( implode( ',', $ids ) ) . '">';
		submit_button( __( 'Start translating', 'aumlang' ), 'primary', 'aumlang_bulk_start', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'Cancel', 'aumlang' ) . '</a>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The progress screen.
	 *
	 * @return void
	 */
	private function render_progress() {
		$this->maybe_start();

		$status   = $this->queue->status();
		$language = $this->languages->get_language( $status['lang'] );
		$name     = $language && method_exists( $language, 'name' ) ? $language->name() : $status['lang'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bulk translation', 'aumlang' ) . '</h1>';

		if ( 0 === $status['total'] ) {
			echo '<p>' . esc_html__( 'Nothing is running. Select posts in the list and choose a language from the bulk actions menu.', 'aumlang' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'Back to Pages', 'aumlang' ) . '</a></p>';
			echo '</div>';

			return;
		}

		echo '<p id="aumlang-bulk-head">' . esc_html( sprintf(
			/* translators: %1$d: finished, %2$d: total, %3$s: language name. */
			__( '%1$d of %2$d finished — into %3$s', 'aumlang' ),
			$status['done'],
			$status['total'],
			$name
		) ) . '</p>';

		echo '<progress id="aumlang-bulk-bar" max="' . esc_attr( (string) $status['total'] ) . '" value="' . esc_attr( (string) $status['done'] ) . '" style="width:420px;height:22px"></progress>';

		echo '<p><button type="button" class="button" id="aumlang-bulk-stop">' . esc_html__( 'Stop after the current post', 'aumlang' ) . '</button></p>';

		echo '<p class="description">' . esc_html__( 'You can close this page. The run continues in the background and you can come back to it.', 'aumlang' ) . '</p>';

		echo '<table class="widefat striped" id="aumlang-bulk-log"><thead><tr>'
			. '<th>' . esc_html__( 'Post', 'aumlang' ) . '</th>'
			. '<th>' . esc_html__( 'Outcome', 'aumlang' ) . '</th>'
			. '</tr></thead><tbody></tbody></table>';

		$this->print_script();
		echo '</div>';
	}

	/**
	 * Start a run when the confirmation form was submitted.
	 *
	 * @return void
	 */
	private function maybe_start() {
		if ( ! isset( $_POST['aumlang_bulk_start'] ) ) {
			return;
		}

		check_admin_referer( 'aumlang_bulk_start' );

		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'aumlang' ) );
		}

		$lang = isset( $_POST['aumlang_bulk_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['aumlang_bulk_lang'] ) ) : '';
		$raw  = isset( $_POST['aumlang_bulk_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['aumlang_bulk_ids'] ) ) : '';
		$ids  = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );

		if ( '' === $lang || ! $ids || ! $this->languages->is_registered( $lang ) ) {
			return;
		}

		if ( $this->queue->start( $ids, $lang ) ) {
			$this->runner->schedule_next();
		}
	}

	/**
	 * One round, called from the progress screen.
	 *
	 * @return void
	 */
	public function ajax_round() {
		check_ajax_referer( 'aumlang_bulk_ajax', 'nonce' );

		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$out            = $this->runner->run_round();
		$status         = $this->queue->status();
		$status['state'] = $out['state'];

		wp_send_json_success( $status );
	}

	/**
	 * Ask the run to stop.
	 *
	 * @return void
	 */
	public function ajax_stop() {
		check_ajax_referer( 'aumlang_bulk_ajax', 'nonce' );

		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$this->queue->request_stop();
		$this->runner->unschedule();

		wp_send_json_success( $this->queue->status() );
	}

	/**
	 * The small script that drives rounds from this screen.
	 *
	 * @return void
	 */
	private function print_script() {
		$data = array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'aumlang_bulk_ajax' ),
			'strings'  => array(
				/* translators: %1$d: finished, %2$d: total. */
				'head'    => __( '%1$d of %2$d finished', 'aumlang' ),
				'stopped' => __( 'Stopped. Posts already finished stay translated.', 'aumlang' ),
				'done'    => __( 'Finished.', 'aumlang' ),
				'ok'      => __( 'Translated', 'aumlang' ),
				'skipped' => __( 'Already translated — left alone', 'aumlang' ),
				'failed'  => __( 'Failed', 'aumlang' ),
			),
		);

		wp_print_inline_script_tag(
			'window.aumlangBulk=' . wp_json_encode( $data ) . ';'
			. 'document.addEventListener("DOMContentLoaded",function(){'
			. 'var c=window.aumlangBulk,bar=document.getElementById("aumlang-bulk-bar"),'
			. 'head=document.getElementById("aumlang-bulk-head"),'
			. 'body=document.querySelector("#aumlang-bulk-log tbody"),'
			. 'stop=document.getElementById("aumlang-bulk-stop"),busy=false;'
			. 'function row(r){var tr=document.createElement("tr");var a=document.createElement("td");'
			. 'a.textContent=r.title||("#"+r.id);var b=document.createElement("td");'
			. 'b.textContent=(c.strings[r.outcome]||r.outcome)+(r.detail?" — "+r.detail:"");'
			. 'tr.appendChild(a);tr.appendChild(b);return tr;}'
			. 'function paint(s){bar.max=s.total;bar.value=s.done;'
			. 'head.textContent=c.strings.head.replace("%1$d",s.done).replace("%2$d",s.total);'
			. 'body.textContent="";(s.results||[]).forEach(function(r){body.appendChild(row(r));});}'
			. 'function tick(){if(busy){return;}busy=true;'
			. 'var f=new FormData();f.append("action","aumlang_bulk_round");f.append("nonce",c.nonce);'
			. 'fetch(c.ajaxUrl,{method:"POST",body:f,credentials:"same-origin"})'
			. '.then(function(r){return r.json();}).then(function(j){busy=false;'
			. 'if(!j||!j.success){return;}paint(j.data);'
			. 'if(j.data.state==="running"){setTimeout(tick,500);}'
			. 'else{head.textContent=j.data.state==="stopped"?c.strings.stopped:c.strings.done;}'
			. '}).catch(function(){busy=false;setTimeout(tick,5000);});}'
			. 'stop.addEventListener("click",function(){stop.disabled=true;'
			. 'var f=new FormData();f.append("action","aumlang_bulk_stop");f.append("nonce",c.nonce);'
			. 'fetch(c.ajaxUrl,{method:"POST",body:f,credentials:"same-origin"});});'
			. 'tick();});'
		);
	}
}
