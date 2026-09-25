<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ログテーブル名を取得
 *
 * @return string テーブル名
 */
function kssl_get_log_table_name_func() {
    global $wpdb;
    return $wpdb->prefix . KSSL_LOG_TABLE_NAME_CONST;
}

/**
 * 安全なデータベースクエリ実行
 *
 * @param string $query クエリ
 * @param array $params パラメータ
 * @param string $type クエリタイプ ('get_var', 'get_results', 'get_row')
 * @param mixed $default デフォルト値
 * @return mixed
 */
function kssl_safe_db_query($query, $params = [], $type = 'get_results', $default = null) {
    global $wpdb;
    
    try {
        $prepared_query = empty($params) ? $query : $wpdb->prepare($query, ...$params);
        
        switch ($type) {
            case 'get_var':
                $result = $wpdb->get_var($prepared_query);
                return $result !== null ? $result : $default;
                
            case 'get_row':
                $result = $wpdb->get_row($prepared_query);
                return $result !== null ? $result : $default;
                
            case 'get_results':
            default:
                $result = $wpdb->get_results($prepared_query);
                return $result !== null ? $result : ($default !== null ? $default : []);
        }
    } catch (Exception $e) {
        kssl_log_error('Database query error: ' . $e->getMessage());
        return $default !== null ? $default : [];
    }
}

/**
 * エラーログの記録
 *
 * @param string $message エラーメッセージ
 * @param string $context コンテキスト
 */
function kssl_log_error($message, $context = 'KSSL') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($context . ': ' . $message);
    }
}

/**
 * パフォーマンス設定の適用
 */
function kssl_apply_performance_settings() {
    @set_time_limit(KSSL_MAX_EXECUTION_TIME);
    @ini_set('memory_limit', KSSL_MAX_MEMORY_LIMIT);
}

/**
 * IPアドレスの取得
 *
 * @return string IPアドレス
 */
/**
 * Visitor IP. Forwarding headers are client-controlled, so they are used only when the direct
 * peer (REMOTE_ADDR) is a proxy that sets them:
 * - a Cloudflare address: CF-Connecting-IP (Cloudflare documents this header and advises
 *   accepting it only from its published IP ranges); when a host drops that header, the
 *   X-Forwarded-For list, to which Cloudflare appends the address that connected to it;
 * - a private / loopback address or one listed by the 'kssl_trusted_proxies' filter: the
 *   right-most X-Forwarded-For entry that is not itself such a proxy.
 * Otherwise REMOTE_ADDR.
 */
function kssl_get_ip_address() {
    $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), FILTER_VALIDATE_IP ) : false;
    if ( ! $remote ) {
        return '';
    }
    $cloudflare = kssl_cloudflare_ip_ranges();
    $from_cloudflare = kssl_ip_in_ranges( $remote, $cloudflare );
    if ( $from_cloudflare && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        $cf = filter_var( trim( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ), FILTER_VALIDATE_IP );
        if ( $cf ) {
            return $cf;
        }
    }
    if ( ( $from_cloudflare || kssl_is_trusted_proxy( $remote ) ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $hops = array_reverse( array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
        $client = '';
        foreach ( $hops as $hop ) {
            $ip = filter_var( $hop, FILTER_VALIDATE_IP );
            if ( ! $ip ) {
                break; // a malformed entry: nothing to its left can be trusted
            }
            $client = $ip;
            if ( ! kssl_is_trusted_proxy( $ip ) && ! kssl_ip_in_ranges( $ip, $cloudflare ) ) {
                return $ip;
            }
        }
        if ( $client !== '' ) {
            return $client; // every hop is a trusted proxy (e.g. a visitor on the local network)
        }
    }
    return $remote;
}

/**
 * Directory for files that hold log data (CSV exports, uploaded CSV being imported).
 * It lives under uploads in a directory whose name contains a 128-bit random key (web servers
 * that ignore .htaccess, such as nginx, cannot be asked for a file they cannot name), and it is
 * denied with an .htaccess that works on Apache 2.4 ("Require all denied") and on 2.2
 * ("Deny from all"; httpd.apache.org/docs/2.4/upgrading.html). Files are only served through
 * the nonce- and capability-checked admin handlers.
 *
 * @param string $sub 'exports' or 'temp'.
 * @return string Absolute path without trailing slash, or '' when uploads are unavailable.
 */
function kssl_private_dir( $sub ) {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
        return '';
    }
    $key = get_option( 'kssl_private_dir_key' );
    if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
        $key = bin2hex( random_bytes( 16 ) );
        update_option( 'kssl_private_dir_key', $key, false );
    }
    $base = $upload['basedir'] . '/kssl-private-' . $key;
    $dir = $base . '/' . preg_replace( '/[^a-z]/', '', $sub );
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    kssl_protect_dir( $base );
    kssl_protect_dir( $dir );
    return $dir;
}

