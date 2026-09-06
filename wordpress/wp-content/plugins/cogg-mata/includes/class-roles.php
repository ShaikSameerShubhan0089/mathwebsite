<?php
/**
 * Role-based access control.
 *
 * SRS §4.2 defines the permissions matrix; this file is its only implementation.
 * The three COGG roles are custom, not aliases of WordPress defaults, because
 * SRS §4.3 requires Content Editor and Digital Author to be *peer* roles with
 * non-overlapping responsibilities — a relationship WordPress's built-in
 * editor/author hierarchy cannot express.
 *
 * SRS §3.8 validation rule: "Permission checks are enforced server-side for
 * every content action, not merely hidden in the UI." WordPress capability
 * checks run in `map_meta_cap` before any write, so hiding a menu is cosmetic
 * and the capability is the control.
 *
 * Note there is no pupil role and no teacher role. Public users are never
 * authenticated (SRS §4.1); `subscriber` is removed so the site cannot
 * accumulate accounts by accident.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_Roles {

	public const ROLE_EDITOR = 'mata_content_editor';
	public const ROLE_AUTHOR = 'mata_digital_author';
	public const ROLE_ADMIN  = 'mata_administrator';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_upgrade_roles' ) );
		add_filter( 'map_meta_cap', array( $this, 'guard_publish' ), 10, 4 );
		add_action( 'admin_menu', array( $this, 'trim_menus' ), 999 );
		add_filter( 'editable_roles', array( $this, 'limit_assignable_roles' ) );
	}

	/**
	 * Capability sets, expressed once. Read this next to SRS §4.2 — the tables
	 * should agree line for line.
	 */
	public static function capability_map(): array {
		$read_only = array(
			'read'                    => true,
			'upload_files'            => true,
		);

		$resource_caps = array(
			'edit_mata_resource'              => true,
			'read_mata_resource'              => true,
			'delete_mata_resource'            => true,
			'edit_mata_resources'             => true,
			'edit_others_mata_resources'      => true,
			'publish_mata_resources'          => true,
			'read_private_mata_resources'     => true,
			'delete_mata_resources'           => true,
			'delete_others_mata_resources'    => true,
			'delete_published_mata_resources' => true,
			'edit_published_mata_resources'   => true,
		);

		$activity_caps = array(
			'edit_mata_activity'               => true,
			'read_mata_activity'               => true,
			'delete_mata_activity'             => true,
			'edit_mata_activities'             => true,
			'edit_others_mata_activities'      => true,
			'publish_mata_activities'          => true,
			'read_private_mata_activities'     => true,
			'delete_mata_activities'           => true,
			'delete_others_mata_activities'    => true,
			'delete_published_mata_activities' => true,
			'edit_published_mata_activities'   => true,
		);

		$pages = array(
			'edit_pages'            => true,
			'edit_others_pages'     => true,
			'edit_published_pages'  => true,
			'publish_pages'         => true,
			'delete_pages'          => true,
			'read_private_pages'    => true,
		);

		return array(
			// Static content and the PDF library. No activity authoring.
			self::ROLE_EDITOR => array(
				'label' => 'COGG — Eagarthóir ábhair',
				'caps'  => $read_only + $resource_caps + $pages + array(
					'mata_view_audit'    => true,
					'mata_manage_featured' => true,
				),
			),
			/*
			 * Digital activities only. Cannot touch pages or the PDF library,
			 * and — deliberately — cannot publish.
			 *
			 * publish_mata_activities is subtracted from the shared activity set
			 * so an author builds and submits, and a second pair of COGG eyes
			 * publishes. Testing found the author self-publishing, which
			 * contradicted the permissions model in the technical dossier and
			 * removed the review step the tender promises. The array is copied
			 * with array_diff_key rather than edited in place, because
			 * $activity_caps is also handed to the administrator role below.
			 */
			self::ROLE_AUTHOR => array(
				'label' => 'COGG — Údar digiteach',
				'caps'  => $read_only
					+ array_diff_key( $activity_caps, array( 'publish_mata_activities' => true ) )
					+ array(
						'mata_view_audit' => true,
						'mata_use_h5p'    => true,
					),
			),
			// Everything above, plus users and the taxonomy.
			self::ROLE_ADMIN => array(
				'label' => 'COGG — Riarthóir',
				'caps'  => $read_only + $resource_caps + $activity_caps + $pages + array(
					'mata_view_audit'      => true,
					'mata_manage_featured' => true,
					'mata_manage_taxonomy' => true,
					'mata_use_h5p'         => true,
					'mata_export_backup'   => true,
					'list_users'           => true,
					'edit_users'           => true,
					'create_users'         => true,
					'promote_users'        => true,
					'delete_users'         => true,
					'manage_categories'    => true,
				),
			),
		);
	}

	public function register_roles(): void {
		foreach ( self::capability_map() as $slug => $definition ) {
			remove_role( $slug );
			add_role( $slug, $definition['label'], $definition['caps'] );
		}

		// The site administrator keeps every plugin capability.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capability_map()[ self::ROLE_ADMIN ]['caps'] as $cap => $granted ) {
				$admin->add_cap( $cap );
			}
		}

		// No public account tier should exist at all (SRS §4.1, §13.2).
		remove_role( 'subscriber' );
		update_option( 'default_role', self::ROLE_EDITOR );
		update_option( 'users_can_register', 0 );
		update_option( 'cogg_mata_roles_version', COGG_MATA_VERSION );
	}

	public function maybe_upgrade_roles(): void {
		if ( get_option( 'cogg_mata_roles_version' ) !== COGG_MATA_VERSION ) {
			$this->register_roles();
		}
	}

	/**
	 * The publish gate, at capability level.
	 *
	 * BR-03 says every item must carry the full metadata set before publication.
	 * Enforcing that only in the editor screen would leave the REST API, XML-RPC,
	 * WP-CLI and quick-edit wide open. Denying the *capability* closes all of
	 * them at once: an incomplete item simply cannot be published by any route.
	 */
	public function guard_publish( array $caps, string $cap, int $user_id, array $args ): array {
		$watched = array( 'publish_mata_resources', 'publish_mata_activities' );
		if ( ! in_array( $cap, $watched, true ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $post_id ) {
			return $caps;
		}

		$missing = COGG_Mata_Metadata::missing_terms( $post_id );
		if ( ! empty( $missing ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/** Keep the admin surface small for non-technical editors (SRS §6.5.5). */
	public function trim_menus(): void {
		$user = wp_get_current_user();
		if ( empty( $user->roles ) ) {
			return;
		}
		$role = $user->roles[0];

		if ( self::ROLE_AUTHOR === $role ) {
			remove_menu_page( 'edit.php?post_type=' . COGG_Mata_CPT::POST_RESOURCE );
			remove_menu_page( 'edit.php' );          // posts
			remove_menu_page( 'edit.php?post_type=page' );
		}
		if ( self::ROLE_EDITOR === $role ) {
			remove_menu_page( 'edit.php?post_type=' . COGG_Mata_CPT::POST_ACTIVITY );
			remove_menu_page( 'edit.php' );
		}
		if ( in_array( $role, array( self::ROLE_EDITOR, self::ROLE_AUTHOR ), true ) ) {
			remove_menu_page( 'tools.php' );
			remove_menu_page( 'options-general.php' );
			remove_menu_page( 'themes.php' );
			remove_menu_page( 'plugins.php' );
		}
	}

	/** A COGG administrator may not mint a WordPress super-admin. */
	public function limit_assignable_roles( array $roles ): array {
		if ( current_user_can( 'manage_options' ) ) {
			return $roles;
		}
		return array_intersect_key(
			$roles,
			array_flip( array( self::ROLE_EDITOR, self::ROLE_AUTHOR, self::ROLE_ADMIN ) )
		);
	}
}
