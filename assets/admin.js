/* Email Log & Notifications — 询盘列表详情弹窗（版本 2.0.0）
 * 从 PHP 内联脚本原样外置：仍从 .eln-modal 的 data-* 属性读取配置。
 */
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
    var del    = modal.querySelector('.eln-modal__delete');
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
        // 回复 = mailto：to/cc/bcc/subject/body 全部来自「回复模板」设置，占位符按本条记录填充
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
            // to 也以模板为准（可为空，mailto:?… 合法）；cc/bcc/subject/body 非空才进 query
            var to = fill(modal.getAttribute('data-reply-to'));
            var parts = [];
            var cc = fill(modal.getAttribute('data-reply-cc'));
            var bcc = fill(modal.getAttribute('data-reply-bcc'));
            var subject = fill(modal.getAttribute('data-reply-subject'));
            var body = fill(modal.getAttribute('data-reply-body'));
            if (cc) { parts.push('cc=' + encodeURIComponent(cc)); }
            if (bcc) { parts.push('bcc=' + encodeURIComponent(bcc)); }
            if (subject) { parts.push('subject=' + encodeURIComponent(subject)); }
            if (body) { parts.push('body=' + encodeURIComponent(body)); }
            reply.href = 'mailto:' + encodeURIComponent(to) + (parts.length ? '?' + parts.join('&') : '');
        }
        del.hidden = !d.delete;
        del.removeAttribute('href');
        if (d.delete) { del.href = d.delete; }
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
        del.hidden = true; del.removeAttribute('href');
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
    del.addEventListener('click', function () {
        var url = del.getAttribute('href');
        if (!url) { return; }
        if (window.confirm(modal.getAttribute('data-confirm-delete'))) {
            window.location.assign(url); // admin-post 处理完会 302 回列表页
        }
    });
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
