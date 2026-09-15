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
	 * means the very first thing most visitors type is answered with "I do not
	 * have that detail", which reads as broken software rather than as caution.
	 *
	 * A greeting asserts nothing about the business, so greeting back invents
	 * nothing — this is the one reply that is safe without a source behind it.
	 *
	 * @param string $message Visitor's message.
	 * @return bool
	 */
	public static function is_greeting( string $message ): bool {
		// Punctuation and emoji are not part of the comparison, and collapsing
		// whitespace means "hi   there" is the same message as "hi there".
		$words = self::lower( trim( $message ) );
		$words = (string) preg_replace( '/[^\p{L}\s]+/u', ' ', $words );
		$words = trim( (string) preg_replace( '/\s+/u', ' ', $words ) );

		if ( '' === $words || self::length( $words ) > 40 ) {
			return false;
		}

		$greetings = array( 'hello', 'hi', 'hey', 'yo', 'good morning', 'good afternoon', 'good evening', 'good day', 'howdy', 'hiya' );

		/**
		 * Filter the openers treated as a greeting rather than as a question.
		 *
		 * Adding the greetings of another language is how a non-English site
		 * stops its visitors' first message being met with a refusal. Add whole
		 * phrases, not fragments: a greeting only counts when it is the entire
		 * message, or all that follows it is one of the words in $trailing
		 * below.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $greetings Lowercase greeting openers.
		 */
		$greetings = (array) apply_filters( 'curio_greetings', $greetings );

		// What may legitimately follow a greeting and still leave it a
		// greeting. Anything else means a question has been asked.
		$trailing = array( 'there', 'all', 'team', 'everyone', 'everybody', 'guys', 'folks', 'again', 'morning', 'afternoon', 'evening' );

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
