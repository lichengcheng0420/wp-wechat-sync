<?php
/**
 * 微信公众号服务器配置与关键词实时自动回复服务
 *
 * 实现了微信公众平台“开发者服务器配置（开发模式）”的握手验证、
 * 消息接收、关键词智能匹配与 WordPress 实时内容提取回复。
 *
 * @package WP_WeChat_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WeChat_Message_Server {

    const REST_NAMESPACE = 'wp-wechat-sync/v1';
    const REST_ROUTE     = '/server';
    const QUERY_FLAG     = 'wechat_sync_server';

    /**
     * 初始化监听
     */
    public static function init() {
        // 注册 REST API 路由
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // 注册回退兼容路由 (针对没有开启伪静态或 REST API 被拦截的环境)
        add_action( 'parse_request', array( __CLASS__, 'handle_fallback_request' ) );
    }

    /**
     * 获取微信服务器接入的主 URL (REST API 方式)
     *
     * @return string
     */
    public static function get_server_url() {
        return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
    }

    /**
     * 获取微信服务器接入的回退兼容 URL (Query 参数方式)
     *
     * @return string
     */
    public static function get_fallback_url() {
        return add_query_arg( self::QUERY_FLAG, '1', home_url( '/' ) );
    }

    /**
     * 注册 REST API 路由
     */
    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'handle_rest_get' ),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'handle_rest_post' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    /**
     * 微信接口配置 URL 首次接入验证 (GET)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_rest_get( $request ) {
        $signature = $request->get_param( 'signature' );
        $timestamp = $request->get_param( 'timestamp' );
        $nonce     = $request->get_param( 'nonce' );
        $echostr   = $request->get_param( 'echostr' );

        if ( self::check_signature( $signature, $timestamp, $nonce ) ) {
            // 微信要求原样返回 echostr 纯文本，HTTP 状态码必须严格为 200
            status_header( 200 );
            if ( ! headers_sent() ) {
                header( 'Content-Type: text/plain; charset=utf-8' );
            }
            echo (string) $echostr;
            exit;
        }

        return new WP_REST_Response( 'Invalid signature', 403 );
    }

    /**
     * 微信用户消息推送处理 (POST)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_rest_post( $request ) {
        $signature = $request->get_param( 'signature' );
        $timestamp = $request->get_param( 'timestamp' );
        $nonce     = $request->get_param( 'nonce' );

        if ( ! self::check_signature( $signature, $timestamp, $nonce ) ) {
            return new WP_REST_Response( 'Invalid signature', 403 );
        }

        $raw_xml = $request->get_body();
        if ( empty( $raw_xml ) ) {
            $raw_xml = file_get_contents( 'php://input' );
        }

        $reply_xml = self::process_message( $raw_xml );

        if ( ! headers_sent() ) {
            header( 'Content-Type: application/xml; charset=utf-8' );
        }
        echo $reply_xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * 回退兼容模式请求处理 (支持 ?wechat_sync_server=1)
     *
     * @param WP_Query $wp
     */
    public static function handle_fallback_request( $wp ) {
        // 检查是否存在识别参数
        if ( ! isset( $_GET[ self::QUERY_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $method    = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        $signature = isset( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $timestamp = isset( $_GET['timestamp'] ) ? sanitize_text_field( wp_unslash( $_GET['timestamp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce     = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! self::check_signature( $signature, $timestamp, $nonce ) ) {
            status_header( 403 );
            nocache_headers();
            echo 'Invalid signature';
            exit;
        }

        if ( 'GET' === $method ) {
            $echostr = isset( $_GET['echostr'] ) ? sanitize_text_field( wp_unslash( $_GET['echostr'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo esc_html( $echostr );
            exit;
        }

        if ( 'POST' === $method ) {
            $raw_xml   = file_get_contents( 'php://input' );
            $reply_xml = self::process_message( $raw_xml );
            header( 'Content-Type: application/xml; charset=utf-8' );
            echo $reply_xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
    }

    /**
     * 校验微信公众平台签名
     *
     * 微信公众平台算法：
     * 1）将 token、timestamp、nonce 三个参数进行字典序排序
     * 2）将三个参数字符串拼接成一个字符串进行 sha1 加密
     * 3）加密后的字符串与 signature 对比
     *
     * @param string $signature
     * @param string $timestamp
     * @param string $nonce
     * @return bool
     */
    public static function check_signature( $signature, $timestamp, $nonce ) {
        if ( empty( $signature ) || empty( $timestamp ) || empty( $nonce ) ) {
            return false;
        }

        $options = get_option( defined( 'WP_WECHAT_SYNC_OPTION_KEY' ) ? WP_WECHAT_SYNC_OPTION_KEY : 'wp_wechat_sync_options', array() );
        $token   = ! empty( $options['message_server_token'] ) ? trim( $options['message_server_token'] ) : '';

        // 如果未配置 token，则不允许验证通过
        if ( empty( $token ) ) {
            return false;
        }

        $tmp_arr = array( $token, (string) $timestamp, (string) $nonce );
        sort( $tmp_arr, SORT_STRING );
        $tmp_str = implode( '', $tmp_arr );
        $calc_hash = sha1( $tmp_str );

        return hash_equals( $calc_hash, $signature );
    }

    /**
     * 解析微信 XML 消息并生成对应的被动回复 XML
     *
     * @param string $raw_xml
     * @return string
     */
    public static function process_message( $raw_xml ) {
        if ( empty( $raw_xml ) ) {
            return 'success';
        }

        // 安全解析 XML (防 XXE 注入)
        $msg = self::safe_parse_xml( $raw_xml );
        if ( ! $msg ) {
            return 'success';
        }

        $to_user   = (string) $msg->ToUserName;   // 开发者微信号 (公众平台)
        $from_user = (string) $msg->FromUserName; // 发送方 OpenID
        $msg_type  = (string) $msg->MsgType;

        $options = get_option( defined( 'WP_WECHAT_SYNC_OPTION_KEY' ) ? WP_WECHAT_SYNC_OPTION_KEY : 'wp_wechat_sync_options', array() );

        // 1. 处理关注事件 (subscribe)
        if ( 'event' === $msg_type && 'subscribe' === strtolower( (string) $msg->Event ) ) {
            $welcome = ! empty( $options['welcome_message'] ) ? trim( $options['welcome_message'] ) : '';
            if ( ! empty( $welcome ) ) {
                WP_WeChat_Sync_Logger::log( 0, '关注事件', 'server', 'success', '已向用户回复关注欢迎语' );
                return self::build_text_reply( $from_user, $to_user, $welcome );
            }
            return 'success';
        }

        // 2. 处理文本关键词匹配 (text)
        if ( 'text' === $msg_type ) {
            $content = trim( (string) $msg->Content );

            $rules = ! empty( $options['keyword_rules'] ) && is_array( $options['keyword_rules'] ) ? $options['keyword_rules'] : array();

            foreach ( $rules as $rule ) {
                if ( empty( $rule['keywords'] ) ) {
                    continue;
                }

                if ( self::match_keyword( $content, $rule['keywords'] ) ) {
                    $reply = self::handle_rule_reply( $rule, $from_user, $to_user );
                    if ( ! empty( $reply ) ) {
                        WP_WeChat_Sync_Logger::log(
                            isset( $rule['post_id'] ) ? (int) $rule['post_id'] : 0,
                            '关键词回复: ' . mb_substr( $content, 0, 20, 'UTF-8' ),
                            'server',
                            'success',
                            sprintf( '命中关键词规则，回复类型: %s', $rule['reply_type'] ?? 'text_link' )
                        );
                        return $reply;
                    }
                }
            }

            // 3. 兜底默认回复 (未命中任何关键词)
            $default_text = ! empty( $options['default_reply_text'] ) ? trim( $options['default_reply_text'] ) : '';
            if ( ! empty( $default_text ) ) {
                return self::build_text_reply( $from_user, $to_user, $default_text );
            }
        }

        // 微信规范：若不回复任何消息，直接返回 success 或空字符串，避免公众号报错
        return 'success';
    }

    /**
     * 匹配关键词
     *
     * 支持以英文逗号、中文逗号、分号或竖线分隔多个关键词，大小写不敏感
     *
     * @param string $input
     * @param string $keywords_str
     * @return bool
     */
    public static function match_keyword( $input, $keywords_str ) {
        $input = mb_strtolower( trim( $input ), 'UTF-8' );
        if ( '' === $input ) {
            return false;
        }

        $keywords = preg_split( '/[,，;；|]/u', $keywords_str );
        if ( ! is_array( $keywords ) ) {
            return false;
        }

        foreach ( $keywords as $kw ) {
            $kw = mb_strtolower( trim( $kw ), 'UTF-8' );
            if ( '' === $kw ) {
                continue;
            }

            // 包含匹配 (用户发送的内容包含关键词，或者完全一致)
            if ( false !== mb_strpos( $input, $kw, 0, 'UTF-8' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 根据规则提取关联文章并组装回复内容
     *
     * @param array  $rule
     * @param string $to_user   接收方 (用户的 OpenID)
     * @param string $from_user 发送方 (公众号的原始 ID)
     * @return string XML 回复字符串
     */
    public static function handle_rule_reply( $rule, $to_user, $from_user ) {
        $post_id    = isset( $rule['post_id'] ) ? (int) $rule['post_id'] : 0;
        $reply_type = isset( $rule['reply_type'] ) ? $rule['reply_type'] : 'text_link';
        $prefix     = isset( $rule['prefix'] ) ? trim( $rule['prefix'] ) : '';
        $suffix     = isset( $rule['suffix'] ) ? trim( $rule['suffix'] ) : '';

        // 如果未绑定文章，或者仅配置了固定纯文本
        if ( 'custom_text' === $reply_type || empty( $post_id ) ) {
            $text = ! empty( $rule['custom_text'] ) ? trim( $rule['custom_text'] ) : '暂无数据';
            return self::build_text_reply( $to_user, $from_user, $text );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'trash' === $post->post_status ) {
            return self::build_text_reply( $to_user, $from_user, '关联文章暂不可用或已删除。' );
        }

        $title      = get_the_title( $post );
        $permalink  = get_permalink( $post->ID );
        $clean_text = self::extract_clean_text( $post->post_content );

        // 模式 A：单图文卡片 (可直接转发至微信群，支持标题、封面图、链接跳转)
        if ( 'news' === $reply_type ) {
            $cover_url   = self::get_post_cover_url( $post );
            $description = ! empty( $post->post_excerpt ) ? wp_strip_all_tags( $post->post_excerpt ) : mb_substr( $clean_text, 0, 100, 'UTF-8' );

            return self::build_news_reply( $to_user, $from_user, $title, $description, $cover_url, $permalink );
        }

        // 模式 B：纯文本 (不带网页链接)
        if ( 'text_only' === $reply_type ) {
            $lines = array();
            if ( ! empty( $prefix ) ) {
                $lines[] = $prefix;
            }
            $lines[] = sprintf( "【%s】", $title );
            $lines[] = '';
            $lines[] = $clean_text;
            if ( ! empty( $suffix ) ) {
                $lines[] = '';
                $lines[] = $suffix;
            }
            return self::build_text_reply( $to_user, $from_user, implode( "\n", $lines ) );
        }

        // 模式 C (默认推荐)：文本内容 + 原网页实时刷新直达链接 (个人号完全支持，用户可直接复制转发群，群友点击链接打开网页查看最新状态)
        $lines = array();
        if ( ! empty( $prefix ) ) {
            $lines[] = $prefix;
            $lines[] = '';
        }

        $lines[] = sprintf( "📌 %s", $title );
        $lines[] = "────────────────";
        $lines[] = $clean_text;
        $lines[] = "────────────────";
        $lines[] = "🔗 直达原网页 (实时刷新)：";
        $lines[] = sprintf( '<a href="%s">%s</a>', esc_url( $permalink ), esc_url( $permalink ) );

        if ( ! empty( $suffix ) ) {
            $lines[] = '';
            $lines[] = $suffix;
        }

        return self::build_text_reply( $to_user, $from_user, implode( "\n", $lines ) );
    }

    /**
     * 将 WordPress HTML 正文转换为适合在微信聊天界面清晰阅读的文本 (保留排版换行)
     *
     * @param string $content
     * @return string
     */
    public static function extract_clean_text( $content ) {
        if ( empty( $content ) ) {
            return '（文章暂无正文内容）';
        }

        // 1. 去除 shortcode
        $text = strip_shortcodes( $content );

        // 2. 去除 script 与 style
        $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

        // 3. 将常见块级换行标签转换为标准的独立换行符 \n
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $text );
        $text = preg_replace( '/<\/(p|div|h[1-6]|tr)>/i', "\n", $text );
        $text = preg_replace( '/<li[^>]*>/i', "• ", $text );
        $text = preg_replace( '/<\/li>/i', "\n", $text );

        // 4. 清除所有剩余 HTML 标签
        $text = wp_strip_all_tags( $text );

        // 5. 实体解码 (如 &nbsp; &amp; &lt; 等)
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // 6. 统一换行符并消除多余空行 (最多连续保留一个空行)
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        $text = preg_replace( "/[ \t]+/", ' ', $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        return trim( $text );
    }

    /**
     * 获取文章封面图链接
     *
     * @param WP_Post $post
     * @return string
     */
    public static function get_post_cover_url( $post ) {
        // 1. 特色图像
        if ( has_post_thumbnail( $post->ID ) ) {
            $img_url = get_the_post_thumbnail_url( $post->ID, 'large' );
            if ( ! empty( $img_url ) ) {
                return $img_url;
            }
        }

        // 2. 正文第一张图
        if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $match ) ) {
            return $match[1];
        }

        // 3. 全局默认封面
        $options = get_option( defined( 'WP_WECHAT_SYNC_OPTION_KEY' ) ? WP_WECHAT_SYNC_OPTION_KEY : 'wp_wechat_sync_options', array() );
        if ( ! empty( $options['default_cover_image'] ) ) {
            return $options['default_cover_image'];
        }

        return '';
    }

    /**
     * 组装被动回复文本消息 XML
     *
     * @param string $to_user   接收方 (用户的 OpenID)
     * @param string $from_user 发送方 (公众号的微信号)
     * @param string $content   回复文本内容 (支持 <a href="...">超链接)
     * @return string
     */
    public static function build_text_reply( $to_user, $from_user, $content ) {
        $xml  = "<xml>\n";
        $xml .= sprintf( "<ToUserName><![CDATA[%s]]></ToUserName>\n", $to_user );
        $xml .= sprintf( "<FromUserName><![CDATA[%s]]></FromUserName>\n", $from_user );
        $xml .= sprintf( "<CreateTime>%d</CreateTime>\n", time() );
        $xml .= "<MsgType><![CDATA[text]]></MsgType>\n";
        $xml .= sprintf( "<Content><![CDATA[%s]]></Content>\n", $content );
        $xml .= "</xml>";
        return $xml;
    }

    /**
     * 组装被动回复单图文消息 XML
     *
     * @param string $to_user     接收方 (用户的 OpenID)
     * @param string $from_user   发送方 (公众号的微信号)
     * @param string $title       标题
     * @param string $description 描述摘要
     * @param string $pic_url     封面图片 URL
     * @param string $url         点击跳转的目标 URL
     * @return string
     */
    public static function build_news_reply( $to_user, $from_user, $title, $description, $pic_url, $url ) {
        $xml  = "<xml>\n";
        $xml .= sprintf( "<ToUserName><![CDATA[%s]]></ToUserName>\n", $to_user );
        $xml .= sprintf( "<FromUserName><![CDATA[%s]]></FromUserName>\n", $from_user );
        $xml .= sprintf( "<CreateTime>%d</CreateTime>\n", time() );
        $xml .= "<MsgType><![CDATA[news]]></MsgType>\n";
        $xml .= "<ArticleCount>1</ArticleCount>\n";
        $xml .= "<Articles>\n";
        $xml .= "<item>\n";
        $xml .= sprintf( "<Title><![CDATA[%s]]></Title>\n", $title );
        $xml .= sprintf( "<Description><![CDATA[%s]]></Description>\n", $description );
        $xml .= sprintf( "<PicUrl><![CDATA[%s]]></PicUrl>\n", $pic_url );
        $xml .= sprintf( "<Url><![CDATA[%s]]></Url>\n", $url );
        $xml .= "</item>\n";
        $xml .= "</Articles>\n";
        $xml .= "</xml>";
        return $xml;
    }

    /**
     * 安全解析 XML 字符串 (防 XXE 注入)
     *
     * @param string $xml_string
     * @return SimpleXMLElement|false
     */
    public static function safe_parse_xml( $xml_string ) {
        if ( empty( $xml_string ) ) {
            return false;
        }

        // 避免实体扩展攻击
        $disable_entities = true;
        if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
            // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
            $disable_entities = libxml_disable_entity_loader( true );
        }

        $prev_use_errors = libxml_use_internal_errors( true );

        try {
            $xml = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
        } catch ( Exception $e ) {
            $xml = false;
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $prev_use_errors );

        if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
            // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
            libxml_disable_entity_loader( $disable_entities );
        }

        return $xml;
    }
}
