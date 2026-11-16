<?php
/**
 * Plugin Name: Email Log & Notifications
 * Description: A companion to your SMTP plugin: sends the email notifications (inquiry notifications to the sales inbox, auto-confirmations back to the visitor in their own language) and keeps a log of every send with its delivery result.
 * Version:     1.3.0
 * Author:      kaisyuan-tseng
 *
 * 定位：
 *   SMTP 插件负责「怎么发出去」，本插件负责「发什么、发没发出去」——
 *     · Email notifications：新询盘通知销售、按访客语言自动确认回访客（两封都是事务邮件，
 *       主题与正文可在后台按语言分组编辑）；
 *     · Email log：每封询盘邮件留一条投递结果（成功 / 失败 + SMTP 报错原文），详情页可查。
 *   **不含 alerts**：不主动推告警/通知管理员，失败只记进日志。后续按需继续扩展。
 *
 * 设计取向：
 *   - 后台与邮件文案全部走内置三语字典（zh_CN / zh_TW / en，按 get_locale() 选择），
 *     可用 add_filter('eln_strings', fn($dict) => ...) 增补语种或改文案；
 *   - 字段展示名后台可改、可拖动排序；没配就回落到表单自己提交的标签；
 *   - 邮件里的站名、配色都写在模板源码里，插件不注入任何品牌元素。
 *
 * 端点：POST /wp-json/email-log-notifications/v1/inquiry
 *   - Content-Type: multipart/form-data
 *   - 必填：fullName / email / message；其余字段按名透传
 *   - 环境数据：browserLanguage / timezone 由前端采集（服务端校验后入库）；
 *     IP 一律由 WordPress 从 X-Real-IP / X-Forwarded-For / REMOTE_ADDR 识别，前端传了也丢弃
 *   - 附件：drawings[]（单个 ≤20MB）、pictures[]（单个 ≤10MB），扩展名白名单
 *   - 蜜罐字段 companyWebsite：非空即机器人，静默丢弃（不消耗限流配额）
 *   - 限流：同一可信请求标识 1 分钟 3 次 / 1 小时 10 次，超限 429 + Retry-After
 *
 * 附件留档：保存到 wp-content/uploads/inquiries/{年月}/{文章ID-随机}/
 *   （在 uploads 下可直接访问；随机目录名防猜测）；
 *   邮件附件来自留档路径（真实附件）；删除询盘记录时附件一并清理。
 *
 * 后台交互：标题列即「姓名」，点标题直接打开只读详情；来源列标注具体页面；
 *   无 Add New、无编辑/快速编辑、直连 edit 被拦；删除仅对有 delete_posts
 *   权限的用户（管理员）开放，删除时连同附件一起清掉。
 *
 * 邮件模板：两个标签页共用一套编辑器（源码 + 实时预览 + 测试发信）
 *   - 询盘通知  option eln_template（subject / html / labels）
 *   - 自动确认   option eln_autoreply（enabled + 语言组），默认关闭
 *     语言组按访客浏览器语言匹配：完整标签（zh-CN）→ 主语言（zh）→ 默认组（兜底）；
 *     每组各有 subject / html / labels，等价于「一种语言一份完整模板」；
 *     测试发信按当前语言组渲染，收件人固定为询盘收件人。
 *   **占位符只输出值，不带任何标签文案**——「时区：」这类字眼由模板源码自己写；
 *   品牌色不做占位符，默认模板里直接写 #04979e，想换色改源码即可。
 *
 * Filters：
 *   eln_recipient   收件人（默认 admin_email），同时作为自动确认的 Reply-To
 *   eln_max_files   单次提交附件总数（默认 20）
 *   eln_strings     语种字典增补
 *   eln_required_fields 必填字段（默认 fullName/email/message）
 *   eln_dashboard_limit 仪表盘「最近询盘」显示条数（默认 5）
 * Actions：
 *   eln_autoreply_sent($sent, $email, $fields) 自动确认发信后
 */

defined( 'ABSPATH' ) || exit;

final class Email_Log_Notifications {


	const VERSION               = '2.0.0';
	const TEXT_DOMAIN           = 'email-log-notifications';
	const REST_NAMESPACE        = 'email-log-notifications/v1';
	const LEGACY_REST_NAMESPACE = 'site-inquiries/v1';

	const POST_TYPE        = 'eln_inquiry';
	const LEGACY_POST_TYPE = 'inquiry';
	const VIEW_SLUG        = 'eln-view';
	const TPL_SLUG         = 'eln-template';
	const HONEYPOT         = 'companyWebsite';

	/** 邮件模板设置存在这个 option 里 */
	const OPT_TEMPLATE = 'eln_template';
	/** 自动确认模板（含开关 + 语言组） */
	const OPT_AUTOREPLY = 'eln_autoreply';
	/** 人工回信模板（mailto 的主题/正文骨架，弹窗「回信」按钮用） */
	const OPT_REPLY = 'eln_reply';
	/** 历史询盘补编号的一次性开关（eln_inquiry_no 是后加的 meta） */
	const OPT_BACKFILL = 'eln_numbers_backfilled';

