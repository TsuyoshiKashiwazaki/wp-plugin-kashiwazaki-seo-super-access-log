<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSV インポート・エクスポート機能を提供するクラス
 */
class KSSL_CSV_Handler {
    
    /**
     * CSVエクスポート実行
     */
    public static function export_csv($filters = []) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        // ファイル名を生成
        $filename = 'kssl-logs-export-' . date('Y-m-d-H-i-s') . '.csv';
        
        // HTTPヘッダーを設定
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // BOMを追加（Excelでの文字化け防止）
        echo "\xEF\xBB\xBF";
        
        $handle = fopen('php://output', 'w');
        
        // カラム定義を取得
        $all_columns = kssl_get_all_column_definitions();
        $column_keys = array_keys($all_columns);
        $header_labels = array_values($all_columns);
        
        // CSVヘッダーを出力
        fputcsv($handle, $header_labels);
        
        // 実行時間を無制限に設定
        set_time_limit(0);
        
        // フィルター条件を構築
        $where_clause = self::build_where_clause($filters);
        $params = self::build_where_params($filters);
        
        // バッチサイズを設定
        $limit = 1000;
        $offset = 0;
        
        while (true) {
            $query = "SELECT * FROM {$table_name}";
            if (!empty($where_clause)) {
                $query .= " WHERE {$where_clause}";
            }
            $query .= " ORDER BY id ASC LIMIT %d OFFSET %d";
            
            $prepare_params = array_merge($params, [$limit, $offset]);
            $results = $wpdb->get_results(
                $wpdb->prepare($query, $prepare_params),
                ARRAY_A
            );
            
            if (empty($results)) {
                break;
            }
            
            foreach ($results as $row) {
                $ordered_row = [];
                foreach ($column_keys as $key) {
                    $ordered_row[] = $row[$key] ?? '';
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
     * CSVインポート実行
     */
    public static function import_csv($file_path, $options = []) {
        global $wpdb;

        if (!file_exists($file_path)) {
            return ['success' => false, 'message' => __('File not found.', 'kashiwazaki-seo-super-access-log')];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => __('Could not open file.', 'kashiwazaki-seo-super-access-log')];
        }

        $table_name = kssl_get_log_table_name_func();
        $all_columns = kssl_get_all_column_definitions();
        $column_keys = array_keys($all_columns);

        // 最初の行をヘッダーとして読み取り
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return ['success' => false, 'message' => __('Could not read CSV header.', 'kashiwazaki-seo-super-access-log')];
        }

        // BOM を削除（もしあれば）
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        // ヘッダーとカラムのマッピング
        $column_mapping = self::create_column_mapping($header, $all_columns);
        if (empty($column_mapping)) {
            fclose($handle);
            return ['success' => false, 'message' => __('No valid columns found in CSV.', 'kashiwazaki-seo-super-access-log')];
        }

        // ファイルの総行数を取得（進捗計算用）
        $total_lines = 0;
        while (fgetcsv($handle) !== FALSE) {
            $total_lines++;
        }
        rewind($handle);
        fgetcsv($handle); // ヘッダーをスキップ

        $imported_count = 0;
        $skipped_count = 0;
        $error_count = 0;
        $line_number = 1;
        $error_details = [];
        $skip_details = [];

        // インポートオプション
        $skip_duplicates = isset($options['skip_duplicates']) ? $options['skip_duplicates'] : true;
        $validate_data = isset($options['validate_data']) ? $options['validate_data'] : true;
        $progress_callback = isset($options['progress_callback']) ? $options['progress_callback'] : null;

        // 実行時間を無制限に設定
        set_time_limit(0);

        // バッチ処理用の配列
        $batch_data = [];
        $batch_size = 100;
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            $line_number++;

            if (count($data) < count($column_mapping)) {
                $skipped_count++;
                $skip_details[] = sprintf(__('行 %d: カラム数が不足しています（必要: %d, 実際: %d）', 'kashiwazaki-seo-super-access-log'), $line_number, count($column_mapping), count($data));
                continue;
            }
            
            // データをマッピング
            $row_data = [];
            foreach ($column_mapping as $csv_index => $db_column) {
                $value = isset($data[$csv_index]) ? trim($data[$csv_index]) : '';
                $row_data[$db_column] = $value;
            }
            
            // データ検証
            if ($validate_data) {
                $validation_result = self::validate_row_data($row_data);
                if (!$validation_result['valid']) {
                    $skipped_count++;
                    $skip_details[] = sprintf(__('行 %d: データ検証エラー - %s', 'kashiwazaki-seo-super-access-log'), $line_number, $validation_result['error'] ?? '不明なエラー');
                    continue;
                }
                $row_data = $validation_result['data'];
            }
            
            // 重複チェック
            if ($skip_duplicates && self::is_duplicate_entry($row_data)) {
                $skipped_count++;
                $skip_details[] = sprintf(__('行 %d: 重複データ（同じアクセス時刻・IPアドレス・User-Agent・URIのレコードが既に存在）', 'kashiwazaki-seo-super-access-log'), $line_number);
                continue;
            }
            
            $batch_data[] = $row_data;

            // バッチサイズに達したら挿入
            if (count($batch_data) >= $batch_size) {
                $result = self::insert_batch_data($batch_data, $line_number - count($batch_data) + 1);
                $imported_count += $result['success'];
                $error_count += $result['error'];
                if (!empty($result['errors'])) {
                    $error_details = array_merge($error_details, $result['errors']);
                }
                $batch_data = [];

                // 進捗コールバックを呼び出し
                if ($progress_callback && is_callable($progress_callback)) {
                    $processed_lines = $line_number - 1;
                    $progress = $total_lines > 0 ? min(99, floor(($processed_lines / $total_lines) * 100)) : 50;
                    call_user_func($progress_callback, [
                        'total_lines' => $total_lines,
                        'processed_lines' => $processed_lines,
                        'imported_count' => $imported_count,
                        'skipped_count' => $skipped_count,
                        'error_count' => $error_count,
                        'progress' => $progress
                    ]);
                }
            }
        }

        // 残りのデータを挿入
        if (!empty($batch_data)) {
            $result = self::insert_batch_data($batch_data, $line_number - count($batch_data) + 1);
            $imported_count += $result['success'];
            $error_count += $result['error'];
            if (!empty($result['errors'])) {
                $error_details = array_merge($error_details, $result['errors']);
            }
        }
        
        fclose($handle);

        // 成功判定：少なくとも1件インポートされ、エラーがない場合のみ成功
        $is_success = ($imported_count > 0 && $error_count === 0);

        // メッセージを構築
        if ($imported_count === 0 && $error_count === 0 && $skipped_count === 0) {
            $message = __('❌ インポート失敗: CSVファイルに有効なデータがありませんでした。', 'kashiwazaki-seo-super-access-log');
        } elseif ($imported_count === 0 && ($error_count > 0 || $skipped_count > 0)) {
            $message = sprintf(
                __('❌ インポート失敗: %d件のレコードが処理されましたが、すべてエラーまたはスキップされました。（スキップ: %d件, エラー: %d件）', 'kashiwazaki-seo-super-access-log'),
                $skipped_count + $error_count,
                $skipped_count,
                $error_count
            );
        } elseif ($error_count > 0) {
            $message = sprintf(
                __('⚠️ 一部インポート成功: %d件をインポートしましたが、%d件のエラーが発生しました。（スキップ: %d件）', 'kashiwazaki-seo-super-access-log'),
                $imported_count,
                $error_count,
                $skipped_count
            );
        } else {
            $message = sprintf(
                __('✓ インポート成功: %d件のレコードをインポートしました。（スキップ: %d件）', 'kashiwazaki-seo-super-access-log'),
                $imported_count,
                $skipped_count
            );
        }

        // 詳細情報を追加（最大10件まで）
        $details = [];
        if (!empty($error_details)) {
            $details[] = "\n\n【エラー詳細】";
            $error_limit = min(10, count($error_details));
            for ($i = 0; $i < $error_limit; $i++) {
                $details[] = $error_details[$i];
            }
            if (count($error_details) > 10) {
                $details[] = sprintf(__('...他 %d件のエラー', 'kashiwazaki-seo-super-access-log'), count($error_details) - 10);
            }
        }

        if (!empty($skip_details)) {
            $details[] = "\n\n【スキップ詳細】";
            $skip_limit = min(10, count($skip_details));
            for ($i = 0; $i < $skip_limit; $i++) {
                $details[] = $skip_details[$i];
            }
            if (count($skip_details) > 10) {
                $details[] = sprintf(__('...他 %d件のスキップ', 'kashiwazaki-seo-super-access-log'), count($skip_details) - 10);
            }
        }

        if (!empty($details)) {
            $message .= implode("\n", $details);
        }

        return [
            'success' => $is_success,
            'message' => $message,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'errors' => $error_count,
            'error_details' => $error_details,
            'skip_details' => $skip_details
        ];
    }
    
