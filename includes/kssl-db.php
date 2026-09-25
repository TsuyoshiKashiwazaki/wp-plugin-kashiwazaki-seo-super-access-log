<?php
/**
 * Log table schema, migration to the lightweight schema, derived columns and the shared job lock.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KSSL_DB_VERSION', 2 );
define( 'KSSL_DB_VERSION_OPTION_KEY', 'kssl_db_version' );
define( 'KSSL_MIGRATION_OPTION_KEY', 'kssl_migration' );
define( 'KSSL_SUSPICIOUS_GEN_OPTION_KEY', 'kssl_suspicious_gen' );
define( 'KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY', 'kssl_suspicious_gen_done' );
define( 'KSSL_RECLASSIFY_OPTION_KEY', 'kssl_reclassify' );
define( 'KSSL_CACHE_GEN_OPTION_KEY', 'kssl_cache_gen' );
define( 'KSSL_TABLE_COLLATION_OPTION_KEY', 'kssl_table_collation' );
define( 'KSSL_JOB_TIME_BUDGET', 20 );
define( 'KSSL_MIGRATION_BATCH', 5000 );
define( 'KSSL_VERIFY_WINDOW', 20000 );
define( 'KSSL_AUTO_INCREMENT_MARGIN', 1000000 );
define( 'KSSL_AUTO_MIGRATE_MAX_ROWS', 200000 );

/* ------------------------------------------------------------------------
 * Schema detection
 * --------------------------------------------------------------------- */

function kssl_migration_tables() {
    $main = kssl_get_log_table_name_func();
    return [ 'main' => $main, 'new' => $main . '_new', 'legacy' => $main . '_legacy' ];
}

/**
 * @return bool|null Null when the check itself failed (unknown): callers must not act on it.
 */
function kssl_table_exists( $table ) {
    global $wpdb;
    $found = kssl_db_value( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    return $found === false ? null : $found === $table;
}

/**
 * True when $table has the lightweight schema (is_suspicious column present).
 */
function kssl_table_is_lean( $table ) {
    global $wpdb;
    $count = kssl_db_value( $wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $table,
        'is_suspicious'
    ) );
    return $count === false ? null : (int) $count === 1;
}

/**
 * Cheap check used on every request: the option is kept in sync with the real table layout
 * by kssl_migration_detect_state() (called by every job and on admin_init).
 */
function kssl_schema_is_lean() {
    return (int) get_option( KSSL_DB_VERSION_OPTION_KEY, 0 ) >= KSSL_DB_VERSION;
}

/**
 * Migration state derived from the actual tables (the option only holds auxiliary data).
 *
 * @return string not_started|copy|swapped|done|partial_rename|unexpected|missing
 */
function kssl_migration_detect_state() {
    $t = kssl_migration_tables();
    $main = kssl_table_exists( $t['main'] );
    $new = kssl_table_exists( $t['new'] );
    $legacy = kssl_table_exists( $t['legacy'] );
    $main_lean = $main ? kssl_table_is_lean( $t['main'] ) : false;
    if ( $main === null || $new === null || $legacy === null || $main_lean === null ) {
        // The layout could not be read: treat as unknown (writes blocked, no job acts, option untouched).
        return 'unexpected';
    }

    if ( $main && ! $main_lean && ! $new && ! $legacy ) {
        $state = 'not_started';
    } elseif ( $main && ! $main_lean && $new && ! $legacy ) {
        $state = 'copy';
    } elseif ( $main_lean && ! $new && $legacy ) {
        $state = 'swapped';
    } elseif ( $main_lean && ! $new && ! $legacy ) {
        $state = 'done';
    } elseif ( ! $main && $new && $legacy ) {
        $state = 'partial_rename';
    } elseif ( ! $main && ! $new && ! $legacy ) {
        $state = 'missing';
    } else {
        $state = 'unexpected';
    }

    $version = $main_lean ? KSSL_DB_VERSION : ( $main ? 1 : 0 );
    if ( (int) get_option( KSSL_DB_VERSION_OPTION_KEY, -1 ) !== $version ) {
        update_option( KSSL_DB_VERSION_OPTION_KEY, $version );
    }
    return $state;
}

function kssl_migration_state() {
    $state = get_option( KSSL_MIGRATION_OPTION_KEY, [] );
    return is_array( $state ) ? $state : [];
}

function kssl_migration_update( array $data ) {
    update_option( KSSL_MIGRATION_OPTION_KEY, array_merge( kssl_migration_state(), $data, [ 'updated_at' => time() ] ), false );
}

/**
 * True while two copies of the log exist (or the layout is unknown): operations that delete or
 * bulk-insert rows would act on only one of them, so they must not run.
 */
function kssl_migration_blocks_writes() {
    $state = kssl_migration_detect_state();
    if ( in_array( $state, [ 'copy', 'swapped', 'partial_rename', 'unexpected' ], true ) ) {
        return true;
    }
    // A failure marker only matters while the legacy table still exists; once the migration is
    // done there is a single table again and a leftover marker must not block writes forever.
    return $state !== 'done' && ! empty( kssl_migration_state()['verify_failed'] );
}

function kssl_migration_block_message() {
    return __( 'ログテーブルを新しい形式へ移行中のため、この操作は移行が終わるまで実行できません。', 'kashiwazaki-seo-super-access-log' );
}

/* ------------------------------------------------------------------------
 * Cache generation
 * --------------------------------------------------------------------- */

function kssl_bump_cache_generation() {
    update_option( KSSL_CACHE_GEN_OPTION_KEY, (int) get_option( KSSL_CACHE_GEN_OPTION_KEY, 0 ) + 1, false );
}

