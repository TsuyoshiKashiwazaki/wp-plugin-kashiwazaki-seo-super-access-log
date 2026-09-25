<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation. Only light work happens here: create the log table when none exists, set option
 * defaults and schedule jobs. Existing tables are never altered or rebuilt in this request
 * (the migration to the lightweight schema runs in WP-Cron, see kssl-db.php).
 */
function kssl_activate_plugin_func() {
    $created = kssl_create_table_if_missing();
    kssl_migration_detect_state();

    kssl_initialize_default_options();
    kssl_update_existing_options();
    if ( $created ) {
        kssl_apply_new_install_defaults();
    }

    kssl_remove_legacy_optimization();

    if ( function_exists( 'kssl_schedule_cleanup_cron' ) ) {
        kssl_schedule_cleanup_cron();
    }
    if ( ! wp_next_scheduled( 'kssl_jobs_watchdog' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'kssl_jobs_watchdog' );
    }
}

/**
 * Defaults that only apply to a brand-new install (the log table did not exist before):
 * keep 90 days of logs. Existing installs keep their settings.
 */
function kssl_apply_new_install_defaults() {
    update_option( KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 1 );
    update_option( KSSL_LOG_RETENTION_DAYS_OPTION_KEY, KSSL_DEFAULT_RETENTION_DAYS );
    if ( function_exists( 'kssl_schedule_cleanup_cron' ) ) {
        kssl_schedule_cleanup_cron();
    }
}

function kssl_initialize_default_options() {
    $all_cols = kssl_get_all_column_definitions();
    $default_columns = [];
    foreach (array_keys($all_cols) as $key) {
        $default_columns[$key] = 1;
    }

    // 表示カラムの初期化
    if ( false === get_option( KSSL_DISPLAYED_COLUMNS_OPTION_KEY ) ) {
        update_option( KSSL_DISPLAYED_COLUMNS_OPTION_KEY, $default_columns );
    }
    
    // Cookie設定の初期化
    if ( false === get_option( KSSL_COOKIE_LIFETIME_OPTION_KEY ) ) {
        update_option( KSSL_COOKIE_LIFETIME_OPTION_KEY, KSSL_DEFAULT_COOKIE_LIFETIME );
    }
    
    // トラッキング設定の初期化
    if ( false === get_option( KSSL_STATIC_TRACKING_OPTION_KEY ) ) {
        update_option( KSSL_STATIC_TRACKING_OPTION_KEY, 0 );
    }
    
    if ( false === get_option( KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY ) ) {
        update_option( KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY, 0 );
    }
    
    // セキュリティ設定の初期化
    if ( false === get_option( KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY ) ) {
        update_option( KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS );
    }
    
    if ( false === get_option( KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY ) ) {
        update_option( KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY, '' );
    }
    
    if ( false === get_option( KSSL_BOT_DETECTION_PATTERN_OPTION_KEY ) ) {
        update_option( KSSL_BOT_DETECTION_PATTERN_OPTION_KEY, '/(bot|crawl|slurp|spider|archiv|seek|extract|fetch|seeker|scan|survey|Mediapartners-Google|AdsBot-Google|FeedFetcher|Googlebot|bingbot|msnbot|YandexBot|Baiduspider|facebookexternalhit|twitterbot|linkedinbot|embedly|pinterest|SemrushBot|AhrefsBot|MJ12bot|Applebot|DuckDuckBot|BLEXBot|DotBot|Exabot|Sogou|ia_archiver|UptimeRobot|Linespider|GPTBot|ChatGPT-User|Google-Extended|ClaudeBot|Claude-Web|PerplexityBot|Applebot-Extended|CCBot|OAI-SearchBot|anthropic-ai|cohere-ai|ICC-Crawler|Bytespider|Meta-ExternalAgent)/i' );
    }
    
    // ブロック設定の初期化
    if ( false === get_option( KSSL_BLOCKED_VISITORS_OPTION_KEY ) ) {
        update_option( KSSL_BLOCKED_VISITORS_OPTION_KEY, [] );
    }
    
    if ( false === get_option( KSSL_BLOCKED_UAS_OPTION_KEY ) ) {
        update_option( KSSL_BLOCKED_UAS_OPTION_KEY, '' );
    }
    
    // ログ保存期間と自動クリーンアップの初期化
    if ( false === get_option( KSSL_LOG_RETENTION_DAYS_OPTION_KEY ) ) {
        update_option( KSSL_LOG_RETENTION_DAYS_OPTION_KEY, KSSL_DEFAULT_RETENTION_DAYS );
    }
    
    if ( false === get_option( KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY ) ) {
        update_option( KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 0 );
    }
    
    // パフォーマンス設定の初期化
    if ( false === get_option( KSSL_MAX_CHART_RECORDS_OPTION_KEY ) ) {
        update_option( KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT );
    }

    // 静的ファイル除外設定の初期化（デフォルトは除外する）
    if ( false === get_option( KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY ) ) {
        update_option( KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY, '1' );
    }

    // 静的ファイル除外パターンの初期化
    if ( false === get_option( KSSL_STATIC_FILES_PATTERN_OPTION_KEY ) ) {
        update_option( KSSL_STATIC_FILES_PATTERN_OPTION_KEY, KSSL_DEFAULT_STATIC_FILES_PATTERN );
    }
}

