<?php
/**
 * 插件冒烟测试：SimHash 指纹纯函数（不依赖 WordPress）
 *
 * 用法：php tests/wp_plugin_smoke_fingerprint.php
 * 覆盖：归一化一致性、指纹确定性、汉明距离、判重阈值行为（真实文章长度）。
 * 说明：本文件为测试脚本（原则 2：测试数据明确标注，不进入业务逻辑）。
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../wp-plugin/includes/class-abp-fingerprint.php';

$pass = 0;
$fail = 0;

function check( $name, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "[PASS] {$name}\n";
	} else {
		$fail++;
		echo "[FAIL] {$name} {$extra}\n";
	}
}

/* 真实长度的文章样本（模拟 Python 侧产出的复盘文） */
$article_a = '今日A股三大指数集体收涨，沪指涨0.6%报3456.78点，深成指涨0.9%，创业板指涨1.2%。两市成交额突破1.2万亿，较昨日放量约800亿元。北向资金全天净流入超80亿元，其中沪股通净流入45亿元，深股通净流入35亿元。板块方面，半导体、人工智能领涨，银行、煤炭小幅回调。个股涨多跌少，超3700只个股上涨，仅1200余只下跌，市场赚钱效应明显。消息面上，央行开展2000亿元逆回购操作，维护银行体系流动性合理充裕。技术面看，沪指站稳5日与10日均线，MACD红柱继续放大，短期趋势向好。操作策略上，建议控制仓位在六成左右，重点关注业绩确定性的科技龙头与高股息板块的轮动机会。';

$article_a2 = '今日A股三大指数集体收涨，沪指涨0.6%报3456.78点，深成指涨0.9%，创业板指涨1.2%。两市成交额突破1.2万亿，较昨日放量约800亿元。北向资金今日净流入超80亿元，其中沪股通净流入45亿元，深股通净流入35亿元。板块方面，半导体、人工智能领涨，银行、煤炭小幅回调。个股涨多跌少，逾3700只个股上涨，仅1200余只下跌，市场赚钱效应明显。消息面上，央行开展2000亿元逆回购操作，维护银行体系流动性合理充裕。技术面看，沪指站稳5日与10日均线，MACD红柱继续放大，短期趋势向好。操作策略上，建议控制仓位在六成左右，重点关注业绩确定性的科技龙头与高股息板块的轮动机会。';

$article_b = 'MySQL 慢查询优化实战：某生产环境订单表数据量超过两千万行，接口响应从 800ms 飙升至 3.2s。排查发现 where 条件中的 status 字段未建索引，order by create_time 与 group by user_id 混用导致临时表排序。优化方案：为 (status, create_time) 建联合索引，将 group by 改写为子查询先聚合再关联，并把高频查询结果写入 Redis 缓存，TTL 设置为 300 秒。改造后接口平均响应降至 120ms，高峰期 P99 稳定在 400ms 以内，数据库 CPU 占用从 78% 下降到 22%。';

/* 1. 归一化：标点/空白差异不影响指纹（同文不同排版） */
$h1 = abp_simhash( $article_a );
$h2 = abp_simhash( str_replace( array( '，', '。', ' ' ), array( ',', '.', '　' ), $article_a ) );
check( '归一化一致（标点/空白差异 → 相同指纹）', $h1 === $h2, "h1={$h1} h2={$h2}" );
check( '指纹为 16 位十六进制', 16 === strlen( $h1 ) && ctype_xdigit( $h1 ) );

/* 2. 指纹确定性 */
check( '指纹确定性', $h1 === abp_simhash( $article_a ) );

/* 3. 汉明距离：相同指纹 = 0 */
check( '相同指纹汉明距离为 0', 0 === abp_hamming( $h1, $h1 ) );

/* 4. 近似重复文章（结尾改动约 15%）→ 距离 < 4 判重 */
$d = abp_hamming( $h1, abp_simhash( $article_a2 ) );
check( '近似重复文章距离 < 4（判重）', $d < 4, "d={$d}" );

/* 5. 同主题但完全不同的文章 → 不判重 */
$article_c = '今日市场先抑后扬，早盘低开后一路震荡走高。午后券商板块异动拉升，带动指数快速上行，尾盘小幅回落收红。两市成交额较昨日明显萎缩，显示观望情绪仍浓。外资连续第三日净卖出，主要减持权重白马。题材方面，低空经济概念午后爆发，多股涨停；机器人概念分化明显。综合来看，当前处于存量博弈阶段，建议保持耐心，等待放量信号确认方向后再加大仓位。';
$d = abp_hamming( abp_simhash( $article_a ), abp_simhash( $article_c ) );
check( '同主题不同文距离 >= 4（不误判）', $d >= 4, "d={$d}" );

/* 6. 完全无关文章 → 距离大 */
$d = abp_hamming( abp_simhash( $article_a ), abp_simhash( $article_b ) );
check( '无关文章距离较大（> 20）', $d > 20, "d={$d}" );

/* 7. 完全相同文章（重复提交）→ 距离 0，必判重 */
$d = abp_hamming( $h1, abp_simhash( $article_a ) );
check( '相同文章距离为 0（必判重）', 0 === $d, "d={$d}" );

/* 8. 空文本边界 */
check( '空文本指纹为全零', '0000000000000000' === abp_simhash( '' ) );

/* 9. 非法指纹输入 */
check( '非法指纹汉明距离返回最大值', PHP_INT_MAX === abp_hamming( 'abc', $h1 ) );

echo "\n=== 结果：PASS {$pass} / FAIL {$fail} ===\n";
exit( $fail > 0 ? 1 : 0 );
