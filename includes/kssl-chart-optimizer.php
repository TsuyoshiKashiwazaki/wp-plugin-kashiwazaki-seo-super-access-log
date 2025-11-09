<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * チャートデータの最適化された取得（キャッシュ付き）
 */
function kssl_get_optimized_chart_data($where_clauses, $params, $total_items) {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();

    // 配列の初期化
    $where_clauses = is_array($where_clauses) ? $where_clauses : [];
    $params = is_array($params) ? $params : [];

    // キャッシュキーの生成（フィルター条件も含む）
    $cache_key = 'kssl_chart_' . md5(serialize($where_clauses) . serialize($params) . $total_items);
    $cached_data = get_transient($cache_key);

    if ($cached_data !== false && !isset($_GET['force_refresh'])) {
        return $cached_data;
    }

    $max_chart_records = intval(get_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT));

    // データ量が多すぎる場合はチャートを無効化
    if ($max_chart_records > 0 && $total_items > $max_chart_records) {
        $all_chart_data = kssl_generate_disabled_chart_data_optimized($total_items, $max_chart_records);
    } else {
        // 並列でチャートデータを取得（最適化版）
        $all_chart_data = kssl_get_all_charts_optimized($where_clauses, $params, $table_name, $total_items);
    }

    // 60秒間キャッシュ（データ量に応じて調整）
    $cache_duration = $total_items > 100000 ? 300 : 60;
    set_transient($cache_key, $all_chart_data, $cache_duration);

    return $all_chart_data;
}

/**
 * 最適化された全チャートデータ取得
 */
function kssl_get_all_charts_optimized($where_clauses, $params, $table_name, $total_items) {
    global $wpdb;

    // 大量データの場合はサンプリングを使用
    $use_sampling = $total_items > 100000; // 10万件以上でサンプリング
    $sample_rate = $use_sampling ? 10 : 1; // 10%サンプリング

    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    // サンプリング条件を追加
    if ($use_sampling) {
        if (empty($where_sql)) {
            $where_sql = " WHERE MOD(id, 10) = 0";
        } else {
            $where_sql .= " AND MOD(id, 10) = 0";
        }
    }

    $chart_targets = [
        'user_agent' => __('ユーザーエージェント', 'kashiwazaki-seo-super-access-log'),
        'country_code' => __('国・地域', 'kashiwazaki-seo-super-access-log'),
        'visit_type' => __('訪問タイプ', 'kashiwazaki-seo-super-access-log'),
        'status_code' => __('ステータスコード', 'kashiwazaki-seo-super-access-log'),
        'referer_domain' => __('リファラードメイン', 'kashiwazaki-seo-super-access-log'),
    ];

    $all_chart_data = [];

    foreach ($chart_targets as $key => $label) {
        // チャートごとに最適化されたクエリを実行
        if ($key === 'referer_domain') {
            // リファラードメインは特別処理（文字列操作が重い）
            $all_chart_data[$key] = kssl_get_referer_chart_data_optimized($where_clauses, $params, $table_name, $label, $use_sampling, $sample_rate);
        } else {
            // その他のチャートは標準処理
            $limit = 20; // TOP 20のみ取得して高速化

            $query = "SELECT {$key} as item, COUNT(*) * %d as count
                     FROM {$table_name} {$where_sql}
                     GROUP BY {$key}
                     ORDER BY count DESC
                     LIMIT %d";

            $results = $wpdb->get_results(
                empty($params) ?
                    $wpdb->prepare($query, $sample_rate, $limit) :
                    $wpdb->prepare($query, array_merge([$sample_rate], $params, [$limit]))
            );

            // 総数はキャッシュか概算値を使用
            $total_count = $use_sampling ? $total_items :
                           $wpdb->get_var(
                               empty($params) ?
                                   "SELECT COUNT(*) FROM {$table_name} {$where_sql}" :
                                   $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} {$where_sql}", $params)
                           );

            $all_chart_data[$key] = kssl_format_chart_data_optimized($results, $total_count, $label, $key);
        }
    }

    return $all_chart_data;
}