function kssl_cache_generation() {
    return (int) get_option( KSSL_CACHE_GEN_OPTION_KEY, 0 );
}

/**
 * Exact row count of the whole log table, read once per request (the admin page shows it in
 * several sections; each COUNT(*) scans the whole table: about 0.4 s at 3 million rows). Read
 * again after the cache generation changed (deletion, import, migration).
 *
 * @return string|null As $wpdb->get_var(): the count, or null on a read error.
 */
function kssl_count_all_logs() {
    global $wpdb;
    static $memo = [];
    $key = kssl_get_log_table_name_func() . '|' . kssl_cache_generation();
    if ( ! array_key_exists( $key, $memo ) ) {
        $memo = [ $key => $wpdb->get_var( 'SELECT COUNT(*) FROM ' . kssl_get_log_table_name_func() ) ];
    }
    return $memo[ $key ];
}

/* ------------------------------------------------------------------------
 * Shared job lock (one per database + table prefix)
 * --------------------------------------------------------------------- */

function kssl_job_lock_name() {
    global $wpdb;
    $name = DB_NAME . '.' . $wpdb->prefix . '.kssl_job';
    return strlen( $name ) > 64 ? 'kssl_job_' . md5( $name ) : $name;
}

/**
 * GET_LOCK returns 1 when obtained, 0 on timeout, NULL on error; the lock is released when the
 * connection ends, so a crashed request never leaves it held.
 */
function kssl_job_lock() {
    global $wpdb;
    if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', kssl_job_lock_name() ) ) !== 1 ) {
        return false;
    }
    // Bound metadata-lock and row-lock waits for this connection (MariaDB default is 86400 s).
    $wpdb->query( 'SET SESSION lock_wait_timeout = 5' );
    $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 5' );
    return true;
}

function kssl_job_unlock() {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', kssl_job_lock_name() ) );
}

/**
 * Schedule a single event unless one is already pending.
 */
function kssl_schedule_once( $hook, $delay, array $args = [] ) {
    if ( ! wp_next_scheduled( $hook, $args ) ) {
        wp_schedule_single_event( time() + $delay, $hook, $args );
    }
}

/**
 * Per-job mutex (one running step per export/import job). A holder older than $stale seconds is
 * taken over (the step's own time budget is 20 s, so such a holder has died).
 */
function kssl_job_mutex_acquire( $name, $stale = 300 ) {
    if ( add_option( $name, time(), '', 'no' ) ) {
        return true;
    }
    if ( time() - (int) get_option( $name, 0 ) >= $stale ) {
        update_option( $name, time(), 'no' );
        return true;
    }
    return false;
}

function kssl_job_mutex_release( $name ) {
    delete_option( $name );
}

/* ------------------------------------------------------------------------
 * Collation, schema and derived-column expressions
 * --------------------------------------------------------------------- */

function kssl_table_collation() {
    global $wpdb;
    $stored = get_option( KSSL_TABLE_COLLATION_OPTION_KEY );
    if ( is_array( $stored ) && ! empty( $stored['collation'] ) && ! empty( $stored['charset'] ) ) {
        return $stored;
    }
    $collation = $wpdb->get_var( $wpdb->prepare(
        'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        kssl_get_log_table_name_func()
    ) );
    if ( ! $collation ) {
        $collation = $wpdb->collate ? $wpdb->collate : 'utf8mb4_unicode_520_ci';
    }
    $charset = $wpdb->get_var( $wpdb->prepare(
        'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = %s',
        $collation
    ) );
    if ( ! $charset ) {
        $charset = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
    }
    $result = [
        'collation' => preg_replace( '/[^A-Za-z0-9_]/', '', $collation ),
        'charset'   => preg_replace( '/[^A-Za-z0-9_]/', '', $charset ),
    ];
    update_option( KSSL_TABLE_COLLATION_OPTION_KEY, $result, false );
    return $result;
}

function kssl_lean_table_sql( $table_name ) {
    $c = kssl_table_collation();
    return "CREATE TABLE IF NOT EXISTS {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        access_time datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        ip_address varchar(45) NOT NULL DEFAULT '',
        user_agent text NOT NULL,
        request_uri text NOT NULL,
        referer_url text,
        referer_host varchar(191) DEFAULT NULL,
        request_method varchar(10) NOT NULL DEFAULT '',
        status_code smallint(3) NOT NULL DEFAULT 0,
        user_id bigint(20) DEFAULT NULL,
        is_bot tinyint(1) NOT NULL DEFAULT 0,
        is_suspicious tinyint(1) DEFAULT NULL,
        visit_type varchar(15) NOT NULL DEFAULT 'unknown',
        source varchar(15) NOT NULL DEFAULT 'wordpress',
        visitor_id_cookie varchar(255) DEFAULT NULL,
        country_code varchar(3) DEFAULT NULL,
        navigation_type varchar(15) NOT NULL DEFAULT 'unknown',
        PRIMARY KEY (id),
        KEY idx_time_susp (access_time, is_suspicious),
        KEY idx_ip_address (ip_address)
    ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET={$c['charset']} COLLATE={$c['collation']}";
}

function kssl_suspicious_keywords() {
    $raw = get_option( KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS );
    // Same filtering as the original query-time exclusion (array_filter without callback).
    return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) ) );
}

