<?php
/**
 * Database portability layer.
 *
 * COGG has specified PostgreSQL. WordPress core has no native PostgreSQL
 * support — `wpdb` speaks MySQL — so the platform runs behind the PG4WP
 * translation drop-in, which rewrites MySQL SQL into PostgreSQL on the way to
 * the server.
 *
 * PG4WP handles WordPress core's own queries. It does NOT reliably translate
 * DDL issued by plugins, because `dbDelta()` parses MySQL `CREATE TABLE`
 * syntax that has no PostgreSQL equivalent: there is no `UNSIGNED`, no
 * `AUTO_INCREMENT` (it is `BIGSERIAL`), no `ENGINE=`, and no charset/collation
 * clause on a table.
 *
 * So this class does two things:
 *   1. tells the rest of the plugin which server it is actually talking to, and
 *   2. emits the audit table's DDL in that server's dialect.
 *
 * Everything else in this plugin was already portable — the search filter uses
 * a plain `EXISTS (SELECT 1 ... LIKE ?)` subquery, and because
 * COGG_Mata_Search::fold() lower-cases on both write and read, the fact that
 * PostgreSQL's LIKE is case-sensitive while MySQL's is not never arises.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_DB {

	private static ?string $driver = null;

	/**
	 * Which server is behind wpdb: 'pgsql' or 'mysql'.
	 *
	 * Detection order matters. The explicit constant wins, because an operator
	 * who has configured PG4WP knows more than a runtime probe does. The probe
	 * is the fallback for an install that was set up without the constant.
	 */
	public static function driver(): string {
		if ( null !== self::$driver ) {
			return self::$driver;
		}

		if ( defined( 'DB_DRIVER' ) && 'pgsql' === strtolower( (string) DB_DRIVER ) ) {
			return self::$driver = 'pgsql';
		}

		// PG4WP defines this in its own configuration.
		if ( defined( 'PG4WP_ROOT' ) ) {
			return self::$driver = 'pgsql';
		}

		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$suppress = $wpdb->suppress_errors( true );
			// version() exists on both; only PostgreSQL reports "PostgreSQL".
			$version = $wpdb->get_var( 'SELECT version()' );
			$wpdb->suppress_errors( $suppress );
			if ( is_string( $version ) && false !== stripos( $version, 'postgresql' ) ) {
				return self::$driver = 'pgsql';
			}
		}

		return self::$driver = 'mysql';
	}

	public static function is_postgres(): bool {
		return 'pgsql' === self::driver();
	}

	/** Human-readable server version, for the health endpoint. */
	public static function server_version(): string {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$version  = $wpdb->get_var( 'SELECT version()' );
		$wpdb->suppress_errors( $suppress );
		return is_string( $version ) ? trim( explode( ',', $version )[0] ) : 'unknown';
	}

	/**
	 * Create the audit table in whichever dialect is in play.
	 *
	 * PostgreSQL takes plain `CREATE TABLE IF NOT EXISTS` executed directly.
	 * MySQL goes through `dbDelta()` so an existing table is migrated rather
	 * than skipped, which is the behaviour WordPress operators expect.
	 *
	 * @param string $table  Fully-prefixed table name.
	 */
	public static function create_audit_table( string $table ): void {
		global $wpdb;

		if ( self::is_postgres() ) {
			// BIGSERIAL is PostgreSQL's AUTO_INCREMENT. There is no UNSIGNED,
			// and encoding is a database-level property, not a table clause.
			$statements = array(
				"CREATE TABLE IF NOT EXISTS {$table} (
					id BIGSERIAL PRIMARY KEY,
					logged_at TIMESTAMP NOT NULL,
					user_id BIGINT NULL,
					user_name VARCHAR(190) NOT NULL DEFAULT '',
					user_role VARCHAR(60) NOT NULL DEFAULT '',
					action VARCHAR(60) NOT NULL,
					object_type VARCHAR(60) NULL,
					object_id BIGINT NULL,
					detail TEXT NULL
				)",
				"CREATE INDEX IF NOT EXISTS {$table}_logged_at_idx ON {$table} (logged_at)",
				"CREATE INDEX IF NOT EXISTS {$table}_object_idx    ON {$table} (object_type, object_id)",
				"CREATE INDEX IF NOT EXISTS {$table}_action_idx    ON {$table} (action)",
			);

			foreach ( $statements as $sql ) {
				// PG4WP's rewriter is tuned for WordPress core's queries and can
				// mangle DDL, so this goes straight to the server.
				self::raw_pg_exec( $sql );
			}
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				logged_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NULL,
				user_name VARCHAR(190) NOT NULL DEFAULT '',
				user_role VARCHAR(60) NOT NULL DEFAULT '',
				action VARCHAR(60) NOT NULL,
				object_type VARCHAR(60) NULL,
				object_id BIGINT UNSIGNED NULL,
				detail TEXT NULL,
				PRIMARY KEY (id),
				KEY idx_logged_at (logged_at),
				KEY idx_object (object_type, object_id),
				KEY idx_action (action)
			) {$collate};"
		);
	}

	/**
	 * Execute a statement against PostgreSQL, bypassing PG4WP's MySQL rewriter.
	 *
	 * Falls back to `$wpdb->query()` if a direct connection is not available,
	 * which is correct but leaves the statement subject to rewriting.
	 */
	private static function raw_pg_exec( string $sql ): bool {
		global $wpdb;

		// PHP 8.1 turned the pgsql connection from a resource into a
		// PgSql\Connection object, so is_resource() alone silently misses it
		// and the statement falls through to PG4WP's MySQL rewriter — which
		// rejects PostgreSQL DDL such as CREATE INDEX IF NOT EXISTS.
		$dbh = $wpdb->dbh ?? null;
		$is_pg_conn = ( null !== $dbh )
			&& ( is_resource( $dbh ) || ( class_exists( '\PgSql\Connection' ) && $dbh instanceof \PgSql\Connection ) );

		if ( function_exists( 'pg_query' ) && $is_pg_conn ) {
			// phpcs:ignore
			$result = @pg_query( $dbh, $sql );
			if ( false === $result ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'cogg-mata DDL failed: ' . pg_last_error( $dbh ) . ' -- ' . $sql );
				return false;
			}
			return true;
		}

		// No direct handle: last resort. PG4WP will try to rewrite this and may
		// well refuse, so the failure is logged rather than swallowed.
		$suppress = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->query( $sql );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'cogg-mata DDL rejected by the rewriter: ' . $e->getMessage() . ' -- ' . $sql );
			$wpdb->suppress_errors( $suppress );
			return false;
		}
		$wpdb->suppress_errors( $suppress );
		return true;
	}

	/**
	 * Does a table exist? `SHOW TABLES` is MySQL-only.
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		if ( self::is_postgres() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT to_regclass(%s)',
					$table
				)
			);
			return ! empty( $found );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