    /**
     * WHERE句を構築
     */
    private static function build_where_clause($filters) {
        $where_parts = [];

        if (!empty($filters['date_from'])) {
            $where_parts[] = "access_time >= %s";
        }
        if (!empty($filters['date_to'])) {
            $where_parts[] = "access_time <= %s";
        }
        if (!empty($filters['ip_address'])) {
            $where_parts[] = "ip_address LIKE %s";
        }
        if (!empty($filters['status_code'])) {
            $where_parts[] = "status_code = %d";
        }
        if (isset($filters['is_bot']) && $filters['is_bot'] !== '') {
            $where_parts[] = "is_bot = %d";
        }
        if (!empty($filters['visit_type'])) {
            $where_parts[] = "visit_type = %s";
        }
        if (!empty($filters['country_code'])) {
            $country_codes = array_map('trim', explode(',', $filters['country_code']));
            $placeholders = array_fill(0, count($country_codes), '%s');
            $where_parts[] = "country_code IN (" . implode(', ', $placeholders) . ")";
        }
        if (!empty($filters['url_pattern'])) {
            $where_parts[] = "request_uri LIKE %s";
        }
        if (!empty($filters['ua_pattern'])) {
            $where_parts[] = "user_agent LIKE %s";
        }
        if (!empty($filters['source'])) {
            $where_parts[] = "source = %s";
        }
        if (!empty($filters['referer_pattern'])) {
            $where_parts[] = "referer_url LIKE %s";
        }
        if (!empty($filters['suspicious_only'])) {
            // 疑わしいキーワードを取得
            $suspicious_keywords = get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
            $keywords = array_filter(array_map('trim', explode("\n", $suspicious_keywords)));
            if (!empty($keywords)) {
                $suspicious_conditions = [];
                foreach ($keywords as $keyword) {
                    $suspicious_conditions[] = "request_uri LIKE %s";
                }
                if (!empty($suspicious_conditions)) {
                    $where_parts[] = "(" . implode(" OR ", $suspicious_conditions) . ")";
                }
            }
        }

        return implode(' AND ', $where_parts);
    }
    
