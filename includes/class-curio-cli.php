<?php
/**
 * WP-CLI commands.
 *
 * @package Curio
 */

namespace Curio;

use Curio\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * `wp curio ...`
 *
 * Everything the settings screen does, available without a browser: seeding a
 * knowledge base, running the indexer, checking what the assistant knows,
 * storing a key. That matters because the interesting deployments are the
 * unattended ones — a provisioning script, a staging refresh, a client site
 * being set up for the fifth time — and clicking through six tabs by hand is
 * not a deployment method.
 *
 * Output here is deliberately not run through the translation functions.
 * WP-CLI output is read by an operator in a terminal, WP-CLI's own strings are
 * English, and putting a hundred operator-facing lines into the .pot would make
 * the translator's job harder for no reader's benefit.
 */
final class CLI {

	/**
	 * Register with WP-CLI when running under it.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'curio', __CLASS__ );
	}

	/**
	 * Show what the assistant currently knows and how it is configured.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio status
	 *     wp curio status --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function status( $args, $assoc_args ): void {
		$counts   = Knowledge_Store::counts_by_type();
		$provider = Registry::current();
		$usage    = Rate_Limiter::month_usage();
		$log      = Conversation_Log::totals();

		$rows = array(
			array( 'setting' => 'Widget', 'value' => Options::flag( 'enabled' ) ? 'on' : 'off' ),
			array( 'setting' => 'Provider', 'value' => $provider->label() ),
			array( 'setting' => 'Model', 'value' => Options::text( 'model' ) ?: '(provider default)' ),
			array( 'setting' => 'API key on file', 'value' => Secret::exists( $provider->slug() ) ? 'yes' : 'no' ),
			array( 'setting' => 'Keys encrypted at rest', 'value' => Secret::is_encrypted() ? 'yes' : 'no (no OpenSSL on this host)' ),
			array( 'setting' => 'Business name', 'value' => Options::text( 'business_name' ) ?: '(not set)' ),
			array( 'setting' => 'Hand-off line', 'value' => Options::text( 'contact_line' ) ?: '(not set)' ),
			array( 'setting' => 'Hand-off button', 'value' => Handoff::is_configured() ? Handoff::url() : '(none)' ),
			array( 'setting' => 'Written answers', 'value' => (string) ( $counts[ Knowledge_Store::SOURCE_MANUAL ] ?? 0 ) ),
			array( 'setting' => 'Indexed page passages', 'value' => (string) ( $counts[ Knowledge_Store::SOURCE_POST ] ?? 0 ) ),
			array( 'setting' => 'Indexed product passages', 'value' => (string) ( $counts[ Knowledge_Store::SOURCE_PRODUCT ] ?? 0 ) ),
			array( 'setting' => 'Total passages', 'value' => (string) Knowledge_Store::count() ),
			array( 'setting' => 'API calls this month', 'value' => (string) $usage['calls'] ),
			array( 'setting' => 'Answered from cache', 'value' => (string) $usage['cached'] ),
			array( 'setting' => 'Conversations stored', 'value' => (string) $log['total'] ),
			array( 'setting' => 'Unanswered questions', 'value' => (string) $log['unanswered'] ),
		);

		\WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'setting', 'value' )
		);

		if ( 0 === Knowledge_Store::count() ) {
			\WP_CLI::warning( 'The knowledge base is empty, so the assistant will decline every question. That is the correct default, not a fault — give it something with `wp curio import`.' );
		}
	}

	/**
	 * Import knowledge entries from a JSON file.
	 *
	 * Accepts either title/content or question/answer key names, so an export
	 * from most FAQ tools imports without editing.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON file containing an array of entries.
	 *
	 * [--replace]
	 * : Delete existing hand-written entries first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio import demo-knowledge.json
	 *     wp curio import faq.json --replace
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function import( $args, $assoc_args ): void {
		$file = $args[0] ?? '';

		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read {$file}" );
		}

		// A local path the operator typed, read in a terminal they are sitting
		// at. WP_Filesystem exists for the case where the web server may not
		// own the file; it does not apply here and would only add a credentials
		// prompt to a non-interactive script.
		$raw  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an operator-supplied local path under WP-CLI.
		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			\WP_CLI::error( 'That is not valid JSON: ' . json_last_error_msg() );
		}
		if ( ! is_array( $data ) ) {
			\WP_CLI::error( 'The JSON must be an array of entries.' );
		}

		if ( ! empty( $assoc_args['replace'] ) ) {
			$removed = Knowledge_Store::clear_type( Knowledge_Store::SOURCE_MANUAL );
			\WP_CLI::log( "Removed {$removed} existing written answers." );
		}

		$imported     = 0;
		$skipped      = 0;
		$placeholders = 0;

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				++$skipped;
				continue;
			}

			$title   = trim( sanitize_text_field( (string) ( $entry['title'] ?? $entry['question'] ?? '' ) ) );
			$content = trim( sanitize_textarea_field( (string) ( $entry['content'] ?? $entry['answer'] ?? '' ) ) );

			if ( '' === $content ) {
				++$skipped;
				continue;
			}

			// A template placeholder that reached a live knowledge base would be
			// read out to a customer verbatim. Refusing to import it is the
			// same instinct the whole plugin is built on.
			if ( false !== strpos( $content, '[[ FILL IN' ) ) {
				++$placeholders;
				++$skipped;
				continue;
			}

			if ( '' === $title ) {
				$title = Text::truncate( $content, 60 );
			}

			$id = Knowledge_Store::upsert(
				array(
					'ref'         => Knowledge_Store::SOURCE_MANUAL . ':' . md5( $title . '|' . $content ),
					'source_type' => Knowledge_Store::SOURCE_MANUAL,
					'title'       => $title,
					'content'     => $content,
					'keywords'    => $entry['keywords'] ?? array(),
					'url'         => (string) ( $entry['url'] ?? '' ),
				)
			);

			if ( $id > 0 ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		if ( $placeholders > 0 ) {
			\WP_CLI::warning( "Skipped {$placeholders} entr" . ( 1 === $placeholders ? 'y' : 'ies' ) . " still containing a [[ FILL IN ]] placeholder. Fill them in or delete them — an assistant that reads a placeholder to a customer is worse than one that declines." );
		}

		\WP_CLI::success( "Imported {$imported}, skipped {$skipped}. The knowledge base now holds " . Knowledge_Store::count() . ' passages.' );
	}

	/**
	 * Export hand-written knowledge entries as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Write to this path instead of standard output.
	 *
	 * [--all]
	 * : Include indexed site content as well as written answers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio export
	 *     wp curio export backup.json --all
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function export( $args, $assoc_args ): void {
		$items = Knowledge_Store::export( empty( $assoc_args['all'] ) );
		$json  = wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( isset( $args[0] ) && '' !== $args[0] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to an operator-supplied local path under WP-CLI.
			file_put_contents( $args[0], (string) $json );
			\WP_CLI::success( 'Wrote ' . count( $items ) . " entries to {$args[0]}" );
			return;
		}

		\WP_CLI::line( (string) $json );
	}

	/**
	 * Index the site content selected on the Sources tab.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Items per batch.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio reindex
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function reindex( $args, $assoc_args ): void {
		$state = Indexer::start();
		$total = (int) $state['total'];

		if ( 0 === $total ) {
			\WP_CLI::warning( 'Nothing to index. Choose some post types first: wp curio set index_post_types page,post' );
			return;
		}

		$batch    = max( 1, (int) ( $assoc_args['batch'] ?? 20 ) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing', $total );

		while ( ! empty( $state['running'] ) ) {
			$before = (int) $state['done'];
			$state  = Indexer::run_batch( $batch );
			$progress->tick( max( 0, (int) $state['done'] - $before ) );
		}

		$progress->finish();
		\WP_CLI::success( "Indexed {$state['done']} items into {$state['stored']} passages. Total now " . Knowledge_Store::count() . '.' );
	}

	/**
	 * Change a setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting name, as used on the settings screen.
	 *
	 * <value>
	 * : New value. Comma separated for list settings; 1/0 for switches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio set business_name "TechNerdXp"
	 *     wp curio set index_post_types page,post
	 *     wp curio set provider anthropic
	 *     wp curio set enabled 1
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function set( $args, $assoc_args ): void {
		$key   = (string) ( $args[0] ?? '' );
		$value = (string) ( $args[1] ?? '' );

		$defaults = Options::defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			\WP_CLI::error( "Unknown setting '{$key}'. Run `wp curio settings` for the full list." );
		}

		$typed = $value;
		if ( is_bool( $defaults[ $key ] ) ) {
			$typed = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		} elseif ( is_array( $defaults[ $key ] ) ) {
			$typed = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
		} elseif ( is_int( $defaults[ $key ] ) ) {
			$typed = (int) $value;
		}

		$saved = Options::update( array( $key => $typed ) );
		$now   = $saved[ $key ];

		if ( is_bool( $now ) ) {
			$now = $now ? '1' : '0';
		} elseif ( is_array( $now ) ) {
			$now = implode( ',', $now );
		}

		\WP_CLI::success( "{$key} = {$now}" );
	}

	/**
	 * List every setting and its current value.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function settings( $args, $assoc_args ): void {
		$rows = array();
		foreach ( Options::all() as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			} elseif ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}
			$rows[] = array(
				'setting' => (string) $key,
				'value'   => Text::truncate( (string) $value, 60 ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'setting', 'value' ) );
	}

	/**
	 * Store an API key for a provider.
	 *
	 * The key is encrypted at rest where the host supports it, and is never
	 * printed back.
	 *
	 * ## OPTIONS
	 *
	 * <provider>
	 * : anthropic, openai or gemini.
	 *
	 * [<key>]
	 * : The API key. Omit to read it from standard input, which keeps it out of
	 * your shell history.
	 *
	 * [--test]
	 * : Verify the key against the provider after saving it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio key anthropic --test < key.txt
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function key( $args, $assoc_args ): void {
		$slug     = sanitize_key( (string) ( $args[0] ?? '' ) );
		$provider = Registry::get( $slug );

		if ( ! $provider || 'demo' === $slug ) {
			\WP_CLI::error( "Unknown provider '{$slug}'. Use anthropic, openai or gemini." );
		}

		$key = (string) ( $args[1] ?? '' );
		if ( '' === $key ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading standard input under WP-CLI, so the key never reaches shell history.
			$key = trim( (string) file_get_contents( 'php://stdin' ) );
		}
		if ( '' === $key ) {
			\WP_CLI::error( 'No key supplied.' );
		}

		Secret::put( $slug, $key );
		\WP_CLI::success( "Stored a key for {$slug} (" . Secret::mask( $slug ) . ')' );

		if ( empty( $assoc_args['test'] ) ) {
			return;
		}

		$result = $provider->test();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( 'The provider rejected it: ' . $result->get_error_message() );
		}
		\WP_CLI::success( 'The provider answered normally.' );
	}

	/**
	 * Empty the knowledge base, or part of it.
	 *
	 * ## OPTIONS
	 *
	 * [<scope>]
	 * : manual, post, product, or all.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function clear( $args, $assoc_args ): void {
		$scope = sanitize_key( (string) ( $args[0] ?? 'all' ) );

		\WP_CLI::confirm( "Delete knowledge scope '{$scope}'? There is no undo.", $assoc_args );

		if ( in_array( $scope, array( Knowledge_Store::SOURCE_MANUAL, Knowledge_Store::SOURCE_POST, Knowledge_Store::SOURCE_PRODUCT ), true ) ) {
			$removed = Knowledge_Store::clear_type( $scope );
			\WP_CLI::success( "Removed {$removed} passages." );
			return;
		}

		Knowledge_Store::clear_all();
		\WP_CLI::success( 'The knowledge base is empty. The assistant now declines everything, which is the correct state for a fresh install.' );
	}

	/**
	 * Ask the assistant a question, exactly as a visitor would.
	 *
	 * The fastest way to tell whether a deployment actually works, and whether
	 * it declines where it should.
	 *
	 * ## OPTIONS
	 *
	 * <question>
	 * : What to ask.
	 *
	 * ## EXAMPLES
	 *
	 *     wp curio ask "what does Curio do?"
	 *     wp curio ask "how much is a bespoke build?"
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function ask( $args, $assoc_args ): void {
		$question = trim( (string) ( $args[0] ?? '' ) );
		if ( '' === $question ) {
			\WP_CLI::error( 'Ask it something.' );
		}

		$result = Chat::answer( $question, array(), '' );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9Q:%n ' ) . $question );
		\WP_CLI::line( \WP_CLI::colorize( '%9A:%n ' ) . $result['answer'] );

		if ( ! empty( $result['sources'] ) ) {
			\WP_CLI::line( '' );
			foreach ( $result['sources'] as $source ) {
				\WP_CLI::line( '    source: ' . $source['title'] . ' — ' . $source['url'] );
			}
		}

		\WP_CLI::line( '' );

		// Three outcomes, not two. A greeting is ungrounded and yet was not
		// declined, and reporting it as a decline would send somebody looking
		// for a hole in a knowledge base that has none.
		if ( ! empty( $result['declined'] ) ) {
			$verdict = '    (declined — nothing in the knowledge base matched, so no AI request was made)';
		} elseif ( empty( $result['grounded'] ) ) {
			$verdict = '    (greeted — recognised as a greeting, so no retrieval and no AI request)';
		} else {
			$verdict = '    (grounded in retrieved knowledge)';
		}

		\WP_CLI::line( $verdict );
		\WP_CLI::line( '' );
	}
}
