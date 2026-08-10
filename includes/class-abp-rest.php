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

		// —— 备用选题池（v1.5.0 本地库：WP 数据库读写，后台 UI 与 Python 服务共用）——
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
			'/pool/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pool_clear' ),
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

		// —— 任务队列（v1.5.0 本地库：后台 UI 管理 + Python 服务创建/回报状态）——
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
			'/tasks',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_tasks_create' ),
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
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_task_get' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_task_delete' ),
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

		// 重写任务（published → queued + run_now，发布端覆盖原文章）。
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})/rewrite',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_task_rewrite' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_task_status' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/tasks/(?P<task_id>[0-9]{8}-[a-z]+-[0-9]{3})/pick',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_task_pick' ),
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
		register_rest_route(
			ABP_API_NAMESPACE,
			'/toolbox/cover',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_toolbox_cover' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
		register_rest_route(
			ABP_API_NAMESPACE,
			'/toolbox/ai-cover',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_toolbox_ai_cover' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
			)
		);
	}

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
		// 状态遵循「评论必须经人工批准」设置（翁老规则：开启则 AI 评论也进待审）。
		$status = is_array( $body ) && ! empty( $body['status'] ) ? sanitize_key( $body['status'] ) : null;
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
	 * POST /toolbox/ai-cover —— AI 生成封面（插件本地生图，逐篇调用）。body: {post_id}。
	 * 使用设置页「图片 API 配置」的生图服务，生成后自动上传媒体库并设为特色图。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_toolbox_ai_cover( $request ) {
		$body = $request->get_json_params();
		$pid  = ( is_array( $body ) && ! empty( $body['post_id'] ) ) ? (int) $body['post_id'] : 0;
		if ( ! $pid || ! get_post( $pid ) ) {
			return self::error( 'post_id 必填且文章存在', 400, '', '', 'toolbox' );
		}
		set_time_limit( 0 );
		$r = ABP_Toolbox::generate_cover( $pid );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 502, '', '', 'toolbox' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /toolbox/cover —— 手动配图：为勾选的文章设置封面图。
	 * body: {post_ids: [...], image_url} 或 {post_id, image_url}
	 * image_url 支持 http(s) URL 或 base64 data URI（复用发布层 ABP_Publish::attach_featured_image）。
	 *
	 * @param WP_REST_Request $request 请求对象。
	 * @return WP_REST_Response
	 */
	public static function handle_toolbox_cover( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$image_url = isset( $body['image_url'] ) ? trim( (string) $body['image_url'] ) : '';
		if ( '' === $image_url ) {
			return self::error( 'image_url 必填（http(s) 图片地址或 base64 data URI）', 400, '', '', 'toolbox' );
		}

		$ids = array();
		if ( ! empty( $body['post_ids'] ) && is_array( $body['post_ids'] ) ) {
			foreach ( $body['post_ids'] as $pid ) {
				$id = intval( $pid );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		} elseif ( ! empty( $body['post_id'] ) ) {
			$ids[] = intval( $body['post_id'] );
		}
		if ( ! $ids ) {
			return self::error( 'post_id / post_ids 必填', 400, '', '', 'toolbox' );
		}

		set_time_limit( 0 );
		$results = array();
		foreach ( array_unique( $ids ) as $pid ) {
			$title  = get_the_title( $pid );
			$att_id = ABP_Publish::attach_featured_image( $pid, $title, $image_url );
			if ( false !== $att_id ) {
				set_post_thumbnail( $pid, $att_id );
				abp_log_write( 'toolbox', 'cover', 'cover', 'ok', '手动配图 post_id=' . $pid . ' 附件=' . $att_id );
				$results[] = array(
					'post_id'      => (int) $pid,
				'ok'           => true,
				'attachment_id'=> (int) $att_id,
			);
			} else {
				$results[] = array(
					'post_id' => (int) $pid,
					'ok'      => false,
					'error'   => '配图失败（详见任务日志 image 记录）',
				);
			}
		}
		return rest_ensure_response( new WP_REST_Response( array(
			'ok'      => true,
			'batch'   => true,
			'results' => $results,
		), 200 ) );
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
		);
		return rest_ensure_response( new WP_REST_Response( $body, 200 ) );
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
				// 完整 Key 与 Endpoint 仅经 Bearer 认证接口回传（供 Python 侧同步采用，不入日志）。
				'key'      => isset( $models['image_api']['key'] ) ? (string) $models['image_api']['key'] : '',
				'endpoint' => isset( $models['image_api']['endpoint'] ) ? (string) $models['image_api']['endpoint'] : '',
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

	/* ================= 备用选题池（v1.5.0 本地库） ================= */

	/**
	 * GET /pool —— 池子列表（排队 + 最近已用）。
	 */
	public static function handle_pool_list() {
		return rest_ensure_response( new WP_REST_Response( ABP_Queue::pool_list(), 200 ) );
	}

	/**
	 * POST /pool —— 加入池子。body: {column, topic, source?}。
	 */
	public static function handle_pool_add( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$r = ABP_Queue::pool_add(
			isset( $body['column'] ) ? $body['column'] : '',
			isset( $body['topic'] ) ? $body['topic'] : '',
			isset( $body['source'] ) ? $body['source'] : 'manual'
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'pool' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * PUT /pool/{id} —— 编辑池子项。body: {topic, column?}。
	 */
	public static function handle_pool_edit( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$r = ABP_Queue::pool_update(
			(int) $request['pool_id'],
			isset( $body['topic'] ) ? $body['topic'] : '',
			isset( $body['column'] ) ? $body['column'] : ''
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'pool' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * DELETE /pool/{id} —— 删除池子项（仅排队）。
	 */
	public static function handle_pool_delete( $request ) {
		$r = ABP_Queue::pool_delete( (int) $request['pool_id'] );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 404, '', '', 'pool' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /pool/reorder —— 重排。body: {ids: [...]}。
	 */
	public static function handle_pool_reorder( $request ) {
		$body = $request->get_json_params();
		$ids  = ( is_array( $body ) && isset( $body['ids'] ) && is_array( $body['ids'] ) ) ? $body['ids'] : array();
		$r = ABP_Queue::pool_reorder( $ids );
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /pool/clear —— 一键清空（保留已用历史）。
	 */
	public static function handle_pool_clear() {
		return rest_ensure_response( new WP_REST_Response( ABP_Queue::pool_clear(), 200 ) );
	}

	/**
	 * POST /pool/fill —— 智能填充（插件本地素材库）。body: {column?, n?}。
	 */
	public static function handle_pool_fill( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$r = ABP_Queue::pool_fill(
			isset( $body['column'] ) ? $body['column'] : '',
			isset( $body['n'] ) ? (int) $body['n'] : null
		);
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * 生成今日任务 ID：YYYYMMDD-column-NNN（当日该栏目序号）。
	 *
	 * @param string $column 栏目码。
	 * @return string
	 */
	private static function next_task_id( $column ) {
		// 时区修复（与 task_list_by_date 同源）：直接用本地日期，否则任务 ID 落到「明天」，
		// 列表按今天查不到 → 「列入计划」后今日任务列表无变化。
		$date = current_time( 'Ymd' );
		$like = $wpdb_prefix_like = $date . '-' . $column . '-%';
		global $wpdb;
		$t = $wpdb->prefix . ABP_Queue::TASKS;
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE task_id LIKE %s", $like ) );
		return $date . '-' . $column . '-' . sprintf( '%03d', $n + 1 );
	}

	/**
	 * POST /pool/{id}/plan —— 列入今日计划（池子项 → 今日任务，标已用）。
	 */
	public static function handle_pool_plan( $request ) {
		global $wpdb;
		$t = $wpdb->prefix . ABP_Queue::POOL;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", (int) $request['pool_id'] ), ARRAY_A );
		if ( ! $row ) {
			return self::error( '池子条目不存在', 404, '', '', 'pool' );
		}
		if ( 'queued' !== $row['status'] ) {
			return self::error( '该条目已用，不可重复列入', 400, '', '', 'pool' );
		}
		$task_id = self::next_task_id( $row['column_name'] );
		$r = ABP_Queue::task_create( $task_id, $row['column_name'], $row['topic'] );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'pool' );
		}
		ABP_Queue::pool_mark_used( (int) $row['id'] );
		return rest_ensure_response( new WP_REST_Response( array( 'ok' => true, 'task' => $r['task'] ), 200 ) );
	}

	/**
	 * POST /pool/{id}/run —— 立即完成（列入计划 + 请求立即执行）。
	 */
	public static function handle_pool_run( $request ) {
		$plan = self::handle_pool_plan( $request );
		$data = $plan->get_data();
		if ( empty( $data['ok'] ) || empty( $data['task']['task_id'] ) ) {
			return $plan;
		}
		return self::handle_task_run_now( $data['task']['task_id'] );
	}

	/* ================= 任务队列（v1.5.0 本地库） ================= */

	/**
	 * GET /tasks —— 今日任务列表（?date=YYYY-MM-DD 可查他日）。
	 */
	public static function handle_tasks_list( $request ) {
		$date = isset( $request['date'] ) ? sanitize_text_field( (string) $request['date'] ) : null;
		return rest_ensure_response( new WP_REST_Response( ABP_Queue::task_list_by_date( $date ), 200 ) );
	}

	/**
	 * GET /tasks/{task_id} —— 查单个任务（前端轮询 / 服务查状态）。
	 */
	public static function handle_task_get( $request ) {
		$row = ABP_Queue::task_get( $request['task_id'] );
		if ( ! $row ) {
			return self::error( '任务不存在', 404, $request['task_id'], '', 'tasks' );
		}
		return rest_ensure_response( new WP_REST_Response( array( 'ok' => true, 'task' => $row ), 200 ) );
	}

	/**
	 * POST /tasks —— 服务创建任务（幂等）。body: {task_id?, column, topic?, candidates?, publish_at?, pool_id?}。
	 */
	public static function handle_tasks_create( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$column = isset( $body['column'] ) ? $body['column'] : '';
		if ( ! in_array( (string) $column, array( 'stock', 'tech', 'reading', 'book', 'industry' ), true ) ) {
			return self::error( 'column 必填（stock/tech/reading/book/industry）', 400, '', '', 'tasks' );
		}
		$task_id = isset( $body['task_id'] ) ? $body['task_id'] : self::next_task_id( $column );
		$r = ABP_Queue::task_create(
			$task_id,
			$column,
			isset( $body['topic'] ) ? $body['topic'] : '',
			isset( $body['candidates'] ) && is_array( $body['candidates'] ) ? $body['candidates'] : array(),
			isset( $body['publish_at'] ) ? $body['publish_at'] : null
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, '', '', 'tasks' );
		}
		if ( ! empty( $body['pool_id'] ) ) {
			ABP_Queue::pool_mark_used( (int) $body['pool_id'] );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /tasks/clear —— 清空（删 queued/skipped）。
	 */
	public static function handle_tasks_clear() {
		return rest_ensure_response( new WP_REST_Response( ABP_Queue::task_clear(), 200 ) );
	}

	/**
	 * DELETE /tasks/{task_id} —— 删除任务（仅 queued/skipped）。
	 */
	public static function handle_task_delete( $request ) {
		$r = ABP_Queue::task_delete( $request['task_id'] );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, $request['task_id'], '', 'tasks' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /tasks/{task_id}/run —— 请求立即执行（run_now=1，服务拉取时优先执行）。
	 */
	public static function handle_task_run_now( $request ) {
		$task_id = ( $request instanceof WP_REST_Request ) ? (string) $request['task_id'] : (string) $request;
		$r = ABP_Queue::task_request_run( $task_id );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, $task_id, '', 'tasks' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /tasks/{id}/rewrite — 重写任务（published→queued+run_now，发布端覆盖原文章）。
	 */
	public static function handle_task_rewrite( $request ) {
		$task_id = ( $request instanceof WP_REST_Request ) ? (string) $request['task_id'] : (string) $request;
		$r = ABP_Queue::task_rewrite( $task_id );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, $task_id, '', 'tasks' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /tasks/{task_id}/status —— 服务回报状态。body: {status, post_id?, error?}。
	 */
	public static function handle_task_status( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$r = ABP_Queue::task_update_status(
			$request['task_id'],
			isset( $body['status'] ) ? $body['status'] : '',
			isset( $body['post_id'] ) ? $body['post_id'] : null,
			isset( $body['error'] ) ? $body['error'] : ''
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, $request['task_id'], '', 'tasks' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}

	/**
	 * POST /tasks/{task_id}/pick —— 指定候选 / 指定选题。body: {topic?} 或 {index?}。
	 */
	public static function handle_task_pick( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$r = ABP_Queue::task_pick(
			$request['task_id'],
			isset( $body['topic'] ) ? $body['topic'] : null,
			isset( $body['index'] ) ? (int) $body['index'] : null
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'], 400, $request['task_id'], '', 'tasks' );
		}
		return rest_ensure_response( new WP_REST_Response( $r, 200 ) );
	}
}
