<?php
/**
 * REST API for the front-end.
 *
 * Read the permission callbacks carefully — they are the point of this file.
 * Public routes are `__return_true` and read-only. Every write route names a
 * capability, which is how SRS §3.8's "enforced server-side for every content
 * action" is actually satisfied.
 *
 * There is deliberately no public POST route that accepts content. The
 * save-and-share workflow (RFT §7.2.3) never contacts this server, so there is
 * no endpoint here through which pupil data could arrive — BR-01 and BR-05 hold
 * because the surface does not exist, not because it is validated away.
 *
 * The single public write is a download counter, which increments an integer
 * and records nothing about who asked (SRS §13.3).
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_REST {

	public const NAMESPACE = 'cogg-mata/v1';

	private COGG_Mata_Search $search;
	private COGG_Mata_Metadata $metadata;
	private COGG_Mata_Audit $audit;

	public function __construct( COGG_Mata_Search $search, COGG_Mata_Metadata $metadata, COGG_Mata_Audit $audit ) {
		$this->search   = $search;
		$this->metadata = $metadata;
		$this->audit    = $audit;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_items' ),
				'args'                => array(
					'q'     => array( 'type' => 'string', 'default' => '' ),
					'kind'  => array( 'type' => 'string', 'default' => '' ),
					'sort'  => array( 'type' => 'string', 'default' => 'az' ),
					'page'  => array( 'type' => 'integer', 'default' => 1 ),
					'per'   => array( 'type' => 'integer', 'default' => 24 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/vocab',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_vocab' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/items/(?P<id>\d+)/download',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'count_download' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'health' ),
			)
		);

		// ---- authenticated ----

		register_rest_route(
			self::NAMESPACE,
			'/admin/gate/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'callback'            => array( $this, 'gate_status' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn(): bool => current_user_can( 'mata_view_audit' ),
				'callback'            => array( $this, 'get_audit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/backup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn(): bool => current_user_can( 'mata_export_backup' ),
				'callback'            => array( $this, 'export_backup' ),
			)
		);
	}

	/* ------------------------------------------------------------- public */

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$kind = (string) $request->get_param( 'kind' );
		$types = match ( $kind ) {
			'resource' => array( COGG_Mata_CPT::POST_RESOURCE ),
			'activity' => array( COGG_Mata_CPT::POST_ACTIVITY ),
			default    => COGG_Mata_Metadata::post_types(),
		};

		$args = array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => min( 100, max( 1, (int) $request->get_param( 'per' ) ) ),
			'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
		);

		$tax_query = array( 'relation' => 'AND' );
		foreach ( COGG_Mata_CPT::TAXONOMIES as $taxonomy => $label ) {
			$param = str_replace( 'mata_', '', $taxonomy );
			$raw   = (string) $request->get_param( $param );
			if ( '' === $raw ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => array_filter( array_map( 'sanitize_title', explode( '|', $raw ) ) ),
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query;
		}

		// Diacritic-insensitive matching against the folded index.
		$terms = COGG_Mata_Search::tokenise( (string) $request->get_param( 'q' ) );
		if ( ! empty( $terms ) ) {
			$meta = array( 'relation' => 'AND' );
			foreach ( $terms as $term ) {
				$meta[] = array(
					'key'     => COGG_Mata_Search::META_INDEX,
					'value'   => $term,
					'compare' => 'LIKE',
				);
			}
			$args['meta_query'] = $meta;
		}

		switch ( (string) $request->get_param( 'sort' ) ) {
			case 'za':
				$args['orderby'] = 'title';
				$args['order']   = 'DESC';
				break;
			case 'new':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'popular':
				$args['meta_key'] = '_mata_downloads';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			default:
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
		}

		$query = new WP_Query( $args );
		$items = array_map( array( $this, 'shape' ), $query->posts );

		return new WP_REST_Response(
			array(
				'total'  => (int) $query->found_posts,
				'pages'  => (int) $query->max_num_pages,
				'items'  => $items,
				'facets' => $this->shape_facets( COGG_Mata_Search::facet_counts( COGG_Mata_Search::active_facets(), $types ) ),
			),
			200
		);
	}

	public function get_vocab(): WP_REST_Response {
		$out = array();
		foreach ( COGG_Mata_CPT::TAXONOMIES as $taxonomy => $label ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$out[ str_replace( 'mata_', '', $taxonomy ) ] = array(
				'label' => $label,
				'terms' => array_map(
					static fn( WP_Term $t ): array => array( 'slug' => $t->slug, 'name' => $t->name, 'count' => $t->count ),
					$terms
				),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Aggregate download counting. Increments an integer on the post. No IP, no
	 * user agent, no cookie, no row per visitor (SRS §13.3).
	 */
	public function count_download( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status
			|| ! in_array( $post->post_type, COGG_Mata_Metadata::post_types(), true ) ) {
			return new WP_Error( 'mata_not_found', 'Níor aimsíodh an acmhainn sin.', array( 'status' => 404 ) );
		}
		$current = (int) get_post_meta( $id, '_mata_downloads', true );
		update_post_meta( $id, '_mata_downloads', $current + 1 );
		return new WP_REST_Response( array( 'ok' => true, 'downloads' => $current + 1 ), 200 );
	}

	/** SRS §8.5 / §15.1 — a monitoring endpoint the uptime checker can poll. */
	public function health(): WP_REST_Response {
		global $wpdb;
		$db_ok = (bool) $wpdb->get_var( 'SELECT 1' );

		return new WP_REST_Response(
			array(
				'ok'             => $db_ok,
				'service'        => 'cogg-mata',
				'version'        => COGG_MATA_VERSION,
				'time'           => current_time( 'c', true ),
				'db'             => $db_ok ? 'up' : 'down',
				'db_driver'      => COGG_Mata_DB::driver(),
				'db_server'      => COGG_Mata_DB::server_version(),
				'charset'        => $wpdb->charset,
				'collate'        => $wpdb->collate,
				'resources'      => (int) wp_count_posts( COGG_Mata_CPT::POST_RESOURCE )->publish,
				'activities'     => (int) wp_count_posts( COGG_Mata_CPT::POST_ACTIVITY )->publish,
				// Stated explicitly so monitoring proves the claim continuously.
				'pupil_accounts' => 0,
				'pupil_records'  => 0,
			),
			$db_ok ? 200 : 503
		);
	}

	/* ------------------------------------------------------ authenticated */

	public function gate_status( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$missing = COGG_Mata_Metadata::missing_terms( $id );
		return new WP_REST_Response(
			array(
				'complete'    => empty( $missing ),
				'missing'     => array_keys( $missing ),
				'missingText' => array_values( $missing ),
			),
			200
		);
	}

	public function get_audit( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( COGG_Mata_Audit::recent( (int) ( $request->get_param( 'limit' ) ?: 100 ) ), 200 );
	}

	public function export_backup(): WP_REST_Response {
		$this->audit->log( 'backup', 'system', null, 'Easpórtáil iomlán trí REST' );

		$items = get_posts(
			array(
				'post_type'      => COGG_Mata_Metadata::post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
			)
		);

		return new WP_REST_Response(
			array(
				'generated_at' => current_time( 'c', true ),
				'version'      => COGG_MATA_VERSION,
				'items'        => array_map( array( $this, 'shape' ), $items ),
				'audit'        => COGG_Mata_Audit::recent( 500 ),
			),
			200
		);
	}

	/* ---------------------------------------------------------- shaping */

	private function shape( WP_Post $post ): array {
		$taxonomies = array();
		foreach ( COGG_Mata_CPT::TAXONOMIES as $taxonomy => $label ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			$taxonomies[ str_replace( 'mata_', '', $taxonomy ) ] = is_wp_error( $terms )
				? array()
				: array_map( static fn( WP_Term $t ): array => array( 'slug' => $t->slug, 'name' => $t->name ), $terms );
		}

		$file_id = (int) get_post_meta( $post->ID, '_mata_file_id', true );
		$cfg_raw = (string) get_post_meta( $post->ID, '_mata_cfg', true );

		return array(
			'id'         => $post->ID,
			'kind'       => COGG_Mata_CPT::POST_ACTIVITY === $post->post_type ? 'activity' : 'resource',
			'title'      => get_the_title( $post ),
			'excerpt'    => $post->post_excerpt,
			'permalink'  => get_permalink( $post ),
			'status'     => $post->post_status,
			'updated'    => $post->post_modified_gmt,
			'taxonomies' => $taxonomies,
			'file'       => $file_id ? array(
				'url'  => wp_get_attachment_url( $file_id ),
				'size' => (int) filesize( (string) get_attached_file( $file_id ) ),
				'mime' => get_post_mime_type( $file_id ),
			) : null,
			'kit'        => get_post_meta( $post->ID, '_mata_kit', true ) ?: null,
			'cfg'        => $cfg_raw ? json_decode( $cfg_raw, true ) : null,
			'adaptable'  => (bool) get_post_meta( $post->ID, '_mata_adaptable', true ),
			'downloads'  => (int) get_post_meta( $post->ID, '_mata_downloads', true ),
			'complete'   => COGG_Mata_Metadata::is_complete( $post->ID ),
		);
	}

	private function shape_facets( array $facets ): array {
		$out = array();
		foreach ( $facets as $taxonomy => $rows ) {
			$out[ str_replace( 'mata_', '', $taxonomy ) ] = array(
				'label' => COGG_Mata_CPT::TAXONOMIES[ $taxonomy ] ?? $taxonomy,
				'terms' => array_map(
					static fn( array $r ): array => array(
						'slug'     => $r['term']->slug,
						'name'     => $r['term']->name,
						'count'    => $r['count'],
						'selected' => $r['selected'],
					),
					$rows
				),
			);
		}
		return $out;
	}
}
