<?php
/**
 * ELN_I18n_Trait — gettext-backed strings for Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_I18n_Trait {

	/**
	 * Build the string map. Source language is English; translations come from gettext.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return array<string, string>
	 */
	private static function strings( ?string $locale = null ): array {
		static $cache = array();
		$locale       = $locale ?? self::$render_locale ?? get_locale();
		if ( isset( $cache[ $locale ] ) ) {
			return $cache[ $locale ];
		}

		$en = array(
			'menu_name'               => __( 'Inquiries', 'email-log-notifications' ),
			'all_items'               => __( 'All Inquiries', 'email-log-notifications' ),
			'search_items'            => __( 'Search Inquiries', 'email-log-notifications' ),
			'not_found'               => __( 'No inquiries yet.', 'email-log-notifications' ),
			'not_found_trash'         => __( 'No inquiries in Trash.', 'email-log-notifications' ),
			'col_no'                  => __( 'No.', 'email-log-notifications' ),
			'col_name'                => __( 'Contact', 'email-log-notifications' ),
			'col_email'               => __( 'Email', 'email-log-notifications' ),
			'col_source'              => __( 'Source', 'email-log-notifications' ),
			'col_attachments'         => __( 'Attachments', 'email-log-notifications' ),
			'col_mail'                => __( 'Email status', 'email-log-notifications' ),
			'status_none'             => __( 'Not sent', 'email-log-notifications' ),
			'status_partial'          => __( 'Partly sent', 'email-log-notifications' ),
			'status_all'              => __( 'All sent', 'email-log-notifications' ),
			'status_none_hint'        => __( 'Neither the inquiry notification nor the auto-confirmation was sent', 'email-log-notifications' ),
			'status_notify_only'      => __( 'Only the inquiry notification was sent', 'email-log-notifications' ),
			'status_reply_only'       => __( 'Only the auto-confirmation was sent', 'email-log-notifications' ),
			'status_all_hint'         => __( 'Both the inquiry notification and the auto-confirmation were sent', 'email-log-notifications' ),
			'status_yes'              => __( 'Sent', 'email-log-notifications' ),
			'status_no'               => __( 'Failed', 'email-log-notifications' ),
			'label_notify'            => __( 'Inquiry notification', 'email-log-notifications' ),
			'label_reply'             => __( 'Auto-confirmation', 'email-log-notifications' ),
			'view_trash'              => __( 'Trash', 'email-log-notifications' ),
			'all_sources'             => __( 'All sources', 'email-log-notifications' ),
			'tpl_menu'                => __( 'Email template', 'email-log-notifications' ),
			'dash_title'              => __( 'Recent inquiries', 'email-log-notifications' ),
			/* translators: Placeholders must be preserved. */
			'dash_today_at'           => __( 'Today %s', 'email-log-notifications' ),
			/* translators: Placeholders must be preserved. */
			'dash_yesterday_at'       => __( 'Yesterday %s', 'email-log-notifications' ),
			/* translators: Placeholders must be preserved. */
			'dash_today'              => __( '%d today', 'email-log-notifications' ),
			/* translators: Placeholders must be preserved. */
			'dash_total'              => __( '%d in total', 'email-log-notifications' ),
			'dash_empty'              => __( 'No inquiries yet.', 'email-log-notifications' ),
			'dash_all'                => __( 'View all inquiries', 'email-log-notifications' ),
			'tpl_intro'               => __( 'The email template is one HTML source you edit directly — the preview on the right renders this exact code.', 'email-log-notifications' ),
			'tpl_subject'             => __( 'Subject', 'email-log-notifications' ),
			'tpl_subject_hint'        => __( 'Tokens: {{name}} {{email}} — same braces as the body. Leave empty to use the default wording.', 'email-log-notifications' ),
			'tpl_html'                => __( 'Email HTML source', 'email-log-notifications' ),
			'tpl_html_hint'           => __( 'This is the complete email body — the header text lives here too: look for the <!-- 表头标题 --> comment and edit that line. {{...}} tokens are filled with inquiry data. Clear it and save to restore the default.', 'email-log-notifications' ),
			'tpl_tab_notify'          => __( 'Inquiry notification', 'email-log-notifications' ),
			'tpl_tab_reply'           => __( 'Auto-confirmation', 'email-log-notifications' ),
			'tpl_reply_on'            => __( 'Enable auto-confirmation', 'email-log-notifications' ),
			'tpl_reply_on_hint'       => __( 'When on, the visitor immediately receives the auto-confirmation at the address they typed. Reply-To points at the inquiry inbox.', 'email-log-notifications' ),
			'tpl_lang_bar'            => __( 'Language', 'email-log-notifications' ),
			'tpl_lang_default'        => __( 'Default group', 'email-log-notifications' ),
			'tpl_lang_code'           => __( 'Language', 'email-log-notifications' ),
			'tpl_lang_add'            => __( 'Add language group', 'email-log-notifications' ),
			'tpl_lang_delete'         => __( 'Delete this language group', 'email-log-notifications' ),
			'tpl_lang_delete_confirm' => __( 'Delete this language group and its template?', 'email-log-notifications' ),
			'tpl_lang_added'          => __( 'Language group added.', 'email-log-notifications' ),
			'tpl_lang_deleted'        => __( 'Language group deleted.', 'email-log-notifications' ),
			'tpl_lang_exists'         => __( 'That language group already exists.', 'email-log-notifications' ),
			'tpl_lang_hint'           => __( 'Matched against the visitor’s browser language ({{lang}}): full tag first (zh-CN), then the primary language (zh). Anything unmatched uses the “Default group” — the one template with no language attached.', 'email-log-notifications' ),
			'tpl_reply_labels_hint'   => __( 'These labels only affect {{rows}} in this language group. Leave empty to use the wording the form itself submitted.', 'email-log-notifications' ),
			'tpl_lang_default_hint'   => __( 'Used when the visitor’s browser language matches no language group', 'email-log-notifications' ),
			'tpl_labels_sort'         => __( 'Drag to reorder', 'email-log-notifications' ),
			'tpl_labels_order_hint'   => __( 'Drag the handle to reorder — the table rows in {{rows}} follow this order.', 'email-log-notifications' ),
			'tpl_legend_head'         => __( 'Values marked * come from the front end:', 'email-log-notifications' ),
			'tpl_legend_tail'         => __( 'Every row of {{rows}} also comes from the submitted form fields — new form fields show up here automatically.', 'email-log-notifications' ),
			'tpl_preview'             => __( 'Preview', 'email-log-notifications' ),
			'tpl_preview_hint'        => __( 'Rendered live from the code on the left.', 'email-log-notifications' ),
			'tpl_reset'               => __( 'Restore defaults', 'email-log-notifications' ),
			'tpl_reset_done'          => __( 'Email template restored to defaults.', 'email-log-notifications' ),
			'tpl_test'                => __( 'Send test email', 'email-log-notifications' ),
			'tpl_test_hint'           => __( 'Renders the sample email and sends it to the inquiry inbox — never to a customer.', 'email-log-notifications' ),
			'tpl_test_ok'             => __( 'Test email sent.', 'email-log-notifications' ),
			'tpl_test_fail'           => __( 'Test email failed. Check WP Mail SMTP.', 'email-log-notifications' ),
			'tpl_sample_note'         => __( 'Sample data', 'email-log-notifications' ),
			'tpl_media'               => __( 'Insert media', 'email-log-notifications' ),
			'tpl_media_hint'          => __( 'Pick an image or video from the media library and insert it at the cursor.', 'email-log-notifications' ),
			'tpl_format'              => __( 'Format HTML', 'email-log-notifications' ),
			'tpl_placeholders'        => __( 'Placeholders:', 'email-log-notifications' ),
			'tpl_labels'              => __( 'Field labels', 'email-log-notifications' ),
			'tpl_labels_hint'         => __( 'Labels come from the form that submitted the inquiry. Anything set here overrides them; leave empty to fall back to the field name itself.', 'email-log-notifications' ),
			'tpl_labels_sample'       => __( 'No inquiry has reported any fields yet. The fields sent by the form will appear here automatically after the first inquiry.', 'email-log-notifications' ),
			'view_action'             => __( 'View', 'email-log-notifications' ),
			'restore_action'          => __( 'Restore', 'email-log-notifications' ),
			'delete_action'           => __( 'Delete', 'email-log-notifications' ),
			'confirm_delete'          => __( 'Delete this inquiry and its attachments? This cannot be undone.', 'email-log-notifications' ),
			'deleted_notice'          => __( 'Inquiry deleted.', 'email-log-notifications' ),
			'invalid_request'         => __( 'Invalid request.', 'email-log-notifications' ),
			'not_found_msg'           => __( 'Inquiry not found.', 'email-log-notifications' ),
			'view_page_title'         => __( 'View Inquiry', 'email-log-notifications' ),
			'back'                    => __( '← All Inquiries', 'email-log-notifications' ),
			'content_heading'         => __( 'Inquiry content', 'email-log-notifications' ),
			'modal_close'             => __( 'Close', 'email-log-notifications' ),
			'modal_loading'           => __( 'Loading…', 'email-log-notifications' ),
			'modal_error'             => __( 'Could not load this inquiry.', 'email-log-notifications' ),
			'modal_hint'              => __( 'Inquiry details', 'email-log-notifications' ),
			'modal_prev'              => __( 'Previous inquiry', 'email-log-notifications' ),
			'modal_next'              => __( 'Next inquiry', 'email-log-notifications' ),
			'modal_reply'             => __( 'Email reply', 'email-log-notifications' ),
			'modal_delete'            => __( 'Delete', 'email-log-notifications' ),
			'reply_menu'              => __( 'Reply template', 'email-log-notifications' ),
			'reply_to'                => __( 'To', 'email-log-notifications' ),
			'reply_cc'                => __( 'Cc', 'email-log-notifications' ),
			'reply_bcc'               => __( 'Bcc', 'email-log-notifications' ),
			'reply_reset'             => __( 'Restore defaults', 'email-log-notifications' ),
			'reply_card_hint'         => __( 'Fills the subject and body of the mailto link behind the viewer\'s email reply button.', 'email-log-notifications' ),
			'reply_subject'           => __( 'Subject', 'email-log-notifications' ),
			'reply_body'              => __( 'Body', 'email-log-notifications' ),
			'reply_hint'              => __( 'Placeholders (usable in every field): {{name}} name, {{email}} email, {{no}} inquiry no., {{date}} submitted time, {{source}} source. Multiple addresses are comma-separated. What you save is what is used - blanks included; "Restore defaults" brings the built-in template back.', 'email-log-notifications' ),
			'detail_time'             => __( 'Submitted', 'email-log-notifications' ),
			'form_page'               => __( 'Page form', 'email-log-notifications' ),
			'form_modal'              => __( 'Popup form', 'email-log-notifications' ),
			/* translators: Placeholders must be preserved. */
			'email_subject'           => __( 'New inquiry from %s', 'email-log-notifications' ),
			'row_attachments'         => __( 'Attachments', 'email-log-notifications' ),
			'read_only'               => __( 'Inquiries are read-only. Open them from the Inquiries list.', 'email-log-notifications' ),
			'email_subject_reply'     => __( 'We received your inquiry', 'email-log-notifications' ),
			'fullName'                => __( 'Full Name', 'email-log-notifications' ),
			'email'                   => __( 'Email', 'email-log-notifications' ),
			'message'                 => __( 'Message', 'email-log-notifications' ),
			'application'             => __( 'Application', 'email-log-notifications' ),
			'quantity'                => __( 'Quantity', 'email-log-notifications' ),
			'material'                => __( 'Material', 'email-log-notifications' ),
			'finish'                  => __( 'Finish', 'email-log-notifications' ),
			'connections'             => __( 'Connections', 'email-log-notifications' ),
			'outerDiameter'           => __( 'Outer Diameter', 'email-log-notifications' ),
			'innerDiameter'           => __( 'Inner Diameter', 'email-log-notifications' ),
			'length'                  => __( 'Length', 'email-log-notifications' ),
			'supportingWeight'        => __( 'Supporting Weight', 'email-log-notifications' ),
			'supportingDirection'     => __( 'Supporting Direction', 'email-log-notifications' ),
		);

		/**
		 * Filter the string dictionaries. Returned per-locale maps override gettext.
		 */
		$all = apply_filters(
			'eln_strings',
			array(
				'en'    => $en,
				'zh_CN' => array(),
				'zh_TW' => array(),
			)
		);

		$dict             = ( isset( $all[ $locale ] ) && is_array( $all[ $locale ] ) && $all[ $locale ] )
			? $all[ $locale ]
			: ( $all['en'] ?? $en );
		$cache[ $locale ] = $dict;
		return $dict;
	}

	/**
	 * Load the plugin text domain and allow bundled MO files to win when present.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'email-log-notifications',
			false,
			dirname( plugin_basename( ELN_PLUGIN_FILE ) ) . '/languages'
		);
	}

	private static function t( string $key, ?string $locale = null ): string {
		return self::strings( $locale )[ $key ] ?? '';
	}

	/**
	 * 字段展示名：后台自定义标签 > 语种字典 > camelCase 自动切词。
	 *
	 * @param array|null $labels 后台保存的覆盖表（预览时要传未保存的那份）.
	 */
	private static function field_label( string $key, ?array $labels = null, ?array $submitted = null ): string {
		$labels = $labels ?? (array) ( self::tpl()['labels'] ?? array() );

		// 1) 后台覆盖（站点管理员说了算）
		$custom = trim( (string) ( $labels[ $key ] ?? '' ) );
		if ( $custom !== '' ) {
			return $custom;
		}

		// 2) 前端提交时带来的标签（对接方自己的文案）
		$from_form = trim( (string) ( ( $submitted ?? array() )[ $key ] ?? '' ) );
		if ( $from_form !== '' ) {
			return $from_form;
		}

		// 3) 都没有：字段名本身转为可读文本，插件不假设任何字段叫法
		return self::readable_label( $key );
	}

	/**
	 * 字段名的可读化：插件对字段名做的唯一「翻译」。
	 * outerDiameter → Outer Diameter；shaft_diameter → Shaft Diameter；material1 → Material 1
	 */
	private static function readable_label( string $key ): string {
		$label = preg_replace( '/(?<!^)[A-Z]/', ' $0', $key );          // 驼峰拆词
		$label = str_replace( array( '-', '_' ), ' ', (string) $label );   // 连字符/下划线变空格
		$label = preg_replace( '/(?<=[a-zA-Z])(?=\d)/', ' ', (string) $label ); // 字母与数字之间留空格
		$label = preg_replace( '/\s+/', ' ', (string) $label );
		return ucwords( trim( (string) $label ) );
	}
}
