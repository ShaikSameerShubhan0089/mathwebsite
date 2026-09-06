<?php
/**
 * Production settings for Áis Mhatamaitice.
 *
 * Paste this block into the host's generated wp-config.php, immediately ABOVE
 * the line that reads:
 *
 *     require_once ABSPATH . 'wp-settings.php';
 *
 * Do not copy the development wp-config.php from local/www/. That file resolves
 * the site URL dynamically from request headers, which exists so one build can
 * be reached through localhost, a LAN address and a tunnel during development.
 * In production the site has exactly one name, so it is pinned below — a fixed
 * value cannot be influenced by a forged Host header at all.
 *
 * Replace every REPLACE_ME before uploading.
 */

/* -------------------------------------------------- the site's single name */

define( 'WP_HOME',    'https://REPLACE_ME.ie' );
define( 'WP_SITEURL', 'https://REPLACE_ME.ie' );

/*
 * Many shared hosts terminate TLS at a load balancer and forward plain HTTP to
 * PHP. Without this WordPress believes the request is insecure, writes http://
 * into generated markup, and sets login cookies without the secure flag.
 */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

/* Admin and login always over TLS. */
define( 'FORCE_SSL_ADMIN', true );

/* --------------------------------------------------------------- hardening */

/*
 * No file editing from the browser. Without this an account that is
 * compromised, or simply careless, can edit plugin and theme PHP from
 * wp-admin — which turns an editor account into arbitrary code execution.
 */
define( 'DISALLOW_FILE_EDIT', true );

/*
 * No plugin or theme installation from the browser either. Changes arrive
 * through the deployment process, which means they are reviewed and reversible.
 * Set to false temporarily if COGG needs to install something and you are not
 * available.
 */
define( 'DISALLOW_FILE_MODS', true );

/* Never surface PHP errors to visitors; log them for the operator instead. */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
@ini_set( 'display_errors', '0' );

/* Post revisions are how COGG recovers from an editing mistake. Keep a useful
   number, but not unbounded growth in the database. */
define( 'WP_POST_REVISIONS', 10 );

/* Empty the trash monthly rather than weekly, so a deletion is recoverable for
   longer than a school half-term break. */
define( 'EMPTY_TRASH_DAYS', 30 );

/* Cron via a real scheduler is more reliable than firing on page loads.
   Add a server cron entry:
     */15 * * * * curl -s https://REPLACE_ME.ie/wp-cron.php?doing_wp_cron > /dev/null */
define( 'DISABLE_WP_CRON', true );

/* Automatic minor core updates: security fixes arrive without waiting for us.
   Major versions stay manual so they can be tested first. */
define( 'WP_AUTO_UPDATE_CORE', 'minor' );

/*
 * PostgreSQL note.
 *
 * The development build runs on PostgreSQL through the PG4WP drop-in. Do NOT
 * copy wp-content/db.php or the pg4wp directory to a MySQL host — WordPress
 * would try to translate queries for a database it is not talking to. The
 * production recommendation is MySQL/MariaDB; see the Technical Methodology
 * document §9 for why.
 */
