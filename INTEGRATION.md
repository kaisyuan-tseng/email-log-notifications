# Email Log & Notifications · 前端对接说明

一份给**任何前端**看的接入文档。插件只认 HTTP 与 multipart，不关心你用 React、Vue、Svelte、Astro、jQuery 还是纯 HTML 表单。

---

## 1. 设计原则

| 原则 | 具体做法 |
|---|---|
| 框架无关 | 只暴露一个 `POST` 端点，请求体是 `multipart/form-data`；不用 JSON、不用非标 header、不要求特定字段顺序 |
| 零 JS 依赖 | 插件不注册任何前端脚本；前端自己决定怎么收集表单 |
| 字段自由 | 字段名由前端定（`fullName`、`your_name`、`contact[0]` 都可以），插件按到达顺序渲染 |
| 标签由前端给 | 每个字段的显示名通过 `fieldLabels[字段名]=标签` 一起提交；插件不内置任何字段表 |
| 单请求完成 | 文本 + 附件 + 元信息一次 multipart 提交，没有分步上传、没有轮询 |

---

## 2. 接口契约

```
POST /wp-json/email-log-notifications/v1/inquiry
Content-Type: multipart/form-data（浏览器自动带 boundary，不要手写）
```

### 2.1 必填字段

默认 `fullName`、`email`、`message`。可通过 filter 整组替换（见 §7）：

```php
add_filter('eln_required_fields', fn() => ['name', 'mail', 'body']);
```

校验规则：非空（去首尾空白）；`email` 额外走 `is_email()`。

### 2.2 元信息（可选，但强烈建议）

| 字段 | 说明 |
|---|---|
| `formVariant` | `page`（页面内嵌表单）/ `modal`（弹窗） |
| `pageUrl` | 提交页面的完整 URL |
| `pageTitle` | 当前语言下的页面标题（已去品牌后缀） |
| `pageTitleEn` | 该页面的**英文**标题；用于后台统一来源口径 |
| `locale` | 页面语言，如 `zh-hans` / `en` |
| `browserLanguage` | 浏览器语言，如 `zh-CN` / `en-US`（取 `navigator.languages[0]`） |
| `timezone` | 用户时区，IANA 标识如 `Asia/Shanghai`（取 `Intl.DateTimeFormat().resolvedOptions().timeZone`） |

- `browserLanguage` / `timezone` 服务端会校验（BCP47 粗校验 / PHP 时区库），不合格**静默丢弃**，不影响提交；缺失时模板里对应的行整行消失，不会留空标签。
- **不要传 `ip`**：IP 一律由 WordPress 从 `X-Real-IP` / `X-Forwarded-For` / `REMOTE_ADDR` 识别（见 §2.7），前端传了也会被忽略。

### 2.3 字段标签

```
fieldLabels[fullName]=姓名
fieldLabels[outerDiameter]=外径
```

- 键必须与字段名一致；值是该字段在表单上的可见标签。
- 是普通表单字段，可以一次传多个；数组形式 `fieldLabels[]` 会按数字下标处理，不推荐。
- 后端取值优先级：**后台覆盖 > 这里的标签 > 字段名转可读文本**（`outerDiameter` → `Outer Diameter`）。

### 2.4 附件

| 字段 | 白名单 | 单文件上限 |
|---|---|---|
| `drawings[]` | pdf step stp dwg dxf igs sldprt sldasm zip rar 7z | 20 MB |
| `pictures[]` | png jpg jpeg webp | 10 MB |

- 字段名**必须带 `[]`**，否则多个文件只会留下最后一个。
- 单次总数上限 20（filter `eln_max_files`）。
- 服务端只看扩展名，不做 MIME 嗅探；前端应先用 `accept` 和 `size` 拦住明显不合规的文件。
- 附件最终落在 `wp-content/uploads/email-log-notifications/{年月}/{记录ID-随机}/`，meta 里存的是相对 Web 路径。

### 2.5 蜜罐

隐藏字段 `companyWebsite`，正常用户不填。**非空即判定机器人**：返回 `200 {"sent":true}`，但不入库、不发信。

### 2.6 询盘编号

服务端在入库时生成编号，格式 `YYYYMMDD` + 3 位当日流水（如 `20261105001`），存进记录的 `inquiry_no` meta，邮件模板里用 `{{no}}` 引用。流水用 MySQL 的 `LAST_INSERT_ID(expr)` 原子自增，并发提交不会重号；每日一个计数器（option `inquiry_seq_YYYYMMDD`）。「发送测试邮件」和预览不会消耗真实编号。