/**
 * リファラードメインの最適化された取得
 */
function kssl_get_referer_chart_data_optimized($where_clauses, $params, $table_name, $label, $use_sampling, $sample_rate) {
    global $wpdb;
    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

    $referer_where_clauses = $where_clauses;
    $referer_params = $params;
    $referer_where_clauses[] = "referer_url IS NOT NULL AND referer_url != ''";

    if ($home_host) {
        $referer_where_clauses[] = "referer_url NOT LIKE %s";
        $referer_params[] = '%' . $wpdb->esc_like($home_host) . '%';
    }

    $referer_where_sql = "";
    if (!empty($referer_where_clauses)) {
        $referer_where_sql = " WHERE " . implode(" AND ", $referer_where_clauses);
    }

    if ($use_sampling) {
        $referer_where_sql .= " AND MOD(id, 10) = 0";
    }

    // referer_domainカラムがある場合はそれを使用、なければ文字列処理
    $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'referer_domain'");

    if ($column_exists) {
        $domain_field = "referer_domain";
    } else {
        // 文字列処理を簡略化
        $domain_field = "SUBSTRING_INDEX(SUBSTRING_INDEX(referer_url, '//', -1), '/', 1)";
    }

    $query = "SELECT {$domain_field} as item, COUNT(*) * %d as count
             FROM {$table_name} {$referer_where_sql}
             GROUP BY item
             ORDER BY count DESC
             LIMIT 20";

    $results = $wpdb->get_results(
        empty($referer_params) ?
            $wpdb->prepare($query, $sample_rate) :
            $wpdb->prepare($query, array_merge([$sample_rate], $referer_params))
    );

    $total_count = $wpdb->get_var(
        empty($referer_params) ?
            "SELECT COUNT(*) FROM {$table_name} {$referer_where_sql}" :
            $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} {$referer_where_sql}", $referer_params)
    );

    return kssl_format_chart_data_optimized($results, $total_count * ($use_sampling ? 10 : 1), $label, 'referer_domain');
}

/**
 * チャートデータのフォーマット（最適化版）
 */
function kssl_format_chart_data_optimized($results, $total_count, $label, $chart_key = '') {
    $chart_labels = [];
    $chart_counts = [];
    $list_data = [];
    $sum_top_counts = 0;
    $notice = '';

    // 国コードの場合、設定が無効ならメッセージを追加
    if ($chart_key === 'country_code') {
        $enable_country_lookup = get_option(KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY, false);
        if (!$enable_country_lookup) {
            $notice = '国・地域データを取得するには、設定で「国コード取得を有効化」にチェックを入れてください。';
        }
    }

    // 最大10項目に制限
    $limit = min(count($results), 10);

    for ($i = 0; $i < $limit; $i++) {
        $item = $results[$i];
        $chart_labels[] = $item->item ?: '(不明)';
        $count = intval($item->count);
        $chart_counts[] = $count;
        $sum_top_counts += $count;
        $list_data[] = ['item' => $item->item ?: '(不明)', 'count' => $count];
    }

    // その他を追加
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
        'disabled' => false,
        'notice' => $notice
    ];
}

/**
 * 元の単一クエリ関数（使用しない）
 */
