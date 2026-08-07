<?php
/**
 * class-abp-materials.php — 插件内置素材库（智能填充数据源，v1.5.2）。
 *
 * 插件为自足功能插件：智能填充不再依赖外部服务，直接从内置素材随机取题入池。
 * 素材来源：服务端采集器的 builtin 兜底清单（唐诗宋词/IT 问题池）+ 经典书单 + 行业概念。
 * 服务端采集器联网后补充更丰富的素材；本库保证插件在任何环境开箱可用。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Materials {

	/**
	 * 按栏目取内置素材（随机抽取 n 条）。
	 *
	 * @param string $column 栏目码（stock/tech/reading/book/industry）。
	 * @param int    $n      数量。
	 * @return string[] 选题文本列表（stock 恒为空：复盘标题=日期+固定格式，不设备选题）。
	 */
	public static function pick( $column, $n = 1 ) {
		$n = max( 1, min( (int) $n, 10 ) );
		switch ( $column ) {
			case 'tech':
				// 动态选题：RSS 抓取（高频问题/建站/开源工具） + 内置问题池 混合。
				return self::sample( self::tech_candidates(), $n );
			case 'reading':
				// 动态选题：唐诗三百首（优先）/宋词三百首/古文观止/节气话题 混合
				$out = array();
				$candidates = self::reading_candidates( $n + 2 );
				foreach ( array_slice( $candidates, 0, $n ) as $c ) {
					$out[] = $c['topic'];
				}
				return $out;
			case 'book':
				// 动态选题：站点书目（优先）→ 联网热门书 → 内置书单兜底；已写书目查重过滤
				return array_map(
					function ( $b ) {
						return '读《' . $b . '》：核心书评与阅读感悟';
					},
					self::book_candidates( $n )
				);
			case 'industry':
				// 动态选题：Tavily 联网搜索热门行业/概念（有 key 时），否则内置行业概念兑底。
				return array_map(
					function ( $i ) {
						return $i . '行业：市场前景与景气龙头盘点';
					},
					self::sample( self::industry_candidates(), $n )
				);
			default:
				return array(); // stock 不生成备选题
		}
	}

	/**
	 * IT 选题候选：RSS 抓取（设置 rss_urls，每行一个）+ 内置高频问题池。
	 *
	 * @return string[]
	 */
	public static function tech_candidates() {
		$pool = self::tech_pool();
		foreach ( self::rss_urls() as $url ) {
			foreach ( self::fetch_rss( $url ) as $title ) {
				$pool[] = $title;
			}
		}
		return array_values( array_unique( $pool ) );
	}

	/**
	 * 读取 RSS 源配置（设置项，每行一个 URL）。
	 *
	 * @return string[]
	 */
	private static function rss_urls() {
		$s = get_option( 'abp_settings', array() );
		$s = is_array( $s ) ? $s : array();
		$urls = isset( $s['rss_urls'] ) && is_array( $s['rss_urls'] ) ? $s['rss_urls'] : array();
		return array_values( array_filter( $urls, 'is_string' ) );
	}

	/**
	 * 抓取 RSS/Atom 条目标题（失败回落空数组，绝不抛异常）。
	 *
	 * @param string $url RSS 地址。
	 * @return string[]
	 */
	private static function fetch_rss( $url ) {
		$resp = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (compatible; A-Blog/1.5.x)' ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$xml = wp_remote_retrieve_body( $resp );
		$titles = array();
		libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml );
		if ( false !== $doc ) {
			// 兼容 RSS2 与 Atom（命名空间无关，local-name 匹配）。
			$items = $doc->xpath( '//*[local-name()="item"] | //*[local-name()="entry"]' );
			foreach ( (array) $items as $it ) {
				$tNodes = $it->xpath( './*[local-name()="title"]' );
				$t = $tNodes ? trim( (string) $tNodes[0] ) : '';
				if ( '' !== $t ) {
					$titles[] = $t;
				}
			}
		}
		if ( ! $titles && preg_match_all( '/<title[^>]*>([^<]{4,120})<\/title>/u', $xml, $m ) ) {
			foreach ( $m[1] as $t ) {
				$titles[] = trim( html_entity_decode( $t, ENT_QUOTES, 'UTF-8' ) );
			}
		}
		return array_slice( $titles, 0, 20 );
	}

	/**
	 * Tavily 联网搜索（行业综述用）。
	 *
	 * @param string $query 查询词。
	 * @param int    $max   结果数（1-10）。
	 * @return array[] 每项 {title, content, url}；失败返回空数组。
	 */
	public static function tavily_search( $query, $max = 5 ) {
		$s = get_option( 'abp_settings', array() );
		$s = is_array( $s ) ? $s : array();
		$key = isset( $s['tavily_api_key'] ) ? trim( (string) $s['tavily_api_key'] ) : '';
		if ( '' === $key ) {
			return array();
		}
		$resp = wp_remote_post(
			'https://api.tavily.com/search',
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'api_key'     => $key,
					'query'       => $query,
					'max_results' => max( 1, min( (int) $max, 10 ) ),
					'topic'       => 'finance',
					'time_range'  => 'week',
					'search_depth'=> 'basic',
				) ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$out = array();
		foreach ( (array) ( isset( $data['results'] ) ? $data['results'] : array() ) as $r ) {
			$out[] = array(
				'title'   => isset( $r['title'] ) ? (string) $r['title'] : '',
				'content' => isset( $r['content'] ) ? (string) $r['content'] : '',
				'url'     => isset( $r['url'] ) ? (string) $r['url'] : '',
			);
		}
		return $out;
	}

	/**
	 * 行业综述选题候选：Tavily 搜热门行业/概念（有 key）；无 key/失败回落内置行业名单。
	 *
	 * @return string[]
	 */
	public static function industry_candidates() {
		$hits = self::tavily_search( 'A股最近一周热门行业 概念 板块 景气', 6 );
		$names = array();
		foreach ( $hits as $h ) {
			if ( preg_match_all( '/[\x{4e00}-\x{9fa5}]{2,8}(?:行业|概念|板块|产业链|赛道)/u', $h['title'] . ' ' . mb_substr( $h['content'], 0, 200 ), $m ) ) {
				foreach ( $m[0] as $w ) {
					$names[] = trim( $w );
				}
			}
		}
		$names = array_values( array_unique( $names ) );
		if ( $names ) {
			return array_slice( $names, 0, 10 );
		}
		return self::industries();
	}

	/**
	 * 国学选题候选（唐诗 50% / 宋词 30% / 古文 15% / 节气话题 5%）。
	 *
	 * @param int $n 数量。
	 * @return array[] 每项 {topic, title, author}。
	 */
	public static function reading_candidates( $n = 3 ) {
		$out = array();
		for ( $i = 0; $i < $n * 3 && count( $out ) < $n; $i++ ) {
			$r = mt_rand( 1, 100 );
			if ( $r <= 50 ) {
				$p = self::sample( self::poems( 'tang' ), 1 );
			} elseif ( $r <= 80 ) {
				$p = self::sample( self::poems( 'song' ), 1 );
			} elseif ( $r <= 95 ) {
				$p = self::sample( self::guwen(), 1 );
			} else {
				$term = self::solar_term();
				if ( $term ) {
					$out[] = array(
						'topic'  => $term . '：传统节气文化与习俗赏析',
						'title'  => $term,
						'author' => '',
					);
				}
				continue;
			}
			if ( ! empty( $p[0]['title'] ) ) {
				$out[] = array(
					'topic'  => '读《' . $p[0]['title'] . '》' . ( ! empty( $p[0]['author'] ) ? '（' . $p[0]['author'] . '）' : '' ) . '：原文赏析',
					'title'  => $p[0]['title'],
					'author' => isset( $p[0]['author'] ) ? $p[0]['author'] : '',
				);
			}
		}
		return array_slice( $out, 0, $n );
	}

	/**
	 * 书评选题候选：站点书目（book_catalog_url 可配）→ 内置热门书单，已写书目查重过滤。
	 *
	 * @param int $n 数量。
	 * @return string[]
	 */
	public static function book_candidates( $n = 1 ) {
		$written = self::written_books();
		$pool = array();
		$catalog = self::site_catalog_books();
		if ( $catalog ) {
			$pool = $catalog;
		}
		if ( count( $pool ) < $n ) {
			$pool = array_merge( $pool, self::books() );
		}
		$pool = array_values( array_unique( $pool ) );
		$fresh = array();
		foreach ( $pool as $b ) {
			if ( in_array( $b, $written, true ) ) {
				continue; // 本站已写过的书，查重过滤
			}
			$fresh[] = $b;
		}
		if ( ! $fresh ) {
			$fresh = $pool;
		}
		return self::sample( $fresh, $n );
	}

	/**
	 * 本站已写书评的书名列表（post meta abp_is_book_review + 标题去《》）。
	 *
	 * @return string[]
	 */
	private static function written_books() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='abp_is_book_review' AND meta_value='1' LIMIT 500"
		);
		$written = array();
		foreach ( $ids as $pid ) {
			$title = get_the_title( (int) $pid );
			if ( ! $title ) {
				continue;
			}
			$title = trim( str_replace( array( '《', '》' ), '', $title ) );
			// 去掉「读《XX》：书评」前缀后缀
			if ( preg_match( '/读《([^》]+)》/', $title, $m ) ) {
				$title = $m[1];
			}
			$written[] = $title;
		}
		return $written;
	}

	/**
	 * 站点图书目录抓取（设置 book_catalog_url；空则尝试常见路径）。
	 * 返回书名列表；失败返回空数组（走内置书单兜底）。
	 *
	 * @return string[]
	 */
	private static function site_catalog_books() {
		$s = get_option( 'abp_settings', array() );
		$s = is_array( $s ) ? $s : array();
		$url = isset( $s['book_catalog_url'] ) ? untrailingslashit( esc_url_raw( (string) $s['book_catalog_url'] ) ) : '';
		if ( '' === $url ) {
			$candidates = array(
				trailingslashit( home_url() ) . urlencode( '藏书馆书目【电子书】' ),
				trailingslashit( home_url() ) . 'books/',
				trailingslashit( home_url() ) . 'cangshuge/',
			);
			foreach ( $candidates as $c ) {
				$books = self::fetch_catalog( $c );
				if ( $books ) {
					return $books;
				}
			}
			return array();
		}
		return self::fetch_catalog( $url );
	}

	/**
	 * 抓取并解析书目页书名：优先《书名》（高置信），再补充链接文本（过滤导航/文章噪音）。
	 *
	 * @param string $url 书目页地址。
	 * @return string[]
	 */
	private static function fetch_catalog( $url ) {
		$resp = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$html = wp_remote_retrieve_body( $resp );
		if ( '' === $html ) {
			return array();
		}
		$books = array();
		// ① 优先：《书名》（高置信）。
		if ( preg_match_all( '/《([^》]{2,30})》/u', $html, $m ) ) {
			foreach ( $m[1] as $t ) {
				$books[] = trim( $t );
			}
		}
		// ② 补充：链接文本中符合书目特征的条目（排除导航词与文章标题）。
		if ( preg_match_all( '/<a[^>]+href=["\'][^"\']*["\'][^>]*>([^<]{2,40})<\/a>/u', $html, $m2 ) ) {
			foreach ( $m2[1] as $text ) {
				$text = trim( strip_tags( $text ) );
				if ( '' === $text ) {
					continue;
				}
				// 导航/杂项词过滤。
				if ( preg_match( '/^(首页|分类|标签|关于|联系|搜索|登录|注册|上一页|下一页|阅读全文|更多|最新|热门|跳到内容|问候海报|藏书阁|中文诗词|书目|留言簿|隐私政策)/u', $text ) ) {
					continue;
				}
				// 文章标题特征过滤（日期开头 / 含冒号 / 市场类字样）。
				if ( preg_match( '/^(\d{4}|\d{4}年|\d{4}-\d{2}-\d{2})/u', $text ) || preg_match( '/[：:]/u', $text ) || preg_match( '/(市场|复盘|公告|消息|观点)$/u', $text ) ) {
					continue;
				}
				$books[] = $text;
			}
		}
		return array_slice( array_values( array_unique( $books ) ), 0, 60 );
	}

	/**
	 * 农历/公历节气近似：返回最近（或当日）节气名。公历近似每节气 15 天，立春 2/4 起。
	 *
	 * @return string 节气名（非节气季节空串）。
	 */
	public static function solar_term() {
		$day = (int) gmdate( 'z', current_time( 'timestamp' ) ); // 年内第几天（0 起）
		$names = array( '立春', '雨水', '惊蛰', '春分', '清明', '谷雨', '立夏', '小满', '芒种', '夏至', '小暑', '大暑', '立秋', '处暑', '白露', '秋分', '寒露', '霜降', '立冬', '小雪', '大雪', '冬至', '小寒', '大寒' );
		// 每节气近似间隔 15.22 天，立春 ≈ 第 34 天（2/4）
		$idx = (int) floor( ( $day - 34 + 365 ) / 15.22 ) % 24;
		$base = (int) ( 34 + $idx * 15.22 );
		// 仅在节气前后 3 天内输出，否则空串（避免天天都是节气话题）
		if ( abs( $day - $base ) <= 3 ) {
			return $names[ $idx ];
		}
		return '';
	}

	/**
	 * 随机抽样（不重复）。
	 *
	 * @param array $pool 素材数组。
	 * @param int   $n    数量。
	 * @return array
	 */
	private static function sample( $pool, $n ) {
		if ( empty( $pool ) ) {
			return array();
		}
		$keys = array_rand( $pool, min( $n, count( $pool ) ) );
		$keys = (array) $keys;
		$out  = array();
		foreach ( $keys as $k ) {
			$out[] = $pool[ $k ];
		}
		return $out;
	}

	/**
	 * 诗词语料：即时联网获取（chinese-poetry 公版库），WP option 缓存，每日更新；
	 * 联网失败回落最小内置清单。不打包大语料（插件体积与固化问题）。
	 *
	 * @param string $set 'tang'|'song'。
	 * @return array[] 每项 {title, author, paragraphs}。
	 */
	public static function poems( $set = '' ) {
		$data = self::cached_poems();
		if ( 'tang' === $set ) {
			return isset( $data['tang'] ) ? $data['tang'] : array();
		}
		if ( 'song' === $set ) {
			return isset( $data['song'] ) ? $data['song'] : array();
		}
		$pool = array();
		if ( ! empty( $data['tang'] ) ) {
			$pool = array_merge( $pool, $data['tang'] );
		}
		if ( ! empty( $data['song'] ) ) {
			$pool = array_merge( $pool, $data['song'] );
		}
		return $pool;
	}

	/**
	 * 读缓存语料；过期/缺失则尝试联网刷新。
	 *
	 * @return array{tang:array, song:array}
	 */
	private static function cached_poems() {
		$opt = get_option( 'abp_material_poems', array() );
		if ( is_array( $opt ) && ! empty( $opt['tang'] ) ) {
			return $opt;
		}
		self::refresh_poems();
		$opt2 = get_option( 'abp_material_poems', array() );
		if ( is_array( $opt2 ) && ! empty( $opt2['tang'] ) ) {
			return $opt2;
		}
		return array( 'tang' => self::poems_fallback(), 'song' => array() );
	}

	/**
	 * 联网刷新语料（chinese-poetry GitHub 公版库），存 WP option（autoload=no）。
	 * 每日调度调用；失败保留旧缓存。
	 *
	 * @return array{ok:bool, tang:int, song:int, error?:string}
	 */
	public static function refresh_poems() {
		$sources = array(
			'tang' => 'https://raw.githubusercontent.com/chinese-poetry/chinese-poetry/master/全唐诗/唐诗三百首.json',
			'song' => 'https://raw.githubusercontent.com/chinese-poetry/chinese-poetry/master/宋词/宋词三百首.json',
		);
		$data = array( 'tang' => array(), 'song' => array(), 'updated' => current_time( 'mysql' ) );
		$last_err = '';
		foreach ( $sources as $key => $url ) {
			$resp = wp_remote_get( $url, array( 'timeout' => 30, 'sslverify' => false, 'user-agent' => 'A-Blog/1.5.x' ) );
			if ( is_wp_error( $resp ) ) {
				$last_err = $resp->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				$last_err = 'HTTP ' . $code;
				continue;
			}
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $json ) || ! $json ) {
				$last_err = 'JSON 解析失败';
				continue;
			}
			$clean = array();
			foreach ( $json as $p ) {
				if ( ! is_array( $p ) || empty( $p['author'] ) ) {
					continue;
				}
				$title = isset( $p['title'] ) ? $p['title'] : '';
				if ( '' === $title && 'song' === $key ) {
					$rhythmic = isset( $p['rhythmic'] ) ? $p['rhythmic'] : '';
					$first = isset( $p['paragraphs'][0] ) ? $p['paragraphs'][0] : '';
					$title = ( $rhythmic ? $rhythmic . '·' : '' ) . str_replace( array( '。', '，' ), '', mb_substr( (string) $first, 0, 6 ) );
				}
				$clean[] = array(
					'title'      => $title,
					'author'     => $p['author'],
					'paragraphs' => isset( $p['paragraphs'] ) ? (array) $p['paragraphs'] : array(),
				);
			}
			if ( $clean ) {
				$data[ $key ] = $clean;
			}
		}
		// 至少拿到一个源才算成功；否则保留旧值。
		if ( $data['tang'] || $data['song'] ) {
			update_option( 'abp_material_poems', $data, false );
		}
		return array(
			'ok'    => (bool) ( $data['tang'] || $data['song'] ),
			'tang'  => count( $data['tang'] ),
			'song'  => count( $data['song'] ),
			'error' => $last_err ? $last_err : '',
		);
	}

	/**
	 * 古文观止精选（公版名篇，内置 20 篇；后续可扩展全集）。
	 *
	 * @return array[] 每项 {title, author}。
	 */
	public static function guwen() {
		return array(
			array( 'title' => '岳阳楼记', 'author' => '范仲淹' ),
			array( 'title' => '滕王阁序', 'author' => '王勃' ),
			array( 'title' => '师说', 'author' => '韩愈' ),
			array( 'title' => '桃花源记', 'author' => '陶渊明' ),
			array( 'title' => '兰亭集序', 'author' => '王羲之' ),
			array( 'title' => '前赤壁赋', 'author' => '苏轼' ),
			array( 'title' => '出师表', 'author' => '诸葛亮' ),
			array( 'title' => '陈情表', 'author' => '李密' ),
			array( 'title' => '醉翁亭记', 'author' => '欧阳修' ),
			array( 'title' => '陋室铭', 'author' => '刘禹锡' ),
			array( 'title' => '爱莲说', 'author' => '周敦颐' ),
			array( 'title' => '马说', 'author' => '韩愈' ),
			array( 'title' => '阿房宫赋', 'author' => '杜牧' ),
			array( 'title' => '归去来兮辞', 'author' => '陶渊明' ),
			array( 'title' => '谏太宗十思疏', 'author' => '魏征' ),
			array( 'title' => '捕蛇者说', 'author' => '柳宗元' ),
			array( 'title' => '小石潭记', 'author' => '柳宗元' ),
			array( 'title' => '三峡', 'author' => '郦道元' ),
			array( 'title' => '与朱元思书', 'author' => '吴均' ),
			array( 'title' => '卖油翁', 'author' => '欧阳修' ),
		);
	}

	/* ---- 以下为旧版精简清单（诗词/IT 问题/书单/行业），保留作兜底 ---- */

	/**
	 * 诗词精简清单（兜底，数据文件缺失时使用）。
	 *
	 * @return array[] 每项 {title, author}。
	 */
	public static function poems_fallback() {
		return array(
			array( 'title' => '静夜思', 'author' => '李白' ),
			array( 'title' => '登鹳雀楼', 'author' => '王之涣' ),
			array( 'title' => '春晓', 'author' => '孟浩然' ),
			array( 'title' => '悯农', 'author' => '李绅' ),
			array( 'title' => '江雪', 'author' => '柳宗元' ),
			array( 'title' => '水调歌头·明月几时有', 'author' => '苏轼' ),
			array( 'title' => '关雎', 'author' => '诗经' ),
			array( 'title' => '学而·其一', 'author' => '论语' ),
			array( 'title' => '望庐山瀑布', 'author' => '李白' ),
			array( 'title' => '黄鹤楼送孟浩然之广陵', 'author' => '李白' ),
			array( 'title' => '枫桥夜泊', 'author' => '张继' ),
			array( 'title' => '出塞', 'author' => '王昌龄' ),
			array( 'title' => '凉州词', 'author' => '王之涣' ),
			array( 'title' => '九月九日忆山东兄弟', 'author' => '王维' ),
			array( 'title' => '相思', 'author' => '王维' ),
			array( 'title' => '乌衣巷', 'author' => '刘禹锡' ),
			array( 'title' => '声声慢·寻寻觅觅', 'author' => '李清照' ),
			array( 'title' => '江城子·乙卯正月二十日夜记梦', 'author' => '苏轼' ),
			array( 'title' => '虞美人·春花秋月何时了', 'author' => '李煜' ),
			array( 'title' => '陋室铭', 'author' => '刘禹锡' ),
			array( 'title' => '爱莲说', 'author' => '周敦颐' ),
			array( 'title' => '岳阳楼记', 'author' => '范仲淹' ),
			array( 'title' => '醉翁亭记', 'author' => '欧阳修' ),
			array( 'title' => '劝学', 'author' => '荀子' ),
		);
	}

	/**
	 * IT 技术问题池（WordPress/Nginx/Linux/建站实操场景）。
	 *
	 * @return string[]
	 */
	public static function tech_pool() {
		return array(
			'WordPress 网站打开慢的十大原因与提速优化方法',
			'WordPress 后台登录页面打不开或一直加载中如何解决',
			'WordPress 修改固定链接后文章 404 的排查与修复',
			'WordPress 主题更新后样式错乱怎么办',
			'WordPress 自动更新失败的原因与手动更新步骤',
			'WordPress 数据库连接错误（Error establishing a database connection）排查',
			'WordPress 文章图片不显示或防盗链失效怎么处理',
			'WordPress 被攻击挂马后的清理与加固流程',
			'WordPress 评论垃圾信息太多，如何配置反垃圾插件',
			'WordPress 多站点（Multisite）配置注意事项',
			'WP Super Cache 与 W3 Total Cache 怎么选',
			'WordPress 网站被 502 Bad Gateway 缠住的原因与解决',
			'WordPress 定时发布文章不生效的排查思路',
			'WordPress 备份与恢复：数据库+文件完整教程',
			'Nginx 配置反向代理后 502/504 报错排查',
			'Nginx location 匹配优先级详解与常见坑',
			'Nginx 开启 gzip 压缩后网页仍慢的原因',
			'Nginx 配置 HTTPS 证书后 http 自动跳转 https',
			'Nginx 限制单个 IP 并发连接与请求速率（防 CC）',
			'Nginx 日志格式自定义与访问日志分析',
			'Nginx 静态资源缓存配置（expires/cache-control）',
			'Nginx 负载均衡 upstream 配置与健康检查',
			'Nginx 上传文件大小限制 client_max_body_size 详解',
			'服务器 SSH 登录慢的排查与优化',
			'Linux 服务器磁盘空间不足的清理思路（du/find 实战）',
			'服务器 CPU 占用 100% 的定位与处理（top/ps）',
			'Linux 定时任务 crontab 不执行的原因排查',
			'iptables 与 firewalld 防火墙规则配置入门',
			'服务器被暴力破解 SSH 的防护（fail2ban）',
			'Linux 查看端口占用与进程（netstat/lsof/ss）',
			'服务器内存不足导致 OOM 的排查与优化',
			'系统日志 /var/log 排查思路：journalctl 与 dmesg',
			'宝塔面板常见故障：MySQL 启动失败的排查',
			'Hugo 静态博客部署到 Nginx 的完整流程',
			'Git 常用命令速查与误操作恢复（reflog）',
			'Docker 容器日志占用磁盘过大如何清理',
			'Docker Compose 部署 WordPress+MySQL 实战',
			'Markdown 写作与发布工作流：从本地到线上',
			'Cloudflare CDN 加速网站并隐藏真实 IP 的配置',
			'网站被收录慢？robots.txt 与 sitemap 正确写法',
		);
	}

	/**
	 * 经典书单（书评栏目素材）。
	 *
	 * @return string[]
	 */
	public static function books() {
		return array(
			'活着', '百年孤独', '红楼梦', '三国演义', '西游记', '水浒传', '平凡的世界',
			'围城', '边城', '骆驼祥子', '呐喊', '朝花夕拾', '人间词话', '传习录',
			'道德经', '论语', '庄子', '孙子兵法', '史记', '资治通鉴',
			'思考，快与慢', '人类简史', '未来简史', '穷查理宝典', '原则', '置身事内',
			'三体', '银河帝国', '小王子', '老人与海', '月亮与六便士', '瓦尔登湖',
		);
	}

	/**
	 * 行业/概念名单（行业综述栏目素材）。
	 *
	 * @return string[]
	 */
	public static function industries() {
		return array(
			'人工智能', '算力', '半导体', '低空经济', '人形机器人', '数据要素',
			'新能源车', '光伏', '储能', '创新药', '军工', '量子科技', '脑机接口',
			'卫星互联网', '消费电子', '智能驾驶', '工业母机', '液冷服务器',
			'AI 眼镜', '固态电池', '核电', '氢能', '跨境电商', '银发经济',
		);
	}
}
