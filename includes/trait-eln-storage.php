<?php
/**
 * ELN_Storage_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Storage_Trait {


	private static function store_inquiry( array $fields, string $body_html, string $eln_inquiry_no = '' ): int {
		$meta = array(
			'eln_inquiry_email'         => (string) ( $fields['email'] ?? '' ),
			'eln_inquiry_variant'       => (string) ( $fields['formVariant'] ?? '' ),
			'eln_inquiry_locale'        => (string) ( $fields['locale'] ?? '' ),
			'eln_inquiry_page_url'      => (string) ( $fields['pageUrl'] ?? '' ),
			'eln_inquiry_page_title'    => (string) ( $fields['pageTitle'] ?? '' ),
			'eln_inquiry_page_title_en' => (string) ( $fields['pageTitleEn'] ?? '' ),
			'eln_inquiry_ip'            => self::client_ip(),
			'eln_inquiry_lang'          => (string) ( $fields['browserLanguage'] ?? '' ),
			'eln_inquiry_timezone'      => self::field_timezone( $fields ),
			'eln_inquiry_no'            => $eln_inquiry_no,
			// 记录本条出现过哪些字段（供后台动态列出标签项），与是否传了标签无关
			'eln_inquiry_fields'        => array_values( array_diff( array_keys( $fields ), self::META_FIELDS ) ),
			'eln_inquiry_field_labels'  => (array) ( $fields['fieldLabels'] ?? array() ),
		);

		// 正文由 build_body() 生成（用户值入库前已 esc_html）。核心 kses 会剥掉 rgba() 颜色，
		// 这里只对本插件这一次写入挂一个定向过滤器，不再全局 kses_remove_filters()。
		$eln_preserve = static function ( $content ) use ( $body_html ) {
			return $body_html;
		};
		add_filter( 'content_save_pre', $eln_preserve, 100 );
		$post_id = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				// 标题只存姓名：列表「姓名」列直接展示，邮箱有独立列
				'post_title'     => (string) ( $fields['fullName'] ?? 'Unknown' ),
				'post_content'   => $body_html,
				'meta_input'     => $meta,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		remove_filter( 'content_save_pre', $eln_preserve, 100 );

		return is_wp_error( $post_id ) ? 0 : (int) $post_id;
	}

	/**
	 * 给早期记录补编号。
	 *
	 * eln_inquiry_no 是后加的 meta，之前入库的询盘没有它，列表「编号」列就一直显示 —。
	 * 按提交时间顺序补号，并把当天的流水计数器推到已用最大值，免得下一封询盘撞号。
	 * 只跑一次（option 记账），之后完全零开销。
	 */
	public static function backfill_numbers(): void {
		if ( get_option( self::OPT_BACKFILL ) === '1' ) {
			return;
		}
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = %s AND m.meta_id IS NULL
                 ORDER BY p.post_date ASC, p.ID ASC",
				'eln_inquiry_no',
				self::POST_TYPE
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration query.

		$used = array();
		foreach ( (array) $ids as $id ) {
			$id           = (int) $id;
			$day          = mysql2date( 'Ymd', (string) get_post_field( 'post_date', $id ) );
			$used[ $day ] = ( $used[ $day ] ?? 0 ) + 1;
			update_post_meta( $id, 'eln_inquiry_no', 'IN-' . $day . str_pad( (string) $used[ $day ], 3, '0', STR_PAD_LEFT ) );
		}

		// 补出来的号已经占用了当天前 N 个流水位，计数器必须跟上
		foreach ( $used as $day => $count ) {
			$key = 'eln_seq_' . $day;
			if ( (int) get_option( $key, 0 ) < $count ) {
				update_option( $key, $count, false );
			}
		}

		update_option( self::OPT_BACKFILL, '1', false );
	}

	/* ================================================================ 后台 */
}
