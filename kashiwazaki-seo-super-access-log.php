<?php
/*
Plugin Name: Kashiwazaki SEO Super Access Log
Plugin URI: https://www.tsuyoshikashiwazaki.jp
Description: WordPress access log plugin with visitor tracking, bot filtering, CSV export/import, charts, and security features.
Version: 1.0.1
Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
License: GPLv2 or later
Text Domain: kashiwazaki-seo-super-access-log
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグイン基本情報
define( 'KSSL_PLUGIN_FILE_PATH', __FILE__ );
define( 'KSSL_PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'KSSL_PLUGIN_VERSION', '1.0.1' );

// データベース関連
define( 'KSSL_LOG_TABLE_NAME_CONST', 'super_access_logs' );

// Cookie関連
define( 'KSSL_COOKIE_NAME_BASE', 'kssl_visitor_tracker' );
define( 'KSSL_COOKIE_SUFFIX', '_KSSLID' );
define( 'KSSL_DEFAULT_COOKIE_LIFETIME', 24 );

// オプション名（設定項目）
define( 'KSSL_BLOCKED_VISITORS_OPTION_KEY', 'kssl_blocked_visitor_ids' );
define( 'KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY', 'kssl_enable_country_lookup' );
define( 'KSSL_BLOCKED_UAS_OPTION_KEY', 'kssl_blocked_user_agents' );
define( 'KSSL_DISPLAYED_COLUMNS_OPTION_KEY', 'kssl_displayed_columns' );
define( 'KSSL_COOKIE_LIFETIME_OPTION_KEY', 'kssl_cookie_lifetime_hours' );
define( 'KSSL_STATIC_TRACKING_OPTION_KEY', 'kssl_static_tracking_enabled' );
define( 'KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY', 'kssl_suspicious_keywords' );
define( 'KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY', 'kssl_excluded_uri_patterns' );
define( 'KSSL_BOT_DETECTION_PATTERN_OPTION_KEY', 'kssl_bot_detection_pattern' );
define( 'KSSL_LOG_RETENTION_DAYS_OPTION_KEY', 'kssl_log_retention_days' );
define( 'KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY', 'kssl_enable_auto_cleanup' );
define( 'KSSL_MAX_CHART_RECORDS_OPTION_KEY', 'kssl_max_chart_records' );
define( 'KSSL_EXCLUDE_SELF_SERVER_IP_OPTION_KEY', 'kssl_exclude_self_server_ip' );
define( 'KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY', 'kssl_exclude_static_files' );
define( 'KSSL_STATIC_FILES_PATTERN_OPTION_KEY', 'kssl_static_files_pattern' );
define( 'KSSL_BLOCK_EMPTY_UA_OPTION_KEY', 'kssl_block_empty_ua' );

// デフォルト値
define( 'KSSL_DEFAULT_CHART_LIMIT', 100000 );
define( 'KSSL_DEFAULT_RETENTION_DAYS', 90 );
define( 'KSSL_DEFAULT_LOGS_PER_PAGE', 10 );
define( 'KSSL_DEFAULT_CHART_TOP_LIMIT', 10 );
define( 'KSSL_DEFAULT_SUSPICIOUS_KEYWORDS', "wp-config.php\n.env\n.git\n/etc/passwd\nSELECT\nUNION SELECT\n<script\n../\n../../\n%00\n%2e%2e\neval(\nbase64_decode\nsystem(\nexec(\nshell_exec\nphpinfo\nproc_open" );
define( 'KSSL_DEFAULT_STATIC_FILES_PATTERN', '/\.(css|js|json|xml|txt|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot|otf|map|webp|avif|pdf|zip|rar|7z|tar|gz|mp3|mp4|avi|mov|wmv|flv|webm|ogg|wav)(\?.*)?$/i' );

// パフォーマンス設定
define( 'KSSL_MAX_EXECUTION_TIME', 300 );
define( 'KSSL_MAX_MEMORY_LIMIT', '256M' );
define( 'KSSL_QUERY_LIMIT', 100 );
define( 'KSSL_CLEANUP_BATCH_SIZE', 1000 );

// 必要ファイルの読み込み
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-helpers.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-db.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-aggregate.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-activation.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-settings.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-logging.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-csv-handler.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-log-deletion.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-performance.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-chart-optimizer.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/admin/kssl-admin-hooks.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/admin/kssl-admin-display.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/admin/kssl-admin-table.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/admin/kssl-admin-settings-form.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/admin/kssl-admin-filters.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/admin/kssl-admin-log-management.php';
require_once KSSL_PLUGIN_DIR_PATH . 'includes/kssl-rest-api.php';

// プラグインのアクティベーション・デアクティベーション
register_activation_hook( KSSL_PLUGIN_FILE_PATH, 'kssl_activate_plugin_func' );
register_deactivation_hook( KSSL_PLUGIN_FILE_PATH, 'kssl_deactivate_plugin_func' );

// プラグインの初期化
add_action( 'init', 'kssl_handle_cookie_and_log_init_hook', 0 );
add_action( 'rest_api_init', 'kssl_register_static_tracking_endpoint_hook' );
add_action( 'admin_init', 'kssl_admin_session_start_hook' );
// CSV files left in the old public directories are moved on the first request after an update
add_action( 'init', 'kssl_maybe_move_legacy_private_files' );
add_action( 'admin_notices', 'kssl_legacy_private_notice' );

// Background jobs (kssl_cleanup_old_logs is registered in kssl-logging.php)
add_action( 'kssl_migration_tick', 'kssl_migration_tick' );
add_action( 'kssl_derived_fill_tick', 'kssl_derived_fill_tick' );
add_action( 'kssl_reclassify_tick', 'kssl_reclassify_tick' );
add_action( 'kssl_agg_tick', 'kssl_agg_tick' );
add_action( 'kssl_jobs_watchdog', 'kssl_jobs_watchdog' );
add_action( 'admin_init', 'kssl_admin_maintain_jobs' );
add_action( 'init', 'kssl_ensure_log_table', 1 );
add_action( 'wp_initialize_site', 'kssl_initialize_new_site', 20 );

/**
 * First request on a site without the schema-version option (e.g. a new network site that never
 * ran activation): create the lightweight table if no log table exists, or record the version of
 * the existing one. Runs once per site.
 */