function kssl_protect_dir( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $htaccess = "# Apache 2.4\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
    if ( ! file_exists( $dir . '/.htaccess' ) || file_get_contents( $dir . '/.htaccess' ) !== $htaccess ) {
        @file_put_contents( $dir . '/.htaccess', $htaccess );
    }
    if ( ! file_exists( $dir . '/index.php' ) ) {
        @file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
    }
}

/**
 * Moves files from the directories used before (uploads/kssl-exports, uploads/kssl-temp, which
 * could be served by nginx) into the private directory, and removes the old directories.
 * An old directory is removed (with its deny rules) only when nothing else is left in it; if a
 * file could not be moved, the directory keeps deny rules that work on Apache 2.4 and 2.2.
 *
 * @return bool True when no old directory is left.
 */
function kssl_move_legacy_private_files() {
    $upload = wp_upload_dir( null, false );
    if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
        return false;
    }
    $all_gone = true;
    $dirs_left = [];
    foreach ( [ 'kssl-exports' => 'exports', 'kssl-temp' => 'temp' ] as $old => $sub ) {
        $old_dir = $upload['basedir'] . '/' . $old;
        if ( ! is_dir( $old_dir ) ) {
            continue;
        }
        $new_dir = kssl_private_dir( $sub );
        foreach ( (array) glob( $old_dir . '/*.csv' ) as $file ) {
            if ( ! is_file( $file ) ) {
                continue;
            }
            $dest = $new_dir !== '' ? $new_dir . '/' . basename( $file ) : '';
            // rename, or copy then remove the original (rename can fail across file systems)
            if ( $dest !== '' && ! @rename( $file, $dest ) && @copy( $file, $dest ) ) {
                @unlink( $file );
            }
        }
        $entries = scandir( $old_dir );
        $left = $entries === false ? [ '?' ] : array_diff( $entries, [ '.', '..', '.htaccess', 'index.php' ] );
        if ( empty( $left ) ) {
            @unlink( $old_dir . '/.htaccess' );
            @unlink( $old_dir . '/index.php' );
            @rmdir( $old_dir );
        } else {
            kssl_protect_dir( $old_dir );
        }
        if ( is_dir( $old_dir ) ) {
            // Still there: files could not be moved or removed, or could not even be listed
            $all_gone = false;
            $dirs_left[] = $old;
        }
    }
    // Read by kssl_legacy_private_notice: old public directories that are still there
    if ( $dirs_left ) {
        update_option( 'kssl_legacy_private_left', $dirs_left, false );
    } else {
        delete_option( 'kssl_legacy_private_left' );
    }
    return $all_gone;
}

/**
 * Runs the move on the first request after an update (not only when the export screen is
 * opened), until no old directory is left. The flag is autoloaded, so later requests only read it.
 */
function kssl_maybe_move_legacy_private_files() {
    if ( get_option( 'kssl_legacy_private_moved' ) === '1' || get_transient( 'kssl_legacy_move_wait' ) ) {
        return;
    }
    if ( kssl_move_legacy_private_files() ) {
        update_option( 'kssl_legacy_private_moved', '1', true );
    } else {
        // Something could not be moved (file owner / permissions): retry every 10 minutes, not on every request
        set_transient( 'kssl_legacy_move_wait', 1, 10 * MINUTE_IN_SECONDS );
    }
}

/**
 * Tells administrators when an old public directory (which held exported / uploaded CSV files:
 * the whole log, IP addresses included) could not be emptied and removed. Directories removed by
 * hand since the last attempt are dropped at once.
 */
function kssl_legacy_private_notice() {
    $left = get_option( 'kssl_legacy_private_left', [] );
    if ( ! is_array( $left ) || ! $left || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $upload = wp_upload_dir( null, false );
    if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
        return;
    }
    $still = [];
    foreach ( $left as $old ) {
        if ( in_array( $old, [ 'kssl-exports', 'kssl-temp' ], true ) && is_dir( $upload['basedir'] . '/' . $old ) ) {
            $still[] = 'wp-content/uploads/' . $old;
        }
    }
    if ( ! $still ) {
        delete_option( 'kssl_legacy_private_left' );
        return;
    }
    $message = sprintf(
        /* translators: %s: directory paths */
        __( 'Kashiwazaki SEO Super Access Log: 以前のバージョンがエクスポート・インポートの CSV (アクセスログ全件、IP アドレスを含む) を置いていた公開ディレクトリ %s を、空にして削除できませんでした (ファイルの所有者・権限)。nginx などでは URL を知られると中の CSV を取得されます。所有者・権限を直すか、このディレクトリを削除してください。', 'kashiwazaki-seo-super-access-log' ),
        implode( ', ', $still )
    );
    echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * True for a file in the private temp directory (an uploaded CSV being imported).
 */
function kssl_is_import_temp_file( $path ) {
    $dir = kssl_private_dir( 'temp' );
    $real = realpath( (string) $path );
    $real_dir = $dir !== '' ? realpath( $dir ) : false;
    return $real !== false && $real_dir !== false && dirname( $real ) === $real_dir;
}

/**
 * Removes uploaded CSV copies left in the private temp directory after their import job expired
 * (a job lives at most one day, so a copy older than two days has no job).
 */
function kssl_cleanup_import_temp_files() {
    $dir = kssl_private_dir( 'temp' );
    if ( $dir === '' ) {
        return;
    }
    foreach ( (array) glob( $dir . '/*.csv' ) as $file ) {
        if ( is_file( $file ) && filemtime( $file ) < time() - 2 * DAY_IN_SECONDS ) {
            @unlink( $file );
        }
    }
}

/**
 * A proxy whose X-Forwarded-For is believed: private / loopback / reserved addresses (a proxy on
 * the same host or network) and addresses or CIDR ranges from the 'kssl_trusted_proxies' filter.
 */
function kssl_is_trusted_proxy( $ip ) {
    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
        return true;
    }
    return kssl_ip_in_ranges( $ip, (array) apply_filters( 'kssl_trusted_proxies', [] ) );
}

