<?php
/**
 * Plugin Name: A-Blog IIS 中文分类链接兼容
 * Description: IIS/FastCGI 对中文 PATH_INFO 按 GBK 解码导致中文 slug 分类页 404；将中文 slug 分类链接改为 ?category_name= 参数形式。
 * Version: 1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'category_link',
	function ( $link, $cat_id ) {
		// 仅处理带 index.php 路径形式（IIS 无 URL Rewrite 的站点）
		if ( false === strpos( $link, '/archives/category/' ) ) {
			return $link;
		}
		$cat = get_category( $cat_id );
		if ( $cat && preg_match( '/%[0-9a-fA-F]{2}/', $cat->slug ) ) {
			// 中文（URL 编码字面量）slug：改用 query 参数，绕开 IIS PATH_INFO 乱码
			return home_url( '/index.php' ) . '?category_name=' . rawurlencode( $cat->slug );
		}
		return $link;
	},
	10,
	2
);
