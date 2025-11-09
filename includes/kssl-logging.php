<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KSSL_Request_Cache {
    private static $options = [];

    public static function get_option($key, $default = false) {
        if (!isset(self::$options[$key])) {
            self::$options[$key] = get_option($key, $default);
        }
        return self::$options[$key];
    }

    public static function get_blocked_visitor_ids() {
        if (!isset(self::$options[KSSL_BLOCKED_VISITORS_OPTION_KEY])) {
             $blocked_ids = get_option(KSSL_BLOCKED_VISITORS_OPTION_KEY, []);
             self::$options[KSSL_BLOCKED_VISITORS_OPTION_KEY] = is_array($blocked_ids) ? $blocked_ids : [];
        }
        return self::$options[KSSL_BLOCKED_VISITORS_OPTION_KEY];
    }

    public static function get_blocked_user_agents() {
        $cache_key = KSSL_BLOCKED_UAS_OPTION_KEY;
        if (!isset(self::$options[$cache_key])) {
            self::$options[$cache_key] = get_option($cache_key, '');
        }
        return self::$options[$cache_key];
    }

    public static function get_bot_detection_pattern() {
        $cache_key = 'kssl_bot_detection_pattern';
        if (!isset(self::$options[$cache_key])) {
            self::$options[$cache_key] = get_option($cache_key, '/(bot|crawl|slurp|spider|archiv|seek|extract|fetch|seeker|scan|survey|Mediapartners-Google|AdsBot-Google|FeedFetcher|Googlebot|bingbot|msnbot|YandexBot|Baiduspider|facebookexternalhit|twitterbot|linkedinbot|embedly|pinterest|SemrushBot|AhrefsBot|MJ12bot|Applebot|DuckDuckBot|BLEXBot|DotBot|Exabot|Sogou|ia_archiver|UptimeRobot|Linespider|GPTBot|ChatGPT-User|Google-Extended|ClaudeBot|Claude-Web|PerplexityBot|Applebot-Extended|CCBot|OAI-SearchBot|anthropic-ai|cohere-ai|ICC-Crawler|Bytespider|Meta-ExternalAgent)/i');
        }
        return self::$options[$cache_key];
    }

    public static function get_enable_country_lookup() {
        $cache_key = KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY;
        if (!isset(self::$options[$cache_key])) {
            self::$options[$cache_key] = get_option($cache_key, 0);
        }
        return self::$options[$cache_key];
    }
}

