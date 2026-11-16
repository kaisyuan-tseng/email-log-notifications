<?php
/**
 * Plugin Name: Email Log & Notifications
 * Description: A companion to your SMTP plugin: sends the email notifications (inquiry notifications to the sales inbox, auto-confirmations back to the visitor in their own language) and keeps a log of every send with its delivery result.
 * Version:     2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      kaisyuan-tseng
 * Author URI:  https://profiles.wordpress.org/kaisyuan-tseng/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: email-log-notifications
 * Domain Path: /languages
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

define( 'ELN_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/trait-eln-core.php';
require_once __DIR__ . '/includes/trait-eln-i18n.php';
require_once __DIR__ . '/includes/trait-eln-manifest.php';
require_once __DIR__ . '/includes/trait-eln-rest.php';
require_once __DIR__ . '/includes/trait-eln-upload.php';
require_once __DIR__ . '/includes/trait-eln-rate-limiter.php';
require_once __DIR__ . '/includes/trait-eln-template.php';
require_once __DIR__ . '/includes/trait-eln-mailer.php';
require_once __DIR__ . '/includes/trait-eln-storage.php';
require_once __DIR__ . '/includes/trait-eln-cpt.php';
require_once __DIR__ . '/includes/trait-eln-cpt-actions.php';
require_once __DIR__ . '/includes/trait-eln-admin-view.php';
require_once __DIR__ . '/includes/trait-eln-admin-modal.php';
require_once __DIR__ . '/includes/trait-eln-dashboard.php';
require_once __DIR__ . '/includes/trait-eln-settings.php';
require_once __DIR__ . '/includes/trait-eln-settings-render.php';
require_once __DIR__ . '/includes/trait-eln-assets.php';
require_once __DIR__ . '/includes/trait-eln-endpoints.php';
require_once __DIR__ . '/includes/class-eln-migration.php';
require_once __DIR__ . '/includes/class-eln-compat.php';
require_once __DIR__ . '/includes/class-eln-privacy.php';
require_once __DIR__ . '/includes/class-email-log-notifications.php';

Email_Log_Notifications::init();
