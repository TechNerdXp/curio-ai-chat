<?php
/**
 * Demo responder.
 *
 * @package Curio
 */

namespace Curio\Providers;

use Curio\Prompt;
use Curio\Retriever;
use Curio\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The responder used before an API key is added. Costs nothing, calls nothing.
 *
 * It is not a script of canned answers, and that distinction is the whole
 * reason this class is written the way it is. The plugin this replaces shipped
 * a "mock" responder containing invented wedding packages at three price
 * points, an hourly rate, a phone number and two booking addresses — and demo
 * mode was the *default*, so those fabricated prices were what a fresh install
 * said to the first real customer who asked, before the owner had touched a
 * setting.
 *
 * So this runs the same retrieval the real providers run and returns what it
 * finds, verbatim, or declines in the same words they decline in. With an empty
 * knowledge base it declines everything, which is the correct behaviour on a
 * fresh install and precisely the thing worth demonstrating.
 */
final class Demo extends Provider_Base {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'demo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Demo mode — no API key, no cost', 'curio-ai-chat' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_model(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fallback_models(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function models(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_models() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function links(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( string $question, array $history, string $system ) {
		$question = trim( $question );

		// A greeting gets a greeting. Chat::answer() already intercepts these
		// before a provider is reached; this stays because the provider is a
		// public interface and has to behave correctly when called directly,
		// which the test suite does.
		if ( '' !== $question && Text::is_greeting( $question ) ) {
			return array(
				'text'       => Prompt::welcome(),
				'tokens_in'  => 0,
				'tokens_out' => 0,
			);
		}

		$rows = Retriever::search( $question, 1 );
		if ( array() === $rows ) {
			return array(
				'text'       => Prompt::decline(),
				'tokens_in'  => 0,
				'tokens_out' => 0,
			);
		}

		// Returned as written. Rephrasing is the exact point at which a
		// keyword-matching responder starts inventing, so it does not rephrase.
		return array(
			'text'       => sprintf(
				/* translators: %s: the matching knowledge base entry, quoted as written. */
				__( "Here is what I have on that:\n\n%s", 'curio-ai-chat' ),
				trim( (string) $rows[0]['content'] )
			),
			'tokens_in'  => 0,
			'tokens_out' => 0,
			'matched'    => array( (int) $rows[0]['id'] ),
		);
	}
}
