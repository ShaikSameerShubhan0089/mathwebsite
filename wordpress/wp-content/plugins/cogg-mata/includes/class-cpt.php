<?php
/**
 * Content types and the curriculum taxonomy.
 *
 * RFT §8.2.3 lists the metadata fields COGG wants; each becomes a real WordPress
 * taxonomy rather than a free-text custom field. That is the whole point of
 * SRS §3.7: "Controlled vocabularies are owned and editable by COGG, not
 * hard-coded by the Vendor, so the taxonomy can evolve." Registering them as
 * taxonomies means COGG edits terms in wp-admin with no developer involved, and
 * faceted queries stay indexed as the library grows.
 *
 * Resources and digital activities are separate post types but share every
 * taxonomy, so they land in one search index (SRS §3.10, §16.1) — a teacher
 * never has to search two systems.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_CPT {

	public const POST_RESOURCE = 'mata_resource';
	public const POST_ACTIVITY = 'mata_activity';

	/** Taxonomy slug => admin label. Shared by both post types. */
	public const TAXONOMIES = array(
		'mata_class_level'   => 'Rangleibhéal',
		'mata_strand'        => 'Snáithe',
		'mata_strand_unit'   => 'Aonad snáithe',
		'mata_topic'         => 'Topaic',
		'mata_resource_type' => 'Cineál acmhainne',
		'mata_learning_focus'=> 'Fócas foghlama',
		'mata_curriculum'    => 'Ailíniú curaclaim',
		'mata_format'        => 'Formáid',
	);

	/**
	 * Taxonomies that must carry a term before an item may be published.
	 * BR-03. Learning focus and curriculum alignment are deliberately NOT here:
	 * RFT §8.2.3 lists them as fields that "may include", so they are optional
	 * until COGG says otherwise in Phase 2 planning.
	 */
	public const REQUIRED_TAXONOMIES = array(
		'mata_class_level',
		'mata_strand',
		'mata_strand_unit',
		'mata_topic',
		'mata_resource_type',
		'mata_format',
	);

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ), 5 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 6 );
		add_filter( 'manage_' . self::POST_RESOURCE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_filter( 'manage_' . self::POST_ACTIVITY . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_RESOURCE . '_posts_custom_column', array( $this, 'admin_column_body' ), 10, 2 );
		add_action( 'manage_' . self::POST_ACTIVITY . '_posts_custom_column', array( $this, 'admin_column_body' ), 10, 2 );
	}

	public function register_post_types(): void {
		register_post_type(
			self::POST_RESOURCE,
			array(
				'labels'              => array(
					'name'          => 'Acmhainní',
					'singular_name' => 'Acmhainn',
					'add_new_item'  => 'Cuir acmhainn nua leis',
					'edit_item'     => 'Cuir an acmhainn in eagar',
					'search_items'  => 'Cuardaigh acmhainní',
					'not_found'     => 'Níor aimsíodh aon acmhainn.',
				),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-media-document',
				'menu_position'       => 21,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author' ),
				'has_archive'         => 'acmhainni',
				'rewrite'             => array( 'slug' => 'acmhainn', 'with_front' => false ),
				'capability_type'     => array( 'mata_resource', 'mata_resources' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => false,
			)
		);

		register_post_type(
			self::POST_ACTIVITY,
			array(
				'labels'          => array(
					'name'          => 'Gníomhaíochtaí',
					'singular_name' => 'Gníomhaíocht',
					'add_new_item'  => 'Cuir gníomhaíocht nua leis',
					'edit_item'     => 'Cuir an ghníomhaíocht in eagar',
					'search_items'  => 'Cuardaigh gníomhaíochtaí',
					'not_found'     => 'Níor aimsíodh aon ghníomhaíocht.',
				),
				'public'          => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-welcome-learn-more',
				'menu_position'   => 22,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author' ),
				'has_archive'     => 'gniomhaiochtai',
				'rewrite'         => array( 'slug' => 'gniomhaiocht', 'with_front' => false ),
				'capability_type' => array( 'mata_activity', 'mata_activities' ),
				'map_meta_cap'    => true,
			)
		);

		$this->register_meta();
	}

	/**
	 * Post meta. Note what these fields carry: activity configuration and
	 * editorial flags. There is no meta key here — and by design never will be —
	 * that holds anything about a pupil (BR-05).
	 */
	private function register_meta(): void {
		$common = array(
			'show_in_rest' => true,
			'single'       => true,
			'auth_callback'=> static fn(): bool => current_user_can( 'edit_posts' ),
		);

		register_post_meta( self::POST_ACTIVITY, '_mata_kit', $common + array( 'type' => 'string' ) );
		register_post_meta( self::POST_ACTIVITY, '_mata_cfg', $common + array( 'type' => 'string' ) );
		register_post_meta( self::POST_ACTIVITY, '_mata_adaptable', $common + array( 'type' => 'boolean' ) );
		register_post_meta( self::POST_ACTIVITY, '_mata_h5p_id', $common + array( 'type' => 'integer' ) );

		register_post_meta( self::POST_RESOURCE, '_mata_file_id', $common + array( 'type' => 'integer' ) );
		register_post_meta( self::POST_RESOURCE, '_mata_downloads', $common + array( 'type' => 'integer' ) );
		register_post_meta( self::POST_RESOURCE, '_mata_external_url', $common + array( 'type' => 'string' ) );
		register_post_meta( self::POST_RESOURCE, '_mata_link_checked', $common + array( 'type' => 'string' ) );
		register_post_meta( self::POST_RESOURCE, '_mata_a11y_flag', $common + array( 'type' => 'string' ) );

		/*
		 * English title and description, per item.
		 *
		 * RFT §13 makes Irish the site's first language and SRS §1.10 puts
		 * translation with COGG rather than the Vendor. So the platform provides
		 * the fields and COGG supplies the words — an English rendering is
		 * COGG-authored content like any other, and passes through the same
		 * editorial approval as the Irish (BR-02). Empty means "not translated
		 * yet", and the Irish shows instead.
		 */
		foreach ( array( self::POST_RESOURCE, self::POST_ACTIVITY ) as $type ) {
			register_post_meta( $type, '_mata_title_en', $common + array( 'type' => 'string' ) );
			register_post_meta( $type, '_mata_excerpt_en', $common + array( 'type' => 'string' ) );
		}
	}

	public function register_taxonomies(): void {
		foreach ( self::TAXONOMIES as $slug => $label ) {
			register_taxonomy(
				$slug,
				array( self::POST_RESOURCE, self::POST_ACTIVITY ),
				array(
					'labels'            => array(
						'name'          => $label,
						'singular_name' => $label,
						'add_new_item'  => sprintf( 'Cuir %s nua leis', mb_strtolower( $label ) ),
						'search_items'  => sprintf( 'Cuardaigh %s', mb_strtolower( $label ) ),
					),
					'public'            => true,
					'hierarchical'      => true,   // a picker, not a free-text tag box
					'show_admin_column' => true,
					'show_in_rest'      => true,
					'query_var'         => true,
					'rewrite'           => array( 'slug' => str_replace( 'mata_', '', $slug ), 'with_front' => false ),
					'capabilities'      => array(
						'manage_terms' => 'mata_manage_taxonomy',
						'edit_terms'   => 'mata_manage_taxonomy',
						'delete_terms' => 'mata_manage_taxonomy',
						'assign_terms' => 'edit_posts',
					),
				)
			);
		}
	}

	/**
	 * Initial controlled vocabulary, seeded once on activation. COGG edits these
	 * freely afterwards — the plugin never re-asserts them, so an edit is never
	 * silently reverted by a plugin update.
	 */
	public function seed_vocabularies(): void {
		if ( get_option( 'cogg_mata_vocab_seeded' ) ) {
			return;
		}

		$seed = array(
			'mata_class_level'   => array( 'Naíonáin Bheaga', 'Naíonáin Mhóra', 'Rang 1', 'Rang 2', 'Rang 3', 'Rang 4', 'Rang 5', 'Rang 6' ),
			'mata_strand'        => array( 'Uimhir', 'Ailgéabar', 'Cruth agus Spás', 'Tomhas', 'Sonraí agus Seans' ),
			'mata_strand_unit'   => array( 'Luach ionaid', 'Suimiú agus dealú', 'Iolrú agus roinnt', 'Codáin', 'Patrúin', 'Cruthanna 2T', 'Cruthanna 3T', 'Fad', 'Achar', 'Am', 'Airgead', 'Léaráidí' ),
			'mata_topic'         => array( 'Luach ionaid', 'Codáin', 'Táblaí', 'Siméadracht', 'Tomhas faid', 'Am', 'Airgead', 'Patrúin', 'Sonraí' ),
			'mata_resource_type' => array( 'Plean ceachta', 'Tasc ranga', 'Treoir mhúinteora', 'Bileog oibre', 'Nasc seachtrach' ),
			'mata_learning_focus'=> array( 'Tuiscint', 'Cumas', 'Réiteach fadhbanna', 'Cumarsáid mhatamaiticiúil' ),
			'mata_curriculum'    => array( 'Curaclam Matamaitice na Bunscoile (athfhorbartha)' ),
			'mata_format'        => array( 'PDF', 'Idirghníomhach', 'Treoir mhúinteora' ),
		);

		foreach ( $seed as $taxonomy => $terms ) {
			foreach ( $terms as $term ) {
				if ( ! term_exists( $term, $taxonomy ) ) {
					wp_insert_term( $term, $taxonomy );
				}
			}
		}

		update_option( 'cogg_mata_vocab_seeded', 1 );
	}

	public function admin_columns( array $columns ): array {
		$reordered = array();
		foreach ( $columns as $key => $label ) {
			$reordered[ $key ] = $label;
			if ( 'title' === $key ) {
				$reordered['mata_meta_status'] = 'Meiteashonraí';
			}
		}
		return $reordered;
	}

	public function admin_column_body( string $column, int $post_id ): void {
		if ( 'mata_meta_status' !== $column ) {
			return;
		}
		$missing = COGG_Mata_Metadata::missing_terms( $post_id );
		if ( empty( $missing ) ) {
			echo '<span class="mata-pill mata-pill--ok">Iomlán</span>';
			return;
		}
		printf(
			'<span class="mata-pill mata-pill--warn">%d ar iarraidh</span>',
			count( $missing )
		);
	}
}
