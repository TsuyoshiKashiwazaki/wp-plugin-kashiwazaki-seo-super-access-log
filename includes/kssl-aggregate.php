<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Hourly aggregate tables for long display periods.
 *
 * - Closed UTC hours are rebuilt from the raw log as a whole (idempotent), under the shared job
 *   lock. agg_hours says which hours are built and with which generation.
 * - Every path that changes raw rows first removes the agg_hours rows of the hours it touches
 *   (kssl_agg_mark_dirty / kssl_agg_mark_dirty_before / kssl_agg_reset_all), so a built hour is
 *   never used after its raw rows changed.
 * - The generation is computed from its inputs on every use and never stored.
 * - Display reads aggregates for built hours and the raw log for the rest, in one consistent
 *   snapshot; too many raw hours fall back to the raw queries.
 */

define( 'KSSL_AGG_VERSION', 1 );
define( 'KSSL_AGG_SCHEMA', 2 ); // table layout; a different stored value drops and rebuilds the tables
define( 'KSSL_AGG_SCHEMA_OPTION_KEY', 'kssl_agg_schema' );
define( 'KSSL_AGG_CLOSED_AFTER', 2 * HOUR_IN_SECONDS );
define( 'KSSL_AGG_MAX_RAW_HOURS', 48 );
define( 'KSSL_AGG_MAX_RAW_RANGES', 8 );
define( 'KSSL_AGG_EXACT_UNIQUE_DAYS', 32 ); // the 1-month preset spans up to 32 days (one month ago through today; DateTime::modify('-1 month') never goes further back), so it stays exact
define( 'KSSL_AGG_HLL_P', 11 );
define( 'KSSL_AGG_VERIFY_CURSOR_OPTION_KEY', 'kssl_agg_verify_cursor' );

function kssl_agg_tables() {
    $main = kssl_get_log_table_name_func();
    return [
        'hourly' => $main . '_agg_hourly',
        'dim'    => $main . '_agg_dim',
        'values' => $main . '_agg_values',
        'hours'  => $main . '_agg_hours',
        'hll'    => $main . '_agg_hll',
    ];
}

/**
 * Generation of the aggregates: changes whenever an input of the build changes.
 */
function kssl_agg_generation() {
    $c = kssl_table_collation();
    $inputs = [
        KSSL_AGG_VERSION,
        kssl_agg_excluded_patterns(),
        (int) get_option( KSSL_SUSPICIOUS_GEN_OPTION_KEY, 0 ),
        (string) wp_parse_url( home_url(), PHP_URL_HOST ),
        $c['collation'],
    ];
    return (int) hexdec( substr( md5( serialize( $inputs ) ), 0, 7 ) );
}

function kssl_agg_excluded_patterns() {
    $raw = kssl_get_option( KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY, '' );
    return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) ) );
}

/**
 * True when aggregates may be built and used (lightweight table, migration finished, stored
 * suspicious flag current).
 */
function kssl_agg_available() {
    return kssl_schema_is_lean()
        && kssl_suspicious_flag_is_current()
        && kssl_migration_detect_state() === 'done';
}

/**
 * @return bool|null Null when the check failed.
 */
function kssl_agg_tables_exist() {
    static $cache = [];
    $t = kssl_agg_tables();
    if ( ! empty( $cache[ $t['hours'] ] ) ) {
        return true;
    }
    $exists = kssl_table_exists( $t['hours'] );
    if ( $exists ) {
        $cache[ $t['hours'] ] = true;
    }
    return $exists;
}

/**
 * True when the aggregate tables exist with the current table layout (display reads them only then).
 */
function kssl_agg_tables_ready() {
    return kssl_agg_tables_exist() === true && (int) get_option( KSSL_AGG_SCHEMA_OPTION_KEY, 0 ) === KSSL_AGG_SCHEMA;
}

/**
 * Create the tables (job only, under the job lock). Tables of an older layout hold only derived
 * data: they are dropped and rebuilt.
 */
function kssl_agg_create_tables() {
    global $wpdb;
    $t = kssl_agg_tables();
    if ( (int) get_option( KSSL_AGG_SCHEMA_OPTION_KEY, 0 ) !== KSSL_AGG_SCHEMA ) {
        // The built state first, so nothing is used while the rest is replaced.
        foreach ( [ 'hours', 'hll', 'hourly', 'dim', 'values' ] as $k ) {
            if ( $wpdb->query( "DROP TABLE IF EXISTS {$t[ $k ]}" ) === false ) {
                return false;
            }
        }
    }
    $c = kssl_table_collation();
    $tail = "ENGINE=InnoDB DEFAULT CHARSET={$c['charset']} COLLATE={$c['collation']}";
    // hourly / dim are clustered by the columns the display reads a range of (measured on 3.1M
    // log rows: the 12-month UA chart 0.69 s -> 0.29 s); id only makes the key unique.
    $sqls = [
        "CREATE TABLE IF NOT EXISTS {$t['hourly']} (
            id int unsigned NOT NULL AUTO_INCREMENT,
            bucket datetime NOT NULL,
            is_bot tinyint(1) NOT NULL,
            is_suspicious tinyint(1) NOT NULL,
            logged_in tinyint(1) NOT NULL,
            visit_type varchar(15) NOT NULL,
            source varchar(15) NOT NULL,
            status_code smallint(3) NOT NULL,
            hits int unsigned NOT NULL,
            PRIMARY KEY (bucket, id),
            KEY idx_id (id)
        ) {$tail}",
        "CREATE TABLE IF NOT EXISTS {$t['dim']} (
            id int unsigned NOT NULL AUTO_INCREMENT,
            bucket datetime NOT NULL,
            dim tinyint NOT NULL,
            is_bot tinyint(1) NOT NULL,
            is_suspicious tinyint(1) NOT NULL,
            logged_in tinyint(1) NOT NULL,
            visit_type varchar(15) NOT NULL,
            source varchar(15) NOT NULL,
            status_code smallint(3) NOT NULL,
            value_id int unsigned NOT NULL,
            hits int unsigned NOT NULL,
            PRIMARY KEY (dim, bucket, id),
            KEY idx_id (id)
        ) {$tail}",
        "CREATE TABLE IF NOT EXISTS {$t['values']} (
            id int unsigned NOT NULL AUTO_INCREMENT,
            dim tinyint NOT NULL,
            value_hash binary(16) NOT NULL,
            value text NOT NULL,
            PRIMARY KEY (id),
            KEY idx_hash (dim, value_hash)
        ) {$tail}",
        "CREATE TABLE IF NOT EXISTS {$t['hours']} (
            bucket datetime NOT NULL,
            gen int unsigned NOT NULL,
            built_at datetime NOT NULL,
            PRIMARY KEY (bucket)
        ) {$tail}",
        "CREATE TABLE IF NOT EXISTS {$t['hll']} (
            day date NOT NULL,
            is_bot tinyint(1) NOT NULL,
            is_suspicious tinyint(1) NOT NULL,
            logged_in tinyint(1) NOT NULL,
            gen int unsigned NOT NULL,
            non_ascii tinyint(1) NOT NULL DEFAULT 0,
            registers blob NOT NULL,
            KEY idx_day (day)
        ) {$tail}",
    ];
    foreach ( $sqls as $sql ) {
        if ( $wpdb->query( $sql ) === false ) {
            return false;
        }
    }
    if ( (int) get_option( KSSL_AGG_SCHEMA_OPTION_KEY, 0 ) !== KSSL_AGG_SCHEMA ) {
        update_option( KSSL_AGG_SCHEMA_OPTION_KEY, KSSL_AGG_SCHEMA, false );
    }
    return true;
}

