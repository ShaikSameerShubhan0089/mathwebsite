<?php
/**
 * The metadata completeness gate.
 *
 * BR-03: "Every PDF and digital activity must carry the agreed metadata fields
 * (class level, strand, strand unit, topic, resource type) before publication."
 * SRS §3.7 validation rule: "The CMS blocks publishing of a resource missing any
 * mandatory metadata field."
 *
 * Defence in depth, three layers, because a gate that only lives in the editor
 * screen is not a gate:
 *   1. capability  — COGG_Mata_Roles::guard_publish() denies publish_* outright
 *   2. transition  — wp_insert_post_data forces status back to draft
 *   3. interface   — an editor-screen notice naming the missing fields
 *
 * The error message names the fields in Irish rather than saying "invalid",
 * per SRS §3.12: "An attempt to publish an incomplete activity returns a
 * specific list of missing requirements to the editor."
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_Metadata {

	private COGG_Mata_Audit $audit;

	public function __construct( COGG_Mata_Audit $audit ) {
		$this->audit = $audit;

		add_filter( 'wp_insert_post_data', array( $this, 'block_incomplete_publish' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_activity_meta' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'log_transition' ), 10, 3 );
	}

	public static function post_types(): array {
		return array( COGG_Mata_CPT::POST_RESOURCE, COGG_Mata_CPT::POST_ACTIVITY );
	}

	/**
	 * Which required taxonomies have no term assigned.
	 *
	 * @return array<string,string> taxonomy slug => human label
	 */
	public static function missing_terms( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return array();
		}

		$missing = array();
		foreach ( COGG_Mata_CPT::REQUIRED_TAXONOMIES as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$missing[ $taxonomy ] = COGG_Mata_CPT::TAXONOMIES[ $taxonomy ] ?? $taxonomy;
			}
		}

		// A short description is part of the agreed minimum (SRS §3.4).
		if ( '' === trim( (string) $post->post_excerpt ) && '' === trim( wp_strip_all_tags( (string) $post->post_content ) ) ) {
			$missing['excerpt'] = 'Cur síos gearr';
		}

		return $missing;
	}

	public static function is_complete( int $post_id ): bool {
		return array() === self::missing_terms( $post_id );
	}

	/**
	 * Layer 2. Runs inside wp_insert_post for every write path — editor, REST,
	 * WP-CLI, quick edit — and demotes an incomplete item back to draft.
	 */
	public function block_incomplete_publish( array $data, array $postarr ): array {
		if ( ! in_array( $data['post_type'] ?? '', self::post_types(), true ) ) {
			return $data;
		}
		if ( 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $post_id ) {
			return $data;
		}

		$missing = self::missing_terms( $post_id );
		if ( empty( $missing ) ) {
			return $data;
		}

		$data['post_status'] = 'draft';

		set_transient(
			'cogg_mata_gate_' . get_current_user_id() . '_' . $post_id,
			array_values( $missing ),
			60
		);

		$this->audit->log(
			'publish_blocked',
			$data['post_type'],
			$post_id,
			'Réimsí ar iarraidh: ' . implode( ', ', array_values( $missing ) )
		);

		return $data;
	}

	/** Layer 3. Tells the editor exactly which fields are missing. */
	public function render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$key     = 'cogg_mata_gate_' . get_current_user_id() . '_' . $post->ID;
		$blocked = get_transient( $key );

		if ( $blocked ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:22px">%s</ul><p>%s</p></div>',
				esc_html( 'Níorbh fhéidir é a fhoilsiú — tá meiteashonraí riachtanacha ar iarraidh.' ),
				implode( '', array_map( static fn( $label ): string => '<li>' . esc_html( $label ) . '</li>', $blocked ) ),
				esc_html( 'Líon isteach na réimsí thuas agus bain triail eile as. Sábháladh mar dhréacht é idir an dá linn.' )
			);
			return;
		}

		$missing = self::missing_terms( $post->ID );
		if ( ! empty( $missing ) && 'publish' !== $post->post_status ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html( 'Sula bhféadfar é seo a fhoilsiú:' ),
				esc_html( implode( ', ', array_values( $missing ) ) )
			);
		}
	}

	/** Activity configuration box — the toolkit kit and its JSON settings. */
	public function add_meta_box(): void {
		add_meta_box(
			'cogg_mata_activity',
			'Socruithe na gníomhaíochta',
			array( $this, 'render_meta_box' ),
			COGG_Mata_CPT::POST_ACTIVITY,
			'normal',
			'high'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'cogg_mata_activity_save', 'cogg_mata_activity_nonce' );

		$kit       = (string) get_post_meta( $post->ID, '_mata_kit', true );
		$cfg       = (string) get_post_meta( $post->ID, '_mata_cfg', true );
		$adaptable = (bool) get_post_meta( $post->ID, '_mata_adaptable', true );

		$kits = array(
			'linear' => 'Líne uimhreach',
			'pv'     => 'Luach ionaid',
			'frac'   => 'Codáin',
			'tab'    => 'Fíricí uimhre',
			'h5p'    => 'H5P (ábhar seachtrach)',
		);

		echo '<p><label for="mata_kit"><strong>Cineál uirlise</strong></label><br>';
		echo '<select name="mata_kit" id="mata_kit" class="widefat">';
		echo '<option value="">— Roghnaigh —</option>';
		foreach ( $kits as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $kit, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></p>';

		/*
		 * The builder mounts here and drives the two fields above and below it.
		 * It is progressive enhancement, not a replacement: with JavaScript off,
		 * the select and the textarea are still the whole editor, and the save
		 * path is identical either way.
		 */
		printf(
			'<div class="mata-builder-mount" data-kit-field="%s" data-cfg-field="%s"></div>',
			'mata_kit',
			'mata_cfg'
		);

		echo '<details class="mata-cfg-advanced"><summary>Cumraíocht amh (JSON)</summary>';
		echo '<p><label for="mata_cfg" class="screen-reader-text">Cumraíocht (JSON)</label>';
		printf(
			'<textarea name="mata_cfg" id="mata_cfg" rows="6" class="widefat code">%s</textarea>',
			esc_textarea( $cfg )
		);
		echo '<span class="description">Líonann an tógálaí thuas an réimse seo duit. Ní chuimsíonn an chumraíocht ach paraiméadair na gníomhaíochta — ná cuir aon sonra pearsanta anseo, mar diúltófar dó.</span></p>';
		echo '</details>';

		printf(
			'<p><label><input type="checkbox" name="mata_adaptable" value="1" %s> <strong>%s</strong></label><br><span class="description">%s</span></p>',
			checked( $adaptable, true, false ),
			esc_html( 'Is féidir le múinteoirí í seo a chur in oiriúint' ),
			esc_html( 'Nuair a bhíonn sé seo múchta, taispeántar go soiléir don mhúinteoir nach féidir í a athrú (SRS §3.11).' )
		);
	}

	public function save_activity_meta( int $post_id, WP_Post $post ): void {
		if ( COGG_Mata_CPT::POST_ACTIVITY !== $post->post_type ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['cogg_mata_activity_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cogg_mata_activity_nonce'] ) ), 'cogg_mata_activity_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_mata_activity', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_mata_kit', sanitize_key( wp_unslash( $_POST['mata_kit'] ?? '' ) ) );
		update_post_meta( $post_id, '_mata_adaptable', isset( $_POST['mata_adaptable'] ) ? 1 : 0 );

		$raw = (string) wp_unslash( $_POST['mata_cfg'] ?? '' );
		$cfg = self::sanitise_config( $raw );
		update_post_meta( $post_id, '_mata_cfg', $cfg );
	}

	/**
	 * BR-05 enforced at the point of storage.
	 *
	 * Activity configuration is scalar parameters only. Any key that looks like
	 * it identifies a person is stripped before the value is written, so the
	 * "no pupil-identifying field" rule survives a careless paste as well as a
	 * malicious one.
	 */
	public static function sanitise_config( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$banned = array( 'name', 'ainm', 'pupil', 'dalta', 'student', 'school', 'scoil',
			'email', 'ríomhphost', 'riomhphost', 'class_list', 'roster', 'id', 'uid', 'user' );

		$clean = static function ( array $input, int $depth ) use ( &$clean, $banned ): array {
			if ( $depth > 4 ) {
				return array();
			}
			$out = array();
			foreach ( $input as $key => $value ) {
				$normal = strtolower( (string) $key );
				foreach ( $banned as $bad ) {
					if ( false !== strpos( $normal, $bad ) ) {
						continue 2;
					}
				}
				if ( is_array( $value ) ) {
					$out[ $key ] = $clean( $value, $depth + 1 );
				} elseif ( is_scalar( $value ) || null === $value ) {
					$out[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
			return $out;
		};

		return (string) wp_json_encode( $clean( $decoded, 0 ) );
	}

	/** SRS §3.12 — every publish/unpublish/revision logged with actor and time. */
	public function log_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}

		$action = match ( true ) {
			'publish' === $new_status                          => 'publish',
			'publish' === $old_status && 'trash' !== $new_status => 'unpublish',
			'trash' === $new_status                            => 'trash',
			'auto-draft' === $old_status                       => 'create',
			default                                            => 'status_change',
		};

		$this->audit->log( $action, $post->post_type, $post->ID, $post->post_title );
	}
}
