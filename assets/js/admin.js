/**
 * WP WeChat Sync - 后台交互脚本
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // 1. AppSecret 显示/隐藏切换
        $('.toggle-secret-btn').on('click', function () {
            var $input = $('#wechat_appsecret');
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $(this).text('隐藏');
            } else {
                $input.attr('type', 'password');
                $(this).text('显示');
            }
        });

        // 2. 媒体库封面图片选择器
        $('.upload-media-btn').on('click', function (e) {
            e.preventDefault();
            var targetSelector = $(this).data('target');
            var customUploader = wp.media({
                title: '选择全局默认封面图片',
                button: {
                    text: '使用此图片'
                },
                multiple: false
            });

            customUploader.on('select', function () {
                var attachment = customUploader.state().get('selection').first().toJSON();
                $(targetSelector).val(attachment.url);
            });

            customUploader.open();
        });

        // 3. 复制服务器出口 IP
        $('.copy-ip-btn').on('click', function () {
            var ip = $('#server-egress-ip').text().trim();
            var $btn = $(this);
            if (!ip) return;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ip).then(function () {
                    var originalText = $btn.text();
                    $btn.text('已复制！');
                    setTimeout(function () {
                        $btn.text(originalText);
                    }, 2000);
                });
            } else {
                // 兼容回退方案
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(ip).select();
                document.execCommand('copy');
                $temp.remove();
                var originalText = $btn.text();
                $btn.text('已复制！');
                setTimeout(function () {
                    $btn.text(originalText);
                }, 2000);
            }
        });

        // 4. 刷新公网出口 IP
        $('.refresh-ip-btn').on('click', function () {
            var $btn = $(this);
            var $ipDisplay = $('#server-egress-ip');
            $btn.prop('disabled', true).text('检测中...');

            $.post(wpWeChatSync.ajax_url, {
                action: 'wp_wechat_refresh_ip',
                nonce: wpWeChatSync.nonce
            }, function (response) {
                $btn.prop('disabled', false).text('刷新');
                if (response.success && response.data.ip) {
                    $ipDisplay.text(response.data.ip);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('刷新');
            });
        });

        // 5. 测试微信 API 连接
        $('#btn-test-connection').on('click', function () {
            var $btn = $(this);
            var $status = $('#test-connection-status');
            var appid = $('#wechat_appid').val().trim();
            var appsecret = $('#wechat_appsecret').val().trim();

            if (!appid || !appsecret) {
                $status.attr('class', 'test-status is-error').text('请先填写 AppID 和 AppSecret。');
                return;
            }

            $btn.prop('disabled', true).addClass('is-loading');
            $status.attr('class', 'test-status is-loading').text('正在连接微信公众平台测试...');

            $.post(wpWeChatSync.ajax_url, {
                action: 'wp_wechat_test_connection',
                nonce: wpWeChatSync.nonce,
                appid: appid,
                appsecret: appsecret
            }, function (response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                if (response.success) {
                    $status.attr('class', 'test-status is-success').text(response.data.message);
                } else {
                    $status.attr('class', 'test-status is-error').text(response.data.message);
                }
            }).fail(function (xhr) {
                $btn.prop('disabled', false).removeClass('is-loading');
                $status.attr('class', 'test-status is-error').text('网络请求失败：HTTP ' + xhr.status);
            });
        });

        // 6. 清空同步日志
        $('#btn-clear-logs').on('click', function () {
            if (!confirm('确定要清空所有微信同步日志吗？该操作不可逆。')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(wpWeChatSync.ajax_url, {
                action: 'wp_wechat_clear_logs',
                nonce: wpWeChatSync.nonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $('#logs-table-body').html('<tr><td colspan="5" style="text-align:center;color:#999;padding:25px;">暂无同步记录</td></tr>');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
            });
        });

        // 7. 文章编辑页 Meta Box 手动一键同步
        $('#btn-manual-sync').on('click', function () {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $feedback = $('#manual-sync-feedback');
            var $btnText = $btn.find('.btn-text');
            var $badge = $('#wechat-sync-badge');
            var $details = $('#wechat-sync-meta-details');

            if (!postId) return;

            $btn.prop('disabled', true).addClass('is-loading');
            $btnText.text('正在同步微信草稿...');
            $feedback.hide().removeClass('is-success is-error');

            $.post(wpWeChatSync.ajax_url, {
                action: 'wp_wechat_manual_sync',
                nonce: wpWeChatSync.nonce,
                post_id: postId
            }, function (response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                $btnText.text('立即同步到公众号');

                if (response.success) {
                    $feedback.addClass('is-success').text(response.data.message).show();
                    $badge.html('<span class="metabox-badge badge-success">' + (response.data.published ? '已提交发布' : '已同步到草稿箱') + '</span>');

                    if ($('#wechat-sync-time').length) {
                        $('#wechat-sync-time').text(response.data.time);
                    }
                    if ($('#wechat-sync-media-id').length) {
                        var shortId = response.data.media_id.length > 16 ? response.data.media_id.substring(0, 16) + '...' : response.data.media_id;
                        $('#wechat-sync-media-id').text(shortId).attr('title', response.data.media_id);
                    }
                    $('#wechat-sync-error-notice').remove();
                    $details.show();
                } else {
                    $feedback.addClass('is-error').text('同步失败：' + response.data.message).show();
                    $badge.html('<span class="metabox-badge badge-danger">同步失败</span>');
                }
            }).fail(function (xhr) {
                $btn.prop('disabled', false).removeClass('is-loading');
                $btnText.text('立即同步到公众号');
                $feedback.addClass('is-error').text('网络请求失败：HTTP ' + xhr.status).show();
            });
        });

        // 8. 文章列表页快捷同步
        $(document).on('click', '.btn-list-quick-sync', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $cell = $btn.closest('.wechat-list-cell');

            if (!postId || $btn.prop('disabled')) return;

            $btn.prop('disabled', true);
            var origHtml = $cell.html();
            $cell.html('<span class="is-syncing"><span class="dashicons dashicons-update"></span> 同步中...</span>');

            $.post(wpWeChatSync.ajax_url, {
                action: 'wp_wechat_manual_sync',
                nonce: wpWeChatSync.nonce,
                post_id: postId
            }, function (response) {
                if (response.success) {
                    $cell.html(
                        '<span class="metabox-badge badge-success" title="草稿 ID: ' + response.data.media_id + '">已同步</span> ' +
                        '<button type="button" class="button button-small btn-list-quick-sync" data-post-id="' + postId + '">重新同步</button>'
                    );
                } else {
                    alert('微信同步失败：\n' + response.data.message);
                    $cell.html(
                        '<span class="metabox-badge badge-danger" title="' + (response.data ? response.data.message : '') + '">失败</span> ' +
                        '<button type="button" class="button button-small btn-list-quick-sync" data-post-id="' + postId + '">重试</button>'
                    );
                }
            }).fail(function (xhr) {
                alert('网络异常，无法同步：HTTP ' + xhr.status);
                $cell.html(origHtml);
            });
        });
    });
})(jQuery);
