<?php
/**
 * Dedicated Page Template for Lead Dashboard (Embedded in plugin)
 * 
 * @package FluentForm_Frontend_Entries
 */

get_header();
?>

<main id="main" class="pt-28 pb-16 min-h-[70vh] bg-slate-50">
    <div class="max-w-[1400px] mx-auto px-4 md:px-8">
        <?php
        while ( have_posts() ) :
            the_post();
            ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="mb-8">
                    <h1 class="text-2xl md:text-3xl font-extrabold text-navy-900 tracking-tight"><?php the_title(); ?></h1>
                </header>
                <div class="page-content bg-white p-6 md:p-8 rounded-xl shadow-sm border border-slate-200">
                    <?php the_content(); ?>
                </div>
            </article>
            <?php
        endwhile;
        ?>
    </div>
</main>

<?php
get_footer();
