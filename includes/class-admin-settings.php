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

        // 微信公众号消息服务器配置
        $clean['enable_message_server'] = ! empty( $input['enable_message_server'] ) ? 1 : 0;
        $clean['message_server_token']  = isset( $input['message_server_token'] ) ? sanitize_text_field( trim( $input['message_server_token'] ) ) : '';

        // 如果开启了消息服务器但 Token 为空，则自动生成一个 16 位安全 Token
        if ( empty( $clean['message_server_token'] ) && ! empty( $clean['enable_message_server'] ) ) {
            $clean['message_server_token'] = wp_generate_password( 16, false, false );
        }

        $clean['welcome_message']    = isset( $input['welcome_message'] ) ? sanitize_textarea_field( trim( $input['welcome_message'] ) ) : '';
        $clean['default_reply_text'] = isset( $input['default_reply_text'] ) ? sanitize_textarea_field( trim( $input['default_reply_text'] ) ) : '';

        // 关键词规则清洗
        $clean_rules = array();
        if ( ! empty( $input['keyword_rules'] ) && is_array( $input['keyword_rules'] ) ) {
            foreach ( $input['keyword_rules'] as $rule ) {
                if ( ! is_array( $rule ) ) {
                    continue;
                }
                $kw = isset( $rule['keywords'] ) ? sanitize_text_field( trim( $rule['keywords'] ) ) : '';
                if ( '' === $kw ) {
                    continue;
                }

                $reply_type = isset( $rule['reply_type'] ) && in_array( $rule['reply_type'], array( 'text_link', 'news', 'text_only', 'custom_text' ), true )
                    ? $rule['reply_type']
                    : 'text_link';

                $clean_rules[] = array(
                    'keywords'    => $kw,
                    'post_id'     => isset( $rule['post_id'] ) ? absint( $rule['post_id'] ) : 0,
                    'reply_type'  => $reply_type,
                    'prefix'      => isset( $rule['prefix'] ) ? sanitize_text_field( trim( $rule['prefix'] ) ) : '',
                    'suffix'      => isset( $rule['suffix'] ) ? sanitize_text_field( trim( $rule['suffix'] ) ) : '',
                    'custom_text' => isset( $rule['custom_text'] ) ? sanitize_textarea_field( trim( $rule['custom_text'] ) ) : '',
                );
            }
        }
        $clean['keyword_rules'] = $clean_rules;

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
            'appid'                 => '',
            'appsecret'             => '',
            'auto_sync'             => 1,
            'sync_on_update'        => 0,
            'sync_action'           => 'draft',
            'post_types'            => array( 'post' ),
            'default_author'        => '',
            'default_cover_image'   => '',
            'add_source_url'        => 1,
            'append_source_notice'  => 1,
            'custom_source_notice'  => '',
            'open_comment'          => 0,
            'only_fans_comment'     => 0,
            'enable_message_server' => 0,
            'message_server_token'  => wp_generate_password( 16, false, false ),
            'keyword_rules'         => array(),
            'welcome_message'       => '',
            'default_reply_text'    => '',
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

                        <!-- 模块 4：微信公众号消息服务器与关键词实时回复 (开发模式) -->
                        <div class="wp-wechat-card">
                            <h2 class="card-title">
                                4. 微信公众号消息服务器与关键词实时回复 (开发模式)
                                <span class="badge-feature" style="font-size:12px;background:#e7f5eb;color:#07c160;padding:2px 8px;border-radius:3px;font-weight:normal;margin-left:8px;">个人号全面支持</span>
                            </h2>
                            <p class="card-desc">
                                开启后，粉丝向公众号发送指定关键词（如 <code>codex</code>、<code>额度</code>、<code>重置</code>）时，插件将<strong>实时提取 WordPress 绑定文章的最新内容</strong>并自动回复。支持生成<strong>原网页实时刷新直达链接</strong>或<strong>单图文卡片</strong>，粉丝可直接转发到微信群，群友点击即可在微信中直接查看最新状态。
                            </p>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">消息服务器开关</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_message_server]" id="enable_message_server" value="1" <?php checked( $options['enable_message_server'], 1 ); ?>>
                                            <strong>启用微信服务器接入与关键词实时回复功能</strong>
                                        </label>
                                        <p class="description">启用后，需在微信公众平台后台填写下方的 URL 与 Token 进行验证激活。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label>服务器地址 (URL)</label></th>
                                    <td>
                                        <div class="server-url-box" style="margin-bottom:8px;">
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <input type="text" id="wechat_server_url" value="<?php echo esc_url( WP_WeChat_Message_Server::get_server_url() ); ?>" class="regular-text" readonly style="background:#f6f7f7;font-family:monospace;width:480px;">
                                                <button type="button" class="button button-secondary copy-server-url-btn" data-target="#wechat_server_url">复制 URL</button>
                                            </div>
                                            <p class="description">请将此 URL 填入 <a href="https://developers.weixin.qq.com/console/product/mp/wxcbdf7caf12aff350?tab1=basicInfo&tab2=apiMonitoring" target="_blank" rel="noopener">微信开发者平台</a><strong>“基础信息 -> 域名与消息推送配置 -> 消息推送”</strong>的 <strong>URL (服务器地址)</strong> 中。</p>
                                        </div>
                                        <details style="font-size:13px;color:#646970;margin-top:6px;">
                                            <summary style="cursor:pointer;color:#2271b1;">备用兼容接入地址（无伪静态或 REST API 被安全拦截时使用）</summary>
                                            <div style="margin-top:6px;display:flex;align-items:center;gap:8px;">
                                                <input type="text" id="wechat_fallback_url" value="<?php echo esc_url( WP_WeChat_Message_Server::get_fallback_url() ); ?>" class="regular-text" readonly style="background:#f6f7f7;font-family:monospace;width:480px;">
                                                <button type="button" class="button button-secondary copy-server-url-btn" data-target="#wechat_fallback_url">复制备用 URL</button>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wechat_server_token">令牌 (Token) <span class="required">*</span></label></th>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[message_server_token]" id="wechat_server_token" value="<?php echo esc_attr( $options['message_server_token'] ); ?>" class="regular-text" placeholder="3-32位字符，如 a1b2c3d4e5f6" required style="font-family:monospace;">
                                            <button type="button" class="button button-secondary" id="btn-generate-token">随机生成</button>
                                        </div>
                                        <p class="description">需与微信开发者平台“消息推送”中填写的 Token 完全一致（3-32 位字母或数字）。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">消息加解密方式</th>
                                    <td>
                                        <span style="font-weight:600;color:#1d2327;">明文模式 (推荐)</span>
                                        <p class="description">在微信公众平台配置服务器时，请务必勾选<strong>“明文模式”</strong>。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">关键词回复规则</th>
                                    <td>
                                        <div class="keyword-rules-wrapper" style="max-width:850px;">
                                            <table class="widefat rules-table" id="keyword-rules-table" style="margin-bottom:12px;background:#fff;border-radius:4px;">
                                                <thead>
                                                    <tr>
                                                        <th style="width:28%;">触发关键词 (多个逗号隔开)</th>
                                                        <th style="width:22%;">关联文章 ID</th>
                                                        <th style="width:28%;">回复形式</th>
                                                        <th style="width:12%;">操作</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="keyword-rules-tbody">
                                                    <?php
                                                    $rules = ! empty( $options['keyword_rules'] ) && is_array( $options['keyword_rules'] ) ? $options['keyword_rules'] : array();
                                                    if ( empty( $rules ) ) {
                                                        // 默认提供一个示例规则骨架
                                                        $rules = array(
                                                            array(
                                                                'keywords'    => 'codex,额度,重置',
                                                                'post_id'     => '',
                                                                'reply_type'  => 'text_link',
                                                                'prefix'      => '【今日额度实时查询】',
                                                                'suffix'      => '',
                                                                'custom_text' => '',
                                                            ),
                                                        );
                                                    }
                                                    foreach ( $rules as $index => $rule ) :
                                                        $post_title = '';
                                                        if ( ! empty( $rule['post_id'] ) ) {
                                                            $linked_p = get_post( (int) $rule['post_id'] );
                                                            if ( $linked_p ) {
                                                                $post_title = get_the_title( $linked_p );
                                                            }
                                                        }
                                                        ?>
                                                        <tr class="rule-row" data-index="<?php echo esc_attr( $index ); ?>">
                                                            <td>
                                                                <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[keyword_rules][<?php echo esc_attr( $index ); ?>][keywords]" value="<?php echo esc_attr( $rule['keywords'] ?? '' ); ?>" class="large-text" placeholder="例：codex,额度,重置" required>
                                                                <input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[keyword_rules][<?php echo esc_attr( $index ); ?>][prefix]" value="<?php echo esc_attr( $rule['prefix'] ?? '' ); ?>" class="large-text" placeholder="可选前缀，如【实时数据】" style="margin-top:4px;font-size:12px;">
                                                            </td>
                                                            <td>
                                                                <input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[keyword_rules][<?php echo esc_attr( $index ); ?>][post_id]" value="<?php echo esc_attr( $rule['post_id'] ?? '' ); ?>" class="small-text post-id-input" placeholder="文章ID">
                                                                <div class="post-title-preview" style="font-size:12px;color:#2271b1;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">
                                                                    <?php echo esc_html( $post_title ? '已关联: ' . $post_title : '输入ID自动关联文章' ); ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[keyword_rules][<?php echo esc_attr( $index ); ?>][reply_type]" class="rule-reply-type" style="width:100%;">
                                                                    <option value="text_link" <?php selected( $rule['reply_type'] ?? 'text_link', 'text_link' ); ?>>【推荐】文本 + 网页直达链接</option>
                                                                    <option value="news" <?php selected( $rule['reply_type'] ?? '', 'news' ); ?>>微信单图文卡片 (含封面/可转发)</option>
                                                                    <option value="text_only" <?php selected( $rule['reply_type'] ?? '', 'text_only' ); ?>>纯文本 (仅提取正文并保留换行)</option>
                                                                    <option value="custom_text" <?php selected( $rule['reply_type'] ?? '', 'custom_text' ); ?>>固定文本 (无需绑定文章)</option>
                                                                </select>
                                                                <div class="custom-text-box" style="<?php echo ( ( $rule['reply_type'] ?? '' ) === 'custom_text' ) ? '' : 'display:none;'; ?>margin-top:4px;">
                                                                    <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[keyword_rules][<?php echo esc_attr( $index ); ?>][custom_text]" rows="2" class="large-text" placeholder="输入固定回复内容"><?php echo esc_textarea( $rule['custom_text'] ?? '' ); ?></textarea>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="button button-link-delete btn-remove-rule" style="color:#d63638;cursor:pointer;padding-top:6px;">删除</button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>

                                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                                <button type="button" class="button button-secondary" id="btn-add-keyword-rule">
                                                    <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;font-size:16px;"></span>
                                                    添加新关键词规则
                                                </button>
                                                <?php
                                                $recent_posts = get_posts( array( 'numberposts' => 5, 'post_status' => 'publish' ) );
                                                if ( ! empty( $recent_posts ) ) :
                                                    ?>
                                                    <span style="font-size:12px;color:#646970;">
                                                        最近文章参考：
                                                        <?php foreach ( $recent_posts as $rp ) : ?>
                                                            <a href="javascript:void(0);" class="quick-fill-post-id" data-id="<?php echo esc_attr( $rp->ID ); ?>" title="点击填入 ID: <?php echo esc_attr( $rp->ID ); ?> (<?php echo esc_attr( $rp->post_title ); ?>)" style="margin-left:4px;">
                                                                #<?php echo esc_html( $rp->ID ); ?>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wechat_welcome_message">关注公众号欢迎语</label></th>
                                    <td>
                                        <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[welcome_message]" id="wechat_welcome_message" rows="3" class="large-text" placeholder="例：感谢关注！发送【codex】或【额度】即可实时获取最新更新记录；点击推送链接可直达原网页。"><?php echo esc_textarea( $options['welcome_message'] ); ?></textarea>
                                        <p class="description">新用户关注公众号时自动回复此内容。留空则不回复（支持 <code>&lt;a href="..."&gt;超链接&lt;/a&gt;</code>）。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wechat_default_reply_text">未匹配消息默认回复</label></th>
                                    <td>
                                        <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_reply_text]" id="wechat_default_reply_text" rows="2" class="large-text" placeholder="例：抱歉，未找到相关内容。您可以发送【codex】或【额度】查询最新动态。"><?php echo esc_textarea( $options['default_reply_text'] ); ?></textarea>
                                        <p class="description">当用户发送的内容未命中任何关键词时回复。<strong>强烈建议留空</strong>（留空时微信保持静默不打扰，完全符合常规公众号体验）。</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <?php submit_button( '保存设置', 'primary', 'submit', true, array( 'id' => 'btn-save-settings' ) ); ?>
                    </form>
                </div>

                <!-- 右侧边栏：服务器 IP 指引与快捷信息 -->
                <div class="wp-wechat-sidebar">
                    <!-- 模块 4 关联：微信开发者平台消息推送配置指引卡片 -->
                    <div class="wp-wechat-card sidebar-card server-card" style="border-left:4px solid #07c160;">
                        <h3>
                            <span class="dashicons dashicons-admin-network" style="color:#07c160;vertical-align:text-bottom;"></span>
                            微信消息推送配置指引 (新版控制台)
                        </h3>
                        <div class="server-steps" style="font-size:13px;line-height:1.6;color:#50575e;">
                            <ol style="margin-left:18px;padding-left:0;">
                                <li>登录 <a href="https://developers.weixin.qq.com/console/product/mp/wxcbdf7caf12aff350?tab1=basicInfo&tab2=apiMonitoring" target="_blank" rel="noopener">微信开发者平台</a> 进入公众号控制台。</li>
                                <li>进入<strong>“基础信息”</strong>页面，向下滑动找到<strong>“域名与消息推送配置”</strong>。</li>
                                <li>在<strong>“消息推送”</strong>栏目，点击右侧的<strong>“配置”</strong>（若为首次请点击“配置”或“启用”）。</li>
                                <li><strong>URL (服务器地址)</strong>：粘贴左侧显示的 URL。<br>
                                    <strong>Token (令牌)</strong>：粘贴左侧生成的 Token。<br>
                                    <strong>消息加解密方式</strong>：建议勾选<strong>“明文模式”</strong>。
                                </li>
                                <li>点击<strong>“提交”</strong>（系统瞬间完成握手验证），提交成功后点击右侧的<strong>“启用”</strong>按钮即可生效！</li>
                            </ol>
                            <div style="background:#f6f7f7;padding:8px 10px;border-radius:4px;margin-top:10px;border:1px solid #e2e4e7;font-size:12px;">
                                💡 <strong>排查提示</strong>：若提示“HTTP 返回非 200”，请先确认 WordPress 插件后台的【保存设置】已点击，且两边 Token 完全一致。
                            </div>
                        </div>
                    </div>

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
                                            } elseif ( 'server' === $log['type'] ) {
                                                echo '<span class="log-badge badge-server" style="background:#f0f6fc;color:#0969da;border:1px solid #c8e1ff;">自动回复</span>';
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
