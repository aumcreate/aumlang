<?php
/**
 * Classic widget for the language switcher.
 *
 * @package AumLang
 */

namespace AumLang\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the language switcher in classic theme widget areas. Rendering is
 * delegated to the shared template function so all entry points stay identical.
 */
class SwitcherWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'aumlang_switcher',
			__( 'AumLang Language Switcher', 'aumlang' ),
			array( 'description' => __( 'Links to the current page in each language.', 'aumlang' ) )
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Widget area args.
	 * @param array $instance Saved settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$html = aumlang_get_language_switcher(
			array(
				'show'         => isset( $instance['show'] ) ? $instance['show'] : 'name',
				'hide_current' => ! empty( $instance['hide_current'] ),
			)
		);

		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Saved settings.
	 * @return string
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$show  = isset( $instance['show'] ) ? $instance['show'] : 'name';
		$hide  = ! empty( $instance['hide_current'] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'aumlang' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>"><?php esc_html_e( 'Display:', 'aumlang' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show' ) ); ?>">
				<option value="name" <?php selected( $show, 'name' ); ?>><?php esc_html_e( 'Name', 'aumlang' ); ?></option>
				<option value="code" <?php selected( $show, 'code' ); ?>><?php esc_html_e( 'Code', 'aumlang' ); ?></option>
				<option value="both" <?php selected( $show, 'both' ); ?>><?php esc_html_e( 'Name + code', 'aumlang' ); ?></option>
				<option value="flag" <?php selected( $show, 'flag' ); ?>><?php esc_html_e( 'Flag only', 'aumlang' ); ?></option>
				<option value="flag_name" <?php selected( $show, 'flag_name' ); ?>><?php esc_html_e( 'Flag + name', 'aumlang' ); ?></option>
			</select>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'hide_current' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'hide_current' ) ); ?>" value="1" <?php checked( $hide ); ?> />
			<label for="<?php echo esc_attr( $this->get_field_id( 'hide_current' ) ); ?>"><?php esc_html_e( 'Hide the current language', 'aumlang' ); ?></label>
		</p>
		<?php
		return '';
	}

	/**
	 * Save settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Previous values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'        => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'show'         => isset( $new_instance['show'] ) && in_array( $new_instance['show'], array( 'name', 'code', 'both', 'flag', 'flag_name' ), true ) ? $new_instance['show'] : 'name',
			'hide_current' => ! empty( $new_instance['hide_current'] ),
		);
	}
}
