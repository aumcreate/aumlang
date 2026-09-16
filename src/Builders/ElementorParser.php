<?php
/**
 * Parser for Elementor content (_elementor_data JSON).
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Translates Elementor pages automatically by reading Elementor's OWN control
 * types: only TEXT / TEXTAREA / WYSIWYG controls (and the text controls inside
 * REPEATER controls) are extracted — every other control type (color, select,
 * slider, URL, media, dimensions, etc.) is left untouched. This means any
 * widget, custom or standard, is handled with zero per-widget configuration,
 * while non-text settings can never be corrupted.
 *
 * Node paths use the stable Elementor element id (and repeater row _id), so they
 * survive reordering. A hardcoded fallback covers the case where Elementor's
 * control metadata is unavailable (e.g. some CLI contexts).
 */
class ElementorParser implements BuilderParserInterface, MetaContentParser {

	/**
	 * HTML text helper (for WYSIWYG / rich-text fields).
	 *
	 * @var HtmlTextExtractor
	 */
	private $html;

	/**
	 * Text-type controls that nonetheless hold non-translatable values.
	 *
	 * @var string[]
	 */
	private $skip_controls = array( '_element_id', 'css_classes', '_css_classes', 'css_id', '_css_id' );

	/**
	 * Resolved field specs per widget type (static request cache).
	 *
	 * @var array<string, array>
	 */
	private $spec_cache = array();

	/**
	 * Constructor.
	 *
	 * @param HtmlTextExtractor $html HTML text helper.
	 */
	public function __construct( HtmlTextExtractor $html ) {
		$this->html = $html;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true )
			&& '' !== (string) get_post_meta( $post_id, '_elementor_data', true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract( $post_id ) {
		$elements = $this->load_data( $post_id );

		if ( empty( $elements ) ) {
			return array();
		}

		$nodes = array();
		$this->collect( $elements, $nodes );

		return $nodes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Elementor stores content in meta, so post_content is copied as-is; the
	 * translated data is written by persist_meta().
	 */
	public function rebuild( $post_id, array $translations ) {
		$post = get_post( $post_id );

		return $post ? (string) $post->post_content : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function persist_meta( $source_id, $target_id, array $translations ) {
		$elements = $this->load_data( $source_id );

		if ( empty( $elements ) ) {
			return;
		}

		$this->apply( $elements, $translations );

		update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $target_id, '_elementor_edit_mode', 'builder' );

		/*
		 * The WordPress page template is separate from Elementor's document data.
		 * Theme-specific templates (including Woodmart layouts) often determine
		 * whether the_content() is rendered at all. Without this meta the target
		 * falls back to the theme's default post/page template, which can make an
		 * otherwise valid Elementor document appear as a normal article.
		 */
		$page_template = get_post_meta( $source_id, '_wp_page_template', true );
		update_post_meta( $target_id, '_wp_page_template', '' !== $page_template ? $page_template : 'default' );

		foreach ( array( '_elementor_template_type', '_elementor_version', '_elementor_page_settings', '_elementor_controls_usage' ) as $meta_key ) {
			$value = get_post_meta( $source_id, $meta_key, true );

			if ( '' !== $value && null !== $value ) {
				update_post_meta( $target_id, $meta_key, $value );
			}
		}

		/*
		 * Woodmart stores its per-page title and breadcrumb visibility outside
		 * Elementor. Without these values a translated page inherits Woodmart's
		 * global title area even when the source page has disabled it.
		 */
		foreach ( array( '_woodmart_title_off', '_woodmart_title_image', '_woodmart_title_color', '_woodmart_title_bg_color' ) as $meta_key ) {
			$value = get_post_meta( $source_id, $meta_key, true );

			if ( '' !== $value && null !== $value ) {
				update_post_meta( $target_id, $meta_key, $value );
			} else {
				delete_post_meta( $target_id, $meta_key );
			}
		}

		delete_post_meta( $target_id, '_elementor_css' );

		// Clear Elementor caches so the translated page renders fresh at once
		// (covers CSS regeneration and the element-cache experiment).
		if ( \Elementor\Plugin::instance()->files_manager ) {
			\Elementor\Plugin::instance()->files_manager->clear_cache();
		}
	}

	/**
	 * Decode a post's Elementor data into an element array.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function load_data( $post_id ) {
		$data = get_post_meta( $post_id, '_elementor_data', true );

		if ( empty( $data ) ) {
			return array();
		}

		$elements = is_string( $data ) ? json_decode( $data, true ) : $data;

		return is_array( $elements ) ? $elements : array();
	}

	/**
	 * Recursively collect translatable nodes from elements.
	 *
	 * @param array $elements Element list.
	 * @param array $nodes    Accumulator (by reference).
	 * @return void
	 */
	private function collect( $elements, array &$nodes ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$this->collect_widget( $element, $nodes );
			}

			if ( ! empty( $element['elements'] ) ) {
				$this->collect( $element['elements'], $nodes );
			}
		}
	}

