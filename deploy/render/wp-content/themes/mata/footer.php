<?php
/**
 * @package mata
 */
declare( strict_types=1 );
?>
	</div>
</main>

<footer class="site-footer">
	<div class="mata-wrap bar">
		<span>&copy; <?php echo esc_html( (string) gmdate( 'Y' ) ); ?> COGG — An Chomhairle um Oideachas Gaeltachta agus Gaelscolaíochta</span>
		<a href="<?php echo esc_url( home_url( '/priobhaideacht/' ) ); ?>"><?php mata_e( 'Fógra príobháideachais' ); ?></a>
		<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php mata_e( 'Riarachán' ); ?></a>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
