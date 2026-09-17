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

        // 9. 复制服务器 URL
        $(document).on('click', '.copy-server-url-btn', function () {
            var targetSelector = $(this).data('target');
            var url = $(targetSelector).val();
            var $btn = $(this);
            if (!url) return;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    var origText = $btn.text();
                    $btn.text('已复制！');
                    setTimeout(function () { $btn.text(origText); }, 2000);
                });
            } else {
                $(targetSelector).select();
                document.execCommand('copy');
                var origText = $btn.text();
                $btn.text('已复制！');
                setTimeout(function () { $btn.text(origText); }, 2000);
            }
        });

        // 10. 随机生成安全 Token (16-24位英文字符串)
        $('#btn-generate-token').on('click', function () {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            var token = '';
            for (var i = 0; i < 18; i++) {
                token += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            $('#wechat_server_token').val(token);
        });

        // 11. 关键词回复形式切换
        $(document).on('change', '.rule-reply-type', function () {
            var $select = $(this);
            var $row = $select.closest('tr');
            var $customBox = $row.find('.custom-text-box');
            if ($select.val() === 'custom_text') {
                $customBox.slideDown(150);
            } else {
                $customBox.slideUp(150);
            }
        });

        // 12. 快速填入最近文章 ID
        $(document).on('click', '.quick-fill-post-id', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            var $lastPostInput = $('#keyword-rules-tbody tr:last-child .post-id-input');
            if ($lastPostInput.length) {
                $lastPostInput.val(id).trigger('change');
            }
        });

        // 13. 添加新关键词回复规则
        $('#btn-add-keyword-rule').on('click', function () {
            var nextIndex = $('#keyword-rules-tbody tr').length;
            var html = '<tr class="rule-row" data-index="' + nextIndex + '">' +
                '<td>' +
                    '<input type="text" name="wp_wechat_sync_options[keyword_rules][' + nextIndex + '][keywords]" value="" class="large-text" placeholder="例：codex,额度,重置" required>' +
                    '<input type="text" name="wp_wechat_sync_options[keyword_rules][' + nextIndex + '][prefix]" value="" class="large-text" placeholder="可选前缀，如【实时数据】" style="margin-top:4px;font-size:12px;">' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="wp_wechat_sync_options[keyword_rules][' + nextIndex + '][post_id]" value="" class="small-text post-id-input" placeholder="文章ID">' +
                    '<div class="post-title-preview" style="font-size:12px;color:#2271b1;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">输入ID自动关联文章</div>' +
                '</td>' +
                '<td>' +
                    '<select name="wp_wechat_sync_options[keyword_rules][' + nextIndex + '][reply_type]" class="rule-reply-type" style="width:100%;">' +
                        '<option value="text_link">【推荐】文本 + 网页直达链接</option>' +
                        '<option value="news">微信单图文卡片 (含封面/可转发)</option>' +
                        '<option value="text_only">纯文本 (仅提取正文并保留换行)</option>' +
                        '<option value="custom_text">固定文本 (无需绑定文章)</option>' +
                    '</select>' +
                    '<div class="custom-text-box" style="display:none;margin-top:4px;">' +
                        '<textarea name="wp_wechat_sync_options[keyword_rules][' + nextIndex + '][custom_text]" rows="2" class="large-text" placeholder="输入固定回复内容"></textarea>' +
                    '</div>' +
                '</td>' +
                '<td>' +
                    '<button type="button" class="button button-link-delete btn-remove-rule" style="color:#d63638;cursor:pointer;padding-top:6px;">删除</button>' +
                '</td>' +
            '</tr>';

            $('#keyword-rules-tbody').append(html);
        });

        // 14. 删除关键词规则行
        $(document).on('click', '.btn-remove-rule', function () {
            var $tbody = $('#keyword-rules-tbody');
            if ($tbody.find('tr').length <= 1) {
                // 如果只剩一行，清空其内容而非删除
                $tbody.find('tr input, tr textarea').val('');
                $tbody.find('.post-title-preview').text('输入ID自动关联文章');
                return;
            }
            $(this).closest('tr').fadeOut(150, function () {
                $(this).remove();
            });
        });
    });
})(jQuery);