插件启用后的首次进入后台时，会给**早于该功能**的旧记录按提交时间补号（option `inquiry_numbers_backfilled` 记账，只跑一次），当天计数器同时推到已用最大值，保证下一封询盘接着排。

### 2.7 环境数据：IP / 浏览器语言 / 时区

三类数据各有出处，邮件模板里分别对应 `{{ip}}`、`{{lang}}`、`{{time_zone}}`：

| 数据 | 谁采集 | 说明 |
|---|---|---|
| IP | **WordPress** | 依次读 `X-Real-IP` → `X-Forwarded-For`（第一段）→ `REMOTE_ADDR`，`FILTER_VALIDATE_IP` 通过才用；全站限流用的也是这个值 |
| 浏览器语言 `browserLanguage` | 前端 | `navigator.languages?.[0] \|\| navigator.language`，如 `zh-CN` |
| 用户时区 `timezone` | 前端 | `Intl.DateTimeFormat().resolvedOptions().timeZone`，如 `Asia/Shanghai` |

前端只需两行：

```js
const lang = navigator.languages?.[0] || navigator.language || "";
if (lang) data.append("browserLanguage", lang);
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
if (tz) data.append("timezone", tz);
```

- **不要再传 `ip`**。同源反代已经把真实来源放在 `X-Real-IP` 里，前端自报的 IP 只是自证，服务端会直接丢弃（`normalize_env_fields()`）。
- 时区同时用于渲染 `{{time_local}}`（按访客时区换算的提交时间），拿不到就整行不显示。
- 走内网穿透（frp）时，nginx 看到的源地址恒为回环，服务端识别到的会是 `127.0.0.1` —— 要拿真实公网 IP 得让穿透层传 `X-Forwarded-For` 或启用 PROXY protocol。这是链路限制，不是插件问题。
- 本站 nginx 里那个 `location = /ip` 端点已不再被前端调用，可以删掉。

### 2.8 响应

| 情况 | 状态码 | 响应体 |
|---|---|---|
| 成功 | 200 | `{"sent": true}` |
| 缺必填 | 400 | `{"code":"inquiry_missing_field", ...}` |
| 邮箱非法 | 400 | `{"code":"inquiry_invalid_email", ...}` |
| 附件不合规 | 400 | `{"code":"inquiry_bad_upload","message":"File type .exe is not allowed."}` |
| 触发限流 | 429 | `{"code":"inquiry_rate_limited", ...}` |
| 发信失败 | 500 | `{"code":"inquiry_mail_failed", ...}` |

前端只需要区分 `2xx` / 非 `2xx`；错误文案用站点自己的兜底提示。

### 2.9 邮件模板（询盘通知 + 自动确认）

后台「询盘 → 邮件模板」有两个标签页，共用一套编辑器（源码 + 实时预览 + 测试发信 + 恢复默认）：

| 标签页 | option | 发给谁 | 结构 |
|---|---|---|---|
| 询盘通知 | `eln_template` | 站长/销售（`admin_email`，filter `eln_recipient`） | `subject` / `html` / `labels` |
| 自动确认 | `eln_autoreply` | **访客自己填的邮箱** | `enabled` + `groups[]`，每组含 `lang` / `subject` / `html` / `labels` |

**自动确认按浏览器语言分「语言组」**：

- `groups[0]` 固定是**默认组**（`lang` 为空），其它组各自带一个语言标签（如 `zh`、`zh-CN`、`en`）。
- 匹配顺序：**完整标签精确命中**（`zh-CN`）→ **主语言命中**（`zh` 组吃 `zh-CN` / `zh-TW` / `zh`）→ 默认组。
- 每组独立持有主题、源码、字段标签——等于「一种语言一份完整模板」。
- 后台在「自动确认」页用标签条切换语言组，可新增/删除（默认组不可删）。
- 默认关闭，勾选「启用自动确认」才发；发信顺序固定：先通知、后确认，两者互不牵连。
- 确认邮件带 `Reply-To: <询盘收件人>`，客户直接回信落到销售邮箱；不带附件。
- 状态各记各的：通知 → `inquiry_mail_sent`，自动确认 → `inquiry_autoreply_sent`（未启用时不写，后台显示「—」）。
- 发出后可挂 `do_action('eln_autoreply_sent', $sent, $email, $fields)`。

