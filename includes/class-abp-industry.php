<?php
/**
 * class-abp-industry.php — 行业综述栏目：Tavily 联网搜索 + 生成发布（v1.5.5）。
 *
 * 数据源：设置页「Tavily API Key」（无 key 时用内置行业概念选题，正文注明数据源限制）。
 * 流程：选题（Tavily 热门行业/概念）→ 深度搜索该行业最新动态 → industry.md 提示词生成正文 → 发布。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Industry {

	/**
	 * 生成并发布一篇行业综述。
	 *
	 * @param array $row 任务行。
	 * @return array{ok:bool, post_id?:int, error?:string}
	 */
	public static function generate( $row ) {
		$topic = trim( (string) $row['topic'] );
		$topic = (string) preg_replace( '/行业：市场前景与景气龙头盘点$/', '', $topic ); // 去掉选题模板后缀
		$topic = trim( $topic );
		if ( '' === $topic ) {
			return array( 'ok' => false, 'error' => '任务选题为空' );
		}

		// 深度搜索该行业最新动态（行情/政策/公司，带数字优先）。
		$hits = ABP_Materials::tavily_search( $topic . ' 行业 市场规模 产业链 龙头公司 最新动态', 6 );
		if ( ! $hits ) {
			$hits = ABP_Materials::tavily_search( $topic . ' 行业 前景 龙头', 4 );
		}

		$prompts = include ABP_PLUGIN_DIR . 'includes/data-prompts.php';
		$prompt  = isset( $prompts['industry'] ) ? $prompts['industry'] : '';

		$material = array(
			'topic'        => $topic,
			'web_results'  => $hits,
			'source_note'  => $hits ? 'Tavily 联网搜索（finance/week）' : 'Tavily 未配置或搜索失败，正文以通用框架编写并注明',
		);
		$user_msg = "选题：{$topic}\n\n联网素材（JSON）：\n" . wp_json_encode( $material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
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

		$title = $topic . '行业：市场全景与景气龙头盘点';
		$payload = array(
			'task_id'      => $row['task_id'],
			'column'       => 'industry',
			'final_title'  => $title,
			'content_html' => $html,
			'excerpt'      => $parsed_excerpt,
			'category'     => '行业',            // 主题已有「行业」分类（翁老：industry 归入行业）
			'tags'         => array( '行业综述', $topic ),
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
}
