<?php
/**
 * Adds a "Translations" column with one-click translate to term list tables.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

use AumLang\Language\LanguageRegistry;
use AumLang\Taxonomy\TermLinker;
use AumLang\Taxonomy\TermTranslator;

defined( 'ABSPATH' ) || exit;

/**
 * On each public taxonomy's term list table, shows per-language translation
 * state for a source term (with a one-click AJAX translate button) or which
 * original a translated term came from. Mirrors the post AdminColumns + MetaBox.
 */
class TermAdminColumns {

	const COLUMN = 'aumlang';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Term linker.
	 *
	 * @var TermLinker
	 */
	private $linker;

	/**
	 * Term translator.
	 *
	 * @var TermTranslator
	 */
	private $translator;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages  Language registry.
	 * @param TermLinker       $linker     Term linker.
	 * @param TermTranslator   $translator Term translator.
	 */
	public function __construct( LanguageRegistry $languages, TermLinker $linker, TermTranslator $translator ) {
		$this->languages  = $languages;
		$this->linker     = $linker;
		$this->translator = $translator;
	}

	/**
	 * Register WordPress hooks for each translatable taxonomy.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( $this->taxonomies() as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_column' ), 10, 3 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_aumlang_translate_term', array( $this, 'ajax_translate' ) );
	}

	/**
	 * Public, translatable taxonomies (skips post_format).
	 *
	 * @return string[]
	 */
	private function taxonomies() {
		$out = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}

			$out[] = $taxonomy->name;
		}

		return (array) apply_filters( 'aumlang_translatable_taxonomies', $out );
	}

	/**
	 * Enqueue assets on a relevant term list table.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'edit-tags.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->taxonomy, $this->taxonomies(), true ) ) {
			return;
		}

		wp_enqueue_style( 'aumlang-admin', AUMLANG_URL . 'src/Admin/assets/css/admin.css', array(), AUMLANG_VERSION );
		wp_enqueue_script( 'aumlang-term-columns', AUMLANG_URL . 'src/Admin/assets/js/term-columns.js', array( 'jquery' ), AUMLANG_VERSION, true );
		wp_localize_script(
			'aumlang-term-columns',
			'AumLangTerms',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'aumlang_terms' ),
				'translating' => __( 'Translating…', 'aumlang' ),
				'failed'      => __( 'Failed', 'aumlang' ),
			)
		);
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
	 * Render the column for one term row (taxonomy columns are filters).
	 *
	 * @param string $content Current cell content.
	 * @param string $column  Column id.
	 * @param int    $term_id Term id.
	 * @return string
	 */
	public function render_column( $content, $column, $term_id ) {
		if ( self::COLUMN !== $column ) {
			return $content;
		}

		$source_term = (int) get_term_meta( $term_id, '_aumlang_source_term', true );

		if ( $source_term ) {
			return $this->translation_cell( $source_term, $term_id );
		}

		return $this->source_cell( (int) $term_id );
	}

	/**
	 * Chips (translated link or one-click translate button) for a source term.
	 *
	 * @param int $term_id Source term id.
	 * @return string
	 */
	private function source_cell( $term_id ) {
		$taxonomy = $this->term_taxonomy( $term_id );
		$default  = $this->languages->get_default_language();
		$out      = '<div class="aumlang-col-chips">';

		foreach ( $this->languages->get_languages( true ) as $language ) {
			if ( $default && $language->code() === $default->code() ) {
				continue;
			}

			$code   = $language->code();
			$status = $this->linker->get_status( $term_id, $code );
			$target = $this->linker->get_translation( $term_id, $code );
			$label  = $this->status_label( $status );
			$chip   = strtoupper( $code );

			if ( $target ) {
				$out .= sprintf(
					'<a class="aumlang-chip aumlang-chip-%1$s" href="%2$s" title="%3$s">%4$s</a>',
					esc_attr( $status ),
					esc_url( (string) get_edit_term_link( (int) $target, $taxonomy ) ),
					esc_attr( $label ),
					esc_html( $chip )
				);
			} else {
				$out .= sprintf(
					'<button type="button" class="aumlang-chip aumlang-chip-none aumlang-term-translate" data-term="%1$d" data-tax="%2$s" data-lang="%3$s" title="%4$s">%5$s</button>',
					(int) $term_id,
					esc_attr( $taxonomy ),
					esc_attr( $code ),
					esc_attr__( 'Translate', 'aumlang' ),
					esc_html( $chip )
				);
			}
		}

		return $out . '</div>';
	}

	/**
	 * "Translation of X" note for a translated term.
	 *
	 * @param int $source_term_id Source term id.
	 * @param int $term_id        Translated term id.
	 * @return string
	 */
	private function translation_cell( $source_term_id, $term_id ) {
		$lang     = strtoupper( (string) get_term_meta( $term_id, '_aumlang_language', true ) );
		$taxonomy = $this->term_taxonomy( $term_id );
		$source   = get_term( $source_term_id, $taxonomy );

		$out = '<span class="aumlang-col-source"><span class="dashicons dashicons-translation" aria-hidden="true"></span> ';

		if ( $source instanceof \WP_Term ) {
			$out .= sprintf(
				/* translators: 1: language code, 2: link to the original. */
				esc_html__( '%1$s translation of %2$s', 'aumlang' ),
				esc_html( $lang ),
				'<a href="' . esc_url( (string) get_edit_term_link( $source_term_id, $taxonomy ) ) . '">' . esc_html( $source->name ) . '</a>'
			);
		} else {
			$out .= esc_html( $lang );
		}

		return $out . '</span>';
	}

	/**
	 * AJAX: translate a source term into a language.
	 *
	 * @return void
	 */
	public function ajax_translate() {
		check_ajax_referer( 'aumlang_terms', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aumlang' ) ) );
		}

		$term     = isset( $_POST['term'] ) ? absint( wp_unslash( $_POST['term'] ) ) : 0;
		$taxonomy = isset( $_POST['tax'] ) ? sanitize_key( wp_unslash( $_POST['tax'] ) ) : '';
		$lang     = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';

		if ( ! $term || '' === $lang || ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'aumlang' ) ) );
		}

		$target = $this->translator->translate_term( $term, $taxonomy, $lang );

		if ( ! $target ) {
			wp_send_json_error( array( 'message' => __( 'Translation failed — check the provider settings.', 'aumlang' ) ) );
		}

		$status = $this->linker->get_status( $term, $lang );

		wp_send_json_success(
			array(
				'status'   => $status,
				'label'    => $this->status_label( $status ),
				'edit_url' => esc_url_raw( (string) get_edit_term_link( (int) $target, $taxonomy ) ),
			)
		);
	}

	/**
	 * Resolve a term's taxonomy.
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	private function term_taxonomy( $term_id ) {
		$term = get_term( $term_id );

		return $term instanceof \WP_Term ? $term->taxonomy : '';
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
				return __( 'Not translated — click to translate', 'aumlang' );
		}
	}
}
