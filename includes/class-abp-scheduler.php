<?php
/**
 * class-abp-scheduler.php — 插件自动调度 + 本地生成引擎（v1.5.2）。
 *
 * 插件自足：不依赖外部服务也能自动运行——
 *   1. 每日 build_time（默认 08:00）：从备用池取题建当日任务队列（池子不足自动用内置素材补齐）；
 *   2. 每 5 分钟：处理「立即完成」（run_now=1）与到点（publish_at）任务 → 本地 AI 生成 → 发布。
 * stock/industry 栏目需实时数据（行情/Tavily），插件本地不生成，由可选生成引擎（Python 服务）接管。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Scheduler {

	const HOOK_BUILD = 'abp_daily_build';
	const HOOK_DUE   = 'abp_process_due';
	const HOOK_MATERIALS = 'abp_daily_materials';

	/**
	 * 注册 WP-Cron 调度（激活时）。
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_BUILD ) ) {
			$ts = strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS ) . ' 00:00:00' ) + 8 * HOUR_IN_SECONDS - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::HOOK_BUILD );
		}
		if ( ! wp_next_scheduled( self::HOOK_DUE ) ) {
			wp_schedule_event( time() + 120, 'abp_five_min', self::HOOK_DUE );
		}
		if ( ! wp_next_scheduled( self::HOOK_MATERIALS ) ) {
			// 每日 06:00 刷新联网素材（诗词语料等）。
			$ts = strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS ) . ' 00:00:00' ) + 6 * HOUR_IN_SECONDS - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::HOOK_MATERIALS );
		}
	}

	/**
	 * 每日刷新联网素材（诗词语料）。
	 *
	 * @return array
	 */
	public static function refresh_materials() {
		$r = ABP_Materials::refresh_poems();
		return $r;
	}

	/**
	 * 停用时清理调度。
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_BUILD );
		wp_clear_scheduled_hook( self::HOOK_DUE );
		wp_clear_scheduled_hook( self::HOOK_MATERIALS );
	}

	/**
	 * 注册 5 分钟 cron 间隔。
	 *
	 * @param array $schedules WP 间隔表。
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['abp_five_min'] = array(
			'interval' => 300,
			'display'  => 'A-Blog 每 5 分钟',
		);
		return $schedules;
	}

	/* ================= 每日建队列 ================= */

	/**
	 * 每日建当日任务队列：从池子按栏目取题（tech/reading/book 各 1），
	 * 池子不足用内置素材补齐；今天已有任务则跳过（幂等）。
	 *
	 * @param string|null $date Y-m-d（默认今天；补建历史队列时传入）。
	 * @return array{ok:bool, created:int, note?:string}
	 */
	public static function build_daily_queue( $date = null ) {
		global $wpdb;
		$t = $wpdb->prefix . ABP_Queue::TASKS;
		if ( $date ) {
			$date_str = gmdate( 'Y-m-d', strtotime( $date ) );
		} else {
			$date_str = gmdate( 'Y-m-d', current_time( 'timestamp' ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		}
		$date_ymd = str_replace( '-', '', $date_str );
		$ts = strtotime( $date_str );

		// 每日先按设置补充选题池（翁老：备用选题每日新增数，按栏目；一天一次，防重）。
		self::refill_pool_daily();

		$exist = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE task_id LIKE %s", $date_ymd . '%' ) );
		if ( $exist > 0 ) {
			return array( 'ok' => true, 'created' => 0, 'note' => $date_str . ' 任务已存在，跳过' );
		}

		$created = 0;
		// 每日篇数（翁老规则：daily_limit 设置必须生效，之前写死每栏目 1 篇导致每天 5 篇）+
		// 栏目开关过滤 + 栏目轮转（避免固定栏目霸占每日额度）。
		$settings = ABP_Settings::get_settings();
		$limit    = isset( $settings['daily_limit'] ) ? (int) $settings['daily_limit'] : 3;
		$limit    = max( 1, min( 10, $limit ) );
		$all_cols = array( 'stock', 'tech', 'reading', 'book', 'industry' );
		$on_cols  = array();
		foreach ( $all_cols as $col ) {
			$key = 'column_' . $col . '_enabled';
			if ( 'on' === ( isset( $settings[ $key ] ) ? $settings[ $key ] : 'on' ) ) {
				$on_cols[] = $col;
			}
		}
		$rotation = (int) get_option( 'abp_scheduler_rotation', 0 );
		$n_cols   = count( $on_cols );
		$picked   = array();
		if ( $n_cols > 0 ) {
			for ( $i = 0; $i < $limit; $i++ ) {
				$picked[] = $on_cols[ ( $rotation + $i ) % $n_cols ];
			}
			update_option( 'abp_scheduler_rotation', ( $rotation + $limit ) % $n_cols );
		}
		// A股复盘：仅交易日选题，题目固定（日期+「A股市场：」+副标题），无需素材库。
		if ( in_array( 'stock', $picked, true ) && ABP_Stock::is_trading_day( $ts ) ) {
			$task_id = $date_ymd . '-stock-' . sprintf( '%03d', self::column_seq( 'stock', $date_ymd ) );
			$r = ABP_Queue::task_create( $task_id, 'stock', 'A股每日复盘' );
			if ( $r['ok'] ) {
				$created++;
			}
		}
		foreach ( $picked as $col ) {
			if ( 'stock' === $col ) {
				continue; // stock 已在上方处理（非交易日不建）。
			}
			$item = self::pick_topic( $col );
			if ( ! $item ) {
				continue;
			}
			$task_id = $date_ymd . '-' . $col . '-' . sprintf( '%03d', self::column_seq( $col, $date_ymd ) );
			$r = ABP_Queue::task_create( $task_id, $col, $item['topic'] );
			if ( $r['ok'] ) {
				$created++;
				if ( ! empty( $item['pool_id'] ) ) {
					ABP_Queue::pool_mark_used( (int) $item['pool_id'] );
				}
			}
		}
		return array( 'ok' => true, 'created' => $created, 'date' => $date_str );
	}

	/**
	 * 备用选题池每日新增（翁老规则：按设置 pool_daily_<栏目> 自动入池，一天一次）。
	 * 池子上限 20 条、自动去重；复盘无素材选题（题目固定），设置 0 即可。
	 *
	 * @return array{ok:bool, added:int, note?:string}
	 */
	public static function refill_pool_daily() {
		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		if ( get_option( 'abp_pool_refill_date' ) === $today ) {
			return array( 'ok' => true, 'added' => 0, 'note' => '今日已补，跳过' );
		}
		$settings = ABP_Settings::get_settings();
		$added    = 0;
		foreach ( array( 'tech', 'reading', 'book', 'industry' ) as $pool_col ) {
			$n = isset( $settings[ 'pool_daily_' . $pool_col ] ) ? (int) $settings[ 'pool_daily_' . $pool_col ] : 0;
			if ( $n <= 0 ) {
				continue;
			}
			$r = ABP_Queue::pool_fill( $pool_col, $n );
			if ( is_array( $r ) && ! empty( $r['added'] ) ) {
				$added += (int) $r['added'];
			}
		}
		update_option( 'abp_pool_refill_date', $today );
		return array( 'ok' => true, 'added' => $added );
	}

	/**
	 * 错过检测 + 补充执行（每次 process_due 先跑）：
	 *   诗词语料超 1 天未刷新 → 立即联网刷新。
	 * 翁老规则：未发文章不补发——不再补建最近 3 天缺失队列（错过了就不补）。
	 *
	 * @return array{ok:bool, refreshed:bool}
	 */
	public static function catchup_missed() {
		// 1. 素材刷新补偿。
		$refreshed = false;
		$mat = get_option( 'abp_material_poems', array() );
		$mat = is_array( $mat ) ? $mat : array();
		$updated = isset( $mat['updated'] ) ? (string) $mat['updated'] : '';
		if ( '' === $updated || strtotime( $updated ) < strtotime( '-1 day' ) ) {
			ABP_Materials::refresh_poems();
			$refreshed = true;
		}
		return array( 'ok' => true, 'refreshed' => $refreshed );
	}

	/**
	 * 选题：池子题（优先）+ 素材候选（动态）→ AI 挑选（大模型选题；无 Key 时取第一个）。
	 *
	 * @param string $column 栏目码。
	 * @return array|null {topic, pool_id?}。
	 */
	private static function pick_topic( $column ) {
		global $wpdb;
		$t = $wpdb->prefix . ABP_Queue::POOL;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE status=%s AND column_name=%s ORDER BY sort_order ASC, id ASC LIMIT 3",
			'queued', $column
		), ARRAY_A );
		$pool_topics = array();
		foreach ( $rows as $r ) {
			$pool_topics[] = array( 'topic' => $r['topic'], 'pool_id' => (int) $r['id'] );
		}

		$all = array();
		foreach ( $pool_topics as $p ) {
			$all[] = $p['topic'];
		}
		// 素材候选不足 3 个时用动态素材补齐（国学=唐诗宋词古文节气；书评=站点书目+热门书）。
		foreach ( ABP_Materials::pick( $column, 3 ) as $m ) {
			$all[] = $m;
		}
		$all = array_values( array_unique( $all ) );
		if ( ! $all ) {
			return null;
		}

		$pick = self::ai_pick_topic( $column, $all );
		foreach ( $pool_topics as $p ) {
			if ( $p['topic'] === $pick ) {
				return array( 'topic' => $pick, 'pool_id' => $p['pool_id'] );
			}
		}
		return array( 'topic' => $pick, 'pool_id' => null );
	}

	/**
	 * 大模型选题：从候选中挑 1 个（考虑时效性/价值/避重复）。无 Key 时回落第一个。
	 *
	 * @param string   $column 栏目码。
	 * @param string[] $cands  候选列表。
	 * @return string
	 */
	private static function ai_pick_topic( $column, $cands ) {
		$models = abp_get_models();
		if ( empty( $models['deepseek_api_key'] ) ) {
			return $cands[0];
		}
		$labels = array( 'tech' => 'IT技术笔记', 'reading' => '国学赏析', 'book' => '读书书评', 'stock' => 'A股复盘', 'industry' => '行业综述' );
		$label = isset( $labels[ $column ] ) ? $labels[ $column ] : $column;
		$list = '';
		foreach ( $cands as $i => $c ) {
			$list .= ( $i + 1 ) . '. ' . $c . "\n";
		}
		$r = ABP_Toolbox::ai_chat(
			array(
				array( 'role' => 'system', 'content' => '你是博客选题编辑。从候选中选 1 个最适合今日发布的选题（考虑时效性、价值、与近期文章重复度），只输出选中项的序号数字。' ),
				array( 'role' => 'user', 'content' => '栏目：' . $label . "\n候选：\n" . $list . '请只输出序号（如 2）。' ),
			),
			20,
			0.3
		);
		if ( $r['ok'] ) {
			$n = (int) trim( (string) $r['text'] );
			if ( $n >= 1 && $n <= count( $cands ) ) {
				return $cands[ $n - 1 ];
			}
		}
		return $cands[0];
	}

	/**
	 * 当日某栏目任务序号（用于 task_id 编号）。
	 *
	 * @param string $column 栏目码。
	 * @param string $date   YYYYMMDD。
	 * @return int
	 */
	private static function column_seq( $column, $date ) {
		global $wpdb;
		$t = $wpdb->prefix . ABP_Queue::TASKS;
		return 1 + (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE task_id LIKE %s",
			$date . '-' . $column . '-%'
		) );
	}

	/* ================= 到点处理（每 5 分钟） ================= */

	/**
	 * 处理待执行任务：run_now=1 优先，其次 publish_at 到点（或无时间约束的 queued）。
	 *
	 * @return array{ok:bool, processed:int, results:array}
	 */
	public static function process_due() {
		// 错过检测 + 补充执行（素材刷新 / 最近 3 天缺队列补建）。
		self::catchup_missed();

		global $wpdb;
		$t = $wpdb->prefix . ABP_Queue::TASKS;
		$now = current_time( 'mysql' );

		$rows = $wpdb->get_results(
			"SELECT * FROM {$t} WHERE status='queued' AND run_now=1 ORDER BY updated_at ASC LIMIT 2",
			ARRAY_A
		);
		if ( ! $rows ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE status='queued' AND (publish_at IS NULL OR publish_at <= %s) ORDER BY sort_order ASC, id ASC LIMIT 2",
					$now
				),
				ARRAY_A
			);
		}

		$processed = 0;
		$results = array();
		foreach ( $rows as $row ) {
			$task_id = $row['task_id'];
			$col = $row['column_name'];
			if ( 'stock' === $col ) {
				// A股复盘：即时采集行情 + 本地生成（仅交易日会有任务）。
				ABP_Queue::task_update_status( $task_id, 'generating' );
				$r = ABP_Stock::generate( $row );
				if ( $r['ok'] ) {
					ABP_Queue::task_update_status( $task_id, 'published', isset( $r['post_id'] ) ? $r['post_id'] : null );
				} else {
					ABP_Queue::task_update_status( $task_id, 'failed', null, $r['error'] );
				}
				$results[] = array( 'task_id' => $task_id, 'ok' => $r['ok'], 'error' => isset( $r['error'] ) ? $r['error'] : '' );
				$processed++;
				continue;
			}
			if ( 'industry' === $col ) {
				// 行业综述：Tavily 联网搜索 + 本地生成（无 key 时用内置行业概念，正文注明）。
				ABP_Queue::task_update_status( $task_id, 'generating' );
				$r = ABP_Industry::generate( $row );
				if ( $r['ok'] ) {
					ABP_Queue::task_update_status( $task_id, 'published', isset( $r['post_id'] ) ? $r['post_id'] : null );
				} else {
					ABP_Queue::task_update_status( $task_id, 'failed', null, $r['error'] );
				}
				$results[] = array( 'task_id' => $task_id, 'ok' => $r['ok'], 'error' => isset( $r['error'] ) ? $r['error'] : '' );
				$processed++;
				continue;
			}
			ABP_Queue::task_update_status( $task_id, 'generating' );
			$r = self::generate_and_publish( $row );
			if ( $r['ok'] ) {
				ABP_Queue::task_update_status( $task_id, 'published', isset( $r['post_id'] ) ? $r['post_id'] : null );
			} else {
				ABP_Queue::task_update_status( $task_id, 'failed', null, $r['error'] );
			}
			$results[] = array( 'task_id' => $task_id, 'ok' => $r['ok'], 'error' => isset( $r['error'] ) ? $r['error'] : '' );
			$processed++;
		}
		return array( 'ok' => true, 'processed' => $processed, 'results' => $results );
	}

	/**
	 * 本地生成并发布一篇任务文章。
	 *
	 * @param array $row 任务行。
	 * @return array{ok:bool, post_id?:int, error?:string}
	 */
	private static function generate_and_publish( $row ) {
		$col = $row['column_name'];
		$topic = trim( (string) $row['topic'] );
		if ( '' === $topic ) {
			return array( 'ok' => false, 'error' => '任务选题为空' );
		}

		$prompts = array(
			'tech'    => array(
				'system' => '你是资深中文 IT 运维与建站技术作者。写实操教程：步骤清晰、结论先行，涉及命令/配置用 <pre><code> 代码块，不编造不存在的功能。正文 600-1200 字，配 2-4 个小标题（<h2>）。',
				'user'   => '选题：《%s》。直接输出 JSON（不要多余文字）：{"title":"标题(15-30字)","content_html":"完整正文HTML（含 h2/p/pre/code/ul）","excerpt":"80-110字中文摘要（去掉AI味，口语自然）"}',
			),
			'reading' => array(
				'system' => '你是古典文学赏析作者，文风雅致。赏析结构：原文、逐句译文、深度赏析、创作背景，结尾一句点睛。正文 600-900 字，配 <h2> 小标题。不编造史实，不确定处写"一说"。',
				'user'   => '选题：《%s》。直接输出 JSON（不要多余文字）：{"title":"标题","content_html":"完整正文HTML","excerpt":"80-110字中文摘要"}',
			),
			'book'    => array(
				'system' => '你是书评作者。书评结构：书籍简介、核心观点、精彩段落摘引、个人阅读感悟。正文 600-900 字，配 <h2> 小标题，观点明确不剧透过多。',
				'user'   => '选题：《%s》。直接输出 JSON（不要多余文字）：{"title":"标题","content_html":"完整正文HTML","excerpt":"80-110字中文摘要"}',
			),
		);
		if ( ! isset( $prompts[ $col ] ) ) {
			return array( 'ok' => false, 'error' => '不支持的栏目：' . $col );
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $prompts[ $col ]['system'] ),
			array( 'role' => 'user', 'content' => sprintf( $prompts[ $col ]['user'], $topic ) ),
		);
		$r = ABP_Toolbox::ai_chat( $messages, 4000, 0.7 );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'error' => 'AI 生成失败：' . $r['error'] );
		}

		$parsed = self::parse_article( $r['text'], $topic );
		if ( ! $parsed ) {
			return array( 'ok' => false, 'error' => 'AI 输出解析失败' );
		}

		$payload = array(
			'task_id'      => $row['task_id'],
			'column'       => $col,
			'final_title'  => $parsed['title'],
			'content_html' => $parsed['content_html'],
			'excerpt'      => $parsed['excerpt'],
			'category'     => self::column_category( $col ),
			'tags'         => array( self::column_label( $col ) ),
			'status'       => 'publish',
		);
		$pub = ABP_Publish::publish( $payload );
		if ( ! $pub['ok'] ) {
			return array( 'ok' => false, 'error' => '发布失败：' . $pub['error'] );
		}
		return array( 'ok' => true, 'post_id' => (int) $pub['post_id'] );
	}

	/**
	 * 解析 AI 输出 JSON（兼容被 ```json 包裹/前后杂质）。
	 *
	 * @param string $text  AI 原文。
	 * @param string $topic 选题（解析失败时兜底标题）。
	 * @return array|null {title, content_html}。
	 */
	private static function parse_article( $text, $topic ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			// 尝试提取第一个 { ... }。
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$data = json_decode( $m[0], true );
			}
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		$html  = isset( $data['content_html'] ) ? trim( (string) $data['content_html'] ) : '';
		if ( '' === $title ) {
			$title = mb_substr( $topic, 0, 30 );
		}
		if ( '' === $html ) {
			$html = isset( $data['content'] ) ? trim( (string) $data['content'] ) : '';
		}
		if ( '' === $html ) {
			return null;
		}
		// 摘要：AI 未返回时留空，交由 ABP_Publish 兜底从正文截取。
		$excerpt = isset( $data['excerpt'] ) ? trim( (string) $data['excerpt'] ) : '';
		return array( 'title' => $title, 'content_html' => $html, 'excerpt' => $excerpt );
	}

	/**
	 * 栏目 → 分类 slug。
	 *
	 * @param string $column 栏目码。
	 * @return string
	 */
	private static function column_category( $column ) {
		// 分类对齐主题已有分类（翁老：industry/it-notes 归入「行业」「IT」）。
		$map = array(
			'stock'    => 'a-share-review',  // 主题已有：股市
			'tech'     => 'it',              // 主题已有：IT
			'reading'  => 'reading-classics', // 主题已有：读书
			'book'     => 'reading-classics', // 主题已有：读书
			'industry' => '行业',            // 主题已有：行业
		);
		return isset( $map[ $column ] ) ? $map[ $column ] : 'uncategorized';
	}

	/**
	 * 栏目中文名。
	 *
	 * @param string $column 栏目码。
	 * @return string
	 */
	private static function column_label( $column ) {
		$map = array(
			'stock'    => 'A股复盘',
			'tech'     => 'IT技术',
			'reading'  => '国学',
			'book'     => '书评',
			'industry' => '行业综述',
		);
		return isset( $map[ $column ] ) ? $map[ $column ] : $column;
	}
}
