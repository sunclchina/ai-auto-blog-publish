<?php
/**
 * A-Blog 全家桶打包（PHP ZipArchive 版，正斜杠条目，WordPress/Linux 兼容）。
 *
 * 与 deploy/package-plugin.bat 同清单，替换其 .NET ZipFile 实现（反斜杠坑，见 MEMORY 教训）：
 *   1. WP 插件：主文件 / readme.txt / uninstall.php / includes / mu-plugins / assets
 *   2. Python backend：排除 __pycache__ / data / *.pyc / config.yaml（运行时配置不进包）
 *   3. deploy 脚本并入 backend\
 *   4. backend\data\README.txt 模板
 *
 * 用法：php deploy/package-plugin.php   → dist\ai-auto-blog-publish-v<VER>.zip
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$root = dirname( __DIR__ );
$ver  = '';
$main = file_get_contents( $root . '/ai-auto-blog-publish.php' );
if ( preg_match( "/define\(\s*'ABP_VERSION'\s*,\s*'([^']+)'/", $main, $m ) ) {
	$ver = $m[1];
}
if ( '' === $ver ) {
	fwrite( STDERR, "ERROR: cannot read ABP_VERSION\n" );
	exit( 1 );
}

$tmp = $root . '/dist/_pkg_tmp';
$dst = $tmp . '/ai-auto-blog-publish';
$out = $root . '/dist/ai-auto-blog-publish-v' . $ver . '.zip';

echo "[A-Blog] version: {$ver}\n";

// ---- 清理 ----
if ( is_dir( $tmp ) ) {
	remove_tree( $tmp );
}
mkdir( $dst . '/assets', 0777, true );

// ---- 1. WP 插件 ----
foreach ( array( 'ai-auto-blog-publish.php', 'readme.txt', 'uninstall.php' ) as $f ) {
	copy( $root . '/' . $f, $dst . '/' . $f );
}
copy_tree( $root . '/includes', $dst . '/includes' );
copy_tree( $root . '/mu-plugins', $dst . '/mu-plugins' );
copy_tree( $root . '/assets/css', $dst . '/assets/css' );
copy_tree( $root . '/assets/js', $dst . '/assets/js' );
echo "[1/4] WP plugin copied\n";

// ---- 2. Python backend（排除密钥/缓存/运行时配置）----
copy_tree_filtered(
	$root . '/backend',
	$dst . '/backend',
	array( '__pycache__', 'data' ),           // 排除目录
	array( '.pyc', 'config.yaml', '.log' )    // 排除文件（后缀/名）
);
echo "[2/4] backend copied (secrets excluded)\n";

// ---- 3. deploy 脚本并入 backend\ ----
$scripts = array(
	'install-backend.bat' => 'install-backend.bat',
	'start-backend.bat'   => 'start-backend.bat',
	'install.sh'          => 'install.sh',
	'ablog.service'       => 'ablog.service',
	'security.md'         => 'security.md',
	'backend-README.md'   => 'README.md',
);
foreach ( $scripts as $src => $name ) {
	$s = $root . '/deploy/' . $src;
	if ( file_exists( $s ) ) {
		copy( $s, $dst . '/backend/' . $name );
	}
}
echo "[3/4] deploy scripts bundled\n";

// ---- 4. backend\data 模板 ----
mkdir( $dst . '/backend/data', 0777, true );
file_put_contents(
	$dst . '/backend/data/README.txt',
	"A-Blog 伴生服务数据目录（安装后生成，勿提交到版本库）:\n"
	. "  ablog.db         SQLite 数据库（任务/选题/指纹）\n"
	. "  wp_token.txt     WordPress REST Bearer Token\n"
	. "  tavily_key.txt   Tavily 搜索 API Key\n"
	. "  sensitive_words.txt  敏感词表（缺失自动创建）\n"
	. "  logs\\            运行日志\n"
);
echo "[4/4] data template ready\n";

// ---- 5. 打包（正斜杠条目）----
if ( file_exists( $out ) ) {
	unlink( $out );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "ERROR: cannot create zip\n" );
	exit( 1 );
}
$base_len = strlen( $tmp );
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS )
);
foreach ( $it as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$rel = str_replace( '\\', '/', substr( $file->getPathname(), $base_len + 1 ) );
	$zip->addFile( $file->getPathname(), $rel );
}
$zip->close();

// ---- 6. 验证（MEMORY 教训：反斜杠 / 顶层目录 / 凭据）----
$z = new ZipArchive();
if ( true !== $z->open( $out ) ) {
	fwrite( STDERR, "ERROR: cannot reopen zip for verification\n" );
	exit( 1 );
}
$bad_backslash = array();
$credentials   = array();
$top = array();
for ( $i = 0; $i < $z->numFiles; $i++ ) {
	$name = $z->getNameIndex( $i );
	if ( false !== strpos( $name, '\\' ) ) {
		$bad_backslash[] = $name;
	}
	$low = strtolower( $name );
	foreach ( array( '.git-credentials', 'wp_token', 'tavily_key', 'sensitive_words', 'ablog.db', 'config.yaml' ) as $k ) {
		if ( false !== strpos( $low, $k ) && false === strpos( $low, 'example' ) && false === strpos( $low, 'readme' ) ) {
			$credentials[] = $name;
		}
	}
	$first = strtok( $name, '/' );
	if ( $first ) {
		$top[ $first ] = true;
	}
}
$z->close();

$ok = true;
if ( $bad_backslash ) {
	$ok = false;
	echo "FAIL: backslash entries:\n  " . implode( "\n  ", array_slice( $bad_backslash, 0, 10 ) ) . "\n";
} else {
	echo "OK: no backslash entries\n";
}
if ( ! isset( $top['ai-auto-blog-publish'] ) ) {
	$ok = false;
	echo "FAIL: top-level dir missing (ai-auto-blog-publish)\n";
} else {
	echo "OK: top-level = ai-auto-blog-publish\n";
}
if ( $credentials ) {
	$ok = false;
	echo "FAIL: credential/secret files leaked:\n  " . implode( "\n  ", $credentials ) . "\n";
} else {
	echo "OK: no credentials/secrets\n";
}
if ( file_exists( $root . '/my-project.git-credentials' ) ) {
	$in_zip = in_array( 'my-project.git-credentials', array_keys( $top ), true ) || in_array( 'ai-auto-blog-publish/my-project.git-credentials', array_map( function ( $n ) { return $n; }, array() ), true );
	echo ( $in_zip ? "FAIL" : "OK" ) . ": my-project.git-credentials " . ( $in_zip ? 'LEAKED' : 'excluded' ) . "\n";
}

// 主文件存在
$z = new ZipArchive();
$z->open( $out );
$has_main = false !== $z->locateName( 'ai-auto-blog-publish/ai-auto-blog-publish.php' );
$z->close();
echo ( $has_main ? "OK" : "FAIL" ) . ": main plugin file present\n";
if ( ! $has_main ) {
	$ok = false;
}

// 清理临时目录
remove_tree( $tmp );

if ( ! $ok ) {
	echo "PACKAGE FAILED: " . $out . "\n";
	exit( 1 );
}
$size = filesize( $out );
echo "PACKAGE OK: " . $out . " (" . round( $size / 1024 ) . " KB)\n";

// ---------------------------------------------------------------------

function copy_tree( $src, $dst ) {
	if ( ! is_dir( $src ) ) {
		return;
	}
	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0777, true );
	}
	foreach ( scandir( $src ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$s = $src . '/' . $item;
		$d = $dst . '/' . $item;
		if ( is_dir( $s ) ) {
			copy_tree( $s, $d );
		} else {
			copy( $s, $d );
		}
	}
}

function copy_tree_filtered( $src, $dst, $skip_dirs, $skip_files ) {
	if ( ! is_dir( $src ) ) {
		return;
	}
	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0777, true );
	}
	foreach ( scandir( $src ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$s = $src . '/' . $item;
		$d = $dst . '/' . $item;
		if ( is_dir( $s ) ) {
			if ( in_array( $item, $skip_dirs, true ) ) {
				continue;
			}
			copy_tree_filtered( $s, $d, $skip_dirs, $skip_files );
		} else {
			$skip = false;
			foreach ( $skip_files as $sf ) {
				if ( '.' === $sf[0] ) {
					if ( substr( $item, -strlen( $sf ) ) === $sf ) {
						$skip = true;
						break;
					}
				} elseif ( $item === $sf ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				copy( $s, $d );
			}
		}
	}
}

function remove_tree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$p = $dir . '/' . $item;
		is_dir( $p ) ? remove_tree( $p ) : unlink( $p );
	}
	rmdir( $dir );
}
