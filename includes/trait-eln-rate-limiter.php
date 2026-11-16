<?php
/**
 * ELN_Rate_Limiter_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Rate_Limiter_Trait {

	/**
	 * Proxy IPs that are allowed to set X-Forwarded-For / X-Real-IP.
	 */
	private static function trusted_proxies(): array {
		$proxies = apply_filters( 'eln_trusted_proxies', array() );
		return is_array( $proxies ) ? array_values( array_map( 'strval', $proxies ) ) : array();
	}

	/**
	 * Resolve the chain IP without trusting arbitrary client headers.
	 */
	private static function chain_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		if ( ! in_array( $remote, self::trusted_proxies(), true ) ) {
			return $remote;
		}

		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$header = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			foreach ( explode( ',', $header ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return $remote;
	}

	/**
	 * Signed visitor cookie used only when no routable chain IP is available.
	 */
	private static function visitor_cookie_id(): string {
		$raw   = isset( $_COOKIE[ self::RATE_VISITOR_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::RATE_VISITOR_COOKIE ] ) ) : '';
		$salt  = wp_salt( 'auth' );
		$parts = explode( '.', $raw, 2 );

		if (
			2 === count( $parts )
			&& preg_match( '/^[a-f0-9]{32}$/', $parts[0] )
			&& hash_equals( hash_hmac( 'sha256', $parts[0], $salt ), $parts[1] )
		) {
			return $parts[0];
		}

		$vid       = bin2hex( random_bytes( 16 ) );
		$signature = hash_hmac( 'sha256', $vid, $salt );
		if ( ! headers_sent() ) {
			setcookie(
				self::RATE_VISITOR_COOKIE,
				$vid . '.' . $signature,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => '/',
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::RATE_VISITOR_COOKIE ] = $vid . '.' . $signature;
		return $vid;
	}

	private static function rate_identity(): string {
		$ip = self::chain_ip();
		if ( $ip !== '' && filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) ) {
			return 'ip_' . $ip;
		}
		return 'vid_' . self::visitor_cookie_id();
	}

	/**
	 * 固定窗口双限流：1 分钟 3 次 + 1 小时 10 次。
	 *
	 * transient 里存 ['start' => 窗口起点, 'count' => 次数]，是否过期按 start 判断，
	 * 不依赖每次 set_transient 被续写的 TTL（否则窗口会无限滑动、永不恢复）。
	 * 超限的请求本身不计数，否则被拒的请求会把窗口一直顶住。
	 * 返回 0 = 放行（已计数）；>0 = 还要等待的秒数。
	 */
	private static function rate_consume( string $identity ): int {
		$now     = time();
		$hash    = md5( $identity );
		$windows = array(
			'min'  => array(
				'ttl'   => self::RATE_MINUTE_TTL,
				'limit' => self::RATE_MINUTE_LIMIT,
			),
			'hour' => array(
				'ttl'   => self::RATE_HOUR_TTL,
				'limit' => self::RATE_HOUR_LIMIT,
			),
		);
		$states  = array();
		$retry   = 0;

		foreach ( $windows as $name => $cfg ) {
			$key   = 'eln_rl_' . $name . '_' . $hash;
			$state = get_transient( $key );
			if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] )
				|| $now - (int) $state['start'] >= $cfg['ttl'] ) {
				$state = array(
					'start' => $now,
					'count' => 0,
				);
			}
			$states[ $key ] = array(
				'state' => $state,
				'ttl'   => $cfg['ttl'],
			);
			if ( (int) $state['count'] >= $cfg['limit'] ) {
				$retry = max( $retry, $cfg['ttl'] - ( $now - (int) $state['start'] ) );
			}
		}

		if ( $retry > 0 ) {
			return max( 1, $retry );
		}

		foreach ( $states as $key => $item ) {
			$state          = $item['state'];
			$state['count'] = (int) $state['count'] + 1;
			set_transient( $key, $state, $item['ttl'] );
		}
		return 0;
	}

	/**
	 * 提交者 IP：**只认服务端识别**，前端上报的一律忽略。
	 * 是否采信代理头由可信代理配置决定，避免客户端伪造头污染日志。
	 * 限流标识另算（见 rate_identity()）：优先可信链路地址，回环时改用访客 cookie。
	 */
	private static function client_ip(): string {
		$ip = self::chain_ip();
		return $ip !== '' ? $ip : 'unknown';
	}

	/**
	 * 环境字段清洗（浏览器语言 / 时区）。
	 *
	 * 这两项由前端采集、可信度低于服务端，入库前必须验一遍：
	 * 语言按 BCP47 粗校验（en、zh-CN、zh-Hant-TW…），时区交给 PHP 的时区库；
	 * 不合格的整条丢掉 —— 邮件里宁可空着，也不要写进一个假的 Asia/Shanghia。
	 * 顺手丢掉前端自报的 `ip`：IP 一律以服务端识别为准（见 client_ip()）。
	 */
	private static function normalize_env_fields( array $fields ): array {
		if ( isset( $fields['browserLanguage'] ) ) {
			$lang                      = trim( (string) $fields['browserLanguage'] );
			$fields['browserLanguage'] = preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{1,8})*$/', $lang )
				? substr( $lang, 0, 35 )
				: '';
		}
		if ( isset( $fields['timezone'] ) ) {
			$tz                 = trim( (string) $fields['timezone'] );
			$fields['timezone'] = @timezone_open( $tz ) !== false ? substr( $tz, 0, 64 ) : '';
		}
		unset( $fields['ip'] );

		return array_filter(
			$fields,
			static function ( $value ) {
				return $value !== '' && $value !== null && $value !== array();
			}
		);
	}

	/** 访客时区（IANA 标识）：缺失或不合法时返回空串，模板里就不会多出一行空标签 */
	private static function field_timezone( array $fields ): string {
		$tz = trim( (string) ( $fields['timezone'] ?? '' ) );
		return ( $tz !== '' && @timezone_open( $tz ) !== false ) ? $tz : '';
	}

	/** 按访客时区渲染的当地时间；没有时区时返回空串 */
	private static function local_time_label( string $tz, string $format = 'Y-m-d H:i:s T', int $timestamp = 0 ): string {
		if ( $tz === '' ) {
			return '';
		}
		return (string) wp_date( $format, $timestamp > 0 ? $timestamp : time(), new DateTimeZone( $tz ) );
	}

	/* ================================================================ 邮件 */

	/** 邮件统一字体：中文优先 PingFang / 微软雅黑，西文回落到系统无衬线 */
	private static function font_stack(): string {
		return "font-family:'PingFang SC','Microsoft YaHei',sans-serif;";
	}

	/**
	 * 字段表样式：对齐站点 gooseneck-models 表格——标签列加粗深色、数值列次级灰，
	 * 只保留横向分隔线。邮件客户端只认内联样式，所以逐条写死。
	 */
	private static function th_style(): string {
		return 'text-align:left;vertical-align:top;padding:12px 16px 12px 0;'
			. 'border-bottom:1px solid #eaeaea;width:96px;font-size:13px;color:#222222;'
			. 'font-weight:600;line-height:1.6;' . self::font_stack();
	}

	private static function td_style(): string {
		return 'vertical-align:top;padding:12px 0;border-bottom:1px solid #eaeaea;'
			. 'font-size:14px;color:#555555;line-height:1.7;word-break:break-word;' . self::font_stack();
	}

	/* ================================================================ 邮件模板 */
}
