<?php
/**
 * 文章编辑页 Meta Box 与文章列表列展示
 *
 * @package WP_WeChat_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WeChat_Meta_Box {

    /**
     * 初始化
     */
    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post', array( __CLASS__, 'save_meta_data' ), 10, 2 );

        // AJAX 手动同步文章
        add_action( 'wp_ajax_wp_wechat_manual_sync', array( __CLASS__, 'ajax_manual_sync' ) );

        // 文章列表页增加状态列
        add_filter( 'manage_posts_columns', array( __CLASS__, 'add_list_column' ) );
        add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
    }

    /**
     * 注册 Meta Box
     */
    public static function add_meta_box() {
        $options    = get_option( 'wp_wechat_sync_options', array() );
        $post_types = isset( $options['post_types'] ) && is_array( $options['post_types'] ) ? $options['post_types'] : array( 'post' );

        foreach ( $post_types as $pt ) {
            add_meta_box(
                'wp_wechat_sync_meta_box',
                '微信公众号同步',
                array( __CLASS__, 'render_meta_box' ),
                $pt,
                'side',
                'high'
            );
        }
    }

    /**
     * 渲染文章编辑页侧边栏 Meta Box
     *
     * @param WP_Post $post
     */
    public static function render_meta_box( $post ) {
        wp_nonce_field( 'wp_wechat_metabox_save', 'wp_wechat_metabox_nonce' );

        $status         = get_post_meta( $post->ID, '_wechat_sync_status', true );
        $media_id       = get_post_meta( $post->ID, '_wechat_sync_media_id', true );
        $sync_time      = get_post_meta( $post->ID, '_wechat_sync_time', true );
        $error_msg      = get_post_meta( $post->ID, '_wechat_sync_error', true );
        $publish_id     = get_post_meta( $post->ID, '_wechat_publish_id', true );
        $disable_auto   = get_post_meta( $post->ID, '_wechat_sync_disable_auto', true );
        $custom_author  = get_post_meta( $post->ID, '_wechat_custom_author', true );
        $custom_digest  = get_post_meta( $post->ID, '_wechat_custom_digest', true );
        ?>
        <div class="wp-wechat-metabox-wrapper" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
            <!-- 状态卡片 -->
            <div class="sync-status-box">
                <div class="status-row">
                    <span class="status-label">当前状态：</span>
                    <span id="wechat-sync-badge">
                        <?php if ( 'synced' === $status ) : ?>
                            <span class="metabox-badge badge-success">
                                <?php echo $publish_id ? '已提交发布' : '已同步到草稿箱'; ?>
                            </span>
                        <?php elseif ( 'failed' === $status ) : ?>
                            <span class="metabox-badge badge-danger">同步失败</span>
                        <?php else : ?>
                            <span class="metabox-badge badge-gray">未同步</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div id="wechat-sync-meta-details" style="<?php echo ( 'synced' === $status || 'failed' === $status ) ? '' : 'display:none;'; ?>">
                    <?php if ( $sync_time ) : ?>
                        <div class="meta-item">
                            <span class="meta-label">同步时间：</span>
                            <span id="wechat-sync-time" class="meta-value"><?php echo esc_html( $sync_time ); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ( $media_id ) : ?>
                        <div class="meta-item">
                            <span class="meta-label">草稿 ID：</span>
                            <code id="wechat-sync-media-id" class="meta-value" title="<?php echo esc_attr( $media_id ); ?>"><?php echo esc_html( mb_strimwidth( $media_id, 0, 16, '...' ) ); ?></code>
                        </div>
                    <?php endif; ?>

                    <?php if ( 'failed' === $status && $error_msg ) : ?>
                        <div class="meta-error-notice" id="wechat-sync-error-notice">
                            <?php echo esc_html( $error_msg ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 手动同步操作 -->
            <div class="sync-action-box">
                <button type="button" class="button button-primary button-large btn-sync-now" id="btn-manual-sync" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <span class="btn-text">立即同步到公众号</span>
                </button>
                <div id="manual-sync-feedback" class="manual-feedback"></div>
            </div>

            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #ddd;">

            <!-- 文章独立选项 -->
            <div class="sync-options-box">
                <p>
                    <label>
                        <input type="checkbox" name="_wechat_sync_disable_auto" value="1" <?php checked( $disable_auto, 1 ); ?>>
                        <strong>禁止自动同步此文章</strong>
                    </label>
                </p>

                <p style="margin-bottom:6px;">
                    <label for="_wechat_custom_author"><strong>自定义微信作者名：</strong></label>
                    <input type="text" name="_wechat_custom_author" id="_wechat_custom_author" value="<?php echo esc_attr( $custom_author ); ?>" class="widefat" placeholder="最多 8 个字" maxlength="8">
                </p>

                <p style="margin-bottom:0;">
                    <label for="_wechat_custom_digest"><strong>自定义微信摘要：</strong></label>
                    <textarea name="_wechat_custom_digest" id="_wechat_custom_digest" rows="3" class="widefat" placeholder="留空则自动提取正文或摘要（最多 120 字）" maxlength="120"><?php echo esc_textarea( $custom_digest ); ?></textarea>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * 保存文章独立设置
     *
     * @param int     $post_id
     * @param WP_Post $post
     */
    public static function save_meta_data( $post_id, $post ) {
        if ( ! isset( $_POST['wp_wechat_metabox_nonce'] ) || ! wp_verify_nonce( $_POST['wp_wechat_metabox_nonce'], 'wp_wechat_metabox_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // 保存禁止自动同步
        if ( ! empty( $_POST['_wechat_sync_disable_auto'] ) ) {
            update_post_meta( $post_id, '_wechat_sync_disable_auto', 1 );
        } else {
            delete_post_meta( $post_id, '_wechat_sync_disable_auto' );
        }

        // 保存自定义作者
        if ( isset( $_POST['_wechat_custom_author'] ) ) {
            $author = sanitize_text_field( trim( $_POST['_wechat_custom_author'] ) );
            if ( ! empty( $author ) ) {
                update_post_meta( $post_id, '_wechat_custom_author', mb_substr( $author, 0, 8, 'UTF-8' ) );
            } else {
                delete_post_meta( $post_id, '_wechat_custom_author' );
            }
        }

        // 保存自定义摘要
        if ( isset( $_POST['_wechat_custom_digest'] ) ) {
            $digest = sanitize_textarea_field( trim( $_POST['_wechat_custom_digest'] ) );
            if ( ! empty( $digest ) ) {
                update_post_meta( $post_id, '_wechat_custom_digest', mb_substr( $digest, 0, 120, 'UTF-8' ) );
            } else {
                delete_post_meta( $post_id, '_wechat_custom_digest' );
            }
        }
    }

    /**
     * AJAX 处理手动同步
     */
    public static function ajax_manual_sync() {
        check_ajax_referer( 'wp_wechat_sync_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '缺少文章 ID 或无编辑权限。' ) );
        }

        // 执行同步
        $result = WP_WeChat_Post_Sync::sync_post( $post_id, true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message'   => $result['message'],
                'media_id'  => $result['media_id'],
                'time'      => isset( $result['time'] ) ? $result['time'] : current_time( 'Y-m-d H:i:s' ),
                'published' => ! empty( $result['published'] ),
            )
        );
    }

    /**
     * 在文章列表增加“微信同步”列
     *
     * @param array $columns
     * @return array
     */
    public static function add_list_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            $new_columns[ $key ] = $title;
            if ( 'title' === $key ) {
                $new_columns['wechat_sync'] = '微信同步';
            }
        }
        return $new_columns;
    }

    /**
     * 渲染列表列内容
     *
     * @param string $column_name
     * @param int    $post_id
     */
    public static function render_list_column( $column_name, $post_id ) {
        if ( 'wechat_sync' !== $column_name ) {
            return;
        }

        $status    = get_post_meta( $post_id, '_wechat_sync_status', true );
        $sync_time = get_post_meta( $post_id, '_wechat_sync_time', true );
        $media_id  = get_post_meta( $post_id, '_wechat_sync_media_id', true );

        echo '<div class="wechat-list-cell" data-post-id="' . esc_attr( $post_id ) . '">';
        if ( 'synced' === $status ) {
            echo '<span class="metabox-badge badge-success" title="' . esc_attr( '草稿 ID: ' . $media_id . '，时间: ' . $sync_time ) . '">已同步</span>';
            echo '<button type="button" class="button button-small btn-list-quick-sync" data-post-id="' . esc_attr( $post_id ) . '">重新同步</button>';
        } elseif ( 'failed' === $status ) {
            $err = get_post_meta( $post_id, '_wechat_sync_error', true );
            echo '<span class="metabox-badge badge-danger" title="' . esc_attr( $err ) . '">失败</span>';
            echo '<button type="button" class="button button-small btn-list-quick-sync" data-post-id="' . esc_attr( $post_id ) . '">重试</button>';
        } else {
            echo '<span class="metabox-badge badge-gray">未同步</span>';
            echo '<button type="button" class="button button-small btn-list-quick-sync" data-post-id="' . esc_attr( $post_id ) . '">同步</button>';
        }
        echo '</div>';
    }
}
