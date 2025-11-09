<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * パフォーマンス最適化関数群
 */
class KSSL_Performance {
    
    /**
     * 高速化されたログ取得クエリ
     * 
     * @param array $filters フィルター条件
     * @param int $limit 取得件数制限
     * @param int $offset オフセット
     * @param string $orderby ソート基準
     * @param string $order ソート順序
     * @return array ログデータ
     */
    public static function get_optimized_logs($filters = [], $limit = 50, $offset = 0, $orderby = 'access_time', $order = 'DESC') {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        $where_clauses = [];
        $params = [];
        
        // 日付フィルターを最優先で処理（インデックス効率化）
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            if (!empty($filters['date_from'])) {
                $where_clauses[] = "access_date >= %s";
                $params[] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $where_clauses[] = "access_date <= %s";
                $params[] = $filters['date_to'];
            }
        } else {
            // デフォルトで過去30日に制限（大量データ対策）
            $where_clauses[] = "access_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }
        
        // IPアドレスフィルター（ハッシュ使用）
        if (!empty($filters['ip_address_filter'])) {
            $where_clauses[] = "ip_hash = SHA2(%s, 256)";
            $params[] = $filters['ip_address_filter'];
        }
        
        // 訪問者フィルター（ハッシュ使用）
        if (!empty($filters['visitor_id_filter'])) {
            $where_clauses[] = "visitor_hash = SHA2(%s, 256)";
            $params[] = $filters['visitor_id_filter'];
        }
        
        // その他のフィルター
        if (!empty($filters['status_code_filter'])) {
            $where_clauses[] = "status_code = %d";
            $params[] = intval($filters['status_code_filter']);
        }
        
        if (!empty($filters['country_filter'])) {
            $where_clauses[] = "country_code = %s";
            $params[] = $filters['country_filter'];
        }
        
        if (!empty($filters['bot_filter'])) {
            if ($filters['bot_filter'] === 'bot') {
                $where_clauses[] = "is_bot = 1";
            } elseif ($filters['bot_filter'] === 'human') {
                $where_clauses[] = "is_bot = 0";
            }
        }
        
        if (!empty($filters['visit_type_filter'])) {
            $where_clauses[] = "visit_type = %s";
            $params[] = $filters['visit_type_filter'];
        }
        
        if (!empty($filters['source_filter'])) {
            $where_clauses[] = "source = %s";
            $params[] = $filters['source_filter'];
        }
        
        $where_clause = '';
        if (!empty($where_clauses)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // ソート設定
        $allowed_orderby = ['id', 'access_time', 'access_date', 'ip_address', 'status_code', 'country_code', 'visit_type', 'source'];
        $orderby = in_array($orderby, $allowed_orderby) ? $orderby : 'access_time';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        
        // メインクエリ
        $query = "SELECT 
                    id, access_time, ip_address, user_agent, request_uri, referer_url,
                    request_method, status_code, user_id, is_bot, visit_type, source,
                    visitor_id_cookie, country_code, navigation_type
                  FROM {$table_name} 
                  {$where_clause}
                  ORDER BY {$orderby} {$order}
                  LIMIT %d OFFSET %d";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $wpdb->get_results($wpdb->prepare($query, $params));
    }
    
    /**
     * 高速化されたログ件数取得
     */
    public static function get_optimized_log_count($filters = []) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        $where_clauses = [];
        $params = [];
        
        // 日付フィルター最優先
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            if (!empty($filters['date_from'])) {
                $where_clauses[] = "access_date >= %s";
                $params[] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $where_clauses[] = "access_date <= %s";
                $params[] = $filters['date_to'];
            }
        } else {
            $where_clauses[] = "access_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }
        
        // その他フィルター（get_optimized_logsと同じロジック）
        if (!empty($filters['ip_address_filter'])) {
            $where_clauses[] = "ip_hash = SHA2(%s, 256)";
            $params[] = $filters['ip_address_filter'];
        }
        
        if (!empty($filters['visitor_id_filter'])) {
            $where_clauses[] = "visitor_hash = SHA2(%s, 256)";
            $params[] = $filters['visitor_id_filter'];
        }
        
        if (!empty($filters['status_code_filter'])) {
            $where_clauses[] = "status_code = %d";
            $params[] = intval($filters['status_code_filter']);
        }
        
        if (!empty($filters['country_filter'])) {
            $where_clauses[] = "country_code = %s";
            $params[] = $filters['country_filter'];
        }
        
        if (!empty($filters['bot_filter'])) {
            if ($filters['bot_filter'] === 'bot') {
                $where_clauses[] = "is_bot = 1";
            } elseif ($filters['bot_filter'] === 'human') {
                $where_clauses[] = "is_bot = 0";
            }
        }
        
        if (!empty($filters['visit_type_filter'])) {
            $where_clauses[] = "visit_type = %s";
            $params[] = $filters['visit_type_filter'];
        }
        
        if (!empty($filters['source_filter'])) {
            $where_clauses[] = "source = %s";
            $params[] = $filters['source_filter'];
        }
        
        $where_clause = '';
        if (!empty($where_clauses)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        $query = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
        
        if (!empty($params)) {
            return intval($wpdb->get_var($wpdb->prepare($query, $params)));
        } else {
            return intval($wpdb->get_var($query));
        }
    }
    
    /**
     * 高速化されたチャート用データ取得
     */
    public static function get_chart_data($type = 'daily', $days = 30, $limit = null) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        $max_records = $limit ?: intval(get_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT));
        
