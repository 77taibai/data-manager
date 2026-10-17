(function () {
    'use strict';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('dlm-theme', theme); } catch (e) {}
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('#theme-toggle');
        if (toggle) {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        }
    });

    window.DLM = window.DLM || {};
    window.DLM.applyTheme = applyTheme;
    window.DLM.currentTheme = currentTheme;

    window.DLM.openModal = function (id) {
        var el = document.getElementById(id);
        if (el) { el.hidden = false; }
    };

    window.DLM.closeModal = function (id) {
        var el = document.getElementById(id);
        if (el) { el.hidden = true; }
    };

    window.DLM.confirmSubmit = function (form, message) {
        return window.confirm(message || '确定要执行该操作吗？');
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
