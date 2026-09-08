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
                    // 若个别图片上传失败，记录警告但不完全中断同步流程，或者记录日志
                    WP_WeChat_Sync_Logger::log( $post->ID, $post->post_title, 'auto', 'warning', '正文中有图片上传至微信 CDN 失败：' . $wechat_cdn_url->get_error_message() );
                    continue;
                }

                $image_cdn_map[ $src ] = $wechat_cdn_url;
                $content               = str_replace( $src, $wechat_cdn_url, $content );
            }
            update_post_meta( $post->ID, '_wechat_image_cdn_map', $image_cdn_map );
        }

        // 清理正文中的不安全标签（如 <script>、<style>、<iframe> 等微信不支持的标签）
        $content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $content );
        $content = preg_replace( '/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $content );

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
                '<section style="margin-top: 30px; padding: 15px 14px; background: #f8f9fa; border-left: 4px solid #07c160; border-radius: 4px; font-size: 14px; color: #555; line-height: 1.6;">
                    <p style="margin: 0;">%s</p>
                </section>',
                $notice_body
            );
            $content .= $notice_html;
        }

        return $content;
    }

    /**
     * 格式化 HTML 样式以更好兼容微信图文排版
     *
     * @param string $html
     * @return string
     */
    private static function format_wechat_html( $html ) {
        // 图片默认居中且最大宽度 100%
        $html = preg_replace(
            '/<img\b([^>]*)>/i',
            '<img $1 style="max-width: 100% !important; height: auto !important; display: block; margin: 15px auto; border-radius: 4px;">',
            $html
        );

        // 外层增加适合微信公众号阅读的容器排版
        $wrapped_html = sprintf(
            '<section style="font-family: -apple-system, BlinkMacSystemFont, \'Helvetica Neue\', \'PingFang SC\', \'Hiragino Sans GB\', \'Microsoft YaHei UI\', \'Microsoft YaHei\', Arial, sans-serif; font-size: 16px; line-height: 1.8; color: #333333; letter-spacing: 0.5px; word-break: break-word;">%s</section>',
            $html
        );

        return $wrapped_html;
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
