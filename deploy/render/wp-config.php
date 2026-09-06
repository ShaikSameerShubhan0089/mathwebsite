<?php
/**
 * WordPress configuration for the Render + Supabase deployment.
 *
 * Everything environment-specific comes from environment variables set in the
 * Render dashboard, so no credential is ever committed to the repository.
 *
 * @package cogg-mata
 */

/* ------------------------------------------------------------------ database */

/*
 * Supabase gives a host and a port in one connection string. WordPress expects
 * DB_HOST to carry both, separated by a colon, which is also what PG4WP parses.
 *
 * Use the SESSION pooler string from the Supabase dashboard, not the direct
 * connection: on the free tier the direct host resolves to IPv6 only, and
 * Render's outbound network is IPv4, so a direct connection times out with no
 * useful error. The session pooler is IPv4 and behaves like an ordinary
 * Postgres connection, which is what WordPress needs — the transaction pooler
 * on port 6543 drops session state that WordPress relies on.
 */
define( 'DB_NAME',     getenv( 'DB_NAME' ) ?: 'postgres' );
define( 'DB_USER',     getenv( 'DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );
define( 'DB_HOST',     getenv( 'DB_HOST' ) );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

$table_prefix = getenv( 'DB_PREFIX' ) ?: 'wp_';

/*
 * PG4WP's rewriter checks for these MySQL constants by name. They are absent
 * when the mysqli extension is not installed, and their absence is a fatal
 * error rather than a warning, so they are defined here.
 */
defined( 'MYSQLI_REPORT_OFF' )        || define( 'MYSQLI_REPORT_OFF', 0 );
defined( 'MYSQLI_REPORT_ERROR' )      || define( 'MYSQLI_REPORT_ERROR', 1 );
defined( 'MYSQLI_REPORT_STRICT' )     || define( 'MYSQLI_REPORT_STRICT', 2 );
defined( 'MYSQLI_REPORT_INDEX' )      || define( 'MYSQLI_REPORT_INDEX', 4 );
defined( 'MYSQLI_REPORT_ALL' )        || define( 'MYSQLI_REPORT_ALL', 255 );

/* ---------------------------------------------------------------- site URL */

/*
 * Render publishes the service's own URL as RENDER_EXTERNAL_URL, so the site
 * knows its address without anything being hardcoded. Set SITE_URL yourself
 * once a custom domain is attached — the custom domain must win, or every
 * generated link points at the onrender.com address.
 */
$mata_site_url = getenv( 'SITE_URL' ) ?: getenv( 'RENDER_EXTERNAL_URL' );
if ( $mata_site_url ) {
	$mata_site_url = rtrim( $mata_site_url, '/' );
	define( 'WP_HOME',    $mata_site_url );
	define( 'WP_SITEURL', $mata_site_url );
}

/*
 * Render terminates TLS at its edge and forwards plain HTTP to the container.
 * Without this WordPress believes the request is insecure: it writes http://
 * into generated markup, which the browser then blocks as mixed content, and it
 * sets login cookies without the secure flag.
 */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

/*
 * Align the request with the site's own name.
 *
 * Defining WP_HOME is not enough. WordPress rebuilds the URL of the CURRENT
 * request from $_SERVER['HTTP_HOST'], and any proxy that rewrites Host leaves
 * that disagreeing with the canonical URL — redirect_canonical then fires and
 * sends visitors somewhere unreachable. The front page survives because
 * WordPress does not canonicalise it, which is why the symptom is always
 * "the home page works and nothing else does".
 */
if ( defined( 'WP_HOME' ) ) {
	// parse_url, not wp_parse_url: wp-config.php is read before WordPress loads
	// any of its own functions, so the wrapper does not exist yet and calling it
	// is a fatal error on every request.
	$mata_parts = parse_url( WP_HOME );
	if ( ! empty( $mata_parts['host'] ) ) {
		$_SERVER['HTTP_HOST']   = $mata_parts['host'];
		$_SERVER['SERVER_NAME'] = $mata_parts['host'];
	}
}

define( 'FORCE_SSL_ADMIN', true );

/* ------------------------------------------------------------------- salts */

/*
 * Generated once at https://api.wordpress.org/secret-key/1.1/salt/ and stored
 * as Render environment variables. If these are empty every session cookie on
 * the site is forgeable, so the deploy guide treats setting them as mandatory.
 */
define( 'AUTH_KEY',         getenv( 'AUTH_KEY' ) ?: '' );
define( 'SECURE_AUTH_KEY',  getenv( 'SECURE_AUTH_KEY' ) ?: '' );
define( 'LOGGED_IN_KEY',    getenv( 'LOGGED_IN_KEY' ) ?: '' );
define( 'NONCE_KEY',        getenv( 'NONCE_KEY' ) ?: '' );
define( 'AUTH_SALT',        getenv( 'AUTH_SALT' ) ?: '' );
define( 'SECURE_AUTH_SALT', getenv( 'SECURE_AUTH_SALT' ) ?: '' );
define( 'LOGGED_IN_SALT',   getenv( 'LOGGED_IN_SALT' ) ?: '' );
define( 'NONCE_SALT',       getenv( 'NONCE_SALT' ) ?: '' );

/* --------------------------------------------------------------- hardening */

define( 'DISALLOW_FILE_EDIT', true );

/*
 * The container filesystem is rebuilt on every deploy, so anything installed
 * through wp-admin disappears at the next push. Allowing it would mean COGG
 * installing a plugin, seeing it work, and finding it gone a week later.
 * Plugins belong in the image.
 */
define( 'DISALLOW_FILE_MODS', true );

define( 'WP_DEBUG',         (bool) getenv( 'WP_DEBUG' ) );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG',     false );   // container logs go to stdout, not a file
define( 'WP_POST_REVISIONS', 10 );
define( 'EMPTY_TRASH_DAYS', 30 );

/* No page-load cron: a free instance that has spun down runs nothing at all,
   so scheduling is driven by an external ping. See RENDER-SUPABASE.md. */
define( 'DISABLE_WP_CRON', true );

/* Core updates would be lost on the next deploy and can only mislead. */
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );

/* ------------------------------------------------------------------- boot */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
