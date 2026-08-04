<?php
/**
 * class-abp-updater.php — GitHub Release 自动升级（v1.2.0）
 *
 * 原理：接入 WordPress 标准更新通道（update_plugins transient + plugins_api），
 * 从 GitHub Releases API 拉取最新版本，匹配 zip 包：
 *   优先 Release Asset（zip 根目录即 ai-auto-blog-publish，WP 直接识别），
 *   无 Asset 时回退 Source code zip（配合 upgrader_source_selection 重命名目录）。
 * 后台「插件」页出现标准「有可用更新」提示，一键走 WP 自带升级流程。
 *
 * 配置（后台「AI 自动博客」→「自动升级」卡片）：
 *   owner/repo（默认 sunclchina/ai-auto-blog-publish）、开关、可选 Token
 *   （GitHub API 未认证限 60 次/小时/IP，配 Token 可到 5000 次/小时）。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接访问则终止（安全防护）。
}

class ABP_Updater {

	const CACHE_KEY = 'abp_gh_release_cache';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * 初始化：钩子挂载（由主文件调用一次；开关关闭则不注册任何更新通道）。
	 *
	 * @return void
	 */
	public static function init() {
		$s = ABP_Settings::get_settings();
		if ( 'on' !== ( isset( $s['auto_update_enabled'] ) ? $s['auto_update_enabled'] : 'on' ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * 本插件 basename（ai-auto-blog-publish/ai-auto-blog-publish.php）。
	 *
	 * @return string
	 */
	public static function plugin_basename() {
		return plugin_basename( ABP_PLUGIN_FILE );
	}

	/**
	 * 仓库配置（来自后台设置）。
	 *
	 * @return string
	 */
	public static function owner() {
		$s = ABP_Settings::get_settings();
		return isset( $s['github_owner'] ) ? trim( (string) $s['github_owner'] ) : 'sunclchina';
	}

	public static function repo() {
		$s = ABP_Settings::get_settings();
		return isset( $s['github_repo'] ) ? trim( (string) $s['github_repo'] ) : 'ai-auto-blog-publish';
	}

	public static function token() {
		$s = ABP_Settings::get_settings();
		return isset( $s['github_token'] ) ? trim( (string) $s['github_token'] ) : '';
	}

	/**
	 * 拉取 GitHub 最新 Release（带 12h 缓存；force 强制刷新）。
	 *
	 * @param bool $force 是否忽略缓存。
	 * @return array|null 失败返回 null（静默，不影响站点）。
	 */
	public static function get_remote_release( $force = false ) {
		$cache = $force ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cache ) && ! empty( $cache['tag_name'] ) ) {
			return $cache;
		}
		$owner = self::owner();
		$repo  = self::repo();
		if ( ! $owner || ! $repo ) {
			return null;
		}
		$url = 'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/releases/latest';
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'User-Agent' => 'A-Blog/' . ABP_VERSION,
				'Accept'     => 'application/vnd.github+json',
			),
		);
		$tok = self::token();
		if ( $tok ) {
			$args['headers']['Authorization'] = 'Bearer ' . $tok;
		}
		$resp = wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null; // 网络/限流失败静默降级。
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}
		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * 注入标准更新通道（pre_set_site_transient_update_plugins）。
	 *
	 * @param object $transient 更新 transient。
	 * @return object
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$base    = self::plugin_basename();
		$release = self::get_remote_release();
		if ( ! $release ) {
			return $transient;
		}
		$remote_ver = ltrim( (string) $release['tag_name'], 'vV' );
		if ( version_compare( $remote_ver, ABP_VERSION, '<=' ) ) {
			return $transient;
		}
		$package = self::package_url( $release );
		if ( ! $package ) {
			return $transient;
		}
		$obj                = new stdClass();
		$obj->slug          = dirname( $base );
		$obj->plugin        = $base;
		$obj->new_version   = $remote_ver;
		$obj->url           = isset( $release['html_url'] ) ? $release['html_url'] : '';
		$obj->package       = $package;
		$obj->tested        = '6.7';
		$obj->requires_php  = '7.4';
		$obj->id            = 'github.com/' . self::owner() . '/' . self::repo() . '/' . $remote_ver;
		$obj->icons         = array();
		$obj->banners       = array();
		$transient->response[ $base ] = $obj;
		return $transient;
	}

	/**
	 * 计算下载包地址。
	 *
	 * @param array $release GitHub release 数据。
	 * @return string 空串表示无可用包。
	 */
	public static function package_url( $release ) {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
		foreach ( $assets as $a ) {
			$name = isset( $a['name'] ) ? (string) $a['name'] : '';
			if ( false !== strpos( $name, 'ai-auto-blog-publish' ) && '.zip' === substr( $name, -4 ) ) {
				return isset( $a['browser_download_url'] ) ? $a['browser_download_url'] : '';
			}
		}
		// 回退：Source code zip（codeload 域名，配合 fix_source_dir 重命名目录）。
		if ( ! empty( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}
		return '';
	}

	/**
	 * Source code zip 的顶层目录是 {repo}-{tag}，与插件目录名不符会导致升级失败，
	 * 统一重命名为 ai-auto-blog-publish（仅处理本插件升级）。
	 *
	 * @param string      $source       解压后源目录。
	 * @param string      $remote_source 远端临时目录。
	 * @param WP_Upgrader $upgrader     升级器实例。
	 * @param array       $hook_extra   额外参数（含 plugin basename）。
	 * @return string
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
		if ( ! $source || ! is_dir( $source ) ) {
			return $source;
		}
		$base = self::plugin_basename();
		if ( ! $hook_extra || empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $base ) {
			return $source;
		}
		$slug = dirname( $base );
		$src  = rtrim( $source, '/\\' );
		$new  = rtrim( dirname( $source ), '/\\' ) . DIRECTORY_SEPARATOR . $slug;
		if ( $src === rtrim( $new, '/\\' ) ) {
			return $source; // 目录名已正确（Asset 包）。
		}
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $new, true );
			$wp_filesystem->move( $src, $new );
		} elseif ( @rename( $src, $new ) ) { // phpcs:ignore
			// PHP 原生 rename 兜底。
		} else {
			return $source; // 重命名失败，交回 WP 处理（大概率报错，但不至于破坏站点）。
		}
		return $new;
	}

	/**
	 * 插件「查看详情」数据（plugins_api）。
	 *
	 * @param mixed  $res    默认结果。
	 * @param string $action 动作名。
	 * @param object $args   请求参数。
	 * @return mixed
	 */
	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $res;
		}
		if ( dirname( self::plugin_basename() ) !== $args->slug ) {
			return $res;
		}
		$release = self::get_remote_release();
		if ( ! $release ) {
			return $res;
		}
		$info                = new stdClass();
		$info->name          = 'AI自动博客 A-Blog';
		$info->slug          = $args->slug;
		$info->version       = ltrim( (string) $release['tag_name'], 'vV' );
		$info->author        = '<a href="https://sunclnas.cn/">A-Blog Team</a>';
		$info->homepage      = 'https://github.com/' . self::owner() . '/' . self::repo();
		$info->requires      = '5.6';
		$info->tested        = '6.7';
		$info->requires_php  = '7.4';
		$info->download_link = self::package_url( $release );
		$info->sections      = array(
			'description' => 'AI 全自动博客发布端插件（GitHub 自动升级）。',
			'changelog'   => isset( $release['body'] ) ? nl2br( esc_html( (string) $release['body'] ) ) : '',
		);
		return $info;
	}

	/**
	 * 强制检查更新（后台「检查更新」AJAX 用）。
	 *
	 * @return array
	 */
	public static function force_check() {
		delete_site_transient( self::CACHE_KEY );
		$release = self::get_remote_release( true );
		if ( ! $release ) {
			return array(
				'ok'    => false,
				'error' => 'GitHub 不可达或仓库不存在（检查 owner/repo 与网络）',
			);
		}
		$remote_ver = ltrim( (string) $release['tag_name'], 'vV' );
		$has_update = version_compare( $remote_ver, ABP_VERSION, '>' );
		return array(
			'ok'          => true,
			'current'     => ABP_VERSION,
			'latest'      => $remote_ver,
			'has_update'  => $has_update,
			'release_url' => isset( $release['html_url'] ) ? $release['html_url'] : '',
			'package'     => self::package_url( $release ),
			'update_url'  => $has_update ? admin_url( 'update-core.php' ) : '',
		);
	}
}