/**
 * Cloudflare's published ranges (https://www.cloudflare.com/ips-v4 and /ips-v6, 2026-09-25).
 * Sites can update the list with the 'kssl_cloudflare_ip_ranges' filter.
 */
function kssl_cloudflare_ip_ranges() {
    return (array) apply_filters( 'kssl_cloudflare_ip_ranges', [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ] );
}

/**
 * True when $ip equals one of $ranges or falls in one of its CIDR blocks (IPv4 and IPv6).
 */
function kssl_ip_in_ranges( $ip, array $ranges ) {
    $bin = @inet_pton( $ip );
    if ( $bin === false ) {
        return false;
    }
    foreach ( $ranges as $range ) {
        $range = trim( (string) $range );
        if ( $range === '' ) {
            continue;
        }
        $parts = explode( '/', $range, 2 );
        $net = @inet_pton( $parts[0] );
        if ( $net === false || strlen( $net ) !== strlen( $bin ) ) {
            continue;
        }
        $bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $bin ) * 8;
        if ( $bits < 0 || $bits > strlen( $bin ) * 8 ) {
            continue;
        }
        $bytes = intdiv( $bits, 8 );
        if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
            continue;
        }
        $rest = $bits % 8;
        if ( $rest === 0 ) {
            return true;
        }
        $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
        if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
            return true;
        }
    }
    return false;
}

/**
 * サーバーのIPアドレスを取得
 *
 * @return string サーバーのIPアドレス
 */
function kssl_get_server_ip() {
    // 複数の方法でサーバーIPを取得を試行
    $server_ip = '';
    
    // SERVER_ADDRから取得
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $server_ip = filter_var(sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])), FILTER_VALIDATE_IP);
        if ($server_ip) return $server_ip;
    }
    
    // LOCAL_ADDRから取得
    if (!empty($_SERVER['LOCAL_ADDR'])) {
        $server_ip = filter_var(sanitize_text_field(wp_unslash($_SERVER['LOCAL_ADDR'])), FILTER_VALIDATE_IP);
        if ($server_ip) return $server_ip;
    }
    
    // gethostbyname()でドメインからIPを取得
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    if ($host) {
        // ポート番号があれば除去
        $host = preg_replace('/:\d+$/', '', $host);
        // DNS lookup is cached (per request and for a day): it used to run on every logged request.
        static $resolved = [];
        if (!array_key_exists($host, $resolved)) {
            $cache_key = 'kssl_srvip_' . md5($host);
            $cached = get_transient($cache_key);
            if ($cached === false) {
                $ip = gethostbyname($host);
                $cached = ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '';
                set_transient($cache_key, $cached, DAY_IN_SECONDS);
            }
            $resolved[$host] = $cached;
        }
        if ($resolved[$host] !== '') {
            return $resolved[$host];
        }
    }
    
    return '';
}

function kssl_get_all_column_definitions() {
    return [
        'id' => __('ID', 'kashiwazaki-seo-super-access-log'),
        'access_time' => __('Time', 'kashiwazaki-seo-super-access-log'), // Label changed slightly
        'visitor_id_cookie' => __('Visitor ID (Cookie)', 'kashiwazaki-seo-super-access-log'),
        'ip_address' => __('IP Address', 'kashiwazaki-seo-super-access-log'),
        'country_code' => __('Country', 'kashiwazaki-seo-super-access-log'),
        'request_uri' => __('Request Path', 'kashiwazaki-seo-super-access-log'),
        'request_method' => __('Method', 'kashiwazaki-seo-super-access-log'),
        'status_code' => __('Status', 'kashiwazaki-seo-super-access-log'),
        'referer_url' => __('Referer', 'kashiwazaki-seo-super-access-log'),
        'user_agent' => __('User Agent', 'kashiwazaki-seo-super-access-log'),
        'user_id' => __('User ID', 'kashiwazaki-seo-super-access-log'),
        'is_bot' => __('Is Bot?', 'kashiwazaki-seo-super-access-log'),
        'visit_type' => __('Visit Type', 'kashiwazaki-seo-super-access-log'),
        'source' => __('Source', 'kashiwazaki-seo-super-access-log'),
    ];
}

