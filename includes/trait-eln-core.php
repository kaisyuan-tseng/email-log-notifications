<?php
/**
 * ELN_Core_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Core_Trait {


	/** 统一成正斜杠：Windows 反斜杠路径过 update_post_meta 的 wp_unslash 会损坏（\0→NUL） */
	private static function norm( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	/** 上传目录信息（附件放在 uploads 下，浏览器可直开） */
	private static function uploads_dir(): array {
		static $u = null;
		if ( $u === null ) {
			$dir = wp_get_upload_dir();
			$u   = array(
				'basedir' => self::norm( $dir['basedir'] ),
				'baseurl' => rtrim( self::norm( $dir['baseurl'] ), '/' ),
				'webpath' => rtrim( (string) parse_url( $dir['baseurl'], PHP_URL_PATH ), '/' ),
			);
		}
		return $u;
	}

	/** 附件库根目录（规范化绝对路径） */
	private static function storage_base(): string {
		return self::uploads_dir()['basedir'] . '/' . self::STORAGE_DIR . '/';
	}

	/** 附件库相对 Web 路径前缀（相对站点根，主站 80 与 WP 8081 两个域都可用） */
	private static function storage_web_base(): string {
		return self::uploads_dir()['webpath'] . '/' . self::STORAGE_DIR . '/';
	}

	/** 每个附件分组的白名单：扩展名 + 单文件大小上限（字节） */
	private const FILE_GROUPS = array(
		'drawings' => array(
			'max_size' => 20971520,
			'ext'      => array( 'pdf', 'step', 'stp', 'dwg', 'dxf', 'igs', 'sldprt', 'sldasm', 'zip', 'rar', '7z' ),
		),
		'pictures' => array(
			'max_size' => 10485760,
			'ext'      => array( 'png', 'jpg', 'jpeg', 'webp' ),
		),
	);

	/**
	 * 双窗口提交频率限制（同一可信请求标识）：
	 * 1 分钟最多 3 次、1 小时最多 10 次。两个窗口都是固定窗口，按首次提交起算，
	 * 到点自动清零；超限时返回 429 + Retry-After，期间不再放行。
	 */
	const RATE_MINUTE_LIMIT = 3;
	const RATE_HOUR_LIMIT   = 10;
	const RATE_MINUTE_TTL   = 60;
	const RATE_HOUR_TTL     = 3600;
	/** 访客标识 cookie：服务端签发、HttpOnly，脚本读不到 */
	const RATE_VISITOR_COOKIE = 'eln_vid';

	/** 进页脚元信息、不进正文字段表的键（环境数据与来源信息都在这里） */
	private const META_FIELDS = array(
		'formVariant',
		'pageUrl',
		'pageTitle',
		'pageTitleEn',
		'locale',
		'browserLanguage',
		'timezone',
		'fieldLabels',
		'ip',
	);

	/**
	 * 预览/测试发信用的示例值（只是「值」：字段清单永远来自前端提交的历史，
	 * 这里没有的字段回退 'Sample'，不会凭空列出不存在的字段）。
	 */
	private const SAMPLE_FIELD_VALUES = array(
		'fullName'            => 'Jane Doe',
		'email'               => 'jane.doe@example.com',
		'message'             => "Hello,\nCould you quote 500 pcs of the 6mm gooseneck?\nThanks.",
		'application'         => 'Medical',
		'quantity'            => '500',
		'outerDiameter'       => '6',
		'innerDiameter'       => '4.5',
		'length'              => '300',
		'supportingWeight'    => '2',
		'supportingDirection' => 'Straight',
		'material'            => 'Stainless Steel + Stainless Steel',
		'finish'              => 'Nickel Plated',
		'connections'         => 'M8 Male Thread',
	);

	/**
	 * 样例数据的单位（预览与测试发信要用）。
	 * 真实提交里单位来自前端 `xxxUnit`，样例里必须逐个补齐——
	 * 少一个，预览就少一行单位，看着像插件漏传。
	 */
	private const SAMPLE_FIELD_UNITS = array(
		'quantity'         => 'PCS',
		'outerDiameter'    => 'MM',
		'innerDiameter'    => 'MM',
		'length'           => 'MM',
		'supportingWeight' => 'g',
	);

	/*
	==================================================================
	 * i18n：内置三语字典（en 为兜底），filter eln_strings 可增补
	 * ================================================================== */
}
