<?php
/**
 * class-abp-avatar.php — AI 评论作者本地 SVG 头像（零成本、确定性生成）
 *
 * 原理：按评论作者昵称确定性生成 100×100 SVG 头像（双色渐变背景 + 首字母），
 *       保存到 wp-content/uploads/avatars/abp-<md5>.svg（约 0.5-1KB），
 *       通过 pre_get_avatar_data 过滤器替换默认 Gravatar。
 * 特点：
 *   - 同一昵称 → 永远同一头像（确定性，无需存储映射表）
 *   - 不依赖任何外部头像服务 / API / 额度
 *   - 原子写入（tmp + rename），并发安全；IIS 下自动补 .svg MIME 配置
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Avatar {

	const DIR_NAME    = 'avatars';
	const FILE_PREFIX = 'abp-';

	/**
	 * 注册头像过滤器（后台 init 调用一次）。
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_avatar_data' ), 99, 2 );
	}

	/**
	 * 为评论作者生成（已存在则复用）SVG 头像，返回本地 URL。
	 *
	 * @param string $name  评论作者昵称。
	 * @param string $email 评论作者邮箱（备选 seed，本插件评论通常为空）。
	 * @return string 头像 URL；不可写/失败返回空串（不阻断评论发布）。
	 */
	public static function ensure_avatar( $name, $email = '' ) {
		$key     = md5( strtolower( trim( (string) $name ) ) . '|' . strtolower( trim( (string) $email ) ) );
		$uploads = wp_upload_dir();
		if ( is_array( $uploads ) && ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir  = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
		$file = trailingslashit( $dir ) . self::FILE_PREFIX . $key . '.svg';
		if ( ! file_exists( $file ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			$svg = self::generate_svg( $name, $email );
			if ( '' === $svg ) {
				return '';
			}
			// 原子写入：临时文件 + rename，避免并发读到半截文件。
			$tmp = $file . '.tmp';
			if ( false === @file_put_contents( $tmp, $svg ) ) { // phpcs:ignore
				return '';
			}
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore
				@unlink( $tmp ); // phpcs:ignore
			}
		}
		return trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME . '/' . self::FILE_PREFIX . $key . '.svg';
	}

	/**
	 * 确定性生成 SVG 头像：昵称哈希 → 双色渐变背景 + 首字母。
	 *
	 * @param string $name  昵称。
	 * @param string $email 邮箱（昵称为空时的 seed）。
	 * @return string SVG 内容。
	 */
	public static function generate_svg( $name, $email = '' ) {
		$name = trim( (string) $name );
		$seed = md5( strtolower( $name ? $name : (string) $email ) );
		$hue1 = ( hexdec( substr( $seed, 0, 2 ) ) * 360 ) / 255;
		$hue2 = ( (int) $hue1 + 40 ) % 360;
		$c1   = self::hsl_to_hex( $hue1, 55, 52 );
		$c2   = self::hsl_to_hex( $hue2, 55, 42 );
		$initial = $name ? mb_substr( $name, 0, 1 ) : '?';
		$initial = htmlspecialchars( $initial, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		return '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
			. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
			. '<stop offset="0" stop-color="' . $c1 . '"/>'
			. '<stop offset="1" stop-color="' . $c2 . '"/>'
			. '</linearGradient></defs>'
			. '<rect width="100" height="100" rx="18" fill="url(#g)"/>'
			. '<circle cx="82" cy="18" r="24" fill="rgba(255,255,255,0.12)"/>'
			. '<circle cx="14" cy="86" r="18" fill="rgba(255,255,255,0.10)"/>'
			. '<text x="50" y="50" dy="0.36em" font-family="&quot;PingFang SC&quot;,&quot;Microsoft YaHei&quot;,Arial,sans-serif" font-size="44" font-weight="600" fill="#ffffff" text-anchor="middle">' . $initial . '</text>'
			. '</svg>';
	}

	/**
	 * 头像过滤器：本插件头像优先于默认 Gravatar。
	 *
	 * @param array $args       头像参数（url/found_avatar 等）。
	 * @param mixed $id_or_email 评论对象 / 用户 / 邮箱。
	 * @return array
	 */
	public static function filter_avatar_data( $args, $id_or_email ) {
		$url = '';

		if ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) ) {
			// 评论对象：优先读 meta（新评论），旧评论（空邮箱）按昵称确定性生成。
			$meta = get_comment_meta( $id_or_email->comment_ID, '_abp_avatar', true );
			if ( $meta ) {
				$url = (string) $meta;
			} elseif ( '' === (string) $id_or_email->comment_author_email ) {
				$url = self::ensure_avatar( (string) $id_or_email->comment_author );
			}
		}

		if ( '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}
		return $args;
	}

	/**
	 * HSL → HEX（SVG 颜色用）。
	 *
	 * @param float $h 色相 0-360。
	 * @param float $s 饱和度 0-100。
	 * @param float $l 亮度 0-100。
	 * @return string #rrggbb
	 */
	private static function hsl_to_hex( $h, $s, $l ) {
		$s /= 100;
		$l /= 100;
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;
		if ( $h < 60 ) {
			list( $r, $g, $b ) = array( $c, $x, 0 );
		} elseif ( $h < 120 ) {
			list( $r, $g, $b ) = array( $x, $c, 0 );
		} elseif ( $h < 180 ) {
			list( $r, $g, $b ) = array( 0, $c, $x );
		} elseif ( $h < 240 ) {
			list( $r, $g, $b ) = array( 0, $x, $c );
		} elseif ( $h < 300 ) {
			list( $r, $g, $b ) = array( $x, 0, $c );
		} else {
			list( $r, $g, $b ) = array( $c, 0, $x );
		}
		$to_hex = function ( $v ) use ( $m ) {
			return str_pad( dechex( (int) round( ( $v + $m ) * 255 ) ), 2, '0', STR_PAD_LEFT );
		};
		return '#' . $to_hex( $r ) . $to_hex( $g ) . $to_hex( $b );
	}

	/**
	 * 删除某个头像文件（备用，未接入 UI）。
	 *
	 * @param string $name  昵称。
	 * @param string $email 邮箱。
	 * @return void
	 */
	public static function delete_avatar( $name, $email = '' ) {
		$key     = md5( strtolower( trim( (string) $name ) ) . '|' . strtolower( trim( (string) $email ) ) );
		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME . '/' . self::FILE_PREFIX . $key . '.svg';
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
}
