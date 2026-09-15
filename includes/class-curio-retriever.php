<?php
/**
 * Retrieval and ranking.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the passages a question should be answered from — and, just as
 * importantly, decides when there are none.
 *
 * The threshold at the bottom of `search()` is the whole safety story of this
 * plugin. A retriever that always returns its best three rows will hand the
 * model three irrelevant paragraphs about parking when someone asks about
 * refunds, and a helpful model will construct a refund policy out of them. The
 * only way to stop that is to be willing to return nothing.
 */
final class Retriever {

	/**
	 * Rows below this score are treated as noise rather than context.
	 */
	private const MIN_SCORE = 1.2;

	/**
	 * How many rows to pull out of the database before ranking properly.
	 */
	private const CANDIDATE_LIMIT = 60;

	/**
	 * Find the best passages for a question.
	 *
	 * @param string $question Visitor's question.
	 * @param int    $limit    Maximum passages to return.
	 * @return array<int,array<string,mixed>> Rows with an added `score` key.
	 */
	public static function search( string $question, int $limit = 4 ): array {
		$question = trim( $question );
		if ( '' === $question ) {
			return array();
		}

		$tokens = Text::tokens( $question );
		if ( array() === $tokens ) {
			// "Where?" is all stop words but still a question. Falling back to
			// the unfiltered tokens beats returning nothing at all.
			$tokens = Text::tokens( $question, true );
		}
		if ( array() === $tokens ) {
			return array();
		}
		$tokens = array_slice( array_values( array_unique( $tokens ) ), 0, 16 );

		$candidates = self::candidates( $tokens );
		if ( array() === $candidates ) {
			return array();
		}

		$scored = self::rank( $question, $tokens, $candidates );

		/**
		 * Filter the minimum relevance a passage needs to be used as context.
		 *
		 * Raising this makes the assistant decline more often; lowering it
		 * makes it answer from weaker matches. It is the single dial that
		 * trades safety against helpfulness.
		 *
		 * @since 1.0.0
		 *
		 * @param float  $threshold Minimum score.
		 * @param string $question  The question being answered.
		 */
		$threshold = (float) apply_filters( 'curio_relevance_threshold', self::MIN_SCORE, $question );

		$kept = array();
		foreach ( $scored as $row ) {
			if ( $row['score'] >= $threshold ) {
				$kept[] = $row;
			}
			if ( count( $kept ) >= max( 1, $limit ) ) {
				break;
			}
		}

		/**
		 * Filter the passages about to be handed to the model.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int,array<string,mixed>> $kept     Selected passages.
		 * @param string                         $question The question.
		 */
		return (array) apply_filters( 'curio_retrieved_context', $kept, $question );
	}

	/**
	 * Pull a candidate set out of the database.
	 *
	 * Runs the FULLTEXT path and the LIKE path and merges them, rather than
	 * treating them as alternatives. MySQL's full-text index ignores tokens
	 * shorter than `innodb_ft_min_token_size` (three characters by default) and
	 * its own stop-word list, so "VAT", "fee" and "A3" — precisely the terms a
	 * pricing question hangs on — are invisible to it. The LIKE pass is slower
	 * and dumber and catches exactly those.
	 *
	 * @param string[] $tokens Query tokens.
	 * @return array<int,array<string,mixed>>
	 */
	private static function candidates( array $tokens ): array {
		global $wpdb;

		$table = Installer::knowledge_table();
		$rows  = array();

		if ( (bool) get_option( Options::FULLTEXT, false ) ) {
			$terms = array();
			foreach ( $tokens as $token ) {
				$clean = preg_replace( '/[^\p{L}\p{N}]/u', '', $token );
				if ( is_string( $clean ) && Text::length( $clean ) >= 3 ) {
					$terms[] = $clean . '*';
				}
			}
			if ( array() !== $terms ) {
				$against = implode( ' ', $terms );
				$sql     = "SELECT id, ref, source_type, source_id, title, content, keywords, url
					FROM `{$table}`
					WHERE MATCH(title, content, keywords) AGAINST (%s IN BOOLEAN MODE)
					LIMIT %d";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name interpolated; both values are bound.
				$found = $wpdb->get_results( $wpdb->prepare( $sql, $against, self::CANDIDATE_LIMIT ), ARRAY_A );
				foreach ( (array) $found as $row ) {
					$rows[ (int) $row['id'] ] = $row;
				}
			}
		}

		if ( count( $rows ) < self::CANDIDATE_LIMIT ) {
			$clauses = array();
			$values  = array();
			foreach ( array_slice( $tokens, 0, 8 ) as $token ) {
				$like      = '%' . $wpdb->esc_like( $token ) . '%';
				$clauses[] = '(title LIKE %s OR content LIKE %s OR keywords LIKE %s)';
				$values[]  = $like;
				$values[]  = $like;
				$values[]  = $like;
			}
			if ( array() !== $clauses ) {
				$values[] = self::CANDIDATE_LIMIT;
				$sql      = "SELECT id, ref, source_type, source_id, title, content, keywords, url
					FROM `{$table}`
					WHERE " . implode( ' OR ', $clauses ) . ' LIMIT %d';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clause list is generated from a count; every value is bound.
				$found = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
				foreach ( (array) $found as $row ) {
					$rows[ (int) $row['id'] ] = $row;
				}
			}
		}

		return array_values( $rows );
	}

