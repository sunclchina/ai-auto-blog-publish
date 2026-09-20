<?php
/**
 * 独立逻辑测试：ABP_Updater 自动升级机制（stub WP 函数）。
 * 运行：php tests/php_updater_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('ABP_PLUGIN_FILE')) { define('ABP_PLUGIN_FILE', 'D:/my-project/A-Blog/ai-auto-blog-publish.php'); }
if (!defined('ABP_PLUGIN_DIR')) { define('ABP_PLUGIN_DIR', 'D:/my-project/A-Blog/'); }
if (!defined('ABP_VERSION')) { define('ABP_VERSION', '1.5.60'); }

/* ---- WP stub ---- */
class WP_Error {
	public $errors = array();
	public function __construct($code = '', $message = '') { if ($code) { $this->errors[$code] = array($message); } }
	public function get_error_message() { $m = reset($this->errors); return $m ? (string) $m[0] : ''; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function plugin_basename($file) { return 'ai-auto-blog-publish/ai-auto-blog-publish.php'; }
function current_time($type) { return time(); }
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($ts, $freq, $hook) { $GLOBALS['ABP_SCHEDULED'][] = $hook; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['ABP_SCHEDULED'] = array_values(array_diff(isset($GLOBALS['ABP_SCHEDULED'])?$GLOBALS['ABP_SCHEDULED']:array(), array($hook))); }
function add_filter($t, $c, $p = 10, $a = 1) { $GLOBALS['ABP_FILTERS'][] = $t . ':' . (is_array($c)?$c[1]:$c); }
function add_action($t, $c, $p = 10, $a = 1) { $GLOBALS['ABP_ACTIONS'][] = $t . ':' . (is_array($c)?$c[1]:$c); }
function get_site_transient($k) { return isset($GLOBALS['ABP_TRANSIENT'][$k]) ? $GLOBALS['ABP_TRANSIENT'][$k] : false; }
function set_site_transient($k, $v, $ttl) { $GLOBALS['ABP_TRANSIENT'][$k] = $v; }
function delete_site_transient($k) { unset($GLOBALS['ABP_TRANSIENT'][$k]); }
function abp_log_write($task_id = '', $column = '', $action = '', $status = 'ok', $message = '') { $GLOBALS['ABP_LOGS'][] = compact('task_id', 'column', 'action', 'status', 'message'); }
function wp_remote_get($url, $args = array()) {
	$m = isset($GLOBALS['ABP_MOCK_REMOTE']) ? $GLOBALS['ABP_MOCK_REMOTE'] : array('code' => 200, 'body' => '{}');
	$GLOBALS['ABP_LAST_URL'] = $url;
	$GLOBALS['ABP_LAST_ARGS'] = $args;
	return array('response' => array('code' => $m['code']), 'body' => $m['body']);
}
function wp_remote_retrieve_response_code($resp) { return isset($resp['response']['code']) ? (int) $resp['response']['code'] : 0; }
function wp_remote_retrieve_body($resp) { return isset($resp['body']) ? $resp['body'] : ''; }
function untrailingslashit($s) { return rtrim($s, '/\\'); }
function get_option($k, $d = false) { return isset($GLOBALS['ABP_OPTION'][$k]) ? $GLOBALS['ABP_OPTION'][$k] : $d; }
function update_option($k, $v) { $GLOBALS['ABP_OPTION'][$k] = $v; return true; }
function admin_url($p = '') { return 'http://example.test/wp-admin/' . $p; }

/* ---- ABP_Settings stub ---- */
if (!class_exists('ABP_Settings')) {
	class ABP_Settings {
		public static function get_settings() {
			$def = array(
				'auto_update_enabled' => 'on',
				'github_owner' => 'sunclchina',
				'github_repo' => 'ai-auto-blog-publish',
				'github_api_base' => 'https://api.github.com',
				'github_token' => '',
			);
			$opt = get_option('abp_settings', array());
			return array_merge($def, is_array($opt) ? $opt : array());
		}
	}
}

require __DIR__ . '/../includes/class-abp-updater.php';

$pass = 0; $fail = 0;
function check($name, $cond) {
	global $pass, $fail;
	if ($cond) { $pass++; echo "PASS  $name\n"; }
	else       { $fail++; echo "FAIL  $name\n"; }
}
function set_settings($patch) { $s = ABP_Settings::get_settings(); $GLOBALS['ABP_OPTION']['abp_settings'] = array_merge($s, $patch); }
function mock_release($tag, $assets = array(), $zipball = '') {
	$GLOBALS['ABP_MOCK_REMOTE'] = array('code' => 200, 'body' => json_encode(array(
		'tag_name' => $tag,
		'html_url' => 'https://github.com/sunclchina/ai-auto-blog-publish/releases/tag/' . $tag,
		'assets' => $assets,
		'zipball_url' => $zipball,
	)));
}

/* ---- 1. api_base ---- */
set_settings(array('github_api_base' => 'https://api.github.com'));
check('api_base 默认', ABP_Updater::api_base() === 'https://api.github.com');
set_settings(array('github_api_base' => 'https://git.example.com/api/v1'));
check('api_base 自建源', ABP_Updater::api_base() === 'https://git.example.com/api/v1');
set_settings(array('github_api_base' => 'not-a-url'));
check('api_base 非法回落默认', ABP_Updater::api_base() === 'https://api.github.com');
set_settings(array('github_api_base' => 'https://api.github.com'));

/* ---- 2. get_remote_release URL 拼接 ---- */
$GLOBALS['ABP_TRANSIENT'] = array();
mock_release('v9.9.9');
$r = ABP_Updater::get_remote_release(true);
check('release 拉取成功', is_array($r) && $r['tag_name'] === 'v9.9.9');
check('URL 使用 api_base 拼接', strpos($GLOBALS['ABP_LAST_URL'], 'https://api.github.com/repos/sunclchina/ai-auto-blog-publish/releases/latest') === 0);
set_settings(array('github_api_base' => 'https://git.example.com/api/v1'));
$GLOBALS['ABP_TRANSIENT'] = array();
mock_release('v9.9.9');
ABP_Updater::get_remote_release(true);
check('URL 使用自建源', strpos($GLOBALS['ABP_LAST_URL'], 'https://git.example.com/api/v1/repos/') === 0);
set_settings(array('github_api_base' => 'https://api.github.com'));

/* ---- 3. package_url 版本优先 ---- */
$assets = array(
	array('name' => 'ai-auto-blog-publish.zip', 'browser_download_url' => 'https://a.example/x.zip'),
	array('name' => 'ai-auto-blog-publish-v9.9.9.zip', 'browser_download_url' => 'https://a.example/v999.zip'),
	array('name' => 'ai-auto-blog-publish-1.5.99.zip', 'browser_download_url' => 'https://a.example/1599.zip'),
);
check('资产名含 tag 版本优先', ABP_Updater::package_url(array('tag_name' => 'v9.9.9', 'assets' => $assets)) === 'https://a.example/v999.zip');
check('无匹配资产回落 zipball', ABP_Updater::package_url(array('tag_name' => 'v9.9.9', 'assets' => array(), 'zipball_url' => 'https://codeload.example/z.zip')) === 'https://codeload.example/z.zip');
check('资产为空且无 zipball → 空串', ABP_Updater::package_url(array('tag_name' => 'v9.9.9', 'assets' => array())) === '');

/* ---- 4. check_update 注入 ---- */
$transient = (object) array('checked' => array(ABP_Updater::plugin_basename() => ABP_VERSION), 'response' => array());
$GLOBALS['ABP_TRANSIENT'] = array();
mock_release('v9.9.9', $assets);
$out = ABP_Updater::check_update($transient);
$base = ABP_Updater::plugin_basename();
check('新版本注入 response', isset($out->response[$base]) && $out->response[$base]->new_version === '9.9.9');
check('注入包地址为版本优先资产', isset($out->response[$base]) && $out->response[$base]->package === 'https://a.example/v999.zip');
mock_release('v1.0.0', $assets);
$GLOBALS['ABP_TRANSIENT'] = array(); // 清掉 v9.9.9 缓存，确保读取远端 v1.0.0。
$transient2 = (object) array('checked' => array($base => ABP_VERSION), 'response' => array());
$out2 = ABP_Updater::check_update($transient2);
check('远端低于当前 → 不注入（防降级）', !isset($out2->response[$base]));

/* ---- 5. fix_source_dir 校验 ---- */
$hook = array('plugin' => ABP_Updater::plugin_basename());
$GLOBALS['wp_filesystem'] = null;
// 缺主插件文件 → WP_Error（独立临时目录，避免场景间相互影响）
$tmpA = sys_get_temp_dir() . '/abp_upd_test_a_' . uniqid();
@mkdir($tmpA . '/ai-auto-blog-publish-9.9.9', 0777, true);
$ret = ABP_Updater::fix_source_dir($tmpA . '/ai-auto-blog-publish-9.9.9', $tmpA, null, $hook);
check('缺主插件文件 → WP_Error 中止', is_wp_error($ret));
// 含主插件文件 → 重命名成功返回 slug 目录
$tmpB = sys_get_temp_dir() . '/abp_upd_test_b_' . uniqid();
$srcB = $tmpB . '/ai-auto-blog-publish-9.9.9';
$newB = $tmpB . '/ai-auto-blog-publish';
@mkdir($srcB, 0777, true);
file_put_contents($srcB . '/ai-auto-blog-publish.php', '<?php');
$ret2 = ABP_Updater::fix_source_dir($srcB, $tmpB, null, $hook);
// Windows 下 DIRECTORY_SEPARATOR 为反斜杠，$ret2 与正斜杠拼的 $newB 字符串不等，用存在性断言。
check('含主插件文件 → 重命名并返回', !is_wp_error($ret2) && is_dir($newB) && is_file($newB . '/ai-auto-blog-publish.php'));
// 非本插件升级不处理
$tmpC = sys_get_temp_dir() . '/abp_upd_test_c_' . uniqid();
$other = $tmpC . '/other-plugin';
@mkdir($other, 0777, true);
$ret3 = ABP_Updater::fix_source_dir($other, $tmpC, null, array('plugin' => 'other/other.php'));
check('其它插件升级原样返回', $ret3 === $other);

/* ---- 6. force_check ---- */
$GLOBALS['ABP_TRANSIENT'] = array();
mock_release('v9.9.9', $assets);
$fc = ABP_Updater::force_check();
check('force_check 返回新版本状态', $fc['ok'] === true && $fc['latest'] === '9.9.9' && $fc['has_update'] === true);
$GLOBALS['ABP_MOCK_REMOTE'] = array('code' => 404, 'body' => '{}');
$fc2 = ABP_Updater::force_check();
check('force_check 网络失败降级', $fc2['ok'] === false);

/* ---- 7. init 挂载钩子 ---- */
$GLOBALS['ABP_FILTERS'] = array(); $GLOBALS['ABP_ACTIONS'] = array(); $GLOBALS['ABP_SCHEDULED'] = array();
ABP_Updater::init();
check('init 挂载 update 过滤', in_array('pre_set_site_transient_update_plugins:check_update', $GLOBALS['ABP_FILTERS'], true));
check('init 挂载升级完成钩子', in_array('upgrader_process_complete:upgrade_done', $GLOBALS['ABP_ACTIONS'], true));
check('init 注册每日检查定时', in_array('abp_updater_daily', $GLOBALS['ABP_SCHEDULED'], true));

/* ---- 清理临时目录 ---- */
function rrmdir($d) { if (!is_dir($d)) { return; } foreach (scandir($d) as $f) { if ($f === '.' || $f === '..') { continue; } $p = $d . '/' . $f; is_dir($p) ? rrmdir($p) : unlink($p); } rmdir($d); }
foreach (array($tmpA, $tmpB, $tmpC) as $d) { rrmdir($d); }

echo "\n结果：PASS {$pass} / FAIL {$fail}\n";
exit($fail === 0 ? 0 : 1);
