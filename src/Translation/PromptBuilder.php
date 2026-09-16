<?php
/**
 * Injects glossary term mappings into the translation prompt.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

use AumLang\Translation\Glossary\GlossaryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the `aumlang_translation_instructions` filter (applied by providers when
 * they build the prompt) and appends the glossary as strict term-mapping
 * instructions for the target language. Because the provider applies the filter
 * for every translation, content, term and string translations all honour the
 * glossary with no per-caller wiring.
 */
class PromptBuilder {

	/**
	 * Glossary repository.
	 *
	 * @var GlossaryRepository
	 */
	private $glossary;

	/**
	 * Constructor.
	 *
	 * @param GlossaryRepository $glossary Glossary repository.
	 */
	public function __construct( GlossaryRepository $glossary ) {
		$this->glossary = $glossary;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'aumlang_translation_instructions', array( $this, 'glossary_instructions' ), 10, 2 );
	}

	/**
	 * Append glossary mappings to the provider instructions for a language.
	 *
	 * @param string $instructions Existing instructions.
	 * @param string $target_lang  Target language code.
	 * @return string
	 */
	public function glossary_instructions( $instructions, $target_lang ) {
		$entries = $this->glossary->get_for_language( $target_lang );

		if ( empty( $entries ) ) {
			return $instructions;
		}

		$lines = array();

		foreach ( $entries as $entry ) {
			$source = trim( (string) $entry['source_term'] );
			$target = trim( (string) $entry['target_term'] );

			if ( '' === $source || '' === $target ) {
				continue;
			}

			$line = sprintf( '- "%1$s" => "%2$s"', $source, $target );

			if ( ! empty( $entry['case_sensitive'] ) ) {
				$line .= ' (case-sensitive)';
			}

			$lines[] = $line;
		}

		if ( empty( $lines ) ) {
			return $instructions;
		}

		$glossary = "Apply this glossary strictly: whenever a source term on the left appears, translate it as exactly the term on the right (adapt only surrounding grammar, never the mapped term itself):\n"
			. implode( "\n", $lines );

		return '' === trim( (string) $instructions ) ? $glossary : $instructions . "\n" . $glossary;
	}
}
