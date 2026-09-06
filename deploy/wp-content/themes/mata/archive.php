<?php
/**
 * Archive for resources and activities.
 *
 * Server-rendered so browse works, is crawlable and is screen-reader navigable
 * without JavaScript (SRS §6.3). The faceted rail comes from the plugin.
 *
 * @package mata
 */
declare( strict_types=1 );
get_header();

$is_activity = is_post_type_archive( 'mata_activity' );
?>
<h1><?php echo esc_html( mata_t( $is_activity ? 'Gníomhaíochtaí digiteacha' : 'Acmhainní' ) ); ?></h1>
<p style="max-width:60ch;color:var(--mata-ink-2)">
	<?php
	echo esc_html(
		mata_t(
		$is_activity
			? 'Gníomhaíochtaí a d\'údaraigh COGG. Nuair a cheadaítear é, is féidir leat ceann a chur in oiriúint do do rang féin.'
			: 'Gach acmhainn scagtha de réir rangleibhéil, snáithe, aonad snáithe, topaice agus cineáil. Gan logáil isteach.'
		)
	);
	?>
</p>

<?php echo do_shortcode( $is_activity ? '[mata_activities]' : '[mata_library]' ); ?>

<?php
get_footer();
