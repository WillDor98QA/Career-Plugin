<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QWJA_Post_Types {

    public function __construct() {
        add_action( 'init', array( __CLASS__, 'register_job_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_department_taxonomy' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_qwja_job', array( $this, 'save_meta' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_job_edit_assets' ) );
    }

    /**
     * Enqueue the screening-questions UI script only on the job editor screens
     * so we don't ship admin JS to every admin page.
     */
    public function enqueue_job_edit_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'qwja_job' ) {
            return;
        }

        wp_enqueue_script(
            'qwja-job-edit',
            QWJA_PLUGIN_URL . 'admin/job-edit.js',
            array(),
            QWJA_VERSION,
            true
        );
    }

    /**
     * Allows the activation hook to register the CPT + taxonomy before flush_rewrite_rules()
     * without instantiating the class (the `init` action hasn't fired yet at activation).
     */
    public static function register_once() {
        self::register_job_post_type();
        self::register_department_taxonomy();
    }

    public static function register_job_post_type() {
        register_post_type( 'qwja_job', array(
            'labels' => array(
                'name'               => 'Job Listings',
                'singular_name'      => 'Job',
                'add_new_item'       => 'Add New Job',
                'edit_item'          => 'Edit Job',
                'new_item'           => 'New Job',
                'view_item'          => 'View Job',
                'search_items'       => 'Search Jobs',
                'not_found'          => 'No jobs found',
                'not_found_in_trash' => 'No jobs found in trash',
            ),
            'public'       => true,
            'show_in_menu' => false,
            'supports'     => array( 'title', 'editor', 'thumbnail' ),
            'rewrite'      => array( 'slug' => 'jobs' ),
            'has_archive'  => false,
        ) );
    }

    public static function register_department_taxonomy() {
        register_taxonomy( 'qwja_department', 'qwja_job', array(
            'labels' => array(
                'name'          => 'Departments',
                'singular_name' => 'Department',
                'add_new_item'  => 'Add New Department',
            ),
            'hierarchical' => true,
            'show_ui'      => true,
            'show_in_menu' => false,
            'rewrite'      => array( 'slug' => 'department' ),
        ) );
    }

    public function add_meta_boxes() {
        add_meta_box( 'qwja_job_details', 'Job Details', array( $this, 'render_details_box' ), 'qwja_job', 'normal', 'high' );
        add_meta_box( 'qwja_screening_questions', 'Screening Questions', array( $this, 'render_screening_box' ), 'qwja_job', 'normal', 'default' );
    }

    public function render_details_box( $post ) {
        wp_nonce_field( 'qwja_save_job_meta', 'qwja_job_nonce' );
        $location      = get_post_meta( $post->ID, '_qwja_location',      true );
        $type          = get_post_meta( $post->ID, '_qwja_job_type',       true );
        $salary        = get_post_meta( $post->ID, '_qwja_salary',         true );
        $deadline      = get_post_meta( $post->ID, '_qwja_deadline',       true );
        $require_portfolio = get_post_meta( $post->ID, '_qwja_require_portfolio', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="qwja_location">Location</label></th>
                <td><input type="text" id="qwja_location" name="qwja_location" value="<?php echo esc_attr($location); ?>" class="regular-text" placeholder="e.g. Accra, Ghana or Remote"></td>
            </tr>
            <tr>
                <th><label for="qwja_job_type">Job Type</label></th>
                <td>
                    <select id="qwja_job_type" name="qwja_job_type">
                        <?php foreach ( array('Full-time','Part-time','Contract','Internship','Remote') as $t ) : ?>
                            <option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="qwja_salary">Salary / Range</label></th>
                <td><input type="text" id="qwja_salary" name="qwja_salary" value="<?php echo esc_attr($salary); ?>" class="regular-text" placeholder="e.g. GHS 5,000 – 8,000/month"></td>
            </tr>
            <tr>
                <th><label for="qwja_deadline">Application Deadline</label></th>
                <td>
                    <input type="datetime-local" id="qwja_deadline" name="qwja_deadline" value="<?php echo esc_attr( QWJA_Deadline::value_for_input( $deadline ) ); ?>" class="regular-text">
                    <p class="description">Date and time use your site timezone (<?php echo esc_html( wp_timezone_string() ); ?>). Leave blank for no deadline.</p>
                </td>
            </tr>
            <tr>
                <th>Portfolio Required?</th>
                <td><label><input type="checkbox" name="qwja_require_portfolio" value="1" <?php checked($require_portfolio, '1'); ?>> Require portfolio link from applicants</label></td>
            </tr>
        </table>
        <?php
    }

    public function render_screening_box( $post ) {
        $questions = get_post_meta( $post->ID, '_qwja_screening_questions', true );
        if ( ! is_array( $questions ) ) $questions = array( '' );
        ?>
        <p style="color:#666;font-size:13px;">Add screening questions applicants must answer. Leave blank to skip. Duplicate questions will be merged on save.</p>
        <div id="cp-questions-wrap">
            <?php foreach ( $questions as $i => $q ) : ?>
            <div class="cp-question-row" style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" name="qwja_screening_questions[]" value="<?php echo esc_attr($q); ?>" class="regular-text cp-screening-q" placeholder="e.g. Why do you want this role?">
                <button type="button" class="button cp-remove-question">Remove</button>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="cp-dup-warning" style="display:none;color:#b32d2e;font-size:13px;margin-top:6px;">
            Duplicate questions detected. Only one copy will be saved.
        </p>
        <button type="button" class="button" id="cp-add-question">+ Add Question</button>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST['qwja_job_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qwja_job_nonce'] ) ), 'qwja_save_job_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = array( 'qwja_location', 'qwja_job_type', 'qwja_salary' );
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        if ( isset( $_POST['qwja_deadline'] ) ) {
            update_post_meta( $post_id, QWJA_Deadline::META_KEY, QWJA_Deadline::sanitize_input( sanitize_text_field( wp_unslash( $_POST['qwja_deadline'] ) ) ) );
        }

        update_post_meta( $post_id, '_qwja_require_portfolio', isset( $_POST['qwja_require_portfolio'] ) ? '1' : '0' );

        if ( isset( $_POST['qwja_screening_questions'] ) && is_array( $_POST['qwja_screening_questions'] ) ) {
            $questions = array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['qwja_screening_questions'] ) ) );
            // Drop duplicate question labels (case-insensitive, trimmed) so the
            // applicant form doesn't end up with conflicting array keys.
            $seen   = array();
            $unique = array();
            foreach ( $questions as $q ) {
                $key = strtolower( trim( $q ) );
                if ( $key === '' || isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $unique[] = $q;
            }
            update_post_meta( $post_id, '_qwja_screening_questions', array_values( $unique ) );
        }
    }
}
