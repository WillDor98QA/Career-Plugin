<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CP_Shortcodes {

    // Set to true the moment a shortcode actually renders, so we only ship assets where they're needed.
    protected static $has_shortcode = false;

    public function __construct() {
        add_shortcode( 'career_listings', array( $this, 'render_listings' ) );
        add_shortcode( 'career_apply',    array( $this, 'render_apply_form' ) );

        // Conditional asset loading: enqueue on single job pages up front, and again from wp_footer
        // for any page that ended up rendering a shortcode.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_for_singular' ) );
        add_action( 'wp_footer', array( $this, 'enqueue_if_shortcode_rendered' ), 1 );

        add_action( 'admin_post_cp_download_cv',        array( $this, 'download_cv' ) );
        // Note: no nopriv handler — CV downloads require admin auth.
    }

    public static function enqueue_assets() {
        if ( wp_style_is( 'career-portal', 'enqueued' ) ) return;

        wp_enqueue_style( 'career-portal', CP_PLUGIN_URL . 'public/css/career-portal.css', array(), CP_VERSION );
        wp_enqueue_script( 'career-portal', CP_PLUGIN_URL . 'public/js/career-portal.js', array('jquery'), CP_VERSION, true );
        wp_localize_script( 'career-portal', 'cpAjax', array(
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cp_apply_nonce'),
        ) );
    }

    public function maybe_enqueue_for_singular() {
        if ( is_singular( 'cp_job' ) ) {
            self::enqueue_assets();
        }
    }

    public function enqueue_if_shortcode_rendered() {
        if ( self::$has_shortcode ) {
            self::enqueue_assets();
        }
    }

    // [career_listings] — shows all open jobs
    public function render_listings( $atts ) {
        self::$has_shortcode = true;
        $atts = shortcode_atts( array( 'department' => '' ), $atts );

        $args = array(
            'post_type'      => 'cp_job',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( $atts['department'] ) {
            $args['tax_query'] = array( array(
                'taxonomy' => 'cp_department',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['department'] ),
            ) );
        }

        $jobs = get_posts( $args );

        // Do not list jobs past their application deadline.
        $jobs = array_values( array_filter( $jobs, function( $job ) {
            return ! CP_Deadline::is_expired( $job->ID );
        } ) );

        ob_start();
        if ( empty( $jobs ) ) {
            echo '<div class="cp-no-jobs"><p>No open positions at the moment. Check back soon!</p></div>';
        } else {
            echo '<div class="cp-jobs-list">';
            foreach ( $jobs as $job ) {
                $location  = get_post_meta( $job->ID, '_cp_location',   true );
                $type      = get_post_meta( $job->ID, '_cp_job_type',    true );
                $salary    = get_post_meta( $job->ID, '_cp_salary',      true );
                $deadline  = get_post_meta( $job->ID, CP_Deadline::META_KEY, true );

                echo '<div class="cp-job-card">';
                echo '<div class="cp-job-header">';
                echo '<h3 class="cp-job-title">' . esc_html( $job->post_title ) . '</h3>';
                echo '<div class="cp-job-meta">';
                if ( $location ) echo '<span class="cp-meta-tag cp-location">📍 ' . esc_html($location) . '</span>';
                if ( $type )     echo '<span class="cp-meta-tag cp-type">' . esc_html($type) . '</span>';
                if ( $salary )   echo '<span class="cp-meta-tag cp-salary">💰 ' . esc_html($salary) . '</span>';
                if ( $deadline ) {
                    echo '<span class="cp-meta-tag cp-deadline">⏰ Closes ' . esc_html( CP_Deadline::format_display( $deadline ) ) . '</span>';
                }
                echo '</div></div>';

                $excerpt = wp_trim_words( $job->post_content, 30 );
                if ( $excerpt ) echo '<p class="cp-job-excerpt">' . esc_html($excerpt) . '</p>';

                echo '<div class="cp-job-actions">';
                echo '<a href="' . esc_url( get_permalink($job->ID) ) . '" class="cp-btn cp-btn-outline">View Details</a>';
                echo '<a href="' . esc_url( get_permalink($job->ID) ) . '#cp-apply" class="cp-btn cp-btn-primary">Apply Now</a>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        return ob_get_clean();
    }

    // [career_apply job_id="123"] — shows the application form
    public function render_apply_form( $atts ) {
        // Instance counter so multiple [career_apply] on one page don't collide on IDs.
        static $instance = 0;
        $instance++;

        $atts   = shortcode_atts( array( 'job_id' => 0 ), $atts );
        $job_id = absint( $atts['job_id'] );

        // Only fall back to the current post if we're actually on a job page.
        if ( ! $job_id ) {
            if ( get_post_type() !== 'cp_job' ) {
                return '<!-- career_apply: no job_id specified and not on a job page -->';
            }
            $job_id = absint( get_the_ID() );
        }

        $job = get_post( $job_id );
        if ( ! $job || $job->post_type !== 'cp_job' || $job->post_status !== 'publish' ) {
            return '<p>This position is no longer accepting applications.</p>';
        }

        if ( CP_Deadline::is_expired( $job_id ) ) {
            return $this->render_deadline_closed_message( $job );
        }

        self::$has_shortcode = true;

        $questions         = get_post_meta( $job_id, '_cp_screening_questions', true ) ?: array();
        $require_portfolio = get_post_meta( $job_id, '_cp_require_portfolio',   true );

        $form_id   = 'cp-application-form-' . $instance;
        $submit_id = 'cp-submit-btn-' . $instance;
        $apply_id  = $instance === 1 ? 'cp-apply' : 'cp-apply-' . $instance;

        ob_start(); ?>
        <div class="cp-apply-form-wrap" id="<?php echo esc_attr( $apply_id ); ?>">
            <h2 class="cp-form-title">Apply for <?php echo esc_html($job->post_title); ?></h2>
            <div class="cp-alert cp-alert-success" style="display:none;"></div>
            <div class="cp-alert cp-alert-error"   style="display:none;"></div>

            <form
                id="<?php echo esc_attr( $form_id ); ?>"
                class="cp-application-form"
                action=""
                method="post"
                enctype="multipart/form-data"
                novalidate>

                <noscript>
                    <div class="cp-alert cp-alert-error" style="display:block;">
                        Please enable JavaScript to submit your application.
                    </div>
                </noscript>

                <?php wp_nonce_field( 'cp_apply_nonce', 'cp_nonce' ); ?>
                <input type="hidden" name="cp_job_id" value="<?php echo esc_attr($job_id); ?>">
                <input type="hidden" name="action"    value="cp_submit_application">
                <!-- Marker used by the no-JS handler to show a friendly message instead of failing silently. -->
                <input type="hidden" name="cp_nojs_submit" value="1">

                <div class="cp-form-section">
                    <h3>Personal Information</h3>
                    <div class="cp-form-row cp-two-col">
                        <div class="cp-field">
                            <label for="cp_full_name_<?php echo esc_attr( $instance ); ?>">Full Name <span class="cp-required">*</span></label>
                            <input type="text" id="cp_full_name_<?php echo esc_attr( $instance ); ?>" name="cp_full_name" required placeholder="John Doe">
                        </div>
                        <div class="cp-field">
                            <label for="cp_email_<?php echo esc_attr( $instance ); ?>">Email Address <span class="cp-required">*</span></label>
                            <input type="email" id="cp_email_<?php echo esc_attr( $instance ); ?>" name="cp_email" required placeholder="you@example.com">
                        </div>
                    </div>
                    <div class="cp-field">
                        <label for="cp_phone_<?php echo esc_attr( $instance ); ?>">Phone Number</label>
                        <input type="tel" id="cp_phone_<?php echo esc_attr( $instance ); ?>" name="cp_phone" placeholder="+233 XX XXX XXXX">
                    </div>
                </div>

                <div class="cp-form-section">
                    <h3>Your Documents</h3>
                    <div class="cp-field">
                        <label for="cp_cv_<?php echo esc_attr( $instance ); ?>">CV / Resume <span class="cp-required">*</span></label>
                        <div class="cp-file-upload">
                            <input type="file" id="cp_cv_<?php echo esc_attr( $instance ); ?>" name="cp_cv" accept=".pdf,.doc,.docx" required>
                            <span class="cp-file-hint">PDF, DOC, or DOCX — max 5MB</span>
                        </div>
                    </div>
                    <div class="cp-field">
                        <label for="cp_portfolio_url_<?php echo esc_attr( $instance ); ?>">Portfolio / Work Samples URL<?php echo $require_portfolio === '1' ? ' <span class="cp-required">*</span>' : ''; ?></label>
                        <input type="url" id="cp_portfolio_url_<?php echo esc_attr( $instance ); ?>" name="cp_portfolio_url"
                            <?php echo $require_portfolio === '1' ? 'required' : ''; ?>
                            placeholder="https://yourportfolio.com">
                    </div>
                    <div class="cp-field">
                        <label for="cp_cover_letter_<?php echo esc_attr( $instance ); ?>">Cover Letter</label>
                        <textarea id="cp_cover_letter_<?php echo esc_attr( $instance ); ?>" name="cp_cover_letter" rows="5" placeholder="Tell us why you're a great fit for this role..."></textarea>
                    </div>
                </div>

                <?php if ( ! empty( $questions ) ) : ?>
                <div class="cp-form-section">
                    <h3>Screening Questions</h3>
                    <?php foreach ( $questions as $q ) : ?>
                    <div class="cp-field">
                        <label><?php echo esc_html($q); ?> <span class="cp-required">*</span></label>
                        <textarea name="cp_screening[<?php echo esc_attr($q); ?>]" rows="3" required placeholder="Your answer..."></textarea>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="cp-form-footer">
                    <button type="submit" class="cp-btn cp-btn-primary cp-btn-lg cp-submit-btn" id="<?php echo esc_attr( $submit_id ); ?>">
                        <span class="cp-btn-text">Submit Application</span>
                        <span class="cp-btn-loading" style="display:none;">Submitting…</span>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_deadline_closed_message( $job ) {
        self::$has_shortcode = true;
        $deadline = get_post_meta( $job->ID, CP_Deadline::META_KEY, true );
        $when     = $deadline ? CP_Deadline::format_display( $deadline ) : '';

        ob_start();
        ?>
        <div class="cp-apply-form-wrap cp-apply-closed" id="cp-apply">
            <h2 class="cp-form-title"><?php echo esc_html( $job->post_title ); ?></h2>
            <div class="cp-alert cp-alert-error" style="display:block;">
                <?php if ( $when ) : ?>
                    <p><?php printf( esc_html__( 'Applications for this position closed on %s.', 'career-portal' ), esc_html( $when ) ); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Applications for this position are no longer being accepted.', 'career-portal' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function download_cv() {
        if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
        if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'cp_download_cv') ) wp_die('Invalid request');

        $id  = absint( $_GET['id'] ?? 0 );
        $app = CP_Database::get_application( $id );
        if ( ! $app || ! $app->cv_file ) wp_die('File not found');

        $path = CP_UPLOAD_DIR . basename( $app->cv_file );
        if ( ! file_exists($path) ) wp_die('File not found on server');

        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $name = sanitize_file_name( $app->full_name . '_CV.' . $ext );

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