/**
 * SQL boolean expression "is this row suspicious", shared by every path so that the display
 * filter, the insert, the migration copy, the re-classification and the import agree.
 *
 * @param string $ua_sql  Operand for the user agent: a column reference or '%s'.
 * @param string $uri_sql Operand for the request URI.
 * @param array  $params  Bound parameters are appended in order.
 * @param array  $values  [ua, uri] values, used when an operand is '%s'.
 */
function kssl_suspicious_sql( $ua_sql, $uri_sql, array &$params, array $values = [] ) {
    global $wpdb;
    $keywords = kssl_suspicious_keywords();
    if ( empty( $keywords ) ) {
        return '0';
    }
    $parts = [];
    foreach ( $keywords as $keyword ) {
        $pattern = '%' . $wpdb->esc_like( $keyword ) . '%';
        foreach ( [ [ $ua_sql, 0 ], [ $uri_sql, 1 ] ] as $pair ) {
            list( $operand, $value_index ) = $pair;
            $sql_operand = $operand;
            if ( $operand === '%s' ) {
                $sql_operand = kssl_bound_string_sql();
                $params[] = (string) $values[ $value_index ];
            }
            $parts[] = "{$sql_operand} LIKE %s";
            $params[] = $pattern;
        }
    }
    return '(' . implode( ' OR ', $parts ) . ')';
}

/**
 * A bound string compared with the table collation (literal-to-literal LIKE would otherwise use
 * the connection collation, not the column collation).
 */
function kssl_bound_string_sql() {
    $c = kssl_table_collation();
    return "CONVERT(%s USING {$c['charset']}) COLLATE {$c['collation']}";
}

/**
 * Same expression the referer chart used before the lightweight schema existed.
 */
function kssl_referer_host_sql( $referer_sql ) {
    return "NULLIF(LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE({$referer_sql}, ''), '//', -1), '/', 1), 191), '')";
}

/**
 * Every shared column equal, byte for byte for strings (no case / trailing-space folding of _ci
 * collations) and NULL-safe.
 */
function kssl_all_columns_equal_sql( $a, $b ) {
    $strings = [ 'ip_address', 'user_agent', 'request_uri', 'referer_url', 'request_method', 'visit_type', 'source', 'visitor_id_cookie', 'country_code', 'navigation_type' ];
    $parts = [];
    foreach ( kssl_common_columns() as $c ) {
        $parts[] = in_array( $c, $strings, true )
            ? "BINARY {$a}.{$c} <=> BINARY {$b}.{$c}"
            : "{$a}.{$c} <=> {$b}.{$c}";
    }
    return implode( ' AND ', $parts );
}

function kssl_common_columns() {
    return [ 'id', 'access_time', 'ip_address', 'user_agent', 'request_uri', 'referer_url', 'request_method', 'status_code', 'user_id', 'is_bot', 'visit_type', 'source', 'visitor_id_cookie', 'country_code', 'navigation_type' ];
}

/**
 * Insert one log row (derived columns are computed in SQL on the lightweight schema).
 */
function kssl_insert_log_row( array $row ) {
    global $wpdb;
    $result = kssl_insert_log_row_once( $row );
    // A request that read the schema version just before/after the swap may use the wrong column
    // set; on ER_BAD_FIELD_ERROR (1054, "Unknown column") re-detect the table layout and try the
    // other form once.
    if ( $result === false && kssl_last_db_errno() === 1054 ) {
        kssl_migration_detect_state();
        $result = kssl_insert_log_row_once( $row );
    }
    return $result;
}

function kssl_insert_log_row_once( array $row ) {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    // 'id' is only passed by the CSV import without validation (the exported ids are kept, as before).
    return kssl_insert_log_rows_once( [ $row ] );
}

function kssl_log_row_formats() {
    return [
        'id' => '%d', 'access_time' => '%s', 'ip_address' => '%s', 'user_agent' => '%s', 'request_uri' => '%s',
        'referer_url' => '%s', 'request_method' => '%s', 'status_code' => '%d', 'user_id' => '%d',
        'is_bot' => '%d', 'visit_type' => '%s', 'source' => '%s', 'visitor_id_cookie' => '%s',
        'country_code' => '%s', 'navigation_type' => '%s',
    ];
}

/**
 * Insert several rows with one multi-row INSERT (CSV import). All rows must have the same keys.
 * One statement: on error nothing is inserted and the caller can retry row by row.
 *
 * @return int|false Number of rows inserted, or false.
 */
function kssl_insert_log_rows( array $rows ) {
    $result = kssl_insert_log_rows_once( $rows );
    if ( $result === false && kssl_last_db_errno() === 1054 ) {
        kssl_migration_detect_state();
        $result = kssl_insert_log_rows_once( $rows );
    }
    return $result;
}

function kssl_insert_log_rows_once( array $rows ) {
    global $wpdb;
    if ( empty( $rows ) ) {
        return 0;
    }
    $table_name = kssl_get_log_table_name_func();
    $formats = kssl_log_row_formats();
    $columns = array_keys( array_intersect_key( reset( $rows ), $formats ) );
    $lean = kssl_schema_is_lean();
    $tuples = [];
    $params = [];
    foreach ( $rows as $row ) {
        if ( array_keys( array_intersect_key( $row, $formats ) ) !== $columns ) {
            return false;
        }
        $placeholders = [];
        foreach ( $columns as $column ) {
            if ( $row[ $column ] === null ) {
                $placeholders[] = 'NULL';
            } else {
                $placeholders[] = $formats[ $column ];
                $params[] = $row[ $column ];
            }
        }
        if ( $lean ) {
            $placeholders[] = kssl_suspicious_sql( '%s', '%s', $params, [ (string) ( $row['user_agent'] ?? '' ), (string) ( $row['request_uri'] ?? '' ) ] );
            $placeholders[] = kssl_referer_host_sql( '%s' );
            $params[] = (string) ( $row['referer_url'] ?? '' );
        }
        $tuples[] = '(' . implode( ', ', $placeholders ) . ')';
    }
    $all_columns = $lean ? array_merge( $columns, [ 'is_suspicious', 'referer_host' ] ) : $columns;
    $sql = "INSERT INTO {$table_name} (" . implode( ', ', $all_columns ) . ') VALUES ' . implode( ', ', $tuples );
    return $wpdb->query( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ) );
}

