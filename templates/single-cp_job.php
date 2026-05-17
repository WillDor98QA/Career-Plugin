<?php
/**
 * Default single job template (used when the theme has no single-cp_job.php).
 *
 * @package Career_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CP_RENDERING_JOB_TEMPLATE', true );

get_header();
?>
<main id="primary" class="site-main cp-job-single-wrap">
    <?php
    while ( have_posts() ) :
        the_post();
        $location = get_post_meta( get_the_ID(), '_cp_location', true );
        $type     = get_post_meta( get_the_ID(), '_cp_job_type', true );
        $salary   = get_post_meta( get_the_ID(), '_cp_salary', true );
        $deadline  = get_post_meta( get_the_ID(), CP_Deadline::META_KEY, true );
        $is_closed = CP_Deadline::is_expired( get_the_ID() );
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class( 'cp-job-single' ); ?>>
            <header class="cp-job-single-header">
                <p class="cp-job-single-back">
                    <?php
                    $careers_url = cp_get_careers_page_url();
                    if ( $careers_url ) {
                        echo '<a href="' . esc_url( $careers_url ) . '">&larr; ' . esc_html__( 'Back to all positions', 'career-portal' ) . '</a>';
                    }
                    ?>
                </p>
                <h1 class="cp-job-single-title"><?php the_title(); ?></h1>
                <div class="cp-job-meta cp-job-single-meta">
                    <?php if ( $location ) : ?>
                        <span class="cp-meta-tag cp-location"><?php echo esc_html( $location ); ?></span>
                    <?php endif; ?>
                    <?php if ( $type ) : ?>
                        <span class="cp-meta-tag cp-type"><?php echo esc_html( $type ); ?></span>
                    <?php endif; ?>
                    <?php if ( $salary ) : ?>
                        <span class="cp-meta-tag cp-salary"><?php echo esc_html( $salary ); ?></span>
                    <?php endif; ?>
                    <?php if ( $deadline ) : ?>
                        <span class="cp-meta-tag cp-deadline">
                            <?php
                            if ( $is_closed ) {
                                /* translators: %s: formatted closing date/time */
                                printf( esc_html__( 'Closed %s', 'career-portal' ), esc_html( CP_Deadline::format_display( $deadline ) ) );
                            } else {
                                /* translators: %s: formatted closing date/time */
                                printf( esc_html__( 'Closes %s', 'career-portal' ), esc_html( CP_Deadline::format_display( $deadline ) ) );
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </header>

            <div class="cp-job-single-content entry-content">
                <?php the_content(); ?>
            </div>

            <?php
            if ( ! $is_closed && ! has_shortcode( get_post()->post_content, 'career_apply' ) ) {
                echo do_shortcode( '[career_apply]' );
            } elseif ( $is_closed ) {
                echo do_shortcode( '[career_apply job_id="' . (int) get_the_ID() . '"]' );
            }
            ?>
        </article>
        <?php
    endwhile;
    ?>
</main>
<?php
get_footer();
