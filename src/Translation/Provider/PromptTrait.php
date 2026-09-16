<?php
/**
 * Prompt construction and response parsing shared by every provider.
 *
 * Both live here rather than in each provider because they encode hard-won
 * behaviour -- the JSON repair below exists because models really do return
 * fenced code blocks, trailing commas and stray CJK punctuation. Two copies of
 * that would drift, and the copy that drifted would be the one that breaks a
 * customer's translation run.
 *
 * @package AumLang
 */

namespace AumLang\Translation\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Shared provider behaviour.
 */
trait PromptTrait {

	/**
	 * Build the instruction sent ahead of the strings.
	 *
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @param array  $options     Translation options.
	 * @return string
	 */
	protected function system_prompt( $source_lang, $target_lang, array $options ) {
		$prompt = sprintf(
			'You are a professional translation engine. Translate each string in the input JSON array from %1$s to %2$s. '
			. 'Preserve HTML tags, placeholders (such as %%s, %%1$s, {name}), shortcodes, and URLs exactly as they appear. '
			. 'Do not add comments or explanations. '
			. 'Return ONLY a JSON array of translated strings, in the same order and with the same number of items as the input. '
			// Echoing the input passes every structural check a caller can make — same count, same order,
			// valid JSON — so it has to be discouraged in the prompt as well as detected afterwards.
			. 'Every string must actually be translated: do not copy the input through unchanged unless it is a proper noun, a product code, or already in the target language.',
			$source_lang,
			$target_lang
		);

		if ( ! empty( $options['instructions'] ) ) {
			$prompt .= "\n" . $options['instructions'];
		}

		/**
		 * Filter extra per-language instructions appended to the prompt (e.g. the
		 * glossary). Applied for every translation, so all paths honour it.
		 *
		 * @param string $extra       Extra instruction text (default empty).
		 * @param string $target_lang Target language code.
		 * @param string $source_lang Source language code.
		 */
		$extra = (string) apply_filters( 'aumlang_translation_instructions', '', $target_lang, $source_lang );

		if ( '' !== trim( $extra ) ) {
			$prompt .= "\n" . $extra;
		}

		return $prompt;
	}

	/**
	 * Pull a JSON array of strings out of whatever the model actually returned.
	 *
	 * @param string $content Raw model output.
	 * @return array<int, string>
	 * @throws \RuntimeException When no array can be recovered.
	 */
	protected function parse_json_array( $content ) {
		$content = trim( $content );

		// Strip a leading/trailing markdown code fence if present.
		$content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );
		$content = trim( $content );

		// Models sometimes wrap the array in prose or insert stray punctuation.
		// Extract just the array literal, then repair common artifacts.
		$start = strpos( $content, '[' );
		$end   = strrpos( $content, ']' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$content = substr( $content, $start, $end - $start + 1 );
		}

		// Stray CJK/ASCII punctuation after a string element, before , or ] (e.g. "…"。]).
		$content = preg_replace( '/"\s*[。，、；,;]+\s*([,\]])/u', '"$1', $content );
		// Trailing comma before a closing bracket.
		$content = preg_replace( '/,\s*\]/', ']', $content );

		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( '%s response was not a JSON array: %s', $this->get_label(), $content ) )
			);
		}

		return array_map( 'strval', array_values( $decoded ) );
	}
}
