<?php
/**
 * Plugin Name: FluentForm Frontend Entries
 * Description: Hiển thị entries của FluentForm ra ngoài frontend với AJAX table, bảo mật theo quyền user, xem chi tiết và thông báo realtime.
 * Version: 1.6.0
 * Author: allship dev
 */

if (!defined('ABSPATH')) {
    exit;
}

class FF_Frontend_Entries
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_shortcode('fluentform_frontend_entries', [$this, 'render_shortcode']);
        
        add_action('wp_ajax_ff_frontend_entries', [$this, 'ajax_get_entries']);
        add_action('wp_ajax_nopriv_ff_frontend_entries', [$this, 'ajax_get_entries']);
        
        add_action('wp_ajax_ff_frontend_update_status', [$this, 'ajax_update_status']);
        add_action('wp_ajax_ff_frontend_check_new', [$this, 'ajax_check_new_leads']);

        add_action('template_redirect', [$this, 'force_login_for_shortcode']);
        add_filter('template_include', [$this, 'load_custom_template']);
        add_action('admin_init', [$this, 'block_admin_access']);
        add_filter('login_redirect', [$this, 'custom_login_redirect'], 10, 3);
        add_action('init', [$this, 'ensure_role_created']);
    }

    public function load_custom_template($template)
    {
        global $post;
        if ($post && has_shortcode($post->post_content, 'fluentform_frontend_entries')) {
            $plugin_template = plugin_dir_path(__FILE__) . 'templates/lead-dashboard-template.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }
        return $template;
    }

    public function force_login_for_shortcode()
    {
        if (!is_user_logged_in()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'fluentform_frontend_entries')) {
                auth_redirect();
                exit;
            }
        }
    }

    public function ensure_role_created()
    {
        if (!get_role('ff_viewer')) {
            add_role('ff_viewer', 'FluentForm Viewer', [
                'read' => true,
                'view_fluentform_frontend_entries' => true
            ]);
        }
    }

    public function activate()
    {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_fluentform_frontend_entries')) {
            $role->add_cap('view_fluentform_frontend_entries');
        }

        add_role('ff_viewer', 'FluentForm Viewer', [
            'read' => true,
            'view_fluentform_frontend_entries' => true
        ]);
    }

    public function block_admin_access()
    {
        if (wp_doing_ajax()) {
            return;
        }

        $user = wp_get_current_user();
        if (in_array('ff_viewer', (array) $user->roles)) {
            wp_redirect(home_url('/lead-data/'));
            exit;
        }
    }

    public function custom_login_redirect($redirect_to, $request, $user)
    {
        if (isset($user->roles) && is_array($user->roles)) {
            if (in_array('ff_viewer', $user->roles)) {
                global $wpdb;
                $page = $wpdb->get_row("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[fluentform_frontend_entries]%' LIMIT 1");
                if ($page) {
                    return get_permalink($page->ID);
                }
                return home_url('/lead-data/');
            }
        }
        return $redirect_to;
    }

    private function extract_options_from_fields($fields, &$map)
    {
        if (!is_array($fields)) {
            return;
        }
        foreach ($fields as $field) {
            $name = isset($field['attributes']['name']) ? $field['attributes']['name'] : '';
            if ($name && isset($field['settings']['advanced_options']) && is_array($field['settings']['advanced_options'])) {
                foreach ($field['settings']['advanced_options'] as $opt) {
                    if (isset($opt['value']) && isset($opt['label'])) {
                        $map[$name][$opt['value']] = $opt['label'];
                    }
                }
            }
            if (isset($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $col) {
                    if (isset($col['fields']) && is_array($col['fields'])) {
                        $this->extract_options_from_fields($col['fields'], $map);
                    }
                }
            }
            if (isset($field['fields']) && is_array($field['fields'])) {
                $this->extract_options_from_fields($field['fields'], $map);
            }
        }
    }

    public function render_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return '<p>Vui lòng <a href="' . esc_url(wp_login_url(home_url($_SERVER['REQUEST_URI']))) . '">Đăng nhập</a> để tiếp tục.</p>';
        }

        if (!current_user_can('administrator') && !current_user_can('view_fluentform_frontend_entries')) {
            return '<p>Bạn không có quyền xem dữ liệu này.</p>';
        }

        $atts = shortcode_atts(['form_id' => 0], $atts);
        $default_form_id = intval($atts['form_id']);

        wp_enqueue_style('dashicons');
        wp_enqueue_style('ff-frontend-entries-css', plugin_dir_url(__FILE__) . 'assets/css/frontend-entries.css', ['dashicons'], '1.6.0');
        wp_enqueue_script('ff-frontend-entries-js', plugin_dir_url(__FILE__) . 'assets/js/frontend-entries.js', ['jquery'], '1.6.0', true);

        $forms = [];
        if (function_exists('wpFluent')) {
            $forms = wpFluent()->table('fluentform_forms')
                ->select(['id', 'title'])
                ->where('status', 'published')
                ->orderBy('id', 'DESC')
                ->get();
        }

        wp_localize_script('ff-frontend-entries-js', 'ffFrontendEntries', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ff_frontend_entries_nonce'),
            'form_id'  => $default_form_id
        ]);

        $form_options = '<option value="0">Tất cả các Form</option>';
        foreach ($forms as $form) {
            $selected = ($form->id == $default_form_id) ? 'selected' : '';
            $form_options .= '<option value="' . esc_attr($form->id) . '" ' . $selected . '>' . esc_html($form->title) . ' (#' . $form->id . ')</option>';
        }

        return '
        <div class="ff-lead-dashboard" data-form-id="' . esc_attr($default_form_id) . '">
            <!-- Toast notification for new leads -->
            <div class="ff-new-lead-alert" style="display:none;">
                <div class="ff-alert-content">
                    <span class="dashicons dashicons-bell ff-alert-icon"></span>
                    <span class="ff-alert-text">Có lead mới chưa đọc</span>
                    <button type="button" class="ff-alert-btn-refresh">Tải lại danh sách</button>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="ff-stats-grid">
                <div class="ff-stat-card ff-stat-total" data-status="" title="Xem tất cả lead">
                    <div class="ff-stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
                    <div class="ff-stat-info">
                        <div class="ff-stat-label">Tổng số Lead</div>
                        <div class="ff-stat-value" id="ff-stat-total-val">0</div>
                    </div>
                </div>
                <div class="ff-stat-card ff-stat-unread is-active" id="ff-card-unread" data-status="unread" title="Lọc lead chưa đọc">
                    <div class="ff-stat-icon"><span class="dashicons dashicons-email-alt"></span></div>
                    <div class="ff-stat-info">
                        <div class="ff-stat-label">Chưa đọc</div>
                        <div class="ff-stat-value" id="ff-stat-unread-val">0</div>
                    </div>
                    <span class="ff-unread-badge" style="display:none;">MỚI</span>
                </div>
                <div class="ff-stat-card ff-stat-read" data-status="read" title="Lọc lead đã xử lý">
                    <div class="ff-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="ff-stat-info">
                        <div class="ff-stat-label">Đã xử lý</div>
                        <div class="ff-stat-value" id="ff-stat-read-val">0</div>
                    </div>
                </div>
            </div>

            <!-- Control Header -->
            <div class="ff-control-bar">
                <div class="ff-filter-left">
                    <select class="ff-form-filter">
                        ' . $form_options . '
                    </select>
                    <select class="ff-status-filter">
                        <option value="">Tất cả trạng thái</option>
                        <option value="unread" selected>Chưa đọc</option>
                        <option value="read">Đã đọc</option>
                        <option value="trashed">Thùng rác</option>
                    </select>
                    <div class="ff-search-box">
                        <span class="dashicons dashicons-search ff-search-icon"></span>
                        <span class="dashicons dashicons-update rotating ff-search-spinner" style="display:none;"></span>
                        <input type="text" class="ff-search-input" placeholder="SĐT, email, tên..." autocomplete="off">
                        <button type="button" class="ff-search-clear" style="display:none;" title="Xóa tìm kiếm">&times;</button>
                    </div>
                    <button type="button" class="ff-btn-action ff-btn-search" title="Tìm kiếm">
                        Tìm
                    </button>
                </div>
                <div class="ff-action-right">
                    <button type="button" class="ff-btn-action ff-btn-refresh" title="Làm mới dữ liệu">
                        <span class="dashicons dashicons-update"></span> Làm mới
                    </button>
                    <button type="button" class="ff-btn-action ff-btn-export" title="Xuất file CSV">
                        <span class="dashicons dashicons-download"></span> Xuất CSV
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="ff-table-wrapper">
                <table class="ff-frontend-table">
                    <thead>
                        <tr>
                            <th style="width: 65px;">ID</th>
                            <th style="width: 170px;">Nguồn Form</th>
                            <th style="width: 220px;">Khách hàng</th>
                            <th style="width: 200px;">Liên hệ</th>
                            <th>Nội dung yêu cầu</th>
                            <th style="width: 110px; text-align:center;">Trạng thái</th>
                            <th style="width: 140px;">Thời gian</th>
                            <th style="width: 120px; text-align:center;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="8" style="text-align:center; padding: 40px;"><span class="dashicons dashicons-update rotating"></span> Đang tải dữ liệu...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="ff-pagination-wrapper">
                <div class="ff-pagination-info"></div>
                <div class="ff-pagination"></div>
            </div>

            <!-- Detail Modal -->
            <div class="ff-modal" id="ff-detail-modal" style="display:none;">
                <div class="ff-modal-overlay"></div>
                <div class="ff-modal-container">
                    <div class="ff-modal-header">
                        <div class="ff-modal-title">
                            <h3>Chi tiết Lead <span id="ff-modal-lead-id"></span></h3>
                            <span id="ff-modal-form-badge" class="ff-form-badge"></span>
                        </div>
                        <button type="button" class="ff-modal-close" title="Đóng"><span class="dashicons dashicons-no-alt"></span></button>
                    </div>
                    <div class="ff-modal-body">
                        <div class="ff-detail-grid">
                            <div class="ff-detail-section">
                                <h4>Thông tin khách hàng & liên hệ</h4>
                                <div class="ff-detail-group" id="ff-modal-contact-info"></div>
                            </div>
                            <div class="ff-detail-section">
                                <h4>Nội dung yêu cầu</h4>
                                <div class="ff-detail-group" id="ff-modal-request-info"></div>
                            </div>
                        </div>
                        <div class="ff-detail-meta" id="ff-modal-meta-info"></div>
                    </div>
                    <div class="ff-modal-footer">
                        <button type="button" class="ff-btn-modal-status" id="ff-btn-toggle-status">Đổi trạng thái</button>
                        <button type="button" class="ff-btn-modal-copy" id="ff-btn-copy-info"><span class="dashicons dashicons-clipboard"></span> Copy thông tin</button>
                        <button type="button" class="ff-btn-modal-close">Đóng</button>
                    </div>
                </div>
            </div>
        </div>';
    }

    public function ajax_get_entries()
    {
        check_ajax_referer('ff_frontend_entries_nonce', 'nonce');

        if (!current_user_can('administrator') && !current_user_can('view_fluentform_frontend_entries')) {
            wp_send_json_error(['message' => 'Bạn không có quyền xem dữ liệu này.']);
        }

        if (!function_exists('wpFluent')) {
            wp_send_json_error(['message' => 'FluentForm chưa được kích hoạt.']);
        }

        $form_id  = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $page     = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $status   = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $search   = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $per_page = 15;
        $offset   = ($page - 1) * $per_page;

        // Base Query for Stats
        $stats_query = wpFluent()->table('fluentform_submissions');
        if ($form_id > 0) {
            $stats_query->where('form_id', $form_id);
        }
        $stats_query->where('status', '!=', 'trashed');
        $total_active = $stats_query->count();

        $unread_query = wpFluent()->table('fluentform_submissions')->where('status', 'unread');
        if ($form_id > 0) {
            $unread_query->where('form_id', $form_id);
        }
        $total_unread = $unread_query->count();

        $read_query = wpFluent()->table('fluentform_submissions')->where('status', 'read');
        if ($form_id > 0) {
            $read_query->where('form_id', $form_id);
        }
        $total_read = $read_query->count();

        // Main Query
        $query = wpFluent()->table('fluentform_submissions')
            ->select([
                'fluentform_submissions.*',
                'fluentform_forms.title as form_title',
                'fluentform_forms.form_fields as form_fields_json'
            ])
            ->leftJoin('fluentform_forms', 'fluentform_forms.id', '=', 'fluentform_submissions.form_id')
            ->orderBy('fluentform_submissions.id', 'DESC');

        if ($form_id > 0) {
            $query->where('fluentform_submissions.form_id', $form_id);
        }

        if ($status && in_array($status, ['read', 'unread', 'trashed'])) {
            $query->where('fluentform_submissions.status', $status);
        } else {
            $query->where('fluentform_submissions.status', '!=', 'trashed');
        }

        if ($search) {
            $digits = preg_replace('/[^0-9]/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('fluentform_submissions.id', 'LIKE', "%{$search}%")
                  ->orWhere('fluentform_submissions.response', 'LIKE', "%{$search}%")
                  ->orWhere('fluentform_submissions.created_at', 'LIKE', "%{$search}%")
                  ->orWhere('fluentform_forms.title', 'LIKE', "%{$search}%");

                if (!empty($digits) && strlen($digits) >= 3) {
                    $q->orWhere('fluentform_submissions.response', 'LIKE', "%{$digits}%");
                    if (strpos($digits, '0') === 0) {
                        $intl = '84' . substr($digits, 1);
                        $q->orWhere('fluentform_submissions.response', 'LIKE', "%{$intl}%");
                    } elseif (strpos($digits, '84') === 0) {
                        $local = '0' . substr($digits, 2);
                        $q->orWhere('fluentform_submissions.response', 'LIKE', "%{$local}%");
                    }
                }
            });
        }

        $total     = $query->count();
        $entries   = $query->offset($offset)->limit($per_page)->get();
        $last_page = (int) ceil($total / $per_page);

        $forms_options_map = [];
        $clean_entries = [];

        foreach ($entries as $entry) {
            // Build options map for this form if not already built
            if (!isset($forms_options_map[$entry->form_id])) {
                $map = [];
                if (!empty($entry->form_fields_json)) {
                    $decoded_fields = json_decode($entry->form_fields_json, true);
                    if (isset($decoded_fields['fields'])) {
                        $this->extract_options_from_fields($decoded_fields['fields'], $map);
                    }
                }
                $forms_options_map[$entry->form_id] = $map;
            }
            $opt_map = $forms_options_map[$entry->form_id];

            $resp = json_decode($entry->response, true);
            $clean_resp = [];
            if (is_array($resp)) {
                foreach ($resp as $k => $v) {
                    // Filter nonces and internal fields
                    if (strpos($k, 'nonce') !== false || strpos($k, '_fluentform') !== false || strpos($k, '_wp') !== false || strpos($k, '__') === 0) {
                        continue;
                    }
                    if ($v !== null && $v !== '') {
                        // Map option value to display label if defined in form settings
                        if (is_string($v) && isset($opt_map[$k][$v])) {
                            $clean_resp[$k] = $opt_map[$k][$v];
                        } elseif (is_array($v)) {
                            $mapped_arr = [];
                            foreach ($v as $sub_v) {
                                $mapped_arr[] = (is_string($sub_v) && isset($opt_map[$k][$sub_v])) ? $opt_map[$k][$sub_v] : $sub_v;
                            }
                            $clean_resp[$k] = $mapped_arr;
                        } else {
                            $clean_resp[$k] = $v;
                        }
                    }
                }
            }
            unset($entry->form_fields_json);
            $entry->response = $clean_resp;
            $clean_entries[] = $entry;
        }

        wp_send_json_success([
            'data'         => $clean_entries,
            'total'        => $total,
            'per_page'     => $per_page,
            'current_page' => $page,
            'last_page'    => $last_page,
            'stats'        => [
                'total'  => $total_active,
                'unread' => $total_unread,
                'read'   => $total_read
            ]
        ]);
    }

    public function ajax_update_status()
    {
        check_ajax_referer('ff_frontend_entries_nonce', 'nonce');

        if (!current_user_can('administrator') && !current_user_can('view_fluentform_frontend_entries')) {
            wp_send_json_error(['message' => 'Không có quyền.']);
        }

        $entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
        $status   = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$entry_id || !in_array($status, ['read', 'unread', 'trashed'])) {
            wp_send_json_error(['message' => 'Dữ liệu không hợp lệ.']);
        }

        if (function_exists('wpFluent')) {
            wpFluent()->table('fluentform_submissions')
                ->where('id', $entry_id)
                ->update([
                    'status'     => $status,
                    'updated_at' => current_time('mysql')
                ]);

            do_action('fluentform/after_submission_status_update', $entry_id, $status);

            $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
            $unread_query = wpFluent()->table('fluentform_submissions')->where('status', 'unread');
            $read_query = wpFluent()->table('fluentform_submissions')->where('status', 'read');
            $total_query = wpFluent()->table('fluentform_submissions')->where('status', '!=', 'trashed');

            if ($form_id > 0) {
                $unread_query->where('form_id', $form_id);
                $read_query->where('form_id', $form_id);
                $total_query->where('form_id', $form_id);
            }

            wp_send_json_success([
                'message' => 'Cập nhật trạng thái thành công.',
                'status'  => $status,
                'stats'   => [
                    'total'  => $total_query->count(),
                    'unread' => $unread_query->count(),
                    'read'   => $read_query->count()
                ]
            ]);
        }

        wp_send_json_error(['message' => 'Lỗi cập nhật.']);
    }

    public function ajax_check_new_leads()
    {
        check_ajax_referer('ff_frontend_entries_nonce', 'nonce');

        if (!current_user_can('administrator') && !current_user_can('view_fluentform_frontend_entries')) {
            wp_send_json_error(['message' => 'Không có quyền.']);
        }

        if (function_exists('wpFluent')) {
            $latest_id = isset($_POST['latest_id']) ? intval($_POST['latest_id']) : 0;
            $count = wpFluent()->table('fluentform_submissions')
                ->where('id', '>', $latest_id)
                ->where('status', 'unread')
                ->count();

            $unread_total = wpFluent()->table('fluentform_submissions')
                ->where('status', 'unread')
                ->count();

            wp_send_json_success([
                'has_new'      => ($count > 0),
                'new_count'    => $count,
                'unread_total' => $unread_total
            ]);
        }

        wp_send_json_error();
    }
}

FF_Frontend_Entries::get_instance();