/* ------------------------------------------------------------------------
 * Dirty marking (call BEFORE changing raw rows, inside the job lock)
 * --------------------------------------------------------------------- */

function kssl_agg_hour_floor( $datetime ) {
    return substr( (string) $datetime, 0, 13 ) . ':00:00';
}

/**
 * Remove the built state of the given hours (and the HLL of their days).
 *
 * @param string[] $datetimes UTC 'Y-m-d H:i:s' values of the rows about to change.
 * @return bool False when the state could not be removed: the caller must not change the rows.
 */
function kssl_agg_mark_dirty( array $datetimes ) {
    global $wpdb;
    if ( empty( $datetimes ) ) {
        return true;
    }
    $exists = kssl_agg_tables_exist();
    if ( $exists === null ) {
        return false;
    }
    if ( ! $exists ) {
        return true;
    }
    $t = kssl_agg_tables();
    $hours = [];
    $days = [];
    foreach ( $datetimes as $dt ) {
        if ( ! is_string( $dt ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}/', $dt ) ) {
            // Unknown time: forget everything rather than guess.
            return kssl_agg_reset_all();
        }
        $hours[ kssl_agg_hour_floor( $dt ) ] = true;
        $days[ substr( $dt, 0, 10 ) ] = true;
    }
    foreach ( array_chunk( array_keys( $hours ), 500 ) as $chunk ) {
        $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['hours']} WHERE bucket IN ({$in})", $chunk ) ) === false ) {
            return false;
        }
    }
    foreach ( array_chunk( array_keys( $days ), 500 ) as $chunk ) {
        $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['hll']} WHERE day IN ({$in})", $chunk ) ) === false ) {
            return false;
        }
    }
    return true;
}

/**
 * Before deleting every row older than $cutoff (UTC): forget every hour up to the one containing
 * the cutoff, and drop the aggregate rows of hours that will be empty.
 */
function kssl_agg_mark_dirty_before( $cutoff ) {
    global $wpdb;
    $exists = kssl_agg_tables_exist();
    if ( $exists === null ) {
        return false;
    }
    if ( ! $exists ) {
        return true;
    }
    $t = kssl_agg_tables();
    $hour = kssl_agg_hour_floor( $cutoff );
    $day = substr( $cutoff, 0, 10 );
    foreach ( [
        $wpdb->prepare( "DELETE FROM {$t['hours']} WHERE bucket <= %s", $hour ),
        $wpdb->prepare( "DELETE FROM {$t['hll']} WHERE day <= %s", $day ),
        $wpdb->prepare( "DELETE FROM {$t['hourly']} WHERE bucket < %s", $hour ),
        $wpdb->prepare( "DELETE FROM {$t['dim']} WHERE bucket < %s", $hour ),
    ] as $sql ) {
        if ( $wpdb->query( $sql ) === false ) {
            return false;
        }
    }
    return true;
}

/**
 * Forget every aggregate (delete all logs, unknown times).
 */
function kssl_agg_reset_all() {
    global $wpdb;
    $exists = kssl_agg_tables_exist();
    if ( $exists === null ) {
        return false;
    }
    if ( ! $exists ) {
        return true;
    }
    $t = kssl_agg_tables();
    // The built state first: once it is empty nothing else is used.
    if ( $wpdb->query( "DELETE FROM {$t['hours']}" ) === false || $wpdb->query( "DELETE FROM {$t['hll']}" ) === false ) {
        return false;
    }
    foreach ( [ 'hourly', 'dim', 'values' ] as $k ) {
        $wpdb->query( "TRUNCATE TABLE {$t[ $k ]}" );
    }
    return true;
}

/* ------------------------------------------------------------------------
 * Build
 * --------------------------------------------------------------------- */

/**
 * WHERE for the rows that go into the aggregates of [$from, $to) (UTC).
 */
function kssl_agg_source_where( $from, $to, array &$params ) {
    global $wpdb;
    $parts = [ 'access_time >= %s', 'access_time < %s' ];
    $params[] = $from;
    $params[] = $to;
    foreach ( kssl_agg_excluded_patterns() as $pattern ) {
        $parts[] = 'request_uri NOT LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $pattern ) . '%';
    }
    return implode( ' AND ', $parts );
}

function kssl_agg_logged_in_sql( $user_id_sql = 'user_id' ) {
    return "(CASE WHEN {$user_id_sql} > 0 THEN 1 WHEN {$user_id_sql} IS NULL OR {$user_id_sql} = 0 THEN 0 ELSE 2 END)";
}

function kssl_agg_dims() {
    return [
        1 => [ 'value' => 'user_agent', 'cond' => '' ],
        2 => [ 'value' => 'country_code', 'cond' => '' ],
        3 => [ 'value' => 'referer_host', 'cond' => 'referer' ],
    ];
}

/**
 * Referer chart condition (same as the raw chart).
 */
function kssl_agg_referer_cond( array &$params ) {
    global $wpdb;
    $sql = "referer_url IS NOT NULL AND referer_url != ''";
    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( $home_host ) {
        $sql .= ' AND referer_url NOT LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $home_host ) . '%';
    }
    return $sql;
}

/**
 * Rebuild the consecutive hours [$from, $to) (UTC, whole hours within one day) in one
 * transaction. The generation is re-read just before the commit; a change discards the work.
 *
 * @return bool|null True when built, null when skipped (rows not yet classified), false on error.
 */