function kssl_handle_cookie_and_log_init_hook() {
    if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }

    $blocked_ua_raw = KSSL_Request_Cache::get_blocked_user_agents();
    if ( ! empty($blocked_ua_raw) && isset($_SERVER['HTTP_USER_AGENT']) ) {
        $blocked_ua_patterns = array_filter(array_map('trim', explode("\n", $blocked_ua_raw)));
        if (!empty($blocked_ua_patterns)) {
            $current_user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
            foreach ($blocked_ua_patterns as $pattern) {
                if (stripos($current_user_agent, $pattern) !== false) {
                    $block_message = apply_filters('kssl_ua_block_message', __('Your access to this site has been temporarily restricted due to your User Agent. Please contact the site administrator if you believe this is an error.', 'kashiwazaki-seo-super-access-log'));
                    $block_title = apply_filters('kssl_ua_block_title', __('Access Restricted', 'kashiwazaki-seo-super-access-log'));
                    wp_die( esc_html($block_message), esc_html($block_title), ['response' => 503, 'link_url' => home_url(), 'link_text' => __('Go to Homepage', 'kashiwazaki-seo-super-access-log')] );
                }
            }
        }
    }

    $full_cookie_name = kssl_get_full_cookie_name();
    $visitor_id_cookie_base = null;
    $visitor_id_suffix = KSSL_COOKIE_SUFFIX;
    $current_time = time();
    $cookie_visit_type = 'new';
    $visitor_data = null;

    if ( isset( $_COOKIE[ $full_cookie_name ] ) ) {
        $cookie_raw_value = sanitize_text_field(wp_unslash($_COOKIE[ $full_cookie_name ]));
        $decoded_data = json_decode( $cookie_raw_value , true );
        if (is_null($decoded_data) && strpos($cookie_raw_value, '\\') !== false) {
            $decoded_data = json_decode( stripslashes($cookie_raw_value) , true );
        }

        if ( is_array( $decoded_data ) ) {
            $visitor_data = $decoded_data;
            if (isset( $visitor_data['last_visit'] ) && is_numeric($visitor_data['last_visit'])) {
                $last_visit_time = (int)$visitor_data['last_visit'];
                $session_threshold = apply_filters('kssl_session_threshold_seconds', 30 * 60);
                if ( ($current_time - $last_visit_time) < $session_threshold ) {
                     $cookie_visit_type = 'returning_session';
                } else {
                     $cookie_visit_type = 'returning';
                }
            }

            if (isset($visitor_data['vid_base']) && !empty($visitor_data['vid_base']) && strlen($visitor_data['vid_base']) === 36) {
                $visitor_id_cookie_base = sanitize_text_field($visitor_data['vid_base']);
            } else {
                if (isset($visitor_data['vid']) && !empty($visitor_data['vid'])) {
                    $potential_vid = sanitize_text_field($visitor_data['vid']);
                    if (str_ends_with($potential_vid, $visitor_id_suffix) && strlen(str_replace($visitor_id_suffix, '', $potential_vid)) === 36) {
                         $visitor_id_cookie_base = str_replace($visitor_id_suffix, '', $potential_vid);
                    } elseif (strlen($potential_vid) === 36) {
                        $visitor_id_cookie_base = $potential_vid;
                    }
                }
                if (empty($visitor_id_cookie_base)) {
                    $visitor_id_cookie_base = wp_generate_uuid4();
                }
            }
        } else {
            $visitor_id_cookie_base = wp_generate_uuid4();
        }
    } else {
        $visitor_id_cookie_base = wp_generate_uuid4();
    }

    $full_visitor_id_with_suffix = $visitor_id_cookie_base . $visitor_id_suffix;

    $blocked_visitor_ids = KSSL_Request_Cache::get_blocked_visitor_ids();
    if ( in_array( $full_visitor_id_with_suffix, $blocked_visitor_ids ) ) {
        $block_message = apply_filters('kssl_visitor_block_message', __('Your access to this site has been temporarily restricted due to unusual activity. Please try again later or contact the site administrator if you believe this is an error.', 'kashiwazaki-seo-super-access-log'));
        $block_title = apply_filters('kssl_visitor_block_title', __('Access Restricted', 'kashiwazaki-seo-super-access-log'));
        wp_die( esc_html($block_message), esc_html($block_title), ['response' => 503, 'link_url' => home_url(), 'link_text' => __('Go to Homepage', 'kashiwazaki-seo-super-access-log')] );
    }

    // 静的ファイルの除外（オプション設定に基づく）
    if (get_option(KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY, '1') === '1') {
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri_path = parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
            // カスタムパターンまたはデフォルトパターンを使用
            $pattern = get_option(KSSL_STATIC_FILES_PATTERN_OPTION_KEY, KSSL_DEFAULT_STATIC_FILES_PATTERN);
            if ($request_uri_path && preg_match($pattern, $request_uri_path)) {
                return;
            }
        }
    }
    
    $cookie_lifetime_hours = (int) KSSL_Request_Cache::get_option( 'kssl_cookie_lifetime_hours', KSSL_DEFAULT_COOKIE_LIFETIME );
    $cookie_lifetime_seconds = $cookie_lifetime_hours * HOUR_IN_SECONDS;

    $new_visitor_data = [
        'vid_base'    => $visitor_id_cookie_base,
        'first_visit' => isset( $visitor_data['first_visit'] ) && is_numeric($visitor_data['first_visit']) ? (int)$visitor_data['first_visit'] : $current_time,
        'last_visit'  => $current_time,
    ];

    $secure_cookie = is_ssl();
    $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

    if (!headers_sent()) {
        setcookie(
            $full_cookie_name,
            wp_json_encode( $new_visitor_data ),
            [
                'expires' => $current_time + $cookie_lifetime_seconds,
                'path' => $cookie_path,
                'domain' => $cookie_domain,
                'secure' => $secure_cookie,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    $bot_detection_pattern = KSSL_Request_Cache::get_bot_detection_pattern();

    add_action( 'shutdown', function() use ($cookie_visit_type, $full_visitor_id_with_suffix, $bot_detection_pattern) {
        kssl_record_access_log_func( $cookie_visit_type, 'wordpress', [], $full_visitor_id_with_suffix, $bot_detection_pattern );
    }, 99 );
}

function kssl_record_access_log_func( $cookie_visit_type = 'new', $source = 'wordpress', $static_data = [], $visitor_id_from_cookie = null, $bot_detection_pattern = '' ) {
    global $wpdb;

    if ( $source === 'wordpress') {
         if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( is_admin() && ! wp_doing_ajax() ) ) return;
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( $_SERVER['REQUEST_METHOD'] ) === 'HEAD' ) return;
        if ( isset( $_SERVER['HTTP_X_MOZ'] ) && strtolower( $_SERVER['HTTP_X_MOZ'] ) === 'prefetch' ) return;
        if ( isset( $_SERVER['HTTP_X_PURPOSE'] ) && strtolower( $_SERVER['HTTP_X_PURPOSE'] ) === 'preview' ) return;
        if ( isset( $_SERVER['HTTP_PURPOSE'] ) && strtolower( $_SERVER['HTTP_PURPOSE'] ) === 'preview' ) return;
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri_path = parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
            if ($request_uri_path && preg_match('/\.(css|js|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot|map)(\?.*)?$/i', $request_uri_path)) return;
        }
    }

    $ip_address_val = kssl_get_ip_address();

    // 自サーバーのIPアドレスからのアクセスを除外チェック
    if (get_option(KSSL_EXCLUDE_SELF_SERVER_IP_OPTION_KEY, 1)) {
        $server_ip = kssl_get_server_ip();
        if (!empty($server_ip) && $ip_address_val === $server_ip) {
            return;
        }
    }
    $user_agent_val = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    $request_uri_val = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    $referer_url_val = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
    $request_method_val = isset( $_SERVER['REQUEST_METHOD'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ), 0, 10 ) : '';
    $current_user_id = ($source === 'wordpress') ? get_current_user_id() : null;

    // 国コード取得：まずヘッダーから（常に実行、軽量）
    $country_code_val = kssl_get_country_code_from_headers();

    // ヘッダーから取得できず、かつIPルックアップが有効な場合のみ外部API呼び出し
    if ($country_code_val === null && KSSL_Request_Cache::get_enable_country_lookup()) {
        $country_code_val = kssl_get_country_code_from_ip_func($ip_address_val);
    }

    if ($source === 'static' && !empty($static_data)) {
        $user_agent_val = isset($static_data['ua']) ? sanitize_text_field( wp_unslash($static_data['ua'])) : $user_agent_val;
        $request_uri_val = isset($static_data['url']) ? esc_url_raw($static_data['url']) : $request_uri_val;
        $referer_url_val = isset($static_data['referrer']) ? esc_url_raw($static_data['referrer']) : $referer_url_val;
        $request_method_val = 'POST';
    }

    $status_code_val = http_response_code();
    if ( !is_int($status_code_val) || $status_code_val < 100) {
        if (function_exists('apache_response_headers')) {
            $headers = apache_response_headers();
            if (isset($headers['Status']) && preg_match('/^(\d{3})/', $headers['Status'], $matches)) $status_code_val = intval($matches[1]);
            elseif (isset($headers['status']) && preg_match('/^(\d{3})/', $headers['status'], $matches)) $status_code_val = intval($matches[1]);
        }
    }
     if (!is_int($status_code_val) || $status_code_val < 100) {
         if ($source === 'static') $status_code_val = 200;
         elseif (function_exists('is_404') && is_404()) $status_code_val = 404;
         elseif (did_action('template_redirect') || did_action('wp_head')) $status_code_val = 200;
         else $status_code_val = 200;
    }

    $is_bot_val = kssl_is_bot_with_pattern_func( $user_agent_val, $bot_detection_pattern );
    $final_visit_type = 'unknown';

    if ($cookie_visit_type === 'new') {
        $final_visit_type = 'new';
    } else {
        $referer_for_nav_check = ($source === 'static' && isset($static_data['referrer'])) ? $static_data['referrer'] : $referer_url_val;
        $is_internal_navigation = false;

        if (!empty($referer_for_nav_check)) {
            $site_url = home_url();
            if (!empty($site_url)) {
                $site_url_parsed = wp_parse_url($site_url);
                $referer_url_parsed = wp_parse_url($referer_for_nav_check);

                if (isset($site_url_parsed['host']) && !empty($site_url_parsed['host']) && 
                    isset($referer_url_parsed['host']) && !empty($referer_url_parsed['host'])) {
                    
                    $site_host = strtolower($site_url_parsed['host']);
                    $referer_host = strtolower($referer_url_parsed['host']);
                    $normalized_site_host = preg_replace('/^www\./i', '', $site_host);
                    $normalized_referer_host = preg_replace('/^www\./i', '', $referer_host);

                    if ($normalized_referer_host === $normalized_site_host) {
                        $is_internal_navigation = true;
                    }
                }
            }
        }

        if ($is_internal_navigation) {
            $final_visit_type = 'transition';
        } else {
            $final_visit_type = 'returning'; 
        }
    }
    
    if ($is_bot_val && $status_code_val == 404 && apply_filters('kssl_skip_bot_404', true)) {
        return;
    }

    // User-Agentが空のアクセスの処理
    if (empty($user_agent_val)) {
        // チェックがオンの場合はアクセスをブロック（403エラー）
        if (get_option(KSSL_BLOCK_EMPTY_UA_OPTION_KEY, false)) {
            wp_die(__('Forbidden: User-Agent required', 'kashiwazaki-seo-super-access-log'), 403);
            return;
        }
        // デフォルト: 記録しない（アクセスは許可するが、ログに残さない）
        return;
    }

    $table_name = kssl_get_log_table_name_func();
    $wpdb->insert(
        $table_name,
        [
            'access_time'    => current_time( 'mysql', 1 ),
            'ip_address'     => $ip_address_val,
            'user_agent'     => $user_agent_val,
            'request_uri'    => $request_uri_val,
            'referer_url'    => $referer_url_val,
            'request_method' => $request_method_val,
            'status_code'    => $status_code_val,
            'user_id'        => $current_user_id > 0 ? $current_user_id : null,
            'is_bot'         => $is_bot_val ? 1 : 0,
            'visit_type'     => $final_visit_type,
            'source'         => $source,
            'visitor_id_cookie' => $visitor_id_from_cookie,
            'country_code'   => $country_code_val,
            'navigation_type'=> 'deprecated',
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
    );
}

