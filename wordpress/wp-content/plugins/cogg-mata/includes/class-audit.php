<?php
/**
 * Editorial accountability log.
 *
 * SRS §14.5: "Every content create/edit/publish/unpublish action is attributed
 * to the acting COGG user and timestamped." SRS §9.4 retains it for the contract
 * term to support the quarterly support reporting in §19.5.
 *
 * A dedicated table rather than post meta, because the log must survive deletion
 * of the thing it describes — "who unpublished that resource" is precisely the
 * question you ask after it has gone.
 *
 * Deliberately NOT recorded: visitor IP, session identifiers, or anything about
 * a public user. The only actors that appear here are authenticated COGG staff
 * (SRS §13.1).
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_Audit {

	public const TABLE = 'cogg_mata_audit';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'wp_login', array( $this, 'log_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'log_logout' ) );
		add_action( 'wp_login_failed', array( $this, 'log_failed_login' ) );
		add_action( 'attachment_updated', array( $this, 'log_media_replace' ), 10, 3 );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table in whichever dialect is configured.
	 *
	 * The DDL itself lives in COGG_Mata_DB because PostgreSQL and MySQL
	 * disagree about almost every word of it — see that class for why
	 * dbDelta() cannot be used on PostgreSQL.
	 *
	 * The actor column must round-trip Irish names either way: utf8mb4 on
	 * MySQL, UTF8 database encoding on PostgreSQL.
	 */
	public static function install_table(): void {
		COGG_Mata_DB::create_audit_table( self::table_name() );
	}

	public function log( string $action, ?string $object_type = null, ?int $object_id = null, string $detail = '' ): void {
		global $wpdb;

		$user = wp_get_current_user();
		$name = ( $user && $user->ID ) ? $user->display_name : 'córas';
		$role = ( $user && ! empty( $user->roles ) ) ? (string) $user->roles[0] : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated audit table
		$wpdb->insert(
			self::table_name(),
			array(
				'logged_at'   => current_time( 'mysql', true ),
				'user_id'     => ( $user && $user->ID ) ? $user->ID : null,
				'user_name'   => $name,
				'user_role'   => $role,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'detail'      => mb_substr( $detail, 0, 2000 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	public function log_login( string $login, $user ): void {
		$this->log( 'login', 'user', $user instanceof WP_User ? $user->ID : null, 'Logáil isteach' );
	}

	public function log_logout(): void {
		$this->log( 'logout', 'user', get_current_user_id(), 'Logáil amach' );
	}

	/**
	 * SRS §11.1 — failed attempts are recorded so lockout and alerting have
	 * something to act on. The submitted username is stored, never the password.
	 */
	public function log_failed_login( string $login ): void {
		$this->log( 'login_failed', 'user', null, 'Iarracht theipthe: ' . sanitize_user( $login ) );
	}

	/** SRS §14.2 — PDF replace is versioned; record who swapped the file. */
	public function log_media_replace( int $attachment_id, $after, $before ): void {
		$this->log( 'media_update', 'attachment', $attachment_id, get_the_title( $attachment_id ) );
	}

	/** @return array<int,object> */
	public static function recent( int $limit = 100, string $action_filter = '' ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 500, $limit ) );

		if ( '' !== $action_filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			return (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d", $action_filter, $limit )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
		);
	}

	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . COGG_Mata_CPT::POST_RESOURCE,
			'Loga iniúchta',
			'Loga iniúchta',
			'mata_view_audit',
			'cogg-mata-audit',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'mata_view_audit' ) ) {
			wp_die( esc_html__( 'Níl cead agat an leathanach seo a fheiceáil.', 'cogg-mata' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
		$filter = isset( $_GET['action_filter'] ) ? sanitize_key( wp_unslash( $_GET['action_filter'] ) ) : '';
		$rows   = self::recent( 200, $filter );

		echo '<div class="wrap"><h1>Loga iniúchta</h1>';
		echo '<p class="description">Cuirtear gach gníomh eagarthóireachta i leith úsáideora ainmnithe agus cuirtear stampa ama air (SRS §14.5).</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Am (UTC)</th><th>Duine</th><th>Ról</th><th>Gníomh</th><th>Mír</th><th>Mionsonraí</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6">Níl aon iontráil ann fós.</td></tr>';
		}

		foreach ( $rows as $row ) {
			$title = $row->object_id ? get_the_title( (int) $row->object_id ) : '';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $row->logged_at ),
				esc_html( $row->user_name ),
				esc_html( $row->user_role ),
				esc_html( $row->action ),
				esc_html( $title ?: (string) $row->object_type ),
				esc_html( (string) $row->detail )
			);
		}

		echo '</tbody></table></div>';
	}
}
