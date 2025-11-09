<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kssl_activate_plugin_func() {
    kssl_create_log_table_func();
    kssl_initialize_default_options();
    kssl_update_existing_options(); // 既存ユーザーの設定を更新

    // データベース最適化を実行（インデックス作成含む）
    kssl_optimize_on_activation();

    // Cronジョブ関数が定義されている場合のみ実行
    if (function_exists('kssl_schedule_cleanup_cron')) {
        kssl_schedule_cleanup_cron();
    }

    // データベース最適化の自動実行をスケジュール
    kssl_schedule_optimization_cron();
}

/**
 * プラグイン有効化時のデータベース最適化
 */
function kssl_optimize_on_activation() {
    // パフォーマンスクラスが読み込まれているか確認
    if (class_exists('KSSL_Performance')) {
        KSSL_Performance::optimize_database_complete();
    }
}

/**
 * データベース最適化の自動実行スケジュール設定
 */
function kssl_schedule_optimization_cron() {
    // 既存のスケジュールをクリア
    wp_clear_scheduled_hook('kssl_monthly_optimization');

    // 自動最適化がオンの場合のみスケジュール
    $auto_optimize = get_option('kssl_auto_optimization_enabled', '1'); // デフォルト: 有効
    if ($auto_optimize === '1') {
        if (!wp_next_scheduled('kssl_monthly_optimization')) {
            wp_schedule_event(time(), 'monthly', 'kssl_monthly_optimization');
        }
    }
}

/**
 * 月1回のデータベース最適化を実行
 */
function kssl_run_monthly_optimization() {
    // 自動最適化が有効な場合のみ実行
    $auto_optimize = get_option('kssl_auto_optimization_enabled', '1');
    if ($auto_optimize !== '1') {
        return;
    }

    if (class_exists('KSSL_Performance')) {
        KSSL_Performance::optimize_database_complete();
        error_log('KSSL: Monthly database optimization completed');
    }
}
add_action('kssl_monthly_optimization', 'kssl_run_monthly_optimization');

