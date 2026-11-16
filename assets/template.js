/* Email Log & Notifications — 邮件模板设置页脚本（版本 2.0.0）
 * 由 PHP heredoc 原样外置，配置由 wp_add_inline_script 注入 window.ElnTpl；
 * window.SiteInqTpl 为向后兼容别名（见 PHP 注入处）。
 */
(function () {
  function boot() {
    var cfg = window.ElnTpl || {};
    var form = document.getElementById('eln-tpl-form');
    var ta = document.getElementById('eln-html');
    var preview = document.getElementById('eln-preview');
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

    var formatBtn = document.getElementById('eln-format-code');    if (formatBtn) {
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
          items: '> .eln-tpl-label-row',
          handle: '.eln-sort',
          axis: 'y',
          placeholder: 'ui-sortable-placeholder',
          forcePlaceholderSize: true,
          update: function () { schedule(); }
        });
      }
    }

    var mediaBtn = document.getElementById('eln-insert-media');
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
      params.set('action', 'eln_preview');
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