/**
 * 設定値の取得（デフォルト値付き）
 *
 * @param string $option_key オプションキー
 * @param mixed $default デフォルト値
 * @return mixed 設定値
 */
function kssl_get_option($option_key, $default = null) {
    return get_option($option_key, $default);
}

/**
 * 表示カラムの取得
 *
 * @return array 表示カラム
 */
function kssl_get_displayed_columns() {
    $stored_columns = kssl_get_option(KSSL_DISPLAYED_COLUMNS_OPTION_KEY, []);
    $all_column_definitions = kssl_get_all_column_definitions();
    
    // 設定が空の場合はすべてのカラムを表示
    if (empty($stored_columns)) {
        return $all_column_definitions;
    }
    
    // 設定されているカラムのみを返す（キー => ラベルの形式）
    $displayed_columns = [];
    foreach ($stored_columns as $col_key => $enabled) {
        if ($enabled && isset($all_column_definitions[$col_key])) {
            $displayed_columns[$col_key] = $all_column_definitions[$col_key];
        }
    }
    
    return $displayed_columns;
}

/**
 * WHERE句の構築
 *
 * @param array $where_clauses WHERE句の配列
 * @return string WHERE句
 */
function kssl_build_where_clause($where_clauses) {
    if (empty($where_clauses)) {
        return '';
    }
    return ' WHERE ' . implode(' AND ', $where_clauses);
}

/**
 * 除外URIパターンの取得と適用
 *
 * @param array $where_clauses WHERE句の配列
 * @param array $params パラメータの配列
 * @return array [WHERE句, パラメータ]
 */
function kssl_apply_excluded_uri_patterns($where_clauses, $params) {
    global $wpdb;
    
    $excluded_uri_patterns_raw = kssl_get_option(KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY, '');
    $excluded_uri_patterns = array_filter(array_map('trim', explode("\n", $excluded_uri_patterns_raw)));

    if (!empty($excluded_uri_patterns)) {
        foreach ($excluded_uri_patterns as $pattern_item) {
            if (empty($pattern_item)) continue;
            $where_clauses[] = "request_uri NOT LIKE %s";
            $params[] = '%' . $wpdb->esc_like(trim($pattern_item)) . '%';
        }
    }
    
    return [$where_clauses, $params];
}

/**
 * True when the request URI contains one of the excluded URI patterns. Same meaning as the
 * "request_uri NOT LIKE '%pattern%'" condition used for display (the column collation is
 * case-insensitive), so an excluded request is not recorded at all.
 *
 * @param string $uri
 * @return bool
 */
function kssl_uri_is_excluded( $uri ) {
    $patterns = array_filter( array_map( 'trim', explode( "\n", (string) kssl_get_option( KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY, '' ) ) ) );
    foreach ( $patterns as $pattern ) {
        $pos = function_exists( 'mb_stripos' ) ? mb_stripos( (string) $uri, $pattern ) : stripos( (string) $uri, $pattern );
        if ( $pos !== false ) {
            return true;
        }
    }
    return false;
}

/**
 * 整数値のバリデーション
 *
 * @param mixed $value 値
 * @param int $min 最小値
 * @param int $max 最大値
 * @param int $default デフォルト値
 * @return int バリデーション済み値
 */
function kssl_validate_int($value, $min = 0, $max = PHP_INT_MAX, $default = 0) {
    $int_value = intval($value);
    if ($int_value < $min || $int_value > $max) {
        return $default;
    }
    return $int_value;
}

/**
 * 文字列値のサニタイズ
 *
 * @param mixed $value 値
 * @param int $max_length 最大長
 * @return string サニタイズ済み値
 */
function kssl_sanitize_string($value, $max_length = 255) {
    $sanitized = sanitize_text_field($value);
    if (strlen($sanitized) > $max_length) {
        return substr($sanitized, 0, $max_length);
    }
    return $sanitized;
}

/**
 * ページネーション情報の計算
 *
 * @param int $total_items 総アイテム数
 * @param int $items_per_page 1ページあたりのアイテム数
 * @param int $current_page 現在のページ
 * @return array ページネーション情報
 */
function kssl_calculate_pagination($total_items, $items_per_page, $current_page) {
    $total_pages = ceil($total_items / $items_per_page);
    $offset = ($current_page - 1) * $items_per_page;
    
    return [
        'total_pages' => $total_pages,
        'offset' => $offset,
        'current_page' => max(1, min($current_page, $total_pages))
    ];
}

/**
 * ページネーションの表示
 *
 * @param int $current_page 現在のページ
 * @param int $total_pages 総ページ数
 * @param int $total_items 総アイテム数
 */