    /**
     * WHERE句のパラメータを構築
     */
    private static function build_where_params($filters) {
        $params = [];

        if (!empty($filters['date_from'])) {
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['ip_address'])) {
            $params[] = '%' . $filters['ip_address'] . '%';
        }
        if (!empty($filters['status_code'])) {
            $params[] = intval($filters['status_code']);
        }
        if (isset($filters['is_bot']) && $filters['is_bot'] !== '') {
            $params[] = intval($filters['is_bot']);
        }
        if (!empty($filters['visit_type'])) {
            $params[] = $filters['visit_type'];
        }
        if (!empty($filters['country_code'])) {
            $country_codes = array_map('trim', explode(',', $filters['country_code']));
            foreach ($country_codes as $code) {
                $params[] = $code;
            }
        }
        if (!empty($filters['url_pattern'])) {
            $params[] = '%' . $filters['url_pattern'] . '%';
        }
        if (!empty($filters['ua_pattern'])) {
            $params[] = '%' . $filters['ua_pattern'] . '%';
        }
        if (!empty($filters['source'])) {
            $params[] = $filters['source'];
        }
        if (!empty($filters['referer_pattern'])) {
            $params[] = '%' . $filters['referer_pattern'] . '%';
        }
        if (!empty($filters['suspicious_only'])) {
            // 疑わしいキーワードを取得
            $suspicious_keywords = get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
            $keywords = array_filter(array_map('trim', explode("\n", $suspicious_keywords)));
            foreach ($keywords as $keyword) {
                $params[] = '%' . $keyword . '%';
            }
        }

