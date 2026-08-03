<?php
/**
 * 临时脚本：在 WP 环境内激活 A-Blog 插件（触发 activation hook）
 * 用法: php -r 或 php 本文件（需在可写目录）
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once 'C:\inetpub\wwwroot\wordpress\wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'ai-auto-blog-publish/ai-auto-blog-publish.php';
$result = activate_plugin($plugin);

if (is_wp_error($result)) {
    echo "ACTIVATE FAIL: " . $result->get_error_message() . "\n";
    exit(1);
}
echo "ACTIVATE OK\n";

// 输出关键状态
echo "abp_settings: " . (get_option('abp_settings') ? 'exists' : 'MISSING') . "\n";
global $wpdb;
$tables = $wpdb->get_col("SHOW TABLES LIKE 'wp_abp%'");
echo "abp tables: " . implode(', ', $tables) . "\n";

// 输出 token（打码前 6 位）
$s = get_option('abp_settings');
if (is_array($s) && !empty($s['abp_api_token'])) {
    $t = $s['abp_api_token'];
    echo "token: " . substr($t, 0, 6) . "..." . substr($t, -4) . " (len=" . strlen($t) . ")\n";
} else {
    echo "token: MISSING\n";
}

// 模型探测状态（应探测到青简主题? 本机未装主题 -> self/默认）
if (function_exists('abp_get_models')) {
    $m = abp_get_models();
    echo "model provider: " . (is_array($m) ? ($m['provider'] ?? '?') : '?') . "\n";
}
