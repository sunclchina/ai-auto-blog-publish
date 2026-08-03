<?php
/**
 * 指纹交叉验证（PHP 侧）— 与 Python tests/cross_check_fingerprint.py 对拍
 * 用法: php tests/cross_check_fingerprint.php
 * 规范: docs/05-plugin.md §6.1（S1-S7，唯一权威）
 */
declare(strict_types=1);

const ABP_STOPWORDS = ['的','了','是','在','和','与','及','就','都','而','或',
                       '我','你','他','她','它','们','有','也','着','一个','之','以','为','等'];

function abp_normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\p{P}\p{S}\p{Z}]+/u', '', $s);
    foreach (ABP_STOPWORDS as $w) { $s = str_replace($w, '', $s); }
    return $s;
}
function abp_features(string $n): array {
    $len = mb_strlen($n, 'UTF-8');
    if ($len === 0) return [];
    if ($len === 1) return [$n];
    $out = [];
    for ($i = 0; $i < $len - 1; $i++) { $out[] = mb_substr($n, $i, 2, 'UTF-8'); }
    return $out;
}
function abp_hash64(string $f): int {
    $h1 = crc32($f . "\x01");
    $h2 = crc32($f . "\x02");
    return ($h1 << 32) | $h2;
}
function abp_simhash(string $text): string {
    $n = abp_normalize($text);
    $v = array_fill(0, 64, 0);
    // 逐 gram 累加（±1 等价于频次加权 S4；避免 array_count_values 数字键转 int 的坑）
    foreach (abp_features($n) as $f) {
        $h = abp_hash64($f);
        for ($b = 0; $b < 64; $b++) { $v[$b] += (($h >> $b) & 1) ? 1 : -1; }
    }
    $hash = 0;
    for ($b = 0; $b < 64; $b++) { if ($v[$b] > 0) { $hash |= (1 << $b); } }
    return sprintf('%016x', $hash);
}
function abp_hamming(string $a, string $b): int {
    // hexdec 对 64bit 值返回 float 丢精度 → 拆两个 32 位半字比较
    $x1 = hexdec(substr($a, 0, 8)) ^ hexdec(substr($b, 0, 8));
    $x2 = hexdec(substr($a, 8, 8)) ^ hexdec(substr($b, 8, 8));
    $c = 0;
    while ($x1) { $x1 &= $x1 - 1; $c++; }
    while ($x2) { $x2 &= $x2 - 1; $c++; }
    return $c;
}

$samples = [
    "今日A股三大指数集体收跌，上证指数跌0.42%，深证成指跌0.77%。两市成交额1.03万亿，较昨日缩量。板块方面，银行板块逆势走强。",
    "Nginx 反向代理配置实战：从入门到常见报错排查，服务器运维必备技能，WordPress 提速优化指南。",
    "静夜思 李白 床前明月光疑是地上霜举头望明月低头思故乡 这是一首描写思乡之情的经典唐诗。",
];
foreach ($samples as $i => $s) {
    echo "PHP_SAMPLE" . ($i + 1) . "=" . abp_simhash($s) . "\n";
}
// 汉明距离对拍
$a = abp_simhash($samples[0]);
$b = abp_simhash("今日A股三大指数集体收跌，上证指数跌0.42%，深证成指跌0.77%，两市成交额1.03万亿。板块方面，银行板块逆势走强。");
echo "PHP_HAMMING_AB=" . abp_hamming($a, $b) . "\n";
echo "PHP_OK=1\n";