function kssl_display_pagination($current_page, $total_pages, $total_items) {
    if ($total_pages <= 1) {
        return;
    }
    
    $pagination_base_url = remove_query_arg(['kssl_action', 'kssl_clear_logs_nonce', '_wpnonce', 'kssl_message'], $_SERVER['REQUEST_URI']);
    $items_per_page = isset($_REQUEST['logs_per_page']) ? intval($_REQUEST['logs_per_page']) : KSSL_DEFAULT_LOGS_PER_PAGE;
    $pagination_base_url = add_query_arg('logs_per_page', $items_per_page, $pagination_base_url);
    
    $page_links_html = paginate_links([
        'base' => add_query_arg('paged', '%#%', $pagination_base_url),
        'format' => '',
        'prev_text' => esc_html__('«', 'kashiwazaki-seo-super-access-log'),
        'next_text' => esc_html__('»', 'kashiwazaki-seo-super-access-log'),
        'total' => $total_pages,
        'current' => $current_page,
        'type' => 'plain',
    ]);
    
    if ($page_links_html) {
        $start_item = ($current_page - 1) * $items_per_page + 1;
        $end_item = min($current_page * $items_per_page, $total_items);
        
        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . sprintf(
            esc_html__('%1$s-%2$s of %3$s items', 'kashiwazaki-seo-super-access-log'),
            number_format_i18n($start_item),
            number_format_i18n($end_item),
            number_format_i18n($total_items)
        ) . '</span>';
        echo $page_links_html;
        echo '</div>';
    }
}

function kssl_is_suspicious_access($log_entry) {
    if ( !is_object($log_entry) ) return false;
    if (isset($log_entry->status_code) && $log_entry->status_code >= 400) {
        if ($log_entry->status_code == 404 && isset($log_entry->is_bot) && $log_entry->is_bot && apply_filters('kssl_ignore_bot_404_as_suspicious', true)) {
        } else { return true; }
    }
    $suspicious_keywords_raw = get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS);
    $suspicious_keywords = array_filter(array_map('trim', explode("\n", $suspicious_keywords_raw)));
    if (isset($log_entry->request_uri) && $log_entry->request_uri !== null && !empty($suspicious_keywords)) {
        foreach ($suspicious_keywords as $keyword) {
            if (stripos($log_entry->request_uri, $keyword) !== false) { return true; }
        }
    }
    return false;
}

function kssl_get_blocked_visitor_ids_func() {
    $blocked_ids = get_option(KSSL_BLOCKED_VISITORS_OPTION_KEY, []);
    return is_array($blocked_ids) ? $blocked_ids : [];
}

function kssl_add_blocked_visitor_id_func($visitor_id) {
    if (empty($visitor_id)) return false;
    $blocked_ids = kssl_get_blocked_visitor_ids_func();
    if (!in_array($visitor_id, $blocked_ids)) {
        $blocked_ids[] = sanitize_text_field($visitor_id);
        update_option(KSSL_BLOCKED_VISITORS_OPTION_KEY, array_unique($blocked_ids));
        return true;
    }
    return false;
}

function kssl_remove_blocked_visitor_id_func($visitor_id) {
    if (empty($visitor_id)) return false;
    $blocked_ids = kssl_get_blocked_visitor_ids_func();
    $key = array_search(sanitize_text_field($visitor_id), $blocked_ids);
    if ($key !== false) {
        unset($blocked_ids[$key]);
        update_option(KSSL_BLOCKED_VISITORS_OPTION_KEY, array_values($blocked_ids));
        return true;
    }
    return false;
}

/**
 * User-AgentとAccept-Languageヘッダーから国コードを推定（高速）
 */
function kssl_get_country_code_from_headers() {
    // Accept-Languageヘッダーから優先言語を取得
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $accept_language = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']));

        // Accept-Language形式: ja-JP,ja;q=0.9,en-US;q=0.8,en;q=0.7
        // 最初の言語を抽出
        $languages = explode(',', $accept_language);
        if (!empty($languages)) {
            $primary_lang = explode(';', $languages[0])[0]; // "ja-JP" or "en-US"

            // ロケールから国コードを抽出
            if (strpos($primary_lang, '-') !== false) {
                $parts = explode('-', $primary_lang);
                if (count($parts) >= 2) {
                    $country_code = strtoupper(trim($parts[1])); // "JP" or "US"

                    // 2文字の国コードであることを確認
                    if (strlen($country_code) === 2 && ctype_alpha($country_code)) {
                        return $country_code;
                    }
                }
            }

            // 言語コードのみの場合、よくあるマッピングを使用
            $lang_code = strtolower(trim(explode('-', $primary_lang)[0]));
            $lang_to_country = [
                'ja' => 'JP', 'zh' => 'CN', 'ko' => 'KR', 'ar' => 'SA',
                'de' => 'DE', 'fr' => 'FR', 'it' => 'IT', 'pt' => 'BR',
                'ru' => 'RU', 'es' => 'ES', 'nl' => 'NL', 'sv' => 'SE',
                'pl' => 'PL', 'tr' => 'TR', 'th' => 'TH', 'vi' => 'VN',
                'id' => 'ID', 'he' => 'IL', 'cs' => 'CZ', 'da' => 'DK',
                'fi' => 'FI', 'el' => 'GR', 'hu' => 'HU', 'no' => 'NO',
                'uk' => 'UA', 'ro' => 'RO', 'sk' => 'SK', 'bg' => 'BG',
            ];

            if (isset($lang_to_country[$lang_code])) {
                return $lang_to_country[$lang_code];
            }
        }
    }

    return null;
}

