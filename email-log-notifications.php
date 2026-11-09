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
 *   （在 uploads 下，主站 nginx 与 WP 两个域都能直接打开；随机目录名防猜测）；
 *   邮件附件来自留档路径（真实附件）；删除询盘记录时附件一并清理。
 *
 * 后台交互：标题列即「姓名」，点标题直接打开只读详情；来源列标注具体页面；
 *   无 Add New、无编辑/快速编辑、直连 edit 被拦；删除仅对有 delete_posts
 *   权限的用户（管理员）开放，删除时连同附件一起清掉。
 *
 * 邮件模板：两个标签页共用一套编辑器（源码 + 实时预览 + 测试发信 + 恢复默认）
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

defined('ABSPATH') || exit;

final class Email_Log_Notifications {

    const REST_NAMESPACE = 'email-log-notifications/v1';
    const POST_TYPE      = 'inquiry';
    const VIEW_SLUG      = 'inquiry-view';
    const TPL_SLUG       = 'inquiry-template';
    /** 回信模板设置页 slug */
    const REPLY_SLUG     = 'inquiry-reply';
    const HONEYPOT       = 'companyWebsite';
    /** 邮件模板设置存在这个 option 里 */
    const OPT_TEMPLATE   = 'eln_template';
    /** 自动确认模板（含开关 + 语言组） */
    const OPT_AUTOREPLY  = 'eln_autoreply';
    /** 人工回信模板（mailto 的主题/正文骨架，弹窗「回信」按钮用） */
    const OPT_REPLY      = 'eln_reply';
    /** 历史询盘补编号的一次性开关（inquiry_no 是后加的 meta） */
    const OPT_BACKFILL   = 'eln_numbers_backfilled';

    /**
     * 邮件里唯一的品牌色（页头底色 / 页脚竖条 / 链接）。
     * 不再做成 {{accent}} 占位符：模板源码里直接写这个色值就行，
     * 想换色改默认源码或自己的模板即可，插件不掺和配色。
     */
    const BRAND_COLOR    = '#04979e';

    /** 附件总目录（在 uploads 下，主站 nginx 直接静态输出） */
    private const STORAGE_DIR = 'inquiries';

    /** 模板页用的 CodeMirror 设置（enqueue 时拿到，footer 打印脚本时用） */
    private static $code_editor_settings = null;

    /** 渲染邮件时的语言覆盖；null = 跟随 WordPress 当前语言 */
    private static $render_locale = null;

    /** 正在发本站的询盘邮件（phpmailer_init 里据此补纯文本正文） */
    private static $sending = false;

    /** 最近一次 wp_mail 失败的原因（wp_mail_failed 捕获，写进记录 meta 供后台排查） */
    private static $mail_error = '';

    /** 统一成正斜杠：Windows 反斜杠路径过 update_post_meta 的 wp_unslash 会损坏（\0→NUL） */
    private static function norm(string $path): string {
        return str_replace('\\', '/', $path);
    }

    /** 上传目录信息（附件放在 uploads 下，主站 nginx 直接静态输出，浏览器可直开） */
    private static function uploads_dir(): array {
        static $u = null;
        if ($u === null) {
            $dir = wp_get_upload_dir();
            $u = array(
                'basedir' => self::norm($dir['basedir']),
                'baseurl' => rtrim(self::norm($dir['baseurl']), '/'),
                'webpath' => rtrim((string) parse_url($dir['baseurl'], PHP_URL_PATH), '/'),
            );
        }
        return $u;
    }

    /** 附件库根目录（规范化绝对路径） */
    private static function storage_base(): string {
        return self::uploads_dir()['basedir'] . '/' . self::STORAGE_DIR . '/';
    }

    /** 附件库相对 Web 路径前缀（相对站点根，主站 80 与 WP 8081 两个域都可用） */
    private static function storage_web_base(): string {
        return self::uploads_dir()['webpath'] . '/' . self::STORAGE_DIR . '/';
    }

    /** 每个附件分组的白名单：扩展名 + 单文件大小上限（字节） */
    private const FILE_GROUPS = array(
        'drawings' => array('max_size' => 20971520,
            'ext' => array('pdf', 'step', 'stp', 'dwg', 'dxf', 'igs', 'sldprt', 'sldasm', 'zip', 'rar', '7z')),
        'pictures' => array('max_size' => 10485760,
            'ext' => array('png', 'jpg', 'jpeg', 'webp')),
    );

    /**
     * 双窗口提交频率限制（同一可信请求标识）：
     * 1 分钟最多 3 次、1 小时最多 10 次。两个窗口都是固定窗口，按首次提交起算，
     * 到点自动清零；超限时返回 429 + Retry-After，期间不再放行。
     */
    const RATE_MINUTE_LIMIT = 3;
    const RATE_HOUR_LIMIT   = 10;
    const RATE_MINUTE_TTL   = 60;
    const RATE_HOUR_TTL     = 3600;
    /** 访客标识 cookie：服务端签发、HttpOnly，脚本读不到 */
    const RATE_VISITOR_COOKIE = 'eln_vid';

    /** 字段表展示顺序 */
    private const FIELD_ORDER = array(
        'fullName', 'email', 'message', 'application',
        'outerDiameter', 'innerDiameter', 'length', 'supportingWeight',
        'supportingDirection', 'material', 'finish', 'connections', 'quantity',
    );

    /** 进页脚元信息、不进正文字段表的键（环境数据与来源信息都在这里） */
    private const META_FIELDS = array(
        'formVariant', 'pageUrl', 'pageTitle', 'pageTitleEn', 'locale',
        'browserLanguage', 'timezone', 'fieldLabels', 'ip',
    );

    /**
     * 一条历史询盘都没有时，标签编辑器与邮件预览用这套字段占位。
     * 就是本站表单的完整字段集——预览要能一眼看全所有标签，不能只挑几个。
     */
    private const SAMPLE_FIELD_KEYS = array(
        'fullName', 'email', 'message', 'application', 'quantity',
        'outerDiameter', 'innerDiameter', 'length', 'supportingWeight',
        'supportingDirection', 'material', 'finish', 'connections',
    );

    /** 上面那套字段的示例值（预览与测试发信共用） */
    private const SAMPLE_FIELD_VALUES = array(
        'fullName'            => 'Jane Doe',
        'email'               => 'jane.doe@example.com',
        'message'             => "Hello,\nCould you quote 500 pcs of the 6mm gooseneck?\nThanks.",
        'application'         => 'Medical',
        'quantity'            => '500',
        'outerDiameter'       => '6',
        'innerDiameter'       => '4.5',
        'length'              => '300',
        'supportingWeight'    => '2',
        'supportingDirection' => 'Straight',
        'material'            => 'Stainless Steel + Stainless Steel',
        'finish'              => 'Nickel Plated',
        'connections'         => 'M8 Male Thread',
    );

    /**
     * 样例数据的单位（预览与测试发信要用）。
     * 真实提交里单位来自前端 `xxxUnit`，样例里必须逐个补齐——
     * 少一个，预览就少一行单位，看着像插件漏传。
     */
    private const SAMPLE_FIELD_UNITS = array(
        'quantity'         => 'PCS',
        'outerDiameter'    => 'MM',
        'innerDiameter'    => 'MM',
        'length'           => 'MM',
        'supportingWeight' => 'g',
    );

    /* ==================================================================
     * i18n：内置三语字典（en 为兜底），filter eln_strings 可增补
     * ================================================================== */

    private static function strings(?string $locale = null): array {
        static $cache = array(); // locale => 字典
        $locale = $locale ?? self::$render_locale ?? get_locale();
        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $en = array(
            'menu_name'        => 'Inquiries',
            'all_items'        => 'All Inquiries',
            'search_items'     => 'Search Inquiries',
            'not_found'        => 'No inquiries yet.',
            'not_found_trash'  => 'No inquiries in Trash.',
            'col_no'           => 'No.',
            'col_name'         => 'Contact',
            'col_email'        => 'Email',
            'col_source'       => 'Source',
            'col_attachments'  => 'Attachments',
            'col_mail'         => 'Email status',
            'status_none'             => 'Not sent',
            'status_partial'          => 'Partly sent',
            'status_all'              => 'All sent',
            'status_none_hint'        => 'Neither the inquiry notification nor the auto-confirmation was sent',
            'status_notify_only'      => 'Only the inquiry notification was sent',
            'status_reply_only'       => 'Only the auto-confirmation was sent',
            'status_all_hint'         => 'Both the inquiry notification and the auto-confirmation were sent',
            'status_yes'              => 'Sent',
            'status_no'               => 'Failed',
            'label_notify'            => 'Inquiry notification',
            'label_reply'             => 'Auto-confirmation',
            'view_trash'       => 'Trash',
            'all_sources'      => 'All sources',
            'tpl_menu'         => 'Email template',
            'dash_title'       => 'Recent inquiries',
            'dash_today_at'    => 'Today %s',
            'dash_yesterday_at' => 'Yesterday %s',
            'dash_today'       => '%d today',
            'dash_total'       => '%d in total',
            'dash_empty'       => 'No inquiries yet.',
            'dash_all'         => 'View all inquiries',
            'tpl_intro'        => 'The email template is one HTML source you edit directly — the preview on the right renders this exact code.',
            'tpl_subject'      => 'Subject',
            'tpl_subject_hint' => 'Tokens: {{name}} {{email}} — same braces as the body. Leave empty to use the default wording.',
            'tpl_html'         => 'Email HTML source',
            'tpl_html_hint'    => 'This is the complete email body — the header text lives here too: look for the <!-- 表头标题 --> comment and edit that line. {{...}} tokens are filled with inquiry data. Clear it and save to restore the default.',
            'tpl_tab_notify'   => 'Inquiry notification',
            'tpl_tab_reply'    => 'Auto-confirmation',
            'tpl_reply_on'     => 'Enable auto-confirmation',
            'tpl_reply_on_hint' => 'When on, the visitor immediately receives the auto-confirmation at the address they typed. Reply-To points at the inquiry inbox.',
            'tpl_lang_bar'     => 'Language',
            'tpl_lang_default' => 'Default group',
            'tpl_lang_code'    => 'Language',
            'tpl_lang_add'     => 'Add language group',
            'tpl_lang_delete'  => 'Delete this language group',
            'tpl_lang_delete_confirm' => 'Delete this language group and its template?',
            'tpl_lang_added'   => 'Language group added.',
            'tpl_lang_deleted' => 'Language group deleted.',
            'tpl_lang_exists'  => 'That language group already exists.',
            'tpl_lang_hint'    => 'Matched against the visitor’s browser language ({{lang}}): full tag first (zh-CN), then the primary language (zh). Anything unmatched uses the “Default group” — the one template with no language attached.',
            'tpl_reply_labels_hint' => 'These labels only affect {{rows}} in this language group. Leave empty to use the wording the form itself submitted.',
            'tpl_lang_default_hint' => 'Used when the visitor’s browser language matches no language group',
            'tpl_labels_sort'  => 'Drag to reorder',
            'tpl_labels_order_hint' => 'Drag the handle to reorder — the table rows in {{rows}} follow this order.',
            'tpl_legend_head'  => 'Values marked * come from the front end:',
            'tpl_legend_tail'  => 'Every row of {{rows}} also comes from the submitted form fields — new form fields show up here automatically.',
            'tpl_preview'      => 'Preview',
            'tpl_preview_hint' => 'Rendered live from the code on the left.',
            'tpl_reset'        => 'Restore defaults',
            'tpl_reset_done'   => 'Email template restored to defaults.',
            'tpl_test'         => 'Send test email',
            'tpl_test_hint'    => 'Renders the sample email and sends it to the inquiry inbox — never to a customer.',
            'tpl_test_ok'      => 'Test email sent.',
            'tpl_test_fail'    => 'Test email failed. Check WP Mail SMTP.',
            'tpl_sample_note'  => 'Sample data',
            'tpl_media'        => 'Insert media',
            'tpl_media_hint'   => 'Pick an image or video from the media library and insert it at the cursor.',
            'tpl_format'       => 'Format HTML',
            'tpl_placeholders' => 'Placeholders:',
            'tpl_labels'       => 'Field labels',
            'tpl_labels_hint'  => 'Labels come from the form that submitted the inquiry. Anything set here overrides them; leave empty to fall back to the field name itself.',
            'tpl_labels_sample' => 'These are sample fields for now; once the first inquiry arrives they switch to the real ones.',
            'view_action'      => 'View',
            'restore_action'   => 'Restore',
            'delete_action'    => 'Delete',
            'confirm_delete'   => 'Delete this inquiry and its attachments? This cannot be undone.',
            'deleted_notice'   => 'Inquiry deleted.',
            'invalid_request'  => 'Invalid request.',
            'not_found_msg'    => 'Inquiry not found.',
            'view_page_title'  => 'View Inquiry',
            'back'             => '← All Inquiries',
            'content_heading'  => 'Inquiry content',
            'modal_close'             => 'Close',
            'modal_loading'           => 'Loading…',
            'modal_error'             => 'Could not load this inquiry.',
            'modal_hint'              => 'Inquiry details',
            'modal_prev'              => 'Previous inquiry',
            'modal_next'              => 'Next inquiry',
            'modal_reply'             => 'Reply',
            'reply_menu'              => 'Reply Template',
            'reply_subject'           => 'Subject',
            'reply_body'              => 'Body',
            'reply_hint'              => 'Placeholders: {{name}} name, {{email}} email, {{no}} inquiry no., {{date}} submitted time, {{source}} source. Save empty to restore the defaults.',
            'detail_time'             => 'Submitted',
            'form_page'        => 'Page form',
            'form_modal'       => 'Popup form',
            'email_subject'    => 'New inquiry from %s',
            'row_attachments'  => 'Attachments',
            'read_only'        => 'Inquiries are read-only. Open them from the Inquiries list.',
            'email_subject_reply' => 'We received your inquiry',
            'fields'           => array(
                'fullName'            => 'Full Name',
                'email'               => 'Email',
                'message'             => 'Message',
                'application'         => 'Application',
                'quantity'            => 'Quantity',
                'material'            => 'Material',
                'finish'              => 'Finish',
                'connections'         => 'Connections',
                'outerDiameter'       => 'Outer Diameter',
                'innerDiameter'       => 'Inner Diameter',
                'length'              => 'Length',
                'supportingWeight'    => 'Supporting Weight',
                'supportingDirection' => 'Supporting Direction',
            ),
        );

        $zh = array(
            'menu_name'        => '询盘',
            'all_items'        => '所有询盘',
            'search_items'     => '搜索询盘',
            'not_found'        => '暂无询盘。',
            'not_found_trash'  => '回收站中没有询盘。',
            'col_no'           => '编号',
            'col_name'         => '联系人',
            'col_email'        => '邮箱',
            'col_source'       => '来源',
            'col_attachments'  => '附件',
            'col_mail'         => '发信状态',
            'status_none'             => '未发送',
            'status_partial'          => '部分发送',
            'status_all'              => '已全部发送',
            'status_none_hint'        => '询盘通知、自动确认均未发送',
            'status_notify_only'      => '仅发送询盘通知',
            'status_reply_only'       => '仅发送自动确认',
            'status_all_hint'         => '询盘通知、自动确认均已发送',
            'status_yes'              => '已发送',
            'status_no'               => '发送失败',
            'label_notify'            => '询盘通知',
            'label_reply'             => '自动确认',
            'view_trash'       => '回收站',
            'all_sources'      => '所有来源',
            'tpl_menu'         => '邮件模板',
            'dash_title'       => '最近询盘',
            'dash_today_at'    => '今天 %s',
            'dash_yesterday_at' => '昨天 %s',
            'dash_today'       => '今天 %d 条',
            'dash_total'       => '共 %d 条',
            'dash_empty'       => '暂无询盘。',
            'dash_all'         => '查看所有询盘',
            'tpl_intro'        => '邮件模板就是可直接编辑的 HTML 源码，右侧预览按它实时渲染。',
            'tpl_subject'      => '邮件主题',
            'tpl_subject_hint' => '可用变量：{{name}} {{email}}（与正文同一套花括号）；留空用默认文案。',
            'tpl_html'         => '邮件模板源码（HTML）',
            'tpl_html_hint'    => '这里就是邮件正文的完整源码；表头也在这里改——找到 <!-- 表头标题 --> 注释，直接改那一行文字。{{...}} 由询盘数据注入。清空并保存 = 恢复默认模板。',
            'tpl_tab_notify'   => '询盘通知',
            'tpl_tab_reply'    => '自动确认',
            'tpl_reply_on'     => '启用自动确认',
            'tpl_reply_on_hint' => '开启后，访客提交成功会立刻收到自动确认邮件（发到表单里填的邮箱，Reply-To 指向询盘收件人）。',
            'tpl_lang_bar'     => '编辑语言',
            'tpl_lang_default' => '默认组',
            'tpl_lang_code'    => '语言',
            'tpl_lang_add'     => '添加语言组',
            'tpl_lang_delete'  => '删除该语言组',
            'tpl_lang_delete_confirm' => '删除该语言组及其模板？',
            'tpl_lang_added'   => '语言组已添加。',
            'tpl_lang_deleted' => '语言组已删除。',
            'tpl_lang_exists'  => '该语言组已存在。',
            'tpl_lang_hint'    => '按访客的浏览器语言（{{lang}}）匹配：先比完整标签（zh-CN），再比主语言（zh）；都匹配不上就用「默认组」——它不分语言，是最后的兜底。',
            'tpl_reply_labels_hint' => '这里的标签只作用于当前语言组的 {{rows}}；留空则用前端表单提交时的措辞。',
            'tpl_lang_default_hint' => '访客的浏览器语言没匹配到任何语言组时，发这一份',
            'tpl_labels_sort'  => '拖动排序',
            'tpl_labels_order_hint' => '拖左侧手柄调整顺序——{{rows}} 的表格行就按这个顺序排。',
            'tpl_legend_head'  => '带 * 的值需要前端提交：',
            'tpl_legend_tail'  => '{{rows}} 的每一行也来自前端表单的字段（表单加了新字段，这里会自动多一行）。',
            'tpl_preview'      => '预览',
            'tpl_preview_hint' => '按左侧代码实时渲染。',
            'tpl_reset'        => '恢复默认',
            'tpl_reset_done'   => '邮件模板已恢复默认。',
            'tpl_test'         => '发送测试邮件',
            'tpl_test_hint'    => '按当前模板渲染一封示例邮件，发到询盘收件人邮箱，不会发给客户。',
            'tpl_test_ok'      => '测试邮件已发送。',
            'tpl_test_fail'    => '测试邮件发送失败，请检查 WP Mail SMTP。',
            'tpl_sample_note'  => '示例数据',
            'tpl_media'        => '插入媒体',
            'tpl_media_hint'   => '从媒体库选图片或视频，插入到光标处。',
            'tpl_format'       => '格式化 HTML',
            'tpl_placeholders' => '占位符：',
            'tpl_labels'       => '字段标签',
            'tpl_labels_hint'  => '默认用前端表单提交的字段标签；这里填了会覆盖它，留空则回退到字段名本身。',
            'tpl_labels_sample' => '当前列出的是示例字段；收到第一封询盘后会自动换成真实字段。',
            'view_action'      => '查看',
            'restore_action'   => '恢复',
            'delete_action'    => '删除',
            'confirm_delete'   => '确定删除这条询盘及其附件？此操作不可恢复。',
            'deleted_notice'   => '询盘已删除。',
            'invalid_request'  => '请求无效。',
            'not_found_msg'    => '询盘不存在。',
            'view_page_title'  => '查看询盘',
            'back'             => '← 返回所有询盘',
            'content_heading'  => '询盘内容',
            'modal_close'             => '关闭',
            'modal_loading'           => '加载中…',
            'modal_error'             => '加载失败，请重试。',
            'modal_hint'              => '询盘内容',
            'modal_prev'              => '上一条询盘',
            'modal_next'              => '下一条询盘',
            'modal_reply'             => '回信',
            'reply_menu'              => '回信模板',
            'reply_subject'           => '邮件主题',
            'reply_body'              => '邮件正文',
            'reply_hint'              => '可用占位符：{{name}} 姓名、{{email}} 邮箱、{{no}} 编号、{{date}} 提交时间、{{source}} 来源。全部留空保存即恢复默认。',
            'detail_time'             => '提交时间',
            'form_page'        => '页面表单',
            'form_modal'       => '弹窗表单',
            'email_subject'    => '收到新询盘：%s',
            'row_attachments'  => '附件',
            'read_only'        => '询盘为只读档案，请到后台「询盘」列表查看。',
            'email_subject_reply' => '已收到您的询盘',
            'fields'           => array(
                'fullName'            => '姓名',
                'email'               => '邮箱',
                'message'             => '询盘内容',
                'application'         => '应用场景',
                'quantity'            => '数量',
                'material'            => '材质',
                'finish'              => '表面处理',
                'connections'         => '连接方式',
                'outerDiameter'       => '外径',
                'innerDiameter'       => '内径',
                'length'              => '长度',
                'supportingWeight'    => '承重',
                'supportingDirection' => '出线方向',
            ),
        );

        $tw = array(
            'menu_name'        => '詢盤',
            'all_items'        => '所有詢盤',
            'search_items'     => '搜尋詢盤',
            'not_found'        => '尚無詢盤。',
            'not_found_trash'  => '回收站中沒有詢盤。',
            'col_no'           => '編號',
            'col_name'         => '聯絡人',
            'col_email'        => '郵箱',
            'col_source'       => '來源',
            'col_attachments'  => '附件',
            'col_mail'         => '發信狀態',
            'status_none'             => '未寄送',
            'status_partial'          => '部分寄送',
            'status_all'              => '已全部寄送',
            'status_none_hint'        => '詢盤通知、自動確認均未寄送',
            'status_notify_only'      => '僅寄送詢盤通知',
            'status_reply_only'       => '僅寄送自動確認',
            'status_all_hint'         => '詢盤通知、自動確認均已寄送',
            'status_yes'              => '已寄送',
            'status_no'               => '寄送失敗',
            'label_notify'            => '詢盤通知',
            'label_reply'             => '自動確認',
            'view_trash'       => '回收站',
            'all_sources'      => '所有來源',
            'tpl_menu'         => '郵件模板',
            'dash_title'       => '最近詢盤',
            'dash_today_at'    => '今天 %s',
            'dash_yesterday_at' => '昨天 %s',
            'dash_today'       => '今天 %d 則',
            'dash_total'       => '共 %d 則',
            'dash_empty'       => '尚無詢盤。',
            'dash_all'         => '檢視所有詢盤',
            'tpl_intro'        => '郵件模板就是可直接編輯的 HTML 原始碼，右側預覽依它即時渲染。',
            'tpl_subject'      => '郵件主旨',
            'tpl_subject_hint' => '可用變數：{{name}} {{email}}（與正文同一套花括號）；留空用預設文案。',
            'tpl_html'         => '郵件模板原始碼（HTML）',
            'tpl_html_hint'    => '這裡就是郵件正文的完整原始碼；頁首也在這裡改——找到 <!-- 表頭標題 --> 註解，直接改那一行文字。{{...}} 由詢盤資料注入。清空並儲存 = 恢復預設模板。',
            'tpl_tab_notify'   => '詢盤通知',
            'tpl_tab_reply'    => '自動確認',
            'tpl_reply_on'     => '啟用自動確認',
            'tpl_reply_on_hint' => '開啟後，訪客提交成功會立刻收到自動確認郵件（寄到表單裡填的信箱，Reply-To 指向詢盤收件人）。',
            'tpl_lang_bar'     => '編輯語言',
            'tpl_lang_default' => '預設組',
            'tpl_lang_code'    => '語言',
            'tpl_lang_add'     => '新增語言組',
            'tpl_lang_delete'  => '刪除該語言組',
            'tpl_lang_delete_confirm' => '刪除該語言組及其模板？',
            'tpl_lang_added'   => '語言組已新增。',
            'tpl_lang_deleted' => '語言組已刪除。',
            'tpl_lang_exists'  => '該語言組已存在。',
            'tpl_lang_hint'    => '依訪客瀏覽器語言（{{lang}}）比對：先比完整標籤（zh-CN），再比主要語言（zh）；都比對不上就用「預設組」——它不分語言，是最後的後備。',
            'tpl_reply_labels_hint' => '這裡的標籤只作用於目前語言組的 {{rows}}；留空則用前端表單送出的措辭。',
            'tpl_lang_default_hint' => '訪客的瀏覽器語言沒有比對到任何語言組時，寄這一份',
            'tpl_labels_sort'  => '拖曳排序',
            'tpl_labels_order_hint' => '拖左側把手調整順序——{{rows}} 的表格列就依這個順序排。',
            'tpl_legend_head'  => '帶 * 的值需要前端送出：',
            'tpl_legend_tail'  => '{{rows}} 的每一列也來自前端表單的欄位（表單加了新欄位，這裡會自動多一列）。',
            'tpl_preview'      => '預覽',
            'tpl_preview_hint' => '依左側原始碼即時渲染。',
            'tpl_reset'        => '恢復預設',
            'tpl_reset_done'   => '郵件模板已恢復預設。',
            'tpl_test'         => '發送測試郵件',
            'tpl_test_hint'    => '依目前模板渲染一封範例郵件，寄到詢盤收件信箱，不會寄給客戶。',
            'tpl_test_ok'      => '測試郵件已寄出。',
            'tpl_test_fail'    => '測試郵件寄送失敗，請檢查 WP Mail SMTP。',
            'tpl_sample_note'  => '範例資料',
            'tpl_media'        => '插入媒體',
            'tpl_media_hint'   => '從媒體庫選圖片或影片，插入到游標處。',
            'tpl_format'       => '格式化 HTML',
            'tpl_placeholders' => '佔位符：',
            'tpl_labels'       => '欄位標籤',
            'tpl_labels_hint'  => '預設用前端表單送出的欄位標籤；這裡填了會覆蓋它，留空則回退到欄位名本身。',
            'tpl_labels_sample' => '目前列出的是範例欄位；收到第一封詢盤後會自動換成真實欄位。',
            'view_action'      => '檢視',
            'restore_action'   => '還原',
            'delete_action'    => '刪除',
            'confirm_delete'   => '確定刪除這條詢盤及其附件？此操作不可恢復。',
            'deleted_notice'   => '詢盤已刪除。',
            'invalid_request'  => '請求無效。',
            'not_found_msg'    => '詢盤不存在。',
            'view_page_title'  => '檢視詢盤',
            'back'             => '← 返回所有詢盤',
            'content_heading'  => '詢盤內容',
            'modal_close'             => '關閉',
            'modal_loading'           => '載入中…',
            'modal_error'             => '載入失敗，請重試。',
            'modal_hint'              => '詢盤內容',
            'modal_prev'              => '上一條詢盤',
            'modal_next'              => '下一條詢盤',
            'modal_reply'             => '回覆',
            'reply_menu'              => '回覆範本',
            'reply_subject'           => '郵件主題',
            'reply_body'              => '郵件正文',
            'reply_hint'              => '可用佔位符：{{name}} 姓名、{{email}} 郵箱、{{no}} 編號、{{date}} 提交時間、{{source}} 來源。全部留空儲存即恢復預設。',
            'detail_time'             => '提交時間',
            'form_page'        => '頁面表單',
            'form_modal'       => '彈窗表單',
            'email_subject'    => '收到新詢盤：%s',
            'row_attachments'  => '附件',
            'read_only'        => '詢盤為唯讀檔案，請到後台「詢盤」列表查看。',
            'email_subject_reply' => '已收到您的詢盤',
            'fields'           => array(
                'fullName'            => '姓名',
                'email'               => '郵箱',
                'message'             => '詢盤內容',
                'application'         => '應用場景',
                'quantity'            => '數量',
                'material'            => '材質',
                'finish'              => '表面處理',
                'connections'         => '連接方式',
                'outerDiameter'       => '外徑',
                'innerDiameter'       => '內徑',
                'length'              => '長度',
                'supportingWeight'    => '承重',
                'supportingDirection' => '出線方向',
            ),
        );

        /**
         * 语种字典增补：可整语种替换或新增语种（如 ja）。
         * 字典结构：['en' => [...], 'zh_CN' => [...], 'ja' => [...]]，未命中回落 en。
         */
        $all = apply_filters('eln_strings', array(
            'en'    => $en,
            'zh_CN' => $zh,
            'zh_TW' => $tw,
        ));

        $dict = $all[$locale] ?? $all['en'] ?? $en;
        $cache[$locale] = $dict;
        return $dict;
    }

