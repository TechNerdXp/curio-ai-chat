<?php
/**
 * Text normalisation, chunking and tokenising.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * The unglamorous half of retrieval.
 *
 * Quality of answers is decided here more than anywhere else in the plugin: a
 * chunk that splits a price away from the thing it prices, or a tokeniser that
 * throws away the one word that mattered, cannot be rescued by a better model
 * downstream.
 */
final class Text {

	/**
	 * What may legitimately follow a greeting and still leave it a greeting.
	 * Anything else means a question has been asked.
	 *
	 * Small talk accepts them too, so "thanks guys" and "bye all" are a
	 * thank-you and a goodbye rather than two questions.
	 */
	private const GREETING_TRAILERS = array( 'there', 'all', 'team', 'everyone', 'everybody', 'guys', 'folks', 'again', 'morning', 'afternoon', 'evening' );

	/**
	 * Words carrying no retrieval signal, so they neither inflate a score nor
	 * crowd out the words that do.
	 *
	 * @return string[]
	 */
	public static function stopwords(): array {
		static $words = null;
		if ( null === $words ) {
			$words = array(
				'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are',
				'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but',
				'by', 'can', 'cannot', 'could', 'did', 'do', 'does', 'doing', 'down', 'during', 'each',
				'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having', 'he', 'her', 'here',
				'hers', 'herself', 'him', 'himself', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it',
				'its', 'itself', 'just', 'me', 'more', 'most', 'my', 'myself', 'no', 'nor', 'not', 'now',
				'of', 'off', 'on', 'once', 'only', 'or', 'other', 'ought', 'our', 'ours', 'ourselves',
				'out', 'over', 'own', 'please', 'same', 'she', 'should', 'so', 'some', 'such', 'than',
				'that', 'the', 'their', 'theirs', 'them', 'themselves', 'then', 'there', 'these', 'they',
				'this', 'those', 'through', 'to', 'too', 'under', 'until', 'up', 'very', 'was', 'we',
				'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why', 'will', 'with',
				'would', 'you', 'your', 'yours', 'yourself', 'yourselves',
			);

			/**
			 * Filter the stop-word list used when scoring questions.
			 *
			 * Retrieval is tuned for English out of the box. A site running in
			 * another language can replace the list wholesale here rather than
			 * fork the plugin.
			 *
			 * @since 1.0.0
			 *
			 * @param string[] $words Stop words, lowercase.
			 */
			$words = (array) apply_filters( 'curio_stopwords', $words );
		}
		return $words;
	}

