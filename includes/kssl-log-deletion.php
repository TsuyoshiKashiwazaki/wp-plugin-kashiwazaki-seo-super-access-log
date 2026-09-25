<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Log deletion. Counting and deleting walk the table in id windows, a bounded slice per AJAX
 * request; the browser calls again with the returned cursor until done.
 */
class KSSL_Log_Deletion {

    const WINDOW = 50000;
    const BATCH = 1000;
    const TIME_BUDGET = 10;

    /**
     * One bounded step of counting or deleting.
     *
     * @param array  $filters Deletion conditions (same meaning as before).
     * @param string $mode    'count' or 'delete'.
     * @param int    $cursor  Last id already processed (0 at start).
     * @param int    $max_id  Upper id fixed at start (0 at start: read now).
     * @return array { success, done, cursor, max_id, matched, message? }
     */
    public static function step( $filters, $mode, $cursor, $max_id ) {
        global $wpdb;
        $filters = self::normalize_flags( is_array( $filters ) ? $filters : [] );
        if ( ! empty( $filters['_invalid_date'] ) ) {
            return [ 'success' => false, 'message' => __( '日付の形式が正しくありません (YYYY-MM-DD)。', 'kashiwazaki-seo-super-access-log' ) ];
        }
        if ( $mode === 'delete' && ! kssl_job_lock() ) {
            return [ 'success' => false, 'message' => __( '別の処理が実行中です。少し待ってから再度お試しください。', 'kashiwazaki-seo-super-access-log' ) ];
        }
        try {
            if ( kssl_migration_blocks_writes() ) {
                return [ 'success' => false, 'message' => kssl_migration_block_message() ];
            }
            $table_name = kssl_get_log_table_name_func();
            $cursor = max( 0, (int) $cursor );
            $max_id = (int) $max_id;
            if ( $max_id <= 0 ) {
                $max_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table_name}" );
            }
            $where = self::build_deletion_where_clause( $filters );
            $params = self::build_deletion_params( $filters );
            $cond = $where === '' ? '' : " AND ({$where})";
            $matched = 0;
            $start = microtime( true );

            while ( $cursor < $max_id && microtime( true ) - $start < self::TIME_BUDGET ) {
                $upper = min( $cursor + self::WINDOW, $max_id );
                $window_params = array_merge( [ $cursor, $upper ], $params );
                if ( $mode === 'count' ) {
                    $n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE id > %d AND id <= %d{$cond}", $window_params ) );
                    if ( $n === null ) {
                        return [ 'success' => false, 'message' => 'データベースエラー: ' . $wpdb->last_error ];
                    }
                    $matched += (int) $n;
                    $cursor = $upper;
                    continue;
                }
                // delete: collect ids (and their times) inside the window, then delete by primary key
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, access_time FROM {$table_name} WHERE id > %d AND id <= %d{$cond} ORDER BY id LIMIT " . self::BATCH,
                    $window_params
                ) );
                if ( $rows === null || $wpdb->last_error !== '' ) {
                    return [ 'success' => false, 'message' => 'データベースエラー: ' . $wpdb->last_error ];
                }
                if ( empty( $rows ) ) {
                    $cursor = $upper;
                    continue;
                }
                $ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
                // The aggregates of these hours stop being used before any row changes.
                if ( ! kssl_agg_mark_dirty( wp_list_pluck( $rows, 'access_time' ) ) ) {
                    return [ 'success' => false, 'message' => __( '集計の更新に失敗したため、削除を中止しました。少し待ってから再度お試しください。', 'kashiwazaki-seo-super-access-log' ) ];
                }
                $deleted = $wpdb->query( "DELETE FROM {$table_name} WHERE id IN (" . implode( ',', $ids ) . ')' );
                if ( $deleted === false ) {
                    return [ 'success' => false, 'message' => 'データベースエラー: ' . $wpdb->last_error ];
                }
                $matched += (int) $deleted;
                $cursor = count( $ids ) < self::BATCH ? $upper : end( $ids );
            }
            if ( $mode === 'delete' && $matched > 0 ) {
                kssl_bump_cache_generation();
            }
            return [
                'success' => true,
                'done'    => $cursor >= $max_id,
                'cursor'  => $cursor,
                'max_id'  => $max_id,
                'matched' => $matched,
            ];
        } finally {
            if ( $mode === 'delete' ) {
                kssl_job_unlock();
            }
        }
    }

    /**
     * Delete every log row. TRUNCATE is one statement; if it fails, fall back to windowed deletes
     * (the caller continues with step()).
     */
    public static function delete_all_logs() {
        global $wpdb;
        if ( ! kssl_job_lock() ) {
            return [ 'success' => false, 'message' => __( '別の処理が実行中です。少し待ってから再度お試しください。', 'kashiwazaki-seo-super-access-log' ) ];
        }
        try {
            if ( kssl_migration_blocks_writes() ) {
                return [ 'success' => false, 'message' => kssl_migration_block_message() ];
            }
            $table_name = kssl_get_log_table_name_func();
            if ( ! kssl_agg_reset_all() ) {
                return [ 'success' => false, 'message' => __( '集計の更新に失敗したため、削除を中止しました。少し待ってから再度お試しください。', 'kashiwazaki-seo-super-access-log' ) ];
            }
            if ( $wpdb->query( "TRUNCATE TABLE {$table_name}" ) !== false ) {
                kssl_bump_cache_generation();
                return [ 'success' => true, 'done' => true, 'message' => __( 'すべてのログを削除しました。', 'kashiwazaki-seo-super-access-log' ) ];
            }
            return [ 'success' => true, 'done' => false, 'fallback' => true ];
        } finally {
            kssl_job_unlock();
        }
    }

    /**
     * Checkbox flags as booleans. Browsers / jQuery send '0', 'false' or '' for an unchecked box,
     * and !empty('false') is true in PHP; FILTER_VALIDATE_BOOLEAN reads "1", "true", "on", "yes"
     * as true and "0", "false", "off", "no", "" as false (php.net filter constants).
     */
    private static function normalize_flags( array $filters ) {
        foreach ( [ 'only_bots', 'only_errors', 'only_suspicious' ] as $flag ) {
            $filters[ $flag ] = isset( $filters[ $flag ] ) && filter_var( $filters[ $flag ], FILTER_VALIDATE_BOOLEAN );
        }
        // Dates are days in the site's timezone; access_time is UTC. "Older than D" = before D
        // 00:00, a range covers whole days (to < the next day's 00:00).
        foreach ( [ '_before', '_from', '_to_excl', '_invalid_date' ] as $k ) {
            unset( $filters[ $k ] );
        }
        $type = $filters['period_type'] ?? '';
        $dates = [];
        if ( $type === 'older_than' && ! empty( $filters['older_than_date'] ) ) {
            $dates['_before'] = kssl_site_date_to_utc( $filters['older_than_date'] );
        } elseif ( $type === 'date_range' ) {
            if ( ! empty( $filters['date_from'] ) ) {
                $dates['_from'] = kssl_site_date_to_utc( $filters['date_from'] );
            }
            if ( ! empty( $filters['date_to'] ) ) {
                $next = kssl_site_date_to_utc( $filters['date_to'] ) !== null ? gmdate( 'Y-m-d', strtotime( $filters['date_to'] . ' +1 day' ) ) : '';
                $dates['_to_excl'] = $next !== '' ? kssl_site_date_to_utc( $next ) : null;
            }
        }
        foreach ( $dates as $k => $v ) {
            if ( $v === null ) {
                $filters['_invalid_date'] = true;
            } else {
                $filters[ $k ] = $v;
            }
        }
        return $filters;
    }

    /**
     * WHERE clause (unchanged semantics, including "suspicious only" = request_uri LIKE per keyword).
     */
    private static function build_deletion_where_clause( $filters ) {
        $where_parts = [];
        if ( isset( $filters['_before'] ) ) {
            $where_parts[] = 'access_time < %s';
        }
        if ( isset( $filters['_from'] ) ) {
            $where_parts[] = 'access_time >= %s';
        }
        if ( isset( $filters['_to_excl'] ) ) {
            $where_parts[] = 'access_time < %s';
        }
        if ( ! empty( $filters['only_bots'] ) ) {
            $where_parts[] = 'is_bot = 1';
        }
        if ( ! empty( $filters['only_errors'] ) ) {
            $where_parts[] = 'status_code >= 400';
        }
        if ( ! empty( $filters['only_suspicious'] ) ) {
            $keywords = array_filter( array_map( 'trim', explode( "\n", get_option( KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS ) ) ) );
            if ( ! empty( $keywords ) ) {
                $where_parts[] = '(' . implode( ' OR ', array_fill( 0, count( $keywords ), 'request_uri LIKE %s' ) ) . ')';
            }
        }
        if ( ! empty( $filters['specific_ip'] ) ) {
            $where_parts[] = 'ip_address = %s';
        }
        return implode( ' AND ', $where_parts );
    }

    private static function build_deletion_params( $filters ) {
        $params = [];
        foreach ( [ '_before', '_from', '_to_excl' ] as $bound ) {
            if ( isset( $filters[ $bound ] ) ) {
                $params[] = $filters[ $bound ];
            }
        }
        if ( ! empty( $filters['only_suspicious'] ) ) {
            $keywords = array_filter( array_map( 'trim', explode( "\n", get_option( KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS ) ) ) );
            foreach ( $keywords as $keyword ) {
                $params[] = '%' . $keyword . '%';
            }
        }
        if ( ! empty( $filters['specific_ip'] ) ) {
            $params[] = $filters['specific_ip'];
        }
        return $params;
    }
}

function kssl_delete_all_logs() {
    return KSSL_Log_Deletion::delete_all_logs();
}
