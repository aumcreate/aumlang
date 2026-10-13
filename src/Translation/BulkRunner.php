<?php
/**
 * Drives a bulk queue one round at a time.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

use AumLang\Content\ContentLinker;
use AumLang\Language\LanguageRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a queue into translated posts, a round at a time.
 *
 * # Rounds, not posts
 *
 * {@see TranslationOrchestrator::translate_content()} already stops itself after
 * {@see TranslationOrchestrator::ROUND_SECONDS} and reports `done === false` when a post needs
 * another pass. This class does not change that — it calls the same method the editor's button
 * calls, and only decides **what to call it on next**. One round here is one HTTP request's worth of
 * work, which is what keeps a bulk run from being one enormous request that a gateway cuts off.
 *
 * # 🔴 A post that already has a translation is skipped, not re-translated
 *
 * The owner selected rows in a list; nothing in that gesture says "spend again on the ones that are
 * already done". Re-translating would be the expensive reading of an ambiguous instruction, and the
 * owner would discover it on the invoice. Re-translating a single post stays where it was: the
 * button in the editor, where the badge says what will happen before it happens.
 *
 * ⚠️ That includes posts whose translation is **outdated**. Skipping those is debatable — the owner
 * may well want them refreshed — but "it costs money" decides it: the quiet default is the one that
 * spends nothing, and the editor's **Update** button is one click away for a post that needs it.
 */
class BulkRunner {

	/**
	 * Queue.
	 *
	 * @var BulkQueue
	 */
	private $queue;

	/**
	 * Orchestrator.
	 *
	 * @var TranslationOrchestrator
	 */
	private $orchestrator;

	/**
	 * Source → translation links.
	 *
	 * @var ContentLinker
	 */
	private $linker;

	/**
	 * Languages.
	 *
	 * @var LanguageRegistry
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param BulkQueue               $queue        Queue.
	 * @param TranslationOrchestrator $orchestrator Orchestrator.
	 * @param ContentLinker           $linker       Linker.
	 * @param LanguageRegistry        $languages    Languages.
	 */
	public function __construct( BulkQueue $queue, TranslationOrchestrator $orchestrator, ContentLinker $linker, LanguageRegistry $languages ) {
		$this->queue        = $queue;
		$this->orchestrator = $orchestrator;
		$this->linker       = $linker;
		$this->languages    = $languages;
	}

	/**
	 * Register the cron fallback.
	 *
	 * The progress screen drives the run while it is open. Cron is what finishes the job when the
	 * owner closes the tab — which they will, because a hundred combinations is not something anyone
	 * watches to the end.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'aumlang_bulk_tick', array( $this, 'cron_tick' ) );
	}

	/**
	 * One round of work.
	 *
	 * @return array {
	 *     @type string $state   'running' | 'finished' | 'stopped' | 'idle'.
	 *     @type string $message Short detail for the screen.
	 * }
	 */
	public function run_round() {
		$status = $this->queue->status();

		if ( 0 === $status['total'] ) {
			return array( 'state' => 'idle', 'message' => '' );
		}

		if ( $status['finished'] ) {
			return array( 'state' => 'finished', 'message' => '' );
		}

		if ( $this->queue->is_stopping() ) {
			return array( 'state' => 'stopped', 'message' => '' );
		}

		$post_id = $this->queue->current();
		$lang    = $status['lang'];

		if ( ! $post_id || '' === $lang ) {
			$this->queue->advance( 'failed', __( 'Nothing to translate.', 'aumlang' ) );

			return array( 'state' => 'running', 'message' => '' );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->queue->advance( 'failed', __( 'The post no longer exists.', 'aumlang' ) );

			return array( 'state' => 'running', 'message' => '' );
		}

		/*
		 * 🔴 这篇本身就是一篇译文吗？
		 *
		 * 文章列表里**译文和原文混在一起**：一个站翻了三种语言，列表里就有四倍的行。
		 * 站长「全选」的时候选中的是**屏幕上所有的行**，他没有、也不该有办法分辨哪几行
		 * 是副本。2026-10-12 实测出图站：9 篇文章里 4 篇是译文，而 1.7.0 把其中三篇
		 * **日文文章算进了「要翻译成日文」** —— 花买家的钱做一件无意义的事，
		 * 而且产出会是一篇日译日的文章覆盖掉原来的。
		 *
		 * `get_source_id()` 对译文返回它的源，对原文返回它自己 —— 两者不等即为译文。
		 */
		if ( (int) $this->linker->get_source_id( $post_id ) !== $post_id ) {
			$this->queue->advance( 'skipped', __( 'This is itself a translation — left untouched.', 'aumlang' ) );

			return array( 'state' => 'running', 'message' => '' );
		}

		$existing = (int) $this->linker->get_translation( $post_id, $lang );

		if ( $existing && $existing !== $post_id ) {
			$this->queue->advance( 'skipped', __( 'Already translated — left untouched.', 'aumlang' ) );

			return array( 'state' => 'running', 'message' => '' );
		}

		try {
			$result = $this->orchestrator->translate_content( $post_id, $lang );
		} catch ( \Throwable $e ) {
			$this->queue->advance( 'failed', $e->getMessage() );

			return array( 'state' => 'running', 'message' => '' );
		}

		if ( ! $result->success ) {
			$why = trim( (string) $result->message );
			$this->queue->advance( 'failed', '' !== $why ? $why : __( 'The translation did not complete, and no reason was recorded.', 'aumlang' ) );

			return array( 'state' => 'running', 'message' => '' );
		}

		if ( ! $result->done ) {
			/* Same post, another round. Keep the run marked alive so it is not judged abandoned. */
			$this->queue->touch();

			return array( 'state' => 'running', 'message' => (string) $result->message );
		}

		$this->queue->advance( 'ok', (string) $result->message );

		return array( 'state' => 'running', 'message' => (string) $result->message );
	}

