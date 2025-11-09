<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ログページの表示（メイン関数）
 */
function kssl_display_log_page_wrapper_func() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'このページにアクセスする権限がありません。', 'kashiwazaki-seo-super-access-log' ) );
    }

    // パフォーマンス設定を適用
    kssl_apply_performance_settings();

    // 設定保存処理
    $message = kssl_handle_settings_save();
    
    // フィルター処理
    $filter_data = kssl_get_filters_from_request();
    $display_where_clauses = $filter_data['where_clauses'] ?? [];
    $display_params = $filter_data['params'] ?? [];
    $current_filters = $filter_data['filters'] ?? [];
    
    // 除外URIパターンの適用
    list($display_where_clauses, $display_params) = kssl_apply_excluded_uri_patterns($display_where_clauses, $display_params);
    
    // ログデータの取得
    $log_data = kssl_get_log_data($display_where_clauses, $display_params);
    
    // 最適化ファイルを読み込み
    if (file_exists(dirname(__DIR__) . '/kssl-chart-optimizer.php')) {
        require_once dirname(__DIR__) . '/kssl-chart-optimizer.php';
    }

    // チャートデータの取得（最適化版を優先）
    if (function_exists('kssl_get_optimized_chart_data')) {
        $chart_data = kssl_get_optimized_chart_data($display_where_clauses, $display_params, $log_data['total_items']);
    } else {
        $chart_data = kssl_get_chart_data($display_where_clauses, $display_params, $log_data['total_items']);
    }

    // トレンドデータの取得（最適化版を優先）
    $current_timezone = isset($current_filters['timezone_filter']) ? $current_filters['timezone_filter'] : 'UTC';
    if (function_exists('kssl_get_optimized_trend_data')) {
        $trend_data = kssl_get_optimized_trend_data($display_where_clauses, $display_params, $current_timezone);
    } else {
        $trend_data = kssl_get_trend_data($display_where_clauses, $display_params, $current_timezone);
    }
    
    // JavaScriptデータの設定
    kssl_set_javascript_data($chart_data, $trend_data);
    
    // 画面表示
    kssl_render_log_page($message, $log_data, $current_filters);
}

/**
 * 設定保存処理
 *
 * @return string メッセージ
 */
function kssl_handle_settings_save() {
    $message = '';
    
    if ( isset( $_POST['kssl_save_settings_nonce'] ) && wp_verify_nonce( sanitize_key($_POST['kssl_save_settings_nonce']), 'kssl_save_settings_action' ) ) {
        if (kssl_save_plugin_settings($_POST)) {
            $message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '設定を保存しました。', 'kashiwazaki-seo-super-access-log' ) . '</p></div>';
        } else {
            $message = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '設定の保存に失敗しました。', 'kashiwazaki-seo-super-access-log' ) . '</p></div>';
        }
    }
    
    return $message;
}

/**
 * ログデータの取得
 *
 * @param array $where_clauses WHERE句の配列
 * @param array $params パラメータの配列
 * @return array ログデータ
 */
