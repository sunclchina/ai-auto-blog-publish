<?php
/**
 * Plugin Name: A-Blog IIS REST 兼容
 * Description: 无 URL Rewrite 的 IIS 环境：强制 REST 地址使用 ?rest_route= 形式（站点健康检查/插件 REST 均可用）。
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 把 pretty 形式的 /wp-json/ 统一转为 ?rest_route= 形式，并归一化可能叠加的 index.php。
add_filter( 'rest_url', function ( $url ) {
	$url = str_replace( '/index.php/wp-json/', '/index.php?rest_route=/', $url );
	$url = str_replace( '/wp-json/', '/index.php?rest_route=/', $url );
	$url = preg_replace( '#(/index\.php){2,}#', '/index.php', $url );
	return $url;
} );
