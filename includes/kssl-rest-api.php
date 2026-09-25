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
    if ( !isset($params['u']) || !is_string($params['u']) || strlen($params['u']) > 2048 || !filter_var($params['u'], FILTER_VALIDATE_URL) ) {
        return new WP_REST_Response( ['message' => 'Invalid data: URL (u) is required and must be valid.'], 400 );
    }
    // Only pages of this site are recorded (the tracking snippet calls this endpoint same-origin);
    // other hosts can be allowed with the 'kssl_track_allowed_hosts' filter.
    $page_host = strtolower( (string) wp_parse_url( $params['u'], PHP_URL_HOST ) );
    if ( ! kssl_track_host_allowed( $page_host ) ) {
        return new WP_REST_Response( ['message' => 'Invalid data: URL (u) is not a page of this site.'], 400 );
    }

    $text = function ( $key, $max ) use ( $params ) {
        return isset( $params[ $key ] ) && is_string( $params[ $key ] ) ? substr( $params[ $key ], 0, $max ) : '';
    };
    $static_data = [
        'url'      => sanitize_url($params['u']),
        'referrer' => $text( 'r', 2048 ) !== '' ? sanitize_url( $text( 'r', 2048 ) ) : '',
        'ua'       => sanitize_text_field( wp_unslash( $text( 'ua', 512 ) ) ),
        'title'    => sanitize_text_field( $text( 't', 255 ) ),
    ];

    $cookie_visit_type_static = 'new';
    $visitor_id_base_for_static = null;
    $visitor_id_suffix = KSSL_COOKIE_SUFFIX;
    $full_cookie_name = kssl_get_full_cookie_name();

    $full_visitor_id_with_suffix_for_static = '';

    // The init hook of this same request already decided the visitor (and sent the cookie when
    // it was missing): use that id, so the first view is not recorded under a second, unknown id.
    if ( isset( $GLOBALS['kssl_current_visitor']['vid_base'] ) && strlen( (string) $GLOBALS['kssl_current_visitor']['vid_base'] ) === 36 ) {
        $visitor_id_base_for_static = $GLOBALS['kssl_current_visitor']['vid_base'];
        $cookie_visit_type_static = $GLOBALS['kssl_current_visitor']['visit_type'];
    } elseif (isset($_COOKIE[$full_cookie_name])) {
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
 * Hosts whose pages the static tracker may record: this site's home and site URL hosts (with and
 * without www), plus the 'kssl_track_allowed_hosts' filter. Subdomains of these hosts are also
 * accepted (kssl_track_host_allowed), as the settings screen describes.
 */
function kssl_track_allowed_hosts() {
    $hosts = [];
    foreach ( [ home_url(), site_url() ] as $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( $host !== '' ) {
            $hosts[] = $host;
            $hosts[] = strpos( $host, 'www.' ) === 0 ? substr( $host, 4 ) : 'www.' . $host;
        }
    }
    $hosts = array_merge( $hosts, (array) apply_filters( 'kssl_track_allowed_hosts', [] ) );
    return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $hosts ) ) ) );
}

/**
 * True for an allowed host or a subdomain of one (static.example.com for example.com). Another
 * domain that merely ends with the same letters (evilexample.com) is not a subdomain.
 */
function kssl_track_host_allowed( $host ) {
    $host = strtolower( rtrim( (string) $host, '.' ) );
    if ( $host === '' ) {
        return false;
    }
    foreach ( kssl_track_allowed_hosts() as $allowed ) {
        $allowed = strpos( $allowed, 'www.' ) === 0 ? substr( $allowed, 4 ) : $allowed;
        if ( $allowed === '' ) {
            continue;
        }
        if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
            return true;
        }
    }
    return false;
}

/**
 * データベース最適化リクエストのハンドラー
 */
function kssl_handle_optimize_db_request( WP_REST_Request $request ) {
    if (!current_user_can('manage_options')) {
        return new WP_REST_Response( ['message' => 'Unauthorized'], 401 );
    }
    // Refresh optimizer statistics only (ANALYZE TABLE); indexes come from the table schema.
    if ( KSSL_Performance::optimize_database_complete() ) {
        return new WP_REST_Response( [
            'status' => 'success',
            'message' => 'データベースの統計情報を更新しました。'
        ], 200 );
    }
    return new WP_REST_Response( [
        'status' => 'error',
        'message' => '統計情報の更新に失敗しました。'
    ], 500 );
}