<?php
/**
 * A-Blog 后台设置页（「AI 自动博客」菜单，总纲原则 6：统一入口）
 *
 * 所有配置集中存于一个 WP option：abp_settings（数组），字段见 DEFAULT_SETTINGS。
 * 页面区块：
 *   1. 运行状态卡（模型探测结果展示：调 abp_get_models，显示当前生效配置来源）
 *   2. 开关列表（AI 写文总开关 / 三栏目独立开关 / 配图 / 发布）
 *   3. 配置表单（settings_fields + submit：篇数、Token 额度、时段、比例、模型映射、图片 API）
 *   4. API Token（生成/显示/复制，Bearer 认证用）
 *   5. 任务日志（wp_abp_log 最近 50 条，AJAX 刷新）
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Settings {

	const PAGE_SLUG = 'ai-auto-blog';
	const OPTION    = 'abp_settings';
	const GROUP     = 'abp_settings_group';

	/**
	 * 默认配置（与总纲 4 调度规则一致）。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'ai_enabled'            => 'on',
			'column_stock_enabled'  => 'on',
			'column_tech_enabled'   => 'on',
			'column_reading_enabled'=> 'on',
			'column_book_enabled'   => 'on',
			'column_industry_enabled' => 'on',
			'image_enabled'         => 'on',
			'publish_enabled'       => 'on',
			'flush_cache'           => 'on',
			'daily_limit'           => 3,      // 每日发文篇数 1-10。
			'daily_token_limit'     => 200000, // 每日 Token 额度。
			'publish_window'        => '09:00-21:00', // 模拟人工时段。
			'deepseek_api_key'      => '',
			'models'                => array(
				'stock'   => 'deepseek-chat',
				'tech'    => 'deepseek-chat',
				'reading' => 'deepseek-chat',
				'image'   => '',
			),
			'image_api'             => array(
				'provider' => '',
				'key'      => '',
				'endpoint' => '',
				'model'    => '',
			),
			'api_token'             => '',
			'max_tags'              => 10,
			// GitHub 自动升级（v1.2.0）。
			'auto_update_enabled'   => 'on',
			'github_owner'          => 'sunclchina',
			'github_repo'           => 'ai-auto-blog-publish',
			'github_token'          => '',    // 可选：GitHub PAT，防 API 限流。
			'rss_urls'              => array(
				// 默认 RSS 源（已实测可用，2026-08-07）：WP 生态 / 建站 / 服务器运维 / 科技。
				'https://wordpress.org/news/feed/',
				'https://wptavern.com/feed',
				'https://www.wpbeginner.com/feed/',
				'https://www.ruanyifeng.com/blog/atom.xml',
				'https://www.digitalocean.com/community/tutorials/feed',
				'https://blog.cloudflare.com/rss/',
				'https://serversforhackers.com/feed',
				'https://www.lxlinux.net/feed',
				'https://sspai.com/feed',
			),
			'tavily_api_key'        => '',        // Tavily 搜索 key（行业综述栏目用）
			'book_catalog_url'      => '',   // 站点图书目录页地址（书评栏目选题源），空=自动探测常见路径
		);
	}

	/**
	 * 读取设置（合并默认值）。
	 *
	 * @return array
	 */
	public static function get_settings() {
		$options = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
	}

	/**
	 * 初始化：菜单 + 设置注册 + 后台脚本 + admin-post/ajax 处理器。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Token 生成（admin-post 表单提交，非 GET 直接操作）。
		add_action( 'admin_post_abp_generate_token', array( __CLASS__, 'handle_generate_token' ) );
		// 日志 AJAX 刷新。
		add_action( 'wp_ajax_abp_log_refresh', array( __CLASS__, 'ajax_log_refresh' ) );
		// 日志清空。
		add_action( 'wp_ajax_abp_log_clear', array( __CLASS__, 'ajax_log_clear' ) );
		// GitHub 自动升级：检查更新。
		add_action( 'wp_ajax_abp_check_update', array( __CLASS__, 'ajax_check_update' ) );
		// AI 工具箱文章列表。
		add_action( 'wp_ajax_abp_toolbox_posts', array( __CLASS__, 'ajax_toolbox_posts' ) );
		// 备选题池栏目：现有文章分类列表。
		add_action( 'wp_ajax_abp_pool_categories', array( __CLASS__, 'ajax_pool_categories' ) );
	}

	/**
	 * 静态资源版本号（文件 mtime，避免浏览器缓存旧版 JS/CSS）。
	 *
	 * @param string $rel 相对插件根路径，如 assets/js/admin.js。
	 * @return string
	 */
	public static function asset_version( $rel ) {
		$file = ABP_PLUGIN_DIR . $rel;
		return file_exists( $file ) ? (string) filemtime( $file ) : ABP_VERSION;
	}

	/**
	 * 添加后台菜单（原则 6：统一入口）。
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			'AI 自动博客',
			'AI 自动博客',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-megaphone',
			58
		);
	}

	/**
	 * 注册设置（sanitize 回调负责全量清洗）。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting( self::GROUP, self::OPTION, array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * 后台资源（仅本页面加载）。
	 *
	 * @param string $hook 当前页面 hook。
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'abp-admin', ABP_PLUGIN_URL . 'assets/css/admin.css', array(), self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'abp-admin', ABP_PLUGIN_URL . 'assets/js/admin.js', array(), self::asset_version( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'abp-admin',
			'abpAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'logNonce'    => wp_create_nonce( 'abp_log_refresh' ),
				'logClearNonce' => wp_create_nonce( 'abp_log_clear' ),
				'logRefresh'  => __( '刷新中…', 'ai-auto-blog-publish' ),
				'copied'      => __( '已复制', 'ai-auto-blog-publish' ),
				'copyFailed'  => __( '复制失败，请手动选择复制', 'ai-auto-blog-publish' ),
				'restUrl'     => home_url( '/index.php?rest_route=/ai-auto-blog/v1/pool' ),
				'tasksUrl'    => home_url( '/index.php?rest_route=/ai-auto-blog/v1/tasks' ),
				'toolboxUrl'  => home_url( '/index.php?rest_route=/ai-auto-blog/v1/toolbox' ),
				'homeUrl'     => home_url( '/' ),
			)
		);
	}

	/**
	 * 设置清洗回调（保存时全量校验）。
	 *
	 * @param array $input 原始提交。
	 * @return array 清洗后的设置。
	 */
	public static function sanitize( $input ) {
		$old     = self::get_settings();
		$input   = is_array( $input ) ? $input : array();
		$clean   = array();

		// 开关类：只允许 on/off。
		foreach ( array( 'ai_enabled', 'column_stock_enabled', 'column_tech_enabled', 'column_reading_enabled', 'column_book_enabled', 'column_industry_enabled', 'image_enabled', 'publish_enabled', 'flush_cache', 'auto_update_enabled' ) as $switch ) {
			$clean[ $switch ] = ( isset( $input[ $switch ] ) && 'on' === $input[ $switch ] ) ? 'on' : 'off';
		}

		// 每日发文篇数：1-10 收敛。
		$clean['daily_limit'] = isset( $input['daily_limit'] ) ? (int) $input['daily_limit'] : 3;
		$clean['daily_limit'] = max( 1, min( 10, $clean['daily_limit'] ) );

		// 每日 Token 额度：非负整数。
		$clean['daily_token_limit'] = isset( $input['daily_token_limit'] ) ? (int) $input['daily_token_limit'] : 200000;
		$clean['daily_token_limit'] = max( 0, $clean['daily_token_limit'] );

		// 发布时段：宽松校验 HH:MM-HH:MM。
		$window = isset( $input['publish_window'] ) ? sanitize_text_field( (string) $input['publish_window'] ) : '09:00-21:00';
		if ( ! preg_match( '/^\d{2}:\d{2}\s*-\s*\d{2}:\d{2}$/', $window ) ) {
			$window = '09:00-21:00';
		}
		$clean['publish_window'] = $window;

		// DeepSeek Key（provider=self 用）：留空表示沿用旧值，绝不强制覆盖。
		$clean['deepseek_api_key'] = isset( $old['deepseek_api_key'] ) ? $old['deepseek_api_key'] : '';
		if ( isset( $input['deepseek_api_key'] ) && '' !== trim( (string) $input['deepseek_api_key'] ) ) {
			$clean['deepseek_api_key'] = sanitize_text_field( (string) $input['deepseek_api_key'] );
		}

		// 统一 AI 模型（A股/IT/国学书评共用；v1.5.7 起不再区分栏目模型）。
		$clean['model'] = isset( $input['model'] ) && '' !== trim( (string) $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : 'deepseek-chat';
		// 兼容旧结构：models 三栏目同步为统一模型，image 废弃置空。
		$clean['models'] = array(
			'stock'   => $clean['model'],
			'tech'    => $clean['model'],
			'reading' => $clean['model'],
			'image'   => '',
		);

		// 图片 API 配置（key 留空沿用旧值）。
		$old_img = isset( $old['image_api'] ) && is_array( $old['image_api'] ) ? $old['image_api'] : array();
		$clean['image_api'] = array(
			'provider' => isset( $input['image_api']['provider'] ) ? sanitize_text_field( (string) $input['image_api']['provider'] ) : ( isset( $old_img['provider'] ) ? $old_img['provider'] : '' ),
			'key'      => isset( $input['image_api']['key'] ) && '' !== trim( (string) $input['image_api']['key'] ) ? sanitize_text_field( (string) $input['image_api']['key'] ) : ( isset( $old_img['key'] ) ? $old_img['key'] : '' ),
			'endpoint' => isset( $input['image_api']['endpoint'] ) ? esc_url_raw( (string) $input['image_api']['endpoint'] ) : ( isset( $old_img['endpoint'] ) ? $old_img['endpoint'] : '' ),
			'model'    => isset( $input['image_api']['model'] ) ? sanitize_text_field( (string) $input['image_api']['model'] ) : ( isset( $old_img['model'] ) ? $old_img['model'] : '' ),
		);

		// API Token：表单不可改，仅生成按钮更新；保存时沿用旧值。
		$clean['api_token'] = isset( $old['api_token'] ) ? $old['api_token'] : '';

		// 标签上限。
		$clean['max_tags'] = isset( $input['max_tags'] ) ? max( 1, min( 30, (int) $input['max_tags'] ) ) : 10;

		// IT 动态选题 RSS 源（每行一个 URL）。
		$clean['rss_urls'] = array();
		if ( isset( $input['rss_urls'] ) && is_string( $input['rss_urls'] ) ) {
			foreach ( preg_split( '/[\r\n]+/', $input['rss_urls'] ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line && filter_var( $line, FILTER_VALIDATE_URL ) ) {
					$clean['rss_urls'][] = esc_url_raw( $line );
				}
			}
		}

		// Tavily 搜索 key（行业综述栏目；留空=沿用旧值，绝不覆盖）。
		$clean['tavily_api_key'] = isset( $old['tavily_api_key'] ) ? $old['tavily_api_key'] : '';
		if ( isset( $input['tavily_api_key'] ) && '' !== trim( (string) $input['tavily_api_key'] ) ) {
			$clean['tavily_api_key'] = sanitize_text_field( (string) $input['tavily_api_key'] );
		}

		// GitHub 自动升级配置（owner/repo 白名单字符；token 留空沿用旧值）。
		$clean['auto_update_enabled'] = ( isset( $input['auto_update_enabled'] ) && 'on' === $input['auto_update_enabled'] ) ? 'on' : 'off';
		$gh_owner = isset( $input['github_owner'] ) ? sanitize_text_field( (string) $input['github_owner'] ) : '';
		$gh_repo  = isset( $input['github_repo'] ) ? sanitize_text_field( (string) $input['github_repo'] ) : '';
		$clean['github_owner'] = preg_match( '/^[A-Za-z0-9-]{1,39}$/', $gh_owner ) ? $gh_owner : 'sunclchina';
		$clean['github_repo']  = preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $gh_repo ) ? $gh_repo : 'ai-auto-blog-publish';
		$clean['github_token'] = isset( $old['github_token'] ) ? $old['github_token'] : '';
		if ( isset( $input['github_token'] ) && '' !== trim( (string) $input['github_token'] ) ) {
			$clean['github_token'] = sanitize_text_field( (string) $input['github_token'] );
		}

		// 站点图书目录页地址（书评选题源；留空自动探测）。
		$clean['book_catalog_url'] = isset( $input['book_catalog_url'] ) ? untrailingslashit( esc_url_raw( (string) $input['book_catalog_url'] ) ) : '';


		return $clean;
	}

	/**
	 * GitHub 自动升级：手动检查更新（AJAX）。
	 *
	 * @return void
	 */
	public static function ajax_check_update() {
		check_ajax_referer( 'abp_log_refresh' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '权限不足' );
		}
		$r = ABP_Updater::force_check();
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( isset( $r['error'] ) ? $r['error'] : '检查失败' );
		}
		wp_send_json_success( $r );
	}

	/**
	 * Token 生成（admin-post 处理器）。
	 *
	 * @return void
	 */
	public static function handle_generate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '权限不足' );
		}
		check_admin_referer( 'abp_generate_token' );

		$settings = self::get_settings();
		$settings['api_token'] = wp_generate_password( 32, false, false );
		update_option( self::OPTION, $settings );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'abp_msg' => 'token_generated' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * 日志 AJAX 刷新（返回日志表格 HTML）。
	 *
	 * @return void
	 */
	public static function ajax_log_refresh() {
		check_ajax_referer( 'abp_log_refresh' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '权限不足' );
		}
		$rows = abp_log_get_recent( 50 );
		wp_send_json_success( array( 'html' => self::render_log_table( $rows, true ) ) );
	}

	/**
	 * 清空任务日志（AJAX）。
	 *
	 * @return void
	 */
	public static function ajax_log_clear() {
		check_ajax_referer( 'abp_log_clear' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '权限不足' );
		}
		$deleted = ABP_Log::clear_all();
		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/**
	 * AI 工具箱：最近文章列表（AJAX）。
	 *
	 * @return void
	 */
	public static function ajax_toolbox_posts() {
		check_ajax_referer( 'abp_log_refresh' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '权限不足' );
		}
		$q = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$list = array();
		foreach ( $q as $p ) {
			$topics = wp_get_post_terms( $p->ID, 'abp_topic', array( 'fields' => 'names' ) );
			if ( is_wp_error( $topics ) ) {
				$topics = array();
			}
			// 兼容星河插件（XHTheme AI Toolbox）数据：
			//  - 摘要：星河存 _xhai_excerpt（生产站 334 篇有），post_excerpt 为空时回退读取
			//  - 话题：星河存 xhai_thread meta + xhai_postparent（thread 文章），abp_topic 为空时回退
			if ( '' === trim( (string) $p->post_excerpt ) ) {
				$xhai = get_post_meta( $p->ID, '_xhai_excerpt', true );
				if ( is_string( $xhai ) && '' !== trim( $xhai ) ) {
					$p->post_excerpt = $xhai;
				}
			}
			if ( empty( $topics ) ) {
				$thread_ids = get_post_meta( $p->ID, 'xhai_thread' );
				$thread_names = array();
				foreach ( (array) $thread_ids as $tid ) {
					$title = get_the_title( (int) $tid );
					if ( $title ) {
						$thread_names[] = $title;
					}
				}
				// 反向：xhai_postparent 在 thread 上指向文章 → 查挂到本文的 thread 标题
				if ( empty( $thread_names ) ) {
					global $wpdb;
					$rows = $wpdb->get_col( $wpdb->prepare(
						"SELECT post_title FROM {$wpdb->posts} p
						 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
						 WHERE pm.meta_key='xhai_postparent' AND pm.meta_value=%d
						 AND p.post_type='thread' AND p.post_status='publish'
						 ORDER BY p.ID LIMIT 6",
						(int) $p->ID
					) );
					foreach ( (array) $rows as $title ) {
						if ( $title ) {
							$thread_names[] = $title;
						}
					}
				}
				$topics = array_slice( $thread_names, 0, 6 );
			}
			$list[] = array(
				'ID'            => (int) $p->ID,
				'post_title'    => mb_substr( $p->post_title, 0, 50 ),
				'post_date'     => get_the_date( '', $p ),
				'has_excerpt'   => '' !== trim( (string) $p->post_excerpt ),
				'comment_count' => (int) get_comments_number( $p->ID ),
				'has_cover'     => (bool) has_post_thumbnail( $p->ID ),
				'topics'        => $topics,
			);
		}
		wp_send_json_success( $list );
	}

	/**
	 * 备选题池栏目：现有文章分类名列表（AJAX）。
	 *
	 * @return void
	 */
	public static function ajax_pool_categories() {
		check_ajax_referer( 'abp_log_refresh' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '权限不足' );
		}
		$cats = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );
		$names = array();
		foreach ( $cats as $c ) {
			if ( '未分类' === $c->name ) {
				continue;
			}
			$names[] = $c->name;
		}
		wp_send_json_success( $names );
	}

	/**

	/**
	 * 渲染页面。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();
		$summary  = abp_get_models_summary();
		$msg      = isset( $_GET['abp_msg'] ) ? sanitize_key( wp_unslash( $_GET['abp_msg'] ) ) : '';
		?>
		<div class="wrap abp-wrap">
			<h1>AI 自动博客 <span class="abp-version">v<?php echo esc_html( ABP_VERSION ); ?></span></h1>

			<?php if ( 'token_generated' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>新 API Token 已生成，请复制保存。</p></div>
			<?php endif; ?>

			<div class="abp-grid">
				<div class="abp-col-main">

					<!-- ③ 配置表单 -->
					<form method="post" action="options.php" class="abp-card" id="abp-settings-form">
						<?php settings_fields( self::GROUP ); ?>

						<h2>① 开关列表</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">AI 写文总开关</th>
								<td><?php self::render_switch( 'ai_enabled', $settings, '关闭后不生成文章' ); ?></td>
							</tr>
							<tr>
								<th scope="row">栏目开关</th>
								<td>
									<?php self::render_switch( 'column_stock_enabled', $settings, '复盘' ); ?>
									<?php self::render_switch( 'column_tech_enabled', $settings, 'IT技术' ); ?>
									<?php self::render_switch( 'column_reading_enabled', $settings, '国学' ); ?>
									<?php self::render_switch( 'column_book_enabled', $settings, '书评' ); ?>
									<?php self::render_switch( 'column_industry_enabled', $settings, '行业分析' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row">配图开关</th>
								<td><?php self::render_switch( 'image_enabled', $settings, '关闭则纯文字发布，不上传封面' ); ?></td>
							</tr>
							<tr>
								<th scope="row">发布开关</th>
								<td><?php self::render_switch( 'publish_enabled', $settings, '关闭=仅存草稿，不直接发布（总纲 4）' ); ?></td>
							</tr>
							<tr>
								<th scope="row">发布后刷新缓存</th>
								<td><?php self::render_switch( 'flush_cache', $settings, 'wp_cache_flush + 常见缓存插件清理钩子' ); ?></td>
							</tr>
						</table>

						<h2>② 调度与模型配置</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="abp_daily_limit">每日发文篇数</label></th>
								<td>
									<input type="number" id="abp_daily_limit" name="abp_settings[daily_limit]" min="1" max="10" value="<?php echo esc_attr( $settings['daily_limit'] ); ?>" class="small-text" /> <span class="description">1-10 篇（默认 3）</span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_daily_token">每日 Token 额度</label></th>
								<td>
									<input type="number" id="abp_daily_token" name="abp_settings[daily_token_limit]" min="0" step="1000" value="<?php echo esc_attr( $settings['daily_token_limit'] ); ?>" class="regular-text" /> <span class="description">超额当日拦截（0 表示不限制）</span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_window">发布时段</label></th>
								<td>
									<input type="text" id="abp_window" name="abp_settings[publish_window]" value="<?php echo esc_attr( $settings['publish_window'] ); ?>" class="regular-text" /> <span class="description">模拟人工时段 HH:MM-HH:MM，发布分钟随机</span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_ds_key">DeepSeek API Key</label></th>
								<td>
									<input type="password" id="abp_ds_key" name="abp_settings[deepseek_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="留空保持不变" />
									<?php $probe_model = abp_get_models(); ?>
									<span class="description">当前：<?php echo esc_html( ! empty( $probe_model['deepseek_api_key'] ) ? '有可用模型' : '无可用模型' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_model">AI 模型</label></th>
								<td>
									<input type="text" id="abp_model" name="abp_settings[model]" value="<?php echo esc_attr( isset( $settings['model'] ) ? $settings['model'] : 'deepseek-chat' ); ?>" class="regular-text" placeholder="deepseek-chat" />
								</td>
							</tr>
							<tr>
								<th scope="row">图片 API 配置<br /><small>（生图服务连接，AI 配图用）</small></th>
								<td>
									<p><label>Provider：</label> <input type="text" name="abp_settings[image_api][provider]" value="<?php echo esc_attr( $settings['image_api']['provider'] ); ?>" class="regular-text" placeholder="openai（兼容端点）/ dashscope（阿里百炼万相）" /></p>
									<p><label>API Key：</label> <input type="password" name="abp_settings[image_api][key]" value="" class="regular-text" autocomplete="new-password" placeholder="留空保持不变" /> <span class="description">当前：<?php echo esc_html( ! empty( $settings['image_api']['key'] ) ? '已配置' : '未配置' ); ?></span></p>
									<p><label>Endpoint：</label> <input type="url" name="abp_settings[image_api][endpoint]" value="<?php echo esc_attr( $settings['image_api']['endpoint'] ); ?>" class="regular-text" placeholder="https://..." /></p>
									<p><label>Model：</label> <input type="text" name="abp_settings[image_api][model]" value="<?php echo esc_attr( $settings['image_api']['model'] ); ?>" class="regular-text" placeholder="如 dall-e-3" /></p>
								</td>
							</tr>
							<tr>
								<th scope="row">自动升级<br /><small>GitHub Release</small></th>
								<td>
									<p><?php self::render_switch( 'auto_update_enabled', $settings, '开启后定期检查 GitHub 最新版本，后台「插件」页出现标准更新提示' ); ?></p>
									<p>
										当前版本 v<?php echo esc_html( ABP_VERSION ); ?>
										<button type="button" class="button button-small" id="abp-check-update">检查更新</button>
										<span id="abp-update-status" class="description"></span>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_max_tags">单篇标签上限</label></th>
								<td><input type="number" id="abp_max_tags" name="abp_settings[max_tags]" min="1" max="30" value="<?php echo esc_attr( $settings['max_tags'] ); ?>" class="small-text" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_book_catalog_url">站点图书目录</label></th>
								<td>
									<input type="url" id="abp_book_catalog_url" name="abp_settings[book_catalog_url]" value="<?php echo esc_attr( ( isset( $settings['book_catalog_url'] ) ? $settings['book_catalog_url'] : '' ) ); ?>" placeholder="https://你的站点/藏书馆书目" class="regular-text" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_rss_urls">IT 动态 RSS 源</label></th>
								<td>
									<textarea id="abp_rss_urls" name="abp_settings[rss_urls]" rows="3" class="large-text code" placeholder="https://example.com/feed/&#10;https://another.com/rss"><?php echo esc_textarea( is_array( ( isset( $settings['rss_urls'] ) ? $settings['rss_urls'] : array() ) ) ? implode( "\n", ( isset( $settings['rss_urls'] ) ? $settings['rss_urls'] : array() ) ) : '' ); ?></textarea>
									<span class="description">IT 技术笔记栏目的动态选题源（RSS/Atom，每行一个）；留空=仅用内置高频问题池</span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_tavily_key">Tavily API Key</label></th>
								<td>
									<input type="password" id="abp_tavily_key" name="abp_settings[tavily_api_key]" value="" class="regular-text" autocomplete="off" /> <span class="description">当前：<?php echo esc_html( ! empty( ( isset( $settings['tavily_api_key'] ) ? $settings['tavily_api_key'] : '' ) ) ? '已配置' : '未配置' ); ?></span>
								</td>
							</tr>
									</p>
								</td>
							</tr>
						</table>

						<?php submit_button( '保存设置' ); ?>
					</form>
				</div>

				<div class="abp-col-side">
					<!-- ④ API Token -->
					<div class="abp-card">
						<h2>API Token（REST 认证）</h2>
						<p class="description">生成引擎以 <code>Authorization: Bearer &lt;token&gt;</code> 调用本插件 REST 接口。</p>
						<p>
							<input type="text" id="abp-token" class="regular-text" readonly value="<?php echo esc_attr( $settings['api_token'] ?: '（尚未生成）' ); ?>" />
						</p>
						<div class="abp-token-actions">
							<button type="button" class="button" id="abp-copy-token" data-target="abp-token">复制 Token</button>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="abp_generate_token" />
								<?php wp_nonce_field( 'abp_generate_token' ); ?>
								<button type="submit" class="button button-secondary">重新生成</button>
							</form>
						</div>
						<p class="description">⚠️ 重新生成后旧 Token 立即失效，请同步更新生成引擎配置。</p>
					</div>

					<!-- 模型探测结果 -->
					<div class="abp-card" id="abp-probe-card">
						<h2>模型探测结果（当前生效）</h2>
						<table class="widefat striped">
							<tbody>
								<tr><th>来源 Provider</th><td><?php echo esc_html( self::provider_label( $summary['provider'] ) ); ?></td></tr>
								<tr><th>来源标识</th><td><?php echo esc_html( $summary['source'] ?: '—' ); ?></td></tr>
								<tr><th>API Key</th><td><?php echo $summary['has_key'] ? '<span class="abp-badge ok">已配置</span> ' . esc_html( $summary['key'] ) : '<span class="abp-badge warn">未配置（任务将无法生成）</span>'; ?></td></tr>
								<tr><th>复盘模型</th><td><?php echo esc_html( $summary['models']['stock'] ); ?></td></tr>
								<tr><th>技术模型</th><td><?php echo esc_html( $summary['models']['tech'] ); ?></td></tr>
								<tr><th>国学模型</th><td><?php echo esc_html( $summary['models']['reading'] ); ?></td></tr>
								<tr><th>图片 API</th><td><?php echo esc_html( ( $summary['image_api']['provider'] ? $summary['image_api']['provider'] . ' / ' : '' ) . ( $summary['image_api']['model'] ?: '未配置' ) ); ?></td></tr>
							</tbody>
						</table>
						<p class="description">探测顺序：青简主题 → 插件自身配置。</p>
					</div>
				</div>
			</div>

			<!-- ④.5 备用选题池（数据存 WP 数据库，插件本地管理，v1.5.0） -->
			<div class="abp-card" id="abp-pool-card">
				<h2>备用选题池（按计划排队，自动供每日任务取用）
					<button type="button" class="button button-small" id="abp-refresh-pool">刷新</button>
					<button type="button" class="button button-small" id="abp-pool-fill">智能填充</button>
					<button type="button" class="button button-small" id="abp-pool-clear" style="color:#b32d2e;">一键清空</button>
				</h2>
				<p class="description">备用题目按序排队，每日任务自动取用；内置素材库一键智能填充（诗词/IT 问题/书单/行业概念），预选题多余候选由生成引擎自动补充入池。可编辑、删除、↑↓ 调整顺序、列入今日计划、立即完成。</p>
				<div id="abp-pool-container"><p class="description">点击「刷新」加载备用选题池…</p></div>
				<div class="abp-pool-add">
					<select id="abp-pool-col"><option value="">加载分类…</option></select>
					<input type="text" id="abp-pool-topic" class="regular-text" placeholder="输入备用选题…（系统自动判断并优化标题）" />
					<button type="button" class="button button-primary" id="abp-pool-add-btn">加入池子</button>
				</div>
				<div id="abp-pool-msg" class="abp-pool-msg"></div>
			</div>

			<!-- ④.7 今日计划任务（删除 / 立即完成 / 清空） -->
			<div class="abp-card" id="abp-plan-card">
				<h2>计划任务
					<button type="button" class="button button-small" id="abp-refresh-plan">刷新</button>
					<button type="button" class="button button-small" id="abp-plan-clear">清空计划</button>
				</h2>
				<p class="description">全部计划任务列表（含已发布/失败/跳过）；单条可立即完成/重写/删除，也可一键清空全部任务。「立即完成」将任务置为优先执行，由生成引擎拉取后生成发布。</p>
				<div id="abp-plan-container"><p class="description">点击「刷新」加载全部计划任务…</p></div>
			</div>

			<!-- ④.8 AI 工具箱（摘要 / 评论 / 热门话题，参照星河AI工具箱形态） -->
			<div class="abp-card" id="abp-toolbox-card">
				<h2>AI 工具箱（摘要 / 评论 / 热门话题 / AI 配图）</h2>
				<p class="description">从下方文章列表勾选（可多选），批量生成摘要 / 评论 / 热门话题，或 AI 生成封面（消耗生图服务额度，走设置页「图片 API 配置」）。状态列显示是否已有相关内容。</p>
				<div class="abp-toolbox-row">
					<button type="button" class="button" id="abp-toolbox-refresh">刷新列表</button>
					<label><input type="checkbox" id="abp-toolbox-all" /> 全选</label>
					<span>已选 <strong id="abp-toolbox-selcount">0</strong> 篇</span>
				</div>
				<div class="abp-toolbox-table-wrap">
					<table class="widefat striped" id="abp-toolbox-table">
						<thead>
							<tr><th class="abp-col-check"><input type="checkbox" id="abp-toolbox-all2" title="全选" /></th><th>文章标题</th><th>日期</th><th>摘要</th><th>评论</th><th>话题</th><th>封面</th></tr>
						</thead>
						<tbody id="abp-toolbox-tbody"><tr><td colspan="7">加载中…</td></tr></tbody>
					</table>
				</div>
				<div class="abp-toolbox-row">
					<button type="button" class="button button-primary" id="abp-toolbox-summary">批量生成摘要</button>
					<span class="abp-toolbox-sep">|</span>
					<button type="button" class="button" id="abp-toolbox-comments">批量生成评论</button>
					<label>条数 <input type="number" id="abp-toolbox-count" min="1" max="30" value="5" class="small-text" /></label>
					<label>状态 <select id="abp-toolbox-cstatus"><option value="pending">待审核</option><option value="approved">直接显示</option></select></label>
					<span class="abp-toolbox-sep">|</span>
					<button type="button" class="button" id="abp-toolbox-topics">批量热门话题</button>
					<span class="abp-toolbox-sep">|</span>
					<button type="button" class="button" id="abp-toolbox-cover" title="AI 自动生成封面并设为文章特色图（走已配置的生图服务，逐篇生成）">AI 配图</button>
				</div>
				<div id="abp-toolbox-result" class="abp-toolbox-result"></div>
			</div>

			<!-- ⑤ 任务日志 -->
			<div class="abp-card" id="abp-log-card">
				<h2>任务日志（最近 50 条）
					<button type="button" class="button button-small" id="abp-refresh-log">刷新</button>
					<button type="button" class="button button-small" id="abp-clear-log">清空日志</button>
				</h2>
				<div id="abp-log-container">
					<?php echo self::render_log_table( abp_log_get_recent( 50 ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染开关（checkbox 样式）。
	 *
	 * @param string $key   设置键。
	 * @param array  $settings 当前设置。
	 * @param string $hint  提示文字。
	 * @return void
	 */
	private static function render_switch( $key, $settings, $hint = '' ) {
		$checked = 'on' === ( isset( $settings[ $key ] ) ? $settings[ $key ] : 'off' );
		?>
		<label class="abp-switch">
			<input type="checkbox" name="abp_settings[<?php echo esc_attr( $key ); ?>]" value="on" <?php checked( $checked ); ?> />
			<span class="abp-slider"></span>
		</label>
		<?php if ( $hint ) : ?>
			<span class="description"><?php echo esc_html( $hint ); ?></span>
		<?php endif; ?>
		<?php
	}

	/**
	 * Provider 中文标签。
	 *
	 * @param string $provider provider 值。
	 * @return string
	 */
	private static function provider_label( $provider ) {
		$map = array(
			'theme'  => '青简主题（theme）',
			'plugin' => '其他插件（plugin）',
			'self'   => '插件自身（self）',
			'none'   => '未配置（none）',
		);
		return isset( $map[ $provider ] ) ? $map[ $provider ] : $provider;
	}

	/**
	 * 渲染日志表格。
	 *
	 * @param array $rows    日志行。
	 * @param bool  $is_ajax 是否 AJAX 局部刷新（true 时不带外层容器）。
	 * @return string HTML。
	 */
	private static function render_log_table( $rows, $is_ajax = false ) {
		ob_start();
		if ( empty( $rows ) ) {
			echo '<p class="description">暂无日志。文章提交后这里会显示接收/查重/建文/发布记录。</p>';
		} else {
			?>
			<table class="widefat striped abp-log-table">
				<thead>
					<tr>
						<th>ID</th><th>任务 ID</th><th>栏目</th><th>动作</th><th>状态</th><th>说明</th><th>时间</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['id'] ); ?></td>
							<td><code><?php echo esc_html( $row['task_id'] ?: '—' ); ?></code></td>
							<td><?php echo esc_html( $row['column'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $row['action'] ); ?></td>
							<td><span class="abp-badge <?php echo esc_attr( 'ok' === $row['status'] ? 'ok' : ( 'fail' === $row['status'] ? 'fail' : 'warn' ) ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
							<td class="abp-log-msg"><?php echo esc_html( $row['message'] ); ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}
		$html = ob_get_clean();

		// AJAX 模式仅返回表格内容（无外层卡片）。
		if ( $is_ajax ) {
			return $html;
		}
		return $html;
	}
}

/**
 * 全局函数：读取设置。
 *
 * @return array
 */
function abp_get_settings() {
	return ABP_Settings::get_settings();
}
