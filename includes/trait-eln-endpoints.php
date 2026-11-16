<?php
/**
 * ELN_Endpoints_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Endpoints_Trait {


	/** 实时预览：拿表单里未保存的值渲染一封示例邮件 */
	public static function ajax_preview(): void {
		check_ajax_referer( 'eln_preview', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$kind = isset( $_POST['kind'] ) ? sanitize_key( (string) $_POST['kind'] ) : 'notify';
		$kind = $kind === 'autoreply' ? 'autoreply' : 'notify';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw    = isset( $_POST['template'] ) && is_array( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : array();
		$fields = self::sample_fields();

		if ( $kind === 'autoreply' ) {
			// 预览看的是「当前语言组」这一份，不走保存逻辑（不做跨组合并）
			$tpl = self::sanitize_group( $raw );
			if ( $tpl['lang'] !== '' ) {
				// 让预览里的 {{lang}} 也跟着该组语言走
				$fields['browserLanguage'] = $tpl['lang'];
			}
		} else {
			$tpl = self::sanitize_template_of( $raw, $kind );
		}

		$html = self::build_body( $fields, array(), $tpl, '', $kind );

		wp_send_json_success(
			array(
				'html' => $html,
				'kind' => $kind,
			)
		);
	}

	/**
	 * 发一封测试邮件。
	 *
	 * 收件人固定用询盘收件人（admin_email / filter eln_recipient），
	 * 两套模板发往同一收件箱，便于对比。
	 * 自动确认页发的是「当前语言组」的模板，不是默认组。
	 */
	public static function handle_test_mail(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}
		$kind = self::current_kind();
		check_admin_referer( 'eln_test_mail_' . $kind );

		if ( $kind === 'autoreply' ) {
			$slots = self::reply_groups();
			$slot  = self::current_slot( $slots );
			$tpl   = $slots[ $slot ];
		} else {
			$slot = 0;
			$tpl  = self::tpl( 'notify' );
		}

		$fields = self::sample_fields();
		if ( ! empty( $tpl['lang'] ) ) {
			$fields['browserLanguage'] = (string) $tpl['lang'];
		}
		$body    = self::build_body( $fields, array(), $tpl, '', $kind );
		$subject = self::build_subject( $fields, $tpl, $kind );
		$to      = (string) apply_filters( 'eln_recipient', get_option( 'admin_email' ) );

		// 和正式发信走同一个封装：补纯文本正文、失败原因也照同一套规则记录
		$sent = self::mail(
			$to,
			'[TEST] ' . $subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::TPL_SLUG,
					'kind'     => $kind,
					'slot'     => $slot,
					'eln_test' => $sent ? '1' : '0',
				),
				admin_url( 'edit.php?post_type=' . self::POST_TYPE )
			)
		);
		exit;
	}

	/** 自动确认：新增一个语言组（语言代码来自页面顶部的小表单） */
	public static function handle_add_reply_lang(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'eln_reply_lang', '_wpnonce_lang' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$lang = self::canonical_lang( (string) sanitize_text_field( wp_unslash( $_POST['newlang'] ?? '' ) ) );

		$cfg    = self::tpl( 'autoreply' );
		$groups = self::reply_groups( $cfg );

		if ( $lang === '' ) {
			wp_safe_redirect( self::reply_slot_url( 0 ) );
			exit;
		}

		foreach ( $groups as $i => $group ) {
			if ( $group['lang'] === $lang ) {
				wp_safe_redirect( add_query_arg( 'eln_lang_exists', 1, self::reply_slot_url( $i ) ) );
				exit;
			}
		}

		$groups[] = self::empty_group( $lang );
		update_option(
			self::OPT_AUTOREPLY,
			array(
				'enabled' => empty( $cfg['enabled'] ) ? '0' : '1',
				'groups'  => $groups,
			),
			false
		);

		wp_safe_redirect( add_query_arg( 'eln_lang_added', 1, self::reply_slot_url( count( $groups ) - 1 ) ) );
		exit;
	}

	/** 自动确认：删除一个语言组（默认组不可删） */
	public static function handle_delete_reply_lang(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}
        // phpcs:ignore WordPress.Security.NonceVerification
		$slot = isset( $_GET['slot'] ) ? max( 0, (int) $_GET['slot'] ) : 0;
		check_admin_referer( 'eln_reply_lang_' . $slot );

		$cfg    = self::tpl( 'autoreply' );
		$groups = self::reply_groups( $cfg );

		if ( $slot > 0 && isset( $groups[ $slot ] ) ) {
			unset( $groups[ $slot ] );
			$groups = array_values( $groups );
			update_option(
				self::OPT_AUTOREPLY,
				array(
					'enabled' => empty( $cfg['enabled'] ) ? '0' : '1',
					'groups'  => $groups,
				),
				false
			);
		}

		wp_safe_redirect( add_query_arg( 'eln_lang_deleted', 1, self::reply_slot_url( 0 ) ) );
		exit;
	}
}
