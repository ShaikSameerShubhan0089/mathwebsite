<?php
/**
 * Custom 404 — SRS §3.1 requires an on-brand page, not a raw server error.
 *
 * @package mata
 */
declare( strict_types=1 );
get_header();
?>
<div class="mata-empty">
	<h1><?php mata_e( 'Níor aimsíodh an leathanach sin' ); ?></h1>
	<p><?php mata_e( "B'fhéidir gur bogadh é nó gur athraíodh an nasc." ); ?></p>
	<p style="margin-top:16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
		<a class="mata-btn mata-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php mata_e( 'Ar ais go dtí an baile' ); ?></a>
		<a class="mata-btn mata-btn--ghost" href="<?php echo esc_url( home_url( '/acmhainni/' ) ); ?>"><?php mata_e( 'Brabhsáil acmhainní' ); ?></a>
	</p>
</div>
<?php
get_footer();
