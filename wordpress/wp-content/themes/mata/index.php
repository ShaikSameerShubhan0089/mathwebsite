<?php
/**
 * Generic fallback template.
 *
 * @package mata
 */
declare( strict_types=1 );
get_header();
?>
<h1><?php echo esc_html( wp_get_document_title() ); ?></h1>

<?php if ( have_posts() ) : ?>
	<div class="mata-results">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'mata-card' ); ?>>
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<p class="mata-desc"><?php echo esc_html( get_the_excerpt() ); ?></p>
			</article>
			<?php
		endwhile;
		?>
	</div>
	<div class="pager"><?php echo wp_kses_post( (string) paginate_links( array( 'type' => 'list' ) ) ); ?></div>
<?php else : ?>
	<div class="mata-empty">
		<p><strong>Níor aimsíodh aon toradh.</strong></p>
		<p>Bain triail as cuardach níos leithne.</p>
	</div>
<?php endif; ?>
<?php
get_footer();
