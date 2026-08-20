<?php
/**
 * A-Blog 发布执行器
 *
 * 职责（总纲 1.2 第 7 步）：REST 层校验通过后，
 *   wp_insert_post 建文 → 分类（slug 匹配，不存在则创建）→ 标签打标 →
 *   SEO Meta 描述 → 封面图（base64/URL → 媒体库 → 特色图）→ 草稿/发布/定时。
 * 5xx/网络类失败由 Python 侧重试，插件侧"尽力而为"，关键节点写 wp_abp_log。
 *
 * 青简主题适配（总纲第 7 节）：
 *   - 正文 HTML 由 Python 侧生成 h2/h3 层级、p、blockquote、pre>code；
 *     本类以 wp_kses_post 白名单放行（pre/code/h2/h3/blockquote 均在默认白名单内）；
 *   - 封面 1280×720 WebP 由 Python 侧处理完成，本类只负责上传媒体库并绑定特色图；
 *   - 关键数据 <strong> 加粗由内容生成侧保证，本类不干预。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Publish {

	/**
	 * 分类映射表（对齐博客已有分类，值 = 目标分类名）：
	 * 股票 / IT / 国学 / 读书 / 行业。任何别名都归入对应已有分类，绝不产生新分类。
	 * key 为栏目码或常见别名（小写），value 为博客已定义的分类名。
	 */
	private static $category_slug_map = array(
		'stock'       => '股票',
		'a-share-review' => '股票',
		'a股每日复盘' => '股票',
		'a股复盘'     => '股票',
		'复盘'        => '股票',
		'股市'        => '股票',
		'股票'        => '股票',
		'tech'        => 'IT',
		'it技术笔记'  => 'IT',
		'it技术'      => 'IT',
		'技术笔记'    => 'IT',
		'技术'        => 'IT',
		'it-notes'    => 'IT',
		'it'          => 'IT',
		'reading'     => '国学',
		'book'        => '读书',
		'reading-classics' => '国学',
		'读书与国学'  => '国学',
		'读书'        => '读书',
		'国学'        => '国学',
		'书评'        => '读书',
		'industry'    => '行业',
		'行业综述'    => '行业',
		'行业'        => '行业',
	);

	/**
	 * 插件自身设置（abp_settings）。
	 *
	 * @return array
	 */
	private static function get_settings() {
		$defaults = array(
			'image_enabled'   => 'on',
			'publish_enabled' => 'on',
			'flush_cache'     => 'on',
			'max_tags'        => 10,
		);
		$options  = get_option( 'abp_settings', array() );
		return wp_parse_args( is_array( $options ) ? $options : array(), $defaults );
	}

	/**
	 * 主入口：发布一篇成品文章。
	 *
	 * @param array $payload 任务对象（总纲 3.1 契约字段）。
	 * @return array {ok:bool, post_id:int, permalink:string, error?:string}
	 */
	public static function publish( $payload ) {
		$task_id = isset( $payload['task_id'] ) ? sanitize_text_field( (string) $payload['task_id'] ) : '';
		// 重写覆盖：payload 带 post_id 时更新原文章（保留 ID），否则新建。
		$rewrite_post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$column  = isset( $payload['column'] ) ? sanitize_text_field( (string) $payload['column'] ) : '';
		$title   = isset( $payload['final_title'] ) ? sanitize_text_field( (string) $payload['final_title'] ) : '';
		if ( '' === $title && isset( $payload['title'] ) ) {
			$title = sanitize_text_field( (string) $payload['title'] );
		}

		$response = array(
			'ok'        => false,
			'post_id'   => 0,
			'permalink' => '',
			'error'     => '',
		);

		if ( '' === $title ) {
			$response['error'] = '标题不能为空';
			abp_log_write( $task_id, $column, 'create', 'fail', '标题为空，拒绝建文' );
			return $response;
		}

		$content = isset( $payload['content_html'] ) ? (string) $payload['content_html'] : '';
		if ( '' === trim( $content ) && isset( $payload['content'] ) ) {
			$content = (string) $payload['content'];
		}
		// 内链占位修正：/?s=关键词 → 站点完整搜索链接（子目录安装安全，如 /wordpress/?s=…）
		$content = self::fix_internal_links( $content );
		// 正文以 wp_kses_post 白名单放行（青简主题兼容：h2/h3/p/blockquote/pre/code/strong 均在列）。
		$content = wp_kses_post( $content );

		$excerpt = isset( $payload['excerpt'] ) ? sanitize_textarea_field( (string) $payload['excerpt'] ) : '';
		// 摘要策略（翁老规则）：只接受 AI 生成/手工填写的摘要；调用方未提供时留空，
		// 不做「正文前 110 字硬截取」充数（前台由主题兼容层回退星河摘要或显示空）。
		$meta    = isset( $payload['meta_description'] ) ? sanitize_textarea_field( (string) $payload['meta_description'] ) : '';

		// 分类：先按映射 slug 找，再按名字找，最后创建。
		$category  = isset( $payload['category'] ) ? sanitize_text_field( (string) $payload['category'] ) : '';
		$cat_id    = self::resolve_category( $category );
		if ( ! $cat_id ) {
			// 分类解析失败不阻断建文：无分类文章仍可发布，日志标记。
			abp_log_write( $task_id, $column, 'category', 'fail', '分类「' . $category . '」解析失败，文章将无分类' );
		}

		// 标签（智能打标：去重、去 # 号、限长限量）。
		$tags = isset( $payload['tags'] ) && is_array( $payload['tags'] ) ? $payload['tags'] : array();

		// 状态：发布开关关闭 → 一律存草稿（总纲 4「关闭则仅存草稿」）。
		$settings       = self::get_settings();
		$requested_status = isset( $payload['status'] ) ? (string) $payload['status'] : 'draft';
		if ( ! in_array( $requested_status, array( 'draft', 'publish', 'future' ), true ) ) {
			$requested_status = 'draft';
		}
		if ( 'off' === $settings['publish_enabled'] ) {
			$requested_status = 'draft';
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $requested_status,
			'post_type'    => 'post',
			'post_author'  => self::resolve_author(),
		);
		if ( $cat_id ) {
			$postarr['post_category'] = array( $cat_id );
		}

		// 定时发布：status=future 且带 publish_date（ISO8601）→ 转 WP 本地时间。
		if ( 'future' === $requested_status && ! empty( $payload['publish_date'] ) ) {
			$ts = strtotime( (string) $payload['publish_date'] );
			if ( $ts && $ts > 0 ) {
				$postarr['post_date']     = date( 'Y-m-d H:i:s', $ts );
				$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
			}
		}

		// 建文（重写时覆盖更新原文章，保留 post_id）。
		abp_log_write( $task_id, $column, 'create', 'ok', $rewrite_post_id ? '重写覆盖文章 #' . $rewrite_post_id . '：' . $title : '开始建文：' . $title );
		if ( $rewrite_post_id ) {
			$postarr['ID'] = $rewrite_post_id;
			$upd = wp_update_post( $postarr, true );
			if ( is_wp_error( $upd ) ) {
				$response['error'] = '重写更新失败：' . $upd->get_error_message();
				abp_log_write( $task_id, $column, 'create', 'fail', $response['error'] );
				return $response;
			}
			$post_id = $rewrite_post_id;
		} else {
			$post_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $post_id ) ) {
				$response['error'] = '建文失败：' . $post_id->get_error_message();
				abp_log_write( $task_id, $column, 'create', 'fail', $response['error'] );
				return $response;
			}
			$post_id = (int) $post_id;
		}

		// 标签。
		if ( ! empty( $tags ) ) {
			$clean_tags = self::sanitize_tags( $tags, (int) $settings['max_tags'] );
			if ( ! empty( $clean_tags ) ) {
				wp_set_post_tags( $post_id, $clean_tags );
			}
		}

		// SEO Meta 描述：有 Yoast 用 _yoast_wpseo_metadesc，有 RankMath 用 rank_math_description，
		// 否则用通用 _abp_meta_description（可被 SEO 插件/主题识别，注释说明适配策略）。
		if ( '' !== $meta ) {
			if ( defined( 'WPSEO_VERSION' ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta );
			} elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
				update_post_meta( $post_id, 'rank_math_description', $meta );
			} else {
				update_post_meta( $post_id, '_abp_meta_description', $meta );
			}
		}

		// 封面图（配图开关关闭则跳过）。
		$image_ok = true;
		if ( 'on' === $settings['image_enabled'] && ! empty( $payload['featured_image'] ) ) {
			$att_id   = self::attach_featured_image( $post_id, $title, $payload['featured_image'] );
			$image_ok = ( false !== $att_id );
			if ( false !== $att_id ) {
				set_post_thumbnail( $post_id, $att_id );
			}
		}

		// 指纹入库（查重体系：建文成功即登记，后续 /check 与发布前查重可命中）。
		$plain = wp_strip_all_tags( $content );
		abp_fingerprint_save( $post_id, abp_simhash( $plain ), $title );

		// 记录任务标识（供发布后附加内容事件写日志用）。
		if ( '' !== $task_id ) {
			update_post_meta( $post_id, '_abp_task_id', $task_id );
		}
		if ( '' !== $column ) {
			update_post_meta( $post_id, '_abp_column', $column );
		}

		// 文章完成附加内容（摘要/评论/话题开关，v1.5.51）：
		// 发布成功后调度一次性 WP-Cron 事件延迟执行（约 30 秒后），
		// 避免拖长 REST 发布请求（Python 侧超时 30s）；失败不阻断发布（尽力而为）。
		$extras_settings = ABP_Settings::get_settings();
		$want_extras = ( 'on' === ( isset( $extras_settings['summary_enabled'] ) ? $extras_settings['summary_enabled'] : 'on' ) )
			|| ( 'on' === ( isset( $extras_settings['comments_enabled'] ) ? $extras_settings['comments_enabled'] : 'off' ) )
			|| ( 'on' === ( isset( $extras_settings['topics_enabled'] ) ? $extras_settings['topics_enabled'] : 'off' ) );
		if ( $want_extras && ! wp_next_scheduled( 'abp_after_publish_extras', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 30, 'abp_after_publish_extras', array( $post_id ) );
		}

		// AI 配图（v1.5.52 异步机制）：image_enabled 开且文章尚无特色图 → 自动排队生成。
		// 翁老规则：自动发布也必须配图（之前仅手动按钮触发，按钮移除后发布文章再无配图）。
		// 与 extras 同机制，由一次性 WP-Cron 事件（abp_ai_cover_job）后台生图，不拖长 REST 请求。
		if ( 'on' === $settings['image_enabled'] && ! get_post_thumbnail_id( $post_id )
		     && ! wp_next_scheduled( 'abp_ai_cover_job', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 10, 'abp_ai_cover_job', array( $post_id ) );
			abp_log_write( $task_id, $column, 'cover', 'queued', 'AI 配图已排队 post_id=' . $post_id );
		}

		// 缓存刷新钩子（总纲 7 适配缓存插件：预留钩子，可按设置执行）。
		if ( 'on' === $settings['flush_cache'] ) {
			self::flush_cache( $post_id );
		}
		// 通用钩子：供其他插件/主题扩展（如推送 CDN purge）。
		do_action( 'abp_after_publish', $post_id, $payload );

		$response['ok']        = true;
		$response['post_id']   = $post_id;
		$response['permalink'] = get_permalink( $post_id );

		$img_note = $image_ok ? '' : '（配图失败，详见日志）';
		abp_log_write( $task_id, $column, 'publish', 'ok', '发布完成：' . $response['permalink'] . $img_note );

		return $response;
	}

	/**
	 * 复盘日期查重：标题含该复盘日（兼容 ISO「2026-08-10」与中文「2026年8月10日」格式）
	 * 且状态为 publish/future/draft 的文章即视为已写。
	 * 翁老规则：已有该复盘日的文章 = 对结果不满意 → 删除旧文覆盖重做。
	 *
	 * @param string $title 标题或日期串（如 2026-08-10）。
	 * @return array{duplicate:bool, date?:string, similar_post_id?:int, similar_title?:string, distance?:int}
	 */
	public static function review_date_duplicate( $title ) {
		if ( ! preg_match( '/(\d{4})\s*[-年]\s*(\d{1,2})\s*[-月]\s*(\d{1,2})/u', (string) $title, $m ) ) {
			return array( 'duplicate' => false );
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
		$variants = array(
			sprintf( '%04d-%02d-%02d', $y, $mo, $d ),
			sprintf( '%04d年%d月%d日', $y, $mo, $d ),
			sprintf( '%04d年%02d月%02d日', $y, $mo, $d ),
		);
		global $wpdb;
		$likes = array();
		foreach ( $variants as $v ) {
			$likes[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $v ) . '%' );
		}
		$id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type='post' " .
			"AND post_status IN ('publish','future','draft') AND (" . implode( ' OR ', $likes ) . ') LIMIT 1'
		);
		if ( $id ) {
			return array(
				'duplicate'       => true,
				'date'            => $variants[0],
				'similar_post_id' => (int) $id,
				'similar_title'   => get_the_title( $id ),
				'distance'        => 0,
			);
		}
		return array( 'duplicate' => false );
	}

	/**
	 * 解析分类：映射 slug → 按 slug 找 → 按名字找 → 创建。
	 *
	 * @param string $category 分类名（如「A股每日复盘」）。
	 * @return int 分类 term_id，0 表示失败。
	 */
	private static function fix_internal_links( $content ) {
		if ( ! $content || false === strpos( $content, '/?s=' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/href=\s*["\']\/?\?s=([^"\'\s]+)["\']/',
			function ( $m ) {
				$kw  = urldecode( $m[1] );
				$url = home_url( '/?s=' . rawurlencode( $kw ) );
				return 'href="' . esc_url( $url ) . '"';
			},
			$content
		);
	}

	private static function resolve_category( $category ) {
		$category = trim( (string) $category );
		if ( '' === $category ) {
			return 0;
		}

		// ① 映射表 → 目标分类名（对齐博客已有分类）。
		$key = mb_strtolower( $category, 'UTF-8' );
		if ( isset( self::$category_slug_map[ $key ] ) ) {
			$target = self::$category_slug_map[ $key ];
			// 按目标分类名匹配。
			$term = get_term_by( 'name', $target, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
			// 按目标 slug 匹配（英文 slug，如 it / a-share-review）。
			$term = get_term_by( 'slug', sanitize_title( $target ), 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
			// 目标分类不存在（被删除）：回退默认分类，不创建新分类。
			return (int) get_option( 'default_category', 1 );
		}

		// ② 无映射：仅按名字匹配已有分类，绝不创建新分类。
		$term = get_term_by( 'name', $category, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		return (int) get_option( 'default_category', 1 );
	}

	/**
	 * 标签清洗：去 # 号、trim、去重、限长（50 字符）、限量。
	 *
	 * @param array $tags     原始标签数组。
	 * @param int   $max_tags 上限数量。
	 * @return array 清洗后的标签数组。
	 */
	private static function sanitize_tags( $tags, $max_tags = 10 ) {
		$clean = array();
		foreach ( (array) $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			$tag = ltrim( $tag, '#' );
			$tag = trim( $tag );
			if ( '' === $tag ) {
				continue;
			}
			$tag = function_exists( 'mb_substr' ) ? mb_substr( $tag, 0, 50, 'UTF-8' ) : substr( $tag, 0, 50 );
			if ( ! in_array( $tag, $clean, true ) ) {
				$clean[] = $tag;
			}
			if ( count( $clean ) >= $max_tags ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * 解析发布作者：默认用站点管理员，避免以当前（可能是 CLI/系统）身份建文。
	 *
	 * @return int
	 */
	private static function resolve_author() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}
		return (int) get_current_user_id();
	}

	/**
	 * 封面图：base64 data URI 或 http(s) URL → 媒体库附件 → 返回附件 ID。
	 *
	 * 依赖 WP 媒体函数：media_handle_sideload（wp-admin/includes/media.php, file.php, image.php）。
	 * 失败不抛异常，返回 false 并写日志；发布流程继续（无图发布）。
	 *
	 * @param int    $post_id 文章 ID（媒体归属）。
	 * @param string $title   文章标题（用于生成文件名）。
	 * @param string $image   base64 data URI 或 URL。
	 * @return int|false 附件 ID 或 false。
	 */
	public static function attach_featured_image( $post_id, $title, $image ) {
		$image = trim( (string) $image );
		if ( '' === $image ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = '';
		$name     = '';

		// ① base64 data URI：data:image/webp;base64,xxxx
		if ( 0 === strpos( $image, 'data:' ) && false !== strpos( $image, ';base64,' ) ) {
			$parts  = explode( ',', $image, 2 );
			$header = $parts[0]; // data:image/webp;base64
			$b64    = isset( $parts[1] ) ? $parts[1] : '';
			$mime   = '';
			if ( preg_match( '#^data:([a-z0-9/+.\-]+);#i', $header, $mm ) ) {
				$mime = strtolower( $mm[1] );
			}
			$ext      = self::mime_to_ext( $mime );
			$bin_data = base64_decode( $b64, true );
			if ( false === $bin_data || '' === $bin_data ) {
				abp_log_write( '', '', 'image', 'fail', 'base64 图片解码失败' );
				return false;
			}
			$tmp_file = self::make_tmp_file( $bin_data );
			$name     = sanitize_file_name( ( '' !== $title ? sanitize_title( $title ) : 'featured' ) . '.' . $ext );
		} else {
			// ② http(s) URL：download_url 拉取到临时文件（失败回退原 URL 名）。
			$url = esc_url_raw( $image );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				abp_log_write( '', '', 'image', 'fail', '不支持的图片来源：' . substr( $image, 0, 80 ) );
				return false;
			}
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				abp_log_write( '', '', 'image', 'fail', '图片下载失败：' . $tmp->get_error_message() );
				return false;
			}
			$ext      = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ? wp_parse_url( $url, PHP_URL_PATH ) : '', PATHINFO_EXTENSION ) );
			$tmp_file = $tmp;
			$name     = sanitize_file_name( ( '' !== $title ? sanitize_title( $title ) : 'featured' ) . ( $ext ? '.' . $ext : '.webp' ) );
		}

		if ( ! $tmp_file || ! file_exists( $tmp_file ) ) {
			abp_log_write( '', '', 'image', 'fail', '临时图片文件不可用' );
			return false;
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp_file,
		);

		$att_id = media_handle_sideload( $file_array, $post_id, $title );
		// 清理临时文件（media_handle_sideload 成功后已移动文件，残留则删除）。
		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file );
		}

		if ( is_wp_error( $att_id ) ) {
			abp_log_write( '', '', 'image', 'fail', '附件入库失败：' . $att_id->get_error_message() );
			return false;
		}

		return (int) $att_id;
	}

	/**
	 * MIME → 扩展名。
	 *
	 * @param string $mime MIME 类型。
	 * @return string 扩展名（默认 webp）。
	 */
	private static function mime_to_ext( $mime ) {
		$map = array(
			'image/webp'  => 'webp',
			'image/jpeg'  => 'jpg',
			'image/jpg'   => 'jpg',
			'image/png'   => 'png',
			'image/gif'   => 'gif',
			'image/avif'  => 'avif',
			'image/bmp'   => 'bmp',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'webp';
	}

	/**
	 * 写临时文件（uploads 目录下，前缀 abp-）。
	 *
	 * @param string $bin 二进制数据。
	 * @return string 临时文件绝对路径，失败返回空串。
	 */
	private static function make_tmp_file( $bin ) {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return '';
		}
		$dir = $upload_dir['path'];
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$file = trailingslashit( $dir ) . 'abp-' . wp_generate_password( 12, false ) . '.tmp';
		$ok   = file_put_contents( $file, $bin );
		if ( false === $ok ) {
			return '';
		}
		return $file;
	}

	/**
	 * 文章完成附加内容（v1.5.51 摘要/评论/话题开关）——WP-Cron 事件处理器。
	 *
	 * 由 publish() 调度的一次性事件触发（abp_after_publish_extras，约发布后 30 秒）：
	 *   - summary_enabled=on 且文章无摘要 → AI 生成摘要（post_excerpt + meta description）
	 *   - comments_enabled=on → 生成 5 条 AI 评论（状态遵循「评论必须经人工批准」设置）
	 *   - topics_enabled=on → 提炼 2 个热门话题并归档（abp_topic 分类法）
	 *
	 * 失败不阻断发布（尽力而为）；每个动作写 wp_abp_log 供后台查看。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public static function run_after_publish_extras( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return;
		}
		$settings = ABP_Settings::get_settings();
		$title    = get_the_title( $post_id );
		$task_id  = (string) get_post_meta( $post_id, '_abp_task_id', true );
		$column   = (string) get_post_meta( $post_id, '_abp_column', true );

		// 摘要开关：开启且文章无摘要 → AI 生成（翁老规则：只接受 AI 生成/手工填写，不硬截取正文充数）。
		if ( 'on' === ( isset( $settings['summary_enabled'] ) ? $settings['summary_enabled'] : 'on' ) ) {
			$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
			if ( '' === $excerpt ) {
				$r = ABP_Toolbox::generate_summary( $post_id, true );
				if ( isset( $r['ok'] ) && $r['ok'] ) {
					abp_log_write( $task_id, $column, 'summary', 'ok', 'AI 摘要自动生成 post_id=' . $post_id );
				} else {
					abp_log_write( $task_id, $column, 'summary', 'fail', 'AI 摘要生成失败：' . ( isset( $r['error'] ) ? $r['error'] : '未知' ) );
				}
			}
		}

		// 评论开关：开启 → 生成 5 条 AI 评论（遵循评论审批设置）。
		if ( 'on' === ( isset( $settings['comments_enabled'] ) ? $settings['comments_enabled'] : 'off' ) ) {
			$r = ABP_Toolbox::generate_comments( $post_id, 5, null );
			if ( isset( $r['ok'] ) && $r['ok'] ) {
				abp_log_write( $task_id, $column, 'comments', 'ok', 'AI 评论自动生成 post_id=' . $post_id . ' 条数=' . $r['inserted'] );
			} else {
				abp_log_write( $task_id, $column, 'comments', 'fail', 'AI 评论生成失败：' . ( isset( $r['error'] ) ? $r['error'] : '未知' ) );
			}
		}

		// 话题开关：开启 → 提炼 2 个热门话题并归档。
		if ( 'on' === ( isset( $settings['topics_enabled'] ) ? $settings['topics_enabled'] : 'off' ) ) {
			$r = ABP_Toolbox::generate_topics( $post_id, 2 );
			if ( isset( $r['ok'] ) && $r['ok'] ) {
				abp_log_write( $task_id, $column, 'topics', 'ok', 'AI 话题自动生成 post_id=' . $post_id . ' 话题=' . implode( ',', (array) $r['topics'] ) );
			} else {
				abp_log_write( $task_id, $column, 'topics', 'fail', 'AI 话题生成失败：' . ( isset( $r['error'] ) ? $r['error'] : '未知' ) );
			}
		}
	}

	/**
	 * 发布后缓存刷新（可选）：wp_cache_flush + 常见缓存插件清理函数 + 通用钩子。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	private static function flush_cache( $post_id ) {
		// WP 内置对象缓存。
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		// WP Super Cache / W3TC 等通用清理。
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		// LiteSpeed Cache。
		if ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all( 'A-Blog publish' );
		}
		// WP Rocket。
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		// 通用钩子：第三方缓存/CDN 插件可挂接。
		do_action( 'abp_flush_cache', $post_id );
	}
}