**占位符（两套模板通用，只输出值、不带标签）**：

| 占位符 | 内容 | 谁提供 |
|---|---|---|
| `{{rows}}` | 字段表格（表头用当前模板的 `labels`，没配就用表单自己提交的 `fieldLabels`） | 前端表单 |
| `{{name}}` `{{email}}` | 姓名 / 邮箱 | 前端表单 |
| `{{source}}` `{{form_type}}` | 来源页英文标题 / 页面表单·弹窗表单 | 前端（pageTitle、pageUrl、formVariant） |
| `{{time_local}}` | 提交时间换算到访客时区 | 前端（timezone） |
| `{{lang}}` `{{time_zone}}` | 浏览器语言 / 访客时区 | 前端（browserLanguage、timezone） |
| `{{no}}` `{{time}}` `{{ip}}` | 询盘编号 / 提交时间（站点时区）/ 服务端识别的 IP | 插件 |

> **占位符不会自动加「时区：」这类标签**。想要带标签的行，就在源码里写 `时区：{{time_zone}}`；
> 后台把需要前端配合的占位符标了 `*`，旁边有图例。

**字段标签与行序**：`{{rows}}` 的行序 = 后台「字段标签」列表的顺序（可拖动排序），
没排过的字段再按内置顺序补在后面。字段清单本身来自前端实际提交的字段名，
表单加字段后这里会自动多一行。

主题（subject）用**和正文同一套双花括号**：`{{name}}` `{{email}}`（旧的单花括号 `{name}` `{email}` 仍兼容）；留空用语言字典的默认文案。
主题走 `sanitize_text_field`，**不要写 `<` `>`**（会被当成 HTML 标签剥掉）。

---

## 3. 最小实现（原生 JS）

```html
<form id="inquiry">
  <label for="f-name">姓名 *</label>
  <input id="f-name" name="fullName" required />

  <label for="f-mail">邮箱 *</label>
  <input id="f-mail" name="email" type="email" required />

  <label for="f-msg">留言 *</label>
  <textarea id="f-msg" name="message" required></textarea>

  <input type="file" name="drawings[]" multiple accept=".pdf,.step,.stp,.dwg,.dxf,.igs,.zip,.rar,.7z" />
  <input type="file" name="pictures[]" multiple accept="image/png,image/jpeg,image/webp" />

  <input name="companyWebsite" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" />
  <button type="submit">发送</button>
</form>

<script>
document.getElementById('inquiry').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const data = new FormData(form);

  // 1) 带上每个字段自己的标签（可省略，省略后后端用字段名）
  form.querySelectorAll('input[name], textarea[name], select[name]').forEach((el) => {
    if (!el.name || el.name === 'companyWebsite') return;
    const label = el.id ? form.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
    const text = (label?.textContent || '').replace(/\*/g, '').trim();
    if (text) data.append(`fieldLabels[${el.name}]`, text);
  });

  // 2) 元信息（IP 不用传，服务端自己识别）
  data.append('formVariant', 'page');
  data.append('pageUrl', location.href);
  data.append('pageTitle', document.title.replace(/\s*[-–|]\s*Brand\s*$/i, '').trim());
  data.append('locale', document.documentElement.lang || 'en');
  const lang = navigator.languages?.[0] || navigator.language;
  if (lang) data.append('browserLanguage', lang);
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (tz) data.append('timezone', tz);

  // 3) 提交（不要手写 Content-Type）
  const res = await fetch('/wp-json/email-log-notifications/v1/inquiry', { method: 'POST', body: data });
  if (res.ok) {
    form.reset();
    // 清空自定义上传组件、重置自定义下拉…
  } else {
    // 显示失败提示
  }
});
</script>
```

要点：

- `new FormData(form)` 会自动带上所有 `name` 控件；**动态控件（自定义下拉、拖拽上传）没有原生 name，需要手动 `append`**。
- 一定不要设置 `Content-Type`，浏览器要自己写 boundary。
- 提交期间禁用按钮，避免重复记录。

---

## 4. 主流框架怎么接

### React

```jsx
const formRef = useRef(null);

async function onSubmit(e) {
  e.preventDefault();
  const form = formRef.current;
  const data = new FormData(form);
  // 受控组件也在 DOM 里，FormData 同样能取到
  data.append('fieldLabels[fullName]', '姓名');
  const res = await fetch('/wp-json/email-log-notifications/v1/inquiry', { method: 'POST', body: data });
  if (res.ok) form.reset();
}
```