        return $params;
    }
    
    /**
     * CSVヘッダーとDBカラムのマッピングを作成
     */
    private static function create_column_mapping($csv_header, $db_columns) {
        $mapping = [];
        
        // 日本語ラベルから英語キーへのマッピング
        $label_to_key = [];
        foreach ($db_columns as $key => $label) {
            $label_to_key[$label] = $key;
            $label_to_key[strtolower($label)] = $key;
        }
        
        // 英語キーの直接マッピング
        $direct_keys = array_keys($db_columns);
        foreach ($direct_keys as $key) {
            $label_to_key[strtolower($key)] = $key;
        }
        
        // CSVヘッダーをマッピング
        foreach ($csv_header as $index => $header_name) {
            $header_name = trim($header_name);
            $header_lower = strtolower($header_name);
            
            if (isset($label_to_key[$header_lower])) {
                $mapping[$index] = $label_to_key[$header_lower];
            } elseif (isset($label_to_key[$header_name])) {
                $mapping[$index] = $label_to_key[$header_name];
            }
        }
        
        return $mapping;
    }
    
    /**
     * 行データの検証
     */
    private static function validate_row_data($row_data) {
        $validated_data = [];
        
        // 必須フィールドのデフォルト値設定
        $defaults = [
            'access_time' => current_time('mysql', 1),
            'ip_address' => '',
            'user_agent' => '',
            'request_uri' => '',
            'referer_url' => '',
            'request_method' => 'GET',
            'status_code' => 200,
            'user_id' => null,
            'is_bot' => 0,
            'visit_type' => 'unknown',
            'source' => 'import',
            'visitor_id_cookie' => null,
            'country_code' => null,
            'navigation_type' => 'unknown'
        ];
        
        foreach ($defaults as $field => $default_value) {
            if (isset($row_data[$field]) && $row_data[$field] !== '') {
                $validated_data[$field] = $row_data[$field];
            } else {
                $validated_data[$field] = $default_value;
            }
        }
        
        // データ型の検証と変換
        if (isset($validated_data['status_code'])) {
            $validated_data['status_code'] = intval($validated_data['status_code']);
            if ($validated_data['status_code'] < 100 || $validated_data['status_code'] > 599) {
                $validated_data['status_code'] = 200;
            }
        }
        
        if (isset($validated_data['user_id'])) {
            $validated_data['user_id'] = !empty($validated_data['user_id']) ? intval($validated_data['user_id']) : null;
        }
        
        if (isset($validated_data['is_bot'])) {
            $validated_data['is_bot'] = intval($validated_data['is_bot']) ? 1 : 0;
        }
        
        // 日時フォーマットの検証
        if (isset($validated_data['access_time']) && !empty($validated_data['access_time'])) {
            $timestamp = strtotime($validated_data['access_time']);
            if ($timestamp === false) {
                $validated_data['access_time'] = current_time('mysql', 1);
            } else {
                $validated_data['access_time'] = date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        return ['valid' => true, 'data' => $validated_data];
    }
    
    /**
     * 重複エントリのチェック
     */
    private static function is_duplicate_entry($row_data) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        
        // アクセス時刻、IPアドレス、User-Agent、リクエストURIで重複チェック
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                WHERE access_time = %s 
                AND ip_address = %s 
                AND user_agent = %s 
                AND request_uri = %s",
                $row_data['access_time'],
                $row_data['ip_address'],
                $row_data['user_agent'],
                $row_data['request_uri']
            )
        );
        
        return $count > 0;
    }
    
    /**
     * バッチデータの挿入
     */
    private static function insert_batch_data($batch_data, $start_line = 0) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();

        $success_count = 0;
        $error_count = 0;
        $errors = [];

        foreach ($batch_data as $index => $row_data) {
            $result = $wpdb->insert(
                $table_name,
                $row_data,
                [
                    '%s', '%s', '%s', '%s', '%s', '%s', '%d',
                    '%d', '%d', '%s', '%s', '%s', '%s', '%s'
                ]
            );

            if ($result !== false) {
                $success_count++;
            } else {
                $error_count++;
                $line_num = $start_line + $index;
                $db_error = $wpdb->last_error ? $wpdb->last_error : __('不明なデータベースエラー', 'kashiwazaki-seo-super-access-log');
                $errors[] = sprintf(__('行 %d: データベース挿入エラー - %s', 'kashiwazaki-seo-super-access-log'), $line_num, $db_error);
            }
        }

        return ['success' => $success_count, 'error' => $error_count, 'errors' => $errors];
    }
    
    /**
     * CSVサンプルファイルの生成
     */
    public static function generate_sample_csv_content() {
        // BOM
        $csv_content = "\xEF\xBB\xBF";

        $handle = fopen('php://temp', 'w+');

        // ヘッダー
        $all_columns = kssl_get_all_column_definitions();
        $header_labels = array_values($all_columns);
        fputcsv($handle, $header_labels);

        // サンプルデータ
        $sample_data = [
            [
                '1',
                '2024-01-15 10:30:00',
                'sample-visitor-id-123',
                '192.168.1.100',
                'JP',
                '/sample-page/',
                'GET',
                '200',
                'https://example.com/referrer/',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                '',
                '0',
                'new',
                'wordpress'
            ],
            [
                '2',
                '2024-01-15 10:31:00',
                'sample-visitor-id-456',
                '192.168.1.101',
                'US',
                '/another-page/',
                'GET',
                '404',
                'https://google.com/',
                'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
                '5',
                '0',
                'returning',
                'wordpress'
            ]
        ];

        foreach ($sample_data as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv_content .= stream_get_contents($handle);
        fclose($handle);

        return $csv_content;
    }

    public static function generate_sample_csv() {
        $content = self::generate_sample_csv_content();
        $filename = 'kssl-import-sample.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo $content;
        exit;
    }

    /**
     * エクスポートディレクトリのパスを取得
     */
    private static function get_exports_directory() {
        $upload_dir = wp_upload_dir();
        $exports_dir = $upload_dir['basedir'] . '/kssl-exports';

        if (!file_exists($exports_dir)) {
            wp_mkdir_p($exports_dir);
            // ディレクトリのパーミッションを設定（書き込み可能に）
            @chmod($exports_dir, 0777);
            // セキュリティ：直接アクセスを防ぐ
            file_put_contents($exports_dir . '/.htaccess', 'deny from all');
            file_put_contents($exports_dir . '/index.php', '<?php // Silence is golden');
        } elseif (!is_writable($exports_dir)) {
            // ディレクトリが書き込み不可の場合、権限を修正
            @chmod($exports_dir, 0777);
        }

        return $exports_dir;
    }

    /**
     * エクスポートジョブを開始
     */
    public static function start_export_job($filters = []) {
        $job_id = wp_generate_password(16, false);
        $exports_dir = self::get_exports_directory();

        // ジョブ情報を保存
        set_transient('kssl_export_job_' . $job_id, [
            'status' => 'processing',
            'filters' => $filters,
            'started' => time(),
            'progress' => 0,
            'total' => 0,
            'message' => 'エクスポートを開始しています...'
        ], 3600); // 1時間

        // バックグラウンドでエクスポートを実行
        $scheduled = wp_schedule_single_event(time() + 1, 'kssl_process_export_job', [$job_id, $filters]);

        // WP-Cronが無効化されている場合は直接実行
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            // WP-Cronが無効化されている場合のみ直接実行
            self::process_export_job_background($job_id, $filters);
        } elseif ($scheduled !== false) {
            // WP-Cronジョブとして登録成功 - spawn処理を実行してすぐに開始
            spawn_cron();
        }

        return $job_id;
    }

    /**
     * バックグラウンドでエクスポートを処理
     */
    public static function process_export_job_background($job_id, $filters = [], $user_id = null, $lock_key = null) {
        global $wpdb;

        // タイムアウトを延長
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $table_name = kssl_get_log_table_name_func();
        $exports_dir = self::get_exports_directory();
        $filename = 'kssl-export-' . date('Y-m-d-H-i-s') . '-' . substr($job_id, 0, 8) . '.csv';
        $file_path = $exports_dir . '/' . $filename;

        try {
            // ステータス更新
            self::update_job_status($job_id, 'processing', 'CSVファイルを生成中...', 10);

            $handle = fopen($file_path, 'w');
            if (!$handle) {
                throw new Exception('ファイルを作成できませんでした');
            }

            // BOMを追加
            fwrite($handle, "\xEF\xBB\xBF");

            // ヘッダー
            $all_columns = kssl_get_all_column_definitions();
            $column_keys = array_keys($all_columns);
            $header_labels = array_values($all_columns);
            fputcsv($handle, $header_labels);

            // WHERE句を構築
            $where_clause = KSSL_CSV_Handler::build_where_clause($filters);
            $params = KSSL_CSV_Handler::build_where_params($filters);

            // 総レコード数を取得
            $count_query = "SELECT COUNT(*) FROM {$table_name}";
            if (!empty($where_clause)) {
                $count_query .= " WHERE {$where_clause}";
            }
            $total = empty($params) ?
                $wpdb->get_var($count_query) :
                $wpdb->get_var($wpdb->prepare($count_query, $params));

            self::update_job_status($job_id, 'processing', sprintf('%s件のレコードをエクスポート中...', number_format($total)), 20, $total);

            // バッチ処理
            $limit = 1000;
            $offset = 0;
            $exported = 0;

            while (true) {
                $query = "SELECT * FROM {$table_name}";
                if (!empty($where_clause)) {
                    $query .= " WHERE {$where_clause}";
                }
                $query .= " ORDER BY id ASC LIMIT %d OFFSET %d";

                $prepare_params = array_merge($params, [$limit, $offset]);
                $results = $wpdb->get_results(
                    $wpdb->prepare($query, $prepare_params),
                    ARRAY_A
                );

                if (empty($results)) {
                    break;
                }

                foreach ($results as $row) {
                    $ordered_row = [];
                    foreach ($column_keys as $key) {
                        $ordered_row[] = $row[$key] ?? '';
                    }
                    fputcsv($handle, $ordered_row);
                }

                $exported += count($results);
                $progress = $total > 0 ? min(90, 20 + (($exported / $total) * 70)) : 90;
                self::update_job_status(
                    $job_id,
                    'processing',
                    sprintf('%s / %s件をエクスポート中...', number_format($exported), number_format($total)),
                    $progress,
                    $total
                );

                if (count($results) < $limit) {
                    break;
                }

                $offset += $limit;
            }

            fclose($handle);

            // ファイルのパーミッションを設定（削除可能にする）
            @chmod($file_path, 0666);

            // 完了
            $file_size = filesize($file_path);
            self::update_job_status($job_id, 'completed', sprintf('エクスポート完了！ %s件のレコード（%s）', number_format($exported), size_format($file_size)), 100, $total);

            // ファイル情報を保存
            set_transient('kssl_export_file_' . $job_id, [
                'filename' => $filename,
                'filepath' => $file_path,
                'size' => $file_size,
                'records' => $exported,
                'created' => time()
            ], 86400 * 7); // 7日間保持

            // Release lock after successful completion
            if ($lock_key !== null) {
                delete_transient($lock_key);
            }

        } catch (Exception $e) {
            self::update_job_status($job_id, 'error', 'エラー: ' . $e->getMessage(), 0);
            if (isset($handle) && $handle) {
                fclose($handle);
            }
            if (file_exists($file_path)) {
                @unlink($file_path);
            }

            // Release lock after error
            if ($lock_key !== null) {
                delete_transient($lock_key);
            }
        }
    }

    /**
     * ジョブステータスを更新
     */
    private static function update_job_status($job_id, $status, $message, $progress = 0, $total = 0) {
        set_transient('kssl_export_job_' . $job_id, [
            'status' => $status,
            'message' => $message,
            'progress' => $progress,
            'total' => $total,
            'updated' => time()
        ], 3600);
    }

    /**
     * エクスポートジョブの状態を取得
     */
    public static function get_export_status($job_id) {
        return get_transient('kssl_export_job_' . $job_id);
    }

    /**
     * エクスポートファイルの情報を取得
     */
    public static function get_export_file_info($job_id) {
        return get_transient('kssl_export_file_' . $job_id);
    }

    /**
     * 生成済みエクスポートファイルのリストを取得
     */
    public static function get_export_files() {
        $exports_dir = self::get_exports_directory();
        $files = [];

        if (!is_dir($exports_dir)) {
            return $files;
        }

        $scan = scandir($exports_dir);
        foreach ($scan as $file) {
            if (substr($file, 0, 12) === 'kssl-export-' && substr($file, -4) === '.csv') {
                $filepath = $exports_dir . '/' . $file;
                $filesize = filesize($filepath);
                $created = filemtime($filepath);

                $files[] = [
                    'name' => $file,  // JavaScriptが期待するキー名
                    'path' => $filepath,  // JavaScriptが期待するキー名
                    'size' => $filesize,  // 数値形式（JavaScriptで計算するため）
                    'size_formatted' => size_format($filesize, 2),  // フォーマット済み（表示用）
                    'date' => get_date_from_gmt(date('Y-m-d H:i:s', $created), 'Y-m-d H:i'),
                    'created' => $created,
                    'download_url' => admin_url('admin-ajax.php?action=kssl_download_export&file=' . urlencode($file) . '&nonce=' . wp_create_nonce('kssl_csv_nonce'))
                ];
            }
        }

        // 作成日時の降順でソート
        usort($files, function($a, $b) {
            return $b['created'] - $a['created'];
        });

        return $files;
    }

    /**
     * エクスポートファイルをダウンロード
     */
    public static function download_export_file($filename) {
        $exports_dir = self::get_exports_directory();
        $filepath = $exports_dir . '/' . basename($filename);

        if (!file_exists($filepath) || !is_file($filepath)) {
            wp_die('ファイルが見つかりません');
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($filepath);
        exit;
    }

    /**
     * エクスポートファイルを削除
     */
    public static function delete_export_file($filename) {
        $exports_dir = self::get_exports_directory();
        $filepath = $exports_dir . '/' . basename($filename);

        // ファイル存在確認
        if (!file_exists($filepath)) {
            error_log('KSSL: File not found for deletion: ' . $filepath);
            return false;
        }

        if (!is_file($filepath)) {
            error_log('KSSL: Not a file: ' . $filepath);
            return false;
        }

        // 権限確認
        if (!is_writable($filepath)) {
            error_log('KSSL: File not writable: ' . $filepath);
            // 権限を変更してみる
            @chmod($filepath, 0666);
        }

        // 削除実行
        $result = @unlink($filepath);

        if (!$result) {
            $error = error_get_last();
            error_log('KSSL: Failed to delete file: ' . $filepath . ' - Error: ' . ($error['message'] ?? 'Unknown'));
        } else {
            error_log('KSSL: Successfully deleted file: ' . $filepath);
        }

        return $result;
    }
}

// WP-Cronアクション登録は kssl-admin-hooks.php で行っているため、ここでは削除
// add_action('kssl_process_export_job', ['KSSL_CSV_Handler', 'process_export_job_background'], 10, 2);