	/**
	 * Turn stored post content into something worth indexing.
	 *
	 * Order matters. Blocks are rendered first so that a reusable block or a
	 * query loop contributes its text; shortcodes are stripped rather than run,
	 * because running an arbitrary shortcode during a save or a cron tick is a
	 * side effect nobody asked for.
	 *
	 * @param string $raw Raw post content or HTML.
	 * @return string
	 */
	public static function normalize( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $raw ) && function_exists( 'do_blocks' ) ) {
			$raw = do_blocks( $raw );
		}

		$raw = strip_shortcodes( $raw );

		// Drop whole elements whose text is markup furniture, not content.
		$raw = (string) preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $raw );

		// Keep block-level boundaries as paragraph breaks so chunking has
		// something honest to split on.
		$raw = (string) preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/tr|/section)\s*/?>#i', "\n", $raw );

		$text = wp_strip_all_tags( $raw, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), $text );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Split text into overlapping chunks on natural boundaries.
	 *
	 * Paragraphs first, sentences second, a hard cut only as a last resort. The
	 * overlap exists because the sentence that answers a question and the
	 * sentence that names its subject are frequently either side of a break,
	 * and a chunk boundary that separates them makes both useless.
	 *
	 * @param string $text    Normalised plain text.
	 * @param int    $size    Target characters per chunk.
	 * @param int    $overlap Characters repeated from the previous chunk.
	 * @return string[]
	 */
	public static function chunk( string $text, int $size = 900, int $overlap = 120 ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		$size    = max( 200, $size );
		$overlap = max( 0, min( $overlap, (int) floor( $size / 3 ) ) );

		if ( self::length( $text ) <= $size ) {
			return array( $text );
		}

		$units = preg_split( '/\n{2,}/', $text ) ?: array( $text );
		$parts = array();

		foreach ( $units as $unit ) {
			$unit = trim( $unit );
			if ( '' === $unit ) {
				continue;
			}
			if ( self::length( $unit ) <= $size ) {
				$parts[] = $unit;
				continue;
			}
			foreach ( self::split_sentences( $unit ) as $sentence ) {
				if ( self::length( $sentence ) <= $size ) {
					$parts[] = $sentence;
					continue;
				}
				foreach ( self::hard_split( $sentence, $size ) as $piece ) {
					$parts[] = $piece;
				}
			}
		}

		// Re-assemble the pieces up to the target size, so a chunk is as full
		// as it can be without crossing a boundary.
		$chunks  = array();
		$current = '';
		foreach ( $parts as $part ) {
			$candidate = '' === $current ? $part : $current . "\n\n" . $part;
			if ( self::length( $candidate ) > $size && '' !== $current ) {
				$chunks[] = $current;
				$current  = self::tail( $current, $overlap );
				$current  = '' === $current ? $part : $current . "\n\n" . $part;
				continue;
			}
			$current = $candidate;
		}
		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}

		return array_values( array_filter( array_map( 'trim', $chunks ), 'strlen' ) );
	}

	/**
	 * Break a paragraph into sentences.
	 *
	 * @param string $text Paragraph.
	 * @return string[]
	 */
	private static function split_sentences( string $text ): array {
		$pieces = preg_split( '/(?<=[.!?])\s+(?=[A-Z0-9"\'\x{00C0}-\x{024F}])/u', $text );
		if ( ! is_array( $pieces ) || array() === $pieces ) {
			return array( $text );
		}
		return array_values( array_filter( array_map( 'trim', $pieces ), 'strlen' ) );
	}

	/**
	 * Last resort: cut on word boundaries at a fixed width.
	 *
	 * @param string $text Long sentence.
	 * @param int    $size Maximum characters.
	 * @return string[]
	 */
	private static function hard_split( string $text, int $size ): array {
		$out     = array();
		$words   = preg_split( '/\s+/', $text ) ?: array();
		$current = '';
		foreach ( $words as $word ) {
			$candidate = '' === $current ? $word : $current . ' ' . $word;
			if ( self::length( $candidate ) > $size && '' !== $current ) {
				$out[]   = $current;
				$current = $word;
				continue;
			}
			$current = $candidate;
		}
		if ( '' !== $current ) {
			$out[] = $current;
		}
		return $out;
	}

	/**
	 * The trailing N characters, trimmed back to a word boundary.
	 *
	 * @param string $text   Source.
	 * @param int    $length Characters wanted.
	 * @return string
	 */
	private static function tail( string $text, int $length ): string {
		if ( $length < 1 ) {
			return '';
		}
		$tail = function_exists( 'mb_substr' ) ? mb_substr( $text, -$length ) : substr( $text, -$length );
		$tail = (string) preg_replace( '/^\S*\s+/', '', (string) $tail );
		return trim( $tail );
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $text Source.
	 * @return int
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Multibyte-safe lowercase.
	 *
	 * @param string $text Source.
	 * @return string
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Multibyte-safe truncation.
	 *
	 * @param string $text   Source.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	public static function truncate( string $text, int $length ): string {
		if ( self::length( $text ) <= $length ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
		return rtrim( (string) $cut ) . '…';
	}

	/**
	 * Does the message open with a greeting rather than ask something?
	 *
	 * Retrieval finds nothing for "hello", because there is nothing to find,
	 * and the plugin declines whatever retrieval cannot ground. Left alone that
	 * means the very first thing most visitors type is answered with a
	 * refusal, which reads as broken software rather than as caution.
	 *
	 * A greeting asserts nothing about the business, so greeting back invents
	 * nothing — a reply that is safe without a source behind it, as the small
	 * talk below is for the same reason.
	 *
	 * @param string $message Visitor's message.
	 * @return bool
	 */
	public static function is_greeting( string $message ): bool {
		$words = self::plain_words( $message );

		if ( '' === $words || self::length( $words ) > 40 ) {
			return false;
		}

		$greetings = self::greetings();
		$trailing  = self::GREETING_TRAILERS;

		foreach ( $greetings as $greeting ) {
			$greeting = trim( self::lower( (string) $greeting ) );

			if ( '' === $greeting ) {
				continue;
			}
			if ( $words === $greeting ) {
				return true;
			}

			// The space matters. Matching on a bare prefix made "your opening
			// hours?" a greeting, because it begins with "yo" — and the visitor
			// got a cheerful hello instead of the answer, with the question
			// logged as one that had been dealt with.
			if ( 0 !== strpos( $words, $greeting . ' ' ) ) {
				continue;
			}

			$rest = trim( substr( $words, strlen( $greeting ) + 1 ) );
			if ( '' === $rest ) {
				return true;
			}

			$leftover = array_diff(
				explode( ' ', $rest ),
				array_merge( $trailing, array_map( 'strval', $greetings ) )
			);
			if ( array() === $leftover ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The openers that count as a greeting.
	 *
	 * @return string[]
	 */
	private static function greetings(): array {
		$greetings = array( 'hello', 'hi', 'hey', 'yo', 'good morning', 'good afternoon', 'good evening', 'good day', 'howdy', 'hiya' );

		/**
		 * Filter the openers treated as a greeting rather than as a question.
		 *
		 * Adding the greetings of another language is how a non-English site
		 * stops its visitors' first message being met with a refusal. Add whole
		 * phrases, not fragments: a greeting only counts when it is the entire
		 * message, or all that follows it is one of a short list of words such
		 * as "there", "all" and "everyone".
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $greetings Lowercase greeting openers.
		 */
		return (array) apply_filters( 'curio_greetings', $greetings );
	}

	/**
	 * A message reduced to lowercase words and single spaces.
	 *
	 * Punctuation and emoji are not part of a greeting or small-talk
	 * comparison, and collapsing whitespace means "hi   there" is the same
	 * message as "hi there". An apostrophe becomes a space like any other
	 * punctuation, so "that's all" is compared as "that s all"; the word lists
	 * go through here too, which is what lets either spelling match.
	 *
	 * @param string $message Visitor's message, or one phrase from a word list.
	 * @return string
	 */
	private static function plain_words( string $message ): string {
		$words = self::lower( trim( $message ) );
		$words = (string) preg_replace( '/[^\p{L}\s]+/u', ' ', $words );
		return trim( (string) preg_replace( '/\s+/u', ' ', $words ) );
	}

	/**
	 * Is the whole message small talk, and if it is, which kind?
	 *
	 * The greeting fix stopped at "hello". Real visitors also say "thanks",
	 * "great", "ok", "bye", "how are you" and "are you a bot", and every one of
	 * those retrieved nothing and was met with a refusal, which on a live site
	 * read as an assistant that was cold and slightly dim. None of them asks
	 * anything about the business, so answering them invents nothing.
	 *
	 * A message counts only when it is small talk and nothing else: take every
	 * small-talk phrase out of it, and all that may be left is filler such as
	 * "so", "really" or "mate", or a greeting. "Thanks, how much is a
	 * headshot?" and "nice photos?" leave words that are neither, so they are
	 * questions and go to retrieval as usual, and so is "ok so how much",
	 * which is a question that has lost its question mark. And a question mark
	 * means something was asked, so only the kinds that are questions in
	 * themselves, such as "how are you?" and "are you a bot?", survive one:
	 * "is it good?" is a question, not a compliment.
	 *
	 * The first kind with a phrase in the message wins, so the table lists the
	 * more particular kinds first: "no thanks" is a goodbye before it is thanks,
	 * and "thanks, how are you?" is asking after the assistant.
	 *
	 * @param string              $message Visitor's message.
	 * @param array<string,mixed> $talk    The table Prompt::small_talk_table() builds: `kinds`, each with `phrases` and `asks`, and `filler`.
	 * @return string The kind, or an empty string when the message asks for something.
	 */
	public static function small_talk_kind( string $message, array $talk ): string {
		$words = self::plain_words( $message );

		if ( '' === $words || self::length( $words ) > 60 ) {
			return '';
		}

		// The ASCII question mark, and the full-width and Arabic ones, since
		// the word lists are translatable and so is the punctuation.
		$asked = (bool) preg_match( '/[?\x{FF1F}\x{061F}]/u', $message );

		$kinds = array();
		$every = array();
		foreach ( (array) ( $talk['kinds'] ?? array() ) as $kind => $entry ) {
			$phrases = array();
			foreach ( (array) ( $entry['phrases'] ?? array() ) as $phrase ) {
				$phrase = self::plain_words( (string) $phrase );
				if ( '' !== $phrase ) {
					$phrases[] = $phrase;
					$every[]   = $phrase;
				}
			}
			$kinds[ (string) $kind ] = array(
				'phrases' => $phrases,
				'asks'    => ! empty( $entry['asks'] ),
			);
		}

		// Take the phrases out longest first, so "how are you doing" goes as
		// one piece rather than as "how are you" with "doing" left behind.
		// Whole words only, which is what the padding is for: without it the
		// thank-you "ta" is found inside "fantastic", and a compliment is
		// answered as thanks.
		usort(
			$every,
			static function ( $a, $b ) {
				return substr_count( $b, ' ' ) <=> substr_count( $a, ' ' );
			}
		);
		$rest = ' ' . $words . ' ';
		foreach ( $every as $phrase ) {
			do {
				$before = $rest;
				$rest   = str_replace( ' ' . $phrase . ' ', ' ', $rest );
			} while ( $rest !== $before );
		}

		$allowed = array();
		foreach ( array_merge( (array) ( $talk['filler'] ?? array() ), self::greetings(), self::GREETING_TRAILERS ) as $word ) {
			foreach ( explode( ' ', self::plain_words( (string) $word ) ) as $part ) {
				$allowed[ $part ] = true;
			}
		}
		foreach ( explode( ' ', trim( $rest ) ) as $word ) {
			if ( '' !== $word && ! isset( $allowed[ $word ] ) ) {
				return '';
			}
		}

		$padded = ' ' . $words . ' ';
		foreach ( $kinds as $kind => $entry ) {
			if ( $asked && ! $entry['asks'] ) {
				continue;
			}
			foreach ( $entry['phrases'] as $phrase ) {
				if ( false !== strpos( $padded, ' ' . $phrase . ' ' ) ) {
					return $kind;
				}
			}
		}

		return '';
	}

	/**
	 * Tokenise for scoring: lowercase words, stop words and noise removed.
	 *
	 * Numbers survive on purpose. "2024", "4k" and "£150" are exactly the
	 * tokens a pricing or availability question turns on, and a tokeniser that
	 * drops digits throws away the most answerable questions a business gets.
	 *
	 * @param string $text      Source text.
	 * @param bool   $keep_stop Keep stop words (used when the query is tiny).
	 * @return string[]
	 */
	public static function tokens( string $text, bool $keep_stop = false ): array {
		$text  = self::lower( $text );
		$text  = (string) preg_replace( '/[^\p{L}\p{N}\s\-\']+/u', ' ', $text );
		$parts = preg_split( '/[\s\-]+/u', $text ) ?: array();

		$stop   = $keep_stop ? array() : array_flip( self::stopwords() );
		$tokens = array();
		foreach ( $parts as $part ) {
			$part = trim( $part, "'" );
			if ( '' === $part || self::length( $part ) < 2 ) {
				continue;
			}
			if ( isset( $stop[ $part ] ) ) {
				continue;
			}
			$tokens[] = $part;
		}
		return $tokens;
	}

	/**
	 * A modest suffix strip, so "pricing" matches "price" and "bookings"
	 * matches "booking".
	 *
	 * Deliberately not a full Porter stemmer. Aggressive stemming collapses
	 * words a small business cares about keeping apart — "printing" and
	 * "prints", "wedding" and "weddings" is fine, "printer" and "printing" is
	 * not — and there is no test set here to tune one against.
	 *
	 * @param string $token Single token.
	 * @return string
	 */
	public static function stem( string $token ): string {
		$length = self::length( $token );
		if ( $length < 5 ) {
			return $token;
		}
		foreach ( array( 'ings', 'ing', 'ies', 'ers', 'es', 's' ) as $suffix ) {
			$suffix_length = strlen( $suffix );
			if ( $length - $suffix_length >= 3 && substr( $token, -$suffix_length ) === $suffix ) {
				$stem = substr( $token, 0, $length - $suffix_length );
				if ( 'ies' === $suffix ) {
					$stem .= 'y';
				}
				return $stem;
			}
		}
		return $token;
	}

	/**
	 * The most distinctive words in a passage, for the stored keyword column.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit How many to keep.
	 * @return string[]
	 */
	public static function keywords( string $text, int $limit = 20 ): array {
		$counts = array_count_values( self::tokens( $text ) );
		if ( array() === $counts ) {
			return array();
		}
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, max( 1, $limit ) );
	}
}