function kssl_admin_session_start_hook() {
    if (is_admin() && current_user_can('manage_options')) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start(['read_and_close' => true]);
        }
    }
}

/**
 * 古いログを自動的にクリーンアップする
 */
function kssl_cleanup_old_logs() {
    global $wpdb;
    
    // 自動クリーンアップが有効かチェック
    if (!get_option('kssl_enable_auto_cleanup', 0)) {
        return;
    }
    
    // 保存期間を取得
    $retention_days = intval(get_option('kssl_log_retention_days', 0));
    if ($retention_days <= 0) {
        return;
    }
    
    $table_name = kssl_get_log_table_name_func();
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
    
    // バッチ処理で削除（一度に大量のレコードを削除しないように）
    $batch_size = 1000;
    $deleted_total = 0;
    
    do {
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE access_time < %s LIMIT %d",
                $cutoff_date,
                $batch_size
            )
        );
        $deleted_total += $deleted;
        
        // 少し待機してサーバー負荷を軽減
        if ($deleted > 0) {
            usleep(100000); // 0.1秒待機
        }
    } while ($deleted >= $batch_size);
    
    // クリーンアップの実行をログに記録（オプション）
    if ($deleted_total > 0) {
        update_option('kssl_last_cleanup_date', current_time('mysql'));
        update_option('kssl_last_cleanup_count', $deleted_total);
    }
}

/**
 * WP-Cronのスケジュールを登録
 */
function kssl_schedule_cleanup_cron() {
    // 既存のスケジュールをクリア（古い名前も含めて）
    wp_clear_scheduled_hook('kssl_daily_cleanup');
    wp_clear_scheduled_hook('kssl_cleanup_old_logs');

    // 自動クリーンアップが有効な場合のみスケジュール
    $enable_auto_cleanup = get_option(KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 0);
    if ($enable_auto_cleanup) {
        if (!wp_next_scheduled('kssl_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'kssl_cleanup_old_logs');
        }
    }
}

/**
 * WP-Cronのスケジュールを削除
 */
function kssl_unschedule_cleanup_cron() {
    $timestamp = wp_next_scheduled('kssl_cleanup_old_logs');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'kssl_cleanup_old_logs');
    }
    // 古い名前のスケジュールもクリア
    $timestamp_old = wp_next_scheduled('kssl_daily_cleanup');
    if ($timestamp_old) {
        wp_unschedule_event($timestamp_old, 'kssl_daily_cleanup');
    }
}

// Cronアクションを登録
add_action('kssl_cleanup_old_logs', 'kssl_cleanup_old_logs');