(function () {
    'use strict';

    async function hydrateCsrf() {
        var fields = document.querySelectorAll('[data-cachekit-csrf]');
        var metas = document.querySelectorAll('meta[data-cachekit-csrf-meta]');
        if (!fields.length && !metas.length) {
            return;
        }

        // In de noodkopie draait geen PHP: het token ophalen kan niet. Markeer
        // de formulieren zodat de site ze kan uitschakelen of een melding kan tonen.
        if (document.querySelector('meta[name="cachekit-snapshot"]')) {
            fields.forEach(function (field) {
                if (field.form) {
                    field.form.setAttribute('data-cachekit-offline', '');
                }
            });
            document.dispatchEvent(new CustomEvent('cachekit:offline'));
            return;
        }

        try {
            var response = await fetch('/actions/cachekit/csrf/token', {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            var data = await response.json();
            fields.forEach(function (field) {
                field.name = data.param;
                field.value = data.token;
            });
            metas.forEach(function (meta) {
                if (meta.dataset.cachekitCsrfMeta === 'name') {
                    meta.content = data.param;
                } else {
                    meta.content = data.token;
                }
            });
            document.dispatchEvent(new CustomEvent('cachekit:csrf-ready', {detail: data}));
        } catch (error) {
            console.error('CacheKit kon het CSRF-token niet laden.', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hydrateCsrf);
    } else {
        hydrateCsrf();
    }
})();
