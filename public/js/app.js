(function () {
    'use strict';

    var PREF_KEY = 'dlm-theme';
    var mql = window.matchMedia('(prefers-color-scheme: dark)');
    var LABELS = { auto: '跟随系统', light: '浅色', dark: '深色' };
    var ORDER = ['auto', 'dark', 'light'];

    function getPref() {
        try { return localStorage.getItem(PREF_KEY) || 'auto'; } catch (e) { return 'auto'; }
    }

    function resolveTheme(pref) {
        return pref === 'auto' ? (mql.matches ? 'dark' : 'light') : pref;
    }

    function updateToggle() {
        var btn = document.getElementById('theme-toggle');
        if (!btn) { return; }
        var pref = getPref();
        btn.textContent = LABELS[pref] || '主题';
        btn.title = '主题：' + (LABELS[pref] || '主题') + '（点击切换：跟随系统 / 深色 / 浅色）';
    }

    function applyPref(pref) {
        document.documentElement.setAttribute('data-theme', resolveTheme(pref));
        document.documentElement.setAttribute('data-theme-pref', pref);
        try { localStorage.setItem(PREF_KEY, pref); } catch (e) {}
        updateToggle();
    }

    mql.addEventListener('change', function () {
        if (getPref() === 'auto') {
            applyPref('auto');
        }
    });

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('#theme-toggle');
        if (toggle) {
            var next = ORDER[(ORDER.indexOf(getPref()) + 1) % ORDER.length];
            applyPref(next);
        }
    });

    applyPref(getPref());

    window.DLM = window.DLM || {};
    window.DLM.applyTheme = applyPref;
    window.DLM.currentPref = getPref;

    window.DLM.openModal = function (id) {
        var el = document.getElementById(id);
        if (el) { el.hidden = false; }
    };

    window.DLM.closeModal = function (id) {
        var el = document.getElementById(id);
        if (el) { el.hidden = true; }
    };

    window.DLM.copyText = function (text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(area);
        return Promise.resolve();
    };

    document.addEventListener('click', function (event) {
        var closer = event.target.closest('[data-modal-close]');
        if (closer) {
            var modal = closer.closest('.modal-backdrop');
            if (modal) { modal.hidden = true; }
        }
    });
})();

