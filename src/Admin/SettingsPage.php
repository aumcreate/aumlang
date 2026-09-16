<?php
/**
 * Settings page: languages, translation provider, and general options.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Content\TranslatableTypes;
use AumLang\Language\LanguageCatalog;
use AumLang\Language\LanguagePackInstaller;
use AumLang\Language\LanguageRegistry;
use AumLang\Translation\Provider\CoreAiProvider;
use AumLang\Translation\Provider\DeepSeekProvider;
use AumLang\Translation\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the AumLang settings screen.
 */
class SettingsPage {

	const MENU_SLUG  = 'aumlang';
	const CAPABILITY = 'manage_options';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Language pack installer.
	 *
	 * @var LanguagePackInstaller
	 */
	private $packs;

	/**
	 * Translatable post types / taxonomies resolver.
	 *
	 * @var TranslatableTypes
	 */
	private $types;

	/**
	 * Notices queued during request handling.
	 *
	 * @var array<int, array{type:string,text:string}>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry      $languages Language registry.
	 * @param ProviderRegistry      $providers Provider registry.
	 * @param LanguagePackInstaller $packs     Language pack installer.
	 * @param TranslatableTypes     $types     Translatable types resolver.
	 */
	public function __construct(
		LanguageRegistry $languages,
		ProviderRegistry $providers,
		LanguagePackInstaller $packs,
		TranslatableTypes $types
	) {
		$this->languages = $languages;
		$this->providers = $providers;
		$this->packs     = $packs;
		$this->types     = $types;
	}

	/**
	 * Process any submitted form or action. Runs on admin_init.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['aumlang_settings_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_settings_nonce'] ) ), 'aumlang_save_settings' ) ) {
			$this->save_settings();
		}

		if ( isset( $_POST['aumlang_language_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_language_nonce'] ) ), 'aumlang_add_language' ) ) {
			$this->add_language();
		}

		$this->handle_language_action();
	}

	/**
	 * Handle GET row actions (set default / delete) with nonce + redirect.
	 *
	 * @return void
	 */
	private function handle_language_action() {
		if ( empty( $_GET['aumlang_action'] ) || empty( $_GET['lang'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['aumlang_action'] ) );
		$code   = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'aumlang_lang_' . $action . '_' . $code ) ) {
			return;
		}

		if ( 'default' === $action ) {
			$this->languages->set_default( $code );
		} elseif ( 'delete' === $action ) {
			$this->languages->unregister( $code );
		}

