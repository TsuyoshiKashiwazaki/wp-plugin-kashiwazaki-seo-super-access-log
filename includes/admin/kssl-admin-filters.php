<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kssl_get_filters_from_request() {
    global $wpdb;
    $filters = [];
    $where_clauses = [];
    $params = [];

    $filter_fields_map = [
        'ip_address' => 'ip_address_filter',
        'request_uri' => 'request_uri_filter',
        'user_agent' => 'user_agent_filter',
        'referer_url' => 'referer_url_filter',
        'status_code' => 'status_code_filter',
        'user_id' => 'user_id_filter',
        'is_bot' => 'is_bot_filter',
        'visit_type' => 'visit_type_filter',
        'source' => 'source_filter',
        'visitor_id_cookie' => 'visitor_id_cookie_filter',
        'country_code' => 'country_code_filter',
        'date_from' => 'date_from_filter',
        'date_to' => 'date_to_filter',
        'timezone' => 'timezone_filter',
        'period_preset' => 'period_preset_filter',
    ];

    foreach ($filter_fields_map as $db_field => $request_key) {
        if (isset($_REQUEST[$request_key]) && $_REQUEST[$request_key] !== '') {
            if ($request_key === 'timezone_filter') {
                $available_timezones = array_keys(kssl_get_available_timezones());
                if (in_array($_REQUEST[$request_key], $available_timezones, true)) {
                    $filters[$request_key] = sanitize_text_field(wp_unslash($_REQUEST[$request_key]));
                } else {
                    $filters[$request_key] = 'UTC'; // Default if invalid
                }
            } else {
                $filters[$request_key] = sanitize_text_field(wp_unslash($_REQUEST[$request_key]));
            }
        }
    }
    if (empty($filters['timezone_filter'])) { // Ensure default timezone if not set
        $filters['timezone_filter'] = 'UTC';
    }

    // Handle period preset - if no preset is selected and no custom dates, default to 1 month
    if (empty($filters['period_preset_filter']) && empty($filters['date_from_filter']) && empty($filters['date_to_filter'])) {
        $filters['period_preset_filter'] = '1month';
    }

    // Process period preset to set date_from and date_to
    if (!empty($filters['period_preset_filter']) && $filters['period_preset_filter'] !== 'custom') {
        $timezone = isset($filters['timezone_filter']) ? $filters['timezone_filter'] : 'UTC';
        $preset_dates = kssl_get_preset_dates($filters['period_preset_filter'], $timezone);
        if ($preset_dates) {
            $filters['date_from_filter'] = $preset_dates['from'];
            $filters['date_to_filter'] = $preset_dates['to'];
        }
    }

    if (!empty($filters['ip_address_filter'])) { $where_clauses[] = "ip_address LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters['ip_address_filter']) . '%'; }
    if (!empty($filters['request_uri_filter'])) { $where_clauses[] = "request_uri LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters['request_uri_filter']) . '%'; }
    if (!empty($filters['user_agent_filter'])) { $where_clauses[] = "user_agent LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters['user_agent_filter']) . '%'; }
    if (!empty($filters['referer_url_filter'])) { $where_clauses[] = "referer_url LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters['referer_url_filter']) . '%'; }
    if (!empty($filters['country_code_filter'])) { $where_clauses[] = "country_code LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters['country_code_filter']) . '%'; }
    if (!empty($filters['status_code_filter']) && is_numeric($filters['status_code_filter'])) { $where_clauses[] = "status_code = %d"; $params[] = intval($filters['status_code_filter']); }
    if (!empty($filters['user_id_filter'])) {
        if ($filters['user_id_filter'] === 'logged_in') $where_clauses[] = "user_id IS NOT NULL AND user_id > 0";
        elseif ($filters['user_id_filter'] === 'not_logged_in') $where_clauses[] = "(user_id IS NULL OR user_id = 0)";
        elseif (is_numeric($filters['user_id_filter'])) { $where_clauses[] = "user_id = %d"; $params[] = intval($filters['user_id_filter']); }
    }
    if (!empty($filters['is_bot_filter']) && in_array($filters['is_bot_filter'], ['yes', 'no'])) { $where_clauses[] = "is_bot = %d"; $params[] = ($filters['is_bot_filter'] === 'yes' ? 1 : 0); }
    
    if (!empty($filters['visit_type_filter']) && in_array($filters['visit_type_filter'], ['new', 'returning', 'transition', 'unknown'])) {
        $where_clauses[] = "visit_type = %s"; $params[] = $filters['visit_type_filter'];
    }
    
    if (!empty($filters['source_filter']) && in_array($filters['source_filter'], ['wordpress', 'static'])) { $where_clauses[] = "source = %s"; $params[] = $filters['source_filter']; }

    if (!empty($filters['visitor_id_cookie_filter'])) {
        $where_clauses[] = "visitor_id_cookie LIKE %s";
        $params[] = '%' . $wpdb->esc_like($filters['visitor_id_cookie_filter']) . '%';
    }

    // タイムゾーンを考慮した日付フィルター処理
    $timezone = isset($filters['timezone_filter']) ? $filters['timezone_filter'] : 'UTC';
    
    if (!empty($filters['date_from_filter'])) {
        $date_from = sanitize_text_field($filters['date_from_filter']);
        if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_from)) {
            // タイムゾーンをUTCに変換
            $utc_date_from = kssl_convert_local_date_to_utc($date_from . ' 00:00:00', $timezone);
            $where_clauses[] = "access_time >= %s";
            $params[] = $utc_date_from;
        }
    }
    if (!empty($filters['date_to_filter'])) {
        $date_to = sanitize_text_field($filters['date_to_filter']);
        if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_to)) {
            // タイムゾーンをUTCに変換
            $utc_date_to = kssl_convert_local_date_to_utc($date_to . ' 23:59:59', $timezone);
            $where_clauses[] = "access_time <= %s";
            $params[] = $utc_date_to;
        }
    }

    // 疑わしいアクセスを除外（デフォルトで有効）
    $exclude_suspicious = !isset($_REQUEST['include_suspicious']) || $_REQUEST['include_suspicious'] !== '1';
    if ($exclude_suspicious) {
        $suspicious_keywords_raw = get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
        $suspicious_keywords = array_filter(array_map('trim', explode("\n", $suspicious_keywords_raw)));

        if (!empty($suspicious_keywords)) {
            $suspicious_conditions = [];
            foreach ($suspicious_keywords as $keyword) {
                // User-AgentとRequest URIの両方をチェック
                $suspicious_conditions[] = "user_agent LIKE %s";
                $params[] = '%' . $wpdb->esc_like($keyword) . '%';
                $suspicious_conditions[] = "request_uri LIKE %s";
                $params[] = '%' . $wpdb->esc_like($keyword) . '%';
            }

            if (!empty($suspicious_conditions)) {
                // すべての疑わしいパターンを除外（NOT (A OR B OR C...)）
                $where_clauses[] = "NOT (" . implode(" OR ", $suspicious_conditions) . ")";
            }
        }
    }

    return ['filters' => $filters, 'where_clauses' => $where_clauses, 'params' => $params];
}

function kssl_display_filters_form($current_filters, $current_orderby, $current_order) {
    $page_slug = 'kssl_access_log_page';
    $filter_fields_map = [
        'ip_address' => 'ip_address_filter',
        'request_uri' => 'request_uri_filter',
        'user_agent' => 'user_agent_filter',
        'referer_url' => 'referer_url_filter',
        'status_code' => 'status_code_filter',
        'user_id' => 'user_id_filter',
        'is_bot' => 'is_bot_filter',
        'visit_type' => 'visit_type_filter',
        'source' => 'source_filter',
        'visitor_id_cookie' => 'visitor_id_cookie_filter',
        'country_code' => 'country_code_filter',
        'date_from' => 'date_from_filter',
        'date_to' => 'date_to_filter',
        'timezone' => 'timezone_filter',
        'period_preset' => 'period_preset_filter',
    ];
    $available_timezones = kssl_get_available_timezones();
    $current_timezone = isset($current_filters['timezone_filter']) ? $current_filters['timezone_filter'] : 'UTC';
    
    // Determine current period preset
    $current_period_preset = '3months'; // Default
    if (isset($current_filters['period_preset_filter'])) {
        $current_period_preset = $current_filters['period_preset_filter'];
    } elseif (!empty($current_filters['date_from_filter']) || !empty($current_filters['date_to_filter'])) {
        $current_period_preset = 'custom';
    }
    ?>
    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
        <input type="hidden" name="orderby" value="<?php echo esc_attr($current_orderby); ?>" />
        <input type="hidden" name="order" value="<?php echo esc_attr($current_order); ?>" />

        <div class="kssl-filters alignleft actions bulkactions" style="padding: 10px 0; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <select name="period_preset_filter" title="<?php esc_attr_e('期間プリセット', 'kashiwazaki-seo-super-access-log'); ?>" onchange="kssl_toggle_custom_dates(this.value)">
                <option value="24hours" <?php selected($current_period_preset, '24hours'); ?>><?php esc_html_e('過去24時間', 'kashiwazaki-seo-super-access-log'); ?></option>
                <option value="1week" <?php selected($current_period_preset, '1week'); ?>><?php esc_html_e('過去1週間', 'kashiwazaki-seo-super-access-log'); ?></option>
                <option value="1month" <?php selected($current_period_preset, '1month'); ?>><?php esc_html_e('過去1ヶ月', 'kashiwazaki-seo-super-access-log'); ?></option>
                <option value="3months" <?php selected($current_period_preset, '3months'); ?>><?php esc_html_e('過去3ヶ月', 'kashiwazaki-seo-super-access-log'); ?></option>
                <option value="6months" <?php selected($current_period_preset, '6months'); ?>><?php esc_html_e('過去6ヶ月', 'kashiwazaki-seo-super-access-log'); ?></option>
                <option value="12months" <?php selected($current_period_preset, '12months'); ?>><?php esc_html_e('過去12ヶ月', 'kashiwazaki-seo-super-access-log'); ?></option>
                <option value="custom" <?php selected($current_period_preset, 'custom'); ?>><?php esc_html_e('カスタム期間', 'kashiwazaki-seo-super-access-log'); ?></option>
            </select>
            
            <span id="kssl-custom-dates" style="<?php echo ($current_period_preset === 'custom') ? '' : 'display:none;'; ?>">
                <input type="text" name="date_from_filter" id="kssl-date-from-filter" placeholder="<?php esc_attr_e('開始日', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['date_from_filter'] ?? ''); ?>" size="10" autocomplete="off">
                <input type="text" name="date_to_filter" id="kssl-date-to-filter" placeholder="<?php esc_attr_e('終了日', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['date_to_filter'] ?? ''); ?>" size="10" autocomplete="off">
            </span>

            <input type="text" name="ip_address_filter" placeholder="<?php esc_attr_e('IPアドレス', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['ip_address_filter'] ?? ''); ?>" size="12">
            <input type="text" name="request_uri_filter" placeholder="<?php esc_attr_e('リクエストパス', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['request_uri_filter'] ?? ''); ?>" size="15">
            <input type="text" name="user_agent_filter" placeholder="<?php esc_attr_e('ユーザーエージェント', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['user_agent_filter'] ?? ''); ?>" size="15">
            <input type="text" name="referer_url_filter" placeholder="<?php esc_attr_e('リファラーURL', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['referer_url_filter'] ?? ''); ?>" size="15">
            <input type="text" name="country_code_filter" placeholder="<?php esc_attr_e('国コード', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['country_code_filter'] ?? ''); ?>" size="10">
            <input type="text" name="visitor_id_cookie_filter" placeholder="<?php esc_attr_e('訪問者ID（Cookie）', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['visitor_id_cookie_filter'] ?? ''); ?>" size="20">
            <input type="number" name="status_code_filter" placeholder="<?php esc_attr_e('ステータス', 'kashiwazaki-seo-super-access-log');?>" value="<?php echo esc_attr($current_filters['status_code_filter'] ?? ''); ?>" style="width:70px;" min="100" max="599">

            <select name="user_id_filter" title="<?php esc_attr_e('ユーザーログイン状態でフィルター', 'kashiwazaki-seo-super-access-log');?>">
                <option value=""><?php esc_html_e('すべてのユーザー', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="logged_in" <?php selected($current_filters['user_id_filter'] ?? '', 'logged_in'); ?>><?php esc_html_e('ログイン中', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="not_logged_in" <?php selected($current_filters['user_id_filter'] ?? '', 'not_logged_in'); ?>><?php esc_html_e('非ログイン', 'kashiwazaki-seo-super-access-log');?></option>
            </select>
            <select name="is_bot_filter" title="<?php esc_attr_e('ボット検出でフィルター', 'kashiwazaki-seo-super-access-log');?>">
                <option value=""><?php esc_html_e('すべて（ボット含む）', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="yes" <?php selected($current_filters['is_bot_filter'] ?? '', 'yes'); ?>><?php esc_html_e('ボット: はい', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="no" <?php selected($current_filters['is_bot_filter'] ?? '', 'no'); ?>><?php esc_html_e('ボット: いいえ', 'kashiwazaki-seo-super-access-log');?></option>
            </select>
            
            <select name="visit_type_filter" title="<?php esc_attr_e('訪問タイプでフィルター', 'kashiwazaki-seo-super-access-log');?>">
                <option value=""><?php esc_html_e('すべての訪問タイプ', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="new" <?php selected($current_filters['visit_type_filter'] ?? '', 'new'); ?>><?php esc_html_e('新規', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="returning" <?php selected($current_filters['visit_type_filter'] ?? '', 'returning'); ?>><?php esc_html_e('リピーター', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="transition" <?php selected($current_filters['visit_type_filter'] ?? '', 'transition'); ?>><?php esc_html_e('ページ遷移', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="unknown" <?php selected($current_filters['visit_type_filter'] ?? '', 'unknown'); ?>><?php esc_html_e('不明', 'kashiwazaki-seo-super-access-log');?></option>
            </select>
            
            <select name="source_filter" title="<?php esc_attr_e('ログソースでフィルター', 'kashiwazaki-seo-super-access-log');?>">
                <option value=""><?php esc_html_e('すべてのソース', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="wordpress" <?php selected($current_filters['source_filter'] ?? '', 'wordpress'); ?>><?php esc_html_e('WordPress', 'kashiwazaki-seo-super-access-log');?></option>
                <option value="static" <?php selected($current_filters['source_filter'] ?? '', 'static'); ?>><?php esc_html_e('静的ページ', 'kashiwazaki-seo-super-access-log');?></option>
            </select>
             <select name="timezone_filter" title="<?php esc_attr_e('表示タイムゾーン', 'kashiwazaki-seo-super-access-log'); ?>">
                <?php foreach ( $available_timezones as $tz_value => $tz_label ) : ?>
                    <option value="<?php echo esc_attr( $tz_value ); ?>" <?php selected( $current_timezone, $tz_value ); ?>>
                        <?php echo esc_html( $tz_label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="<?php esc_attr_e('フィルター', 'kashiwazaki-seo-super-access-log'); ?>">
             <?php
             $is_any_filter_active = false;
             foreach ($filter_fields_map as $db_field => $request_key_check) {
                 if ($request_key_check === 'timezone_filter' && (!isset($current_filters[$request_key_check]) || $current_filters[$request_key_check] === 'UTC')) {
                     continue; 
                 }
                 if ($request_key_check === 'period_preset_filter' && (!isset($current_filters[$request_key_check]) || $current_filters[$request_key_check] === '3months')) {
                     continue; 
                 }
                 if (!empty($current_filters[$request_key_check])) {
                     $is_any_filter_active = true;
                     break;
                 }
             }
             if ($is_any_filter_active): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug)); ?>" class="button"><?php esc_html_e('フィルターをクリア', 'kashiwazaki-seo-super-access-log'); ?></a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}