function kssl_agg_build_range( $from, $to, $gen ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();

    $params = [];
    $where = kssl_agg_source_where( $from, $to, $params );
    $pending = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$log} WHERE {$where} AND is_suspicious IS NULL LIMIT 1", $params ) );
    if ( $pending === null && $wpdb->last_error !== '' ) {
        return kssl_agg_fail( 'build 1', $from );
    }
    if ( $pending !== null ) {
        return null;
    }

    $bucket = "DATE_FORMAT(access_time, '%%Y-%%m-%%d %%H:00:00')";
    $logged_in = kssl_agg_logged_in_sql();
    $ok = false;
    $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ); // no locks on the log rows read (InnoDB: INSERT ... SELECT)
    $wpdb->query( 'START TRANSACTION' );
    try {
        foreach ( [ 'hourly', 'dim' ] as $k ) {
            if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$t[ $k ]} WHERE bucket >= %s AND bucket < %s", $from, $to ) ) === false ) {
                return kssl_agg_fail( 'build 2', $from );
            }
        }
        if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['hours']} WHERE bucket >= %s AND bucket < %s", $from, $to ) ) === false ) {
            return kssl_agg_fail( 'build 3', $from );
        }
        $p = [];
        $w = kssl_agg_source_where( $from, $to, $p );
        if ( $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$t['hourly']} (bucket, is_bot, is_suspicious, logged_in, visit_type, source, status_code, hits)
             SELECT {$bucket}, is_bot, is_suspicious, {$logged_in}, visit_type, source, status_code, COUNT(*)
             FROM {$log} WHERE {$w}
             GROUP BY 1, is_bot, is_suspicious, 4, visit_type, source, status_code",
            $p
        ) ) === false ) {
            return kssl_agg_fail( 'build 4', $from );
        }
        foreach ( kssl_agg_dims() as $dim => $def ) {
            $col = $def['value'];
            $p = [];
            $w = kssl_agg_source_where( $from, $to, $p );
            if ( $def['cond'] === 'referer' ) {
                $w .= ' AND ' . kssl_agg_referer_cond( $p );
            }
            // Dictionary: one row per distinct byte string (no collation folding). An anti-join on
            // binary keys, not a correlated NOT EXISTS: the subquery cache looks results up through
            // "a unique index over all parameters" (MariaDB KB, Subquery Cache), which under a _ci
            // collation treats 'ABC' and 'abc' as the same parameter.
            $pd = array_merge( [ $dim ], $p, [ $dim ] );
            if ( $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$t['values']} (dim, value_hash, value)
                 SELECT %d, s.h, s.v FROM (
                    SELECT MIN({$col}) AS v, UNHEX(MD5(MIN({$col}))) AS h, CAST(MIN({$col}) AS BINARY) AS b
                    FROM {$log} WHERE {$w} AND {$col} IS NOT NULL GROUP BY CAST({$col} AS BINARY)
                 ) s
                 LEFT JOIN {$t['values']} x ON x.dim = %d AND x.value_hash = s.h AND CAST(x.value AS BINARY) = s.b
                 WHERE x.id IS NULL",
                $pd
            ) ) === false ) {
                return kssl_agg_fail( 'build 5', $from );
            }
            $pi = array_merge( [ $dim, $dim ], $p );
            if ( $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$t['dim']} (bucket, dim, is_bot, is_suspicious, logged_in, visit_type, source, status_code, value_id, hits)
                 SELECT {$bucket}, %d, l.is_bot, l.is_suspicious, " . kssl_agg_logged_in_sql( 'l.user_id' ) . ", l.visit_type, l.source, l.status_code,
                        CASE WHEN l.{$col} IS NULL THEN 0 ELSE x.id END, COUNT(*)
                 FROM {$log} l
                 LEFT JOIN {$t['values']} x ON l.{$col} IS NOT NULL AND x.dim = %d AND x.value_hash = UNHEX(MD5(l.{$col})) AND CAST(x.value AS BINARY) = CAST(l.{$col} AS BINARY)
                 WHERE " . kssl_agg_prefix_columns( $w, 'l' ) . "
                 GROUP BY 1, l.is_bot, l.is_suspicious, 5, l.visit_type, l.source, l.status_code, 9",
                $pi
            ) ) === false ) {
                return kssl_agg_fail( 'build 6', $from );
            }
            // Check: every row of the range is in the dim table, NULL values only under id 0.
            $pc = array_merge( [ $dim, $from, $to ], $p );
            $check = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    (SELECT COALESCE(SUM(hits), 0) FROM {$t['dim']} WHERE dim = %d AND bucket >= %s AND bucket < %s) AS agg,
                    (SELECT COUNT(*) FROM {$log} WHERE {$w}) AS raw",
                $pc
            ) );
            if ( ! $check || (int) $check->agg !== (int) $check->raw ) {
                return kssl_agg_fail( "dim {$dim} sum " . ( $check ? $check->agg . ' vs ' . $check->raw : 'error' ), $from, (bool) $check );
            }
            $missing = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['dim']} d WHERE d.dim = %d AND d.bucket >= %s AND d.bucket < %s AND d.value_id <> 0
                 AND NOT EXISTS (SELECT 1 FROM {$t['values']} x WHERE x.id = d.value_id)",
                $dim, $from, $to
            ) );
            if ( $missing === null || (int) $missing !== 0 ) {
                return kssl_agg_fail( 'dictionary', $from, $missing !== null );
            }
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $rows = [];
        $hp = [];
        for ( $h = strtotime( $from . ' UTC' ); $h < strtotime( $to . ' UTC' ); $h += HOUR_IN_SECONDS ) {
            $rows[] = '(%s, %d, %s)';
            array_push( $hp, gmdate( 'Y-m-d H:00:00', $h ), $gen, $now );
        }
        if ( $wpdb->query( $wpdb->prepare( "INSERT INTO {$t['hours']} (bucket, gen, built_at) VALUES " . implode( ',', $rows ), $hp ) ) === false ) {
            return kssl_agg_fail( 'build 9', $from );
        }
        if ( kssl_agg_generation() !== $gen || ! kssl_suspicious_flag_is_current() ) {
            return kssl_agg_fail( 'build 10', $from );
        }
        $ok = $wpdb->query( 'COMMIT' ) !== false;
        return $ok;
    } finally {
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
        }
    }
}

/**
 * Record why a build was abandoned (error log) and return false. $corrupt: the in-transaction
 * check found the stored aggregates inconsistent (e.g. a damaged dictionary); the tick then
 * forgets everything and rebuilds.
 */
function kssl_agg_fail( $what, $bucket, $corrupt = false ) {
    global $wpdb;
    kssl_log_error( "Aggregate build {$what} failed at {$bucket}: " . $wpdb->last_error );
    $GLOBALS['kssl_agg_last_fail'] = "{$what} @ {$bucket}: " . $wpdb->last_error;
    if ( $corrupt ) {
        $GLOBALS['kssl_agg_corrupt'] = true;
    }
    return false;
}

/**
 * Qualify the columns of a source WHERE with a table alias.
 */
function kssl_agg_prefix_columns( $where, $alias ) {
    return preg_replace( '/\b(access_time|request_uri|referer_url)\b/', $alias . '.$1', $where );
}

/**
 * Visitor key normalized so that byte equality matches the log column's equality (the one the
 * exact COUNT(DISTINCT ...) uses), or null when that cannot be guaranteed for the collation.
 * _ci + PAD SPACE: case and trailing spaces are folded, which equals the collation for ASCII keys
 * (non-ASCII keys are detected separately). _bin: bytes as they are.
 */
function kssl_agg_hll_key_sql() {
    $c = kssl_table_collation();
    $key = "COALESCE(NULLIF(visitor_id_cookie, ''), ip_address)";
    $collation = strtolower( $c['collation'] );
    // PAD SPACE collations ignore trailing spaces (MariaDB: every collation whose name has no
    // "nopad"; MySQL's _0900_ ones are NO PAD).
    $pad = strpos( $collation, 'nopad' ) === false && strpos( $collation, '_0900_' ) === false;
    if ( $pad ) {
        $key = "TRIM(TRAILING ' ' FROM {$key})";
    }
    if ( substr( $collation, -4 ) === '_bin' ) {
        return $key;
    }
    if ( substr( $collation, -3 ) === '_ci' ) {
        return "UPPER({$key})";
    }
    return null;
}

/**
 * SQL computing HLL registers (index => max rank) of the rows matching $where, grouped by the
 * given extra columns. MariaDB bit operators work on 64-bit integers; bits shifted out are lost.
 */
