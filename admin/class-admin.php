<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QWJA_Admin {

    public function __construct() {
        add_action( 'admin_menu',           array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts',array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_qwja_update_status', array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_qwja_send_test_email', array( $this, 'ajax_send_test_email' ) );
        add_action( 'admin_post_qwja_download_cv', array( $this, 'proxy_download' ) );
    }

    public function register_menus() {
        add_menu_page(
            'Qadwilliam Jobs & Apply', 'Qadwilliam Jobs & Apply', 'manage_options',
            'qadwilliam-jobs-apply', array( $this, 'render_dashboard' ),
            'dashicons-groups', null
        );
        add_submenu_page(
            'qadwilliam-jobs-apply', 'Applications', 'Applications', 'manage_options',
            'qadwilliam-jobs-apply', array( $this, 'render_dashboard' )
        );
        add_submenu_page(
            'qadwilliam-jobs-apply', 'Job Listings', 'Job Listings', 'manage_options',
            'edit.php?post_type=qwja_job'
        );
        add_submenu_page(
            'qadwilliam-jobs-apply', 'Add New Job', 'Add New Job', 'manage_options',
            'post-new.php?post_type=qwja_job'
        );
        add_submenu_page(
            'qadwilliam-jobs-apply', 'Settings', 'Settings', 'manage_options',
            'qadwilliam-jobs-apply-settings', array( $this, 'render_settings' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos($hook, 'qadwilliam-jobs-apply') === false && get_post_type() !== 'qwja_job' ) return;
        wp_enqueue_style( 'qwja-admin', QWJA_PLUGIN_URL . 'admin/admin.css', array(), QWJA_VERSION );
        wp_enqueue_script( 'qwja-admin', QWJA_PLUGIN_URL . 'admin/admin.js', array('jquery'), QWJA_VERSION, true );
        wp_localize_script( 'qwja-admin', 'qwjaAdmin', array(
            'nonce'          => wp_create_nonce('qwja_admin_nonce'),
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'isSettingsPage' => ( strpos( $hook, 'qadwilliam-jobs-apply-settings' ) !== false ),
            'mailConfigured' => QWJA_Mailer::is_configured(),
        ) );
    }

    public function render_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'qadwilliam-jobs-apply' ) );
        }

        // The dashboard is a read-only admin list view filtered via GET; no state is
        // changed here, so a nonce is not required for these reads.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $action = sanitize_text_field( wp_unslash( $_GET['action'] ?? 'list' ) );

        if ( $action === 'view' && ! empty($_GET['id']) ) {
            $this->render_single_application( absint( wp_unslash( $_GET['id'] ) ) );
            return;
        }

        // Filters
        $job_filter    = absint( wp_unslash( $_GET['job_id'] ?? 0 ) );
        $status_filter = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
        $paged         = max(1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $per_page      = 20;

        $args = array(
            'job_id'   => $job_filter,
            'status'   => $status_filter,
            'per_page' => $per_page,
            'paged'    => $paged,
        );

        $applications = QWJA_Database::get_applications( $args );
        $total        = QWJA_Database::count_applications( $args );
        $total_pages  = ceil( $total / $per_page );

        // Stats
        $stats = array(
            'total'     => QWJA_Database::count_applications(),
            'pending'   => QWJA_Database::count_applications(array('status'=>'pending')),
            'interview' => QWJA_Database::count_applications(array('status'=>'interview')),
            'hired'     => QWJA_Database::count_applications(array('status'=>'hired')),
        );

        // Jobs for filter dropdown
        $jobs = get_posts(array('post_type'=>'qwja_job','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC'));

        $statuses = array(
            'pending'   => array('label'=>'Pending',   'color'=>'#f0ad4e'),
            'reviewing' => array('label'=>'Reviewing', 'color'=>'#5bc0de'),
            'interview' => array('label'=>'Interview', 'color'=>'#9b59b6'),
            'hired'     => array('label'=>'Hired',     'color'=>'#5cb85c'),
            'rejected'  => array('label'=>'Rejected',  'color'=>'#d9534f'),
        );
        ?>
        <div class="wrap cp-admin-wrap">
            <h1 class="cp-admin-title">Qadwilliam Jobs & Apply <span class="cp-version">v<?php echo esc_html( QWJA_VERSION ); ?></span></h1>

            <!-- Stats -->
            <div class="cp-stats-row">
                <div class="cp-stat-card"><span class="cp-stat-number"><?php echo (int) $stats['total']; ?></span><span class="cp-stat-label">Total Applications</span></div>
                <div class="cp-stat-card cp-stat-pending"><span class="cp-stat-number"><?php echo (int) $stats['pending']; ?></span><span class="cp-stat-label">Pending Review</span></div>
                <div class="cp-stat-card cp-stat-interview"><span class="cp-stat-number"><?php echo (int) $stats['interview']; ?></span><span class="cp-stat-label">In Interview</span></div>
                <div class="cp-stat-card cp-stat-hired"><span class="cp-stat-number"><?php echo (int) $stats['hired']; ?></span><span class="cp-stat-label">Hired</span></div>
            </div>

            <!-- Filters -->
            <div class="cp-filters-bar">
                <form method="get">
                    <input type="hidden" name="page" value="qadwilliam-jobs-apply">
                    <select name="job_id" onchange="this.form.submit()">
                        <option value="">All Positions</option>
                        <?php foreach ($jobs as $j) : ?>
                        <option value="<?php echo (int) $j->ID; ?>" <?php selected($job_filter, $j->ID); ?>><?php echo esc_html($j->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $key => $s) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected($status_filter, $key); ?>><?php echo esc_html( $s['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <span class="cp-result-count"><?php echo (int) $total; ?> application<?php echo $total !== 1 ? 's' : ''; ?></span>
            </div>

            <!-- Applications Table -->
            <table class="cp-applications-table widefat">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Applicant</th>
                        <th>Position</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty($applications) ) : ?>
                    <tr><td colspan="6" class="cp-empty">No applications found.</td></tr>
                <?php else : foreach ($applications as $app) :
                    $job = get_post($app->job_id);
                    $s   = $statuses[$app->status] ?? $statuses['pending'];
                ?>
                    <tr>
                        <td>#<?php echo (int) $app->id; ?></td>
                        <td>
                            <strong><?php echo esc_html($app->full_name); ?></strong><br>
                            <a href="mailto:<?php echo esc_attr($app->email); ?>" class="cp-email-link"><?php echo esc_html($app->email); ?></a>
                        </td>
                        <td><?php echo $job ? esc_html($job->post_title) : '<em>' . esc_html__( 'Deleted', 'qadwilliam-jobs-apply' ) . '</em>'; ?></td>
                        <td><?php echo esc_html( date_i18n('M j, Y', strtotime($app->submitted_at)) ); ?></td>
                        <td>
                            <span class="cp-status-badge" style="background:<?php echo esc_attr($s['color']); ?>20;color:<?php echo esc_attr($s['color']); ?>;border:1px solid <?php echo esc_attr($s['color']); ?>40;">
                                <?php echo esc_html( $s['label'] ); ?>
                            </span>
                        </td>
                        <td class="cp-actions">
                            <a href="<?php echo esc_url( admin_url('admin.php?page=qadwilliam-jobs-apply&action=view&id='.$app->id) ); ?>" class="button button-small">View</a>
                            <?php if ($app->cv_file) : ?>
                            <a href="<?php echo esc_url( admin_url('admin-post.php?action=qwja_download_cv&id='.$app->id.'&_wpnonce='.wp_create_nonce('qwja_download_cv')) ); ?>" class="button button-small">⬇ CV</a>
                            <?php endif; ?>
                            <select class="cp-status-select" data-id="<?php echo (int) $app->id; ?>">
                                <?php foreach ($statuses as $key => $s2) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected($app->status, $key); ?>><?php echo esc_html( $s2['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
            <div class="cp-pagination">
                <?php
                $base = add_query_arg(array('page'=>'qadwilliam-jobs-apply','job_id'=>$job_filter,'status'=>$status_filter,'paged'=>'%#%'), admin_url('admin.php'));
                echo wp_kses_post( paginate_links(array('base'=>$base,'format'=>'','total'=>$total_pages,'current'=>$paged,'prev_text'=>'&laquo; Prev','next_text'=>'Next &raquo;')) );
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_single_application( $id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'qadwilliam-jobs-apply' ) );
        }

        $app  = QWJA_Database::get_application( $id );
        if ( ! $app ) { echo '<div class="wrap"><p>' . esc_html__( 'Application not found.', 'qadwilliam-jobs-apply' ) . '</p></div>'; return; }

        $job     = get_post( $app->job_id );
        $answers = QWJA_Database::get_screening_answers( $id );
        $statuses = array(
            'pending'=>'Pending','reviewing'=>'Reviewing','interview'=>'Interview','hired'=>'Hired','rejected'=>'Rejected'
        );
        $status_colors = array('pending'=>'#f0ad4e','reviewing'=>'#5bc0de','interview'=>'#9b59b6','hired'=>'#5cb85c','rejected'=>'#d9534f');
        ?>
        <div class="wrap cp-admin-wrap">
            <a href="<?php echo esc_url(admin_url('admin.php?page=qadwilliam-jobs-apply')); ?>" class="cp-back-link">← Back to Applications</a>
            <h1>Application #<?php echo (int) $id; ?> — <?php echo esc_html($app->full_name); ?></h1>

            <div class="cp-detail-grid">
                <div class="cp-detail-main">
                    <div class="cp-detail-card">
                        <h3>Applicant Details</h3>
                        <table class="cp-detail-table">
                            <tr><td>Name</td><td><strong><?php echo esc_html($app->full_name); ?></strong></td></tr>
                            <tr><td>Email</td><td><a href="mailto:<?php echo esc_attr($app->email); ?>"><?php echo esc_html($app->email); ?></a></td></tr>
                            <tr><td>Phone</td><td><?php echo esc_html($app->phone ?: '—'); ?></td></tr>
                            <tr><td>Position</td><td><?php echo $job ? esc_html($job->post_title) : '<em>' . esc_html__( 'Deleted', 'qadwilliam-jobs-apply' ) . '</em>'; ?></td></tr>
                            <tr><td>Portfolio</td><td><?php echo $app->portfolio_url ? '<a href="' . esc_url($app->portfolio_url) . '" target="_blank">' . esc_html($app->portfolio_url) . '</a>' : '—'; ?></td></tr>
                            <tr><td>Submitted</td><td><?php echo esc_html( date_i18n('M j, Y g:i A', strtotime($app->submitted_at)) ); ?></td></tr>
                        </table>
                    </div>

                    <?php if ($app->cover_letter) : ?>
                    <div class="cp-detail-card">
                        <h3>Cover Letter</h3>
                        <div class="cp-cover-letter"><?php echo nl2br(esc_html($app->cover_letter)); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($answers) : ?>
                    <div class="cp-detail-card">
                        <h3>Screening Questions</h3>
                        <?php foreach ($answers as $a) : ?>
                        <div class="cp-qa-block">
                            <p class="cp-question"><?php echo esc_html($a->question); ?></p>
                            <p class="cp-answer"><?php echo nl2br(esc_html($a->answer)); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="cp-detail-sidebar">
                    <div class="cp-detail-card">
                        <h3>Status</h3>
                        <div class="cp-status-current" style="color:<?php echo esc_attr( $status_colors[$app->status] ?? '#888' ); ?>">
                            <?php echo esc_html( $statuses[$app->status] ?? ucfirst($app->status) ); ?>
                        </div>
                        <select id="cp-status-select" data-id="<?php echo (int) $id; ?>" class="cp-status-select widefat">
                            <?php foreach ($statuses as $key => $label) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected($app->status, $key); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="cp-status-note">Changing status will email the applicant.</p>
                    </div>

                    <?php if ($app->cv_file) : ?>
                    <div class="cp-detail-card">
                        <h3>CV / Resume</h3>
                        <a href="<?php echo esc_url( admin_url('admin-post.php?action=qwja_download_cv&id='.$id.'&_wpnonce='.wp_create_nonce('qwja_download_cv')) ); ?>" class="button button-primary" style="width:100%;text-align:center;">⬇ Download CV</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'qadwilliam-jobs-apply' ) );
        }

        if ( isset( $_POST['qwja_settings_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qwja_settings_nonce'] ) ), 'qwja_save_settings' ) ) {
            update_option( 'qwja_admin_email', sanitize_email( wp_unslash( $_POST['qwja_admin_email'] ?? '' ) ) );
            QWJA_Mailer::save_from_post( $_POST );
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        $admin_email     = get_option( 'qwja_admin_email', get_option( 'admin_email' ) );
        $mail            = QWJA_Mailer::get_settings();
        $careers_page_id = (int) get_option( 'qwja_careers_page_id', 0 );
        $careers_url     = $careers_page_id ? get_permalink( $careers_page_id ) : '';
        $mail_ok         = QWJA_Mailer::is_configured();
        ?>
        <div class="wrap cp-admin-wrap">
            <h1>Qadwilliam Jobs & Apply Settings</h1>

            <?php if ( $careers_url ) : ?>
            <p>
                <?php esc_html_e( 'Careers listing page:', 'qadwilliam-jobs-apply' ); ?>
                <a href="<?php echo esc_url( $careers_url ); ?>" target="_blank"><?php echo esc_html( get_the_title( $careers_page_id ) ); ?></a>
                —
                <a href="<?php echo esc_url( get_edit_post_link( $careers_page_id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit page', 'qadwilliam-jobs-apply' ); ?></a>
            </p>
            <?php endif; ?>

            <?php if ( ! $mail_ok ) : ?>
            <div class="notice notice-warning"><p><strong>SMTP not configured.</strong> Application emails will not send until you enable and save SMTP settings below.</p></div>
            <?php else : ?>
            <div class="notice notice-success"><p><strong>SMTP is active.</strong> Qadwilliam Jobs & Apply sends all application emails through its own mailer (independent of WP Mail SMTP and other plugins).</p></div>
            <?php endif; ?>

            <form method="post" class="cp-settings-form">
                <?php wp_nonce_field( 'qwja_save_settings', 'qwja_settings_nonce' ); ?>

                <h2 class="title">Notifications</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="qwja_admin_email">Admin notification email</label></th>
                        <td>
                            <input type="email" id="qwja_admin_email" name="qwja_admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text">
                            <p class="description">Receives alerts when someone applies for a job.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Mail server (SMTP)</h2>
                <p class="description">Qadwilliam Jobs & Apply uses its own SMTP connection. You do not need WP Mail SMTP or another mail plugin.</p>

                <table class="form-table cp-mail-settings">
                    <tr>
                        <th><label for="qwja_mail_enabled">Enable SMTP</label></th>
                        <td>
                            <label><input type="checkbox" id="qwja_mail_enabled" name="qwja_mail_enabled" value="1" <?php checked( $mail['enabled'], '1' ); ?>> Use Qadwilliam Jobs & Apply SMTP for all plugin emails</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_host">SMTP host</label></th>
                        <td>
                            <input type="text" id="qwja_mail_host" name="qwja_mail_host" value="<?php echo esc_attr( $mail['host'] ); ?>" class="regular-text" placeholder="smtp.gmail.com">
                            <p class="description">Examples: <code>smtp.gmail.com</code>, <code>smtp.office365.com</code>, <code>mail.yourdomain.com</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_port">SMTP port</label></th>
                        <td>
                            <input type="number" id="qwja_mail_port" name="qwja_mail_port" value="<?php echo esc_attr( $mail['port'] ); ?>" class="small-text" min="1" max="65535">
                            <p class="description">Usually <code>587</code> (TLS) or <code>465</code> (SSL).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_encryption">Encryption</label></th>
                        <td>
                            <select id="qwja_mail_encryption" name="qwja_mail_encryption">
                                <option value="tls" <?php selected( $mail['encryption'], 'tls' ); ?>>TLS (recommended)</option>
                                <option value="ssl" <?php selected( $mail['encryption'], 'ssl' ); ?>>SSL</option>
                                <option value="none" <?php selected( $mail['encryption'], 'none' ); ?>>None</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Authentication</th>
                        <td>
                            <label><input type="checkbox" name="qwja_mail_auth" value="1" <?php checked( $mail['auth'], '1' ); ?>> SMTP authentication required</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_username">Username</label></th>
                        <td><input type="text" id="qwja_mail_username" name="qwja_mail_username" value="<?php echo esc_attr( $mail['username'] ); ?>" class="regular-text" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_password">Password</label></th>
                        <td>
                            <input type="password" id="qwja_mail_password" name="qwja_mail_password" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $mail['password'] ? esc_attr__( '•••••••• (unchanged)', 'qadwilliam-jobs-apply' ) : ''; ?>">
                            <p class="description">Leave blank to keep the current password. For Gmail, use an <a href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener">App Password</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_from_email">From email</label></th>
                        <td>
                            <input type="email" id="qwja_mail_from_email" name="qwja_mail_from_email" value="<?php echo esc_attr( $mail['from_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                            <p class="description">Must be allowed by your mail provider (often must match the SMTP account).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qwja_mail_from_name">From name</label></th>
                        <td>
                            <input type="text" id="qwja_mail_from_name" name="qwja_mail_from_name" value="<?php echo esc_attr( $mail['from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                        </td>
                    </tr>
                </table>

                <p>
                    <label for="qwja_test_email">Send test email to:</label>
                    <input type="email" id="qwja_test_email" class="regular-text" value="<?php echo esc_attr( $admin_email ); ?>" style="margin-left:8px;">
                    <button type="button" class="button" id="cp-send-test-email">Send test email</button>
                    <span id="cp-test-email-result" style="margin-left:10px;"></span>
                </p>
                <p class="description">Save settings first, then send a test. The test uses Qadwilliam Jobs & Apply SMTP only.</p>

                <h2 class="title">Shortcodes</h2>
                <table class="widefat" style="max-width:600px;">
                    <tr><td><code>[qwja_listings]</code></td><td>Shows all open job listings</td></tr>
                    <tr><td><code>[qwja_apply]</code></td><td>Shows application form on a single job page</td></tr>
                    <tr><td><code>[qwja_listings department="design"]</code></td><td>Filter by department slug</td></tr>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }

    public function ajax_send_test_email() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'qwja_admin_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $to = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! is_email( $to ) ) {
            wp_send_json_error( array( 'message' => 'Enter a valid email address.' ) );
        }

        $result = QWJA_Mailer::send_test( $to );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'Test email sent to ' . $to . '. Check your inbox.' ) );
    }

    public function ajax_update_status() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'qwja_admin_nonce' ) ) wp_send_json_error( 'Unauthorized' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
        $prev   = QWJA_Database::get_application($id);

        if ( QWJA_Database::update_status($id, $status) === false ) {
            wp_send_json_error('Could not update status.');
        }

        // Send status change email if status actually changed
        if ( $prev && $prev->status !== $status ) {
            $notifications = new QWJA_Email_Notifications();
            $notifications->notify_status_change($id, $status);
        }

        wp_send_json_success( array('message'=>'Status updated.') );
    }

    public function proxy_download() {
        // Delegate to shortcode handler
        (new QWJA_Shortcodes())->download_cv();
    }
}