	/**
	 * Score and order the candidate set.
	 *
	 * A cut-down BM25: saturating term frequency, inverse document frequency
	 * measured across the candidate set, and length normalisation, plus three
	 * bonuses that matter for this particular job — a hit in the title, a hit
	 * in the curated keyword column, and the whole question appearing verbatim.
	 *
	 * Computing IDF over the candidates rather than the whole table is a
	 * shortcut, and an intentional one: it costs one pass over sixty rows
	 * instead of a second query per term, and for a knowledge base of this size
	 * the ordering it produces is the same.
	 *
	 * @param string                         $question   Original question.
	 * @param string[]                       $tokens     Query tokens.
	 * @param array<int,array<string,mixed>> $candidates Candidate rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rank( string $question, array $tokens, array $candidates ): array {
		// N is the size of the whole knowledge base, not the size of the
		// candidate set. Using the candidate count here is a subtle and
		// expensive mistake: every candidate matched the query by definition,
		// so a term present in all of them gets a document frequency equal to
		// N, its inverse document frequency collapses towards zero, and the
		// passage's total score collapses with it. The practical symptom is an
		// assistant that refuses to answer "wedding prices" on a site with
		// several pages about weddings — refusing hardest exactly where it
		// knows most, which is the worst possible failure for this product.
		$corpus = max( count( $candidates ), Knowledge_Store::count() );
		$stems  = array();
		foreach ( $tokens as $token ) {
			$stems[ $token ] = Text::stem( $token );
		}

		// Prepare a lowercase view of each row once.
		$prepared = array();
		$lengths  = array();
		foreach ( $candidates as $index => $row ) {
			$title    = Text::lower( (string) $row['title'] );
			$body     = Text::lower( (string) $row['content'] );
			$keywords = Text::lower( (string) $row['keywords'] );

			$prepared[ $index ] = array(
				'title'    => $title,
				'body'     => $body,
				'keywords' => $keywords,
				'haystack' => $title . ' ' . $keywords . ' ' . $body,
			);
			$lengths[ $index ]  = max( 1, str_word_count( $body ) );
		}
		$average_length = array_sum( $lengths ) / max( 1, count( $lengths ) );

		// Document frequency per token, counted across the candidate set.
		//
		// The candidate query returns every row containing any query term, up
		// to its limit, so for a knowledge base of realistic size this count
		// *is* the corpus document frequency — one pass over sixty rows in
		// memory instead of one extra database query per term.
		$document_frequency = array();
		foreach ( $stems as $token => $stem ) {
			$count = 0;
			foreach ( $prepared as $view ) {
				if ( false !== strpos( $view['haystack'], $stem ) ) {
					++$count;
				}
			}
			$document_frequency[ $token ] = $count;
		}

		$question_lower = Text::lower( trim( $question ) );
		$k1             = 1.4;
		$b              = 0.72;
		$scored         = array();

		foreach ( $candidates as $index => $row ) {
			$view  = $prepared[ $index ];
			$score = 0.0;
			$hits  = 0;

			foreach ( $stems as $token => $stem ) {
				$frequency = substr_count( $view['body'], $stem );
				$in_title  = false !== strpos( $view['title'], $stem );
				$in_keys   = false !== strpos( $view['keywords'], $stem );

				if ( 0 === $frequency && ! $in_title && ! $in_keys ) {
					continue;
				}
				++$hits;

				$document_count = max( 1, (int) ( $document_frequency[ $token ] ?? 1 ) );
				$idf            = log( 1 + ( ( $corpus - $document_count + 0.5 ) / ( $document_count + 0.5 ) ) );
				$idf            = max( 0.35, $idf );

				$normalised = $frequency * ( $k1 + 1 )
					/ ( $frequency + ( $k1 * ( 1 - $b + ( $b * ( $lengths[ $index ] / $average_length ) ) ) ) );

				$score += $idf * $normalised;

				// The title of a knowledge entry is written by a human to say
				// what the entry is about, so a hit there is worth more than a
				// hit anywhere in the body.
				if ( $in_title ) {
					$score += $idf * 1.6;
				}
				// The keyword column is the same argument, more so: somebody
				// typed those words specifically to route questions here.
				if ( $in_keys ) {
					$score += $idf * 1.1;
				}
			}

			if ( 0 === $hits ) {
				continue;
			}

			// Matching several distinct query terms is much stronger evidence
			// than matching one term several times.
			$score *= 1 + ( 0.18 * ( $hits - 1 ) );

			// The whole question, verbatim, in the passage. Rare, and decisive
			// when it happens — it is usually an FAQ entry written from the
			// question itself.
			if ( Text::length( $question_lower ) > 12 && false !== strpos( $view['haystack'], $question_lower ) ) {
				$score += 4.0;
			}

			// Hand-written entries beat auto-indexed page text at equal score.
			// Somebody chose to write them as an answer; a paragraph of a blog
			// post merely happens to contain the words.
			if ( Knowledge_Store::SOURCE_MANUAL === ( $row['source_type'] ?? '' ) ) {
				$score *= 1.12;
			}

			$row['score'] = round( $score, 4 );
			$scored[]     = $row;
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $scored;
	}

	/**
	 * Render selected passages as the context block sent to the model.
	 *
	 * Numbered, titled and with the source URL attached, because the model is
	 * asked to cite which passage it used and cannot do that if the passages
	 * are an undifferentiated wall of text.
	 *
	 * @param array<int,array<string,mixed>> $rows Selected passages.
	 * @return string
	 */
	public static function to_context( array $rows ): string {
		if ( array() === $rows ) {
			return '';
		}
		$blocks = array();
		$number = 0;
		foreach ( $rows as $row ) {
			++$number;
			$header = '[' . $number . ']';
			$title  = trim( (string) ( $row['title'] ?? '' ) );
			if ( '' !== $title ) {
				$header .= ' ' . $title;
			}
			$url = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' !== $url ) {
				$header .= ' (' . $url . ')';
			}
			$blocks[] = $header . "\n" . trim( (string) $row['content'] );
		}
		return implode( "\n\n---\n\n", $blocks );
	}
}
