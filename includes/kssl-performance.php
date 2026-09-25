<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database maintenance.
 *
 * "Optimize" only refreshes the optimizer statistics (ANALYZE TABLE, a few seconds). OPTIMIZE
 * TABLE rebuilds an InnoDB table and cannot finish inside one web request on a large log, so it
 * is not run from the plugin; indexes are defined by the table schema (kssl-db.php).
 */
class KSSL_Performance {

    /**
     * Kept for existing callers.
     */
    public static function optimize_database() {
        return self::optimize_database_complete();
    }

    /**
     * @return bool
     */
    public static function optimize_database_complete() {
        global $wpdb;
        $table_name = kssl_get_log_table_name_func();
        $result = $wpdb->query( "ANALYZE TABLE {$table_name}" );
        if ( $result === false ) {
            error_log( 'KSSL Database analyze error: ' . $wpdb->last_error );
            return false;
        }
        update_option( 'kssl_last_optimization_time', time() );
        return true;
    }
}
