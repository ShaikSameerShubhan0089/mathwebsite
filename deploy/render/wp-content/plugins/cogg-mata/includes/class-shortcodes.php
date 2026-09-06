<?php
/**
 * Front-end mount points.
 *
 * Shortcodes rather than block-editor blocks: RFT §8.2.4 requires COGG staff to
 * place these on pages without developer involvement, and a shortcode is the
 * lowest-training-cost way to do that in a mixed classic/block install. They are
 * also trivially portable if COGG ever changes theme.
 *
 *   [mata_toolkit]     the configurable toolkit + save-and-share
 *   [mata_open]        the "open a saved activity" entry point (RFT §7.2.3)
 *   [mata_library]     faceted PDF resource library
 *   [mata_activities]  digital activity library with adaptation
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_Shortcodes {

	public function __construct() {
		add_shortcode( 'mata_toolkit', array( $this, 'toolkit' ) );
		add_shortcode( 'mata_open', array( $this, 'open' ) );
		add_shortcode( 'mata_library', array( $this, 'library' ) );
		add_shortcode( 'mata_activities', array( $this, 'activities' ) );
	}

	public function toolkit( $atts ): string {
		$atts = shortcode_atts( array( 'kit' => 'linear' ), $atts, 'mata_toolkit' );
		return sprintf(
			'<div class="mata-toolkit" data-default-kit="%s" data-open-url="%s">
			   <noscript><p class="mata-note">%s</p></noscript>
			 </div>',
			esc_attr( sanitize_key( $atts['kit'] ) ),
			esc_url( home_url( '/oscail/' ) ),
			esc_html( mata_t( 'Teastaíonn JavaScript chun an uirlis a úsáid. Tá na hacmhainní PDF ar fáil gan é.' ) )
		);
	}

	public function open( $atts ): string {
		return '<div class="mata-open"></div>';
	}

	public function library( $atts ): string {
		$atts = shortcode_atts( array( 'per' => 24 ), $atts, 'mata_library' );
		return $this->render_browse( COGG_Mata_CPT::POST_RESOURCE, (int) $atts['per'], false );
	}

	public function activities( $atts ): string {
		$atts = shortcode_atts( array( 'per' => 24 ), $atts, 'mata_activities' );
		return $this->render_browse( COGG_Mata_CPT::POST_ACTIVITY, (int) $atts['per'], true );
	}

	/**
	 * Server-rendered browse. Deliberately not a JavaScript-only view: the
	 * library must work, be crawlable and be screen-reader navigable without JS
	 * (SRS §6.3). The script layer only enhances it.
	 */
	private function render_browse( string $post_type, int $per_page, bool $is_activity ): string {
		$active = COGG_Mata_Search::active_facets();

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, $per_page ) ),
			'paged'          => max( 1, (int) get_query_var( 'paged' ) ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $active ) ) {
			$tax_query = array( 'relation' => 'AND' );
			foreach ( $active as $taxonomy => $slugs ) {
				$tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $slugs );
			}
			$args['tax_query'] = $tax_query;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only search
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( '' !== $q ) {
			$meta = array( 'relation' => 'AND' );
			foreach ( COGG_Mata_Search::tokenise( $q ) as $token ) {
				$meta[] = array( 'key' => COGG_Mata_Search::META_INDEX, 'value' => $token, 'compare' => 'LIKE' );
			}
			$args['meta_query'] = $meta;
		}

		$query  = new WP_Query( $args );
		$facets = COGG_Mata_Search::facet_counts( $active, array( $post_type ) );

		ob_start();
		echo '<div class="mata-browse">';

		/* ---- facet rail ---- */
		echo '<form class="mata-facets" method="get" aria-label="' . esc_attr( mata_t( 'Scagairí' ) ) . '">';
		printf(
			'<div class="mata-field"><label for="mata-q">%s</label>
			 <input type="search" id="mata-q" name="q" value="%s" placeholder="%s"></div>',
			esc_html( mata_t( 'Cuardaigh' ) ),
			esc_attr( $q ),
			esc_attr( mata_t( 'Cuardaigh… (oibríonn sé le sínte fada nó gan iad)' ) )
		);

		foreach ( $facets as $taxonomy => $rows ) {
			$param = str_replace( 'mata_', '', $taxonomy );
			printf( '<fieldset class="mata-fgroup"><legend>%s</legend>', esc_html( mata_t( COGG_Mata_CPT::TAXONOMIES[ $taxonomy ] ) ) );
			foreach ( $rows as $row ) {
				printf(
					'<label class="mata-fopt"><input type="checkbox" name="%s[]" value="%s"%s> <span>%s</span> <span class="mata-count">%d</span></label>',
					esc_attr( $param ),
					esc_attr( $row['term']->slug ),
					checked( $row['selected'], true, false ),
					esc_html( mata_t( $row['term']->name ) ),
					(int) $row['count']
				);
			}
			echo '</fieldset>';
		}

		printf(
			'<div class="mata-facet-actions"><button type="submit" class="mata-btn mata-btn--primary">%s</button>
			 <a class="mata-btn mata-btn--quiet" href="%s">%s</a></div>',
			esc_html( mata_t( 'Scag' ) ),
			esc_url( strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ) ),
			esc_html( mata_t( 'Glan' ) )
		);
		echo '</form>';

		/* ---- results ---- */
		echo '<div class="mata-results">';
		printf(
			'<p class="mata-count-line" role="status">%s</p>',
			esc_html( sprintf( COGG_Mata_I18n::is_english() ? '%d results' : '%d toradh', (int) $query->found_posts ) )
		);

		if ( ! $query->have_posts() ) {
			printf(
				'<div class="mata-empty"><p><strong>%s</strong></p><p>%s</p></div>',
				esc_html( mata_t( 'Níor aimsíodh aon toradh' ) ),
				esc_html( mata_t( 'Bain scagaire amach nó déan an cuardach níos leithne.' ) )
			);
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$id        = get_the_ID();
			$adaptable = (bool) get_post_meta( $id, '_mata_adaptable', true );
			$file_id   = (int) get_post_meta( $id, '_mata_file_id', true );

			echo '<article class="mata-card">';
			printf( '<h3><a href="%s">%s</a></h3>', esc_url( (string) get_permalink() ), esc_html( get_the_title() ) );
			printf( '<p class="mata-desc">%s</p>', esc_html( (string) get_the_excerpt() ) );

			echo '<div class="mata-chips">';
			foreach ( array( 'mata_class_level', 'mata_strand', 'mata_strand_unit', 'mata_resource_type' ) as $taxonomy ) {
				$terms = wp_get_object_terms( $id, $taxonomy );
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					printf(
						'<span class="mata-chip mata-chip--%s">%s</span>',
						esc_attr( sanitize_html_class( $term->slug ) ),
						esc_html( mata_t( $term->name ) )
					);
				}
			}
			echo '</div>';

			echo '<div class="mata-actions">';
			if ( $file_id ) {
				printf(
					'<a class="mata-btn mata-btn--primary" href="%s" data-download="%d" download>%s</a>',
					esc_url( (string) wp_get_attachment_url( $file_id ) ),
					(int) $id,
					esc_html( mata_t( 'Íoslódáil' ) )
				);
			}
			if ( $is_activity ) {
				if ( $adaptable ) {
					printf(
						'<a class="mata-btn mata-btn--ghost" href="%s">%s</a>',
						esc_url( add_query_arg( 'adapt', $id, home_url( '/uirlisi/' ) ) ),
						esc_html( mata_t( 'Cuir in oiriúint' ) )
					);
				} else {
					printf( '<span class="mata-chip mata-chip--warn">%s</span>', esc_html( mata_t( 'Ní féidir í seo a chur in oiriúint' ) ) );
				}
			}
			printf( '<a class="mata-btn mata-btn--ghost" href="%s">%s</a>', esc_url( (string) get_permalink() ), esc_html( mata_t( 'Féach' ) ) );
			echo '</div></article>';
		}
		wp_reset_postdata();

		echo '</div></div>';
		return (string) ob_get_clean();
	}
}
