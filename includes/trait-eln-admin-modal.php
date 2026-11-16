<?php
/**
 * ELN_Admin_Modal_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Admin_Modal_Trait {


	/** 列表页打印弹窗骨架 + 样式 + 脚本（参考 WP「外观 → 主题详情」的覆盖层） */
	public static function print_list_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . self::POST_TYPE ) {
			return;
		}
		self::enqueue_admin_assets();
		?>
		<div id="eln-modal" class="eln-modal" role="dialog" aria-modal="true"
			aria-label="<?php echo esc_attr( self::t( 'modal_hint' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'eln_inquiry_detail' ) ); ?>"
			data-loading="<?php echo esc_attr( self::t( 'modal_loading' ) ); ?>"
			data-error="<?php echo esc_attr( self::t( 'modal_error' ) ); ?>"
			data-reply-to="<?php echo esc_attr( self::reply_tpl()['to'] ); ?>"
			data-reply-cc="<?php echo esc_attr( self::reply_tpl()['cc'] ); ?>"
			data-reply-bcc="<?php echo esc_attr( self::reply_tpl()['bcc'] ); ?>"
			data-reply-subject="<?php echo esc_attr( self::reply_tpl()['subject'] ); ?>"
			data-reply-body="<?php echo esc_attr( self::reply_tpl()['body'] ); ?>"
			data-confirm-delete="<?php echo esc_attr( self::t( 'confirm_delete' ) ); ?>">
			<div class="eln-modal__panel" data-elnver="r27">
			<div class="eln-modal__top">
				<div class="eln-modal__nav">
					<button type="button" class="eln-modal__arrow eln-modal__prev" aria-label="<?php echo esc_attr( self::t( 'modal_prev' ) ); ?>" disabled>
						<svg width="20" height="20" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
					</button>
					<button type="button" class="eln-modal__arrow eln-modal__next" aria-label="<?php echo esc_attr( self::t( 'modal_next' ) ); ?>" disabled>
						<svg width="20" height="20" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>
					</button>
				</div>
				<button type="button" class="eln-modal__close" aria-label="<?php echo esc_attr( self::t( 'modal_close' ) ); ?>">&times;</button>
			</div>
			<div class="eln-modal__wrap">
				<div class="eln-modal__main">
					<div class="eln-modal__stage"></div>
				</div>
				<aside class="eln-modal__side">
					<h2 class="eln-modal__title"></h2>
					<p class="eln-modal__no"></p>
					<dl class="eln-modal__fields">
						<dt><?php echo esc_html( self::t( 'col_email' ) ); ?></dt>
						<dd class="eln-modal__email"></dd>
						<dt><?php echo esc_html( self::t( 'col_source' ) ); ?></dt>
						<dd class="eln-modal__source"></dd>
						<dt><?php echo esc_html( self::t( 'col_attachments' ) ); ?></dt>
						<dd class="eln-modal__attach"></dd>
						<dt><?php echo esc_html( self::t( 'col_mail' ) ); ?></dt>
						<dd><span class="eln-modal__chip" hidden></span></dd>
						<dt><?php echo esc_html( self::t( 'detail_time' ) ); ?></dt>
						<dd class="eln-modal__date"></dd>
					</dl>
				</aside>
			</div>
			<div class="eln-modal__foot">
				<a class="button button-compact button-primary eln-modal__reply" hidden><?php echo esc_html( self::t( 'modal_reply' ) ); ?></a>
				<a class="button button-compact delete-theme eln-modal__delete" hidden><?php echo esc_html( self::t( 'modal_delete' ) ); ?></a>
			</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- 仪表盘 */
}
