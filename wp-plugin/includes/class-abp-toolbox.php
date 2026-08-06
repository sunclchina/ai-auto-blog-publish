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
		if ( ! empty( $models['models']['tech'] ) ) {
			$model = (string) $models['models']['tech'];
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
	 * @param string $status  评论状态 approved|pending。
	 * @return array {"ok", "inserted"?, "comments"?, "error"?}
	 */
	public static function generate_comments( $post_id, $count = 5, $status = 'pending' ) {
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
