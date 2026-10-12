<?php
/**
 * A list of (post, language) pairs waiting to be translated.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * The queue behind "translate these pages into this language".
 *
 * # 🔴 Why this exists
 *
 * Until now the only way to translate was the box in the editor: one post, one language, one click.
 * A site with 20 pages and 5 languages is **100 clicks across 20 page loads**, each waiting for the
 * rounds to finish. That is not a slow workflow, it is an impossible one, and it is why a real site
 * sat at 4 of 100 combinations translated while its owner reported the result as a bug. He was not
 * wrong about what he saw; he had simply been handed a job nobody finishes.
 *
 * # Why the queue is stored, not held in the browser
 *
 * A bulk run is minutes long. Anything held in a page can be closed, navigated away from, or put to
 * sleep by the laptop lid. The queue lives in an option, so whatever drives it — the progress screen
 * or cron — picks up where the last round stopped, and closing the tab costs nothing.
 *
 * ⚠️ **One queue per site, deliberately.** Two bulk runs at once would interleave rounds against the
 * same provider and the same rate limit, and the owner would have no way to tell which one was
 * spending. Starting a run while one is active is refused, not merged.
 *
 * # What is NOT stored here
 *
 * Nothing about money. The estimate shown before starting is computed fresh from the posts, because
 * a stale estimate is worse than none — the owner would approve a number that no longer describes
 * what is about to be spent.
 */
class BulkQueue {

	/**
	 * Where the queue lives.
	 */
	const OPTION = 'aumlang_bulk_queue';

	/**
	 * A run with no progress for this long is treated as abandoned and can be replaced.
	 *
	 * Without this a crashed round would lock bulk translation out of the site forever, and the only
	 * cure would be a developer deleting a row.
	 */
	const STALE_AFTER = 900;

	/**
	 * The current queue, or an empty shape when there is none.
	 *
	 * @return array
	 */
	public function get() {
		$q = get_option( self::OPTION, array() );

		return is_array( $q ) && isset( $q['items'] ) ? $q : $this->empty_queue();
	}

	/**
	 * An empty queue.
	 *
	 * @return array
	 */
	private function empty_queue() {
		return array(
			'items'     => array(),
			'cursor'    => 0,
			'lang'      => '',
			'started'   => 0,
			'touched'   => 0,
			'stopping'  => false,
			'results'   => array(),
		);
	}

	/**
	 * Whether a run is active (has work left and has not gone stale).
	 *
	 * @return bool
	 */
	public function is_running() {
		$q = $this->get();

		if ( $q['cursor'] >= count( $q['items'] ) ) {
			return false;
		}

		return ( time() - (int) $q['touched'] ) < self::STALE_AFTER;
	}

	/**
	 * Start a run.
	 *
	 * @param int[]  $post_ids Source post ids, in the order the owner selected them.
	 * @param string $lang     Target language code.
	 * @return bool False when a run is already active.
	 */
	public function start( array $post_ids, $lang ) {
		if ( $this->is_running() ) {
			return false;
		}

		$items = array();

		foreach ( $post_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$items[] = $id;
			}
		}

		update_option(
			self::OPTION,
			array(
				'items'    => array_values( array_unique( $items ) ),
				'cursor'   => 0,
				'lang'     => (string) $lang,
				'started'  => time(),
				'touched'  => time(),
				'stopping' => false,
				'results'  => array(),
			),
			false
		);

		return true;
	}

	/**
	 * The post this run is on, or 0 when it is finished.
	 *
	 * @return int
	 */
	public function current() {
		$q = $this->get();

		return isset( $q['items'][ $q['cursor'] ] ) ? (int) $q['items'][ $q['cursor'] ] : 0;
	}

	/**
	 * Keep the run marked alive without advancing it.
	 *
	 * Called after a round that did not finish its post, so a long post does not look abandoned.
	 *
	 * @return void
	 */
	public function touch() {
		$q            = $this->get();
		$q['touched'] = time();

		update_option( self::OPTION, $q, false );
	}

	/**
	 * Record the outcome for the current post and move to the next.
	 *
	 * @param string $outcome 'ok' | 'skipped' | 'failed'.
	 * @param string $detail  Short human-readable detail.
	 * @return void
	 */
	public function advance( $outcome, $detail = '' ) {
		$q  = $this->get();
		$id = $this->current();

		if ( $id ) {
			$q['results'][] = array(
				'id'      => $id,
				'title'   => get_the_title( $id ),
				'outcome' => (string) $outcome,
				'detail'  => (string) $detail,
			);
		}

		++$q['cursor'];
		$q['touched'] = time();

		update_option( self::OPTION, $q, false );
	}

	/**
	 * Ask the run to stop after the current post.
	 *
	 * 🔴 Not "stop now". A round that is mid-flight has already been paid for; cutting it off would
	 * throw away work the owner was charged for and could leave a post half-written. The flag is read
	 * between posts.
	 *
	 * @return void
	 */
	public function request_stop() {
		$q             = $this->get();
		$q['stopping'] = true;
		$q['touched']  = time();

		update_option( self::OPTION, $q, false );
	}

	/**
	 * Whether a stop has been requested.
	 *
	 * @return bool
	 */
	public function is_stopping() {
		$q = $this->get();

		return ! empty( $q['stopping'] );
	}

	/**
	 * Forget the queue entirely.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * A snapshot for the progress screen.
	 *
	 * @return array
	 */
	public function status() {
		$q     = $this->get();
		$total = count( $q['items'] );
		$done  = min( (int) $q['cursor'], $total );

		return array(
			'total'     => $total,
			'done'      => $done,
			'lang'      => (string) $q['lang'],
			'current'   => $this->current(),
			'running'   => $this->is_running(),
			'stopping'  => ! empty( $q['stopping'] ),
			'finished'  => $total > 0 && $done >= $total,
			'results'   => array_slice( (array) $q['results'], -50 ),
		);
	}
}