function kssl_ensure_log_table() {
    if ( get_option( KSSL_DB_VERSION_OPTION_KEY, false ) !== false ) {
        return;
    }
    // First request after updating from 1.0.0 without re-activation
    kssl_remove_legacy_optimization();
    if ( kssl_create_table_if_missing() ) {
        kssl_apply_new_install_defaults();
    }
}

function kssl_initialize_new_site( $site ) {
    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! is_plugin_active_for_network( plugin_basename( KSSL_PLUGIN_FILE_PATH ) ) ) {
        return;
    }
    switch_to_blog( (int) $site->blog_id );
    if ( kssl_create_table_if_missing() ) {
        kssl_apply_new_install_defaults();
    }
    restore_current_blog();
}

/**
 * プラグインのデアクティベーション処理
 */
function kssl_deactivate_plugin_func() {
    // Stop every scheduled job (state options are kept; admin_init resumes them after re-activation)
    foreach ( [ 'kssl_cleanup_old_logs', 'kssl_cleanup_old_logs_continue', 'kssl_monthly_optimization', 'kssl_migration_tick', 'kssl_derived_fill_tick', 'kssl_reclassify_tick', 'kssl_agg_tick', 'kssl_jobs_watchdog', 'kssl_export_job_step', 'kssl_import_job_step' ] as $hook ) {
        wp_unschedule_hook( $hook );
    }
}

function kssl_get_full_cookie_name() {
    return KSSL_COOKIE_NAME_BASE . KSSL_COOKIE_SUFFIX;
}