/**
 * IPアドレスから国コードを取得（外部API使用）
 * 注意: この関数は外部API呼び出しを行うため、チェックボックスがオンの場合のみ呼ばれます
 */
function kssl_get_country_code_from_ip_func($ip_address) {
    if (empty($ip_address) || !filter_var($ip_address, FILTER_VALIDATE_IP)) {
        return null;
    }

    if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return 'LAN';
    }

    $transient_key = 'kssl_geo_' . md5($ip_address);
    $cached_country_code = get_transient($transient_key);

    if (false !== $cached_country_code) {
        return $cached_country_code === 'KSSL_NULL' ? null : $cached_country_code;
    }

    $api_url = "http://ip-api.com/json/" . urlencode($ip_address) . "?fields=status,message,countryCode";
    $response = wp_remote_get($api_url, ['timeout' => 1]);

    $country_code_to_cache = 'KSSL_NULL';

    if (is_wp_error($response)) {
        set_transient($transient_key, $country_code_to_cache, 1 * HOUR_IN_SECONDS);
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        set_transient($transient_key, $country_code_to_cache, 1 * HOUR_IN_SECONDS);
        return null;
    }

    if (isset($data['status']) && $data['status'] === 'success' && isset($data['countryCode']) && !empty($data['countryCode'])) {
        $country_code_val = sanitize_text_field(strtoupper($data['countryCode']));
        set_transient($transient_key, $country_code_val, 7 * DAY_IN_SECONDS);
        return $country_code_val;
    } elseif (isset($data['message'])) {
        if (strpos(strtolower($data['message']), 'private range') !== false || strpos(strtolower($data['message']), 'reserved range') !== false) {
             set_transient($transient_key, 'LAN', 12 * HOUR_IN_SECONDS);
             return 'LAN';
        }
        set_transient($transient_key, $country_code_to_cache, 1 * HOUR_IN_SECONDS);
    }
    return null;
}

function kssl_is_bot_with_pattern_func( $user_agent_string, $bot_pattern ) {
    if ( empty( $user_agent_string ) ) return false;
    if ( empty( $bot_pattern ) ) {
        $bot_pattern = '/(bot|crawl|slurp|spider|archiv|seek|extract|fetch|seeker|scan|survey|Mediapartners-Google|AdsBot-Google|FeedFetcher|Googlebot|bingbot|msnbot|YandexBot|Baiduspider|facebookexternalhit|twitterbot|linkedinbot|embedly|pinterest|SemrushBot|AhrefsBot|MJ12bot|Applebot|DuckDuckBot|BLEXBot|DotBot|Exabot|Sogou|ia_archiver|UptimeRobot|Linespider|GPTBot|ChatGPT-User|Google-Extended|ClaudeBot|Claude-Web|PerplexityBot|Applebot-Extended|CCBot|OAI-SearchBot|anthropic-ai|cohere-ai|ICC-Crawler|Bytespider|Meta-ExternalAgent)/i';
    }
    return (bool) preg_match( $bot_pattern, $user_agent_string );
}

function kssl_get_available_timezones() {
    return [
        'UTC' => 'UTC (Default)',
        'Asia/Tokyo' => 'Tokyo (JST)',
        'America/New_York' => 'New York (EST/EDT)',
        'America/Los_Angeles' => 'Los Angeles (PST/PDT)',
        'Europe/London' => 'London (GMT/BST)',
        'Europe/Paris' => 'Paris (CET/CEST)',
        // Add more as needed
    ];
}

function kssl_format_time_by_timezone($datetime_string_utc, $target_timezone_identifier) {
    if (empty($datetime_string_utc) || $datetime_string_utc === '0000-00-00 00:00:00') {
        return $datetime_string_utc;
    }
    try {
        $utc_tz = new DateTimeZone('UTC');
        $datetime_obj = new DateTime($datetime_string_utc, $utc_tz);

        if ($target_timezone_identifier && $target_timezone_identifier !== 'UTC') {
            $target_tz = new DateTimeZone($target_timezone_identifier);
            $datetime_obj->setTimezone($target_tz);
        }
        return $datetime_obj->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $datetime_string_utc; // Fallback to original on error
    }
}

/**
 * ローカル日時をUTC日時に変換
 * 
 * @param string $local_datetime_string ローカル日時文字列
 * @param string $source_timezone_identifier ソースタイムゾーン
 * @return string UTC日時文字列
 */
