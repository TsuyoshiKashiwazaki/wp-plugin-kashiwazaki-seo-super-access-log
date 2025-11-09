<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ログの管理タブの表示
 */
function kssl_display_log_management_tab() {
    ?>
    <div class="wrap">
        <h2>ログの管理</h2>

        <?php kssl_display_database_status_dashboard(); ?>

        <hr style="margin: 30px 0;">

        <?php kssl_display_database_optimization_section(); ?>

        <hr style="margin: 30px 0;">

        <?php kssl_display_cache_management_section(); ?>

        <hr style="margin: 30px 0;">

        <?php kssl_display_log_deletion_section(); ?>

        <hr style="margin: 30px 0;">

        <?php kssl_display_csv_operations_section(); ?>

        <hr style="margin: 30px 0;">

        <?php kssl_display_debug_info_section(); ?>
    </div>
    <?php
}

/**
 * データベース状態ダッシュボード
 */
function kssl_display_database_status_dashboard() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();

    // データベース統計を取得
    $stats = kssl_get_database_statistics();

    // パフォーマンススコアを計算
    $performance_score = kssl_calculate_performance_score($stats);

    // 警告メッセージを生成
    $warnings = kssl_generate_warnings($stats);

    ?>
    <div class="kssl-dashboard">
        <h3>📊 データベース状態</h3>

        <!-- パフォーマンススコア -->
        <div class="kssl-performance-score" style="margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 20px;">
                <div class="kssl-score-circle" style="width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; font-weight: bold; <?php echo $performance_score >= 80 ? 'background: linear-gradient(135deg, #46b450 0%, #5cb85c 100%); color: white;' : ($performance_score >= 60 ? 'background: linear-gradient(135deg, #ffb900 0%, #ffa000 100%); color: white;' : 'background: linear-gradient(135deg, #dc3232 0%, #c92c2c 100%); color: white;'); ?>">
                    <?php echo $performance_score; ?>
                </div>
                <div>
                    <h4 style="margin: 0 0 10px 0;">パフォーマンススコア</h4>
                    <p style="margin: 0; color: #666;">
                        <?php
                        if ($performance_score >= 80) {
                            echo '✅ 優良 - データベースは最適な状態です';
                        } elseif ($performance_score >= 60) {
                            echo '⚠️ 注意 - 最適化を推奨します';
                        } else {
                            echo '❌ 要改善 - 今すぐ最適化してください';
                        }
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- 警告メッセージ -->
        <?php if (!empty($warnings)): ?>
        <div class="kssl-warnings" style="margin-bottom: 20px;">
            <?php foreach ($warnings as $warning): ?>
            <div class="notice notice-<?php echo esc_attr($warning['type']); ?> inline" style="margin: 5px 0;">
                <p><strong><?php echo esc_html($warning['icon']); ?> <?php echo esc_html($warning['title']); ?></strong><br>
                <?php echo esc_html($warning['message']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 統計カード -->
        <div class="kssl-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">

            <!-- レコード数 -->
            <div class="kssl-stat-card" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <p style="margin: 0; color: #666; font-size: 13px;">総レコード数</p>
                        <p style="margin: 5px 0 0 0; font-size: 28px; font-weight: bold; color: #0073aa;">
                            <?php echo number_format_i18n($stats['total_records']); ?>
                        </p>
                    </div>
                    <span style="font-size: 32px;">📝</span>
                </div>
            </div>

            <!-- テーブルサイズ -->
            <div class="kssl-stat-card" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <p style="margin: 0; color: #666; font-size: 13px;">データサイズ</p>
                        <p style="margin: 5px 0 0 0; font-size: 28px; font-weight: bold; color: #0073aa;">
                            <?php echo esc_html($stats['data_size_mb']); ?> MB
                        </p>
                    </div>
                    <span style="font-size: 32px;">💾</span>
                </div>
            </div>

            <!-- インデックスサイズ -->
            <div class="kssl-stat-card" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <p style="margin: 0; color: #666; font-size: 13px;">インデックスサイズ</p>
                        <p style="margin: 5px 0 0 0; font-size: 28px; font-weight: bold; color: #0073aa;">
                            <?php echo esc_html($stats['index_size_mb']); ?> MB
                        </p>
                        <p style="margin: 3px 0 0 0; font-size: 11px; color: #999;">
                            <?php echo $stats['index_count']; ?>個のインデックス
                        </p>
                    </div>
                    <span style="font-size: 32px;">🔍</span>
                </div>
            </div>

            <!-- 断片化率 -->
            <div class="kssl-stat-card" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <p style="margin: 0; color: #666; font-size: 13px;">断片化</p>
                        <p style="margin: 5px 0 0 0; font-size: 28px; font-weight: bold; color: <?php echo $stats['fragmentation_percent'] > 20 ? '#dc3232' : '#46b450'; ?>">
                            <?php echo esc_html($stats['fragmentation_percent']); ?>%
                        </p>
                        <p style="margin: 3px 0 0 0; font-size: 11px; color: #999;">
                            <?php echo $stats['data_free_mb']; ?> MB 空き領域
                        </p>
                    </div>
                    <span style="font-size: 32px;"><?php echo $stats['fragmentation_percent'] > 20 ? '⚠️' : '✅'; ?></span>
                </div>
            </div>

        </div>

        <!-- 詳細情報 -->
        <details style="margin-top: 20px;">
            <summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f6f7f7; border-radius: 4px;">📋 詳細情報を表示</summary>
            <div style="margin-top: 10px; padding: 15px; background: white; border: 1px solid #ddd; border-radius: 4px;">
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>テーブル名</strong></td>
                            <td><?php echo esc_html($table_name); ?></td>
                        </tr>
                        <tr>
                            <td><strong>エンジン</strong></td>
                            <td><?php echo esc_html($stats['engine']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>ROW_FORMAT</strong></td>
                            <td><?php echo esc_html($stats['row_format']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>平均レコードサイズ</strong></td>
                            <td><?php echo esc_html($stats['avg_row_length']); ?> bytes</td>
                        </tr>
                        <tr>
                            <td><strong>合計サイズ</strong></td>
                            <td><?php echo esc_html($stats['total_size_mb']); ?> MB</td>
                        </tr>
                        <tr>
                            <td><strong>最終最適化</strong></td>
                            <td><?php echo esc_html($stats['last_optimized'] ?: '不明'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
    <?php
}

/**
 * データベース統計を取得
 */
function kssl_get_database_statistics() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();

    // テーブル情報を取得
    $table_info = $wpdb->get_row("
        SELECT
            engine,
            row_format,
            table_rows,
            avg_row_length,
            ROUND(data_length/1024/1024, 2) as data_size_mb,
            ROUND(index_length/1024/1024, 2) as index_size_mb,
            ROUND(data_free/1024/1024, 2) as data_free_mb,
            ROUND((data_length + index_length)/1024/1024, 2) as total_size_mb,
            data_free,
            data_length
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = '{$table_name}'
    ");

    // インデックス数を取得
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
    $index_count = count(array_unique(wp_list_pluck($indexes, 'Key_name')));

    // 実際のレコード数を取得
    $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

    // 断片化率を計算 (data_free / (data_length + data_free) * 100)
    $fragmentation_percent = 0;
    if ($table_info && $table_info->data_length > 0) {
        $fragmentation_percent = round(($table_info->data_free / ($table_info->data_length + $table_info->data_free)) * 100, 1);
    }

    // 最終最適化日時（オプションから取得）
    $last_optimized = get_option('kssl_last_optimization_time');
    if ($last_optimized) {
        $last_optimized = date('Y-m-d H:i:s', $last_optimized);
    }

    return [
        'engine' => $table_info->engine ?? 'Unknown',
        'row_format' => $table_info->row_format ?? 'Unknown',
        'total_records' => $total_records,
        'avg_row_length' => $table_info->avg_row_length ?? 0,
        'data_size_mb' => $table_info->data_size_mb ?? 0,
        'index_size_mb' => $table_info->index_size_mb ?? 0,
        'data_free_mb' => $table_info->data_free_mb ?? 0,
        'total_size_mb' => $table_info->total_size_mb ?? 0,
        'index_count' => $index_count,
        'fragmentation_percent' => $fragmentation_percent,
        'last_optimized' => $last_optimized,
    ];
}

/**
 * パフォーマンススコアを計算
 */
function kssl_calculate_performance_score($stats) {
    $score = 100;

    // 断片化率でペナルティ
    if ($stats['fragmentation_percent'] > 30) {
        $score -= 30;
    } elseif ($stats['fragmentation_percent'] > 20) {
        $score -= 20;
    } elseif ($stats['fragmentation_percent'] > 10) {
        $score -= 10;
    }

    // インデックス数チェック（少なすぎる場合）
    if ($stats['index_count'] < 10) {
        $score -= 20;
    }

    // テーブルサイズとレコード数の比率チェック
    if ($stats['total_records'] > 0) {
        $size_per_record = ($stats['data_size_mb'] * 1024 * 1024) / $stats['total_records'];
        // 1レコードあたり1KB以上の場合はペナルティ
        if ($size_per_record > 1024) {
            $score -= 10;
        }
    }

    // 最終最適化から時間が経過している場合
    $last_optimized_time = get_option('kssl_last_optimization_time');
    if ($last_optimized_time) {
        $days_since = (time() - $last_optimized_time) / 86400;
        if ($days_since > 60) {
            $score -= 15;
        } elseif ($days_since > 30) {
            $score -= 10;
        }
    } else {
        // 一度も最適化されていない
        $score -= 15;
    }

    return max(0, min(100, $score));
}

/**
 * 警告メッセージを生成
 */
function kssl_generate_warnings($stats) {
    $warnings = [];

    // 断片化の警告
    if ($stats['fragmentation_percent'] > 20) {
        $warnings[] = [
            'type' => 'error',
            'icon' => '⚠️',
            'title' => '高レベルの断片化を検出',
            'message' => sprintf('テーブルが%s%%断片化しています。最適化を実行してパフォーマンスを改善してください。', $stats['fragmentation_percent'])
        ];
    } elseif ($stats['fragmentation_percent'] > 10) {
        $warnings[] = [
            'type' => 'warning',
            'icon' => '⚠️',
            'title' => '中レベルの断片化を検出',
            'message' => sprintf('テーブルが%s%%断片化しています。定期的な最適化を推奨します。', $stats['fragmentation_percent'])
        ];
    }

    // 容量の警告
    if ($stats['total_size_mb'] > 1000) {
        $warnings[] = [
            'type' => 'warning',
            'icon' => '💾',
            'title' => 'データベース容量が大きくなっています',
            'message' => sprintf('合計サイズが%s MBに達しています。古いログの削除を検討してください。', $stats['total_size_mb'])
        ];
    }

    // インデックス数の警告
    if ($stats['index_count'] < 10) {
        $warnings[] = [
            'type' => 'error',
            'icon' => '🔍',
            'title' => 'インデックスが不足しています',
            'message' => sprintf('現在%s個のインデックスしかありません。最適化を実行してインデックスを作成してください。', $stats['index_count'])
        ];
    }

    // 最終最適化の警告
    $last_optimized_time = get_option('kssl_last_optimization_time');
    if (!$last_optimized_time) {
        $warnings[] = [
            'type' => 'warning',
            'icon' => '⏰',
            'title' => '最適化が実行されていません',
            'message' => 'まだ一度も最適化が実行されていません。最適化を実行してパフォーマンスを向上させてください。'
        ];
    } else {
        $days_since = (time() - $last_optimized_time) / 86400;
        if ($days_since > 60) {
            $warnings[] = [
                'type' => 'warning',
                'icon' => '⏰',
                'title' => '最適化から時間が経過しています',
                'message' => sprintf('最終最適化から%s日が経過しています。定期的な最適化を推奨します。', round($days_since))
            ];
        }
    }

    return $warnings;
}

/**
 * データベース最適化セクション
 */
function kssl_display_database_optimization_section() {
    // 自動最適化の設定を取得
    $auto_optimize_enabled = get_option('kssl_auto_optimization_enabled', '1');

    // 次の最適化予定を取得
    $next_optimization = wp_next_scheduled('kssl_monthly_optimization');
    $next_optimization_html = '';

    if ($auto_optimize_enabled === '1') {
        if ($next_optimization) {
            $next_time = get_date_from_gmt(date('Y-m-d H:i:s', $next_optimization), 'Y年m月d日 H:i');
            $days_until = ceil(($next_optimization - time()) / 86400);
            $next_optimization_html = sprintf(
                '<div style="background: #e6f3ff; padding: 10px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #0073aa;">'.
                '<strong>📅 次回の自動最適化:</strong> %s（約%d日後）'.
                '</div>',
                esc_html($next_time),
                $days_until
            );
        } else {
            $next_optimization_html = '<div style="background: #fff8e6; padding: 10px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #f0ad4e;">'.
                '<strong>⚠️ 注意:</strong> 自動最適化がスケジュールされていません。プラグインを再有効化してください。'.
                '</div>';
        }
    } else {
        $next_optimization_html = '<div style="background: #f0f0f0; padding: 10px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #999;">'.
            '<strong>ℹ️ 自動最適化は無効になっています。</strong> 手動で最適化を実行してください。'.
            '</div>';
    }
    ?>
    <div class="kssl-optimization-section">
        <h3>🔧 データベース最適化</h3>
        <div style="padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: white; margin-bottom: 20px;">
            <!-- 自動最適化のオン/オフ設定 -->
            <div style="background: #f8f8f8; padding: 12px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #0073aa;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" id="kssl-auto-optimize-toggle" <?php checked($auto_optimize_enabled, '1'); ?> style="margin-right: 8px;">
                    <strong style="font-size: 14px;">⚙️ 月1回の自動最適化を有効にする（WP-Cron）</strong>
                </label>
                <p style="margin: 8px 0 0 24px; font-size: 12px; color: #666;">無効にすると、自動最適化は実行されません。手動での最適化のみとなります。</p>
            </div>

            <p style="color: #0073aa; font-weight: bold; margin-bottom: 8px;">💡 自動最適化について</p>
            <p style="margin-bottom: 12px;">データベースの最適化は以下のタイミングで自動的に実行されます：</p>
            <ul style="margin: 10px 0 15px 20px; list-style: disc;">
                <li><strong>プラグイン有効化時:</strong> インデックス作成と初期最適化を実行</li>
                <li><strong>月1回（WP-Cron）:</strong> 定期的なメンテナンスを自動実行</li>
            </ul>

            <?php echo $next_optimization_html; ?>

            <details style="margin-top: 15px;">
                <summary style="cursor: pointer; color: #0073aa; font-weight: 600;">🔍 自動最適化の内容を見る</summary>
                <ul style="margin: 10px 0 0 20px; list-style: circle; color: #666;">
                    <li>✅ インデックスの作成・更新（高速検索のため）</li>
                    <li>✅ テーブルの最適化（断片化の解消）</li>
                    <li>✅ 統計情報の更新（クエリプランの改善）</li>
                    <li>❌ データの削除は行いません</li>
                </ul>
            </details>

            <div style="margin-top: 15px; padding: 10px; background: #f8f8f8; border-radius: 4px; font-size: 12px; color: #666;">
                <strong>💡 WP-Cronとは？</strong><br>
                WordPressの疑似cronシステムです。サイトへの訪問時に実行されるため、<strong>設定不要で自動的に動作</strong>します。<br>
                トラフィックが少ないサイトでは実行が遅れる場合がありますが、下のボタンから手動実行も可能です。
            </div>

            <p style="font-size: 13px; color: #666; margin-top: 15px;">必要に応じて手動で最適化を実行することもできます：</p>
            <button type="button" id="kssl-optimize-indexes-btn" class="button button-primary">
                今すぐデータベースを最適化
            </button>
            <div id="kssl-optimize-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
        </div>
    </div>
    <?php
}

/**
 * キャッシュ管理セクション
 */
function kssl_display_cache_management_section() {
    global $wpdb;

    // キャッシュ統計を取得
    $cache_stats = kssl_get_cache_statistics();

    // 自動削除設定を取得
    $auto_clear_expired = get_option('kssl_auto_clear_expired_cache', '1');

    ?>
    <div class="kssl-cache-management-section">
        <h3>🗄️ キャッシュ管理</h3>

        <div style="padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: white; margin-bottom: 20px;">
            <!-- 期限切れキャッシュの自動削除設定 -->
            <div style="background: #f8f8f8; padding: 12px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #0073aa;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" id="kssl-auto-clear-cache-toggle" <?php checked($auto_clear_expired, '1'); ?> style="margin-right: 8px;">
                    <strong style="font-size: 14px;">🗑️ 期限切れキャッシュを自動的に削除する</strong>
                </label>
                <p style="margin: 8px 0 0 24px; font-size: 12px; color: #666;">有効にすると、キャッシュクリア時に期限切れのキャッシュのみが削除されます。無効にすると、すべてのキャッシュが削除されます。</p>
            </div>

            <p style="color: #0073aa; font-weight: bold;">💡 トレンドデータキャッシュについて</p>
            <p>チャート表示のパフォーマンス向上のため、トレンドデータは30秒間キャッシュされます。フィルター条件を変更した際に古いデータが表示される場合は、キャッシュをクリアしてください。</p>

            <div class="kssl-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
                <!-- キャッシュ数 -->
                <div class="kssl-stat-card" style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 6px; padding: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <p style="margin: 0; color: #666; font-size: 12px;">キャッシュ数</p>
                            <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: #0073aa;">
                                <?php echo number_format_i18n($cache_stats['total_count']); ?> 件
                            </p>
                        </div>
                        <span style="font-size: 28px;">📦</span>
                    </div>
                </div>

                <!-- 有効なキャッシュ -->
                <div class="kssl-stat-card" style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 6px; padding: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <p style="margin: 0; color: #666; font-size: 12px;">有効なキャッシュ</p>
                            <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: #46b450;">
                                <?php echo number_format_i18n($cache_stats['active_count']); ?> 件
                            </p>
                        </div>
                        <span style="font-size: 28px;">✅</span>
                    </div>
                </div>

                <!-- 期限切れキャッシュ -->
                <div class="kssl-stat-card" style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 6px; padding: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <p style="margin: 0; color: #666; font-size: 12px;">期限切れキャッシュ</p>
                            <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: <?php echo $cache_stats['expired_count'] > 0 ? '#dc3232' : '#666'; ?>">
                                <?php echo number_format_i18n($cache_stats['expired_count']); ?> 件
                            </p>
                        </div>
                        <span style="font-size: 28px;"><?php echo $cache_stats['expired_count'] > 0 ? '⚠️' : '⏱️'; ?></span>
                    </div>
                </div>

                <!-- 総サイズ -->
                <div class="kssl-stat-card" style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 6px; padding: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <p style="margin: 0; color: #666; font-size: 12px;">総サイズ</p>
                            <p style="margin: 5px 0 0 0; font-size: 24px; font-weight: bold; color: #0073aa;">
                                <?php echo $cache_stats['total_size_mb']; ?> MB
                            </p>
                        </div>
                        <span style="font-size: 28px;">💾</span>
                    </div>
                </div>
            </div>

            <?php if ($cache_stats['expired_count'] > 0): ?>
            <div class="notice notice-warning inline" style="margin: 15px 0;">
                <p><strong>⚠️ 期限切れキャッシュが残っています</strong><br>
                <?php echo number_format_i18n($cache_stats['expired_count']); ?>個の期限切れキャッシュが見つかりました。キャッシュをクリアして古いデータを削除することを推奨します。</p>
            </div>
            <?php endif; ?>

            <div style="margin-top: 15px;">
                <button type="button" id="kssl-clear-cache-btn" class="button button-primary">
                    すべてのキャッシュをクリア
                </button>
                <button type="button" id="kssl-clear-expired-cache-btn" class="button button-secondary" style="margin-left: 10px;">
                    期限切れキャッシュのみクリア
                </button>
                <div id="kssl-cache-clear-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
            </div>

            <!-- キャッシュ詳細（折りたたみ） -->
            <?php if ($cache_stats['total_count'] > 0): ?>
            <details style="margin-top: 20px;">
                <summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f6f7f7; border-radius: 4px;">📋 キャッシュ詳細を表示</summary>
                <div style="margin-top: 10px; padding: 15px; background: white; border: 1px solid #ddd; border-radius: 4px; max-height: 400px; overflow-y: auto;">
                    <table class="widefat" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>キャッシュキー</th>
                                <th>ステータス</th>
                                <th>有効期限</th>
                                <th>残り時間</th>
                                <th>サイズ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cache_stats['cache_list'] as $cache): ?>
                            <tr>
                                <td style="font-family: monospace; font-size: 11px;">
                                    <?php echo esc_html(substr($cache['key'], 0, 40)) . '...'; ?>
                                </td>
                                <td>
                                    <?php if ($cache['is_expired']): ?>
                                        <span style="color: #dc3232;">⚠️ 期限切れ</span>
                                    <?php else: ?>
                                        <span style="color: #46b450;">✅ 有効</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($cache['expires_at']); ?></td>
                                <td>
                                    <?php if ($cache['is_expired']): ?>
                                        <span style="color: #dc3232;"><?php echo esc_html($cache['remaining']); ?></span>
                                    <?php else: ?>
                                        <?php echo esc_html($cache['remaining']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($cache['size']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // すべてのキャッシュをクリア
        $('#kssl-clear-cache-btn').on('click', function() {
            if (!confirm('すべてのトレンドデータキャッシュをクリアします。よろしいですか？')) {
                return;
            }

            var $button = $(this);
            var $result = $('#kssl-cache-clear-result');

            $button.prop('disabled', true).text('クリア中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_clear_cache',
                    nonce: '<?php echo wp_create_nonce('kssl_cache_nonce'); ?>',
                    clear_type: 'all'
                },
                success: function(response) {
                    if (response.success) {
                        $result.show().html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><strong>✓ クリア完了!</strong><br>' + response.data.message + '</div>');
                        // 現在のタブを保存してからリロード
                        sessionStorage.setItem('kssl_active_tab', '#log-management-section');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ エラー!</strong><br>' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 通信エラー!</strong><br>サーバーに接続できませんでした。</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('すべてのキャッシュをクリア');
                }
            });
        });

        // 期限切れキャッシュのみクリア
        $('#kssl-clear-expired-cache-btn').on('click', function() {
            var $button = $(this);
            var $result = $('#kssl-cache-clear-result');

            $button.prop('disabled', true).text('クリア中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_clear_cache',
                    nonce: '<?php echo wp_create_nonce('kssl_cache_nonce'); ?>',
                    clear_type: 'expired'
                },
                success: function(response) {
                    if (response.success) {
                        $result.show().html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><strong>✓ クリア完了!</strong><br>' + response.data.message + '</div>');
                        // 現在のタブを保存してからリロード
                        sessionStorage.setItem('kssl_active_tab', '#log-management-section');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ エラー!</strong><br>' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 通信エラー!</strong><br>サーバーに接続できませんでした。</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('期限切れキャッシュのみクリア');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * キャッシュ統計を取得
 */
function kssl_get_cache_statistics() {
    global $wpdb;

    // トレンドデータのキャッシュを検索
    $transients = $wpdb->get_results("
        SELECT option_name, option_value
        FROM {$wpdb->options}
        WHERE option_name LIKE '%transient%kssl_trend%'
        ORDER BY option_name
    ");

    $total_count = 0;
    $active_count = 0;
    $expired_count = 0;
    $total_size = 0;
    $cache_list = [];

    $current_time = time();

    foreach ($transients as $transient) {
        $name = $transient->option_name;
        $value = $transient->option_value;

        // timeout transientを処理
        if (strpos($name, '_timeout') !== false) {
            $total_count++;

            $key = str_replace('_transient_timeout_', '', $name);
            $expires_at = intval($value);
            $is_expired = $expires_at < $current_time;

            if ($is_expired) {
                $expired_count++;
                $remaining = '期限切れ (' . abs($current_time - $expires_at) . '秒前)';
            } else {
                $active_count++;
                $remaining_seconds = $expires_at - $current_time;
                $remaining = $remaining_seconds . '秒';
            }

            // データサイズを取得
            $data_key = '_transient_' . $key;
            $data_value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $data_key
            ));

            $size = 0;
            if ($data_value) {
                $size = strlen($data_value);
                $total_size += $size;
            }

            $cache_list[] = [
                'key' => $key,
                'expires_at' => date('Y-m-d H:i:s', $expires_at),
                'is_expired' => $is_expired,
                'remaining' => $remaining,
                'size' => number_format($size / 1024, 2) . ' KB'
            ];
        }
    }

    return [
        'total_count' => $total_count,
        'active_count' => $active_count,
        'expired_count' => $expired_count,
        'total_size_mb' => number_format($total_size / 1024 / 1024, 2),
        'cache_list' => $cache_list
    ];
}

/**
 * ログ削除セクション
 */
function kssl_display_log_deletion_section() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    ?>
    <div class="kssl-log-deletion-section">
        <h3>🗑️ ログ削除</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <!-- 条件付き削除 -->
            <div style="padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: white;">
                <h4>条件を指定して削除</h4>
                <p>指定した条件に合致するログデータを削除します。削除前に該当件数が表示されます。</p>

                <div style="margin-bottom: 15px;">
                    <label>削除対象期間:
                        <select name="delete_period_type" style="margin-left: 8px;">
                            <option value="">すべての期間</option>
                            <option value="older_than">指定日より古いデータ</option>
                            <option value="date_range">指定期間内のデータ</option>
                        </select>
                    </label>
                </div>

                <div id="delete-date-options" style="display: none; margin-bottom: 15px; padding: 10px; background: #f8f8f8; border-radius: 3px;">
                    <div id="older-than-option" style="display: none;">
                        <label>基準日: <input type="date" name="delete_older_than_date"></label>
                    </div>
                    <div id="date-range-option" style="display: none;">
                        <label>開始日: <input type="date" name="delete_date_from"></label><br>
                        <label style="margin-top: 5px;">終了日: <input type="date" name="delete_date_to"></label>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label>
                        <input type="checkbox" name="delete_only_bots">
                        ボットのアクセスのみ削除
                    </label><br>
                    <label style="margin-top: 5px;">
                        <input type="checkbox" name="delete_only_errors">
                        エラー（4xx, 5xx）のみ削除
                    </label><br>
                    <label style="margin-top: 5px;">
                        <input type="checkbox" name="delete_only_suspicious">
                        疑わしいアクセスのみ削除
                    </label>
                </div>

                <div style="margin-bottom: 15px;">
                    <label>特定のIPアドレス:
                        <input type="text" name="delete_specific_ip" placeholder="例: 192.168.1.1" style="margin-left: 8px;">
                    </label>
                </div>

                <button type="button" id="kssl-check-delete-count-btn" class="button button-secondary">
                    削除対象件数を確認
                </button>
                <div id="delete-count-result" style="margin-top: 10px; display: none;"></div>
                <button type="button" id="kssl-delete-logs-btn" class="button button-primary" style="margin-left: 10px;" disabled>
                    ログを削除
                </button>
            </div>

            <!-- 全削除 -->
            <div style="padding: 15px; border: 1px solid #dc3232; border-radius: 4px; background: #fff8f8;">
                <h4 style="color: #dc3232;">全ログ削除</h4>
                <p style="color: #666;">すべてのアクセスログデータを削除します。<strong>この操作は元に戻せません。</strong></p>
                <p style="color: #666;">削除前に必ずCSVエクスポートでバックアップを取ることをお勧めします。</p>

                <div style="margin-bottom: 15px;">
                    <label style="color: #dc3232; font-weight: bold;">
                        <input type="checkbox" id="confirm-delete-all" name="confirm_delete_all">
                        すべてのログを削除することを理解しました
                    </label>
                </div>

                <button type="button" id="kssl-delete-all-logs-btn" class="button button-primary" style="background: #dc3232; border-color: #dc3232;" disabled>
                    すべてのログを削除
                </button>

                <div style="margin-top: 15px; font-size: 12px; color: #666;">
                    現在のログ件数: <strong id="current-log-count"><?php echo number_format_i18n($total_logs); ?> 件</strong>
                </div>
            </div>
        </div>

        <div id="kssl-delete-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
    </div>
    <?php
}

/**
 * CSV操作セクション
 */
function kssl_display_csv_operations_section() {
    ?>
    <div class="kssl-csv-section">
        <h3>📁 CSV インポート・エクスポート</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <!-- Export Section -->
            <div style="padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: white;">
                <h4>📤 データエクスポート</h4>
                <p style="color: #666; margin-bottom: 15px;">現在のアクセスログデータをCSVファイルとしてダウンロードします。バックアップや分析に使用できます。</p>

                <div style="margin-bottom: 15px; background: #f8f8f8; padding: 12px; border-radius: 4px; border-left: 4px solid #0073aa;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <input type="radio" name="export_type" value="all" checked>
                        すべてのログをエクスポート
                    </label>
                    <p style="margin: 0 0 12px 24px; font-size: 12px; color: #666;">データベース内のすべてのアクセスログをエクスポートします。</p>

                    <label style="display: block; font-weight: 600;">
                        <input type="radio" name="export_type" value="filtered">
                        条件を指定してエクスポート
                    </label>
                    <p style="margin: 0 0 0 24px; font-size: 12px; color: #666;">下のフィルター条件に一致するログのみエクスポートします。</p>
                </div>

                <div id="export-filters" style="display: none; margin-bottom: 15px; padding: 12px; background: #fff9e6; border-radius: 4px; border: 1px solid #f0ad4e;">
                    <p style="font-weight: 600; margin-top: 0;"><strong>🔍 エクスポート条件:</strong></p>
                    <div style="display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
                        <label>📅 開始日: <input type="date" name="export_date_from" style="margin-left: 8px; width: 150px;"></label>
                        <label>📅 終了日: <input type="date" name="export_date_to" style="margin-left: 8px; width: 150px;"></label>
                        <label>🌐 IPアドレス: <input type="text" name="export_ip_filter" placeholder="例: 192.168.1.1" style="margin-left: 8px; width: 150px;"></label>
                        <label>📊 ステータスコード:
                            <select name="export_status_code" style="margin-left: 8px; width: 150px;">
                                <option value="">すべて</option>
                                <option value="200">200 (成功)</option>
                                <option value="301">301 (恒久的リダイレクト)</option>
                                <option value="302">302 (一時的リダイレクト)</option>
                                <option value="400">400 (不正リクエスト)</option>
                                <option value="403">403 (アクセス拒否)</option>
                                <option value="404">404 (未検出)</option>
                                <option value="500">500 (サーバーエラー)</option>
                                <option value="503">503 (サービス利用不可)</option>
                            </select>
                        </label>
                        <label>🤖 アクセスタイプ:
                            <select name="export_bot_filter" style="margin-left: 8px; width: 150px;">
                                <option value="">すべて</option>
                                <option value="0">通常アクセス</option>
                                <option value="1">ボット</option>
                            </select>
                        </label>
                        <label>🌍 国コード: <input type="text" name="export_country_code" placeholder="例: JP, US" style="margin-left: 8px; width: 150px;"></label>
                        <label>🔗 URLパターン: <input type="text" name="export_url_pattern" placeholder="例: /admin/" style="margin-left: 8px; width: 150px;"></label>
                        <label>📱 User-Agentパターン: <input type="text" name="export_ua_pattern" placeholder="例: Chrome" style="margin-left: 8px; width: 150px;"></label>
                        <label>👥 訪問タイプ:
                            <select name="export_visit_type" style="margin-left: 8px; width: 150px;">
                                <option value="">すべて</option>
                                <option value="new">新規訪問</option>
                                <option value="returning_session">リピーター（セッション内）</option>
                                <option value="returning">リピーター</option>
                            </select>
                        </label>
                        <label>💻 ソース:
                            <select name="export_source" style="margin-left: 8px; width: 150px;">
                                <option value="">すべて</option>
                                <option value="wordpress">WordPress</option>
                                <option value="static">静的ページ</option>
                            </select>
                        </label>
                        <label>🔙 リファラー: <input type="text" name="export_referer_pattern" placeholder="例: google.com" style="margin-left: 8px; width: 150px;"></label>
                        <label>⚠️ 疑わしいアクセスのみ:
                            <input type="checkbox" name="export_suspicious_only" value="1" style="margin-left: 8px;">
                        </label>
                    </div>
                </div>

                <button type="button" id="kssl-export-csv-btn" class="button button-primary">
                    📥 CSVファイルをダウンロード
                </button>

                <!-- Export Progress -->
                <div id="kssl-export-progress" style="display: none; margin-top: 15px;">
                    <div style="background: #f1f1f1; border-radius: 10px; overflow: hidden;">
                        <div id="kssl-export-progress-bar" style="height: 20px; background: #0073aa; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <p id="kssl-export-status" style="margin: 5px 0 0 0; font-size: 12px;"></p>
                </div>
            </div>

            <!-- Import Section -->
            <div style="padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: white;">
                <h4>📥 データインポート</h4>
                <p style="color: #666; margin-bottom: 15px;">CSVファイルからアクセスログデータをインポートします。正しいフォーマットのCSVファイルを使用してください。</p>

                <div style="margin-bottom: 15px; padding: 10px; background: #e6f3ff; border-radius: 4px; border-left: 4px solid #0073aa;">
                    <p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600;">📝 CSVフォーマットが不明な場合</p>
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=kssl_csv_sample&nonce=' . wp_create_nonce('kssl_csv_sample_nonce'))); ?>" class="button button-secondary" style="font-size: 12px; text-decoration: none;">
                        📄 サンプルCSVをダウンロード
                    </a>
                    <p style="margin: 8px 0 0 0; font-size: 11px; color: #666;">正しいカラム名と形式を確認できるサンプルファイルです。</p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">インポート方法を選択:</label>

                    <?php
                    $upload_max = ini_get('upload_max_filesize');
                    $upload_max_bytes = wp_convert_hr_to_bytes($upload_max);
                    $upload_max_mb = $upload_max_bytes / 1024 / 1024;
                    ?>

                    <div style="margin: 10px 0;">
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="import_method" value="existing" checked>
                            <strong>🔶 既存のエクスポートファイルから選択（推奨）</strong>
                            <span style="color: #0073aa; font-size: 12px; display: block; margin-left: 20px;">
                                <strong>大容量ファイル対応</strong> - サイズ制限なし、高速インポート
                            </span>
                        </label>
                        <div id="existing-method" style="margin-left: 20px; margin-top: 10px;">
                            <select id="kssl-import-existing" style="width: 100%; max-width: 400px;">
                                <option value="">-- ファイルを選択 --</option>
                            </select>
                            <button type="button" id="kssl-refresh-files" class="button" style="margin-top: 5px;">
                                🔄 ファイル一覧を更新
                            </button>
                            <div id="kssl-file-size-info" style="margin-top: 8px; color: #666; font-size: 12px;"></div>
                        </div>
                    </div>

                    <div style="margin: 10px 0;">
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="import_method" value="upload">
                            <strong>ファイルをアップロード</strong>
                            <span style="color: #d63638; font-size: 12px; display: block; margin-left: 20px;">
                                ⚠️ 最大<strong><?php echo $upload_max; ?></strong>までのファイルのみ
                                （現在の制限: <?php echo number_format($upload_max_mb, 1); ?> MB）
                            </span>
                        </label>
                        <div id="upload-method" style="margin-left: 20px; margin-top: 10px; display: none;">
                            <input type="file" id="kssl-import-file" name="import_csv_file" accept=".csv">
                            <div id="kssl-upload-warning" style="margin-top: 8px; padding: 8px; background: #ffebe8; border-left: 3px solid #d63638; display: none;">
                                <strong>❌ ファイルサイズが制限を超えています</strong><br>
                                <span id="kssl-file-size-error"></span><br>
                                「既存のエクスポートファイルから選択」を使用してください。
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 15px; padding: 10px; background: #f0f8ff; border-radius: 4px; border-left: 4px solid #0073aa;">
                        <strong>💡 ヒント:</strong> 大きなファイル（<?php echo $upload_max; ?>以上）をインポートする場合は、<br>
                        先に管理画面からエクスポートしてから「既存のエクスポートファイルから選択」を使用してください。
                    </div>
                </div>

                <div style="margin-bottom: 15px; padding: 10px; background: #f8f8f8; border-radius: 4px;">
                    <p style="margin: 0 0 8px 0; font-weight: 600;">インポートオプション:</p>
                    <label style="display: block; margin-bottom: 6px;">
                        <input type="checkbox" name="skip_duplicates" checked>
                        <strong>重複エントリをスキップ</strong>
                        <span style="font-size: 11px; color: #666; display: block; margin-left: 24px;">同じアクセス時刻・IPアドレス・User-Agent・URIのレコードは重複とみなされます</span>
                    </label>
                    <label style="display: block;">
                        <input type="checkbox" name="validate_data" checked>
                        <strong>データを検証してからインポート</strong>
                        <span style="font-size: 11px; color: #666; display: block; margin-left: 24px;">日時形式、ステータスコード、データ型などを検証します</span>
                    </label>
                </div>

                <button type="button" id="kssl-import-csv-btn" class="button button-primary" disabled>
                    📤 CSVをインポート
                </button>
                <div id="kssl-import-progress" style="display: none; margin-top: 10px;">
                    <div style="background: #f1f1f1; border-radius: 10px; overflow: hidden;">
                        <div id="kssl-import-progress-bar" style="height: 20px; background: #0073aa; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <p id="kssl-import-status" style="margin: 5px 0 0 0; font-size: 12px;"></p>
                </div>
            </div>
        </div>

        <div id="kssl-csv-result" style="margin-top: 10px; padding: 10px; display: none;"></div>

        <!-- Export Files List -->
        <div id="kssl-export-files-section" style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: white;">
            <h4>📦 エクスポート済みファイル</h4>
            <p style="color: #666; margin-bottom: 15px;">バックグラウンドで生成されたCSVファイルの一覧です。ダウンロードまたは削除できます。</p>
            <div id="kssl-export-files-list">
                <p style="color: #999; font-style: italic;">読み込み中...</p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * デバッグ情報セクション（簡略版）
 */
function kssl_display_debug_info_section() {
    global $wpdb;
    $table_name = kssl_get_log_table_name_func();
    $debug_info = kssl_debug_plugin_status();
    ?>
    <div class="kssl-debug-section">
        <h3>🔍 デバッグ情報</h3>
        <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
            <table class="widefat">
                <tbody>
                    <tr>
                        <td><strong>データベーステーブル</strong></td>
                        <td>
                            <strong><?php echo esc_html($debug_info['table_name']); ?></strong>
                            <?php if ($debug_info['table_exists']): ?>
                                <span style="color: green;">✓ 存在</span>
                            <?php else: ?>
                                <span style="color: red;">✗ 不存在</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>ログ件数</strong></td>
                        <td>
                            <?php if ($debug_info['table_exists']): ?>
                                <strong><?php echo esc_html(number_format_i18n($debug_info['log_count'])); ?></strong> 件
                            <?php else: ?>
                                <span style="color: red;">N/A（テーブル不存在）</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>初期化フック</strong></td>
                        <td>
                            <?php if ($debug_info['init_hook_registered']): ?>
                                <span style="color: green;">✓ 登録済み</span>
                            <?php else: ?>
                                <span style="color: red;">✗ 未登録</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>最新ログ</strong></td>
                        <td>
                            <?php if ($debug_info['table_exists'] && $debug_info['latest_log']): ?>
                                <strong><?php echo esc_html($debug_info['latest_log']->access_time); ?></strong>
                                <br>IP: <?php echo esc_html($debug_info['latest_log']->ip_address); ?>
                                <br>URI: <?php echo esc_html($debug_info['latest_log']->request_uri); ?>
                            <?php else: ?>
                                <span style="color: orange;">ログが見つかりません</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 15px;">
                <button type="button" id="kssl-test-log-btn" class="button button-secondary">
                    テストログ記録
                </button>
                <div id="kssl-test-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // CSV Export/Import UI処理
        $('input[name="export_type"]').on('change', function() {
            if ($(this).val() === 'filtered') {
                $('#export-filters').show();
            } else {
                $('#export-filters').hide();
            }
        });

        // ファイル選択時の処理
        $('#kssl-import-file').on('change', function() {
            $('#kssl-import-csv-btn').prop('disabled', !this.files.length);
        });

        // Note: CSV export and import handlers are now in kssl-admin.js to avoid duplicate event handlers

        // Note: CSV import handler is now in kssl-admin.js to avoid duplicate event handlers

        // ログ削除UI処理
        $('select[name="delete_period_type"]').on('change', function() {
            var selectedType = $(this).val();
            $('#delete-date-options').toggle(selectedType !== '');
            $('#older-than-option').toggle(selectedType === 'older_than');
            $('#date-range-option').toggle(selectedType === 'date_range');
        });

        // 全削除チェックボックスの処理
        $('#confirm-delete-all').on('change', function() {
            $('#kssl-delete-all-logs-btn').prop('disabled', !$(this).prop('checked'));
        });

        // 現在のログ件数を取得
        function updateLogCount() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_get_log_count',
                    nonce: '<?php echo wp_create_nonce('kssl_delete_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#current-log-count').text(response.data.count.toLocaleString() + ' 件');
                    } else {
                        $('#current-log-count').text('取得エラー');
                    }
                }
            });
        }

        // 削除対象件数確認
        $('#kssl-check-delete-count-btn').on('click', function() {
            var $button = $(this);
            var $result = $('#delete-count-result');
            var $deleteBtn = $('#kssl-delete-logs-btn');

            var filters = {
                period_type: $('select[name="delete_period_type"]').val(),
                older_than_date: $('input[name="delete_older_than_date"]').val(),
                date_from: $('input[name="delete_date_from"]').val(),
                date_to: $('input[name="delete_date_to"]').val(),
                only_bots: $('input[name="delete_only_bots"]').prop('checked'),
                only_errors: $('input[name="delete_only_errors"]').prop('checked'),
                only_suspicious: $('input[name="delete_only_suspicious"]').prop('checked'),
                specific_ip: $('input[name="delete_specific_ip"]').val()
            };

            $button.prop('disabled', true).text('確認中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_count_delete_logs',
                    nonce: '<?php echo wp_create_nonce('kssl_delete_nonce'); ?>',
                    filters: filters
                },
                success: function(response) {
                    if (response.success) {
                        var count = response.data.count;
                        $result.show().html('<div style="padding: 10px; border: 1px solid #0073aa; background: #e6f3ff; border-radius: 4px;"><strong>削除対象: ' + count.toLocaleString() + ' 件</strong><br>上記のログが削除されます。よろしいですか？</div>');
                        $deleteBtn.prop('disabled', count === 0);
                    } else {
                        $result.show().html('<div style="color: red; padding: 10px; border: 1px solid #d63638; background: #ffebee; border-radius: 4px;">エラー: ' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.show().html('<div style="color: red; padding: 10px; border: 1px solid #d63638; background: #ffebee; border-radius: 4px;">通信エラーが発生しました。</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('削除対象件数を確認');
                }
            });
        });

        // 条件付きログ削除
        $('#kssl-delete-logs-btn').on('click', function() {
            if (!confirm('選択した条件に合致するログを削除します。この操作は元に戻せません。続行しますか？')) {
                return;
            }

            var $button = $(this);
            var $result = $('#kssl-delete-result');

            var filters = {
                period_type: $('select[name="delete_period_type"]').val(),
                older_than_date: $('input[name="delete_older_than_date"]').val(),
                date_from: $('input[name="delete_date_from"]').val(),
                date_to: $('input[name="delete_date_to"]').val(),
                only_bots: $('input[name="delete_only_bots"]').prop('checked'),
                only_errors: $('input[name="delete_only_errors"]').prop('checked'),
                only_suspicious: $('input[name="delete_only_suspicious"]').prop('checked'),
                specific_ip: $('input[name="delete_specific_ip"]').val()
            };

            $button.prop('disabled', true).text('削除中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_delete_logs',
                    nonce: '<?php echo wp_create_nonce('kssl_delete_nonce'); ?>',
                    filters: filters
                },
                success: function(response) {
                    if (response.success) {
                        $result.show().html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><strong>✓ 削除完了!</strong><br>' + response.data.message + '<br><br>ページを更新します...</div>');
                        $('#delete-count-result').hide();
                        // データベース状態を最新にするためリロード
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 削除エラー!</strong><br>' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 通信エラー!</strong><br>サーバーに接続できませんでした。</div>');
                },
                complete: function() {
                    $button.prop('disabled', true).text('ログを削除');
                }
            });
        });

        // 全ログ削除
        $('#kssl-delete-all-logs-btn').on('click', function() {
            if (!confirm('すべてのアクセスログを削除します。この操作は元に戻せません。本当に続行しますか？')) {
                return;
            }

            var $button = $(this);
            var $result = $('#kssl-delete-result');

            $button.prop('disabled', true).text('削除中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_delete_all_logs',
                    nonce: '<?php echo wp_create_nonce('kssl_delete_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.show().html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><strong>✓ 全削除完了!</strong><br>' + response.data.message + '<br><br>ページを更新します...</div>');
                        $('#confirm-delete-all').prop('checked', false);
                        // データベース状態を最新にするためリロード
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 削除エラー!</strong><br>' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 通信エラー!</strong><br>サーバーに接続できませんでした。</div>');
                },
                complete: function() {
                    $button.prop('disabled', true).text('すべてのログを削除');
                }
            });
        });

        // データベース最適化ボタンの処理は kssl-admin-optimized.js で実装（バックグラウンド処理対応）

        // テストログボタンの処理
        $('#kssl-test-log-btn').on('click', function() {
            var $button = $(this);
            var $result = $('#kssl-test-result');

            $button.prop('disabled', true).text('テスト中...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_test_log_recording',
                    nonce: '<?php echo wp_create_nonce('kssl_test_log_nonce'); ?>'
                },
                success: function(response) {
                    $result.show();
                    if (response.success) {
                        $result.html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><strong>✓ 成功！</strong><br>' + response.data.message + '</div>');
                    } else {
                        $result.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ エラー！</strong><br>' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $result.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ AJAXエラー！</strong><br>サーバーに接続できませんでした。</div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('テストログ記録');
                }
            });
        });
    });
    </script>
    <?php
}
