<?php
/**
 * class-abp-stock.php — A股复盘栏目：交易日历 + 行情数据采集 + 生成发布（v1.5.4）。
 *
 * 翁老原则：
 *   - 仅交易日选题（周末/节假日休市）；行情数据即时联网采集（新浪/东财），禁止编造；
 *   - 标题 = 当日日期 + 「A股市场：」 + 副标题，**先写正文、后定副标题**（基于正文内容特点，6-14 字），
 *     副标题生成失败回落「收盘综述」；
 *   - 数据缺失字段如实注明「该数据暂缺」（stock.md 规范）。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Stock {

	/**
	 * A股休市日表（2025-2027，YYYY-MM-DD；仅法定休市，周末另行判断）。
	 *
	 * @return string[]
	 */
	public static function holidays() {
		return array(
			// 2025
			'2025-01-01', '2025-01-28', '2025-01-29', '2025-01-30', '2025-01-31', '2025-02-03', '2025-02-04',
			'2025-04-04', '2025-05-01', '2025-05-02', '2025-05-05', '2025-06-02', '2025-10-01', '2025-10-02',
			'2025-10-03', '2025-10-06', '2025-10-07', '2025-10-08',
			// 2026
			'2026-01-01', '2026-01-02', '2026-02-16', '2026-02-17', '2026-02-18', '2026-02-19', '2026-02-20',
			'2026-02-23', '2026-02-24', '2026-04-06', '2026-05-01', '2026-05-04', '2026-05-05', '2026-06-19',
			'2026-09-25', '2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08',
			// 2027
			'2027-01-01', '2027-01-04', '2027-02-08', '2027-02-09', '2027-02-10', '2027-02-11', '2027-02-12',
			'2027-02-15', '2027-04-05', '2027-05-03', '2027-05-04', '2027-05-05', '2027-06-14', '2027-09-17',
			'2027-10-01', '2027-10-04', '2027-10-05', '2027-10-06', '2027-10-07', '2027-10-08',
		);
	}

	/**
	 * 是否 A股交易日。
	 *
	 * @param int|null $ts 时间戳（默认当前）。
	 * @return bool
	 */
	public static function is_trading_day( $ts = null ) {
		$ts = $ts ? (int) $ts : current_time( 'timestamp' );
		$w = (int) gmdate( 'w', $ts );
		if ( 0 === $w || 6 === $w ) {
			return false;
		}
		$ymd = gmdate( 'Y-m-d', $ts );
		if ( in_array( $ymd, self::holidays(), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * 采集行情素材。当天用实时接口（新浪/东财）；历史日期用新浪日K线（收盘点位/涨跌幅，
	 * 成交额/板块等当日字段如实标注「数据暂缺」——不编造）。
	 *
	 * @param string|null $date Y-m-d（默认当天）。
	 * @return array{ok:bool, indices:array, sectors:array, breadth:array, date:string, error?:string}
	 */
	public static function collect_data( $date = null ) {
		$out = array(
			'ok'      => false,
			'indices' => array(),
			'sectors' => array(),
			'breadth' => array(),
			'date'    => $date ? $date : gmdate( 'Y-m-d', current_time( 'timestamp' ) ),
			'error'   => '',
		);
		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
		if ( $date && $date !== $today ) {
			// 历史日期：新浪日K线。
			$indices = self::fetch_sina_kline( $date );
			if ( $indices ) {
				$out['indices'] = $indices;
				$out['ok'] = true;
			} else {
				$out['error'] = '历史行情不可用（' . $date . '）';
			}
			return $out;
		}
		$indices = self::fetch_sina_indices();
		if ( $indices ) {
			$out['indices'] = $indices;
			$out['ok'] = true;
		} else {
			$out['error'] = '新浪行情不可用';
		}
		$sectors = self::fetch_em_sectors();
		if ( $sectors ) {
			$out['sectors'] = $sectors;
			$out['ok'] = true;
		}
		return $out;
	}

	/**
	 * 新浪日K线（历史收盘点位/涨跌幅）。返回 {code,name,close,change_pct,amount_yi:null}。
	 *
	 * @param string $date Y-m-d。
	 * @return array[]
	 */
	private static function fetch_sina_kline( $date ) {
		$codes = array(
			'sh000001' => '上证指数',
			'sz399001' => '深证成指',
			'sz399006' => '创业板指',
			'sz399905' => '中证500',
		);
		$out = array();
		foreach ( $codes as $code => $name ) {
			$url = 'https://quotes.sina.cn/cn/api/jsonp_v2.php/var%20_data=/CN_MarketDataService.getKLineData?symbol=' . $code . '&scale=240&ma=no&datalen=15';
			$resp = wp_remote_get( $url, array( 'timeout' => 12, 'sslverify' => false, 'headers' => array( 'User-Agent' => 'Mozilla/5.0' ) ) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$body = wp_remote_retrieve_body( $resp );
			if ( ! preg_match( '/\[.*\]/s', $body, $m ) ) {
				continue;
			}
			$rows = json_decode( $m[0], true );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			for ( $i = 0; $i < count( $rows ); $i++ ) {
				if ( isset( $rows[ $i ]['day'] ) && $rows[ $i ]['day'] === $date && $i > 0 ) {
					$close = (float) $rows[ $i ]['close'];
					$prev  = (float) $rows[ $i - 1 ]['close'];
					if ( $prev > 0 ) {
						$out[] = array(
							'code'       => $code,
							'name'       => $name,
							'close'      => $close,
							'change_pct' => round( ( $close - $prev ) / $prev * 100, 2 ),
							'amount_yi'  => null, // 日K无成交额，如实置空（提示词会注明数据暂缺）。
						);
					}
				}
			}
		}
		return $out;
	}

	/**
	 * 新浪指数实时行情（sh000001 上证 / sz399001 深成 / sz399006 创业板 / sz399905 中证500）。
	 *
	 * @return array[] 每项 {code,name,close,change_pct,amount_yi}。
	 */
	private static function fetch_sina_indices() {
		$resp = wp_remote_get(
			'https://hq.sinajs.cn/list=sh000001,sz399001,sz399006,sz399905',
			array(
				'timeout'  => 12,
				'sslverify' => false,
				'headers'  => array( 'Referer' => 'https://finance.sina.com.cn', 'User-Agent' => 'Mozilla/5.0' ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$body = wp_remote_retrieve_body( $resp );
		if ( function_exists( 'iconv' ) ) {
			$body = iconv( 'GBK', 'UTF-8//IGNORE', $body );
		}
		if ( ! preg_match_all( '/hq_str_(sh\d+|sz\d+)="([^"]*)"/', $body, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $row ) {
			$f = explode( ',', $row[2] );
			if ( count( $f ) < 32 ) {
				continue;
			}
			$name  = $f[0];
			$close = (float) $f[3];
			$prev  = (float) $f[2];
			$amt   = (float) $f[9] / 1e8; // 成交额（元 → 亿元）
			if ( $prev <= 0 ) {
				continue;
			}
			$out[] = array(
				'code'        => $row[1],
				'name'        => $name,
				'close'       => $close,
				'change_pct'  => round( ( $close - $prev ) / $prev * 100, 2 ),
				'amount_yi'   => round( $amt, 2 ),
			);
		}
		return $out;
	}

	/**
	 * 东财行业板块涨幅榜（前 8，含领涨股）。
	 *
	 * @return array[] 每项 {name, change_pct, leader}。
	 */
	private static function fetch_em_sectors() {
		$url = 'https://push2.eastmoney.com/api/qt/clist/get?pn=1&pz=8&po=1&np=1&fltt=2&invt=2&fid=f3&fs=m:90+t:2+f:!50&fields=f2,f3,f12,f14,f104,f105,f128,f140';
		$resp = wp_remote_get( $url, array( 'timeout' => 12, 'sslverify' => false, 'headers' => array( 'User-Agent' => 'Mozilla/5.0' ) ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$list = isset( $data['data']['diff'] ) ? $data['data']['diff'] : array();
		$out = array();
		foreach ( (array) $list as $s ) {
			$out[] = array(
				'name'       => isset( $s['f14'] ) ? $s['f14'] : '',
				'change_pct' => isset( $s['f3'] ) ? round( (float) $s['f3'], 2 ) : null,
				'leader'     => isset( $s['f128'] ) ? $s['f128'] : '',
				'up'         => isset( $s['f104'] ) ? (int) $s['f104'] : null,
				'down'       => isset( $s['f105'] ) ? (int) $s['f105'] : null,
			);
		}
		return $out;
	}

	/**
	 * 生成并发布一篇复盘（先正文 → 后副标题 → 定标题 → 发布）。
	 *
	 * @param array $row 任务行。
	 * @return array{ok:bool, post_id?:int, error?:string}
	 */
	public static function generate( $row ) {
		// 任务日期：从 task_id（YYYYMMDD-column-NNN）解析，补建的昨日任务用历史行情。
		$task_date = '';
		if ( isset( $row['task_id'] ) && preg_match( '/^(\d{4})(\d{2})(\d{2})/', (string) $row['task_id'], $m ) ) {
			$task_date = $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		// 复盘查重（翁老规则）：该复盘日已有文章 = 对结果不满意 → 删除旧文，覆盖重做。
		// 与 REST 通道一致（兼容中文标题日期格式，如「2026年8月10日」）。
		$dup_date = $task_date ? $task_date : gmdate( 'Y-m-d', current_time( 'timestamp' ) );
		$dedup    = ABP_Publish::review_date_duplicate( $dup_date );
		if ( $dedup['duplicate'] ) {
			$old_id = (int) $dedup['similar_post_id'];
			wp_delete_post( $old_id, true );
			abp_log_write( $row['task_id'], 'stock', 'dedup', 'overwrite',
				'该复盘日已有文章 ID ' . $old_id . '（' . $dedup['similar_title'] . '），已删除并覆盖重做' );
		}

		$data = self::collect_data( $task_date ? $task_date : null );
		$prompts = include ABP_PLUGIN_DIR . 'includes/data-prompts.php';
		$prompt  = isset( $prompts['stock'] ) ? $prompts['stock'] : '';

		$material = array(
			'date'        => $data['date'],
			'indices'     => $data['indices'],
			'sectors'     => $data['sectors'],
			'breadth'     => $data['breadth'],
			'source_note' => $data['ok'] ? '新浪财经/东财实时采集' : '行情源暂不可用',
		);
		$user_msg = "今日采集素材（JSON）：\n" . wp_json_encode( $material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			. "\n\n请按规范输出 JSON：{\"content_html\":\"完整正文HTML\",\"excerpt\":\"80-110字中文摘要\"}";

		$r = ABP_Toolbox::ai_chat(
			array(
				array( 'role' => 'system', 'content' => $prompt ),
				array( 'role' => 'user', 'content' => $user_msg ),
			),
			4000,
			0.7
		);
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'error' => 'AI 生成失败：' . $r['error'] );
		}
		$parsed = self::parse_html( $r['text'] );
		if ( '' === $parsed['html'] ) {
			return array( 'ok' => false, 'error' => 'AI 输出解析失败' );
		}
		$html           = $parsed['html'];
		$parsed_excerpt = $parsed['excerpt'];

		$subtitle = self::make_subtitle( $html );
		// 标题日期用任务日期（补建的昨日复盘标题写昨日，不写今天）。
		$date_cn  = $task_date ? date( 'Y年n月j日', strtotime( $task_date ) ) : gmdate( 'Y年n月j日', current_time( 'timestamp' ) );
		$title    = $date_cn . ' A股市场：' . ( '' !== $subtitle ? $subtitle : '收盘综述' );

		$payload = array(
			'task_id'      => $row['task_id'],
			'column'       => 'stock',
			'final_title'  => $title,
			'content_html' => $html,
			'excerpt'      => $parsed_excerpt,
			'category'     => 'a-share-review',
			'tags'         => array( 'A股复盘', $date_cn ),
			'status'       => 'publish',
		);
		$pub = ABP_Publish::publish( $payload );
		if ( ! $pub['ok'] ) {
			return array( 'ok' => false, 'error' => '发布失败：' . $pub['error'] );
		}
		return array( 'ok' => true, 'post_id' => (int) $pub['post_id'] );
	}

	/**
	 * 从 AI 输出提取正文与摘要（兼容 JSON 包裹）。
	 *
	 * @param string $text AI 原文。
	 * @return array{html:string, excerpt:string} 摘要缺省时留空（由 ABP_Publish 兜底截取）。
	 */
	private static function parse_html( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) && isset( $data['content_html'] ) ) {
			return array(
				'html'    => trim( (string) $data['content_html'] ),
				'excerpt' => isset( $data['excerpt'] ) ? trim( (string) $data['excerpt'] ) : '',
			);
		}
		if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
			$data = json_decode( $m[0], true );
			if ( is_array( $data ) && isset( $data['content_html'] ) ) {
				return array(
					'html'    => trim( (string) $data['content_html'] ),
					'excerpt' => isset( $data['excerpt'] ) ? trim( (string) $data['excerpt'] ) : '',
				);
			}
		}
		// 直接是 HTML 正文（无 JSON 外壳）。
		if ( false !== strpos( $text, '<' ) && false !== strpos( $text, '>' ) ) {
			return array( 'html' => $text, 'excerpt' => '' );
		}
		return array( 'html' => '', 'excerpt' => '' );
	}

	/**
	 * 副标题：基于正文内容特点生成 6-14 字概括（翁老：先写正文后定标题，不预设）。
	 *
	 * @param string $html 正文 HTML。
	 * @return string 副标题（失败返回空串，调用方回落「收盘综述」）。
	 */
	private static function make_subtitle( $html ) {
		$plain = trim( (string) wp_strip_all_tags( $html ) );
		$plain = (string) preg_replace( '/\s+/u', ' ', $plain );
		$models = abp_get_models();
		if ( empty( $models['deepseek_api_key'] ) ) {
			return '';
		}
		$r = ABP_Toolbox::ai_chat(
			array(
				array( 'role' => 'system', 'content' => '你是资深证券分析师，只输出副标题本身，禁止多余文字。' ),
				array( 'role' => 'user', 'content' => '请用 6-14 个字概括今日 A股盘面最突出的特点（基于以下复盘正文）：' . mb_substr( $plain, 0, 1200 ) ),
			),
			30,
			0.3
		);
		if ( ! $r['ok'] ) {
			return '';
		}
		$sub = trim( (string) $r['text'] );
		$sub = trim( $sub, '“”"\'。，；' );
		$len = mb_strlen( $sub );
		if ( $len < 4 || $len > 20 ) {
			return '';
		}
		return $sub;
	}
}
