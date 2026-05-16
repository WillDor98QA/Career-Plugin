<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CP_Post_Types {

    public function __construct() {
        add_action( 'init', array( __CLASS__, 'register_job_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_department_taxonomy' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_cp_job', array( $this, 'save_meta' ) );
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
        register_post_type( 'cp_job', array(
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
        register_taxonomy( 'cp_department', 'cp_job', array(
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
        add_meta_box( 'cp_job_details', 'Job Details', array( $this, 'render_details_box' ), 'cp_job', 'normal', 'high' );
        add_meta_box( 'cp_screening_questions', 'Screening Questions', array( $this, 'render_screening_box' ), 'cp_job', 'normal', 'default' );
    }

    public function render_details_box( $post ) {
        wp_nonce_field( 'cp_save_job_meta', 'cp_job_nonce' );
        $location      = get_post_meta( $post->ID, '_cp_location',      true );
        $type          = get_post_meta( $post->ID, '_cp_job_type',       true );
        $salary        = get_post_meta( $post->ID, '_cp_salary',         true );
        $deadline      = get_post_meta( $post->ID, '_cp_deadline',       true );
        $require_portfolio = get_post_meta( $post->ID, '_cp_require_portfolio', true );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="cp_location">Location</label></th>
                <td><input type="text" id="cp_location" name="cp_location" value="<?php echo esc_attr($location); ?>" class="regular-text" placeholder="e.g. Accra, Ghana or Remote"></td>
            </tr>
            <tr>
                <th><label for="cp_job_type">Job Type</label></th>
                <td>
                    <select id="cp_job_type" name="cp_job_type">
                        <?php foreach ( array('Full-time','Part-time','Contract','Internship','Remote') as $t ) : ?>
                            <option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="cp_salary">Salary / Range</label></th>
                <td><input type="text" id="cp_salary" name="cp_salary" value="<?php echo esc_attr($salary); ?>" class="regular-text" placeholder="e.g. GHS 5,000 – 8,000/month"></td>
            </tr>
            <tr>
                <th><label for="cp_deadline">Application Deadline</label></th>
                <td><input type="date" id="cp_deadline" name="cp_deadline" value="<?php echo esc_attr($deadline); ?>"></td>
            </tr>
            <tr>
                <th>Portfolio Required?</th>
                <td><label><input type="checkbox" name="cp_require_portfolio" value="1" <?php checked($require_portfolio, '1'); ?>> Require portfolio link from applicants</label></td>
            </tr>
        </table>
        <?php
    }

    public function render_screening_box( $post ) {
        $questions = get_post_meta( $post->ID, '_cp_screening_questions', true );
        if ( ! is_array( $questions ) ) $questions = array( '' );
        ?>
        <p style="color:#666;font-size:13px;">Add screening questions applicants must answer. Leave blank to skip. Duplicate questions will be merged on save.</p>
        <div id="cp-questions-wrap">
            <?php foreach ( $questions as $i => $q ) : ?>
            <div class="cp-question-row" style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" name="cp_screening_questions[]" value="<?php echo esc_attr($q); ?>" class="regular-text cp-screening-q" placeholder="e.g. Why do you want this role?">
                <button type="button" class="button cp-remove-question">Remove</button>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="cp-dup-warning" style="display:none;color:#b32d2e;font-size:13px;margin-top:6px;">
            Duplicate questions detected. Only one copy will be saved.
        </p>
        <button type="button" class="button" id="cp-add-question">+ Add Question</button>
        <script>
        (function() {
            function checkDuplicates() {
                var inputs = document.querySelectorAll('.cp-screening-q');
                var seen = {};
                var hasDup = false;
                inputs.forEach(function(el) {
                    var v = (el.value || '').trim().toLowerCase();
                    el.style.borderColor = '';
                    if (!v) return;
                    if (seen[v]) {
                        hasDup = true;
                        el.style.borderColor = '#b32d2e';
                        seen[v].style.borderColor = '#b32d2e';
                    } else {
                        seen[v] = el;
                    }
                });
                var warn = document.querySelector('.cp-dup-warning');
                if (warn) warn.style.display = hasDup ? 'block' : 'none';
            }
            document.getElementById('cp-add-question').addEventListener('click', function() {
                var wrap = document.getElementById('cp-questions-wrap');
                var div = document.createElement('div');
                div.className = 'cp-question-row';
                div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';
                div.innerHTML = '<input type="text" name="cp_screening_questions[]" class="regular-text cp-screening-q" placeholder="e.g. Why do you want this role?"><button type="button" class="button cp-remove-question">Remove</button>';
                wrap.appendChild(div);
            });
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('cp-remove-question')) {
                    e.target.closest('.cp-question-row').remove();
                    checkDuplicates();
                }
            });
            document.addEventListener('input', function(e) {
                if (e.target.classList.contains('cp-screening-q')) checkDuplicates();
            });
        })();
        </script>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST['cp_job_nonce'] ) || ! wp_verify_nonce( $_POST['cp_job_nonce'], 'cp_save_job_meta' ) ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = array( 'cp_location', 'cp_job_type', 'cp_salary', 'cp_deadline' );
        foreach ( $fields as $field ) {
            if ( isset( $_POST[$field] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( $_POST[$field] ) );
            }
        }

        update_post_meta( $post_id, '_cp_require_portfolio', isset( $_POST['cp_require_portfolio'] ) ? '1' : '0' );

        if ( isset( $_POST['cp_screening_questions'] ) && is_array( $_POST['cp_screening_questions'] ) ) {
            $questions = array_filter( array_map( 'sanitize_text_field', $_POST['cp_screening_questions'] ) );
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
            update_post_meta( $post_id, '_cp_screening_questions', array_values( $unique ) );
        }
    }
}