		update_option( 'aumlang_flush_rewrite', 1 );

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save general + provider settings.
	 *
	 * @return void
	 */
	private function save_settings() {
		// The caller checks this too. Repeating it here keeps the check next to
		// the code that trusts $_POST, so a future second caller cannot skip it.
		if ( ! isset( $_POST['aumlang_settings_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_settings_nonce'] ) ), 'aumlang_save_settings' ) ) {
			return;
		}

		$settings = (array) get_option( 'aumlang_settings', array() );

		$settings['default_language_has_prefix'] = ! empty( $_POST['default_language_has_prefix'] );
		$settings['delete_data_on_uninstall']    = ! empty( $_POST['delete_data_on_uninstall'] );
		// Either the client WordPress ships, or a direct connection to an
		// OpenAI-compatible endpoint. Anything unrecognised falls back to the
		// core client rather than silently keeping a stale value.
		$engine = isset( $_POST['active_provider'] ) ? sanitize_key( wp_unslash( $_POST['active_provider'] ) ) : ( CoreAiProvider::is_available() ? 'core' : 'deepseek' );
		$settings['active_provider'] = in_array( $engine, array( 'core', 'deepseek' ), true ) ? $engine : ( CoreAiProvider::is_available() ? 'core' : 'deepseek' );
		$settings['provider_preset'] = isset( $_POST['provider_preset'] ) ? sanitize_key( wp_unslash( $_POST['provider_preset'] ) ) : 'deepseek';

		// Only overwrite the API key when a new one is entered (blank keeps current).
		$submitted_key = isset( $_POST['deepseek_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['deepseek_api_key'] ) ) : '';
		if ( '' !== $submitted_key ) {
			$settings['providers']['deepseek']['api_key'] = $submitted_key;
		}

		// Endpoint + model let one OpenAI-compatible provider target DeepSeek, a
		// relay/proxy ("中转站"), or any compatible API.
		$settings['providers']['deepseek']['endpoint'] = isset( $_POST['deepseek_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['deepseek_endpoint'] ) ) : '';
		$settings['providers']['deepseek']['model']    = isset( $_POST['deepseek_model'] ) ? sanitize_text_field( wp_unslash( $_POST['deepseek_model'] ) ) : '';

		// Translatable content: store only known post types / taxonomies.
		$posted_types = isset( $_POST['translatable_post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['translatable_post_types'] ) ) : array();
		$posted_taxes = isset( $_POST['translatable_taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['translatable_taxonomies'] ) ) : array();

		$settings[ TranslatableTypes::POST_TYPES_KEY ] = array_values( array_intersect( $posted_types, array_keys( $this->types->available_post_types() ) ) );
		$settings[ TranslatableTypes::TAXONOMIES_KEY ] = array_values( array_intersect( $posted_taxes, array_keys( $this->types->available_taxonomies() ) ) );

		// Custom field meta keys to translate (one per line, advanced).
		$raw_keys = isset( $_POST['translatable_meta_keys'] ) ? wp_unslash( $_POST['translatable_meta_keys'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$meta_keys = array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n]+/', (string) $raw_keys ) ) );
		$settings['translatable_meta_keys'] = array_values( array_unique( $meta_keys ) );

		update_option( 'aumlang_settings', $settings );
		update_option( 'aumlang_flush_rewrite', 1 );

		$this->notices[] = array( 'type' => 'success', 'text' => __( 'Settings saved.', 'aumlang' ) );
	}

	/**
	 * AJAX: test the translation provider connection with a tiny request.
	 *
	 * Uses the values currently in the form (falling back to the saved key) so
	 * the user can verify before buying credits or saving.
	 *
	 * @return void
	 */
	public function ajax_test_provider() {
		check_ajax_referer( 'aumlang_test_provider', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$saved  = (array) get_option( 'aumlang_settings', array() );
		$engine = isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : '';
		if ( ! in_array( $engine, array( 'core', 'deepseek' ), true ) ) {
			$engine = isset( $saved['active_provider'] ) ? (string) $saved['active_provider'] : ( CoreAiProvider::is_available() ? 'core' : 'deepseek' );
		}

		// Test whichever engine is actually selected. Testing the direct
		// connection while the site is set to the WordPress AI client would
		// report an API key missing that the site does not need.
		if ( 'core' === $engine ) {
			$provider = new CoreAiProvider();

			if ( ! $provider->is_configured() ) {
				wp_send_json_error(
					array(
						'message' => __( 'WordPress has no AI provider connected yet. Connect one under Settings > Connectors, or switch the engine to a direct connection.', 'aumlang' ),
					)
				);
			}
		} else {
			$conf = isset( $saved['providers']['deepseek'] ) ? (array) $saved['providers']['deepseek'] : array();

			$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			if ( '' === $key ) {
				$key = isset( $conf['api_key'] ) ? (string) $conf['api_key'] : '';
			}

			$provider = new DeepSeekProvider(
				array(
					'api_key'  => $key,
					'endpoint' => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '',
					'model'    => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '',
				)
			);

			if ( ! $provider->is_configured() ) {
				wp_send_json_error( array( 'message' => __( 'Enter an API key first.', 'aumlang' ) ) );
			}
		}

		try {
			$out    = $provider->translate( array( 'Hello' ), 'en', 'zh' );
			$sample = isset( $out[0] ) ? $out[0] : '';
			wp_send_json_success(
				array(
					/* translators: %s: the translated sample word. */
					'message' => sprintf( __( 'Connection OK — "Hello" translated to "%s".', 'aumlang' ), $sample ),
				)
			);
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Common OpenAI-compatible provider presets (label + endpoint + model).
	 *
	 * @return array<string, array{label:string,endpoint:string,model:string}>
	 */
	private function provider_presets() {
		return array(
			'deepseek'   => array(
				'label'    => 'DeepSeek',
				'endpoint' => 'https://api.deepseek.com/chat/completions',
				'model'    => 'deepseek-chat',
				'url'      => 'https://platform.deepseek.com/',
				'note'     => __( 'Affordable and broadly reachable — a good default to start with.', 'aumlang' ),
			),
			'openai'     => array(
				'label'    => 'OpenAI',
				'endpoint' => 'https://api.openai.com/v1/chat/completions',
				'model'    => 'gpt-4o-mini',
				'url'      => 'https://platform.openai.com/api-keys',
				'note'     => __( 'High quality, but some servers cannot reach it — you may need a server in another region or a relay.', 'aumlang' ),
			),
			'openrouter' => array(
				'label'    => 'OpenRouter',
				'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
				'model'    => 'openai/gpt-4o-mini',
				'url'      => 'https://openrouter.ai/keys',
				'note'     => __( 'One key, many models (GPT, Claude, Gemini…). Handy if you want to switch models.', 'aumlang' ),
			),
			'custom'     => array(
				'label'    => __( 'Custom (relay / proxy)', 'aumlang' ),
				'endpoint' => '',
				'model'    => '',
				'url'      => '',
				'note'     => __( 'Enter an OpenAI-compatible relay URL + its key — use this for Claude, Gemini, or any proxy service.', 'aumlang' ),
			),
		);
	}

	/**
	 * AJAX: check which common provider endpoints this server can reach (no key
	 * needed — any HTTP response means reachable; a transport error means not).
	 *
	 * @return void
	 */
	public function ajax_test_reachability() {
		check_ajax_referer( 'aumlang_test_provider', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$results = array();

		foreach ( $this->provider_presets() as $preset ) {
			if ( '' === $preset['endpoint'] ) {
				continue; // Custom has no fixed endpoint.
			}

			$response = wp_remote_post(
				$preset['endpoint'],
				array(
					'timeout' => 8,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'model'      => $preset['model'],
							'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
							'max_tokens' => 1,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'label'  => $preset['label'],
					'ok'     => false,
					'detail' => $response->get_error_message(),
				);
			} else {
				$results[] = array(
					'label'  => $preset['label'],
					'ok'     => true,
					/* translators: %d: HTTP status code. */
					'detail' => sprintf( __( 'reachable (HTTP %d)', 'aumlang' ), (int) wp_remote_retrieve_response_code( $response ) ),
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Register a new language chosen from the catalog dropdown.
	 *
	 * @return void
	 */
	private function add_language() {
		$code  = isset( $_POST['catalog_code'] ) ? sanitize_text_field( wp_unslash( $_POST['catalog_code'] ) ) : '';
		$entry = LanguageCatalog::get( $code );

		if ( ! $entry ) {
			$this->notices[] = array( 'type' => 'error', 'text' => __( 'Please choose a language to add.', 'aumlang' ) );
			return;
		}

		$result = $this->languages->register(
			array(
				'code'   => $code,
				'name'   => $entry['name'],
				'slug'   => $code,
				'locale' => $entry['locale'],
				'is_rtl' => $entry['rtl'],
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->notices[] = array( 'type' => 'error', 'text' => $result->get_error_message() );
			return;
		}

		update_option( 'aumlang_flush_rewrite', 1 );
		$this->notices[] = array( 'type' => 'success', 'text' => __( 'Language added.', 'aumlang' ) );

		// Pull official WordPress.org translations for core + themes + plugins so
		// the site is mostly translated for free before any AI is needed.
		$packs = $this->packs->install( $entry['locale'] );

		if ( '' !== $packs['error'] ) {
			$this->notices[] = array( 'type' => 'error', 'text' => $packs['error'] );
		} else {
			$this->notices[] = array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: 1: core yes/no, 2: theme pack count, 3: plugin pack count. */
					__( 'Language packs installed — core: %1$s, themes: %2$d, plugins: %3$d. Remaining untranslated UI strings can be filled in under Strings.', 'aumlang' ),
					$packs['core'] ? __( 'yes', 'aumlang' ) : __( 'no', 'aumlang' ),
					(int) $packs['themes'],
					(int) $packs['plugins']
				),
			);
		}
	}

	/**
	 * Render the Settings tab body (no outer wrap — {@see AdminMenu} provides it).
	 *
	 * @return void
	 */
	public function render_tab() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = (array) get_option( 'aumlang_settings', array() );
		$has_key  = ! empty( $settings['providers']['deepseek']['api_key'] );
		?>
		<div class="aml-tab-settings">
			<?php $this->render_notices(); ?>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-flag" aria-hidden="true"></span> <?php esc_html_e( 'Languages', 'aumlang' ); ?></h2>
				<?php $this->render_languages_table(); ?>
				<?php $this->render_add_language_form(); ?>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'aumlang_save_settings', 'aumlang_settings_nonce' ); ?>

				<div class="aml-card">
					<h2 class="aml-card-h"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span> <?php esc_html_e( 'Translatable content', 'aumlang' ); ?></h2>
					<p class="aml-card-desc"><?php esc_html_e( 'Choose which content types AumLang translates. Anything turned off stays in the original language.', 'aumlang' ); ?></p>

					<h3 class="aml-group-label"><?php esc_html_e( 'Post types', 'aumlang' ); ?></h3>
					<div class="aml-toggle-grid">
						<?php
						$selected_types = $this->types->selected_post_types();
						foreach ( $this->types->available_post_types() as $name => $label ) :
							?>
							<label class="aml-switch-row">
								<span class="aml-switch">
									<input type="checkbox" name="translatable_post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $selected_types, true ) ); ?> />
									<span class="aml-track" aria-hidden="true"></span>
								</span>
								<span class="aml-switch-label"><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $name ); ?></code></span>
							</label>
						<?php endforeach; ?>
					</div>

					<h3 class="aml-group-label"><?php esc_html_e( 'Taxonomies', 'aumlang' ); ?></h3>
					<div class="aml-toggle-grid">
						<?php
						$selected_taxes = $this->types->selected_taxonomies();
						foreach ( $this->types->available_taxonomies() as $name => $label ) :
							?>
							<label class="aml-switch-row">
								<span class="aml-switch">
									<input type="checkbox" name="translatable_taxonomies[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $selected_taxes, true ) ); ?> />
									<span class="aml-track" aria-hidden="true"></span>
								</span>
								<span class="aml-switch-label"><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $name ); ?></code></span>
							</label>
						<?php endforeach; ?>
					</div>

					<h3 class="aml-group-label"><?php esc_html_e( 'Custom fields', 'aumlang' ); ?></h3>
					<p class="aml-card-desc"><?php esc_html_e( 'ACF fields are translated automatically by type. For other raw custom fields (theme/plugin post meta), list the meta keys to translate here — one per line. Only add keys whose value is text.', 'aumlang' ); ?></p>
					<?php $meta_keys = isset( $settings['translatable_meta_keys'] ) ? (array) $settings['translatable_meta_keys'] : array(); ?>
					<textarea name="translatable_meta_keys" rows="3" class="large-text code" placeholder="_subtitle&#10;_cta_text"><?php echo esc_textarea( implode( "\n", $meta_keys ) ); ?></textarea>
				</div>

				<?php
				$deepseek = isset( $settings['providers']['deepseek'] ) ? (array) $settings['providers']['deepseek'] : array();
				$endpoint = isset( $deepseek['endpoint'] ) ? $deepseek['endpoint'] : '';
				$model    = isset( $deepseek['model'] ) ? $deepseek['model'] : '';
				$presets  = $this->provider_presets();
				$current  = isset( $settings['provider_preset'] ) ? $settings['provider_preset'] : 'deepseek';
				?>
				<?php
				$engine     = isset( $settings['active_provider'] ) ? $settings['active_provider'] : ( CoreAiProvider::is_available() ? 'core' : 'deepseek' );
				$core_ready = ( new CoreAiProvider() )->is_configured();
				?>
				<div class="aml-card">
					<h2 class="aml-card-h"><span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span> <?php esc_html_e( 'Translation provider', 'aumlang' ); ?></h2>

					<div class="aml-field">
						<label for="aumlang-engine" class="aml-label"><?php esc_html_e( 'Translation engine', 'aumlang' ); ?></label>
						<select name="active_provider" id="aumlang-engine">
							<option value="core" <?php selected( $engine, 'core' ); ?>><?php esc_html_e( 'WordPress AI Client (recommended)', 'aumlang' ); ?></option>
							<option value="deepseek" <?php selected( $engine, 'deepseek' ); ?>><?php esc_html_e( 'Direct connection to an OpenAI-compatible API', 'aumlang' ); ?></option>
						</select>
						<p class="aml-field-hint">
							<?php
							if ( $core_ready ) {
								esc_html_e( 'A provider is connected to this site under Settings > Connectors. With the WordPress AI Client, the connection is managed there once and shared by every plugin, so no key is needed below.', 'aumlang' );
							} else {
								esc_html_e( 'The WordPress AI Client needs WordPress 7.0 or newer with a provider connected under Settings > Connectors. The connectors that ship with WordPress cover Anthropic, Google and OpenAI; if your network cannot reach those, choose the direct connection and enter your own key below.', 'aumlang' );
							}
							?>
						</p>
					</div>

					<div class="aml-field-grid">
						<div class="aml-field">
							<label for="aumlang-provider" class="aml-label"><?php esc_html_e( 'Provider', 'aumlang' ); ?></label>
							<select name="provider_preset" id="aumlang-provider">
								<?php foreach ( $presets as $key => $preset ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" data-endpoint="<?php echo esc_attr( $preset['endpoint'] ); ?>" data-model="<?php echo esc_attr( $preset['model'] ); ?>" <?php selected( $current, $key ); ?>>
										<?php echo esc_html( $preset['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="aml-field">
							<label for="aumlang-key" class="aml-label"><?php esc_html_e( 'API key', 'aumlang' ); ?></label>
							<input type="password" name="deepseek_api_key" id="aumlang-key" autocomplete="off"
								placeholder="<?php echo $has_key ? esc_attr__( 'Saved — leave blank to keep', 'aumlang' ) : esc_attr__( 'Enter your API key', 'aumlang' ); ?>" />
						</div>
						<div class="aml-field">
							<label for="aumlang-endpoint" class="aml-label"><?php esc_html_e( 'API endpoint (Base URL)', 'aumlang' ); ?></label>
							<input type="url" name="deepseek_endpoint" id="aumlang-endpoint" value="<?php echo esc_attr( $endpoint ); ?>"
								placeholder="https://api.deepseek.com/chat/completions" />
						</div>
						<div class="aml-field">
							<label for="aumlang-model" class="aml-label"><?php esc_html_e( 'Model', 'aumlang' ); ?></label>
							<input type="text" name="deepseek_model" id="aumlang-model" value="<?php echo esc_attr( $model ); ?>"
								placeholder="deepseek-chat" />
						</div>
					</div>
					<p class="aml-card-desc" style="margin:10px 0 0">
						<?php esc_html_e( 'Pick a provider to auto-fill its endpoint and model, or choose Custom for a relay/proxy. Any OpenAI-compatible API works.', 'aumlang' ); ?>
						<button type="button" class="aml-help-toggle" id="aumlang-help-toggle"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php esc_html_e( 'New to API keys? Where to get one', 'aumlang' ); ?></button>
					</p>

					<div class="aml-provider-help" id="aumlang-provider-help" hidden>
						<p><?php esc_html_e( 'An API key lets AumLang call an AI to translate. You pay the AI provider directly — AumLang never charges per translation. Steps: choose a provider below, create a key on its website, paste it into the API key box above, then click Test connection.', 'aumlang' ); ?></p>
						<ul class="aml-help-list">
							<?php foreach ( $presets as $preset ) : ?>
								<li>
									<strong><?php echo esc_html( $preset['label'] ); ?></strong>
									<?php if ( ! empty( $preset['url'] ) ) : ?>
										&mdash; <a href="<?php echo esc_url( $preset['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get API key', 'aumlang' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
									<?php endif; ?>
									<span class="aml-help-note"><?php echo esc_html( $preset['note'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="aml-help-why">
							<strong><?php esc_html_e( 'Why do Claude / Gemini use the Custom option?', 'aumlang' ); ?></strong>
							<?php esc_html_e( 'Claude (Anthropic) and Gemini (Google) use a different API format than OpenAI, and AumLang\'s engine speaks the OpenAI-compatible format. To use them, pick Custom and enter a relay URL that exposes them in OpenAI format — many relay services and OpenRouter do exactly that.', 'aumlang' ); ?>
						</p>
					</div>

					<p class="aml-provider-test">
						<button type="button" class="aml-btn aml-btn-primary" id="aumlang-test-provider"><?php esc_html_e( 'Test connection', 'aumlang' ); ?></button>
						<button type="button" class="aml-btn" id="aumlang-test-reach"><?php esc_html_e( 'Test reachability of all providers', 'aumlang' ); ?></button>
						<span class="aml-test-result" id="aumlang-test-result"></span>
					</p>
					<div class="aml-reach-results" id="aumlang-reach-results"></div>
					<p class="aml-hint aml-hint-warn"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> <?php esc_html_e( 'Before buying API credits, run "Test reachability" — a server (e.g. in mainland China) often cannot reach OpenAI but can reach DeepSeek. "Test connection" then verifies your key against the selected provider.', 'aumlang' ); ?></p>
					<p class="aml-hint"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Your key is stored in your site database and used to call the provider directly.', 'aumlang' ); ?></p>
				</div>

				<div class="aml-card">
					<h2 class="aml-card-h"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'General', 'aumlang' ); ?></h2>
					<label class="aml-switch-row aml-switch-block">
						<span class="aml-switch">
							<input type="checkbox" name="default_language_has_prefix" value="1" <?php checked( ! empty( $settings['default_language_has_prefix'] ) ); ?> />
							<span class="aml-track" aria-hidden="true"></span>
						</span>
						<span class="aml-switch-label"><?php esc_html_e( 'Add a language prefix to the default language (e.g. /en/).', 'aumlang' ); ?></span>
					</label>
					<label class="aml-switch-row aml-switch-block">
						<span class="aml-switch">
							<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
							<span class="aml-track" aria-hidden="true"></span>
						</span>
						<span class="aml-switch-label"><?php esc_html_e( 'Delete all AumLang data when the plugin is deleted.', 'aumlang' ); ?></span>
					</label>
				</div>

				<p class="aml-actions-bar">
					<button type="submit" class="aml-btn aml-btn-primary"><?php esc_html_e( 'Save settings', 'aumlang' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render queued admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->notices[] = array( 'type' => 'success', 'text' => __( 'Saved.', 'aumlang' ) );
		}

		foreach ( $this->notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['text'] )
			);
		}
	}

	/**
	 * Render the table of configured languages.
	 *
	 * @return void
	 */
	private function render_languages_table() {
		$languages = $this->languages->get_languages();

		if ( empty( $languages ) ) {
			echo '<p class="aml-card-desc">' . esc_html__( 'No languages yet.', 'aumlang' ) . '</p>';
			return;
		}
		?>
		<div class="aml-lang-list">
			<?php foreach ( $languages as $language ) : ?>
				<div class="aml-lang-row">
					<span class="aml-lang-code"><?php echo esc_html( strtoupper( $language->code() ) ); ?></span>
					<span class="aml-lang-name">
						<?php echo esc_html( $language->name() ); ?>
						<span class="aml-lang-locale"><?php echo esc_html( $language->locale() ); ?></span>
					</span>
					<span class="aml-lang-actions">
						<?php if ( $language->is_default() ) : ?>
							<span class="aml-pill aml-pill-default"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Default', 'aumlang' ); ?></span>
						<?php else : ?>
							<a class="aml-link" href="<?php echo esc_url( $this->action_url( 'default', $language->code() ) ); ?>"><?php esc_html_e( 'Make default', 'aumlang' ); ?></a>
							<a class="aml-link aml-link-danger" href="<?php echo esc_url( $this->action_url( 'delete', $language->code() ) ); ?>" aria-label="<?php esc_attr_e( 'Remove language', 'aumlang' ); ?>">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							</a>
						<?php endif; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the add-language form.
	 *
	 * @return void
	 */
	private function render_add_language_form() {
		$available = $this->available_catalog_languages();

		if ( empty( $available ) ) {
			echo '<p class="aml-card-desc aml-add-lang-empty">' . esc_html__( 'All available languages have been added.', 'aumlang' ) . '</p>';
			return;
		}
		?>
		<form method="post" class="aml-add-lang">
			<?php wp_nonce_field( 'aumlang_add_language', 'aumlang_language_nonce' ); ?>
			<select name="catalog_code" required>
				<option value=""><?php esc_html_e( 'Add a language…', 'aumlang' ); ?></option>
				<?php foreach ( $available as $code => $entry ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>">
						<?php echo esc_html( sprintf( '%1$s (%2$s)', $entry['name'], $code ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="aml-btn"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Add', 'aumlang' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Catalog languages that are not yet registered.
	 *
	 * @return array<string, array{name:string,locale:string,rtl:bool}>
	 */
	private function available_catalog_languages() {
		$available = array();

		foreach ( LanguageCatalog::all() as $code => $entry ) {
			if ( ! $this->languages->is_registered( $code ) ) {
				$available[ $code ] = $entry;
			}
		}

		return $available;
	}

	/**
	 * Build a nonced URL for a row action.
	 *
	 * @param string $action Action name.
	 * @param string $code   Language code.
	 * @return string
	 */
	private function action_url( $action, $code ) {
		$url = add_query_arg(
			array(
				'page'           => self::MENU_SLUG,
				'aumlang_action' => $action,
				'lang'           => $code,
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $url, 'aumlang_lang_' . $action . '_' . $code );
	}
}
