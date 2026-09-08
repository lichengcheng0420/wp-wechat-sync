<?php
/**
 * 微信公众号 API 接口封装类
 *
 * @package WP_WeChat_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WeChat_API {

    const TRANSIENT_TOKEN     = 'wp_wechat_access_token';
    const TRANSIENT_SERVER_IP = 'wp_wechat_server_egress_ip';

    /**
     * 获取配置的 AppID
     *
     * @return string
     */
    public static function get_appid() {
        $options = get_option( 'wp_wechat_sync_options', array() );
        return isset( $options['appid'] ) ? trim( $options['appid'] ) : '';
    }

    /**
     * 获取配置的 AppSecret
     *
     * @return string
     */
    public static function get_appsecret() {
        $options = get_option( 'wp_wechat_sync_options', array() );
        return isset( $options['appsecret'] ) ? trim( $options['appsecret'] ) : '';
    }

    /**
     * 获取 Access Token（带自动缓存与刷新）
     *
     * @param bool $force_refresh 是否强制刷新
     * @return string|WP_Error
     */
    public static function get_access_token( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_TOKEN );
            if ( ! empty( $cached ) ) {
                return $cached;
            }
        }

        $appid  = self::get_appid();
        $secret = self::get_appsecret();

        if ( empty( $appid ) || empty( $secret ) ) {
            return new WP_Error( 'missing_credentials', '微信公众号 AppID 或 AppSecret 尚未配置，请在设置页面填写。' );
        }

        $url = add_query_arg(
            array(
                'grant_type' => 'client_credential',
                'appid'      => $appid,
                'secret'     => $secret,
            ),
            'https://api.weixin.qq.com/cgi-bin/token'
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout'   => 15,
                'sslverify' => false,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', '请求微信接口失败：' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', '微信接口返回了非法的响应格式：' . substr( $body, 0, 100 ) );
        }

        if ( ! empty( $data['errcode'] ) ) {
            $msg = self::get_error_message( $data['errcode'], isset( $data['errmsg'] ) ? $data['errmsg'] : '' );
            return new WP_Error( 'wechat_error_' . $data['errcode'], $msg, $data );
        }

        if ( empty( $data['access_token'] ) ) {
            return new WP_Error( 'empty_token', '未能从微信返回结果中解析出 access_token。' );
        }

        $token      = $data['access_token'];
        $expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 7200;
        // 提前 200 秒过期以确保平滑更新
        $cache_time = max( 300, $expires_in - 200 );

        set_transient( self::TRANSIENT_TOKEN, $token, $cache_time );

        return $token;
    }

    /**
     * 上传正文中的图片至微信 CDN (永久 URL，不占素材库配额)
     * 接口：https://api.weixin.qq.com/cgi-bin/media/uploadimg
     *
     * @param string $image_path_or_url 本地物理路径或图片 URL
     * @return string|WP_Error 返回微信 CDN 图片 URL (mmbiz.qpic.cn) 或 WP_Error
     */
    public static function upload_content_image( $image_path_or_url ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $local_file = self::resolve_local_file( $image_path_or_url );
        $is_temp    = false;

        if ( is_wp_error( $local_file ) ) {
            // 尝试下载外部图片到本地临时目录
            $temp_file = self::download_external_image( $image_path_or_url );
            if ( is_wp_error( $temp_file ) ) {
                return $temp_file;
            }
            $local_file = $temp_file;
            $is_temp    = true;
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/media/uploadimg?access_token=' . rawurlencode( $token );
        $res = self::post_file( $url, $local_file );

        if ( $is_temp && file_exists( $local_file ) ) {
            @unlink( $local_file );
        }

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        if ( empty( $res['url'] ) ) {
            return new WP_Error( 'missing_url', '上传图片到微信 CDN 失败，未返回 URL。' );
        }

        return $res['url'];
    }

    /**
     * 上传封面图为永久素材获取 thumb_media_id
     * 接口：https://api.weixin.qq.com/cgi-bin/material/add_material?type=image
     *
     * @param string $image_path_or_url 本地物理路径或图片 URL
     * @return array|WP_Error 返回 array('media_id' => '...', 'url' => '...') 或 WP_Error
     */
    public static function upload_cover_image( $image_path_or_url ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $local_file = self::resolve_local_file( $image_path_or_url );
        $is_temp    = false;

        if ( is_wp_error( $local_file ) ) {
            $temp_file = self::download_external_image( $image_path_or_url );
            if ( is_wp_error( $temp_file ) ) {
                return $temp_file;
            }
            $local_file = $temp_file;
            $is_temp    = true;
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=' . rawurlencode( $token ) . '&type=image';
        $res = self::post_file( $url, $local_file );

        if ( $is_temp && file_exists( $local_file ) ) {
            @unlink( $local_file );
        }

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        if ( empty( $res['media_id'] ) ) {
            return new WP_Error( 'missing_media_id', '上传封面素材失败，未能获取 media_id。' );
        }

        return array(
            'media_id' => $res['media_id'],
            'url'      => isset( $res['url'] ) ? $res['url'] : '',
        );
    }

    /**
     * 添加草稿至微信公众号草稿箱
     * 接口：https://api.weixin.qq.com/cgi-bin/draft/add
     *
     * @param array $article 单篇图文数组
     * @return string|WP_Error 成功返回草稿 media_id
     */
    public static function add_draft( $article ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . rawurlencode( $token );

        $payload = array(
            'articles' => array(
                array(
                    'title'                 => $article['title'],
                    'author'                => isset( $article['author'] ) ? $article['author'] : '',
                    'digest'                => isset( $article['digest'] ) ? $article['digest'] : '',
                    'content'               => $article['content'],
                    'content_source_url'    => isset( $article['content_source_url'] ) ? $article['content_source_url'] : '',
                    'thumb_media_id'        => $article['thumb_media_id'],
                    'need_open_comment'     => ! empty( $article['need_open_comment'] ) ? 1 : 0,
                    'only_fans_can_comment' => ! empty( $article['only_fans_can_comment'] ) ? 1 : 0,
                ),
            ),
        );

        $json_data = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        $response = wp_remote_post(
            $url,
            array(
                'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body'      => $json_data,
                'timeout'   => 30,
                'sslverify' => false,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', '创建微信草稿请求失败：' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', '微信草稿接口返回异常：' . substr( $body, 0, 100 ) );
        }

        if ( ! empty( $data['errcode'] ) ) {
            $msg = self::get_error_message( $data['errcode'], isset( $data['errmsg'] ) ? $data['errmsg'] : '' );
            return new WP_Error( 'wechat_error_' . $data['errcode'], $msg, $data );
        }

        if ( empty( $data['media_id'] ) ) {
            return new WP_Error( 'missing_draft_media_id', '创建草稿成功但未返回 media_id。' );
        }

        return $data['media_id'];
    }

    /**
     * 将草稿公开发布（FreePublish 接口）
     * 接口：https://api.weixin.qq.com/cgi-bin/freepublish/submit
     *
     * @param string $media_id 草稿 media_id
     * @return array|WP_Error
     */
    public static function publish_draft( $media_id ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/freepublish/submit?access_token=' . rawurlencode( $token );

        $payload   = array( 'media_id' => $media_id );
        $json_data = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );

        $response = wp_remote_post(
            $url,
            array(
                'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body'      => $json_data,
                'timeout'   => 30,
                'sslverify' => false,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', '发布草稿请求失败：' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['errcode'] ) ) {
            $msg = self::get_error_message( $data['errcode'], isset( $data['errmsg'] ) ? $data['errmsg'] : '' );
            return new WP_Error( 'wechat_error_' . $data['errcode'], $msg, $data );
        }

        return $data;
    }

    /**
     * 上传单个文件到微信接口（支持 cURL 与 WP HTTP Boundary 回退）
     *
     * @param string $url       接口地址
     * @param string $file_path 本地文件绝对路径
     * @return array|WP_Error
     */
    private static function post_file( $url, $file_path ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'file_not_found', '上传文件不存在或无读取权限：' . esc_html( $file_path ) );
        }

        $mime_type = wp_check_filetype( $file_path )['type'];
        if ( empty( $mime_type ) ) {
            $mime_type = 'image/jpeg';
        }

        // 优先使用 PHP cURL 扩展进行 multipart 文件上传
        if ( function_exists( 'curl_init' ) ) {
            $ch = curl_init();
            $cfile = new CURLFile( $file_path, $mime_type, basename( $file_path ) );
            $post_data = array( 'media' => $cfile );

            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 40 );

            $result    = curl_exec( $ch );
            $curl_err  = curl_error( $ch );
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ( ! empty( $curl_err ) ) {
                return new WP_Error( 'curl_error', 'cURL 文件上传错误：' . $curl_err );
            }

            $data = json_decode( $result, true );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'invalid_json', '微信上传接口返回非预期格式：' . substr( $result, 0, 100 ) );
            }

            if ( ! empty( $data['errcode'] ) ) {
                $msg = self::get_error_message( $data['errcode'], isset( $data['errmsg'] ) ? $data['errmsg'] : '' );
                return new WP_Error( 'wechat_error_' . $data['errcode'], $msg, $data );
            }

            return $data;
        }

        // 回退：使用 WordPress HTTP API 构造 boundary
        $boundary = wp_generate_password( 24, false );
        $filename = basename( $file_path );
        $content  = file_get_contents( $file_path );

        $payload  = "--{$boundary}\r\n";
        $payload .= "Content-Disposition: form-data; name=\"media\"; filename=\"{$filename}\"\r\n";
        $payload .= "Content-Type: {$mime_type}\r\n\r\n";
        $payload .= $content . "\r\n";
        $payload .= "--{$boundary}--\r\n";

        $response = wp_remote_post(
            $url,
            array(
                'headers'   => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
                'body'      => $payload,
                'timeout'   => 40,
                'sslverify' => false,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', '微信上传接口返回格式异常：' . substr( $body, 0, 100 ) );
        }

        if ( ! empty( $data['errcode'] ) ) {
            $msg = self::get_error_message( $data['errcode'], isset( $data['errmsg'] ) ? $data['errmsg'] : '' );
            return new WP_Error( 'wechat_error_' . $data['errcode'], $msg, $data );
        }

        return $data;
    }

    /**
     * 将 URL 解析为本地物理文件路径（如果是本站媒体库文件）
     *
     * @param string $url 图片 URL 或物理路径
     * @return string|WP_Error
     */
    public static function resolve_local_file( $url ) {
        if ( file_exists( $url ) && is_file( $url ) ) {
            return $url;
        }

        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'];
        $basedir = $uploads['basedir'];

        // 统一协议以便比较
        $normalized_url     = preg_replace( '#^https?:#', '', $url );
        $normalized_baseurl = preg_replace( '#^https?:#', '', $baseurl );

        if ( strpos( $normalized_url, $normalized_baseurl ) === 0 ) {
            $rel_path   = substr( $normalized_url, strlen( $normalized_baseurl ) );
            $local_path = $basedir . $rel_path;
            if ( file_exists( $local_path ) ) {
                return $local_path;
            }
        }

        // 尝试通过 attachment_url_to_postid 解析
        $post_id = attachment_url_to_postid( $url );
        if ( $post_id ) {
            $attached_file = get_attached_file( $post_id );
            if ( $attached_file && file_exists( $attached_file ) ) {
                return $attached_file;
            }
        }

        return new WP_Error( 'not_local_file', '该地址非本站媒体库中的本地文件。' );
    }

    /**
     * 将外部图片下载为本地临时文件
     *
     * @param string $url 外部图片 URL
     * @return string|WP_Error 临时文件物理路径
     */
    public static function download_external_image( $url ) {
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp_file = download_url( $url, 20 );
        if ( is_wp_error( $tmp_file ) ) {
            return new WP_Error( 'download_failed', '无法下载外部图片：' . $tmp_file->get_error_message() . ' (' . esc_url( $url ) . ')' );
        }

        return $tmp_file;
    }

    /**
     * 获取当前服务器公网出口 IP（带 24 小时缓存）
     *
     * @param bool $force 是否强制重新检测
     * @return string
     */
    public static function get_server_egress_ip( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT_SERVER_IP );
            if ( ! empty( $cached ) ) {
                return $cached;
            }
        }

        $endpoints = array(
            'https://api.ipify.org',
            'https://icanhazip.com',
            'https://checkip.amazonaws.com',
        );

        $ip = '';
        foreach ( $endpoints as $endpoint ) {
            $response = wp_remote_get(
                $endpoint,
                array(
                    'timeout'   => 5,
                    'sslverify' => false,
                )
            );

            if ( ! is_wp_error( $response ) ) {
                $candidate = trim( wp_remote_retrieve_body( $response ) );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    $ip = $candidate;
                    break;
                }
            }
        }

        if ( empty( $ip ) && ! empty( $_SERVER['SERVER_ADDR'] ) && filter_var( $_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP ) ) {
            $ip = $_SERVER['SERVER_ADDR'];
        }

        if ( ! empty( $ip ) ) {
            set_transient( self::TRANSIENT_SERVER_IP, $ip, DAY_IN_SECONDS );
        }

        return $ip ? $ip : '未能自动获取（请使用命令 curl ifconfig.me 查看）';
    }

    /**
     * 将微信 API 错误码翻译为友好的中文说明与指引
     *
     * @param int    $errcode
     * @param string $errmsg
     * @return string
     */
    public static function get_error_message( $errcode, $errmsg = '' ) {
        $server_ip = self::get_server_egress_ip();

        $map = array(
            -1    => '微信系统繁忙，请稍候再试。',
            40001 => 'AppSecret 错误或不属于该 AppID，或者 access_token 无效。请检查后台设置中的 AppSecret。',
            40002 => '不合法的凭证类型。',
            40004 => '不合法的媒体文件类型。微信文章正文图片仅支持 JPG/PNG 等格式。',
            40005 => '不合法的文件类型。',
            40006 => '不合法的文件大小，图片文件需小于 10MB。',
            40007 => '不合法的媒体文件 id，封面图 thumb_media_id 无效。',
            40013 => '不合法的 AppID，请检查后台设置中的 AppID 是否填写正确。',
            40014 => '不合法的 access_token，请尝试重新保存设置以刷新令牌。',
            40164 => sprintf(
                '【IP 白名单错误】当前服务器公网出口 IP（检测为 %s）未加入微信公众号后台的 IP 白名单中！请前往“微信公众平台 -> 基本配置 -> IP 白名单”将该 IP 填入。',
                $server_ip
            ),
            41001 => '缺少 access_token 参数。',
            42001 => 'access_token 已过期，系统将自动尝试重新获取。',
            45009 => '接口调用频率超过微信单日限制。',
            45028 => '草稿箱已满或单日新增草稿数超限（微信限制每天最多 1000 篇）。',
            48001 => '【API 功能未授权】当前接口未获得调用权限，请在微信开发者平台“接口管理 / 开放能力”中检查该接口权限状态。',
            50002 => '用户受限，当前微信公众号账号状态可能异常或已被冻结。',
        );

        $custom_msg = isset( $map[ $errcode ] ) ? $map[ $errcode ] : '';

        if ( $custom_msg ) {
            return sprintf( '微信错误 [%d]：%s (原始信息: %s)', $errcode, $custom_msg, $errmsg );
        }

        return sprintf( '微信返回错误 [%d]：%s', $errcode, $errmsg );
    }
}