/**
 * Error number of the last query on the WordPress connection (0 when unknown).
 */
function kssl_last_db_errno() {
    global $wpdb;
    if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) {
        return (int) mysqli_errno( $wpdb->dbh );
    }
    return strpos( (string) $wpdb->last_error, 'Unknown column' ) !== false ? 1054 : 0;
}

/**
 * Read one value / one column, returning false when the query failed. wpdb::get_var() returns
 * null and wpdb::get_col() an empty array on a failed query, which must not be taken as 0 or
 * "no rows" (that would let the migration skip verification). wpdb::query() clears last_error
 * before running (wpdb::flush()), so a non-empty last_error right after the call is this query's.
 */
function kssl_db_value( $sql ) {
    global $wpdb;
    $value = $wpdb->get_var( $sql );
    return $wpdb->last_error !== '' ? false : $value;
}

function kssl_db_column( $sql ) {
    global $wpdb;
    $values = $wpdb->get_col( $sql );
    return $wpdb->last_error !== '' ? false : $values;
}

/**
 * CSV export batches wait while rows may still exist only in the legacy table.
 */
function kssl_migration_export_should_wait() {
    return in_array( kssl_migration_detect_state(), [ 'swapped', 'partial_rename' ], true );
}

/* ------------------------------------------------------------------------
 * Table creation and migration start
 * --------------------------------------------------------------------- */

/**
 * Create the lightweight table when no log table exists (new install, new network site, or
 * first request after the table went missing). Never runs DDL against an existing table.
 *
 * @return bool True when a table was created.
 */
function kssl_create_table_if_missing() {
    global $wpdb;
    if ( ! kssl_job_lock() ) {
        return false;
    }
    try {
        return kssl_create_table_if_missing_locked();
    } finally {
        kssl_job_unlock();
    }
}

function kssl_create_table_if_missing_locked() {
    global $wpdb;
    $t = kssl_migration_tables();
    // Create only when all three are known to be absent (a failed check returns null = unknown).
    if ( kssl_table_exists( $t['main'] ) !== false || kssl_table_exists( $t['new'] ) !== false || kssl_table_exists( $t['legacy'] ) !== false ) {
        kssl_migration_detect_state();
        return false;
    }
    delete_option( KSSL_TABLE_COLLATION_OPTION_KEY );
    $wpdb->query( kssl_lean_table_sql( $t['main'] ) );
    if ( ! kssl_table_exists( $t['main'] ) ) {
        return false;
    }
    update_option( KSSL_DB_VERSION_OPTION_KEY, KSSL_DB_VERSION );
    update_option( KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY, (int) get_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, 0 ) );
    delete_option( KSSL_MIGRATION_OPTION_KEY );
    return true;
}

/**
 * Rough row count (information_schema estimate) used only to decide auto-start and for display.
 */
function kssl_estimated_rows( $table ) {
    global $wpdb;
    $rows = kssl_db_value( $wpdb->prepare(
        'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
    ) );
    return $rows === false || $rows === null ? null : (int) $rows;
}

/**
 * The monthly OPTIMIZE of 1.0.0 (a full table rebuild) is no longer used. Its event and option are
 * removed on activation, on the first request after an update (kssl_ensure_log_table), and on admin
 * screens, so sites updated without re-activation do not keep a leftover monthly event.
 */
function kssl_remove_legacy_optimization() {
    if ( wp_next_scheduled( 'kssl_monthly_optimization' ) ) {
        wp_unschedule_hook( 'kssl_monthly_optimization' ); // all events of the hook, whatever their arguments (WP 4.9+)
    }
    if ( get_option( 'kssl_auto_optimization_enabled', null ) !== null ) {
        delete_option( 'kssl_auto_optimization_enabled' );
    }
}

/**
 * admin_init: keep jobs alive and auto-start small migrations.
 */
function kssl_admin_maintain_jobs() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    kssl_remove_legacy_optimization();
    // Sites updated without re-activation never got the hourly event (it is added on activation).
    // wp_next_scheduled() first, as the WordPress reference recommends against duplicate events.
    if ( ! wp_next_scheduled( 'kssl_jobs_watchdog' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'kssl_jobs_watchdog' );
    }
    // Automatic cleanup enabled but its daily event missing (enabled before the settings form
    // scheduled it): add it.
    if ( get_option( KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 0 ) && ! wp_next_scheduled( 'kssl_cleanup_old_logs' ) ) {
        kssl_schedule_cleanup_cron();
    }
    kssl_jobs_watchdog();
    if ( kssl_migration_detect_state() === 'not_started' && empty( kssl_migration_state()['failed'] ) ) {
        $t = kssl_migration_tables();
        $rows = kssl_estimated_rows( $t['main'] );
        // Auto-start only on a successful count below the limit (larger tables wait for the button).
        if ( $rows !== null && $rows < KSSL_AUTO_MIGRATE_MAX_ROWS ) {
            kssl_migration_start();
        }
    }
}