	/**
	 * Collect translatable nodes from one widget using its field spec.
	 *
	 * @param array $element Widget element.
	 * @param array $nodes   Accumulator (by reference).
	 * @return void
	 */
	private function collect_widget( $element, array &$nodes ) {
		$id       = isset( $element['id'] ) ? (string) $element['id'] : '';
		$type     = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		if ( '' === $id ) {
			return;
		}

		foreach ( $this->field_spec( $type ) as $field => $spec ) {
			if ( $this->is_repeater( $spec ) ) {
				$this->collect_repeater( $id, $field, $spec['repeater'], $settings, $type, $nodes );
				continue;
			}

			if ( empty( $settings[ $field ] ) || ! is_string( $settings[ $field ] ) ) {
				continue;
			}

			$this->collect_value( $id . ':' . $field, $spec, $settings[ $field ], $type . '/' . $field, $nodes );
		}
	}

	/**
	 * Collect nodes from a repeater control's rows.
	 *
	 * @param string $id        Widget id.
	 * @param string $field     Repeater control name.
	 * @param array  $subfields Map of sub-field => kind.
	 * @param array  $settings  Widget settings.
	 * @param string $type      Widget type (context).
	 * @param array  $nodes     Accumulator (by reference).
	 * @return void
	 */
	private function collect_repeater( $id, $field, array $subfields, $settings, $type, array &$nodes ) {
		if ( empty( $settings[ $field ] ) || ! is_array( $settings[ $field ] ) ) {
			return;
		}

		foreach ( $settings[ $field ] as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row_id = isset( $row['_id'] ) ? (string) $row['_id'] : (string) $row_index;

			foreach ( $subfields as $sub => $kind ) {
				if ( empty( $row[ $sub ] ) || ! is_string( $row[ $sub ] ) ) {
					continue;
				}

				$this->collect_value(
					$id . ':' . $field . ':' . $row_id . ':' . $sub,
					$kind,
					$row[ $sub ],
					$type . '/' . $field . '/' . $sub,
					$nodes
				);
			}
		}
	}

	/**
	 * Collect node(s) for a single value (text = one node, html = text nodes).
	 *
	 * @param string $path_base Node path base.
	 * @param string $kind      'text' or 'html'.
	 * @param string $value     Value.
	 * @param string $context   Context hint.
	 * @param array  $nodes     Accumulator (by reference).
	 * @return void
	 */
	private function collect_value( $path_base, $kind, $value, $context, array &$nodes ) {
		if ( 'html' === $kind ) {
			foreach ( $this->html->extract( $value ) as $text_index => $text ) {
				$nodes[] = new TranslatableNode( $path_base . ':' . $text_index, $text, $context );
			}

			return;
		}

		$nodes[] = new TranslatableNode( $path_base, $value, $context );
	}

	/**
	 * Recursively apply translations back into the elements.
	 *
	 * @param array $elements     Element list (by reference).
	 * @param array $translations Translations.
	 * @return void
	 */
	private function apply( array &$elements, array $translations ) {
		foreach ( $elements as &$element ) {
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$this->apply_widget( $element, $translations );
			}

			if ( ! empty( $element['elements'] ) ) {
				$this->apply( $element['elements'], $translations );
			}
		}