function kssl_agg_hll_sql( $where, array $group_cols ) {
    $key = kssl_agg_hll_key_sql();
    $raw_key = "COALESCE(NULLIF(visitor_id_cookie, ''), ip_address)";
    $shift = 64 - KSSL_AGG_HLL_P;
    $max_rank = $shift + 1;
    $w = '(h << ' . KSSL_AGG_HLL_P . ') & 18446744073709551615';
    // $group_cols: alias => expression on the log table (the outer levels use the alias)
    $inner = '';
    $outer = '';
    foreach ( $group_cols as $alias => $expr ) {
        $inner .= "{$expr} AS {$alias}, ";
        $outer .= "{$alias}, ";
    }
    $log = kssl_get_log_table_name_func();
    return "SELECT {$outer}idx, MAX(rnk) AS r, MAX(na) AS na FROM (
                SELECT {$outer}h >> {$shift} AS idx,
                       LEAST(IF(({$w}) = 0, {$max_rank}, 65 - LENGTH(BIN({$w}))), {$max_rank}) AS rnk, na
                FROM (
                    SELECT {$inner}CAST(CONV(LEFT(MD5({$key}), 16), 16, 10) AS UNSIGNED) AS h,
                           (CAST({$raw_key} AS BINARY) REGEXP '[^ -~]') AS na
                    FROM {$log} WHERE {$where} AND {$raw_key} IS NOT NULL
                ) k
            ) r GROUP BY {$outer}idx";
}

/**
 * HLL of one UTC day, per (is_bot, is_suspicious, logged_in). Built only when all 24 hours of the
 * day are built with $gen. A day without rows gets a marker row (flags -1, empty registers).
 */
function kssl_agg_build_hll_day( $day, $gen ) {
    global $wpdb;
    $t = kssl_agg_tables();
    if ( kssl_agg_hll_key_sql() === null ) {
        return null;
    }
    $from = $day . ' 00:00:00';
    $to = gmdate( 'Y-m-d 00:00:00', strtotime( $day . ' UTC' ) + DAY_IN_SECONDS );
    $params = [];
    $where = kssl_agg_source_where( $from, $to, $params );
    $ok = false;
    $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
    $wpdb->query( 'START TRANSACTION' );
    try {
        $built = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['hours']} WHERE bucket >= %s AND bucket < %s AND gen = %d", $from, $to, $gen ) );
        if ( $built === null || (int) $built !== 24 ) {
            return null;
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            kssl_agg_hll_sql( $where, [ 'is_bot' => 'is_bot', 'is_suspicious' => 'is_suspicious', 'lg' => kssl_agg_logged_in_sql() ] ),
            $params
        ) );
        if ( $rows === null || $wpdb->last_error !== '' ) {
            return false;
        }
        $m = 1 << KSSL_AGG_HLL_P;
        $sketches = [];
        $non_ascii = 0;
        foreach ( $rows as $row ) {
            $key = (int) $row->is_bot . ':' . (int) $row->is_suspicious . ':' . (int) $row->lg;
            if ( ! isset( $sketches[ $key ] ) ) {
                $sketches[ $key ] = str_repeat( "\0", $m );
            }
            $sketches[ $key ][ (int) $row->idx ] = chr( (int) $row->r );
            if ( (int) $row->na ) {
                $non_ascii = 1;
            }
        }
        if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['hll']} WHERE day = %s", $day ) ) === false ) {
            return false;
        }
        if ( empty( $sketches ) ) {
            $sketches['-1:-1:-1'] = '';
        }
        foreach ( $sketches as $key => $registers ) {
            list( $bot, $susp, $lg ) = array_map( 'intval', explode( ':', $key ) );
            // Binary data as hex: wpdb refuses queries with invalid text.
            if ( $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$t['hll']} (day, is_bot, is_suspicious, logged_in, gen, non_ascii, registers) VALUES (%s, %d, %d, %d, %d, %d, UNHEX(%s))",
                $day, $bot, $susp, $lg, $gen, $non_ascii, bin2hex( $registers )
            ) ) === false ) {
                return false;
            }
        }
        if ( kssl_agg_generation() !== $gen ) {
            return false;
        }
        $ok = $wpdb->query( 'COMMIT' ) !== false;
        return $ok;
    } finally {
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
        }
    }
}

/**
 * Background job: build missing closed hours (newest first), then HLL days, then re-check a few
 * built hours against the raw log. 20 s budget; re-schedules itself sooner while work remains.
 */
function kssl_agg_tick() {
    global $wpdb;
    kssl_schedule_once( 'kssl_agg_tick', 5 * MINUTE_IN_SECONDS );
    if ( ! kssl_agg_available() || ! kssl_job_lock() ) {
        return;
    }
    try {
        if ( ! kssl_agg_create_tables() ) {
            return;
        }
        $t = kssl_agg_tables();
        $log = kssl_get_log_table_name_func();
        $start = microtime( true );
        $gen = kssl_agg_generation();
        $min = kssl_db_value( "SELECT MIN(access_time) FROM {$log}" );
        if ( $min === false || $min === null ) {
            return;
        }
        $first = strtotime( kssl_agg_hour_floor( $min ) . ' UTC' );
        $last_closed = strtotime( gmdate( 'Y-m-d H:00:00', time() - KSSL_AGG_CLOSED_AFTER ) . ' UTC' ); // exclusive
        $built = kssl_db_column( $wpdb->prepare( "SELECT bucket FROM {$t['hours']} WHERE gen = %d AND bucket >= %s", $gen, gmdate( 'Y-m-d H:i:s', $first ) ) );
        if ( $built === false ) {
            return;
        }
        $built = array_flip( $built );
        $remaining = false;
        // Walk days from the newest; in each day build the runs of missing hours.
        for ( $day_start = strtotime( gmdate( 'Y-m-d', $last_closed - 1 ) . ' UTC' ); $day_start + DAY_IN_SECONDS > $first; $day_start -= DAY_IN_SECONDS ) {
            $run_from = null;
            for ( $h = max( $day_start, $first ); $h <= min( $day_start + DAY_IN_SECONDS, $last_closed ); $h += HOUR_IN_SECONDS ) {
                $is_end = $h >= min( $day_start + DAY_IN_SECONDS, $last_closed );
                $missing = ! $is_end && ! isset( $built[ gmdate( 'Y-m-d H:i:s', $h ) ] );
                if ( $missing && $run_from === null ) {
                    $run_from = $h;
                } elseif ( ! $missing && $run_from !== null ) {
                    if ( microtime( true ) - $start >= KSSL_JOB_TIME_BUDGET ) {
                        $remaining = true;
                        break 2;
                    }
                    $GLOBALS['kssl_agg_corrupt'] = false;
                    $r = kssl_agg_build_range( gmdate( 'Y-m-d H:i:s', $run_from ), gmdate( 'Y-m-d H:i:s', $h ), $gen );
                    if ( $r === false ) {
                        if ( ! empty( $GLOBALS['kssl_agg_corrupt'] ) ) {
                            kssl_log_error( 'Aggregate tables were inconsistent; rebuilding everything.' );
                            kssl_agg_reset_all();
                            kssl_schedule_once( 'kssl_agg_tick', 30 );
                        }
                        return;
                    }
                    $run_from = null;
                }
            }
        }
        if ( ! $remaining ) {
            $remaining = ! kssl_agg_build_hll_days( $gen, $first, $last_closed, $start );
        }
        if ( ! $remaining ) {
            kssl_agg_verify_some( $gen );
        }
        if ( $remaining ) {
            wp_clear_scheduled_hook( 'kssl_agg_tick' );
            kssl_schedule_once( 'kssl_agg_tick', 30 );
        }
    } finally {
        kssl_job_unlock();
    }
}

