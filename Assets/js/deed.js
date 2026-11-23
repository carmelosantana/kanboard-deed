(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var link = event.target?.closest?.('a[data-deed-dns]');
        if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        var results = document.getElementById(link.getAttribute('aria-controls'));
        if (!results) {
            return;
        }
        event.preventDefault();
        if (results.getAttribute('aria-busy') === 'true') {
            return;
        }
        results.setAttribute('aria-busy', 'true');

        fetch(link.href, {redirect: 'error'}).then(function (response) {
            if (!response.ok) {
                throw new Error('DNS request failed');
            }
            return response.text();
        }).then(function (html) {
            results.innerHTML = html;
        }).catch(function () {
            results.textContent = results.dataset.deedDnsError;
        }).finally(function () {
            results.removeAttribute('aria-busy');
        });
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!event.defaultPrevented && form.matches('form[data-deed-refresh]')) {
            var button = form.querySelector('button[type="submit"]');
            if (button.disabled) {
                event.preventDefault();
                return;
            }
            button.disabled = true;
        }
    });

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-deed-refresh] button:disabled').forEach(function (button) {
            button.disabled = false;
        });
    });
}());
