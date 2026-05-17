<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CP_Admin {

    public function __construct() {
        add_action( 'admin_menu',           array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts',array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_cp_update_status', array( $this, 'ajax_update_status' ) );
        add_action( 'admin_post_cp_download_cv', array( $this, 'proxy_download' ) );
    }

    public function register_menus() {
        add_menu_page(
            'Career Portal', 'Career Portal', 'manage_options',
            'career-portal', array( $this, 'render_dashboard' ),
            'dashicons-groups', 25
        );
        add_submenu_page(
            'career-portal', 'Applications', 'Applications', 'manage_options',
            'career-portal', array( $this, 'render_dashboard' )
        );
        add_submenu_page(
            'career-portal', 'Job Listings', 'Job Listings', 'manage_options',
            'edit.php?post_type=cp_job'
        );
        add_submenu_page(
            'career-portal', 'Add New Job', 'Add New Job', 'manage_options',
            'post-new.php?post_type=cp_job'
        );
        add_submenu_page(
            'career-portal', 'Settings', 'Settings', 'manage_options',
            'career-portal-settings', array( $this, 'render_settings' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos($hook, 'career-portal') === false && get_post_type() !== 'cp_job' ) return;
        wp_enqueue_style( 'cp-admin', CP_PLUGIN_URL . 'admin/admin.css', array(), CP_VERSION );
        wp_enqueue_script( 'cp-admin', CP_PLUGIN_URL . 'admin/admin.js', array('jquery'), CP_VERSION, true );
        wp_localize_script( 'cp-admin', 'cpAdmin', array(
            'nonce'   => wp_create_nonce('cp_admin_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ) );
    }

    public function render_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'career-portal' ) );
        }

        $action = $_GET['action'] ?? 'list';

        if ( $action === 'view' && ! empty($_GET['id']) ) {
            $this->render_single_application( absint($_GET['id']) );
            return;
        }

        // Filters
        $job_filter    = absint( $_GET['job_id'] ?? 0 );
        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $paged         = max(1, absint( $_GET['paged'] ?? 1 ) );
        $per_page      = 20;

        $args = array(
            'job_id'   => $job_filter,
            'status'   => $status_filter,
            'per_page' => $per_page,
            'paged'    => $paged,
        );

        $applications = CP_Database::get_applications( $args );
        $total        = CP_Database::count_applications( $args );
        $total_pages  = ceil( $total / $per_page );

        // Stats
        $stats = array(
            'total'     => CP_Database::count_applications(),
            'pending'   => CP_Database::count_applications(array('status'=>'pending')),
            'interview' => CP_Database::count_applications(array('status'=>'interview')),
            'hired'     => CP_Database::count_applications(array('status'=>'hired')),
        );

        // Jobs for filter dropdown
        $jobs = get_posts(array('post_type'=>'cp_job','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC'));

        $statuses = array(
            'pending'   => array('label'=>'Pending',   'color'=>'#f0ad4e'),
            'reviewing' => array('label'=>'Reviewing', 'color'=>'#5bc0de'),
            'interview' => array('label'=>'Interview', 'color'=>'#9b59b6'),
            'hired'     => array('label'=>'Hired',     'color'=>'#5cb85c'),
            'rejected'  => array('label'=>'Rejected',  'color'=>'#d9534f'),
        );
        ?>
        <div class="wrap cp-admin-wrap">
            <h1 class="cp-admin-title">Career Portal <span class="cp-version">v<?php echo CP_VERSION; ?></span></h1>

            <!-- Stats -->
            <div class="cp-stats-row">
                <div class="cp-stat-card"><span class="cp-stat-number"><?php echo $stats['total']; ?></span><span class="cp-stat-label">Total Applications</span></div>
                <div class="cp-stat-card cp-stat-pending"><span class="cp-stat-number"><?php echo $stats['pending']; ?></span><span class="cp-stat-label">Pending Review</span></div>
                <div class="cp-stat-card cp-stat-interview"><span class="cp-stat-number"><?php echo $stats['interview']; ?></span><span class="cp-stat-label">In Interview</span></div>
                <div class="cp-stat-card cp-stat-hired"><span class="cp-stat-number"><?php echo $stats['hired']; ?></span><span class="cp-stat-label">Hired</span></div>
            </div>

            <!-- Filters -->
            <div class="cp-filters-bar">
                <form method="get">
                    <input type="hidden" name="page" value="career-portal">
                    <select name="job_id" onchange="this.form.submit()">
                        <option value="">All Positions</option>
                        <?php foreach ($jobs as $j) : ?>
                        <option value="<?php echo $j->ID; ?>" <?php selected($job_filter, $j->ID); ?>><?php echo esc_html($j->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $key => $s) : ?>
                        <option value="<?php echo $key; ?>" <?php selected($status_filter, $key); ?>><?php echo $s['label']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <span class="cp-result-count"><?php echo $total; ?> application<?php echo $total !== 1 ? 's' : ''; ?></span>
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
                        <td>#<?php echo $app->id; ?></td>
                        <td>
                            <strong><?php echo esc_html($app->full_name); ?></strong><br>
                            <a href="mailto:<?php echo esc_attr($app->email); ?>" class="cp-email-link"><?php echo esc_html($app->email); ?></a>
                        </td>
                        <td><?php echo $job ? esc_html($job->post_title) : '<em>Deleted</em>'; ?></td>
                        <td><?php echo esc_html( date_i18n('M j, Y', strtotime($app->submitted_at)) ); ?></td>
                        <td>
                            <span class="cp-status-badge" style="background:<?php echo esc_attr($s['color']); ?>20;color:<?php echo esc_attr($s['color']); ?>;border:1px solid <?php echo esc_attr($s['color']); ?>40;">
                                <?php echo $s['label']; ?>
                            </span>
                        </td>
                        <td class="cp-actions">
                            <a href="<?php echo esc_url( admin_url('admin.php?page=career-portal&action=view&id='.$app->id) ); ?>" class="button button-small">View</a>
                            <?php if ($app->cv_file) : ?>
                            <a href="<?php echo esc_url( admin_url('admin-post.php?action=cp_download_cv&id='.$app->id.'&_wpnonce='.wp_create_nonce('cp_download_cv')) ); ?>" class="button button-small">⬇ CV</a>
                            <?php endif; ?>
                            <select class="cp-status-select" data-id="<?php echo $app->id; ?>">
                                <?php foreach ($statuses as $key => $s2) : ?>
                                <option value="<?php echo $key; ?>" <?php selected($app->status, $key); ?>><?php echo $s2['label']; ?></option>
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
                $base = add_query_arg(array('page'=>'career-portal','job_id'=>$job_filter,'status'=>$status_filter,'paged'=>'%#%'), admin_url('admin.php'));
                echo paginate_links(array('base'=>$base,'format'=>'','total'=>$total_pages,'current'=>$paged,'prev_text'=>'&laquo; Prev','next_text'=>'Next &raquo;'));
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_single_application( $id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'career-portal' ) );
        }

        $app  = CP_Database::get_application( $id );
        if ( ! $app ) { echo '<div class="wrap"><p>Application not found.</p></div>'; return; }

        $job     = get_post( $app->job_id );
        $answers = CP_Database::get_screening_answers( $id );
        $statuses = array(
            'pending'=>'Pending','reviewing'=>'Reviewing','interview'=>'Interview','hired'=>'Hired','rejected'=>'Rejected'
        );
        $status_colors = array('pending'=>'#f0ad4e','reviewing'=>'#5bc0de','interview'=>'#9b59b6','hired'=>'#5cb85c','rejected'=>'#d9534f');
        ?>
        <div class="wrap cp-admin-wrap">
            <a href="<?php echo esc_url(admin_url('admin.php?page=career-portal')); ?>" class="cp-back-link">← Back to Applications</a>
            <h1>Application #<?php echo $id; ?> — <?php echo esc_html($app->full_name); ?></h1>

            <div class="cp-detail-grid">
                <div class="cp-detail-main">
                    <div class="cp-detail-card">
                        <h3>Applicant Details</h3>
                        <table class="cp-detail-table">
                            <tr><td>Name</td><td><strong><?php echo esc_html($app->full_name); ?></strong></td></tr>
                            <tr><td>Email</td><td><a href="mailto:<?php echo esc_attr($app->email); ?>"><?php echo esc_html($app->email); ?></a></td></tr>
                            <tr><td>Phone</td><td><?php echo esc_html($app->phone ?: '—'); ?></td></tr>
                            <tr><td>Position</td><td><?php echo $job ? esc_html($job->post_title) : '<em>Deleted</em>'; ?></td></tr>
                            <tr><td>Portfolio</td><td><?php echo $app->portfolio_url ? '<a href="'.esc_url($app->portfolio_url).'" target="_blank">'.esc_html($app->portfolio_url).'</a>' : '—'; ?></td></tr>
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
                        <div class="cp-status-current" style="color:<?php echo $status_colors[$app->status] ?? '#888'; ?>">
                            <?php echo $statuses[$app->status] ?? ucfirst($app->status); ?>
                        </div>
                        <select id="cp-status-select" data-id="<?php echo $id; ?>" class="cp-status-select widefat">
                            <?php foreach ($statuses as $key => $label) : ?>
                            <option value="<?php echo $key; ?>" <?php selected($app->status, $key); ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="cp-status-note">Changing status will email the applicant.</p>
                    </div>

                    <?php if ($app->cv_file) : ?>
                    <div class="cp-detail-card">
                        <h3>CV / Resume</h3>
                        <a href="<?php echo esc_url( admin_url('admin-post.php?action=cp_download_cv&id='.$id.'&_wpnonce='.wp_create_nonce('cp_download_cv')) ); ?>" class="button button-primary" style="width:100%;text-align:center;">⬇ Download CV</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'career-portal' ) );
        }

        if ( isset($_POST['cp_settings_nonce']) && wp_verify_nonce($_POST['cp_settings_nonce'], 'cp_save_settings') ) {
            update_option( 'cp_admin_email', sanitize_email($_POST['cp_admin_email']) );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
        $admin_email     = get_option( 'cp_admin_email', get_option('admin_email') );
        $careers_page_id = (int) get_option( 'cp_careers_page_id', 0 );
        $careers_url     = $careers_page_id ? get_permalink( $careers_page_id ) : '';
        ?>
        <div class="wrap cp-admin-wrap">
            <h1>Career Portal Settings</h1>
            <?php if ( $careers_url ) : ?>
            <p>
                <?php esc_html_e( 'Careers listing page:', 'career-portal' ); ?>
                <a href="<?php echo esc_url( $careers_url ); ?>" target="_blank"><?php echo esc_html( get_the_title( $careers_page_id ) ); ?></a>
                —
                <a href="<?php echo esc_url( get_edit_post_link( $careers_page_id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit page', 'career-portal' ); ?></a>
            </p>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('cp_save_settings','cp_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="cp_admin_email">Notification Email</label></th>
                        <td>
                            <input type="email" id="cp_admin_email" name="cp_admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text">
                            <p class="description">Where to send new application notifications.</p>
                        </td>
                    </tr>
                </table>
                <p><strong>Shortcodes</strong></p>
                <table class="widefat" style="max-width:600px;">
                    <tr><td><code>[career_listings]</code></td><td>Shows all open job listings</td></tr>
                    <tr><td><code>[career_apply]</code></td><td>Shows application form on a single job page</td></tr>
                    <tr><td><code>[career_listings department="design"]</code></td><td>Filter by department slug</td></tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    public function ajax_update_status() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'cp_admin_nonce' ) ) wp_send_json_error('Unauthorized');
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');

        $id     = absint( $_POST['id'] ?? 0 );
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $prev   = CP_Database::get_application($id);

        if ( CP_Database::update_status($id, $status) === false ) {
            wp_send_json_error('Could not update status.');
        }

        // Send status change email if status actually changed
        if ( $prev && $prev->status !== $status ) {
            $notifications = new CP_Email_Notifications();
            $notifications->notify_status_change($id, $status);
        }

        wp_send_json_success( array('message'=>'Status updated.') );
    }

    public function proxy_download() {
        // Delegate to shortcode handler
        (new CP_Shortcodes())->download_cv();
    }
}
