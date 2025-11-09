<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kssl_display_log_table($log_entries, $displayed_columns, $current_orderby, $current_order, $current_filters, $current_page_num, $current_timezone = 'UTC') {
    if (empty($displayed_columns) || !is_array($displayed_columns)) {
        echo '<div class="notice notice-warning"><p>表示用カラムが選択されていないか、無効なカラムデータです。プラグイン設定を確認してください。</p></div>';
        return;
    }
    $blocked_visitor_ids = kssl_get_blocked_visitor_ids_func();
    ?>
    <table class="wp-list-table widefat fixed striped posts kssl-log-table">
        <thead>
            <tr>
                <?php
                $base_admin_url = admin_url('admin.php?page=kssl_access_log_page');
                // Add timezone to all sort links to maintain selection
                $base_admin_url = add_query_arg('timezone_filter', $current_timezone, $base_admin_url);

                foreach ($displayed_columns as $col_key => $col_label) {
                    $is_sortable = in_array($col_key, ['id', 'access_time', 'visitor_id_cookie', 'ip_address', 'country_code', 'status_code', 'user_id', 'visit_type', 'source']);
                    
                    $sort_indicator = '';
                    $sort_indicator_class = '';
                    $link_order = 'ASC';
                    if ($is_sortable) {
                        if ($current_orderby === $col_key) {
                            $sort_indicator_class = ' sorted ' . strtolower($current_order);
                            $sort_indicator = '<span class="sorting-indicator dashicons dashicons-arrow-' . (strtolower($current_order) === 'asc' ? 'up' : 'down') . '-alt2"></span>';
                            $link_order = ($current_order === 'ASC') ? 'DESC' : 'ASC';
                        } else {
                            $sort_indicator_class = ' sortable desc';
                        }
                        $sort_link_args = array_merge($current_filters, ['orderby' => $col_key, 'order' => $link_order, 'paged' => $current_page_num]);
                        // Remove timezone_filter from sort_link_args as it's already in base_admin_url
                        unset($sort_link_args['timezone_filter']);
                        $header_content = '<a href="' . esc_url(add_query_arg($sort_link_args, $base_admin_url)) . '"><span>' . esc_html($col_label) . '</span>' . $sort_indicator . '</a>';
                    } else {
                        $header_content = esc_html($col_label);
                    }
                    $is_primary = ($col_key === 'id' || (empty($displayed_columns['id']) && $col_key === 'access_time'));
                    echo '<th scope="col" id="kssl_' . esc_attr($col_key) . '" class="manage-column column-kssl_' . esc_attr($col_key) . ($is_primary ? ' column-primary' : '') . esc_attr($sort_indicator_class) . '">';
                    echo $header_content;
                    echo '</th>';
                }
                ?>
            </tr>
        </thead>
        <tbody id="the-list" data-wp-lists="list:log">
            <?php if ( ! empty( $log_entries ) ) : ?>
                <?php foreach ( $log_entries as $single_log ) :
                    $is_suspicious = kssl_is_suspicious_access($single_log);
                ?>
                    <tr class="<?php if ($is_suspicious) echo 'kssl-suspicious-row';?>">
                        <?php foreach ($displayed_columns as $col_key => $col_label) :
                            $value = isset($single_log->$col_key) ? $single_log->$col_key : '';
                            $is_primary = ($col_key === 'id' || (empty($displayed_columns['id']) && $col_key === 'access_time'));
                        ?>
                        <td class="column-kssl_<?php echo esc_attr($col_key); if ($is_primary) echo ' column-primary'; ?>" data-colname="<?php echo esc_attr($col_label); ?>">
                            <?php
                            if ($is_primary && $is_suspicious) {
                                echo '<span class="dashicons dashicons-warning" title="' . esc_attr__('Potentially suspicious access', 'kashiwazaki-seo-super-access-log') . '" style="color:red; margin-right:5px; vertical-align: middle;"></span>';
                            }
                            switch ($col_key) {
                                case 'access_time':
                                    echo esc_html( kssl_format_time_by_timezone($value, $current_timezone) );
                                    break;
                                case 'ip_address':
                                    if ($value) {
                                        $ip_filter_url_args = array_merge($current_filters, ['ip_address_filter' => $value, 'paged' => 1]);
                                        // Ensure timezone is passed along for IP filter link
                                        if (!isset($ip_filter_url_args['timezone_filter'])) {
                                            $ip_filter_url_args['timezone_filter'] = $current_timezone;
                                        }
                                        $ip_filter_url = add_query_arg($ip_filter_url_args, admin_url('admin.php?page=kssl_access_log_page'));
                                        echo '<a href="' . esc_url($ip_filter_url) . '" title="' . sprintf(esc_attr__('Filter by IP: %s', 'kashiwazaki-seo-super-access-log'), $value) . '">' . esc_html($value) . '</a>';
                                    } else {
                                        echo '–';
                                    }
                                    break;
                                case 'request_uri':
                                    $original_uri_value = $value;
                                    $parsed_url = wp_parse_url($original_uri_value);
                                    $display_path = '/';

                                    if (isset($parsed_url['path']) && !empty($parsed_url['path'])) {
                                        $display_path = $parsed_url['path'];
                                    }
                                    if ($display_path !== '/' && strpos($display_path, '/') !== 0) {
                                        $display_path = '/' . $display_path;
                                    }

                                    $link_target = '';
                                    if (isset($single_log->source) && $single_log->source === 'static') {
                                        if (filter_var($original_uri_value, FILTER_VALIDATE_URL)) {
                                            $link_target = esc_url($original_uri_value);
                                        }
                                    } else {
                                        if (preg_match('/^(http|https):\/\//i', $original_uri_value)) {
                                            $link_target = esc_url($original_uri_value);
                                        } else {
                                            $relative_path = '/' . ltrim($original_uri_value, '/');
                                            $link_target = esc_url(site_url($relative_path));
                                        }
                                    }

                                    echo '<div class="kssl-ellipsis-cell" title="' . esc_attr($original_uri_value) . '">';
                                    if (!empty($link_target)) {
                                        echo '<a href="' . $link_target . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_html_excerpt($display_path, 70, '...')) . '</a>';
                                    } else {
                                        echo esc_html(wp_html_excerpt($display_path, 70, '...'));
                                    }
                                    echo '</div>';
                                    break;
                                case 'visitor_id_cookie':
                                    echo '<div class="kssl-ellipsis-cell" title="' . esc_attr($value) . '">' . esc_html(wp_html_excerpt($value, 36, '...')) . '</div>';
                                    if (!empty($value)) {
                                        $is_blocked = in_array($value, $blocked_visitor_ids);
                                        $nonce_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=kssl_toggle_visitor_block&block_action=' . ($is_blocked ? 'unblock' : 'block') . '&visitor_id=' . urlencode($value)),
                                            'kssl_toggle_visitor_block_nonce',
                                            'kssl_nonce'
                                        );
                                        echo '<div class="kssl-visitor-actions">';
                                        if ($is_blocked) {
                                            echo '<a href="' . esc_url($nonce_url) . '" class="kssl-unblock-visitor-link" style="color:green;" title="' . esc_attr__('Unblock this visitor', 'kashiwazaki-seo-super-access-log') . '"><span class="dashicons dashicons-unlock"></span> ' . esc_html__('Unblock', 'kashiwazaki-seo-super-access-log') . '</a>';
                                        } else {
                                            echo '<a href="' . esc_url($nonce_url) . '" class="kssl-block-visitor-link" style="color:red;" title="' . esc_attr__('Block this visitor', 'kashiwazaki-seo-super-access-log') . '"><span class="dashicons dashicons-shield-alt"></span> ' . esc_html__('Block', 'kashiwazaki-seo-super-access-log') . '</a>';
                                        }
                                        echo '</div>';
                                    }
                                    break;
                                case 'user_agent':
                                    echo '<div class="kssl-ellipsis-cell" title="' . esc_attr($value) . '">' . esc_html(wp_html_excerpt($value, 70, '...')) . '</div>';
                                    break;
                                case 'referer_url':
                                    echo '<div class="kssl-ellipsis-cell">';
                                    echo $value ? '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $value ) . '">' . esc_html( wp_html_excerpt( $value, 50, '...' ) ) . '</a>' : '–';
                                    echo '</div>';
                                    break;
                                case 'country_code':
                                    if ($value === 'LAN') {
                                        echo esc_html__('LAN/Private', 'kashiwazaki-seo-super-access-log');
                                    } elseif ($value) {
                                        $flag_url = kssl_get_country_flag_url($value);
                                        if ($flag_url) {
                                            echo '<img src="' . esc_url($flag_url) . '" alt="' . esc_attr($value) . '" title="' . esc_attr($value) . '" style="width: 16px; height: 12px; vertical-align: middle; margin-right: 5px;" /> ';
                                        }
                                        echo esc_html( strtoupper($value) );
                                    } else {
                                        echo '–';
                                    }
                                    break;
                                case 'is_bot':
                                    echo $value ? esc_html__( 'Yes', 'kashiwazaki-seo-super-access-log' ) : esc_html__( 'No', 'kashiwazaki-seo-super-access-log' );
                                    break;
                                case 'user_id':
                                    echo $value ? esc_html( $value ) : '–';
                                    break;
                                case 'visit_type':
                                    $visit_icon = '';
                                    $visit_text = ucfirst($value);
                                    switch ($value) {
                                        case 'new':
                                            $visit_icon = '<span class="dashicons dashicons-star-filled" style="color: orange;" title="' . esc_attr__('New Visitor', 'kashiwazaki-seo-super-access-log') . '"></span> ';
                                            $visit_text = __('New', 'kashiwazaki-seo-super-access-log');
                                            break;
                                        case 'returning':
                                            $visit_icon = '<span class="dashicons dashicons-backup" style="color: green;" title="' . esc_attr__('Returning Visitor (New Session)', 'kashiwazaki-seo-super-access-log') . '"></span> ';
                                            $visit_text = __('Returning', 'kashiwazaki-seo-super-access-log');
                                            break;
                                        case 'transition':
                                            $visit_icon = '<span class="dashicons dashicons-arrow-right-alt2" style="color: #1d2327;" title="' . esc_attr__('Page Transition (Internal)', 'kashiwazaki-seo-super-access-log') . '"></span> ';
                                            $visit_text = __('Transition', 'kashiwazaki-seo-super-access-log');
                                            break;
                                        default:
                                            $visit_icon = '<span class="dashicons dashicons-editor-help" style="color: grey;" title="' . esc_attr__('Unknown Visit Type', 'kashiwazaki-seo-super-access-log') . '"></span> ';
                                             $visit_text = __('Unknown', 'kashiwazaki-seo-super-access-log');
                                            break;
                                    }
                                    echo $visit_icon . '<span class="screen-reader-text">' . esc_html($visit_text) . '</span><span aria-hidden="true">' . esc_html($visit_text) . '</span>';
                                    break;
                                case 'source':
                                    echo esc_html(ucfirst($value));
                                    break;
                                default:
                                    echo esc_html( $value );
                            }
                            ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr class="no-items"><td class="colspanchange" colspan="<?php echo count($displayed_columns); ?>">現在のフィルター条件ではログが見つかりません。</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
             <tr>
                <?php foreach ($displayed_columns as $col_key => $col_label) {
                     $is_primary = ($col_key === 'id' || (empty($displayed_columns['id']) && $col_key === 'access_time'));
                    echo '<th scope="col" class="manage-column column-kssl_' . esc_attr($col_key) . ($is_primary ? ' column-primary' : '') . '">';
                    echo esc_html($col_label);
                    echo '</th>';
                } ?>
            </tr>
        </tfoot>
    </table>
    <?php
}