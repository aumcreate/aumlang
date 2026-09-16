<?php
/**
 * Admin page: front-end language switcher display settings.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the user control how the language switcher appears on the front end —
 * a floating overlay and/or appended to a theme menu location — and shows the
 * shortcode / block / template-tag ways to place it manually.
 */
class SwitcherPage {

	const SLUG = 'aumlang-switcher';
	const TAB  = 'switcher';

	/**
	 * Register WordPress hooks. The menu + assets are owned by {@see AdminMenu};
	 * this page only registers its own save handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Save the switcher display settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( empty( $_POST['aumlang_switcher_nonce'] ) || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aumlang_switcher_nonce'] ) ), 'aumlang_switcher_save' ) ) {
			return;
		}

		$show      = isset( $_POST['show'] ) ? sanitize_key( wp_unslash( $_POST['show'] ) ) : 'name';
		$position  = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'bottom-right';
		$location  = isset( $_POST['menu_location'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_location'] ) ) : '';
		$locations = array_keys( get_registered_nav_menus() );

		$settings              = (array) get_option( 'aumlang_settings', array() );
		$settings['switcher']  = array(
			'floating'      => ! empty( $_POST['floating'] ),
			'position'      => in_array( $position, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ? $position : 'bottom-right',
			'show'          => in_array( $show, array( 'name', 'code', 'both', 'flag', 'flag_name' ), true ) ? $show : 'name',
			'menu_location' => in_array( $location, $locations, true ) ? $location : '',
			'menu_label'    => isset( $_POST['menu_label'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_label'] ) ) : '',
		);

		update_option( 'aumlang_settings', $settings );

		wp_safe_redirect( AdminMenu::tab_url( self::TAB, array( 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * Render the Switcher tab body (no outer wrap — {@see AdminMenu} provides it).
	 *
	 * @return void
	 */
	public function render_tab() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$settings  = aumlang()->switcher()->settings();
		$locations = get_registered_nav_menus();
		?>
		<div class="aml-tab-switcher">
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Switcher settings saved.', 'aumlang' ); ?></p></div>
			<?php endif; ?>

