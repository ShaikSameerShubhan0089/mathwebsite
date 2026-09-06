<?php
/**
 * Plugin Name:       COGG Mata — Áis Mhatamaitice
 * Plugin URI:        https://cogg.ie/
 * Description:       Primary Mathematics Hub for COGG: curriculum taxonomy, resource
 *                    library, digital activity library, editorial roles, metadata
 *                    completeness gate, Irish-language search, audit log and the
 *                    no-login file-based save-and-share toolkit.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Vendor (COGG RFT — Primary Mathematics Hub)
 * License:           GPL-2.0-or-later
 * Text Domain:       cogg-mata
 * Domain Path:       /languages
 *
 * ---------------------------------------------------------------------------
 * Requirement traceability (RFT / SRS clause -> code):
 *   RFT §7.2.3  no-login save-and-share ....... assets/toolkit.js (100% client-side)
 *   RFT §7.2.4  PDF resource library .......... class-cpt.php (mata_resource)
 *   RFT §8.2.1  faceted browse & search ....... class-search.php
 *   RFT §8.2.3  controlled taxonomy ........... class-cpt.php (8 taxonomies)
 *   RFT §8.2.4  COGG self-service CMS ......... class-roles.php
 *   RFT §9.2.1  authoring platform ............ class-h5p.php
 *   RFT §9.2.2  digital activity library ...... class-cpt.php (mata_activity)
 *   RFT §13     Irish language correctness .... class-search.php (fadas, collation)
 *   SRS BR-03   metadata completeness gate .... class-metadata.php
 *   SRS §14.5   editorial accountability ...... class-audit.php
 *   SRS §4.2    permissions matrix ............ class-roles.php
 *
 * BR-01 / BR-05: this plugin registers no pupil-facing endpoint, no pupil post
 * type, no pupil user role and no table column capable of holding a pupil
 * identifier. The save-and-share file is produced and consumed entirely in the
 * browser and is never transmitted to, nor stored by, this server.
 * ---------------------------------------------------------------------------
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COGG_MATA_VERSION', '1.0.0' );
define( 'COGG_MATA_FILE', __FILE__ );
define( 'COGG_MATA_DIR', plugin_dir_path( __FILE__ ) );
define( 'COGG_MATA_URL', plugin_dir_url( __FILE__ ) );
define( 'COGG_MATA_DB_VERSION', '1' );

require_once COGG_MATA_DIR . 'includes/class-db.php';
require_once COGG_MATA_DIR . 'includes/class-i18n.php';
require_once COGG_MATA_DIR . 'includes/class-english.php';
require_once COGG_MATA_DIR . 'includes/class-cpt.php';
require_once COGG_MATA_DIR . 'includes/class-roles.php';
require_once COGG_MATA_DIR . 'includes/class-metadata.php';
require_once COGG_MATA_DIR . 'includes/class-search.php';
require_once COGG_MATA_DIR . 'includes/class-audit.php';
require_once COGG_MATA_DIR . 'includes/class-rest.php';
require_once COGG_MATA_DIR . 'includes/class-hardening.php';
require_once COGG_MATA_DIR . 'includes/class-h5p.php';
require_once COGG_MATA_DIR . 'includes/class-shortcodes.php';

/**
 * Plugin bootstrap. Instantiates each subsystem and wires WordPress hooks.
 */
final class COGG_Mata {

	private static ?COGG_Mata $instance = null;

	public COGG_Mata_CPT $cpt;
	public COGG_Mata_Roles $roles;
	public COGG_Mata_Metadata $metadata;
	public COGG_Mata_Search $search;
	public COGG_Mata_Audit $audit;
	public COGG_Mata_REST $rest;
	public COGG_Mata_Hardening $hardening;
	public COGG_Mata_H5P $h5p;
	public COGG_Mata_Shortcodes $shortcodes;
	public COGG_Mata_I18n $i18n;
	public COGG_Mata_English $english;

