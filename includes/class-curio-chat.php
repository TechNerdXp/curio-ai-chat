<?php
/**
 * Request orchestration.
 *
 * @package Curio
 */

namespace Curio;

use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * One question in, one answer out, with every guard rail in between.
 *
 * The order of the checks below is the design. Throttles and the spending cap
 * come first, because the cheapest request is the one never made. The cache
 * comes next, because the second cheapest is the one already answered.
 * Retrieval comes before the model, because a question with no matching
 * knowledge is declined locally and never costs a call at all, unless the
 * owner has chosen to let general questions about their industry through.
 */
final class Chat {

	/**
	 * Where the provider's reason for the last failed reply is kept.
	 */
	public const FAILURE_OPTION = 'curio_last_failure';

	/**
	 * Answer a question.
	 *
	 * @param string                          $question Visitor's question.
	 * @param array<int,array<string,string>> $history  Prior turns, oldest first.
	 * @param string                          $page_url Page the widget is on.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function answer( string $question, array $history = array(), string $page_url = '' ) {
		$question = trim( wp_strip_all_tags( $question ) );

		if ( '' === $question ) {
			return new \WP_Error(
				'curio_empty_question',
				__( 'Please type a question.', 'curio-ai-chat' ),
				array( 'status' => 400 )
			);
		}

		$max = Options::number( 'max_question_length' );
		if ( Text::length( $question ) > $max ) {
			$question = Text::truncate( $question, $max );
		}

		if ( Rate_Limiter::visitor_throttled() ) {
			return new \WP_Error(
				'curio_throttled',
				__( 'You have sent a lot of messages just now. Please wait a moment and try again.', 'curio-ai-chat' ),
				array( 'status' => 429 )
			);
		}

		if ( Rate_Limiter::capped() ) {
			// Deliberately worded as the assistant being unavailable rather
			// than the site having run out of budget. A visitor does not need
			// to know about somebody else's billing, and saying so invites
			// exactly the person who caused it to try again tomorrow.
			return new \WP_Error(
				'curio_capped',
				Prompt::unavailable(),
				array( 'status' => 503 )
			);
		}

		// "Hello" retrieves nothing, because there is nothing to retrieve, and
		// everything below declines what it cannot ground. Answered literally
		// that makes the first thing most visitors type — a greeting — come
		// back as a refusal. Greeting back asserts nothing about the business,
		// so it needs no source, and it costs no API call to give.
		//
		// The same holds past "hello". "Thanks", "great", "ok", "bye", "how
		// are you" and "are you a bot" retrieve nothing either, and on a live
		// site a refusal to each of them read as an assistant that was cold
		// and a little dim. None of them asks about the business, so they are
		// answered here in the same way, without retrieval or a call.
		$reply = Text::is_greeting( $question ) ? Prompt::welcome() : Prompt::small_talk( $question );
		if ( '' !== $reply ) {
			$result = array(
				'answer'   => $reply,
				'sources'  => array(),
				'cached'   => false,
				'grounded' => false,
				'declined' => false,
				'general'  => false,
			);
			self::log( $question, $result['answer'], true, 'none', '', array(), 0, 0, $page_url );
			return self::finish( $result, $question );
		}

		$provider = Registry::current();
		$model    = method_exists( $provider, 'model' ) ? (string) $provider->model() : '';

		// A configured provider with no key is a misconfiguration, not a
		// visitor's problem: fall back to demo mode so the widget still
		// behaves instead of showing an error to the public.
		if ( ! $provider->is_configured() ) {
			$provider = Registry::get( 'demo' );
			$model    = '';
		}

		$rows        = Retriever::search( $question, Options::number( 'context_chunks' ) );
		$has_context = array() !== $rows;

		// The general-knowledge switch is in the seed because an answer
		// written under one set of rules is not the answer under the other,
		// and `wp curio set` changes a setting without emptying the cache.
		$cache_seed = wp_json_encode(
			array(
				Text::lower( $question ),
				$provider->slug(),
				$model,
				array_map( static function ( $row ) {
					return (int) $row['id'];
				}, $rows ),
				Options::flag( 'general_knowledge' ),
			)
		);

		if ( Options::flag( 'cache_answers' ) ) {
			$cached = Cache::get( 'answer', (string) $cache_seed );
			if ( is_array( $cached ) && isset( $cached['answer'] ) ) {
				Rate_Limiter::record_cached();
				$cached['cached'] = true;

				// Entries written before `declined` existed have no such key,
				// and the widget must not read a missing key as "it declined".
				if ( ! array_key_exists( 'declined', $cached ) ) {
					$cached['declined'] = empty( $cached['grounded'] );
				}
				$cached['general'] = ! empty( $cached['general'] );

				// Logged like a fresh reply, at no token cost. Left out, a
				// question answered from the cache counted once however often
				// it was asked, and the Insights counts are what the owner
				// decides which answer to write first from.
				self::log( $question, (string) $cached['answer'], ! empty( $cached['grounded'] ), $provider->slug(), $model, wp_list_pluck( $rows, 'id' ), 0, 0, $page_url, $cached['general'] );

				return self::finish( $cached, $question );
			}
		}

		// Nothing retrieved means nothing to ground an answer in. Declining
		// here rather than sending the question anyway is the difference
		// between a plugin that cannot invent a price and one that merely asks
		// a model nicely not to.
		//
		// General knowledge is the one exception, and only when the owner
		// switches it on. "What is a prime lens?" matches no entry on a
		// photographer's site and is still worth answering, so the question
		// goes to the provider with no passages and a prompt that says so: it
		// may answer a general question about the industry and must decline
		// anything about the business. That is a model asked nicely, which is
		// exactly why it is off by default. Demo mode has no model to ask and
		// declines as before.
		if ( ! $has_context && 'demo' !== $provider->slug() && ! Options::flag( 'general_knowledge' ) ) {
			$result = array(
				'answer'   => Prompt::decline(),
				'sources'  => array(),
				'cached'   => false,
				'grounded' => false,
				'declined' => true,
				'general'  => false,
			);
			self::log( $question, $result['answer'], false, $provider->slug(), $model, array(), 0, 0, $page_url );
			return self::finish( $result, $question );
		}

		$system   = Prompt::system( Retriever::to_context( $rows ), $has_context );
		$response = $provider->complete( $question, $history, $system );

		if ( is_wp_error( $response ) ) {
			// The provider's own words go to the log and the admin screen; the
			// visitor gets a plain apology. Nobody browsing a photography site
			// should be reading "invalid x-api-key".
			self::log( $question, $response->get_error_message(), false, $provider->slug(), $model, array(), 0, 0, $page_url );
			self::record_failure( $provider->slug(), $model, $response->get_error_message() );

			return new \WP_Error(
				$response->get_error_code(),
				Prompt::unavailable(),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$text = (string) ( $response['text'] ?? '' );
		[ $text, $sources ] = self::extract_sources( $text, $rows );

		if ( 'demo' !== $provider->slug() ) {
			Rate_Limiter::record(
				(int) ( $response['tokens_in'] ?? 0 ),
				(int) ( $response['tokens_out'] ?? 0 )
			);
			self::clear_failure();
		}

		// A real model answering with nothing retrieved only happens with
		// general knowledge on, since the decline above stops everything else.
		// That reply is an answer: general advice, or the model's own decline
		// of a question about the business, which carries the hand-off line in
		// words. Demo mode with nothing retrieved is the other way here, and
		// that is a decline written without a source.
		$general = ! $has_context && 'demo' !== $provider->slug();

		$result = array(
			'answer'   => $text,
			'sources'  => $sources,
			'cached'   => false,
			'grounded' => $has_context,
			// The widget uses this to offer a human, so only a reply that
			// declined sets it.
			'declined' => ! $has_context && ! $general,
			'general'  => $general,
		);

		if ( Options::flag( 'cache_answers' ) ) {
			Cache::set( 'answer', (string) $cache_seed, $result, Options::number( 'cache_ttl' ) * HOUR_IN_SECONDS );
		}

		self::log(
			$question,
			$text,
			$has_context,
			$provider->slug(),
			$model,
			wp_list_pluck( $rows, 'id' ),
			(int) ( $response['tokens_in'] ?? 0 ),
			(int) ( $response['tokens_out'] ?? 0 ),
			$page_url,
			$general
		);

		return self::finish( $result, $question );
	}

	/**
	 * The last thing that happens to every reply, whichever route produced it.
	 *
	 * All four routes out of answer() come through here — a greeting or other
	 * small talk, a decline written without a model, a cached answer and a
	 * fresh one. A filter that only sees some of them is worse than no filter:
	 * a site adding an "AI generated" notice, or logging replies to its own
	 * CRM, would find it silently absent from exactly the replies it cared
	 * about most.
	 *
	 * @param array<string,mixed> $result   The answer payload.
	 * @param string              $question The question asked.
	 * @return array<string,mixed>
	 */
	private static function finish( array $result, string $question ): array {
		/**
		 * Filter the finished answer before it is returned to the visitor.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $result   The answer payload.
		 * @param string              $question The question asked.
		 */
		return (array) apply_filters( 'curio_answer', $result, $question );
	}

