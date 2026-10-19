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
        document.querySelectorAll('[data-bulk-submit]').forEach(function (btn) {
            btn.disabled = count === 0;
        });
    }

    function toggleBulkAttr() {
        var select = document.querySelector('[data-bulk-action]');
        var box = document.querySelector('[data-bulk-attr]');
        if (!select || !box) { return; }
        box.hidden = select.value !== 'set_attr';

        document.querySelectorAll('[data-bulk-submit]').forEach(function (btn) {
            var isExport = select.value === 'export';
            btn.textContent = isExport
                ? (btn.getAttribute('data-label-export') || '导出 CSV')
                : (btn.getAttribute('data-label-run') || '执行');
            btn.classList.toggle('btn-danger', !isExport);
            btn.classList.toggle('btn-primary', isExport);
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.id !== 'bulk-form') { return; }
        var select = form.querySelector('[data-bulk-action]');
        if (!select || select.value !== 'export') { return; }

        var exportUrl = form.getAttribute('data-export-url') || '';
        var boxes = form.elements['ids[]'];
        var list = !boxes ? [] : (boxes.length === undefined ? [boxes] : Array.prototype.slice.call(boxes));
        var seen = {};
        var ids = [];
        list.forEach(function (cb) {
            if (cb.checked && !seen[cb.value]) {
                seen[cb.value] = true;
                ids.push(cb.value);
            }
        });
        event.preventDefault();
        if (exportUrl === '' || ids.length === 0) { return; }
        window.location.href = exportUrl + '?ids=' + encodeURIComponent(ids.join(','));
    });

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
        var pageSize = event.target.closest ? event.target.closest('[data-page-size]') : null;
        if (pageSize) {
            var option = pageSize.options[pageSize.selectedIndex];
            var url = option ? option.getAttribute('data-url') : '';
            if (url) { window.location.href = url; }
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
            if (result && result.code) {
                if (target) {
                    target.innerHTML = '<p class="flash flash-error">'
                        + escapeHtml(result.message || '预览失败，请重试') + '</p>';
                }
                return;
            }
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

/* ============================================================
 * 自定义下拉菜单
 * ------------------------------------------------------------
 * 渐进增强：保留原生 <select> 作为真正的表单控件（提交 / 校验 /
 * 读写 value 均不受影响），只在其上方渲染一套与站内 UI 一致的
 * 触发器 + 浮层菜单。
 * ============================================================ */
(function () {
    'use strict';

    if (window.DLM && window.DLM.selectsReady) { return; }

    var SELECTOR = 'select:not([data-native-select]):not([multiple])';
    var GAP = 6;
    var EDGE = 8;
    var MAX_HEIGHT = 320;
    var counter = 0;
    var instances = [];
    var openInst = null;

    function textOf(node) {
        return (node.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function optionList(select) {
        return Array.prototype.slice.call(select.options || []);
    }

    function EnhancedSelect(select) {
        var self = this;

        self.select = select;
        self.id = 'dlm-sel-' + (++counter);
        self.menu = null;
        self.open = false;
        self.activeIndex = -1;

        var wrap = document.createElement('div');
        wrap.className = 'dlm-select';
        select.parentNode.insertBefore(wrap, select);

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'dlm-select-trigger';
        trigger.id = self.id + '-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="dlm-select-value"></span>'
            + '<span class="dlm-select-arrow" aria-hidden="true"></span>';

        wrap.appendChild(trigger);
        wrap.appendChild(select);

        select.classList.add('dlm-select-native');
        select.setAttribute('tabindex', '-1');

        self.wrap = wrap;
        self.trigger = trigger;
        self.valueEl = trigger.querySelector('.dlm-select-value');

        var ariaLabel = select.getAttribute('aria-label');
        if (ariaLabel) {
            trigger.setAttribute('aria-label', ariaLabel);
        } else if (!select.closest('label')) {
            var field = select.closest('.field');
            var lab = field ? field.querySelector('label') : null;
            if (lab) { trigger.setAttribute('aria-label', textOf(lab)); }
        }

        self.sync();
        self.bindNative();
        self.bindTrigger();

        if (select.disabled) {
            wrap.classList.add('is-disabled');
        }
    }

    EnhancedSelect.prototype.bindNative = function () {
        var self = this;
        var select = self.select;
        var proto = window.HTMLSelectElement && window.HTMLSelectElement.prototype;

        // 同步通过 JS 直接赋值（例如弹窗回填表单）产生的变化
        if (proto) {
            ['value', 'selectedIndex'].forEach(function (prop) {
                var desc = Object.getOwnPropertyDescriptor(proto, prop);
                if (!desc || !desc.set) { return; }
                Object.defineProperty(select, prop, {
                    configurable: true,
                    enumerable: false,
                    get: function () { return desc.get.call(select); },
                    set: function (next) {
                        desc.set.call(select, next);
                        self.sync();
                    }
                });
            });
        }

        // 原生 select 被隐藏，但仍可能在 label 激活 / 表单校验时被触发
        select.addEventListener('mousedown', function (event) { event.preventDefault(); });
        select.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (event.detail === 0) { self.openMenu(); } else { self.toggle(); }
        });
        select.addEventListener('focus', function () { self.trigger.focus(); });
        select.addEventListener('change', function () {
            self.wrap.classList.remove('is-invalid');
            self.sync();
        });
        select.addEventListener('invalid', function () {
            self.wrap.classList.add('is-invalid');
        });
    };

    EnhancedSelect.prototype.bindTrigger = function () {
        var self = this;

        self.trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            // event.detail === 0 表示这次 click 不是真实鼠标点击（例如点击包裹
            // 该下拉的 <label> 时浏览器转发过来的）。此时只负责"打开"，不再
            // 反向关闭，避免点标签时菜单闪烁。
            if (event.detail === 0) {
                self.openMenu();
                return;
            }
            self.toggle();
        });

        self.trigger.addEventListener('keydown', function (event) {
            var key = event.key;
            var handled = true;

            if (key === 'Escape') {
                if (self.open) { self.close(); }
            } else if (key === 'ArrowDown' || key === 'Down') {
                if (self.open) { self.move(1); } else { self.openMenu(); }
            } else if (key === 'ArrowUp' || key === 'Up') {
                if (self.open) { self.move(-1); } else { self.openMenu(); }
            } else if (key === 'Home') {
                if (self.open) { self.setActive(self.firstEnabled(), true); } else { handled = false; }
            } else if (key === 'End') {
                if (self.open) { self.setActive(self.lastEnabled(), true); } else { handled = false; }
            } else if (key === 'Enter' || key === ' ' || key === 'Spacebar') {
                if (self.open) {
                    if (self.activeIndex >= 0) { self.choose(self.activeIndex); }
                    else { self.close(); }
                } else {
                    self.openMenu();
                }
            } else if (key === 'Tab') {
                if (self.open) { self.close(); }
                handled = false;
            } else if (key.length === 1 && /\S/.test(key)) {
                self.typeahead(key);
            } else {
                handled = false;
            }

            if (handled) { event.preventDefault(); }
        });
    };

    EnhancedSelect.prototype.sync = function () {
        var self = this;
        var select = self.select;
        var index = select.selectedIndex;
        var opt = index >= 0 ? select.options[index] : null;
        var text = opt ? textOf(opt) : '';

        self.valueEl.textContent = text || '请选择';
        self.valueEl.classList.toggle('is-placeholder', !opt || opt.value === '');
        self.wrap.classList.toggle('is-disabled', !!select.disabled);
        self.trigger.disabled = !!select.disabled;

        if (self.open) { self.refreshSelection(); }
    };

    EnhancedSelect.prototype.firstEnabled = function () {
        var opts = optionList(this.select);
        for (var i = 0; i < opts.length; i++) {
            if (!opts[i].disabled) { return i; }
        }
        return -1;
    };

    EnhancedSelect.prototype.lastEnabled = function () {
        var opts = optionList(this.select);
        for (var i = opts.length - 1; i >= 0; i--) {
            if (!opts[i].disabled) { return i; }
        }
        return -1;
    };

    EnhancedSelect.prototype.move = function (delta) {
        var opts = optionList(this.select);
        if (!opts.length) { return; }
        var start = this.activeIndex;
        if (start < 0) { start = delta > 0 ? -1 : opts.length; }
        var i = start;
        for (var step = 0; step < opts.length; step++) {
            i += delta;
            if (i < 0) { i = opts.length - 1; }
            if (i >= opts.length) { i = 0; }
            if (!opts[i].disabled) {
                this.setActive(i, true);
                return;
            }
        }
    };

    EnhancedSelect.prototype.typeahead = function (char) {
        var self = this;
        var opts = optionList(self.select);
        var needle = char.toLowerCase();
        var start = self.activeIndex + 1;

        for (var step = 0; step < opts.length; step++) {
            var i = (start + step) % opts.length;
            if (opts[i].disabled) { continue; }
            if (textOf(opts[i]).toLowerCase().indexOf(needle) === 0) {
                if (self.open) { self.setActive(i, true); }
                else { self.choose(i); }
                return;
            }
        }
    };

    EnhancedSelect.prototype.setActive = function (index, scrollIntoView) {
        var self = this;
        if (!self.menu) { return; }
        self.activeIndex = index;

        var nodes = self.menu.querySelectorAll('.dlm-select-option');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var isActive = Number(node.getAttribute('data-index')) === index;
            node.classList.toggle('is-active', isActive);
            if (isActive && scrollIntoView) {
                // 只滚动菜单自身，避免带动整个页面
                var menu = self.menu;
                var top = node.offsetTop;
                var bottom = top + node.offsetHeight;
                if (top < menu.scrollTop) {
                    menu.scrollTop = top;
                } else if (bottom > menu.scrollTop + menu.clientHeight) {
                    menu.scrollTop = bottom - menu.clientHeight;
                }
            }
        }

        var active = self.menu.querySelector('.dlm-select-option.is-active');
        if (active && active.id) {
            self.trigger.setAttribute('aria-activedescendant', active.id);
        } else {
            self.trigger.removeAttribute('aria-activedescendant');
        }
    };

    EnhancedSelect.prototype.refreshSelection = function () {
        var self = this;
        if (!self.menu) { return; }
        var index = self.select.selectedIndex;
        var nodes = self.menu.querySelectorAll('.dlm-select-option');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var selected = Number(node.getAttribute('data-index')) === index;
            node.classList.toggle('is-selected', selected);
            node.setAttribute('aria-selected', selected ? 'true' : 'false');
        }
    };

    EnhancedSelect.prototype.buildMenu = function () {
        var self = this;
        var select = self.select;

        var menu = document.createElement('div');
        menu.className = 'dlm-select-menu';
        menu.id = self.id + '-menu';
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('tabindex', '-1');

        if (!select.options.length) {
            var empty = document.createElement('div');
            empty.className = 'dlm-select-empty';
            empty.textContent = '暂无可选项';
            menu.appendChild(empty);
        } else {
            for (var i = 0; i < select.children.length; i++) {
                var child = select.children[i];
                if (child.tagName === 'OPTGROUP') {
                    if (child.label) {
                        var groupLabel = document.createElement('div');
                        groupLabel.className = 'dlm-select-group';
                        groupLabel.textContent = child.label;
                        menu.appendChild(groupLabel);
                    }
                    for (var j = 0; j < child.children.length; j++) {
                        if (child.children[j].tagName === 'OPTION') {
                            menu.appendChild(self.buildOption(child.children[j]));
                        }
                    }
                } else if (child.tagName === 'OPTION') {
                    menu.appendChild(self.buildOption(child));
                }
            }
        }

        self.menu = menu;
        self.trigger.setAttribute('aria-controls', menu.id);
    };

    EnhancedSelect.prototype.buildOption = function (opt) {
        var self = this;

        var el = document.createElement('div');
        el.className = 'dlm-select-option';
        el.id = self.id + '-opt-' + opt.index;
        el.setAttribute('role', 'option');
        el.setAttribute('data-index', String(opt.index));
        el.setAttribute('aria-selected', opt.selected ? 'true' : 'false');

        var text = document.createElement('span');
        text.className = 'dlm-select-option-text';
        text.textContent = textOf(opt);
        el.appendChild(text);

        if (opt.selected) { el.classList.add('is-selected'); }
        if (opt.disabled) {
            el.classList.add('is-disabled');
            el.setAttribute('aria-disabled', 'true');
        } else {
            el.addEventListener('mouseenter', function () { self.setActive(opt.index, false); });
            el.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                self.choose(opt.index);
            });
        }

        return el;
    };

    EnhancedSelect.prototype.choose = function (index) {
        var self = this;
        var opt = self.select.options[index];
        if (!opt || opt.disabled) { return; }

        self.close();
        self.select.selectedIndex = index;
        self.select.dispatchEvent(new Event('change', { bubbles: true }));
        self.trigger.focus();
    };

    EnhancedSelect.prototype.toggle = function () {
        if (this.open) { this.close(); } else { this.openMenu(); }
    };

    EnhancedSelect.prototype.openMenu = function () {
        var self = this;
        if (self.open || self.select.disabled) { return; }
        if (openInst && openInst !== self) { openInst.close(); }

        self.buildMenu();
        document.body.appendChild(self.menu);

        self.open = true;
        openInst = self;
        self.wrap.classList.add('is-open');
        self.trigger.setAttribute('aria-expanded', 'true');

        self.position();

        var index = self.select.selectedIndex >= 0 ? self.select.selectedIndex : self.firstEnabled();
        self.setActive(index, true);

        document.addEventListener('mousedown', onDocumentDown, true);
        document.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onResize);
    };

    EnhancedSelect.prototype.close = function () {
        var self = this;
        if (!self.open) { return; }

        self.open = false;
        if (openInst === self) { openInst = null; }
        self.wrap.classList.remove('is-open');
        self.trigger.setAttribute('aria-expanded', 'false');
        self.trigger.removeAttribute('aria-activedescendant');

        if (self.menu && self.menu.parentNode) {
            self.menu.parentNode.removeChild(self.menu);
        }
        self.menu = null;
        self.activeIndex = -1;

        document.removeEventListener('mousedown', onDocumentDown, true);
        document.removeEventListener('scroll', onScroll, true);
        window.removeEventListener('resize', onResize);
    };

    EnhancedSelect.prototype.position = function () {
        var self = this;
        var menu = self.menu;
        if (!menu) { return; }

        var rect = self.trigger.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;

        menu.style.minWidth = Math.round(rect.width) + 'px';
        menu.style.maxWidth = Math.max(160, vw - EDGE * 2) + 'px';
        menu.style.maxHeight = '';

        var below = vh - rect.bottom - GAP - EDGE;
        var above = rect.top - GAP - EDGE;
        var menuHeight = menu.offsetHeight;
        var top;

        if (menuHeight <= below || below >= above) {
            top = rect.bottom + GAP;
            menu.style.maxHeight = Math.min(MAX_HEIGHT, Math.max(120, below)) + 'px';
        } else {
            menu.style.maxHeight = Math.min(MAX_HEIGHT, Math.max(120, above)) + 'px';
            top = rect.top - GAP - menu.offsetHeight;
        }

        var menuWidth = menu.offsetWidth;
        var left = rect.left;
        if (left + menuWidth > vw - EDGE) { left = vw - EDGE - menuWidth; }
        if (left < EDGE) { left = EDGE; }

        menu.style.top = Math.round(top) + 'px';
        menu.style.left = Math.round(left) + 'px';
    };

    function onDocumentDown(event) {
        if (!openInst) { return; }
        var target = event.target;
        if (!target || !target.closest) { return; }
        if (openInst.menu && openInst.menu.contains(target)) { return; }
        if (openInst.trigger.contains(target)) { return; }
        // 点击包裹该下拉的 <label> 时，浏览器会把点击转发给原生 select，
        // 这里不关闭，避免"先关闭再重新打开"的闪烁。
        var label = target.closest('label');
        if (label && label.contains(openInst.trigger)) { return; }
        openInst.close();
    }

    function onScroll(event) {
        if (!openInst) { return; }
        var target = event.target;
        if (openInst.menu && target && openInst.menu.contains(target)) { return; }

        var rect = openInst.trigger.getBoundingClientRect();
        var vh = document.documentElement.clientHeight;
        if (rect.bottom < 0 || rect.top > vh) {
            openInst.close();
            return;
        }
        openInst.position();
    }

    function onResize() {
        if (openInst) { openInst.position(); }
    }

    function enhance(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll(SELECTOR);
        for (var i = 0; i < nodes.length; i++) {
            var select = nodes[i];
            if (select.classList.contains('dlm-select-native')) { continue; }
            if (select.dataset.dlmEnhanced === '1') { continue; }
            select.dataset.dlmEnhanced = '1';
            instances.push(new EnhancedSelect(select));
        }
    }

    function syncAll() {
        instances.forEach(function (inst) {
            if (inst.select.isConnected === false) { return; }
            inst.sync();
        });
    }

    document.addEventListener('reset', function (event) {
        if (!event.target || !event.target.querySelectorAll) { return; }
        window.setTimeout(syncAll, 0);
    }, true);

    window.DLM = window.DLM || {};
    window.DLM.enhanceSelects = enhance;
    window.DLM.syncSelects = syncAll;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(); });
    } else {
        enhance();
    }

    window.DLM.selectsReady = true;
})();