		unset( $element );
	}

	/**
	 * Apply translations to one widget using its field spec.
	 *
	 * @param array $element      Widget element (by reference).
	 * @param array $translations Translations.
	 * @return void
	 */
	private function apply_widget( array &$element, array $translations ) {
		$id   = isset( $element['id'] ) ? (string) $element['id'] : '';
		$type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

		if ( '' === $id ) {
			return;
		}

		foreach ( $this->field_spec( $type ) as $field => $spec ) {
			if ( $this->is_repeater( $spec ) ) {
				$this->apply_repeater( $id, $field, $spec['repeater'], $element, $translations );
				continue;
			}

			if ( ! isset( $element['settings'][ $field ] ) || ! is_string( $element['settings'][ $field ] ) ) {
				continue;
			}

			$element['settings'][ $field ] = $this->apply_value( $id . ':' . $field, $spec, $element['settings'][ $field ], $translations );
		}
	}

	/**
	 * Apply translations to a repeater control's rows.
	 *
	 * @param string $id           Widget id.
	 * @param string $field        Repeater control name.
	 * @param array  $subfields    Map of sub-field => kind.
	 * @param array  $element      Widget element (by reference).
	 * @param array  $translations Translations.
	 * @return void
	 */
	private function apply_repeater( $id, $field, array $subfields, array &$element, array $translations ) {
		if ( ! isset( $element['settings'][ $field ] ) || ! is_array( $element['settings'][ $field ] ) ) {
			return;
		}

		foreach ( $element['settings'][ $field ] as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row_id = isset( $row['_id'] ) ? (string) $row['_id'] : (string) $row_index;

			foreach ( $subfields as $sub => $kind ) {
				if ( ! isset( $row[ $sub ] ) || ! is_string( $row[ $sub ] ) ) {
					continue;
				}

				$element['settings'][ $field ][ $row_index ][ $sub ] = $this->apply_value(
					$id . ':' . $field . ':' . $row_id . ':' . $sub,
					$kind,
					$row[ $sub ],
					$translations
				);
			}
		}
	}

	/**
	 * Apply translation(s) to a single value.
	 *
	 * @param string $path_base    Node path base.
	 * @param string $kind         'text' or 'html'.
	 * @param string $value        Current value.
	 * @param array  $translations Translations.
	 * @return string
	 */
	private function apply_value( $path_base, $kind, $value, array $translations ) {
		if ( 'html' === $kind ) {
			$prefix = $path_base . ':';
			$map    = array();

			foreach ( $translations as $key => $translation ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$map[ (int) substr( $key, strlen( $prefix ) ) ] = $translation;
				}
			}

			return ! empty( $map ) ? $this->html->rebuild( $value, $map ) : $value;
		}

		return isset( $translations[ $path_base ] ) ? $translations[ $path_base ] : $value;
	}

	/**
	 * Whether a field spec describes a repeater.
	 *
	 * @param mixed $spec Field spec.
	 * @return bool
	 */
	private function is_repeater( $spec ) {
		return is_array( $spec ) && isset( $spec['repeater'] ) && is_array( $spec['repeater'] );
	}

	/**
	 * Resolve the translatable-field spec for a widget type.
	 *
	 * Primary source is Elementor's own control metadata (fully automatic). A
	 * hardcoded fallback covers contexts where that metadata is unavailable. The
	 * result can be refined via the aumlang_elementor_fields filter.
	 *
	 * @param string $widget_type Widget type.
	 * @return array<string, mixed> field name => 'text'|'html'|['repeater'=>[...]].
	 */
	private function field_spec( $widget_type ) {
		if ( isset( $this->spec_cache[ $widget_type ] ) ) {
			return $this->spec_cache[ $widget_type ];
		}

		$spec = $this->introspect_spec( $widget_type );

		if ( null === $spec ) {
			$spec = $this->fallback_spec( $widget_type );
		}

		/**
		 * Filter the translatable fields for an Elementor widget. Rarely needed —
		 * fields are detected automatically from Elementor control types — but
		 * available to force-include or force-exclude specific controls.
		 *
		 * @param array  $spec        field => 'text'|'html'|['repeater'=>[...]].
		 * @param string $widget_type Widget type.
		 */
		$spec = (array) apply_filters( 'aumlang_elementor_fields', $spec, $widget_type );

		$this->spec_cache[ $widget_type ] = $spec;

		return $spec;
	}

	/**
	 * Build a field spec from Elementor's control definitions for a widget type.
	 *
	 * @param string $widget_type Widget type.
	 * @return array|null Null when Elementor metadata is unavailable.
	 */
	private function introspect_spec( $widget_type ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$manager = \Elementor\Plugin::instance()->widgets_manager;

		if ( ! $manager ) {
			return null;
		}

		$widget = $manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			return null;
		}

		$spec = array();

		foreach ( $widget->get_controls() as $name => $control ) {
			if ( ! is_array( $control ) || $this->should_skip_control( $name ) ) {
				continue;
			}

			$type = isset( $control['type'] ) ? $control['type'] : '';

			if ( 'repeater' === $type ) {
				$sub = $this->repeater_subfields( isset( $control['fields'] ) ? (array) $control['fields'] : array() );

				if ( ! empty( $sub ) ) {
					$spec[ $name ] = array( 'repeater' => $sub );
				}

				continue;
			}

			$kind = $this->kind_for_control_type( $type );

			if ( $kind ) {
				$spec[ $name ] = $kind;
			}
		}

		return $spec;
	}

	/**
	 * Map a repeater control's fields to translatable sub-fields.
	 *
	 * @param array $fields Repeater field configs.
	 * @return array<string, string> sub-field => kind.
	 */
	private function repeater_subfields( array $fields ) {
		$sub = array();

		foreach ( $fields as $field_name => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$name = isset( $config['name'] ) ? $config['name'] : $field_name;

			if ( $this->should_skip_control( $name ) ) {
				continue;
			}

			$kind = $this->kind_for_control_type( isset( $config['type'] ) ? $config['type'] : '' );

			if ( $kind ) {
				$sub[ $name ] = $kind;
			}
		}

		return $sub;
	}

	/**
	 * Whether a control should be skipped despite being a text-type control,
	 * because its name marks it as holding non-text (CSS, IDs, URLs, formats…).
	 *
	 * @param string $name Control name.
	 * @return bool
	 */
	private function should_skip_control( $name ) {
		if ( in_array( $name, $this->skip_controls, true ) ) {
			return true;
		}

		$lower = strtolower( (string) $name );

		foreach ( array( 'css', 'video', 'background', 'icon' ) as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}

		foreach ( array( '_id', '_link', '_url', '_format', '_color', '_size', '_key' ) as $suffix ) {
			if ( substr( $lower, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Translatable kind for an Elementor control type, or null if not text.
	 *
	 * @param string $control_type Elementor control type.
	 * @return string|null 'text', 'html', or null.
	 */
	private function kind_for_control_type( $control_type ) {
		if ( 'text' === $control_type || 'textarea' === $control_type ) {
			return 'text';
		}

		if ( 'wysiwyg' === $control_type ) {
			return 'html';
		}

		return null;
	}

	/**
	 * Hardcoded spec for common widgets, used when control metadata is absent.
	 *
	 * @param string $widget_type Widget type.
	 * @return array
	 */
	private function fallback_spec( $widget_type ) {
		$map = array(
			'heading'        => array( 'title' => 'text' ),
			'text-editor'    => array( 'editor' => 'html' ),
			'button'         => array( 'text' => 'text' ),
			'icon-box'       => array( 'title_text' => 'text', 'description_text' => 'html' ),
			'image-box'      => array( 'title_text' => 'text', 'description_text' => 'html' ),
			'testimonial'    => array( 'testimonial_content' => 'html', 'testimonial_name' => 'text', 'testimonial_job' => 'text' ),
			'call-to-action' => array( 'title' => 'text', 'description' => 'text', 'button' => 'text' ),
			'alert'          => array( 'alert_title' => 'text', 'alert_description' => 'html' ),
			'accordion'      => array( 'tabs' => array( 'repeater' => array( 'tab_title' => 'text', 'tab_content' => 'html' ) ) ),
			'toggle'         => array( 'tabs' => array( 'repeater' => array( 'tab_title' => 'text', 'tab_content' => 'html' ) ) ),
			'tabs'           => array( 'tabs' => array( 'repeater' => array( 'tab_title' => 'text', 'tab_content' => 'html' ) ) ),
			'icon-list'      => array( 'icon_list' => array( 'repeater' => array( 'text' => 'text' ) ) ),
		);

		return isset( $map[ $widget_type ] ) ? $map[ $widget_type ] : array();
	}
}
