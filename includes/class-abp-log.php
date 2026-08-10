<?php
/**
 * A-Blog 任务日志表（wp_abp_log）
 *
 * 记录 Python 侧提交任务在本插件内的全生命周期：接收、查重、建文、传图、发布、失败。
 * 数据同时供后台设置页「任务日志」区块展示（最近 50 条）。
 *
 * 表结构（与总纲 3.2 契约一致）：
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   task_id   VARCHAR(64)   任务 ID（如 20260803-stock-001）
 *   `column`  VARCHAR(32)   栏目 stock|tech|reading|book
 *   action    VARCHAR(64)   动作名（receive|dedup|create|image|publish|error 等）
 *   status    VARCHAR(16)   ok|fail|skip
 *   message   TEXT          中文说明
 *   created_at DATETIME     记录时间
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接访问则终止。
}

class ABP_Log {

	/**
	 * 建表（激活钩子调用，dbDelta 幂等）。
	 * 注意：dbDelta 要求字段定义与 WordPress 规范一致（每行两个空格缩进、类型大写）。
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'abp_log';
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id VARCHAR(64) NOT NULL DEFAULT '',
			`column` VARCHAR(32) NOT NULL DEFAULT '',
			action VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'ok',
			message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY task_id (task_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * 写入一条日志。
	 *
	 * @param string $task_id 任务 ID（可为空字符串）。
	 * @param string $column  栏目（stock|tech|reading|book）。
	 * @param string $action  动作名。
	 * @param string $status  ok|fail|skip。
	 * @param string $message 中文说明。
	 * @return int|false 插入行 ID，失败返回 false。
	 */
	public static function write( $task_id = '', $column = '', $action = '', $status = 'ok', $message = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'abp_log';
		$data       = array(
			'task_id'    => sanitize_text_field( (string) $task_id ),
			'column'     => sanitize_text_field( (string) $column ),
			'action'     => sanitize_text_field( (string) $action ),
			'status'     => in_array( (string) $status, array( 'ok', 'fail', 'skip' ), true ) ? (string) $status : 'ok',
			'message'    => sanitize_textarea_field( (string) $message ),
			'created_at' => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( $table_name, $data, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );

		// 日志表意外损坏时静默自愈重建，避免打断主流程。
		if ( false === $result && ! empty( $wpdb->last_error ) && false !== strpos( $wpdb->last_error, 'abp_log' ) ) {
			self::create_table();
			$result = $wpdb->insert( $table_name, $data, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}

		return $result;
	}

	/**
	 * 查询最近 N 条日志。
	 *
	 * @param int $limit 条数，默认 50。
	 * @return array 日志行数组（含 id/task_id/column/action/status/message/created_at）。
	 */
	public static function get_recent( $limit = 50 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'abp_log';
		$limit      = max( 1, min( (int) $limit, 500 ) );

		// 表不存在时返回空数组，不抛错。
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 清理过期日志（保留天数，可选调用；后台不强制）。
	 *
	 * @param int $days 保留天数，默认 30。
	 * @return int 删除行数。
	 */
	public static function purge_old( $days = 30 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'abp_log';
		$days       = max( 1, (int) $days );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * 清空全部任务日志（后台「清空日志」按钮）。
	 *
	 * @return int 删除行数。
	 */
	public static function clear_all() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'abp_log';
		return (int) $wpdb->query( "DELETE FROM {$table_name}" );
	}
}

/**
 * 全局便捷函数：写日志。
 *
 * @param string $task_id 任务 ID。
 * @param string $column  栏目。
 * @param string $action  动作名。
 * @param string $status  ok|fail|skip。
 * @param string $message 中文说明。
 * @return int|false
 */
function abp_log_write( $task_id = '', $column = '', $action = '', $status = 'ok', $message = '' ) {
	return ABP_Log::write( $task_id, $column, $action, $status, $message );
}

/**
 * 全局便捷函数：查询最近日志。
 *
 * @param int $limit 条数。
 * @return array
 */
function abp_log_get_recent( $limit = 50 ) {
	return ABP_Log::get_recent( $limit );
}
