<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
add_action( 'admin_menu', 'kssl_admin_menu_func_hook' );
add_action( 'admin_enqueue_scripts', 'kssl_admin_scripts_func_hook' );
add_action( 'admin_post_kssl_toggle_visitor_block', 'kssl_handle_toggle_visitor_block_action' );
add_action( 'admin_post_kssl_export_logs', 'kssl_handle_export_logs_action' );
add_action( 'wp_ajax_kssl_test_log_recording', 'kssl_handle_test_log_recording_ajax' );

// CSV Import/Export AJAX handlers
add_action( 'wp_ajax_kssl_csv_export', 'kssl_handle_csv_export_ajax' );
add_action( 'wp_ajax_kssl_csv_import', 'kssl_handle_csv_import_ajax' );
add_action( 'wp_ajax_kssl_csv_import_existing', 'kssl_handle_csv_import_existing_ajax' );
add_action( 'wp_ajax_kssl_check_import_status', 'kssl_check_import_status_ajax' );
add_action( 'wp_ajax_kssl_csv_sample', 'kssl_handle_csv_sample_ajax' );
add_action( 'wp_ajax_kssl_check_export_status', 'kssl_check_export_status_ajax' );
add_action( 'wp_ajax_kssl_get_export_files', 'kssl_get_export_files_ajax' );
add_action( 'wp_ajax_nopriv_kssl_download_export', 'kssl_download_export_ajax' );
add_action( 'wp_ajax_kssl_download_export', 'kssl_download_export_ajax' );
add_action( 'wp_ajax_kssl_delete_export', 'kssl_delete_export_ajax' );

// Background export WP-Cron handler
add_action( 'kssl_process_export_job', 'kssl_process_export_job_cron', 10, 2 );

// Log deletion AJAX handlers
add_action( 'wp_ajax_kssl_get_log_count', 'kssl_handle_get_log_count_ajax' );
add_action( 'wp_ajax_kssl_count_delete_logs', 'kssl_handle_count_delete_logs_ajax' );
add_action( 'wp_ajax_kssl_delete_logs', 'kssl_handle_delete_logs_ajax' );
add_action( 'wp_ajax_kssl_delete_all_logs', 'kssl_handle_delete_all_logs_ajax' );
add_action( 'wp_ajax_kssl_optimize_database', 'kssl_handle_optimize_database_ajax' );
add_action( 'wp_ajax_kssl_optimize_status', 'kssl_handle_optimize_status_ajax' );

// Background optimize WP-Cron handler
add_action( 'kssl_process_optimize_background', 'kssl_process_optimize_background' );

// Cache management AJAX handlers
add_action( 'wp_ajax_kssl_clear_cache', 'kssl_handle_clear_cache_ajax' );

// Settings AJAX handlers
add_action( 'wp_ajax_kssl_toggle_auto_optimization', 'kssl_handle_toggle_auto_optimization_ajax' );
add_action( 'wp_ajax_kssl_toggle_auto_clear_cache', 'kssl_handle_toggle_auto_clear_cache_ajax' );

function kssl_admin_menu_func_hook() {
    add_menu_page(
        __( 'Kashiwazaki SEO Super Access Log', 'kashiwazaki-seo-super-access-log' ),
        __( 'Kashiwazaki SEO Access Log', 'kashiwazaki-seo-super-access-log' ),
        'manage_options',
        'kssl_access_log_page',
        'kssl_display_log_page_wrapper_func',
        'dashicons-visibility',
        80
    );
}
function kssl_admin_scripts_func_hook($hook_suffix) {
    if ( strpos($hook_suffix, 'kssl_access_log_page') === false ) {
        return;
    }
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);

    // パフォーマンス最適化版のJavaScriptを使用
    $js_file = file_exists(plugin_dir_path( KSSL_PLUGIN_FILE_PATH ) . 'assets/kssl-admin-optimized.js')
        ? 'assets/kssl-admin-optimized.js'
        : 'assets/kssl-admin.js';

    wp_enqueue_script('kssl-admin-js', plugin_dir_url( KSSL_PLUGIN_FILE_PATH ) . $js_file, ['jquery', 'jquery-ui-datepicker', 'chart-js'], '2.0.' . time(), true);
    
    // アップロード制限を取得
    $upload_max_filesize = ini_get('upload_max_filesize');
    $upload_max_bytes = wp_convert_hr_to_bytes($upload_max_filesize);

    wp_localize_script('kssl-admin-js', 'kssl_ajax', [
        'nonce' => wp_create_nonce('kssl_admin_nonce'),
        'csv_nonce' => wp_create_nonce('kssl_csv_nonce'),
        'cache_nonce' => wp_create_nonce('kssl_cache_nonce'),
        'toggle_nonce' => wp_create_nonce('kssl_toggle_nonce'),
        'upload_max_bytes' => $upload_max_bytes,
        'upload_max_filesize' => $upload_max_filesize,
        'confirm_clear' => __('すべてのログを削除してもよろしいですか？この操作は元に戻せません。', 'kashiwazaki-seo-super-access-log'),
        'confirm_clear_filtered' => __('フィルターされたログを削除してもよろしいですか？この操作は元に戻せません。', 'kashiwazaki-seo-super-access-log'),
        'confirm_block_visitor' => __('この訪問者IDをブロックしてもよろしいですか？ブロックされたユーザーはサイトにアクセスできなくなります。', 'kashiwazaki-seo-super-access-log'),
        'confirm_unblock_visitor' => __('この訪問者IDのブロックを解除してもよろしいですか？', 'kashiwazaki-seo-super-access-log'),
        'no_ua_data' => __('No data available for current filters or Chart.js not loaded.', 'kashiwazaki-seo-super-access-log'),
        'no_trend_data' => __('No trend data available for the selected period.', 'kashiwazaki-seo-super-access-log'),
    ]);
    
    // ajaxurlを追加
    wp_add_inline_script('kssl-admin-js', 'var ajaxurl = "' . admin_url('admin-ajax.php') . '";', 'before');
    
    wp_enqueue_style('jquery-ui-style', admin_url('/css/jquery-ui-dialog.css'));

    $custom_css = "
