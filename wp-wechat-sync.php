<?php
/**
 * Plugin Name: WP WeChat Sync (微信公众号文章同步助手)
 * Plugin URI: https://lichengcheng.cn/
 * Description: 在 WordPress 中撰写或发布的文章可自动或一键同步到微信公众号草稿箱（或直接公开发布）。具备正文图片自动转存微信永久 CDN、封面智能提取、出口 IP 自动识别与详细日志跟踪功能。由 李成成的博客 (lichengcheng.cn) 原创打造。
 * Version: 1.0.9
 * Author: 李成成的博客 (LiChengCheng)
 * Author URI: https://lichengcheng.cn/
 * License: GPLv2 or later
 * Text Domain: wp-wechat-sync
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 核心常量定义
define( 'WP_WECHAT_SYNC_VERSION', '1.0.9' );
define( 'WP_WECHAT_SYNC_FILE', __FILE__ );
define( 'WP_WECHAT_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_WECHAT_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * 核心插件类
 */
final class WP_WeChat_Sync {

    /**
     * 单例实例
     *
     * @var WP_WeChat_Sync
     */
    private static $instance = null;

    /**
     * 获取单例
     *
     * @return WP_WeChat_Sync
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数：载入模块与挂载钩子
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * 引入所有依赖模块
     */
    private function includes() {
        require_once WP_WECHAT_SYNC_PATH . 'includes/class-sync-logger.php';
        require_once WP_WECHAT_SYNC_PATH . 'includes/class-wechat-api.php';
        require_once WP_WECHAT_SYNC_PATH . 'includes/class-post-sync.php';

        if ( is_admin() ) {
            require_once WP_WECHAT_SYNC_PATH . 'includes/class-admin-settings.php';
            require_once WP_WECHAT_SYNC_PATH . 'includes/class-meta-box.php';
        }
    }

    /**
     * 初始化各个模块
     */
    private function init_hooks() {
        // 初始化文章同步核心监听
        WP_WeChat_Post_Sync::init();

        // 初始化后台功能
        if ( is_admin() ) {
            WP_WeChat_Admin_Settings::init();
            WP_WeChat_Meta_Box::init();
        }

        // 注册激活/注销钩子
        register_activation_hook( WP_WECHAT_SYNC_FILE, array( __CLASS__, 'activate' ) );
        register_deactivation_hook( WP_WECHAT_SYNC_FILE, array( __CLASS__, 'deactivate' ) );
    }

    /**
     * 插件激活时初始化默认选项
     */
    public static function activate() {
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
            'open_comment'         => 0,
            'only_fans_comment'    => 0,
        );

        $existing = get_option( 'wp_wechat_sync_options' );
        if ( false === $existing ) {
            add_option( 'wp_wechat_sync_options', $defaults, '', 'no' );
        }
    }

    /**
     * 插件停用时清理临时缓存
     */
    public static function deactivate() {
        delete_transient( WP_WeChat_API::TRANSIENT_TOKEN );
        delete_transient( WP_WeChat_API::TRANSIENT_SERVER_IP );
    }
}

/**
 * 启动插件
 */
function wp_wechat_sync() {
    return WP_WeChat_Sync::instance();
}

add_action( 'plugins_loaded', 'wp_wechat_sync' );
