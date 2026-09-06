<?php
/**
 * Mata theme.
 *
 * Deliberately thin. Everything that encodes a requirement lives in the
 * cogg-mata plugin, so COGG can restyle or replace the theme without losing the
 * taxonomy, the metadata gate, the roles or the toolkit (SRS §21.3).
 *
 * @package mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'after_setup_theme', static function (): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'nav-menu', 'style', 'script' ) );
	add_theme_support( 'automatic-feed-links' );
	register_nav_menus( array( 'primary' => 'Príomhroghchlár' ) );
} );

add_action( 'wp_enqueue_scripts', static function (): void {
	wp_enqueue_style( 'mata-theme', get_stylesheet_uri(), array( 'cogg-mata' ), '1.0.0' );
}, 20 );

/** Irish is the site's first language, not a translation layer (SRS §6.4). */
add_filter( 'language_attributes', static function ( string $output ): string {
	return $output;
} );

/** Keep the admin bar off the public site so classroom screens stay clean. */
add_filter( 'show_admin_bar', static fn( bool $show ): bool => is_user_logged_in() && current_user_can( 'edit_posts' ) );
