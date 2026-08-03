<?php
/**
 * A-Blog 后台设置页（「AI 自动博客」菜单，总纲原则 6：统一入口）
 *
 * 所有配置集中存于一个 WP option：abp_settings（数组），字段见 DEFAULT_SETTINGS。
 * 页面区块：
 *   1. 运行状态卡（模型探测结果展示：调 abp_get_models，显示当前生效配置来源）
 *   2. 开关列表（AI 写文总开关 / 三栏目独立开关 / 配图 / 发布）
 *   3. 配置表单（settings_fields + submit：篇数、Token 额度、时段、比例、模型映射、图片 API、探测表）
 *   4. API Token（生成/显示/复制，Python 侧 Bearer 认证用）
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
			'image_enabled'         => 'on',
			'publish_enabled'       => 'on',
			'flush_cache'           => 'on',
			'daily_limit'           => 3,      // 每日发文篇数 1-10。
			'daily_token_limit'     => 200000, // 每日 Token 额度。
			'publish_window'        => '09:00-21:00', // 模拟人工时段。
			'column_ratio'          => '40:30:30',    // 复盘/技术/国学+书评 比例。
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
			'probe_plugins'         => array(), // 其他已知插件探测表（默认空）。
			'api_token'             => '',
			'max_tags'              => 10,
			'python_base'           => '',   // Python 伴生服务地址，空=默认 http://127.0.0.1:8080
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
		foreach ( array( 'ai_enabled', 'column_stock_enabled', 'column_tech_enabled', 'column_reading_enabled', 'column_book_enabled', 'image_enabled', 'publish_enabled', 'flush_cache' ) as $switch ) {
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

		// 栏目比例：三段数字比例（宽松，仅格式校验）。
		$ratio = isset( $input['column_ratio'] ) ? sanitize_text_field( (string) $input['column_ratio'] ) : '40:30:30';
		if ( ! preg_match( '/^\d{1,3}:\d{1,3}:\d{1,3}$/', $ratio ) ) {
			$ratio = '40:30:30';
		}
		$clean['column_ratio'] = $ratio;

		// DeepSeek Key（provider=self 用）：留空表示沿用旧值，绝不强制覆盖。
		$clean['deepseek_api_key'] = isset( $old['deepseek_api_key'] ) ? $old['deepseek_api_key'] : '';
		if ( isset( $input['deepseek_api_key'] ) && '' !== trim( (string) $input['deepseek_api_key'] ) ) {
			$clean['deepseek_api_key'] = sanitize_text_field( (string) $input['deepseek_api_key'] );
		}

		// 模型映射表（DeepSeek 单 key 多模型分发）。
		$clean['models'] = self::defaults()['models'];
		if ( isset( $input['models'] ) && is_array( $input['models'] ) ) {
			foreach ( array( 'stock', 'tech', 'reading', 'image' ) as $col ) {
				if ( isset( $input['models'][ $col ] ) ) {
					$clean['models'][ $col ] = sanitize_text_field( (string) $input['models'][ $col ] );
				}
			}
		}

		// 图片 API 配置（key 留空沿用旧值）。
		$old_img = isset( $old['image_api'] ) && is_array( $old['image_api'] ) ? $old['image_api'] : array();
		$clean['image_api'] = array(
			'provider' => isset( $input['image_api']['provider'] ) ? sanitize_text_field( (string) $input['image_api']['provider'] ) : ( isset( $old_img['provider'] ) ? $old_img['provider'] : '' ),
			'key'      => isset( $input['image_api']['key'] ) && '' !== trim( (string) $input['image_api']['key'] ) ? sanitize_text_field( (string) $input['image_api']['key'] ) : ( isset( $old_img['key'] ) ? $old_img['key'] : '' ),
			'endpoint' => isset( $input['image_api']['endpoint'] ) ? esc_url_raw( (string) $input['image_api']['endpoint'] ) : ( isset( $old_img['endpoint'] ) ? $old_img['endpoint'] : '' ),
			'model'    => isset( $input['image_api']['model'] ) ? sanitize_text_field( (string) $input['image_api']['model'] ) : ( isset( $old_img['model'] ) ? $old_img['model'] : '' ),
		);

		// 其他已知插件探测表：支持 JSON 数组或每行 source|option_key|theme_mod_key。
		$clean['probe_plugins'] = isset( $old['probe_plugins'] ) && is_array( $old['probe_plugins'] ) ? $old['probe_plugins'] : array();
		if ( isset( $input['probe_plugins_text'] ) ) {
			$clean['probe_plugins'] = self::parse_probe_plugins( (string) $input['probe_plugins_text'] );
		}

		// API Token：表单不可改，仅生成按钮更新；保存时沿用旧值。
		$clean['api_token'] = isset( $old['api_token'] ) ? $old['api_token'] : '';

		// 标签上限。
		$clean['max_tags'] = isset( $input['max_tags'] ) ? max( 1, min( 30, (int) $input['max_tags'] ) ) : 10;

		// Python 伴生服务地址（智能选题中心代理用）。
		$clean['python_base'] = isset( $input['python_base'] ) ? untrailingslashit( esc_url_raw( (string) $input['python_base'] ) ) : '';

		return $clean;
	}

	/**
	 * 解析探测表文本 → 数组。
	 *
	 * @param string $text JSON 或行格式。
	 * @return array
	 */
	private static function parse_probe_plugins( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		// 优先 JSON。
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			$out = array();
			foreach ( $decoded as $item ) {
				if ( is_array( $item ) ) {
					$out[] = array(
						'source'       => isset( $item['source'] ) ? sanitize_text_field( (string) $item['source'] ) : '',
						'option_key'   => isset( $item['option_key'] ) ? sanitize_key( (string) $item['option_key'] ) : '',
						'theme_mod_key'=> isset( $item['theme_mod_key'] ) ? sanitize_key( (string) $item['theme_mod_key'] ) : '',
					);
				}
			}
			return $out;
		}

		// 行格式：source|option_key|theme_mod_key。
		$out = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$out[] = array(
				'source'        => isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '',
				'option_key'    => isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '',
				'theme_mod_key' => isset( $parts[2] ) ? sanitize_key( $parts[2] ) : '',
			);
		}
		return $out;
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
			$list[] = array(
				'ID'            => (int) $p->ID,
				'post_title'    => mb_substr( $p->post_title, 0, 50 ),
				'post_date'     => get_the_date( '', $p ),
				'has_excerpt'   => '' !== trim( (string) $p->post_excerpt ),
				'comment_count' => (int) get_comments_number( $p->ID ),
				'topics'        => is_wp_error( $topics ) ? array() : $topics,
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
				<div class="notice notice-success is-dismissible"><p>新 API Token 已生成，请复制保存（Python 侧 wp_rest.py 同步更新）。</p></div>
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
								<td><?php self::render_switch( 'ai_enabled', $settings, '关闭后 Python 侧不生成任何文章' ); ?></td>
							</tr>
							<tr>
								<th scope="row">栏目开关</th>
								<td>
									<?php self::render_switch( 'column_stock_enabled', $settings, 'A股每日复盘（仅交易日）' ); ?>
									<?php self::render_switch( 'column_tech_enabled', $settings, 'IT技术笔记' ); ?>
									<?php self::render_switch( 'column_reading_enabled', $settings, '读书与国学' ); ?>
									<?php self::render_switch( 'column_book_enabled', $settings, '书评（书目专评）' ); ?>
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
								<th scope="row"><label for="abp_python_base">Python 服务地址</label></th>
								<td>
									<input type="url" id="abp_python_base" name="abp_settings[python_base]" value="<?php echo esc_attr( $settings['python_base'] ); ?>" placeholder="http://127.0.0.1:8080" class="regular-text" /> <span class="description">智能选题中心代理地址，留空=本机 8080</span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_ratio">栏目比例</label></th>
								<td>
									<input type="text" id="abp_ratio" name="abp_settings[column_ratio]" value="<?php echo esc_attr( $settings['column_ratio'] ); ?>" class="small-text" /> <span class="description">格式 复盘:技术:国学+书评（如 40:30:30）</span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_ds_key">DeepSeek API Key</label></th>
								<td>
									<input type="password" id="abp_ds_key" name="abp_settings[deepseek_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="留空保持不变" />
									<span class="description">当前：<?php echo esc_html( ABP_Models::mask_key( $settings['deepseek_api_key'] ) ?: '未配置（将自动探测青简主题 qy_ai_api_key）' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row">模型映射表<br /><small>DeepSeek 单 key 多模型</small></th>
								<td>
									<?php
									$model_labels = array(
										'stock'   => 'A股复盘模型',
										'tech'    => 'IT技术模型',
										'reading' => '国学书评模型',
										'image'   => '生图模型',
									);
									foreach ( $model_labels as $col => $label ) :
										?>
										<p>
											<label for="abp_model_<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $label ); ?>：</label>
											<input type="text" id="abp_model_<?php echo esc_attr( $col ); ?>" name="abp_settings[models][<?php echo esc_attr( $col ); ?>]" value="<?php echo esc_attr( $settings['models'][ $col ] ); ?>" class="regular-text" />
										</p>
									<?php endforeach; ?>
									<span class="description">image 留空表示未配置生图（纯文字发布）</span>
								</td>
							</tr>
							<tr>
								<th scope="row">图片 API 配置<br /><small>（Python 侧生图接口）</small></th>
								<td>
									<p><label>Provider：</label> <input type="text" name="abp_settings[image_api][provider]" value="<?php echo esc_attr( $settings['image_api']['provider'] ); ?>" class="regular-text" placeholder="如 openai / 通义 / 豆包" /></p>
									<p><label>API Key：</label> <input type="password" name="abp_settings[image_api][key]" value="" class="regular-text" autocomplete="new-password" placeholder="留空保持不变" /></p>
									<p><label>Endpoint：</label> <input type="url" name="abp_settings[image_api][endpoint]" value="<?php echo esc_attr( $settings['image_api']['endpoint'] ); ?>" class="regular-text" placeholder="https://..." /></p>
									<p><label>Model：</label> <input type="text" name="abp_settings[image_api][model]" value="<?php echo esc_attr( $settings['image_api']['model'] ); ?>" class="regular-text" placeholder="如 dall-e-3" /></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_probe">其他插件探测表</label><br /><small>（模型探测顺序第②级）</small></th>
								<td>
									<textarea id="abp_probe" name="abp_settings[probe_plugins_text]" rows="4" class="large-text code" placeholder='每行：来源标识|option_key|theme_mod_key&#10;或粘贴 JSON 数组 [{ "source":"xx","option_key":"yy","theme_mod_key":"zz" }]'><?php echo esc_textarea( self::probe_plugins_to_text( $settings['probe_plugins'] ) ); ?></textarea>
									<span class="description">默认空：仅探测青简主题与插件自身。示例行：<code>my-plugin|my_ai_key|</code></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="abp_max_tags">单篇标签上限</label></th>
								<td><input type="number" id="abp_max_tags" name="abp_settings[max_tags]" min="1" max="30" value="<?php echo esc_attr( $settings['max_tags'] ); ?>" class="small-text" /></td>
							</tr>
						</table>

						<?php submit_button( '保存设置' ); ?>
					</form>
				</div>

				<div class="abp-col-side">
					<!-- ④ API Token -->
					<div class="abp-card">
						<h2>API Token（REST 认证）</h2>
						<p class="description">Python 侧以 <code>Authorization: Bearer &lt;token&gt;</code> 调用本插件 REST 接口。</p>
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
						<p class="description">⚠️ 重新生成后旧 Token 立即失效，请同步更新 Python 侧配置。</p>
					</div>

					<!-- 模型探测结果 -->
					<div class="abp-card" id="abp-probe-card">
						<h2>模型探测结果（当前生效）</h2>
						<table class="widefat striped">
							<tbody>
								<tr><th>来源 Provider</th><td><?php echo esc_html( self::provider_label( $summary['provider'] ) ); ?></td></tr>
								<tr><th>来源标识</th><td><?php echo esc_html( $summary['source'] ?: '—' ); ?></td></tr>
								<tr><th>API Key</th><td><?php echo $summary['has_key'] ? '<span class="abp-badge ok">已配置</span> ' . esc_html( $summary['key'] ) : '<span class="abp-badge warn">未配置（Python 侧将拦截任务）</span>'; ?></td></tr>
								<tr><th>复盘模型</th><td><?php echo esc_html( $summary['models']['stock'] ); ?></td></tr>
								<tr><th>技术模型</th><td><?php echo esc_html( $summary['models']['tech'] ); ?></td></tr>
								<tr><th>国学模型</th><td><?php echo esc_html( $summary['models']['reading'] ); ?></td></tr>
								<tr><th>生图模型</th><td><?php echo esc_html( $summary['models']['image'] ?: '未配置' ); ?></td></tr>
								<tr><th>图片 API</th><td><?php echo esc_html( ( $summary['image_api']['provider'] ? $summary['image_api']['provider'] . ' / ' : '' ) . ( $summary['image_api']['model'] ?: '未配置' ) ); ?></td></tr>
							</tbody>
						</table>
						<p class="description">探测顺序：青简主题（qy_ai_api_key）→ 其他插件探测表 → 插件自身配置。</p>
					</div>
				</div>
			</div>

			<!-- ④.5 备用选题池（唯一操作台：备用题按计划排队，可编辑/删除/排序/列入计划/立即完成） -->
			<div class="abp-card" id="abp-pool-card">
				<h2>备用选题池（按计划排队，自动供每日任务取用）
					<button type="button" class="button button-small" id="abp-refresh-pool">刷新</button>
					<button type="button" class="button button-small" id="abp-pool-fill">智能填充</button>
				</h2>
				<p class="description">系统自动积累备用题目（预选题多余候选 / 本地素材生成）；可编辑、删除、↑↓ 调整排队顺序。每日任务按此顺序取用，取完自动标记已用。</p>
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
				<h2>今日计划任务
					<button type="button" class="button button-small" id="abp-refresh-plan">刷新</button>
					<button type="button" class="button button-small" id="abp-plan-clear">清空计划</button>
				</h2>
				<p class="description">每日调度从备用池按序取题生成；单条可立即完成/删除，也可一键清空（清空仅删排队与跳过，已发布保留）。</p>
				<div id="abp-plan-container"><p class="description">点击「刷新」加载今日计划…</p></div>
			</div>

			<!-- ④.8 AI 工具箱（摘要 / 评论 / 热门话题，参照星河AI工具箱形态） -->
			<div class="abp-card" id="abp-toolbox-card">
				<h2>AI 工具箱（摘要 / 评论 / 热门话题）</h2>
				<p class="description">从下方文章列表勾选（可多选），批量生成摘要 / 评论 / 热门话题。状态列显示是否已有相关内容。</p>
				<div class="abp-toolbox-row">
					<button type="button" class="button" id="abp-toolbox-refresh">刷新列表</button>
					<label><input type="checkbox" id="abp-toolbox-all" /> 全选</label>
					<span>已选 <strong id="abp-toolbox-selcount">0</strong> 篇</span>
				</div>
				<div class="abp-toolbox-table-wrap">
					<table class="widefat striped" id="abp-toolbox-table">
						<thead>
							<tr><th class="abp-col-check"><input type="checkbox" id="abp-toolbox-all2" title="全选" /></th><th>文章标题</th><th>日期</th><th>摘要</th><th>评论</th><th>话题</th></tr>
						</thead>
						<tbody id="abp-toolbox-tbody"><tr><td colspan="6">加载中…</td></tr></tbody>
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
	 * 探测表数组 → 展示文本。
	 *
	 * @param array $plugins 探测表。
	 * @return string
	 */
	private static function probe_plugins_to_text( $plugins ) {
		if ( empty( $plugins ) ) {
			return '';
		}
		$lines = array();
		foreach ( (array) $plugins as $p ) {
			$lines[] = ( isset( $p['source'] ) ? $p['source'] : '' ) . '|' . ( isset( $p['option_key'] ) ? $p['option_key'] : '' ) . '|' . ( isset( $p['theme_mod_key'] ) ? $p['theme_mod_key'] : '' );
		}
		return implode( "\n", $lines );
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