/**
 * 既存ユーザーの設定を更新（新しい設定項目のデフォルト値を設定）
 */
function kssl_update_existing_options() {
    // 新しく追加された設定項目にデフォルト値を設定
    
    // ログ保存期間が設定されていない場合のデフォルト値
    if ( get_option( KSSL_LOG_RETENTION_DAYS_OPTION_KEY ) === false ) {
        update_option( KSSL_LOG_RETENTION_DAYS_OPTION_KEY, KSSL_DEFAULT_RETENTION_DAYS );
    }
    
    // 自動クリーンアップが設定されていない場合のデフォルト値
    if ( get_option( KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY ) === false ) {
        update_option( KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 0 );
    }
    
    // チャート制限が設定されていない場合のデフォルト値
    if ( get_option( KSSL_MAX_CHART_RECORDS_OPTION_KEY ) === false ) {
        update_option( KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT );
    }

    // 静的ファイル除外設定が設定されていない場合のデフォルト値
    if ( get_option( KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY ) === false ) {
        update_option( KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY, '1' );
    }

    // ボット検出パターンの更新（AI Botを追加）
    // Only the previous default pattern itself is upgraded; a pattern the administrator changed
    // is kept as it is.
    $current_bot_pattern = get_option( KSSL_BOT_DETECTION_PATTERN_OPTION_KEY, '' );
    if ( $current_bot_pattern === '/(bot|crawl|slurp|spider|archiv|seek|extract|fetch|seeker|scan|survey|Mediapartners-Google|AdsBot-Google|FeedFetcher|Googlebot|bingbot|msnbot|YandexBot|Baiduspider|facebookexternalhit|twitterbot|linkedinbot|embedly|pinterest|SemrushBot|AhrefsBot|MJ12bot|Applebot|DuckDuckBot|BLEXBot|DotBot|Exabot|Sogou|ia_archiver|UptimeRobot|Linespider)/i' ) {
        $new_pattern = '/(bot|crawl|slurp|spider|archiv|seek|extract|fetch|seeker|scan|survey|Mediapartners-Google|AdsBot-Google|FeedFetcher|Googlebot|bingbot|msnbot|YandexBot|Baiduspider|facebookexternalhit|twitterbot|linkedinbot|embedly|pinterest|SemrushBot|AhrefsBot|MJ12bot|Applebot|DuckDuckBot|BLEXBot|DotBot|Exabot|Sogou|ia_archiver|UptimeRobot|Linespider|GPTBot|ChatGPT-User|Google-Extended|ClaudeBot|Claude-Web|PerplexityBot|Applebot-Extended|CCBot|OAI-SearchBot|anthropic-ai|cohere-ai|ICC-Crawler|Bytespider|Meta-ExternalAgent)/i';
        update_option( KSSL_BOT_DETECTION_PATTERN_OPTION_KEY, $new_pattern );
    }

    // 古いオプション名から新しいオプション名への移行
    $migration_map = [
        'kssl_displayed_columns' => KSSL_DISPLAYED_COLUMNS_OPTION_KEY,
        'kssl_cookie_lifetime_hours' => KSSL_COOKIE_LIFETIME_OPTION_KEY,
        'kssl_static_tracking_enabled' => KSSL_STATIC_TRACKING_OPTION_KEY,
        'kssl_suspicious_keywords' => KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY,
        'kssl_excluded_uri_patterns' => KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY,
        'kssl_bot_detection_pattern' => KSSL_BOT_DETECTION_PATTERN_OPTION_KEY,
        'kssl_log_retention_days' => KSSL_LOG_RETENTION_DAYS_OPTION_KEY,
        'kssl_enable_auto_cleanup' => KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY,
        'kssl_max_chart_records' => KSSL_MAX_CHART_RECORDS_OPTION_KEY,
    ];
    
    foreach ($migration_map as $old_key => $new_key) {
        if ($old_key !== $new_key) {
            $old_value = get_option($old_key);
            if ($old_value !== false && get_option($new_key) === false) {
                update_option($new_key, $old_value);
                // 古いオプションは削除しない（互換性のため）
            }
        }
    }
}