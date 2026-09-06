<?php
/**
 * Closes the parts of WordPress core that are open by default and should not be.
 *
 * WordPress ships assuming a blog with public authors. This is a public-sector
 * resource portal whose only accounts belong to named COGG staff, so several
 * core defaults leak more than they should:
 *
 *   /wp-json/wp/v2/users     lists every account — display name AND the login
 *                            slug — to anyone, with no authentication. The slug
 *                            is half of a credential pair, so publishing it
 *                            turns a password attack into a password-only
 *                            attack. Verified open before this class existed.
 *   /?author=1               redirects to the author archive, which spells the
 *                            same slug out in the URL. Blocking only the REST
 *                            route leaves this second door open.
 *   XML-RPC                  a remote-publishing endpoint this site never uses,
 *                            and historically the most-attacked entry point in
 *                            WordPress because a single request can carry many
 *                            login attempts.
 *   <meta name="generator">  publishes the exact core version, which tells an
 *                            attacker which advisories to try.
 *
 * None of this is defence in depth against a determined attacker on its own —
 * it removes free reconnaissance. RFT §12, SRS §8.4.
 *
 * @package cogg-mata
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Removes core's default public exposure of accounts and version information.
 */
final class COGG_Mata_Hardening {

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_filter( 'rest_endpoints', array( $this, 'restrict_user_endpoints' ) );
		add_action( 'template_redirect', array( $this, 'block_author_enumeration' ) );

		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_headers', array( $this, 'drop_pingback_header' ) );

		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}

	/**
	 * Require authentication for the core user endpoints.
	 *
	 * The endpoints are kept rather than unregistered: the block editor calls
	 * them when a signed-in editor assigns an author, and removing them outright
	 * breaks that. Wrapping the permission callback keeps the editor working
	 * while refusing anonymous callers.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array
	 */
	public function restrict_user_endpoints( $endpoints ) {
		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( ! isset( $endpoints[ $route ] ) ) {
				continue;
			}
			foreach ( $endpoints[ $route ] as $i => $handler ) {
				if ( ! is_array( $handler ) ) {
					continue;
				}
				$endpoints[ $route ][ $i ]['permission_callback'] = static function () {
					/*
					 * Authentication is the right boundary here, and a
					 * capability check is the wrong one.
					 *
					 * The COGG roles carry custom capabilities
					 * (edit_mata_resources and friends), not the core
					 * edit_posts, and list_users belongs to administrators
					 * alone — so gating on either would leave an editor or a
					 * digital author with a broken author selector in the block
					 * editor while fixing nothing.
					 *
					 * Every account on this site belongs to a named member of
					 * COGG staff: there are no pupil, parent or teacher accounts
					 * by design (BR-01). "Signed in" therefore means "COGG
					 * staff", which is exactly the line this endpoint should
					 * draw. The leak being closed is anonymous enumeration by
					 * the public, not staff seeing colleagues.
					 */
					return is_user_logged_in()
						? true
						: new WP_Error(
							'mata_rest_forbidden',
							__( 'Níl cead agat an t-eolas seo a fheiceáil.', 'cogg-mata' ),
							array( 'status' => is_user_logged_in() ? 403 : 401 )
						);
				};
			}
		}
		return $endpoints;
	}

	/**
	 * Refuse /?author=N, the other way to read a login slug.
	 *
	 * Signed-in staff are left alone so author archives stay usable inside the
	 * admin preview.
	 */
	public function block_author_enumeration(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_GET['author'] ) && ! is_author() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Stop advertising an XML-RPC endpoint that is switched off.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function drop_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
}