function kssl_create_log_table_func() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        access_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        access_date date GENERATED ALWAYS AS (DATE(access_time)) STORED,
        access_hour tinyint(2) GENERATED ALWAYS AS (HOUR(access_time)) STORED,
        ip_address varchar(45) DEFAULT '' NOT NULL,
        ip_hash char(64) GENERATED ALWAYS AS (SHA2(ip_address, 256)) STORED,
        user_agent_hash char(64) GENERATED ALWAYS AS (SHA2(user_agent, 256)) STORED,
        user_agent text NOT NULL,
        request_uri text NOT NULL,
        request_uri_hash char(64) GENERATED ALWAYS AS (SHA2(request_uri, 256)) STORED,
        referer_url text,
        referer_hash char(64) GENERATED ALWAYS AS (SHA2(COALESCE(referer_url, ''), 256)) STORED,
        request_method varchar(10) DEFAULT '' NOT NULL,
        status_code smallint(3) DEFAULT 0 NOT NULL,
        user_id bigint(20) DEFAULT NULL,
        is_bot tinyint(1) DEFAULT 0 NOT NULL,
        visit_type varchar(15) DEFAULT 'unknown' NOT NULL,
        source varchar(15) DEFAULT 'wordpress' NOT NULL,
        visitor_id_cookie varchar(255) DEFAULT NULL,
        visitor_hash char(64) GENERATED ALWAYS AS (SHA2(COALESCE(visitor_id_cookie, ''), 256)) STORED,
        country_code varchar(3) DEFAULT NULL,
        navigation_type varchar(15) DEFAULT 'unknown' NOT NULL,
        PRIMARY KEY (id),
        KEY idx_access_time (access_time),
        KEY idx_access_date (access_date),
        KEY idx_access_date_hour (access_date, access_hour),
        KEY idx_ip_hash (ip_hash),
        KEY idx_user_id (user_id),
        KEY idx_status_code (status_code),
        KEY idx_is_bot (is_bot),
        KEY idx_visit_type (visit_type),
        KEY idx_source (source),
        KEY idx_visitor_hash (visitor_hash),
        KEY idx_country_code (country_code),
        KEY idx_request_uri_hash (request_uri_hash),
        KEY idx_referer_hash (referer_hash),
        KEY idx_composite_main (access_date, status_code, is_bot),
        KEY idx_composite_analytics (access_date, country_code, visit_type),
        KEY idx_composite_security (access_date, ip_hash, status_code),
        KEY idx_composite_visitor (visitor_hash, access_date)
    ) {$charset_collate} 
    ENGINE=InnoDB 
    ROW_FORMAT=COMPRESSED 
    KEY_BLOCK_SIZE=8;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    $columns_to_check = [
        'visit_type' => "ADD visit_type VARCHAR(15) DEFAULT 'unknown' NOT NULL AFTER is_bot",
        'source'     => "ADD source VARCHAR(15) DEFAULT 'wordpress' NOT NULL AFTER visit_type",
        'visitor_id_cookie' => "ADD visitor_id_cookie VARCHAR(255) DEFAULT NULL AFTER source",
        'country_code' => "ADD country_code VARCHAR(3) DEFAULT NULL AFTER visitor_id_cookie",
    ];

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
        foreach ($columns_to_check as $column_name => $alter_query) {
            $column_exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", $column_name ) );
            if ( empty( $column_exists ) ) {
                $wpdb->query( "ALTER TABLE {$table_name} {$alter_query}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }
        $nav_type_exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", 'navigation_type' ) );
        if (empty($nav_type_exists)) {
            $after_col = 'country_code';
            $col_country_code_exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", 'country_code' ) );
             if (empty($col_country_code_exists)) $after_col = 'visitor_id_cookie';


            $wpdb->query( "ALTER TABLE {$table_name} ADD navigation_type VARCHAR(15) DEFAULT 'unknown' NOT NULL AFTER {$after_col}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // 既存のテーブルに新しいインデックスを追加
        kssl_add_missing_indexes($table_name);
    }
}

/**
 * 既存のテーブルに不足しているカラムとインデックスを追加
 */
function kssl_add_missing_indexes($table_name) {
    global $wpdb;
    
    // 新しい生成カラムを追加
    $new_columns = [
        'access_date' => "ADD COLUMN access_date date GENERATED ALWAYS AS (DATE(access_time)) STORED",
        'access_hour' => "ADD COLUMN access_hour tinyint(2) GENERATED ALWAYS AS (HOUR(access_time)) STORED", 
        'ip_hash' => "ADD COLUMN ip_hash char(64) GENERATED ALWAYS AS (SHA2(ip_address, 256)) STORED",
        'user_agent_hash' => "ADD COLUMN user_agent_hash char(64) GENERATED ALWAYS AS (SHA2(user_agent, 256)) STORED",
        'request_uri_hash' => "ADD COLUMN request_uri_hash char(64) GENERATED ALWAYS AS (SHA2(request_uri, 256)) STORED",
        'referer_hash' => "ADD COLUMN referer_hash char(64) GENERATED ALWAYS AS (SHA2(COALESCE(referer_url, ''), 256)) STORED",
        'visitor_hash' => "ADD COLUMN visitor_hash char(64) GENERATED ALWAYS AS (SHA2(COALESCE(visitor_id_cookie, ''), 256)) STORED"
    ];
    
    foreach ($new_columns as $column_name => $alter_query) {
        $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column_name));
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} {$alter_query}");
        }
    }
    
    // IP address カラムのサイズ調整（IPv6対応）
    $ip_column = $wpdb->get_row("SHOW COLUMNS FROM {$table_name} LIKE 'ip_address'");
    if ($ip_column && strpos($ip_column->Type, 'varchar(100)') !== false) {
        $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN ip_address varchar(45) DEFAULT '' NOT NULL");
    }
    
    // status_code を smallint に変更（メモリ節約）
    $status_column = $wpdb->get_row("SHOW COLUMNS FROM {$table_name} LIKE 'status_code'");
    if ($status_column && strpos($status_column->Type, 'int(3)') !== false) {
        $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN status_code smallint(3) DEFAULT 0 NOT NULL");
    }
    
    // 新しいインデックスを追加
    $new_indexes = [
        'idx_access_date' => "ADD INDEX idx_access_date (access_date)",
        'idx_access_date_hour' => "ADD INDEX idx_access_date_hour (access_date, access_hour)",
        'idx_ip_hash' => "ADD INDEX idx_ip_hash (ip_hash)",
        'idx_visitor_hash' => "ADD INDEX idx_visitor_hash (visitor_hash)",
        'idx_request_uri_hash' => "ADD INDEX idx_request_uri_hash (request_uri_hash)",
        'idx_referer_hash' => "ADD INDEX idx_referer_hash (referer_hash)",
        'idx_composite_main' => "ADD INDEX idx_composite_main (access_date, status_code, is_bot)",
        'idx_composite_analytics' => "ADD INDEX idx_composite_analytics (access_date, country_code, visit_type)",
        'idx_composite_security' => "ADD INDEX idx_composite_security (access_date, ip_hash, status_code)",
        'idx_composite_visitor' => "ADD INDEX idx_composite_visitor (visitor_hash, access_date)"
    ];
    
    foreach ($new_indexes as $index_name => $alter_query) {
        $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}'");
        if (empty($index_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} {$alter_query}");
        }
    }
    
    // 古いインデックスを追加（後方互換性）
    $legacy_indexes = [
        'idx_request_uri' => "ADD INDEX idx_request_uri (request_uri(255))",
        'idx_referer_url' => "ADD INDEX idx_referer_url (referer_url(255))",
        'idx_composite' => "ADD INDEX idx_composite (access_time, status_code, is_bot)"
    ];
    
    foreach ($legacy_indexes as $index_name => $alter_query) {
        $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}'");
        if (empty($index_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} {$alter_query}");
        }
    }
    
    // テーブルエンジンとROW_FORMATの最適化
    $wpdb->query("ALTER TABLE {$table_name} ENGINE=InnoDB ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8");
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
    $current_bot_pattern = get_option( KSSL_BOT_DETECTION_PATTERN_OPTION_KEY, '' );
    if ( !empty($current_bot_pattern) && strpos($current_bot_pattern, 'ChatGPT-User') === false ) {
        // 既存パターンにAI Botが含まれていない場合は追加
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