<?php
/**
 * 微信公众号同步日志记录器
 *
 * @package WP_WeChat_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WeChat_Sync_Logger {

    const OPTION_KEY = 'wp_wechat_sync_logs';
    const MAX_LOGS   = 100;

    /**
     * 记录一条同步日志
     *
     * @param int    $post_id    文章 ID (测试连接等无文章场景可传 0)
     * @param string $post_title 文章标题
     * @param string $type       类型：'auto' (自动同步), 'manual' (手动同步), 'test' (连接测试)
     * @param string $status     状态：'success' (成功), 'error' (失败), 'info' (信息)
     * @param string $message    主要描述
     * @param mixed  $details    详细信息（错误对象、返回数据等）
     */
    public static function log( $post_id, $post_title, $type, $status, $message, $details = null ) {
        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        $entry = array(
            'id'         => uniqid( 'log_', true ),
            'time'       => current_time( 'Y-m-d H:i:s' ),
            'post_id'    => (int) $post_id,
            'post_title' => sanitize_text_field( $post_title ),
            'type'       => sanitize_key( $type ),
            'status'     => sanitize_key( $status ),
            'message'    => sanitize_text_field( $message ),
            'details'    => $details ? ( is_scalar( $details ) ? (string) $details : wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) ) : '',
        );

        array_unshift( $logs, $entry );

        // 保持日志数量在限制之内
        if ( count( $logs ) > self::MAX_LOGS ) {
            $logs = array_slice( $logs, 0, self::MAX_LOGS );
        }

        update_option( self::OPTION_KEY, $logs, false );
    }

    /**
     * 获取最近的日志记录
     *
     * @param int $limit 数量限制
     * @return array
     */
    public static function get_logs( $limit = 50 ) {
        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }
        return array_slice( $logs, 0, $limit );
    }

    /**
     * 清空所有日志
     *
     * @return bool
     */
    public static function clear_logs() {
        return delete_option( self::OPTION_KEY );
    }
}
