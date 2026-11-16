<?php
/**
 * ELN_Assets_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Assets_Trait {


	/** 静态资源版本号（与插件版本一致） */
	private const ASSET_VER = '2.0.0';

	/** 当前是否在「邮件模板」页 */
	private static function is_template_screen(): bool {
        // phpcs:disable WordPress.Security.NonceVerification
		$by_query = isset( $_GET['page'], $_GET['post_type'] )
			&& $_GET['page'] === self::TPL_SLUG
			&& $_GET['post_type'] === self::POST_TYPE;
        // phpcs:enable
		if ( $by_query ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}
		// 子菜单挂在询盘 CPT 下：screen id 形如 inquiry_page_inquiry-template
		// （同时兼容 edit-inquiry_page-inquiry-template 的写法）
		return $screen->id === self::POST_TYPE . '_page_' . self::TPL_SLUG
			|| $screen->id === 'edit-' . self::POST_TYPE . '_page_' . self::TPL_SLUG;
	}

	/** 静态资源 URL（以主插件文件为基准，不依赖外部常量） */
	private static function asset_url( string $file ): string {
		return plugins_url( $file, dirname( __DIR__ ) . '/email-log-notifications.php' );
	}

	/** 后台共享样式：询盘列表弹窗 + 仪表盘挂件 + 列表列宽 */
	public static function enqueue_admin_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}
		$is_list      = $screen->id === 'edit-' . self::POST_TYPE;
		$is_dashboard = $screen->id === 'dashboard';
		if ( ! $is_list && ! $is_dashboard ) {
			return;
		}
		wp_enqueue_style( 'eln-admin-css', self::asset_url( 'assets/admin.css' ), array(), self::ASSET_VER );
		if ( $is_list ) {
			wp_enqueue_script( 'eln-admin-js', self::asset_url( 'assets/admin.js' ), array( 'jquery' ), self::ASSET_VER, true );
		}
	}

	/** 模板页资源：媒体库 + WP 自带 CodeMirror + jQuery UI 排序 + 本页 CSS/JS */
	public static function enqueue_template_assets(): void {
		// 列表页 / 仪表盘的共享样式须在 admin_print_styles 之前入队
		self::enqueue_admin_assets();

		if ( ! self::is_template_screen() ) {
			return;
		}
		wp_enqueue_media();
		// 字段标签的顺序就是邮件里的行序，用 WP 自带的 sortable，不额外打包拖拽库
		wp_enqueue_script( 'jquery-ui-sortable' );
		$settings                   = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		self::$code_editor_settings = is_array( $settings ) ? $settings : null;

		wp_enqueue_style( 'eln-template-css', self::asset_url( 'assets/template.css' ), array(), self::ASSET_VER );
		wp_enqueue_script(
			'eln-template-js',
			self::asset_url( 'assets/template.js' ),
			array( 'jquery', 'jquery-ui-sortable' ),
			self::ASSET_VER,
			true
		);
	}

	/** 模板页脚本配置：在 footer 打印脚本之前注入 window.ElnTpl（兼容别名 SiteInqTpl 一并保留） */
	public static function print_template_script(): void {
		if ( ! self::is_template_screen() ) {
			return;
		}

		$config = array(
			'ajax'         => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'eln_preview' ),
			'codeSettings' => self::$code_editor_settings,
			'mediaTitle'   => self::t( 'tpl_media' ),
			// 两个标签页共用一个渲染器：option 名与 kind 都从 PHP 传，JS 不写死
			'name'         => self::option_name( self::current_kind() ),
			'kind'         => self::current_kind(),
		);

		$json = wp_json_encode(
			$config,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		// 仅前端变量别名，不涉及任何 option / 数据库键
		wp_add_inline_script(
			'eln-template-js',
			'window.ElnTpl=' . $json . ';window.SiteInqTpl=window.ElnTpl;',
			'before'
		);
	}
}
