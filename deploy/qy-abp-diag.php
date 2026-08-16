<?php
/**
 * A-Blog 临时诊断脚本（问题排查用，查完即删！）
 * 放到线上插件目录：/www/wwwroot/sunclnas.cn/wordpress/wp-content/plugins/ai-auto-blog-publish/qy-abp-diag.php
 * 访问：https://sunclnas.cn/wp-content/plugins/ai-auto-blog-publish/qy-abp-diag.php
 * 或（子目录安装）：https://sunclnas.cn/wordpress/wp-content/plugins/ai-auto-blog-publish/qy-abp-diag.php
 */
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
header( 'Content-Type: text/plain; charset=utf-8' );

echo "=== A-Blog 诊断 " . date( 'Y-m-d H:i:s' ) . " ===\n\n";

// 1. 插件常量
echo "ABP_VERSION 常量定义文件存在: " . ( file_exists( dirname( __FILE__ ) . '/ai-auto-blog-publish.php' ) ? '是' : '否' ) . "\n";
echo "includes 目录: " . ( is_dir( dirname( __FILE__ ) . '/includes' ) ? '存在' : '缺失!' ) . "\n";

// 2. 加载 WP
define( 'WP_USE_THEMES', false );
$wp_load = dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';
echo "wp-load.php 路径: " . $wp_load . " => " . ( file_exists( $wp_load ) ? '存在' : '缺失!' ) . "\n";
require $wp_load;

echo "WordPress 加载成功: " . get_bloginfo( 'version' ) . "\n\n";

// 3. 插件是否激活
$active = get_option( 'active_plugins', array() );
$plugin_base = 'ai-auto-blog-publish/ai-auto-blog-publish.php';
echo "插件激活状态: " . ( in_array( $plugin_base, $active, true ) ? '已激活' : '未激活!!' ) . "\n";
echo "active_plugins 数量: " . count( $active ) . "\n";

// 4. ABP_Settings 类是否存在
echo "ABP_Settings 类: " . ( class_exists( 'ABP_Settings' ) ? '存在' : '不存在!!' ) . "\n";
echo "ABP_REST 类: " . ( class_exists( 'ABP_REST' ) ? '存在' : '不存在!!' ) . "\n";

// 5. 读设置 + token
$settings = get_option( 'abp_settings', array() );
echo "\n=== abp_settings option ===\n";
echo "option 存在: " . ( false !== $settings ? '是' : '否(默认值)' ) . "\n";
if ( is_array( $settings ) ) {
	echo "api_token: [" . ( isset( $settings['api_token'] ) ? $settings['api_token'] : '未设置' ) . "]\n";
	echo "ai_enabled: " . ( isset( $settings['ai_enabled'] ) ? $settings['ai_enabled'] : '未设置' ) . "\n";
	echo "summary_enabled: " . ( isset( $settings['summary_enabled'] ) ? $settings['summary_enabled'] : '未设置' ) . "\n";
	echo "字段数: " . count( $settings ) . "\n";
} else {
	echo "abp_settings 非数组! 类型: " . gettype( $settings ) . "\n";
}

// 6. 手动生成 token 测试（不保存，仅验证函数可用）
echo "\n=== 生成 token 测试 ===\n";
try {
	$new_token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false, false ) : 'FALLBACK_' . md5( uniqid() );
	echo "wp_generate_password 生成: " . strlen( $new_token ) . " 字符 => " . $new_token . "\n";
	$settings2 = get_option( 'abp_settings', array() );
	if ( ! is_array( $settings2 ) ) { $settings2 = array(); }
	$settings2['api_token'] = $new_token;
	$ok = update_option( 'abp_settings', $settings2 );
	echo "update_option 写入: " . ( $ok ? '成功' : '失败!!' ) . "\n";
	$verify = get_option( 'abp_settings', array() );
	echo "回读 api_token: [" . ( is_array( $verify ) && isset( $verify['api_token'] ) ? $verify['api_token'] : '空!!' ) . "]\n";
} catch ( Throwable $e ) {
	echo "异常: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

// 7. admin-post 钩子是否注册（重新生成按钮的路径）
echo "\n=== admin-post 钩子检查 ===\n";
global $wp_filter;
$hook = 'admin_post_abp_generate_token';
echo "has_action('admin_post_abp_generate_token'): " . ( has_action( $hook ) ? '已注册' : '未注册!!' ) . "\n";
echo "has_action('admin_post_nopriv_abp_generate_token'): " . ( has_action( 'admin_post_nopriv_abp_generate_token' ) ? '已注册' : '未注册(正常)' ) . "\n";

echo "\n=== 诊断完成 ===\n";