function kssl_get_all_charts_single_query($where_clauses, $params, $table_name) {
    global $wpdb;
    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    // UNIONを使用して複数の集計を一度に実行
    $union_queries = [];

    // ユーザーエージェント
    $union_queries[] = $wpdb->prepare(
        "SELECT 'user_agent' as chart_type, user_agent as item, COUNT(*) as count
         FROM {$table_name} {$where_sql}
         GROUP BY user_agent
         ORDER BY count DESC
         LIMIT %d",
        array_merge($params, [KSSL_QUERY_LIMIT])
    );

    // 国コード
    $union_queries[] = $wpdb->prepare(
        "SELECT 'country_code' as chart_type, country_code as item, COUNT(*) as count
         FROM {$table_name} {$where_sql}
         GROUP BY country_code
         ORDER BY count DESC
         LIMIT %d",
        array_merge($params, [KSSL_QUERY_LIMIT])
    );

    // 訪問タイプ
    $union_queries[] = $wpdb->prepare(
        "SELECT 'visit_type' as chart_type, visit_type as item, COUNT(*) as count
         FROM {$table_name} {$where_sql}
         GROUP BY visit_type
         ORDER BY count DESC
         LIMIT %d",
        array_merge($params, [KSSL_QUERY_LIMIT])
    );

    // ステータスコード
    $union_queries[] = $wpdb->prepare(
        "SELECT 'status_code' as chart_type, status_code as item, COUNT(*) as count
         FROM {$table_name} {$where_sql}
         GROUP BY status_code
         ORDER BY count DESC
         LIMIT %d",
        array_merge($params, [KSSL_QUERY_LIMIT])
    );

    // リファラードメイン（別途処理が必要）
    $referer_where_clauses = $where_clauses;
    $referer_params = $params;
    $referer_where_clauses[] = "referer_url IS NOT NULL AND referer_url != ''";
    if ($home_host) {
        $referer_where_clauses[] = "referer_url NOT LIKE %s";
        $referer_params[] = '%' . $wpdb->esc_like($home_host) . '%';
    }
    $referer_where_sql = "";
    if (!empty($referer_where_clauses)) {
        $referer_where_sql = " WHERE " . implode(" AND ", $referer_where_clauses);
    }

    $union_queries[] = $wpdb->prepare(
        "SELECT 'referer_domain' as chart_type,
         SUBSTRING_INDEX(REPLACE(REPLACE(referer_url, 'https://', ''), 'http://', ''), '/', 1) as item,
         COUNT(*) as count
         FROM {$table_name} {$referer_where_sql}
         GROUP BY item
         ORDER BY count DESC
         LIMIT %d",
        array_merge($referer_params, [KSSL_QUERY_LIMIT])
    );

    // 全クエリを結合
    $combined_query = "(" . implode(") UNION ALL (", $union_queries) . ")";

    // クエリ実行
    $results = $wpdb->get_results($combined_query);

    // 結果を整形
    $chart_data_by_type = [];
    foreach ($results as $row) {
        if (!isset($chart_data_by_type[$row->chart_type])) {
            $chart_data_by_type[$row->chart_type] = [];
        }
        $chart_data_by_type[$row->chart_type][] = [
            'item' => $row->item,
            'count' => intval($row->count)
        ];
    }

    // 各チャートタイプの総数を取得（これも最適化可能）
    $totals = kssl_get_chart_totals_optimized($where_clauses, $params, $table_name, $home_host);

    // チャートデータを整形
    $all_chart_data = [];
    $chart_labels = [
        'user_agent' => __('ユーザーエージェント', 'kashiwazaki-seo-super-access-log'),
        'country_code' => __('国・地域', 'kashiwazaki-seo-super-access-log'),
        'visit_type' => __('訪問タイプ', 'kashiwazaki-seo-super-access-log'),
        'status_code' => __('ステータスコード', 'kashiwazaki-seo-super-access-log'),
        'referer_domain' => __('リファラードメイン', 'kashiwazaki-seo-super-access-log'),
    ];

    foreach ($chart_labels as $key => $label) {
        $data = isset($chart_data_by_type[$key]) ? $chart_data_by_type[$key] : [];
        $total = isset($totals[$key]) ? $totals[$key] : 0;
        $all_chart_data[$key] = kssl_format_chart_data($data, $total, $label);
    }

    return $all_chart_data;
}

/**
 * チャートの総数を最適化して取得
 */
