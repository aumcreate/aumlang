<?php
/**
 * Selects the right parser for a piece of content.
 *
 * @package AumLang
 */

namespace AumLang\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Holds parsers ordered by priority (lower runs first) and picks the first one
 * that supports a given post. The classic parser registers last as a fallback.
 */
class ParserRegistry {

	/**
	 * Parsers as a list of array{ priority:int, parser:BuilderParserInterface }.
	 *
	 * @var array[]
	 */
	private $parsers = array();

	/**
	 * Whether the list is currently sorted by priority.
	 *
	 * @var bool
	 */
	private $sorted = true;

	/**
	 * Register a parser.
	 *
	 * @param BuilderParserInterface $parser   Parser.
	 * @param int                    $priority Lower runs first.
	 * @return void
	 */
	public function register( BuilderParserInterface $parser, $priority = 10 ) {
		$this->parsers[] = array(
			'priority' => (int) $priority,
			'parser'   => $parser,
		);
		$this->sorted    = false;
	}

	/**
	 * Get a parser by id.
	 *
	 * @param string $id Parser id.
	 * @return BuilderParserInterface|null
	 */
	public function get( $id ) {
		foreach ( $this->parsers as $entry ) {
			if ( $entry['parser']->get_id() === $id ) {
				return $entry['parser'];
			}
		}

		return null;
	}

	/**
	 * Pick the first parser that supports the post.
	 *
	 * @param int $post_id Post id.
	 * @return BuilderParserInterface|null
	 */
	public function get_parser_for( $post_id ) {
		$this->sort();

		foreach ( $this->parsers as $entry ) {
			/*
			 * A third-party parser (see the `aumlang_register_parsers` action) is other people's code
			 * running inside our loop. Left unguarded, one parser throwing on one post takes down the
			 * whole translation screen for every post — including the ones our own parsers handle fine.
			 * Skip the broken one and carry on; the classic fallback at the end still catches the post.
			 */
			try {
				if ( $entry['parser']->supports( $post_id ) ) {
					return $entry['parser'];
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Silence is worse than a log line nobody reads: without this the symptom is
					// "that builder's pages just never translate" and nothing says why.
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						sprintf(
							'AumLang: parser "%s" threw in supports() for post %d — skipped. %s',
							method_exists( $entry['parser'], 'get_id' ) ? (string) $entry['parser']->get_id() : get_class( $entry['parser'] ),
							(int) $post_id,
							$e->getMessage()
						)
					);
				}
				continue;
			}
		}

		return null;
	}

	/**
	 * Sort parsers by ascending priority, once.
	 *
	 * @return void
	 */
	private function sort() {
		if ( $this->sorted ) {
			return;
		}

		usort(
			$this->parsers,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		$this->sorted = true;
	}
}
