<?php
/**
 * ELN_Admin_View_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Admin_View_Trait {

	public static function register_view_page(): void {
		$hook = add_submenu_page(
			null,
			self::t( 'view_page_title' ),
			self::t( 'view_page_title' ),
			Email_Log_Notifications::CAP_VIEW,
			self::VIEW_SLUG,
			array( __CLASS__, 'render_view_page' )
		);

		// 校验放在 load-* 阶段：此时后台页头还没输出，wp_die 才能给出真正的 403/404；
		// 等 render_view_page 里再 wp_die，状态码早已被页头固定成 200。
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'guard_view_page' ) );
		}
	}

	/* ---------------------------------------------------------------- 查看弹窗 */

	/** AJAX：取一条询盘的正文，给列表页的「查看」弹窗用 */
	public static function ajax_inquiry_detail(): void {
		check_ajax_referer( 'eln_inquiry_detail', 'nonce' );
		if ( ! current_user_can( Email_Log_Notifications::CAP_VIEW ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}
		if ( ! current_user_can( 'eln_edit_inquiry', $id ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$notify = (string) get_post_meta( $id, 'eln_inquiry_mail_sent', true );
		$reply  = (string) get_post_meta( $id, 'eln_inquiry_autoreply_sent', true );
		$sent   = (int) ( $notify === 'yes' ) + (int) ( $reply === 'yes' );
		$state  = $sent === 2 ? 'all' : ( $sent === 1 ? 'partial' : 'none' );

		wp_send_json_success(
			array(
				'no'     => (string) get_post_meta( $id, 'eln_inquiry_no', true ),
				'title'  => get_the_title( $post ),
				'date'   => get_the_date( 'Y-m-d H:i', $post ),
				'email'  => (string) get_post_meta( $id, 'eln_inquiry_email', true ),
				'source' => self::record_source_label( $id ),
				'attach' => self::attachments_html( $id ), // 附件直链（与列表「附件」列同源）
			// 弹窗「删除」按钮：与列表行操作同一端点、同一 nonce 口径；无权限时为 null。
			// wp_nonce_url 会把 & 转成 &#038; 实体（那是给 HTML 属性用的），这里要走 JSON，得先还原成裸 &
				'delete' => current_user_can( 'eln_delete_inquiry', $id )
					? htmlspecialchars_decode( wp_nonce_url( admin_url( 'admin-post.php?action=eln_delete&id=' . $id ), 'eln_del_' . $id ), ENT_QUOTES )
					: null,
				'state'  => $state, // all | partial | none
				'status' => self::t( 'status_' . $state ),
				// 正文是本插件 build_body() 生成的（用户值入库前已 esc_html），
				// 不走 wp_kses_post：它内部的 safecss_filter_attr 会剥掉 rgba() 颜色，
				// 页头白字会变成后台黑字，和真实邮件显示不一致。
				'html'   => $post->post_content,
			)
		);
	}

	/** 详情页入参校验（load-* 阶段执行，早于任何输出） */
	public static function guard_view_page(): void {
		if ( ! current_user_can( Email_Log_Notifications::CAP_VIEW ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}

		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'eln_edit_inquiry', $post_id ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}
	}

	/** 只读详情页：只留邮件正文；元信息表与正文重复，已去掉 */
	public static function render_view_page(): void {
		if ( ! current_user_can( Email_Log_Notifications::CAP_VIEW ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}

		$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			wp_die( esc_html( self::t( 'not_found_msg' ) ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( 'eln_edit_inquiry', $post_id ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}

		$delete_url = current_user_can( 'eln_delete_inquiry', $post_id )
			? wp_nonce_url( admin_url( 'admin-post.php?action=eln_delete&id=' . $post_id ), 'eln_del_' . $post_id )
			: '';
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( get_the_title( $post ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>" class="page-title-action"><?php echo esc_html( self::t( 'back' ) ); ?></a>
				<?php if ( $delete_url !== '' ) : ?>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="page-title-action" style="color:#b32d2e;"
						onclick="return confirm('<?php echo esc_js( self::t( 'confirm_delete' ) ); ?>');"><?php echo esc_html( self::t( 'delete_action' ) ); ?></a>
				<?php endif; ?>
			</h1>

			<h2 class="title"><?php echo esc_html( self::t( 'content_heading' ) ); ?></h2>
			<div style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:16px;">
				<?php
				// 正文由 build_body() 生成、用户值已转义；不过 kses 是为了保住 rgba() 颜色（见 ajax_inquiry_detail）。
				echo $post->post_content; // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</div>
		</div>
		<?php
	}

	/* ================================================================ 邮件模板设置 */
}