(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var pendingForm = null;

    function closeConfirm() {
        var modal = document.getElementById('confirm-modal');
        if (modal) { modal.hidden = true; }
        pendingForm = null;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('[data-confirm]')) {
            return;
        }
        event.preventDefault();
        var modal = document.getElementById('confirm-modal');
        if (!modal) {
            form.submit();
            return;
        }
        pendingForm = form;
        var messageEl = modal.querySelector('[data-confirm-message]');
        if (messageEl) {
            messageEl.textContent = form.getAttribute('data-confirm') || '确定要执行该操作吗？';
        }
        var titleEl = modal.querySelector('[data-confirm-title]');
        if (titleEl) {
            titleEl.textContent = form.getAttribute('data-confirm-title') || '确认操作';
        }
        modal.hidden = false;
    });

    document.addEventListener('click', function (event) {
        var modal = document.getElementById('confirm-modal');
        if (!modal || modal.hidden) { return; }
        if (event.target.closest('[data-confirm-ok]')) {
            var form = pendingForm;
            closeConfirm();
            if (form) { form.submit(); }
            return;
        }
        if (event.target.closest('[data-confirm-cancel]') || event.target === modal) {
            closeConfirm();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var modal = document.getElementById('confirm-modal');
            if (modal && !modal.hidden) { closeConfirm(); }
        }
    });

    function updateBulkCount() {
        var count = document.querySelectorAll('input[data-row-check]:checked').length;
        document.querySelectorAll('[data-bulk-count]').forEach(function (el) {
            el.textContent = String(count);
        });
    }

    function toggleBulkAttr() {
        var select = document.querySelector('[data-bulk-action]');
        var box = document.querySelector('[data-bulk-attr]');
        if (!select || !box) { return; }
        box.hidden = select.value !== 'set_attr';
    }

    document.addEventListener('change', function (event) {
        var checkAll = event.target.closest('[data-check-all]');
        if (checkAll) {
            var scope = checkAll.closest('.table-wrap, .table-cards') || document;
            scope.querySelectorAll('input[data-row-check]').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
            updateBulkCount();
        }
        if (event.target.matches && event.target.matches('input[data-row-check]')) {
            updateBulkCount();
        }
        if (event.target.closest && event.target.closest('[data-bulk-action]')) {
            toggleBulkAttr();
        }
    });

    function fillLinkForm(data, mode) {
        var form = document.getElementById('link-form');
        if (!form) { return; }
        var createAction = form.getAttribute('data-action-create') || '';
        var editBase = form.getAttribute('data-action-edit') || '';
        form.action = (mode === 'edit' && data) ? editBase + data.id : createAction;

        var modal = form.closest('.modal');
        var title = modal ? modal.querySelector('[data-modal-title]') : null;
        if (title) { title.textContent = mode === 'edit' ? '编辑链接' : '新增链接'; }

        var urlInput = form.querySelector('input[name="url"]');
        if (urlInput) { urlInput.value = (data && data.url) ? data.url : ''; }

        var values = (data && data.values) ? data.values : {};
        form.querySelectorAll('[data-attr]').forEach(function (input) {
            var key = input.getAttribute('data-attr');
            var value = Object.prototype.hasOwnProperty.call(values, key) ? values[key] : '';
            input.value = (value === null || value === undefined) ? '' : value;
        });
    }

    function fillAttrForm(data, mode) {
        var form = document.getElementById('attr-form');
        if (!form) { return; }
        var createAction = form.getAttribute('data-action-create') || '';
        var editBase = form.getAttribute('data-action-edit') || '';
        form.action = (mode === 'edit' && data) ? editBase + data.id : createAction;

        var modal = form.closest('.modal');
        var title = modal ? modal.querySelector('[data-modal-title]') : null;
        if (title) { title.textContent = mode === 'edit' ? '编辑属性' : '新增属性'; }

        var set = function (name, value) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) { el.value = value; }
        };
        var check = function (name, value) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) { el.checked = !!value; }
        };

        if (mode === 'edit' && data) {
            set('label', data.label || '');
            set('key', data.key || '');
            set('type', data.type || 'text');
            set('sort_order', data.sort_order != null ? data.sort_order : 0);
            check('required', data.required);
            check('constrained', data.constrained);
            set('options', (data.options || []).join('\n'));
        } else {
            set('label', '');
            set('key', '');
            set('type', 'text');
            set('sort_order', '0');
            check('required', false);
            check('constrained', false);
            set('options', '');
        }
    }

    document.addEventListener('click', function (event) {
        var editLink = event.target.closest('[data-link-edit]');
        if (editLink) {
            var linkData = null;
            try { linkData = JSON.parse(editLink.getAttribute('data-link') || 'null'); } catch (e) { linkData = null; }
            fillLinkForm(linkData, 'edit');
            window.DLM.openModal('link-modal');
            return;
        }

        var newLink = event.target.closest('[data-link-new]');
        if (newLink) {
            fillLinkForm(null, 'new');
            window.DLM.openModal('link-modal');
            return;
        }

        var editAttr = event.target.closest('[data-attr-edit]');
        if (editAttr) {
            var attrData = null;
            try { attrData = JSON.parse(editAttr.getAttribute('data-attr') || 'null'); } catch (e) { attrData = null; }
            fillAttrForm(attrData, 'edit');
            window.DLM.openModal('attr-modal');
            return;
        }

        var newAttr = event.target.closest('[data-attr-new]');
        if (newAttr) {
            fillAttrForm(null, 'new');
            window.DLM.openModal('attr-modal');
            return;
        }

        var copyBtn = event.target.closest('[data-copy-token]');
        if (copyBtn) {
            var text = copyBtn.getAttribute('data-copy-token') || '';
            if (window.DLM.copyText) {
                window.DLM.copyText(text).then(function () {
                    var original = copyBtn.textContent;
                    copyBtn.textContent = '已复制';
                    setTimeout(function () { copyBtn.textContent = original; }, 1500);
                });
            }
        }
    });

    function renderPreview(target, data) {
        if (!target) { return; }
        if (!data) {
            target.innerHTML = '<p class="flash flash-error">无法解析预览结果</p>';
            return;
        }
        var html = '<p class="muted">共 ' + (data.total || 0) + ' 行；'
            + (data.has_url ? '已找到 url 列' : '<span class="tag-error">缺少 url 列</span>');
        if (data.unknown_columns && data.unknown_columns.length) {
            html += '；未知列：' + escapeHtml(data.unknown_columns.join('、'));
        }
        html += '</p>';

        var known = data.known_columns || [];
        html += '<div class="table-wrap"><table class="data-table"><thead><tr><th>url</th>';
        known.forEach(function (col) { html += '<th>' + escapeHtml(col) + '</th>'; });
        html += '<th>校验</th></tr></thead><tbody>';

        (data.preview || []).forEach(function (row) {
            html += '<tr><td class="url-cell">' + escapeHtml(row.url || '') + '</td>';
            known.forEach(function (col) {
                var value = (row.values || {})[col];
                html += '<td>' + escapeHtml(value === null || value === undefined ? '' : String(value)) + '</td>';
            });
            var errors = row.errors || [];
            html += '<td>' + (errors.length
                ? '<span class="tag-error">' + escapeHtml(errors.join('；')) + '</span>'
                : '<span class="tag-ok">OK</span>') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        target.innerHTML = html;
    }

    document.addEventListener('click', function (event) {
        var previewBtn = event.target.closest('[data-preview-btn]');
        if (!previewBtn) { return; }
        event.preventDefault();
        var form = document.getElementById('import-form');
        if (!form) { return; }
        var target = document.getElementById('import-preview');
        var url = form.getAttribute('data-preview-action') || '';
        if (target) { target.innerHTML = '<p class="muted">解析中…</p>'; }
        fetch(url, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            renderPreview(target, result && result.data ? result.data : null);
        }).catch(function () {
            if (target) { target.innerHTML = '<p class="flash flash-error">预览失败，请重试</p>'; }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        updateBulkCount();
        toggleBulkAttr();
    });
})();