	/**
	 * Remember why the provider refused, for the owner rather than the visitor.
	 *
	 * The visitor is only ever told that something went wrong, which is right
	 * for them and useless to the person who has to fix it. The provider's own
	 * reason used to reach the conversation log and nowhere else, and that log
	 * is off by default, so a site owner whose chat had stopped answering had
	 * no way to learn whether it was the key, the credit or the model. This
	 * keeps that one line, whatever the logging setting, and the Connection tab
	 * shows it. It holds the provider's error and nothing the visitor typed.
	 *
	 * Autoloaded, so while it exists the success path reads it for free; while
	 * it does not, that read is one primary-key lookup beside an API call.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model id.
	 * @param string $reason   The provider's error, as explained to the owner.
	 * @return void
	 */
	private static function record_failure( string $provider, string $model, string $reason ): void {
		update_option(
			self::FAILURE_OPTION,
			array(
				'provider' => $provider,
				'model'    => $model,
				'reason'   => Text::truncate( $reason, 300 ),
				'at'       => time(),
			),
			true
		);
	}

	/**
	 * Forget the last failure once it no longer describes the chat.
	 *
	 * Called after any reply a provider gave normally, and after a passing
	 * "Test" of the same provider, so the notice only stands while it is true.
	 *
	 * @param string $provider Only clear a failure that belongs to this provider; empty clears any.
	 * @return void
	 */
	public static function clear_failure( string $provider = '' ): void {
		$failure = get_option( self::FAILURE_OPTION );
		if ( ! is_array( $failure ) ) {
			return;
		}
		if ( '' === $provider || ( $failure['provider'] ?? '' ) === $provider ) {
			delete_option( self::FAILURE_OPTION );
		}
	}

