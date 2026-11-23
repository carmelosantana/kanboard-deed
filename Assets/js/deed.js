(function () {
    'use strict';

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
