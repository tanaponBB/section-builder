<?php
/**
 * Template Name: Section Builder — Full Page
 */
get_header(); ?>
<main id="sb-main" class="sb-main">
    <?php echo SectionBuilder\Renderer::render(get_the_ID()); ?>
</main>
<?php get_footer();
