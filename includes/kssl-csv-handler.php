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
        $last_id = 0;
        // Upper id fixed at start: rows logged during the export are not chased.
        $upper_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$table_name}");
        
        while (true) {
            // Keyset pagination (id > last_id) instead of OFFSET, which rescans skipped rows
            $query = "SELECT * FROM {$table_name} WHERE id > %d AND id <= %d";
            if (!empty($where_clause)) {
                $query .= " AND {$where_clause}";
            }
            $query .= " ORDER BY id ASC LIMIT %d";
            
            $prepare_params = array_merge([$last_id, $upper_id], $params, [$limit]);
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
            
            $last_id = (int) end($results)['id'];
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

        $all_columns = kssl_get_all_column_definitions();

        // Resumable run: 'state' carries the position and counters of the previous step.
        $state = isset($options['state']) && is_array($options['state']) ? $options['state'] : null;
        $time_budget = isset($options['time_budget']) ? (float) $options['time_budget'] : 0;
        $file_size = (int) filesize($file_path);

        if ($state === null) {
            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                return ['success' => false, 'message' => __('Could not read CSV header.', 'kashiwazaki-seo-super-access-log')];
            }
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            $column_mapping = self::create_column_mapping($header, $all_columns);
            if (empty($column_mapping)) {
                fclose($handle);
                return ['success' => false, 'message' => __('No valid columns found in CSV.', 'kashiwazaki-seo-super-access-log')];
            }
            $state = [
                'column_mapping' => $column_mapping,
                'offset' => ftell($handle),
                'line_number' => 1,
                'imported_count' => 0,
                'skipped_count' => 0,
                'error_count' => 0,
                'error_details' => [],
                'skip_details' => [],
            ];
        } else {
            fseek($handle, (int) $state['offset']);
        }
        $column_mapping = $state['column_mapping'];
        $line_number = (int) $state['line_number'];
        $imported_count = (int) $state['imported_count'];
        $skipped_count = (int) $state['skipped_count'];
        $error_count = (int) $state['error_count'];
        $error_details = (array) $state['error_details'];
        $skip_details = (array) $state['skip_details'];

        $skip_duplicates = isset($options['skip_duplicates']) ? $options['skip_duplicates'] : true;
        $validate_data = isset($options['validate_data']) ? $options['validate_data'] : true;
        $progress_callback = isset($options['progress_callback']) ? $options['progress_callback'] : null;

        $start = microtime(true);
        $batch_size = 500;
        $done = false;
        $aborted = '';

        while (true) {
            // Read one batch of rows (position saved only after the batch is written).
            $batch_data = [];
            $batch_lines = [];
            $eof = false;
            while (count($batch_data) < $batch_size) {
                $data = fgetcsv($handle);
                if ($data === false) {
                    $eof = true;
                    break;
                }
                $line_number++;
                if (count($data) < count($column_mapping)) {
                    $skipped_count++;
                    $skip_details[] = sprintf(__('行 %d: カラム数が不足しています（必要: %d, 実際: %d）', 'kashiwazaki-seo-super-access-log'), $line_number, count($column_mapping), count($data));
                    continue;
                }
                $row_data = [];
                foreach ($column_mapping as $csv_index => $db_column) {
                    $row_data[$db_column] = isset($data[$csv_index]) ? trim($data[$csv_index]) : '';
                }
                if ($validate_data) {
                    $validation_result = self::validate_row_data($row_data);
                    if (!$validation_result['valid']) {
                        $skipped_count++;
                        $skip_details[] = sprintf(__('行 %d: データ検証エラー - %s', 'kashiwazaki-seo-super-access-log'), $line_number, $validation_result['error'] ?? '不明なエラー');
                        continue;
                    }
                    $row_data = $validation_result['data'];
                }
                $batch_data[] = $row_data;
                $batch_lines[] = $line_number;
            }

            if (!empty($batch_data)) {
                if (!kssl_job_lock()) {
                    // Another bulk job holds the lock: rewind to the saved position and retry later.
                    fclose($handle);
                    return self::import_step_result($state, false, '', $file_size);
                }
                try {
                    if (kssl_migration_blocks_writes()) {
                        $aborted = kssl_migration_block_message();
                    } else {
                        if ($skip_duplicates) {
                            $existing = self::existing_duplicate_keys($batch_data);
                            $kept = [];
                            $kept_lines = [];
                            foreach ($batch_data as $i => $row_data) {
                                if (isset($existing[self::duplicate_key($row_data)])) {
                                    $skipped_count++;
                                    $skip_details[] = sprintf(__('行 %d: 重複データ（同じアクセス時刻・IPアドレス・User-Agent・URIのレコードが既に存在）', 'kashiwazaki-seo-super-access-log'), $batch_lines[$i]);
                                    continue;
                                }
                                $kept[] = $row_data;
                                $kept_lines[] = $batch_lines[$i];
                            }
                            $batch_data = $kept;
                            $batch_lines = $kept_lines;
                        }
                        // The aggregates of the imported hours stop being used before any row changes.
                        if (!kssl_agg_mark_dirty(array_map(function ($r) { return $r['access_time'] ?? null; }, $batch_data))) {
                            $aborted = __('集計の更新に失敗したため、インポートを中止しました。', 'kashiwazaki-seo-super-access-log');
                            $batch_data = [];
                        }
                        $result = self::insert_batch_data($batch_data, $batch_lines);
                        $imported_count += $result['success'];
                        $error_count += $result['error'];
                        $error_details = array_merge($error_details, $result['errors']);
                    }
                } finally {
                    kssl_job_unlock();
                }
            }

            $state = array_merge($state, [
                'offset' => ftell($handle),
                'line_number' => $line_number,
                'imported_count' => $imported_count,
                'skipped_count' => $skipped_count,
                'error_count' => $error_count,
                'error_details' => array_slice($error_details, 0, 50),
                'skip_details' => array_slice($skip_details, 0, 50),
            ]);
            if ($progress_callback && is_callable($progress_callback)) {
                call_user_func($progress_callback, [
                    'total_lines' => 0,
                    'processed_lines' => $line_number - 1,
                    'imported_count' => $imported_count,
                    'skipped_count' => $skipped_count,
                    'error_count' => $error_count,
                    'progress' => $file_size > 0 ? min(99, (int) floor($state['offset'] * 100 / $file_size)) : 50,
                ]);
            }
            if ($aborted !== '') {
                break;
            }
            if ($eof) {
                $done = true;
                break;
            }
            if ($time_budget > 0 && microtime(true) - $start >= $time_budget) {
                fclose($handle);
                return self::import_step_result($state, false, '', $file_size);
            }
        }

        fclose($handle);
        if ($imported_count > 0) {
            kssl_bump_cache_generation();
        }
        if ($aborted !== '') {
            return array_merge(self::import_step_result($state, true, '', $file_size), [
                'success' => false,
                'message' => $aborted . sprintf(__('（中断までに %d件をインポート済み）', 'kashiwazaki-seo-super-access-log'), $imported_count),
            ]);
        }

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
            'done' => true,
            'message' => $message,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'errors' => $error_count,
            'imported_count' => $imported_count,
            'skipped_count' => $skipped_count,
            'error_count' => $error_count,
            'error_details' => $error_details,
            'skip_details' => $skip_details
        ];
    }

    /**
     * Result of an unfinished import step (the caller schedules the next one with 'state').
     */
    private static function import_step_result($state, $done, $message, $file_size) {
        return [
            'success' => true,
            'done' => $done,
            'state' => $state,
            'message' => $message,
            'progress' => $file_size > 0 ? min(99, (int) floor($state['offset'] * 100 / $file_size)) : 50,
            'imported_count' => $state['imported_count'],
            'skipped_count' => $state['skipped_count'],
            'error_count' => $state['error_count'],
        ];
    }

    private static function duplicate_key($row) {
        return md5(($row['access_time'] ?? '') . "\0" . ($row['ip_address'] ?? '') . "\0" . ($row['user_agent'] ?? '') . "\0" . ($row['request_uri'] ?? ''));
    }

    /**
     * Existing rows matching the batch on the same four columns as before (one query per batch,
     * narrowed by exact access_time values on the indexed column).
     */
    private static function existing_duplicate_keys($batch_data) {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        $times = array_values(array_unique(array_filter(array_map(function ($r) { return $r['access_time'] ?? ''; }, $batch_data), 'strlen')));
        if (empty($times)) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT access_time, ip_address, user_agent, request_uri FROM {$table_name} WHERE access_time IN (" . implode(',', array_fill(0, count($times), '%s')) . ')',
            $times
        ), ARRAY_A);
        $keys = [];
        foreach ((array) $rows as $row) {
            $keys[self::duplicate_key($row)] = true;
        }
        return $keys;
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
            $where_parts[] = "access_time < %s";
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

        // Dates are days in the site's timezone; access_time is UTC (a day ends before the next 00:00).
        if (!empty($filters['date_from'])) {
            $params[] = kssl_site_date_to_utc($filters['date_from']) ?? '9999-12-31 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $to = kssl_site_date_to_utc($filters['date_to']) !== null ? kssl_site_date_to_utc(gmdate('Y-m-d', strtotime($filters['date_to'] . ' +1 day'))) : null;
            $params[] = $to ?? '0000-01-01 00:00:00';
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

        // The CSV has a time column but this row's time is empty: "now" would put the row on the
        // wrong day, so skip it (a CSV without a time column still gets the import time).
        if (array_key_exists('access_time', $row_data) && trim((string) $row_data['access_time']) === '') {
            return ['valid' => false, 'error' => __('日時が空です', 'kashiwazaki-seo-super-access-log')];
        }

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
                // Replacing the time with "now" would put the row on the wrong day: skip it instead.
                return ['valid' => false, 'error' => sprintf(__('日時の形式が正しくありません: %s', 'kashiwazaki-seo-super-access-log'), $validated_data['access_time'])];
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
    private static function insert_batch_data($batch_data, $lines = []) {
        global $wpdb;
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        $batch_data = array_values($batch_data);
        $lines = is_array($lines) ? array_values($lines) : [];
        // Multi-row INSERTs in chunks of at most ~1 MB of SQL (on the lightweight schema each row's
        // UA/URI is bound once per keyword), well below max_allowed_packet (4 MB on MySQL 5.7).
        $per_row_factor = kssl_schema_is_lean() ? 2 * max(1, count(kssl_suspicious_keywords())) + 1 : 1;
        $chunks = [];
        $chunk = [];
        $bytes = 0;
        foreach ($batch_data as $index => $row_data) {
            $row_bytes = 200 + $per_row_factor * (strlen((string) ($row_data['user_agent'] ?? '')) + strlen((string) ($row_data['request_uri'] ?? ''))) + strlen((string) ($row_data['referer_url'] ?? ''));
            if (!empty($chunk) && ($bytes + $row_bytes > 1048576 || count($chunk) >= 100)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[$index] = $row_data;
            $bytes += $row_bytes;
        }
        if (!empty($chunk)) {
            $chunks[] = $chunk;
        }
        foreach ($chunks as $chunk) {
            // One statement: if it fails nothing of the chunk was inserted, so retry row by row to
            // report the failing lines as before.
            $inserted = kssl_insert_log_rows(array_values($chunk));
            if ($inserted !== false) {
                $success_count += (int) $inserted;
                continue;
            }
            foreach ($chunk as $index => $row_data) {
                // Shared insert: computes is_suspicious / referer_host with the same SQL as every other path.
                $result = kssl_insert_log_row($row_data);
                if ($result !== false) {
                    $success_count++;
                } else {
                    $error_count++;
                    $line_num = isset($lines[$index]) ? $lines[$index] : $index;
                    $db_error = $wpdb->last_error ? $wpdb->last_error : __('不明なデータベースエラー', 'kashiwazaki-seo-super-access-log');
                    $errors[] = sprintf(__('行 %d: データベース挿入エラー - %s', 'kashiwazaki-seo-super-access-log'), $line_num, $db_error);
                }
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
    public static function get_exports_directory() {
        // Exports hold the whole log (IP addresses included): kept in the private directory
        // (kssl_private_dir), files from the old public location are moved there.
        kssl_move_legacy_private_files();
        return kssl_private_dir('exports');
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
     * Start an export job: state is stored per job and the file is written in bounded steps
     * (kssl_export_job_step events), each resuming from the saved file position and last id.
     */
    public static function process_export_job_background($job_id, $filters = [], $user_id = null, $lock_key = null) {
        global $wpdb;
        $exports_dir = self::get_exports_directory();
        $filename = 'kssl-export-' . date('Y-m-d-H-i-s') . '-' . substr($job_id, 0, 8) . '.csv';
        $table_name = kssl_get_log_table_name_func();
        $job = [
            'status'   => 'processing',
            'filters'  => $filters,
            'filename' => $filename,
            'filepath' => $exports_dir . '/' . $filename,
            'upper_id' => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table_name}" ),
            'last_id'  => 0,
            'bytes'    => 0,
            'exported' => 0,
            'lock_key' => $lock_key,
            'started'  => time(),
        ];
        set_transient( 'kssl_export_state_' . $job_id, $job, DAY_IN_SECONDS );
        self::update_job_status( $job_id, 'processing', 'CSVファイルを生成中...', 1 );
        self::process_export_job_step( $job_id );
    }

    /**
     * One bounded export step (20 s): holds the shared job lock per batch, waits while the
     * migration still has rows only in the legacy table, and saves the file offset after each batch.
     */
    public static function process_export_job_step( $job_id ) {
        global $wpdb;
        $job = get_transient( 'kssl_export_state_' . $job_id );
        if ( ! is_array( $job ) || $job['status'] !== 'processing' ) {
            return;
        }
        kssl_schedule_once( 'kssl_export_job_step', 60, [ $job_id ] );
        // One running step per job: two steps reading the same saved position would write rows twice.
        $mutex = 'kssl_export_running_' . md5( $job_id );
        if ( ! kssl_job_mutex_acquire( $mutex ) ) {
            return;
        }
        try {
            self::run_export_step( $job_id, $job );
        } finally {
            kssl_job_mutex_release( $mutex );
        }
    }

    private static function run_export_step( $job_id, $job ) {
        global $wpdb;
        $job = get_transient( 'kssl_export_state_' . $job_id ); // re-read under the mutex
        if ( ! is_array( $job ) || $job['status'] !== 'processing' ) {
            return;
        }
        if ( kssl_migration_export_should_wait() ) {
            self::update_job_status( $job_id, 'processing', 'ログテーブルの移行の仕上げが終わるのを待っています...', self::export_progress( $job ) );
            return;
        }
        $column_keys = array_keys( kssl_get_all_column_definitions() );
        $where_clause = self::build_where_clause( $job['filters'] );
        $params = self::build_where_params( $job['filters'] );
        $handle = fopen( $job['filepath'], $job['bytes'] > 0 ? 'c+' : 'w' );
        if ( ! $handle ) {
            self::finish_export_job( $job_id, $job, 'error', 'ファイルを作成できませんでした' );
            return;
        }
        if ( $job['bytes'] > 0 ) {
            // Drop anything written after the last saved position (a crashed step), then append.
            ftruncate( $handle, $job['bytes'] );
            fseek( $handle, $job['bytes'] );
        } else {
            fwrite( $handle, "\xEF\xBB\xBF" );
            fputcsv( $handle, array_values( kssl_get_all_column_definitions() ) );
        }
        $start = microtime( true );
        $done = false;
        $progressed = false;
        while ( microtime( true ) - $start < 20 ) {
            if ( ! kssl_job_lock() ) {
                break;
            }
            try {
                if ( kssl_migration_export_should_wait() ) {
                    break;
                }
                $table_name = kssl_get_log_table_name_func();
                $sql = "SELECT * FROM {$table_name} WHERE id > %d AND id <= %d" . ( $where_clause !== '' ? " AND {$where_clause}" : '' ) . ' ORDER BY id ASC LIMIT 1000';
                $results = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ (int) $job['last_id'], (int) $job['upper_id'] ], $params ) ), ARRAY_A );
            } finally {
                kssl_job_unlock();
            }
            if ( $results === null ) {
                break;
            }
            foreach ( $results as $row ) {
                $ordered_row = [];
                foreach ( $column_keys as $key ) {
                    $ordered_row[] = $row[ $key ] ?? '';
                }
                fputcsv( $handle, $ordered_row );
            }
            fflush( $handle );
            $job['exported'] += count( $results );
            if ( count( $results ) < 1000 ) {
                $job['last_id'] = $job['upper_id'];
                $done = true;
            } else {
                $job['last_id'] = (int) end( $results )['id'];
            }
            $job['bytes'] = ftell( $handle );
            set_transient( 'kssl_export_state_' . $job_id, $job, DAY_IN_SECONDS );
            $progressed = true;
            if ( $done ) {
                break;
            }
        }
        fclose( $handle );
        if ( $done ) {
            $file_size = filesize( $job['filepath'] );
            set_transient( 'kssl_export_file_' . $job_id, [
                'filename' => $job['filename'],
                'filepath' => $job['filepath'],
                'size'     => $file_size,
                'records'  => $job['exported'],
                'created'  => time(),
            ], 86400 * 7 );
            self::finish_export_job( $job_id, $job, 'completed', sprintf( 'エクスポート完了！ %s件のレコード（%s）', number_format( $job['exported'] ), size_format( $file_size ) ) );
            return;
        }
        self::update_job_status( $job_id, 'processing', sprintf( '%s件をエクスポート中...', number_format( $job['exported'] ) ), self::export_progress( $job ) );
        if ( ! empty( $progressed ) ) {
            // Continue right away (replaces the 60 s safety event registered at the start of the step).
            wp_clear_scheduled_hook( 'kssl_export_job_step', [ $job_id ] );
            wp_schedule_single_event( time(), 'kssl_export_job_step', [ $job_id ] );
        }
    }

    private static function export_progress( $job ) {
        return $job['upper_id'] > 0 ? min( 99, (int) floor( $job['last_id'] * 100 / $job['upper_id'] ) ) : 99;
    }

    private static function finish_export_job( $job_id, $job, $status, $message ) {
        wp_clear_scheduled_hook( 'kssl_export_job_step', [ $job_id ] ); // only this job's events
        $job['status'] = $status;
        set_transient( 'kssl_export_state_' . $job_id, $job, DAY_IN_SECONDS );
        self::update_job_status( $job_id, $status, $message, $status === 'completed' ? 100 : 0, $job['exported'] );
        if ( $status !== 'completed' && ! empty( $job['filepath'] ) && file_exists( $job['filepath'] ) ) {
            @unlink( $job['filepath'] );
        }
        if ( ! empty( $job['lock_key'] ) ) {
            delete_transient( $job['lock_key'] );
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