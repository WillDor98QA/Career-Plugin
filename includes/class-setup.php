<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * First-run setup: careers page, admin guidance, helper URLs.
 */
class CP_Setup {

    const CAREERS_PAGE_OPTION = 'cp_careers_page_id';
    const SETUP_DONE_OPTION   = 'cp_setup_dismissed';

    public function __construct() {
        add_action( 'admin_notices', array( $this, 'render_setup_notice' ) );
        add_action( 'wp_ajax_cp_dismiss_setup_notice', array( $this, 'dismiss_setup_notice' ) );
    }

    /**
     * Create a published Careers page with [career_listings] on activation.
     */
    public static function create_careers_page() {
        $page_id = (int) get_option( self::CAREERS_PAGE_OPTION, 0 );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            return $page_id;
        }

        $existing = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            's'              => 'careers',
        ) );

        foreach ( $existing as $page ) {
            if ( stripos( $page->post_title, 'career' ) !== false && has_shortcode( $page->post_content, 'career_listings' ) ) {
                update_option( self::CAREERS_PAGE_OPTION, $page->ID );
                return $page->ID;
            }
        }

        $page_id = wp_insert_post( array(
            'post_title'   => __( 'Careers', 'career-portal' ),
            'post_name'    => 'careers',
            'post_content' => '[career_listings]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ), true );

        if ( is_wp_error( $page_id ) ) {
            return 0;
        }

        update_option( self::CAREERS_PAGE_OPTION, $page_id );
        return (int) $page_id;
    }

    public function render_setup_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( self::SETUP_DONE_OPTION ) ) {
            return;
        }

        $careers_page_id = (int) get_option( self::CAREERS_PAGE_OPTION, 0 );
        $careers_url     = $careers_page_id ? get_permalink( $careers_page_id ) : '';
        $jobs_count      = (int) wp_count_posts( 'cp_job' )->publish;
        $admin_email     = get_option( 'cp_admin_email', '' );

        ?>
        <div class="notice notice-info is-dismissible cp-setup-notice" data-cp-dismiss="setup">
            <p><strong><?php esc_html_e( 'Career Portal is active', 'career-portal' ); ?></strong> — <?php esc_html_e( 'Complete these steps for a working careers site on any theme:', 'career-portal' ); ?></p>
            <ol style="margin-left:1.2em;list-style:decimal;">
                <li>
                    <?php
                    esc_html_e( 'Save permalinks once:', 'career-portal' );
                    echo ' <a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings → Permalinks → Save', 'career-portal' ) . '</a>';
                    ?>
                </li>
                <li>
                    <?php
                    if ( $careers_url ) {
                        printf(
                            /* translators: %s: URL to the careers page */
                            esc_html__( 'Careers listing page: %s (uses [career_listings])', 'career-portal' ),
                            '<a href="' . esc_url( $careers_url ) . '" target="_blank">' . esc_html__( 'View page', 'career-portal' ) . '</a>'
                        );
                    } else {
                        esc_html_e( 'Create a page and add the [career_listings] shortcode.', 'career-portal' );
                    }
                    ?>
                </li>
                <li>
                    <?php
                    printf(
                        /* translators: %s: admin settings URL */
                        esc_html__( 'Set your notification email under %s.', 'career-portal' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=career-portal-settings' ) ) . '">Career Portal → Settings</a>'
                    );
                    if ( ! $admin_email ) {
                        echo ' <em>(' . esc_html__( 'recommended', 'career-portal' ) . ')</em>';
                    }
                    ?>
                </li>
                <li>
                    <?php
                    if ( $jobs_count > 0 ) {
                        printf(
                            /* translators: %d: number of published jobs */
                            esc_html__( 'You have %d published job(s). Job detail pages use your theme’s single-cp_job.php if present, otherwise the plugin’s default template with the apply form.', 'career-portal' ),
                            $jobs_count
                        );
                    } else {
                        echo '<a href="' . esc_url( admin_url( 'post-new.php?post_type=cp_job' ) ) . '">' . esc_html__( 'Publish your first job', 'career-portal' ) . '</a>';
                    }
                    ?>
                </li>
            </ol>
            <p class="description">
                <?php esc_html_e( 'Custom theme layouts (e.g. branded careers pages) are optional. The plugin provides admin, applications, emails, listings, and job pages without theme code.', 'career-portal' ); ?>
            </p>
        </div>
        <script>
        (function() {
            var notice = document.querySelector('.cp-setup-notice');
            if (!notice) return;
            notice.addEventListener('click', function(e) {
                if (!e.target.classList.contains('notice-dismiss')) return;
                var fd = new FormData();
                fd.append('action', 'cp_dismiss_setup_notice');
                fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'cp_dismiss_setup' ) ); ?>');
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
            });
        })();
        </script>
        <?php
    }

    public function dismiss_setup_notice() {
        check_ajax_referer( 'cp_dismiss_setup', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        update_option( self::SETUP_DONE_OPTION, 1 );
        wp_send_json_success();
    }
}

/**
 * URL of the auto-created (or linked) careers listing page.
 */
function cp_get_careers_page_url() {
    $page_id = (int) get_option( CP_Setup::CAREERS_PAGE_OPTION, 0 );
    if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
        return get_permalink( $page_id );
    }
    return '';
}