function kssl_get_log_data($where_clauses, $params) {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    
    // 配列の初期化
    $where_clauses = is_array($where_clauses) ? $where_clauses : [];
    $params = is_array($params) ? $params : [];
    
    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }
    
    // ソート処理
    $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'access_time';
    $order = isset($_REQUEST['order']) ? strtoupper(sanitize_key($_REQUEST['order'])) : 'DESC';
    
    if(!in_array($orderby, array_keys(kssl_get_all_column_definitions()))) $orderby = 'access_time';
    if(!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';
    $order_sql = "ORDER BY {$orderby} {$order}";

    // ページネーション
    $items_per_page = isset($_REQUEST['logs_per_page']) ? max(KSSL_DEFAULT_LOGS_PER_PAGE, intval($_REQUEST['logs_per_page'])) : KSSL_DEFAULT_LOGS_PER_PAGE;
    $current_page_num = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset_val = ( $current_page_num - 1 ) * $items_per_page;

    // データベースクエリの実行
    $total_items_count = kssl_safe_db_query(
        "SELECT COUNT(id) FROM {$table_name} {$where_sql}",
        $params,
        'get_var',
        0
    );

    $unique_visitors_count = kssl_safe_db_query(
        "SELECT COUNT(DISTINCT COALESCE(NULLIF(visitor_id_cookie, ''), ip_address)) FROM {$table_name} {$where_sql}",
        $params,
        'get_var',
        0
    );

    $total_pages_count = ceil( $total_items_count / $items_per_page );

    $log_entries = kssl_safe_db_query(
        "SELECT * FROM {$table_name} {$where_sql} {$order_sql} LIMIT %d OFFSET %d",
        array_merge($params, [$items_per_page, $offset_val]),
        'get_results',
        []
    );

    return [
        'total_items' => $total_items_count,
        'unique_visitors' => $unique_visitors_count,
        'total_pages' => $total_pages_count,
        'log_entries' => $log_entries,
        'current_page' => $current_page_num,
        'items_per_page' => $items_per_page,
        'orderby' => $orderby,
        'order' => $order
    ];
}

/**
 * チャートデータの取得
 *
 * @param array $where_clauses WHERE句の配列
 * @param array $params パラメータの配列
 * @param int $total_items 総アイテム数
 * @return array チャートデータ
 */
function kssl_get_chart_data($where_clauses, $params, $total_items) {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    
    // 配列の初期化
    $where_clauses = is_array($where_clauses) ? $where_clauses : [];
    $params = is_array($params) ? $params : [];
    
    $chart_targets = [
        'user_agent' => __('ユーザーエージェント', 'kashiwazaki-seo-super-access-log'),
        'country_code' => __('国・地域', 'kashiwazaki-seo-super-access-log'),
        'visit_type' => __('訪問タイプ', 'kashiwazaki-seo-super-access-log'),
        'status_code' => __('ステータスコード', 'kashiwazaki-seo-super-access-log'),
        'referer_domain' => __('リファラードメイン', 'kashiwazaki-seo-super-access-log'),
    ];
    
    $all_chart_data = [];
    $max_chart_records = intval(get_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT));
    
    // チャートを有効にするかどうかの判定
    $enable_charts = ($max_chart_records === 0 || $total_items <= $max_chart_records);
    
    if ($enable_charts) {
        $all_chart_data = kssl_generate_chart_data($chart_targets, $where_clauses, $params, $table_name);
    } else {
        $all_chart_data = kssl_generate_disabled_chart_data($chart_targets, $total_items, $max_chart_records);
    }
    
    return $all_chart_data;
}

/**
 * チャートデータの生成
 *
 * @param array $chart_targets チャートターゲット
 * @param array $where_clauses WHERE句の配列
 * @param array $params パラメータの配列
 * @param string $table_name テーブル名
 * @return array チャートデータ
 */
function kssl_generate_chart_data($chart_targets, $where_clauses, $params, $table_name) {
    global $wpdb;
    $all_chart_data = [];
    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
    
    // 配列の初期化
    $where_clauses = is_array($where_clauses) ? $where_clauses : [];
    $params = is_array($params) ? $params : [];
    
    foreach ($chart_targets as $key => $label) {
        $group_by_column = $key;
        $chart_where_clauses = $where_clauses;
        $chart_params = $params;

        if ($key === 'referer_domain') {
            $group_by_column = "SUBSTRING_INDEX(REPLACE(REPLACE(referer_url, 'https://', ''), 'http://', ''), '/', 1)";
            $chart_where_clauses[] = "referer_url IS NOT NULL AND referer_url != ''";
            if ($home_host) {
                $chart_where_clauses[] = "referer_url NOT LIKE %s";
                $chart_params[] = '%' . $wpdb->esc_like($home_host) . '%';
            }
        }

        $chart_where_sql = "";
        if (!empty($chart_where_clauses)) {
            $chart_where_sql = " WHERE " . implode(" AND ", $chart_where_clauses);
        }
        
        $results = kssl_safe_db_query(
            "SELECT {$group_by_column} as item, COUNT(*) as count FROM {$table_name} {$chart_where_sql} GROUP BY item ORDER BY count DESC LIMIT " . KSSL_QUERY_LIMIT,
            $chart_params,
            'get_results',
            []
        );
        
        $total_count = kssl_safe_db_query(
            "SELECT COUNT(id) FROM {$table_name} {$chart_where_sql}",
            $chart_params,
            'get_var',
            0
        );

        $all_chart_data[$key] = kssl_process_chart_results($results, $total_count, $label);
    }
    
    return $all_chart_data;
}

