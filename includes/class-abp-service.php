<?php
/**
 * ABP_Service：Python 伴生服务的部署与重启。
 *
 * 解决的问题：WP 插件升级只覆盖 wp-content/plugins/ai-auto-blog-publish/，
 * 但 systemd 服务 ablog 跑的是独立目录（默认 /opt/ablog/backend/）。本类把
 * 插件内置的 backend/ 同步到服务目录并重启服务，做到「插件升级即服务升级」。
 *
 * 部署前提（NAS 上一次性配置）：
 *  1. Web 用户（www-data/nginx）对服务目录 backend/ 有写权限；
 *  2. 该用户可免密 sudo 执行 systemctl restart <service>（sudoers 加一行）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Service {

	const SRC_REL = 'backend';

	/**
	 * 执行部署：同步 backend → 服务目录，重启服务。
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function deploy() {
		$settings = ABP_Settings::get_settings();
		$svc_dir  = rtrim( (string) $settings['service_dir'], '/\\' );
		$svc_name = (string) $settings['service_name'];
		$src      = ABP_PLUGIN_DIR . self::SRC_REL;
		$dst      = $svc_dir . '/' . self::SRC_REL;

		if ( '' === $svc_dir ) {
			return array( 'ok' => false, 'message' => '未配置 Python 服务目录（service_dir）' );
		}
		if ( ! is_dir( $src ) ) {
			return array( 'ok' => false, 'message' => '插件内未找到 backend 目录：' . $src );
		}
		// Windows 本地开发无 systemd，只复制不重启。
		$is_win = ( DIRECTORY_SEPARATOR === '\\' );

		if ( ! is_dir( $dst ) && ! wp_mkdir_p( $dst ) ) {
			return array( 'ok' => false, 'message' => "无法创建服务目录：{$dst}（检查 Web 用户对 {$svc_dir} 的写权限）" );
		}

		$copied = self::sync_dir( $src, $dst );
		if ( false === $copied ) {
			return array( 'ok' => false, 'message' => "复制 backend 失败（{$src} → {$dst}），检查目录写权限" );
		}

		$restart = 'skipped(windows)';
		if ( ! $is_win && '' !== $svc_name ) {
			$restart = self::restart_service( $svc_name );
		}

		return array(
			'ok'      => true,
			'message' => sprintf( '已部署 backend → %s（%d 个文件），服务重启：%s', $dst, $copied, $restart ),
		);
	}

	/**
	 * 递归同步目录（覆盖式，不删目标多余文件）。
	 * 排除：data/、.env、__pycache__、*.pyc、.git、secrets、备份。
	 *
	 * @param string $src 源目录。
	 * @param string $dst 目标目录。
	 * @return int|false 复制文件数，失败 false。
	 */
	protected static function sync_dir( $src, $dst ) {
		$exclude_dirs = array( 'data', '__pycache__', '.git', '.venv', 'venv', 'backup', 'backups' );
		$exclude_files = array( '.env', '.env.local', '.env.production' );
		$count = 0;

		$dir = @opendir( $src );
		if ( ! $dir ) {
			return false;
		}
		while ( false !== ( $entry = readdir( $dir ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$spath = $src . '/' . $entry;
			$dpath = $dst . '/' . $entry;
			if ( is_dir( $spath ) ) {
				if ( in_array( $entry, $exclude_dirs, true ) ) {
					continue;
				}
				if ( ! is_dir( $dpath ) ) {
					wp_mkdir_p( $dpath );
				}
				$sub = self::sync_dir( $spath, $dpath );
				if ( false === $sub ) {
					closedir( $dir );
					return false;
				}
				$count += $sub;
			} else {
				if ( in_array( $entry, $exclude_files, true ) ) {
					continue;
				}
				if ( preg_match( '/\.(pyc|pyo|log|db|sqlite|sqlite3)$/', $entry ) ) {
					continue;
				}
				if ( ! @copy( $spath, $dpath ) ) {
					closedir( $dir );
					return false;
				}
				$count++;
			}
		}
		closedir( $dir );
		return $count;
	}

	/**
	 * 重启 systemd 服务。
	 *
	 * @param string $name 服务名。
	 * @return string 结果描述。
	 */
	protected static function restart_service( $name ) {
		$name = escapeshellarg( $name );
		// 优先 sudo -n（免密），失败则直接 systemctl（部分部署 Web 用户有权限）。
		$cmd = "sudo -n systemctl restart {$name} 2>&1";
		exec( $cmd, $out, $code );
		if ( 0 === $code ) {
			// 再查状态。
			exec( "systemctl is-active {$name} 2>&1", $sout, $sret );
			$state = trim( implode( "\n", $sout ) );
			return 'ok(' . $state . ')';
		}
		// 退回直接 systemctl。
		exec( "systemctl restart {$name} 2>&1", $out2, $code2 );
		if ( 0 === $code2 ) {
			exec( "systemctl is-active {$name} 2>&1", $sout, $sret );
			return 'ok(' . trim( implode( "\n", $sout ) ) . ')';
		}
		return 'FAIL: ' . trim( implode( '; ', array_merge( $out, $out2 ) ) );
	}

	/**
	 * 当前服务状态（给设置页显示）。
	 *
	 * @return string
	 */
	public static function get_status() {
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			return 'windows-local（开发环境，无 systemd）';
		}
		$settings = ABP_Settings::get_settings();
		$name     = (string) $settings['service_name'];
		if ( '' === $name ) {
			return '未配置';
		}
		exec( 'systemctl is-active ' . escapeshellarg( $name ) . ' 2>&1', $out, $code );
		$state = trim( implode( "\n", $out ) );
		// 版本探测：服务目录 backend 的 mtime。
		$backend = rtrim( (string) $settings['service_dir'], '/\\' ) . '/backend/scheduler/daily_queue.py';
		$mt = file_exists( $backend ) ? date( 'Y-m-d H:i', filemtime( $backend ) ) : '未找到';
		return sprintf( '%s ｜ 服务目录代码更新时间：%s', $state, $mt );
	}

	/**
	 * 升级完成钩子：插件升级成功后自动部署 backend 并重启服务。
	 *
	 * @return void
	 */
	public static function on_upgrade_done() {
		$r = self::deploy();
		abp_log_write(
			'service',
			'service',
			$r['ok'] ? 'deploy_success' : 'deploy_failed',
			$r['ok'] ? 'ok' : 'fail',
			$r['message']
		);
	}
}
