<?php
/**
 * Diagnostics tab: find out why translation fails on *this* server.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Translation\Provider\ProviderRegistry;
use AumLang\Translation\TranslationOrchestrator;

defined( 'ABSPATH' ) || exit;

/**
 * 🔴 为什么要有这一页。
 *
 * 买家报「Translation failed: … 502 Bad Gateway」，我们这边复现不出来——因为 502
 * 根本不是插件发的，是买家主机的网关发的：它等不到 PHP 回话就把连接掐了。从插件
 * 这一侧看，只看得见一个「请求没回来」，看不见是谁掐的、几秒掐的。
 *
 * 1.0.22 把翻译改成分轮进行，每轮约 20 秒。但那只在**网关肯等 20 秒以上**时才成立，
 * 而且一轮的收工判断是在每块**开始前**做的 —— 所以一轮最坏会超时一整块。真正决定
 * 成败的是两个数：
 *
 *   A. 这台服务器的网关肯等多少秒；
 *   B. 调一次翻译服务商要多少秒（一块就是一次调用，echo 重试还会更久）。
 *
 * A 和 B 都只能在买家那台机器上量。所以这一页做的事就是把 A 和 B 量出来，
 * 再给一句能照着做的结论，最后输出一段可以整段复制发回来的纯文本。
 */
class DiagnosticsPage {

	/**
	 * Capability required, shared with the rest of the screen.
	 */
	const CAPABILITY = SettingsPage::CAPABILITY;

	/**
	 * Nonce action for every diagnostics request.
	 */
	const NONCE = 'aumlang_diag';

	/**
	 * 探测梯子（秒）。升序逐级试，**第一次失败就停** —— 没必要把后面更长的也跑完，
	 * 那只是白白占着买家的 PHP 工作进程。
	 */
	const LADDER = array( 5, 15, 25, 40, 55, 70, 90, 120 );

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Translation orchestrator — the same object the Translate button drives.
	 *
	 * @var TranslationOrchestrator
	 */
	private $orchestrator;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry        $providers    Provider registry.
	 * @param TranslationOrchestrator $orchestrator Orchestrator.
	 */
	public function __construct( ProviderRegistry $providers, TranslationOrchestrator $orchestrator ) {
		$this->providers    = $providers;
		$this->orchestrator = $orchestrator;
	}

	/**
	 * Register the AJAX endpoints.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_aumlang_diag_env', array( $this, 'ajax_env' ) );
		add_action( 'wp_ajax_aumlang_diag_sleep', array( $this, 'ajax_sleep' ) );
		add_action( 'wp_ajax_aumlang_diag_call', array( $this, 'ajax_call' ) );
		add_action( 'wp_ajax_aumlang_diag_real', array( $this, 'ajax_real' ) );
	}

	/**
	 * Shared guard for every endpoint on this page.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}
	}

	/**
	 * AJAX: the facts that need no waiting — versions, limits, who is in front of PHP.
	 *
	 * @return void
	 */
	public function ajax_env() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->guard();

		$provider = $this->providers->get_active();
		$batch    = 0;

		if ( $provider && $provider->supports_batch() ) {
			$batch = (int) $provider->max_batch_size();
		}

		/*
		 * 代理层要单独报：Cloudflare 的 100 秒上限、各家 CDN 和负载均衡器自己的超时，
		 * 都在 nginx 之外再加一道，而买家通常不知道它存在。
		 */
		$proxy = array();
		foreach ( array( 'HTTP_CF_RAY' => 'Cloudflare', 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_X_FORWARDED_PROTO' => 'X-Forwarded-Proto' ) as $key => $label ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$proxy[] = $label;
			}
		}

