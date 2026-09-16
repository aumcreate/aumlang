<?php
/**
 * Adds a "Translations" column to post/page list tables.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Content\ContentLinker;
use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Shows, per row, the translation state of each language (for a source) or which
 * original a row translates (for a translation), with quick edit links.
 */
class AdminColumns {

	const COLUMN = 'aumlang';

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
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Language registry.
	 * @param ContentLinker    $linker    Content linker.
	 */
	public function __construct( LanguageRegistry $languages, ContentLinker $linker ) {
		$this->languages = $languages;
		$this->linker    = $linker;
	}

	/**
	 * Register WordPress hooks for each translatable post type.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( $this->post_types() as $type ) {
			if ( 'page' === $type ) {
				add_filter( 'manage_pages_columns', array( $this, 'add_column' ) );
				add_action( 'manage_pages_custom_column', array( $this, 'render_column' ), 10, 2 );
			} else {
				add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
				add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			}
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Translatable post types (shared definition with the meta box).
	 *
	 * @return string[]
	 */
	private function post_types() {
		return (array) apply_filters( 'aumlang_translatable_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Enqueue the admin styles on the relevant list tables.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'aumlang-admin', AUMLANG_URL . 'src/Admin/assets/css/admin.css', array(), AUMLANG_VERSION );
	}

	/**
	 * Add the Translations column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Translations', 'aumlang' );

		return $columns;
	}

	/**
	 * Render the column for one row.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Row post id.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$source_id = get_post_meta( $post_id, '_aumlang_source_id', true );

		if ( $source_id ) {
			$this->render_translation_cell( (int) $source_id, $post_id );
			return;
		}

		$this->render_source_cell( (int) $post_id );
	}

	/**
	 * Render language chips for a source row.
	 *
	 * @param int $post_id Source post id.
	 * @return void
	 */
	private function render_source_cell( $post_id ) {
		$default = $this->languages->get_default_language();

		echo '<div class="aumlang-col-chips">';

		foreach ( $this->languages->get_languages( true ) as $language ) {
			if ( $default && $language->code() === $default->code() ) {
				continue;
			}

			$code   = $language->code();
			$status = $this->linker->get_status( $post_id, $code );
			$target = $this->linker->get_translation( $post_id, $code );
			$label  = $this->status_label( $status );
			$chip   = strtoupper( $code );

			if ( $target ) {
				printf(
					'<a class="aumlang-chip aumlang-chip-%1$s" href="%2$s" title="%3$s">%4$s</a>',
					esc_attr( $status ),
					esc_url( (string) get_edit_post_link( $target ) ),
					esc_attr( $label ),
					esc_html( $chip )
				);
			} else {
				printf(
					'<span class="aumlang-chip aumlang-chip-none" title="%1$s">%2$s</span>',
					esc_attr( $label ),
					esc_html( $chip )
				);
			}
		}

		echo '</div>';
	}

	/**
	 * Render the "translation of" note for a translation row.
	 *
	 * @param int $source_id Source post id.
	 * @param int $post_id   Translation post id.
	 * @return void
	 */
	private function render_translation_cell( $source_id, $post_id ) {
		$lang   = strtoupper( (string) get_post_meta( $post_id, '_aumlang_language', true ) );
		$source = get_post( $source_id );

		echo '<span class="aumlang-col-source"><span class="dashicons dashicons-translation" aria-hidden="true"></span> ';

		if ( $source ) {
			printf(
				/* translators: 1: language code, 2: link to the original. */
				esc_html__( '%1$s translation of %2$s', 'aumlang' ),
				esc_html( $lang ),
				'<a href="' . esc_url( (string) get_edit_post_link( $source_id ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		} else {
			echo esc_html( $lang );
		}

		echo '</span>';
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
		switch ( $status ) {
			case 'machine':
				return __( 'Machine translated', 'aumlang' );
			case 'reviewed':
				return __( 'Reviewed', 'aumlang' );
			case 'stale':
				return __( 'Outdated — source changed', 'aumlang' );
			default:
				return __( 'Not translated', 'aumlang' );
		}
	}
}