	/** 迁移版本与标识符映射 */
	const OPT_DB_VERSION     = 'eln_db_version';
	const DB_VERSION         = '20000';
	const META_PREFIX        = 'eln_inquiry_';
	const LEGACY_META_PREFIX = 'inquiry_';
	const SEQ_PREFIX         = 'eln_seq_';
	const LEGACY_SEQ_PREFIX  = 'eln_seq_';
	const RATE_PREFIX        = 'eln_rl_';
	const LEGACY_RATE_PREFIX = 'eln_rl_';

	/** 自定义能力 */
	const CAP_VIEW   = 'eln_view_inquiries';
	const CAP_EDIT   = 'eln_edit_inquiries';
	const CAP_DELETE = 'eln_delete_inquiries';

	/**
	 * 邮件里唯一的品牌色（页头底色 / 页脚竖条 / 链接）。
	 * 不再做成 {{accent}} 占位符：模板源码里直接写这个色值就行，
	 * 想换色改默认源码或自己的模板即可，插件不掺和配色。
	 */
	const BRAND_COLOR = '#04979e';

	/** 附件总目录（在 uploads 下，可静态访问） */
	public const STORAGE_DIR = 'eln-inquiries';

	/** 1.x 遗留附件目录，仅迁移使用 */
	public const LEGACY_STORAGE_DIR = 'inquiries';

	/** 模板页用的 CodeMirror 设置（enqueue 时拿到，footer 打印脚本时用） */
	private static $code_editor_settings = null;

	/** 渲染邮件时的语言覆盖；null = 跟随 WordPress 当前语言 */
	private static $render_locale = null;

	/** 正在发本站的询盘邮件（phpmailer_init 里据此补纯文本正文） */
	private static $sending = false;

	/** 最近一次 wp_mail 失败的原因（wp_mail_failed 捕获，写进记录 meta 供后台排查） */
	private static $mail_error = '';

	use ELN_Core_Trait;
	use ELN_I18n_Trait;
	use ELN_Manifest_Trait;
	use ELN_REST_Trait;
	use ELN_Upload_Trait;
	use ELN_Rate_Limiter_Trait;
	use ELN_Template_Trait;
	use ELN_Mailer_Trait;
	use ELN_Storage_Trait;
	use ELN_CPT_Trait;
	use ELN_CPT_Actions_Trait;
	use ELN_Admin_View_Trait;
	use ELN_Admin_Modal_Trait;
	use ELN_Dashboard_Trait;
	use ELN_Settings_Trait;
	use ELN_Settings_Render_Trait;
	use ELN_Assets_Trait;
	use ELN_Endpoints_Trait;


	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		ELN_Privacy::init();
		add_action( 'admin_init', array( 'ELN_Migration', 'maybe_migrate' ) );
		add_action( 'admin_notices', array( 'ELN_Migration', 'admin_notice' ) );
		add_filter( 'map_meta_cap', array( 'ELN_Migration', 'map_meta_cap' ), 10, 4 );
		ELN_Compat::register_legacy_hooks();
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'purge_record_files' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'restore_status' ) );
		add_action( 'admin_post_eln_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_init', array( __CLASS__, 'backfill_numbers' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		// 编号列和日期一样可点表头排序（按 eln_inquiry_no 数字序）
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		// 主列 = 编号：行操作（查看/删除）挂在编号下面；客户、来源两列都是纯文本
		add_filter( 'list_table_primary_column', array( __CLASS__, 'primary_column' ), 10, 2 );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		// 列表页只保留「全部 / 回收站」两个视图，批量操作只留「移动至回收站」
		add_filter( 'views_edit-' . self::POST_TYPE, array( __CLASS__, 'admin_views' ) );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'admin_bulk_actions' ) );
		// 询盘没有「草稿/发布」概念，日期列不显示「已发布」
		add_filter( 'post_date_column_status', array( __CLASS__, 'hide_date_status' ), 10, 2 );
		// 列表上方的来源筛选
		add_action( 'restrict_manage_posts', array( __CLASS__, 'source_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_source_filter' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_list_styles' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'deleted_notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_view_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_template_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_template_setting' ) );
		add_action( 'admin_post_eln_test_mail', array( __CLASS__, 'handle_test_mail' ) );
		add_action( 'admin_post_eln_reply_addlang', array( __CLASS__, 'handle_add_reply_lang' ) );
		add_action( 'admin_post_eln_reply_dellang', array( __CLASS__, 'handle_delete_reply_lang' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_template_assets' ) );
		// 询盘列表：查看弹窗（AJAX 取正文）与弹窗骨架
		add_action( 'wp_ajax_eln_inquiry_detail', array( __CLASS__, 'ajax_inquiry_detail' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_list_modal' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_template_script' ) );
		// 仪表盘「最近询盘」小挂件
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );

		// 发信：补纯文本正文（只有 HTML 的邮件更容易被 Gmail 判垃圾），并记录失败原因
		add_action( 'phpmailer_init', array( __CLASS__, 'fill_alt_body' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
		add_action( 'wp_ajax_eln_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'load-post.php', array( __CLASS__, 'block_edit_screen' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'block_edit_screen' ) );
	}

	/* ================================================================== REST */
}