/**
 * Hourly event and admin_init: re-schedule unfinished jobs whose chain was broken.
 */
function kssl_jobs_watchdog() {
    $state = kssl_migration_detect_state();
    if ( in_array( $state, [ 'copy', 'swapped', 'partial_rename' ], true ) ) {
        kssl_schedule_once( 'kssl_migration_tick', 10 );
    }
    if ( $state === 'done' && ! kssl_suspicious_flag_is_current() ) {
        kssl_schedule_once( 'kssl_derived_fill_tick', 60 );
    }
    if ( is_array( get_option( KSSL_RECLASSIFY_OPTION_KEY ) ) ) {
        kssl_schedule_once( 'kssl_reclassify_tick', 10 );
    }
    if ( $state === 'done' && kssl_suspicious_flag_is_current() ) {
        kssl_schedule_once( 'kssl_agg_tick', 60 );
    }
    kssl_cleanup_import_temp_files();
}

/**
 * Start the migration (button or auto-start). Runs under the job lock so that no deletion or
 * import batch is running at the same time.
 *
 * @return true|string True on success, or an error message.
 */
function kssl_migration_start() {
    global $wpdb;
    if ( ! kssl_job_lock() ) {
        return __( '別の処理が実行中です。少し待ってから再度お試しください。', 'kashiwazaki-seo-super-access-log' );
    }
    try {
        if ( kssl_migration_detect_state() !== 'not_started' ) {
            return true;
        }
        $t = kssl_migration_tables();
        delete_option( KSSL_TABLE_COLLATION_OPTION_KEY );
        kssl_table_collation();
        $wpdb->query( kssl_lean_table_sql( $t['new'] ) );
        if ( ! kssl_table_exists( $t['new'] ) ) {
            kssl_migration_update( [ 'failed' => $wpdb->last_error ] );
            return (string) $wpdb->last_error;
        }
        // Stop-gap before the copy: drop indexes that only serve generated columns or duplicate others,
        // when possible without a rebuild (ALGORITHM=NOCOPY; any error leaves the index in place).
        $redundant = [ 'idx_access_date', 'idx_access_date_hour', 'idx_ip_hash', 'idx_visitor_hash', 'idx_request_uri_hash', 'idx_referer_hash', 'idx_composite_main', 'idx_composite_analytics', 'idx_composite_security', 'idx_composite_visitor', 'idx_composite', 'idx_request_uri', 'idx_referer_url', 'idx_user_agent', 'idx_composite_filter', 'idx_navigation_type', 'idx_is_bot', 'idx_source', 'idx_visit_type' ];
        $existing = array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$t['main']}", 2 ) );
        $suppress = $wpdb->suppress_errors( true );
        foreach ( array_intersect( $redundant, $existing ) as $index ) {
            $wpdb->query( "ALTER TABLE {$t['main']} DROP INDEX {$index}, ALGORITHM=NOCOPY" );
        }
        $wpdb->suppress_errors( $suppress );
        update_option( KSSL_MIGRATION_OPTION_KEY, [ 'started_at' => time(), 'updated_at' => time() ], false );
        kssl_schedule_once( 'kssl_migration_tick', 5 );
        return true;
    } finally {
        kssl_job_unlock();
    }
}

/* ------------------------------------------------------------------------
 * Migration job
 * --------------------------------------------------------------------- */

/**
 * INSERT ... SELECT copying rows with derived columns computed in SQL. No IGNORE: errors such as
 * truncation must surface instead of silently dropping data.
 */
function kssl_copy_rows_sql( $from, $to, $where_sql, array $where_params, $limit ) {
    global $wpdb;
    $cols = kssl_common_columns();
    $select = implode( ', ', array_map( function ( $c ) { return "src.{$c}"; }, $cols ) );
    $params = [];
    $suspicious = kssl_suspicious_sql( 'src.user_agent', 'src.request_uri', $params );
    $sql = "INSERT INTO {$to} (" . implode( ', ', $cols ) . ", is_suspicious, referer_host)
            SELECT {$select}, {$suspicious}, " . kssl_referer_host_sql( 'src.referer_url' ) . "
            FROM {$from} src WHERE {$where_sql} ORDER BY src.id LIMIT %d";
    $params = array_merge( $params, $where_params, [ (int) $limit ] );
    return $wpdb->query( $wpdb->prepare( $sql, $params ) );
}

/**
 * WP-Cron: one bounded slice of the migration. The next event is scheduled first, so the chain
 * survives a PHP crash; progress is always recomputed from the tables.
 */
function kssl_migration_tick() {
    kssl_schedule_once( 'kssl_migration_tick', 60 );
    if ( ! kssl_job_lock() ) {
        return;
    }
    try {
        $start = microtime( true );
        while ( microtime( true ) - $start < KSSL_JOB_TIME_BUDGET ) {
            $state = kssl_migration_detect_state();
            if ( $state === 'copy' ) {
                $done = kssl_migration_copy_step();
            } elseif ( $state === 'partial_rename' ) {
                $done = kssl_migration_finish_partial_rename();
            } elseif ( $state === 'swapped' ) {
                $done = kssl_migration_swapped_step();
            } else {
                $done = true;
            }
            if ( $done === 'stop' || $done === true && ! in_array( kssl_migration_detect_state(), [ 'copy', 'swapped', 'partial_rename' ], true ) ) {
                wp_clear_scheduled_hook( 'kssl_migration_tick' );
                return;
            }
            if ( $done === 'wait' ) {
                return;
            }
        }
    } finally {
        kssl_job_unlock();
    }
}