.kssl-ellipsis-cell { display: block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wp-list-table .column-kssl_request_uri .kssl-ellipsis-cell { max-width: 300px; }
.wp-list-table .column-kssl_referer_url .kssl-ellipsis-cell { max-width: 200px; }
.wp-list-table .column-kssl_user_agent .kssl-ellipsis-cell { max-width: 250px; }
.wp-list-table .column-kssl_visitor_id_cookie .kssl-visitor-actions { font-size: 0.9em; display: block; margin-top: 3px; }
.wp-list-table .column-kssl_visitor_id_cookie .kssl-visitor-actions a { text-decoration: none; }
.wp-list-table .column-kssl_visitor_id_cookie .kssl-visitor-actions .dashicons { font-size: 1.2em; vertical-align: middle; }
.wp-list-table th.sortable a span, .wp-list-table th.sorted a span { display: block; }
.wp-list-table th.sortable .sorting-indicator { float: right; }
.kssl-suspicious-row td { border-left: 3px solid #dc3232 !important; }
.kssl-suspicious-row td:first-child { padding-left: 8px; }
.kssl-filters input[type=\"text\"], .kssl-filters input[type=\"number\"], .kssl-filters select { margin-right: 5px; margin-bottom: 5px; vertical-align: middle; }
.nav-tab-wrapper { margin-bottom: 15px; }
.kssl-tab-content { margin-top: 15px; background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-radius: 5px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
.kssl-tab-content h3 { margin-top:0; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
.kssl-columns-selection label { margin-bottom: 5px; }
.wp-list-table.kssl-log-table { table-layout: fixed; width: 100%; margin-top: 15px; border-radius: 5px; overflow: hidden; }
.wp-list-table.kssl-log-table td, .wp-list-table.kssl-log-table th { word-wrap: break-word; overflow-wrap: break-word; }
.wp-list-table.kssl-log-table .column-kssl_id { width: 5%; }
.wp-list-table.kssl-log-table .column-kssl_access_time { width: 12%; }
.wp-list-table.kssl-log-table .column-kssl_ip_address { width: 10%; }
.wp-list-table.kssl-log-table .column-kssl_country_code { width: 5%; }
.tablenav-pages { 
    float: right; 
    margin: 10px 0; 
    font-size: 14px;
}
.tablenav-pages .page-numbers { 
    display: inline-block; 
    padding: 4px 8px; 
    margin: 0 2px; 
    line-height: 1.4; 
    min-width: 28px; 
    text-align: center; 
    text-decoration: none; 
    border: 1px solid #ddd; 
    border-radius: 4px; 
    background: #f7f7f7; 
    color: #0073aa;
    font-weight: normal;
    transition: all 0.2s ease;
}
.tablenav-pages .page-numbers.current { 
    background: #0073aa; 
    border-color: #0073aa; 
    color: #fff; 
    font-weight: bold;
    cursor: default;
}
.tablenav-pages a.page-numbers:hover { 
    background: #0073aa; 
    border-color: #0073aa; 
    color: #fff;
}
.tablenav-pages .page-numbers.dots {
    border: none;
    background: transparent;
    color: #666;
    cursor: default;
}
.tablenav-pages .page-numbers.dots:hover {
    background: transparent;
    color: #666;
}
.tablenav-pages .displaying-num {
    color: #666;
    font-size: 13px;
    font-style: italic;
    margin-right: 15px;
    vertical-align: middle;
}
#ui-datepicker-div { z-index: 100000 !important; }

/* Date picker calendar styling */
.ui-datepicker {
    background-color: #ffffff !important;
    border: 1px solid #cccccc !important;
    border-radius: 4px !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2) !important;
    padding: 0 !important;
}
.ui-datepicker .ui-datepicker-header {
    background-color: #f7f7f7 !important;
    border-bottom: 1px solid #dddddd !important;
    padding: 10px !important;
}
.ui-datepicker .ui-datepicker-title {
    color: #333333 !important;
    font-weight: bold !important;
}
.ui-datepicker table {
    background-color: #ffffff !important;
    margin: 0 !important;
    width: 100% !important;
}
.ui-datepicker td, .ui-datepicker th {
    background-color: #ffffff !important;
    border: none !important;
    padding: 5px !important;
    text-align: center !important;
}
.ui-datepicker td a {
    background-color: #ffffff !important;
    color: #333333 !important;
    text-decoration: none !important;
    display: block !important;
    padding: 5px !important;
    border-radius: 3px !important;
}
.ui-datepicker td a:hover {
    background-color: #e6f3ff !important;
    color: #0073aa !important;
}
.ui-datepicker .ui-state-active,
.ui-datepicker .ui-state-active a {
    background-color: #0073aa !important;
    color: #ffffff !important;
}
.ui-datepicker .ui-datepicker-prev, 
.ui-datepicker .ui-datepicker-next {
    background-color: #f7f7f7 !important;
    border: 1px solid #cccccc !important;
    border-radius: 3px !important;
    color: #333333 !important;
    text-decoration: none !important;
    padding: 2px 6px !important;
}
.ui-datepicker .ui-datepicker-prev:hover, 
.ui-datepicker .ui-datepicker-next:hover {
    background-color: #e6f3ff !important;
    color: #0073aa !important;
}

/* Chart & List Styles */
.kssl-chart-switch-buttons {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* Trend Chart Styles */
.kssl-trend-summary {
    font-size: 14px;
}
.kssl-trend-increasing {
    color: #46b450;
    font-weight: bold;
}
.kssl-trend-decreasing {
    color: #dc3232;
    font-weight: bold;
}
.kssl-trend-stable {
    color: #666;
    font-weight: bold;
}
#kssl-trend-chart {
    width: 100% !important;
    height: 300px !important;
}

.kssl-accordion-wrapper {
    margin: 15px 0;
}
.kssl-accordion-trigger {
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 15px;
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    margin: 0 !important;
    font-size: 14px;
    font-weight: 600;
}
.kssl-accordion-trigger:hover {
    background: #f0f0f1;
    border-color: #a7aaad;
}
.kssl-accordion-trigger.kssl-accordion-active {
    background: #e0f2f1;
    border-color: #0073aa;
    color: #0073aa;
}
.kssl-accordion-content {
    display: none;
    padding: 20px;
    border: 1px solid #c3c4c7;
    border-top: none;
    background: #fff;
    border-bottom-left-radius: 3px;
    border-bottom-right-radius: 3px;
}
.kssl-flex-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: flex-start;
    gap: 25px;
}
.kssl-chart-canvas-wrapper {
    position: relative;
    height: 250px;
    width: 350px;
    max-width: 100%;
    min-width: 280px;
    flex-shrink: 0;
}
.kssl-ua-list-style {
    flex-grow: 1;
    max-width: calc(100% - 375px);
    min-width: 300px;
    max-height: 400px;
    overflow-y: auto;
    padding: 15px;
    border: 1px solid #e9e9e9;
    background: #ffffff;
    border-radius: 5px;
    box-shadow: inset 0 1px 3px rgba(0,0,0,.03);
    font-size: 0.9em;
    line-height: 1.4;
}
.kssl-ua-list-style h4 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #444;
    border-bottom: 1px solid #eee;
    padding-bottom: 8px;
}
.kssl-ua-list-ul {
    list-style-type: none;
    margin: 0;
    padding: 0;
}
.kssl-ua-list-ul li {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px dashed #f0f0f0;
    word-break: break-all;
}
.kssl-ua-list-ul li:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.kssl-ua-list-item-stats {
    font-weight: bold;
    color: #0073aa;
    margin-bottom: 3px;
    font-size: 1.1em;
}
.kssl-ua-list-item-ua {
    color: #333;
    background-color: #f8f8f8;
    padding: 5px 8px;
    border-radius: 3px;
    font-family: monospace;
    font-size: 0.9em;
}

/* List controls styling */
.kssl-list-controls {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: wrap;
    gap: 10px;
}
.kssl-list-controls label {
    margin: 0;
    font-size: 13px;
}
.kssl-list-controls select {
    font-size: 12px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

/* Responsive adjustments */
@media screen and (max-width: 1100px) {
    .kssl-filters input, .kssl-filters select { min-width: 150px; }
    .kssl-ua-list-style { max-width: 100%; }
}
@media screen and (max-width: 782px) {
    .kssl-ellipsis-cell { white-space: normal; }
    .wp-list-table th#kssl_id, .wp-list-table td.column-kssl_id,
    .wp-list-table th#kssl_access_time, .wp-list-table td.column-kssl_access_time { display: table-cell !important; width: auto !important; }
    .wp-list-table td.column-kssl_id, .wp-list-table td.column-kssl_access_time { font-size: 12px; }
    .kssl-filters input, .kssl-filters select, .kssl-filters .button { display: block; width: 100%; margin-bottom: 10px; }
    .wp-list-table.kssl-log-table { table-layout: auto; }
    .tablenav-pages { float: none; text-align: center; margin-top: 10px;}
    .tablenav-pages .page-numbers, .tablenav-pages .pagination-links .page-numbers { margin-bottom: 5px; }

    .kssl-flex-container {
        flex-direction: column;
        align-items: center;
    }
    .kssl-chart-canvas-wrapper,
    .kssl-ua-list-style {
        width: 100%;
        max-width: 100%;
        min-width: unset;
    }
    .kssl-ua-list-style {
        max-height: 300px;
    }
}

/* Chart Status Styles */
.kssl-status-enabled {
    color: #46b450;
    font-weight: bold;
}
.kssl-status-disabled {
    color: #dc3232;
    font-weight: bold;
}

/* Chart Switch Buttons */
.kssl-chart-switch-buttons {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* CSV Import/Export Styles */
.kssl-csv-section {
    margin-top: 20px;
}

.kssl-csv-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.kssl-csv-panel {
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
}

.kssl-csv-panel h5 {
    margin-top: 0;
    margin-bottom: 10px;
    color: #333;
    border-bottom: 1px solid #eee;
    padding-bottom: 5px;
}

.kssl-export-filters {
    margin-bottom: 15px;
    padding: 10px;
    background: #f8f8f8;
    border-radius: 3px;
    border: 1px solid #e8e8e8;
}

.kssl-export-filters label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
}

.kssl-export-filters input,
.kssl-export-filters select {
    margin-left: 8px;
    width: calc(100% - 90px);
    max-width: 200px;
}

.kssl-import-progress {
    margin-top: 10px;
}

.kssl-progress-bar-container {
    background: #f1f1f1;
    border-radius: 10px;
    overflow: hidden;
    height: 20px;
    border: 1px solid #ddd;
}

.kssl-progress-bar {
    height: 100%;
    background: linear-gradient(45deg, #0073aa, #005a87);
    width: 0%;
    transition: width 0.3s ease;
    border-radius: 10px;
}

.kssl-import-status {
    margin: 5px 0 0 0;
    font-size: 12px;
    color: #666;
    font-style: italic;
}

/* Responsive Design for CSV Section */
@media screen and (max-width: 782px) {
    .kssl-csv-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .kssl-export-filters input,
    .kssl-export-filters select {
        width: 100%;
        max-width: none;
        margin-left: 0;
        margin-top: 5px;
    }
    
    .kssl-csv-panel {
        padding: 12px;
    }
}

/* ローディングスピナー */
.kssl-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: kssl-spin 0.8s linear infinite;
    margin-right: 8px;
    vertical-align: middle;
}

@keyframes kssl-spin {
    to { transform: rotate(360deg); }
}

/* ドット点滅アニメーション */
.kssl-dots::after {
    content: '';
    animation: kssl-dots 1.5s steps(4, end) infinite;
}

@keyframes kssl-dots {
    0%, 20% { content: ''; }
    40% { content: '.'; }
    60% { content: '..'; }
    80%, 100% { content: '...'; }
}
";
    wp_add_inline_style( 'wp-admin', $custom_css );
}

function kssl_handle_toggle_visitor_block_action() {
    if ( ! isset( $_GET['kssl_nonce'] ) || ! wp_verify_nonce( sanitize_key($_GET['kssl_nonce']), 'kssl_toggle_visitor_block_nonce' ) ) {
        wp_redirect( add_query_arg( 'kssl_message', 'visitor_op_failed', wp_get_referer() ) );
        exit;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_redirect( add_query_arg( 'kssl_message', 'visitor_op_failed', wp_get_referer() ) );
        exit;
    }

    $visitor_id = isset($_GET['visitor_id']) ? sanitize_text_field(wp_unslash($_GET['visitor_id'])) : '';
    $action = isset($_GET['block_action']) ? sanitize_key($_GET['block_action']) : '';

    if (empty($visitor_id) || !in_array($action, ['block', 'unblock'])) {
        wp_redirect( add_query_arg( 'kssl_message', 'visitor_op_failed', wp_get_referer() ) );
        exit;
    }

    $result = false;
    if ($action === 'block') {
        $result = kssl_add_blocked_visitor_id_func($visitor_id);
        $message_key = $result ? 'visitor_blocked' : 'visitor_op_failed';
    } elseif ($action === 'unblock') {
        $result = kssl_remove_blocked_visitor_id_func($visitor_id);
        $message_key = $result ? 'visitor_unblocked' : 'visitor_op_failed';
    } else {
        $message_key = 'visitor_op_failed';
    }

    wp_redirect( add_query_arg( 'kssl_message', $message_key, wp_get_referer() ) );
    exit;
}

function kssl_handle_export_logs_action() {
    if ( ! isset( $_POST['kssl_export_logs_nonce'] ) || ! wp_verify_nonce( sanitize_key($_POST['kssl_export_logs_nonce']), 'kssl_export_logs_action_nonce' ) ) {
        wp_die( 'Nonce verification failed.', 'Error', ['response' => 403] );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to export logs.', 'Error', ['response' => 403] );
    }

    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $filename = 'kssl-logs-backup-' . date('Y-m-d') . '.csv';

    header( 'Content-Type: text/csv' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $all_columns = kssl_get_all_column_definitions();
    $column_keys = array_keys($all_columns);

    $handle = fopen( 'php://output', 'w' );
    fputcsv( $handle, $column_keys );

    set_time_limit(0);

    $limit = 1000;
    $offset = 0;

    while (true) {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if (empty($results)) {
            break;
        }

        foreach ($results as $row) {
            $ordered_row = [];
            foreach ($column_keys as $key) {
                $ordered_row[$key] = $row[$key] ?? '';
            }
            fputcsv($handle, $ordered_row);
        }

        if (count($results) < $limit) {
            break;
        }

        $offset += $limit;
    }

    fclose($handle);
    exit;
}

/**
 * AJAX処理：ログ記録のテスト
 */
function kssl_handle_test_log_recording_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_test_log_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    try {
        $test_log = kssl_test_log_recording();
        
        if ($test_log) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Test log successfully recorded! ID: %d, Time: %s', 'kashiwazaki-seo-super-access-log'),
                    $test_log->id,
                    $test_log->access_time
                )
            ]);
        } else {
            wp_send_json_error(['message' => __('Test log could not be recorded. Please check database permissions.', 'kashiwazaki-seo-super-access-log')]);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => __('Error: ', 'kashiwazaki-seo-super-access-log') . $e->getMessage()]);
    }
}

