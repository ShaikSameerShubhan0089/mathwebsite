<?php
/**
 * Irish-language search, sorting and faceting.
 *
 * RFT §13 and SRS §12.6 require that fadas display, store, sort and search
 * correctly, and that a query matches "both with and without fadas".
 *
 * Two findings worth stating plainly, because they shape the implementation:
 *
 * 1. SORTING. Irish needs no custom collation. Unlike Danish (å after z) or
 *    Swedish, Irish files accented vowels under their base letter — Ábhar,
 *    Achar, Airgead is correct Irish order, and that is exactly what MySQL's
 *    utf8mb4_unicode_ci already produces. So we sort in SQL and do not pay for
 *    a PHP-side sort. What we DO enforce is that the connection, tables and
 *    columns are utf8mb4 throughout; a latin1 column is where fadas die.
 *
 * 2. SEARCHING. MySQL's _ci collations are also accent-insensitive, so on a
 *    correctly configured install "codain" already matches "Codáin". That is
 *    load-bearing behaviour we cannot leave to chance across four years of
 *    hosting changes, so every item also carries a normalised shadow index in
 *    post meta and the search runs against that. If the collation is ever
 *    changed by a host migration, search keeps working.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_Search {

	public const META_INDEX = '_mata_search_index';

	public function __construct() {
		add_action( 'save_post', array( $this, 'rebuild_index' ), 20, 2 );
		add_action( 'set_object_terms', array( $this, 'rebuild_index_on_terms' ), 20, 1 );
		add_action( 'pre_get_posts', array( $this, 'apply_public_query' ) );
		add_filter( 'posts_search', array( $this, 'search_normalised' ), 10, 2 );
	}

	/**
	 * Fold a string to its searchable form: decompose, drop combining marks,
	 * lowercase. "Codáin" -> "codain".
	 *
	 * Transliterator is preferred (intl); the manual map is the fallback so the
	 * plugin does not hard-depend on a PHP extension the host may not enable.
	 */
	public static function fold( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		if ( class_exists( 'Transliterator' ) ) {
			$tr = Transliterator::create( 'NFD; [:Nonspacing Mark:] Remove; NFC; Lower' );
			if ( $tr ) {
				$folded = $tr->transliterate( $text );
				if ( is_string( $folded ) ) {
					return $folded;
				}
			}
		}

		// Fallback: the vowels Irish actually uses, upper and lower.
		$map = array(
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
			'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
			// Older orthography and loanwords.
			'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
			'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
			'ṁ' => 'm', 'ḃ' => 'b', 'ċ' => 'c', 'ḋ' => 'd', 'ḟ' => 'f',
			'ġ' => 'g', 'ṗ' => 'p', 'ṡ' => 's', 'ṫ' => 't',
		);

		return mb_strtolower( strtr( $text, $map ), 'UTF-8' );
	}

	/** Everything a teacher might reasonably type to find this item. */
	public static function build_index( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$parts = array(
			$post->post_title,
			$post->post_excerpt,
			wp_strip_all_tags( (string) $post->post_content ),
		);

		foreach ( array_keys( COGG_Mata_CPT::TAXONOMIES ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) ) {
				$parts = array_merge( $parts, $terms );
			}
		}

		return self::fold( implode( ' ', array_filter( $parts ) ) );
	}

	public function rebuild_index( int $post_id, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, COGG_Mata_Metadata::post_types(), true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		update_post_meta( $post_id, self::META_INDEX, self::build_index( $post_id ) );
	}

	public function rebuild_index_on_terms( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, COGG_Mata_Metadata::post_types(), true ) ) {
			update_post_meta( $post_id, self::META_INDEX, self::build_index( $post_id ) );
		}
	}

	/**
	 * Replace WordPress's default LIKE-on-post_title/content search with a match
	 * against the folded index, so an unaccented query hits accented content and
	 * vice versa.
	 */
	public function search_normalised( string $sql, WP_Query $query ): string {
		if ( is_admin() || ! $query->is_search() || ! $query->is_main_query() ) {
			return $sql;
		}

		$types = (array) $query->get( 'post_type' );
		if ( ! array_intersect( $types, COGG_Mata_Metadata::post_types() ) ) {
			return $sql;
		}

		global $wpdb;
		$terms = self::tokenise( (string) $query->get( 's' ) );
		if ( empty( $terms ) ) {
			return $sql;
		}

		$clauses = array();
		foreach ( $terms as $term ) {
			$clauses[] = $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$wpdb->postmeta} sm
				          WHERE sm.post_id = {$wpdb->posts}.ID
				            AND sm.meta_key = %s
				            AND sm.meta_value LIKE %s)",
				self::META_INDEX,
				'%' . $wpdb->esc_like( $term ) . '%'
			);
		}

		return ' AND ( ' . implode( ' AND ', $clauses ) . ' ) ';
	}

	/** @return string[] folded, de-duplicated query terms. */
	public static function tokenise( string $query ): array {
		$folded = self::fold( $query );
		$parts  = preg_split( '/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_filter( (array) $parts ) ) );
	}

	/**
	 * Faceted browse on the public archives (RFT §8.2.1).
	 * Query vars arrive as ?strand=uimhir&class_level=rang-1 and stack as AND
	 * across taxonomies, OR within one.
	 */
	public function apply_public_query( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive( COGG_Mata_Metadata::post_types() ) && ! $query->is_search() ) {
			return;
		}

		$tax_query = array( 'relation' => 'AND' );
		foreach ( COGG_Mata_CPT::TAXONOMIES as $taxonomy => $label ) {
			$param = str_replace( 'mata_', '', $taxonomy );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public filter
			$raw = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$slugs = array_filter( array_map( 'sanitize_title', explode( '|', $raw ) ) );
			if ( empty( $slugs ) ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
				'operator' => 'IN',
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$query->set( 'tax_query', $tax_query );
		}

		// Irish alphabetical order is the Unicode default — see the class docblock.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'az';
		switch ( $sort ) {
			case 'za':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'DESC' );
				break;
			case 'new':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			case 'popular':
				$query->set( 'meta_key', '_mata_downloads' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'DESC' );
				break;
			case 'az':
			default:
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
				break;
		}

		$query->set( 'posts_per_page', 24 );
	}

	/**
	 * Facet counts for the current filter state. Each group is counted with its
	 * own filter removed, so a count answers "what would I get if I ticked this"
	 * rather than "what is already selected".
	 *
	 * @return array<string,array<int,array{term:WP_Term,count:int}>>
	 */
	public static function facet_counts( array $active, array $post_types ): array {
		$out = array();

		foreach ( COGG_Mata_CPT::TAXONOMIES as $taxonomy => $label ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$others = $active;
			unset( $others[ $taxonomy ] );

			$rows = array();
			foreach ( $terms as $term ) {
				$tax_query = array( 'relation' => 'AND' );
				foreach ( $others as $tax => $slugs ) {
					$tax_query[] = array(
						'taxonomy' => $tax,
						'field'    => 'slug',
						'terms'    => (array) $slugs,
					);
				}
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array( $term->slug ),
				);

				$count = ( new WP_Query(
					array(
						'post_type'              => $post_types,
						'post_status'            => 'publish',
						'tax_query'              => $tax_query,
						'fields'                 => 'ids',
						'posts_per_page'         => 1,
						'no_found_rows'          => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				) )->found_posts;

				$selected = isset( $active[ $taxonomy ] ) && in_array( $term->slug, (array) $active[ $taxonomy ], true );
				if ( $count > 0 || $selected ) {
					$rows[] = array( 'term' => $term, 'count' => $count, 'selected' => $selected );
				}
			}

			if ( ! empty( $rows ) ) {
				$out[ $taxonomy ] = $rows;
			}
		}

		return $out;
	}

	/** Read the active facet selection out of the query string. */
	public static function active_facets(): array {
		$active = array();
		foreach ( array_keys( COGG_Mata_CPT::TAXONOMIES ) as $taxonomy ) {
			$param = str_replace( 'mata_', '', $taxonomy );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$slugs = array_filter( array_map( 'sanitize_title', explode( '|', $raw ) ) );
			if ( ! empty( $slugs ) ) {
				$active[ $taxonomy ] = $slugs;
			}
		}
		return $active;
	}
}
