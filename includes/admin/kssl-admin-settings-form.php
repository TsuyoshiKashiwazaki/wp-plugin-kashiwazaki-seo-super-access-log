<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 設定フォームの表示
 */
function kssl_display_settings_form() {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field( 'kssl_save_settings_action', 'kssl_save_settings_nonce' ); ?>
        
        <?php submit_button('設定を保存', 'primary', 'submit_top', false, ['style' => 'margin-bottom: 20px;']); ?>
        
        <hr>
        <h3>表示カラム</h3>
        <p>ログテーブルに表示するカラムを選択してください。</p>
        <fieldset class="kssl-columns-selection" style="margin-bottom: 20px; padding: 15px; border: 1px solid #e5e5e5; background: #fafafa;">
            <?php
            $all_cols_defs = kssl_get_all_column_definitions();
            $current_disp_cols = kssl_get_option(KSSL_DISPLAYED_COLUMNS_OPTION_KEY, []);
            
            foreach ($all_cols_defs as $col_key => $col_label) {
                $checked = isset($current_disp_cols[$col_key]) ? 'checked' : '';
                echo '<label style="display: inline-block; margin-right: 15px; margin-bottom: 8px;">';
                echo '<input type="checkbox" name="kssl_columns[]" value="' . esc_attr($col_key) . '" ' . $checked . '> ';
                echo esc_html($col_label);
                echo '</label>';
            }
            ?>
        </fieldset>

        <hr>
        <h3>Cookie設定</h3>
        <table class="form-table">
            <tr>
                <th scope="row">Cookie有効期限（時間）</th>
                <td>
                    <input type="number" name="kssl_cookie_lifetime" value="<?php echo esc_attr(kssl_get_option(KSSL_COOKIE_LIFETIME_OPTION_KEY, KSSL_DEFAULT_COOKIE_LIFETIME)); ?>" min="1" max="8760" class="small-text">
                    <p class="description">訪問者追跡Cookieの有効期限を設定します（1-8760時間）。</p>
                </td>
            </tr>
        </table>

        <hr>
        <h3>アクセス除外設定</h3>
        <table class="form-table">
            <tr>
                <th scope="row">自サーバーIPからのアクセス除外</th>
                <td>
                    <label>
                        <input type="checkbox" name="kssl_exclude_self_server_ip" value="1" <?php checked(kssl_get_option(KSSL_EXCLUDE_SELF_SERVER_IP_OPTION_KEY, 1), 1); ?>>
                        自サーバーと同じIPアドレスからのアクセスをログに記録しない
                    </label>
                    <p class="description">
                        内部スクレイピングやシステムによるアクセスなど、サーバー内部からのアクセスを除外できます。<br>
                        現在のサーバーIP: <strong><?php echo esc_html(kssl_get_server_ip() ?: '取得できませんでした'); ?></strong>
                    </p>
                </td>
            </tr>
        </table>

        <hr>
        <h3>パフォーマンス設定</h3>
        <table class="form-table">
            <tr>
                <th scope="row">チャート表示制限</th>
                <td>
                    <?php
                    $current_limit = intval(kssl_get_option(KSSL_MAX_CHART_RECORDS_OPTION_KEY, KSSL_DEFAULT_CHART_LIMIT));
                    global $wpdb;
                    $table_name = kssl_get_log_table_name_func();
                    $total_logs = kssl_safe_db_query("SELECT COUNT(id) FROM {$table_name}", [], 'get_var', 0);
                    ?>
                    
                    <div class="kssl-chart-limit-container">
                        <div class="kssl-preset-buttons" style="margin-bottom: 15px;">
                            <button type="button" class="button kssl-preset-btn" data-value="50000">50K</button>
                            <button type="button" class="button kssl-preset-btn" data-value="100000">100K</button>
                            <button type="button" class="button kssl-preset-btn" data-value="250000">250K</button>
                            <button type="button" class="button kssl-preset-btn" data-value="500000">500K</button>
                            <button type="button" class="button kssl-preset-btn" data-value="1000000">1M</button>
                            <button type="button" class="button kssl-preset-btn" data-value="0">無制限</button>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>
                                <input type="radio" name="kssl_chart_limit_type" value="preset" <?php checked($current_limit !== 0 && in_array($current_limit, [50000, 100000, 250000, 500000, 1000000]) || $current_limit === 0); ?>>
                                プリセット値を使用
                            </label>
                            <input type="hidden" id="kssl_preset_value" name="kssl_preset_value" value="<?php echo esc_attr($current_limit); ?>">
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>
                                <input type="radio" name="kssl_chart_limit_type" value="custom" <?php checked(!in_array($current_limit, [0, 50000, 100000, 250000, 500000, 1000000])); ?>>
                                カスタム値:
                            </label>
                            <input type="number" id="kssl_custom_limit" name="kssl_custom_limit" value="<?php echo esc_attr($current_limit); ?>" min="0" step="1000" class="regular-text" placeholder="1000以上または0（無制限）">
                        </div>
                        
                        <input type="hidden" name="kssl_max_chart_records" id="kssl_final_limit" value="<?php echo esc_attr($current_limit); ?>">
                    </div>
                    
                    <div class="kssl-status-display" style="padding: 10px; background: #f0f0f1; border-radius: 4px; margin: 15px 0;">
                        <strong>現在の状況:</strong><br>
                        総ログ数: <span style="font-weight: bold;"><?php echo esc_html(number_format_i18n($total_logs)); ?></span><br>
                        現在の制限: <span style="font-weight: bold;"><?php echo $current_limit === 0 ? '無制限' : esc_html(number_format_i18n($current_limit)); ?></span><br>
                        <span style="color: #666; font-size: 12px;">※チャート状態は現在のフィルター条件により動的に変化します</span>
                    </div>
                    
                    <p class="description">
                        ログ数がこの制限を超えるとパフォーマンス維持のためチャートが無効になります。0で無制限（大量データには推奨しません）。
                    </p>
                </td>
            </tr>
        </table>

        <hr>
        <h3>自動クリーンアップ</h3>
        <table class="form-table">
            <tr>
                <th scope="row">自動クリーンアップを有効化</th>
                <td>
                    <label>
                        <input type="checkbox" name="kssl_enable_auto_cleanup" value="1" <?php checked(kssl_get_option(KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 0)); ?>>
                        古いログを自動削除
                    </label>
                    <p class="description">有効にすると、保存期間を過ぎたログがWP-Cron経由で1日1回自動削除されます。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">ログ保存期間（日数）</th>
                <td>
                    <?php $auto_cleanup_enabled = kssl_get_option(KSSL_ENABLE_AUTO_CLEANUP_OPTION_KEY, 0); ?>
                    <input type="number" name="kssl_log_retention_days" 
                           id="kssl_log_retention_days"
                           value="<?php echo esc_attr(kssl_get_option(KSSL_LOG_RETENTION_DAYS_OPTION_KEY, KSSL_DEFAULT_RETENTION_DAYS)); ?>" 
                           min="0" max="3650" class="small-text"
                           <?php echo $auto_cleanup_enabled ? '' : 'disabled'; ?>>
                    <p class="description">
                        <?php 
                        printf(
                            esc_html__('Logs older than this many days will be automatically deleted (if auto cleanup is enabled). Default: %d days. Set to 0 to disable automatic deletion.', 'kashiwazaki-seo-super-access-log'),
                            KSSL_DEFAULT_RETENTION_DAYS
                        ); 
                        ?>
                    </p>
                    <p class="description">
                        <strong><?php esc_html_e('Note:', 'kashiwazaki-seo-super-access-log'); ?></strong> 
                        <?php esc_html_e('This setting only applies when auto cleanup is enabled. Recommended: 30-90 days for most sites, 7-30 days for high-traffic sites.', 'kashiwazaki-seo-super-access-log'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            // 自動クリーンアップのチェックボックスの状態に応じてLog Retention Periodの有効/無効を切り替え
            function toggleRetentionField() {
                var autoCleanupChecked = $('input[name="kssl_enable_auto_cleanup"]').is(':checked');
                var retentionField = $('#kssl_log_retention_days');
                
                if (autoCleanupChecked) {
                    retentionField.prop('disabled', false);
                    retentionField.attr('min', '1'); // 自動クリーンアップが有効な場合は最小値1
                    if (retentionField.val() == '0') {
                        retentionField.val('<?php echo KSSL_DEFAULT_RETENTION_DAYS; ?>');
                    }
                } else {
                    retentionField.prop('disabled', true);
                    retentionField.attr('min', '0'); // 自動クリーンアップが無効な場合は0も許可
                }
            }
            
            // 初期状態の設定
            toggleRetentionField();
            
            // チェックボックスの変更時にフィールドの状態を更新
            $('input[name="kssl_enable_auto_cleanup"]').on('change', toggleRetentionField);
        });
        </script>

        <hr>
        <h3>トラッキング設定</h3>
        <table class="form-table">
            <tr>
                <th scope="row">国コード取得</th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY); ?>" value="1" <?php checked(kssl_get_option(KSSL_ENABLE_COUNTRY_LOOKUP_OPTION_KEY, 0)); ?>>
                        IPアドレスから国コードを推定
                    </label>
                    <p class="description">外部APIを使用して訪問者の国を判定します。パフォーマンスに影響する可能性があります。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">静的ページトラッキング</th>
                <td>
                    <label>
                        <input type="checkbox" name="kssl_static_tracking_enabled" value="1" <?php checked(kssl_get_option(KSSL_STATIC_TRACKING_OPTION_KEY, 0)); ?>>
                        静的HTMLページのトラッキングを有効化
                    </label>
                    <p class="description">JavaScriptとREST APIを通じてWordPress以外のページの追跡を可能にします。</p>
                    
                    <?php if (kssl_get_option(KSSL_STATIC_TRACKING_OPTION_KEY, 0)): ?>
                    <div class="kssl-static-tracking-code" style="margin-top: 15px; padding: 15px; background: #f1f1f1; border-left: 4px solid #0073aa;">
                        <h4 style="margin-top: 0;">静的HTMLページに貼付するコード例：</h4>
                        <p style="margin-bottom: 10px;"><strong>&lt;/body&gt;タグの直前</strong>に以下のコードを追加してください：</p>
                        <textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px; background: #fff; border: 1px solid #ccc;"><?php 
