<?php
/**
 * H5P authoring platform integration.
 *
 * RFT §9.2.1 requires an authoring platform "approved by COGG" to be integrated
 * so staff can create structured digital activities and publish them through the
 * portal. SRS §8.3 nominates H5P as a Design Assumption, to be confirmed before
 * Phase 3 build begins (RFT §12.4).
 *
 * This class integrates the standard h5p/h5p-wordpress-plugin when present and
 * degrades to the built-in toolkit kits when it is not — so Phase 1 and Phase 2
 * never depend on a Phase 3 decision, and COGG can still swap the platform after
 * evaluating alternatives without any Phase 1 rework.
 *
 * Caveat recorded here because it is the honest position, and it is the same one
 * flagged in the delivery roadmap: H5P's .h5p export is an authoring-level
 * artefact, not the lightweight per-teacher file the Phase 1 workflow produces.
 * Teacher adaptation (RFT §9.2.3) therefore runs through the native kits, where
 * the save-and-share format is genuinely identical to Phase 1. H5P content is
 * embedded and playable, and adaptation is marked unsupported for it rather than
 * half-working.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_H5P {

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'availability_notice' ) );
		add_filter( 'cogg_mata_activity_render', array( $this, 'render_activity' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_picker' ) );
		add_action( 'save_post_' . COGG_Mata_CPT::POST_ACTIVITY, array( $this, 'save_picker' ), 10, 1 );
	}

	public static function is_available(): bool {
		return function_exists( 'H5P_Plugin' ) || shortcode_exists( 'h5p' );
	}

	/** Surface the Phase 3 dependency honestly in wp-admin rather than failing silently. */
	public function availability_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || COGG_Mata_CPT::POST_ACTIVITY !== $screen->post_type ) {
			return;
		}
		if ( self::is_available() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
			esc_html( 'Níl an t-ardán údaraithe (H5P) suiteáilte.' ),
			esc_html( 'Oibríonn na huirlisí dúchasacha (líne uimhreach, luach ionaid, codáin, fíricí uimhre) gan é. Teastaíonn H5P do Chéim 3 amháin.' )
		);
	}

	public function add_picker(): void {
		if ( ! self::is_available() ) {
			return;
		}
		add_meta_box(
			'cogg_mata_h5p',
			'Ábhar H5P',
			array( $this, 'render_picker' ),
			COGG_Mata_CPT::POST_ACTIVITY,
			'side'
		);
	}

	public function render_picker( WP_Post $post ): void {
		wp_nonce_field( 'cogg_mata_h5p_save', 'cogg_mata_h5p_nonce' );
		$current = (int) get_post_meta( $post->ID, '_mata_h5p_id', true );

		printf(
			'<p><label for="mata_h5p_id">%s</label><br><input type="number" min="0" class="widefat" id="mata_h5p_id" name="mata_h5p_id" value="%s"></p>',
			esc_html( 'Aitheantas ábhair H5P' ),
			esc_attr( $current ?: '' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( 'Fág folamh chun uirlis dhúchasach a úsáid. Ní thacaítear le hoiriúnú múinteora ar ábhar H5P — féach SRS §3.11.' )
		);
	}

	public function save_picker( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['cogg_mata_h5p_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cogg_mata_h5p_nonce'] ) ), 'cogg_mata_h5p_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'mata_use_h5p' ) ) {
			return;
		}
		$id = isset( $_POST['mata_h5p_id'] ) ? absint( wp_unslash( $_POST['mata_h5p_id'] ) ) : 0;
		if ( $id > 0 ) {
			update_post_meta( $post_id, '_mata_h5p_id', $id );
			// H5P content cannot honour the Phase 1 adaptation contract.
			update_post_meta( $post_id, '_mata_adaptable', 0 );
		} else {
			delete_post_meta( $post_id, '_mata_h5p_id' );
		}
	}

	/**
	 * Render an activity: H5P embed if one is attached, otherwise a mount point
	 * the native toolkit script hydrates.
	 */
	public function render_activity( string $html, int $post_id ): string {
		$h5p_id = (int) get_post_meta( $post_id, '_mata_h5p_id', true );

		if ( $h5p_id > 0 && self::is_available() ) {
			return do_shortcode( sprintf( '[h5p id="%d"]', $h5p_id ) );
		}

		$kit = (string) get_post_meta( $post_id, '_mata_kit', true );
		$cfg = (string) get_post_meta( $post_id, '_mata_cfg', true );
		if ( '' === $kit ) {
			return $html;
		}

		return sprintf(
			'<div class="mata-activity-mount" data-kit="%s" data-cfg="%s" data-adaptable="%s"></div>',
			esc_attr( $kit ),
			esc_attr( $cfg ),
			get_post_meta( $post_id, '_mata_adaptable', true ) ? '1' : '0'
		);
	}
}