- 别把文件塞进 state 再转 Blob；直接读 `<input type="file">` 的 `files`。
- 用组件库（Ant Design / MUI）时，标签往往不在 `<label for>` 里，**手动传 `fieldLabels`** 最省事。

### Vue 3

```vue
<form ref="form" @submit.prevent="submit">…</form>

<script setup>
const form = ref(null);
async function submit() {
  const data = new FormData(form.value);
  const res = await fetch('/wp-json/email-log-notifications/v1/inquiry', { method: 'POST', body: data });
  if (res.ok) form.value.reset();
}
</script>
```

### Svelte

```svelte
<form on:submit|preventDefault={submit} bind:this={form}>…</form>
```

### Next.js / Nuxt（SSR）

- 站点与 WordPress **同源**时直接相对路径提交；不同源就配反向代理（推荐），避免 CORS 预检。
- 若必须跨域：后端已允许（REST 默认 CORS），但要确认 `multipart` 预检通过；**仍然建议同源**。

### 纯 HTML（无 JS）

```html
<form action="/wp-json/email-log-notifications/v1/inquiry" method="post" enctype="multipart/form-data">…</form>
```

可提交成功（浏览器会跳转显示 JSON），但拿不到成功/失败提示。要留在页面上就必须用 `fetch`。

---

## 5. 性能与解耦

| 维度 | 现状 |
|---|---|
| 请求数 | 每次提交 **1 个** multipart 请求；附件随请求走，没有分片/二次上传 |
| 服务端开销 | 校验 → 存记录 → 移动附件 → 组邮件 → 发信，一次同步完成（SMTP 通常 0.3–1s） |
| 前端依赖 | 0；不加载任何插件脚本、不依赖 jQuery |
| 与 CMS 解耦 | 插件只写自己的 CPT 与 `wp-content/uploads/email-log-notifications/`，不改主题、不注册短代码 |
| 迁移成本 | 换站点只需改端点地址与（可选）`pageTitleEn` 的来源 |

> 本站额外做了一个优化：构建期生成 `dist/page-titles.json`（路径 → 英文标题），前端在页面加载时预热、提交时查表带上 `pageTitleEn`。这是**站点侧**的可选增强，插件不依赖它。

---

## 6. 安全与限制

- 蜜罐 `companyWebsite`：非空静默成功，不入库不发信。
- 限流：公网 IP 30 次/小时；回环/私网退化成一个全局桶 60 次/小时（穿透场景下源 IP 恒为回环）。
- 附件只校验扩展名与大小；落盘目录名含随机串，实际不可枚举。
- 只接受 `multipart/form-data`；发 JSON 会被判为"缺必填字段"。
- 请求体上限：nginx `client_max_body_size 60m`、PHP `post_max_size 64m`。

---

## 7. 换一个项目要改什么

1. **必填字段**（如果不是 `fullName/email/message`）：

```php
add_filter('eln_required_fields', fn() => ['name', 'mail', 'body']);
```

2. **收件人**（默认 `admin_email`）：

```php
add_filter('eln_recipient', fn() => 'sales@example.com');
```

3. **附件上限 / 白名单**：`eln_max_files` 可改数量；白名单在插件常量 `FILE_GROUPS` 里。
4. **反代命名空间**：nginx 只放行 `/wp-json/(email-log-notifications|lfx)/`，新项目按需调整。
5. **页面标题规范化**（可选）：不做的话，来源列显示前端提交的 `pageTitle`；做了就有统一英文口径。

---

## 8. 验收清单

- [ ] 最小表单（三个必填）能提交，收到邮件且后台出现记录
- [ ] 附件：单文件、多文件、超限、非法扩展名各测一次
- [ ] 蜜罐填写后返回 200 但不产生记录
- [ ] 刷新页面后重复提交按钮被禁用（或后端限流生效）
- [ ] 移动端键盘弹出时提交按钮可点
- [ ] 失败时（断网/500）有可见提示，表单内容不丢
- [ ] 后台来源列显示的是期望的页面名
- [ ] 邮件页脚出现「浏览器语言 / 时区 / 当地时间」，且换一个时区（如 America/New_York）后当地时间跟着变
- [ ] 后台记录详情的 IP 是服务端识别值（不是前端传的），浏览器语言/时区与访问环境一致
- [ ] 自动回复：开启后提交能收到第二封（收件人是填表邮箱），关闭后不再发
