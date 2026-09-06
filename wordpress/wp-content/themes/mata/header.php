<?php
/**
 * @package mata
 */
declare( strict_types=1 );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main"><?php mata_e( 'Léim go dtí an príomhábhar' ); ?></a>

<header class="site-header">
	<div class="mata-wrap bar">
		<a class="site-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<span class="glyph" aria-hidden="true">M</span>
			<span><?php bloginfo( 'name' ); ?>
				<small><?php bloginfo( 'description' ); ?></small>
			</span>
		</a>
		<nav class="site-nav" aria-label="<?php echo esc_attr( mata_t( 'Príomhnascleanúint' ) ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'fallback_cb'    => false,
					'depth'          => 1,
				)
			);
			?>
		</nav>

		<?php
		/*
		 * Language switcher (SRS §6.1: Irish labels "with English support as
		 * agreed with COGG"). A plain link, not JavaScript, so it works without
		 * scripts and is announced properly by a screen reader. hreflang tells
		 * assistive tech the destination language.
		 */
		?>
		<a class="lang-switch"
		   href="<?php echo esc_url( COGG_Mata_I18n::switch_url() ); ?>"
		   hreflang="<?php echo COGG_Mata_I18n::is_english() ? 'ga-IE' : 'en-IE'; ?>"
		   rel="alternate"
		   title="<?php echo esc_attr( COGG_Mata_I18n::switch_label() ); ?>">
			<span class="lang-switch__code"><?php echo esc_html( COGG_Mata_I18n::switch_code() ); ?></span>
			<span class="lang-switch__name"><?php echo esc_html( COGG_Mata_I18n::switch_label() ); ?></span>
		</a>
	</div>
</header>

<?php if ( COGG_Mata_I18n::is_english() ) : ?>
<p class="lang-note">
	<strong>Shown in English.</strong>
	<?php mata_e( 'Nuair nach bhfuil leagan Béarla curtha ar fáil ag COGG, taispeántar an Ghaeilge.' ); ?>
</p>
<?php endif; ?>

<main id="main" tabindex="-1">
	<div class="mata-wrap">