    private static function t(string $key, ?string $locale = null): string {
        return self::strings($locale)[$key] ?? '';
    }

    /**
     * 字段展示名：后台自定义标签 > 语种字典 > camelCase 自动切词。
     * @param array|null $labels 后台保存的覆盖表（预览时要传未保存的那份）
     */
    private static function field_label(string $key, ?array $labels = null, ?array $submitted = null): string {
        $labels = $labels ?? (array) (self::tpl()['labels'] ?? array());

        // 1) 后台覆盖（站点管理员说了算）
        $custom = trim((string) ($labels[$key] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        // 2) 前端提交时带来的标签（对接方自己的文案）
        $from_form = trim((string) (($submitted ?? array())[$key] ?? ''));
        if ($from_form !== '') {
            return $from_form;
        }

        // 3) 都没有：字段名本身转为可读文本，插件不假设任何字段叫法
        return self::readable_label($key);
    }

    /** 字段名的可读化兜底：fullName → Full Name */
    private static function readable_label(string $key): string {
        return ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $key));
    }

    /** 最近一封询盘带来的字段标签（前端自己的措辞），作编辑器占位与预览示例 */
    private static function latest_field_labels(): array {
        global $wpdb;
        static $labels = null;
        if ($labels === null) {
            $post_id = (int) $wpdb->get_var(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = 'inquiry_field_labels' AND meta_value <> 'a:0:{}'
                 ORDER BY meta_id DESC LIMIT 1"
            );
            $labels = $post_id ? (array) get_post_meta($post_id, 'inquiry_field_labels', true) : array();
        }
        return $labels;
    }

    /**
     * 可改的字段集合：字段名 => 默认标签（**顺序 = 后台排出来的行序**）。
     *
     * 来源依次为：历史询盘提交的 fieldLabels（含没填值的字段）、旧版 inquiry_fields、
     * 后台已保存的标签键。一条都没有才退回 SAMPLE_FIELD_KEYS 占位。
     * 标签编辑器、邮件预览、测试发信都读它，保证三处字段集永远一致。
     *
     * @param array|null $preferred 当前模板保存的 labels，其键序决定列表顺序（用户拖过就是它）
     */
    private static function label_fields(?array $preferred = null): array {
        global $wpdb;

        $preferred = $preferred ?? (array) (self::tpl('notify')['labels'] ?? array());
        $seen      = array();

        // fieldLabels 是「字段名 => 标签」的完整集合（含没填值的字段），
        // inquiry_fields 是旧版本只记非空字段的产物；两者都收，才能列全表单字段。
        $rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ('inquiry_field_labels', 'inquiry_fields')
             ORDER BY meta_id DESC LIMIT 400"
        );
        foreach ((array) $rows as $serialized) {
            foreach ((array) maybe_unserialize($serialized) as $key => $value) {
                $field_key = is_string($key) ? $key : (string) $value; // labels 用键，fields 用值
                // 单位是值的后缀（quantityUnit → "500 PCS"），在 build_rows 里并进数值，
                // 不单独成行，所以也不该出现在标签表里
                if ($field_key !== ''
                    && !in_array($field_key, self::META_FIELDS, true)
                    && !str_ends_with($field_key, 'Unit')
                ) {
                    $seen[$field_key] = true;
                }
            }
        }
        // 后台手工加过标签、但历史数据里从没出现过的字段也要留住
        foreach (array_keys($preferred) as $field_key) {
            $seen[$field_key] = true;
        }

        $sample = false;
        if (!$seen) {
            $sample = true;
            foreach (self::SAMPLE_FIELD_KEYS as $field_key) {
                $seen[$field_key] = true;
            }
        }

        $latest = self::latest_field_labels();
        $defaults = array();
        foreach (array_keys($seen) as $field_key) {
            $default = trim((string) ($latest[$field_key] ?? ''));
            $defaults[$field_key] = $default !== '' ? $default : self::readable_label($field_key);
        }

        // 1) 用户排过的顺序优先（后台拖动排序就存在这里）
        $ordered = array();
        foreach (array_keys($preferred) as $field_key) {
            if (isset($defaults[$field_key])) {
                $ordered[$field_key] = $defaults[$field_key];
                unset($defaults[$field_key]);
            }
        }
        // 2) 没排过的按 FIELD_ORDER，再按字段名
        foreach (self::FIELD_ORDER as $field_key) {
            if (isset($defaults[$field_key])) {
                $ordered[$field_key] = $defaults[$field_key];
                unset($defaults[$field_key]);
            }
        }
        ksort($defaults);

