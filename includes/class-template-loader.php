<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Provides a default single-job template when the active theme does not
 * ship single-qwja_job.php (e.g. GHIB theme overrides; generic themes do not).
 */
class QWJA_Template_Loader {

    public function __construct() {
        add_filter( 'template_include', array( $this, 'load_single_job_template' ), 99 );
        add_filter( 'the_content', array( $this, 'append_apply_form_to_job_content' ), 20 );
    }

    public function load_single_job_template( $template ) {
        if ( ! is_singular( 'qwja_job' ) ) {
            return $template;
        }

        // Theme wins if it provides single-qwja_job.php (GHIB and other integrated themes).
        $theme_template = locate_template( array( 'single-qwja_job.php' ) );
        if ( $theme_template ) {
            return $theme_template;
        }

        $plugin_template = QWJA_PLUGIN_DIR . 'templates/single-qwja_job.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }

        return $template;
    }

    /**
     * On generic theme single.php views, append the apply form after job description
     * unless the editor already embedded [qwja_apply].
     */
    public function append_apply_form_to_job_content( $content ) {
        if ( defined( 'QWJA_RENDERING_JOB_TEMPLATE' ) && QWJA_RENDERING_JOB_TEMPLATE ) {
            return $content;
        }

        if ( ! is_singular( 'qwja_job' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        if ( has_shortcode( $content, 'qwja_apply' ) ) {
            return $content;
        }

        if ( QWJA_Deadline::is_expired( get_the_ID() ) ) {
            return $content;
        }

        return $content . do_shortcode( '[qwja_apply]' );
    }
}