        switch ($type) {
            case 'hourly':
                $query = "SELECT 
                            access_date as date,
                            access_hour as hour,
                            COUNT(*) as count,
                            COUNT(DISTINCT visitor_hash) as unique_visitors,
                            SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_count
                          FROM {$table_name} 
                          WHERE access_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                          GROUP BY access_date, access_hour
                          ORDER BY access_date DESC, access_hour DESC
                          LIMIT %d";
                return $wpdb->get_results($wpdb->prepare($query, $days, $max_records));
                
            case 'daily':
            default:
                $query = "SELECT 
                            access_date as date,
                            COUNT(*) as count,
                            COUNT(DISTINCT visitor_hash) as unique_visitors,
                            COUNT(DISTINCT ip_hash) as unique_ips,
                            SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_count,
                            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count
                          FROM {$table_name} 
                          WHERE access_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                          GROUP BY access_date
                          ORDER BY access_date DESC
                          LIMIT %d";
                return $wpdb->get_results($wpdb->prepare($query, $days, $max_records));
        }
    }
    
    /**
     * 高速化された統計データ取得
     */
    public static function get_statistics($days = 7) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        $query = "SELECT 
                    COUNT(*) as total_pageviews,
                    COUNT(DISTINCT visitor_hash) as unique_visitors,
                    COUNT(DISTINCT ip_hash) as unique_ips,
                    SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_visits,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count,
                    AVG(CASE WHEN is_bot = 0 THEN 1 ELSE NULL END) as avg_human_visits
                  FROM {$table_name} 
                  WHERE access_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)";
        
        return $wpdb->get_row($wpdb->prepare($query, $days));
    }
    
    /**
     * 高速化された国別統計
     */
    public static function get_country_stats($days = 30, $limit = 20) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        $query = "SELECT 
                    country_code,
                    COUNT(*) as count,
                    COUNT(DISTINCT visitor_hash) as unique_visitors
                  FROM {$table_name} 
                  WHERE access_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                    AND country_code IS NOT NULL
                    AND country_code != ''
                  GROUP BY country_code
                  ORDER BY count DESC
                  LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($query, $days, $limit));
    }
    
    /**
     * データベース最適化実行（旧バージョン - 互換性のために残す）
     */
    public static function optimize_database() {
        return self::optimize_database_complete();
    }

    /**
     * データベース完全最適化（インデックス作成 + テーブル最適化 + 統計更新）
     */
    public static function optimize_database_complete() {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();

        try {
            // 1. インデックスの作成/最適化
            if (function_exists('kssl_optimize_database_indexes')) {
                kssl_optimize_database_indexes();
            } else {
                // フォールバック: 最低限のインデックスを作成
                self::create_essential_indexes();
            }

            // 2. テーブル最適化（断片化解消、空き領域回収）
            $wpdb->query("OPTIMIZE TABLE {$table_name}");

            // 3. 統計情報更新（クエリオプティマイザー用）
            $wpdb->query("ANALYZE TABLE {$table_name}");

            // 4. 最終最適化時刻を保存
            update_option('kssl_last_optimization_time', time());

            return true;
        } catch (Exception $e) {
            error_log('KSSL Database optimization error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 必須インデックスの作成（フォールバック用）
     */
    private static function create_essential_indexes() {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
        $existing_indexes = [];
        foreach ($indexes as $index) {
            $existing_indexes[] = $index->Key_name;
        }

        $required_indexes = [
            'idx_access_time' => 'access_time',
            'idx_access_date' => 'access_date',
            'idx_country_code' => 'country_code',
            'idx_visit_type' => 'visit_type',
            'idx_status_code' => 'status_code',
        ];

        foreach ($required_indexes as $index_name => $columns) {
            if (!in_array($index_name, $existing_indexes)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD INDEX {$index_name} ({$columns})");
            }
        }
    }
}