		wp_send_json_success(
			array(
				'plugin'     => AUMLANG_VERSION,
				'wp'         => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
				'maxExec'    => (int) ini_get( 'max_execution_time' ),
				'memory'     => (string) ini_get( 'memory_limit' ),
				'server'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'proxy'      => $proxy,
				'provider'   => $provider ? $provider->get_id() : '',
				'configured' => $provider ? (bool) $provider->is_configured() : false,
				'batchSize'  => $batch,
				'round'      => (int) TranslationOrchestrator::ROUND_SECONDS,
				'stuck'      => $this->stuck_jobs(),
			)
		);
	}

	/**
	 * How many half-finished translations are parked in transients.
	 *
	 * 不为零说明确实有任务跑到一半被掐断过 —— 这是「买家真遇到过」的硬证据，
	 * 而不是又一次我们这边复现不出来。
	 *
	 * @return int
	 */
	private function stuck_jobs() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_aumlang_prog_' ) . '%'
			)
		);
	}

	/**
	 * AJAX: hold the request open for N seconds, then answer.
	 *
	 * 🔴 这是量网关、不是量 PHP 的。PHP 的 max_execution_time 在 Unix 上**不计**
	 * sleep() 这类系统调用里耗的时间（PHP 手册明说），所以这个探针睡多久都不会被
	 * PHP 自己掐掉 —— 掐掉它的只可能是前面的网关/代理。这正是我们要的那个数。
	 *
	 * @return void
	 */
	public function ajax_sleep() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->guard();

		$secs  = isset( $_POST['secs'] ) ? (int) $_POST['secs'] : 5;
		$secs  = max( 1, min( 75, $secs ) );
		$start = microtime( true );

		sleep( $secs );

		wp_send_json_success(
			array(
				'asked' => $secs,
				'slept' => round( microtime( true ) - $start, 1 ),
			)
		);
	}

	/**
	 * AJAX: time one real call to the translation provider.
	 *
	 * `n` 条一起发，模拟真实的一「块」。因为一轮的收工是在每块开始前判的，
	 * 一块要多久，就是一轮最坏会超出预算多久。
	 *
	 * @return void
	 */
	public function ajax_call() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->guard();

		$provider = $this->providers->get_active();

		if ( ! $provider || ! $provider->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'No translation provider is configured yet.', 'aumlang' ) ) );
		}

		$n = isset( $_POST['n'] ) ? (int) $_POST['n'] : 1;
		/*
		 * 上限要跟得上 provider 真正的每批条数（DeepSeek 是 50）。封在 20 条，
		 * 量的就不是插件实际发出去的那种请求 —— 短返和拆分恰恰在大批量时才明显。
		 */
		$n = max( 1, min( 100, $n ) );

		$texts = array();
		for ( $i = 0; $i < $n; $i++ ) {
			/* 短句、好翻、没有歧义 —— 量的是往返耗时，不是模型的本事。 */
			$texts[] = 'The shop opens at nine in the morning.';
		}

		$start = microtime( true );

		/*
		 * 🔴 provider 是**抛异常**的，不是返回 WP_Error —— 接口注释写着
		 * `@throws \RuntimeException`。所以这里必须 catch：诊断页的职责就是
		 * 把失败原原本本带回来，它自己绝不能是那个崩掉的人。
		 */
		try {
			$result = $provider->translate( $texts, 'en', 'de', array() );
		} catch ( \Throwable $e ) {
			wp_send_json_success(
				array(
					'n'     => $n,
					'took'  => round( microtime( true ) - $start, 1 ),
					'ok'    => false,
					'code'  => get_class( $e ),
					'error' => $e->getMessage(),
				)
			);
		}

		$took = round( microtime( true ) - $start, 1 );

		wp_send_json_success(
			array(
				'n'      => $n,
				'took'   => $took,
				'ok'     => true,
				'sample' => isset( $result[0] ) ? (string) $result[0] : '',
			)
		);
	}

	/**
	 * AJAX: run one real round on a real page.
	 *
	 * 🔴 为什么非得有这一步。
	 *
	 * 只量「服务器肯等多久」和「调一次要多久」，会漏掉真正的故障：2026-10 有买家的
	 * 服务器挂 70 秒都不掐，单次调用也只要 1.4 秒 —— 两项都「正常」，可真翻一页照样
	 * 109 秒后 502。因为一页要调几十次，中间还会因为服务商少返回一条而重试+拆分。
	 * 只有把真实的一页按轮翻一遍，才看得见这件事。
	 *
	 * @return void
	 */
	public function ajax_real() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->guard();

		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_success( array( 'ok' => false, 'error' => __( 'No page chosen.', 'aumlang' ) ) );
		}

		if ( '' === $lang ) {
			wp_send_json_success( array( 'ok' => false, 'error' => __( 'No target language is configured yet.', 'aumlang' ) ) );
		}

		$start = microtime( true );

		try {
			$result = $this->orchestrator->translate_content( $post_id, $lang );
		} catch ( \Throwable $e ) {
			wp_send_json_success(
				array(
					'ok'    => false,
					'took'  => round( microtime( true ) - $start, 1 ),
					'error' => $e->getMessage(),
				)
			);
		}

		$done      = ! is_object( $result ) || ! property_exists( $result, 'done' ) || (bool) $result->done;
		$remaining = ( is_object( $result ) && property_exists( $result, 'remaining' ) ) ? (int) $result->remaining : 0;

		wp_send_json_success(
			array(
				'ok'        => true,
				'took'      => round( microtime( true ) - $start, 1 ),
				'done'      => $done,
				'remaining' => $remaining,
				'message'   => ( is_object( $result ) && property_exists( $result, 'message' ) ) ? (string) $result->message : '',
				'pending'   => ( is_object( $result ) && property_exists( $result, 'pending' ) )
					? array_map( 'strval', (array) $result->pending )
					: array(),
				'diag'      => ( is_object( $result ) && property_exists( $result, 'diag' ) )
					? (array) $result->diag
					: array(),
			)
		);
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public function render_tab() {
		?>
		<p class="aml-card-desc">
			<?php esc_html_e( 'Runs a series of checks against this server and prints one report you can copy and send us. Nothing leaves your site on its own.', 'aumlang' ); ?>
		</p>

		<div class="aml-card">
			<h2 class="aml-card-h">
				<span class="dashicons dashicons-sos" aria-hidden="true"></span>
				<?php esc_html_e( 'Why translation fails here', 'aumlang' ); ?>
			</h2>

			<p class="aml-card-desc">
				<?php esc_html_e( 'A "502 Bad Gateway" is not sent by AumLang — it is sent by your server when it gives up waiting for PHP to answer. This finds out how long your server is willing to wait, and how long one call to your translation provider actually takes. Those two numbers decide whether translation can work here at all.', 'aumlang' ); ?>
			</p>

			<p class="aml-hint aml-hint-warn">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<?php esc_html_e( 'The check deliberately holds requests open for up to about a minute each, so the whole run can take a few minutes. Leave this tab open while it works.', 'aumlang' ); ?>
			</p>

			<p>
				<label for="aumlang-diag-post"><?php esc_html_e( 'Also translate a real page (strongly recommended):', 'aumlang' ); ?></label><br>
				<select id="aumlang-diag-post" style="max-width:360px">
					<option value="0"><?php esc_html_e( '— skip this step —', 'aumlang' ); ?></option>
					<?php
					foreach ( get_posts(
						array(
							'post_type'        => array( 'post', 'page' ),
							'post_status'      => 'publish',
							'numberposts'      => 50,
							'orderby'          => 'modified',
							'order'            => 'DESC',
						)
					) as $item ) :
						?>
						<option value="<?php echo esc_attr( $item->ID ); ?>">
							<?php echo esc_html( $item->post_title ? $item->post_title : '#' . $item->ID ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="number" id="aumlang-diag-id" min="0" step="1" style="width:100px"
					placeholder="<?php esc_attr_e( 'or ID', 'aumlang' ); ?>">
				<span class="aml-hint">
					<?php esc_html_e( 'Pick the page that fails. Measuring the server alone is not enough — a page can still fail while every other number looks fine.', 'aumlang' ); ?>
				</span>
			</p>

			<p>
				<button type="button" class="aml-btn aml-btn-primary" id="aumlang-diag-run">
					<?php esc_html_e( 'Run the check', 'aumlang' ); ?>
				</button>
				<span id="aumlang-diag-status" class="aml-hint" style="margin-left:10px"></span>
			</p>

			<div id="aumlang-diag-out" hidden>
				<div id="aumlang-diag-verdict"></div>
				<p>
					<button type="button" class="aml-btn" id="aumlang-diag-copy">
						<?php esc_html_e( 'Copy the report', 'aumlang' ); ?>
					</button>
				</p>
				<textarea id="aumlang-diag-report" rows="18" readonly
					style="width:100%;font-family:monospace;font-size:12px"></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * The first non-default language, used as the target for the real-page probe.
	 *
	 * @return string
	 */
	private function first_target() {
		global $wpdb;

		$table = $wpdb->prefix . 'aumlang_languages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( "SELECT code FROM {$table} WHERE is_default = 0 AND active = 1 ORDER BY sort_order, id LIMIT 1" );

		return $found ? (string) $found : '';
	}

	/**
	 * Enqueue this tab's script. Called from AdminMenu with the shared handle.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$js = AUMLANG_DIR . 'src/Admin/assets/js/diagnostics.js';

		wp_enqueue_script(
			'aumlang-diagnostics',
			AUMLANG_URL . 'src/Admin/assets/js/diagnostics.js',
			array( 'jquery' ),
			file_exists( $js ) ? filemtime( $js ) : AUMLANG_VERSION,
			true
		);

		wp_localize_script(
			'aumlang-diagnostics',
			'AumLangDiag',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'ladder'  => self::LADDER,
				'site'    => home_url( '/' ),
				'lang'    => $this->first_target(),
				'i18n'    => array(
					'env'      => __( 'Reading the environment…', 'aumlang' ),
					/* translators: %d: seconds the probe is holding the request open. */
					'sleep'    => __( 'Testing whether your server waits %d seconds…', 'aumlang' ),
					'call'     => __( 'Timing one real call to the translation provider…', 'aumlang' ),
					/* translators: %d: round number. */
					'round'    => __( 'Translating the page you picked — round %d…', 'aumlang' ),
					'done'     => __( 'Done.', 'aumlang' ),
					'copied'   => __( 'Copied.', 'aumlang' ),
					'failed'   => __( 'The request failed before the server could answer.', 'aumlang' ),
				),
			)
		);
	}
}
