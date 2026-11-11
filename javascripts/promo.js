/*!
 * Matomo - free/libre analytics platform
 * @link https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/*
 * Feinwerk promotion kit (docs/promo-kit.md; markup in templates/_promo.twig and templates/_promoBox.twig): the tip
 * line, the admin home banner and the context boxes.
 * - "Hide" keeps a message out of this browser for 30 days (localStorage fwpromo:<id>:dismissed holds the time of the
 *   dismissal) and removes every notice of that message on the page.
 * - One notice per placement and page (data-fwpromo-placement; a line without it counts as "line"): when several
 *   reports or several Feinwerk plugins render the same placement, the first one that arrives stays, the others go.
 * Nothing is loaded and nothing is sent. Reports arrive and reload after the page, so new notices are prepared through
 * a MutationObserver and clicks are caught at the document. Plain script without a build and without jQuery. Several
 * Feinwerk plugins ship this file: the first copy on the page does the work (window.fwpromoKit).
 */
(function () {
    'use strict';

    if (window.fwpromoKit) {
        return;
    }
    window.fwpromoKit = 2;

    var DAY = 86400000;
    var NOTICES = '.fwpromo-line, .fwpromo-box';
    var DISMISS = '.fwpromo-dismiss, .fwpromo-line__dismiss';

    function key(id) {
        return 'fwpromo:' + id + ':dismissed';
    }

    function placementOf(notice) {
        return notice.getAttribute('data-fwpromo-placement') || 'line';
    }

    function dismissed(notice) {
        try {
            var at = parseInt(window.localStorage.getItem(key(notice.getAttribute('data-fwpromo-id'))) || '0', 10);
            var days = parseInt(notice.getAttribute('data-fwpromo-days') || '30', 10);
            return at > 0 && Date.now() - at < days * DAY;
        } catch (e) {
            return false;
        }
    }

    function remove(notice) {
        // a context box is the only content of its Matomo widget: hide that widget as well, so no gap stays behind
        var widget = notice.classList.contains('fwpromo-box') && notice.closest ? notice.closest('.matomo-widget') : null;
        if (widget) {
            widget.style.display = 'none';
        }
        if (notice.parentNode) {
            notice.parentNode.removeChild(notice);
        }
    }

    function prepare() {
        var notices = document.querySelectorAll(NOTICES);
        var shown = {};
        Array.prototype.forEach.call(notices, function (notice) {
            if (notice.getAttribute('data-fwpromo-ready')) {
                shown[placementOf(notice)] = true;
            }
        });
        Array.prototype.forEach.call(notices, function (notice) {
            if (notice.getAttribute('data-fwpromo-ready')) {
                return;
            }
            var placement = placementOf(notice);
            if (shown[placement] || dismissed(notice)) {
                remove(notice);
                return;
            }
            notice.setAttribute('data-fwpromo-ready', '1');
            shown[placement] = true;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest(DISMISS) : null;
        var notice = button ? button.closest(NOTICES) : null;
        if (!notice) {
            return;
        }
        event.preventDefault();
        var id = notice.getAttribute('data-fwpromo-id') || '';
        try {
            window.localStorage.setItem(key(id), String(Date.now()));
        } catch (e) {
            // private mode or blocked storage: hidden on this page only
        }
        Array.prototype.forEach.call(document.querySelectorAll(NOTICES), function (other) {
            if (other.getAttribute('data-fwpromo-id') === id) {
                remove(other);
            }
        });
    });

    function start() {
        prepare();
        if (!window.MutationObserver) {
            return;
        }
        var scheduled = false;
        new window.MutationObserver(function () {
            if (scheduled) {
                return;
            }
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;
                prepare();
            });
        }).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