/**
 * A date entered on the log management screen (Y-m-d, in the site's timezone: wp_timezone())
 * at the given local time, as the UTC datetime that access_time is stored in. Null for a value
 * that is not a valid Y-m-d date.
 */
function kssl_site_date_to_utc( $date, $time = '00:00:00' ) {
    $date = trim( (string) $date );
    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
        return null;
    }
    try {
        $dt = new DateTime( $date . ' ' . $time, wp_timezone() );
        $dt->setTimezone( new DateTimeZone( 'UTC' ) );
        return $dt->format( 'Y-m-d H:i:s' );
    } catch ( Exception $e ) {
        return null;
    }
}

function kssl_convert_local_date_to_utc($local_datetime_string, $source_timezone_identifier) {
    if (empty($local_datetime_string) || $source_timezone_identifier === 'UTC') {
        return $local_datetime_string;
    }
    
    try {
        $source_tz = new DateTimeZone($source_timezone_identifier);
        $utc_tz = new DateTimeZone('UTC');
        
        // ローカル時刻として解釈
        $datetime_obj = new DateTime($local_datetime_string, $source_tz);
        
        // UTCに変換
        $datetime_obj->setTimezone($utc_tz);
        
        return $datetime_obj->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        kssl_log_error('Local to UTC conversion error: ' . $e->getMessage());
        return $local_datetime_string; // Fallback to original on error
    }
}

function kssl_get_country_flag_url($country_code) {
    if (empty($country_code) || strlen($country_code) !== 2) {
        if (strtoupper($country_code) === 'LAN') return 'LAN'; // Special case for LAN
        return null;
    }
    // Using flagcdn.com - check their terms of service for your use case.
    // Example: https://flagcdn.com/w20/us.png for a 20px wide US flag
    // Using 16px height version: https://flagcdn.com/h20/us.png and then scale with CSS if needed or use fixed size.
    // Let's use a small fixed size.
    return 'https://flagcdn.com/16x12/' . strtolower(sanitize_key($country_code)) . '.png';
}

function kssl_get_preset_dates($preset, $timezone = 'UTC') {
    try {
        // 指定されたタイムゾーンでの現在日時を取得
        if ($timezone && $timezone !== 'UTC') {
            $tz = new DateTimeZone($timezone);
        } else {
            $tz = new DateTimeZone('UTC');
        }
        
        $now = new DateTime('now', $tz);
        $current_date = $now->format('Y-m-d');
        $from_date = '';
        
        switch ($preset) {
            case '24hours':
                $from_date_obj = clone $now;
                $from_date = $from_date_obj->modify('-1 day')->format('Y-m-d');
                break;
            case '1week':
                $from_date_obj = clone $now;
                $from_date = $from_date_obj->modify('-1 week')->format('Y-m-d');
                break;
            case '1month':
                $from_date_obj = clone $now;
                $from_date = $from_date_obj->modify('-1 month')->format('Y-m-d');
                break;
            case '3months':
                $from_date_obj = clone $now;
                $from_date = $from_date_obj->modify('-3 months')->format('Y-m-d');
                break;
            case '6months':
                $from_date_obj = clone $now;
                $from_date = $from_date_obj->modify('-6 months')->format('Y-m-d');
                break;
            case '12months':
                $from_date_obj = clone $now;
                $from_date = $from_date_obj->modify('-12 months')->format('Y-m-d');
                break;
            default:
                return false;
        }
        
        return [
            'from' => $from_date,
            'to' => $current_date,
            'timezone' => $timezone
        ];
    } catch (Exception $e) {
        kssl_log_error('Preset dates error: ' . $e->getMessage());
        // フォールバック：UTCで処理
        $current_date = gmdate('Y-m-d');
        $from_date = gmdate('Y-m-d', strtotime('-3 months')); // デフォルトは3ヶ月
        return [
            'from' => $from_date,
            'to' => $current_date,
            'timezone' => $timezone
        ];
    }
}