/**
 * AJAX処理：CSVエクスポート開始（バックグラウンド）
 */
function kssl_handle_csv_export_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kssl_csv_nonce')) {
        wp_send_json_error(['message' => 'Nonce verification failed.']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
        return;
    }

    $filters = [];

    if (isset($_POST['export_type']) && $_POST['export_type'] === 'filtered') {
        if (!empty($_POST['date_from'])) {
            $filters['date_from'] = sanitize_text_field(wp_unslash($_POST['date_from']));
        }
        if (!empty($_POST['date_to'])) {
            $filters['date_to'] = sanitize_text_field(wp_unslash($_POST['date_to']));
        }
        if (!empty($_POST['ip_address'])) {
            $filters['ip_address'] = sanitize_text_field(wp_unslash($_POST['ip_address']));
        }
        if (!empty($_POST['status_code'])) {
            $filters['status_code'] = sanitize_text_field(wp_unslash($_POST['status_code']));
        }
        // 追加のフィルター処理
        if (isset($_POST['is_bot']) && $_POST['is_bot'] !== '') {
            $filters['is_bot'] = sanitize_text_field(wp_unslash($_POST['is_bot']));
        }
        if (!empty($_POST['country_code'])) {
            $filters['country_code'] = sanitize_text_field(wp_unslash($_POST['country_code']));
        }
        if (!empty($_POST['url_pattern'])) {
            $filters['url_pattern'] = sanitize_text_field(wp_unslash($_POST['url_pattern']));
        }
        if (!empty($_POST['ua_pattern'])) {
            $filters['ua_pattern'] = sanitize_text_field(wp_unslash($_POST['ua_pattern']));
        }
        if (!empty($_POST['visit_type'])) {
            $filters['visit_type'] = sanitize_text_field(wp_unslash($_POST['visit_type']));
        }
        if (!empty($_POST['source'])) {
            $filters['source'] = sanitize_text_field(wp_unslash($_POST['source']));
        }
        if (!empty($_POST['referer_pattern'])) {
            $filters['referer_pattern'] = sanitize_text_field(wp_unslash($_POST['referer_pattern']));
        }
        // 疑わしいアクセスのみのフィルター
        if (!empty($_POST['suspicious_only'])) {
            $filters['suspicious_only'] = sanitize_text_field(wp_unslash($_POST['suspicious_only']));
        }
    }

    // Atomic lock using user-specific transient to prevent race conditions
    $user_id = get_current_user_id();
    $lock_key = 'kssl_export_lock_' . $user_id;
    $lock_value = microtime(true);

    // Try to acquire lock - if transient already exists, export is running
    $existing_lock = get_transient($lock_key);
    if ($existing_lock !== false) {
        // Check if lock is stale (older than 5 minutes)
        if ((microtime(true) - $existing_lock) < 300) {
            wp_send_json_error(['message' => 'エクスポート処理がすでに実行中です。しばらくお待ちください。']);
            return;
        }
        // Lock is stale, we can proceed
    }

    // Set the lock with our unique value
    set_transient($lock_key, $lock_value, 600); // 10 minute expiry

    // Double-check: verify we own the lock (prevents race condition)
    usleep(10000); // Wait 10ms
    $check_lock = get_transient($lock_key);
    if ($check_lock !== $lock_value) {
        // Someone else got the lock, abort
        wp_send_json_error(['message' => 'エクスポート処理がすでに実行中です。しばらくお待ちください。']);
        return;
    }

    // Generate job ID
    $job_id = wp_generate_password(16, false);

    // Store initial job status
    set_transient('kssl_export_job_' . $job_id, [
        'status' => 'processing',
        'filters' => $filters,
        'started' => time(),
        'progress' => 0,
        'total' => 0,
        'message' => 'エクスポートを開始しています...'
    ], 3600);

    // Manual JSON response to avoid wp_send_json_success() exit()
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'job_id' => $job_id,
            'message' => 'エクスポート処理を開始しました'
        ]
    ]);

    // Continue after response sent
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Execute export in background
    KSSL_CSV_Handler::process_export_job_background($job_id, $filters, $user_id, $lock_key);

    // Note: Lock will be removed in process_export_job_background after completion
    exit();
}

