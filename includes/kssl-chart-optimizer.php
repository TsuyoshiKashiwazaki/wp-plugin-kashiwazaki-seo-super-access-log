<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KSSL_AGGREGATE_CACHE_TTL', 10 * MINUTE_IN_SECONDS );

/**
 * Cache key for aggregates: filter SQL, parameters, extra context and the cache generation
 * (bumped on deletion, import, migration and settings changes).
 */
function kssl_aggregate_cache_key( $prefix, array $where_clauses, array $params, $extra = '' ) {
    return 'kssl_' . $prefix . '_' . md5( serialize( $where_clauses ) . serialize( $params ) . '|' . $extra . '|' . kssl_cache_generation() );
}

/**
 * Cached aggregate. Only one request recomputes a missing entry (30 s lock in the options table);
 * others return the last computed value for the same filter if there is one, otherwise compute too.
 */
function kssl_aggregate_remember( $prefix, array $where_clauses, array $params, $extra, callable $compute ) {
    $key = kssl_aggregate_cache_key( $prefix, $where_clauses, $params, $extra );
    if ( ! kssl_force_refresh_requested() ) {
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }
    }
    $last_key = 'kssl_last_' . $prefix . '_' . md5( serialize( $where_clauses ) . serialize( $params ) . '|' . $extra );
    $lock = 'kssl_agglock_' . md5( $key );
    $locked = add_option( $lock, time(), '', 'no' );
    if ( ! $locked ) {
        $since = (int) get_option( $lock, 0 );
        if ( time() - $since < 30 ) {
            $last = get_transient( $last_key );
            if ( $last !== false ) {
                return $last;
            }
        } else {
            update_option( $lock, time(), 'no' );
            $locked = true;
        }
    }
    try {
        $value = $compute();
        set_transient( $key, $value, KSSL_AGGREGATE_CACHE_TTL );
        set_transient( $last_key, $value, DAY_IN_SECONDS );
        return $value;
    } finally {
        if ( $locked ) {
            delete_option( $lock );
        }
    }
}

function kssl_force_refresh_requested() {
    return isset( $_GET['force_refresh'] ) && current_user_can( 'manage_options' );
}

/**
 * Referer domain expression: the stored column on the lightweight schema, otherwise the same
 * expression computed per row (identical results).
 */
function kssl_referer_domain_sql() {
    return kssl_schema_is_lean() ? 'referer_host' : "SUBSTRING_INDEX(SUBSTRING_INDEX(referer_url, '//', -1), '/', 1)";
}

/**
 * Chart data (cached). The old MOD(id, 10) "sampling" is gone: it read every row anyway and
 * made the numbers approximate.
 */
function kssl_get_optimized_chart_data( $where_clauses, $params, $total_items ) {
    $where_clauses = is_array( $where_clauses ) ? $where_clauses : [];
    $params = is_array( $params ) ? $params : [];

    $max_chart_records = intval( get_option( KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT ) );
    if ( $max_chart_records > 0 && $total_items > $max_chart_records ) {
        return kssl_generate_disabled_chart_data_optimized( $total_items, $max_chart_records );
    }

    return kssl_aggregate_remember( 'chart', $where_clauses, $params, (string) $total_items, function () use ( $where_clauses, $params, $total_items ) {
        return kssl_get_all_charts_optimized( $where_clauses, $params, kssl_get_log_table_name_func(), $total_items );
    } );
}

function kssl_prepare_if_needed( $sql, array $params ) {
    global $wpdb;
    return empty( $params ) ? $sql : $wpdb->prepare( $sql, $params );
}

/**
 * All charts. $total_items is the already computed row count for the same filter, so no chart
 * runs its own COUNT(*).
 */
function kssl_get_all_charts_optimized( $where_clauses, $params, $table_name, $total_items ) {
    global $wpdb;
    $where_sql = empty( $where_clauses ) ? '' : ' WHERE ' . implode( ' AND ', $where_clauses );
    $chart_targets = [
        'user_agent'   => __( 'ユーザーエージェント', 'kashiwazaki-seo-super-access-log' ),
        'country_code' => __( '国・地域', 'kashiwazaki-seo-super-access-log' ),
        'visit_type'   => __( '訪問タイプ', 'kashiwazaki-seo-super-access-log' ),
        'status_code'  => __( 'ステータスコード', 'kashiwazaki-seo-super-access-log' ),
    ];
    $all = [];
    foreach ( $chart_targets as $key => $label ) {
        $sql = "SELECT {$key} AS item, COUNT(*) AS count FROM {$table_name} {$where_sql} GROUP BY {$key} ORDER BY count DESC LIMIT %d";
        $results = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ 20 ] ) ) );
        $all[ $key ] = kssl_format_chart_data_optimized( (array) $results, (int) $total_items, $label, $key );
    }
    $all['referer_domain'] = kssl_get_referer_chart_data_optimized( $where_clauses, $params, $table_name, __( 'リファラードメイン', 'kashiwazaki-seo-super-access-log' ) );
    return $all;
}

/**
 * Referer domains; the self-site exclusion is unchanged (referer_url NOT LIKE '%host%').
 */
function kssl_get_referer_chart_data_optimized( $where_clauses, $params, $table_name, $label ) {
    global $wpdb;
    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $where_clauses[] = "referer_url IS NOT NULL AND referer_url != ''";
    if ( $home_host ) {
        $where_clauses[] = 'referer_url NOT LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $home_host ) . '%';
    }
    $where_sql = ' WHERE ' . implode( ' AND ', $where_clauses );
    $domain = kssl_referer_domain_sql();
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT {$domain} AS item, COUNT(*) AS count FROM {$table_name} {$where_sql} GROUP BY item ORDER BY count DESC LIMIT %d",
        array_merge( $params, [ 20 ] )
    ) );
    $total = (int) $wpdb->get_var( kssl_prepare_if_needed( "SELECT COUNT(*) FROM {$table_name} {$where_sql}", $params ) );
    return kssl_format_chart_data_optimized( (array) $results, $total, $label, 'referer_domain' );
}

