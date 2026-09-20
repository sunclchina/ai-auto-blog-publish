<?php
/**
 * 独立逻辑测试：ABP_Stock 日历 + 复盘日期解析（stub WP 函数）。
 * 运行：php tests/php_stock_logic_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!function_exists('current_time')) {
    function current_time($type) { return time(); }
}

require __DIR__ . '/../includes/class-abp-stock.php';

$pass = 0; $fail = 0;
function check($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else       { $fail++; echo "FAIL  $name\n"; }
}
function ts($d) { return strtotime($d . ' 00:00:00 UTC'); }

// ---- 2026 日历（交易所口径）----
check('9/20（周日，补班上班日）不是交易日', ABP_Stock::is_trading_day(ts('2026-09-20')) === false);
check('10/10（周六，补班上班日）不是交易日', ABP_Stock::is_trading_day(ts('2026-10-10')) === false);
check('10/8（周四，国庆后开市）是交易日', ABP_Stock::is_trading_day(ts('2026-10-08')) === true);
check('2/24（周二，春节后开市）是交易日', ABP_Stock::is_trading_day(ts('2026-02-24')) === true);
check('9/18（周五）是交易日', ABP_Stock::is_trading_day(ts('2026-09-18')) === true);
check('9/25（中秋休市）不是交易日', ABP_Stock::is_trading_day(ts('2026-09-25')) === false);
check('1/2（元旦休市）不是交易日', ABP_Stock::is_trading_day(ts('2026-01-02')) === false);
check('1/5（元旦后开市）是交易日', ABP_Stock::is_trading_day(ts('2026-01-05')) === true);

// ---- previous_trading_day ----
check('9/20 上一交易日 = 9/18', gmdate('Y-m-d', ABP_Stock::previous_trading_day(ts('2026-09-20'))) === '2026-09-18');
check('9/21（周一）上一交易日 = 9/18', gmdate('Y-m-d', ABP_Stock::previous_trading_day(ts('2026-09-21'))) === '2026-09-18');
check('2/16（周一，春节后）上一交易日 = 2/13', gmdate('Y-m-d', ABP_Stock::previous_trading_day(ts('2026-02-16'))) === '2026-02-13');
check('2/24（周二）上一交易日 = 2/13（跳过 2/15-2/23）', gmdate('Y-m-d', ABP_Stock::previous_trading_day(ts('2026-02-24'))) === '2026-02-13');
check('8/20（周四）上一交易日 = 8/19', gmdate('Y-m-d', ABP_Stock::previous_trading_day(ts('2026-08-20'))) === '2026-08-19');
check('10/8 上一交易日 = 9/30（跳过国庆+中秋）', gmdate('Y-m-d', ABP_Stock::previous_trading_day(ts('2026-10-08'))) === '2026-09-30');

// ---- review_date_of ----
check('topic 带日期（YYYY-MM-DD）', ABP_Stock::review_date_of(array('topic' => '2026-09-18 A股每日复盘', 'task_id' => '20260921-stock-001')) === '2026-09-18');
check('topic 中文日期', ABP_Stock::review_date_of(array('topic' => '2026年8月20日 A股市场：数据失联', 'task_id' => '20260820-stock-001')) === '2026-08-20');
check('topic 斜杠日期', ABP_Stock::review_date_of(array('topic' => '2026/09/18 A股复盘', 'task_id' => '20260921-stock-001')) === '2026-09-18');
check('topic 点号日期', ABP_Stock::review_date_of(array('topic' => '2026.09.18 A股复盘', 'task_id' => '20260921-stock-001')) === '2026-09-18');
check('topic 无日期回退 task_id', ABP_Stock::review_date_of(array('topic' => 'A股每日复盘', 'task_id' => '20260820-stock-001')) === '2026-08-20');
check('topic/task_id 均无日期 → 空串', ABP_Stock::review_date_of(array('topic' => '', 'task_id' => '')) === '');

// ---- data_sufficient 数据闸（v1.5.6：仅指数视为严重残缺，post 7482 回归）----
check('仅指数 → 不充分（历史补写日K）', ABP_Stock::data_sufficient(array('indices' => array(array('close' => 3911.87)), 'sectors' => array(), 'breadth' => array(), 'main_flow' => null, 'industry_flow' => array(), 'limit' => array(), 'margin' => null, 'north' => null)) === false);
check('无指数 → 不充分', ABP_Stock::data_sufficient(array()) === false);
check('指数+板块 → 充分', ABP_Stock::data_sufficient(array('indices' => array(array('close' => 3911.87)), 'sectors' => array(array('name' => '半导体')))) === true);
check('指数+涨跌家数 → 充分', ABP_Stock::data_sufficient(array('indices' => array(array('close' => 3911.87)), 'breadth' => array('up' => 3000, 'down' => 200))) === true);
check('指数+资金流 → 充分', ABP_Stock::data_sufficient(array('indices' => array(array('close' => 3911.87)), 'main_flow' => 123.45)) === true);
check('指数+涨跌停 → 充分', ABP_Stock::data_sufficient(array('indices' => array(array('close' => 3911.87)), 'limit' => array('zt' => 45))) === true);
check('指数+北向 → 充分', ABP_Stock::data_sufficient(array('indices' => array(array('close' => 3911.87)), 'north' => array('ratio_pct' => 8.2))) === true);

echo "\n结果：PASS {$pass} / FAIL {$fail}\n";
exit($fail === 0 ? 0 : 1);
