<?php
/**
 * ELN_REST_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_REST_Trait {


	public static function register_routes(): void {
		$text = array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		);
		$args = array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle' ),
			// Public inquiry form: intentionally open. Honeypot and rate limits apply in handle().
			'permission_callback' => '__return_true',
			'args'                => array(
				'fullName'        => $text,
				'email'           => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_email',
				),
				'message'         => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'browserLanguage' => $text,
				'timezone'        => $text,
				'pageTitle'       => $text,
				'pageTitleEn'     => $text,
				'fieldLabels'     => array(
					'type'     => 'object',
					'required' => false,
				),
				self::HONEYPOT    => $text,
			),
		);

		register_rest_route( self::REST_NAMESPACE, '/inquiry', $args );

		// Deprecated namespace. Remove with ELN_Compat in 2.1.0.
		register_rest_route( Email_Log_Notifications::LEGACY_REST_NAMESPACE, '/inquiry', $args );
	}

	public static function handle( WP_REST_Request $request ) {
		// ---------- 蜜罐：非空即机器人，装作成功，什么都不发 ----------
		// 放在限流之前：蜜罐命中不该消耗真实访客的配额，也不入库、不发信。
		$params   = $request->get_params();
		$honeypot = isset( $params[ self::HONEYPOT ] ) ? trim( (string) $params[ self::HONEYPOT ] ) : '';
		if ( $honeypot !== '' ) {
			return new WP_REST_Response( array( 'sent' => true ), 200 );
		}

		// ---------- 限流：1 分钟 3 次 / 1 小时 10 次 ----------
		// 标识优先用链路里的可信地址；拿不到时用服务端签发的 HttpOnly 访客 cookie，
		// 避免把经同一出口访问的访客算成一个人。
		$retry_after = self::rate_consume( self::rate_identity() );
		if ( $retry_after > 0 ) {
			$response = new WP_REST_Response(
				array(
					'code'        => 'inquiry_rate_limited',
					'message'     => 'Too many submissions. Please try again later.',
					'retry_after' => $retry_after,
				),
				429
			);
			$response->header( 'Retry-After', (string) $retry_after );
			return $response;
		}

		// ---------- 字段收集 ----------
		$fields = array();
		foreach ( $params as $name => $value ) {
			if ( $name === self::HONEYPOT || ( is_array( $value ) && isset( $value['tmp_name'] ) ) ) {
				continue;
			}
			// 不能用 sanitize_key()：会把 fullName 转成 fullname，破坏字段契约
			$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );

			// 嵌套字段（如 fieldLabels[fullName]=姓名）逐个清洗
			if ( is_array( $value ) ) {
				$clean = array();
				foreach ( $value as $sub_key => $sub_value ) {
					if ( ! is_string( $sub_value ) ) {
						continue;
					}
					$sub_key   = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $sub_key );
					$sub_value = sanitize_text_field( wp_unslash( $sub_value ) );
					if ( $sub_key !== '' && $sub_value !== '' ) {
						$clean[ $sub_key ] = $sub_value;
					}
				}
				$value = $clean;
			} elseif ( is_string( $value ) ) {
				$value = sanitize_textarea_field( wp_unslash( $value ) );
			}

			if ( $name !== '' && $value !== '' && $value !== null && $value !== array() ) {
				$fields[ $name ] = $value;
			}
		}

		// 环境数据（浏览器语言 / 时区）先验一遍；前端自报的 ip 直接丢弃，改用服务端识别
		$fields = self::normalize_env_fields( $fields );

		// 文本长度上限（与前端 maxlength 一致）：超长截断，防止绕过前端的请求灌大文本
		$length_limits = (array) apply_filters(
			'eln_length_limits',
			array(
				'fullName'    => 80,
				'email'       => 254,
				'message'     => 2000,
				'application' => 100,
				'pageTitle'   => 200,
				'pageTitleEn' => 200,
			)
		);
		foreach ( $length_limits as $key => $max ) {
			if ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) && mb_strlen( $fields[ $key ] ) > $max ) {
				$fields[ $key ] = mb_substr( $fields[ $key ], 0, $max );
			}
		}

		/**
		 * 必填字段：默认 fullName / email / message（本站契约）。
		 * 其它前端可以整组换掉，插件本身不假设字段结构。
		 */
		$required_fields = (array) apply_filters( 'eln_required_fields', array( 'fullName', 'email', 'message' ) );
		foreach ( $required_fields as $required ) {
			if ( empty( $fields[ $required ] ) ) {
				return new WP_Error(
					'inquiry_missing_field',
					'Missing required field: ' . $required,
					array( 'status' => 400 )
				);
			}
		}
		if ( ! isset( $fields['email'] ) || ! is_email( $fields['email'] ) ) {
			return new WP_Error( 'inquiry_invalid_email', 'Invalid email address.', array( 'status' => 400 ) );
		}

		// ---------- 附件校验并落到临时目录 ----------
		try {
			$staged = self::stage_attachments( $request->get_file_params() ); // array( name, tmp_path, size )
		} catch ( RuntimeException $e ) {
			// 抛错时可能有文件已经挪进暂存目录（同批里前几个合法、后面一个非法），
			// 这里必须先清理再返回，否则临时目录会一直堆着没人认领的文件。
			self::cleanup_stage();
			return new WP_Error( 'inquiry_bad_upload', $e->getMessage(), array( 'status' => 400 ) );
		}

		// ---------- 组邮件 ----------
		$now            = time(); // 本次提交的时间戳：通知与自动确认共用，跨零点也不打架
		$to             = apply_filters( 'eln_recipient', get_option( 'admin_email' ) );
		$tpl            = self::tpl();
		$eln_inquiry_no = self::next_inquiry_no(); // 20261105001
		$subject        = self::build_subject( $fields, $tpl );
		$body           = self::build_body( $fields, $staged, null, $eln_inquiry_no, 'notify', $now );
		$headers        = array( 'Content-Type: text/html; charset=UTF-8' );

		// ---------- 留档入库（先入库，附件才好归到记录名下） ----------
		$record_id = self::store_inquiry( $fields, $body, $eln_inquiry_no );

		// ---------- 附件挪进留档目录（持久化；发信与后台下载都用这份） ----------
		$stored = $record_id ? self::persist_attachments( $staged, $record_id ) : $staged;
		if ( $record_id ) {
			update_post_meta( $record_id, 'eln_inquiry_attachments', $stored );
		}

		// ---------- 发信：先发通知，再回访客 ----------
		$sent  = self::mail( $to, $subject, $body, $headers, wp_list_pluck( $stored, 'path' ) );
		$error = self::$mail_error;

		if ( $record_id ) {
			update_post_meta( $record_id, 'eln_inquiry_mail_sent', $sent ? 'yes' : 'no' );
			if ( ! $sent && $error !== '' ) {
				// 失败原因留在记录上，后台详情的「发信」一行会显示出来
				update_post_meta( $record_id, 'eln_inquiry_mail_error', $error );
			}
		}

		// 自动确认与通知互不牵连：通知挂了也要让访客收到「已收到」，
		// 反之通知成功也不代表自动确认成功，两条状态各记各的。
		// 返回 null = 功能未启用，此时不写 meta，后台显示「—」而不是「失败」。
		$replied = self::send_autoreply( $fields, $eln_inquiry_no, $now );
		if ( $record_id && $replied !== null ) {
			update_post_meta( $record_id, 'eln_inquiry_autoreply_sent', $replied ? 'yes' : 'no' );
			if ( $replied === false && self::$mail_error !== '' ) {
				update_post_meta( $record_id, 'eln_inquiry_autoreply_error', self::$mail_error );
			}
		}

		// 清理暂存目录（文件已被挪走或发信完毕）；
		// 挪不动的文件（persist 失败退回暂存路径）要保留，否则存档里的附件指向空文件
		self::cleanup_stage( wp_list_pluck( $stored, 'path' ) );

		if ( ! $sent ) {
			return new WP_Error(
				'inquiry_mail_failed',
				'The message could not be delivered. Please email us directly.',
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/* ================================================================ 附件 */
}