/**
 * Copy one batch; swap when fewer than one batch remains.
 *
 * @return bool|string true when this phase ended, false to continue, 'wait' to retry later, 'stop' on failure.
 */
function kssl_migration_copy_step() {
    global $wpdb;
    $t = kssl_migration_tables();
    $copied_max = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$t['new']}" );
    if ( $copied_max === false ) {
        return 'wait';
    }
    $copied_max = (int) $copied_max;
    if ( kssl_copy_rows_sql( $t['main'], $t['new'], 'src.id > %d', [ $copied_max ], KSSL_MIGRATION_BATCH ) === false ) {
        $error = $wpdb->last_error;
        $wpdb->query( "DROP TABLE IF EXISTS {$t['new']}" );
        kssl_migration_update( [ 'failed' => $error ] );
        return 'stop';
    }
    kssl_migration_update( [] );
    $copied_max = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$t['new']}" );
    if ( $copied_max === false ) {
        return 'wait';
    }
    $remaining = kssl_db_value( $wpdb->prepare(
        "SELECT COUNT(*) FROM (SELECT id FROM {$t['main']} WHERE id > %d LIMIT %d) r",
        (int) $copied_max,
        KSSL_MIGRATION_BATCH
    ) );
    if ( $remaining === false ) {
        return 'wait';
    }
    if ( (int) $remaining >= KSSL_MIGRATION_BATCH ) {
        return false;
    }
    return kssl_migration_swap();
}

/**
 * Reserve an id range above the old table, then rename both tables in one atomic statement.
 */
function kssl_migration_swap() {
    global $wpdb;
    $t = kssl_migration_tables();
    $copied_max = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$t['new']}" );
    // information_schema.TABLES.AUTO_INCREMENT may be a cached value (MySQL 8.0), so use MAX(id).
    $old_max = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$t['main']}" );
    if ( $copied_max === false || $old_max === false ) {
        return 'wait';
    }
    $copied_max = (int) $copied_max;
    $boundary = (int) $old_max + 1 + KSSL_AUTO_INCREMENT_MARGIN;
    if ( $wpdb->query( "ALTER TABLE {$t['new']} AUTO_INCREMENT = " . (int) $boundary ) === false ) {
        return 'wait';
    }
    kssl_migration_update( [ 'boundary' => $boundary, 'copied_max' => $copied_max ] );
    // Multi-table RENAME is atomic; lock_wait_timeout (5 s) bounds the wait for running queries.
    if ( $wpdb->query( "RENAME TABLE {$t['main']} TO {$t['legacy']}, {$t['new']} TO {$t['main']}" ) === false ) {
        return 'wait';
    }
    update_option( KSSL_DB_VERSION_OPTION_KEY, KSSL_DB_VERSION );
    // Keep the old LIKE filter until the post-swap fill has covered the whole table.
    update_option( KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY, -1 );
    kssl_migration_update( [ 'fill_cursor' => 0 ] );
    kssl_schedule_once( 'kssl_derived_fill_tick', 10 * MINUTE_IN_SECONDS );
    kssl_migration_move_remaining_rows();
    return true;
}

/**
 * MariaDB < 10.6.1 may leave a multi-table RENAME half applied after a crash.
 */
function kssl_migration_finish_partial_rename() {
    global $wpdb;
    $t = kssl_migration_tables();
    if ( $wpdb->query( "RENAME TABLE {$t['new']} TO {$t['main']}" ) === false ) {
        return 'wait';
    }
    update_option( KSSL_DB_VERSION_OPTION_KEY, KSSL_DB_VERSION );
    update_option( KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY, -1 );
    return true;
}

/**
 * After the rename: copy legacy rows that are not in the live table yet, then verify legacy
 * row by row (same id and equal content). Drop legacy only when every row matched.
 */
/**
 * Copy the legacy rows written after the last batch (id > copied_max). If the live table already
 * has a row with that id (MySQL 5.7 resets AUTO_INCREMENT to MAX(id)+1 after a restart, so a new
 * visit can take such an id), move that new row to a fresh id first, then insert the legacy row
 * with its original id: both rows are kept, even when their contents are identical.
 *
 * @return bool|string true when done, 'stop' on error.
 */
/**
 * Put legacy row $id into the live table with its original id, in one transaction. If the live
 * table has a different row under that id (a new visit took it after an AUTO_INCREMENT reset),
 * that row is first given a fresh id, so both rows are kept even when identical.
 *
 * @return bool
 */
function kssl_migration_restore_legacy_row( $id, array $extra_state = [] ) {
    global $wpdb;
    $t = kssl_migration_tables();
    $cols = kssl_common_columns();
    $col_list = implode( ', ', $cols );
    $without_id = implode( ', ', array_values( array_diff( $cols, [ 'id' ] ) ) );
    $select = implode( ', ', array_map( function ( $c ) { return "src.{$c}"; }, $cols ) );
    $wpdb->query( 'START TRANSACTION' );
    $ok = true;
    $taken = kssl_db_value( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['main']} WHERE id = %d FOR UPDATE", $id ) );
    if ( $taken === false ) {
        // Could not read (lock wait timeout, lost connection): nothing changed, try again later.
        $wpdb->query( 'ROLLBACK' );
        return null;
    }
    if ( (int) $taken > 0 ) {
        $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$t['main']} ({$without_id}, is_suspicious, referer_host) SELECT {$without_id}, is_suspicious, referer_host FROM {$t['main']} WHERE id = %d", $id ) ) !== false
            && $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['main']} WHERE id = %d", $id ) ) !== false;
    }
    if ( $ok ) {
        $params = [];
        $suspicious = kssl_suspicious_sql( 'src.user_agent', 'src.request_uri', $params );
        $params[] = $id;
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$t['main']} ({$col_list}, is_suspicious, referer_host) SELECT {$select}, {$suspicious}, " . kssl_referer_host_sql( 'src.referer_url' ) . " FROM {$t['legacy']} src WHERE src.id = %d",
            $params
        ) ) !== false;
    }
    if ( $ok ) {
        if ( $extra_state ) {
            kssl_migration_update( $extra_state );
        }
        $wpdb->query( 'COMMIT' );
        return true;
    }
    $error = $wpdb->last_error;
    $wpdb->query( 'ROLLBACK' );
    kssl_migration_update( [ 'verify_failed' => 'restore error at id ' . $id . ': ' . $error ] );
    return false;
}