			<?php if ( in_array( 'aumcreate', array( get_template(), get_stylesheet() ), true ) ) : ?>
				<div class="aml-card aml-theme-note">
					<p class="aml-card-h" style="margin:0 0 6px"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'AumCreate theme detected', 'aumlang' ); ?></p>
					<p class="aml-card-desc" style="margin:0"><?php esc_html_e( 'Your theme has a built-in header toolbar switcher. To use it: edit your header, select the AumCreate Toolbar widget, then under Language Switcher set Switch Mode to “AumLang”. (Then you can leave the options below off to avoid a duplicate switcher.)', 'aumlang' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'aumlang_switcher_save', 'aumlang_switcher_nonce' ); ?>

				<div class="aml-card">
					<h2 class="aml-card-h"><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> <?php esc_html_e( 'Display', 'aumlang' ); ?></h2>

					<div class="aml-field-grid">
						<div class="aml-field">
							<label for="aml-sw-show" class="aml-label"><?php esc_html_e( 'Label style', 'aumlang' ); ?></label>
							<select name="show" id="aml-sw-show">
								<option value="name" <?php selected( $settings['show'], 'name' ); ?>><?php esc_html_e( 'Language name (中文)', 'aumlang' ); ?></option>
								<option value="code" <?php selected( $settings['show'], 'code' ); ?>><?php esc_html_e( 'Code (ZH)', 'aumlang' ); ?></option>
								<option value="both" <?php selected( $settings['show'], 'both' ); ?>><?php esc_html_e( 'Name + code', 'aumlang' ); ?></option>
								<option value="flag" <?php selected( $settings['show'], 'flag' ); ?>><?php esc_html_e( 'Flag only', 'aumlang' ); ?></option>
								<option value="flag_name" <?php selected( $settings['show'], 'flag_name' ); ?>><?php esc_html_e( 'Flag + language name', 'aumlang' ); ?></option>
							</select>
						</div>
						<div class="aml-field">
							<label for="aml-sw-menu" class="aml-label"><?php esc_html_e( 'Add to menu location', 'aumlang' ); ?></label>
							<select name="menu_location" id="aml-sw-menu">
								<option value=""><?php esc_html_e( '— None —', 'aumlang' ); ?></option>
								<?php foreach ( $locations as $loc => $desc ) : ?>
									<option value="<?php echo esc_attr( $loc ); ?>" <?php selected( $settings['menu_location'], $loc ); ?>><?php echo esc_html( $desc ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="aml-field">
							<label for="aml-sw-label" class="aml-label"><?php esc_html_e( 'Menu parent label', 'aumlang' ); ?></label>
							<input type="text" name="menu_label" id="aml-sw-label" value="<?php echo esc_attr( $settings['menu_label'] ); ?>" placeholder="<?php esc_attr_e( 'Current language (e.g. 中文)', 'aumlang' ); ?>" />
						</div>
					</div>
					<p class="aml-card-desc"><?php esc_html_e( 'In a menu, AumLang adds one parent item with the languages as a dropdown submenu. Leave the label blank to use the current language as the parent (e.g. 中文 ▾), or type a fixed label like “Languages”.', 'aumlang' ); ?></p>

					<h3 class="aml-group-label"><?php esc_html_e( 'Floating switcher', 'aumlang' ); ?></h3>
					<label class="aml-switch-row aml-switch-block">
						<span class="aml-switch">
							<input type="checkbox" name="floating" value="1" <?php checked( ! empty( $settings['floating'] ) ); ?> />
							<span class="aml-track" aria-hidden="true"></span>
						</span>
						<span class="aml-switch-label"><?php esc_html_e( 'Show a floating switcher overlaid on every front-end page', 'aumlang' ); ?></span>
					</label>
					<div class="aml-field" style="max-width:260px;margin-top:10px">
						<label for="aml-sw-pos" class="aml-label"><?php esc_html_e( 'Floating position', 'aumlang' ); ?></label>
						<select name="position" id="aml-sw-pos">
							<?php
							$positions = array(
								'top-left'     => __( 'Top left', 'aumlang' ),
								'top-right'    => __( 'Top right', 'aumlang' ),
								'bottom-left'  => __( 'Bottom left', 'aumlang' ),
								'bottom-right' => __( 'Bottom right', 'aumlang' ),
							);
							foreach ( $positions as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<p class="aml-actions-bar" style="margin-top:16px">
						<button type="submit" class="aml-btn aml-btn-primary"><?php esc_html_e( 'Save settings', 'aumlang' ); ?></button>
					</p>
				</div>
			</form>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> <?php esc_html_e( 'Place it manually', 'aumlang' ); ?></h2>
				<p class="aml-card-desc"><?php esc_html_e( 'Prefer to place the switcher yourself (e.g. in an Elementor header, a sidebar, or a template)? Use any of these:', 'aumlang' ); ?></p>
				<ul class="aml-embed-list">
					<li><strong><?php esc_html_e( 'Shortcode', 'aumlang' ); ?></strong><code>[aumlang_language_switcher]</code></li>
					<li><strong><?php esc_html_e( 'Shortcode (options)', 'aumlang' ); ?></strong><code>[aumlang_language_switcher show="both" hide_current="1"]</code></li>
					<li><strong><?php esc_html_e( 'Block', 'aumlang' ); ?></strong><span><?php esc_html_e( 'Search for “Language Switcher” in the block inserter.', 'aumlang' ); ?></span></li>
					<li><strong><?php esc_html_e( 'Widget', 'aumlang' ); ?></strong><span><?php esc_html_e( '“AumLang Language Switcher” under Appearance → Widgets.', 'aumlang' ); ?></span></li>
					<li><strong><?php esc_html_e( 'Template tag', 'aumlang' ); ?></strong><code>&lt;?php aumlang_language_switcher(); ?&gt;</code></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