/**
 * AJAX処理：エクスポート状態確認
 */
function kssl_check_export_status_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kssl_csv_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
    if (empty($job_id)) {
        wp_send_json_error(['message' => 'Job ID required']);
        return;
    }

    $status = KSSL_CSV_Handler::get_export_status($job_id);

    if (!$status) {
        wp_send_json_error(['message' => 'Job not found']);
        return;
    }

    $file_info = null;
    if ($status['status'] === 'completed') {
        $file_info = KSSL_CSV_Handler::get_export_file_info($job_id);
    }

    wp_send_json_success([
        'status' => $status,
        'file_info' => $file_info
    ]);
}

/**
 * AJAX処理：エクスポートファイル一覧取得
 */
function kssl_get_export_files_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kssl_csv_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $files = KSSL_CSV_Handler::get_export_files();

    wp_send_json_success(['files' => $files]);
}

/**
 * AJAX処理：エクスポートファイルダウンロード
 */
function kssl_download_export_ajax() {
    $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
    $nonce = isset($_GET['nonce']) ? sanitize_key($_GET['nonce']) : '';

    if (!wp_verify_nonce($nonce, 'kssl_csv_nonce')) {
        wp_die('Nonce verification failed.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    KSSL_CSV_Handler::download_export_file($file);
}

/**
 * AJAX処理：エクスポートファイル削除
 */
function kssl_delete_export_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kssl_csv_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
    if (empty($filename)) {
        wp_send_json_error(['message' => 'Filename required']);
        return;
    }

    $result = KSSL_CSV_Handler::delete_export_file($filename);

    if ($result) {
        wp_send_json_success(['message' => 'ファイルを削除しました']);
    } else {
        wp_send_json_error(['message' => 'ファイルの削除に失敗しました']);
    }
}

/**
 * WP-Cronコールバック：バックグラウンドエクスポート処理
 */
function kssl_process_export_job_cron($job_id, $filters) {
    KSSL_CSV_Handler::process_export_job_background($job_id, $filters);
}

/**
 * WP-Cronコールバック：バックグラウンドインポート処理
 */
function kssl_process_import_job_cron($job_id, $temp_file, $options) {
    try {
        // 進捗を更新
        set_transient('kssl_import_status_' . $job_id, [
            'status' => 'processing',
            'message' => 'インポート処理を開始しました...',
            'progress' => 0,
            'total_lines' => 0,
            'processed_lines' => 0,
            'imported_count' => 0
        ], 3600);
    // Force flush transient to database
    wp_cache_flush();
    


        // 進捗コールバック関数
        $progress_callback = function($progress_data) use ($job_id) {
            set_transient('kssl_import_status_' . $job_id, [
                'status' => 'processing',
                'message' => sprintf(
                    '%d / %d 行を処理中 (%d件インポート済み)',
                    $progress_data['processed_lines'],
                    $progress_data['total_lines'],
                    $progress_data['imported_count']
                ),
                'progress' => $progress_data['progress'],
                'total_lines' => $progress_data['total_lines'],
                'processed_lines' => $progress_data['processed_lines'],
                'imported_count' => $progress_data['imported_count'],
                'skipped_count' => $progress_data['skipped_count'],
                'error_count' => $progress_data['error_count']
            ], 3600);
        };

        // オプションに進捗コールバックを追加
        $options['progress_callback'] = $progress_callback;

        // インポート実行
        $result = KSSL_CSV_Handler::import_csv($temp_file, $options);

        // 一時ファイルを削除
        @unlink($temp_file);

        // 結果を保存
        if ($result['success']) {
            set_transient('kssl_import_status_' . $job_id, [
                'status' => 'completed',
                'message' => $result['message'],
                'imported_count' => isset($result['imported_count']) ? $result['imported_count'] : 0,
                'skipped_count' => isset($result['skipped_count']) ? $result['skipped_count'] : 0,
                'error_count' => isset($result['error_count']) ? $result['error_count'] : 0,
                'progress' => 100
            ], 3600);
        } else {
            set_transient('kssl_import_status_' . $job_id, [
                'status' => 'error',
                'message' => $result['message'],
                'progress' => 0
            ], 3600);
        }
    } catch (Exception $e) {
        @unlink($temp_file);
        set_transient('kssl_import_status_' . $job_id, [
            'status' => 'error',
            'message' => 'エラー: ' . $e->getMessage(),
            'progress' => 0
        ], 3600);
    }
}
add_action('kssl_process_import_job', 'kssl_process_import_job_cron', 10, 3);

/**
 * AJAX処理：インポート進捗確認
 */
function kssl_check_import_status_ajax() {
    check_ajax_referer('kssl_csv_nonce', 'nonce');

    if (!isset($_POST['job_id'])) {
        wp_send_json_error(['message' => 'ジョブIDが指定されていません']);
        return;
    }

    $job_id = sanitize_text_field($_POST['job_id']);
    $status = get_transient('kssl_import_status_' . $job_id);

    if ($status === false) {
        // Return a waiting status instead of error to keep progress visible
        wp_send_json_success([
            'status' => 'waiting',
            'message' => 'バックグラウンド処理を準備中...',
            'progress' => 0
        ]);
        return;
    }

    wp_send_json_success($status);
}

/**
 * AJAX処理：CSVインポート
 */
function kssl_handle_csv_import_ajax() {
    // 出力バッファリングをクリア（レスポンス送信を確実にする）
    if (ob_get_level()) {
        ob_end_clean();
    }

    // メモリ制限を増やす（大容量ファイル対応）
    @ini_set('memory_limit', '512M');

    if (!wp_verify_nonce($_POST['nonce'], 'kssl_csv_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => __('File upload failed.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    $file = $_FILES['csv_file'];

    // ファイルタイプの検証
    $allowed_types = ['text/csv', 'text/plain', 'application/csv'];
    $file_type = wp_check_filetype($file['name']);

    if (!in_array($file['type'], $allowed_types) && $file_type['ext'] !== 'csv') {
        wp_send_json_error(['message' => __('Invalid file type. Only CSV files are allowed.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    // ファイルサイズの検証 (最大500MB)
    if ($file['size'] > 500 * 1024 * 1024) {
        wp_send_json_error(['message' => __('File too large. Maximum size is 500MB.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    // インポートオプション
    $options = [
        'skip_duplicates' => isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '1',
        'validate_data' => isset($_POST['validate_data']) && $_POST['validate_data'] === '1',
    ];

    // ジョブIDを生成
    $job_id = 'import_' . time() . '_' . wp_generate_password(8, false);

    // ファイルを一時ディレクトリに保存
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/kssl-temp';
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }

    $temp_file = $temp_dir . '/' . $job_id . '.csv';
    if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
        wp_send_json_error(['message' => 'ファイルの保存に失敗しました']);
        return;
    }

    // 初期ステータスを保存
    set_transient('kssl_import_status_' . $job_id, [
        'status' => 'processing',
        'message' => 'インポート処理を開始しました...',
        'progress' => 0
    ], 3600); // 1時間

    // すぐにジョブIDを返す（JSONレスポンスを手動で送信）
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'job_id' => $job_id,
            'message' => 'インポート処理を開始しました'
        ]
    ]);

    // レスポンス送信後、バックグラウンドで処理を継続
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // バックグラウンド処理を実行
    kssl_process_import_job_cron($job_id, $temp_file, $options);
    exit();
}

/**
 * AJAX処理：既存ファイルからCSVインポート
 */
function kssl_handle_csv_import_existing_ajax() {
    // 出力バッファリングをクリア（レスポンス送信を確実にする）
    if (ob_get_level()) {
        ob_end_clean();
    }

    // メモリ制限を増やす（大容量ファイル対応）
    @ini_set('memory_limit', '512M');

    if (!wp_verify_nonce($_POST['nonce'], 'kssl_csv_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    if (!isset($_POST['file_path']) || empty($_POST['file_path'])) {
        wp_send_json_error(['message' => __('No file selected.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    $file_path = sanitize_text_field(wp_unslash($_POST['file_path']));

    // セキュリティチェック: アップロードディレクトリ内のファイルのみ許可
    $upload_dir = wp_upload_dir();
    $export_dir = $upload_dir['basedir'] . '/kssl-exports';
    $real_file_path = realpath($file_path);
    $real_export_dir = realpath($export_dir);

    if (!$real_file_path || strpos($real_file_path, $real_export_dir) !== 0) {
        wp_send_json_error(['message' => __('Invalid file path.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    if (!file_exists($file_path)) {
        wp_send_json_error(['message' => __('File not found.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    // ファイルタイプの検証
    $file_type = wp_check_filetype($file_path);
    if ($file_type['ext'] !== 'csv') {
        wp_send_json_error(['message' => __('Invalid file type. Only CSV files are allowed.', 'kashiwazaki-seo-super-access-log')]);
        return;
    }

    // インポートオプション
    $options = [
        'skip_duplicates' => isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '1',
        'validate_data' => isset($_POST['validate_data']) && $_POST['validate_data'] === '1',
    ];

    // ジョブIDを生成
    $job_id = 'import_' . time() . '_' . wp_generate_password(8, false);

    // 初期ステータスを保存
    set_transient('kssl_import_status_' . $job_id, [
        'status' => 'processing',
        'message' => 'インポート処理を開始しました...',
        'progress' => 0
    ], 3600); // 1時間

    // すぐにジョブIDを返す（JSONレスポンスを手動で送信）
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'job_id' => $job_id,
            'message' => 'インポート処理を開始しました'
        ]
    ]);

    // レスポンス送信後、バックグラウンドで処理を継続
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // バックグラウンド処理を実行
    kssl_process_import_job_cron($job_id, $file_path, $options);
    exit();
}

/**
 * AJAX処理：サンプルCSVダウンロード
 */
function kssl_handle_csv_sample_ajax() {
    // GET/POST両方に対応
    $nonce = isset($_GET['nonce']) ? sanitize_key($_GET['nonce']) : (isset($_POST['nonce']) ? sanitize_key($_POST['nonce']) : '');

    if (empty($nonce) || !wp_verify_nonce($nonce, 'kssl_csv_sample_nonce')) {
        wp_die('Nonce verification failed', 'Error', ['response' => 403]);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions', 'Error', ['response' => 403]);
        return;
    }

    // 直接CSVダウンロードとして出力
    KSSL_CSV_Handler::generate_sample_csv();
}

/**
 * AJAX処理：ログ件数取得
 */
function kssl_handle_get_log_count_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_delete_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    
    wp_send_json_success(['count' => intval($count)]);
}

/**
 * AJAX処理：削除対象ログ件数確認
 */
function kssl_handle_count_delete_logs_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_delete_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    $filters = isset($_POST['filters']) ? $_POST['filters'] : [];
    $count = kssl_count_logs_for_deletion($filters);
    
    if ($count !== false) {
        wp_send_json_success(['count' => $count]);
    } else {
        wp_send_json_error(['message' => 'カウント処理でエラーが発生しました。']);
    }
}

/**
 * AJAX処理：条件付きログ削除
 */
function kssl_handle_delete_logs_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_delete_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    $filters = isset($_POST['filters']) ? $_POST['filters'] : [];
    $result = kssl_delete_logs_with_filters($filters);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * AJAX処理：全ログ削除
 */
function kssl_handle_delete_all_logs_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_delete_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    $result = kssl_delete_all_logs();
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * データベース最適化のAJAXハンドラー（統合版）
 */
function kssl_handle_optimize_database_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_admin_nonce')) {
        wp_send_json_error(['message' => '無効なリクエストです']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
        return;
    }

    try {
        // ジョブIDを生成
        $job_id = wp_generate_password(16, false);

        // 初期ステータスを設定
        set_transient('kssl_optimize_job_' . $job_id, [
            'status' => 'pending',
            'message' => '最適化を開始しています...',
            'progress' => 0,
            'created' => time()
        ], 3600);

        // バックグラウンドでの実行をスケジュール
        wp_schedule_single_event(time(), 'kssl_process_optimize_background', [$job_id]);

        wp_send_json_success([
            'job_id' => $job_id,
            'message' => 'データベース最適化を開始しました。処理には数分かかる場合があります。'
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'エラー: ' . $e->getMessage()]);
    }
}

/**
 * データベース最適化のステータスを取得
 */
function kssl_handle_optimize_status_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_admin_nonce')) {
        wp_send_json_error(['message' => '無効なリクエストです']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
        return;
    }

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    if (empty($job_id)) {
        wp_send_json_error(['message' => 'ジョブIDが指定されていません']);
        return;
    }

    $status = get_transient('kssl_optimize_job_' . $job_id);
    if ($status === false) {
        wp_send_json_error(['message' => 'ジョブが見つかりません']);
        return;
    }

    wp_send_json_success($status);
}

/**
 * バックグラウンドでデータベース最適化を実行
 */
function kssl_process_optimize_background($job_id) {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    ignore_user_abort(true);

    try {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();

        // ステータス更新: 開始
        set_transient('kssl_optimize_job_' . $job_id, [
            'status' => 'processing',
            'message' => 'データベースを最適化しています...',
            'progress' => 10,
            'updated' => time()
        ], 3600);

        // 統合された最適化を実行（インデックス作成 + OPTIMIZE + ANALYZE）
        $optimize_result = KSSL_Performance::optimize_database_complete();

        if ($optimize_result) {
            // ステータス更新: 統計情報取得中
            set_transient('kssl_optimize_job_' . $job_id, [
                'status' => 'processing',
                'message' => '統計情報を取得しています...',
                'progress' => 80,
                'updated' => time()
            ], 3600);

            // 統計情報取得
            $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $table_size = $wpdb->get_var("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb' FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '{$table_name}'");

            // インデックス数取得
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
            $index_count = count(array_unique(wp_list_pluck($indexes, 'Key_name')));

            // ステータス更新: 完了
            set_transient('kssl_optimize_job_' . $job_id, [
                'status' => 'completed',
                'message' => 'データベースの完全最適化が完了しました',
                'progress' => 100,
                'total_records' => number_format($total_records),
                'table_size' => $table_size . ' MB',
                'index_count' => $index_count . '個のインデックス',
                'updated' => time()
            ], 3600);
        } else {
            set_transient('kssl_optimize_job_' . $job_id, [
                'status' => 'error',
                'message' => '最適化処理中にエラーが発生しました',
                'progress' => 0,
                'updated' => time()
            ], 3600);
        }
    } catch (Exception $e) {
        set_transient('kssl_optimize_job_' . $job_id, [
            'status' => 'error',
            'message' => 'エラー: ' . $e->getMessage(),
            'progress' => 0,
            'updated' => time()
        ], 3600);
    }
}

/**
 * AJAX処理：キャッシュクリア
 */
function kssl_handle_clear_cache_ajax() {
    if (!wp_verify_nonce($_POST['nonce'], 'kssl_cache_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    global $wpdb;

    $clear_type = isset($_POST['clear_type']) ? sanitize_text_field(wp_unslash($_POST['clear_type'])) : 'all';

    try {
        if ($clear_type === 'all') {
            // すべてのトレンドデータキャッシュを削除
            $deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%kssl_trend%'");

            wp_send_json_success([
                'message' => sprintf(
                    __('%d個のキャッシュをクリアしました。ページをリロードして最新データを表示します。', 'kashiwazaki-seo-super-access-log'),
                    $deleted
                )
            ]);
        } elseif ($clear_type === 'expired') {
            // 期限切れキャッシュのみ削除
            $current_time = time();

            // 期限切れのキャッシュキーを取得
            $expired_keys = $wpdb->get_col($wpdb->prepare("
                SELECT REPLACE(option_name, '_transient_timeout_', '')
                FROM {$wpdb->options}
                WHERE option_name LIKE %s
                AND CAST(option_value AS UNSIGNED) < %d
            ", '_transient_timeout_kssl_trend%', $current_time));

            $deleted_count = 0;
            foreach ($expired_keys as $key) {
                // データとタイムアウトの両方を削除
                $wpdb->delete($wpdb->options, ['option_name' => '_transient_' . $key]);
                $wpdb->delete($wpdb->options, ['option_name' => '_transient_timeout_' . $key]);
                $deleted_count++;
            }

            wp_send_json_success([
                'message' => sprintf(
                    __('%d個の期限切れキャッシュをクリアしました。', 'kashiwazaki-seo-super-access-log'),
                    $deleted_count
                )
            ]);
        } else {
            wp_send_json_error(['message' => 'Invalid clear type']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'エラー: ' . $e->getMessage()]);
    }
}

/**
 * AJAX処理：自動最適化のオン/オフ切り替え
 */
function kssl_handle_toggle_auto_optimization_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kssl_toggle_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true' ? '1' : '0';
    update_option('kssl_auto_optimization_enabled', $enabled);

    // スケジュールを更新
    if ($enabled === '1') {
        // 既存のスケジュールをクリア
        wp_clear_scheduled_hook('kssl_monthly_optimization');
        // 新しいスケジュールを設定
        if (!wp_next_scheduled('kssl_monthly_optimization')) {
            wp_schedule_event(time(), 'monthly', 'kssl_monthly_optimization');
        }
        $next_time = wp_next_scheduled('kssl_monthly_optimization');
        $next_time_formatted = $next_time ? get_date_from_gmt(date('Y-m-d H:i:s', $next_time), 'Y年m月d日 H:i') : '';
        wp_send_json_success([
            'message' => '自動最適化を有効にしました',
            'next_optimization' => $next_time_formatted
        ]);
    } else {
        // スケジュールをクリア
        wp_clear_scheduled_hook('kssl_monthly_optimization');
        wp_send_json_success([
            'message' => '自動最適化を無効にしました',
            'next_optimization' => null
        ]);
    }
}

/**
 * AJAX処理：期限切れキャッシュ自動削除のオン/オフ切り替え
 */
function kssl_handle_toggle_auto_clear_cache_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'kssl_toggle_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true' ? '1' : '0';
    update_option('kssl_auto_clear_expired_cache', $enabled);

    if ($enabled === '1') {
        wp_send_json_success(['message' => '期限切れキャッシュの自動削除を有効にしました']);
    } else {
        wp_send_json_success(['message' => '期限切れキャッシュの自動削除を無効にしました']);
    }
}