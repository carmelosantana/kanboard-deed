(function () {
    'use strict';

    // Use the watchlist's refresh forms to locate each domain and preserve Kanboard's URL mode.
    document.querySelectorAll('.deed-domains form[data-deed-refresh]').forEach(function (form) {
        var domainId = form.elements.namedItem('domain_id').value;
        var heading = form.closest('tr').querySelector('th[scope="row"]');
        var url = new URL(form.action);
        if (url.searchParams.has('action')) {
            url.searchParams.set('action', 'show');
            url.searchParams.set('domain_id', domainId);
        } else {
            url.pathname = url.pathname.replace(/\/refresh$/, '/show/' + encodeURIComponent(domainId));
        }

        var link = document.createElement('a');
        link.href = url.href;
        link.textContent = heading.textContent;
        heading.replaceChildren(link);
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-deed-dns]');
        if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        var results = document.getElementById(link.getAttribute('aria-controls'));
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
