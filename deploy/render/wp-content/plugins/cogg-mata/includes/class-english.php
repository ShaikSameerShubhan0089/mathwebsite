<?php
/**
 * English rendering of COGG content.
 *
 * The interface strings live in COGG_Mata_I18n and ship with the plugin. The
 * *content* — resource and activity titles and descriptions — does not, and
 * must not: SRS §1.10 puts translation and content authorship with COGG, and
 * BR-02 requires every public-facing string to pass COGG's editorial approval.
 *
 * So this class supplies the mechanism, not the words. Each item carries an
 * optional English title and description that a COGG editor fills in through
 * the normal edit screen, alongside the Irish. Empty means "not translated
 * yet", and English visitors see the Irish — which is correct, not a failure.
 *
 * The demo content is pre-filled by bin/seed-en.php so an evaluator sees a
 * fully English site; in production those fields start empty and COGG fills
 * them as part of publishing.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_English {

	public const META_TITLE   = '_mata_title_en';
	public const META_EXCERPT = '_mata_excerpt_en';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_filter( 'manage_' . COGG_Mata_CPT::POST_RESOURCE . '_posts_columns', array( $this, 'column' ) );
		add_filter( 'manage_' . COGG_Mata_CPT::POST_ACTIVITY . '_posts_columns', array( $this, 'column' ) );
		add_action( 'manage_' . COGG_Mata_CPT::POST_RESOURCE . '_posts_custom_column', array( $this, 'column_body' ), 10, 2 );
		add_action( 'manage_' . COGG_Mata_CPT::POST_ACTIVITY . '_posts_custom_column', array( $this, 'column_body' ), 10, 2 );
	}

	/** The English title for a post, or '' when COGG has not supplied one. */
	public static function title( int $post_id ): string {
		$value = get_post_meta( $post_id, self::META_TITLE, true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/** The English description for a post, or '' when none is supplied. */
	public static function excerpt( int $post_id ): string {
		$value = get_post_meta( $post_id, self::META_EXCERPT, true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	public function add_box(): void {
		foreach ( COGG_Mata_Metadata::post_types() as $type ) {
			add_meta_box(
				'cogg_mata_english',
				'Leagan Béarla / English version',
				array( $this, 'render' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'cogg_mata_en_save', 'cogg_mata_en_nonce' );

		$title   = self::title( $post->ID );
		$excerpt = self::excerpt( $post->ID );

		echo '<p class="description">';
		echo esc_html( 'Fág folamh é agus feicfidh cuairteoirí Béarla an Ghaeilge. — Leave empty and English visitors see the Irish.' );
		echo '</p>';

		printf(
			'<p><label for="mata_title_en"><strong>%s</strong></label><br>'
			. '<input type="text" class="widefat" id="mata_title_en" name="mata_title_en" value="%s"></p>',
			esc_html( 'Teideal Béarla / English title' ),
			esc_attr( $title )
		);

		printf(
			'<p><label for="mata_excerpt_en"><strong>%s</strong></label><br>'
			. '<textarea class="widefat" rows="3" id="mata_excerpt_en" name="mata_excerpt_en">%s</textarea></p>',
			esc_html( 'Cur síos Béarla / English description' ),
			esc_textarea( $excerpt )
		);
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, COGG_Mata_Metadata::post_types(), true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['cogg_mata_en_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cogg_mata_en_nonce'] ) ), 'cogg_mata_en_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$title   = isset( $_POST['mata_title_en'] ) ? sanitize_text_field( wp_unslash( $_POST['mata_title_en'] ) ) : '';
		$excerpt = isset( $_POST['mata_excerpt_en'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mata_excerpt_en'] ) ) : '';

		update_post_meta( $post_id, self::META_TITLE, $title );
		update_post_meta( $post_id, self::META_EXCERPT, $excerpt );
	}

	/** A column so editors can see at a glance what still needs translating. */
	public function column( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'mata_meta_status' === $key ) {
				$out['mata_en'] = 'Béarla';
			}
		}
		if ( ! isset( $out['mata_en'] ) ) {
			$out['mata_en'] = 'Béarla';
		}
		return $out;
	}

	public function column_body( string $column, int $post_id ): void {
		if ( 'mata_en' !== $column ) {
			return;
		}
		if ( '' !== self::title( $post_id ) ) {
			echo '<span class="mata-pill mata-pill--ok">EN</span>';
			return;
		}
		echo '<span class="mata-pill mata-pill--warn">—</span>';
	}
}
