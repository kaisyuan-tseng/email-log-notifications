<?php
/**
 * ELN_CPT_Actions_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_CPT_Actions_Trait {

	private static function mail_status_html( int $post_id ): string {
		$notify  = (string) get_post_meta( $post_id, 'eln_inquiry_mail_sent', true );
		$reply   = (string) get_post_meta( $post_id, 'eln_inquiry_autoreply_sent', true );
		$n_error = (string) get_post_meta( $post_id, 'eln_inquiry_mail_error', true );
		$r_error = (string) get_post_meta( $post_id, 'eln_inquiry_autoreply_error', true );

		$sent = (int) ( $notify === 'yes' ) + (int) ( $reply === 'yes' );

		$word = static function ( string $state ): string {
			if ( $state === 'yes' ) {
				return self::t( 'status_yes' );
			}
			return $state === 'no' ? self::t( 'status_no' ) : self::t( 'status_none' );
		};

		if ( $sent === 2 ) {
			$summary = self::t( 'status_all_hint' );
			$short   = self::t( 'status_all' );
		} elseif ( $sent === 1 ) {
			$summary = $notify === 'yes' ? self::t( 'status_notify_only' ) : self::t( 'status_reply_only' );
			$short   = self::t( 'status_partial' );
		} else {
			$summary = self::t( 'status_none_hint' );
			$short   = self::t( 'status_none' );
		}

		$lines = array(
			self::t( 'label_notify' ) . '：' . $word( $notify ) . ( $n_error !== '' ? '（' . $n_error . '）' : '' ),
			self::t( 'label_reply' ) . '：' . $word( $reply ) . ( $r_error !== '' ? '（' . $r_error . '）' : '' ),
		);
		$title = $summary . "\n" . implode( "\n", $lines );

		// 扁平小圆点：已发送绿 / 失败红 / 未发送灰环（样式在 admin_list_styles 里）
		$item = static function ( string $label, string $state ): string {
			$cls = $state === 'yes' ? 'is-ok' : ( $state === 'no' ? 'is-fail' : 'is-none' );
			return '<span class="eln-mail-item"><i class="eln-mail-dot ' . $cls . '"></i>' . esc_html( $label ) . '</span>';
		};

		return '<span class="eln-mail-status" title="' . esc_attr( $title )
			. '" aria-label="' . esc_attr( $short ) . '">'
			. $item( self::t( 'label_notify' ), $notify )
			. $item( self::t( 'label_reply' ), $reply )
			. '</span>';
	}

	/** 单条记录的来源展示名：英文页面标题（表单类型），表单类型标注页面/弹窗 */
	private static function record_source_label( int $post_id ): string {
		$name = (string) get_post_meta( $post_id, 'eln_inquiry_page_title_en', true );
		if ( $name === '' ) {
			$name = (string) get_post_meta( $post_id, 'eln_inquiry_page_title', true );
		}
		$url     = (string) get_post_meta( $post_id, 'eln_inquiry_page_url', true );
		$variant = (string) get_post_meta( $post_id, 'eln_inquiry_variant', true );
		if ( $name === '' && $url !== '' ) {
			$path = parse_url( $url, PHP_URL_PATH );
			$name = $path && $path !== '/' ? trim( (string) $path, '/' ) : '';
		}
		$variant_label = $variant === 'modal' ? self::t( 'form_modal' )
			: ( $variant === 'page' ? self::t( 'form_page' ) : ( $variant !== '' ? $variant : '' ) );
		if ( $variant_label === '' ) {
			return $name !== '' ? $name : '—';
		}
		return $name !== '' ? $name . '（' . $variant_label . '）' : $variant_label;
	}

	/** 附件直链列表（新标签页浏览器打开；列表列与详情页共用） */
	private static function attachments_html( int $post_id, string $separator = '<br>' ): string {
		$files = (array) get_post_meta( $post_id, 'eln_inquiry_attachments', true );
		if ( ! $files ) {
			return '—';
		}
		$links = array();
		foreach ( array_values( $files ) as $i => $file ) {
			$url = (string) ( $file['url'] ?? '' );
			if ( $url === '' ) {
				continue;
			}
			$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="text-decoration:none;">'
				. esc_html( $file['name'] ?? ( 'file-' . $i ) ) . '</a>'
				. ' <span style="color:#8a979d;font-size:11px;">(' . esc_html( self::size_label( (int) ( $file['size'] ?? 0 ) ) ) . ')</span>';
		}
		return $links ? implode( $separator, $links ) : '—';
	}

	/** 行操作：查看 + 删除；回收站里换成「恢复 + 永久删除」，否则单条记录出不来 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( $post->post_type !== self::POST_TYPE ) {
			return $actions;
		}

		if ( $post->post_status === 'trash' ) {
			$actions = array(
				'untrash' => '<a href="' . esc_url(
					wp_nonce_url(
						admin_url( 'post.php?post=' . $post->ID . '&action=untrash' ),
						'untrash-post_' . $post->ID
					)
				) . '">' . esc_html( self::t( 'restore_action' ) ) . '</a>',
			);
			if ( current_user_can( 'eln_delete_inquiry', $post->ID ) ) {
				$permanent         = wp_nonce_url(
					admin_url( 'admin-post.php?action=eln_delete&id=' . $post->ID ),
					'eln_del_' . $post->ID
				);
				$actions['delete'] = '<a href="' . esc_url( $permanent ) . '" style="color:#b32d2e;" onclick="return confirm(\''
					. esc_js( self::t( 'confirm_delete' ) ) . '\');">' . esc_html( self::t( 'delete_action' ) ) . '</a>';
			}
			return $actions;
		}

		$actions = array(
			'view' => '<a class="eln-open" href="' . esc_url( admin_url( 'admin.php?page=' . self::VIEW_SLUG . '&id=' . $post->ID ) )
					. '" data-id="' . (int) $post->ID . '">' . esc_html( self::t( 'view_action' ) ) . '</a>',
		);
		if ( current_user_can( 'eln_delete_inquiry', $post->ID ) ) {
			$url               = wp_nonce_url(
				admin_url( 'admin-post.php?action=eln_delete&id=' . $post->ID ),
				'eln_del_' . $post->ID
			);
			$actions['delete'] = '<a href="' . esc_url( $url ) . '" style="color:#b32d2e;" onclick="return confirm(\''
				. esc_js( self::t( 'confirm_delete' ) ) . '\');">' . esc_html( self::t( 'delete_action' ) ) . '</a>';
		}
		return $actions;
	}

	/** 删除询盘（连带附件）：管理员专用，其余只读 */
	public static function handle_delete(): void {
		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'eln_del_' . $post_id );
		if ( ! $post_id || get_post_type( $post_id ) !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ) );
		}
		if ( ! current_user_can( 'eln_delete_inquiry', $post_id ) ) {
			wp_die( esc_html( self::t( 'invalid_request' ) ) );
		}
		wp_delete_post( $post_id, true ); // before_delete_post 钩子会联动清理附件
		wp_safe_redirect( add_query_arg( 'eln_deleted', 1, admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ) );
		exit;
	}

	/** 删除成功提示 */
	public static function deleted_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['eln_deleted'] ) && isset( $_GET['post_type'] ) && $_GET['post_type'] === self::POST_TYPE ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( self::t( 'deleted_notice' ) ) );
		}
	}

	/** 直连 edit / add-new 一律拦截；回收站相关的动作放行（恢复、移入回收站、永久删除） */
	public static function block_edit_screen(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification
		$type = isset( $_GET['post_type'] ) ? sanitize_key( (string) $_GET['post_type'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

		// post.php?action=untrash 是后台「已移到回收站，撤销」链接的落点，
		// 拦掉就没法撤销了；这几个动作都不涉及内容编辑
		if ( in_array( $action, array( 'trash', 'untrash', 'delete' ), true ) ) {
			return;
		}

		if ( $type === self::POST_TYPE || ( $post_id && get_post_type( $post_id ) === self::POST_TYPE ) ) {
			// 用 403 而不是 wp_die 默认的 500：这是"不允许"，不是服务端故障
			wp_die(
				esc_html( self::t( 'read_only' ) ),
				esc_html( self::t( 'menu_name' ) ),
				array( 'response' => 403 )
			);
		}
	}

	/** 从回收站恢复后回到「已留档」状态：询盘没有草稿概念，WordPress 默认会给 draft */
	public static function restore_status( int $post_id ): void {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		if ( get_post_status( $post_id ) === 'publish' ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * 只读详情页：注册成隐藏子页（parent 传 null）——
	 * 询盘菜单点击即全部询盘列表，不出现额外子菜单项。
	 */
}
