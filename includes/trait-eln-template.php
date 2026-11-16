<?php
/**
 * ELN_Template_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Template_Trait {


	/** 两种模板：发给销售的通知、发给访客的自动确认 */
	private static function option_name( string $kind = 'notify' ): string {
		return $kind === 'autoreply' ? self::OPT_AUTOREPLY : self::OPT_TEMPLATE;
	}

	/** 模板默认值：后台只保存这几项，其余全部体现在 HTML 源码里 */
	private static function template_defaults( string $kind = 'notify' ): array {
		$defaults = array(
			'subject' => '', // 空 = 语言字典的主题格式
			'html'    => '', // 空 = 使用默认模板源码
			'labels'  => array(), // 字段标签覆盖：字段名 => 显示名
		);
		if ( $kind === 'autoreply' ) {
			// 自动确认按浏览器语言分「语言组」：索引 0 永远是默认（兜底）组
			$defaults['enabled'] = '0';
			$defaults['groups']  = array( self::empty_group() );
		}
		return $defaults;
	}

	/** 一个空的语言组（lang 为空 = 默认/兜底） */
	private static function empty_group( string $lang = '' ): array {
		return array(
			'lang'    => $lang,
			'subject' => '',
			'html'    => '',
			'labels'  => array(),
		);
	}

	/**
	 * 自动确认的语言组（规范化后的列表，索引 0 恒为默认组）。
	 *
	 * 规范化做三件事：补齐缺省字段、把 lang 为空的组提到第一位、同一语言只留第一个。
	 * 读（渲染/匹配）与写（保存）都走它，索引才对得上。
	 */
	private static function reply_groups( ?array $cfg = null ): array {
		$cfg    = $cfg ?? self::tpl( 'autoreply' );
		$groups = isset( $cfg['groups'] ) && is_array( $cfg['groups'] ) ? $cfg['groups'] : array();

		$clean = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$clean[] = array(
				'lang'    => self::canonical_lang( (string) ( $group['lang'] ?? '' ) ),
				'subject' => (string) ( $group['subject'] ?? '' ),
				'html'    => (string) ( $group['html'] ?? '' ),
				'labels'  => (array) ( $group['labels'] ?? array() ),
			);
		}

		// 把默认组换到第 0 位（没有就补一个）
		$default_index = null;
		foreach ( $clean as $i => $group ) {
			if ( $group['lang'] === '' ) {
				$default_index = $i;
				break;
			}
		}
		if ( $default_index === null ) {
			array_unshift( $clean, self::empty_group() );
		} elseif ( $default_index !== 0 ) {
			$swap                    = $clean[0];
			$clean[0]                = $clean[ $default_index ];
			$clean[ $default_index ] = $swap;
		}

		// 同一语言只留第一个（默认组不算重复）
		$seen = array( '' => true );
		$out  = array( $clean[0] );
		foreach ( array_slice( $clean, 1 ) as $group ) {
			if ( $group['lang'] === '' || isset( $seen[ strtolower( $group['lang'] ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $group['lang'] ) ] = true;
			$out[]                                = $group;
		}

		return $out;
	}

	/**
	 * 按访客浏览器语言挑语言组：完整标签精确命中（zh-cn）→ 主语言命中（zh）
	 * → 默认组。没有浏览器语言（旧前端/无 JS）也走默认组。
	 */
	private static function reply_group( array $fields, ?array $cfg = null ): array {
		$groups = self::reply_groups( $cfg );
		$lang   = strtolower( trim( (string) ( $fields['browserLanguage'] ?? '' ) ) );
		if ( $lang === '' ) {
			return $groups[0];
		}

		foreach ( $groups as $group ) {
			if ( strtolower( $group['lang'] ) === $lang ) {
				return $group;
			}
		}
		$primary = explode( '-', $lang )[0];
		foreach ( $groups as $group ) {
			if ( $group['lang'] !== '' && explode( '-', strtolower( $group['lang'] ) )[0] === $primary ) {
				return $group;
			}
		}
		return $groups[0];
	}

	/** 当前生效的模板配置（默认值 + 后台保存值），按 kind 各自缓存 */
	private static function tpl( string $kind = 'notify' ): array {
		static $cache = array();
		if ( ! isset( $cache[ $kind ] ) ) {
			$saved    = get_option( self::option_name( $kind ) );
			$is_saved = is_array( $saved ) && $saved !== array(); // 保存过 = 之后空就是空
			$tpl      = wp_parse_args( is_array( $saved ) ? $saved : array(), self::template_defaults( $kind ) );
			if ( $kind === 'autoreply' ) {
				// 语言组统一规范化：0 号恒为默认组，同一语言只留一份
				$tpl['groups'] = self::reply_groups( $tpl );
				if ( ! $is_saved ) {
					// 从未保存过：各组空源码用内置默认（首次进后台就能看到完整模板）
					foreach ( $tpl['groups'] as $i => $g ) {
						$tpl['groups'][ $i ]['html'] = self::default_template_html( 'autoreply' );
					}
				}
			} elseif ( ! $is_saved ) {
				$tpl['html'] = self::default_template_html( 'notify' );
			}
			$cache[ $kind ] = $tpl;
		}
		return $cache[ $kind ];
	}

	/**
	 * 自动确认可选的语言（i18n 选项）：键 = 浏览器语言标签，值 = 给人看的名字。
	 *
	 * 只列常用的；匹配时先比完整标签再比主语言，所以 `pt` 能吃掉 `pt-BR`。
	 */
	private static function lang_options(): array {
		return array(
			'en'    => 'English (en)',
			'en-US' => 'English — US (en-US)',
			'en-GB' => 'English — UK (en-GB)',
			'zh'    => '中文 — 全部 (zh)',
			'zh-CN' => '简体中文 (zh-CN)',
			'zh-TW' => '繁體中文 (zh-TW)',
			'zh-HK' => '繁體中文 — 香港 (zh-HK)',
			'ja'    => '日本語 (ja)',
			'ko'    => '한국어 (ko)',
			'de'    => 'Deutsch (de)',
			'fr'    => 'Français (fr)',
			'es'    => 'Español (es)',
			'pt'    => 'Português (pt)',
			'it'    => 'Italiano (it)',
			'nl'    => 'Nederlands (nl)',
			'ru'    => 'Русский (ru)',
			'pl'    => 'Polski (pl)',
			'tr'    => 'Türkçe (tr)',
			'ar'    => 'العربية (ar)',
			'th'    => 'ไทย (th)',
			'vi'    => 'Tiếng Việt (vi)',
			'id'    => 'Bahasa Indonesia (id)',
			'hi'    => 'हिन्दी (hi)',
		);
	}

	/** 语言标签的显示名（认不出来的原样大写显示，兼容手改过的旧值） */
	private static function lang_label( string $code ): string {
		if ( $code === '' ) {
			return self::t( 'tpl_lang_default' );
		}
		$options = self::lang_options();
		return $options[ $code ] ?? strtoupper( $code );
	}

	/**
	 * 语言标签归一：能对上候选表就用表里的规范写法（zh-CN，别再变 zh-cn），
	 * 对不上就小写保留（自定义/旧数据）。顺手剔掉非法字符。
	 */
	private static function canonical_lang( string $lang ): string {
		$lang = (string) preg_replace( '/[^A-Za-z0-9\-_]/', '', trim( $lang ) );
		if ( $lang === '' ) {
			return '';
		}
		foreach ( array_keys( self::lang_options() ) as $code ) {
			if ( strcasecmp( $code, $lang ) === 0 ) {
				return $code;
			}
		}
		return strtolower( $lang );
	}

	/**
	 * 询盘序号：IN- + 站点时区的年月日 + 当日流水号，如 IN-20261105001。
	 * 用 MySQL 的 LAST_INSERT_ID(expr) 做原子自增，避免并发提交拿到同一个号。
	 */
	private static function sample_fields(): array {
		$labels = (array) self::label_fields()['fields'];

		$fields = array();
		foreach ( array_keys( $labels ) as $key ) {
			$fields[ $key ] = self::SAMPLE_FIELD_VALUES[ $key ] ?? 'Sample';
		}
		// 单位跟着数值走：每个带单位的字段都要补，否则预览里就这几行"少根尾巴"。
		// 没有数值时 build_rows() 会整条丢掉，不会留下孤立的 "PCS"
		foreach ( self::SAMPLE_FIELD_UNITS as $unit_key => $unit_value ) {
			if ( isset( $fields[ $unit_key ] ) ) {
				$fields[ $unit_key . 'Unit' ] = $unit_value;
			}
		}
		$fields['fieldLabels'] = $labels;

		return $fields + array(
			'formVariant'     => 'page',
			'pageTitle'       => 'Contact Us',
			'pageTitleEn'     => 'Contact Us',
			'pageUrl'         => home_url( '/contact-us' ),
			'locale'          => 'en',
			// 示例环境数据：预览里 {{lang}} / {{time_zone}} / {{time_local}} 才有东西可看
			'browserLanguage' => 'en-US',
			'timezone'        => 'Asia/Shanghai',
		);
	}

	/**
	 * 默认邮件模板源码（后台代码框里看到的就是它）。
	 *
	 * 版式：品牌色整块页头（白字，含编号/来源/时间）+ 细线键值表 + 底部浅灰信息块。
	 * 页头与页脚的文案都写在这里，想换说法或换语言直接改代码。
	 * $kind = 'autoreply' 时换成发给访客的那份默认源码。
	 */
	private static function default_template_html( string $kind = 'notify' ): string {
		if ( $kind === 'autoreply' ) {
			return self::default_autoreply_html();
		}
		return <<<'HTML'
<div style="margin:0;padding:40px 12px;background:#f7f7f7;font-family:'PingFang SC','Microsoft YaHei',sans-serif;letter-spacing:.5px;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-collapse:collapse;">
    <tr>
      <td style="padding:28px 40px;background:#04979e;">
        <!-- 页头第一行：编号 + 标题，改文案就在这里 -->
        <div style="font-size:22px;line-height:1.3;color:#ffffff;font-weight:600;">网站询盘#{{no}}</div>
        <!-- 页头第二行：来源 + 表单类型 + 提交时间 -->
        <div style="margin-top:8px;font-size:12px;line-height:1.7;color:#d9eff0;">来源：{{source}}（{{form_type}}）　{{time}}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:24px 40px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
          {{rows}}
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:24px 40px 36px;">
        <!-- 底部信息块：左边 3px 品牌色竖条。标签都写在下面，占位符只注入值 -->
        <div style="padding:16px 18px;background:#f7f7f7;border-left:3px solid #04979e;font-size:12px;line-height:1.8;color:#555555;">
          浏览器语言：{{lang}}　时区：{{time_zone}}<br>
          提交时间：{{time}}　当地时间：{{time_local}}<br>
          IP：{{ip}}
        </div>
      </td>
    </tr>
  </table>
</div>
HTML;
	}

	/**
	 * 默认的「自动确认」源码。
	 *
	 * 收件人是访客本人：中英双语（站点默认语是 en，询盘客户多数在海外），
	 * 正文回显他刚提交的内容（{{rows}} 的表头用的就是他表单自己的措辞），
	 * 页脚只留站名和时间，不放 IP 这类内部信息。
	 */
	private static function default_autoreply_html(): string {
		return <<<'HTML'
<div style="margin:0;padding:40px 12px;background:#f7f7f7;font-family:'PingFang SC','Microsoft YaHei',sans-serif;letter-spacing:.5px;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-collapse:collapse;">
    <tr>
      <td style="padding:28px 40px;background:#04979e;">
        <!-- 页头：标题 + 编号，改文案就在这里 -->
        <div style="font-size:22px;line-height:1.3;color:#ffffff;font-weight:600;">We've received your inquiry</div>
        <div style="margin-top:8px;font-size:12px;line-height:1.7;color:#d9eff0;">#{{no}}　{{time}}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 40px 0;font-size:14px;line-height:1.85;color:#333333;">
        Dear {{name}},<br>
        Thank you for contacting us. Your message has reached our team and we will reply within one business day.
        <div style="margin-top:10px;color:#777777;">您好，我们已收到您的询盘，会在一个工作日内回复。</div>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 40px 0;">
        <!-- 回显访客刚提交的内容：表头文字来自表单自己的字段标签 -->
        <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8a979d;margin-bottom:6px;">Your message / 您提交的内容</div>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
          {{rows}}
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 40px 36px;">
        <div style="padding:16px 18px;background:#f7f7f7;border-left:3px solid #04979e;font-size:12px;line-height:1.8;color:#555555;">
          Inquiry #{{no}}<br>
          {{time}}
        </div>
      </td>
    </tr>
  </table>
</div>
HTML;
	}

	/**
	 * 邮件正文 = 模板源码（后台保存的，没有就用默认源码）+ 占位符替换。
	 *
	 * **占位符只输出值，不输出任何标签文案**——「语言：」「时区：」这类字眼由源码自己写，
	 * 想让邮件说什么话，完全取决于模板。
	 *
	 *   字段  {{rows}}
	 *   数据  {{name}} {{email}} {{no}} {{source}} {{form_type}}
	 *         {{time}}（站点时间） {{time_local}}（访客当地时间）
	 *         {{ip}} {{lang}}（浏览器语言） {{time_zone}}（访客时区）
	 *
	 * 页头、页脚、各类固定文案都直接写在源码里——想换语言/换说法就改代码。
	 */
}
