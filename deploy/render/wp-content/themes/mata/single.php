<?php
/**
 * Single resource or activity.
 *
 * @package mata
 */
declare( strict_types=1 );
get_header();

while ( have_posts() ) :
	the_post();
	$post_id   = get_the_ID();
	$file_id   = (int) get_post_meta( $post_id, '_mata_file_id', true );
	$adaptable = (bool) get_post_meta( $post_id, '_mata_adaptable', true );
	$is_act    = ( 'mata_activity' === get_post_type() );
	?>
	<article <?php post_class(); ?>>
		<h1><?php the_title(); ?></h1>

		<div class="entry-meta mata-chips">
			<?php
			foreach ( array_keys( COGG_Mata_CPT::TAXONOMIES ) as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( ! $terms || is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					printf(
						'<a class="mata-chip" href="%s">%s</a>',
						esc_url( (string) get_term_link( $term ) ),
						esc_html( $term->name )
					);
				}
			}
			?>
		</div>

		<div class="entry-content"><?php the_content(); ?></div>

		<?php if ( $is_act ) : ?>
			<?php echo wp_kses_post( (string) apply_filters( 'cogg_mata_activity_render', '', $post_id ) ); ?>
			<?php if ( ! $adaptable ) : ?>
				<p class="mata-chip mata-chip--warn"><?php mata_e( 'Ní féidir í seo a chur in oiriúint' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $file_id ) : ?>
			<p style="margin-top:18px">
				<a class="mata-btn mata-btn--primary"
				   href="<?php echo esc_url( (string) wp_get_attachment_url( $file_id ) ); ?>"
				   data-download="<?php echo esc_attr( (string) $post_id ); ?>" download>
					<?php mata_e( 'Íoslódáil' ); ?> (<?php echo esc_html( size_format( (int) filesize( (string) get_attached_file( $file_id ) ) ) ); ?>)
				</a>
			</p>
			<p class="mata-hint"><?php mata_e( 'Ní theastaíonn cuntas chun an acmhainn seo a íoslódáil.' ); ?></p>
		<?php endif; ?>
	</article>
	<?php
endwhile;

get_footer();