        return array('fields' => $ordered + $defaults, 'sample' => $sample);
    }

    /* ================================================================== 启动 */

    public static function init(): void {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('before_delete_post', array(__CLASS__, 'purge_record_files'));
        add_action('untrashed_post', array(__CLASS__, 'restore_status'));
        add_action('admin_post_inquiry_delete', array(__CLASS__, 'handle_delete'));
        add_action('admin_init', array(__CLASS__, 'backfill_numbers'));

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'admin_columns'));
        // 主列 = 编号：行操作（查看/删除）挂在编号下面；客户、来源两列都是纯文本
        add_filter('list_table_primary_column', array(__CLASS__, 'primary_column'), 10, 2);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'admin_column_content'), 10, 2);
        // 列表页只保留「全部 / 回收站」两个视图，批量操作只留「移动至回收站」
        add_filter('views_edit-' . self::POST_TYPE, array(__CLASS__, 'admin_views'));
        add_filter('bulk_actions-edit-' . self::POST_TYPE, array(__CLASS__, 'admin_bulk_actions'));
        // 询盘没有「草稿/发布」概念，日期列不显示「已发布」
        add_filter('post_date_column_status', array(__CLASS__, 'hide_date_status'), 10, 2);
        // 列表上方的来源筛选
        add_action('restrict_manage_posts', array(__CLASS__, 'source_filter'));
        add_action('pre_get_posts', array(__CLASS__, 'apply_source_filter'));
        add_action('admin_head', array(__CLASS__, 'admin_list_styles'));
        add_action('admin_head', array(__CLASS__, 'admin_menu_styles'));
        add_filter('post_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'deleted_notice'));
        add_action('admin_menu', array(__CLASS__, 'register_view_page'));
        add_action('admin_menu', array(__CLASS__, 'register_template_page'));
        add_action('admin_init', array(__CLASS__, 'register_template_setting'));
        add_action('admin_post_inquiry_test_mail', array(__CLASS__, 'handle_test_mail'));
        add_action('admin_post_inquiry_reset_template', array(__CLASS__, 'handle_reset_template'));
        add_action('admin_post_inquiry_reply_addlang', array(__CLASS__, 'handle_add_reply_lang'));
        add_action('admin_post_inquiry_reply_dellang', array(__CLASS__, 'handle_delete_reply_lang'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_template_assets'));
        // 询盘列表：查看弹窗（AJAX 取正文）与弹窗骨架
        add_action('wp_ajax_eln_inquiry_detail', array(__CLASS__, 'ajax_inquiry_detail'));
        add_action('admin_footer', array(__CLASS__, 'print_list_modal'));
        add_action('admin_print_footer_scripts', array(__CLASS__, 'print_template_script'));
        // 仪表盘「最近询盘」小挂件
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_dashboard_widget'));

        // 发信：补纯文本正文（只有 HTML 的邮件更容易被 Gmail 判垃圾），并记录失败原因
        add_action('phpmailer_init', array(__CLASS__, 'fill_alt_body'));
        add_action('wp_mail_failed', array(__CLASS__, 'capture_mail_error'));
        add_action('wp_ajax_inquiry_preview', array(__CLASS__, 'ajax_preview'));
        add_action('load-post.php', array(__CLASS__, 'block_edit_screen'));
        add_action('load-post-new.php', array(__CLASS__, 'block_edit_screen'));
    }

    /* ================================================================== REST */

    public static function register_routes(): void {
        $args = array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        );

        register_rest_route(self::REST_NAMESPACE, '/inquiry', $args);

        // 兼容旧命名空间：改名前的页面缓存 / 前端包里还写着 site-inquiries/v1
        register_rest_route('site-inquiries/v1', '/inquiry', $args);
    }

    public static function handle(WP_REST_Request $request) {
        // ---------- 蜜罐：非空即机器人，装作成功，什么都不发 ----------
        // 放在限流之前：蜜罐命中不该消耗真实访客的配额，也不入库、不发信。
        $honeypot = isset($_POST[self::HONEYPOT]) ? trim(wp_unslash($_POST[self::HONEYPOT])) : '';
        if ($honeypot !== '') {
            return new WP_REST_Response(array('sent' => true), 200);
        }

        // ---------- 限流：1 分钟 3 次 / 1 小时 10 次 ----------
        // 标识优先用链路里的公网 IP；frp 是 TCP 穿透，nginx 看到的源地址恒为回环，
        // 这时改用服务端签发的 HttpOnly 访客 cookie，避免把全站访客算成一个人。
        $retry_after = self::rate_consume(self::rate_identity());
        if ($retry_after > 0) {
            $response = new WP_REST_Response(array(
                'code'        => 'inquiry_rate_limited',
                'message'     => 'Too many submissions. Please try again later.',
                'retry_after' => $retry_after,
            ), 429);
            $response->header('Retry-After', (string) $retry_after);
            return $response;
        }

        // ---------- 字段收集 ----------
        $fields = array();
        foreach ($_POST as $name => $value) {
            if ($name === self::HONEYPOT) {
                continue;
            }
            // 不能用 sanitize_key()：会把 fullName 转成 fullname，破坏字段契约
            $name = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $name);

            // 嵌套字段（如 fieldLabels[fullName]=姓名）逐个清洗
            if (is_array($value)) {
                $clean = array();
                foreach ($value as $sub_key => $sub_value) {
                    if (!is_string($sub_value)) {
                        continue;
                    }
                    $sub_key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $sub_key);
                    $sub_value = sanitize_text_field(wp_unslash($sub_value));
                    if ($sub_key !== '' && $sub_value !== '') {
                        $clean[$sub_key] = $sub_value;
                    }
                }
                $value = $clean;
            } elseif (is_string($value)) {
                $value = sanitize_textarea_field(wp_unslash($value));
            }

            if ($name !== '' && $value !== '' && $value !== null && $value !== array()) {
                $fields[$name] = $value;
            }
        }

        // 环境数据（浏览器语言 / 时区）先验一遍；前端自报的 ip 直接丢弃，改用服务端识别
        $fields = self::normalize_env_fields($fields);

        // 文本长度上限（与前端 maxlength 一致）：超长截断，防止绕过前端的请求灌大文本
        $length_limits = (array) apply_filters('eln_length_limits', array(
            'fullName'    => 80,
            'email'       => 254,
            'message'     => 2000,
            'application' => 100,
            'pageTitle'   => 200,
            'pageTitleEn' => 200,
        ));
        foreach ($length_limits as $key => $max) {
            if (isset($fields[$key]) && is_string($fields[$key]) && mb_strlen($fields[$key]) > $max) {
                $fields[$key] = mb_substr($fields[$key], 0, $max);
            }
        }

        /**
         * 必填字段：默认 fullName / email / message（本站契约）。
         * 其它前端可以整组换掉，插件本身不假设字段结构。
         */
        $required_fields = (array) apply_filters('eln_required_fields', array('fullName', 'email', 'message'));
        foreach ($required_fields as $required) {
            if (empty($fields[$required])) {
                return new WP_Error('inquiry_missing_field',
                    'Missing required field: ' . $required, array('status' => 400));
            }
        }
        if (!is_email($fields['email'])) {
            return new WP_Error('inquiry_invalid_email', 'Invalid email address.', array('status' => 400));
        }

        // ---------- 附件校验并落到临时目录 ----------
        try {
            $staged = self::stage_attachments(); // array( name, tmp_path, size )
        } catch (RuntimeException $e) {
            // 抛错时可能有文件已经挪进暂存目录（同批里前几个合法、后面一个非法），
            // 这里必须先清理再返回，否则临时目录会一直堆着没人认领的文件。
            self::cleanup_stage();
            return new WP_Error('inquiry_bad_upload', $e->getMessage(), array('status' => 400));
        }

        // ---------- 组邮件 ----------
        $now      = time(); // 本次提交的时间戳：通知与自动确认共用，跨零点也不打架
        $to       = apply_filters('eln_recipient', get_option('admin_email'));
        $tpl      = self::tpl();
        $inquiry_no = self::next_inquiry_no(); // 20261105001
        $subject  = self::build_subject($fields, $tpl);
        $body     = self::build_body($fields, $staged, null, $inquiry_no, 'notify', $now);
        $headers  = array('Content-Type: text/html; charset=UTF-8');

        // ---------- 留档入库（先入库，附件才好归到记录名下） ----------
        $record_id = self::store_inquiry($fields, $body, $inquiry_no);

        // ---------- 附件挪进留档目录（持久化；发信与后台下载都用这份） ----------
        $stored = $record_id ? self::persist_attachments($staged, $record_id) : $staged;
        if ($record_id) {
            update_post_meta($record_id, 'inquiry_attachments', $stored);
        }

        // ---------- 发信：先发通知，再回访客 ----------
        $sent  = self::mail($to, $subject, $body, $headers, wp_list_pluck($stored, 'path'));
        $error = self::$mail_error;

        if ($record_id) {
            update_post_meta($record_id, 'inquiry_mail_sent', $sent ? 'yes' : 'no');
            if (!$sent && $error !== '') {
                // 失败原因留在记录上，后台详情的「发信」一行会显示出来
                update_post_meta($record_id, 'inquiry_mail_error', $error);
            }
        }

        // 自动确认与通知互不牵连：通知挂了也要让访客收到「已收到」，
        // 反之通知成功也不代表自动确认成功，两条状态各记各的。
        // 返回 null = 功能未启用，此时不写 meta，后台显示「—」而不是「失败」。
        $replied = self::send_autoreply($fields, $inquiry_no, $now);
        if ($record_id && $replied !== null) {
            update_post_meta($record_id, 'inquiry_autoreply_sent', $replied ? 'yes' : 'no');
            if ($replied === false && self::$mail_error !== '') {
                update_post_meta($record_id, 'inquiry_autoreply_error', self::$mail_error);
            }
        }

        // 清理暂存目录（文件已被挪走或发信完毕）；
        // 挪不动的文件（persist 失败退回暂存路径）要保留，否则存档里的附件指向空文件
        self::cleanup_stage(wp_list_pluck($stored, 'path'));

        if (!$sent) {
            return new WP_Error('inquiry_mail_failed',
                'The message could not be delivered. Please email us directly.',
                array('status' => 500));
        }

        return new WP_REST_Response(array('sent' => true), 200);
    }

    /* ================================================================ 附件 */

    /** 校验并把上传文件挪进本次提交的暂存目录，返回 array( name, tmp_path, size ) */
    private static function stage_attachments(): array {
        $staged = array();
        if (empty($_FILES)) {
            return $staged;
        }

        $stage_dir = self::stage_dir();
        if (!wp_mkdir_p($stage_dir)) {
            throw new RuntimeException('Could not create temp directory.');
        }

        $max_files = (int) apply_filters('eln_max_files', 20);

        foreach (self::FILE_GROUPS as $group => $spec) {
            if (empty($_FILES[$group])) {
                continue;
            }
            // 前端用 "group[]" 提交；单文件时 PHP 给的是标量，统一成数组
            $names  = (array) $_FILES[$group]['name'];
            $tmp    = (array) $_FILES[$group]['tmp_name'];
            $sizes  = (array) $_FILES[$group]['size'];
            $errors = (array) $_FILES[$group]['error'];

            foreach ($names as $i => $original) {
                $error_code = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
                if ($error_code === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error_code !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload failed (code ' . $error_code . ').');
                }
                if (count($staged) >= $max_files) {
                    throw new RuntimeException('Too many files in one submission.');
                }
                $size = (int) ($sizes[$i] ?? 0);
                $ext  = strtolower(pathinfo((string) $original, PATHINFO_EXTENSION));

                if (!in_array($ext, $spec['ext'], true)) {
                    throw new RuntimeException(sprintf('File type .%s is not allowed.', $ext));
                }
                if ($size <= 0) {
                    throw new RuntimeException(sprintf('%s is empty.', $original));
                }
                if ($size > $spec['max_size']) {
                    throw new RuntimeException(sprintf('%s exceeds the %dMB limit.', $original, $spec['max_size'] / 1048576));
                }

                $safe_name = wp_unique_filename($stage_dir, sanitize_file_name((string) $original));
                $dest      = $stage_dir . '/' . $safe_name;
                if (!move_uploaded_file((string) ($tmp[$i] ?? ''), $dest)) {
                    throw new RuntimeException('Could not store uploaded file.');
                }
                $staged[] = array('name' => $safe_name, 'tmp_path' => self::norm($dest), 'size' => $size);
            }
        }
        return $staged;
    }

    /** 本次提交的暂存目录（进程内固定，方便统一清理） */
    private static function stage_dir(): string {
        static $dir = null;
        if ($dir === null) {
            $dir = self::norm(get_temp_dir()) . 'site-inq-' . uniqid();
        }
        return $dir;
    }

    /** 附件持久化：uploads/inquiries/{Ym}/{文章ID-随机}/，返回留档后的文件数组 */
    private static function persist_attachments(array $staged, int $record_id): array {
        if (!$staged) {
            return array();
        }
        $dir = self::record_dir($record_id);
        if (!wp_mkdir_p($dir)) {
            return $staged; // 挪不动就退回暂存路径，至少邮件还能带附件
        }
        $stored = array();
        foreach ($staged as $file) {
            $dest = $dir . '/' . $file['name'];
            if (!@rename($file['tmp_path'], $dest)) {
                $dest = $file['tmp_path']; // 失败退回暂存路径
            }
            // 相对 Web 路径：主站 80 与 WP 8081 两个域都能直接打开
            $web_path = str_starts_with(self::norm($dest), self::storage_base())
                ? self::storage_web_base() . substr(self::norm($dest), strlen(self::storage_base()))
                : '';
            $stored[] = array(
                'name' => $file['name'],
                'path' => self::norm($dest),
                'url'  => $web_path,
                'size' => $file['size'],
            );
        }
        return $stored;
    }

    /** 某条询盘的附件目录（随机后缀，避免可猜测的直链） */
    private static function record_dir(int $record_id): string {
        static $suffixes = array();
        if (!isset($suffixes[$record_id])) {
            $suffixes[$record_id] = uniqid();
        }
        return self::storage_base() . gmdate('Ym') . '/' . $record_id . '-' . $suffixes[$record_id];
    }

    /** 删除询盘记录时，连同附件目录一起清理 */
    public static function purge_record_files(int $post_id): void {
        if (get_post_type($post_id) !== self::POST_TYPE) {
            return;
        }
        $files = (array) get_post_meta($post_id, 'inquiry_attachments', true);
        $dirs  = array();
        foreach ($files as $file) {
            $path = self::norm((string) ($file['path'] ?? ''));
            if ($path !== '' && str_starts_with($path, self::storage_base())) {
                wp_delete_file($path);
                $dirs[dirname($path)] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir); // 清空了才删得掉；残留留待人工
            }
        }
    }

    /**
     * 清空本次提交的暂存目录。
     * @param array $keep 仍被引用的文件路径（挪进留档目录失败时退回暂存路径的），不删
     */
    private static function cleanup_stage(array $keep = array()): void {
        $dir = self::stage_dir();
        if (!is_dir($dir)) {
            return;
        }
        $keep = array_map(array(__CLASS__, 'norm'), $keep);
        foreach (glob($dir . '/*') ?: array() as $f) {
            if (in_array(self::norm($f), $keep, true)) {
                continue;
            }
            wp_delete_file($f);
        }
        @rmdir($dir); // 还有保留文件时删不掉，留着给下次或人工处理
    }

    /**
     * 限流用的请求标识（与邮件里的 {{ip}} 分开：那边如实记录服务端看到的地址）。
     *
     *   1. 先扫 X-Forwarded-For / X-Real-IP / REMOTE_ADDR，取第一个公网 IP；
     *      将来 frp 换成 http 代理或启用 PROXY protocol 后会自动按真实 IP 分桶。
     *   2. 本机反代 / TCP 穿透下全是回环或私网地址，退化成服务端签发的
     *      HttpOnly cookie（前端脚本读不到也改不了），每个访客一个桶。
     *
     * 注意：cookie 是"区分访客"用的，不是身份凭证；丢弃 cookie 的客户端
     * 会拿到新标识，所以要拦住无状态刷接口还得靠链路层拿到真实 IP。
     */
    private static function rate_identity(): string {
        foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            foreach (explode(',', (string) $_SERVER[$key]) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return 'ip_' . $candidate;
                }
            }
        }

        $vid = isset($_COOKIE[self::RATE_VISITOR_COOKIE]) ? (string) $_COOKIE[self::RATE_VISITOR_COOKIE] : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
            $vid = bin2hex(random_bytes(16));
            if (!headers_sent()) {
                setcookie(self::RATE_VISITOR_COOKIE, $vid, array(
                    'expires'  => time() + DAY_IN_SECONDS,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ));
            }
            $_COOKIE[self::RATE_VISITOR_COOKIE] = $vid;
        }
        return 'vid_' . $vid;
    }

    /**
     * 固定窗口双限流：1 分钟 3 次 + 1 小时 10 次。
     *
     * transient 里存 ['start' => 窗口起点, 'count' => 次数]，是否过期按 start 判断，
     * 不依赖每次 set_transient 被续写的 TTL（否则窗口会无限滑动、永不恢复）。
     * 超限的请求本身不计数，否则被拒的请求会把窗口一直顶住。
     * 返回 0 = 放行（已计数）；>0 = 还要等待的秒数。
     */
    private static function rate_consume(string $identity): int {
        $now     = time();
        $hash    = md5($identity);
        $windows = array(
            'min'  => array('ttl' => self::RATE_MINUTE_TTL, 'limit' => self::RATE_MINUTE_LIMIT),
            'hour' => array('ttl' => self::RATE_HOUR_TTL,   'limit' => self::RATE_HOUR_LIMIT),
        );
        $states = array();
        $retry  = 0;

        foreach ($windows as $name => $cfg) {
            $key   = 'inquiry_rl_' . $name . '_' . $hash;
            $state = get_transient($key);
            if (!is_array($state) || !isset($state['start'], $state['count'])
                || $now - (int) $state['start'] >= $cfg['ttl']) {
                $state = array('start' => $now, 'count' => 0);
            }
            $states[$key] = array('state' => $state, 'ttl' => $cfg['ttl']);
            if ((int) $state['count'] >= $cfg['limit']) {
                $retry = max($retry, $cfg['ttl'] - ($now - (int) $state['start']));
            }
        }

        if ($retry > 0) {
            return max(1, $retry);
        }

        foreach ($states as $key => $item) {
            $state = $item['state'];
            $state['count'] = (int) $state['count'] + 1;
            set_transient($key, $state, $item['ttl']);
        }
        return 0;
    }

    /**
     * 提交者 IP：**只认服务端识别**（X-Real-IP / X-Forwarded-For / REMOTE_ADDR），
     * 前端上报的一律忽略——同源 nginx 反代已经带上真实来源，前端传的只是自证，
     * 既不可信也没有额外价值（穿透场景下两边看到的都是回环地址）。
     * 限流标识另算（见 rate_identity()）：优先真实公网 IP，回环时改用访客 cookie。
     */
    private static function client_ip(): string {
        foreach (array('HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key])) {
                $candidate = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return 'unknown';
    }

    /**
     * 环境字段清洗（浏览器语言 / 时区）。
     *
     * 这两项由前端采集、可信度低于服务端，入库前必须验一遍：
     * 语言按 BCP47 粗校验（en、zh-CN、zh-Hant-TW…），时区交给 PHP 的时区库；
     * 不合格的整条丢掉 —— 邮件里宁可空着，也不要写进一个假的 Asia/Shanghia。
     * 顺手丢掉前端自报的 `ip`：IP 一律以服务端识别为准（见 client_ip()）。
     */
    private static function normalize_env_fields(array $fields): array {
        if (isset($fields['browserLanguage'])) {
            $lang = trim((string) $fields['browserLanguage']);
            $fields['browserLanguage'] = preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{1,8})*$/', $lang)
                ? substr($lang, 0, 35)
                : '';
        }
        if (isset($fields['timezone'])) {
            $tz = trim((string) $fields['timezone']);
            $fields['timezone'] = @timezone_open($tz) !== false ? substr($tz, 0, 64) : '';
        }
        unset($fields['ip']);

        return array_filter($fields, static function ($value) {
            return $value !== '' && $value !== null && $value !== array();
        });
    }

    /** 访客时区（IANA 标识）：缺失或不合法时返回空串，模板里就不会多出一行空标签 */
    private static function field_timezone(array $fields): string {
        $tz = trim((string) ($fields['timezone'] ?? ''));
        return ($tz !== '' && @timezone_open($tz) !== false) ? $tz : '';
    }

    /** 按访客时区渲染的当地时间；没有时区时返回空串 */
    private static function local_time_label(string $tz, string $format = 'Y-m-d H:i:s T', int $timestamp = 0): string {
        if ($tz === '') {
            return '';
        }
        return (string) wp_date($format, $timestamp > 0 ? $timestamp : time(), new DateTimeZone($tz));
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

    /** 两种模板：发给销售的通知、发给访客的自动确认 */
    private static function option_name(string $kind = 'notify'): string {
        return $kind === 'autoreply' ? self::OPT_AUTOREPLY : self::OPT_TEMPLATE;
    }

    /** 模板默认值：后台只保存这几项，其余全部体现在 HTML 源码里 */
    private static function template_defaults(string $kind = 'notify'): array {
        $defaults = array(
            'subject' => '', // 空 = 语言字典的主题格式
            'html'    => '', // 空 = 使用默认模板源码
            'labels'  => array(), // 字段标签覆盖：字段名 => 显示名
        );
        if ($kind === 'autoreply') {
            // 自动确认按浏览器语言分「语言组」：索引 0 永远是默认（兜底）组
            $defaults['enabled'] = '0';
            $defaults['groups']  = array(self::empty_group());
        }
        return $defaults;
    }

    /** 一个空的语言组（lang 为空 = 默认/兜底） */
    private static function empty_group(string $lang = ''): array {
        return array('lang' => $lang, 'subject' => '', 'html' => '', 'labels' => array());
    }

    /**
     * 自动确认的语言组（规范化后的列表，索引 0 恒为默认组）。
     *
     * 规范化做三件事：补齐缺省字段、把 lang 为空的组提到第一位、同一语言只留第一个。
     * 读（渲染/匹配）与写（保存）都走它，索引才对得上。
     */
    private static function reply_groups(?array $cfg = null): array {
        $cfg    = $cfg ?? self::tpl('autoreply');
        $groups = isset($cfg['groups']) && is_array($cfg['groups']) ? $cfg['groups'] : array();

        $clean = array();
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $clean[] = array(
                'lang'    => self::canonical_lang((string) ($group['lang'] ?? '')),
                'subject' => (string) ($group['subject'] ?? ''),
                'html'    => (string) ($group['html'] ?? ''),
                'labels'  => (array) ($group['labels'] ?? array()),
            );
        }

        // 把默认组换到第 0 位（没有就补一个）
        $default_index = null;
        foreach ($clean as $i => $group) {
            if ($group['lang'] === '') {
                $default_index = $i;
                break;
            }
        }
        if ($default_index === null) {
            array_unshift($clean, self::empty_group());
        } elseif ($default_index !== 0) {
            $swap = $clean[0];
            $clean[0] = $clean[$default_index];
            $clean[$default_index] = $swap;
        }

        // 同一语言只留第一个（默认组不算重复）
        $seen = array('' => true);
        $out  = array($clean[0]);
        foreach (array_slice($clean, 1) as $group) {
            if ($group['lang'] === '' || isset($seen[strtolower($group['lang'])])) {
                continue;
            }
            $seen[strtolower($group['lang'])] = true;
            $out[] = $group;
        }

        return $out;
    }

    /**
     * 按访客浏览器语言挑语言组：完整标签精确命中（zh-cn）→ 主语言命中（zh）
     * → 默认组。没有浏览器语言（旧前端/无 JS）也走默认组。
     */
    private static function reply_group(array $fields, ?array $cfg = null): array {
        $groups = self::reply_groups($cfg);
        $lang   = strtolower(trim((string) ($fields['browserLanguage'] ?? '')));
        if ($lang === '') {
            return $groups[0];
        }

        foreach ($groups as $group) {
            if (strtolower($group['lang']) === $lang) {
                return $group;
            }
        }
        $primary = explode('-', $lang)[0];
        foreach ($groups as $group) {
            if ($group['lang'] !== '' && explode('-', strtolower($group['lang']))[0] === $primary) {
                return $group;
            }
        }
        return $groups[0];
    }

    /** 当前生效的模板配置（默认值 + 后台保存值），按 kind 各自缓存 */
    private static function tpl(string $kind = 'notify'): array {
        static $cache = array();
        if (!isset($cache[$kind])) {
            $saved = get_option(self::option_name($kind), array());
            $tpl   = wp_parse_args(is_array($saved) ? $saved : array(), self::template_defaults($kind));
            if ($kind === 'autoreply') {
                // 语言组统一规范化：0 号恒为默认组，同一语言只留一份
                $tpl['groups'] = self::reply_groups($tpl);
            }
            $cache[$kind] = $tpl;
        }
        return $cache[$kind];
    }

    /**
     * 自动确认可选的语言（i18n 选项）：键 = 浏览器语言标签，值 = 给人看的名字。
     *
     * 只列常用的；匹配时先比完整标签再比主语言，所以 `pt` 能吃掉 `pt-BR`。
     */
    private static function lang_options(): array {
        return array(
            'en'    => 'English (en)',
            'en-US' => 'English — US (en-US)',
            'en-GB' => 'English — UK (en-GB)',
            'zh'    => '中文 — 全部 (zh)',
            'zh-CN' => '简体中文 (zh-CN)',
            'zh-TW' => '繁體中文 (zh-TW)',
            'zh-HK' => '繁體中文 — 香港 (zh-HK)',
            'ja'    => '日本語 (ja)',
            'ko'    => '한국어 (ko)',
            'de'    => 'Deutsch (de)',
            'fr'    => 'Français (fr)',
            'es'    => 'Español (es)',
            'pt'    => 'Português (pt)',
            'it'    => 'Italiano (it)',
            'nl'    => 'Nederlands (nl)',
            'ru'    => 'Русский (ru)',
            'pl'    => 'Polski (pl)',
            'tr'    => 'Türkçe (tr)',
            'ar'    => 'العربية (ar)',
            'th'    => 'ไทย (th)',
            'vi'    => 'Tiếng Việt (vi)',
            'id'    => 'Bahasa Indonesia (id)',
            'hi'    => 'हिन्दी (hi)',
        );
    }

    /** 语言标签的显示名（认不出来的原样大写显示，兼容手改过的旧值） */
    private static function lang_label(string $code): string {
        if ($code === '') {
            return self::t('tpl_lang_default');
        }
        $options = self::lang_options();
        return $options[$code] ?? strtoupper($code);
    }

    /**
     * 语言标签归一：能对上候选表就用表里的规范写法（zh-CN，别再变 zh-cn），
     * 对不上就小写保留（自定义/旧数据）。顺手剔掉非法字符。
     */
    private static function canonical_lang(string $lang): string {
        $lang = (string) preg_replace('/[^A-Za-z0-9\-_]/', '', trim($lang));
        if ($lang === '') {
            return '';
        }
        foreach (array_keys(self::lang_options()) as $code) {
            if (strcasecmp($code, $lang) === 0) {
                return $code;
            }
        }
        return strtolower($lang);
    }

    /**
     * 询盘序号：站点时区的年月日 + 当日流水号，如 20261105001。
     * 用 MySQL 的 LAST_INSERT_ID(expr) 做原子自增，避免并发提交拿到同一个号。
     */
    private static function next_inquiry_no(): string {
        global $wpdb;

        $day = (string) current_time('Ymd');
        $key = 'inquiry_seq_' . $day;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
            $key
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
            $key
        ));
        $seq = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        return $day . str_pad((string) max(1, $seq), 3, '0', STR_PAD_LEFT);
    }

    /**
     * 邮件主题：**和正文一样用双花括号** {{name}} / {{email}}；留空用当前语言的默认格式。
     * 单花括号 {name} {email} 是旧写法，保留替换但不再宣传（老主题不会变字面量）。
     */
    private static function build_subject(array $fields, ?array $tpl = null, string $kind = 'notify'): string {
        $tpl    = $tpl ?? self::tpl($kind);
        $custom = trim((string) $tpl['subject']);
        if ($custom !== '') {
            $name  = (string) ($fields['fullName'] ?? '');
            $email = (string) ($fields['email'] ?? '');

            // strtr 按最长优先匹配：{{name}} 不会被 {name} 抢先吃掉
            return strtr($custom, array(
                '{{name}}'  => $name,
                '{{email}}' => $email,
                '{name}'    => $name,
                '{email}'   => $email,
            ));
        }
        if ($kind === 'autoreply') {
            return self::t('email_subject_reply');
        }
        return sprintf(
            self::t('email_subject'),
            (string) ($fields['fullName'] ?? '') . ' <' . (string) ($fields['email'] ?? '') . '>'
        );
    }

    /**
     * 设置页的示例数据：预览和测试发信都用它。
     *
     * 字段集直接取 label_fields()——也就是「字段标签」里列出的那一整套，
     * 一封不少。否则预览会随最近一封询盘填了哪些选填项来回跳动（少填几项就少几行），
     * 看着像模板漏行。
     */
    private static function sample_fields(): array {
        $labels = (array) self::label_fields()['fields'];

        $fields = array();
        foreach (array_keys($labels) as $key) {
            $fields[$key] = self::SAMPLE_FIELD_VALUES[$key] ?? 'Sample';
        }
        // 单位跟着数值走：每个带单位的字段都要补，否则预览里就这几行"少根尾巴"。
        // 没有数值时 build_rows() 会整条丢掉，不会留下孤立的 "PCS"
        foreach (self::SAMPLE_FIELD_UNITS as $unit_key => $unit_value) {
            if (isset($fields[$unit_key])) {
                $fields[$unit_key . 'Unit'] = $unit_value;
            }
        }
        $fields['fieldLabels'] = $labels;

        return $fields + array(
            'formVariant'       => 'page',
            'pageTitle'         => 'Contact Us',
            'pageTitleEn'       => 'Contact Us',
            'pageUrl'           => home_url('/contact-us'),
            'locale'            => 'en',
            // 示例环境数据：预览里 {{lang}} / {{time_zone}} / {{time_local}} 才有东西可看
            'browserLanguage'   => 'en-US',
            'timezone'          => 'Asia/Shanghai',
        );
    }

    /**
     * 默认邮件模板源码（后台代码框里看到的就是它）。
     *
     * 版式：品牌色整块页头（白字，含编号/来源/时间）+ 细线键值表 + 底部浅灰信息块。
     * 页头与页脚的文案都写在这里，想换说法或换语言直接改代码。
     * $kind = 'autoreply' 时换成发给访客的那份默认源码。
     */
    private static function default_template_html(string $kind = 'notify'): string {
        if ($kind === 'autoreply') {
            return self::default_autoreply_html();
        }
        return <<<'HTML'
<div style="margin:0;padding:40px 12px;background:#f7f7f7;font-family:'PingFang SC','Microsoft YaHei',sans-serif;letter-spacing:.5px;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="640" style="width:640px;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-collapse:collapse;">
    <tr>
      <td style="padding:28px 40px;background:#04979e;">
        <!-- 页头第一行：编号 + 标题，改文案就在这里 -->
        <div style="font-size:22px;line-height:1.3;color:#ffffff;font-weight:600;">网站询盘#{{no}}</div>
        <!-- 页头第二行：来源 + 表单类型 + 提交时间 -->
        <div style="margin-top:8px;font-size:12px;line-height:1.7;color:rgba(255,255,255,.85);">来源：{{source}}（{{form_type}}）　{{time}}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:24px 40px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
          {{rows}}
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:24px 40px 36px;">
        <!-- 底部信息块：左边 3px 品牌色竖条。标签都写在下面，占位符只注入值 -->
        <div style="padding:16px 18px;background:#f7f7f7;border-left:3px solid #04979e;font-size:12px;line-height:1.8;color:#555555;">
          浏览器语言：{{lang}}　时区：{{time_zone}}<br>
          提交时间：{{time}}　当地时间：{{time_local}}<br>
          IP：{{ip}}
        </div>
      </td>
    </tr>
  </table>
</div>
HTML;
    }

    /**
     * 默认的「自动确认」源码。
     *
     * 收件人是访客本人：中英双语（站点默认语是 en，询盘客户多数在海外），
     * 正文回显他刚提交的内容（{{rows}} 的表头用的就是他表单自己的措辞），
     * 页脚只留站名和时间，不放 IP 这类内部信息。
     */
    private static function default_autoreply_html(): string {
        return <<<'HTML'
<div style="margin:0;padding:40px 12px;background:#f7f7f7;font-family:'PingFang SC','Microsoft YaHei',sans-serif;letter-spacing:.5px;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="640" style="width:640px;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #eaeaea;border-collapse:collapse;">
    <tr>
      <td style="padding:28px 40px;background:#04979e;">
        <!-- 页头：标题 + 编号，改文案就在这里 -->
        <div style="font-size:22px;line-height:1.3;color:#ffffff;font-weight:600;">We've received your inquiry</div>
        <div style="margin-top:8px;font-size:12px;line-height:1.7;color:rgba(255,255,255,.85);">#{{no}}　{{time}}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 40px 0;font-size:14px;line-height:1.85;color:#333333;">
        Dear {{name}},<br>
        Thank you for contacting us. Your message has reached our team and we will reply within one business day.
        <div style="margin-top:10px;color:#777777;">您好，我们已收到您的询盘，会在一个工作日内回复。</div>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 40px 0;">
        <!-- 回显访客刚提交的内容：表头文字来自表单自己的字段标签 -->
        <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8a979d;margin-bottom:6px;">Your message / 您提交的内容</div>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">
          {{rows}}
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 40px 36px;">
        <div style="padding:16px 18px;background:#f7f7f7;border-left:3px solid #04979e;font-size:12px;line-height:1.8;color:#555555;">
          Inquiry #{{no}}<br>
          {{time}}
        </div>
      </td>
    </tr>
  </table>
</div>
HTML;
    }

    /**
     * 邮件正文 = 模板源码（后台保存的，没有就用默认源码）+ 占位符替换。
     *
     * **占位符只输出值，不输出任何标签文案**——「语言：」「时区：」这类字眼由源码自己写，
     * 想让邮件说什么话，完全取决于模板。
     *
     *   字段  {{rows}}
     *   数据  {{name}} {{email}} {{no}} {{source}} {{form_type}}
     *         {{time}}（站点时间） {{time_local}}（访客当地时间）
     *         {{ip}} {{lang}}（浏览器语言） {{time_zone}}（访客时区）
     *
     * 页头、页脚、各类固定文案都直接写在源码里——想换语言/换说法就改代码。
     */
    private static function build_body(array $fields, array $staged, ?array $tpl = null, string $inquiry_no = '', string $kind = 'notify', int $timestamp = 0): string {
        $tpl = $tpl ?? self::tpl($kind);

        // 提交时间 = **服务端收到请求的时刻**（不采信浏览器时钟）：
        // 一次请求里取一次，通知与自动确认共用同一个时间戳，跨零点也不会对不上。
        $timestamp = $timestamp > 0 ? $timestamp : time();

        $variant      = (string) ($fields['formVariant'] ?? '');
        $form_type    = $variant === 'modal' ? self::t('form_modal') : self::t('form_page');
        $source_label = self::source_label($fields);

        // 字段标签：通知用后台那一份；自动确认用自己的那份（每个语言组各一份）。
        // 两边都没配时，field_label() 会回落到「表单自己提交的标签」，
        // 而表单是访客所在语种渲染的，所以表头天然跟着语言走。
        $labels = (array) ($tpl['labels'] ?? array());

        $rows = self::build_rows($fields, $staged, $labels);

        $timezone   = self::field_timezone($fields);
        $local_time = self::local_time_label($timezone, 'Y-m-d H:i:s T', $timestamp);

        $source = trim((string) $tpl['html']) !== ''
            ? (string) $tpl['html']
            : self::default_template_html($kind);

        return strtr($source, array(
            '{{site_name}}'    => '', // 已废弃：站名没有信息量，模板里不再使用
            '{{name}}'         => esc_html((string) ($fields['fullName'] ?? '')),
            '{{email}}'        => esc_html((string) ($fields['email'] ?? '')),
            '{{source}}'       => esc_html($source_label),
            '{{form_type}}'    => esc_html($form_type),
            '{{rows}}'         => $rows,
            '{{no}}'           => esc_html($inquiry_no !== '' ? $inquiry_no : current_time('Ymd') . '001'),
            '{{time}}'         => esc_html(date_i18n('Y-m-d H:i:s T', $timestamp)),
            '{{time_local}}'   => esc_html($local_time),
            '{{ip}}'           => esc_html(self::client_ip()),
            '{{lang}}'         => esc_html((string) ($fields['browserLanguage'] ?? '')),
            '{{time_zone}}'    => esc_html($timezone),
        ));
    }

    /**
     * 自动确认：提交成功后给访客回一封，返回 true/false；
     * **null 表示没开启（或邮箱不合法），调用方据此不写状态 meta**。
     *
     * 用哪套主题/源码/字段标签，取决于访客的浏览器语言（见 reply_group()）：
     * zh-CN → 命中 zh 组，没配就落到默认组。
     */
    private static function send_autoreply(array $fields, string $inquiry_no, int $timestamp = 0): ?bool {
        $cfg = self::tpl('autoreply');
        if (empty($cfg['enabled'])) {
            return null;
        }
        $email = trim((string) ($fields['email'] ?? ''));
        if (!is_email($email)) {
            return null;
        }

        $group    = self::reply_group($fields, $cfg);
        $subject  = self::build_subject($fields, $group, 'autoreply');
        // 自动确认不带附件：访客刚传的文件没必要再回传一遍
        $body     = self::build_body($fields, array(), $group, $inquiry_no, 'autoreply', $timestamp);
        $headers  = array(
            'Content-Type: text/html; charset=UTF-8',
            // 客户直接回信就回到询盘收件箱，而不是 bounced 的发件人地址
            'Reply-To: ' . (string) apply_filters('eln_recipient', get_option('admin_email')),
        );

        $sent = self::mail($email, $subject, $body, $headers);

        /**
         * 自动确认发出后触发，可用来对接 CRM 或另发通知。
         * @param bool   $sent
         * @param string $email
         * @param array  $fields 本次提交的字段
         */
        do_action('eln_autoreply_sent', $sent, $email, $fields);

        return $sent;
    }

    /**
     * 发一封本站邮件：统一包一层，只为两件事——
     *   1. 打上 self::$sending 标记，让 phpmailer_init 补纯文本正文（只有 HTML 容易被判垃圾）
     *   2. 清空并取回失败原因（wp_mail_failed 里捕获的），供调用方写进记录
     */
    private static function mail(string $to, string $subject, string $body, array $headers = array(), array $attachments = array()): bool {
        self::$sending    = true;
        self::$mail_error = '';
        $sent = (bool) wp_mail($to, $subject, $body, $headers, $attachments);
        self::$sending = false;

        return $sent;
    }

    /** 给纯 HTML 邮件补一段 text/plain 正文（Gmail 等对「只有 HTML」的邮件更挑剔） */
    public static function fill_alt_body($phpmailer): void {
        if (!self::$sending || !is_object($phpmailer)) {
            return;
        }
        if ('' !== (string) $phpmailer->AltBody) {
            return; // 已有纯文本就别覆盖
        }
        $phpmailer->AltBody = self::html_to_text((string) $phpmailer->Body);
    }

    /** 记录 wp_mail 失败原因（SMTP 报错、收件人被拒等） */
    public static function capture_mail_error($error): void {
        if (is_wp_error($error)) {
            self::$mail_error = (string) $error->get_error_message();
        }
    }

    /** HTML 转纯文本：够用就行，不做完整解析 */
    private static function html_to_text(string $html): string {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|div|tr|h[1-6]|table|li)>#i', "\n", $text);
        $text = (string) preg_replace('#</t[dh]>#i', '  ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 逐行 trim、丢掉空行堆叠：嵌套表格会留一堆只有空格的“空行”
        $lines = preg_split('/\R/u', $text) ?: array();
        $out   = array();
        $blank = false;
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $line));
            if ($line === '') {
                if (!$blank && $out) {
                    $out[] = '';
                    $blank = true;
                }
                continue;
            }
            $out[] = $line;
            $blank = false;
        }

        return trim(implode("\n", $out));
    }

    /** 来源页展示名：优先英文页面标题（统一口径），退回本地化标题，再退回 URL 路径 */
    private static function source_label(array $fields): string {
        $title = (string) ($fields['pageTitleEn'] ?? '');
        if ($title === '') {
            $title = (string) ($fields['pageTitle'] ?? '');
        }
        if ($title !== '') {
            return $title;
        }
        $path = parse_url((string) ($fields['pageUrl'] ?? ''), PHP_URL_PATH);
        if ($path && $path !== '/') {
            return trim((string) $path, '/');
        }
        return ($fields['formVariant'] ?? '') === 'modal'
            ? self::t('form_modal') : self::t('form_page');
    }

    /** 字段表行：单位后缀并入数值，FIELD_ORDER 定序，未知字段缀尾 */
    private static function build_rows(array $fields, array $staged, ?array $labels = null): string {
        // 1) 单位并入数值（quantityUnit → "500 PCS"）
        $merged = array();
        foreach ($fields as $key => $value) {
            if (str_ends_with($key, 'Unit')) {
                $base = substr($key, 0, -4);
                if (isset($fields[$base]) && !in_array($base, self::META_FIELDS, true)) {
                    $merged[$base] = $fields[$base] . ' ' . $value;
                }
                // 没有配套数值（用户没填）就整条丢掉，否则会出现孤立的 "Quantity Unit: PCS"
                continue;
            }
            $merged[$key] = $value;
        }

        // 2) 排序：**后台「字段标签」里排出来的顺序就是行序**（用户能拖）。
        //    没排过的字段再按 FIELD_ORDER 补在后面，最后是未知字段。
        $ordered = array();
        foreach (array_keys((array) $labels) as $key) {
            if (isset($merged[$key])) {
                $ordered[$key] = $merged[$key];
                unset($merged[$key]);
            }
        }
        foreach (self::FIELD_ORDER as $key) {
            if (isset($merged[$key])) {
                $ordered[$key] = $merged[$key];
                unset($merged[$key]);
            }
        }
        foreach ($merged as $key => $value) {
            if (!in_array($key, self::META_FIELDS, true) && $key !== self::HONEYPOT) {
                $ordered[$key] = $value;
            }
        }

        // 3) 渲染
        $html = '';
        foreach ($ordered as $key => $value) {
            if ($key === 'email') {
                $value_html = '<a href="mailto:' . esc_attr($value) . '" style="color:' . self::BRAND_COLOR . ';text-decoration:none;">'
                    . esc_html($value) . '</a>';
            } else {
                $value_html = nl2br(esc_html((string) $value));
            }
            $html .= '<tr><th style="' . self::th_style() . '">' . esc_html(self::field_label($key, $labels, (array) ($fields['fieldLabels'] ?? array()))) . '</th>'
                   . '<td style="' . self::td_style() . '">' . $value_html . '</td></tr>';
        }

        // 4) 附件清单（真实附件在邮件附件区，这里列清单供核对）
        if ($staged) {
            $items = '';
            foreach ($staged as $file) {
                $items .= '<div style="margin:1px 0;">' . esc_html($file['name'])
                        . ' <span style="color:#8a979d;font-size:12px;">(' . esc_html(self::size_label($file['size'])) . ')</span></div>';
            }
            $html .= '<tr><th style="' . self::th_style() . '">' . esc_html(self::t('row_attachments')) . '</th>'
                   . '<td style="' . self::td_style() . '">' . $items . '</td></tr>';
        }

        return $html;
    }

    private static function size_label(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' B';
    }

    /* ================================================================ 留档 */

    private static function store_inquiry(array $fields, string $body_html, string $inquiry_no = ''): int {
        $meta = array(
            'inquiry_email'      => (string) ($fields['email'] ?? ''),
            'inquiry_variant'    => (string) ($fields['formVariant'] ?? ''),
            'inquiry_locale'     => (string) ($fields['locale'] ?? ''),
            'inquiry_page_url'   => (string) ($fields['pageUrl'] ?? ''),
            'inquiry_page_title' => (string) ($fields['pageTitle'] ?? ''),
            'inquiry_page_title_en' => (string) ($fields['pageTitleEn'] ?? ''),
            'inquiry_ip'         => self::client_ip(),
            'inquiry_lang'       => (string) ($fields['browserLanguage'] ?? ''),
            'inquiry_timezone'   => self::field_timezone($fields),
            'inquiry_no'         => $inquiry_no,
            // 记录本条出现过哪些字段（供后台动态列出标签项），与是否传了标签无关
            'inquiry_fields'     => array_values(array_diff(array_keys($fields), self::META_FIELDS)),
            'inquiry_field_labels' => (array) ($fields['fieldLabels'] ?? array()),
        );

        // 正文由 build_body() 生成（用户值入库前已 esc_html），要原样保留 rgba() 颜色。
        // 非特权用户提交时 WP 会把 wp_filter_post_kses 挂到 content_save_pre 上，
        // safecss_filter_attr 会把 rgba() 一类颜色剥掉（邮件页头白字变黑字的根因），这里临时摘掉再恢复。
        kses_remove_filters();
        $post_id = wp_insert_post(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            // 标题只存姓名：列表「姓名」列直接展示，邮箱有独立列
            'post_title'     => (string) ($fields['fullName'] ?? 'Unknown'),
            'post_content'   => $body_html,
            'meta_input'     => $meta,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ), true);
        kses_init_filters();

        return is_wp_error($post_id) ? 0 : (int) $post_id;
    }

    /**
     * 给早期记录补编号。
     *
     * inquiry_no 是后加的 meta，之前入库的询盘没有它，列表「编号」列就一直显示 —。
     * 按提交时间顺序补号，并把当天的流水计数器推到已用最大值，免得下一封询盘撞号。
     * 只跑一次（option 记账），之后完全零开销。
     */
    public static function backfill_numbers(): void {
        if (get_option(self::OPT_BACKFILL) === '1') {
            return;
        }
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'inquiry_no'
             WHERE p.post_type = '" . self::POST_TYPE . "' AND m.meta_id IS NULL
             ORDER BY p.post_date ASC, p.ID ASC"
        );

        $used = array();
        foreach ((array) $ids as $id) {
            $id  = (int) $id;
            $day = mysql2date('Ymd', (string) get_post_field('post_date', $id));
            $used[$day] = ($used[$day] ?? 0) + 1;
            update_post_meta($id, 'inquiry_no', $day . str_pad((string) $used[$day], 3, '0', STR_PAD_LEFT));
        }

        // 补出来的号已经占用了当天前 N 个流水位，计数器必须跟上
        foreach ($used as $day => $count) {
            $key = 'inquiry_seq_' . $day;
            if ((int) get_option($key, 0) < $count) {
                update_option($key, $count, false);
            }
        }

        update_option(self::OPT_BACKFILL, '1', false);
    }

    /* ================================================================ 后台 */

    /** 询盘留档 CPT：不公开、仅后台可见、UI 禁止新建（wp_insert_post 不查 caps） */
    public static function register_post_type(): void {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name'          => self::t('menu_name'),
                'singular_name' => self::t('menu_name'),
                'menu_name'     => self::t('menu_name'),
                'all_items'     => self::t('all_items'),
                'search_items'  => self::t('search_items'),
                'not_found'     => self::t('not_found'),
                'not_found_in_trash' => self::t('not_found_trash'),
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => false,
            'menu_icon'           => 'dashicons-email',
            'menu_position'       => 26,
            'supports'            => array('title', 'editor'),
            'capability_type'     => 'post',
            'capabilities'        => array('create_posts' => 'do_not_allow'),
            'map_meta_cap'        => true,
        ));
    }

    /**
     * 列表页状态视图：只留「全部」和「回收站」。
     * 回收站为空时 WordPress 不会渲染那一项，这里补一条空壳链接，保证两个入口始终在。
     */
    public static function admin_views(array $views): array {
        $all   = $views['all'] ?? '';
        $trash = $views['trash'] ?? '';

        // WP 核心这一项的文案写死了 __('All')，CPT 的 all_items 标签套不上去，
        // 所以只会显示「全部」。这里只换文案：href、current 状态原样保留，
        // 计数统一成半角括号——a 标签里那对全/半角括号是跟着语种翻译走的，
        // 与下面手工补的回收站链接对不齐。
        if ($all !== ''
            && preg_match('/^(<a\b[^>]*>).*?(<span class="count">)(.*?)(<\/span><\/a>)$/s', $all, $m)
        ) {
            $count = preg_replace('/[^\d,.]/', '', $m[3]);
            $all   = $m[1] . esc_html(self::t('all_items')) . ' '
                   . $m[2] . '(' . $count . ')' . $m[4];
        }

        if ($trash === '') {
            $count = (int) wp_count_posts(self::POST_TYPE)->trash;
            $url   = admin_url('edit.php?post_status=trash&post_type=' . self::POST_TYPE);
            $trash = '<a href="' . esc_url($url) . '">' . esc_html(self::t('view_trash'))
                   . ' <span class="count">(' . $count . ')</span></a>';
        }

        $out = array();
        if ($all !== '') {
            $out['all'] = $all;
        }
        $out['trash'] = $trash;
        return $out;
    }

    /**
     * 批量操作：列表页只留「移动至回收站」。
     * 回收站视图里换成 WordPress 自带的一组（恢复 / 永久删除），否则回收站进去就出不来。
     */
    public static function admin_bulk_actions(array $actions): array {
        // phpcs:ignore WordPress.Security.NonceVerification
        $in_trash = isset($_GET['post_status']) && $_GET['post_status'] === 'trash';

        if ($in_trash) {
            $keep = array();
            foreach (array('untrash', 'delete') as $key) {
                if (isset($actions[$key])) {
                    $keep[$key] = $actions[$key];
                }
            }
            return $keep ?: $actions;
        }

        return isset($actions['trash']) ? array('trash' => $actions['trash']) : array();
    }

    /** 日期列不显示「已发布」：询盘是留档记录，没有发布状态可言 */
    public static function hide_date_status(string $status, WP_Post $post): string {
        return $post->post_type === self::POST_TYPE ? '' : $status;
    }

    /** 列表上方的「来源」筛选下拉：选项来自已存档的英文页面标题 */
    public static function source_filter(string $post_type): void {
        if ($post_type !== self::POST_TYPE) {
            return;
        }
        $values = self::source_values();
        if (!$values) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $current = isset($_GET['inquiry_source']) ? trim(wp_unslash((string) $_GET['inquiry_source'])) : '';

        echo '<select name="inquiry_source" style="max-width:260px;" onchange="this.form.submit()">';
        echo '<option value="">' . esc_html(self::t('all_sources')) . '</option>';
        foreach ($values as $value) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($value, $current, false),
                esc_html($value)
            );
        }
        echo '</select>';
    }

    /** 已存档记录里出现过的来源（英文标题），按字母序 */
    private static function source_values(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'inquiry_page_title_en' AND meta_value <> ''
             ORDER BY meta_value ASC"
        );
        return array_values(array_filter(array_map('trim', (array) $rows), static fn($v) => $v !== ''));
    }

    /** 按来源过滤列表（只在询盘列表的主查询上生效） */
    public static function apply_source_filter(WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== self::POST_TYPE) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $value = isset($_GET['inquiry_source']) ? trim(wp_unslash((string) $_GET['inquiry_source'])) : '';
        if ($value === '') {
            return;
        }
        $query->set('meta_query', array(
            array(
                'key'     => 'inquiry_page_title_en',
                'value'   => $value,
                'compare' => '=',
            ),
        ));
    }

    /**
     * 询盘的子菜单统一走「右侧浮层」。
     *
     * WP 只会给「非当前」的菜单加 .wp-not-current-submenu（那才是浮层）；
     * 当前区块则用 .wp-has-current-submenu 在侧栏里向下展开，把外观/插件等整体顶下去。
     * 这里把当前态也按浮层渲染——位置/尺寸/阴影照抄 .folded 分支的那套，
     * 只是左偏移用展开态侧栏宽度（窄屏 auto-fold 时退回 36px）。
     */
    public static function admin_menu_styles(): void {
        echo '<style>'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu > .wp-submenu{'
            . 'position:absolute;top:-1000em;left:160px;min-width:160px;width:auto;z-index:9999;'
            . 'border:1px solid transparent;border-left-width:5px;box-shadow:0 3px 5px rgba(0,0,0,.2);}'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu:hover,'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu.opensub,'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu:focus-within{z-index:10000;}'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu:hover > .wp-submenu,'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu.opensub > .wp-submenu,'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu:focus-within > .wp-submenu{top:-1px;}'
            . '@media screen and (max-width:960px){'
            . '#adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu > .wp-submenu{left:36px;}}'
            // 收起菜单后侧栏只剩 36px，浮层要贴着图标出（WP 自己的 folded 规则扛不过上面的双 ID 选择器）
            . '.folded #adminmenu li#menu-posts-' . self::POST_TYPE . '.wp-has-current-submenu > .wp-submenu{left:36px;}'
            . '</style>';
    }

    /** 列表列宽：来源是「英文标题（表单类型）」，默认宽度会折成两行 */
    public static function admin_list_styles(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-' . self::POST_TYPE) {
            return;
        }
        echo '<style>'
            . '.fixed .column-inquiry_no{width:110px;}'
            . '.fixed .column-inquiry_source{width:22%;}'
            . '.fixed .column-inquiry_attachments{width:16%;}'
            . '.fixed .column-inquiry_mail{width:120px;}'
            . '.eln-mail-status{display:inline-block;}'
            . '.eln-mail-item{display:block;font-size:12px;line-height:1.9;color:#50575e;white-space:nowrap;}'
            . '.eln-mail-dot{display:inline-block;width:9px;height:9px;margin-right:6px;border-radius:50%;vertical-align:-1px;}'
            . '.eln-mail-dot.is-ok{background:#00a32a;}'
            . '.eln-mail-dot.is-fail{background:#d63638;}'
            . '.eln-mail-dot.is-none{box-shadow:inset 0 0 0 1.5px #c3c4c7;}'
            . '</style>';
    }

    /** 主列固定为「客户」：WP 在没有 title 列时会把第一列（编号）当主列，行操作就挂到编号下面了 */
    public static function primary_column(string $column, string $screen_id): string {
        return $screen_id === 'edit-' . self::POST_TYPE ? 'inquiry_no' : $column;
    }

    /** 列表列：姓名（点击进详情）/ 邮箱 / 来源 / 附件 / 发信 / 日期 */
    public static function admin_columns(array $columns): array {
        $new = array();
        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                // 用「姓名」列取代「标题」列：姓名即详情入口，不与邮箱列重复
                $new['inquiry_no']         = self::t('col_no');
                $new['inquiry_name']       = self::t('col_name');
                $new['inquiry_email']      = self::t('col_email');
                $new['inquiry_source']     = self::t('col_source');
                $new['inquiry_attachments'] = self::t('col_attachments');
                $new['inquiry_mail']       = self::t('col_mail');
            } else {
                $new[$key] = $label;
            }
        }
        return $new;
    }

    public static function admin_column_content(string $column, int $post_id): void {
        switch ($column) {
            case 'inquiry_no':
                $no = (string) get_post_meta($post_id, 'inquiry_no', true);
                echo $no !== ''
                    ? '<span style="font-size:12px;color:#50575e;">' . esc_html($no) . '</span>'
                    : '—';
                break;
            case 'inquiry_name':
                echo '<strong>' . esc_html(get_the_title($post_id)) . '</strong>';
                break;
            case 'inquiry_email':
                $email = get_post_meta($post_id, 'inquiry_email', true);
                echo $email ? '<span style="color:#50575e;">' . esc_html($email) . '</span>' : '—';
                break;
            case 'inquiry_source':
                echo esc_html(self::record_source_label($post_id));
                break;
            case 'inquiry_attachments':
                echo self::attachments_html($post_id, ', ');
                break;
            case 'inquiry_mail':
                echo self::mail_status_html($post_id); // phpcs:ignore WordPress.Security.EscapeOutput
                break;
        }
    }

    /**
     * 发信状态：两行「圆点 + 名称」，每封邮件各一枚圆点。
     *   已发送 = 绿点 / 发送失败 = 红点 / 未发送 = 灰描边空心
     * 失败原因照旧放 title，悬停可查。
     */
    private static function mail_status_html(int $post_id): string {
        $notify  = (string) get_post_meta($post_id, 'inquiry_mail_sent', true);
        $reply   = (string) get_post_meta($post_id, 'inquiry_autoreply_sent', true);
        $n_error = (string) get_post_meta($post_id, 'inquiry_mail_error', true);
        $r_error = (string) get_post_meta($post_id, 'inquiry_autoreply_error', true);

        $sent = (int) ($notify === 'yes') + (int) ($reply === 'yes');

        $word = static function (string $state): string {
            if ($state === 'yes') {
                return self::t('status_yes');
            }
            return $state === 'no' ? self::t('status_no') : self::t('status_none');
        };

        if ($sent === 2) {
            $summary = self::t('status_all_hint');
            $short   = self::t('status_all');
        } elseif ($sent === 1) {
            $summary = $notify === 'yes' ? self::t('status_notify_only') : self::t('status_reply_only');
            $short   = self::t('status_partial');
        } else {
            $summary = self::t('status_none_hint');
            $short   = self::t('status_none');
        }

        $lines = array(
            self::t('label_notify') . '：' . $word($notify) . ($n_error !== '' ? '（' . $n_error . '）' : ''),
            self::t('label_reply') . '：' . $word($reply) . ($r_error !== '' ? '（' . $r_error . '）' : ''),
        );
        $title = $summary . "\n" . implode("\n", $lines);

        // 扁平小圆点：已发送绿 / 失败红 / 未发送灰环（样式在 admin_list_styles 里）
        $item = static function (string $label, string $state): string {
            $cls = $state === 'yes' ? 'is-ok' : ($state === 'no' ? 'is-fail' : 'is-none');
            return '<span class="eln-mail-item"><i class="eln-mail-dot ' . $cls . '"></i>' . esc_html($label) . '</span>';
        };

        return '<span class="eln-mail-status" title="' . esc_attr($title)
             . '" aria-label="' . esc_attr($short) . '">'
             . $item(self::t('label_notify'), $notify)
             . $item(self::t('label_reply'), $reply)
             . '</span>';
    }

    /** 单条记录的来源展示名：英文页面标题（表单类型），表单类型标注页面/弹窗 */
    private static function record_source_label(int $post_id): string {
        $name    = (string) get_post_meta($post_id, 'inquiry_page_title_en', true);
        if ($name === '') {
            $name = (string) get_post_meta($post_id, 'inquiry_page_title', true);
        }
        $url     = (string) get_post_meta($post_id, 'inquiry_page_url', true);
        $variant = (string) get_post_meta($post_id, 'inquiry_variant', true);
        if ($name === '' && $url !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            $name = $path && $path !== '/' ? trim((string) $path, '/') : '';
        }
        $variant_label = $variant === 'modal' ? self::t('form_modal')
            : ($variant === 'page' ? self::t('form_page') : ($variant !== '' ? $variant : ''));
        if ($variant_label === '') {
            return $name !== '' ? $name : '—';
        }
        return $name !== '' ? $name . '（' . $variant_label . '）' : $variant_label;
    }

    /** 附件直链列表（新标签页浏览器打开；列表列与详情页共用） */
    private static function attachments_html(int $post_id, string $separator = '<br>'): string {
        $files = (array) get_post_meta($post_id, 'inquiry_attachments', true);
        if (!$files) {
            return '—';
        }
        $links = array();
        foreach (array_values($files) as $i => $file) {
            $url = (string) ($file['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" style="text-decoration:none;">'
                . esc_html($file['name'] ?? ('file-' . $i)) . '</a>'
                . ' <span style="color:#8a979d;font-size:11px;">(' . esc_html(self::size_label((int) ($file['size'] ?? 0))) . ')</span>';
        }
        return $links ? implode($separator, $links) : '—';
    }

    /** 行操作：查看 + 删除；回收站里换成「恢复 + 永久删除」，否则单条记录出不来 */
    public static function row_actions(array $actions, WP_Post $post): array {
        if ($post->post_type !== self::POST_TYPE) {
            return $actions;
        }

        if ($post->post_status === 'trash') {
            $restore = wp_nonce_url(
                admin_url('post.php?post=' . $post->ID . '&action=untrash'),
                'untrash-post_' . $post->ID
            );
            $permanent = wp_nonce_url(
                admin_url('admin-post.php?action=inquiry_delete&id=' . $post->ID),
                'inquiry_del_' . $post->ID
            );
            return array(
                'untrash' => '<a href="' . esc_url($restore) . '">' . esc_html(self::t('restore_action')) . '</a>',
                'delete'  => '<a href="' . esc_url($permanent) . '" style="color:#b32d2e;" onclick="return confirm(\''
                           . esc_js(self::t('confirm_delete')) . '\');">' . esc_html(self::t('delete_action')) . '</a>',
            );
        }

        $actions = array(
            'view' => '<a class="eln-open" href="' . esc_url(admin_url('admin.php?page=' . self::VIEW_SLUG . '&id=' . $post->ID))
                    . '" data-id="' . (int) $post->ID . '">' . esc_html(self::t('view_action')) . '</a>',
        );
        if (current_user_can('delete_posts')) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=inquiry_delete&id=' . $post->ID),
                'inquiry_del_' . $post->ID
            );
            $actions['delete'] = '<a href="' . esc_url($url) . '" style="color:#b32d2e;" onclick="return confirm(\''
                . esc_js(self::t('confirm_delete')) . '\');">' . esc_html(self::t('delete_action')) . '</a>';
        }
        return $actions;
    }

    /** 删除询盘（连带附件）：管理员专用，其余只读 */
    public static function handle_delete(): void {
        if (!current_user_can('delete_posts')) {
            wp_die(esc_html(self::t('invalid_request')));
        }
        $post_id = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        check_admin_referer('inquiry_del_' . $post_id);
        if (!$post_id || get_post_type($post_id) !== self::POST_TYPE) {
            wp_die(esc_html(self::t('not_found_msg')));
        }
        wp_delete_post($post_id, true); // before_delete_post 钩子会联动清理附件
        wp_safe_redirect(add_query_arg('inquiry_deleted', 1, admin_url('edit.php?post_type=' . self::POST_TYPE)));
        exit;
    }

    /** 删除成功提示 */
    public static function deleted_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (isset($_GET['inquiry_deleted']) && isset($_GET['post_type']) && $_GET['post_type'] === self::POST_TYPE) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(self::t('deleted_notice')));
        }
    }

    /** 直连 edit / add-new 一律拦截；回收站相关的动作放行（恢复、移入回收站、永久删除） */
    public static function block_edit_screen(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification
        $type    = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification
        $action  = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';

        // post.php?action=untrash 是后台「已移到回收站，撤销」链接的落点，
        // 拦掉就没法撤销了；这几个动作都不涉及内容编辑
        if (in_array($action, array('trash', 'untrash', 'delete'), true)) {
            return;
        }

        if ($type === self::POST_TYPE || ($post_id && get_post_type($post_id) === self::POST_TYPE)) {
            // 用 403 而不是 wp_die 默认的 500：这是"不允许"，不是服务端故障
            wp_die(
                esc_html(self::t('read_only')),
                esc_html(self::t('menu_name')),
                array('response' => 403)
            );
        }
    }

    /** 从回收站恢复后回到「已留档」状态：询盘没有草稿概念，WordPress 默认会给 draft */
    public static function restore_status(int $post_id): void {
        if (get_post_type($post_id) !== self::POST_TYPE) {
            return;
        }
        if (get_post_status($post_id) === 'publish') {
            return;
        }
        wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
    }

    /**
     * 只读详情页：注册成隐藏子页（parent 传 null）——
     * 询盘菜单点击即全部询盘列表，不出现额外子菜单项。
     */
    public static function register_view_page(): void {
        $hook = add_submenu_page(
            null,
            self::t('view_page_title'),
            self::t('view_page_title'),
            'read',
            self::VIEW_SLUG,
            array(__CLASS__, 'render_view_page')
        );

        // 校验放在 load-* 阶段：此时后台页头还没输出，wp_die 才能给出真正的 403/404；
        // 等 render_view_page 里再 wp_die，状态码早已被页头固定成 200。
        if ($hook) {
            add_action('load-' . $hook, array(__CLASS__, 'guard_view_page'));
        }
    }

    /* ---------------------------------------------------------------- 查看弹窗 */

    /** AJAX：取一条询盘的正文，给列表页的「查看」弹窗用 */
    public static function ajax_inquiry_detail(): void {
        check_ajax_referer('eln_inquiry_detail', 'nonce');
        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        $id   = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(array('message' => 'not found'), 404);
        }

        $notify = (string) get_post_meta($id, 'inquiry_mail_sent', true);
        $reply  = (string) get_post_meta($id, 'inquiry_autoreply_sent', true);
        $sent   = (int) ($notify === 'yes') + (int) ($reply === 'yes');
        $state  = $sent === 2 ? 'all' : ($sent === 1 ? 'partial' : 'none');

        wp_send_json_success(array(
            'no'     => (string) get_post_meta($id, 'inquiry_no', true),
            'title'  => get_the_title($post),
            'date'   => get_the_date('Y-m-d H:i', $post),
            'email'  => (string) get_post_meta($id, 'inquiry_email', true),
            'source' => self::record_source_label($id),
            'attach' => self::attachments_html($id), // 附件直链（与列表「附件」列同源）
            'state'  => $state, // all | partial | none
            'status' => self::t('status_' . $state),
            // 正文是本插件 build_body() 生成的（用户值入库前已 esc_html），
            // 不走 wp_kses_post：它内部的 safecss_filter_attr 会剥掉 rgba() 颜色，
            // 页头白字会变成后台黑字，和真实邮件显示不一致。
            'html'   => $post->post_content,
        ));
    }

    /** 列表页打印弹窗骨架 + 样式 + 脚本（参考 WP「外观 → 主题详情」的覆盖层） */
    public static function print_list_modal(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-' . self::POST_TYPE) {
            return;
        }
        ?>
        <style>
            /* 参考 WP「外观 → 主题详情」：暗遮罩 + 居中大面板，左预览右信息栏，顶部箭头翻阅；字体沿用后台原生栈 */
            .eln-modal{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:4vh 4vw;background:rgba(0,0,0,.7);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;font-size:13px;line-height:1.5;color:#1d2327;}
            .eln-modal.is-open{display:flex;}
            .eln-modal__panel{width:min(1080px,100%);height:min(760px,100%);display:flex;flex-direction:column;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.3);}
            .eln-modal__top{display:flex;align-items:center;flex:none;height:48px;border-bottom:1px solid #dcdcde;}
            .eln-modal__nav{display:flex;}
            .eln-modal__arrow{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border:0;background:transparent;color:#2c3338;cursor:pointer;}
            .eln-modal__arrow svg{display:block;fill:none;stroke:#2c3338;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;}
            .eln-modal__arrow:hover:not(:disabled){background:#f0f0f1;color:#1d2327;}
            .eln-modal__arrow:hover:not(:disabled) svg{stroke:#1d2327;}
            .eln-modal__arrow:disabled{color:#c3c4c7;cursor:default;}
            .eln-modal__arrow:disabled svg{stroke:#c3c4c7;}
            .eln-modal__top .eln-modal__close{margin-left:auto;border:0;background:transparent;color:#646970;cursor:pointer;font-size:22px;line-height:1;padding:4px 16px;height:48px;}
            .eln-modal__top .eln-modal__close:hover{background:#f0f0f1;color:#1d2327;}
            .eln-modal__wrap{display:flex;flex:1;min-height:0;}
            .eln-modal__main{flex:1;min-width:0;overflow:auto;padding:28px 32px;background:#f6f7f7;}
            .eln-modal__stage{max-width:720px;margin:0 auto;border:1px solid #dcdcde;background:#fff;box-shadow:0 1px 8px rgba(0,0,0,.07);}
            .eln-modal__stage>*{display:block;padding:28px 32px;}
            .eln-modal__loading{text-align:center;color:#646970;padding:64px 0;border:0;box-shadow:none;}
            .eln-modal__side{flex:none;width:280px;overflow:auto;padding:20px 24px;border-left:1px solid #dcdcde;background:#fff;}
            .eln-modal__chip{display:inline-block;padding:1px 8px;border-radius:3px;font-size:12px;line-height:1.7;}
            .eln-modal__chip.is-all{background:#edfaef;color:#00a32a;}
            .eln-modal__chip.is-partial{background:#fcf9e8;color:#996800;}
            .eln-modal__chip.is-none{background:#f0f0f1;color:#787c82;}
            .eln-modal__title{margin:0 0 2px;font-size:21px;font-weight:400;line-height:1.4;}
            .eln-modal__no{margin:0 0 10px;color:#646970;font-size:12px;}
            .eln-modal__fields{margin:0;}
            .eln-modal__fields dt{margin:14px 0 2px;color:#646970;font-size:12px;}
            .eln-modal__fields dd{margin:0;font-size:13px;word-break:break-word;}
            .eln-modal__fields dd .eln-mail-item{display:block;}
            .eln-modal__foot{display:flex;justify-content:space-between;flex:none;padding:12px 24px;border-top:1px solid #dcdcde;background:#fff;}
            @media (max-width:782px){
                .eln-modal{padding:0;}
                .eln-modal__panel{width:100%;height:100%;}
                .eln-modal__wrap{flex-direction:column;overflow:auto;}
                .eln-modal__main{overflow:visible;padding:16px 16px 24px;}
                .eln-modal__side{width:auto;border-left:0;border-top:1px solid #dcdcde;}
            }
        </style>
        <div id="eln-modal" class="eln-modal" role="dialog" aria-modal="true"
             aria-label="<?php echo esc_attr(self::t('modal_hint')); ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('eln_inquiry_detail')); ?>"
             data-loading="<?php echo esc_attr(self::t('modal_loading')); ?>"
             data-error="<?php echo esc_attr(self::t('modal_error')); ?>"
             data-reply-subject="<?php echo esc_attr(self::reply_tpl()['subject']); ?>"
             data-reply-body="<?php echo esc_attr(self::reply_tpl()['body']); ?>">
            <div class="eln-modal__panel">
            <div class="eln-modal__top">
                <div class="eln-modal__nav">
                    <button type="button" class="eln-modal__arrow eln-modal__prev" aria-label="<?php echo esc_attr(self::t('modal_prev')); ?>" disabled>
                        <svg width="20" height="20" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </button>
                    <button type="button" class="eln-modal__arrow eln-modal__next" aria-label="<?php echo esc_attr(self::t('modal_next')); ?>" disabled>
                        <svg width="20" height="20" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
                <button type="button" class="eln-modal__close" aria-label="<?php echo esc_attr(self::t('modal_close')); ?>">&times;</button>
            </div>
            <div class="eln-modal__wrap">
                <div class="eln-modal__main">
                    <div class="eln-modal__stage"></div>
                </div>
                <aside class="eln-modal__side">
                    <h2 class="eln-modal__title"></h2>
                    <p class="eln-modal__no"></p>
                    <dl class="eln-modal__fields">
                        <dt><?php echo esc_html(self::t('col_email')); ?></dt>
                        <dd class="eln-modal__email"></dd>
                        <dt><?php echo esc_html(self::t('col_source')); ?></dt>
                        <dd class="eln-modal__source"></dd>
                        <dt><?php echo esc_html(self::t('col_attachments')); ?></dt>
                        <dd class="eln-modal__attach"></dd>
                        <dt><?php echo esc_html(self::t('col_mail')); ?></dt>
                        <dd><span class="eln-modal__chip" hidden></span></dd>
                        <dt><?php echo esc_html(self::t('detail_time')); ?></dt>
                        <dd class="eln-modal__date"></dd>
                    </dl>
                </aside>
            </div>
            <div class="eln-modal__foot">
                <a class="button eln-modal__reply" hidden><?php echo esc_html(self::t('modal_reply')); ?></a>
                <button type="button" class="button eln-modal__close"><?php echo esc_html(self::t('modal_close')); ?></button>
            </div>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('eln-modal');
            if (!modal) { return; }
            var stage  = modal.querySelector('.eln-modal__stage');
            var chip   = modal.querySelector('.eln-modal__chip');
            var title  = modal.querySelector('.eln-modal__title');
            var noEl   = modal.querySelector('.eln-modal__no');
            var email  = modal.querySelector('.eln-modal__email');
            var source = modal.querySelector('.eln-modal__source');
            var attach = modal.querySelector('.eln-modal__attach');
            var dateEl = modal.querySelector('.eln-modal__date');
            var reply  = modal.querySelector('.eln-modal__reply');
            var prevB  = modal.querySelector('.eln-modal__prev');
            var nextB  = modal.querySelector('.eln-modal__next');
            var closers = modal.querySelectorAll('.eln-modal__close');
            var ids = [];
            var current = -1;
            var lastFocus = null;

            function loading(text) {
                stage.innerHTML = '<p class="eln-modal__loading"></p>';
                stage.firstChild.textContent = text;
            }
            function syncNav() {
                prevB.disabled = current <= 0;
                nextB.disabled = current === -1 || current >= ids.length - 1;
            }
            function fetch(id) {
                loading(modal.getAttribute('data-loading'));
                var url = ajaxurl + '?action=eln_inquiry_detail&id=' + encodeURIComponent(id)
                        + '&nonce=' + encodeURIComponent(modal.getAttribute('data-nonce'));
                fetchAbort();
                request = new AbortController();
                return window.fetch(url, { credentials: 'same-origin', signal: request.signal })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j || !j.success) { throw new Error('failed'); }
                        return j.data;
                    });
            }
            var request = null;
            function fetchAbort() {
                if (request) { request.abort(); }
            }
            function render(d) {
                stage.innerHTML = d.html || '';
                title.textContent = d.title || '';
                noEl.textContent = d.no ? 'No. ' + d.no : '';
                email.textContent = d.email || '\u2014';
                source.textContent = d.source || '\u2014';
                attach.innerHTML = d.attach || '\u2014';
                dateEl.textContent = d.date || '\u2014';
                chip.hidden = !(d.state && d.status);
                chip.textContent = d.status || '';
                chip.className = 'eln-modal__chip' + (d.state ? ' is-' + d.state : '');
                // 回信 = mailto：主题/正文骨架取自「回信模板」设置，占位符按本条记录填充
                reply.hidden = !d.email;
                if (d.email) {
                    var ctx = {
                        name: d.title || '', email: d.email || '',
                        no: d.no || '', date: d.date || '', source: d.source || ''
                    };
                    var fill = function (s) {
                        return (s || '').replace(/\{\{(\w+)\}\}/g, function (_, k) {
                            return ctx[k] || '';
                        });
                    };
                    var href = 'mailto:' + d.email + '?subject=' + encodeURIComponent(fill(modal.getAttribute('data-reply-subject')));
                    var body = fill(modal.getAttribute('data-reply-body'));
                    if (body) { href += '&body=' + encodeURIComponent(body); }
                    reply.href = href;
                }
            }
            function show(pos) {
                if (pos < 0 || pos >= ids.length) { return; }
                current = pos;
                syncNav();
                fetch(ids[current]).then(function (d) {
                    if (ids[current] !== ids[pos]) { return; } // 已翻到别条
                    render(d);
                }).catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    loading(modal.getAttribute('data-error'));
                });
            }
            function open(id) {
                var links = document.querySelectorAll('.eln-open[data-id]');
                ids = [];
                for (var i = 0; i < links.length; i++) {
                    var v = links[i].getAttribute('data-id');
                    if (ids.indexOf(v) === -1) { ids.push(v); }
                }
                lastFocus = document.activeElement;
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
                title.textContent = ''; noEl.textContent = '';
                email.textContent = ''; source.textContent = '';
                attach.innerHTML = ''; dateEl.textContent = '';
                reply.hidden = true; reply.removeAttribute('href');
                chip.hidden = true;
                closers[0].focus();
                show(Math.max(0, ids.indexOf(String(id))));
            }
            function close() {
                fetchAbort();
                modal.classList.remove('is-open');
                document.body.style.overflow = '';
                if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
            }

            document.addEventListener('click', function (e) {
                var link = e.target.closest && e.target.closest('.eln-open');
                if (link) {
                    e.preventDefault();
                    open(link.getAttribute('data-id'));
                }
            });
            prevB.addEventListener('click', function () { show(current - 1); });
            nextB.addEventListener('click', function () { show(current + 1); });
            Array.prototype.forEach.call(closers, function (b) { b.addEventListener('click', close); });
            modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
            document.addEventListener('keydown', function (e) {
                if (!modal.classList.contains('is-open')) { return; }
                if (e.key === 'Escape') { close(); }
                else if (e.key === 'ArrowLeft') { show(current - 1); }
                else if (e.key === 'ArrowRight') { show(current + 1); }
            });

            // 仪表盘点名字跳过来：带 eln_view=<id> 自动打开同一弹窗
            var auto = new URLSearchParams(window.location.search).get('eln_view');
            if (auto) { open(auto); }
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------- 仪表盘 */

    /** 仪表盘「最近询盘」小挂件：只有能看询盘列表的用户才显示 */
    public static function register_dashboard_widget(): void {
        if (!current_user_can('edit_posts')) {
            return;
        }
        wp_add_dashboard_widget(
            'eln_recent',
            self::t('dash_title'),
            array(__CLASS__, 'render_dashboard_widget')
        );
    }

    public static function render_dashboard_widget(): void {
        $limit = max(1, (int) apply_filters('eln_dashboard_limit', 5));
        $posts = get_posts(array(
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ));

        $counts = wp_count_posts(self::POST_TYPE);
        $total  = (int) ($counts->publish ?? 0);
        $today  = (int) (new WP_Query(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'date_query'     => array(array('after' => 'today midnight', 'inclusive' => true)),
        )))->found_posts;


        $all_url = admin_url('edit.php?post_type=' . self::POST_TYPE);
        ?>
        <style>
            /* 参照核心「活动 → 最近发布」：左边固定日期列 + 斑马纹 + 列表出血到挂件边缘 */
            .si-dash { margin: 0 -12px; }
            .si-dash__meta { margin: 0 0 8px; padding: 0 12px; color: #646970; font-size: 12px; }
            .si-dash__list { margin: 0; padding: 0; list-style: none; }
            .si-dash__item { display: grid; grid-template-columns: clamp(112px, calc(2vw + 88px), 150px) minmax(0, 1fr); column-gap: 10px; align-items: baseline; padding: 5px 12px; color: #646970; }
            .si-dash__item:nth-child(odd) { background-color: #f6f7f7; }
            .si-dash__date { font-variant-numeric: tabular-nums; }
            .si-dash__main { min-width: 0; }
            .si-dash__name { text-decoration: none; }
            .si-dash__sub { display: block; overflow: hidden; color: #646970; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
            .si-dash__empty { margin: 4px 0 0; padding: 0 12px; color: #646970; }
            .si-dash__foot { margin: 10px 0 0; padding: 0 12px; }
            .si-dash__foot .count { color: #646970; }
        </style>
        <div class="si-dash">
            <p class="si-dash__meta">
                <span><?php echo esc_html(sprintf(self::t('dash_today'), $today)); ?></span>
                <span aria-hidden="true">·</span>
                <span><?php echo esc_html(sprintf(self::t('dash_total'), $total)); ?></span>
            </p>
            <?php if (!$posts) : ?>
                <p class="si-dash__empty"><?php echo esc_html(self::t('dash_empty')); ?></p>
            <?php else : ?>
                <ul class="si-dash__list">
                    <?php foreach ($posts as $item) :
                        $detail = add_query_arg(
                            array('post_type' => self::POST_TYPE, 'eln_view' => $item->ID),
                            admin_url('edit.php')
                        );
                        $name   = (string) get_post_meta($item->ID, 'inquiry_name', true);
                        $name   = $name !== '' ? $name : $item->post_title;
                        $email  = (string) get_post_meta($item->ID, 'inquiry_email', true);
                        $source = self::record_source_label($item->ID);
                        $sub    = implode(' · ', array_filter(array($email, $source)));
                        $when   = self::dash_date_label((int) get_post_time('U', true, $item));
                        ?>
                        <li class="si-dash__item">
                            <span class="si-dash__date"><?php echo esc_html($when); ?></span>
                            <span class="si-dash__main">
                                <a class="si-dash__name" href="<?php echo esc_url($detail); ?>"><?php echo esc_html($name); ?></a>
                                <?php if ($sub !== '') : ?>
                                    <span class="si-dash__sub"><?php echo esc_html($sub); ?></span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="si-dash__foot">
                <a href="<?php echo esc_url($all_url); ?>"><?php echo esc_html(self::t('dash_all')); ?>
                    <span class="count">(<?php echo (int) $total; ?>)</span></a>
            </p>
        </div>
        <?php
    }

    /** 挂件里的时间：今天/昨天带时刻，同年只到日，跨年带年（口径同核心「最近发布」） */
    private static function dash_date_label(int $timestamp): string {
        if ($timestamp <= 0) {
            return '—';
        }
        $day   = wp_date('Y-m-d', $timestamp);
        $today = current_time('Y-m-d');
        $ytd   = current_datetime()->modify('-1 day')->format('Y-m-d');

        if ($day === $today) {
            return sprintf(self::t('dash_today_at'), wp_date('H:i', $timestamp));
        }
        if ($day === $ytd) {
            return sprintf(self::t('dash_yesterday_at'), wp_date('H:i', $timestamp));
        }
        if (wp_date('Y', $timestamp) === current_time('Y')) {
            return wp_date('n/j H:i', $timestamp);
        }
        return wp_date('Y/n/j', $timestamp);
    }

    /** 详情页入参校验（load-* 阶段执行，早于任何输出） */
    public static function guard_view_page(): void {
        if (!current_user_can('read')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }

        $post_id = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $post    = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die(esc_html(self::t('not_found_msg')), '', array('response' => 404));
        }
    }

    /** 只读详情页：只留邮件正文；元信息表与正文重复，已去掉 */
    public static function render_view_page(): void {
        if (!current_user_can('read')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }

        $post_id = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $post    = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die(esc_html(self::t('not_found_msg')), '', array('response' => 404));
        }

        $delete_url = current_user_can('delete_posts')
            ? wp_nonce_url(admin_url('admin-post.php?action=inquiry_delete&id=' . $post_id), 'inquiry_del_' . $post_id)
            : '';
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html(get_the_title($post)); ?>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::POST_TYPE)); ?>" class="page-title-action"><?php echo esc_html(self::t('back')); ?></a>
                <?php if ($delete_url !== '') : ?>
                    <a href="<?php echo esc_url($delete_url); ?>" class="page-title-action" style="color:#b32d2e;"
                       onclick="return confirm('<?php echo esc_js(self::t('confirm_delete')); ?>');"><?php echo esc_html(self::t('delete_action')); ?></a>
                <?php endif; ?>
            </h1>

            <h2 class="title"><?php echo esc_html(self::t('content_heading')); ?></h2>
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

    public static function register_template_page(): void {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            self::t('tpl_menu'),
            self::t('tpl_menu'),
            'manage_options',
            self::TPL_SLUG,
            array(__CLASS__, 'render_template_page')
        );
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            self::t('reply_menu'),
            self::t('reply_menu'),
            'manage_options',
            self::REPLY_SLUG,
            array(__CLASS__, 'render_reply_page')
        );
    }

    /** 两套模板各注册一个 option（分组不同，表单才互不覆盖） */
    public static function register_template_setting(): void {
        register_setting('eln_template_group', self::OPT_TEMPLATE, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_template'),
            'default'           => array(),
        ));
        register_setting('eln_autoreply_group', self::OPT_AUTOREPLY, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_autoreply'),
            'default'           => array(),
        ));
        register_setting('eln_reply_group', self::OPT_REPLY, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_reply'),
            'default'           => array(),
        ));
    }

    /** 「回信模板」保存时的清洗：占位符要原样保留，正文保留换行 */
    public static function sanitize_reply($input): array {
        $input = is_array($input) ? $input : array();
        return array(
            'subject' => sanitize_text_field((string) ($input['subject'] ?? '')),
            'body'    => sanitize_textarea_field((string) ($input['body'] ?? '')),
        );
    }

    /** 人工回信模板：option 为空（或整体为空串）时用默认 */
    public static function reply_tpl(): array {
        $tpl = (array) get_option(self::OPT_REPLY, array());
        $default = self::reply_template_default();
        return array(
            'subject' => trim((string) ($tpl['subject'] ?? '')) !== '' ? (string) $tpl['subject'] : $default['subject'],
            'body'    => trim((string) ($tpl['body'] ?? '')) !== '' ? (string) $tpl['body'] : $default['body'],
        );
    }

    /** 回信模板默认值（mailto 正文是纯文本，不认 HTML） */
    private static function reply_template_default(): array {
        return array(
            'subject' => 'Re: {{no}}',
            'body'    => "Dear {{name}},\n\nThank you for your inquiry (No. {{no}}, submitted {{date}}).\n\n\n\nBest regards,",
        );
    }

    /** 「回信模板」设置页：mailto 的主题/正文骨架，弹窗「回信」按钮据此生成链接 */
    public static function render_reply_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'email-log-notifications'));
        }
        $tpl = (array) get_option(self::OPT_REPLY, array());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::t('reply_menu')); ?></h1>
            <p><?php echo esc_html(self::t('reply_hint')); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('eln_reply_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="eln-reply-subject"><?php echo esc_html(self::t('reply_subject')); ?></label></th>
                        <td><input type="text" class="regular-text" id="eln-reply-subject"
                                   name="<?php echo esc_attr(self::OPT_REPLY); ?>[subject]"
                                   value="<?php echo esc_attr((string) ($tpl['subject'] ?? '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="eln-reply-body"><?php echo esc_html(self::t('reply_body')); ?></label></th>
                        <td><textarea class="large-text code" rows="10" id="eln-reply-body"
                                      name="<?php echo esc_attr(self::OPT_REPLY); ?>[body]"><?php echo esc_textarea((string) ($tpl['body'] ?? '')); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** 「询盘通知」保存时的清洗 */
    public static function sanitize_template($input): array {
        return self::sanitize_template_of($input, 'notify');
    }

    /**
     * 「自动确认」保存时的清洗。
     *
     * 页面一次只编辑一个语言组，所以这里要**合并**而不是整体覆盖：
     * 取出现有语言组，把提交上来的那组（`cur` 下标）替换掉，其余原样带回。
     * 校验也在这里做：语言标签只留小写字母/数字/连字符；第 0 组恒为默认组（lang 强制为空）。
     */
    public static function sanitize_autoreply($input): array {
        $input = is_array($input) ? $input : array();

        // 程序内直接 update_option(整份配置) 时会再走一遍这个过滤器：
        // 这种载荷带 groups、不带 cur，原样规范化即可，别当成「表单提交的某一组」覆盖掉第 0 组
        if (isset($input['groups']) && !isset($input['cur'])) {
            return array(
                'enabled' => empty($input['enabled']) ? '0' : '1',
                'groups'  => self::reply_groups(array('groups' => (array) $input['groups'])),
            );
        }

        $groups = self::reply_groups();
        $cur    = isset($input['cur']) ? max(0, (int) $input['cur']) : 0;
        if (!isset($groups[$cur])) {
            $cur = 0;
        }

        $lang = self::canonical_lang((string) sanitize_text_field((string) ($input['lang'] ?? '')));
        if ($cur === 0) {
            $lang = ''; // 默认组不带语言标签
        }

        $groups[$cur] = array(
            'lang'    => $lang,
            'subject' => sanitize_text_field((string) ($input['subject'] ?? '')),
            'html'    => self::sanitize_template_html((string) ($input['html'] ?? '')),
            'labels'  => self::sanitize_labels((array) ($input['labels'] ?? array())),
        );

        return array(
            // 复选框不勾选时根本不会出现在提交里，所以这里显式归一成 0/1
            'enabled' => empty($input['enabled']) ? '0' : '1',
            'groups'  => self::reply_groups(array('groups' => $groups)),
        );
    }

    /** 单个语言组的清洗（保存与实时预览共用，预览不落库） */
    private static function sanitize_group($input): array {
        $input = is_array($input) ? $input : array();
        $lang  = self::canonical_lang((string) sanitize_text_field((string) ($input['lang'] ?? '')));
        return array(
            'lang'    => $lang,
            'subject' => sanitize_text_field((string) ($input['subject'] ?? '')),
            'html'    => self::sanitize_template_html((string) ($input['html'] ?? '')),
            'labels'  => self::sanitize_labels((array) ($input['labels'] ?? array())),
        );
    }

    /** 字段标签覆盖表：键必须是合法字段名，值走文本清洗（空值保留 = 未覆盖） */
    private static function sanitize_labels(array $input): array {
        $labels = array();
        foreach ($input as $key => $label) {
            $key   = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
            $label = sanitize_text_field((string) $label);
            // 空值也要留：它表示"这个字段存在，但没自定义标签"，否则手动添加的字段保存后就消失
            if ($key !== '' && !in_array($key, self::META_FIELDS, true)) {
                $labels[$key] = $label;
            }
        }
        return $labels;
    }

    /** 保存时清洗：主题与标签走文本清洗，HTML 走自定义过滤（见 sanitize_template_html） */
    private static function sanitize_template_of($input, string $kind): array {
        $input = is_array($input) ? $input : array();

        return array(
            'subject' => sanitize_text_field((string) ($input['subject'] ?? '')),
            'html'    => self::sanitize_template_html((string) ($input['html'] ?? '')),
            'labels'  => self::sanitize_labels((array) ($input['labels'] ?? array())),
        );
    }

    /**
     * 自定义模板的清洗：只去掉脚本类标签与事件属性，**保留内联样式和 {{占位符}}**。
     *
     * 不能用 wp_kses：它会把 style 属性里的 `{{占位符}}` 当非法 CSS 值删掉，
     * 也会顺手改写内联样式——模板里想用占位符拼样式就没戏了。
     * 该字段仅 manage_options 可写，产物是邮件正文、不在站点里渲染。
     */
    private static function sanitize_template_html(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $dangerous = 'script|iframe|object|embed|form|input|button|link|meta|base';
        // 成对标签连同内容一起删
        $html = (string) preg_replace('#<(' . $dangerous . ')\b[^>]*>.*?</\1>#is', '', $html);
        // 自闭合/未闭合的同类标签
        $html = (string) preg_replace('#<(' . $dangerous . ')\b[^>]*/?>#i', '', $html);
        // on* 事件属性
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        // javascript: 伪协议
        $html = (string) preg_replace('#javascript:#i', '', $html);
        return $html;
    }

    /** 当前编辑的是哪套模板：notify（发给销售）/ autoreply（自动确认） */
    private static function current_kind(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kind = isset($_GET['kind']) ? sanitize_key((string) $_GET['kind']) : '';
        return $kind === 'autoreply' ? 'autoreply' : 'notify';
    }

    /** 模板页某个标签的地址（两个标签只差 kind 参数） */
    private static function template_tab_url(string $kind): string {
        $url = admin_url('edit.php?post_type=' . self::POST_TYPE . '&page=' . self::TPL_SLUG);
        return $kind === 'autoreply' ? add_query_arg('kind', 'autoreply', $url) : $url;
    }

    /** 自动确认里，当前正在编辑哪一个语言组（越界回落到默认组） */
    private static function current_slot(array $groups): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $slot = isset($_GET['slot']) ? max(0, (int) $_GET['slot']) : 0;
        return isset($groups[$slot]) ? $slot : 0;
    }

    /** 某个语言组的编辑地址 */
    private static function reply_slot_url(int $slot): string {
        return add_query_arg(
            array(
                'post_type' => self::POST_TYPE,
                'page'      => self::TPL_SLUG,
                'kind'      => 'autoreply',
                'slot'      => $slot,
            ),
            admin_url('edit.php')
        );
    }

    public static function render_template_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }

        $kind    = self::current_kind();
        $is_auto = $kind === 'autoreply';

        // 自动确认按浏览器语言分语言组：页面一次只编辑一组（索引 0 = 默认兜底组）
        $slots      = $is_auto ? self::reply_groups() : array();
        $slot       = $is_auto ? self::current_slot($slots) : 0;
        $slot_group = $is_auto ? $slots[$slot] : array();
        $enabled    = $is_auto ? (string) (self::tpl('autoreply')['enabled'] ?? '0') : '0';

        // 通知只有一份模板；自动确认取「当前语言组」这一份
        $tpl  = $is_auto ? $slot_group : self::tpl('notify');
        // 代码框显示的就是真正生效的模板源码：没自定义过就是默认源码
        $code = trim((string) $tpl['html']) !== '' ? (string) $tpl['html'] : self::default_template_html($kind);

        $base_args = array('kind' => $kind);
        if ($is_auto) {
            $base_args['slot'] = $slot;
        }
        // 测试发信 / 恢复默认都带上 kind（与 slot），nonce 也按 kind 分，免得在 A 标签页误伤 B 的配置
        $test  = wp_nonce_url(
            add_query_arg($base_args, admin_url('admin-post.php?action=inquiry_test_mail')),
            'inquiry_test_mail_' . $kind
        );
        $reset = wp_nonce_url(
            add_query_arg($base_args, admin_url('admin-post.php?action=inquiry_reset_template')),
            'inquiry_reset_template_' . $kind
        );
        $name  = self::option_name($kind);
        $group = $is_auto ? 'eln_autoreply_group' : 'eln_template_group';

        // phpcs:disable WordPress.Security.NonceVerification
        $notice = '';
        if (isset($_GET['inquiry_test'])) {
            $ok     = $_GET['inquiry_test'] === '1';
            $notice = '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>'
                    . esc_html(self::t($ok ? 'tpl_test_ok' : 'tpl_test_fail')) . '</p></div>';
        } elseif (isset($_GET['inquiry_reset'])) {
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html(self::t('tpl_reset_done')) . '</p></div>';
        } elseif (isset($_GET['inquiry_lang_added'])) {
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html(self::t('tpl_lang_added')) . '</p></div>';
        } elseif (isset($_GET['inquiry_lang_deleted'])) {
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html(self::t('tpl_lang_deleted')) . '</p></div>';
        } elseif (isset($_GET['inquiry_lang_exists'])) {
            $notice = '<div class="notice notice-warning is-dismissible"><p>' . esc_html(self::t('tpl_lang_exists')) . '</p></div>';
        }
        // phpcs:enable

        // 可改的字段标签：字段集与邮件预览共用 label_fields()，两处永远对得上；
        // 传当前模板的 labels 进去，列表顺序 = 用户拖出来的行序
        $label_meta        = self::label_fields((array) ($tpl['labels'] ?? array()));
        $label_fields      = (array) $label_meta['fields'];
        $labels_are_sample = (bool) $label_meta['sample'];

        // 占位符分组 + 悬浮说明（占位符只输出值，标签由源码自己写）
        $placeholder_groups = array(
            array('{{rows}}'),
            array('{{name}}', '{{email}}', '{{no}}', '{{source}}', '{{form_type}}'),
            array('{{time}}', '{{time_local}}', '{{ip}}', '{{lang}}', '{{time_zone}}'),
        );
        // 需要前端配合才有值的占位符：chip 上标 *，下面给一条图例
        $placeholder_needs = array(
            '{{rows}}'       => true,
            '{{name}}'       => true,
            '{{email}}'      => true,
            '{{source}}'     => true,
            '{{form_type}}'  => true,
            '{{time_local}}' => true,
            '{{lang}}'       => true,
            '{{time_zone}}'  => true,
        );
        $placeholder_hints = array(
            '{{rows}}'        => '字段表格：姓名、邮箱、内容…（表头用字段标签，没配就用表单自己的措辞）',
            '{{name}}'        => '访客姓名',
            '{{email}}'       => '访客邮箱',
            '{{no}}'          => '询盘编号，如 20261105001',
            '{{source}}'      => '来源页面的英文标题，如 Contact Us',
            '{{form_type}}'   => '页面表单 / 弹窗表单',
            '{{time}}'        => '提交时间（站点时区，服务端收到请求的时刻）',
            '{{time_local}}'  => '提交时间（换算到访客时区）',
            '{{ip}}'          => '提交者 IP（服务端识别）',
            '{{lang}}'        => '浏览器语言，如 zh-CN',
            '{{time_zone}}'   => '访客时区，如 Asia/Shanghai',
        );
        $legend = sprintf(
            '%s <code>browserLanguage</code> → {{lang}}，<code>timezone</code> → {{time_zone}} / {{time_local}}，<code>pageTitle</code> / <code>pageUrl</code> → {{source}}。%s',
            self::t('tpl_legend_head'),
            self::t('tpl_legend_tail')
        );
        ?>
        <style>
            .si-tpl-lede { margin: 6px 0 16px; color: #646970; }
            .si-tpl-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 20px; align-items: start; }
            @media (max-width: 1280px) { .si-tpl-grid { grid-template-columns: 1fr; } }

            /* 卡片外壳：头（标题 + 操作）/ 语言条 / 表单体 */
            .si-tpl-card { background: #fff; border: 1px solid #dcdcde; }
            .si-tpl-card__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 18px; border-bottom: 1px solid #f0f0f1; }
            .si-tpl-card__head h2 { margin: 0; font-size: 15px; line-height: 1.5; }
            .si-tpl-card__head .description { margin: 0; }
            .si-tpl-card__actions { display: flex; gap: 8px; }
            .si-tpl-card__body { padding: 16px 18px 20px; }

            /* 语言组 */
            .si-lang-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 18px; background: #f6f7f7; border-bottom: 1px solid #f0f0f1; }
            .si-lang-bar__label { font-size: 12px; font-weight: 600; color: #50575e; }
            .si-lang-list { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0; padding: 0; list-style: none; }
            .si-lang-item { display: inline-flex; align-items: stretch; }
            .si-lang-tab { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 3px; color: #2271b1; font-size: 12px; text-decoration: none; }
            .si-lang-tab:hover { border-color: #2271b1; color: #135e96; }
            .si-lang-tab.is-active { background: #2271b1; border-color: #2271b1; color: #fff; }
            .si-lang-item.has-del .si-lang-tab { border-radius: 3px 0 0 3px; border-right: 0; }
            .si-lang-del { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 26px; border: 1px solid #c3c4c7; border-radius: 0 3px 3px 0; background: #fff; color: #b32d2e; font-size: 15px; line-height: 1; text-decoration: none; }
            .si-lang-del:hover { background: #fcf0f1; border-color: #b32d2e; color: #b32d2e; }
            .si-lang-add { display: flex; align-items: center; gap: 6px; margin: 0 0 0 auto; }
            .si-lang-hint { flex: 0 0 100%; margin: 0; font-size: 12px; color: #646970; }

            /* 表单 */
            .si-tpl-row { display: flex; gap: 18px; flex-wrap: wrap; margin: 0 0 18px; }
            .si-tpl-field { display: flex; flex-direction: column; gap: 6px; }
            .si-tpl-field--grow { flex: 1 1 300px; }
            .si-tpl-field label { font-weight: 600; }
            .si-tpl-field .description { margin: 0; }
            .si-tpl-check { display: flex; align-items: center; gap: 8px; font-weight: 600; margin: 0 0 4px; }
            .si-tpl-field--check { margin: 0 0 18px; }
            .si-lang-code { flex: 0 0 220px; }

            /* 字段标签（可拖动） */
            .si-tpl-details { border: 1px solid #e2e8ea; background: #fafafa; padding: 0 14px; margin: 0 0 18px; }
            .si-tpl-details > summary { display: flex; align-items: center; gap: 8px; padding: 10px 0; font-weight: 600; cursor: pointer; list-style: none; }
            .si-tpl-details > summary::-webkit-details-marker { display: none; }
            .si-tpl-details > summary::before { content: "\25B8"; display: inline-block; width: 14px; color: #8a979d; font-size: 12px; line-height: 1; transition: transform .15s; }
            .si-tpl-details[open] > summary::before { transform: rotate(90deg); }
            .si-tpl-details > p { margin: 0 0 8px; }
            .si-tpl-badge { margin-left: auto; min-width: 20px; padding: 0 6px; border-radius: 10px; background: #e2e8ea; color: #50575e; font-size: 11px; line-height: 20px; text-align: center; }
            .si-tpl-labels { display: flex; flex-direction: column; gap: 6px; margin: 0 0 10px; }
            .si-tpl-label-row { display: grid; grid-template-columns: 18px 150px minmax(0, 1fr); align-items: center; gap: 10px; padding: 5px 8px; background: #fff; border: 1px solid #e2e8ea; }
            .si-tpl-label-row .si-sort { cursor: grab; color: #8a979d; font-size: 13px; letter-spacing: -3px; user-select: none; }
            .si-tpl-label-row .si-sort:active { cursor: grabbing; }
            .si-tpl-label-key { font-size: 11px; color: #646970; font-family: Consolas, Monaco, monospace; }
            .si-tpl-labels .ui-sortable-placeholder { visibility: visible !important; height: 38px; border: 1px dashed #c3c4c7; background: #f6f7f7; }
            .si-tpl-label-row.ui-sortable-helper { box-shadow: 0 3px 8px rgba(0,0,0,.12); border-color: #8a979d; }
            .si-labels-hint { margin: 0 0 12px !important; }
            @media (max-width: 782px) { .si-tpl-label-row { grid-template-columns: 18px minmax(0, 1fr); } .si-tpl-label-key { grid-column: 2; } }

            /* 源码区 */
            .si-tpl-section-title { margin: 0 0 8px; font-size: 14px; }
            .si-tpl-toolbar { display: flex; flex-direction: column; gap: 8px; margin: 0 0 10px; padding: 10px 12px; background: #f6f7f7; border: 1px solid #e2e8ea; }
            .si-tpl-toolbar__row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
            .si-tpl-toolbar__row .description { margin: 0; }
            .si-tpl-toolbar__label { color: #646970; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; }
            .si-tpl-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
            .si-tpl-chips .button { margin: 0; }
            .si-tpl-chips__sep { width: 1px; height: 16px; margin: 0 3px; background: #dcdcde; }
            .si-tpl-chip { font-family: Consolas, Monaco, monospace; font-size: 11px; }
            .si-tpl-legend { margin: 0; font-size: 12px; color: #646970; }
            .si-tpl-legend code { font-size: 11px; }
            .si-req { color: #d63638; margin-left: 1px; }
            .CodeMirror { height: 560px; border: 1px solid #dcdcde; }
            .si-tpl-save { margin: 14px 0 0; }

            /* 预览 */
            .si-tpl-preview { position: sticky; top: 32px; }
            .si-preview-body { padding: 12px; background: #f6f7f7; }
            #si-preview { display: block; width: 100%; height: 760px; border: 0; background: #fff; }
            .si-preview-note { margin: 10px 0 0; padding: 0 18px 14px; color: #646970; font-size: 12px; }
        </style>

        <div class="wrap">
            <h1><?php echo esc_html(self::t('tpl_menu')); ?></h1>
            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <nav class="nav-tab-wrapper" style="margin:0 0 6px;">
                <?php
                $tabs = array(
                    'notify'    => self::t('tpl_tab_notify'),
                    'autoreply' => self::t('tpl_tab_reply'),
                );
                foreach ($tabs as $tab_kind => $tab_label) :
                    ?>
                    <a href="<?php echo esc_url(self::template_tab_url($tab_kind)); ?>"
                       class="nav-tab<?php echo $tab_kind === $kind ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </nav>

            <p class="si-tpl-lede"><?php echo esc_html(self::t('tpl_intro')); ?></p>

            <div class="si-tpl-grid">
                <div class="si-tpl-card">
                    <div class="si-tpl-card__head">
                        <h2><?php echo esc_html($is_auto ? self::t('tpl_tab_reply') : self::t('tpl_tab_notify')); ?></h2>
                        <div class="si-tpl-card__actions">
                            <a class="button" href="<?php echo esc_url($test); ?>"
                               title="<?php echo esc_attr(self::t('tpl_test_hint')); ?>"><?php echo esc_html(self::t('tpl_test')); ?></a>
                            <a class="button" href="<?php echo esc_url($reset); ?>"
                               title="<?php echo esc_attr(self::t('tpl_reset')); ?>"
                               onclick="return confirm('<?php echo esc_js(self::t('tpl_reset')); ?>?');"><?php echo esc_html(self::t('tpl_reset')); ?></a>
                        </div>
                    </div>

                    <?php if ($is_auto) : ?>
                        <div class="si-lang-bar">
                            <span class="si-lang-bar__label"><?php echo esc_html(self::t('tpl_lang_bar')); ?></span>
                            <ul class="si-lang-list">
                                <?php
                                foreach ($slots as $i => $item) :
                                    // 默认组不可删；其它组的删除入口就放在各自的标签上
                                    $del_url = $i === 0 ? '' : wp_nonce_url(
                                        add_query_arg(
                                            array('action' => 'inquiry_reply_dellang', 'slot' => $i),
                                            admin_url('admin-post.php')
                                        ),
                                        'inquiry_reply_lang_' . $i
                                    );
                                    ?>
                                    <li class="si-lang-item<?php echo $del_url !== '' ? ' has-del' : ''; ?>">
                                        <a class="si-lang-tab<?php echo $i === $slot ? ' is-active' : ''; ?>"
                                           href="<?php echo esc_url(self::reply_slot_url($i)); ?>"
                                           title="<?php echo esc_attr($item['lang'] === '' ? self::t('tpl_lang_default_hint') : self::lang_label((string) $item['lang'])); ?>"><?php
                                            echo esc_html(self::lang_label((string) $item['lang']));
                                        ?></a>
                                        <?php if ($del_url !== '') : ?>
                                            <a class="si-lang-del" href="<?php echo esc_url($del_url); ?>"
                                               title="<?php echo esc_attr(self::t('tpl_lang_delete')); ?>"
                                               onclick="return confirm('<?php echo esc_js(self::t('tpl_lang_delete_confirm')); ?>');">×</a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="si-lang-add">
                                <input type="hidden" name="action" value="inquiry_reply_addlang" />
                                <?php // 独立字段名，避免和下面 settings_fields 的 _wpnonce 撞 id ?>
                                <?php wp_nonce_field('inquiry_reply_lang', '_wpnonce_lang'); ?>
                                <select name="newlang" aria-label="<?php echo esc_attr(self::t('tpl_lang_code')); ?>">
                                    <option value=""><?php echo esc_html(self::t('tpl_lang_add')); ?>…</option>
                                    <?php
                                    $used_langs = array_map(static function ($g) {
                                        return (string) $g['lang'];
                                    }, $slots);
                                    foreach (self::lang_options() as $opt_code => $opt_label) :
                                        if (in_array($opt_code, $used_langs, true)) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($opt_code); ?>"><?php echo esc_html($opt_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button button-small"><?php echo esc_html(self::t('tpl_lang_add')); ?></button>
                            </form>
                            <p class="si-lang-hint"><?php echo esc_html(self::t('tpl_lang_hint')); ?></p>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="options.php" id="si-tpl-form" class="si-tpl-card__body">
                        <?php settings_fields($group); ?>

                        <?php if ($is_auto) : ?>
                            <input type="hidden" name="<?php echo esc_attr($name); ?>[cur]" value="<?php echo (int) $slot; ?>" />
                            <div class="si-tpl-field--check">
                                <label class="si-tpl-check">
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1"
                                           <?php checked('1', $enabled); ?> />
                                    <?php echo esc_html(self::t('tpl_reply_on')); ?>
                                </label>
                                <p class="description"><?php echo esc_html(self::t('tpl_reply_on_hint')); ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="si-tpl-row">
                            <?php if ($is_auto) : ?>
                                <div class="si-tpl-field si-lang-code">
                                    <label for="si-lang"><?php echo esc_html(self::t('tpl_lang_code')); ?></label>
                                    <?php if ($slot === 0) : ?>
                                        <input type="text" id="si-lang" value="<?php echo esc_attr(self::t('tpl_lang_default')); ?>" disabled />
                                    <?php else : ?>
                                        <?php
                                        $lang_options = self::lang_options();
                                        $current_lang = (string) $slot_group['lang'];
                                        // 兼容手改过的旧值：不在候选表里也要能看见、能保存
                                        if ($current_lang !== '' && !isset($lang_options[$current_lang])) {
                                            $lang_options = array($current_lang => self::lang_label($current_lang)) + $lang_options;
                                        }
                                        ?>
                                        <select name="<?php echo esc_attr($name); ?>[lang]" id="si-lang">
                                            <?php foreach ($lang_options as $opt_code => $opt_label) : ?>
                                                <option value="<?php echo esc_attr($opt_code); ?>" <?php selected($current_lang, $opt_code); ?>><?php echo esc_html($opt_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="si-tpl-field si-tpl-field--grow">
                                <label for="si-subject"><?php echo esc_html(self::t('tpl_subject')); ?></label>
                                <input name="<?php echo esc_attr($name); ?>[subject]" id="si-subject" type="text"
                                       class="large-text" value="<?php echo esc_attr((string) $tpl['subject']); ?>"
                                       placeholder="<?php echo esc_attr($is_auto
                                           ? self::t('email_subject_reply')
                                           : sprintf(self::t('email_subject'), '{{name}} <{{email}}>')); ?>" />
                                <p class="description"><?php echo esc_html(self::t('tpl_subject_hint')); ?></p>
                            </div>
                        </div>

                        <details class="si-tpl-details"<?php echo $label_fields ? '' : ' open'; ?>>
                            <summary>
                                <?php echo esc_html(self::t('tpl_labels')); ?>
                                <span class="si-tpl-badge"><?php echo count($label_fields); ?></span>
                            </summary>
                            <p class="description"><?php echo esc_html($is_auto ? self::t('tpl_reply_labels_hint') : self::t('tpl_labels_hint')); ?></p>
                            <?php if ($labels_are_sample) : ?>
                                <p class="description"><em><?php echo esc_html(self::t('tpl_labels_sample')); ?></em></p>
                            <?php endif; ?>
                            <?php if ($label_fields) : ?>
                                <div class="si-tpl-labels" data-sortable-labels>
                                    <?php foreach ($label_fields as $field_key => $default_label) : ?>
                                        <div class="si-tpl-label-row">
                                            <span class="si-sort" title="<?php echo esc_attr(self::t('tpl_labels_sort')); ?>" aria-hidden="true">⠿</span>
                                            <span class="si-tpl-label-key"><?php echo esc_html($field_key); ?></span>
                                            <input type="text"
                                                   name="<?php echo esc_attr($name); ?>[labels][<?php echo esc_attr($field_key); ?>]"
                                                   value="<?php echo esc_attr((string) ($tpl['labels'][$field_key] ?? '')); ?>"
                                                   placeholder="<?php echo esc_attr($default_label); ?>" />
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description si-labels-hint"><?php echo esc_html(self::t('tpl_labels_order_hint')); ?></p>
                            <?php endif; ?>
                        </details>

                        <h2 class="si-tpl-section-title"><label for="si-html"><?php echo esc_html(self::t('tpl_html')); ?></label></h2>
                        <div class="si-tpl-toolbar">
                            <div class="si-tpl-toolbar__row">
                                <button type="button" class="button" id="si-insert-media"><?php echo esc_html(self::t('tpl_media')); ?></button>
                                <button type="button" class="button" id="si-format-code"><?php echo esc_html(self::t('tpl_format')); ?></button>
                                <span class="description"><?php echo esc_html(self::t('tpl_media_hint')); ?></span>
                            </div>
                            <div class="si-tpl-toolbar__row">
                                <span class="si-tpl-toolbar__label"><?php echo esc_html(self::t('tpl_placeholders')); ?></span>
                                <div class="si-tpl-chips">
                                    <?php foreach ($placeholder_groups as $group) : ?>
                                        <?php foreach ($group as $ph) : ?>
                                            <button type="button" class="button button-small si-tpl-chip"
                                                    data-insert-ph="<?php echo esc_attr($ph); ?>"
                                                    title="<?php echo esc_attr($ph . ' — ' . ($placeholder_hints[$ph] ?? '')); ?>"><?php echo esc_html($ph); ?><?php if (isset($placeholder_needs[$ph])) : ?><span class="si-req">*</span><?php endif; ?></button>
                                        <?php endforeach; ?>
                                        <span class="si-tpl-chips__sep" aria-hidden="true"></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="si-tpl-legend"><?php echo wp_kses_post($legend); ?></p>
                        </div>
                        <textarea name="<?php echo esc_attr($name); ?>[html]" id="si-html" rows="22" class="large-text code"><?php echo esc_textarea($code); ?></textarea>
                        <p class="description"><?php echo esc_html(self::t('tpl_html_hint')); ?></p>
                        <p class="si-tpl-save"><?php submit_button(null, 'primary', 'submit', false); ?></p>
                    </form>
                </div>

                <div class="si-tpl-card si-tpl-preview">
                    <div class="si-tpl-card__head">
                        <h2><?php echo esc_html(self::t('tpl_preview')); ?></h2>
                        <span class="description"><?php echo esc_html(self::t('tpl_preview_hint')); ?></span>
                    </div>
                    <div class="si-preview-body">
                        <iframe id="si-preview" title="<?php echo esc_attr(self::t('tpl_preview')); ?>"></iframe>
                    </div>
                    <p class="si-preview-note"><?php echo esc_html(self::t('tpl_sample_note')); ?>: Jane Doe &lt;jane.doe@example.com&gt;</p>
                </div>
            </div>
        </div>
        <?php
    }

    /** 当前是否在「邮件模板」页 */
    private static function is_template_screen(): bool {
        // phpcs:disable WordPress.Security.NonceVerification
        return isset($_GET['page'], $_GET['post_type'])
            && $_GET['page'] === self::TPL_SLUG
            && $_GET['post_type'] === self::POST_TYPE;
        // phpcs:enable
    }

    /** 模板页资源：媒体库 + WP 自带 CodeMirror（代码高亮）+ jQuery UI 排序（字段标签拖动） */
    public static function enqueue_template_assets(): void {
        if (!self::is_template_screen()) {
            return;
        }
        wp_enqueue_media();
        // 字段标签的顺序就是邮件里的行序，用 WP 自带的 sortable，不额外打包拖拽库
        wp_enqueue_script('jquery-ui-sortable');
        $settings = wp_enqueue_code_editor(array('type' => 'text/html'));
        self::$code_editor_settings = is_array($settings) ? $settings : null;
    }

    /** 模板页脚本：插入占位符 / 插入媒体 / 左侧编辑右侧实时预览 */
    public static function print_template_script(): void {
        if (!self::is_template_screen()) {
            return;
        }

        $config = array(
            'ajax'         => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('inquiry_preview'),
            'codeSettings' => self::$code_editor_settings,
            'mediaTitle'   => self::t('tpl_media'),
            // 两个标签页共用一个渲染器：option 名与 kind 都从 PHP 传，JS 不写死
            'name'         => self::option_name(self::current_kind()),
            'kind'         => self::current_kind(),
        );

        $js = <<<'JS'
(function () {
  function boot() {
    var cfg = window.SiteInqTpl || {};
    var form = document.getElementById('si-tpl-form');
    var ta = document.getElementById('si-html');
    var preview = document.getElementById('si-preview');
    if (!form || !ta || !preview) return;

    var cm = null;
    if (window.wp && wp.codeEditor && cfg.codeSettings) {
      try {
        // 关掉 HTML 校验：邮件模板里的 {{占位符}} 会被 htmlhint 判成错误，行号槽全是感叹号
        var settings = JSON.parse(JSON.stringify(cfg.codeSettings));
        if (settings.codemirror) {
          delete settings.codemirror.lintWith;
          settings.codemirror.lint = false;
          settings.codemirror.gutters = (settings.codemirror.gutters || []).filter(function (g) {
            return g !== 'CodeMirror-lint-markers';
          });
        }
        var inst = wp.codeEditor.initialize(ta, settings);
        cm = inst.codemirror || null;
        if (cm) {
          // 初始化后再次清掉 lint 槽：WP 的默认 gutters 会在实例化时把它加回来
          cm.setOption('lint', false);
          cm.setOption('gutters', (cm.getOption('gutters') || []).filter(function (g) {
            return g !== 'CodeMirror-lint-markers';
          }));
          // 槽位的 DOM 节点不会随 option 立即移除，手动清掉
          var stale = document.querySelector('.CodeMirror-lint-markers');
          if (stale && stale.parentNode) stale.parentNode.removeChild(stale);
          cm.refresh();
          cm.setSize(null, 620);
        }
      } catch (e) { /* 高亮失败也不影响手写 */ }
    }

    /** 极简 HTML 缩进：只在标签边界换行，按每行的净开合数计算层级 */
    function formatHtml(src) {
      var voids = { br: 1, hr: 1, img: 1, input: 1, meta: 1, link: 1, area: 1, base: 1, col: 1, embed: 1, source: 1, track: 1, wbr: 1 };
      var lines = src.replace(/>\s*</g, '>\n<').split('\n');
      var depth = 0;
      var out = [];

      function tagName(tag) {
        var m = tag.match(/^<\/?([a-zA-Z0-9-]+)/);
        return m ? m[1].toLowerCase() : '';
      }

      for (var i = 0; i < lines.length; i++) {
        var line = lines[i].trim();
        if (!line) continue;

        var opens = 0;
        var closes = 0;
        var tags = line.match(/<[a-zA-Z][^>]*>/g) || [];
        for (var j = 0; j < tags.length; j++) {
          if (/\/>$/.test(tags[j])) continue;      // 自闭合
          if (voids[tagName(tags[j])]) continue;   // void 标签
          opens++;
        }
        var closeTags = line.match(/<\/[a-zA-Z][^>]*>/g) || [];
        closes = closeTags.length;

        var net = opens - closes;
        // 以闭合标签开头的行，按"内层"缩进
        var indent = /^<\//.test(line) ? Math.max(0, depth + net) : depth;
        out.push(new Array(indent + 1).join('  ') + line);
        depth = Math.max(0, depth + net);
      }
      return out.join('\n');
    }

    var formatBtn = document.getElementById('si-format-code');    if (formatBtn) {
      formatBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var formatted = formatHtml(cm ? cm.getValue() : ta.value);
        if (cm) cm.setValue(formatted);
        else { ta.value = formatted; schedule(); }
      });
    }

    function insert(text) {
      if (cm) { cm.replaceSelection(text); cm.focus(); }
      else {
        var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
        ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + text.length;
        ta.focus();
      }
      schedule();
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-insert-ph]'), function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        insert(btn.getAttribute('data-insert-ph'));
      });
    });

    // 字段标签拖动排序：DOM 顺序 = 保存顺序 = {{rows}} 的行序
    if (window.jQuery && jQuery.fn.sortable) {
      var labelList = document.querySelector('[data-sortable-labels]');
      if (labelList) {
        jQuery(labelList).sortable({
          items: '> .si-tpl-label-row',
          handle: '.si-sort',
          axis: 'y',
          placeholder: 'ui-sortable-placeholder',
          forcePlaceholderSize: true,
          update: function () { schedule(); }
        });
      }
    }

    var mediaBtn = document.getElementById('si-insert-media');
    if (mediaBtn) {
      mediaBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!window.wp || !wp.media) return;
        var frame = wp.media({ title: cfg.mediaTitle, library: { type: ['image', 'video'] }, multiple: false });
        frame.on('select', function () {
          var a = frame.state().get('selection').first().toJSON();
          var alt = (a.alt || '').replace(/"/g, '&quot;');
          var snippet = a.type === 'video'
            ? '<video src="' + a.url + '" controls style="max-width:100%;height:auto;display:block;"></video>'
            : '<img src="' + a.url + '" alt="' + alt + '" width="600" style="max-width:100%;height:auto;display:block;border:0;">';
          insert(snippet);
        });
        frame.open();
      });
    }

    var timer = null;
    function collect() {
      var out = {};
      var prefix = cfg.name + '[';
      var els = form.querySelectorAll('[name^="' + prefix + '"]');
      Array.prototype.forEach.call(els, function (el) {
        // 未勾选的复选框不会随表单提交，预览里也不该当成 enabled=1
        if (el.type === 'checkbox' && !el.checked) return;
        // "eln_template[labels][fullName]" → "labels][fullName"，
        // 拼回去正好是 template[labels][fullName]，PHP 侧的嵌套解析照旧
        var inner = el.name.slice(prefix.length, el.name.length - 1);
        if (inner) out[inner] = el.value;
      });
      return out;
    }

    function refresh() {
      var params = new URLSearchParams();
      params.set('action', 'inquiry_preview');
      params.set('nonce', cfg.nonce);
      if (cfg.kind) params.set('kind', cfg.kind);
      var data = collect();
      Object.keys(data).forEach(function (k) { params.set('template[' + k + ']', data[k]); });

      fetch(cfg.ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      }).then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.success && j.data && j.data.html) preview.srcdoc = j.data.html;
        })
        .catch(function () {});
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(refresh, 600); }

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    if (cm) {
      cm.on('change', function () {
        cm.save(); // 同步回 textarea：预览取值和表单提交都读它
        schedule();
      });
    }

    refresh();
  }

  // 等页脚里其它脚本（wp.media / code-editor）都就绪再初始化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
JS;

        echo '<script>window.SiteInqTpl=' . wp_json_encode($config) . ';</script>' . "\n";
        echo '<script>' . "\n" . $js . "\n" . '</script>' . "\n";
    }

    /** 实时预览：拿表单里未保存的值渲染一封示例邮件 */
    public static function ajax_preview(): void {
        check_ajax_referer('inquiry_preview', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $kind = isset($_POST['kind']) ? sanitize_key((string) $_POST['kind']) : 'notify';
        $kind = $kind === 'autoreply' ? 'autoreply' : 'notify';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw    = isset($_POST['template']) && is_array($_POST['template']) ? wp_unslash($_POST['template']) : array();
        $fields = self::sample_fields();

        if ($kind === 'autoreply') {
            // 预览看的是「当前语言组」这一份，不走保存逻辑（不做跨组合并）
            $tpl = self::sanitize_group($raw);
            if ($tpl['lang'] !== '') {
                // 让预览里的 {{lang}} 也跟着该组语言走
                $fields['browserLanguage'] = $tpl['lang'];
            }
        } else {
            $tpl = self::sanitize_template_of($raw, $kind);
        }

        $html = self::build_body($fields, array(), $tpl, '', $kind);

        wp_send_json_success(array('html' => $html, 'kind' => $kind));
    }

    /**
     * 发一封测试邮件。
     *
     * 收件人固定用询盘收件人（admin_email / filter eln_recipient），
     * 也就是 sales03@leflexo.com —— 两套模板都往同一个信箱发，方便对比。
     * 自动确认页发的是「当前语言组」的模板，不是默认组。
     */
    public static function handle_test_mail(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }
        $kind = self::current_kind();
        check_admin_referer('inquiry_test_mail_' . $kind);

        if ($kind === 'autoreply') {
            $slots = self::reply_groups();
            $slot  = self::current_slot($slots);
            $tpl   = $slots[$slot];
        } else {
            $slot = 0;
            $tpl  = self::tpl('notify');
        }

        $fields   = self::sample_fields();
        if (!empty($tpl['lang'])) {
            $fields['browserLanguage'] = (string) $tpl['lang'];
        }
        $body     = self::build_body($fields, array(), $tpl, '', $kind);
        $subject  = self::build_subject($fields, $tpl, $kind);
        $to       = (string) apply_filters('eln_recipient', get_option('admin_email'));

        // 和正式发信走同一个封装：补纯文本正文、失败原因也照同一套规则记录
        $sent = self::mail(
            $to,
            '[TEST] ' . $subject,
            $body,
            array('Content-Type: text/html; charset=UTF-8')
        );

        wp_safe_redirect(add_query_arg(
            array('page' => self::TPL_SLUG, 'kind' => $kind, 'slot' => $slot, 'inquiry_test' => $sent ? '1' : '0'),
            admin_url('edit.php?post_type=' . self::POST_TYPE)
        ));
        exit;
    }

    /**
     * 恢复默认。
     *   通知：删 option（回到默认源码）
     *   自动确认：只清空**当前语言组**的三项内容，保留语言分组与开关
     *            （整体删 option 会连 enabled 一起丢掉，等于悄悄关掉功能）
     */
    public static function handle_reset_template(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }
        $kind = self::current_kind();
        check_admin_referer('inquiry_reset_template_' . $kind);

        $slot = 0;
        if ($kind === 'autoreply') {
            $cfg    = self::tpl('autoreply');
            $groups = self::reply_groups($cfg);
            $slot   = self::current_slot($groups);
            $groups[$slot]['subject'] = '';
            $groups[$slot]['html']    = '';
            $groups[$slot]['labels']  = array();
            update_option(self::OPT_AUTOREPLY, array(
                'enabled' => empty($cfg['enabled']) ? '0' : '1',
                'groups'  => $groups,
            ), false);
        } else {
            delete_option(self::OPT_TEMPLATE);
        }

        wp_safe_redirect(add_query_arg(
            array('page' => self::TPL_SLUG, 'kind' => $kind, 'slot' => $slot, 'inquiry_reset' => '1'),
            admin_url('edit.php?post_type=' . self::POST_TYPE)
        ));
        exit;
    }

    /** 自动确认：新增一个语言组（语言代码来自页面顶部的小表单） */
    public static function handle_add_reply_lang(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }
        check_admin_referer('inquiry_reply_lang', '_wpnonce_lang');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $lang = self::canonical_lang((string) sanitize_text_field(wp_unslash($_POST['newlang'] ?? '')));

        $cfg    = self::tpl('autoreply');
        $groups = self::reply_groups($cfg);

        if ($lang === '') {
            wp_safe_redirect(self::reply_slot_url(0));
            exit;
        }

        foreach ($groups as $i => $group) {
            if ($group['lang'] === $lang) {
                wp_safe_redirect(add_query_arg('inquiry_lang_exists', 1, self::reply_slot_url($i)));
                exit;
            }
        }

        $groups[] = self::empty_group($lang);
        update_option(self::OPT_AUTOREPLY, array(
            'enabled' => empty($cfg['enabled']) ? '0' : '1',
            'groups'  => $groups,
        ), false);

        wp_safe_redirect(add_query_arg('inquiry_lang_added', 1, self::reply_slot_url(count($groups) - 1)));
        exit;
    }

    /** 自动确认：删除一个语言组（默认组不可删） */
    public static function handle_delete_reply_lang(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', '', array('response' => 403));
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $slot = isset($_GET['slot']) ? max(0, (int) $_GET['slot']) : 0;
        check_admin_referer('inquiry_reply_lang_' . $slot);

        $cfg    = self::tpl('autoreply');
        $groups = self::reply_groups($cfg);

        if ($slot > 0 && isset($groups[$slot])) {
            unset($groups[$slot]);
            $groups = array_values($groups);
            update_option(self::OPT_AUTOREPLY, array(
                'enabled' => empty($cfg['enabled']) ? '0' : '1',
                'groups'  => $groups,
            ), false);
        }

        wp_safe_redirect(add_query_arg('inquiry_lang_deleted', 1, self::reply_slot_url(0)));
        exit;
    }
}

Email_Log_Notifications::init();
