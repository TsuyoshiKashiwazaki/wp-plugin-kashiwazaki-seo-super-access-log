<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kssl_register_static_tracking_endpoint_hook() {
    // 静的トラッキングエンドポイント
    if (get_option('kssl_static_tracking_enabled', 0)) {
        register_rest_route( 'kashiwazaki-seo-super-access-log/v1', '/track', [
            'methods'  => ['POST', 'OPTIONS'],
            'callback' => 'kssl_handle_static_track_request_cb',
            'permission_callback' => '__return_true',
        ] );
    }

    // データベース最適化エンドポイント（常に登録）
    register_rest_route( 'kashiwazaki-seo-super-access-log/v1', '/optimize-db', [
        'methods'  => 'POST',
        'callback' => 'kssl_handle_optimize_db_request',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ] );
}

function kssl_handle_static_track_request_cb( WP_REST_Request $request ) {
    if (!KSSL_Request_Cache::get_option('kssl_static_tracking_enabled', 0)) {
        return new WP_REST_Response( ['message' => 'Static tracking disabled.'], 403 );
    }
    if ($request->get_method() === 'OPTIONS') {
        return new WP_REST_Response(null, 204);
    }
    if ($request->get_method() !== 'POST') {
        return new WP_REST_Response( ['message' => 'Method not allowed.'], 405 );
    }

    $params = $request->get_json_params();
    if (empty($params)) {
        $raw_body = $request->get_body();
        if (!empty($raw_body)) {
            $decoded_body = json_decode($raw_body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $params = $decoded_body;
            }
        }
    }

    if ( empty( $params ) ) {
        return new WP_REST_Response( ['message' => 'Invalid data: Could not read/parse request body.'], 400 );
    }
    if ( !isset($params['u']) || !filter_var($params['u'], FILTER_VALIDATE_URL) ) {
        return new WP_REST_Response( ['message' => 'Invalid data: URL (u) is required and must be valid.'], 400 );
    }

    $static_data = [
        'url'      => sanitize_url($params['u']),
        'referrer' => isset($params['r']) ? sanitize_url($params['r']) : '',
        'ua'       => isset($params['ua']) ? sanitize_text_field( wp_unslash($params['ua'])) : '',
        'title'    => isset($params['t']) ? sanitize_text_field($params['t']) : '',
    ];

    $cookie_visit_type_static = 'new';
    $visitor_id_base_for_static = null;
    $visitor_id_suffix = KSSL_COOKIE_SUFFIX;
    $full_cookie_name = kssl_get_full_cookie_name();
    
    $full_visitor_id_with_suffix_for_static = '';

    if (isset($_COOKIE[$full_cookie_name])) {
        $cookie_raw_value_global = sanitize_text_field(wp_unslash($_COOKIE[$full_cookie_name]));
        $decoded_data_global = json_decode(stripslashes($cookie_raw_value_global), true);
        if (is_array($decoded_data_global) && isset($decoded_data_global['vid_base']) && !empty($decoded_data_global['vid_base']) && strlen($decoded_data_global['vid_base']) === 36) {
            $visitor_id_base_for_static = sanitize_text_field($decoded_data_global['vid_base']);
            if (isset($decoded_data_global['last_visit']) && is_numeric($decoded_data_global['last_visit'])) {
                $cookie_visit_type_static = 'returning_session'; // Default for existing cookie, logic in record_access_log will refine
            }
        }
    }

    if (empty($visitor_id_base_for_static)) {
        $all_cookies_raw_header = $request->get_header('cookie');
        if ($all_cookies_raw_header) {
            $raw_cookie_parts = explode(';', $all_cookies_raw_header);
            $parsed_cookies_header = [];
            foreach ($raw_cookie_parts as $cookie_part) {
                $name_value = explode('=', trim($cookie_part), 2);
                if (count($name_value) === 2) {
                    $parsed_cookies_header[trim($name_value[0])] = trim($name_value[1]);
                }
            }

            if (isset($parsed_cookies_header[$full_cookie_name])) {
                $cookie_value_from_header = sanitize_text_field($parsed_cookies_header[$full_cookie_name]);
                $decoded_cookie_value_header = null;

                $try_decode_patterns = [
                    fn($val) => json_decode(stripslashes(urldecode($val)), true),
                    fn($val) => json_decode(stripslashes($val), true),
                    fn($val) => json_decode(urldecode($val), true),
                    fn($val) => json_decode($val, true)
                ];

                foreach($try_decode_patterns as $decode_func) {
                    $temp_decoded = $decode_func($cookie_value_from_header);
                    if (is_array($temp_decoded)) {
                        if (isset($temp_decoded['vid_base']) && strlen($temp_decoded['vid_base']) === 36) {
                            $decoded_cookie_value_header = $temp_decoded;
                            break;
                        } elseif (isset($temp_decoded['vid']) && strlen($temp_decoded['vid']) === 36) {
                             $decoded_cookie_value_header = ['vid_base' => $temp_decoded['vid']];
                             if(isset($temp_decoded['last_visit'])) $decoded_cookie_value_header['last_visit'] = $temp_decoded['last_visit'];
                             break;
                        } elseif (isset($temp_decoded['vid']) && str_ends_with($temp_decoded['vid'], $visitor_id_suffix) && strlen(str_replace($visitor_id_suffix, '', $temp_decoded['vid'])) === 36 ){
                            $base_id = str_replace($visitor_id_suffix, '', $temp_decoded['vid']);
                            $decoded_cookie_value_header = ['vid_base' => $base_id];
                            if(isset($temp_decoded['last_visit'])) $decoded_cookie_value_header['last_visit'] = $temp_decoded['last_visit'];
                            break;
                        }
                    }
                }

                if (is_array($decoded_cookie_value_header) && isset($decoded_cookie_value_header['vid_base'])) {
                     if (isset($decoded_cookie_value_header['last_visit']) && is_numeric($decoded_cookie_value_header['last_visit'])) {
                         if(empty($visitor_id_base_for_static)) $cookie_visit_type_static = 'returning_session';
                    }
                    if (!empty($decoded_cookie_value_header['vid_base']) && strlen($decoded_cookie_value_header['vid_base']) === 36) {
                        $visitor_id_base_for_static = sanitize_text_field($decoded_cookie_value_header['vid_base']);
                    }
                }
            }
        }
    }

    if (empty($visitor_id_base_for_static)) {
        $visitor_id_base_for_static = wp_generate_uuid4();
        $cookie_visit_type_static = 'new';
    }

    $full_visitor_id_with_suffix_for_static = $visitor_id_base_for_static . $visitor_id_suffix;

    $blocked_visitor_ids = KSSL_Request_Cache::get_blocked_visitor_ids();
    if (in_array($full_visitor_id_with_suffix_for_static, $blocked_visitor_ids)) {
         return new WP_REST_Response( ['message' => 'Access Restricted.'], 503 );
    }

    $bot_detection_pattern_for_static = KSSL_Request_Cache::get_bot_detection_pattern();

    kssl_record_access_log_func( 
        $cookie_visit_type_static, 
        'static', 
        $static_data, 
        $full_visitor_id_with_suffix_for_static,
        $bot_detection_pattern_for_static
    );
    return new WP_REST_Response( ['status' => 'logged', 'message' => 'Log entry created.'], 201 );
}

