<?php
/**
 * 微信公众号同步设置管理与后台界面
 *
 * @package WP_WeChat_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WeChat_Admin_Settings {

    const OPTION_KEY = 'wp_wechat_sync_options';

    /**
     * 初始化
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        // AJAX 动作
        add_action( 'wp_ajax_wp_wechat_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wp_wechat_clear_logs', array( __CLASS__, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_wp_wechat_refresh_ip', array( __CLASS__, 'ajax_refresh_ip' ) );

        // 插件列表快捷链接
        add_filter( 'plugin_action_links_' . plugin_basename( WP_WECHAT_SYNC_FILE ), array( __CLASS__, 'add_action_links' ) );
    }

    /**
     * 注册后台菜单项
     */
    public static function add_menu_page() {
        add_options_page(
            '微信公众号同步设置',
            '微信同步',
            'manage_options',
            'wp-wechat-sync',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * 注册设置项
     */
    public static function register_settings() {
        register_setting(
            'wp_wechat_sync_group',
            self::OPTION_KEY,
            array( __CLASS__, 'sanitize_options' )
        );
    }

    /**
     * 添加插件设置直达链接
     *
     * @param array $links
     * @return array
     */
    public static function add_action_links( $links ) {
        $settings_link = sprintf( '<a href="%s">设置</a>', esc_url( admin_url( 'options-general.php?page=wp-wechat-sync' ) ) );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 加载后台样式和脚本
     *
     * @param string $hook
     */
    public static function enqueue_assets( $hook ) {
        $allowed_hooks = array(
            'settings_page_wp-wechat-sync',
            'post.php',
            'post-new.php',
            'edit.php',
        );

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        // 仅在设置页面和文章编辑页面加载媒体库组件
        if ( in_array( $hook, array( 'settings_page_wp-wechat-sync', 'post.php', 'post-new.php' ), true ) ) {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'wp-wechat-sync-admin',
            WP_WECHAT_SYNC_URL . 'assets/css/admin.css',
            array(),
            WP_WECHAT_SYNC_VERSION
        );

        wp_enqueue_script(
            'wp-wechat-sync-admin',
            WP_WECHAT_SYNC_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WP_WECHAT_SYNC_VERSION,
            true
        );

        wp_localize_script(
            'wp-wechat-sync-admin',
            'wpWeChatSync',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'wp_wechat_sync_nonce' ),
            )
        );
    }

    /**
     * 清理与验证保存的选项
     *
     * @param array $input
     * @return array
     */
    public static function sanitize_options( $input ) {
        $clean = array();

        $clean['appid']                 = isset( $input['appid'] ) ? sanitize_text_field( trim( $input['appid'] ) ) : '';
        $clean['appsecret']             = isset( $input['appsecret'] ) ? sanitize_text_field( trim( $input['appsecret'] ) ) : '';
        $clean['auto_sync']             = ! empty( $input['auto_sync'] ) ? 1 : 0;
        $clean['sync_on_update']        = ! empty( $input['sync_on_update'] ) ? 1 : 0;
        $clean['sync_action']           = ( isset( $input['sync_action'] ) && 'publish' === $input['sync_action'] ) ? 'publish' : 'draft';
        $clean['post_types']            = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : array( 'post' );
        $clean['default_author']        = isset( $input['default_author'] ) ? sanitize_text_field( trim( $input['default_author'] ) ) : '';
        $clean['default_cover_image']   = isset( $input['default_cover_image'] ) ? esc_url_raw( trim( $input['default_cover_image'] ) ) : '';
        $clean['add_source_url']        = ! empty( $input['add_source_url'] ) ? 1 : 0;
        $clean['append_source_notice']  = ! empty( $input['append_source_notice'] ) ? 1 : 0;
        $clean['custom_source_notice']  = isset( $input['custom_source_notice'] ) ? sanitize_text_field( trim( $input['custom_source_notice'] ) ) : '';
        $clean['open_comment']          = ! empty( $input['open_comment'] ) ? 1 : 0;
        $clean['only_fans_comment']     = ! empty( $input['only_fans_comment'] ) ? 1 : 0;

        // 如果凭据发生变动，清空已缓存的 token
        $old_options = get_option( self::OPTION_KEY, array() );
        if ( ( $old_options['appid'] ?? '' ) !== $clean['appid'] || ( $old_options['appsecret'] ?? '' ) !== $clean['appsecret'] ) {
            delete_transient( WP_WeChat_API::TRANSIENT_TOKEN );
        }

        return $clean;
    }

    /**
     * 渲染设置页面
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options    = get_option( self::OPTION_KEY, array() );
        $server_ip  = WP_WeChat_API::get_server_egress_ip();
        $logs       = WP_WeChat_Sync_Logger::get_logs( 50 );
        $post_types = get_post_types( array( 'public' => true ), 'objects' );

        $defaults = array(
            'appid'                => '',
            'appsecret'            => '',
            'auto_sync'            => 1,
            'sync_on_update'       => 0,
            'sync_action'          => 'draft',
            'post_types'           => array( 'post' ),
            'default_author'       => '',
            'default_cover_image'  => '',
            'add_source_url'       => 1,
            'append_source_notice' => 1,
            'custom_source_notice' => '',
            'open_comment'         => 0,
            'only_fans_comment'    => 0,
        );
        $options = wp_parse_args( $options, $defaults );
        ?>
        <div class="wrap wp-wechat-wrap">
            <h1 class="wp-wechat-title">
                <span class="dashicons dashicons-share" style="font-size:28px;line-height:1;margin-right:6px;color:#07c160;"></span>
                微信公众号同步助手 (WP WeChat Sync)
            </h1>

            <div class="wp-wechat-container">
                <!-- 左侧：主要设置表单 -->
                <div class="wp-wechat-main">
                    <form method="post" action="options.php" id="wp-wechat-settings-form">
                        <?php settings_fields( 'wp_wechat_sync_group' ); ?>

                        <!-- 模块 1：API 凭据配置 -->
                        <div class="wp-wechat-card">
                            <h2 class="card-title">1. 微信公众平台 API 凭据配置</h2>
                            <p class="card-desc">
                                请在 <a href="https://developers.weixin.qq.com/platform/" target="_blank" rel="noopener">微信开发者平台</a>（或微信公众平台）登录公众号，在<strong>“基础信息 -> 开发密钥”</strong>中获取 AppID 与开发者密码 (AppSecret)。
                            </p>

                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="wechat_appid">开发者 ID (AppID) <span class="required">*</span></label></th>
                                    <td>
                                        <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[appid]" id="wechat_appid" value="<?php echo esc_attr( $options['appid'] ); ?>" class="regular-text" placeholder="wx1234567890abcdef" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wechat_appsecret">开发者密码 (AppSecret) <span class="required">*</span></label></th>
                                    <td>
                                        <div class="input-with-toggle">
                                            <input type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[appsecret]" id="wechat_appsecret" value="<?php echo esc_attr( $options['appsecret'] ); ?>" class="regular-text" placeholder="输入公众号 AppSecret" autocomplete="new-password" required>
                                            <button type="button" class="button button-secondary toggle-secret-btn" title="显示/隐藏 Secret">显示</button>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <div class="wp-wechat-test-box">
                                <button type="button" id="btn-test-connection" class="button button-secondary">
                                    <span class="dashicons dashicons-update"></span>
                                    <span class="btn-text">测试 API 连接</span>
                                </button>
                                <span id="test-connection-status" class="test-status"></span>
                            </div>
                        </div>

                        <!-- 模块 2：同步规则与策略 -->
                        <div class="wp-wechat-card">
                            <h2 class="card-title">2. 文章同步策略</h2>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">同步目标</th>
                                    <td>
                                        <fieldset>
                                            <label style="margin-right:20px;">
                                                <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_action]" value="draft" <?php checked( $options['sync_action'], 'draft' ); ?>>
                                                <strong>同步至草稿箱 (推荐)</strong>
                                                <p class="description">推送到微信公众号“草稿箱”，可在微信后台进行排版微调、预览或定时发布，最安全保险。</p>
                                            </label>
                                            <br>
                                            <label>
                                                <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_action]" value="publish" <?php checked( $options['sync_action'], 'publish' ); ?>>
                                                <strong>同步并直接发布</strong>
                                                <p class="description">调用微信 FreePublish 接口直接公开发布（注意：微信每日发布有配额限制，直接发布前请确保文章无排版错误）。</p>
                                            </label>
                                        </fieldset>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">自动同步开关</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_sync]" value="1" <?php checked( $options['auto_sync'], 1 ); ?>>
                                            发布新文章时自动同步到微信公众号
                                        </label>
                                        <br>
                                        <label style="margin-top:6px;display:inline-block;">
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_on_update]" value="1" <?php checked( $options['sync_on_update'], 1 ); ?>>
                                            更新已发布文章时允许重新同步（若未勾选，已同步过的文章更新不会重复推送到公众号）
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">支持的文章类型</th>
                                    <td>
                                        <fieldset>
                                            <?php foreach ( $post_types as $pt ) : ?>
                                                <label style="margin-right: 15px; display:inline-block;">
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $options['post_types'], true ) ); ?>>
                                                    <?php echo esc_html( $pt->label ); ?> (<code><?php echo esc_html( $pt->name ); ?></code>)
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- 模块 3：图文排版与元数据 -->
                        <div class="wp-wechat-card">
                            <h2 class="card-title">3. 图文排版与内容选项</h2>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="wechat_default_author">默认作者署名</label></th>
                                    <td>
                                        <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_author]" id="wechat_default_author" value="<?php echo esc_attr( $options['default_author'] ); ?>" class="regular-text" placeholder="留空则默认使用 WordPress 文章作者昵称">
                                        <p class="description">微信限制作者名字最多 8 个字以内。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wechat_default_cover">全局默认封面图</label></th>
                                    <td>
                                        <div class="media-input-group">
                                            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_cover_image]" id="wechat_default_cover" value="<?php echo esc_url( $options['default_cover_image'] ); ?>" class="regular-text" placeholder="https://example.com/cover.jpg">
                                            <button type="button" class="button button-secondary upload-media-btn" data-target="#wechat_default_cover">选择媒体库图片</button>
                                        </div>
                                        <p class="description">当文章既没有设置“特色图片”，正文中也没有任何图片时，将使用此默认封面（微信图文必须有封面）。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">原文链接与版权</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[add_source_url]" value="1" <?php checked( $options['add_source_url'], 1 ); ?>>
                                            开启“阅读原文”直达 WordPress 原文链接
                                        </label>
                                        <br>
                                        <label style="margin-top:6px;display:inline-block;">
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[append_source_notice]" value="1" <?php checked( $options['append_source_notice'], 1 ); ?>>
                                            在文章底部添加引用提示框（提示读者点击“阅读原文”查看原网页）
                                        </label>
                                        <div style="margin-top:10px;">
                                            <label for="wechat_custom_source_notice" style="display:block;margin-bottom:4px;color:#555;font-weight:500;">自定义文末声明文案（留空则使用默认）：</label>
                                            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_source_notice]" id="wechat_custom_source_notice" value="<?php echo esc_attr( $options['custom_source_notice'] ); ?>" class="large-text" placeholder="本文首发于 {site_name}，点击微信底部“阅读原文”可直接访问原网页参与讨论。">
                                            <p class="description">支持动态变量：<code>{site_name}</code>（当前网站名称）、<code>{site_url}</code>（当前网站网址）。其他站长使用本插件时将自动适配其自身网站名。</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">微信留言设置</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[open_comment]" value="1" <?php checked( $options['open_comment'], 1 ); ?>>
                                            默认开启文章留言
                                        </label>
                                        <br>
                                        <label style="margin-top:6px;display:inline-block;">
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[only_fans_comment]" value="1" <?php checked( $options['only_fans_comment'], 1 ); ?>>
                                            仅允许粉丝留言
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <?php submit_button( '保存设置', 'primary', 'submit', true, array( 'id' => 'btn-save-settings' ) ); ?>
                    </form>
                </div>

                <!-- 右侧边栏：服务器 IP 指引与快捷信息 -->
                <div class="wp-wechat-sidebar">
                    <div class="wp-wechat-card sidebar-card ip-card">
                        <h3>
                            <span class="dashicons dashicons-admin-site-alt3" style="color:#2271b1;vertical-align:text-bottom;"></span>
                            微信 IP 白名单配置指引
                        </h3>
                        <p>微信公众平台强制开启了<strong>接口 IP 白名单</strong>保护。当前服务器的公网出口 IP 如下：</p>
                        <div class="ip-display-box">
                            <code id="server-egress-ip"><?php echo esc_html( $server_ip ); ?></code>
                            <button type="button" class="button button-small copy-ip-btn" data-clipboard-target="#server-egress-ip">复制 IP</button>
                            <button type="button" class="button button-small refresh-ip-btn" title="重新获取最新 IP">刷新</button>
                        </div>
                        <div class="ip-steps">
                            <strong>添加步骤（微信开发者平台新版）：</strong>
                            <ol>
                                <li>登录 <a href="https://developers.weixin.qq.com/platform/" target="_blank" rel="noopener">微信开发者平台</a>。</li>
                                <li>选择对应公众号，进入<strong>“基础信息”</strong>页面。</li>
                                <li>在<strong>“开发密钥”</strong>区域找到<strong>“API IP白名单”</strong>，点击“编辑/添加”。</li>
                                <li>将上方显示的 IP 填入并管理员扫码确认保存即可。</li>
                            </ol>
                        </div>
                    </div>

                    <div class="wp-wechat-card sidebar-card tips-card">
                        <h3><span class="dashicons dashicons-yes-alt" style="color:#07c160;vertical-align:text-bottom;"></span> 功能与使用提示</h3>
                        <ul class="tips-list">
                            <li><strong>全面支持个人号</strong>：<strong>个人订阅号</strong>、认证号及服务号<strong>均全面支持同步到草稿箱</strong>！</li>
                            <li><strong>草稿箱预览发布</strong>：文章同步推送到微信草稿箱后，可在微信公众平台进行手机预览、版式确认与正式群发发布。</li>
                            <li><strong>正文图片防盗链</strong>：插件已自动将文章中的全部图片转换为微信官方永久 CDN 地址，无需担心微信内图片裂图。</li>
                            <li><strong>便捷实时同步</strong>：在文章列表与文章编辑页均提供一键“同步/重新同步”按钮，操作即时生效。</li>
                        </ul>
                    </div>

                    <div class="wp-wechat-card sidebar-card author-card">
                        <h3>
                            <span class="dashicons dashicons-admin-site-alt2" style="color:#07c160;vertical-align:text-bottom;"></span>
                            关于作者与版权
                        </h3>
                        <div class="author-info">
                            <p style="margin: 0 0 6px 0; font-size: 13px; color: #1d2327;">
                                开发者：<strong>李成成</strong>
                            </p>
                            <p style="margin: 0 0 10px 0; font-size: 13px; color: #50575e;">
                                官方主页：<a href="https://lichengcheng.cn" target="_blank" rel="noopener" style="font-weight:600;color:#2271b1;">李成成的博客 (lichengcheng.cn)</a>
                            </p>
                            <div style="font-size:12px;color:#646970;background:#f6f7f7;padding:8px 10px;border-radius:4px;line-height:1.5;border:1px solid #e2e4e7;">
                                感谢使用本插件！更多实用教程、AI 工具与开源项目请访问 <a href="https://lichengcheng.cn" target="_blank" rel="noopener" style="color:#07c160;font-weight:600;">lichengcheng.cn</a>。
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 模块 4：同步日志与排查历史 -->
            <div class="wp-wechat-logs-section">
                <div class="logs-header">
                    <h2>
                        <span class="dashicons dashicons-list-view" style="vertical-align:text-bottom;"></span>
                        最近同步日志 (最近 50 条)
                    </h2>
                    <button type="button" id="btn-clear-logs" class="button button-secondary">清空日志</button>
                </div>

                <div class="logs-table-wrapper">
                    <table class="widefat striped wp-wechat-log-table">
                        <thead>
                            <tr>
                                <th style="width:140px;">时间</th>
                                <th style="width:80px;">类型</th>
                                <th style="width:200px;">关联文章</th>
                                <th style="width:80px;">状态</th>
                                <th>处理信息与详情</th>
                            </tr>
                        </thead>
                        <tbody id="logs-table-body">
                            <?php if ( empty( $logs ) ) : ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;color:#999;padding:25px;">暂无同步记录</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html( $log['time'] ); ?></code></td>
                                        <td>
                                            <?php
                                            if ( 'auto' === $log['type'] ) {
                                                echo '<span class="log-badge badge-auto">自动同步</span>';
                                            } elseif ( 'manual' === $log['type'] ) {
                                                echo '<span class="log-badge badge-manual">手动同步</span>';
                                            } else {
                                                echo '<span class="log-badge badge-test">测试</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $log['post_id'] ) ) : ?>
                                                <a href="<?php echo esc_url( get_edit_post_link( $log['post_id'] ) ); ?>" target="_blank">
                                                    <?php echo esc_html( $log['post_title'] ? $log['post_title'] : '文章 #' . $log['post_id'] ); ?>
                                                </a>
                                            <?php else : ?>
                                                <span style="color:#999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( 'success' === $log['status'] ) : ?>
                                                <span class="status-success">成功</span>
                                            <?php elseif ( 'warning' === $log['status'] ) : ?>
                                                <span class="status-warning">警告</span>
                                            <?php else : ?>
                                                <span class="status-error">失败</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo esc_html( $log['message'] ); ?></div>
                                            <?php if ( ! empty( $log['details'] ) ) : ?>
                                                <details class="log-details">
                                                    <summary>查看详细数据</summary>
                                                    <pre><?php echo esc_html( $log['details'] ); ?></pre>
                                                </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 页脚版权信息 -->
            <div class="wp-wechat-footer">
                <p>
                    <strong>WP WeChat Sync 微信公众号文章同步助手</strong> &copy; <?php echo date( 'Y' ); ?>
                    &nbsp;|&nbsp; 开发者：<strong>李成成</strong>
                    &nbsp;|&nbsp; 官方网站：<a href="https://lichengcheng.cn" target="_blank" rel="noopener"><strong>李成成的博客 (lichengcheng.cn)</strong></a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX 测试连接
     */
    public static function ajax_test_connection() {
        check_ajax_referer( 'wp_wechat_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $input_appid  = isset( $_POST['appid'] ) ? sanitize_text_field( trim( $_POST['appid'] ) ) : '';
        $input_secret = isset( $_POST['appsecret'] ) ? sanitize_text_field( trim( $_POST['appsecret'] ) ) : '';

        // 如果前端传了未保存的新值，临时更新设置以供测试
        if ( ! empty( $input_appid ) && ! empty( $input_secret ) ) {
            $options              = get_option( self::OPTION_KEY, array() );
            $options['appid']     = $input_appid;
            $options['appsecret'] = $input_secret;
            update_option( self::OPTION_KEY, $options );
        }

        // 强制重新获取 token
        $token = WP_WeChat_API::get_access_token( true );

        if ( is_wp_error( $token ) ) {
            $err_msg = $token->get_error_message();
            WP_WeChat_Sync_Logger::log( 0, 'API 连接测试', 'test', 'error', '测试连接失败：' . $err_msg, $token->get_error_data() );
            wp_send_json_error( array( 'message' => $err_msg ) );
        }

        WP_WeChat_Sync_Logger::log( 0, 'API 连接测试', 'test', 'success', '连接微信服务器成功，已成功获取 Access Token。' );
        wp_send_json_success( array( 'message' => '连接成功！已顺利获取到 Access Token，配置无误。' ) );
    }

    /**
     * AJAX 刷新公网出口 IP
     */
    public static function ajax_refresh_ip() {
        check_ajax_referer( 'wp_wechat_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $ip = WP_WeChat_API::get_server_egress_ip( true );
        wp_send_json_success( array( 'ip' => $ip ) );
    }

    /**
     * AJAX 清空日志
     */
    public static function ajax_clear_logs() {
        check_ajax_referer( 'wp_wechat_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        WP_WeChat_Sync_Logger::clear_logs();
        wp_send_json_success();
    }
}
