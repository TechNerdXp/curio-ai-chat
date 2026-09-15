<?php
/**
 * Site content indexing.
 *
 * @package Curio
 */

namespace Curio;

defined( 'ABSPATH' ) || exit;

/**
 * Turns published site content into knowledge rows.
 *
 * Two paths, deliberately: a single-item path that runs on save so an edited
 * page is current within seconds, and a batched path the admin screen drives
 * so a first index of a large site cannot time out a request or a cron tick.
 * There is no "index everything on activation" — that is how plugins earn a
 * reputation for taking sites down.
 */
final class Indexer {

	private const BATCH_OPTION = Options::INDEX_STATE;

	/**
	 * Which post types the owner has opted into, filtered to what exists now.
	 *
	 * @return string[]
	 */
	public static function selected_post_types(): array {
		$selected  = (array) Options::get( 'index_post_types', array() );
		$available = array_keys( self::available_post_types() );
		return array_values( array_intersect( $selected, $available ) );
	}

	/**
	 * Post types a site owner may sensibly index.
	 *
	 * Public types only. An assistant that answers the public should not be
	 * reading from a private or internal post type, whatever a settings form
	 * was persuaded to submit.
	 *
	 * @return array<string,string> Slug to label.
	 */
	public static function available_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( (array) $types as $slug => $type ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			$out[ $slug ] = isset( $type->labels->name ) ? (string) $type->labels->name : (string) $slug;
		}
		return $out;
	}

	/**
	 * Is WooCommerce present and switched on for indexing?
	 *
	 * @return bool
	 */
	public static function products_enabled(): bool {
		return Options::flag( 'index_products' ) && self::woocommerce_active();
	}

	/**
	 * Is WooCommerce active at all?
	 *
	 * @return bool
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Index one post, replacing whatever was stored for it.
	 *
	 * @param int $post_id Post id.
	 * @return int Chunks stored.
	 */
	public static function index_post( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		$is_product = self::woocommerce_active() && 'product' === $post->post_type;

		if ( $is_product ) {
			if ( ! self::products_enabled() ) {
				self::remove_post( $post_id );
				return 0;
			}
		} elseif ( ! in_array( $post->post_type, self::selected_post_types(), true ) ) {
			self::remove_post( $post_id );
			return 0;
		}

		// Anything not publicly readable is removed rather than skipped, so
		// unpublishing a page actually takes it out of the assistant's mouth.
		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			self::remove_post( $post_id );
			return 0;
		}

		/**
		 * Filter whether a single post should be indexed.
		 *
		 * @since 1.0.0
		 *
		 * @param bool     $index Whether to index.
		 * @param \WP_Post $post  The post.
		 */
		if ( ! apply_filters( 'curio_should_index_post', true, $post ) ) {
			self::remove_post( $post_id );
			return 0;
		}

		$body = $is_product ? self::product_body( $post ) : self::post_body( $post );
		$body = trim( $body );
		if ( '' === $body ) {
			self::remove_post( $post_id );
			return 0;
		}

		$source_type = $is_product ? Knowledge_Store::SOURCE_PRODUCT : Knowledge_Store::SOURCE_POST;
		$title       = wp_strip_all_tags( get_the_title( $post ) );
		$url         = (string) get_permalink( $post );
		$chunks      = Text::chunk( $body, 900, 120 );

		$stored = 0;
		foreach ( $chunks as $position => $chunk ) {
			// The title is prepended to every chunk. A paragraph three
			// screens into a page rarely repeats what the page is about, and
			// without it "how much is it" retrieves the paragraph and the
			// model has no idea what "it" was.
			$content = '' !== $title ? $title . "\n\n" . $chunk : $chunk;

			$id = Knowledge_Store::upsert(
				array(
					'ref'         => $source_type . ':' . $post->ID . ':' . $position,
					'source_type' => $source_type,
					'source_id'   => $post->ID,
					'chunk_index' => (int) $position,
					'title'       => $title,
					'content'     => $content,
					'url'         => $url,
					'keywords'    => Text::keywords( $title . ' ' . $chunk, 16 ),
				)
			);
			if ( $id > 0 ) {
				++$stored;
			}
		}

		// A page that got shorter must lose its old tail chunks.
		Knowledge_Store::prune_chunks( $source_type, $post->ID, count( $chunks ) );

		return $stored;
	}

	/**
	 * Remove everything stored for a post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function remove_post( int $post_id ): void {
		Knowledge_Store::delete_source( Knowledge_Store::SOURCE_POST, $post_id );
		Knowledge_Store::delete_source( Knowledge_Store::SOURCE_PRODUCT, $post_id );
	}

	/**
	 * Readable text for an ordinary post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function post_body( \WP_Post $post ): string {
		$excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );

		if ( Options::flag( 'index_excerpt_only' ) && '' !== $excerpt ) {
			return $excerpt;
		}

		$body = Text::normalize( (string) $post->post_content );
		if ( '' !== $excerpt && false === strpos( $body, $excerpt ) ) {
			$body = $excerpt . "\n\n" . $body;
		}
		return $body;
	}

	/**
	 * Readable text for a WooCommerce product.
	 *
	 * Price, stock and SKU are written out as sentences rather than left in
	 * the markup, because "£45.00" on its own in a chunk is a number with no
	 * claim attached and the model cannot safely use it. "The price is £45.00."
	 * is a fact it can quote.
	 *
	 * @param \WP_Post $post Product post.
	 * @return string
	 */
	private static function product_body( \WP_Post $post ): string {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return self::post_body( $post );
		}

		$lines = array();

		$short = Text::normalize( (string) $product->get_short_description() );
		if ( '' !== $short ) {
			$lines[] = $short;
		}

		$long = Text::normalize( (string) $product->get_description() );
		if ( '' !== $long ) {
			$lines[] = $long;
		}

		$price = trim( wp_strip_all_tags( (string) $product->get_price_html() ) );
		if ( '' !== $price ) {
			/* translators: %s: product price, already formatted by WooCommerce. */
			$lines[] = sprintf( __( 'Price: %s', 'curio-ai-chat' ), $price );
		}

		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku ) {
			/* translators: %s: product SKU. */
			$lines[] = sprintf( __( 'SKU: %s', 'curio-ai-chat' ), $sku );
		}

		$lines[] = $product->is_in_stock()
			? __( 'Availability: in stock.', 'curio-ai-chat' )
			: __( 'Availability: out of stock.', 'curio-ai-chat' );

		foreach ( array( 'product_cat' => __( 'Categories', 'curio-ai-chat' ), 'product_tag' => __( 'Tags', 'curio-ai-chat' ) ) as $taxonomy => $label ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( is_array( $terms ) && array() !== $terms ) {
				$names   = wp_list_pluck( $terms, 'name' );
				$lines[] = $label . ': ' . implode( ', ', array_map( 'wp_strip_all_tags', (array) $names ) ) . '.';
			}
		}

		return implode( "\n\n", array_filter( $lines, 'strlen' ) );
	}

	// -----------------------------------------------------------------------
	// Batched full re-index.
	// -----------------------------------------------------------------------

	/**
	 * Build the work queue for a full re-index.
	 *
	 * @return array<string,mixed> The new state.
	 */
	public static function start(): array {
		$types = self::selected_post_types();
		if ( self::products_enabled() && ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}

		if ( array() === $types ) {
			// Nothing selected means nothing auto-indexed. Clear out anything
			// a previous configuration left behind, or the assistant keeps
			// answering from sources the owner has just switched off.
			Knowledge_Store::clear_type( Knowledge_Store::SOURCE_POST );
			Knowledge_Store::clear_type( Knowledge_Store::SOURCE_PRODUCT );
			$state = self::empty_state();
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		$ids = get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'has_password'           => false,
				// get_posts() defaults this to true, which bypasses every
				// pre_get_posts/posts_where filter on the site. Plugin Check
				// rejects that outright, and it is the wrong behaviour here
				// anyway: a multilingual or access-control plugin filtering
				// posts out has a reason, and an indexer that ignores it
				// quietly indexes content the site does not otherwise serve.
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$ids = array_values( array_map( 'absint', (array) $ids ) );

		$state = array(
			'queue'   => $ids,
			'total'   => count( $ids ),
			'done'    => 0,
			'stored'  => 0,
			'running' => count( $ids ) > 0,
			'started' => time(),
		);
		update_option( self::BATCH_OPTION, $state, false );

		// Remove rows for content that no longer qualifies before adding the
		// new ones, so a run always ends in a consistent state even if the
		// owner navigates away half way through.
		self::purge_orphans( $ids );

		return $state;
	}

	/**
	 * Process the next slice of the queue.
	 *
	 * @param int $size How many items to handle.
	 * @return array<string,mixed> The updated state.
	 */
	public static function run_batch( int $size = 10 ): array {
		$state = self::state();
		if ( empty( $state['running'] ) || array() === $state['queue'] ) {
			$state['running'] = false;
			update_option( self::BATCH_OPTION, $state, false );
			return $state;
		}

		$size  = max( 1, min( 50, $size ) );
		$slice = array_splice( $state['queue'], 0, $size );

		foreach ( $slice as $post_id ) {
			$state['stored'] += self::index_post( (int) $post_id );
			++$state['done'];
		}

		$state['running'] = array() !== $state['queue'];
		update_option( self::BATCH_OPTION, $state, false );

		return $state;
	}

	/**
	 * Current batch state.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$state = get_option( self::BATCH_OPTION, array() );
		if ( ! is_array( $state ) ) {
			return self::empty_state();
		}
		return array_merge( self::empty_state(), $state );
	}

	/**
	 * A zeroed state.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_state(): array {
		return array(
			'queue'   => array(),
			'total'   => 0,
			'done'    => 0,
			'stored'  => 0,
			'running' => false,
			'started' => 0,
		);
	}

	/**
	 * Drop indexed rows whose source is no longer in scope.
	 *
	 * @param int[] $keep_ids Post ids that are still eligible.
	 * @return int Rows removed.
	 */
	public static function purge_orphans( array $keep_ids ): int {
		global $wpdb;

		$table = Installer::knowledge_table();
		$types = array( Knowledge_Store::SOURCE_POST, Knowledge_Store::SOURCE_PRODUCT );

		if ( array() === $keep_ids ) {
			$removed = Knowledge_Store::clear_type( Knowledge_Store::SOURCE_POST );
			return $removed + Knowledge_Store::clear_type( Knowledge_Store::SOURCE_PRODUCT );
		}

		$keep_ids     = array_values( array_filter( array_map( 'absint', $keep_ids ) ) );
		$id_slots     = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
		$type_slots   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$bound_values = array_merge( $types, $keep_ids );

		$sql = "DELETE FROM `{$table}` WHERE source_type IN ({$type_slots}) AND source_id NOT IN ({$id_slots})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder lists are generated from counts; every value is bound.
		$removed = (int) $wpdb->query( $wpdb->prepare( $sql, $bound_values ) );

		if ( $removed > 0 ) {
			Cache::flush();
		}
		return $removed;
	}
}