/**
 * データベース最適化リクエストのハンドラー
 */
function kssl_handle_optimize_db_request( WP_REST_Request $request ) {
    if (!current_user_can('manage_options')) {
        return new WP_REST_Response( ['message' => 'Unauthorized'], 401 );
    }

    // 最適化ファイルを読み込み
    $optimizer_file = plugin_dir_path(__FILE__) . 'kssl-chart-optimizer.php';
    if (!file_exists($optimizer_file)) {
        return new WP_REST_Response( [
            'status' => 'error',
            'message' => '最適化モジュールが見つかりません。'
        ], 500 );
    }

    require_once $optimizer_file;

    if (!function_exists('kssl_optimize_database_indexes')) {
        return new WP_REST_Response( [
            'status' => 'error',
            'message' => '最適化関数が見つかりません。'
        ], 500 );
    }

    try {
        // インデックス最適化を実行
        kssl_optimize_database_indexes();

        // キャッシュをクリア
        global $wpdb;
        $wpdb->query("FLUSH QUERY CACHE");

        return new WP_REST_Response( [
            'status' => 'success',
            'message' => 'データベースインデックスの最適化が完了しました。チャートの表示速度が改善されているはずです。'
        ], 200 );
    } catch (Exception $e) {
        return new WP_REST_Response( [
            'status' => 'error',
            'message' => 'エラーが発生しました: ' . $e->getMessage()
        ], 500 );
    }
}