	/**
	 * Pull citation markers out of the reply and turn them into source links.
	 *
	 * The model is asked to end with `[1]`. Those markers are useful data and
	 * ugly prose, so they are removed from the text and rendered underneath as
	 * links the visitor can actually click — which is also what makes a
	 * grounded answer checkable rather than merely asserted.
	 *
	 * @param string                         $text Reply text.
	 * @param array<int,array<string,mixed>> $rows Retrieved passages, in order.
	 * @return array{0:string,1:array<int,array<string,string>>}
	 */
	private static function extract_sources( string $text, array $rows ): array {
		if ( ! Options::flag( 'show_sources' ) || array() === $rows ) {
			return array( trim( preg_replace( '/\s*\[\d{1,2}\]/', '', $text ) ?? $text ), array() );
		}

		$used = array();
		if ( preg_match_all( '/\[(\d{1,2})\]/', $text, $matches ) ) {
			foreach ( $matches[1] as $number ) {
				$index = (int) $number - 1;
				if ( isset( $rows[ $index ] ) ) {
					$used[ $index ] = true;
				}
			}
		}

		$text = trim( (string) preg_replace( '/\s*\[\d{1,2}\]/', '', $text ) );

		$sources = array();
		$seen    = array();
		foreach ( array_keys( $used ) as $index ) {
			$row   = $rows[ $index ];
			$url   = trim( (string) ( $row['url'] ?? '' ) );
			$title = trim( (string) ( $row['title'] ?? '' ) );

			if ( '' === $url || '' === $title ) {
				continue;
			}
			// One chunk per page: three chunks of the same article are one
			// source to a reader, however many passages the retriever used.
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$sources[] = array(
				'title' => $title,
				'url'   => $url,
			);
		}

		return array( $text, array_slice( $sources, 0, 4 ) );
	}

	/**
	 * Hand one exchange to the log, if logging is on.
	 *
	 * @param string $question   Question.
	 * @param string $answer     Answer.
	 * @param bool   $answered   Whether it was grounded in retrieved content.
	 * @param string $provider   Provider slug.
	 * @param string $model      Model id.
	 * @param array  $matched    Knowledge row ids used.
	 * @param int    $tokens_in  Prompt tokens.
	 * @param int    $tokens_out Completion tokens.
	 * @param string $page_url   Page the widget was on.
	 * @param bool   $general    Whether the AI answered it from general knowledge, nothing having matched.
	 * @return void
	 */
	private static function log( string $question, string $answer, bool $answered, string $provider, string $model, array $matched, int $tokens_in, int $tokens_out, string $page_url, bool $general = false ): void {
		Conversation_Log::record(
			array(
				'question'   => $question,
				'answer'     => $answer,
				'answered'   => $answered,
				'general'    => $general,
				'provider'   => $provider,
				'model'      => $model,
				'matched'    => $matched,
				'tokens_in'  => $tokens_in,
				'tokens_out' => $tokens_out,
				'page_url'   => $page_url,
			)
		);
	}
}