/**
 * @return bool True when no HLL day is left to build within the budget.
 */
function kssl_agg_build_hll_days( $gen, $first, $last_closed, $start ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $have = kssl_db_column( $wpdb->prepare( "SELECT DISTINCT day FROM {$t['hll']} WHERE gen = %d", $gen ) );
    if ( $have === false ) {
        return true;
    }
    $have = array_flip( $have );
    $first_day = strtotime( gmdate( 'Y-m-d', $first ) . ' UTC' );
    for ( $d = strtotime( gmdate( 'Y-m-d', $last_closed ) . ' UTC' ) - DAY_IN_SECONDS; $d >= $first_day; $d -= DAY_IN_SECONDS ) {
        $day = gmdate( 'Y-m-d', $d );
        if ( isset( $have[ $day ] ) ) {
            continue;
        }
        if ( microtime( true ) - $start >= KSSL_JOB_TIME_BUDGET ) {
            return false;
        }
        if ( kssl_agg_build_hll_day( $day, $gen ) === false ) {
            return true;
        }
    }
    return true;
}

/**
 * Safety net: each run re-computes one built UTC day (rotating through every day, then starting
 * over) from the raw log and compares it with the stored aggregates, breakdown included. Hours
 * that differ are forgotten; the day's HLL is rebuilt.
 */
function kssl_agg_verify_some( $gen ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();
    $cursor = (string) get_option( KSSL_AGG_VERIFY_CURSOR_OPTION_KEY, '' );
    $day = kssl_db_value( $wpdb->prepare(
        "SELECT DATE(MIN(bucket)) FROM {$t['hours']} WHERE gen = %d AND bucket >= %s",
        $gen, $cursor === '' ? '0000-00-00 00:00:00' : gmdate( 'Y-m-d 00:00:00', strtotime( $cursor . ' UTC' ) + DAY_IN_SECONDS )
    ) );
    if ( $day === false ) {
        return;
    }
    if ( $day === null ) {
        update_option( KSSL_AGG_VERIFY_CURSOR_OPTION_KEY, '', false ); // one round done: start over
        return;
    }
    $from = $day . ' 00:00:00';
    $to = gmdate( 'Y-m-d H:i:s', strtotime( $from . ' UTC' ) + DAY_IN_SECONDS );
    $hours = kssl_db_column( $wpdb->prepare( "SELECT bucket FROM {$t['hours']} WHERE gen = %d AND bucket >= %s AND bucket < %s", $gen, $from, $to ) );
    if ( $hours === false ) {
        return;
    }
    $expected = kssl_agg_day_fingerprints( $from, $to, false );
    $stored = kssl_agg_day_fingerprints( $from, $to, true );
    if ( $expected === null || $stored === null ) {
        return;
    }
    $differ = [];
    foreach ( $hours as $bucket ) {
        if ( ( $expected[ $bucket ] ?? '' ) !== ( $stored[ $bucket ] ?? '' ) ) {
            $differ[] = $bucket;
        }
    }
    if ( ! empty( $differ ) ) {
        kssl_log_error( 'Aggregates differed from the log for ' . implode( ', ', $differ ) . '; rebuilding.' );
        kssl_agg_mark_dirty( $differ );
    } else {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['hll']} WHERE day = %s", $day ) ); // rebuilt by the next run
    }
    update_option( KSSL_AGG_VERIFY_CURSOR_OPTION_KEY, $from, false );
}

/**
 * Per-hour fingerprint of the aggregates of [$from, $to): computed from the raw log ($stored
 * false) or read from the aggregate tables ($stored true). Values are compared byte for byte.
 *
 * @return array|null bucket => md5, or null on a read error.
 */
function kssl_agg_day_fingerprints( $from, $to, $stored ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();
    $lines = [];
    if ( $stored ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT bucket AS b, CONCAT_WS('|', 'h', is_bot, is_suspicious, logged_in, LOWER(visit_type), LOWER(source), status_code, hits) AS k
             FROM {$t['hourly']} WHERE bucket >= %s AND bucket < %s",
            $from, $to
        ), ARRAY_A );
        $dims = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.bucket AS b, CONCAT_WS('|', 'd', a.dim, a.is_bot, a.is_suspicious, a.logged_in, LOWER(a.visit_type), LOWER(a.source), a.status_code, IF(a.value_id = 0, '<null>', HEX(v.value)), SUM(a.hits)) AS k
             FROM {$t['dim']} a LEFT JOIN {$t['values']} v ON v.id = a.value_id
             WHERE a.bucket >= %s AND a.bucket < %s
             GROUP BY a.bucket, a.dim, a.is_bot, a.is_suspicious, a.logged_in, LOWER(a.visit_type), LOWER(a.source), a.status_code, IF(a.value_id = 0, '<null>', HEX(v.value))",
            $from, $to
        ), ARRAY_A );
    } else {
        $p = [];
        $w = kssl_agg_source_where( $from, $to, $p );
        $li = kssl_agg_logged_in_sql();
        $bucket = "DATE_FORMAT(access_time, '%%Y-%%m-%%d %%H:00:00')";
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$bucket} AS b, CONCAT_WS('|', 'h', is_bot, is_suspicious, {$li}, LOWER(visit_type), LOWER(source), status_code, COUNT(*)) AS k
             FROM {$log} WHERE {$w} GROUP BY b, is_bot, is_suspicious, {$li}, LOWER(visit_type), LOWER(source), status_code",
            $p
        ), ARRAY_A );
        $dims = [];
        foreach ( kssl_agg_dims() as $dim => $def ) {
            $pd = [];
            $wd = kssl_agg_source_where( $from, $to, $pd );
            if ( $def['cond'] === 'referer' ) {
                $wd .= ' AND ' . kssl_agg_referer_cond( $pd );
            }
            $col = $def['value'];
            $part = $wpdb->get_results( $wpdb->prepare(
                "SELECT {$bucket} AS b, CONCAT_WS('|', 'd', {$dim}, is_bot, is_suspicious, {$li}, LOWER(visit_type), LOWER(source), status_code, IF({$col} IS NULL, '<null>', HEX({$col})), COUNT(*)) AS k
                 FROM {$log} WHERE {$wd}
                 GROUP BY b, is_bot, is_suspicious, {$li}, LOWER(visit_type), LOWER(source), status_code, IF({$col} IS NULL, '<null>', HEX({$col}))",
                $pd
            ), ARRAY_A );
            if ( $part === null ) {
                return null;
            }
            $dims = array_merge( $dims, $part );
        }
    }
    if ( $rows === null || $dims === null || $wpdb->last_error !== '' ) {
        return null;
    }
    foreach ( array_merge( $rows, $dims ) as $r ) {
        $lines[ $r['b'] ][] = $r['k'];
    }
    $out = [];
    foreach ( $lines as $b => $ls ) {
        sort( $ls, SORT_STRING );
        $out[ $b ] = md5( implode( "\n", $ls ) );
    }
    return $out;
}