function kssl_get_access_trend_data($where_clauses, $params, $timezone = 'UTC') {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    
    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }
    
    // タイムゾーン変換を考慮したクエリ
    $date_expression = "DATE(access_time)"; // Default for UTC
    
    if ($timezone && $timezone !== 'UTC') {
        try {
            // タイムゾーンオフセットを計算
            $utc_tz = new DateTimeZone('UTC');
            $target_tz = new DateTimeZone($timezone);
            $dummy_date = new DateTime('now', $utc_tz);
            $offset_seconds = $target_tz->getOffset($dummy_date);
            
            if ($offset_seconds != 0) {
                // オフセットを適用してDATE変換
                $date_expression = "DATE(DATE_ADD(access_time, INTERVAL {$offset_seconds} SECOND))";
            }
        } catch (Exception $e) {
            // タイムゾーンエラーの場合はUTCを使用
            kssl_log_error('Timezone error in trend data: ' . $e->getMessage());
        }
    }
    
    // Get daily access counts with timezone consideration
    $query = "SELECT {$date_expression} as date, COUNT(*) as count 
              FROM {$table_name} {$where_sql} 
              GROUP BY {$date_expression} 
              ORDER BY date ASC";
    
    $results = $wpdb->get_results(
        empty($params) ? $query : $wpdb->prepare($query, ...$params),
        ARRAY_A
    );
    
    if (empty($results)) {
        return ['dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable'];
    }
    
    $dates = [];
    $counts = [];
    $total = 0;
    
    foreach ($results as $row) {
        $dates[] = $row['date'];
        $counts[] = (int)$row['count'];
        $total += (int)$row['count'];
    }
    
    // Calculate trend (compare first half with second half)
    $trend = 'stable';
    if (count($counts) >= 4) {
        $mid_point = floor(count($counts) / 2);
        $first_half_avg = array_sum(array_slice($counts, 0, $mid_point)) / $mid_point;
        $second_half_avg = array_sum(array_slice($counts, $mid_point)) / (count($counts) - $mid_point);
        
        $change_percentage = ($second_half_avg - $first_half_avg) / max($first_half_avg, 1) * 100;
        
        if ($change_percentage > 10) {
            $trend = 'increasing';
        } elseif ($change_percentage < -10) {
            $trend = 'decreasing';
        }
    }
    
    return [
        'dates' => $dates,
        'counts' => $counts,
        'total' => $total,
        'trend' => $trend,
        'change_percentage' => isset($change_percentage) ? round($change_percentage, 1) : 0,
        'timezone' => $timezone
    ];
}

/**
 * デバッグ用：プラグインの動作状況を確認
 */
function kssl_debug_plugin_status() {
    global $wpdb;
    
    $debug_info = [];
    
    // 1. テーブルの存在確認
    $table_name = kssl_get_log_table_name_func();
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    $debug_info['table_exists'] = $table_exists;
    $debug_info['table_name'] = $table_name;
    
    // 2. ログの件数確認
    if ($table_exists) {
        $log_count = kssl_count_all_logs();
        $debug_info['log_count'] = $log_count;
        
        // 最新のログを取得
        $latest_log = $wpdb->get_row("SELECT * FROM {$table_name} ORDER BY access_time DESC LIMIT 1");
        $debug_info['latest_log'] = $latest_log;
    }
    
    // 3. フックの登録確認（優先度0を指定してチェック）
    global $wp_filter;
    $debug_info['init_hook_registered'] = isset($wp_filter['init']) &&
                                          isset($wp_filter['init']->callbacks[0]) &&
                                          array_key_exists('kssl_handle_cookie_and_log_init_hook', $wp_filter['init']->callbacks[0]);
    
    // 4. 設定値の確認
    $debug_info['cookie_lifetime'] = get_option('kssl_cookie_lifetime_hours', 'NOT_SET');
    $debug_info['static_tracking'] = get_option('kssl_static_tracking_enabled', 'NOT_SET');
    
    return $debug_info;
}

/**
 * デバッグ用：ログ記録のテスト
 */
function kssl_test_log_recording() {
    // ランダムなテストページURLを生成
    $test_pages = [
        '/test-page-' . rand(1000, 9999),
        '/sample/article-' . rand(1, 100),
        '/blog/post-' . date('Y-m-d'),
        '/product/item-' . rand(100, 999),
        '/category/test-' . rand(1, 50),
        '/news/' . date('Y/m/') . 'test-' . rand(1, 30),
        '/gallery/image-' . rand(1, 200),
        '/documentation/page-' . rand(1, 20)
    ];
    $random_url = $test_pages[array_rand($test_pages)];

    // ランダムなリファラーも生成
    $test_referrers = [
        'https://www.google.com/search?q=test',
        'https://www.bing.com/search?q=sample',
        'https://www.yahoo.com/',
        'https://twitter.com/status/123456',
        'https://www.facebook.com/page/test',
        '',  // 直接アクセス
    ];
    $random_referrer = $test_referrers[array_rand($test_referrers)];

    // ランダムなタイトルも生成
    $test_titles = [
        'Test Page #' . rand(1, 1000),
        'Sample Article - Testing',
        'Debug Test ' . date('H:i:s'),
        'Development Test Page',
        'Quality Check #' . rand(100, 999)
    ];
    $random_title = $test_titles[array_rand($test_titles)];

    // テスト用のログを記録（staticソースを使用してフィルターをバイパス）
    $test_data = [
        'url' => home_url() . $random_url,
        'referrer' => $random_referrer,
        'ua' => 'Test User Agent (KSSL Test Log #' . rand(1000, 9999) . ')',
        'title' => $random_title
    ];

    // staticソースを使用することで、is_admin()やself-server IPなどのフィルターをバイパス
    kssl_record_access_log_func('new', 'static', $test_data, 'test-visitor-id-' . time(), '');

    // 記録されたかチェック
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $test_log = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE visitor_id_cookie LIKE %s ORDER BY access_time DESC LIMIT 1",
        'test-visitor-id-%'
    ));

    return $test_log;
}