/**
 * Legacy rows written after the last batch (id > copied_max), right after the rename.
 */
function kssl_migration_move_remaining_rows() {
    global $wpdb;
    $t = kssl_migration_tables();
    $state = kssl_migration_state();
    $cursor = max( (int) ( $state['copied_max'] ?? 0 ), (int) ( $state['move_cursor'] ?? 0 ) );
    $ids = kssl_db_column( $wpdb->prepare( "SELECT id FROM {$t['legacy']} WHERE id > %d ORDER BY id", $cursor ) );
    if ( $ids === false ) {
        return 'wait';
    }
    foreach ( $ids as $id ) {
        $restored = kssl_migration_restore_legacy_row( (int) $id, [ 'move_cursor' => (int) $id ] );
        if ( $restored === null ) {
            return 'wait';
        }
        if ( ! $restored ) {
            return 'stop';
        }
    }
    return true;
}

function kssl_migration_swapped_step() {
    global $wpdb;
    $t = kssl_migration_tables();
    $state = kssl_migration_state();
    $legacy_max = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$t['legacy']}" );
    if ( $legacy_max === false ) {
        return 'wait';
    }
    $legacy_max = (int) $legacy_max;

    // A restore or verification failed: keep the legacy table until an administrator resumes
    // (which clears the marker), even if a later retry in the same run would succeed.
    if ( ! empty( $state['verify_failed'] ) ) {
        return 'stop';
    }

    // 1) Rows after copied_max (normally handled right after the rename; repeated here if that run stopped).
    if ( empty( $state['moved'] ) ) {
        $moved = kssl_migration_move_remaining_rows();
        if ( $moved !== true ) {
            return $moved;
        }
        kssl_migration_update( [ 'moved' => 1 ] );
        return false;
    }

    // 2) Verify by content, one id window per step; rows that are missing or different are
    //    restored (a row may have been skipped by the copy when a lower id committed late),
    //    then the same window is verified again before moving on.
    $verify = (int) ( $state['verify_cursor'] ?? 0 );
    if ( $verify < $legacy_max ) {
        $upper = $verify + KSSL_VERIFY_WINDOW;
        $missing = kssl_db_column( $wpdb->prepare(
            "SELECT l.id FROM {$t['legacy']} l
             LEFT JOIN {$t['main']} m ON m.id = l.id AND " . kssl_all_columns_equal_sql( 'm', 'l' ) . "
             WHERE l.id > %d AND l.id <= %d AND m.id IS NULL ORDER BY l.id LIMIT 500",
            $verify,
            $upper
        ) );
        if ( $missing === false ) {
            return 'wait'; // a failed verification query never counts as "no differences"
        }
        if ( empty( $missing ) ) {
            kssl_migration_update( [ 'verify_cursor' => $upper, 'repair_rounds' => 0 ] );
            return false;
        }
        $rounds = (int) ( $state['repair_rounds'] ?? 0 ) + 1;
        if ( $rounds > 3 ) {
            kssl_migration_update( [ 'verify_failed' => sprintf( 'rows in ids %d-%d still differ after repair (first id %d)', $verify + 1, $upper, (int) $missing[0] ) ] );
            return 'stop';
        }
        $copied_max = (int) ( $state['copied_max'] ?? 0 );
        foreach ( $missing as $id ) {
            $id = (int) $id;
            $present = kssl_db_value( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['main']} WHERE id = %d", $id ) );
            if ( $present === false ) {
                return 'wait';
            }
            $present = (int) $present > 0;
            if ( $id <= $copied_max && $present ) {
                // No new visit can take an id <= copied_max (AUTO_INCREMENT restarts above it), so this
                // row is our own copy and differs from the original: never duplicate it, keep legacy.
                kssl_migration_update( [ 'verify_failed' => sprintf( 'copied row %d differs from the original', $id ) ] );
                return 'stop';
            }
            $restored = kssl_migration_restore_legacy_row( $id );
            if ( $restored === null ) {
                return 'wait';
            }
            if ( ! $restored ) {
                return 'stop';
            }
        }
        kssl_migration_update( [ 'repair_rounds' => $rounds ] );
        return false; // verify the same window again
    }

    // 3) Every legacy row is present with identical content. Re-read the legacy range right
    //    before dropping: only a successful read showing it fully verified allows the DROP.
    $final_max = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$t['legacy']}" );
    if ( $final_max === false ) {
        return 'wait';
    }
    if ( (int) $final_max > (int) ( kssl_migration_state()['verify_cursor'] ?? 0 ) ) {
        return false; // not verified up to the end: keep verifying
    }
    if ( $wpdb->query( "DROP TABLE IF EXISTS {$t['legacy']}" ) === false ) {
        return 'wait';
    }
    kssl_migration_update( [ 'finished_at' => time() ] );
    kssl_bump_cache_generation();
    return true;
}