/* ------------------------------------------------------------------------
 * Display
 * --------------------------------------------------------------------- */

/**
 * Filter spec usable with the aggregates, or null when some filter needs the raw log.
 */
function kssl_agg_filter_spec( array $filters, $include_suspicious ) {
    // The 24-hour preset is an exact time window (not whole hours): the raw log is small there.
    if ( ( $filters['period_preset_filter'] ?? '' ) === '24hours' ) {
        return null;
    }
    foreach ( [ 'ip_address_filter', 'request_uri_filter', 'user_agent_filter', 'referer_url_filter', 'country_code_filter', 'visitor_id_cookie_filter' ] as $k ) {
        if ( ! empty( $filters[ $k ] ) ) {
            return null;
        }
    }
    $spec = [ 'exclude_suspicious' => ! $include_suspicious ];
    if ( ! empty( $filters['user_id_filter'] ) ) {
        if ( $filters['user_id_filter'] === 'logged_in' ) {
            $spec['logged_in'] = 1;
        } elseif ( $filters['user_id_filter'] === 'not_logged_in' ) {
            $spec['logged_in'] = 0;
        } else {
            return null;
        }
    }
    if ( ! empty( $filters['is_bot_filter'] ) && in_array( $filters['is_bot_filter'], [ 'yes', 'no' ], true ) ) {
        $spec['is_bot'] = $filters['is_bot_filter'] === 'yes' ? 1 : 0;
    }
    if ( ! empty( $filters['visit_type_filter'] ) && in_array( $filters['visit_type_filter'], [ 'new', 'returning', 'transition', 'unknown' ], true ) ) {
        $spec['visit_type'] = $filters['visit_type_filter'];
    }
    if ( ! empty( $filters['source_filter'] ) && in_array( $filters['source_filter'], [ 'wordpress', 'static' ], true ) ) {
        $spec['source'] = $filters['source_filter'];
    }
    if ( ! empty( $filters['status_code_filter'] ) && is_numeric( $filters['status_code_filter'] ) ) {
        $spec['status_code'] = (int) $filters['status_code_filter'];
    }
    // Period: both ends, on whole UTC hours.
    $tz = $filters['timezone_filter'] ?? 'UTC';
    if ( empty( $filters['date_from_filter'] ) || empty( $filters['date_to_filter'] )
        || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from_filter'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to_filter'] ) ) {
        return null;
    }
    $from = kssl_convert_local_date_to_utc( $filters['date_from_filter'] . ' 00:00:00', $tz );
    $to_incl = kssl_convert_local_date_to_utc( $filters['date_to_filter'] . ' 23:59:59', $tz );
    $to = gmdate( 'Y-m-d H:i:s', strtotime( $to_incl . ' UTC' ) + 1 );
    if ( substr( $from, 14 ) !== '00:00' || substr( $to, 14 ) !== '00:00' || $to <= $from ) {
        return null;
    }
    $spec['from'] = $from;
    $spec['to'] = $to;
    $spec['timezone'] = $tz;
    return $spec;
}

/**
 * WHERE on an aggregate table for the spec's flag filters.
 */
function kssl_agg_flag_where( array $spec, $alias, array &$params, $with_status = true ) {
    $parts = [];
    if ( $spec['exclude_suspicious'] ) {
        $parts[] = "NOT ({$alias}.is_suspicious <=> 1)";
    }
    foreach ( [ 'is_bot' => '%d', 'logged_in' => '%d', 'visit_type' => '%s', 'source' => '%s' ] as $k => $f ) {
        if ( isset( $spec[ $k ] ) ) {
            $parts[] = "{$alias}.{$k} = {$f}";
            $params[] = $spec[ $k ];
        }
    }
    if ( $with_status && isset( $spec['status_code'] ) ) {
        $parts[] = "{$alias}.status_code = %d";
        $params[] = $spec['status_code'];
    }
    return empty( $parts ) ? '1=1' : implode( ' AND ', $parts );
}

/**
 * Split the period into built hours and raw ranges, reading the state once.
 *
 * @return array|null { raw_ranges: [[from,to],...], gen } or null when the raw path is better.
 */
function kssl_agg_plan( array $spec ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();
    $gen = kssl_agg_generation();
    $built = kssl_db_column( $wpdb->prepare(
        "SELECT bucket FROM {$t['hours']} WHERE gen = %d AND bucket >= %s AND bucket < %s",
        $gen, $spec['from'], $spec['to']
    ) );
    $min = kssl_db_value( "SELECT MIN(access_time) FROM {$log}" );
    if ( $built === false || $min === false ) {
        return null;
    }
    $built = array_flip( $built );
    // Hours before the first row and after now hold no rows.
    $lo = max( strtotime( $spec['from'] . ' UTC' ), $min === null ? PHP_INT_MAX : strtotime( kssl_agg_hour_floor( $min ) . ' UTC' ) );
    // Hours after now are raw hours too: an import can hold rows with future times.
    $hi = strtotime( $spec['to'] . ' UTC' );
    $ranges = [];
    $raw_hours = 0;
    $run = null;
    for ( $h = $lo; $h <= $hi; $h += HOUR_IN_SECONDS ) {
        $raw = $h < $hi && ! isset( $built[ gmdate( 'Y-m-d H:i:s', $h ) ] );
        if ( $raw ) {
            $raw_hours++;
            if ( $run === null ) {
                $run = $h;
            }
        } elseif ( $run !== null ) {
            $ranges[] = [ gmdate( 'Y-m-d H:i:s', $run ), gmdate( 'Y-m-d H:i:s', $h ) ];
            $run = null;
        }
        if ( $raw_hours > KSSL_AGG_MAX_RAW_HOURS || count( $ranges ) > KSSL_AGG_MAX_RAW_RANGES ) {
            return null;
        }
    }
    return [ 'raw_ranges' => $ranges, 'gen' => $gen, 'lo' => $lo, 'hi' => $hi ];
}

/**
 * Raw-log WHERE for the raw part: the display WHERE plus the raw ranges.
 */
function kssl_agg_raw_where( array $where_clauses, array $params, array $ranges ) {
    if ( empty( $ranges ) ) {
        return [ '0=1', [] ];
    }
    $or = [];
    foreach ( $ranges as $r ) {
        $or[] = '(access_time >= %s AND access_time < %s)';
        $params[] = $r[0];
        $params[] = $r[1];
    }
    $where_clauses[] = '(' . implode( ' OR ', $or ) . ')';
    return [ implode( ' AND ', $where_clauses ), $params ];
}

/**
 * Aggregate WHERE: the period, built hours of this generation, not a raw range.
 */
function kssl_agg_table_where( array $spec, array $plan, $alias, array &$params, $with_status = true ) {
    $parts = [ "{$alias}.bucket >= %s", "{$alias}.bucket < %s" ];
    array_push( $params, $spec['from'], $spec['to'] );
    foreach ( $plan['raw_ranges'] as $r ) {
        $parts[] = "NOT ({$alias}.bucket >= %s AND {$alias}.bucket < %s)";
        array_push( $params, $r[0], $r[1] );
    }
    $parts[] = kssl_agg_flag_where( $spec, $alias, $params, $with_status );
    return implode( ' AND ', $parts );
}