/* ============================================================
 * 自定义文件选择控件
 * ------------------------------------------------------------
 * 渐进增强：保留原生 <input type="file"> 作为真正的表单控件
 * （表单提交 / FormData 读取均不受影响），只在其上方渲染与站内
 * UI 一致的触发按钮、文件名显示与清除操作。
 * ============================================================ */
(function () {
    'use strict';

    if (window.DLM && window.DLM.filesReady) { return; }

    var SELECTOR = 'input[type="file"]:not([data-native-file])';
    var instances = [];

    function baseName(value) {
        var parts = String(value).split(/[\\/]/);
        return parts[parts.length - 1] || '';
    }

    function FileField(input) {
        var self = this;
        self.input = input;
        self.picking = false;

        var wrap = document.createElement('div');
        wrap.className = 'dlm-file';

        var trigger = document.createElement('span');
        trigger.className = 'dlm-file-trigger';
        trigger.setAttribute('role', 'button');
        trigger.setAttribute('tabindex', '0');
        trigger.innerHTML = '<svg class="dlm-file-icon" width="15" height="15" viewBox="0 0 16 16"'
            + ' fill="none" aria-hidden="true">'
            + '<path d="M8 10.5V2.6M8 2.6 5 5.6M8 2.6l3 3" stroke="currentColor"'
            + ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
            + '<path d="M2.6 10.4v1.9c0 .7.5 1.2 1.2 1.2h8.4c.7 0 1.2-.5 1.2-1.2v-1.9"'
            + ' stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>'
            + '<span class="dlm-file-label">选择文件</span>';

        var name = document.createElement('span');
        name.className = 'dlm-file-name';

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'dlm-file-clear';
        clear.setAttribute('aria-label', '清除已选文件');
        clear.innerHTML = '&times;';
        clear.hidden = true;

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(trigger);
        wrap.appendChild(name);
        wrap.appendChild(clear);
        wrap.appendChild(input);

        input.classList.add('dlm-file-native');

        self.wrap = wrap;
        self.trigger = trigger;
        self.nameEl = name;
        self.clearEl = clear;
        self.labelEl = trigger.querySelector('.dlm-file-label');

        self.sync();

        // 阻止 <label> 的默认转发，否则会连续弹出两次文件对话框
        // 统一入口：只在用户真实交互中调用，且带重入保护。
        // 注意：input.click() 派发的 click 事件会冒泡回 wrap，若不拦下会再次
        // 进入本函数，并在 preventDefault 后取消掉刚打开的文件对话框。
        function openPicker() {
            if (input.disabled || self.picking) { return; }
            self.picking = true;
            try {
                input.click();
            } finally {
                self.picking = false;
            }
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            openPicker();
        });

        // 点击整行（除清除按钮外）也能打开文件选择器
        wrap.addEventListener('click', function (event) {
            // 来自原生 input 自身的事件直接放行，避免拦截它打开的对话框
            if (event.target === input) { return; }
            if (event.target.closest('.dlm-file-clear')) { return; }
            if (event.target.closest('.dlm-file-trigger')) { return; }
            event.preventDefault();
            openPicker();
        });

        trigger.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') { return; }
            event.preventDefault();
            openPicker();
        });

        clear.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            try { input.value = ''; } catch (e) { return; }
            input.dispatchEvent(new Event('change', { bubbles: true }));
            trigger.focus();
        });

        input.addEventListener('change', function () { self.sync(); });
        input.addEventListener('focus', function () { wrap.classList.add('is-focus'); });
        input.addEventListener('blur', function () { wrap.classList.remove('is-focus'); });

        if (input.disabled) {
            wrap.classList.add('is-disabled');
        }

        self.observer = null;
        if (window.MutationObserver) {
            self.observer = new MutationObserver(function () { self.sync(); });
            self.observer.observe(input, { attributes: true, attributeFilter: ['disabled'] });
        }
    }

    FileField.prototype.sync = function () {
        var self = this;
        var files = self.input.files;
        var names = [];

        if (files && files.length) {
            for (var i = 0; i < files.length; i++) { names.push(files[i].name); }
        } else if (self.input.value) {
            names.push(baseName(self.input.value));
        }

        if (names.length) {
            var text = names.join('、');
            self.nameEl.textContent = text;
            self.nameEl.title = text;
            self.nameEl.classList.add('is-set');
            self.clearEl.hidden = false;
            self.labelEl.textContent = '更换文件';
        } else {
            self.nameEl.textContent = '未选择文件';
            self.nameEl.removeAttribute('title');
            self.nameEl.classList.remove('is-set');
            self.clearEl.hidden = true;
            self.labelEl.textContent = '选择文件';
        }

        if (self.input.disabled) {
            self.wrap.classList.add('is-disabled');
        } else {
            self.wrap.classList.remove('is-disabled');
        }
    };

    function enhance(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll(SELECTOR);
        for (var i = 0; i < nodes.length; i++) {
            var input = nodes[i];
            if (input.classList.contains('dlm-file-native')) { continue; }
            if (input.dataset.dlmFile === '1') { continue; }
            input.dataset.dlmFile = '1';
            instances.push(new FileField(input));
        }
    }

    function syncAll() {
        instances.forEach(function (inst) {
            if (inst.input.isConnected === false) { return; }
            inst.sync();
        });
    }

    document.addEventListener('reset', function (event) {
        if (!event.target || !event.target.querySelectorAll) { return; }
        window.setTimeout(syncAll, 0);
    }, true);

    window.DLM = window.DLM || {};
    window.DLM.enhanceFiles = enhance;
    window.DLM.syncFiles = syncAll;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(); });
    } else {
        enhance();
    }

    window.DLM.filesReady = true;
})();