/**
 * チャート結果の処理
 *
 * @param array $results 結果
 * @param int $total_count 総数
 * @param string $label ラベル
 * @return array 処理されたチャートデータ
 */
function kssl_process_chart_results($results, $total_count, $label) {
    $chart_labels = [];
    $chart_counts = [];
    $list_data = [];
    $sum_top_counts = 0;
    $processed_count = 0;

    foreach ($results as $result) {
        if ($processed_count < KSSL_DEFAULT_CHART_TOP_LIMIT) {
            $chart_labels[] = $result->item;
            $chart_counts[] = intval($result->count);
            $sum_top_counts += intval($result->count);
        }
        $list_data[] = ['item' => $result->item, 'count' => intval($result->count)];
        $processed_count++;
    }

    if ($sum_top_counts < $total_count) {
        $chart_labels[] = __('その他', 'kashiwazaki-seo-super-access-log');
        $chart_counts[] = $total_count - $sum_top_counts;
    }

    return [
        'labels' => $chart_labels,
        'data' => $chart_counts,
        'list' => $list_data,
        'total' => $total_count,
        'title' => $label,
        'disabled' => false
    ];
}

/**
 * 無効化されたチャートデータの生成
 *
 * @param array $chart_targets チャートターゲット
 * @param int $total_items 総アイテム数
 * @param int $max_chart_records 最大チャートレコード数
 * @return array 無効化されたチャートデータ
 */
function kssl_generate_disabled_chart_data($chart_targets, $total_items, $max_chart_records) {
    $all_chart_data = [];
    
    foreach ($chart_targets as $key => $label) {
        $message = sprintf(
            __('パフォーマンスのためチャートは無効になっています（現在: %s ログ、上限: %s）。設定で調整できます。', 'kashiwazaki-seo-super-access-log'),
            number_format_i18n($total_items),
            number_format_i18n($max_chart_records)
        );
        
        $all_chart_data[$key] = [
            'labels' => [],
            'data' => [],
            'list' => [],
            'total' => 0,
            'title' => $label,
            'disabled' => true,
            'message' => $message
        ];
    }
    
    return $all_chart_data;
}

/**
 * トレンドデータの取得
 *
 * @param array $where_clauses WHERE句の配列
 * @param array $params パラメータの配列
 * @param string $timezone タイムゾーン
 * @return array トレンドデータ
 */