function kssl_format_chart_data_optimized( $results, $total_count, $label, $chart_key = '' ) {
    $chart_labels = [];
    $chart_counts = [];
    $list_data = [];
    $sum_top_counts = 0;
    $notice = '';
    if ( $chart_key === 'country_code' && ! get_option( KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY, false ) ) {
        $notice = '国・地域データを取得するには、設定で「国コード取得を有効化」にチェックを入れてください。';
    }
    $limit = min( count( $results ), 10 );
    for ( $i = 0; $i < $limit; $i++ ) {
        $item = $results[ $i ];
        $chart_labels[] = $item->item ?: '(不明)';
        $count = intval( $item->count );
        $chart_counts[] = $count;
        $sum_top_counts += $count;
        $list_data[] = [ 'item' => $item->item ?: '(不明)', 'count' => $count ];
    }
    if ( $sum_top_counts < $total_count ) {
        $chart_labels[] = __( 'その他', 'kashiwazaki-seo-super-access-log' );
        $chart_counts[] = $total_count - $sum_top_counts;
    }
    return [
        'labels'   => $chart_labels,
        'data'     => $chart_counts,
        'list'     => $list_data,
        'total'    => $total_count,
        'title'    => $label,
        'disabled' => false,
        'notice'   => $notice,
    ];
}

function kssl_generate_disabled_chart_data_optimized( $total_items, $max_chart_records ) {
    $chart_targets = [
        'user_agent'     => __( 'ユーザーエージェント', 'kashiwazaki-seo-super-access-log' ),
        'country_code'   => __( '国・地域', 'kashiwazaki-seo-super-access-log' ),
        'visit_type'     => __( '訪問タイプ', 'kashiwazaki-seo-super-access-log' ),
        'status_code'    => __( 'ステータスコード', 'kashiwazaki-seo-super-access-log' ),
        'referer_domain' => __( 'リファラードメイン', 'kashiwazaki-seo-super-access-log' ),
    ];
    $all = [];
    foreach ( $chart_targets as $key => $label ) {
        $all[ $key ] = [
            'labels'   => [],
            'data'     => [],
            'list'     => [],
            'total'    => 0,
            'title'    => $label,
            'disabled' => true,
            'message'  => sprintf(
                __( 'パフォーマンスのためチャートは無効になっています（現在: %s ログ、上限: %s）。設定で調整できます。', 'kashiwazaki-seo-super-access-log' ),
                number_format_i18n( $total_items ),
                number_format_i18n( $max_chart_records )
            ),
        ];
    }
    return $all;
}

/**
 * Daily trend (cached, no sampling).
 */
function kssl_get_optimized_trend_data( $where_clauses, $params, $timezone = 'UTC' ) {
    $where_clauses = is_array( $where_clauses ) ? $where_clauses : [];
    $params = is_array( $params ) ? $params : [];
    try {
        return kssl_aggregate_remember( 'trend', $where_clauses, $params, (string) $timezone, function () use ( $where_clauses, $params, $timezone ) {
            return kssl_get_trend_data_optimized( $where_clauses, $params, $timezone );
        } );
    } catch ( Exception $e ) {
        kssl_log_error( 'Optimized trend data error: ' . $e->getMessage() );
        return [ 'dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone ];
    }
}

function kssl_get_trend_data_optimized( $where_clauses, $params, $timezone = 'UTC' ) {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $where_sql = empty( $where_clauses ) ? '' : ' WHERE ' . implode( ' AND ', $where_clauses );
    $date_expression = 'DATE(access_time)';
    if ( $timezone && $timezone !== 'UTC' ) {
        try {
            $offset_seconds = ( new DateTimeZone( $timezone ) )->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) );
            if ( $offset_seconds != 0 ) {
                $date_expression = 'DATE(DATE_ADD(access_time, INTERVAL ' . (int) $offset_seconds . ' SECOND))';
            }
        } catch ( Exception $e ) {
            kssl_log_error( 'Timezone error in optimized trend data: ' . $e->getMessage() );
        }
    }
    $results = $wpdb->get_results(
        kssl_prepare_if_needed( "SELECT {$date_expression} AS date, COUNT(*) AS count FROM {$table_name} {$where_sql} GROUP BY date ORDER BY date ASC", $params ),
        ARRAY_A
    );
    if ( empty( $results ) ) {
        return [ 'dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone ];
    }
    $dates = [];
    $counts = [];
    foreach ( $results as $row ) {
        $dates[] = $row['date'];
        $counts[] = (int) $row['count'];
    }
    $trend = 'stable';
    $change_percentage = 0;
    if ( count( $counts ) >= 4 ) {
        $mid = (int) floor( count( $counts ) / 2 );
        $first = array_sum( array_slice( $counts, 0, $mid ) ) / $mid;
        $second = array_sum( array_slice( $counts, $mid ) ) / ( count( $counts ) - $mid );
        $change_percentage = ( $second - $first ) / max( $first, 1 ) * 100;
        if ( $change_percentage > 10 ) {
            $trend = 'increasing';
        } elseif ( $change_percentage < -10 ) {
            $trend = 'decreasing';
        }
    }
    return [
        'dates'             => $dates,
        'counts'            => $counts,
        'total'             => array_sum( $counts ),
        'trend'             => $trend,
        'change_percentage' => round( $change_percentage, 1 ),
        'timezone'          => $timezone,
    ];
}
