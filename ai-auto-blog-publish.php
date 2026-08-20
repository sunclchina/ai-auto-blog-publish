<?php
/**
 * Plugin Name: AI自动博客 A-Blog
 * Plugin URI:  https://github.com/sunclchina/ai-auto-blog-publish
 * Description: AI 全自动博客内容生产与发布插件（A-Blog）。接收 Python 伴生服务产出的成品文章，经 SimHash 指纹查重后自动建文、分类、打标、配图并发布；自动探测站点模型配置（青简主题 → 其他插件 → 插件自身）。配套 REST API：/wp-json/ai-auto-blog/v1/*。
 * Version: 1.5.53
 * Author:      A-Blog Team
 * Author URI:  https://sunclnas.cn/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-auto-blog-publish
 * Requires PHP: 7.4
 * Requires WP:  5.6
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接访问则终止（安全防护）。
}

/* 常量定义 */
define( 'ABP_VERSION', '1.5.54' );
define( 'ABP_PLUGIN_FILE', __FILE__ );
define( 'ABP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ABP_API_NAMESPACE', 'ai-auto-blog/v1' );

/* 加载 includes 各 class */
require_once ABP_PLUGIN_DIR . 'includes/class-abp-log.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-materials.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-queue.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-fingerprint.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-models.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-publish.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-rest.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-settings.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-toolbox.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-avatar.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-updater.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-scheduler.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-stock.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-industry.php';

/**
 * 激活钩子：建表（wp_abp_log 任务日志 + wp_abp_fingerprints 指纹索引）+ 初始化默认设置。
 *
 * @return void
 */
function abp_activate() {
	ABP_Log::create_table();
	ABP_Fingerprint::create_table();
	ABP_Queue::create_tables();
	ABP_Scheduler::schedule();

	// 首次激活写入默认设置；已存在（重新激活）则保留用户配置。
	if ( false === get_option( 'abp_settings', false ) ) {
		add_option( 'abp_settings', ABP_Settings::defaults() );
	}

	// 清理可能残留的 rewrite 缓存（REST 路由无需 flush，此步仅为稳妥）。
	flush_rewrite_rules();
}

/**
 * 覆盖升级自愈：文件替换不触发激活钩子，这里在版本变化时自动建表/注册调度。
 *
 * @return void
 */
function abp_ensure_tables() {
	if ( get_option( 'abp_tables_version' ) === ABP_VERSION ) {
		return;
	}
	ABP_Log::create_table();
	ABP_Fingerprint::create_table();
	ABP_Queue::create_tables();
	ABP_Scheduler::schedule();
	update_option( 'abp_tables_version', ABP_VERSION );
}

/**
 * 卸载时清理（uninstall.php 兜底，双保险）。
 *
 * @return void
 */
function abp_deactivate() {
	// 清理 WP-Cron 调度（停用即停自动生成）。
	ABP_Scheduler::unschedule();
}

register_activation_hook( __FILE__, 'abp_activate' );
register_deactivation_hook( __FILE__, 'abp_deactivate' );

add_action( 'init', 'abp_ensure_tables' );

/* REST 路由注册（总纲 3.2） */
add_action( 'rest_api_init', array( 'ABP_REST', 'register_routes' ) );
add_action( 'init', array( 'ABP_Toolbox', 'register_taxonomy' ) );
add_action( 'init', array( 'ABP_Toolbox', 'register_post_type' ) );

/* 自动调度：WP-Cron（每日建队列 + 每 5 分钟处理到点/立即完成任务） */
add_filter( 'cron_schedules', array( 'ABP_Scheduler', 'cron_schedules' ) );
add_action( ABP_Scheduler::HOOK_BUILD, array( 'ABP_Scheduler', 'build_daily_queue' ) );
add_action( ABP_Scheduler::HOOK_DUE, array( 'ABP_Scheduler', 'process_due' ) );
add_action( ABP_Scheduler::HOOK_MATERIALS, array( 'ABP_Scheduler', 'refresh_materials' ) );

/* 文章完成附加内容（摘要/评论/话题开关，v1.5.51）：发布后延迟执行 */
add_action( 'abp_after_publish_extras', array( 'ABP_Publish', 'run_after_publish_extras' ) );

/* AI 配图异步任务（v1.5.52）：工具箱 AI 配图排队后由 WP-Cron 后台生图，避免 nginx 502 */
add_action( 'abp_ai_cover_job', array( 'ABP_Toolbox', 'run_ai_cover_job' ) );

/* 后台设置页（原则 6 统一入口） */
ABP_Settings::init();

/* GitHub 自动升级（v1.2.0） */
ABP_Updater::init();

/* 评论作者本地 SVG 头像（确定性生成，零成本） */
ABP_Avatar::init();

/* 文本域加载（readme 声明 Text Domain） */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'ai-auto-blog-publish', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
