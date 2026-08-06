<?php
/**
 * A-Blog 模型配置探测（总纲 3.4）
 *
 * 探测顺序（硬性规定，不得调整）：
 *   ① 青简主题：get_option('qy_ai_api_key') 非空
 *      → provider=theme, source=qingya, model=get_theme_mod('qy_ai_model','deepseek-chat')
 *   ② 插件自身 abp_settings['deepseek_api_key'] 非空
 *      → provider=self, source=abp_settings
 *   ③ 均未配置 → provider=none（REST /health 返回 no_model_configured，
 *      Python 层据此拦截任务、不消耗 Token）
 *
 * 返回结构（严格对齐 3.4）：
 *   array(
 *     'provider'        => 'theme|plugin|self|none',
 *     'source'          => 来源标识（qingya / abp_settings / ''），
 *     'deepseek_api_key'=> 探测到的 DeepSeek API Key（仅供 Python 侧经 Bearer 接口同步，不入日志），
 *     'models'          => array('stock'=>, 'tech'=>, 'reading'=>, 'image'=>),
 *     'image_api'       => array('provider'=>, 'key'=>, 'endpoint'=>, 'model'=>),
 *   )
 *
 * 模型映射表说明：DeepSeek 单 key 多模型 —— 同一把 key，各栏目可按需传不同
 * model 字段（deepseek-chat / deepseek-coder / deepseek-reasoner，后台可配）；
 * 栏目映射未配置时回退到探测到的默认模型。
 *
 * @package AI_Auto_Blog_Publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABP_Models {

	/** 单例实例。 */
	private static $instance = null;

	/** 探测结果缓存（单次请求内避免重复探测）。 */
	private $cache = null;

	/**
	 * 私有构造（单例）。
	 */
	private function __construct() {}

	/**
	 * 获取单例。
	 *
	 * @return ABP_Models
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 读取插件自身设置（abp_settings option）。
	 *
	 * @return array
	 */
	private function get_settings() {
		$defaults = array(
			'deepseek_api_key' => '',
			'models'           => array(
				'stock'   => 'deepseek-chat',
				'tech'    => 'deepseek-chat',
				'reading' => 'deepseek-chat',
				'image'   => '',
			),
			'image_api'        => array(
				'provider' => '',
				'key'      => '',
				'endpoint' => '',
				'model'    => '',
			),
		);
		$options  = get_option( 'abp_settings', array() );
		$settings = wp_parse_args( is_array( $options ) ? $options : array(), $defaults );

		// 逐层补齐缺失的子数组/子字段，保证调用方取值不报错。
		$settings['models']    = wp_parse_args( isset( $settings['models'] ) && is_array( $settings['models'] ) ? $settings['models'] : array(), $defaults['models'] );
		$settings['image_api'] = wp_parse_args( isset( $settings['image_api'] ) && is_array( $settings['image_api'] ) ? $settings['image_api'] : array(), $defaults['image_api'] );

		return $settings;
	}

	/**
	 * 执行探测，返回 3.4 契约结构。
	 *
	 * @return array
	 */
	public function get() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$settings = $this->get_settings();
		$result   = array(
			'provider'         => 'none',
			'source'           => '',
			'deepseek_api_key' => '',
			'models'           => array(
				'stock'   => 'deepseek-chat',
				'tech'    => 'deepseek-chat',
				'reading' => 'deepseek-chat',
				'image'   => '',
			),
			'image_api'        => $settings['image_api'],
		);

		// ① 青简主题探测。
		$qy_key = get_option( 'qy_ai_api_key', '' );
		if ( is_string( $qy_key ) && '' !== trim( $qy_key ) ) {
			$qy_model = get_theme_mod( 'qy_ai_model', 'deepseek-chat' );
			$result['provider']         = 'theme';
			$result['source']           = 'qingya';
			$result['deepseek_api_key'] = $qy_key;
			// 各栏目默认模型取主题模型；后台映射表（abp_settings.models）若显式配置了栏目模型则优先。
			foreach ( array( 'stock', 'tech', 'reading' ) as $col ) {
				$m = isset( $settings['models'][ $col ] ) ? trim( (string) $settings['models'][ $col ] ) : '';
				$result['models'][ $col ] = '' !== $m ? $m : $qy_model;
			}
			$result['models']['image'] = isset( $settings['models']['image'] ) ? trim( (string) $settings['models']['image'] ) : '';
			$this->cache               = $result;
			return $result;
		}

		// ③ 插件自身配置。
		$self_key = isset( $settings['deepseek_api_key'] ) ? trim( (string) $settings['deepseek_api_key'] ) : '';
		if ( '' !== $self_key ) {
			$result['provider']         = 'self';
			$result['source']           = 'abp_settings';
			$result['deepseek_api_key'] = $self_key;
			foreach ( array( 'stock', 'tech', 'reading' ) as $col ) {
				$m = isset( $settings['models'][ $col ] ) ? trim( (string) $settings['models'][ $col ] ) : '';
				$result['models'][ $col ] = '' !== $m ? $m : 'deepseek-chat';
			}
			$result['models']['image'] = isset( $settings['models']['image'] ) ? trim( (string) $settings['models']['image'] ) : '';
			$this->cache               = $result;
			return $result;
		}

		// ④ 均未配置。
		$this->cache = $result;
		return $result;
	}

	/**
	 * 密钥打码（后台展示用，绝不原样回显）。
	 *
	 * @param string $key 原始 Key。
	 * @return string 打码后字符串。
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', min( 12, $len - 8 ) ) . substr( $key, -4 );
	}

	/**
	 * 获取健康摘要（供 /health 与后台展示；不含完整密钥）。
	 *
	 * @return array
	 */
	public function summary() {
		$m = $this->get();

		return array(
			'provider' => $m['provider'],
			'source'   => $m['source'],
			'key'      => self::mask_key( $m['deepseek_api_key'] ),
			'has_key'  => '' !== trim( (string) $m['deepseek_api_key'] ),
			'models'   => $m['models'],
			'image_api' => array(
				'provider' => isset( $m['image_api']['provider'] ) ? $m['image_api']['provider'] : '',
				'model'    => isset( $m['image_api']['model'] ) ? $m['image_api']['model'] : '',
			),
		);
	}
}

/**
 * 全局函数：获取模型探测结果（总纲 3.4 结构）。
 *
 * @return array
 */
function abp_get_models() {
	return ABP_Models::instance()->get();
}

/**
 * 全局函数：获取模型探测摘要（打码）。
 *
 * @return array
 */
function abp_get_models_summary() {
	return ABP_Models::instance()->summary();
}