	public static function instance(): COGG_Mata {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->i18n       = new COGG_Mata_I18n();
		$this->english    = new COGG_Mata_English();
		$this->cpt        = new COGG_Mata_CPT();
		$this->roles      = new COGG_Mata_Roles();
		$this->audit      = new COGG_Mata_Audit();
		$this->metadata   = new COGG_Mata_Metadata( $this->audit );
		$this->search     = new COGG_Mata_Search();
		$this->rest       = new COGG_Mata_REST( $this->search, $this->metadata, $this->audit );
		$this->hardening  = new COGG_Mata_Hardening();
		$this->h5p        = new COGG_Mata_H5P();
		$this->shortcodes = new COGG_Mata_Shortcodes();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'send_headers', array( $this, 'security_headers' ) );
		add_filter( 'upload_mimes', array( $this, 'restrict_upload_mimes' ), 99 );
		add_filter( 'style_loader_src', array( $this, 'relative_asset_url' ), 999 );
		add_filter( 'script_loader_src', array( $this, 'relative_asset_url' ), 999 );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'verify_pdf_magic' ), 10, 4 );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'cogg-mata', false, dirname( plugin_basename( COGG_MATA_FILE ) ) . '/languages' );
	}

	public function enqueue_public(): void {
		wp_enqueue_style( 'cogg-mata', COGG_MATA_URL . 'assets/mata.css', array(), COGG_MATA_VERSION );
		wp_enqueue_script( 'cogg-mata-toolkit', COGG_MATA_URL . 'assets/toolkit.js', array(), COGG_MATA_VERSION, true );
		wp_localize_script(
			'cogg-mata-toolkit',
			'MataConfig',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'cogg-mata/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'homeUrl'  => esc_url_raw( home_url( '/' ) ),
				'openUrl'  => esc_url_raw( home_url( '/oscail/' ) ),
				'locale'   => COGG_Mata_I18n::is_english() ? 'en_IE' : 'ga_IE',
				'lang'     => COGG_Mata_I18n::lang(),
				'version'  => COGG_MATA_VERSION,
			)
		);
	}

	public function enqueue_admin( string $hook ): void {
		wp_enqueue_style( 'cogg-mata-admin', COGG_MATA_URL . 'assets/admin.css', array(), COGG_MATA_VERSION );

		/*
		 * The activity builder (RFT §9.2.1) is the public toolkit mounted inside
		 * wp-admin, so the author configures an activity with the same controls
		 * a teacher uses and sees the same live preview a pupil will get. Loaded
		 * only on the two activity editor screens — no reason to ship the kits
		 * to the rest of the admin.
		 */
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || COGG_Mata_CPT::POST_ACTIVITY !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'cogg-mata', COGG_MATA_URL . 'assets/mata.css', array(), COGG_MATA_VERSION );
		wp_enqueue_script( 'cogg-mata-toolkit', COGG_MATA_URL . 'assets/toolkit.js', array(), COGG_MATA_VERSION, true );
		wp_localize_script(
			'cogg-mata-toolkit',
			'MataConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'cogg-mata/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'homeUrl' => esc_url_raw( home_url( '/' ) ),
				'openUrl' => esc_url_raw( home_url( '/oscail/' ) ),
				'locale'  => COGG_Mata_I18n::is_english() ? 'en_IE' : 'ga_IE',
				'lang'    => COGG_Mata_I18n::lang(),
				'version' => COGG_MATA_VERSION,
			)
		);
	}

	/**
	 * Emit stylesheet and script URLs root-relative.
	 *
	 * WordPress writes absolute asset URLs built from WP_HOME. That is fine on a
	 * fixed domain and fragile everywhere else: behind a tunnel or reverse proxy
	 * the host WordPress believes in may not be the host the browser is using,
	 * and the mismatch is invisible — the page renders, every stylesheet 404s or
	 * is blocked as mixed content, and the site appears as unstyled text.
	 *
	 * A root-relative "/wp-content/..." resolves against whatever origin the
	 * browser actually loaded the page from, so it is correct on localhost, on a
	 * LAN address, through any tunnel, and behind the production CDN — without
	 * the server having to guess.
	 *
	 * Only assets are made relative. Canonical tags, feeds and redirects still
	 * need absolute URLs and are left alone.
	 */
	public function relative_asset_url( $src ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		// Leave anything genuinely off-site alone (a CDN, Google Fonts).
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = wp_parse_url( $src, PHP_URL_HOST );

		if ( null === $host ) {
			return $src;   // already relative
		}
		if ( $home && $host !== $home ) {
			return $src;   // third-party
		}

		$path  = (string) wp_parse_url( $src, PHP_URL_PATH );
		$query = wp_parse_url( $src, PHP_URL_QUERY );

		if ( '' === $path ) {
			return $src;
		}

		return $path . ( $query ? '?' . $query : '' );
	}

	/**
	 * SRS §11.6 — baseline hardening headers. A CSP is intentionally NOT emitted
	 * here; it belongs at the edge/CDN layer (SRS §8.5) where it can be tuned
	 * without a plugin release.
	 */
	public function security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()' );
	}

	/**
	 * SRS §11.6 — file uploads restricted to an allow-list: PDF for the resource
	 * library plus the authoring platform's own package format.
	 */
	public function restrict_upload_mimes( array $mimes ): array {
		if ( current_user_can( 'manage_options' ) ) {
			return $mimes;
		}
		return array(
			'pdf'  => 'application/pdf',
			'h5p'  => 'application/zip',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'svg'  => 'image/svg+xml',
		);
	}

	/**
	 * Content-sniffing check: a file claiming to be a PDF must actually start
	 * with the %PDF- signature. Extension alone is not evidence.
	 */
	public function verify_pdf_magic( array $data, string $file, string $filename, $mimes ): array {
		if ( 'application/pdf' !== ( $data['type'] ?? '' ) ) {
			return $data;
		}
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return $data;
		}
		$head = fread( $handle, 5 );
		fclose( $handle );
		if ( '%PDF-' !== $head ) {
			$data['type'] = false;
			$data['ext']  = false;
		}
		return $data;
	}
}

/**
 * Activation: create the audit table, register roles and seed the curriculum
 * vocabularies. Runs once, idempotently.
 */
function cogg_mata_activate(): void {
	require_once COGG_MATA_DIR . 'includes/class-db.php';
	require_once COGG_MATA_DIR . 'includes/class-cpt.php';
	require_once COGG_MATA_DIR . 'includes/class-roles.php';
	require_once COGG_MATA_DIR . 'includes/class-audit.php';

	$cpt = new COGG_Mata_CPT();
	$cpt->register_post_types();
	$cpt->register_taxonomies();
	$cpt->seed_vocabularies();

	( new COGG_Mata_Roles() )->register_roles();
	COGG_Mata_Audit::install_table();

	update_option( 'cogg_mata_db_version', COGG_MATA_DB_VERSION );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cogg_mata_activate' );

function cogg_mata_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cogg_mata_deactivate' );

COGG_Mata::instance();
