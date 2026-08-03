<?php
/** 生成 A-Blog API Token（模拟后台「重新生成」按钮逻辑） */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once 'C:\inetpub\wwwroot\wordpress\wp-load.php';

$s = get_option('abp_settings');
if (!is_array($s)) { echo "settings missing\n"; exit(1); }
$s['api_token'] = wp_generate_password(32, false, false);
update_option('abp_settings', $s);
echo "TOKEN=" . $s['api_token'] . "\n";
echo "OK\n";
