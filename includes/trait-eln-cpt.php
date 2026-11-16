<?php
/**
 * ELN_CPT_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_CPT_Trait {


	/** 询盘留档 CPT：不公开、仅后台可见、UI 禁止新建（wp_insert_post 不查 caps） */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => self::t( 'menu_name' ),
					'singular_name'      => self::t( 'menu_name' ),
					'menu_name'          => self::t( 'menu_name' ),
					'all_items'          => self::t( 'all_items' ),
					'search_items'       => self::t( 'search_items' ),
					'not_found'          => self::t( 'not_found' ),
					'not_found_in_trash' => self::t( 'not_found_trash' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-email',
				'menu_position'       => 26,
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => array( 'eln_inquiry', 'eln_inquiries' ),
				'capabilities'        => array(
					'create_posts'           => 'do_not_allow',
					'edit_posts'             => Email_Log_Notifications::CAP_EDIT,
					'edit_others_posts'      => Email_Log_Notifications::CAP_EDIT,
					'publish_posts'          => Email_Log_Notifications::CAP_EDIT,
					'read'                   => Email_Log_Notifications::CAP_VIEW,
					'read_private_posts'     => Email_Log_Notifications::CAP_VIEW,
					'delete_posts'           => Email_Log_Notifications::CAP_DELETE,
					'delete_private_posts'   => Email_Log_Notifications::CAP_DELETE,
					'delete_published_posts' => Email_Log_Notifications::CAP_DELETE,
					'delete_others_posts'    => Email_Log_Notifications::CAP_DELETE,
				),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * 列表页状态视图：只留「全部」和「回收站」。
	 * 回收站为空时 WordPress 不会渲染那一项，这里补一条空壳链接，保证两个入口始终在。
	 */
	public static function admin_views( array $views ): array {
		$all   = $views['all'] ?? '';
		$trash = $views['trash'] ?? '';

		// WP 核心这一项的文案写死了 __('All')，CPT 的 all_items 标签套不上去，
		// 所以只会显示「全部」。这里只换文案：href、current 状态原样保留，
		// 计数统一成半角括号——a 标签里那对全/半角括号是跟着语种翻译走的，
		// 与下面手工补的回收站链接对不齐。
		if ( $all !== ''
			&& preg_match( '/^(<a\b[^>]*>).*?(<span class="count">)(.*?)(<\/span><\/a>)$/s', $all, $m )
		) {
			$count = preg_replace( '/[^\d,.]/', '', $m[3] );
			$all   = $m[1] . esc_html( self::t( 'all_items' ) ) . ' '
					. $m[2] . '(' . $count . ')' . $m[4];
		}

		if ( $trash === '' ) {
			$count = (int) wp_count_posts( self::POST_TYPE )->trash;
			$url   = admin_url( 'edit.php?post_status=trash&post_type=' . self::POST_TYPE );
			$trash = '<a href="' . esc_url( $url ) . '">' . esc_html( self::t( 'view_trash' ) )
					. ' <span class="count">(' . $count . ')</span></a>';
		}

		$out = array();
		if ( $all !== '' ) {
			$out['all'] = $all;
		}
		$out['trash'] = $trash;
		return $out;
	}

	/**
	 * 批量操作：列表页只留「移动至回收站」。
	 * 回收站视图里换成 WordPress 自带的一组（恢复 / 永久删除），否则回收站进去就出不来。
	 */
	public static function admin_bulk_actions( array $actions ): array {
        // phpcs:ignore WordPress.Security.NonceVerification
		$in_trash = isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash';

		if ( $in_trash ) {
			$keep = array();
			foreach ( array( 'untrash', 'delete' ) as $key ) {
				if ( isset( $actions[ $key ] ) ) {
					$keep[ $key ] = $actions[ $key ];
				}
			}
			return $keep ?: $actions;
		}

		return isset( $actions['trash'] ) ? array( 'trash' => $actions['trash'] ) : array();
	}

	/** 日期列不显示「已发布」：询盘是留档记录，没有发布状态可言 */
	public static function hide_date_status( string $status, WP_Post $post ): string {
		return $post->post_type === self::POST_TYPE ? '' : $status;
	}

	/** 列表上方的「来源」筛选下拉：选项来自已存档的英文页面标题 */
	public static function source_filter( string $post_type ): void {
		if ( $post_type !== self::POST_TYPE ) {
			return;
		}
		$values = self::source_values();
		if ( ! $values ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification
		$current = isset( $_GET['eln_inquiry_source'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eln_inquiry_source'] ) ) : '';

		echo '<select name="eln_inquiry_source" style="max-width:260px;" onchange="this.form.submit()">';
		echo '<option value="">' . esc_html( self::t( 'all_sources' ) ) . '</option>';
		foreach ( $values as $value ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $value, $current, false ),
				esc_html( $value )
			);
		}
		echo '</select>';
	}

	/** 已存档记录里出现过的来源（英文标题），按字母序 */
	private static function source_values(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'eln_inquiry_page_title_en' AND meta_value <> ''
             ORDER BY meta_value ASC"
		);
		return array_values( array_filter( array_map( 'trim', (array) $rows ), static fn( $v ) => $v !== '' ) );
	}

	/** 按来源过滤 + 编号列排序（只在询盘列表的主查询上生效） */
	public static function apply_source_filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== self::POST_TYPE ) {
			return;
		}

		// 编号排序：IN-YYYYMMDDXXX 前缀恒定、后缀定宽补零，字符串序即时间序。
		// （旧的纯数字编号也排在 IN- 之前，和历史先后一致；数值序对带前缀的串会全变 0，不能用）
        // phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['orderby'] ) && $_GET['orderby'] === 'eln_inquiry_no' ) {
			$order = isset( $_GET['order'] ) && strtoupper( sanitize_text_field( wp_unslash( (string) $_GET['order'] ) ) ) === 'ASC' ? 'ASC' : 'DESC';
			$query->set( 'meta_key', 'eln_inquiry_no' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', $order );
		}
        // phpcs:enable

        // phpcs:ignore WordPress.Security.NonceVerification
		$value = isset( $_GET['eln_inquiry_source'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eln_inquiry_source'] ) ) : '';
		if ( $value === '' ) {
			return;
		}
		$query->set(
			'meta_query',
			array(
				array(
					'key'     => 'eln_inquiry_page_title_en',
					'value'   => $value,
					'compare' => '=',
				),
			)
		);
	}

	/* 菜单交互完全交给 WP 原生：展开态内联展开、收起态核心自带悬停浮层（此前的自定义浮层 hack 已删） */

	/** 列表列宽：来源是「英文标题（表单类型）」，默认宽度会折成两行 */
	public static function admin_list_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . self::POST_TYPE ) {
			return;
		}
		self::enqueue_admin_assets();
	}

	/** 主列固定为「客户」：WP 在没有 title 列时会把第一列（编号）当主列，行操作就挂到编号下面了 */
	public static function primary_column( string $column, string $screen_id ): string {
		return $screen_id === 'edit-' . self::POST_TYPE ? 'eln_inquiry_no' : $column;
	}

	/** 列表列：编号（点击进详情）/ 姓名 / 邮箱 / 来源 / 附件 / 发信 / 日期 */
	/** 可排序的列表列：编号按 eln_inquiry_no 的字符串序（前缀恒定，等价于时间序），和日期列一样的点击体验 */
	public static function sortable_columns( array $columns ): array {
		$columns['eln_inquiry_no'] = 'eln_inquiry_no';
		return $columns;
	}

	public static function admin_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( $key === 'title' ) {
				// 用「姓名」列取代「标题」列：姓名即详情入口，不与邮箱列重复
				$new['eln_inquiry_no']          = self::t( 'col_no' );
				$new['eln_inquiry_name']        = self::t( 'col_name' );
				$new['eln_inquiry_email']       = self::t( 'col_email' );
				$new['eln_inquiry_source']      = self::t( 'col_source' );
				$new['eln_inquiry_attachments'] = self::t( 'col_attachments' );
				$new['eln_inquiry_mail']        = self::t( 'col_mail' );
			} else {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}

	public static function admin_column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'eln_inquiry_no':
				$no = (string) get_post_meta( $post_id, 'eln_inquiry_no', true );
				echo $no !== ''
					? '<span style="font-size:12px;color:#50575e;">' . esc_html( $no ) . '</span>'
					: '—';
				break;
			case 'eln_inquiry_name':
				echo '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';
				break;
			case 'eln_inquiry_email':
				$email = get_post_meta( $post_id, 'eln_inquiry_email', true );
				echo $email ? '<span style="color:#50575e;">' . esc_html( $email ) . '</span>' : '—';
				break;
			case 'eln_inquiry_source':
				echo esc_html( self::record_source_label( $post_id ) );
				break;
			case 'eln_inquiry_attachments':
				echo self::attachments_html( $post_id, ', ' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built and escaped by attachments_html().
				break;
			case 'eln_inquiry_mail':
				echo self::mail_status_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
		}
	}

	/**
	 * 发信状态：两行「圆点 + 名称」，每封邮件各一枚圆点。
	 *   已发送 = 绿点 / 发送失败 = 红点 / 未发送 = 灰描边空心
	 * 失败原因照旧放 title，悬停可查。
	 */
}