/**
 * Totals, charts and trend for the display from the aggregates, or null (use the raw queries).
 *
 * @return array|null { total_items, unique_visitors, unique_approx, charts, trend }
 */
function kssl_agg_display_data( array $spec, array $where_clauses, array $params ) {
    global $wpdb;
    if ( ! kssl_agg_available() || ! kssl_agg_tables_ready() ) {
        return null;
    }
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();
    // One consistent snapshot for the state and every read (a build commits hours and
    // aggregates together; dirty marking commits before the raw rows change).
    $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
    $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
    try {
        $plan = kssl_agg_plan( $spec );
        if ( $plan === null ) {
            return null;
        }
        $h = 'a';
        $join = "JOIN {$t['hours']} hs ON hs.bucket = a.bucket AND hs.gen = " . (int) $plan['gen'];
        list( $raw_where, $raw_params ) = kssl_agg_raw_where( $where_clauses, $params, $plan['raw_ranges'] );

        // Total
        $p = [];
        $w = kssl_agg_table_where( $spec, $plan, 'a', $p );
        $agg_total = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(a.hits), 0) FROM {$t['hourly']} a {$join} WHERE {$w}", $p ) );
        $raw_total = $wpdb->get_var( kssl_prepare_if_needed( "SELECT COUNT(*) FROM {$log} WHERE {$raw_where}", $raw_params ) );
        if ( $agg_total === null || $raw_total === null ) {
            return null;
        }
        $total = (int) $agg_total + (int) $raw_total;

        // Trend (same day expression as the raw trend: the current offset of the zone)
        $offset = 0;
        if ( $spec['timezone'] && $spec['timezone'] !== 'UTC' ) {
            try {
                $offset = ( new DateTimeZone( $spec['timezone'] ) )->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) );
            } catch ( Exception $e ) {
                $offset = 0;
            }
        }
        $day_agg = $offset ? 'DATE(DATE_ADD(a.bucket, INTERVAL ' . (int) $offset . ' SECOND))' : 'DATE(a.bucket)';
        $day_raw = $offset ? 'DATE(DATE_ADD(access_time, INTERVAL ' . (int) $offset . ' SECOND))' : 'DATE(access_time)';
        $p = [];
        $w = kssl_agg_table_where( $spec, $plan, 'a', $p );
        $trend_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d AS date, SUM(c) AS count FROM (
                SELECT {$day_agg} AS d, SUM(a.hits) AS c FROM {$t['hourly']} a {$join} WHERE {$w} GROUP BY d
                UNION ALL
                SELECT {$day_raw} AS d, COUNT(*) AS c FROM {$log} WHERE {$raw_where} GROUP BY d
             ) u GROUP BY d ORDER BY d ASC",
            array_merge( $p, $raw_params )
        ), ARRAY_A );
        if ( $trend_rows === null || $wpdb->last_error !== '' ) {
            return null;
        }
        $trend = kssl_agg_trend_result( $trend_rows, $spec['timezone'] );

        // Charts
        $charts = kssl_agg_charts( $spec, $plan, $join, $raw_where, $raw_params, $total );
        if ( $charts === null ) {
            return null;
        }

        // Unique visitors
        $days = ( strtotime( $spec['to'] . ' UTC' ) - strtotime( $spec['from'] . ' UTC' ) ) / DAY_IN_SECONDS;
        $unique = null;
        $approx = false;
        if ( $days > KSSL_AGG_EXACT_UNIQUE_DAYS && ! isset( $spec['visit_type'] ) && ! isset( $spec['source'] ) && ! isset( $spec['status_code'] ) ) {
            $unique = kssl_agg_hll_unique( $spec, $plan, $where_clauses, $params );
            $approx = $unique !== null;
        }
        return [
            'total_items'     => $total,
            'unique_visitors' => $unique,
            'unique_approx'   => $approx,
            'charts'          => $charts,
            'trend'           => $trend,
        ];
    } finally {
        $wpdb->query( 'COMMIT' );
    }
}

function kssl_agg_trend_result( array $rows, $timezone ) {
    if ( empty( $rows ) ) {
        return [ 'dates' => [], 'counts' => [], 'total' => 0, 'trend' => 'stable', 'change_percentage' => 0, 'timezone' => $timezone ];
    }
    $dates = [];
    $counts = [];
    foreach ( $rows as $row ) {
        $dates[] = $row['date'];
        $counts[] = (int) $row['count'];
    }
    $trend = 'stable';
    $change_percentage = 0;
    if ( count( $counts ) >= 4 ) {
        $mid = (int) floor( count( $counts ) / 2 );
        $first = array_sum( array_slice( $counts, 0, $mid ) ) / $mid;
        $second = array_sum( array_slice( $counts, $mid ) ) / ( count( $counts ) - $mid );
        $change_percentage = ( $second - $first ) / max( $first, 1 ) * 100;
        if ( $change_percentage > 10 ) {
            $trend = 'increasing';
        } elseif ( $change_percentage < -10 ) {
            $trend = 'decreasing';
        }
    }
    return [
        'dates'             => $dates,
        'counts'            => $counts,
        'total'             => array_sum( $counts ),
        'trend'             => $trend,
        'change_percentage' => round( $change_percentage, 1 ),
        'timezone'          => $timezone,
    ];
}

function kssl_agg_charts( array $spec, array $plan, $join, $raw_where, array $raw_params, $total ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();
    $labels = [
        'user_agent'   => __( 'ユーザーエージェント', 'kashiwazaki-seo-super-access-log' ),
        'country_code' => __( '国・地域', 'kashiwazaki-seo-super-access-log' ),
        'visit_type'   => __( '訪問タイプ', 'kashiwazaki-seo-super-access-log' ),
        'status_code'  => __( 'ステータスコード', 'kashiwazaki-seo-super-access-log' ),
    ];
    $all = [];
    foreach ( $labels as $key => $label ) {
        $p = [];
        $w = kssl_agg_table_where( $spec, $plan, 'a', $p );
        if ( $key === 'visit_type' || $key === 'status_code' ) {
            $agg_sql = "SELECT a.{$key} AS item, SUM(a.hits) AS c FROM {$t['hourly']} a {$join} WHERE {$w} GROUP BY a.{$key}";
        } else {
            $dim = $key === 'user_agent' ? 1 : 2;
            $agg_sql = kssl_agg_dim_sql( $dim, $w, $join );
        }
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT item, SUM(c) AS count FROM (
                {$agg_sql}
                UNION ALL
                SELECT {$key} AS item, COUNT(*) AS c FROM {$log} WHERE {$raw_where} GROUP BY {$key}
             ) u GROUP BY item ORDER BY count DESC LIMIT %d",
            array_merge( $p, $raw_params, [ 20 ] )
        ) );
        if ( $results === null || $wpdb->last_error !== '' ) {
            return null;
        }
        $all[ $key ] = kssl_format_chart_data_optimized( (array) $results, (int) $total, $label, $key );
    }
    // Referer domains
    $p = [];
    $w = kssl_agg_table_where( $spec, $plan, 'a', $p );
    $rp = $raw_params;
    $ref_cond = kssl_agg_referer_cond( $rp );
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT item, SUM(c) AS count FROM (
            " . kssl_agg_dim_sql( 3, $w, $join ) . "
            UNION ALL
            SELECT referer_host AS item, COUNT(*) AS c FROM {$log} WHERE {$raw_where} AND {$ref_cond} GROUP BY referer_host
         ) u GROUP BY item ORDER BY count DESC LIMIT %d",
        array_merge( $p, $rp, [ 20 ] )
    ) );
    $p = [];
    $w = kssl_agg_table_where( $spec, $plan, 'a', $p );
    $rp = $raw_params;
    $ref_cond = kssl_agg_referer_cond( $rp );
    $ref_total = $wpdb->get_var( $wpdb->prepare(
        "SELECT (SELECT COALESCE(SUM(a.hits), 0) FROM {$t['dim']} a {$join} WHERE a.dim = 3 AND {$w})
              + (SELECT COUNT(*) FROM {$log} WHERE {$raw_where} AND {$ref_cond})",
        array_merge( $p, $rp )
    ) );
    if ( $results === null || $ref_total === null || $wpdb->last_error !== '' ) {
        return null;
    }
    $all['referer_domain'] = kssl_format_chart_data_optimized( (array) $results, (int) $ref_total, __( 'リファラードメイン', 'kashiwazaki-seo-super-access-log' ), 'referer_domain' );
    return $all;
}