function kssl_get_chart_totals_optimized($where_clauses, $params, $table_name, $home_host) {
    global $wpdb;

    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    // リファラー用のWHERE句
    $referer_where_clauses = $where_clauses;
    $referer_params = $params;
    $referer_where_clauses[] = "referer_url IS NOT NULL AND referer_url != ''";
    if ($home_host) {
        $referer_where_clauses[] = "referer_url NOT LIKE %s";
        $referer_params[] = '%' . $wpdb->esc_like($home_host) . '%';
    }
    $referer_where_sql = "";
    if (!empty($referer_where_clauses)) {
        $referer_where_sql = " WHERE " . implode(" AND ", $referer_where_clauses);
    }

    // 単一クエリで複数のカウントを取得
    $count_query = "
        SELECT
            (SELECT COUNT(*) FROM {$table_name} {$where_sql}) as total_count,
            (SELECT COUNT(*) FROM {$table_name} {$referer_where_sql}) as referer_count
    ";

    $counts = $wpdb->get_row(
        empty($params) ? $count_query : $wpdb->prepare($count_query, ...$params)
    );

    return [
        'user_agent' => $counts->total_count,
        'country_code' => $counts->total_count,
        'visit_type' => $counts->total_count,
        'status_code' => $counts->total_count,
        'referer_domain' => $counts->referer_count
    ];
}

/**
 * チャートデータのフォーマット
 */
