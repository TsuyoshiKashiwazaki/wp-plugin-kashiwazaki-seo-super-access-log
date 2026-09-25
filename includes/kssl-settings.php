<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * プラグイン設定の保存
 *
 * @param array $post_data POST データ
 * @return bool 成功時true、失敗時false
 */
function kssl_save_plugin_settings($post_data) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    // 表示カラムの設定
    $all_possible_cols = array_keys(kssl_get_all_column_definitions());
    $selected_cols = [];
    if (isset($post_data['kssl_columns']) && is_array($post_data['kssl_columns'])) {
        foreach ($post_data['kssl_columns'] as $col_key_unsafe) {
            $col_key = sanitize_key($col_key_unsafe);
            if (in_array($col_key, $all_possible_cols)) {
                $selected_cols[$col_key] = 1;
            }
        }
    }
    update_option(KSSL_DISPLAYED_COLUMNS_OPTION_KEY, $selected_cols);

    // Cookie有効期限の設定
    if (isset($post_data['kssl_cookie_lifetime'])) {
        $lifetime = intval($post_data['kssl_cookie_lifetime']);
        update_option(KSSL_COOKIE_LIFETIME_OPTION_KEY, max(1, $lifetime));
    }
    
    // 各種設定の保存
    update_option(KSSL_STATIC_TRACKING_OPTION_KEY, isset($post_data['kssl_static_tracking_enabled']) ? 1 : 0);
    update_option(KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY, isset($post_data[KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY]) ? 1 : 0);

    // 疑わしいキーワード設定の保存（正規表現対応のため特別処理）
    if (array_key_exists('kssl_suspicious_keywords', $post_data)) {
        $raw_keywords = wp_unslash($post_data['kssl_suspicious_keywords']);
        // 改行文字のみ統一し、バックスラッシュはそのまま保持
        $normalized_keywords = str_replace(["\r\n", "\r"], "\n", $raw_keywords);
        $current_keywords = (string) get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
        if ($normalized_keywords !== $current_keywords) {
            if (kssl_migration_blocks_writes()) {
                // The stored flags are being rebuilt by the table migration; keep the old keywords.
                $GLOBALS['kssl_settings_notice'] = __('テーブル移行中のため、疑わしいキーワードの変更は保存されませんでした。移行完了後にもう一度保存してください。', 'kashiwazaki-seo-super-access-log');
            } else {
                update_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, $normalized_keywords);
                // Existing rows are re-flagged in the background; until then filters use the keyword match.
                kssl_start_reclassification();
            }
        }
    }

    // その他のテキストエリア設定の保存 ($_POST は WordPress がスラッシュを付けているので wp_unslash してから保存する。
    // しないと保存のたびに \ や引用符の前のバックスラッシュが増える)
    $text_settings = [
        'kssl_excluded_uri_patterns' => KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY,
        KSSL_BLOCKED_UAS_OPTION_KEY => KSSL_BLOCKED_UAS_OPTION_KEY,
    ];

    foreach ($text_settings as $post_key => $option_key) {
        if (array_key_exists($post_key, $post_data) && is_string($post_data[$post_key])) {
            update_option($option_key, sanitize_textarea_field(wp_unslash($post_data[$post_key])));
        }
    }

    // ボット検出パターン: 正規表現なので sanitize_textarea_field (タグを取り除く) は通さず、
    // 正規表現として使えるものだけを保存する (壊れたパターンは毎リクエストの判定を失敗させる)
    if (array_key_exists('kssl_bot_detection_pattern_setting', $post_data) && is_string($post_data['kssl_bot_detection_pattern_setting'])) {
        $bot_pattern = trim(str_replace(["\r\n", "\r", "\n", "\0"], '', wp_unslash($post_data['kssl_bot_detection_pattern_setting'])));
        if ($bot_pattern !== '' && @preg_match($bot_pattern, '') === false) {
            $GLOBALS['kssl_settings_notice'] = trim(($GLOBALS['kssl_settings_notice'] ?? '') . ' ' . __('ボット検出パターンが正規表現として正しくないため、変更は保存されませんでした。', 'kashiwazaki-seo-super-access-log'));
        } else {
            update_option(KSSL_BOT_DETECTION_PATTERN_OPTION_KEY, $bot_pattern);
        }
    }

    // ブロックされた訪問者リストの処理
    if (array_key_exists('kssl_blocked_visitors_list', $post_data)) {
        $raw_list = sanitize_textarea_field($post_data['kssl_blocked_visitors_list']);
        $visitor_ids = array_filter(array_map('trim', explode("\n", $raw_list)));
        $sanitized_visitor_ids = [];
        foreach ($visitor_ids as $vid) {
            if (!empty($vid) && strlen($vid) < 256) {
                $sanitized_visitor_ids[] = sanitize_text_field($vid);
            }
        }
        update_option(KSSL_BLOCKED_VISITORS_OPTION_KEY, array_unique($sanitized_visitor_ids));
    }

    // 自動クリーンアップの設定 (予定の登録・解除は保存期間を保存した後に行う)
    $enable_auto_cleanup = isset($post_data['kssl_enable_auto_cleanup']) ? 1 : 0;
    update_option(KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, $enable_auto_cleanup);
    
    // ログ保存期間の設定
    if (isset($post_data['kssl_log_retention_days'])) {
        $retention_days = intval($post_data['kssl_log_retention_days']);
        
        // 自動クリーンアップが有効な場合のみ最小値チェック
        if ($enable_auto_cleanup) {
            // 自動クリーンアップが有効な場合は1日以上必要
            $retention_days = max(1, $retention_days);
        } else {
            // 自動クリーンアップが無効な場合は0も許可
            $retention_days = max(0, $retention_days);
        }
        
        update_option(KSSL_LOG_RETENTION_DAYS_OPTION_KEY, $retention_days);
    }
    // The daily cleanup event follows the setting (it used to be scheduled only on activation,
    // so turning the option on later never removed anything).
    kssl_schedule_cleanup_cron();
    
    // チャート表示の最大レコード数の設定
    $chart_limit_type = isset($post_data['kssl_chart_limit_type']) ? sanitize_text_field($post_data['kssl_chart_limit_type']) : 'preset';
    $preset_values = [0, 50000, 100000, 250000, 500000, 1000000];

    if ($chart_limit_type === 'preset') {
        // プリセット値を使用
        if (isset($post_data['kssl_preset_value'])) {
            $preset_value = intval($post_data['kssl_preset_value']);
            // プリセット値リストに含まれているか確認
            if (in_array($preset_value, $preset_values, true)) {
                update_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, $preset_value);
            }
        } elseif (isset($post_data['kssl_max_chart_records'])) {
            // 後方互換性のため、kssl_max_chart_recordsもチェック
            $max_records = intval($post_data['kssl_max_chart_records']);
            if (in_array($max_records, $preset_values, true)) {
                update_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, $max_records);
            }
        }
    } else {
        // カスタム値を使用
        if (isset($post_data['kssl_custom_limit'])) {
            $custom_limit = intval($post_data['kssl_custom_limit']);
            // カスタム値は1000以上または0（無制限）のみ許可
            if ($custom_limit === 0 || $custom_limit >= 1000) {
                update_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, $custom_limit);
            }
        } elseif (isset($post_data['kssl_max_chart_records'])) {
            // 後方互換性のため、kssl_max_chart_recordsもチェック
            $max_records = intval($post_data['kssl_max_chart_records']);
            if ($max_records === 0 || $max_records >= 1000) {
                update_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, $max_records);
            }
        }
    }

    // 自サーバーIPアドレス除外の設定
    update_option(KSSL_EXCLUDE_SELF_SERVER_IP_OPTION_KEY, isset($post_data['kssl_exclude_self_server_ip']) ? 1 : 0);

    // 静的ファイル除外の設定
    update_option(KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY, isset($post_data['kssl_exclude_static_files']) ? 1 : 0);

    // 静的ファイル除外パターンの設定
    if (isset($post_data['kssl_static_files_pattern'])) {
        $pattern = wp_unslash($post_data['kssl_static_files_pattern']);
        // 正規表現パターンの妥当性チェック
        if (@preg_match($pattern, 'test.js') !== false) {
            update_option(KSSL_STATIC_FILES_PATTERN_OPTION_KEY, $pattern);
        }
    }

    // User-Agentが空のアクセスをブロックする設定
    update_option(KSSL_BLOCK_EMPTY_UA_OPTION_KEY, isset($post_data[KSSL_BLOCK_EMPTY_UA_OPTION_KEY]) ? 1 : 0);

    // Settings can change what the aggregates show (exclusions, chart limit): drop cached aggregates.
    kssl_bump_cache_generation();
    return true;
}