<?php
/**
 * A-Blog REST API 端点（总纲 §3.2 Python ↔ WP 插件契约）。
 *
 * 端点（全部 Bearer Token 认证，hash_equals 常数时间比较）：
 *   POST   /ai-auto-blog/v1/articles        接收成品文章：查重 → 建文 → 返回结果
 *   GET    /ai-auto-blog/v1/health          健康检查 + 模型探测摘要
 *   GET    /ai-auto-blog/v1/categories      站点分类列表（供 Python 匹配栏目）
 *   POST   /ai-auto-blog/v1/check           指纹查重（fingerprint 或 text）
 *   GET    /ai-auto-blog/v1/written-books   已写书目清单（读书栏目防重复）
 *   GET    /ai-auto-blog/v1/topics          智能选题：今日任务 + 备选列表（代理 Python）
 *   POST   /ai-auto-blog/v1/topics/{id}     人工指定/调整选题（body: topic 或 index）
 *   DELETE /ai-auto-blog/v1/topics/{id}     删除任务（仅 queued/skipped）
 *   POST   /ai-auto-blog/v1/topics/reorder  调整排队顺序（body: task_ids 数组）
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_REST {

	/**
	 * 注册 REST 路由。
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			ABP_API_NAMESPACE,
			'/articles',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_articles' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		register_rest_route(
			ABP_API_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_health' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		register_rest_route(
			ABP_API_NAMESPACE,
			'/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_categories' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		register_rest_route(
			ABP_API_NAMESPACE,
			'/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_check' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		register_rest_route(
			ABP_API_NAMESPACE,
			'/written-books',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_written_books' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		register_rest_route(
			ABP_API_NAMESPACE,
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_settings' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		// —— 智能选题中心（代理 Python 侧 /api/topics/*，供后台人工查看/指定/调整/删除）——
		register_rest_route(
			ABP_API_NAMESPACE,
			'/topics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_topics_list' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/topics/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_topic_reorder' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		// 注意：task_id 用精确格式（YYYYMMDD-column-序号），避免吞掉 /topics/reorder。
		register_rest_route(
			ABP_API_NAMESPACE,
			'/topics/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_topic_pick' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/topics/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_topic_delete' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_task_run_now' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_tasks_list' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_tasks_clear' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		// —— 备用选题池（代理 Python /api/pool/*）——
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_pool_list' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pool_add' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool/reorder',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pool_reorder' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool/fill',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pool_fill' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool/(?P<pool_id>\d+)/plan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pool_plan' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool/(?P<pool_id>\d+)/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pool_run' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool/(?P<pool_id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'handle_pool_edit' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/pool/(?P<pool_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_pool_delete' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);

		// —— AI 工具箱（摘要 / 评论 / 话题，插件本地处理）——
		register_rest_route(
			ABP_API_NAMESPACE,
			'/toolbox/summary',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_toolbox_summary' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/toolbox/comments',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_toolbox_comments' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/toolbox/topics',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_toolbox_topics' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
	}

	/**
	 * 通用代理：转发到 Python 服务并回传 JSON。
	 *
	 * @param string $method HTTP 方法。
	 * @param string $path   相对 /api 的路径（如 /pool）。
	 * @param array  $body   可选 JSON body。
	 * @return WP_REST_Response
	 */
	private static function proxy( $method, $path, $body = null, $timeout = 10 ) {
		$args = array(
			'timeout' => max( 5, (int) $timeout ),
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		if ( 'GET' === $method ) {
			$resp = wp_remote_get( self::python_base() . $path, $args );
		} elseif ( 'POST' === $method ) {
			$resp = wp_remote_post( self::python_base() . $path, $args );
		} else {
			$args['method'] = $method;
			$resp           = wp_remote_request( self::python_base() . $path, $args );
		}
		if ( is_wp_error( $resp ) ) {
			$err = $resp->get_error_message();
			// 超时大概率是任务仍在执行（AI 生成 1-3 分钟），单独提示避免误判服务不可达
			if ( false !== strpos( (string) $err, 'timed out' ) || false !== strpos( (string) $err, 'error 28' ) ) {
				return self::error( 'Python 任务执行超时（AI 生成可能仍在进行，请稍后在「今日计划任务」查看状态）：' . $err, 504, '', '', 'pool' );
			}
			return self::error( 'Python 服务不可达：' . $err, 502, '', '', 'pool' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body_str = wp_remote_retrieve_body( $resp );
		if ( 200 !== $code ) {
			return self::error( 'Python 返回：HTTP ' . $code . ' ' . $body_str, 502, '', '', 'pool' );
		}
		$resp = new WP_REST_Response( json_decode( $body_str, true ), 200 );
		$resp->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $resp;
	}

	/**
	 * GET /pool —— 备用选题池列表。
	 */
	public static function handle_pool_list( $request ) {
		return self::proxy( 'GET', '/api/pool' );
	}

	/**
	 * POST /pool —— 人工添加（系统判断 + 优化标题）。
	 */
	public static function handle_pool_add( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		return self::proxy( 'POST', '/api/pool', array(
			'column' => isset( $body['column'] ) ? sanitize_text_field( $body['column'] ) : '',
			'topic'  => isset( $body['topic'] ) ? sanitize_text_field( $body['topic'] ) : '',
		) );
	}

	/**
	 * POST /pool/reorder —— 备用选题重新排队。
	 */
	public static function handle_pool_reorder( $request ) {
		$body = $request->get_json_params();
		$ids  = array();
		if ( is_array( $body ) && ! empty( $body['ids'] ) && is_array( $body['ids'] ) ) {
			foreach ( $body['ids'] as $i ) {
				$ids[] = intval( $i );
			}
		}
		if ( ! $ids ) {
			return self::error( 'ids 数组必填', 400, '', '', 'pool' );
		}
		return self::proxy( 'POST', '/api/pool/reorder', array( 'ids' => $ids ) );
	}

	/**
	 * POST /pool/fill —— 本地素材生成备用题入池。
	 */
	public static function handle_pool_fill( $request ) {
		$body = $request->get_json_params();
		$col  = ( is_array( $body ) && ! empty( $body['column'] ) ) ? sanitize_text_field( $body['column'] ) : '';
		$n    = ( is_array( $body ) && ! empty( $body['n'] ) ) ? intval( $body['n'] ) : 3;
		$path = '/api/pool/fill?n=' . max( 1, min( $n, 10 ) ) . ( $col ? '&column=' . $col : '' );
		return self::proxy( 'POST', $path );
	}

	/**
	 * PUT /pool/{id} —— 编辑池中选题。
	 */
	public static function handle_pool_edit( $request ) {
		$body    = $request->get_json_params();
		$payload = array();
		if ( is_array( $body ) ) {
			if ( isset( $body['topic'] ) ) {
				$payload['topic'] = sanitize_text_field( $body['topic'] );
			}
			if ( isset( $body['column'] ) ) {
				$payload['column'] = sanitize_text_field( $body['column'] );
			}
		}
		return self::proxy( 'PUT', '/api/pool/' . intval( $request['pool_id'] ), $payload );
	}

	/**
	 * DELETE /pool/{id} —— 删除池中选题。
	 */
	public static function handle_pool_delete( $request ) {
		return self::proxy( 'DELETE', '/api/pool/' . intval( $request['pool_id'] ) );
	}

	/* ---- AI 工具箱端点（本地处理，不走 Python 代理） ---- */

	/**
	 * POST /toolbox/summary —— AI 生成文章摘要。body: {post_id} 或 {post_ids: [...]}
	 */
	public static function handle_toolbox_summary( $request ) {
		$body = $request->get_json_params();
		if ( is_array( $body ) && ! empty( $body['post_ids'] ) && is_array( $body['post_ids'] ) ) {
			set_time_limit( 0 );
			$results = array();
			foreach ( array_unique( array_map( 'intval', $body['post_ids'] ) ) as $pid ) {
				if ( ! $pid ) {
					continue;
				}
				$r = ABP_Toolbox::generate_summary( $pid, true );
				$results[] = array(
					'post_id' => $pid,
					'ok'      => (bool) $r['ok'],
					'summary' => $r['ok'] ? $r['summary'] : null,
					'error'   => $r['ok'] ? null : $r['error'],
				);
			}
			return rest_ensure_response( new WP_REST_Response( array( 'ok' => true, 'batch' => true, 'results' => $results ), 200 ) );
		}
		$pid  = is_array( $body ) && ! empty( $body['post_id'] ) ? intval( $body['post_id'] ) : 0;
		if ( ! $pid ) {
			return self::error( 'post_id 必填', 400, '', '', 'toolbox' );
		}
		$r = ABP_Toolbox::generate_summary( $pid, true );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'toolbox' );
		}
		return rest_ensure_response( new WP_REST_Response( array( 'ok' => true, 'post_id' => $pid, 'summary' => $r['summary'] ), 200 ) );
	}

	/**
	 * POST /toolbox/comments —— AI 生成评论。body: {post_id, count, status} 或 {post_ids, count, status}
	 */
	public static function handle_toolbox_comments( $request ) {
		$body   = $request->get_json_params();
		$count  = is_array( $body ) && ! empty( $body['count'] ) ? intval( $body['count'] ) : 5;
		$status = is_array( $body ) && ! empty( $body['status'] ) ? sanitize_key( $body['status'] ) : 'pending';
		if ( is_array( $body ) && ! empty( $body['post_ids'] ) && is_array( $body['post_ids'] ) ) {
			set_time_limit( 0 );
			$results = array();
			foreach ( array_unique( array_map( 'intval', $body['post_ids'] ) ) as $pid ) {
				if ( ! $pid ) {
					continue;
				}
				$r = ABP_Toolbox::generate_comments( $pid, $count, $status );
				$results[] = array(
					'post_id' => $pid,
					'ok'      => (bool) $r['ok'],
					'count'   => $r['ok'] ? $r['inserted'] : 0,
					'error'   => $r['ok'] ? null : $r['error'],
				);
			}
			return rest_ensure_response( new WP_REST_Response( array( 'ok' => true, 'batch' => true, 'results' => $results ), 200 ) );
		}
		$pid = is_array( $body ) && ! empty( $body['post_id'] ) ? intval( $body['post_id'] ) : 0;
		if ( ! $pid ) {
			return self::error( 'post_id 必填', 400, '', '', 'toolbox' );
		}
		$r = ABP_Toolbox::generate_comments( $pid, $count, $status );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'toolbox' );
		}
		return rest_ensure_response( new WP_REST_Response( array(
			'ok'       => true,
			'post_id'  => $pid,
			'inserted' => $r['inserted'],
			'comments' => $r['comments'],
		), 200 ) );
	}

	/**
	 * POST /toolbox/topics —— AI 生成热门话题。body: {post_id} 或 {post_ids, topic_count}
	 */
	public static function handle_toolbox_topics( $request ) {
		$body = $request->get_json_params();
		$cnt  = is_array( $body ) && ! empty( $body['topic_count'] ) ? intval( $body['topic_count'] ) : 2;
		if ( is_array( $body ) && ! empty( $body['post_ids'] ) && is_array( $body['post_ids'] ) ) {
			set_time_limit( 0 );
			$results = array();
			foreach ( array_unique( array_map( 'intval', $body['post_ids'] ) ) as $pid ) {
				if ( ! $pid ) {
					continue;
				}
				$r = ABP_Toolbox::generate_topics( $pid, $cnt );
				$results[] = array(
					'post_id' => $pid,
					'ok'      => (bool) $r['ok'],
					'topics'  => $r['ok'] ? $r['topics'] : array(),
					'error'   => $r['ok'] ? null : $r['error'],
				);
			}
			return rest_ensure_response( new WP_REST_Response( array( 'ok' => true, 'batch' => true, 'results' => $results ), 200 ) );
		}
		$pid = is_array( $body ) && ! empty( $body['post_id'] ) ? intval( $body['post_id'] ) : 0;
		if ( ! $pid ) {
			return self::error( 'post_id 必填', 400, '', '', 'toolbox' );
		}
		$r = ABP_Toolbox::generate_topics( $pid, $cnt );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'toolbox' );
		}
		return rest_ensure_response( new WP_REST_Response( array(
			'ok'      => true,
			'post_id' => $pid,
			'topics'  => $r['topics'],
		), 200 ) );
	}

	/**
	 * POST /pool/{id}/plan —— 指定立即列入计划。
	 */
	public static function handle_pool_plan( $request ) {
		return self::proxy( 'POST', '/api/pool/' . intval( $request['pool_id'] ) . '/plan', null, 120 );
	}

	/**
	 * POST /pool/{id}/run —— 备用题立即完成（列入计划并马上生成发布，长超时）。
	 */
	public static function handle_pool_run( $request ) {
		return self::proxy( 'POST', '/api/pool/' . intval( $request['pool_id'] ) . '/run', null, 300 );
	}

	/**
	 * POST /tasks/{id}/run —— 立即完成指定任务（生成并发布，耗时 1-3 分钟，长超时）。
	 */
	public static function handle_task_run_now( $request ) {
		return self::proxy( 'POST', '/api/tasks/' . sanitize_text_field( $request['task_id'] ) . '/run', null, 300 );
	}

	/**
	 * GET /tasks —— 今日计划任务列表（代理 Python /api/topics/today）。
	 */
	public static function handle_tasks_list( $request ) {
		return self::proxy( 'GET', '/api/topics/today' );
	}

	/**
	 * POST /tasks/clear —— 清空今日计划任务（queued/skipped）。
	 */
	public static function handle_tasks_clear( $request ) {
		return self::proxy( 'POST', '/api/tasks/clear' );
	}

	/**
	 * GET /settings —— 站点开关与调度参数（供 Python 侧同步，让后台开关真正生效）。
	 *
	 * 仅返回非敏感字段；密钥/Token 不在此接口下发（经 /health 只返回 has_key 布尔）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_settings( $request ) {
		$s = get_option( 'abp_settings', array() );
		$s = is_array( $s ) ? $s : array();
		$body = array(
			'ok'                  => true,
			'ai_enabled'          => ( 'on' === ( isset( $s['ai_enabled'] ) ? $s['ai_enabled'] : 'on' ) ),
			'column_stock_enabled'=> ( 'on' === ( isset( $s['column_stock_enabled'] ) ? $s['column_stock_enabled'] : 'on' ) ),
			'column_tech_enabled' => ( 'on' === ( isset( $s['column_tech_enabled'] ) ? $s['column_tech_enabled'] : 'on' ) ),
			'column_reading_enabled' => ( 'on' === ( isset( $s['column_reading_enabled'] ) ? $s['column_reading_enabled'] : 'on' ) ),
			'column_book_enabled' => ( 'on' === ( isset( $s['column_book_enabled'] ) ? $s['column_book_enabled'] : 'on' ) ),
			'column_industry_enabled' => ( 'on' === ( isset( $s['column_industry_enabled'] ) ? $s['column_industry_enabled'] : 'on' ) ),
			'image_enabled'       => ( 'on' === ( isset( $s['image_enabled'] ) ? $s['image_enabled'] : 'on' ) ),
			'publish_enabled'     => ( 'on' === ( isset( $s['publish_enabled'] ) ? $s['publish_enabled'] : 'on' ) ),
			'flush_cache'         => ( 'on' === ( isset( $s['flush_cache'] ) ? $s['flush_cache'] : 'on' ) ),
			'daily_limit'         => isset( $s['daily_limit'] ) ? (int) $s['daily_limit'] : 3,
			'daily_token_limit'   => isset( $s['daily_token_limit'] ) ? (int) $s['daily_token_limit'] : 200000,
			'publish_window'      => isset( $s['publish_window'] ) ? $s['publish_window'] : '09:00-21:00',
			'column_ratio'        => isset( $s['column_ratio'] ) ? $s['column_ratio'] : '40:30:30',
		);
		return rest_ensure_response( new WP_REST_Response( $body, 200 ) );
	}

	/**
	 * Python 伴生服务地址（设置页可配，默认本机 8080）。
	 *
	 * @return string
	 */
	private static function python_base() {
		$s    = get_option( 'abp_settings', array() );
		$base = isset( $s['python_base'] ) ? untrailingslashit( esc_url_raw( $s['python_base'] ) ) : '';
		return $base ? $base : 'http://127.0.0.1:8080';
	}

	/**
	 * GET /topics —— 拉取今日任务 + 选题候选列表。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_topics_list( $request ) {
		$resp = wp_remote_get(
			self::python_base() . '/api/topics/today',
			array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $resp ) ) {
			return self::error( 'Python 服务不可达：' . $resp->get_error_message(), 502, '', '', 'topics' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			return self::error( 'Python 服务返回异常：HTTP ' . $code . ' ' . wp_remote_retrieve_body( $resp ), 502, '', '', 'topics' );
		}
		$resp = new WP_REST_Response( $body, 200 );
		$resp->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $resp;
	}

	/**
	 * POST /topics/reorder —— 调整排队顺序（代理 Python）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_topic_reorder( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body['task_ids'] ) || ! is_array( $body['task_ids'] ) ) {
			return self::error( 'task_ids 数组必填', 400, '', '', 'topics' );
		}
		$ids = array();
		foreach ( $body['task_ids'] as $tid ) {
			$ids[] = sanitize_text_field( (string) $tid );
		}
		$resp = wp_remote_post(
			self::python_base() . '/api/topics/reorder',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'task_ids' => $ids ) ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return self::error( 'Python 服务不可达：' . $resp->get_error_message(), 502, '', '', 'topics' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return self::error( 'Python 返回：HTTP ' . $code . ' ' . wp_remote_retrieve_body( $resp ), 502, '', '', 'topics' );
		}
		$resp = new WP_REST_Response( json_decode( wp_remote_retrieve_body( $resp ), true ), 200 );
		$resp->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $resp;
	}

	/**
	 * POST /topics/{task_id} —— 人工指定/调整选题（body: topic 或 index）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_topic_pick( $request ) {
		$task_id = sanitize_text_field( $request['task_id'] );
		$body    = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$payload = array(
			'topic' => isset( $body['topic'] ) ? sanitize_text_field( $body['topic'] ) : '',
			'index' => isset( $body['index'] ) ? intval( $body['index'] ) : null,
		);
		$resp = wp_remote_post(
			self::python_base() . '/api/topics/' . rawurlencode( $task_id ),
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return self::error( 'Python 服务不可达：' . $resp->get_error_message(), 502, '', '', 'topics' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return self::error( 'Python 返回：HTTP ' . $code . ' ' . wp_remote_retrieve_body( $resp ), 502, '', '', 'topics' );
		}
		$resp = new WP_REST_Response( json_decode( wp_remote_retrieve_body( $resp ), true ), 200 );
		$resp->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $resp;
	}

	/**
	 * DELETE /topics/{task_id} —— 删除任务（仅 queued/skipped 可删）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_topic_delete( $request ) {
		$task_id = sanitize_text_field( $request['task_id'] );
		$resp    = wp_remote_request(
			self::python_base() . '/api/topics/' . rawurlencode( $task_id ),
			array( 'method' => 'DELETE', 'timeout' => 8 )
		);
		if ( is_wp_error( $resp ) ) {
			return self::error( 'Python 服务不可达：' . $resp->get_error_message(), 502, '', '', 'topics' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return self::error( 'Python 返回：HTTP ' . $code . ' ' . wp_remote_retrieve_body( $resp ), 502, '', '', 'topics' );
		}
		$resp = new WP_REST_Response( json_decode( wp_remote_retrieve_body( $resp ), true ), 200 );
		$resp->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $resp;
	}

	/**
	 * Bearer Token 校验（permission_callback）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return true|WP_Error
	 */
	public static function check_token( $request ) {
		$settings = get_option( 'abp_settings', array() );
		$token    = isset( $settings['api_token'] ) ? (string) $settings['api_token'] : '';

		if ( '' === $token ) {
			return new WP_Error(
				'abp_no_token',
				'插件尚未生成 API Token，请在后台「AI 自动博客」设置页生成',
				array( 'status' => 401 )
			);
		}

		$auth = self::get_bearer_token();
		if ( '' === $auth || ! hash_equals( $token, $auth ) ) {
			return new WP_Error(
				'abp_bad_token',
				'API Token 无效或缺失（请使用 Authorization: Bearer <token>）',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * 从请求头提取 Bearer Token。
	 *
	 * @return string 空串表示未提供。
	 */
	private static function get_bearer_token() {
		$header = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// nginx 常见配置（fastcgi_param 转发）下的取值。
			$header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $k => $v ) {
					if ( strtolower( (string) $k ) === 'authorization' ) {
						$header = $v;
						break;
					}
				}
			}
		}

		$header = trim( (string) $header );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $m ) ) {
			return trim( $m[1] );
		}

		return '';
	}

	/**
	 * POST /articles —— 接收成品文章：查重 → 建文 → 返回结果。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_articles( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$task_id = isset( $params['task_id'] ) ? sanitize_text_field( (string) $params['task_id'] ) : '';
		$column  = isset( $params['column'] ) ? sanitize_text_field( (string) $params['column'] ) : '';

		$title = isset( $params['final_title'] ) ? sanitize_text_field( (string) $params['final_title'] ) : '';
		if ( '' === $title && isset( $params['title'] ) ) {
			$title = sanitize_text_field( (string) $params['title'] );
		}
		$content = isset( $params['content_html'] ) ? (string) $params['content_html'] : '';
		if ( '' === trim( $content ) && isset( $params['content'] ) ) {
			$content = (string) $params['content'];
		}
		$category = isset( $params['category'] ) ? sanitize_text_field( (string) $params['category'] ) : '';

		abp_log_write( $task_id, $column, 'receive', 'ok', '收到文章提交：' . $title );

		// 必填校验（中文错误信息）。
		if ( '' === $title ) {
			return self::error( '标题不能为空（字段 final_title/title）', 400, $task_id, $column, 'validate' );
		}
		if ( '' === trim( $content ) ) {
			return self::error( '正文不能为空（字段 content_html）', 400, $task_id, $column, 'validate' );
		}
		if ( '' === $category ) {
			return self::error( '分类不能为空（字段 category）', 400, $task_id, $column, 'validate' );
		}

		// 查重：复盘栏目只查标题日期（翁老规则：每天内容相似仅数字不同，不查内容）；
		// 其余栏目 SimHash 指纹查重（汉明距离 < 4 判重）。
		if ( 'stock' === $column ) {
			$dedup   = self::review_date_duplicate( $title );
			$channel = 'review-date';
		} else {
			$plain   = wp_strip_all_tags( $content );
			$dedup   = abp_is_duplicate( $plain );
			$channel = 'fingerprint';
		}
		if ( $dedup['duplicate'] ) {
			if ( 'review-date' === $channel ) {
				// 翁老规则：已有该复盘日的文章 = 对结果不满意 → 删除旧文，覆盖重做（不以写文日期查重）
				$old_id = (int) $dedup['similar_post_id'];
				wp_delete_post( $old_id, true );
				abp_log_write( $task_id, $column, 'dedup', 'overwrite',
					'该复盘日已有文章 ID ' . $old_id . '，已删除并覆盖重做' );
			} else {
				$msg = '查重命中：与站内文章《' . $dedup['similar_title'] . '》（ID ' . $dedup['similar_post_id'] . '，距离' . $dedup['distance'] . '）重复';
				abp_log_write( $task_id, $column, 'dedup', 'skip', $msg );
				return self::error( $msg, 409, $task_id, $column, 'dedup' );
			}
		} else {
			abp_log_write( $task_id, $column, 'dedup', 'ok', '查重通过（' . $channel . '）' );
		}

		// 建文发布。
		$result = ABP_Publish::publish( $params );
		if ( ! $result['ok'] ) {
			return self::error( isset( $result['error'] ) ? $result['error'] : '发布失败', 500, $task_id, $column, 'publish' );
		}

		$response = new WP_REST_Response(
			array(
				'ok'        => true,
				'post_id'   => $result['post_id'],
				'permalink' => $result['permalink'],
			),
			200
		);
		return rest_ensure_response( $response );
	}

	/**
	 * GET /health —— 健康检查 + 模型探测摘要。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_health( $request ) {
		$models = abp_get_models();
		$ok     = ( 'none' !== $models['provider'] );

		$body = array(
			'ok'        => $ok,
			'version'   => ABP_VERSION,
			'provider'  => $models['provider'],
			'source'    => $models['source'],
			'has_key'   => '' !== trim( (string) $models['deepseek_api_key'] ),
			// 完整 Key 仅经 Bearer 认证接口回传（供 Python 侧同步采用，不入日志）。
			'deepseek_api_key' => isset( $models['deepseek_api_key'] ) ? (string) $models['deepseek_api_key'] : '',
			'models'    => $models['models'],
			'image_api' => array(
				'provider' => isset( $models['image_api']['provider'] ) ? $models['image_api']['provider'] : '',
				'model'    => isset( $models['image_api']['model'] ) ? $models['image_api']['model'] : '',
			),
		);

		if ( ! $ok ) {
			// 总纲 3.4：模型未配置 → Python 层拦截任务，不消耗 Token。
			$body['error'] = 'no_model_configured';
		}

		return rest_ensure_response( new WP_REST_Response( $body, 200 ) );
	}

	/**
	 * GET /categories —— 站点分类列表。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_categories( $request ) {
		$cats = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$list = array();
		foreach ( (array) $cats as $cat ) {
			$list[] = array(
				'term_id' => (int) $cat->term_id,
				'name'    => $cat->name,
				'slug'    => $cat->slug,
				'count'   => (int) $cat->count,
			);
		}

		return rest_ensure_response(
			new WP_REST_Response(
				array( 'ok' => true, 'categories' => $list ),
				200
			)
		);
	}

	/**
	 * POST /check —— 指纹查重。
	 * 请求体：{"fingerprint": "<16位hex>"} 或 {"text": "正文"}（二选一）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_check( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$fingerprint = isset( $params['fingerprint'] ) ? preg_replace( '/[^0-9a-f]/i', '', strtolower( (string) $params['fingerprint'] ) ) : '';
		if ( '' === $fingerprint && isset( $params['text'] ) ) {
			$fingerprint = abp_simhash( (string) $params['text'] );
		}
		if ( 16 !== strlen( $fingerprint ) ) {
			return self::error( '指纹格式无效：需 16 位十六进制字符串（字段 fingerprint 或 text）', 400, '', '', 'validate' );
		}

		// 注意：请求携带的是指纹字符串，直接按指纹比对（find_similar_all），
		// 不能把指纹当正文再算一次 simhash（abp_is_duplicate 仅用于文本）。
		$matches = ABP_Fingerprint::find_similar_all( $fingerprint, ABP_Fingerprint::THRESHOLD );

		$duplicate       = ! empty( $matches );
		$similar_post_id = 0;
		$similar_title   = '';
		$distance        = PHP_INT_MAX;
		if ( $duplicate ) {
			$similar_post_id = (int) $matches[0]['post_id'];
			$similar_title   = isset( $matches[0]['post_title'] ) ? $matches[0]['post_title'] : '';
			$distance        = (int) $matches[0]['distance'];
		}

		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'ok'              => true,
					'fingerprint'     => $fingerprint,
					'duplicate'       => $duplicate,
					'distance'        => $distance,
					'similar_post_id' => $similar_post_id,
					'similar_title'   => $similar_title,
				),
				200
			)
		);
	}

	/**
	 * GET /written-books —— 已写书目清单（读书栏目防重复）。
	 *
	 * 实现方案（代码注释说明取舍）：
	 *   方案 A（本实现）：查询 wp_posts 中分类为「读书与国学/读书」（slug reading-classics
	 *   或名字匹配）的文章，按书名号《》或读书/书评关键词过滤标题 —— 简单可靠，无需额外表，
	 *   与站点实际文章状态实时一致；文章量大（500 篇）时性能可接受（一次带索引查询）。
	 *   方案 B（备选）：插件自定义表 written_books，Python 侧建书后登记；
	 *   优点查询更快，缺点是与 WP 文章状态可能不一致（草稿/删除需同步）。
	 *   若后续书评量级上升，可切换方案 B 并保持本端点响应结构不变。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_written_books( $request ) {
		$category_id = 0;

		$term = get_term_by( 'slug', 'reading-classics', 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'name', '读书与国学', 'category' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'name', '读书', 'category' );
		}
		if ( $term && ! is_wp_error( $term ) ) {
			$category_id = (int) $term->term_id;
		}

		$books = array();

		if ( $category_id ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => array( 'publish', 'future', 'draft' ),
					'posts_per_page' => 500,
					'category__in'   => array( $category_id ),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $post ) {
				$title = get_the_title( $post );
				// 书目判定模式：书名号《…》，或标题含读书/书评/读后感/阅读/藏书 等关键词。
				if ( preg_match( '/《[^《》]+》/u', $title ) || preg_match( '/(读书|书评|读后感|阅读|藏书)/u', $title ) ) {
					$books[] = array(
						'post_id'   => (int) $post->ID,
						'title'     => $title,
						'date'      => get_the_date( 'Y-m-d', $post ),
						'permalink' => get_permalink( $post ),
					);
				}
			}
		}

		return rest_ensure_response(
			new WP_REST_Response(
				array( 'ok' => true, 'books' => $books, 'category_id' => $category_id ),
				200
			)
		);
	}

	/**
	 * 复盘栏目日期查重：只查标题日期，不查内容。
	 *
	 * 标题格式：YYYY-MM-DD A股市场：副标题（副标题内容后取，每日唯一性由日期保证）。
	 *
	 * @param string $title 文章标题。
	 * @return array
	 */
	private static function review_date_duplicate( $title ) {
		if ( ! preg_match( '/(\d{4}-\d{2}-\d{2})/', (string) $title, $m ) ) {
			return array( 'duplicate' => false );
		}
		$date = $m[1];
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_type='post' " .
				"AND post_status IN ('publish','future','draft') LIMIT 1",
				'%' . $wpdb->esc_like( $date ) . '%'
			)
		);
		if ( $id ) {
			return array(
				'duplicate'       => true,
				'date'            => $date,
				'similar_post_id' => (int) $id,
				'similar_title'   => get_the_title( $id ),
				'distance'        => 0,
			);
		}
		return array( 'duplicate' => false );
	}

	/**
	 * 统一错误响应（中文错误信息）。
	 *
	 * @param string $message 错误信息。
	 * @param int    $status  HTTP 状态码。
	 * @param string $task_id 任务 ID。
	 * @param string $column  栏目。
	 * @param string $action  动作名。
	 * @return WP_REST_Response
	 */
	private static function error( $message, $status = 400, $task_id = '', $column = '', $action = 'error' ) {
		abp_log_write( $task_id, $column, $action, 'fail', $message );
		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => $message,
				),
				$status
			)
		);
	}
}