function kssl_get_trend_data($where_clauses, $params, $timezone = 'UTC') {
    // 配列の初期化
    $where_clauses = is_array($where_clauses) ? $where_clauses : [];
    $params = is_array($params) ? $params : [];
    
    try {
        $trend_data = kssl_get_access_trend_data($where_clauses, $params, $timezone);
        if (!$trend_data || !is_array($trend_data)) {
            return ['dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone];
        }
        return $trend_data;
    } catch (Exception $e) {
        kssl_log_error('Trend data error: ' . $e->getMessage());
        return ['dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone];
    }
}

/**
 * JavaScriptデータの設定
 *
 * @param array $chart_data チャートデータ
 * @param array $trend_data トレンドデータ
 */
function kssl_set_javascript_data($chart_data, $trend_data) {
    wp_add_inline_script(
        'kssl-admin-js',
        'var ksslAllChartData = ' . wp_json_encode($chart_data) . ';',
        'before'
    );
    
    wp_add_inline_script(
        'kssl-admin-js',
        'var ksslTrendData = ' . wp_json_encode($trend_data) . ';',
        'before'
    );
}

/**
 * ログページの表示
 *
 * @param string $message メッセージ
 * @param array $log_data ログデータ
 * @param array $current_filters 現在のフィルター
 */
function kssl_render_log_page($message, $log_data, $current_filters) {
    ?>
    <div class="wrap" id="kssl-log-page">
        <h1><?php esc_html_e('Kashiwazaki SEO Super Access Log', 'kashiwazaki-seo-super-access-log'); ?></h1>
        
        <?php if (!empty($message)) echo $message; ?>
        
        <h2 class="nav-tab-wrapper">
            <a href="#logs-section" class="nav-tab nav-tab-active" data-tab="logs-section"><?php esc_html_e('メイン', 'kashiwazaki-seo-super-access-log'); ?></a>
            <a href="#log-management-section" class="nav-tab" data-tab="log-management-section"><?php esc_html_e('ログの管理', 'kashiwazaki-seo-super-access-log'); ?></a>
            <a href="#settings-section" class="nav-tab" data-tab="settings-section"><?php esc_html_e('設定', 'kashiwazaki-seo-super-access-log'); ?></a>
        </h2>

        <div id="logs-section" class="kssl-tab-content">
            <?php kssl_render_logs_tab($log_data, $current_filters); ?>
        </div>

        <div id="log-management-section" class="kssl-tab-content" style="display:none;">
            <?php kssl_display_log_management_tab(); ?>
        </div>

        <div id="settings-section" class="kssl-tab-content" style="display:none;">
            <?php kssl_display_settings_form(); ?>
        </div>
    </div>
    <?php
}

/**
 * ログタブの表示
 *
 * @param array $log_data ログデータ
 * @param array $current_filters 現在のフィルター
 */
function kssl_render_logs_tab($log_data, $current_filters) {
    echo '<h2>' . esc_html__('アクセスログ', 'kashiwazaki-seo-super-access-log') . '</h2>';
    echo '<p>' . sprintf(
        esc_html__('合計: %s ログ、ユニーク訪問者: %s', 'kashiwazaki-seo-super-access-log'),
        number_format_i18n($log_data['total_items']),
        number_format_i18n($log_data['unique_visitors'])
    ) . '</p>';
    
    // フィルターフォームの表示
    kssl_display_filters_form($current_filters, $log_data['orderby'], $log_data['order']);
    
    // アクセストレンドチャートの表示
    kssl_render_trend_chart_section();
    
    // アクセス統計チャートの表示
    kssl_render_statistics_chart_section();
    
    // 表示カラムの取得
    $displayed_columns = kssl_get_displayed_columns();
    
    // ログテーブルの表示（タイムゾーンを渡す）
    $current_timezone = isset($current_filters['timezone_filter']) ? $current_filters['timezone_filter'] : 'UTC';
    kssl_display_log_table(
        $log_data['log_entries'],
        $displayed_columns,
        $log_data['orderby'],
        $log_data['order'],
        $current_filters,
        $log_data['current_page'],
        $current_timezone
    );
    
    // ページネーションの表示
    kssl_display_pagination($log_data['current_page'], $log_data['total_pages'], $log_data['total_items']);
}

/**
 * アクセストレンドチャートセクションの表示
 */
function kssl_render_trend_chart_section() {
    ?>
    <div class="kssl-accordion-wrapper">
        <h3 class="kssl-accordion-trigger kssl-accordion-active">
            <span><?php esc_html_e('アクセストレンドチャート', 'kashiwazaki-seo-super-access-log'); ?></span>
            <span class="dashicons dashicons-arrow-up-alt2"></span>
        </h3>
        <div id="trend-chart-display-area" class="kssl-accordion-content" style="display: block;">
            <div class="kssl-trend-summary" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid #0073aa;">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <strong><?php esc_html_e('総アクセス数:', 'kashiwazaki-seo-super-access-log'); ?></strong>
                        <span id="kssl-trend-total">-</span>
                    </div>
                    <div>
                        <strong><?php esc_html_e('トレンド:', 'kashiwazaki-seo-super-access-log'); ?></strong>
                        <span id="kssl-trend-indicator" class="kssl-trend-stable">
                            ➡️ <?php esc_html_e('安定', 'kashiwazaki-seo-super-access-log'); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div style="position: relative; height: 300px; margin-bottom: 20px;">
                <canvas id="kssl-trend-chart"></canvas>
            </div>
            <p class="description" style="text-align: center;">
                <?php esc_html_e('このチャートは選択した期間の日別アクセス数を表示します。トレンドは期間の前半と後半を比較して算出されます。', 'kashiwazaki-seo-super-access-log'); ?>
            </p>
        </div>
    </div>
    <?php
}

/**
 * アクセス統計チャートセクションの表示
 */
function kssl_render_statistics_chart_section() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $total_logs = kssl_safe_db_query("SELECT COUNT(id) FROM {$table_name}", [], 'get_var', 0);
    $max_chart_records = intval(get_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT));
    $enable_charts = ($max_chart_records === 0 || $total_logs <= $max_chart_records);
    
    $chart_status = $enable_charts ? 
        __('有効', 'kashiwazaki-seo-super-access-log') : 
        __('無効', 'kashiwazaki-seo-super-access-log');
    $chart_status_class = $enable_charts ? 'kssl-status-enabled' : 'kssl-status-disabled';
    ?>
    <div class="kssl-accordion-wrapper">
        <h3 class="kssl-accordion-trigger kssl-accordion-active">
            <span><?php esc_html_e('アクセス統計チャート', 'kashiwazaki-seo-super-access-log'); ?></span>
            <span class="dashicons dashicons-arrow-up-alt2"></span>
        </h3>
        <div id="chart-display-area" class="kssl-accordion-content" style="display: block;">
            <div style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid #0073aa;">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <strong><?php esc_html_e('現在のログ数:', 'kashiwazaki-seo-super-access-log'); ?></strong>
                        <span><?php echo number_format_i18n($total_logs); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="kssl-chart-switch-buttons">
                <button class="button kssl-chart-switch-btn" data-chart-key="user_agent">
                    <?php esc_html_e('ユーザーエージェント', 'kashiwazaki-seo-super-access-log'); ?>
                </button>
                <button class="button kssl-chart-switch-btn" data-chart-key="country_code">
                    <?php esc_html_e('国・地域', 'kashiwazaki-seo-super-access-log'); ?>
                </button>
                <button class="button kssl-chart-switch-btn" data-chart-key="visit_type">
                    <?php esc_html_e('訪問タイプ', 'kashiwazaki-seo-super-access-log'); ?>
                </button>
                <button class="button kssl-chart-switch-btn" data-chart-key="status_code">
                    <?php esc_html_e('ステータスコード', 'kashiwazaki-seo-super-access-log'); ?>
                </button>
                <button class="button kssl-chart-switch-btn" data-chart-key="referer_domain">
                    <?php esc_html_e('リファラードメイン', 'kashiwazaki-seo-super-access-log'); ?>
                </button>
            </div>
            <p id="kssl-chart-description"></p>
            <div class="kssl-flex-container">
                <div id="kssl-chart-canvas-container" class="kssl-chart-canvas-wrapper">
                    <p style="text-align: center; color: #999; padding: 40px;">チャートを読み込んでいます...</p>
                </div>
                <div id="kssl-chart-details-container" class="kssl-ua-list-style">
                    <p style="text-align: center; color: #999; padding: 20px;">チャートデータを読み込んでいます...</p>
                </div>
            </div>
            <p class="description" style="text-align: center; margin-top: 20px;">
                <?php printf(esc_html__('チャートは上位 %d 項目を表示します。「その他」はそれ以外の全項目の合計を表します。', 'kashiwazaki-seo-super-access-log'), KSSL_DEFAULT_CHART_TOP_LIMIT); ?>
            </p>
        </div>
    </div>
    <?php
}