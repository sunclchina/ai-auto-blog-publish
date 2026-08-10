<?php
/**
 * class-abp-queue.php — 任务队列 + 备用选题池数据层（WordPress 侧）。
 *
 * v1.5.0 起：任务与选题池数据全部存 WP 数据库（wp_abp_tasks / wp_abp_pool），
 * 插件在任何 WordPress 环境安装即用，后台操作台直接读写本库（零代理、零外联）；
 * Python 生成引擎通过 REST API 与插件协作（拉任务/拉池子/建任务/入池/回报状态）。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Queue {

	const TASKS = 'abp_tasks';
	const POOL  = 'abp_pool';

	/**
	 * 建表（dbDelta，幂等）。激活钩子调用。
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t = $wpdb->prefix . self::TASKS;
		$p = $wpdb->prefix . self::POOL;
		$c = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$t} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id VARCHAR(64) NOT NULL DEFAULT '',
			column_name VARCHAR(32) NOT NULL DEFAULT '',
			topic VARCHAR(2000) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			topic_candidates TEXT NULL,
			post_id BIGINT(20) UNSIGNED DEFAULT NULL,
			error TEXT NULL,
			run_now TINYINT(1) NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			publish_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY uk_task_id (task_id),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$c};" );

		dbDelta( "CREATE TABLE {$p} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			column_name VARCHAR(32) NOT NULL DEFAULT '',
			topic VARCHAR(2000) NOT NULL DEFAULT '',
			source VARCHAR(16) NOT NULL DEFAULT 'manual',
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			used_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status)
		) {$c};" );
	}

	/* ================= 备用选题池 ================= */

	/**
	 * 池子列表：排队中（按 sort_order）+ 最近已用。
	 *
	 * @return array{ok:bool, topics:array, recent_used:array}
	 */
	public static function pool_list() {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE status=%s ORDER BY sort_order ASC, id ASC", 'queued' ),
			ARRAY_A
		);
		$used = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE status=%s ORDER BY used_at DESC, id DESC LIMIT 20", 'used' ),
			ARRAY_A
		);
		return array( 'ok' => true, 'count' => count( $rows ), 'topics' => $rows, 'recent_used' => $used );
	}

	/**
	 * 加入池子（校验：长度 + 池内查重）。返回行。
	 *
	 * @param string $column 栏目码。
	 * @param string $topic  选题文本。
	 * @param string $source manual|ai|local。
	 * @return array{ok:bool, item?:array, error?:string}
	 */
	public static function pool_add( $column, $topic, $source = 'manual' ) {
		global $wpdb;
		$topic = trim( (string) $topic );
		if ( '' === $topic ) {
			return array( 'ok' => false, 'error' => '选题内容为空' );
		}
		if ( mb_strlen( $topic ) > 2000 ) {
			return array( 'ok' => false, 'error' => '选题过长（最多 2000 字）' );
		}
		$column = in_array( (string) $column, array( 'stock', 'tech', 'reading', 'book', 'industry' ), true ) ? (string) $column : '';
		$source = in_array( (string) $source, array( 'manual', 'ai', 'local' ), true ) ? (string) $source : 'manual';
		$t = $wpdb->prefix . self::POOL;

		$dup = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE status=%s AND topic=%s LIMIT 1", 'queued', $topic
		) );
		if ( $dup ) {
			return array( 'ok' => false, 'error' => '池中已存在相同选题' );
		}

		$next = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(sort_order),0)+1 FROM {$t} WHERE status=%s", 'queued'
		) );
		$wpdb->insert( $t, array(
			'column_name' => $column ? $column : 'tech',
			'topic'       => $topic,
			'source'      => $source,
			'status'      => 'queued',
			'sort_order'  => $next,
			'created_at'  => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%s', '%d', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return array( 'ok' => false, 'error' => '写入失败' );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $id ), ARRAY_A );
		return array( 'ok' => true, 'item' => $row );
	}

	/**
	 * 服务批量入池（预选题多余候选等）。返回成功数。
	 *
	 * @param array $items 每项 {column, topic, source?}。
	 * @return array{ok:bool, added:int, errors:array}
	 */
	public static function pool_add_batch( $items ) {
		$added = 0;
		$errors = array();
		foreach ( (array) $items as $it ) {
			if ( ! is_array( $it ) || empty( $it['topic'] ) ) {
				continue;
			}
			$r = self::pool_add(
				isset( $it['column'] ) ? $it['column'] : '',
				$it['topic'],
				isset( $it['source'] ) ? $it['source'] : 'ai'
			);
			if ( $r['ok'] ) {
				$added++;
			} else {
				$errors[] = $r['error'];
			}
		}
		return array( 'ok' => true, 'added' => $added, 'errors' => $errors );
	}

	/**
	 * 编辑池子项。
	 *
	 * @param int    $id     池子 ID。
	 * @param string $topic  新选题。
	 * @param string $column 新栏目（可空=不变）。
	 * @return array{ok:bool, item?:array, error?:string}
	 */
	public static function pool_update( $id, $topic, $column = '' ) {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		$topic = trim( (string) $topic );
		if ( '' === $topic ) {
			return array( 'ok' => false, 'error' => '选题内容为空' );
		}
		$data = array( 'topic' => $topic );
		$fmt  = array( '%s' );
		if ( '' !== (string) $column && in_array( (string) $column, array( 'stock', 'tech', 'reading', 'book', 'industry' ), true ) ) {
			$data['column_name'] = (string) $column;
			$fmt[] = '%s';
		}
		$wpdb->update( $t, $data, array( 'id' => (int) $id ), $fmt, array( '%d' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => '条目不存在' );
		}
		return array( 'ok' => true, 'item' => $row );
	}

	/**
	 * 删除池子项（仅排队中）。
	 *
	 * @param int $id 池子 ID。
	 * @return array{ok:bool, error?:string}
	 */
	public static function pool_delete( $id ) {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => '条目不存在' );
		}
		if ( 'used' === $row['status'] ) {
			return array( 'ok' => false, 'error' => '已用条目不可删除' );
		}
		$wpdb->delete( $t, array( 'id' => (int) $id ), array( '%d' ) );
		return array( 'ok' => true );
	}

	/**
	 * 重排池子（按 ids 顺序重写 sort_order）。
	 *
	 * @param array $ids ID 数组（新顺序）。
	 * @return array{ok:bool, updated:int, count:int}
	 */
	public static function pool_reorder( $ids ) {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		$updated = 0;
		$i = 1;
		foreach ( (array) $ids as $id ) {
			$n = (int) $wpdb->update( $t, array( 'sort_order' => $i++ ), array( 'id' => (int) $id, 'status' => 'queued' ), array( '%d' ), array( '%d', '%s' ) );
			if ( false !== $n ) {
				$updated++;
			}
		}
		return array( 'ok' => true, 'updated' => $updated, 'count' => count( (array) $ids ) );
	}

	/**
	 * 一键清空（删全部排队中，保留已用历史）。
	 *
	 * @return array{ok:bool, cleared:int}
	 */
	public static function pool_clear() {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE status=%s", 'queued' ) );
		return array( 'ok' => true, 'cleared' => $n );
	}

	/**
	 * 智能填充（插件本地）：从内置素材库随机取题入池。stock 栏目不填充
	 * （复盘标题=日期+固定格式，不设备选题）。池上限 20，自动去重。
	 *
	 * @param string   $column 栏目码（空=全部非 stock 栏目）。
	 * @param int|null $n      每栏目条数（默认 1，上限 10）。
	 * @return array{ok:bool, added:int, note?:string}
	 */
	public static function pool_fill( $column = '', $n = null ) {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		if ( null === $n ) {
			$n = 1;
		}
		$n = max( 1, min( (int) $n, 10 ) );
		$limit = 20;
		$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status=%s", 'queued' ) );
		$room = $limit - $queued;
		if ( $room <= 0 ) {
			return array( 'ok' => true, 'added' => 0, 'note' => '池子已满（上限 20）' );
		}
		$cols = '' !== (string) $column ? array( (string) $column ) : array( 'tech', 'reading', 'book', 'industry' );
		$added = 0;
		foreach ( $cols as $c ) {
			if ( $added >= $room ) {
				break;
			}
			$topics = ABP_Materials::pick( $c, min( $n, $room - $added ) );
			foreach ( $topics as $tpc ) {
				$r = self::pool_add( $c, $tpc, 'local' );
				if ( $r['ok'] ) {
					$added++;
				}
			}
		}
		return array( 'ok' => true, 'added' => $added );
	}

	/**
	 * 把池子项标记为已用（服务/列入计划后调用）。
	 *
	 * @param int $id 池子 ID。
	 * @return void
	 */
	public static function pool_mark_used( $id ) {
		global $wpdb;
		$t = $wpdb->prefix . self::POOL;
		$wpdb->update( $t, array( 'status' => 'used', 'used_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/* ================= 任务队列 ================= */

	/**
	 * 任务视图（topic_candidates JSON 反序列化）。
	 *
	 * @param array $row 原始行。
	 * @return array
	 */
	private static function task_view( $row ) {
		$view = $row;
		$view['topic_candidates'] = array();
		if ( ! empty( $row['topic_candidates'] ) ) {
			$decoded = json_decode( (string) $row['topic_candidates'], true );
			if ( is_array( $decoded ) ) {
				$view['topic_candidates'] = $decoded;
			}
		}
		unset( $view['topic_candidates_raw'] );
		return $view;
	}

	/**
	 * 建任务（服务或列入计划用）。task_id 已存在则返回旧任务（幂等）。
	 *
	 * @param string      $task_id    YYYYMMDD-column-NNN。
	 * @param string      $column     栏目码。
	 * @param string      $topic      选题（可空）。
	 * @param array       $candidates 候选列表。
	 * @param string|null $publish_at 计划发布时间（可空）。
	 * @return array{ok:bool, task?:array, reused?:bool, error?:string}
	 */
	public static function task_create( $task_id, $column, $topic = '', $candidates = array(), $publish_at = null ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$task_id = sanitize_text_field( (string) $task_id );
		if ( '' === $task_id || ! preg_match( '/^[0-9]{8}-[a-z]+-[0-9]{3}$/', $task_id ) ) {
			return array( 'ok' => false, 'error' => 'task_id 格式应为 YYYYMMDD-column-NNN' );
		}

		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE task_id=%s", $task_id ), ARRAY_A );
		if ( $old ) {
			return array( 'ok' => true, 'reused' => true, 'task' => self::task_view( $old ) );
		}

		$column = in_array( (string) $column, array( 'stock', 'tech', 'reading', 'book', 'industry' ), true ) ? (string) $column : 'tech';
		$now = current_time( 'mysql' );
		$next = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(sort_order),0)+1 FROM {$t} WHERE status IN (%s,%s)", 'queued', 'generating'
		) );
		$wpdb->insert( $t, array(
			'task_id'          => $task_id,
			'column_name'      => $column,
			'topic'            => mb_substr( (string) $topic, 0, 2000 ),
			'status'           => 'queued',
			'topic_candidates' => is_array( $candidates ) && $candidates ? wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE ) : null,
			'sort_order'       => $next,
			'publish_at'       => $publish_at ? $publish_at : null,
			'created_at'       => $now,
			'updated_at'       => $now,
		), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE task_id=%s", $task_id ), ARRAY_A );
		return array( 'ok' => true, 'task' => self::task_view( $row ) );
	}

	/**
	 * 按日期查任务（默认今天）。
	 *
	 * @param string|null $date YYYY-MM-DD。
	 * @return array{ok:bool, date:string, tasks:array, count:int}
	 */
	public static function task_list_by_date( $date = null ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		// 时区修复：current_time('timestamp') 已是本地时间戳，再 +gmt_offset 会多偏 8h
		// （本地 21:51 → 变成次日凌晨 → 后台默认查「明天」任务 → 任务/已完成全空）。
		// 直接用 WP 本地日期格式。
		$date = $date ? $date : current_time( 'Y-m-d' );
		$like = $wpdb->esc_like( str_replace( '-', '', $date ) ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE task_id LIKE %s ORDER BY sort_order ASC, id ASC", $like
		), ARRAY_A );
		$tasks = array_map( array( __CLASS__, 'task_view' ), $rows );
		return array( 'ok' => true, 'date' => $date, 'tasks' => $tasks, 'count' => count( $tasks ) );
	}

	/**
	 * 查单个任务。
	 *
	 * @param string $task_id 任务 ID。
	 * @return array|null
	 */
	public static function task_get( $task_id ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE task_id=%s", $task_id ), ARRAY_A );
		return $row ? self::task_view( $row ) : null;
	}

	/**
	 * 服务拉取待执行任务：run_now 优先，其次 queued（按 sort_order）。
	 *
	 * @param int $limit 数量上限。
	 * @return array
	 */
	public static function task_claim_queued( $limit = 5 ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE status=%s AND run_now=1 ORDER BY updated_at ASC LIMIT %d",
			'queued', max( 1, (int) $limit )
		), ARRAY_A );
		if ( ! $rows ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE status=%s ORDER BY sort_order ASC, id ASC LIMIT %d",
				'queued', max( 1, (int) $limit )
			), ARRAY_A );
		}
		return array_map( array( __CLASS__, 'task_view' ), $rows );
	}

	/**
	 * 服务回报状态。
	 *
	 * @param string $task_id 任务 ID。
	 * @param string $status  queued|generating|ready|published|failed|skipped。
	 * @param int    $post_id 发布后的文章 ID（可空）。
	 * @param string $error   错误信息（可空）。
	 * @return array{ok:bool, task?:array, error?:string}
	 */
	public static function task_update_status( $task_id, $status, $post_id = null, $error = '' ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$row = self::task_get( $task_id );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => '任务不存在' );
		}
		$allowed = array( 'queued', 'generating', 'ready', 'published', 'failed', 'skipped' );
		if ( ! in_array( (string) $status, $allowed, true ) ) {
			return array( 'ok' => false, 'error' => '非法状态：' . $status );
		}
		$data = array(
			'status'     => (string) $status,
			'run_now'    => 0,
			'updated_at' => current_time( 'mysql' ),
		);
		$fmt = array( '%s', '%d', '%s' );
		if ( null !== $post_id ) {
			$data['post_id'] = (int) $post_id;
			$fmt[] = '%d';
		}
		if ( '' !== (string) $error ) {
			$data['error'] = mb_substr( (string) $error, 0, 2000 );
			$fmt[] = '%s';
		}
		$wpdb->update( $t, $data, array( 'task_id' => $task_id ), $fmt, array( '%s' ) );
		return array( 'ok' => true, 'task' => self::task_get( $task_id ) );
	}

	/**
	 * 请求立即执行（UI 按钮）：run_now=1，服务轮询/拉取时优先执行。
	 *
	 * @param string $task_id 任务 ID。
	 * @return array{ok:bool, task?:array, error?:string}
	 */
	public static function task_request_run( $task_id ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$row = self::task_get( $task_id );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => '任务不存在' );
		}
		if ( in_array( $row['status'], array( 'published', 'failed', 'skipped' ), true ) ) {
			return array( 'ok' => false, 'error' => '任务已结束（' . $row['status'] . '），不可再执行' );
		}
		$wpdb->update( $t, array( 'run_now' => 1, 'updated_at' => current_time( 'mysql' ) ), array( 'task_id' => $task_id ), array( '%d', '%s' ), array( '%s' ) );
		return array( 'ok' => true, 'async' => true, 'task' => self::task_get( $task_id ) );
	}

	/**
	 * 删除任务（仅 queued/skipped）。
	 *
	 * @param string $task_id 任务 ID。
	 * @return array{ok:bool, error?:string}
	 */
	public static function task_delete( $task_id ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$row = self::task_get( $task_id );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => '任务不存在' );
		}
		if ( ! in_array( $row['status'], array( 'queued', 'skipped' ), true ) ) {
			return array( 'ok' => false, 'error' => '任务状态 ' . $row['status'] . ' 不可删除（仅 queued/skipped）' );
		}
		$wpdb->delete( $t, array( 'task_id' => $task_id ), array( '%s' ) );
		return array( 'ok' => true );
	}

	/**
	 * 清空任务（删全部 queued/skipped，已发布/生成中保留）。
	 *
	 * @return array{ok:bool, cleared:int}
	 */
	public static function task_clear() {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$n = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE status IN (%s,%s)", 'queued', 'skipped'
		) );
		return array( 'ok' => true, 'cleared' => $n );
	}

	/**
	 * 指定候选 / 指定选题。
	 *
	 * @param string      $task_id 任务 ID。
	 * @param string|null $topic   直接指定选题。
	 * @param int|null    $index   采用第几个候选（1 起）。
	 * @return array{ok:bool, task?:array, error?:string}
	 */
	public static function task_pick( $task_id, $topic = null, $index = null ) {
		global $wpdb;
		$t = $wpdb->prefix . self::TASKS;
		$row = self::task_get( $task_id );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => '任务不存在' );
		}
		if ( ! in_array( $row['status'], array( 'queued', 'generating' ), true ) ) {
			return array( 'ok' => false, 'error' => '任务状态 ' . $row['status'] . ' 不可改选题' );
		}
		$new_topic = '';
		if ( null !== $topic && '' !== trim( (string) $topic ) ) {
			$new_topic = trim( (string) $topic );
		} elseif ( null !== $index ) {
			$cands = (array) $row['topic_candidates'];
			$idx = (int) $index - 1;
			if ( $idx < 0 || $idx >= count( $cands ) ) {
				return array( 'ok' => false, 'error' => '候选序号越界（共 ' . count( $cands ) . ' 个）' );
			}
			$c = $cands[ $idx ];
			$new_topic = is_array( $c ) ? ( isset( $c['topic'] ) ? $c['topic'] : '' ) : (string) $c;
		} else {
			return array( 'ok' => false, 'error' => '需提供 topic 或 index' );
		}
		if ( '' === $new_topic ) {
			return array( 'ok' => false, 'error' => '选题为空' );
		}
		$wpdb->update( $t, array(
			'topic'      => mb_substr( $new_topic, 0, 2000 ),
			'error'      => null,
			'updated_at' => current_time( 'mysql' ),
		), array( 'task_id' => $task_id ), array( '%s', '%s', '%s' ), array( '%s' ) );
		return array( 'ok' => true, 'task' => self::task_get( $task_id ) );
	}
}
