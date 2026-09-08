<?php
/**
 * 文章同步逻辑与内容处理核心类
 *
 * @package WP_WeChat_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WeChat_Post_Sync {

    /**
     * 初始化钩子
     */
    public static function init() {
        // 当文章状态发生变化（发布新文章或更新已发布文章）时触发自动同步
        add_action( 'transition_post_status', array( __CLASS__, 'handle_post_transition' ), 20, 3 );
    }

    /**
     * 监听文章发布状态变更
     *
     * @param string  $new_status 新状态
     * @param string  $old_status 旧状态
     * @param WP_Post $post       文章对象
     */
    public static function handle_post_transition( $new_status, $old_status, $post ) {
        if ( ! $post || ! ( $post instanceof WP_Post ) ) {
            return;
        }

        // 仅在最终状态为 publish 时处理
        if ( 'publish' !== $new_status ) {
            return;
        }

        // 排除自动保存和修订版本
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post->ID ) ) {
            return;
        }

        // 检查允许同步的文章类型
        $options      = get_option( 'wp_wechat_sync_options', array() );
        $post_types   = isset( $options['post_types'] ) && is_array( $options['post_types'] ) ? $options['post_types'] : array( 'post' );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        // 检查全局自动同步开关
        $auto_sync = isset( $options['auto_sync'] ) ? (int) $options['auto_sync'] : 0;
        if ( ! $auto_sync ) {
            return;
        }

        // 检查当前文章是否单独禁用了自动同步
        $disable_auto = get_post_meta( $post->ID, '_wechat_sync_disable_auto', true );
        if ( $disable_auto ) {
            return;
        }

        // 如果旧状态已经是 publish 且已经成功同步过，避免编辑小错误时重复创建草稿
        if ( 'publish' === $old_status ) {
            $already_synced = get_post_meta( $post->ID, '_wechat_sync_media_id', true );
            $sync_on_update = isset( $options['sync_on_update'] ) ? (int) $options['sync_on_update'] : 0;
            if ( $already_synced && ! $sync_on_update ) {
                return;
            }
        }

        // 执行同步
        self::sync_post( $post->ID, false );
    }

    /**
     * 执行文章同步到微信公众号
     *
     * @param int  $post_id   文章 ID
     * @param bool $is_manual 是否为手动触发
     * @return array|WP_Error
     */
    public static function sync_post( $post_id, $is_manual = false ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'invalid_post', '未找到指定的文章。' );
        }

        $options = get_option( 'wp_wechat_sync_options', array() );
        $type    = $is_manual ? 'manual' : 'auto';

        // 1. 获取并处理文章标题
        $title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
        if ( empty( $title ) ) {
            $title = '无标题文章';
        }

        // 2. 获取并处理作者名称
        $author = get_post_meta( $post_id, '_wechat_custom_author', true );
        if ( empty( $author ) ) {
            if ( ! empty( $options['default_author'] ) ) {
                $author = $options['default_author'];
            } else {
                $author = get_the_author_meta( 'display_name', $post->post_author );
            }
        }
        $author = mb_substr( trim( $author ), 0, 8, 'UTF-8' ); // 微信限制作者名字长度不超过 8 个字

        // 3. 准备封面图 thumb_media_id
        $thumb_media_id = self::resolve_thumb_media_id( $post_id, $post->post_content, $options );
        if ( is_wp_error( $thumb_media_id ) ) {
            $err_msg = '【封面图错误】' . $thumb_media_id->get_error_message();
            update_post_meta( $post_id, '_wechat_sync_status', 'failed' );
            update_post_meta( $post_id, '_wechat_sync_error', $err_msg );
            WP_WeChat_Sync_Logger::log( $post_id, $title, $type, 'error', $err_msg );
            return new WP_Error( 'cover_error', $err_msg );
        }

        // 4. 处理文章正文内容与图片
        $processed_content = self::process_content( $post, $options );
        if ( is_wp_error( $processed_content ) ) {
            $err_msg = '【正文处理错误】' . $processed_content->get_error_message();
            update_post_meta( $post_id, '_wechat_sync_status', 'failed' );
            update_post_meta( $post_id, '_wechat_sync_error', $err_msg );
            WP_WeChat_Sync_Logger::log( $post_id, $title, $type, 'error', $err_msg );
            return $processed_content;
        }

        // 5. 准备摘要 digest
        $digest = self::resolve_digest( $post, $options );

        // 6. 原文链接 content_source_url
        $content_source_url = '';
        if ( ! empty( $options['add_source_url'] ) ) {
            $content_source_url = get_permalink( $post_id );
        }

        // 7. 组装文章数据并调用微信草稿箱接口
        $article = array(
            'title'                 => $title,
            'author'                => $author,
            'digest'                => $digest,
            'content'               => $processed_content,
            'content_source_url'    => $content_source_url,
            'thumb_media_id'        => $thumb_media_id,
            'need_open_comment'     => ! empty( $options['open_comment'] ) ? 1 : 0,
            'only_fans_can_comment' => ! empty( $options['only_fans_comment'] ) ? 1 : 0,
        );

        $media_id = WP_WeChat_API::add_draft( $article );

        if ( is_wp_error( $media_id ) ) {
            $err_msg = $media_id->get_error_message();
            update_post_meta( $post_id, '_wechat_sync_status', 'failed' );
            update_post_meta( $post_id, '_wechat_sync_error', $err_msg );
            WP_WeChat_Sync_Logger::log( $post_id, $title, $type, 'error', '同步到微信草稿箱失败：' . $err_msg, $media_id->get_error_data() );
            return $media_id;
        }

        // 记录同步元数据
        $sync_time = current_time( 'Y-m-d H:i:s' );
        update_post_meta( $post_id, '_wechat_sync_status', 'synced' );
        update_post_meta( $post_id, '_wechat_sync_media_id', $media_id );
        update_post_meta( $post_id, '_wechat_sync_time', $sync_time );
        delete_post_meta( $post_id, '_wechat_sync_error' );

        $success_msg = sprintf( '成功同步到微信公众号草稿箱 (草稿 Media ID: %s)', $media_id );

        // 8. 可选：如果开启了直接公开发布 (FreePublish)
        $published_data = null;
        if ( ! empty( $options['sync_action'] ) && 'publish' === $options['sync_action'] ) {
            $pub_res = WP_WeChat_API::publish_draft( $media_id );
            if ( is_wp_error( $pub_res ) ) {
                $warn_msg = sprintf( '草稿已创建，但直接发布失败：%s', $pub_res->get_error_message() );
                WP_WeChat_Sync_Logger::log( $post_id, $title, $type, 'warning', $warn_msg );
                return array(
                    'success'   => true,
                    'media_id'  => $media_id,
                    'published' => false,
                    'message'   => $warn_msg,
                );
            } else {
                $published_data = $pub_res;
                update_post_meta( $post_id, '_wechat_publish_id', isset( $pub_res['publish_id'] ) ? $pub_res['publish_id'] : '' );
                $success_msg = sprintf( '成功同步并提交公开发布！(草稿 Media ID: %s)', $media_id );
            }
        }

        WP_WeChat_Sync_Logger::log( $post_id, $title, $type, 'success', $success_msg, array( 'media_id' => $media_id, 'publish' => $published_data ) );

        return array(
            'success'   => true,
            'media_id'  => $media_id,
            'time'      => $sync_time,
            'message'   => $success_msg,
        );
    }

    /**
     * 解析并获取封面素材的 thumb_media_id
     * 策略：文章特色图像 > 正文首图 > 全局默认封面
     *
     * @param int    $post_id 文章 ID
     * @param string $content 文章正文
     * @param array  $options 插件设置
     * @return string|WP_Error
     */
    public static function resolve_thumb_media_id( $post_id, $content, $options ) {
        // 先检查是否缓存过该文章封面的 thumb_media_id
        $cached_thumb = get_post_meta( $post_id, '_wechat_thumb_media_id', true );
        $cached_source = get_post_meta( $post_id, '_wechat_thumb_source', true );

        $target_image = '';

        // 1. 文章特色图像
        if ( has_post_thumbnail( $post_id ) ) {
            $thumbnail_id = get_post_thumbnail_id( $post_id );
            $target_image = get_attached_file( $thumbnail_id );
            if ( ! $target_image || ! file_exists( $target_image ) ) {
                $thumb_url = wp_get_attachment_image_url( $thumbnail_id, 'full' );
                if ( $thumb_url ) {
                    $target_image = $thumb_url;
                }
            }
        }

        // 2. 正文第一张图片
        if ( empty( $target_image ) && ! empty( $content ) ) {
            if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $matches ) ) {
                $target_image = $matches[1];
            }
        }

        // 3. 全局默认封面
        if ( empty( $target_image ) && ! empty( $options['default_cover_image'] ) ) {
            $target_image = $options['default_cover_image'];
        }

        if ( empty( $target_image ) ) {
            return new WP_Error(
                'no_cover_found',
                '未找到任何可用封面图。微信文章必须包含封面图，请设置文章“特色图像”、在正文中插入图片，或在插件后台设置全局默认封面。'
            );
        }

        // 如果素材没有变更且已有 thumb_media_id，可直接复用
        if ( ! empty( $cached_thumb ) && $cached_source === $target_image ) {
            return $cached_thumb;
        }

        // 上传封面图片到永久素材库
        $upload_res = WP_WeChat_API::upload_cover_image( $target_image );
        if ( is_wp_error( $upload_res ) ) {
            return $upload_res;
        }

        $media_id = $upload_res['media_id'];
        update_post_meta( $post_id, '_wechat_thumb_media_id', $media_id );
        update_post_meta( $post_id, '_wechat_thumb_source', $target_image );

        return $media_id;
    }

    /**
     * 处理文章正文内容：渲染区块与短代码、转存所有图片至微信 CDN 并替换链接
     *
     * @param WP_Post $post
     * @param array   $options
     * @return string|WP_Error
     */
    public static function process_content( $post, $options ) {
        $content = $post->post_content;

        // 渲染 Gutenberg 区块与短代码
        if ( function_exists( 'do_blocks' ) ) {
            $content = do_blocks( $content );
        }
        $content = apply_filters( 'the_content', $content );

        // 提取本地图片映射缓存（避免重复上传相同图片）
        $image_cdn_map = get_post_meta( $post->ID, '_wechat_image_cdn_map', true );
        if ( ! is_array( $image_cdn_map ) ) {
            $image_cdn_map = array();
        }

        // 正则提取所有 <img> 标签的 src
        if ( preg_match_all( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $matches ) ) {
            $srcs = array_unique( $matches[1] );
            foreach ( $srcs as $src ) {
                // 如果已经是微信 CDN 域名，跳过
                if ( false !== strpos( $src, 'qpic.cn' ) || false !== strpos( $src, 'weixin.qq.com' ) ) {
                    continue;
                }

                // 检查缓存
                if ( isset( $image_cdn_map[ $src ] ) ) {
                    $content = str_replace( $src, $image_cdn_map[ $src ], $content );
                    continue;
                }

                // 上传到微信 CDN
                $wechat_cdn_url = WP_WeChat_API::upload_content_image( $src );
                if ( is_wp_error( $wechat_cdn_url ) ) {
                    // 若图片上传微信 CDN 失败，从正文中移除该图片标签，避免残留未转存外链导致微信报错 45166 invalid content
                    WP_WeChat_Sync_Logger::log( $post->ID, $post->post_title, 'auto', 'warning', '正文中有图片上传至微信 CDN 失败，已自动过滤该图片标签以保证文章发布：' . $wechat_cdn_url->get_error_message() );
                    $content = preg_replace( '/<img\b[^>]+src=[\'"]' . preg_quote( $src, '/' ) . '[\'"][^>]*>/i', '', $content );
                    continue;
                }

                $image_cdn_map[ $src ] = $wechat_cdn_url;
                $content               = str_replace( $src, $wechat_cdn_url, $content );
            }
            update_post_meta( $post->ID, '_wechat_image_cdn_map', $image_cdn_map );
        }

        // 执行严格的微信内容清洗（清除外链、未清洗的 srcset/sizes 外部图片属性、Gutenberg 标记与非合规标签）
        $content = self::sanitize_wechat_content( $content );

        // 校验正文是否有效
        if ( empty( trim( strip_tags( $content ) ) ) && ! preg_match( '/<img\b/i', $content ) ) {
            return new WP_Error( 'empty_content', '文章正文处理后内容为空，微信公众号要求正文必须包含文字或图片。' );
        }

        // 对微信排版进行视觉增强（添加适当的行高、字体颜色与内联段落样式）
        $content = self::format_wechat_html( $content );

        // 是否追加底部原文声明与链接
        if ( ! empty( $options['append_source_notice'] ) ) {
            $site_name = get_bloginfo( 'name' );
            if ( empty( $site_name ) ) {
                $site_name = '本站';
            }

            $template = ! empty( $options['custom_source_notice'] )
                ? trim( $options['custom_source_notice'] )
                : '本文首发于 <strong>{site_name}</strong>，点击微信底部<strong>“阅读原文”</strong>可直接访问原网页参与讨论与查看更多内容。';

            $notice_body = str_replace(
                array( '{site_name}', '{site_url}' ),
                array( esc_html( $site_name ), esc_url( home_url() ) ),
                $template
            );

            $notice_html = sprintf(
                '<section style="margin-top: 30px; padding: 15px 14px; background: #f8f9fa; border-left: 4px solid #07c160; border-radius: 4px; font-size: 14px; color: #555555; line-height: 1.6;">
                    <p style="margin: 0;">%s</p>
                </section>',
                $notice_body
            );
            $content .= $notice_html;
        }

        return $content;
    }

    /**
     * 针对微信草稿箱严格的内容校验规则进行深度清洗与合规化
     * 解决微信 45166 (invalid content) 核心成因：
     * 1. 微信草稿箱严禁在正文中使用 <h1> 标签（强制降级替换为合规的 <h2> 标题）
     * 2. 全局剥除所有标签上的非法或交互属性（如 tabindex, dir, lang, id, aria-*, data-* 等）
     * 3. 个人订阅号不支持正文插入非微信外部超链接（自动转换为安全高亮文本）
     * 4. 彻底清理复制残留的剪贴板辅助容器与空白标签（如 GitHub zeroclipboard、空 div/p）
     * 5. 确保 <img> 标签均转存为微信官方 CDN 链接并剥除 srcset/sizes 等响应式外部属性
     * 6. 彻底清理 WordPress 区块标记 <!-- wp:... --> 与 HTML 注释
     * 7. 移除表单、脚本、嵌入框架等微信不支持的异常标签
     * 8. 清洗不可见 ASCII 控制字符
     *
     * @param string $content
     * @return string
     */
    public static function sanitize_wechat_content( $content ) {
        if ( empty( $content ) ) {
            return '';
        }

        // 1. 清理 HTML 注释（包括 Gutenberg 区块标记 <!-- wp:... -->）
        $content = preg_replace( '/<!--(.|\s)*?-->/', '', $content );

        // 2. 清理完全不支持的交互与脚本标签
        $unsupported = array(
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/<style\b[^>]*>(.*?)<\/style>/is',
            '/<iframe\b[^>]*>(.*?)<\/iframe>/is',
            '/<form\b[^>]*>(.*?)<\/form>/is',
            '/<svg\b[^>]*>(.*?)<\/svg>/is',
            '/<canvas\b[^>]*>(.*?)<\/canvas>/is',
            '/<video\b[^>]*>(.*?)<\/video>/is',
            '/<audio\b[^>]*>(.*?)<\/audio>/is',
            '/<input\b[^>]*>/is',
            '/<button\b[^>]*>(.*?)<\/button>/is',
            '/<select\b[^>]*>(.*?)<\/select>/is',
            '/<textarea\b[^>]*>(.*?)<\/textarea>/is',
        );
        $content = preg_replace( $unsupported, '', $content );

        // 3. 清理复制来源特有的空辅助元素与剪贴板容器 (如 GitHub zeroclipboard-container)
        $content = preg_replace( '/<div\b[^>]*class=[\'"][^\'"]*(?:zeroclipboard|clipboard)[^\'"]*[\'"][^>]*>\s*<\/div>/is', '', $content );

        // 4. 微信公众号正文严禁使用 <h1> 标签（草稿箱仅支持 <h2> 及以下作为小标题，<h1> 会直接触发 45166 invalid content）
        $content = preg_replace( '/<(\/?)h1\b([^>]*)>/i', '<$1h2$2>', $content );
        $content = preg_replace( '/<(\/?)h[56]\b([^>]*)>/i', '<$1h4$2>', $content );

        // 5. 转换 figure 与 figcaption
        $content = preg_replace( '/<\/?figure\b[^>]*>/i', '', $content );
        $content = preg_replace(
            '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is',
            '<p style="font-size: 13px; color: #888888; text-align: center; margin: 6px 0 16px 0;">$1</p>',
            $content
        );

        // 6. 解除图片外层的 <a> 链接包裹（WordPress 默认常给图片加指向附件或原图的外链）
        $content = preg_replace( '/<a\b[^>]*>(\s*<img\b[^>]*>\s*)<\/a>/is', '$1', $content );

        // 7. 深度清洗 <img> 标签属性（移除可能残留外部原图域名的 srcset、sizes 以及多余属性）
        $content = preg_replace_callback( '/<img\b([^>]*)>/i', function( $matches ) {
            $attrs = $matches[1];

            // 提取 src
            if ( ! preg_match( '/\bsrc=[\'"]([^\'"]+)[\'"]/i', $attrs, $src_m ) ) {
                return ''; // 没有 src 的空图片标签移除
            }
            $src = trim( $src_m[1] );

            // 微信草稿箱严格要求：正文所有图片必须是微信官方 CDN 链接（qpic.cn 或 weixin.qq.com）
            // 如果含有未成功转存的外链或本地图片，微信将直接报错 45166 invalid content
            if ( false === strpos( $src, 'qpic.cn' ) && false === strpos( $src, 'weixin.qq.com' ) ) {
                $cdn_url = WP_WeChat_API::upload_content_image( $src );
                if ( is_wp_error( $cdn_url ) || empty( $cdn_url ) ) {
                    return ''; // 无法转存至微信 CDN 的图片必须剔除，以确保整篇文章合规发布
                }
                $src = $cdn_url;
            }

            // 提取 alt
            $alt = '';
            if ( preg_match( '/\balt=[\'"]([^\'"]*)[\'"]/i', $attrs, $alt_m ) ) {
                $alt = esc_attr( trim( $alt_m[1] ) );
            }

            // 微信图文标准安全图片标签（杜绝 srcset、sizes 等残留外部域名的属性）
            return sprintf(
                '<img src="%s" alt="%s" style="max-width: 100%% !important; height: auto !important; display: block; margin: 16px auto; border-radius: 4px;">',
                esc_url( $src ),
                $alt
            );
        }, $content );

        // 8. 处理正文中的 <a> 超链接（个人订阅号不具备正文外链权限，外部链接会导致微信返回 45166 invalid content）
        $content = preg_replace_callback( '/<a\b([^>]*)>(.*?)<\/a>/is', function( $matches ) {
            $attrs = $matches[1];
            $text  = $matches[2];

            $href = '';
            if ( preg_match( '/\bhref=[\'"]([^\'"]+)[\'"]/i', $attrs, $href_m ) ) {
                $href = trim( $href_m[1] );
            }

            // 微信公众平台内部链接允许保留（如 mp.weixin.qq.com）
            if ( false !== strpos( $href, 'mp.weixin.qq.com' ) || false !== strpos( $href, 'weixin.qq.com' ) ) {
                return sprintf( '<a href="%s" style="color: #576b95; text-decoration: none;">%s</a>', esc_url( $href ), $text );
            }

            // 外部链接转换为微信安全高亮文本，避免 45166 拦截，同时保留视觉上的链接辨识度
            return sprintf( '<span style="color: #576b95; text-decoration: underline;">%s</span>', $text );
        }, $content );

        // 9. 全局清理所有 HTML 标签上的非法/非标准/交互属性（杜绝 tabindex、dir、data-* 等引发微信解析异常）
        $strip_attrs = array(
            '/\s+(?:tabindex|dir|role|lang|id|contenteditable|draggable|spellcheck|aria-[a-z0-9\-]+)\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
            '/\s+data-(?!miniprogram|src|ratio|w)[a-z0-9\-]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
            '/\s+class=[\'"][^\'"]*(?:heading-element|snippet-clipboard|notranslate|zeroclipboard|position-relative|overflow-auto|markdown-heading)[^\'"]*[\'"]/i',
        );
        $content = preg_replace( $strip_attrs, '', $content );

        // 10. 清理标题后紧跟的孤立 &nbsp; 与多余空白
        $content = preg_replace( '/(<\/h[1-6]>)\s*(?:&nbsp;|\s)+/is', '$1', $content );

        // 11. 递归清理空白无内容的容器标签 (空 div, 空 p, 空 span)
        do {
            $before  = $content;
            $content = preg_replace( '/<(div|p|span)\b[^>]*>\s*(?:&nbsp;|\s)*<\/\1>/is', '', $content );
        } while ( $content !== $before );

        // 12. 清理不可见的 ASCII 控制字符
        $content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content );

        return $content;
    }

    /**
     * 格式化 HTML 样式以更好兼容微信图文排版
     *
     * @param string $html
     * @return string
     */
    private static function format_wechat_html( $html ) {
        // 1. 先保护 <pre>...</pre> 代码块，防止内部标签被后续处理误修改
        $pre_blocks = array();
        $html = preg_replace_callback( '/<pre\b[^>]*>([\s\S]*?)<\/pre>/i', function( $m ) use ( &$pre_blocks ) {
            $idx = count( $pre_blocks );
            $pre_blocks[ $idx ] = $m[0];
            return "###WECHAT_PRE_BLOCK_{$idx}###";
        }, $html );

        // 2. 行内 <code> 排版优化（此时 pre 已被抽取，只匹配独立行内代码）
        $html = preg_replace_callback( '/<code\b([^>]*)>(.*?)<\/code>/is', function( $m ) {
            return '<code style="background: #f3f4f6; color: #d63200; padding: 2px 6px; border-radius: 3px; font-size: 88%; font-family: Consolas, Monaco, monospace; word-break: break-word;">' . $m[2] . '</code>';
        }, $html );

        // 3. 清理行内标签两端的非预期换行与制表符（避免微信把行内加粗和代码块拆成独立行）
        $html = preg_replace( '/(<(?:p|li|h[2-6]|strong|em)\b[^>]*>)\s*[\r\n]+\s*/i', '$1', $html );
        $html = preg_replace( '/\s*[\r\n]+\s*(<\/(?:p|li|h[2-6]|strong|em)>)/i', '$1', $html );
        $html = preg_replace( '/(<\/strong>)\s*[\r\n]+\s*([，。：；！？、）】\)])/u', '$1$2', $html );

        // 4. 有序列表 <ol> 与无序列表 <ul> 的微信图文兼容转换
        // 微信官方自带样式 (ol, ul, li { list-style: none !important; }) 会抹除浏览器自带序号与圆点
        // 通过注入实体序号与项目符号，确保在所有微信客户端 100% 必显且绝不丢失
        if ( false !== stripos( $html, '<ol' ) || false !== stripos( $html, '<ul' ) ) {
            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
            libxml_clear_errors();

            // 处理有序列表 <ol>
            $ols = $dom->getElementsByTagName( 'ol' );
            foreach ( $ols as $ol ) {
                $counter = 1;
                if ( $ol->hasAttribute( 'start' ) ) {
                    $counter = intval( $ol->getAttribute( 'start' ) );
                }
                $is_nested = ( $ol->parentNode && 'li' === strtolower( $ol->parentNode->nodeName ) );
                $style = $is_nested
                    ? 'margin: 6px 0 6px 18px; padding-left: 0; list-style: none;'
                    : 'margin: 8px 0 16px 0; padding-left: 0; list-style: none;';
                $ol->setAttribute( 'style', $style );

                foreach ( $ol->childNodes as $child ) {
                    if ( 'li' === strtolower( $child->nodeName ) ) {
                        // 若 li 内部直接包含 p，将序号注入在 p 内并设为 inline，避免序号单独占一行
                        $target_container = $child;
                        foreach ( $child->childNodes as $sub ) {
                            if ( XML_ELEMENT_NODE === $sub->nodeType ) {
                                if ( 'p' === strtolower( $sub->nodeName ) ) {
                                    $target_container = $sub;
                                    $sub->setAttribute( 'style', 'margin: 0; display: inline;' );
                                }
                                break;
                            }
                        }

                        // 清理 target 开头的空白 TextNode
                        while ( $target_container->firstChild && XML_TEXT_NODE === $target_container->firstChild->nodeType && '' === trim( $target_container->firstChild->textContent ) ) {
                            $target_container->removeChild( $target_container->firstChild );
                        }

                        $text = trim( $child->textContent );
                        // 检查是否已有手动序号（避免重复编号）
                        if ( ! preg_match( '/^(?:\d+[\.、\)\:\-\s]|\(\d+\)|\[\d+\]|【\d+】|[①-⑳]|[一二三四五六七八九十]+[、\.])/u', $text ) ) {
                            $badge = $dom->createElement( 'span', $counter . '. ' );
                            $badge->setAttribute( 'style', 'font-weight: bold; color: #07c160; margin-right: 6px;' );
                            if ( $target_container->firstChild ) {
                                $target_container->insertBefore( $badge, $target_container->firstChild );
                            } else {
                                $target_container->appendChild( $badge );
                            }
                        }
                        $child->setAttribute( 'style', 'margin: 4px 0; line-height: 1.8; list-style: none;' );
                        $counter++;
                    }
                }
            }

            // 处理无序列表 <ul>
            $uls = $dom->getElementsByTagName( 'ul' );
            foreach ( $uls as $ul ) {
                $is_nested = ( $ul->parentNode && 'li' === strtolower( $ul->parentNode->nodeName ) );
                $style = $is_nested
                    ? 'margin: 6px 0 6px 18px; padding-left: 0; list-style: none;'
                    : 'margin: 8px 0 16px 0; padding-left: 0; list-style: none;';
                $ul->setAttribute( 'style', $style );

                foreach ( $ul->childNodes as $child ) {
                    if ( 'li' === strtolower( $child->nodeName ) ) {
                        $target_container = $child;
                        foreach ( $child->childNodes as $sub ) {
                            if ( XML_ELEMENT_NODE === $sub->nodeType ) {
                                if ( 'p' === strtolower( $sub->nodeName ) ) {
                                    $target_container = $sub;
                                    $sub->setAttribute( 'style', 'margin: 0; display: inline;' );
                                }
                                break;
                            }
                        }

                        while ( $target_container->firstChild && XML_TEXT_NODE === $target_container->firstChild->nodeType && '' === trim( $target_container->firstChild->textContent ) ) {
                            $target_container->removeChild( $target_container->firstChild );
                        }

                        $text = trim( $child->textContent );
                        if ( ! preg_match( '/^[•\-\*·◆◇■□▶▷√✓]/u', $text ) ) {
                            $bullet = $dom->createElement( 'span', '• ' );
                            $bullet->setAttribute( 'style', 'color: #07c160; margin-right: 6px; font-weight: bold; line-height: 1;' );
                            if ( $target_container->firstChild ) {
                                $target_container->insertBefore( $bullet, $target_container->firstChild );
                            } else {
                                $target_container->appendChild( $bullet );
                            }
                        }
                        $child->setAttribute( 'style', 'margin: 4px 0; line-height: 1.8; list-style: none;' );
                    }
                }
            }

            $body = $dom->getElementsByTagName( 'body' )->item( 0 );
            $html = '';
            foreach ( $body->childNodes as $c ) {
                $html .= $dom->saveHTML( $c );
            }
        }

        // 5. 段落排版优化
        $html = preg_replace_callback( '/<p\b([^>]*)>/i', function( $m ) {
            return self::inject_style( $m[0], 'margin: 0 0 16px 0; line-height: 1.8; color: #333333;' );
        }, $html );

        // 6. 标题排版优化
        $html = preg_replace_callback( '/<h2\b([^>]*)>/i', function( $m ) {
            return self::inject_style( $m[0], 'margin: 28px 0 14px 0; font-size: 20px; font-weight: bold; color: #111111; border-left: 4px solid #07c160; padding-left: 10px; line-height: 1.4;' );
        }, $html );

        $html = preg_replace_callback( '/<h3\b([^>]*)>/i', function( $m ) {
            return self::inject_style( $m[0], 'margin: 22px 0 12px 0; font-size: 17px; font-weight: bold; color: #222222; line-height: 1.4;' );
        }, $html );

        $html = preg_replace_callback( '/<h4\b([^>]*)>/i', function( $m ) {
            return self::inject_style( $m[0], 'margin: 18px 0 10px 0; font-size: 15px; font-weight: bold; color: #333333; line-height: 1.4;' );
        }, $html );

        // 7. 引用块排版优化
        $html = preg_replace_callback( '/<blockquote\b([^>]*)>/i', function( $m ) {
            return self::inject_style( $m[0], 'margin: 20px 0; padding: 12px 16px; background: #f8f9fa; border-left: 4px solid #07c160; color: #666666; font-size: 15px; line-height: 1.6;' );
        }, $html );

        // 8. 分割线排版优化
        $html = preg_replace_callback( '/<hr\b([^>]*)>/i', function( $m ) {
            return self::inject_style( $m[0], 'border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;' );
        }, $html );

        // 9. 还原并美化 <pre> 代码块
        // 微信草稿箱会把多行代码中的 \n 折叠为单行，这里将换行显式转为 <br/>，将缩进空格转为 &nbsp; 并赋予 pre-wrap 样式
        foreach ( $pre_blocks as $idx => $pre_html ) {
            $code_content = '';
            if ( preg_match( '/<pre\b[^>]*>(?:\s*<code\b[^>]*>)?([\s\S]*?)(?:<\/code>\s*)?<\/pre>/i', $pre_html, $cm ) ) {
                $code_content = $cm[1];
            } else {
                $code_content = $pre_html;
            }

            // 统一换行符
            $code_content = str_replace( array( "\r\n", "\r" ), "\n", $code_content );
            // 按行分割并处理空格缩进
            $lines = explode( "\n", trim( $code_content, "\n" ) );
            $formatted_lines = array();
            foreach ( $lines as $line ) {
                // 连续2个及以上空格转换为 &nbsp; 保留代码缩进与列对齐
                $line = preg_replace_callback( '/ {2,}/', function( $m ) {
                    return str_repeat( '&nbsp;', strlen( $m[0] ) );
                }, $line );
                // 行首单个空格也转为 &nbsp;
                $line = preg_replace( '/^ /', '&nbsp;', $line );
                $formatted_lines[] = $line;
            }
            $formatted_code = implode( "<br/>", $formatted_lines );

            $pre_styled = sprintf(
                '<pre style="background: #282c34; color: #abb2bf; padding: 14px; border-radius: 6px; overflow-x: auto; font-size: 13px; line-height: 1.6; margin: 18px 0; font-family: Consolas, Monaco, monospace; white-space: pre-wrap !important; word-wrap: break-word !important; word-break: break-all !important;"><code style="font-family: Consolas, Monaco, monospace; font-size: 13px; color: inherit; white-space: pre-wrap !important; word-break: break-all !important; display: block;">%s</code></pre>',
                $formatted_code
            );

            $html = str_replace( "###WECHAT_PRE_BLOCK_{$idx}###", $pre_styled, $html );
        }

        // 10. 外层增加适合微信公众号阅读的容器排版
        $wrapped_html = sprintf(
            '<section style="font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; font-size: 16px; line-height: 1.8; color: #333333; letter-spacing: 0.5px; word-break: break-word;">%s</section>',
            $html
        );

        return $wrapped_html;
    }

    /**
     * 辅助方法：安全注入或合并内联样式，杜绝重复 style 属性
     *
     * @param string $tag_html 完整开始标签（如 <p class="abc" style="color:red"> 或 <hr />）
     * @param string $new_style 新增样式规则
     * @return string
     */
    private static function inject_style( $tag_html, $new_style ) {
        if ( preg_match( '/\bstyle=[\'"]([^\'"]*)[\'"]/i', $tag_html, $m ) ) {
            $existing = rtrim( trim( $m[1] ), ';' );
            $combined = $existing . '; ' . $new_style;
            return preg_replace( '/\bstyle=[\'"][^\'"]*[\'"]/i', 'style="' . esc_attr( $combined ) . '"', $tag_html );
        }
        // 清理末尾可能的 / 和 >，避免自闭合标签生成形如 <hr / style="..."> 的语法错误
        $clean_tag = preg_replace( '/\s*\/?\s*>$/', '', $tag_html );
        return $clean_tag . ' style="' . esc_attr( $new_style ) . '">';
    }

    /**
     * 生成并格式化摘要
     *
     * @param WP_Post $post
     * @param array   $options
     * @return string
     */
    private static function resolve_digest( $post, $options ) {
        // 优先使用文章单独填写的微信摘要
        $digest = get_post_meta( $post->ID, '_wechat_custom_digest', true );

        // 其次使用 WordPress 原生文章摘要
        if ( empty( $digest ) && ! empty( $post->post_excerpt ) ) {
            $digest = $post->post_excerpt;
        }

        // 再次从正文中截取前段文本
        if ( empty( $digest ) ) {
            $raw_text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
            $raw_text = preg_replace( '/\s+/', ' ', $raw_text );
            $digest   = mb_substr( trim( $raw_text ), 0, 100, 'UTF-8' );
        }

        // 微信限制摘要最大长度为 120 字符
        return mb_substr( trim( $digest ), 0, 115, 'UTF-8' );
    }
}
