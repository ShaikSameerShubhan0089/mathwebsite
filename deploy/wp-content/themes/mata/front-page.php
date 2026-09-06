<?php
/**
 * Homepage.
 *
 * SRS §6.5.1 — the toolkit and the resource library must both be one click from
 * here, with messaging aimed at a first-time teacher visitor.
 *
 * @package mata
 */
declare( strict_types=1 );
get_header();
?>
<section class="hero">
	<h1><?php mata_e( 'Matamaitic trí Ghaeilge, in aon áit amháin' ); ?></h1>
	<p><?php mata_e( 'Uirlisí idirghníomhacha, acmhainní iníoslódáilte agus gníomhaíochtaí digiteacha — gan cuntas, gan logáil isteach, gan sonraí daltaí a bhailiú riamh.' ); ?></p>
	<p style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
		<a class="mata-btn mata-btn--primary" href="<?php echo esc_url( home_url( '/uirlisi/' ) ); ?>"><?php mata_e( 'Oscail an uirlis' ); ?></a>
		<a class="mata-btn mata-btn--ghost" href="<?php echo esc_url( home_url( '/acmhainni/' ) ); ?>"><?php mata_e( 'Brabhsáil acmhainní' ); ?></a>
	</p>
</section>

<div class="tiles">
	<a class="tile" href="<?php echo esc_url( home_url( '/uirlisi/' ) ); ?>">
		<span aria-hidden="true" style="font-size:1.7rem">🧮</span>
		<strong><?php mata_e( 'Uirlisí' ); ?></strong>
		<span><?php mata_e( 'Cumraigh gníomhaíocht, sábháil í agus roinn í le do rang.' ); ?></span>
	</a>
	<a class="tile" href="<?php echo esc_url( home_url( '/acmhainni/' ) ); ?>">
		<span aria-hidden="true" style="font-size:1.7rem">📚</span>
		<strong><?php mata_e( 'Acmhainní' ); ?></strong>
		<span><?php mata_e( 'Pleananna ceachta agus tascanna ranga, scagtha de réir an churaclaim.' ); ?></span>
	</a>
	<a class="tile" href="<?php echo esc_url( home_url( '/gniomhaiochtai/' ) ); ?>">
		<span aria-hidden="true" style="font-size:1.7rem">✨</span>
		<strong><?php mata_e( 'Gníomhaíochtaí' ); ?></strong>
		<span><?php mata_e( 'Gníomhaíochtaí digiteacha ó COGG — cuir in oiriúint do do rang iad.' ); ?></span>
	</a>
	<a class="tile" href="<?php echo esc_url( home_url( '/oscail/' ) ); ?>">
		<span aria-hidden="true" style="font-size:1.7rem">📂</span>
		<strong><?php mata_e( 'Oscail gníomhaíocht' ); ?></strong>
		<span><?php mata_e( 'Fuair tú comhad nó nasc ó do mhúinteoir? Oscail anseo é.' ); ?></span>
	</a>
</div>

<div class="mata-note" style="margin-top:24px">
	<strong><?php mata_e( 'Cosaint sonraí trí dhearadh.' ); ?></strong>
	<?php mata_e( 'Ní shábhálann an suíomh seo aon sonra faoi dhalta ar an bhfreastalaí. Cruthaítear an comhad gníomhaíochta i mbrabhsálaí an mhúinteora agus osclaítear i mbrabhsálaí an dalta é.' ); ?>
</div>
<?php
get_footer();