/* ------------------------------------------------------------------------
 * Derived columns: post-swap fill and keyword re-classification
 * --------------------------------------------------------------------- */

function kssl_suspicious_flag_is_current() {
    return kssl_schema_is_lean()
        && (int) get_option( KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY, -1 ) === (int) get_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, 0 );
}

/**
 * Fill is_suspicious / referer_host of rows written with the old schema version right after the
 * swap; mark the flag current only when no such row remains.
 */
function kssl_derived_fill_tick() {
    global $wpdb;
    kssl_schedule_once( 'kssl_derived_fill_tick', 10 * MINUTE_IN_SECONDS );
    if ( kssl_migration_detect_state() !== 'done' || ! kssl_job_lock() ) {
        return;
    }
    try {
        $table_name = kssl_get_log_table_name_func();
        $start = microtime( true );
        $cursor = (int) ( kssl_migration_state()['fill_cursor'] ?? 0 );
        $max_id = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$table_name}" );
        if ( $max_id === false ) {
            return; // never mark the flag current after a failed read
        }
        $max_id = (int) $max_id;
        while ( $cursor < $max_id && microtime( true ) - $start < KSSL_JOB_TIME_BUDGET ) {
            $upper = $cursor + KSSL_VERIFY_WINDOW;
            $params = [];
            $suspicious = kssl_suspicious_sql( 'user_agent', 'request_uri', $params );
            $params[] = $cursor;
            $params[] = $upper;
            if ( $wpdb->query( $wpdb->prepare(
                "UPDATE {$table_name} SET is_suspicious = {$suspicious}, referer_host = " . kssl_referer_host_sql( 'referer_url' ) . ' WHERE id > %d AND id <= %d AND is_suspicious IS NULL',
                $params
            ) ) === false ) {
                return;
            }
            $cursor = $upper;
            kssl_migration_update( [ 'fill_cursor' => $cursor ] );
        }
        if ( $cursor >= $max_id && ! is_array( get_option( KSSL_RECLASSIFY_OPTION_KEY ) ) ) {
            update_option( KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY, (int) get_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, 0 ) );
            kssl_bump_cache_generation();
            wp_clear_scheduled_hook( 'kssl_derived_fill_tick' );
        }
    } finally {
        kssl_job_unlock();
    }
}

/**
 * Called after the keyword setting was saved with a different value (blocked during migration).
 */
function kssl_start_reclassification() {
    $gen = (int) get_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, 0 ) + 1;
    update_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, $gen );
    kssl_bump_cache_generation();
    if ( ! kssl_schema_is_lean() ) {
        return;
    }
    update_option( KSSL_RECLASSIFY_OPTION_KEY, [ 'gen' => $gen, 'cursor' => 0 ], false );
    kssl_schedule_once( 'kssl_reclassify_tick', 5 );
}

function kssl_reclassify_tick() {
    global $wpdb;
    kssl_schedule_once( 'kssl_reclassify_tick', 60 );
    if ( ! kssl_job_lock() ) {
        return;
    }
    try {
        $table_name = kssl_get_log_table_name_func();
        $start = microtime( true );
        while ( microtime( true ) - $start < KSSL_JOB_TIME_BUDGET ) {
            $job = get_option( KSSL_RECLASSIFY_OPTION_KEY );
            if ( ! is_array( $job ) || ! kssl_schema_is_lean() ) {
                wp_clear_scheduled_hook( 'kssl_reclassify_tick' );
                return;
            }
            $gen = (int) get_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, 0 );
            if ( $gen !== (int) $job['gen'] ) {
                $job = [ 'gen' => $gen, 'cursor' => 0 ];
            }
            $cursor = (int) $job['cursor'];
            $max_id = kssl_db_value( "SELECT COALESCE(MAX(id), 0) FROM {$table_name}" );
            if ( $max_id === false ) {
                return;
            }
            $max_id = (int) $max_id;
            if ( $cursor >= $max_id ) {
                delete_option( KSSL_RECLASSIFY_OPTION_KEY );
                if ( kssl_migration_detect_state() === 'done' ) {
                    update_option( KSSL_SUSPICIOUS_GEN_DONE_OPTION_KEY, (int) $job['gen'] );
                }
                kssl_bump_cache_generation();
                wp_clear_scheduled_hook( 'kssl_reclassify_tick' );
                return;
            }
            $upper = min( $cursor + KSSL_VERIFY_WINDOW, $max_id );
            $params = [];
            $suspicious = kssl_suspicious_sql( 'user_agent', 'request_uri', $params );
            $params[] = $cursor;
            $params[] = $upper;
            if ( $wpdb->query( $wpdb->prepare( "UPDATE {$table_name} SET is_suspicious = {$suspicious} WHERE id > %d AND id <= %d", $params ) ) === false ) {
                return;
            }
            $job['cursor'] = $upper;
            update_option( KSSL_RECLASSIFY_OPTION_KEY, $job, false );
        }
    } finally {
        kssl_job_unlock();
    }
}

/**
 * WHERE fragment that excludes suspicious rows (display, charts, trend).
 * Uses the stored flag only when it is current; otherwise the equivalent LIKE expression.
 */
function kssl_exclude_suspicious_where( array &$params ) {
    if ( kssl_suspicious_flag_is_current() ) {
        return 'NOT (is_suspicious <=> 1)';
    }
    $sql = kssl_suspicious_sql( 'user_agent', 'request_uri', $params );
    return $sql === '0' ? '1=1' : "NOT {$sql}";
}