/**
 * Dimension counts grouped by the dictionary value (table collation, same grouping as the raw
 * GROUP BY); value id 0 is NULL.
 */
function kssl_agg_dim_sql( $dim, $where, $join ) {
    $t = kssl_agg_tables();
    return "SELECT v.value AS item, SUM(s.c) AS c FROM (
                SELECT a.value_id, SUM(a.hits) AS c FROM {$t['dim']} a {$join} WHERE a.dim = " . (int) $dim . " AND {$where} GROUP BY a.value_id
            ) s LEFT JOIN {$t['values']} v ON v.id = s.value_id GROUP BY v.value";
}

/**
 * Approximate distinct visitors: HLL of the built days merged with the raw rows of the rest.
 *
 * @return int|null
 */
function kssl_agg_hll_unique( array $spec, array $plan, array $where_clauses, array $params ) {
    global $wpdb;
    $t = kssl_agg_tables();
    $log = kssl_get_log_table_name_func();
    if ( kssl_agg_hll_key_sql() === null ) {
        return null;
    }
    // Only the part of the period that can hold rows (from the first row to the current hour).
    $from_ts = $plan['lo'];
    $to_ts = $plan['hi'];
    if ( $to_ts <= $from_ts ) {
        return 0;
    }
    $spec['from'] = gmdate( 'Y-m-d H:i:s', $from_ts );
    $spec['to'] = gmdate( 'Y-m-d H:i:s', $to_ts );
    // UTC days entirely inside the period may use their sketch; the partial days at both ends are
    // always read from the raw log (no systematic error at the day boundaries).
    $full_from = (int) ( ceil( $from_ts / DAY_IN_SECONDS ) * DAY_IN_SECONDS );
    $full_to = (int) ( floor( $to_ts / DAY_IN_SECONDS ) * DAY_IN_SECONDS ); // exclusive
    $raw_ranges = [];
    $have = [];
    $m = 1 << KSSL_AGG_HLL_P;
    $reg = array_fill( 0, $m, 0 );
    if ( $full_to > $full_from ) {
        $d1 = gmdate( 'Y-m-d', $full_from );
        $d2 = gmdate( 'Y-m-d', $full_to - DAY_IN_SECONDS );
        $days = $wpdb->get_results( $wpdb->prepare( "SELECT day, MAX(non_ascii) AS na FROM {$t['hll']} WHERE day >= %s AND day <= %s AND gen = %d GROUP BY day", $d1, $d2, $plan['gen'] ) );
        if ( $days === null || $wpdb->last_error !== '' ) {
            return null;
        }
        foreach ( $days as $d ) {
            if ( (int) $d->na ) {
                return null; // keys whose collation equality the normalization cannot guarantee
            }
            $have[ $d->day ] = true;
        }
        $flag = [];
        $flag_where = kssl_agg_flag_where( array_intersect_key( $spec, [ 'exclude_suspicious' => 1, 'is_bot' => 1, 'logged_in' => 1 ] ), 'h', $flag, false );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.registers FROM {$t['hll']} h WHERE h.day >= %s AND h.day <= %s AND h.gen = %d AND h.is_bot <> -1 AND {$flag_where}",
            array_merge( [ $d1, $d2, $plan['gen'] ], $flag )
        ) );
        if ( $rows === null || $wpdb->last_error !== '' ) {
            return null;
        }
        foreach ( $rows as $row ) {
            if ( strlen( $row->registers ) !== $m ) {
                return null;
            }
            foreach ( unpack( 'C*', $row->registers ) as $i => $v ) {
                if ( $v > $reg[ $i - 1 ] ) {
                    $reg[ $i - 1 ] = $v;
                }
            }
        }
    }
    // Everything not covered by a sketch: the partial days at the ends and full days without one.
    if ( $full_to <= $full_from ) {
        $raw_ranges[] = [ $spec['from'], $spec['to'] ];
    } else {
        if ( $from_ts < $full_from ) {
            $raw_ranges[] = [ $spec['from'], gmdate( 'Y-m-d H:i:s', $full_from ) ];
        }
        for ( $d = $full_from; $d < $full_to; $d += DAY_IN_SECONDS ) {
            if ( ! isset( $have[ gmdate( 'Y-m-d', $d ) ] ) ) {
                $raw_ranges[] = [ gmdate( 'Y-m-d H:i:s', $d ), gmdate( 'Y-m-d H:i:s', $d + DAY_IN_SECONDS ) ];
            }
        }
        if ( $to_ts > $full_to ) {
            $raw_ranges[] = [ gmdate( 'Y-m-d H:i:s', $full_to ), $spec['to'] ];
        }
    }
    $missing = $raw_ranges;
    if ( count( $missing ) > 5 ) {
        return null;
    }
    if ( ! empty( $missing ) ) {
        list( $w, $wp ) = kssl_agg_raw_where( $where_clauses, $params, $missing );
        $raw = $wpdb->get_results( kssl_prepare_if_needed( kssl_agg_hll_sql( $w, [] ), $wp ) );
        if ( $raw === null || $wpdb->last_error !== '' ) {
            return null;
        }
        foreach ( $raw as $row ) {
            if ( (int) $row->na ) {
                return null;
            }
            $i = (int) $row->idx;
            $reg[ $i ] = max( $reg[ $i ], (int) $row->r );
        }
    }
    return kssl_agg_hll_estimate( $reg );
}

function kssl_agg_hll_estimate( array $reg ) {
    $m = count( $reg );
    $alpha = 0.7213 / ( 1 + 1.079 / $m );
    $sum = 0.0;
    $zeros = 0;
    foreach ( $reg as $v ) {
        $sum += 2 ** ( -$v );
        if ( $v === 0 ) {
            $zeros++;
        }
    }
    $e = $alpha * $m * $m / $sum;
    if ( $e <= 2.5 * $m && $zeros > 0 ) {
        $e = $m * log( $m / $zeros );
    }
    return (int) round( $e );
}
