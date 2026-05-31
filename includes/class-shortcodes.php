<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QWJA_Shortcodes {

    // Set to true the moment a shortcode actually renders, so we only ship assets where they're needed.
    protected static $has_shortcode = false;

    const LISTINGS_PER_PAGE = 9;

    public function __construct() {
        add_shortcode( 'qwja_listings', array( $this, 'render_listings' ) );
        add_shortcode( 'qwja_apply',    array( $this, 'render_apply_form' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

        // Conditional asset loading: enqueue on single job pages up front, and again from wp_footer
        // for any page that ended up rendering a shortcode.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_for_singular' ) );
        add_action( 'wp_footer', array( $this, 'enqueue_if_shortcode_rendered' ), 1 );

        add_action( 'admin_post_qwja_download_cv',        array( $this, 'download_cv' ) );
        // Note: no nopriv handler — CV downloads require admin auth.
    }

    public static function enqueue_assets() {
        if ( wp_style_is( 'qadwilliam-jobs-apply', 'enqueued' ) ) return;

        wp_enqueue_style( 'qadwilliam-jobs-apply', QWJA_PLUGIN_URL . 'public/css/qadwilliam-jobs-apply.css', array(), QWJA_VERSION );
        wp_enqueue_script( 'qadwilliam-jobs-apply', QWJA_PLUGIN_URL . 'public/js/qadwilliam-jobs-apply.js', array('jquery'), QWJA_VERSION, true );
        wp_localize_script( 'qadwilliam-jobs-apply', 'qwjaAjax', array(
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qwja_apply_nonce'),
            'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
        ) );
    }

    public function maybe_enqueue_for_singular() {
        if ( is_singular( 'qwja_job' ) ) {
            self::enqueue_assets();
        }
    }

    public function enqueue_if_shortcode_rendered() {
        if ( self::$has_shortcode ) {
            self::enqueue_assets();
        }
    }

    /**
     * @param string[] $vars Query vars.
     * @return string[]
     */
    public function register_query_vars( $vars ) {
        $vars[] = 'qwja_jobs_page';
        return $vars;
    }

    /**
     * Current listings page (1-based) for [qwja_listings] pagination.
     */
    private function get_listings_page() {
        $paged = (int) get_query_var( 'qwja_jobs_page' );
        // Read-only pagination on a public page; no state change, so no nonce required.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( $paged < 1 && isset( $_GET['qwja_jobs_page'] ) ) {
            $paged = absint( wp_unslash( $_GET['qwja_jobs_page'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return max( 1, $paged );
    }

    // [qwja_listings] — open jobs in a 3×3 card grid with pagination.
    public function render_listings( $atts ) {
        self::$has_shortcode = true;
        $atts = shortcode_atts(
            array(
                'department' => '',
                'per_page'   => self::LISTINGS_PER_PAGE,
            ),
            $atts
        );

        $per_page = max( 1, min( 9, absint( $atts['per_page'] ) ) );

        $args = array(
            'post_type'      => 'qwja_job',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( $atts['department'] ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Single optional department filter on a small set of job posts.
            $args['tax_query'] = array( array(
                'taxonomy' => 'qwja_department',
                'field'    => 'slug',
                'terms'    => sanitize_text_field( $atts['department'] ),
            ) );
        }

        $jobs = get_posts( $args );

        // Do not list jobs past their application deadline.
        $jobs = array_values( array_filter( $jobs, function( $job ) {
            return ! QWJA_Deadline::is_expired( $job->ID );
        } ) );

        $total       = count( $jobs );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
        $paged       = $this->get_listings_page();
        if ( $total_pages > 0 ) {
            $paged = min( $paged, $total_pages );
        }
        $offset    = ( $paged - 1 ) * $per_page;
        $jobs_page = array_slice( $jobs, $offset, $per_page );

        ob_start();
        if ( empty( $jobs ) ) {
            echo '<div class="cp-no-jobs"><p>' . esc_html__( 'No open positions at the moment. Check back soon!', 'qadwilliam-jobs-apply' ) . '</p></div>';
        } else {
            echo '<div class="cp-jobs-listings-wrap">';
            echo '<div class="cp-jobs-list" role="list">';
            foreach ( $jobs_page as $job ) {
                echo wp_kses_post( $this->render_job_card( $job ) );
            }
            echo '</div>';

            if ( $total_pages > 1 ) {
                echo wp_kses_post( $this->render_listings_pagination( $paged, $total_pages, $total, $per_page ) );
            }

            echo '</div>';
        }
        return ob_get_clean();
    }

    /**
     * @param WP_Post $job Job post.
     * @return string
     */
    private function render_job_card( $job ) {
        $location  = get_post_meta( $job->ID, '_qwja_location', true );
        $type      = get_post_meta( $job->ID, '_qwja_job_type', true );
        $salary    = get_post_meta( $job->ID, '_qwja_salary', true );
        $deadline  = get_post_meta( $job->ID, QWJA_Deadline::META_KEY, true );
        $excerpt   = wp_trim_words( wp_strip_all_tags( $job->post_content ), 18, '…' );
        $permalink = get_permalink( $job->ID );

        ob_start();
        ?>
        <article class="cp-job-card" role="listitem">
            <div class="cp-job-card-inner">
                <header class="cp-job-header">
                    <h3 class="cp-job-title" title="<?php echo esc_attr( $job->post_title ); ?>">
                        <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $job->post_title ); ?></a>
                    </h3>
                    <div class="cp-job-meta">
                        <?php if ( $location ) : ?>
                            <span class="cp-meta-tag cp-location" title="<?php echo esc_attr( $location ); ?>">📍 <?php echo esc_html( $location ); ?></span>
                        <?php endif; ?>
                        <?php if ( $type ) : ?>
                            <span class="cp-meta-tag cp-type"><?php echo esc_html( $type ); ?></span>
                        <?php endif; ?>
                        <?php if ( $salary ) : ?>
                            <span class="cp-meta-tag cp-salary" title="<?php echo esc_attr( $salary ); ?>">💰 <?php echo esc_html( $salary ); ?></span>
                        <?php endif; ?>
                        <?php if ( $deadline ) : ?>
                            <span class="cp-meta-tag cp-deadline">⏰ <?php echo esc_html( QWJA_Deadline::format_display( $deadline ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </header>
                <?php if ( $excerpt ) : ?>
                    <p class="cp-job-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                <?php else : ?>
                    <p class="cp-job-excerpt cp-job-excerpt--empty" aria-hidden="true"></p>
                <?php endif; ?>
                <footer class="cp-job-actions">
                    <a href="<?php echo esc_url( $permalink ); ?>" class="cp-btn cp-btn-outline">View Details</a>
                    <a href="<?php echo esc_url( $permalink . '#cp-apply' ); ?>" class="cp-btn cp-btn-primary">Apply Now</a>
                </footer>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * @param int $paged       Current page.
     * @param int $total_pages Total pages.
     * @param int $total       Total open jobs.
     * @param int $per_page    Jobs per page.
     * @return string
     */
    private function render_listings_pagination( $paged, $total_pages, $total, $per_page ) {
        $from = ( ( $paged - 1 ) * $per_page ) + 1;
        $to   = min( $paged * $per_page, $total );

        $links = paginate_links( array(
            'base'      => esc_url( add_query_arg( 'qwja_jobs_page', '%#%' ) ),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => '&larr; ' . esc_html__( 'Previous', 'qadwilliam-jobs-apply' ),
            'next_text' => esc_html__( 'Next', 'qadwilliam-jobs-apply' ) . ' &rarr;',
            'type'      => 'list',
            'mid_size'  => 2,
            'end_size'  => 1,
        ) );

        if ( ! $links ) {
            return '';
        }

        ob_start();
        ?>
        <nav class="cp-jobs-pagination" aria-label="<?php esc_attr_e( 'Job listings pages', 'qadwilliam-jobs-apply' ); ?>">
            <p class="cp-jobs-pagination-summary">
                <?php
                printf(
                    /* translators: 1: first item number, 2: last item number, 3: total jobs */
                    esc_html__( 'Showing %1$d–%2$d of %3$d open positions', 'qadwilliam-jobs-apply' ),
                    (int) $from,
                    (int) $to,
                    (int) $total
                );
                ?>
            </p>
            <?php echo wp_kses_post( $links ); ?>
        </nav>
        <?php
        return ob_get_clean();
    }

        // [qwja_apply job_id="123"] — shows the application form
    public function render_apply_form( $atts ) {
        // Instance counter so multiple [qwja_apply] on one page don't collide on IDs.
        static $instance = 0;
        $instance++;

        $atts   = shortcode_atts( array( 'job_id' => 0 ), $atts );
        $job_id = absint( $atts['job_id'] );

        // Only fall back to the current post if we're actually on a job page.
        if ( ! $job_id ) {
            if ( get_post_type() !== 'qwja_job' ) {
                return '<!-- qwja_apply: no job_id specified and not on a job page -->';
            }
            $job_id = absint( get_the_ID() );
        }

        $job = get_post( $job_id );
        if ( ! $job || $job->post_type !== 'qwja_job' || $job->post_status !== 'publish' ) {
            return '<p>This position is no longer accepting applications.</p>';
        }

        if ( QWJA_Deadline::is_expired( $job_id ) ) {
            return $this->render_deadline_closed_message( $job );
        }

        self::$has_shortcode = true;

        $questions         = get_post_meta( $job_id, '_qwja_screening_questions', true ) ?: array();
        $require_portfolio = get_post_meta( $job_id, '_qwja_require_portfolio',   true );

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

                <?php wp_nonce_field( 'qwja_apply_nonce', 'qwja_nonce' ); ?>
                <input type="hidden" name="qwja_job_id" value="<?php echo esc_attr($job_id); ?>">
                <input type="hidden" name="action"    value="qwja_submit_application">
                <!-- Marker used by the no-JS handler to show a friendly message instead of failing silently. -->
                <input type="hidden" name="qwja_nojs_submit" value="1">

                <div class="cp-form-section">
                    <h3>Personal Information</h3>
                    <div class="cp-form-row cp-two-col">
                        <div class="cp-field">
                            <label for="qwja_full_name_<?php echo esc_attr( $instance ); ?>">Full Name <span class="cp-required">*</span></label>
                            <input type="text" id="qwja_full_name_<?php echo esc_attr( $instance ); ?>" name="qwja_full_name" required placeholder="John Doe">
                        </div>
                        <div class="cp-field">
                            <label for="qwja_email_<?php echo esc_attr( $instance ); ?>">Email Address <span class="cp-required">*</span></label>
                            <input type="email" id="qwja_email_<?php echo esc_attr( $instance ); ?>" name="qwja_email" required placeholder="you@example.com">
                        </div>
                    </div>
                    <div class="cp-field">
                        <label for="qwja_phone_<?php echo esc_attr( $instance ); ?>">Phone Number</label>
                        <input type="tel" id="qwja_phone_<?php echo esc_attr( $instance ); ?>" name="qwja_phone" placeholder="+233 XX XXX XXXX">
                    </div>
                </div>

                <div class="cp-form-section">
                    <h3>Your Documents</h3>
                    <div class="cp-field">
                        <label for="qwja_cv_<?php echo esc_attr( $instance ); ?>">CV / Resume <span class="cp-required">*</span></label>
                        <div class="cp-file-upload">
                            <input type="file" id="qwja_cv_<?php echo esc_attr( $instance ); ?>" name="qwja_cv" accept=".pdf,.doc,.docx" required>
                            <span class="cp-file-hint">PDF, DOC, or DOCX — max 5MB</span>
                        </div>
                    </div>
                    <div class="cp-field">
                        <label for="qwja_portfolio_url_<?php echo esc_attr( $instance ); ?>">Portfolio / Work Samples URL<?php echo $require_portfolio === '1' ? ' <span class="cp-required">*</span>' : ''; ?></label>
                        <input type="url" id="qwja_portfolio_url_<?php echo esc_attr( $instance ); ?>" name="qwja_portfolio_url"
                            <?php echo $require_portfolio === '1' ? 'required' : ''; ?>
                            placeholder="https://yourportfolio.com">
                    </div>
                    <div class="cp-field">
                        <label for="qwja_cover_letter_<?php echo esc_attr( $instance ); ?>">Cover Letter</label>
                        <textarea id="qwja_cover_letter_<?php echo esc_attr( $instance ); ?>" name="qwja_cover_letter" rows="5" placeholder="Tell us why you're a great fit for this role..."></textarea>
                    </div>
                </div>

                <?php if ( ! empty( $questions ) ) : ?>
                <div class="cp-form-section">
                    <h3>Screening Questions</h3>
                    <?php foreach ( $questions as $q ) : ?>
                    <div class="cp-field">
                        <label><?php echo esc_html($q); ?> <span class="cp-required">*</span></label>
                        <textarea name="qwja_screening[<?php echo esc_attr($q); ?>]" rows="3" required placeholder="Your answer..."></textarea>
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
        $deadline = get_post_meta( $job->ID, QWJA_Deadline::META_KEY, true );
        $when     = $deadline ? QWJA_Deadline::format_display( $deadline ) : '';

        ob_start();
        ?>
        <div class="cp-apply-form-wrap cp-apply-closed" id="cp-apply">
            <h2 class="cp-form-title"><?php echo esc_html( $job->post_title ); ?></h2>
            <div class="cp-alert cp-alert-error" style="display:block;">
                <?php if ( $when ) : ?>
                    <p><?php
                        /* translators: %s: human-readable date the application window closed */
                        printf( esc_html__( 'Applications for this position closed on %s.', 'qadwilliam-jobs-apply' ), esc_html( $when ) );
                    ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Applications for this position are no longer being accepted.', 'qadwilliam-jobs-apply' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function download_cv() {
        if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
        if ( ! isset( $_GET['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qwja_download_cv' ) ) {
            wp_die( 'Invalid request' );
        }

        $id  = absint( $_GET['id'] ?? 0 );
        $app = QWJA_Database::get_application( $id );
        if ( ! $app || ! $app->cv_file ) wp_die('File not found');

        $dir = qwja_upload_dir();
        if ( '' === $dir ) wp_die('File not found on server');

        $path = $dir . basename( $app->cv_file );
        if ( ! file_exists($path) ) wp_die('File not found on server');

        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $name = sanitize_file_name( $app->full_name . '_CV.' . $ext );

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a binary file to the browser; WP_Filesystem has no equivalent.
        readfile($path);
        exit;
    }
}