	/**
	 * Cron entry point: run a round, then schedule the next one if there is more to do.
	 *
	 * 🔴 Self-rescheduling single events, not a recurring schedule. A recurring event keeps firing
	 * after the queue is empty and has to be unscheduled from several places (finished, stopped,
	 * cleared, abandoned) — miss one and the site runs an empty job forever. A single event that only
	 * books the next one when there is work cannot outlive the work.
	 *
	 * @return void
	 */
	public function cron_tick() {
		$out = $this->run_round();

		if ( 'running' === $out['state'] ) {
			$this->schedule_next();
		}
	}

	/**
	 * Book the next cron round.
	 *
	 * @return void
	 */
	public function schedule_next() {
		if ( ! wp_next_scheduled( 'aumlang_bulk_tick' ) ) {
			wp_schedule_single_event( time() + 30, 'aumlang_bulk_tick' );
		}
	}

	/**
	 * Drop any booked round.
	 *
	 * @return void
	 */
	public function unschedule() {
		$next = wp_next_scheduled( 'aumlang_bulk_tick' );

		while ( $next ) {
			wp_unschedule_event( $next, 'aumlang_bulk_tick' );
			$next = wp_next_scheduled( 'aumlang_bulk_tick' );
		}
	}

	/**
	 * How many of these posts would actually be translated, and how many skipped.
	 *
	 * Computed fresh every time it is shown. A cached estimate would describe a site that no longer
	 * exists by the time the owner presses the button, and the whole point of showing it is that the
	 * owner is agreeing to spend that much.
	 *
	 * @param int[]  $post_ids Candidate posts.
	 * @param string $lang     Target language.
	 * @return array {
	 *     @type int $translate Posts that would be sent to the provider.
	 *     @type int $skip      Posts that already have a translation.
	 * }
	 */
	public function estimate( array $post_ids, $lang ) {
		$translate = 0;
		$skip      = 0;

		foreach ( $post_ids as $id ) {
			$id = (int) $id;

			if ( $id <= 0 ) {
				continue;
			}

			/* 和 run_round() 同一条规则 —— 否则确认屏印的数字和实际会做的事对不上，
			   而那个数字正是站主据以同意花钱的东西。 */
			if ( (int) $this->linker->get_source_id( $id ) !== $id ) {
				++$skip;
				continue;
			}

			$existing = (int) $this->linker->get_translation( $id, $lang );

			if ( $existing && $existing !== $id ) {
				++$skip;
			} else {
				++$translate;
			}
		}

		return array( 'translate' => $translate, 'skip' => $skip );
	}
}
