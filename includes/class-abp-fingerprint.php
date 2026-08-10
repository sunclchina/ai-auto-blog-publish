<?php
/**
 * A-Blog 文章指纹（SimHash）—— 与 Python 侧 backend/core/fingerprint.py 同算法
 *
 * ⚠️ 本实现严格对齐 docs/05-plugin.md §6.1 的 S1-S7 规范（架构师定稿，跨语言一致）：
 *
 * S1 归一化（顺序固定）：
 *    a. mb_strtolower 转小写；
 *    b. 删除全部 Unicode 标点/符号/空白：preg_replace('/[\p{P}\p{S}\p{Z}]+/u', '', $s)
 *       （注意：不能用 \p{Han} 反选，PCRE2 中 Han script 含 CJK 标点如 U+3002；
 *         也不能用 [^\p{L}\p{N}]，其额外剔除控制符/组合符，与规范不一致）；
 *    c. 删除停用词（固定 25 词表 ABP_STOPWORDS，逐词 str_replace 一次；
 *       词表互不包含，结果确定；Python 侧同样处理）。
 * S2 特征：字符级 2-gram（L==0 → 空；L==1 → [文本]；否则 [substr(i,2) for i in 0..L-2]）。
 * S3 特征哈希 64bit：h64 = (crc32(f . "\x01") << 32) | crc32(f . "\x02")
 *    —— PHP crc32() 与 Python zlib.crc32 均为标准 CRC-32/IEEE（多项式 0xEDB88320），结果一致；
 *    实现时拆两个 32 位半字避免 64 位移位溢出：h1=crc32(f."\x01") 为高 32 位（指纹 bit63..32），
 *    h2=crc32(f."\x02") 为低 32 位（指纹 bit31..0），bit 顺序：h1 的 MSB 映射指纹 MSB。
 * S4 加权累加：v[0..63] 初始 0；每个特征按出现频次 w 累加 v[b] += ((h64>>b)&1) ? +w : -w。
 *    （本实现逐次 ±1 累加，等价于频次加权，结果一致。）
 * S5 收敛：hash 第 b 位 = (v[b] > 0) ? 1 : 0（==0 记 0）。
 * S6 输出：16 位小写 hex（64bit → %016x）。
 * S7 判重：汉明距离 popcount(a xor b) < 4 → 重复；
 *    比对范围 = wp_abp_fingerprints 表 + wp_postmeta 键 abp_fingerprint（发布时双写）。
 *
 * 指纹存储：插件建表 wp_abp_fingerprints（post_id, fhash, created_at），
 * 建文成功后由发布类调用 abp_fingerprint_save() 入库（同时写 postmeta abp_fingerprint）；
 * /check 与发布前查重以表 + postmeta + 存量惰性索引为数据源。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Fingerprint {

	/** 固定停用词表（S1.c，与 docs/05-plugin.md §6.1 完全一致，共 25 词；词表互不包含）。 */
	const STOPWORDS = array(
		'的', '了', '是', '在', '和', '与', '及', '就', '都', '而', '或',
		'我', '你', '他', '她', '它', '们', '有', '也', '着', '一个', '之', '以', '为', '等',
	);

	/** crc32 双哈希盐值（S3：\x01 高 32 位 / \x02 低 32 位，跨语言一致）。 */
	const SALT_H = "\x01";
	const SALT_L = "\x02";

	/** 判重汉明距离阈值：< 4 判重。 */
	const THRESHOLD = 4;

	/** 字节汉明重量查表（惰性构建一次）。 */
	private static $byte_bit_count = null;

	/**
	 * 建指纹表（激活钩子调用）。
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'abp_fingerprints';
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			fhash CHAR(16) NOT NULL DEFAULT '',
			post_title VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY fhash (fhash)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * 文本归一化（S1：转小写 → 去标点/符号/空白 → 删停用词，顺序固定）。
	 *
	 * @param string $text 原始文本。
	 * @return string 归一化文本。
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		// a. 转小写。
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		// b. 去标点/符号/空白（\p{P}\p{S}\p{Z}，覆盖全半角与中文标点）。
		$text = preg_replace( '/[\p{P}\p{S}\p{Z}]+/u', '', $text );
		// c. 删停用词：逐词 str_replace 一次（词表互不包含，结果确定）。
		foreach ( self::STOPWORDS as $word ) {
			$text = str_replace( $word, '', $text );
		}
		return (string) $text;
	}

	/**
	 * 计算文本的 64 位 SimHash 指纹。
	 *
	 * @param string $text 原始文本（正文 HTML 或纯文本均可，归一化时自动去标签外的符号；建议先 strip 标签）。
	 * @return string 16 位十六进制小写指纹；空文本返回 '0000000000000000'。
	 */
	public static function simhash( $text ) {
		$norm = self::normalize( $text );
		if ( '' === $norm ) {
			return '0000000000000000';
		}

		// 字符 2-gram（UTF-8 安全）。
		$grams = array();
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $norm, 'UTF-8' ) : strlen( $norm );
		if ( $len < 2 ) {
			$grams[] = $norm;
		} else {
			for ( $i = 0; $i < $len - 1; $i++ ) {
				$grams[] = function_exists( 'mb_substr' ) ? mb_substr( $norm, $i, 2, 'UTF-8' ) : substr( $norm, $i, 2 );
			}
		}

		// 64 位权重累加（S4：逐次 ±1 等价于按频次加权）。
		$weights = array_fill( 0, 64, 0 );
		foreach ( $grams as $gram ) {
			$h1 = crc32( $gram . self::SALT_H ) & 0xFFFFFFFF; // 指纹高 32 位（规范 h64 高半字）。
			$h2 = crc32( $gram . self::SALT_L ) & 0xFFFFFFFF; // 指纹低 32 位（规范 h64 低半字）。
			for ( $i = 0; $i < 32; $i++ ) {
				$weights[ $i ]     += ( ( $h1 >> ( 31 - $i ) ) & 1 ) ? 1 : -1;
				$weights[ 32 + $i ] += ( ( $h2 >> ( 31 - $i ) ) & 1 ) ? 1 : -1;
			}
		}

		// 阈值化 → 16 位十六进制（高字节在前）。64 位 = 8 字节。
		$hex = '';
		for ( $b = 0; $b < 8; $b++ ) {
			$byte = 0;
			for ( $k = 0; $k < 8; $k++ ) {
				if ( $weights[ $b * 8 + $k ] > 0 ) {
					$byte |= ( 1 << ( 7 - $k ) );
				}
			}
			$hex .= sprintf( '%02x', $byte );
		}

		return $hex;
	}

	/**
	 * 计算两个十六进制指纹的汉明距离。
	 *
	 * @param string $a 指纹 A（16 hex）。
	 * @param string $b 指纹 B（16 hex）。
	 * @return int 不同 bit 数量。
	 */
	public static function hamming( $a, $b ) {
		$a = (string) $a;
		$b = (string) $b;
		if ( 16 !== strlen( $a ) || 16 !== strlen( $b ) ) {
			return PHP_INT_MAX; // 非法输入按"完全不同"处理。
		}

		// 惰性构建 256 项查表。
		if ( null === self::$byte_bit_count ) {
			self::$byte_bit_count = array();
			for ( $i = 0; $i < 256; $i++ ) {
				$c = 0;
				for ( $j = 0; $j < 8; $j++ ) {
					if ( $i & ( 1 << $j ) ) {
						$c++;
					}
				}
				self::$byte_bit_count[ $i ] = $c;
			}
		}

		$bin_a = @hex2bin( $a );
		$bin_b = @hex2bin( $b );
		if ( false === $bin_a || false === $bin_b || strlen( $bin_a ) !== strlen( $bin_b ) ) {
			return PHP_INT_MAX;
		}

		$dist = 0;
		$len  = strlen( $bin_a );
		for ( $i = 0; $i < $len; $i++ ) {
			$dist += self::$byte_bit_count[ ord( $bin_a[ $i ] ) ^ ord( $bin_b[ $i ] ) ];
		}

		return $dist;
	}

	/**
	 * 在指纹表中查找与指定指纹相似（汉明距离 < 阈值）的记录。
	 *
	 * @param string $fhash    十六进制指纹。
	 * @param int    $threshold 判重阈值（默认 4，汉明距离 < 阈值判重）。
	 * @return array 相似记录数组 [{post_id, fhash, post_title, distance}]，无则空数组。
	 */
	public static function find_similar( $fhash, $threshold = self::THRESHOLD ) {
		global $wpdb;

		$fhash      = preg_replace( '/[^0-9a-f]/i', '', (string) $fhash );
		$threshold  = max( 1, (int) $threshold );
		$table_name = $wpdb->prefix . 'abp_fingerprints';

		// 表不存在则先尝试建表（发布/查重时自愈）。
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			self::create_table();
			return array();
		}

		// 全表扫描 + 逐条汉明计算：表体量（本站千篇级）可接受；
		// 若未来文章量大，可改为按 fhash 前缀粗筛（前 4 hex 相同才比对）后做二次过滤。
		$rows = $wpdb->get_results( "SELECT post_id, fhash, post_title FROM {$table_name} WHERE fhash <> ''", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$matches = array();
		foreach ( $rows as $row ) {
			$dist = self::hamming( $fhash, $row['fhash'] );
			if ( $dist < $threshold ) {
				$row['distance'] = $dist;
				$matches[]       = $row;
			}
		}

		// 距离近的排前面。
		usort( $matches, function ( $x, $y ) {
			return $x['distance'] - $y['distance'];
		} );

		return $matches;
	}

	/**
	 * 文章指纹入库（建文成功后调用）。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param string $fhash   指纹。
	 * @param string $title   标题（便于后台排查）。
	 * @return bool
	 */
	public static function save( $post_id, $fhash, $title = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'abp_fingerprints';
		$post_id    = (int) $post_id;
		$fhash      = preg_replace( '/[^0-9a-f]/i', '', (string) $fhash );
		if ( ! $post_id || 16 !== strlen( $fhash ) ) {
			return false;
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			self::create_table();
		}

		// 同文章重复保存时先删旧指纹再插（幂等）。
		$wpdb->delete( $table_name, array( 'post_id' => $post_id ), array( '%d' ) );

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'post_id'    => $post_id,
				'fhash'      => $fhash,
				'post_title' => sanitize_text_field( (string) $title ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		// 双写 wp_postmeta（键 abp_fingerprint），与 docs/05-plugin.md §6.1 S7 比对范围一致。
		update_post_meta( $post_id, 'abp_fingerprint', $fhash );

		return false !== $inserted;
	}

	/**
	 * 全量比对（S7 范围）：指纹表 + wp_postmeta，合并按距离排序。
	 *
	 * @param string $fhash     十六进制指纹。
	 * @param int    $threshold 判重阈值（默认 4）。
	 * @return array 相似记录 [{post_id, fhash, post_title, distance}]。
	 */
	public static function find_similar_all( $fhash, $threshold = self::THRESHOLD ) {
		$all = array_merge(
			self::find_similar( $fhash, $threshold ),
			self::find_similar_in_meta( $fhash, $threshold )
		);
		usort( $all, function ( $x, $y ) {
			return $x['distance'] - $y['distance'];
		} );
		return $all;
	}

	/**
	 * 判断文章是否与站内历史重复（S7：汉明距离 < 4，比对范围 = 指纹表 + postmeta + 存量惰性索引）。
	 *
	 * 数据源（总纲 5.1 站内查重）：
	 *   1. wp_abp_fingerprints 指纹表（本插件建文时写入）；
	 *   2. wp_postmeta 键 abp_fingerprint（发布时双写，兼容文档 05-plugin.md §6.1）；
	 *   3. 若表与 postmeta 均为空（插件安装前已有存量文章），做一次惰性索引：
	 *      取最新 500 篇已发布文章计算指纹入库，之后即走表查询。
	 *      （存量扫描代价高，仅首次触发，注释说明取舍。）
	 *
	 * @param string $text 正文文本。
	 * @return array {duplicate:bool, distance:int, similar_post_id:int, similar_title:string}
	 */
	public static function is_duplicate( $text ) {
		global $wpdb;

		$result = array(
			'duplicate'       => false,
			'distance'        => PHP_INT_MAX,
			'similar_post_id' => 0,
			'similar_title'   => '',
		);

		$fhash = self::simhash( $text );

		// 存量惰性索引：仅当指纹表与 postmeta 均无记录且站内有已发布文章时触发一次。
		$table_name = $wpdb->prefix . 'abp_fingerprints';
		$table_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		$meta_cnt   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'abp_fingerprint'" );
		if ( 0 === $table_cnt && 0 === $meta_cnt ) {
			self::lazy_index_existing_posts( 500 );
		}

		// 表 + postmeta 全量比对（S7 范围），按距离排序取最近。
		$all = self::find_similar_all( $fhash, self::THRESHOLD );
		if ( ! empty( $all ) ) {
			$result['duplicate']       = true;
			$result['distance']        = (int) $all[0]['distance'];
			$result['similar_post_id'] = (int) $all[0]['post_id'];
			$result['similar_title']   = isset( $all[0]['post_title'] ) ? $all[0]['post_title'] : '';
		}

		return $result;
	}

	/**
	 * 在 wp_postmeta（键 abp_fingerprint）中比对相似指纹（S7 比对范围之二）。
	 *
	 * @param string $fhash     十六进制指纹。
	 * @param int    $threshold 判重阈值（默认 4）。
	 * @return array 相似记录 [{post_id, fhash, post_title, distance}]。
	 */
	public static function find_similar_in_meta( $fhash, $threshold = self::THRESHOLD ) {
		global $wpdb;

		$fhash     = preg_replace( '/[^0-9a-f]/i', '', (string) $fhash );
		$threshold = max( 1, (int) $threshold );

		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value AS fhash FROM {$wpdb->postmeta} WHERE meta_key = 'abp_fingerprint' AND meta_value <> ''",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$matches = array();
		foreach ( $rows as $row ) {
			$meta_fhash = preg_replace( '/[^0-9a-f]/i', '', (string) $row['fhash'] );
			if ( 16 !== strlen( $meta_fhash ) ) {
				continue;
			}
			$dist = self::hamming( $fhash, $meta_fhash );
			if ( $dist < $threshold ) {
				$matches[] = array(
					'post_id'    => (int) $row['post_id'],
					'fhash'      => $meta_fhash,
					'post_title' => get_the_title( (int) $row['post_id'] ),
					'distance'   => $dist,
				);
			}
		}

		usort( $matches, function ( $x, $y ) {
			return $x['distance'] - $y['distance'];
		} );

		return $matches;
	}

	/**
	 * 惰性索引存量文章（安装前的老文章，无指纹记录）。
	 *
	 * @param int $limit 最多索引篇数（默认 500，取最新）。
	 * @return int 实际索引篇数。
	 */
	public static function lazy_index_existing_posts( $limit = 500 ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => max( 1, min( (int) $limit, 2000 ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$indexed = 0;
		foreach ( $query->posts as $post_id ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( ! $content ) {
				continue;
			}
			$plain = wp_strip_all_tags( $content );
			if ( self::save( (int) $post_id, self::simhash( $plain ), get_the_title( $post_id ) ) ) {
				$indexed++;
			}
		}

		return $indexed;
	}
}

/**
 * 计算文本 SimHash 指纹。
 *
 * @param string $text 文本。
 * @return string 16 位十六进制。
 */
function abp_simhash( $text ) {
	return ABP_Fingerprint::simhash( $text );
}

/**
 * 汉明距离。
 *
 * @param string $a 指纹 A。
 * @param string $b 指纹 B。
 * @return int
 */
function abp_hamming( $a, $b ) {
	return ABP_Fingerprint::hamming( $a, $b );
}

/**
 * 判重（内部含指纹表 + postmeta + 存量惰性索引）。
 *
 * @param string $text 正文文本。
 * @return array {duplicate, distance, similar_post_id, similar_title}
 */
function abp_is_duplicate( $text ) {
	return ABP_Fingerprint::is_duplicate( $text );
}

/**
 * 判重（别名，兼容 docs/05-plugin.md §6.1 约定的 abp_check_duplicate）。
 *
 * @param string $text 正文文本。
 * @return array {duplicate, distance, similar_post_id, similar_title}
 */
function abp_check_duplicate( $text ) {
	return ABP_Fingerprint::is_duplicate( $text );
}

/**
 * 指纹入库。
 *
 * @param int    $post_id 文章 ID。
 * @param string $fhash   指纹。
 * @param string $title   标题。
 * @return bool
 */
function abp_fingerprint_save( $post_id, $fhash, $title = '' ) {
	return ABP_Fingerprint::save( $post_id, $fhash, $title );
}
