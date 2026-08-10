<?php
/**
 * A-Blog AI 工具箱（翁老需求，参照「星河AI工具箱」插件功能形态）：
 *   1. AI 摘要 —— 对已发布文章生成摘要（post_excerpt + Meta 描述）
 *   2. AI 评论 —— 对文章生成 N 条自然评论（可选游客/注册身份，状态可配）
 *   3. 热门话题 —— 分析文章生成话题，注册自定义分类法 topic 并归档文章
 *
 * AI 调用：复用模型探测结果（青简主题 qy_ai_api_key → 插件自身配置），
 * 统一走 OpenAI 兼容 /chat/completions（DeepSeek 等）。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Toolbox {

	/** 话题分类法 slug。 */
	const TOPIC_TAX = 'abp_topic';

	/**
	 * 注册话题分类法（文章归属话题，前台可归档）。
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TOPIC_TAX,
			'post',
			array(
				'label'        => '热门话题',
				'public'       => true,
				'hierarchical' => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'topic' ),
			)
		);
	}

	/**
	 * 注册话题文章类型（thread，兼容原星河AI工具箱的数据结构）。
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			'thread',
			array(
				'public'       => true,
				'label'        => '话题',
				'labels'       => array(
					'name'          => '话题',
					'singular_name' => '话题',
					'menu_name'     => '话题',
					'add_new'       => '添加话题',
					'edit_item'     => '编辑话题',
					'view_item'     => '查看话题',
					'search_items'  => '搜索话题',
					'all_items'     => '全部话题',
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'comments', 'excerpt' ),
				'menu_icon'    => 'dashicons-format-chat',
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'thread' ),
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * 统一 AI 调用（OpenAI 兼容）。返回 {"ok": bool, "text"?: str, "error"?: str}。
	 *
	 * @param array  $messages  消息数组。
	 * @param int    $max_tokens 最大 token。
	 * @param float  $temperature 温度。
	 * @return array
	 */
	public static function ai_chat( $messages, $max_tokens = 512, $temperature = 0.7 ) {
		$models = abp_get_models();
		$key    = isset( $models['deepseek_api_key'] ) ? (string) $models['deepseek_api_key'] : '';
		if ( '' === trim( $key ) ) {
			return array( 'ok' => false, 'error' => '未配置模型 API Key（主题 AI 设置或插件配置）' );
		}
		$model = 'deepseek-v4-flash';
		if ( ! empty( $models['models']['stock'] ) ) {
			$model = (string) $models['models']['stock'];
		}
		$payload = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'stream'      => false,
			'thinking'    => array( 'type' => 'disabled' ),
		);
		$resp = wp_remote_post(
			'https://api.deepseek.com/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return array( 'ok' => false, 'error' => $err );
		}
		$text = isset( $body['choices'][0]['message']['content'] ) ? trim( (string) $body['choices'][0]['message']['content'] ) : '';
		if ( '' === $text && isset( $body['choices'][0]['message']['reasoning_content'] ) ) {
			$text = trim( (string) $body['choices'][0]['message']['reasoning_content'] );
		}
		if ( '' === $text ) {
			return array( 'ok' => false, 'error' => '模型未返回内容' );
		}
		return array( 'ok' => true, 'text' => $text );
	}

	/**
	 * AI 生成文章封面（插件本地生图，OpenAI 兼容 /images/generations）。
	 * 配置来源：abp_get_models()['image_api']（设置页「图片 API 配置」）。
	 * 图片生成后自动上传媒体库并设为特色图。
	 *
	 * @param int $post_id 文章 ID。
	 * @return array{ok:bool, post_id?:int, attachment_id?:int, error?:string}
	 */
	public static function generate_cover( $post_id ) {
		$models = abp_get_models();
		$img    = ( isset( $models['image_api'] ) && is_array( $models['image_api'] ) ) ? $models['image_api'] : array();
		$provider = strtolower( isset( $img['provider'] ) ? (string) $img['provider'] : '' );
		$key    = isset( $img['key'] ) ? (string) $img['key'] : '';
		$endpoint = isset( $img['endpoint'] ) ? untrailingslashit( (string) $img['endpoint'] ) : '';
		$model  = isset( $img['model'] ) ? (string) $img['model'] : '';
		if ( '' === $provider || '' === trim( $key ) ) {
			return array( 'ok' => false, 'error' => '未配置生图服务（设置页「图片 API 配置」）' );
		}

		$post_id = (int) $post_id;
		$title  = get_the_title( $post_id );
		$plain  = trim( (string) wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
		$plain  = (string) preg_replace( '/\s+/u', ' ', $plain );

		// 画面主体：从标题+正文提炼具象意象词（前置，权重最高）。
		$imagery = self::extract_imagery( $post_id, $title, $plain );
		$prompt  = '博客文章封面图（宽幅 16:9，无文字无水印，构图专业克制）';
		if ( ! empty( $imagery ) ) {
			$prompt .= '。画面主体：' . implode( '、', $imagery );
		}
		$prompt .= '。文章主题：' . $title;
		if ( '' !== $plain ) {
			$prompt .= '。文章内容：' . mb_substr( $plain, 0, 300 );
		}

		// 复盘/股市特判：行情数据本身无差异化意象，明确引导模型画当日热点板块的具象场景，避免 K 线模板。
		if ( self::is_stock_post( $post_id ) ) {
			$prompt .= '。画面以当日市场热点板块或盘面特点的具象意象为主，避免千篇一律的 K 线图、蜡烛图、货币符号构图';
		}

		$style = self::cover_style( $post_id );
		if ( '' !== $style ) {
			// 风格词弱化：仅作氛围参考，避免压过内容。
			$prompt .= '。整体氛围可参考：' . $style;
		}
		$prompt = mb_substr( $prompt, 0, 1000 );

		// 按服务商分派：阿里百炼（DashScope 原生异步接口） / OpenAI 兼容（dall-e 等）。
		if ( in_array( $provider, array( 'dashscope', 'bailian', 'aliyun', 'wanx', 'qwen-image' ), true ) ) {
			$r = self::dashscope_generate( $endpoint, $key, $model, $prompt );
		} else {
			$r = self::openai_generate( $endpoint, $key, $model, $prompt );
		}
		if ( ! $r['ok'] ) {
			return $r;
		}

		$att_id = ABP_Publish::attach_featured_image( $post_id, $title, $r['image'] );
		if ( false === $att_id ) {
			return array( 'ok' => false, 'error' => '图片上传失败（详见任务日志 image 记录）' );
		}
		set_post_thumbnail( $post_id, $att_id );
		abp_log_write( 'toolbox', 'cover', 'cover', 'ok', 'AI 配图 post_id=' . $post_id . ' 附件=' . $att_id );
		return array( 'ok' => true, 'post_id' => $post_id, 'attachment_id' => (int) $att_id );
	}

	/**
	 * OpenAI 兼容生图（同步 /images/generations，返回 b64 或 url）。
	 *
	 * @param string $endpoint 接口基址（默认 https://api.openai.com/v1）。
	 * @param string $key      API Key。
	 * @param string $model    模型名（默认 dall-e-3）。
	 * @param string $prompt   提示词。
	 * @return array{ok:bool, image?:string, error?:string}
	 */
	private static function openai_generate( $endpoint, $key, $model, $prompt ) {
		if ( '' === $endpoint ) {
			$endpoint = 'https://api.openai.com/v1';
		}
		$model = $model ? $model : 'dall-e-3';
		$sizes = array( '1792x1024', '1024x1024' );
		$last_err = '';
		foreach ( $sizes as $size ) {
			$payload = array(
				'model'           => $model,
				'prompt'          => $prompt,
				'n'               => 1,
				'size'            => $size,
				'response_format' => 'b64_json',
			);
			$resp = wp_remote_post(
				$endpoint . '/images/generations',
				array(
					'timeout' => 120,
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return array( 'ok' => false, 'error' => $resp->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 200 !== $code ) {
				$last_err = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
				continue; // 大尺寸失败回落小尺寸
			}
			$data = isset( $body['data'][0] ) && is_array( $body['data'][0] ) ? $body['data'][0] : array();
			if ( ! empty( $data['b64_json'] ) ) {
				return array( 'ok' => true, 'image' => 'data:image/png;base64,' . $data['b64_json'] );
			}
			if ( ! empty( $data['url'] ) ) {
				return array( 'ok' => true, 'image' => (string) $data['url'] );
			}
			$last_err = '生图服务未返回图片';
		}
		return array( 'ok' => false, 'error' => $last_err ? $last_err : '生图失败' );
	}

	/**
	 * 阿里百炼 DashScope 原生文生图（异步任务 + 轮询）。
	 * 接口：POST /api/v1/services/aigc/text2image/image-synthesis（X-DashScope-Async: enable）→ 轮询 /api/v1/tasks/{id}。
	 *
	 * @param string $endpoint 接口基址（默认 https://dashscope.aliyuncs.com，不含 /compatible-mode）。
	 * @param string $key      API Key。
	 * @param string $model    模型名（默认 wanx-v1，兼容 wan2.x-t2i / qwen-image）。
	 * @param string $prompt   提示词。
	 * @return array{ok:bool, image?:string, error?:string}
	 */
	private static function dashscope_generate( $endpoint, $key, $model, $prompt ) {
		if ( '' === $endpoint ) {
			$endpoint = 'https://dashscope.aliyuncs.com';
		}
		$model = $model ? $model : 'wanx-v1';
		$sizes = array( '1280*720', '1024*1024' ); // 站点 banner 尺寸优先，失败回落
		$last_err = '';
		foreach ( $sizes as $size ) {
			$payload = array(
				'model'      => $model,
				'input'      => array( 'prompt' => $prompt ),
				'parameters' => array( 'size' => $size, 'n' => 1 ),
			);
			$resp = wp_remote_post(
				$endpoint . '/api/v1/services/aigc/text2image/image-synthesis',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization'     => 'Bearer ' . $key,
						'Content-Type'      => 'application/json',
						'X-DashScope-Async' => 'enable',
					),
					'body'    => wp_json_encode( $payload ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return array( 'ok' => false, 'error' => $resp->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 200 !== $code ) {
				$last_err = isset( $body['message'] ) ? (string) $body['message'] : ( isset( $body['error'] ) ? wp_json_encode( $body['error'] ) : 'HTTP ' . $code );
				continue;
			}
			$task_id = isset( $body['output']['task_id'] ) ? (string) $body['output']['task_id'] : '';
			if ( '' === $task_id ) {
				$last_err = '未返回任务 ID：' . mb_substr( wp_remote_retrieve_body( $resp ), 0, 200 );
				continue;
			}
			$url = self::dashscope_poll( $endpoint, $key, $task_id );
			if ( '' !== $url ) {
				return array( 'ok' => true, 'image' => $url );
			}
			$last_err = '生成任务超时或失败（' . $model . ' ' . $size . '）';
		}
		return array( 'ok' => false, 'error' => $last_err ? $last_err : '生图失败' );
	}

	/**
	 * 轮询 DashScope 任务（最多 12 次 × 5s ≈ 60s）。
	 *
	 * @param string $endpoint 接口基址。
	 * @param string $key      API Key。
	 * @param string $task_id  任务 ID。
	 * @return string 图片 URL（空=失败）。
	 */
	private static function dashscope_poll( $endpoint, $key, $task_id ) {
		for ( $i = 0; $i < 12; $i++ ) {
			sleep( 5 );
			$resp = wp_remote_get(
				$endpoint . '/api/v1/tasks/' . rawurlencode( $task_id ),
				array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $key ) )
			);
			if ( is_wp_error( $resp ) ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			$out  = isset( $body['output'] ) ? $body['output'] : array();
			$status = isset( $out['task_status'] ) ? (string) $out['task_status'] : '';
			if ( 'SUCCEEDED' === $status ) {
				$results = isset( $out['results'] ) ? $out['results'] : array();
				if ( ! empty( $results[0]['url'] ) ) {
					return (string) $results[0]['url'];
				}
				return '';
			}
			if ( in_array( $status, array( 'FAILED', 'CANCELED', 'UNKNOWN' ), true ) ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * 文章分类 → 封面风格词（AI 配图提示词用）。
	 *
	 * @param int $post_id 文章 ID。
	 * @return string 风格描述（空串=通用）。
	 */
	private static function cover_style( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
		$name  = is_wp_error( $terms ) || empty( $terms ) ? '' : implode( ' ', (array) $terms );
		if ( false !== mb_strpos( $name, 'A股' ) || false !== mb_strpos( $name, '股市' ) ) {
			return '金融数据图表风，冷静克制，深蓝主色';
		}
		if ( false !== mb_strpos( $name, 'IT' ) || false !== mb_strpos( $name, '技术' ) ) {
			return '科技极简风，冷色渐变，几何元素';
		}
		if ( false !== mb_strpos( $name, '书评' ) ) {
			return '书香雅致风，暖色纸质感，简约';
		}
		if ( false !== mb_strpos( $name, '读书' ) || false !== mb_strpos( $name, '国学' ) ) {
			return '水墨国风，留白意境，淡雅';
		}
		if ( false !== mb_strpos( $name, '行业' ) ) {
			return '商务数据信息图风，现代感';
		}
		return '';
	}

	/**
	 * 是否复盘/股市文章。
	 *
	 * @param int $post_id 文章 ID。
	 * @return bool
	 */
	private static function is_stock_post( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
		$name  = is_wp_error( $terms ) || empty( $terms ) ? '' : implode( ' ', (array) $terms );
		return false !== mb_strpos( $name, 'A股' ) || false !== mb_strpos( $name, '股市' );
	}

	/**
	 * 从标题+正文提炼具象视觉意象词（AI 提炼，失败兜底书名号内容）。
	 *
	 * 具象词前置到提示词开头，作为画面主体；避免风格词主导导致同类文章封面雷同。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param string $title   文章标题。
	 * @param string $plain   正文纯文本。
	 * @return string[] 具象词数组（0-5 个）。
	 */
	private static function extract_imagery( $post_id, $title, $plain ) {
		$is_stock = self::is_stock_post( $post_id );
		$system   = '你是插画师。从文章标题和内容中提炼 3-5 个具象的视觉意象词（具体事物或场景，如“齿轮”“星空”“老书房”“雨巷”），用于 AI 绘画提示词。要求：①必须是具象名词或场景，不要抽象词（如“人生”“哲学”“命运”）；②与文章核心内容强相关；③每个 2-6 字；④只输出 JSON 数组，如["齿轮","旧书桌","晨光"]。';
		if ( $is_stock ) {
			$system .= '若文章是股市/行情复盘：优先从当日领涨板块或盘面特点提炼具象场景（如“算力机房”“白酒酒坛”“军工战机”“光伏电站”），不要通用金融元素（K线图、蜡烛图、货币符号、炒股屏幕）。';
		}
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => "文章标题：{$title}\n\n正文（节选）：\n" . mb_substr( $plain, 0, 600 ) . "\n\n请提炼具象视觉意象词（JSON 数组）：",
			),
		);
		$r = self::ai_chat( $messages, 150, 0.4 );
		if ( isset( $r['ok'] ) && $r['ok'] && ! empty( $r['text'] ) ) {
			$text = trim( (string) $r['text'] );
			$text = (string) preg_replace( '/^```(?:json)?/i', '', $text );
			$text = (string) preg_replace( '/```$/', '', $text );
			$text = trim( $text );
			$arr  = json_decode( $text, true );
			if ( ! is_array( $arr ) && preg_match( '/\[[^\]]*\]/s', $text, $mm ) ) {
				$arr = json_decode( $mm[0], true );
			}
			if ( is_array( $arr ) ) {
				$out = array();
				foreach ( $arr as $v ) {
					$v = trim( (string) $v );
					if ( '' !== $v && count( $out ) < 5 ) {
						$out[] = $v;
					}
				}
				if ( count( $out ) >= 2 ) {
					return $out;
				}
			}
		}
		// 兑底：标题中的书名号/引号内容作为具象词。
		$fallback = array();
		if ( preg_match_all( '/[《「"\x{201c}]([^》」"\x{201d}]{2,12})[》」"\x{201d}]/u', $title, $m ) ) {
			foreach ( array_unique( $m[1] ) as $v ) {
				if ( count( $fallback ) < 3 ) {
					$fallback[] = $v;
				}
			}
		}
		return $fallback;
	}

	/**
	 * 文章正文纯文本（截取前 N 字供 AI 分析）。
	 *
	 * @param int $post_id 文章 ID。
	 * @param int $limit   截取长度。
	 * @return string
	 */
	public static function plain_text( $post_id, $limit = 3000 ) {
		$content = get_post_field( 'post_content', $post_id );
		$plain   = trim( wp_strip_all_tags( $content ) );
		$plain   = preg_replace( '/\s+/u', ' ', $plain );
		return mb_substr( $plain, 0, $limit );
	}

	/* =====================================================================
	 * 1) AI 摘要
	 * ===================================================================== */

	/**
	 * 为文章生成摘要并写入（post_excerpt + _abp_meta_description）。
	 *
	 * @param int  $post_id 文章 ID。
	 * @param bool $overwrite 是否覆盖已有摘要。
	 * @return array {"ok", "summary"?, "error"?}
	 */
	public static function generate_summary( $post_id, $overwrite = false ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return array( 'ok' => false, 'error' => '文章不存在' );
		}
		// 翁老规则：已有摘要直接覆盖重新生成（不拦截，与复盘“重做、覆盖”逻辑一致）
		$existing = get_post_field( 'post_excerpt', $post_id );
		$overwrite = true;
		$title   = get_the_title( $post_id );
		$plain   = self::plain_text( $post_id );
		$messages = array(
			array(
				'role'    => 'system',
				'content' => '你是博客内容编辑。为文章生成 60-120 字的中文摘要：概括核心内容、吸引点击、不含 AI 套话。只输出摘要本身。',
			),
			array(
				'role'    => 'user',
				'content' => "文章标题：{$title}\n\n正文（节选）：\n{$plain}\n\n请生成摘要（60-120 字）：",
			),
		);
		$r = self::ai_chat( $messages, 200, 0.6 );
		if ( ! $r['ok'] ) {
			return $r;
		}
		$summary = trim( $r['text'] );
		$summary = preg_replace( '/^["\'「]|["\'」]$/u', '', $summary );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => mb_substr( $summary, 0, 200 ),
			)
		);
		update_post_meta( $post_id, '_abp_meta_description', mb_substr( $summary, 0, 150 ) );
		abp_log_write( 'toolbox', 'summary', 'summary', 'ok', 'AI 摘要生成 post_id=' . $post_id );
		return array( 'ok' => true, 'summary' => $summary );
	}

	/* =====================================================================
	 * 2) AI 评论
	 * ===================================================================== */

	/**
	 * 为文章生成并写入 N 条 AI 评论。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param int    $count   评论条数（1-30）。
	 * @param string $status  评论状态 approved|pending（默认 approved：AI 评论直接显示，不需人工批准）。
	 * @return array {"ok", "inserted"?, "comments"?, "error"?}
	 */
	public static function generate_comments( $post_id, $count = 5, $status = 'approved' ) {
		$post_id = (int) $post_id;
		$count   = max( 1, min( 30, (int) $count ) );
		if ( ! get_post( $post_id ) ) {
			return array( 'ok' => false, 'error' => '文章不存在' );
		}
		$title = get_the_title( $post_id );
		$plain = self::plain_text( $post_id, 2500 );
		$messages = array(
			array(
				'role'    => 'system',
				'content' => '你是博客普通读者。根据文章内容生成自然、口语化、观点多样的中文评论，避免机械套话，可以是赞同、补充或提问。只输出 JSON 数组：["评论1","评论2",...]。',
			),
			array(
				'role'    => 'user',
				'content' => "文章标题：{$title}\n\n正文（节选）：\n{$plain}\n\n请生成 {$count} 条读者评论（JSON 数组）：",
			),
		);
		$r = self::ai_chat( $messages, 600, 0.9 );
		if ( ! $r['ok'] ) {
			return $r;
		}
		$comments = self::parse_json_array( $r['text'] );
		if ( empty( $comments ) ) {
			return array( 'ok' => false, 'error' => '评论解析失败：' . mb_substr( $r['text'], 0, 120 ) );
		}
		$names  = array( '清风徐来', '山间明月', '南窗听雨', '一苇以航', '晚来天欲雪', '行到水穷处', '灯火阑珊', '把酒问青天', '松间照', '纸上谈兵', '路人甲', '茶馆常客' );
		$inserted = 0;
		$time = time() - $count * 600; // 评论时间倒序错开（过去几小时内），避免同一秒
		foreach ( array_slice( $comments, 0, $count ) as $i => $text_c ) {
			$text_c = trim( (string) $text_c );
			if ( '' === $text_c ) {
				continue;
			}
			$comment_data = array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $names[ array_rand( $names ) ],
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_content'      => mb_substr( $text_c, 0, 500 ),
				'comment_approved'     => ( 'approved' === $status ) ? 1 : 0,
				'comment_type'         => 'comment',
				'comment_date'         => gmdate( 'Y-m-d H:i:s', $time + $i * 600 ),
				'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $time + $i * 600 ),
			);
			$cid = wp_insert_comment( $comment_data );
			if ( $cid ) {
				// 本地 SVG 头像（零成本、确定性）：生成文件并记 meta，前端 get_avatar 自动替换。
				$avatar_url = ABP_Avatar::ensure_avatar( $comment_data['comment_author'], $comment_data['comment_author_email'] );
				if ( '' !== $avatar_url ) {
					add_comment_meta( $cid, '_abp_avatar', $avatar_url, true );
				}
				$inserted++;
			}
		}
		abp_log_write( 'toolbox', 'comments', 'comments', 'ok', 'AI 评论生成 post_id=' . $post_id . ' 条数=' . $inserted );
		return array( 'ok' => true, 'inserted' => $inserted, 'comments' => array_slice( $comments, 0, $count ) );
	}

	/* =====================================================================
	 * 3) 热门话题
	 * ===================================================================== */

	/**
	 * 为文章生成话题并归档（abp_topic 分类法）。
	 *
	 * @param int $post_id 文章 ID。
	 * @return array {"ok", "topics"?, "error"?}
	 */
	public static function generate_topics( $post_id, $topic_count = 2 ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return array( 'ok' => false, 'error' => '文章不存在' );
		}
		$title = get_the_title( $post_id );
		$plain = self::plain_text( $post_id, 2000 );
		$messages = array(
			array(
				'role'    => 'system',
				'content' => '你是资深内容运营主编。从文章提炼 1-3 个有深意、有讨论价值的热门话题（像微博热搜话题：观点鲜明、制造讨论、引导互动）。\n要求：\n① 紧扣文章核心观点或关键数据，话题与文章强关联；\n② 有观点、有悬念、有话题性，能引发读者讨论，而非简单关键词；\n③ 4-16 字；\n④ 避免照抄文章标题里的原词，避免泛泛而谈（如“股市分析”“市场观察”这类不要）。\n只输出 JSON 数组：["话题1","话题2"]。',
			),
			array(
				'role'    => 'user',
				'content' => "文章标题：{$title}\n\n正文（节选）：\n{$plain}\n\n请提炼 {$topic_count} 个有深意、能引发讨论的热门话题（JSON 数组）：",
			),
		);
		$r = self::ai_chat( $messages, 200, 0.5 );
		if ( ! $r['ok'] ) {
			return $r;
		}
		$topics = self::parse_json_array( $r['text'] );
		if ( empty( $topics ) ) {
			return array( 'ok' => false, 'error' => '话题解析失败：' . mb_substr( $r['text'], 0, 120 ) );
		}
		$assigned = array();
		$next_seq = 1;
		$existing = get_terms( array( 'taxonomy' => self::TOPIC_TAX, 'hide_empty' => false ) );
		if ( ! is_wp_error( $existing ) ) {
			foreach ( $existing as $et ) {
				if ( preg_match( '/^topic-(\d+)$/', $et->slug, $mm ) ) {
					$next_seq = max( $next_seq, (int) $mm[1] + 1 );
				}
			}
		}
		foreach ( array_slice( $topics, 0, $topic_count ) as $t ) {
			$t = trim( (string) $t );
			if ( '' === $t || mb_strlen( $t ) > 20 ) {
				continue;
			}
			$term = term_exists( $t, self::TOPIC_TAX );
			if ( ! $term ) {
				// IIS 下中文 slug 的归档链接 404（PATH_INFO 编码问题）→ slug 用英文序号 topic-N，name 保持中文
				$term = wp_insert_term( $t, self::TOPIC_TAX, array( 'slug' => 'topic-' . $next_seq ) );
				$next_seq++;
			}
			if ( is_wp_error( $term ) ) {
				continue;
			}
			wp_set_object_terms( $post_id, (int) $term['term_id'], self::TOPIC_TAX, true );
			$assigned[] = $t;
			// 话题简介（term description，归档页展示，避免话题页篇幅偏短）
			if ( empty( get_term_meta( (int) $term['term_id'], '_abp_desc', true ) ) ) {
				self::generate_topic_description( (int) $term['term_id'], $t, $title, $plain );
			}
		}
		abp_log_write( 'toolbox', 'topics', 'topics', 'ok', 'AI 话题 post_id=' . $post_id . ' 话题=' . implode( ',', $assigned ) );
		return array( 'ok' => true, 'topics' => $assigned );
	}

	/**
	 * 生成话题简介（100-200 字，存 term description + _abp_desc meta），供话题归档页展示。
	 *
	 * @param int    $term_id 话题 term ID。
	 * @param string $name    话题名。
	 * @param string $title   文章标题（参考）。
	 * @param string $plain   文章正文节选（参考）。
	 * @return array
	 */
	public static function generate_topic_description( $term_id, $name, $title, $plain ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => '你是内容运营主编。为博客话题写 100-200 字的中文简介：说明该话题关注什么、为什么值得关注、适合什么读者。语气专业、有吸引力、不提具体数字。只输出简介本身。',
			),
			array(
				'role'    => 'user',
				'content' => "话题：「{$name}」\n代表文章：《{$title}》\n正文节选：" . mb_substr( $plain, 0, 800 ) . "\n\n请写话题简介（100-200 字）：",
			),
		);
		$r = self::ai_chat( $messages, 300, 0.7 );
		if ( ! $r['ok'] ) {
			return $r;
		}
		$desc = trim( $r['text'] );
		$desc = preg_replace( '/^["\'“”『』「」]+|["\'“”『』「」]+$/u', '', $desc );
		wp_update_term( $term_id, self::TOPIC_TAX, array( 'description' => $desc ) );
		update_term_meta( $term_id, '_abp_desc', $desc );
		return array( 'ok' => true, 'description' => $desc );
	}

	/* =====================================================================
	 * 工具
	 * ===================================================================== */

	/**
	 * 解析 JSON 数组（容错：提取 [ ... ] 或按行拆分）。
	 *
	 * @param string $text 模型输出。
	 * @return array
	 */
	public static function parse_json_array( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$m = preg_match( '/\[.*\]/s', $text, $mm );
		if ( $m ) {
			$data = json_decode( $mm[0], true );
			if ( is_array( $data ) ) {
				$out = array();
				foreach ( $data as $item ) {
					if ( is_string( $item ) && '' !== trim( $item ) ) {
						$out[] = trim( $item );
					}
				}
				if ( $out ) {
					return $out;
				}
			}
		}
		// 行式兜底
		$out = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( $line, " \t\r\n\"'-、.。[]" );
			if ( mb_strlen( $line ) >= 2 ) {
				$out[] = $line;
			}
		}
		return $out;
	}
}
