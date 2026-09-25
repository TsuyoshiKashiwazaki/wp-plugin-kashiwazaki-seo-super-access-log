<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove the log tables (live, migration copy, pre-migration copy and aggregates), options, transients and
 * scheduled events of the current site.
 */
function kssl_uninstall_site() {
    global $wpdb;

    $base = $wpdb->prefix . 'super_access_logs';
    foreach ( [ $base, $base . '_new', $base . '_legacy', $base . '_agg_hourly', $base . '_agg_dim', $base . '_agg_values', $base . '_agg_hours', $base . '_agg_hll' ] as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    $options = [
        'kssl_displayed_columns', 'kssl_cookie_lifetime_hours', 'kssl_static_tracking_enabled',
        'kssl_suspicious_keywords', 'kssl_excluded_uri_patterns', 'kssl_bot_detection_pattern',
        'kssl_blocked_visitor_ids', 'kssl_enable_country_lookup', 'kssl_blocked_user_agents',
        'kssl_log_retention_days', 'kssl_enable_auto_cleanup', 'kssl_max_chart_records',
        'kssl_exclude_self_server_ip', 'kssl_exclude_static_files', 'kssl_static_files_pattern',
        'kssl_block_empty_ua', 'kssl_last_cleanup_date', 'kssl_last_cleanup_count',
        'kssl_last_optimization_time', 'kssl_auto_optimization_enabled', 'kssl_auto_clear_cache_enabled',
        'kssl_db_version', 'kssl_migration', 'kssl_suspicious_gen', 'kssl_suspicious_gen_done',
        'kssl_reclassify', 'kssl_cache_gen', 'kssl_table_collation', 'kssl_agg_verify_cursor', 'kssl_agg_schema',
        'kssl_auto_clear_expired_cache', 'kssl_private_dir_key', 'kssl_legacy_private_moved', 'kssl_legacy_private_left',
    ];
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Exported and uploaded CSV files (they hold log data): the private directory and the
    // directories used before it.
    $upload = wp_upload_dir( null, false );
    if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
        $dirs = (array) glob( $upload['basedir'] . '/kssl-private-*', GLOB_ONLYDIR );
        $dirs[] = $upload['basedir'] . '/kssl-exports';
        $dirs[] = $upload['basedir'] . '/kssl-temp';
        foreach ( $dirs as $dir ) {
            if ( preg_match( '#/(kssl-private-[a-f0-9]{32}|kssl-exports|kssl-temp)$#', $dir ) && is_dir( $dir ) && ! is_link( $dir ) ) {
                kssl_uninstall_remove_dir( $dir );
            }
        }
    }

    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_kssl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_kssl\\_%'" );
    // short-lived locks (aggregate recompute, running export/import step)
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kssl\\_agglock\\_%' OR option_name LIKE 'kssl\\_import\\_running\\_%' OR option_name LIKE 'kssl\\_export\\_running\\_%'" );

    foreach ( [ 'kssl_cleanup_old_logs', 'kssl_cleanup_old_logs_continue', 'kssl_monthly_optimization', 'kssl_migration_tick', 'kssl_derived_fill_tick', 'kssl_reclassify_tick', 'kssl_agg_tick', 'kssl_jobs_watchdog', 'kssl_process_export_job', 'kssl_process_import_job', 'kssl_import_job_step', 'kssl_export_job_step', 'kssl_process_optimize_background' ] as $hook ) {
        wp_unschedule_hook( $hook ); // all events of the hook, whatever their arguments (WP 4.9+)
    }
}

/**
 * Removes a directory the plugin created and everything in it (symbolic links are removed, not followed).
 */
function kssl_uninstall_remove_dir( $dir ) {
    $entries = scandir( $dir );
    if ( $entries === false ) {
        return;
    }
    foreach ( $entries as $entry ) {
        if ( $entry === '.' || $entry === '..' ) {
            continue;
        }
        $path = $dir . '/' . $entry;
        if ( is_dir( $path ) && ! is_link( $path ) ) {
            kssl_uninstall_remove_dir( $path );
        } else {
            @unlink( $path );
        }
    }
    @rmdir( $dir );
}

if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
        switch_to_blog( $blog_id );
        kssl_uninstall_site();
        restore_current_blog();
    }
} else {
    kssl_uninstall_site();
}
