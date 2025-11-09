<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ログ削除機能を提供するクラス
 */
class KSSL_Log_Deletion {
    
    /**
     * 削除条件に基づいてログ件数をカウント
     */
    public static function count_logs_for_deletion($filters) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        $where_clause = self::build_deletion_where_clause($filters);
        $params = self::build_deletion_params($filters);
        
        $query = "SELECT COUNT(*) FROM {$table_name}";
        if (!empty($where_clause)) {
            $query .= " WHERE {$where_clause}";
        }
        
        if (!empty($params)) {
            $count = $wpdb->get_var($wpdb->prepare($query, $params));
        } else {
            $count = $wpdb->get_var($query);
        }
        
        return $count !== null ? intval($count) : false;
    }
    
    /**
     * 条件に基づいてログを削除
     */
    public static function delete_logs_with_filters($filters) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        // 実行時間を無制限に設定
        set_time_limit(0);
        
        $where_clause = self::build_deletion_where_clause($filters);
        $params = self::build_deletion_params($filters);
        
        // 削除前に件数を確認
        $count_query = "SELECT COUNT(*) FROM {$table_name}";
        if (!empty($where_clause)) {
            $count_query .= " WHERE {$where_clause}";
        }
        
        if (!empty($params)) {
            $count = $wpdb->get_var($wpdb->prepare($count_query, $params));
        } else {
            $count = $wpdb->get_var($count_query);
        }
        
        if ($count === null || $count == 0) {
            return [
                'success' => true,
                'message' => '削除対象のログはありませんでした。',
                'deleted' => 0
            ];
        }
        
        // バッチ削除の実行
        $batch_size = 1000;
        $total_deleted = 0;
        
        do {
            $delete_query = "DELETE FROM {$table_name}";
            if (!empty($where_clause)) {
                $delete_query .= " WHERE {$where_clause}";
            }
            $delete_query .= " LIMIT {$batch_size}";
            
            if (!empty($params)) {
                $deleted = $wpdb->query($wpdb->prepare($delete_query, $params));
            } else {
                $deleted = $wpdb->query($delete_query);
            }
            
            if ($deleted === false) {
                return [
                    'success' => false,
                    'message' => 'データベースエラーが発生しました: ' . $wpdb->last_error
                ];
            }
            
            $total_deleted += $deleted;
            
            // 短時間の待機でCPU負荷を軽減
            if ($deleted > 0) {
                usleep(50000); // 0.05秒
            }
            
        } while ($deleted >= $batch_size);
        
        return [
            'success' => true,
            'message' => "{$total_deleted} 件のログを削除しました。",
            'deleted' => $total_deleted
        ];
    }
    
    /**
     * すべてのログを削除
     */
    public static function delete_all_logs() {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        // 実行時間を無制限に設定
        set_time_limit(0);
        
        // まず総件数を取得
        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        if ($total_count === null || $total_count == 0) {
            return [
                'success' => true,
                'message' => '削除するログはありませんでした。',
                'deleted' => 0
            ];
        }
        
        // TRUNCATE が使える場合はそれを使用（高速）
        $result = $wpdb->query("TRUNCATE TABLE {$table_name}");
        
        if ($result === false) {
            // TRUNCATE が失敗した場合はDELETEを使用
            $batch_size = 5000;
            $total_deleted = 0;
            
            do {
                $deleted = $wpdb->query("DELETE FROM {$table_name} LIMIT {$batch_size}");
                
                if ($deleted === false) {
                    return [
                        'success' => false,
                        'message' => 'データベースエラーが発生しました: ' . $wpdb->last_error
                    ];
                }
                
                $total_deleted += $deleted;
                
                // 短時間の待機でCPU負荷を軽減
                if ($deleted > 0) {
                    usleep(100000); // 0.1秒
                }
                
            } while ($deleted >= $batch_size);
            
            return [
                'success' => true,
                'message' => "{$total_deleted} 件のログを削除しました。",
                'deleted' => $total_deleted
            ];
        } else {
            return [
                'success' => true,
                'message' => "{$total_count} 件のログを削除しました。",
                'deleted' => intval($total_count)
            ];
        }
    }
    
    /**
     * WHERE句を構築
     */
    private static function build_deletion_where_clause($filters) {
        $where_parts = [];
        
        // 期間による絞り込み
        if (!empty($filters['period_type'])) {
            if ($filters['period_type'] === 'older_than' && !empty($filters['older_than_date'])) {
                $where_parts[] = "access_time < %s";
            } elseif ($filters['period_type'] === 'date_range') {
                if (!empty($filters['date_from'])) {
                    $where_parts[] = "access_time >= %s";
                }
                if (!empty($filters['date_to'])) {
                    $where_parts[] = "access_time <= %s";
                }
            }
        }
        
        // ボットのみ
        if (!empty($filters['only_bots'])) {
            $where_parts[] = "is_bot = 1";
        }
        
        // エラーのみ
        if (!empty($filters['only_errors'])) {
            $where_parts[] = "status_code >= 400";
        }
        
        // 疑わしいアクセスのみ
        if (!empty($filters['only_suspicious'])) {
            $suspicious_keywords_raw = get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
            $suspicious_keywords = array_filter(array_map('trim', explode("\n", $suspicious_keywords_raw)));
            
            if (!empty($suspicious_keywords)) {
                $suspicious_conditions = [];
                foreach ($suspicious_keywords as $keyword) {
                    $suspicious_conditions[] = "request_uri LIKE %s";
                }
                if (!empty($suspicious_conditions)) {
                    $where_parts[] = "(" . implode(" OR ", $suspicious_conditions) . ")";
                }
            }
        }
        
        // 特定のIPアドレス
        if (!empty($filters['specific_ip'])) {
            $where_parts[] = "ip_address = %s";
        }
        
        return implode(' AND ', $where_parts);
    }
    
    /**
     * WHERE句のパラメータを構築
     */
    private static function build_deletion_params($filters) {
        $params = [];
        
        // 期間による絞り込み
        if (!empty($filters['period_type'])) {
            if ($filters['period_type'] === 'older_than' && !empty($filters['older_than_date'])) {
                $params[] = $filters['older_than_date'] . ' 23:59:59';
            } elseif ($filters['period_type'] === 'date_range') {
                if (!empty($filters['date_from'])) {
                    $params[] = $filters['date_from'] . ' 00:00:00';
                }
                if (!empty($filters['date_to'])) {
                    $params[] = $filters['date_to'] . ' 23:59:59';
                }
            }
        }
        
        // 疑わしいアクセスのみ（キーワードのパラメータ）
        if (!empty($filters['only_suspicious'])) {
            $suspicious_keywords_raw = get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
            $suspicious_keywords = array_filter(array_map('trim', explode("\n", $suspicious_keywords_raw)));
            
            foreach ($suspicious_keywords as $keyword) {
                $params[] = '%' . $keyword . '%';
            }
        }
        
        // 特定のIPアドレス
        if (!empty($filters['specific_ip'])) {
            $params[] = $filters['specific_ip'];
        }
        
        return $params;
    }
}

/**
 * ログ削除関数（互換性のため）
 */
function kssl_count_logs_for_deletion($filters) {
    return KSSL_Log_Deletion::count_logs_for_deletion($filters);
}

function kssl_delete_logs_with_filters($filters) {
    return KSSL_Log_Deletion::delete_logs_with_filters($filters);
}

function kssl_delete_all_logs() {
    return KSSL_Log_Deletion::delete_all_logs();
}