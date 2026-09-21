<?php
/**
 * class-abp-updater.php — GitHub Release 自动升级（v1.2.0 → v1.5.9 完善）
 *
 * 原理：接入 WordPress 标准更新通道（update_plugins transient + plugins_api），
 * 从 Release API 拉取最新版本，匹配 zip 包：
 *   优先 Release Asset（zip 根目录即 ai-auto-blog-publish，WP 直接识别），
 *   无 Asset 时回退 Source code zip（配合 upgrader_source_selection 重命名目录）。
 * 后台「插件」页出现标准「有可用更新」提示，一键走 WP 自带升级流程。
 *
 * 配置（后台「AI 自动博客」→「自动升级」卡片）：
 *   owner/repo、api_base（默认 GitHub API；自建 Gitea/GHE 填完整 API 基址）、
 *   开关、可选 Token（GitHub API 未认证限 60 次/小时/IP，配 Token 可到 5000 次/小时）。
 *
 * v1.5.9 完善：
 *   - 更新源可配置（api_base）：自建 Gitea/Gitee/GHE 镜像可直接换源；
 *   - 独立每日检查定时（不依赖 WP 更新 cron，WP-Cron 被禁用的环境也能发现新版本）；
 *   - 升级包完整性校验：解压后的源目录必须包含主插件文件，防下载错误包损坏站点；
 *   - 升级完成/失败写任务日志 + 清理 Release 缓存（upgrader_process_complete）；
 *   - 下载包选择优先匹配含版本号的资产（Release 挂多个 zip 时不选错）。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接访问则终止（安全防护）。
}

class ABP_Updater {

	const CACHE_KEY = 'abp_gh_release_cache';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	const CRON_HOOK = 'abp_updater_daily';

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
		// v1.5.56：升级器下载 zip 时同样降级 SSL/放宽超时（与 get_remote_release 的
		// GitHub API 降级同因——部分宝塔/Linux PHP 证书链异常或 GitHub 下载慢，
		// 否则 WP 升级在下载阶段失败，报「无法安装这个包」）。
		add_filter( 'http_request_args', array( __CLASS__, 'http_args_for_github_download' ), 10, 2 );
		// v1.5.57：wp_safe_remote_get 有 URL 安全域名白名单（http_allowed_hosts），
		// GitHub 下载域不在默认白名单 → 下载阶段直接报「下载失败。URL 无效。」；
		// 这里把本插件 GitHub 下载域加入白名单（仅追加，不影响其它域名）。
		add_filter( 'http_allowed_hosts', array( __CLASS__, 'allowed_hosts_for_github' ), 10, 2 );
		// v1.5.57：wp_safe_remote_get 还会先解析域名——若解析到回环/内网地址
		// （如本机 hosts 把 GitHub 指向 127.0.0.1），默认拒绝并报「URL 无效。」。
		// 对 GitHub 官方下载域显式放行（不影响其它域名的安全校验）。
		add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_github_host_external' ), 10, 3 );
		// v1.5.64：装新包前先把旧插件目录改名备份（WP clear_destination 删不掉旧目录文件时，
		// move 到非空目标会失败报「无法安装这个包」）。
		add_action( 'upgrader_pre_install', array( __CLASS__, 'pre_install_backup_old' ), 10, 2 );
		// v1.5.9：升级完成/失败写日志 + 清 Release 缓存；独立每日检查定时。
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrade_done' ), 10, 2 );
		self::schedule();
	}

	/**
	 * 注册每日检查定时（幂等；激活/每次 init 自愈）。
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * 清理每日检查定时（停用插件时）。
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * 每日检查回调：强制刷新 Release 缓存（让后台「插件」页的 transient 尽早拿到新版本；
	 * 不依赖 WP 自带的 wp_update_plugins cron，WP-Cron 被禁用的环境也能发现更新）。
	 *
	 * @return void
	 */
	public static function daily_refresh() {
		$release = self::get_remote_release( true );
		if ( $release && ! empty( $release['tag_name'] ) ) {
			abp_log_write( 'updater', 'updater', 'daily_check', 'ok',
				'最新版本 ' . $release['tag_name'] . '（当前 ' . ABP_VERSION . '）' );
		}
	}

	/**
	 * 升级完成后处理（成功/失败/回滚均触发）：写日志 + 清 Release 缓存。
	 *
	 * @param WP_Upgrader $upgrader  升级器实例。
	 * @param array       $hook_extra 额外参数（含 plugin basename）。
	 * @return void
	 */
	public static function upgrade_done( $upgrader, $hook_extra ) {
		if ( ! self::is_our_upgrade( $hook_extra ) ) {
			return;
		}
		delete_site_transient( self::CACHE_KEY );
		// WP_Upgrader::$result 为 public 属性：成功为路径字符串，失败为 WP_Error（回滚后仍为错误）。
		$ok = ! is_wp_error( $upgrader );
		if ( is_object( $upgrader ) && isset( $upgrader->result ) ) {
			$ok = ! is_wp_error( $upgrader->result );
		}
		abp_log_write(
			'updater',
			'updater',
			$ok ? 'upgrade_success' : 'upgrade_failed',
			$ok ? 'ok' : 'fail',
			$ok ? '自动升级完成，当前版本 ' . ABP_VERSION : '自动升级失败（WP 已尝试回滚），当前版本 ' . ABP_VERSION
		);
	}

	/**
	 * 判断本次升级是否为 A-Blog 插件（钩子来自本插件的更新）。
	 *
	 * @param array|null $hook_extra upgrader hook_extra。
	 * @return bool
	 */
	private static function is_our_upgrade( $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
			return false;
		}
		return self::plugin_basename() === (string) $hook_extra['plugin'];
	}

	/**
	 * 装新包前备份旧插件目录：先尝试删除；删不掉（文件被锁/权限不对）就改名为 .bak-时间戳，
	 * 腾出 plugins/ai-auto-blog-publish 目标路径，避免 WP move 到非空目录报「无法安装这个包」。
	 *
	 * @param mixed $return       WP 预安装返回值（null 表示继续）。
	 * @param array $hook_extra   额外参数（含 plugin basename）。
	 * @return mixed
	 */
	public static function pre_install_backup_old( $return, $hook_extra ) {
		if ( ! self::is_our_upgrade( $hook_extra ) ) {
			return $return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $return;
		}
		$old = WP_PLUGIN_DIR . '/' . dirname( self::plugin_basename() );
		if ( $wp_filesystem->exists( $old ) ) {
			if ( ! $wp_filesystem->delete( $old, true ) ) {
				$wp_filesystem->move( $old, $old . '.bak-' . time() );
			}
		}
		return $return;
	}

	/**
	 * 对 GitHub 官方下载域放行 wp_safe_remote_get 的「回环/内网地址」拒绝逻辑：
	 * 某些环境 hosts/解析会把 github.com 解析到本地地址（加速工具残留等），
	 * WP 默认把 127.x 判定为本地并拒绝 → 报「下载失败。URL 无效。」。
	 * 仅匹配 GitHub 官方下载域，其它域名一律保持 WP 默认校验。
	 *
	 * @param bool   $external WP 默认判定（false）。
	 * @param string $host     请求的 host。
	 * @param string $url      请求 URL。
	 * @return bool
	 */
	public static function allow_github_host_external( $external, $host = '', $url = '' ) {
		if ( preg_match( '#^https?://(github\.com|objects\.githubusercontent\.com|codeload\.github\.com)(/|$)#i', (string) $url )
			|| in_array( strtolower( (string) $host ), array( 'github.com', 'objects.githubusercontent.com', 'codeload.github.com' ), true ) ) {
			return true;
		}
		return $external;
	}

	/**
	 * 将本插件 GitHub 下载域加入 WP 安全域名白名单（wp_safe_remote_get 校验用），
	 * 否则下载 zip 阶段报「下载失败。URL 无效。」（WP 默认白名单不含 GitHub）。
	 * 仅追加 GitHub 官方下载域，不影响站点其它 HTTP 请求的安全校验。
	 *
	 * @param string[] $allowed_hosts 已允许域名列表。
	 * @param string   $host         当前待校验的请求域名（未使用，仅匹配 WP 签名）。
	 * @return string[]
	 */
	public static function allowed_hosts_for_github( $allowed_hosts, $host = '' ) {
		$allowed_hosts[] = 'github.com';
		$allowed_hosts[] = 'objects.githubusercontent.com';
		$allowed_hosts[] = 'codeload.github.com';
		return array_values( array_unique( $allowed_hosts ) );
	}

	/**
	 * 仅对本插件 GitHub 下载域调整请求参数（sslverify=false + 超时放宽），
	 * 不影响站点其它 HTTP 请求。
	 *
	 * @param array  $args 请求参数。
	 * @param string $url  请求 URL。
	 * @return array
	 */
	public static function http_args_for_github_download( $args, $url ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$owner = preg_quote( self::owner(), '#' );
		$repo  = preg_quote( self::repo(), '#' );
		if ( preg_match( "#^https?://(github\.com/{$owner}/{$repo}|objects\.githubusercontent\.com|codeload\.github\.com)#i", $url ) ) {
			$args['sslverify'] = false;
			$args['timeout']   = max( 60, (int) ( isset( $args['timeout'] ) ? $args['timeout'] : 0 ) );
		}
		return $args;
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

	/**
	 * Release API 基址（v1.5.9 起可配置；默认 GitHub，自建 Gitea/GHE 填完整 API 基址）。
	 *
	 * @return string
	 */
	public static function api_base() {
		$s = ABP_Settings::get_settings();
		$base = isset( $s['github_api_base'] ) ? untrailingslashit( trim( (string) $s['github_api_base'] ) ) : '';
		if ( ! $base || ! preg_match( '#^https?://#i', $base ) ) {
			return 'https://api.github.com';
		}
		return $base;
	}

	public static function token() {
		$s = ABP_Settings::get_settings();
		return isset( $s['github_token'] ) ? trim( (string) $s['github_token'] ) : '';
	}

	/**
	 * 拉取最新 Release（带 12h 缓存；force 强制刷新）。
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
		$url = self::api_base() . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/releases/latest';
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
		// 部分 Windows PHP 环境的 OpenSSL 证书链验证异常（即使配置了 CA 也无法验证 GitHub 证书），
		// 对 API 域降级重试一次（仅传输层/证书类失败才降级，404 等业务错误不重试）。
		if ( is_wp_error( $resp ) || 0 === wp_remote_retrieve_response_code( $resp ) ) {
			$args2          = $args;
			$args2['sslverify'] = false;
			$args2['timeout']   = 20;
			$resp = wp_remote_get( $url, $args2 );
		}
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null; // 网络/限流/仓库不存在失败静默降级。
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
	 * 选择顺序：1) 资产名含版本号（v1.5.58 / 1.5.58）的插件 zip；
	 * 2) 资产名含插件名且 .zip；3) 回退 Source code zip（zipball_url）。
	 *
	 * @param array $release GitHub release 数据。
	 * @return string 空串表示无可用包。
	 */
	public static function package_url( $release ) {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
		$ver    = ltrim( (string) ( isset( $release['tag_name'] ) ? $release['tag_name'] : '' ), 'vV' );
		$fallback = '';
		foreach ( $assets as $a ) {
			$name = isset( $a['name'] ) ? (string) $a['name'] : '';
			if ( false === strpos( $name, 'ai-auto-blog-publish' ) || '.zip' !== substr( $name, -4 ) ) {
				continue;
			}
			$url = isset( $a['browser_download_url'] ) ? (string) $a['browser_download_url'] : '';
			if ( ! $url ) {
				continue;
			}
			if ( '' === $fallback ) {
				$fallback = $url;
			}
			// 资产名含版本号（v1.5.58 / 1.5.58 等）优先，避免 Release 挂多个 zip 时选错。
			if ( $ver && false !== strpos( $name, $ver ) ) {
				return $url;
			}
			if ( preg_match( '/[vV]?\d+\.\d+\.\d+.*\.zip$/', $name ) ) {
				return $url;
			}
		}
		if ( $fallback ) {
			return $fallback;
		}
		// 回退：Source code zip（codeload 域名，配合 fix_source_dir 重命名目录）。
		if ( ! empty( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}
		return '';
	}

	/**
	 * Source code zip 的顶层目录是 {repo}-{tag}，与插件目录名不符会导致升级失败，
	 * 统一重命名为 ai-auto-blog-publish；同时校验包内必须包含主插件文件
	 * （防下载到错误包/残缺包损坏站点，v1.5.9）。
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
		if ( $src !== rtrim( $new, '/\\' ) ) {
			global $wp_filesystem;
			if ( $wp_filesystem ) {
				// 先尝试删除旧目录；删不掉（文件被锁/权限不对）就改名为 .bak-时间戳，
				// 腾出目标路径再移动新目录，避免「无法安装这个包」。
				if ( $wp_filesystem->exists( $new ) ) {
					if ( ! $wp_filesystem->delete( $new, true ) ) {
						$wp_filesystem->move( $new, $new . '.bak-' . time() );
					}
				}
				if ( ! $wp_filesystem->move( $src, $new ) ) {
					return $source;
				}
			} elseif ( ! @rename( $src, $new ) ) { // phpcs:ignore
				return $source;
			}
		}
		// 完整性校验：包内必须包含主插件文件，否则中止升级（WP 会清理临时目录并提示错误）。
		if ( ! is_file( $new . DIRECTORY_SEPARATOR . 'ai-auto-blog-publish.php' ) ) {
			return new WP_Error(
				'abp_bad_package',
				'下载包不包含 ai-auto-blog-publish/ai-auto-blog-publish.php，已中止升级（可能下载到错误版本或残缺包）'
			);
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
				'error' => '更新源不可达或仓库不存在（检查 owner/repo、API 基址与网络）',
			);
		}
		$remote_ver = ltrim( (string) $release['tag_name'], 'vV' );
		$has_update = version_compare( $remote_ver, ABP_VERSION, '>' );
		return array(
			'ok'          => true,
			'current'     => ABP_VERSION,
			'latest'      => $remote_ver,
			'has_update'  => $has_update,
			'stale'       => version_compare( $remote_ver, ABP_VERSION, '<' ), // 远端 Release 低于当前版本（仓库未同步新 Release）。
			'release_url' => isset( $release['html_url'] ) ? $release['html_url'] : '',
			'package'     => self::package_url( $release ),
			'update_url'  => $has_update ? admin_url( 'update-core.php' ) : '',
		);
	}
}
