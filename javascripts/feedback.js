/*!
 * Matomo - free/libre analytics platform
 * @link https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/*
 * Feedback question at the end of the share dialog (Feinwerk feedback kit,
 * FeinwerkEmailReportsPro/docs/feedback-kit.md; markup in templates/_feedback.twig, loaded with the dialog through the
 * controller action dialogNotes): thumbs up or down go to the API method sendFeedback, the Matomo server talks to
 * Feinwerk, never the browser. Plain script without a build and without jQuery. The dialog is built after the page and
 * again every time it opens, so new bars are prepared through a MutationObserver and clicks are caught at the document.
 */
(function () {
    'use strict';

    var PLUGIN = 'FeinwerkDashboardSharing';

    /** localStorage: this browser's vote per question and plugin version (a convenience only) */
    function storageKey(topic) {
        return 'fwsh.feedback.' + topic;
    }

    function storedVote(topic, version) {
        try {
            var stored = JSON.parse(window.localStorage.getItem(storageKey(topic)) || '{}');
            return stored.version === version && (stored.vote === 'up' || stored.vote === 'down') ? stored.vote : '';
        } catch (e) {
            return '';
        }
    }

    function show(bar, vote) {
        bar.querySelectorAll('.fwsh-feedback__thumb').forEach(function (thumb) {
            var chosen = thumb.getAttribute('data-vote') === vote;
            thumb.disabled = true;
            thumb.classList.toggle('is-chosen', chosen);
            thumb.setAttribute('aria-pressed', chosen ? 'true' : 'false');
        });
        var thanks = bar.querySelector('.fwsh-feedback__thanks');
        if (thanks) {
            thanks.textContent = bar.getAttribute(vote === 'up' ? 'data-thanks-up' : 'data-thanks-down') || '';
        }
    }

    function prepare() {
        document.querySelectorAll('.fwsh-feedback:not([data-fwsh-ready])').forEach(function (bar) {
            bar.setAttribute('data-fwsh-ready', '1');
            var vote = storedVote(bar.getAttribute('data-topic'), bar.getAttribute('data-version'));
            if (vote) {
                show(bar, vote);
            }
        });
    }

    function send(bar, vote) {
        var topic = bar.getAttribute('data-topic');
        // every bar with this question on the page
        document.querySelectorAll('.fwsh-feedback[data-topic="' + topic + '"]').forEach(function (other) {
            show(other, vote);
        });
        try {
            window.localStorage.setItem(storageKey(topic), JSON.stringify({version: bar.getAttribute('data-version'), vote: vote}));
        } catch (e) {
            // private mode or blocked storage: the thanks is shown anyway
        }
        var matomo = window.piwik || {};
        var body = new URLSearchParams();
        body.append('token_auth', matomo.token_auth || '');
        body.append('force_api_session', '1');
        body.append('topic', topic);
        body.append('vote', vote);
        // Best effort: the vote is a hint for Feinwerk, a failed request needs no error message.
        window.fetch('index.php?module=API&format=json&method=' + PLUGIN + '.sendFeedback', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).catch(function () {});
    }

    document.addEventListener('click', function (event) {
        var thumb = event.target && event.target.closest ? event.target.closest('.fwsh-feedback__thumb') : null;
        if (!thumb || thumb.disabled) {
            return;
        }
        var bar = thumb.closest('.fwsh-feedback');
        if (bar) {
            event.preventDefault();
            send(bar, thumb.getAttribute('data-vote'));
        }
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
