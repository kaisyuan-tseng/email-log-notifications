<?php
/**
 * ELN_Mailer_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Mailer_Trait {

	private static function next_inquiry_no(): string {
		global $wpdb;

		$day = (string) current_time( 'Ymd' );
		$key = 'eln_seq_' . $day;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
				$key
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
				$key
			)
		);
		$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		return 'IN-' . $day . str_pad( (string) max( 1, $seq ), 3, '0', STR_PAD_LEFT );
	}

	/**
	 * 邮件主题：**和正文一样用双花括号** {{name}} / {{email}}；留空用当前语言的默认格式。
	 * 单花括号 {name} {email} 是旧写法，保留替换但不再宣传（老主题不会变字面量）。
	 */
	private static function build_subject( array $fields, ?array $tpl = null, string $kind = 'notify' ): string {
		$tpl    = $tpl ?? self::tpl( $kind );
		$custom = trim( (string) $tpl['subject'] );
		if ( $custom !== '' ) {
			$name  = (string) ( $fields['fullName'] ?? '' );
			$email = (string) ( $fields['email'] ?? '' );

			// strtr 按最长优先匹配：{{name}} 不会被 {name} 抢先吃掉
			return strtr(
				$custom,
				array(
					'{{name}}'  => $name,
					'{{email}}' => $email,
					'{name}'    => $name,
					'{email}'   => $email,
				)
			);
		}
		if ( $kind === 'autoreply' ) {
			return self::t( 'email_subject_reply' );
		}
		return sprintf(
			self::t( 'email_subject' ),
			(string) ( $fields['fullName'] ?? '' ) . ' <' . (string) ( $fields['email'] ?? '' ) . '>'
		);
	}

	/**
	 * 设置页的示例数据：预览和测试发信都用它。
	 *
	 * 字段集直接取 label_fields()——也就是「字段标签」里列出的那一整套，
	 * 一封不少。否则预览会随最近一封询盘填了哪些选填项来回跳动（少填几项就少几行），
	 * 看着像模板漏行。
	 */
	private static function build_body( array $fields, array $staged, ?array $tpl = null, string $eln_inquiry_no = '', string $kind = 'notify', int $timestamp = 0 ): string {
		$tpl = $tpl ?? self::tpl( $kind );

		// 提交时间 = **服务端收到请求的时刻**（不采信浏览器时钟）：
		// 一次请求里取一次，通知与自动确认共用同一个时间戳，跨零点也不会对不上。
		$timestamp = $timestamp > 0 ? $timestamp : time();

		$variant      = (string) ( $fields['formVariant'] ?? '' );
		$form_type    = $variant === 'modal' ? self::t( 'form_modal' ) : self::t( 'form_page' );
		$source_label = self::source_label( $fields );

		// 字段标签：通知用后台那一份；自动确认用自己的那份（每个语言组各一份）。
		// 两边都没配时，field_label() 会回落到「表单自己提交的标签」，
		// 而表单是访客所在语种渲染的，所以表头天然跟着语言走。
		$labels = (array) ( $tpl['labels'] ?? array() );

		$rows = self::build_rows( $fields, $staged, $labels );

		$timezone   = self::field_timezone( $fields );
		$local_time = self::local_time_label( $timezone, 'Y-m-d H:i:s T', $timestamp );

		// 源码就是源码：保存过就原样用（清空 = 空正文），没保存过才由 tpl() 注入默认
		$source = (string) ( $tpl['html'] ?? '' );

		return strtr(
			$source,
			array(
				'{{site_name}}'  => '', // 已废弃：站名没有信息量，模板里不再使用
				'{{name}}'       => esc_html( (string) ( $fields['fullName'] ?? '' ) ),
				'{{email}}'      => esc_html( (string) ( $fields['email'] ?? '' ) ),
				'{{source}}'     => esc_html( $source_label ),
				'{{form_type}}'  => esc_html( $form_type ),
				'{{rows}}'       => $rows,
				'{{no}}'         => esc_html( $eln_inquiry_no !== '' ? $eln_inquiry_no : 'IN-' . current_time( 'Ymd' ) . '001' ),
				'{{time}}'       => esc_html( date_i18n( 'Y-m-d H:i:s T', $timestamp ) ),
				'{{time_local}}' => esc_html( $local_time ),
				'{{ip}}'         => esc_html( self::client_ip() ),
				'{{lang}}'       => esc_html( (string) ( $fields['browserLanguage'] ?? '' ) ),
				'{{time_zone}}'  => esc_html( $timezone ),
			)
		);
	}

	/**
	 * 自动确认：提交成功后给访客回一封，返回 true/false；
	 * **null 表示没开启（或邮箱不合法），调用方据此不写状态 meta**。
	 *
	 * 用哪套主题/源码/字段标签，取决于访客的浏览器语言（见 reply_group()）：
	 * zh-CN → 命中 zh 组，没配就落到默认组。
	 */
	private static function send_autoreply( array $fields, string $eln_inquiry_no, int $timestamp = 0 ): ?bool {
		$cfg = self::tpl( 'autoreply' );
		if ( empty( $cfg['enabled'] ) ) {
			return null;
		}
		$email = trim( (string) ( $fields['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return null;
		}

		$group   = self::reply_group( $fields, $cfg );
		$subject = self::build_subject( $fields, $group, 'autoreply' );
		// 自动确认不带附件：访客刚传的文件没必要再回传一遍
		$body    = self::build_body( $fields, array(), $group, $eln_inquiry_no, 'autoreply', $timestamp );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			// 客户直接回信就回到询盘收件箱，而不是 bounced 的发件人地址
			'Reply-To: ' . (string) apply_filters( 'eln_recipient', get_option( 'admin_email' ) ),
		);

		$sent = self::mail( $email, $subject, $body, $headers );

		/**
		 * 自动确认发出后触发，可用来对接 CRM 或另发通知。
		 *
		 * @param bool   $sent
		 * @param string $email
		 * @param array  $fields 本次提交的字段
		 */
		do_action( 'eln_autoreply_sent', $sent, $email, $fields );

		return $sent;
	}

	/**
	 * 发一封本站邮件：统一包一层，只为两件事——
	 *   1. 打上 self::$sending 标记，让 phpmailer_init 补纯文本正文（只有 HTML 容易被判垃圾）
	 *   2. 清空并取回失败原因（wp_mail_failed 里捕获的），供调用方写进记录
	 */
	private static function mail( string $to, string $subject, string $body, array $headers = array(), array $attachments = array() ): bool {
		self::$sending    = true;
		self::$mail_error = '';
		$sent             = (bool) wp_mail( $to, $subject, $body, $headers, $attachments );
		self::$sending    = false;

		return $sent;
	}

	/** 给纯 HTML 邮件补一段 text/plain 正文（Gmail 等对「只有 HTML」的邮件更挑剔） */
	public static function fill_alt_body( $phpmailer ): void {
		if ( ! self::$sending || ! is_object( $phpmailer ) ) {
			return;
		}
		if ( '' !== (string) $phpmailer->AltBody ) {
			return; // 已有纯文本就别覆盖
		}
		$phpmailer->AltBody = self::html_to_text( (string) $phpmailer->Body );
	}

	/** 记录 wp_mail 失败原因（SMTP 报错、收件人被拒等） */
	public static function capture_mail_error( $error ): void {
		if ( is_wp_error( $error ) ) {
			self::$mail_error = (string) $error->get_error_message();
		}
	}

	/** HTML 转纯文本：够用就行，不做完整解析 */
	private static function html_to_text( string $html ): string {
		$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#</(p|div|tr|h[1-6]|table|li)>#i', "\n", $text );
		$text = (string) preg_replace( '#</t[dh]>#i', '  ', $text );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 逐行 trim、丢掉空行堆叠：嵌套表格会留一堆只有空格的“空行”
		$lines = preg_split( '/\R/u', $text ) ?: array();
		$out   = array();
		$blank = false;
		foreach ( $lines as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', (string) $line ) );
			if ( $line === '' ) {
				if ( ! $blank && $out ) {
					$out[] = '';
					$blank = true;
				}
				continue;
			}
			$out[] = $line;
			$blank = false;
		}

		return trim( implode( "\n", $out ) );
	}

	/** 来源页展示名：优先英文页面标题（统一口径），退回本地化标题，再退回 URL 路径 */
	private static function source_label( array $fields ): string {
		$title = (string) ( $fields['pageTitleEn'] ?? '' );
		if ( $title === '' ) {
			$title = (string) ( $fields['pageTitle'] ?? '' );
		}
		if ( $title !== '' ) {
			return $title;
		}
		$path = parse_url( (string) ( $fields['pageUrl'] ?? '' ), PHP_URL_PATH );
		if ( $path && $path !== '/' ) {
			return trim( (string) $path, '/' );
		}
		return ( $fields['formVariant'] ?? '' ) === 'modal'
			? self::t( 'form_modal' ) : self::t( 'form_page' );
	}

	/** 字段表行：单位后缀并入数值；行序 = 标签表顺序（拖过）或前端提交顺序 */
	private static function build_rows( array $fields, array $staged, ?array $labels = null ): string {
		// 1) 单位并入数值（quantityUnit → "500 PCS"）
		$merged = array();
		foreach ( $fields as $key => $value ) {
			if ( str_ends_with( $key, 'Unit' ) ) {
				$base = substr( $key, 0, -4 );
				if ( isset( $fields[ $base ] ) && ! in_array( $base, self::META_FIELDS, true ) ) {
					$merged[ $base ] = $fields[ $base ] . ' ' . $value;
				}
				// 没有配套数值（用户没填）就整条丢掉，否则会出现孤立的 "Quantity Unit: PCS"
				continue;
			}
			$merged[ $key ] = $value;
		}

		// 2) 排序：**后台「字段标签」里排出来的顺序就是行序**（用户能拖）；
		// 没排过的字段保持前端提交时的顺序（数组顺序 = 表单顺序），不做人为排序。
		$ordered = array();
		foreach ( array_keys( (array) $labels ) as $key ) {
			if ( isset( $merged[ $key ] ) ) {
				$ordered[ $key ] = $merged[ $key ];
				unset( $merged[ $key ] );
			}
		}
		foreach ( $merged as $key => $value ) {
			if ( ! in_array( $key, self::META_FIELDS, true ) && $key !== self::HONEYPOT ) {
				$ordered[ $key ] = $value;
			}
		}

		// 3) 渲染
		$html = '';
		foreach ( $ordered as $key => $value ) {
			if ( $key === 'email' ) {
				$value_html = '<a href="mailto:' . esc_attr( $value ) . '" style="color:' . self::BRAND_COLOR . ';text-decoration:none;">'
					. esc_html( $value ) . '</a>';
			} else {
				$value_html = nl2br( esc_html( (string) $value ) );
			}
			$html .= '<tr><th style="' . self::th_style() . '">' . esc_html( self::field_label( $key, $labels, (array) ( $fields['fieldLabels'] ?? array() ) ) ) . '</th>'
					. '<td style="' . self::td_style() . '">' . $value_html . '</td></tr>';
		}

		// 4) 附件清单（真实附件在邮件附件区，这里列清单供核对）
		if ( $staged ) {
			$items = '';
			foreach ( $staged as $file ) {
				$items .= '<div style="margin:1px 0;">' . esc_html( $file['name'] )
						. ' <span style="color:#8a979d;font-size:12px;">(' . esc_html( self::size_label( $file['size'] ) ) . ')</span></div>';
			}
			$html .= '<tr><th style="' . self::th_style() . '">' . esc_html( self::t( 'row_attachments' ) ) . '</th>'
					. '<td style="' . self::td_style() . '">' . $items . '</td></tr>';
		}

		return $html;
	}

	private static function size_label( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 0 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/* ================================================================ 留档 */
}