echo htmlspecialchars('<script>
(function() {
    var trackingData = {
        u: window.location.href,           // 現在のURL
        r: document.referrer || "",        // リファラー
        ua: navigator.userAgent,           // ユーザーエージェント
        t: document.title                  // ページタイトル
    };
    
    fetch("' . esc_url(rest_url('kashiwazaki-seo-super-access-log/v1/track')) . '", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify(trackingData),
        credentials: "include"
    }).catch(function(error) {
        console.warn("KSSL tracking failed:", error);
    });
})();
</script>'); 
                        ?></textarea>
                        <p style="margin-bottom: 0; font-size: 12px; color: #666;">
                            <strong>注意:</strong> このコードはWordPressサイトと同じドメイン・サブドメインの静的ページでのみ動作します。<br>
                            クロスドメインでの使用はセキュリティ上制限されています。
                        </p>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <hr>
        <h3>ログフィルタ設定</h3>
        <table class="form-table">
            <tr>
                <th scope="row">疑わしいキーワード</th>
                <td>
                    <textarea name="kssl_suspicious_keywords" rows="5" cols="50" class="large-text"><?php echo esc_textarea(kssl_get_option(KSSL_SUSPICIOUS_KEYWORDS_OPTION_KEY, KSSL_DEFAULT_SUSPICIOUS_KEYWORDS)); ?></textarea>
                    <p class="description">1行につき1つのキーワード。これらのキーワードを含むURLは疑わしいアクセスとしてマークされます（アクセス自体はブロックされません）。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">ログ除外URIパターン</th>
                <td>
                    <textarea name="kssl_excluded_uri_patterns" rows="5" cols="50" class="large-text"><?php echo esc_textarea(kssl_get_option(KSSL_EXCLUDED_URI_PATTERNS_OPTION_KEY, '')); ?></textarea>
                    <p class="description">1行につき1パターン。これらのパターンに一致するリクエストはログに記録されません（アクセス自体は許可されます）。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">ボット検出パターン</th>
                <td>
                    <textarea name="kssl_bot_detection_pattern_setting" rows="3" cols="50" class="large-text"><?php echo esc_textarea(kssl_get_option(KSSL_BOT_DETECTION_PATTERN_OPTION_KEY, '/(bot|crawl|slurp|spider|archiv|seek|extract|fetch|seeker|scan|survey|Mediapartners-Google|AdsBot-Google|FeedFetcher|Googlebot|bingbot|msnbot|YandexBot|Baiduspider|facebookexternalhit|twitterbot|linkedinbot|embedly|pinterest|SemrushBot|AhrefsBot|MJ12bot|Applebot|DuckDuckBot|BLEXBot|DotBot|Exabot|Sogou|ia_archiver|UptimeRobot|Linespider)/i')); ?></textarea>
                    <p class="description">User Agentからボットを検出する正規表現パターン。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">静的ファイルを除外</th>
                <td>
                    <label>
                        <input type="checkbox" name="kssl_exclude_static_files" value="1" <?php checked(kssl_get_option(KSSL_EXCLUDE_STATIC_FILES_OPTION_KEY, '1'), '1'); ?> />
                        JS、CSS、画像などの静的ファイルへのアクセスをログから除外する
                    </label>
                    <p class="description">チェックを入れると、下記パターンに一致するファイルへのアクセスがログに記録されません。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">静的ファイル除外パターン</th>
                <td>
                    <input type="text" name="kssl_static_files_pattern" class="large-text"
                           value="<?php echo esc_attr(kssl_get_option(KSSL_STATIC_FILES_PATTERN_OPTION_KEY, KSSL_DEFAULT_STATIC_FILES_PATTERN)); ?>" />
                    <p class="description">正規表現パターンです。デフォルト：<br>
                    <code><?php echo esc_html(KSSL_DEFAULT_STATIC_FILES_PATTERN); ?></code><br>
                    .css, .js, .json, .xml, .txt, 画像, フォント, 動画, 圧縮ファイルなどが含まれます。</p>
                </td>
            </tr>
        </table>

        <hr>
        <h3>ブロック設定</h3>
        <table class="form-table">
            <tr>
                <th scope="row">ブロック対象訪問者ID</th>
                <td>
                    <?php
                    $blocked_visitors = kssl_get_blocked_visitor_ids_func();
                    $blocked_visitors_text = implode("\n", $blocked_visitors);
                    ?>
                    <textarea name="kssl_blocked_visitors_list" rows="5" cols="50" class="large-text"><?php echo esc_textarea($blocked_visitors_text); ?></textarea>
                    <p class="description"><?php esc_html_e('One visitor ID per line. These visitors will be blocked from accessing the site.', 'kashiwazaki-seo-super-access-log'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">ブロック対象User Agent</th>
                <td>
                    <textarea name="<?php echo esc_attr(KSSL_BLOCKED_UAS_OPTION_KEY); ?>" rows="5" cols="50" class="large-text"><?php echo esc_textarea(kssl_get_option(KSSL_BLOCKED_UAS_OPTION_KEY, '')); ?></textarea>
                    <p class="description">1行につき1つのキーワード。これらのキーワードを含むUser Agentはブロックされます。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">User-Agentが空のアクセス</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><span>User-Agentが空のアクセスの処理</span></legend>
                        <p style="margin-bottom: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">
                            <strong>標準動作:</strong> User-Agentが空のアクセスは記録されません
                        </p>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(KSSL_BLOCK_EMPTY_UA_OPTION_KEY); ?>" value="1" <?php checked(get_option(KSSL_BLOCK_EMPTY_UA_OPTION_KEY, false)); ?>>
                            <strong>User-Agentが空のアクセスをブロックする（403エラーを返す）</strong>
                        </label>
                        <p class="description">
                            チェックを入れると、User-Agentが空のアクセスに対して403エラーを返してアクセスを拒否します。<br>
                            チェックなし（推奨）: アクセスは許可するが、ログに記録しない<br>
                            チェックあり: アクセス自体を拒否する（脆弱性スキャナーをブロック）
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
    <?php
}
