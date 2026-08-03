<?php
/**
 * A-Blog 卸载清理
 *
 * 删除：abp_settings option + wp_abp_log 表 + wp_abp_fingerprints 表。
 * 注意：不删除任何文章/附件/分类（属于站点内容，保留）。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // 非卸载流程直接终止。
}

global $wpdb;

// 删除设置。
delete_option( 'abp_settings' );

// 删除日志表。
$log_table = $wpdb->prefix . 'abp_log';
$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 删除指纹表。
$fp_table = $wpdb->prefix . 'abp_fingerprints';
$wpdb->query( "DROP TABLE IF EXISTS {$fp_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
