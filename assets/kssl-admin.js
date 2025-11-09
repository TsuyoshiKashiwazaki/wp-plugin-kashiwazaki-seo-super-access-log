jQuery(document).ready(function($) {
    var ksslChartInstance;
    var trendChartInstance;
    var chartDataCache = {};
    var renderingChart = false;

    // DatePicker初期化
    if (typeof $.fn.datepicker === 'function') {
        $('#kssl-date-from-filter, #kssl-date-to-filter').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            showButtonPanel: true,
            constrainInput: true
        });
    }

    // Period preset functionality
    window.kssl_toggle_custom_dates = function(value) {
        var customDates = document.getElementById('kssl-custom-dates');
        if (customDates) {
            if (value === 'custom') {
                customDates.style.display = '';
            } else {
                customDates.style.display = 'none';
                // Clear custom date values when switching to preset
                var dateFromField = document.getElementById('kssl-date-from-filter');
                var dateToField = document.getElementById('kssl-date-to-filter');
                if (dateFromField) dateFromField.value = '';
                if (dateToField) dateToField.value = '';
            }
        }
    };

    // Visitor block/unblock confirmation
    $(document).on('click', '.kssl-block-visitor-link', function(e) {
        if (!confirm(kssl_ajax.confirm_block_visitor)) {
            e.preventDefault();
        }
    });
    $(document).on('click', '.kssl-unblock-visitor-link', function(e) {
        if (!confirm(kssl_ajax.confirm_unblock_visitor)) {
            e.preventDefault();
        }
    });

    // デバウンス関数を追加（連続的な呼び出しを制限）
    function debounce(func, wait) {
        var timeout;
        return function executedFunction() {
            var context = this;
            var args = arguments;
            var later = function() {
                timeout = null;
                func.apply(context, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // RequestAnimationFrameを使用した描画最適化
    function requestAnimFrame(callback) {
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(callback);
        } else {
            setTimeout(callback, 16);
        }
    }

    // チャート描画の最適化版
    function ksslRenderChartOptimized(chartKey) {
        if (renderingChart) return;
        renderingChart = true;

        requestAnimFrame(function() {
            if (!window.ksslAllChartData || !window.ksslAllChartData[chartKey]) {
                renderingChart = false;
                return;
            }

            var chartData = window.ksslAllChartData[chartKey];
            var chartContainer = $('#kssl-chart-canvas-container');
            var detailsContainer = $('#kssl-chart-details-container');

            // 既存のインスタンスを破棄
            if (ksslChartInstance) {
                ksslChartInstance.destroy();
                ksslChartInstance = null;
            }

            // チャートが無効の場合
            if (chartData.disabled) {
                chartContainer.html('<div style="text-align: center; color: #666; padding: 40px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;"><p><strong>Chart Disabled</strong></p><p>' + (chartData.message || 'Chart is disabled for performance reasons.') + '</p></div>');
                detailsContainer.html('<h4>' + chartData.title + '</h4><p style="color: #666;">' + (chartData.message || 'Chart is disabled for performance reasons.') + '</p>');
                renderingChart = false;
                return;
            }

            // Chart.jsのロードチェック
            if (typeof Chart === 'undefined') {
                var errorMsg = '<p style="color: #d63638; padding: 20px; text-align: center;">❌ Chart.jsのロードに失敗しました。ページをリロードしてください。</p>';
                chartContainer.html(errorMsg);
                detailsContainer.html('<h4>' + chartData.title + '</h4>' + errorMsg);
                renderingChart = false;
                return;
            }

            // データがない場合
            if (!chartData.labels || chartData.labels.length === 0 || !chartData.data || chartData.data.reduce((a, b) => a + b, 0) === 0) {
                var noDataMsg = '<p style="color: #666; padding: 20px; text-align: center;">📊 現在のフィルター条件ではデータがありません。<br>フィルターを変更するか、「すべて」を選択してください。</p>';
                chartContainer.html(noDataMsg);
                detailsContainer.html('<h4>' + chartData.title + '</h4>' + noDataMsg);
                renderingChart = false;
                return;
            }

            // キャンバス要素を作成
            chartContainer.html('<canvas id="kssl-stats-chart"></canvas>');
            var ctx = document.getElementById('kssl-stats-chart');

            if (!ctx) {
                renderingChart = false;
                return;
            }

            // 詳細リストを非同期で生成
            setTimeout(function() {
                var detailsHtml = '<h4>' + chartData.title + '</h4>';
                if (chartData.list && chartData.list.length > 0) {
                    // DocumentFragmentを使用してDOM操作を最適化
                    var fragment = document.createDocumentFragment();
                    var tempDiv = document.createElement('div');

                    detailsHtml += '<ul class="kssl-ua-list-ul">';

                    // 最大20件まで表示（パフォーマンス向上のため）
                    var displayLimit = Math.min(chartData.list.length, 20);
                    for (var i = 0; i < displayLimit; i++) {
                        var item = chartData.list[i];
                        var percentage = chartData.total > 0 ? ((item.count / chartData.total) * 100).toFixed(1) : '0.0';
                        detailsHtml += '<li>';
                        detailsHtml += '<div class="kssl-ua-list-item-stats"><strong>' + item.count.toLocaleString() + '</strong> (' + percentage + '%)</div>';
                        detailsHtml += '<div class="kssl-ua-list-item-ua" title="' + (item.item || '').replace(/"/g, '&quot;') + '">' + (item.item || '') + '</div>';
                        detailsHtml += '</li>';
                    }

                    if (chartData.list.length > displayLimit) {
                        detailsHtml += '<li style="text-align: center; color: #666;">... and ' + (chartData.list.length - displayLimit) + ' more</li>';
                    }

                    detailsHtml += '</ul>';
                } else {
                    detailsHtml += '<p style="color: #666; padding: 20px;">データがありません</p>';
                }

                // 注意メッセージがあれば表示
                if (chartData.notice && chartData.notice.length > 0) {
                    detailsHtml += '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-top: 15px; color: #856404;">';
                    detailsHtml += '<strong>📌 お知らせ:</strong> ' + chartData.notice;
                    detailsHtml += '</div>';
                }

                detailsContainer.html(detailsHtml);
            }, 50);

            // チャートカラーを事前に生成
            var chartColors = [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                '#FF9F40', '#E7E9ED', '#C9CBCE', '#A1E6E1', '#F0C2A7'
            ];

            // 必要に応じて追加カラーを生成
            while (chartColors.length < chartData.labels.length) {
                chartColors.push('#' + Math.floor(Math.random()*16777215).toString(16).padStart(6, '0'));
            }

            // Chart.js設定を最適化
            ksslChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        data: chartData.data,
                        backgroundColor: chartColors,
                        hoverBackgroundColor: chartColors,
                        borderWidth: 0 // ボーダーを削除してパフォーマンス向上
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 500 // アニメーション時間を短縮
                    },
                    plugins: {
                        legend: {
                            position: 'right',
                            align: 'start',
                            labels: {
                                boxWidth: 15,
                                padding: 10,
                                usePointStyle: true,
                                font: {
                                    size: 11
                                },
                                generateLabels: function(chart) {
                                    var data = chart.data;
                                    if (data.labels.length && data.datasets.length) {
                                        var sum = data.datasets[0].data.reduce((a, b) => a + b, 0);
                                        return data.labels.slice(0, 10).map(function(label, i) { // 最大10件まで表示
                                            var dataset = data.datasets[0];
                                            var value = dataset.data[i];
                                            var percentage = sum > 0 ? ((value / sum) * 100).toFixed(1) + '%' : '0.0%';

                                            return {
                                                text: label.length > 30 ? label.substring(0, 27) + '...' : label + ': ' + percentage,
                                                fillStyle: dataset.backgroundColor[i],
                                                hidden: false,
                                                index: i
                                            };
                                        });
                                    }
                                    return [];
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(tooltipItem) {
                                    var dataset = tooltipItem.dataset;
                                    var total = dataset.data.reduce((a, b) => a + b, 0);
                                    var currentValue = dataset.data[tooltipItem.dataIndex];
                                    var percentage = total > 0 ? ((currentValue / total) * 100).toFixed(2) : '0.00';
                                    return tooltipItem.label + ': ' + currentValue.toLocaleString() + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });

            renderingChart = false;
        });
    }

    // トレンドチャート描画の最適化版
    function ksslRenderTrendChartOptimized() {
        requestAnimFrame(function() {
            if (!window.ksslTrendData || !window.ksslTrendData.dates || window.ksslTrendData.dates.length === 0) {
                $('#kssl-trend-chart').parent().html('<p style="text-align: center; color: #666; padding: 40px;">' + (kssl_ajax.no_trend_data || 'No trend data available for the selected period.') + '</p>');
                $('#kssl-trend-total').text('-');
                $('#kssl-trend-indicator').removeClass('kssl-trend-increasing kssl-trend-decreasing kssl-trend-stable')
                    .addClass('kssl-trend-stable').html('➡️ Stable');
                return;
            }

            // サマリー情報の更新
            var totalAccess = window.ksslTrendData.total || 0;
            var trend = window.ksslTrendData.trend || 'stable';
            var changePercentage = window.ksslTrendData.change_percentage || 0;

            $('#kssl-trend-total').text(totalAccess.toLocaleString());

            var trendInfo = {
                'increasing': { text: 'Increasing', class: 'kssl-trend-increasing', icon: '📈' },
                'decreasing': { text: 'Decreasing', class: 'kssl-trend-decreasing', icon: '📉' },
                'stable': { text: 'Stable', class: 'kssl-trend-stable', icon: '➡️' }
            }[trend] || { text: 'Stable', class: 'kssl-trend-stable', icon: '➡️' };

            var trendHtml = trendInfo.icon + ' ' + trendInfo.text;
            if (changePercentage !== 0) {
                trendHtml += ' (' + (changePercentage > 0 ? '+' : '') + changePercentage + '%)';
            }

            $('#kssl-trend-indicator').removeClass('kssl-trend-increasing kssl-trend-decreasing kssl-trend-stable')
                .addClass(trendInfo.class).html(trendHtml);

            var ctx = document.getElementById('kssl-trend-chart');
            if (!ctx || typeof Chart === 'undefined') {
                return;
            }

            // 既存のインスタンスを破棄
            if (trendChartInstance) {
                trendChartInstance.destroy();
                trendChartInstance = null;
            }

            // データポイントが多い場合は間引く
            var dates = window.ksslTrendData.dates;
            var counts = window.ksslTrendData.counts;
            var maxDataPoints = 90; // 最大90ポイントまで（3ヶ月対応）

            if (dates.length > maxDataPoints) {
                var step = Math.ceil(dates.length / maxDataPoints);
                var sampledDates = [];
                var sampledCounts = [];

                for (var i = 0; i < dates.length; i += step) {
                    sampledDates.push(dates[i]);
                    // 元の値をそのまま使用（平均化しない）
                    sampledCounts.push(counts[i]);
                }

                dates = sampledDates;
                counts = sampledCounts;
            }

            // ラベルの生成を最適化
            var labels = dates.map(function(date) {
                var dateObj = new Date(date);
                return dateObj.toLocaleDateString();
            });

            // グラデーションの作成
            var gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(0, 115, 170, 0.3)');
            gradient.addColorStop(1, 'rgba(0, 115, 170, 0.05)');

            trendChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Daily Access Count',
                        data: counts,
                        borderColor: '#0073aa',
                        backgroundColor: gradient,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: dates.length > 20 ? 0 : 3, // データポイントが多い場合は点を非表示
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 500 // アニメーション時間を短縮
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            callbacks: {
                                label: function(context) {
                                    return 'Access Count: ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Date'
                            },
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxTicksLimit: 10, // 最大表示ラベル数を制限
                                font: {
                                    size: 10
                                }
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Access Count'
                            },
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        });
    }

    // チャート切り替えボタンのイベント（デバウンス付き）
    $('.kssl-chart-switch-btn').on('click', debounce(function() {
        var chartKey = $(this).data('chart-key');
        $('.kssl-chart-switch-btn').removeClass('button-primary');
        $(this).addClass('button-primary');

        var descriptionTexts = {
            'user_agent': '現在のフィルター条件に基づくユーザーエージェントの分布を表示しています。',
            'country_code': '現在のフィルター条件に基づく訪問者の国・地域の分布を表示しています。',
            'visit_type': '現在のフィルター条件に基づく訪問タイプの分布を表示しています。',
            'status_code': '現在のフィルター条件に基づくレスポンスステータスコードの分布を表示しています。',
            'referer_domain': '現在のフィルター条件に基づくリファラードメインの分布を表示しています。内部リファラーは除外されます。'
        };

        $('#kssl-chart-description').text(descriptionTexts[chartKey] || chartKey + 'のチャートデータ');
        ksslRenderChartOptimized(chartKey);
    }, 100));

    // 初期化処理
    $(document).ready(function() {
        // 最初のチャートを表示
        if ($('.kssl-chart-switch-btn').length) {
            setTimeout(function() {
                $('.kssl-chart-switch-btn[data-chart-key="user_agent"]').trigger('click');
            }, 100);
        }

        // トレンドチャートの初期化
        if ($('#trend-chart-display-area').is(':visible')) {
            setTimeout(function() {
                ksslRenderTrendChartOptimized();
            }, 200);
        }
    });

    // アコーディオンのイベント
    $('.kssl-accordion-trigger').on('click', function() {
        var $content = $(this).next('.kssl-accordion-content');
        var $icon = $(this).find('.dashicons');
        var isOpening = $content.is(':hidden');

        $content.slideToggle(200);

        if ($icon.hasClass('dashicons-arrow-down-alt2')) {
            $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
        } else {
            $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
        }

        // トレンドチャートが開かれた場合のみ描画
        if (isOpening && $content.attr('id') === 'trend-chart-display-area') {
            setTimeout(function() {
                ksslRenderTrendChartOptimized();
            }, 250);
        }
    });

    // その他の既存機能は元のファイルから必要に応じて移行
    $('.nav-tab-wrapper a.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab_id = $(this).attr('href');

        $('.nav-tab-wrapper a.nav-tab').removeClass('nav-tab-active');
        $('.kssl-tab-content').hide();

        $(this).addClass('nav-tab-active');
        $(tab_id).show();

        // 現在のタブをsessionStorageに保存
        sessionStorage.setItem('kssl_active_tab', tab_id);
    });

    // ページ読み込み時に前回のタブを復元
    var savedTab = sessionStorage.getItem('kssl_active_tab');
    if (savedTab && $(savedTab).length) {
        $('.nav-tab-wrapper a.nav-tab').removeClass('nav-tab-active');
        $('.kssl-tab-content').hide();

        $('.nav-tab-wrapper a[href="' + savedTab + '"]').addClass('nav-tab-active');
        $(savedTab).show();
    }

    // ログ削除の確認
    $('.kssl-confirm-clear-all').on('click', function(e) {
        if (!confirm(kssl_ajax.confirm_clear)) {
            e.preventDefault();
        }
    });

    $('.kssl-confirm-clear-filtered').on('click', function(e) {
        if (!confirm(kssl_ajax.confirm_clear_filtered)) {
            e.preventDefault();
        }
    });

    // ログ表示件数の変更
    $('#kssl-logs-per-page').on('change', function() {
        var newPerPage = $(this).val();
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('logs_per_page', newPerPage);
        currentUrl.searchParams.delete('paged');
        window.location.href = currentUrl.toString();
    });

    // チャート表示制限のプリセットボタン処理
    $('.kssl-preset-btn').on('click', function(e) {
        e.preventDefault();
        var presetValue = $(this).data('value');

        // プリセットラジオボタンを選択
        $('input[name="kssl_chart_limit_type"][value="preset"]').prop('checked', true);

        // プリセット値を設定
        $('#kssl_preset_value').val(presetValue);
        $('#kssl_final_limit').val(presetValue);

        // カスタム入力をクリア
        $('#kssl_custom_limit').val(presetValue);

        // ボタンのスタイルを更新（選択状態を視覚化）
        $('.kssl-preset-btn').removeClass('button-primary');
        $(this).addClass('button-primary');
    });

    // チャート制限タイプの切り替え
    $('input[name="kssl_chart_limit_type"]').on('change', function() {
        var limitType = $(this).val();

        if (limitType === 'preset') {
            // プリセット値を使用
            var presetValue = $('#kssl_preset_value').val();
            $('#kssl_final_limit').val(presetValue);
        } else if (limitType === 'custom') {
            // カスタム値を使用
            var customValue = $('#kssl_custom_limit').val();
            $('#kssl_final_limit').val(customValue);

            // プリセットボタンの選択を解除
            $('.kssl-preset-btn').removeClass('button-primary');
        }
    });

    // カスタム値の入力時処理
    $('#kssl_custom_limit').on('input change', function() {
        // カスタムラジオボタンを選択
        $('input[name="kssl_chart_limit_type"][value="custom"]').prop('checked', true);

        // カスタム値を最終値に設定
        var customValue = $(this).val();
        $('#kssl_final_limit').val(customValue);

        // プリセットボタンの選択を解除
        $('.kssl-preset-btn').removeClass('button-primary');
    });

    // ページ読み込み時に現在のプリセット値に対応するボタンをハイライト
    (function() {
        var currentLimit = parseInt($('#kssl_final_limit').val());
        $('.kssl-preset-btn').each(function() {
            if (parseInt($(this).data('value')) === currentLimit) {
                $(this).addClass('button-primary');
            }
        });
    })();

    // 設定フォームのsubmit時バリデーション
    $('form').on('submit', function(e) {
        var limitType = $('input[name="kssl_chart_limit_type"]:checked').val();

        if (limitType === 'custom') {
            var customLimit = parseInt($('#kssl_custom_limit').val());

            // カスタム値は0または1000以上でなければならない
            if (customLimit !== 0 && customLimit < 1000) {
                e.preventDefault();
                alert('カスタム値は1000以上、または0（無制限）を指定してください。');
                $('#kssl_custom_limit').focus();
                return false;
            }

            // 1000未満の値（0を除く）の警告
            if (customLimit > 0 && customLimit < 1000) {
                e.preventDefault();
                alert('1000未満の値は設定できません。0（無制限）または1000以上の値を入力してください。');
                $('#kssl_custom_limit').val('1000');
                $('#kssl_custom_limit').focus();
                return false;
            }
        }
    });

    // データベース最適化ボタンのイベント（統合版）
    // データベース最適化ボタン（バックグラウンド処理版）
    var optimizePollInterval = null;
    $('#kssl-optimize-indexes-btn').on('click', function() {
        var $button = $(this);
        var $resultDiv = $('#kssl-optimize-result');

        // スピナーとアニメーション付きテキストを設定
        $button.prop('disabled', true).html('<span class="kssl-spinner"></span> 最適化を開始中<span class="kssl-dots"></span>');
        $resultDiv.hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_optimize_database',
                nonce: kssl_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.job_id) {
                    // バックグラウンド処理開始
                    $resultDiv.show().html('<div style="border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><span class="kssl-spinner"></span> <strong>バックグラウンド処理を開始しました</strong> - データベースを最適化しています<span class="kssl-dots"></span><br>処理には数分かかる場合があります。このページを開いたままお待ちください。</div>');

                    // ポーリング開始
                    pollOptimizeStatus(response.data.job_id, $resultDiv, $button);
                } else {
                    $resultDiv.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 最適化エラー!</strong><br>' + (response.data.message || '最適化の開始に失敗しました') + '</div>');
                    $button.prop('disabled', false).text('今すぐデータベースを最適化');
                }
            },
            error: function() {
                $resultDiv.show().html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 通信エラー!</strong><br>サーバーに接続できませんでした。</div>');
                $button.prop('disabled', false).text('今すぐデータベースを最適化');
            }
        });
    });

    // 最適化状態をポーリング
    function pollOptimizeStatus(jobId, $resultDiv, $button) {
        if (optimizePollInterval) {
            clearInterval(optimizePollInterval);
        }

        optimizePollInterval = setInterval(function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_optimize_status',
                    nonce: kssl_ajax.nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data;

                        if (status.status === 'processing' || status.status === 'pending') {
                            // 進行中
                            $button.html('<span class="kssl-spinner"></span> 最適化中<span class="kssl-dots"></span>');
                            $resultDiv.html('<div style="border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><span class="kssl-spinner"></span> <strong>バックグラウンド処理実行中:</strong> ' + status.message + '<span class="kssl-dots"></span><br>進行状況: ' + status.progress + '%<br><em style="color: #666; font-size: 0.9em;">※ 処理は継続しています。しばらくお待ちください。</em></div>');
                        } else if (status.status === 'completed') {
                            // 完了
                            clearInterval(optimizePollInterval);

                            var detailsHtml = '';
                            if (status.total_records) {
                                detailsHtml += '<br>レコード数: ' + status.total_records;
                            }
                            if (status.table_size) {
                                detailsHtml += '<br>テーブルサイズ: ' + status.table_size;
                            }
                            if (status.index_count) {
                                detailsHtml += '<br>インデックス数: ' + status.index_count;
                            }

                            $resultDiv.html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px;"><strong>✓ 最適化完了!</strong><br>' + status.message + detailsHtml + '<br><br>ページを更新します...</div>');
                            $button.prop('disabled', false).text('今すぐデータベースを最適化');

                            // ページをリロード
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else if (status.status === 'error') {
                            // エラー
                            clearInterval(optimizePollInterval);
                            $resultDiv.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;"><strong>✗ 最適化エラー!</strong><br>' + status.message + '</div>');
                            $button.prop('disabled', false).text('今すぐデータベースを最適化');
                        }
                    }
                },
                error: function() {
                    // ポーリングエラーは無視（次回のポーリングで再試行）
                }
            });
        }, 3000); // 3秒ごとにポーリング
    }

    // ========== CSV インポート・エクスポート ==========

    // エクスポートタイプの切り替え（フィルター表示/非表示）
    $('input[name="export_type"]').on('change', function() {
        if ($(this).val() === 'filtered') {
            $('#export-filters').slideDown();
        } else {
            $('#export-filters').slideUp();
        }
    });

    // CSVエクスポートボタン
    var exportPollInterval = null;
    var exportInProgress = false;
    $('#kssl-export-csv-btn').on('click', function(e) {
        e.preventDefault();

        if (exportInProgress) {
            return false;
        }

        var $button = $(this);

        // Immediately disable button to prevent double-clicks
        $button.prop('disabled', true);

        var $progress = $('#kssl-export-progress');
        var $progressBar = $('#kssl-export-progress-bar');
        var $status = $('#kssl-export-status');
        var exportType = $('input[name="export_type"]:checked').val();

        exportInProgress = true;

        var data = {
            action: 'kssl_csv_export',
            nonce: kssl_ajax.csv_nonce,
            export_type: exportType
        };

        if (exportType === 'filtered') {
            data.date_from = $('input[name="export_date_from"]').val();
            data.date_to = $('input[name="export_date_to"]').val();
            data.ip_address = $('input[name="export_ip_filter"]').val();
            data.status_code = $('select[name="export_status_code"]').val();
            data.is_bot = $('select[name="export_bot_filter"]').val();
            data.country_code = $('input[name="export_country_code"]').val();
            data.url_pattern = $('input[name="export_url_pattern"]').val();
            data.ua_pattern = $('input[name="export_ua_pattern"]').val();
            data.visit_type = $('select[name="export_visit_type"]').val();
            data.source = $('select[name="export_source"]').val();
            data.referer_pattern = $('input[name="export_referer_pattern"]').val();
            data.suspicious_only = $('input[name="export_suspicious_only"]:checked').val();
        }

        $button.prop('disabled', true).html('<span class="kssl-spinner"></span> エクスポート開始中<span class="kssl-dots"></span>');
        $progress.show();
        $progressBar.css('width', '0%');
        $status.html('<span class="kssl-spinner"></span> エクスポートジョブを開始しています<span class="kssl-dots"></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success && response.data.job_id) {
                    // ポーリング開始
                    pollExportStatus(response.data.job_id, $progressBar, $status, $button);
                } else {
                    $status.html('<span style="color: red;">エクスポート開始に失敗しました: ' + (response.data.message || 'Unknown error') + '</span>');
                    $button.prop('disabled', false).text('📥 CSVファイルをダウンロード');
                    exportInProgress = false;
                }
            },
            error: function() {
                $status.html('<span style="color: red;">エクスポート開始に失敗しました（通信エラー）</span>');
                $button.prop('disabled', false).text('📥 CSVファイルをダウンロード');
                exportInProgress = false;
            }
        });
    });

    // エクスポート状態をポーリング
    function pollExportStatus(jobId, $progressBar, $status, $button) {
        if (exportPollInterval) {
            clearInterval(exportPollInterval);
        }

        exportPollInterval = setInterval(function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_check_export_status',
                    nonce: kssl_ajax.csv_nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data.status;

                        if (status.status === 'processing') {
                            // 進行中
                            $progressBar.css('width', status.progress + '%');
                            $status.text(status.message);
                        } else if (status.status === 'completed') {
                            // 完了
                            clearInterval(exportPollInterval);
                            exportInProgress = false;
                            $progressBar.css('width', '100%');
                            $status.html('<span style="color: green;">✓ エクスポート完了！ファイル一覧をご確認ください。</span>');
                            $button.prop('disabled', false).text('📥 CSVファイルをダウンロード');

                            // ファイル一覧を更新
                            loadExportFiles();

                            // 3秒後にプログレスバーを非表示
                            setTimeout(function() {
                                $('#kssl-export-progress').fadeOut();
                            }, 3000);
                        } else if (status.status === 'error') {
                            // エラー
                            clearInterval(exportPollInterval);
                            exportInProgress = false;
                            $progressBar.css('width', '100%').css('background', '#dc3232');
                            $status.html('<span style="color: red;">✗ エクスポートエラー: ' + status.message + '</span>');
                            $button.prop('disabled', false).text('📥 CSVファイルをダウンロード');
                        }
                    }
                },
                error: function() {
                    clearInterval(exportPollInterval);
                    exportInProgress = false;
                    $status.html('<span style="color: red;">ステータス確認エラー（通信エラー）</span>');
                    $button.prop('disabled', false).text('📥 CSVファイルをダウンロード');
                }
            });
        }, 2000); // 2秒ごとにポーリング
    }

    // エクスポートファイル一覧を読み込み
    function loadExportFiles() {
        var $filesList = $('#kssl-export-files-list');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_get_export_files',
                nonce: kssl_ajax.csv_nonce
            },
            success: function(response) {
                if (response.success) {
                    var files = response.data.files;

                    if (files.length === 0) {
                        $filesList.html('<p style="color: #999; font-style: italic;">エクスポートされたファイルはありません。</p>');
                    } else {
                        var html = '<table class="widefat" style="margin-top: 10px;"><thead><tr><th>ファイル名</th><th>サイズ</th><th>作成日時</th><th>アクション</th></tr></thead><tbody>';

                        files.forEach(function(file) {
                            html += '<tr>';
                            html += '<td><strong>' + file.name + '</strong></td>';
                            html += '<td>' + file.size_formatted + '</td>';
                            html += '<td>' + file.date + '</td>';
                            html += '<td>';
                            html += '<a href="' + file.download_url + '" class="button button-small button-primary" style="margin-right: 5px;">📥 ダウンロード</a>';
                            html += '<button class="button button-small kssl-delete-export-file" data-filename="' + file.name + '">🗑️  削除</button>';
                            html += '</td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                        $filesList.html(html);
                    }
                } else {
                    $filesList.html('<p style="color: red;">ファイル一覧の読み込みに失敗しました。</p>');
                }
            },
            error: function() {
                $filesList.html('<p style="color: red;">ファイル一覧の読み込みに失敗しました（通信エラー）。</p>');
            }
        });
    }

    // エクスポートファイル削除
    $(document).on('click', '.kssl-delete-export-file', function() {
        if (!confirm('このファイルを削除してもよろしいですか？')) {
            return;
        }

        var $button = $(this);
        var filename = $button.data('filename');

        $button.prop('disabled', true).html('<span class="kssl-spinner"></span> 削除中<span class="kssl-dots"></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_delete_export',
                nonce: kssl_ajax.csv_nonce,
                filename: filename
            },
            success: function(response) {
                if (response.success) {
                    // ファイル一覧を再読み込み
                    loadExportFiles();
                } else {
                    alert('削除に失敗しました: ' + (response.data.message || 'Unknown error'));
                    $button.prop('disabled', false).text('🗑️ 削除');
                }
            },
            error: function() {
                alert('削除に失敗しました（通信エラー）');
                $button.prop('disabled', false).text('🗑️ 削除');
            }
        });
    });

    // ページ読み込み時にエクスポートファイル一覧を読み込み
    if ($('#kssl-export-files-list').length > 0) {
        loadExportFiles();
    }

    // CSVインポートファイル選択時
    $('#kssl-import-file').on('change', function() {
        if ($(this).val()) {
            $('#kssl-import-csv-btn').prop('disabled', false);
        } else {
            $('#kssl-import-csv-btn').prop('disabled', true);
        }
    });

    // 既存ファイル一覧を読み込む関数（グローバルスコープで定義）
    window.loadExistingFiles = function() {
        var $select = $('#kssl-import-existing');
        $select.html('<option value="">読み込み中...</option>').prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_get_export_files',
                nonce: kssl_ajax.csv_nonce
            },
            success: function(response) {
                if (response.success && response.data.files) {
                    var options = '<option value="">-- ファイルを選択 --</option>';
                    response.data.files.forEach(function(file) {
                        var sizeInMB = (file.size / 1024 / 1024).toFixed(2);
                        var optionText = file.name + ' (' + sizeInMB + ' MB)';
                        options += '<option value="' + file.path + '" data-size="' + sizeInMB + '">' + optionText + '</option>';
                    });
                    $select.html(options).prop('disabled', false);

                    // ファイル選択時にサイズ情報を表示
                    $select.off('change.filesize').on('change.filesize', function() {
                        var selectedOption = $(this).find('option:selected');
                        var size = selectedOption.attr('data-size');
                        if (size) {
                            $('#kssl-file-size-info').html(
                                '✅ 選択されたファイル: <strong>' + size + ' MB</strong> - サイズ制限なしでインポート可能'
                            ).show();
                        } else {
                            $('#kssl-file-size-info').hide();
                        }
                    });
                } else {
                    $select.html('<option value="">エクスポートファイルがありません</option>').prop('disabled', false);
                    $('#kssl-file-size-info').hide();
                }
            },
            error: function() {
                $select.html('<option value="">読み込みエラー</option>').prop('disabled', false);
            }
        });
    };

    // インポートボタンの有効/無効を更新（グローバルスコープで定義）
    window.updateImportButton = function() {
        var importMethod = $('input[name="import_method"]:checked').val();
        var canImport = false;

        if (importMethod === 'upload') {
            var fileInput = $('#kssl-import-file')[0];
            canImport = fileInput && fileInput.files && fileInput.files[0];
        } else if (importMethod === 'existing') {
            canImport = $('#kssl-import-existing').val() !== '';
        }

        $('#kssl-import-csv-btn').prop('disabled', !canImport);
    };

    // インポート方法のラジオボタンが存在する場合のみ実行
    if ($('input[name="import_method"]').length > 0) {
        // デフォルトで「既存」が選択されている場合
        if ($('input[name="import_method"]:checked').val() === 'existing') {
            // 既存ファイル一覧を読み込む
            loadExistingFiles();
            $('#existing-method').css('display', 'block');
            $('#upload-method').css('display', 'none');
        } else if ($('input[name="import_method"]:checked').val() === 'upload') {
            $('#existing-method').css('display', 'none');
            $('#upload-method').css('display', 'block');
        }

        // インポート方法の切り替え処理
        $('input[name="import_method"]').on('change', function() {
            if ($(this).val() === 'upload') {
                $('#upload-method').css('display', 'block');
                $('#existing-method').css('display', 'none');
            } else {
                $('#upload-method').css('display', 'none');
                $('#existing-method').css('display', 'block');
                // 既存ファイル一覧を読み込む
                loadExistingFiles();
            }
            updateImportButton();
        });

        // 既存ファイル一覧を更新
        $('#kssl-refresh-files').on('click', function() {
            loadExistingFiles();
        });

        // 既存ファイル選択時
        $('#kssl-import-existing').on('change', function() {
            updateImportButton();
        });

        // ファイル変更時の処理（アップロード）
        $('#kssl-import-file').on('change', function() {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                // PHPから渡された実際のアップロード制限を使用
                var maxSizeBytes = kssl_ajax.upload_max_bytes || (2 * 1024 * 1024); // デフォルト2MB
                var maxSizeMB = (maxSizeBytes / 1024 / 1024).toFixed(1);
                var fileSizeMB = (file.size / 1024 / 1024).toFixed(2);

                if (file.size > maxSizeBytes) {
                    // ファイルサイズが制限を超えている
                    $('#kssl-upload-warning').show();
                    $('#kssl-file-size-error').text(
                        'ファイルサイズ: ' + fileSizeMB + ' MB / 制限: ' + maxSizeMB + ' MB'
                    );
                    $('#kssl-import-csv-btn').prop('disabled', true);

                    // 「既存のエクスポートファイルから選択」に自動切り替えを提案
                    if (confirm('ファイルサイズが制限（' + maxSizeMB + ' MB）を超えています。\n\n' +
                               'ファイル: ' + file.name + ' (' + fileSizeMB + ' MB)\n\n' +
                               '「既存のエクスポートファイルから選択」に切り替えますか？')) {
                        $('input[name="import_method"][value="existing"]').prop('checked', true).trigger('change');
                        this.value = ''; // ファイル選択をクリア
                    }
                } else {
                    $('#kssl-upload-warning').hide();
                    updateImportButton();
                }
            } else {
                $('#kssl-upload-warning').hide();
                updateImportButton();
            }
        });

        // 初期状態でインポートボタンを更新
        updateImportButton();
    }

    // インポート進捗ポーリング関数
    function pollImportStatus(jobId, $button, $progress, $progressBar, $status, $resultDiv, fileInput) {
        var pollInterval = setInterval(function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_check_import_status',
                    nonce: kssl_ajax.csv_nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var data = response.data;

                        // Keep progress bar visible
                        $progress.show();

                        // 進捗バーを更新
                        if (data.progress !== undefined) {
                            $progressBar.css('width', data.progress + '%');
                        }

                        // ステータスを更新
                        if (data.message) {
                            var statusHtml = '<span class="kssl-spinner"></span> <strong>バックグラウンド処理実行中:</strong> ' + data.message + '<span class="kssl-dots"></span>';

                            // 詳細な進捗情報を表示
                            if (data.total_lines && data.processed_lines !== undefined) {
                                statusHtml += '<br><small style="color: #666;">進捗: ' + data.processed_lines + ' / ' + data.total_lines + ' 行';
                                if (data.imported_count !== undefined) {
                                    statusHtml += ' (インポート: ' + data.imported_count + '件';
                                    if (data.skipped_count > 0) {
                                        statusHtml += ', スキップ: ' + data.skipped_count + '件';
                                    }
                                    statusHtml += ')';
                                }
                                statusHtml += '</small>';
                            }

                            $status.html(statusHtml);
                        }

                        // 完了またはエラーの場合
                        if (data.status === 'completed') {
                            clearInterval(pollInterval);
                            $progressBar.css('width', '100%');
                            $status.html('<span style="color: green;">✓ インポート完了</span>');

                            var resultMessage = data.message || 'インポートが完了しました';
                            if (data.imported_count !== undefined) {
                                resultMessage += '<br><br>インポート件数: ' + data.imported_count + '件';
                                if (data.skipped_count > 0) {
                                    resultMessage += '<br>スキップ: ' + data.skipped_count + '件';
                                }
                                if (data.error_count > 0) {
                                    resultMessage += '<br>エラー: ' + data.error_count + '件';
                                }
                            }
                            resultMessage += '<br><br>ページを更新します...';

                            $resultDiv.html('<div style="color: green; border: 1px solid #0073aa; background: #e6f3ff; padding: 10px; border-radius: 4px; white-space: pre-line;"><strong>' + resultMessage + '</strong></div>').show();

                            // フォームをリセット
                            if (fileInput) {
                                fileInput.value = '';
                            }

                            $button.prop('disabled', false).text('📤 CSVをインポート');

                            // ページをリロード
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else if (data.status === 'error') {
                            clearInterval(pollInterval);
                            $progressBar.css('width', '100%').css('background', '#dc3232');
                            $status.html('<span style="color: red;">✗ インポート失敗</span>');
                            $resultDiv.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px; white-space: pre-line;"><strong>' + (data.message || 'インポートに失敗しました') + '</strong></div>').show();
                            $button.prop('disabled', false).text('📤 CSVをインポート');
                        }
                    } else {
                        // Keep progress visible even if status not found yet
                        $progress.show();
                        $status.html('<span class="kssl-spinner"></span> <strong>バックグラウンド処理を待機中...</strong><span class="kssl-dots"></span>');
                    }
                },
                error: function() {
                    // エラーが発生してもポーリングは継続（一時的なエラーの可能性）
                    $progress.show();
                }
            });
        }, 1000); // 1秒ごとにポーリング

        // 最大10分でタイムアウト
        setTimeout(function() {
            clearInterval(pollInterval);
            if ($button.prop('disabled')) {
                $status.html('<span style="color: orange;">⚠ タイムアウト</span>');
                $resultDiv.html('<div style="color: #856404; background-color: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px;">処理がタイムアウトしました。ページを更新して結果を確認してください。</div>').show();
                $button.prop('disabled', false).text('📤 CSVをインポート');
            }
        }, 600000); // 10分
    }

    // CSVインポートボタン
    $('#kssl-import-csv-btn').on('click', function() {
        var importMethod = $('input[name="import_method"]:checked').val();

        if (importMethod === 'existing') {
            // 既存ファイルからインポート
            var selectedFile = $('#kssl-import-existing').val();
            if (!selectedFile) {
                alert('インポートするファイルを選択してください。');
                return;
            }

            var $button = $(this);
            var $progress = $('#kssl-import-progress');
            var $progressBar = $('#kssl-import-progress-bar');
            var $status = $('#kssl-import-status');
            var $resultDiv = $('#kssl-csv-result');

            $button.prop('disabled', true).html('<span class="kssl-spinner"></span> インポート中<span class="kssl-dots"></span>');
            $progress.show();
            $progressBar.css('width', '0%');
            $status.html('<span class="kssl-spinner"></span> インポートを開始しています<span class="kssl-dots"></span>');
            $resultDiv.hide();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'kssl_csv_import_existing',
                    nonce: kssl_ajax.csv_nonce,
                    file_path: selectedFile,
                    skip_duplicates: $('input[name="skip_duplicates"]').is(':checked') ? '1' : '0',
                    validate_data: $('input[name="validate_data"]').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    if (response.success && response.data.job_id) {
                        // ジョブIDを受け取ったので、ポーリング開始
                        $status.html('<span class="kssl-spinner"></span> <strong>バックグラウンド処理を開始しました</strong> - インポート処理中<span class="kssl-dots"></span>');
                        pollImportStatus(response.data.job_id, $button, $progress, $progressBar, $status, $resultDiv, null);
                    } else {
                        // エラー
                        $status.html('<span style="color: red;">✗ インポート失敗</span>');
                        $resultDiv.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px; white-space: pre-line;"><strong>' + (response.data ? response.data.message : 'インポート開始に失敗しました') + '</strong></div>').show();
                        $button.prop('disabled', false).text('📤 CSVをインポート');
                    }
                },
                error: function(xhr, status, error) {
                    $status.html('<span style="color: red;">✗ 接続エラー</span>');
                    $resultDiv.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;">サーバーに接続できませんでした。<br>エラー: ' + (error || status || '不明なエラー') + '</div>').show();
                    $button.prop('disabled', false).text('📤 CSVをインポート');
                }
            });

            return;
        } else if (importMethod === 'upload') {
            // 通常のファイルアップロード処理
            var fileInput = $('#kssl-import-file')[0];
            if (!fileInput.files || !fileInput.files[0]) {
                alert('CSVファイルを選択してください。');
                return;
            }

            var $button = $(this);
            var $progress = $('#kssl-import-progress');
            var $progressBar = $('#kssl-import-progress-bar');
            var $status = $('#kssl-import-status');
            var $resultDiv = $('#kssl-csv-result');

            var formData = new FormData();
            formData.append('action', 'kssl_csv_import');
            formData.append('nonce', kssl_ajax.csv_nonce);
            formData.append('csv_file', fileInput.files[0]);
            formData.append('skip_duplicates', $('input[name="skip_duplicates"]').is(':checked') ? '1' : '0');
            formData.append('validate_data', $('input[name="validate_data"]').is(':checked') ? '1' : '0');

            $button.prop('disabled', true).html('<span class="kssl-spinner"></span> インポート中<span class="kssl-dots"></span>');
            $progress.show();
            $progressBar.css('width', '0%');
            $status.html('<span class="kssl-spinner"></span> ファイルをアップロード中<span class="kssl-dots"></span>');
            $resultDiv.hide();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success && response.data.job_id) {
                        // ジョブIDを受け取ったので、ポーリング開始
                        $status.html('<span class="kssl-spinner"></span> <strong>バックグラウンド処理を開始しました</strong> - インポート処理中<span class="kssl-dots"></span>');
                        pollImportStatus(response.data.job_id, $button, $progress, $progressBar, $status, $resultDiv, fileInput);
                    } else {
                        // エラー
                        console.error('[Import Upload] Error - no job_id in response:', response);
                        $status.html('<span style="color: red;">✗ インポート失敗</span>');
                        $resultDiv.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px; white-space: pre-line;"><strong>' + (response.data ? response.data.message : 'インポート開始に失敗しました') + '</strong></div>').show();
                        $button.prop('disabled', false).text('📤 CSVをインポート');
                    }
                },
                error: function(xhr, status, error) {
                    $status.html('<span style="color: red;">✗ 接続エラー</span>');
                    $resultDiv.html('<div style="color: red; border: 1px solid #d63638; background: #ffebee; padding: 10px; border-radius: 4px;">サーバーに接続できませんでした。<br>エラー: ' + (error || status || '不明なエラー') + '</div>').show();
                    $button.prop('disabled', false).text('📤 CSVをインポート');
                }
            });
        }
    });

    // サンプルCSVダウンロードボタン
    $('#kssl-download-sample-btn').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).html('<span class="kssl-spinner"></span> ダウンロード中<span class="kssl-dots"></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_csv_sample',
                nonce: kssl_ajax.csv_nonce
            },
            success: function(response) {
                if (response.success && response.data.csv_content) {
                    // BlobとしてCSVをダウンロード
                    var blob = new Blob([response.data.csv_content], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = 'kssl_sample.csv';
                    link.click();
                    URL.revokeObjectURL(link.href);
                } else {
                    alert('サンプルCSVのダウンロードに失敗しました。');
                }
            },
            error: function() {
                alert('サンプルCSVのダウンロードに失敗しました（通信エラー）。');
            },
            complete: function() {
                $button.prop('disabled', false).text('📄 サンプルCSVをダウンロード');
            }
        });
    });

    // ========== 設定トグル ==========

    // 自動最適化のオン/オフ
    $('#kssl-auto-optimize-toggle').on('change', function() {
        var $checkbox = $(this);
        var enabled = $checkbox.is(':checked');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_toggle_auto_optimization',
                nonce: kssl_ajax.toggle_nonce,
                enabled: enabled
            },
            success: function(response) {
                if (response.success) {
                    // ページをリロードして変更を反映
                    location.reload();
                } else {
                    alert('設定の変更に失敗しました: ' + (response.data.message || 'Unknown error'));
                    // 元の状態に戻す
                    $checkbox.prop('checked', !enabled);
                }
            },
            error: function() {
                alert('設定の変更に失敗しました（通信エラー）');
                // 元の状態に戻す
                $checkbox.prop('checked', !enabled);
            }
        });
    });

    // 期限切れキャッシュ自動削除のオン/オフ
    $('#kssl-auto-clear-cache-toggle').on('change', function() {
        var $checkbox = $(this);
        var enabled = $checkbox.is(':checked');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'kssl_toggle_auto_clear_cache',
                nonce: kssl_ajax.toggle_nonce,
                enabled: enabled
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                } else {
                    alert('設定の変更に失敗しました: ' + (response.data.message || 'Unknown error'));
                    // 元の状態に戻す
                    $checkbox.prop('checked', !enabled);
                }
            },
            error: function() {
                alert('設定の変更に失敗しました（通信エラー）');
                // 元の状態に戻す
                $checkbox.prop('checked', !enabled);
            }
        });
    });

});