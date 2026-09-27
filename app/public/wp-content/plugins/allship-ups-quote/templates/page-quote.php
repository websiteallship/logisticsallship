<?php
/**
 * Plugin-provided page template for UPS Quote Form.
 *
 * This template is automatically applied via the `template_include` filter
 * when the current page contains the [ups_quote_form] shortcode.
 * It wraps the page content with the active theme's header/footer
 * while providing a clean, title-free layout.
 *
 * @package Allship_UPS_Quote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="main" class="ups-quote-page-wrap" style="background:#F4F6F9;padding-top:96px;padding-bottom:48px;min-height:100vh;">
	<div style="max-width:1200px;margin:0 auto;padding:0 12px;">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</div>
</main>

<?php
get_footer();
