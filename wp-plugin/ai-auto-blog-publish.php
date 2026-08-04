<?php
/**
 * Plugin Name: AI自动博客 A-Blog
 * Plugin URI:  https://sunclnas.cn/
 * Description: AI 全自动博客内容生产与发布插件（A-Blog）。接收 Python 伴生服务产出的成品文章，经 SimHash 指纹查重后自动建文、分类、打标、配图并发布；自动探测站点模型配置（青简主题 → 其他插件 → 插件自身）。配套 REST API：/wp-json/ai-auto-blog/v1/*。
 * Version:     1.2.0
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
define( 'ABP_VERSION', '1.2.0' );
define( 'ABP_PLUGIN_FILE', __FILE__ );
define( 'ABP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ABP_API_NAMESPACE', 'ai-auto-blog/v1' );

/* 加载 includes 各 class */
require_once ABP_PLUGIN_DIR . 'includes/class-abp-log.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-fingerprint.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-models.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-publish.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-rest.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-settings.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-toolbox.php';
require_once ABP_PLUGIN_DIR . 'includes/class-abp-updater.php';

/**
 * 激活钩子：建表（wp_abp_log 任务日志 + wp_abp_fingerprints 指纹索引）+ 初始化默认设置。
 *
 * @return void
 */
function abp_activate() {
	ABP_Log::create_table();
	ABP_Fingerprint::create_table();

	// 首次激活写入默认设置；已存在（重新激活）则保留用户配置。
	if ( false === get_option( 'abp_settings', false ) ) {
		add_option( 'abp_settings', ABP_Settings::defaults() );
	}

	// 清理可能残留的 rewrite 缓存（REST 路由无需 flush，此步仅为稳妥）。
	flush_rewrite_rules();
}

/**
 * 卸载时清理（uninstall.php 兜底，双保险）。
 *
 * @return void
 */
function abp_deactivate() {
	// 卸载清理在 uninstall.php 中执行；此处仅预留，不做数据删除。
}

register_activation_hook( __FILE__, 'abp_activate' );
register_deactivation_hook( __FILE__, 'abp_deactivate' );

/* REST 路由注册（总纲 3.2） */
add_action( 'rest_api_init', array( 'ABP_REST', 'register_routes' ) );
add_action( 'init', array( 'ABP_Toolbox', 'register_taxonomy' ) );

/* 后台设置页（原则 6 统一入口） */
ABP_Settings::init();

/* GitHub 自动升级（v1.2.0） */
ABP_Updater::init();

/* 文本域加载（readme 声明 Text Domain） */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'ai-auto-blog-publish', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