function kssl_format_chart_data($results, $total_count, $label) {
    $chart_labels = [];
    $chart_counts = [];
    $list_data = [];
    $sum_top_counts = 0;
    $processed_count = 0;

    foreach ($results as $item) {
        if ($processed_count < KSSL_DEFAULT_CHART_TOP_LIMIT) {
            $chart_labels[] = $item['item'];
            $chart_counts[] = $item['count'];
            $sum_top_counts += $item['count'];
        }
        $list_data[] = $item;
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
 * 無効化されたチャートデータの生成（最適化版）
 */
function kssl_generate_disabled_chart_data_optimized($total_items, $max_chart_records) {
    $chart_targets = [
        'user_agent' => __('ユーザーエージェント', 'kashiwazaki-seo-super-access-log'),
        'country_code' => __('国・地域', 'kashiwazaki-seo-super-access-log'),
        'visit_type' => __('訪問タイプ', 'kashiwazaki-seo-super-access-log'),
        'status_code' => __('ステータスコード', 'kashiwazaki-seo-super-access-log'),
        'referer_domain' => __('リファラードメイン', 'kashiwazaki-seo-super-access-log'),
    ];

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
 * 最適化されたトレンドデータ取得（キャッシュ付き）
 */
function kssl_get_optimized_trend_data($where_clauses, $params, $timezone = 'UTC') {
    // 配列の初期化
    $where_clauses = is_array($where_clauses) ? $where_clauses : [];
    $params = is_array($params) ? $params : [];

    // キャッシュキーの生成
    $cache_key = 'kssl_trend_' . md5(serialize($where_clauses) . serialize($params) . $timezone);
    $cached_data = get_transient($cache_key);

    if ($cached_data !== false) {
        return $cached_data;
    }

    try {
        $trend_data = kssl_get_trend_data_optimized($where_clauses, $params, $timezone);

        // 30秒間キャッシュ
        set_transient($cache_key, $trend_data, 30);

        return $trend_data;
    } catch (Exception $e) {
        kssl_log_error('Optimized trend data error: ' . $e->getMessage());
        return ['dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone];
    }
}

/**
 * トレンドデータの最適化された取得
 */
function kssl_get_trend_data_optimized($where_clauses, $params, $timezone = 'UTC') {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();

    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    // インデックスを活用した日付変換
    // access_time にインデックスがある前提で、DATE関数をなるべく避ける
    $date_expression = "DATE(access_time)";

    // タイムゾーン変換が必要な場合のみ適用
    if ($timezone && $timezone !== 'UTC') {
        try {
            $utc_tz = new DateTimeZone('UTC');
            $target_tz = new DateTimeZone($timezone);
            $dummy_date = new DateTime('now', $utc_tz);
            $offset_seconds = $target_tz->getOffset($dummy_date);

            if ($offset_seconds != 0) {
                // インデックスを活用できるよう、可能な限りシンプルに
                $date_expression = "DATE(DATE_ADD(access_time, INTERVAL {$offset_seconds} SECOND))";
            }
        } catch (Exception $e) {
            kssl_log_error('Timezone error in optimized trend data: ' . $e->getMessage());
        }
    }

    // サンプリングを使用してデータ量を削減（大量データの場合）
    $total_count_query = "SELECT COUNT(*) FROM {$table_name} {$where_sql}";
    $total_count = $wpdb->get_var(
        empty($params) ? $total_count_query : $wpdb->prepare($total_count_query, ...$params)
    );

    // 10万件を超える場合はサンプリングを使用
    $sampling_clause = "";
    if ($total_count > 100000) {
        // 10%のサンプリング
        if (!empty($where_sql)) {
            $sampling_clause = " AND MOD(id, 10) = 0";
        } else {
            $sampling_clause = " WHERE MOD(id, 10) = 0";
        }
        $multiplier = 10;
    } else {
        $multiplier = 1;
    }

    // 日次アクセス数を取得
    $query = "SELECT {$date_expression} as date, COUNT(*) * {$multiplier} as count
              FROM {$table_name} {$where_sql} {$sampling_clause}
              GROUP BY date
              ORDER BY date ASC";

    $results = $wpdb->get_results(
        empty($params) ? $query : $wpdb->prepare($query, ...$params),
        ARRAY_A
    );

    if (empty($results)) {
        return ['dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone];
    }

    $dates = [];
    $counts = [];
    $total = 0;

    foreach ($results as $row) {
        $dates[] = $row['date'];
        $counts[] = (int)$row['count'];
        $total += (int)$row['count'];
    }

    // トレンド計算
    $trend = 'stable';
    $change_percentage = 0;

    if (count($counts) >= 4) {
        $mid_point = floor(count($counts) / 2);
        $first_half_avg = array_sum(array_slice($counts, 0, $mid_point)) / $mid_point;
        $second_half_avg = array_sum(array_slice($counts, $mid_point)) / (count($counts) - $mid_point);

        $change_percentage = ($second_half_avg - $first_half_avg) / max($first_half_avg, 1) * 100;

        if ($change_percentage > 10) {
            $trend = 'increasing';
        } elseif ($change_percentage < -10) {
            $trend = 'decreasing';
        }
    }

    return [
        'dates' => $dates,
        'counts' => $counts,
        'total' => $total,
        'trend' => $trend,
        'change_percentage' => round($change_percentage, 1),
        'timezone' => $timezone
    ];
}

/**
 * データベースインデックスの作成/最適化
 */
function kssl_optimize_database_indexes() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();

    // 既存のインデックスを確認
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
    $existing_indexes = [];
    foreach ($indexes as $index) {
        $existing_indexes[] = $index->Key_name;
    }

    // 必要なインデックスを追加
    $required_indexes = [
        'idx_access_time' => 'access_time',
        'idx_user_agent' => 'user_agent(100)',
        'idx_country_code' => 'country_code',
        'idx_visit_type' => 'visit_type',
        'idx_status_code' => 'status_code',
        'idx_visitor_id' => 'visitor_id_cookie(100)',
        'idx_ip_address' => 'ip_address',
        'idx_composite_filter' => 'access_time, visit_type, status_code'
    ];

    foreach ($required_indexes as $index_name => $columns) {
        if (!in_array($index_name, $existing_indexes)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX {$index_name} ({$columns})");
        }
    }
}