<?php
/**
 * ELN_Settings_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Settings_Trait {


	public static function register_template_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			self::t( 'tpl_menu' ),
			self::t( 'tpl_menu' ),
			'manage_options',
			self::TPL_SLUG,
			array( __CLASS__, 'render_template_page' )
		);
		// 回信模板不单独开页：作为一张卡片并进上面的「邮件模板」页（render_template_page 末尾）
	}

	/** 两套模板各注册一个 option（分组不同，表单才互不覆盖） */
	public static function register_template_setting(): void {
		register_setting(
			'eln_template_group',
			self::OPT_TEMPLATE,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_template' ),
				'default'           => array(),
			)
		);
		register_setting(
			'eln_autoreply_group',
			self::OPT_AUTOREPLY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_autoreply' ),
				'default'           => array(),
			)
		);
		register_setting(
			'eln_reply_group',
			self::OPT_REPLY,
			array(
				// 不要注册 default：一旦注册，get_option 在 option 不存在时会返回注册的空数组，
				// reply_tpl 就无法区分「从未保存过（该显示默认）」和「保存了空白（该保持空白）」
				'sanitize_callback' => array( __CLASS__, 'sanitize_reply' ),
			)
		);
	}

	/** 「回信模板」保存时的清洗：占位符要原样保留，正文保留换行 */
	public static function sanitize_reply( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'to'      => sanitize_text_field( (string) ( $input['to'] ?? '' ) ),
			'cc'      => sanitize_text_field( (string) ( $input['cc'] ?? '' ) ),
			'bcc'     => sanitize_text_field( (string) ( $input['bcc'] ?? '' ) ),
			'subject' => sanitize_text_field( (string) ( $input['subject'] ?? '' ) ),
			'body'    => sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ),
		);
	}

	/** 人工回信模板：从未保存过 → 显示内置默认；保存过 → 原样尊重（包括留空） */
	public static function reply_tpl(): array {
		$tpl = get_option( self::OPT_REPLY );
		if ( ! is_array( $tpl ) ) {
			return self::reply_template_default();
		}
		return $tpl + array(
			'to'      => '',
			'cc'      => '',
			'bcc'     => '',
			'subject' => '',
			'body'    => '',
		);
	}

	/** 回复模板默认值（mailto 正文是纯文本，不认 HTML；to 留空占位符则回退访客邮箱） */
	private static function reply_template_default(): array {
		return array(
			'to'      => '{{email}}',
			'cc'      => '',
			'bcc'     => '',
			'subject' => 'Re: {{no}}',
			'body'    => "Dear {{name}},\n\nThank you for your inquiry (No. {{no}}, submitted {{date}}).\n\n\n\nBest regards,",
		);
	}

	/** 「询盘通知」保存时的清洗 */
	public static function sanitize_template( $input ): array {
		return self::sanitize_template_of( $input, 'notify' );
	}

	/**
	 * 「自动确认」保存时的清洗。
	 *
	 * 页面一次只编辑一个语言组，所以这里要**合并**而不是整体覆盖：
	 * 取出现有语言组，把提交上来的那组（`cur` 下标）替换掉，其余原样带回。
	 * 校验也在这里做：语言标签只留小写字母/数字/连字符；第 0 组恒为默认组（lang 强制为空）。
	 */
	public static function sanitize_autoreply( $input ): array {
		$input = is_array( $input ) ? $input : array();

		// 程序内直接 update_option(整份配置) 时会再走一遍这个过滤器：
		// 这种载荷带 groups、不带 cur，原样规范化即可，别当成「表单提交的某一组」覆盖掉第 0 组
		if ( isset( $input['groups'] ) && ! isset( $input['cur'] ) ) {
			return array(
				'enabled' => empty( $input['enabled'] ) ? '0' : '1',
				'groups'  => self::reply_groups( array( 'groups' => (array) $input['groups'] ) ),
			);
		}

		$groups = self::reply_groups();
		$cur    = isset( $input['cur'] ) ? max( 0, (int) $input['cur'] ) : 0;
		if ( ! isset( $groups[ $cur ] ) ) {
			$cur = 0;
		}

		$lang = self::canonical_lang( (string) sanitize_text_field( (string) ( $input['lang'] ?? '' ) ) );
		if ( $cur === 0 ) {
			$lang = ''; // 默认组不带语言标签
		}

		$groups[ $cur ] = array(
			'lang'    => $lang,
			'subject' => sanitize_text_field( (string) ( $input['subject'] ?? '' ) ),
			'html'    => self::sanitize_template_html( (string) ( $input['html'] ?? '' ) ),
			'labels'  => self::sanitize_labels( (array) ( $input['labels'] ?? array() ) ),
		);

		return array(
			// 复选框不勾选时根本不会出现在提交里，所以这里显式归一成 0/1
			'enabled' => empty( $input['enabled'] ) ? '0' : '1',
			'groups'  => self::reply_groups( array( 'groups' => $groups ) ),
		);
	}

	/** 单个语言组的清洗（保存与实时预览共用，预览不落库） */
	private static function sanitize_group( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$lang  = self::canonical_lang( (string) sanitize_text_field( (string) ( $input['lang'] ?? '' ) ) );
		return array(
			'lang'    => $lang,
			'subject' => sanitize_text_field( (string) ( $input['subject'] ?? '' ) ),
			'html'    => self::sanitize_template_html( (string) ( $input['html'] ?? '' ) ),
			'labels'  => self::sanitize_labels( (array) ( $input['labels'] ?? array() ) ),
		);
	}

	/** 字段标签覆盖表：键必须是合法字段名，值走文本清洗（空值保留 = 未覆盖） */
	private static function sanitize_labels( array $input ): array {
		$labels = array();
		foreach ( $input as $key => $label ) {
			$key   = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
			$label = sanitize_text_field( (string) $label );
			// 空值也要留：它表示"这个字段存在，但没自定义标签"，否则手动添加的字段保存后就消失
			if ( $key !== '' && ! in_array( $key, self::META_FIELDS, true ) ) {
				$labels[ $key ] = $label;
			}
		}
		return $labels;
	}

	/** 保存时清洗：主题与标签走文本清洗，HTML 走自定义过滤（见 sanitize_template_html） */
	private static function sanitize_template_of( $input, string $kind ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'subject' => sanitize_text_field( (string) ( $input['subject'] ?? '' ) ),
			'html'    => self::sanitize_template_html( (string) ( $input['html'] ?? '' ) ),
			'labels'  => self::sanitize_labels( (array) ( $input['labels'] ?? array() ) ),
		);
	}

	/**
	 * 自定义模板的清洗：只去掉脚本类标签与事件属性，**保留内联样式和 {{占位符}}**。
	 *
	 * 用 wp_kses 白名单替代正则；通过 safecss_filter_attr_allow_css 放行 {{token}} 与颜色函数。
	 * 核心 safecss_filter_attr() 会把 rgba()/rgb()/hsl() 里的括号判为不安全并剥掉，
	 * 导致预览里页头浅色字继承成黑字，这里对该字段定向放行。
	 * 该字段仅 manage_options 可写，产物是邮件正文、不在站点前台渲染。
	 */
	private static function sanitize_template_html( string $html ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}

		$allow_tokens = static function ( $allow_css, $css_test_string ) {
			if ( ! is_string( $css_test_string ) ) {
				return $allow_css;
			}
			if ( strpos( $css_test_string, '{{' ) !== false ) {
				return true;
			}
			if ( preg_match( '/\b(?:rgba?|hsla?)\s*\(/i', $css_test_string ) ) {
				return true;
			}
			return $allow_css;
		};
		add_filter( 'safecss_filter_attr_allow_css', $allow_tokens, 10, 2 );
		$clean = wp_kses(
			$html,
			wp_kses_allowed_html( 'post' ),
			array( 'http', 'https', 'mailto' )
		);
		remove_filter( 'safecss_filter_attr_allow_css', $allow_tokens, 10 );

		return $clean;
	}

	/** 当前编辑的是哪套模板：notify（发给销售）/ autoreply（自动确认） */
	private static function current_kind(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$kind = isset( $_GET['kind'] ) ? sanitize_key( (string) $_GET['kind'] ) : '';
		if ( $kind === 'autoreply' ) {
			return 'autoreply';
		}
		return $kind === 'reply' ? 'reply' : 'notify';
	}

	/** 模板页某个标签的地址（标签之间只差 kind 参数） */
	private static function template_tab_url( string $kind ): string {
		$url = admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=' . self::TPL_SLUG );
		return $kind === 'notify' ? $url : add_query_arg( 'kind', $kind, $url );
	}

	/** 自动确认里，当前正在编辑哪一个语言组（越界回落到默认组） */
	private static function current_slot( array $groups ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slot = isset( $_GET['slot'] ) ? max( 0, (int) $_GET['slot'] ) : 0;
		return isset( $groups[ $slot ] ) ? $slot : 0;
	}

	/** 某个语言组的编辑地址 */
	private static function reply_slot_url( int $slot ): string {
		return add_query_arg(
			array(
				'post_type' => self::POST_TYPE,
				'page'      => self::TPL_SLUG,
				'kind'      => 'autoreply',
				'slot'      => $slot,
			),
			admin_url( 'edit.php' )
		);
	}
}
