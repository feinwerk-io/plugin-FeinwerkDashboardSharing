/**
 * Matomo - free/libre analytics platform
 *
 * FeinwerkDashboardSharing: share a dashboard, subscribe to shared dashboards, auto-sync subscribed
 * (linked) dashboards and publish a read-only marker for other plugins. Pure-additive: no core files
 * are modified.
 *
 * Read-only marker (consumed by e.g. FeinwerkDashboardSegment): while a mirror dashboard is open,
 * window.FeinwerkDashboardSharing.readOnly === true and #dashboardWidgetsArea has the class
 * `fwsh-readonly`. The jQuery event `fwsh:readonlychange` is triggered on `document` whenever that
 * state is (re)determined.
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
(function () {
    'use strict';

    var FeinwerkDashboardSharing = {
        readOnly: false,      // true on a subscribed (linked) dashboard
        linkedOwner: '',      // owner login of the currently-open subscribed dashboard (for the banner)
        linkedDashboards: {}, // idDashboard -> owner login, for marking subscribed dashboards in the tab menu
        synced: {}            // idDashboard -> true once auto-synced this page session
    };

    // Current dashboard id from the URL hash (Dashboard uses `subcategory`). null when unknown.
    function currentDashboardId() {
        var broadcast = window.broadcast;
        var value = (broadcast && (broadcast.getValueFromHash('subcategory') || broadcast.getValueFromUrl('subcategory'))) || '';
        var id = parseInt(value, 10);
        return isNaN(id) ? null : id;
    }

    function currentIdSite() {
        if (window.piwik && window.piwik.idSite) { return window.piwik.idSite; }
        var broadcast = window.broadcast;
        var v = (broadcast && (broadcast.getValueFromHash('idSite') || broadcast.getValueFromUrl('idSite'))) || '';
        return v || null;
    }

    // Idiomatic Matomo request via ajaxHelper (handles CSRF token + idSite/period/date defaults).
    function dsAjax(action, getParams, postParams) {
        var ajax = new window.ajaxHelper();
        ajax.addParams(window.$.extend({ module: 'FeinwerkDashboardSharing', action: action }, getParams || {}), 'get');
        if (postParams) { ajax.addParams(postParams, 'post'); }
        ajax.withTokenInUrl();
        ajax.setFormat('json');
        return ajax.send();
    }

    // User-facing toast for a failed action. Reuses Matomo's own notification UI; guarded so a missing
    // require() (e.g. in the JS smoke test) never throws.
    function notify(message, context) {
        try {
            var UI = require('piwik/UI');
            new UI.Notification().show(message, { context: context || 'error', type: 'toast' });
        } catch (e) {
            if (window.console) { window.console.error('FeinwerkDashboardSharing:', message); }
        }
    }

    function notifyShareError() { notify(_pk_translate('FeinwerkDashboardSharing_ShareError'), 'error'); }

    // Publish whether the open dashboard is a read-only mirror (see file header).
    // Tracked here (not via .readOnly, which callers pre-set) so the event fires only on a real change.
    var lastReadOnlyMarker = false;

    function setReadOnlyMarker(on) {
        var changed = lastReadOnlyMarker !== !!on;
        lastReadOnlyMarker = !!on;
        window.FeinwerkDashboardSharing = window.FeinwerkDashboardSharing || {};
        window.FeinwerkDashboardSharing.readOnly = !!on;
        var $area = window.$('#dashboardWidgetsArea');
        if (on) { $area.addClass('fwsh-readonly'); } else { $area.removeClass('fwsh-readonly'); }
        if (changed) { window.$(document).trigger('fwsh:readonlychange'); }
    }

    function currentDashboardName() {
        try { return window.$('#dashboardWidgetsArea').dashboard('getDashboardName') || ''; } catch (e) { return ''; }
    }

    function reloadDashboardArea(id) {
        var $area = window.$('#dashboardWidgetsArea');
        if ($area.length && typeof $area.dashboard === 'function') {
            try { $area.dashboard('loadDashboard', id, true); return true; }
            catch (e) { if (window.console) { window.console.error('FeinwerkDashboardSharing dashboard reload failed', e); } }
        }
        return false;
    }

    // Remove this plugin's controls (share/import buttons) -- used on a read-only mirror dashboard.
    function stripDecorations() {
        window.$('#fwshControls').remove();
    }

    function closeModal() { window.$('.fwsh-overlay').remove(); }

    function openModal(titleText) {
        closeModal();
        var $overlay = window.$('<div class="fwsh-overlay"></div>');
        var $box = window.$('<div class="fwsh-modal"></div>');
        var $head = window.$('<div class="fwsh-modalHead"></div>').text(titleText);
        var $close = window.$('<a href="javascript:;" class="fwsh-modalClose" title="' + _pk_translate('FeinwerkDashboardSharing_Close') + '">×</a>');
        $close.on('click', closeModal);
        $head.append($close);
        var $body = window.$('<div class="fwsh-modalBody"></div>');
        $box.append($head).append($body);
        $overlay.append($box);
        $overlay.on('click', function (e) { if (e.target === $overlay[0]) { closeModal(); } });
        window.$('body').append($overlay);
        return $body;
    }

    function renderMyShares($container) {
        dsAjax('getMyShares', {}).then(function (shares) {
            $container.empty();
            if (!shares || !shares.length) {
                $container.append(window.$('<div class="fwsh-hint"></div>').text(_pk_translate('FeinwerkDashboardSharing_NoShares')));
                return;
            }
            shares.forEach(function (s) {
                var who = s.share_to_all ? _pk_translate('FeinwerkDashboardSharing_ShareToAllLabel') : (s.targets || []).join(', ');
                var $row = window.$('<div class="fwsh-shareRow"></div>');
                $row.append(window.$('<span class="fwsh-shareName"></span>').text(s.name + ' → ' + who));
                $row.append(window.$('<span class="fwsh-shareMeta"></span>').text(' (' + _pk_translate('FeinwerkDashboardSharing_Subscribers', [String(s.subscribers)]) + ')'));
                var $del = window.$('<a href="javascript:;" class="fwsh-link fwsh-danger"></a>').text(_pk_translate('FeinwerkDashboardSharing_Unshare'));
                $del.on('click', function () {
                    dsAjax('unshareDashboard', {}, { idShare: s.idshare })
                        .then(function () { renderMyShares($container); }, notifyShareError);
                });
                $row.append($del);
                $container.append($row);
            });
        });
    }

    function openShareDialog() {
        var id = currentDashboardId();
        if (id === null) { return; }
        var $body = openModal(_pk_translate('FeinwerkDashboardSharing_ShareDashboard'));

        // name
        $body.append(window.$('<label class="fwsh-label"></label>').text(_pk_translate('FeinwerkDashboardSharing_ShareName')));
        var $name = window.$('<input type="text" class="fwsh-input" />').val(currentDashboardName() || 'Shared dashboard');
        $body.append($name);

        // who can subscribe
        $body.append(window.$('<div class="fwsh-sectionTitle"></div>').text(_pk_translate('FeinwerkDashboardSharing_ShareWhoTitle')));
        var $all = window.$('<input type="radio" name="fwshShareTarget" checked />');
        $body.append(window.$('<label class="fwsh-radio"></label>').append($all).append(document.createTextNode(' ' + _pk_translate('FeinwerkDashboardSharing_ShareToAll'))));
        var $users = window.$('<input type="radio" name="fwshShareTarget" />');
        $body.append(window.$('<label class="fwsh-radio"></label>').append($users).append(document.createTextNode(' ' + _pk_translate('FeinwerkDashboardSharing_ShareSpecificUsers'))));

        // "specific users" is always available: a checkbox list (admins) AND a free-text login
        // field, so it works even when the user list can't be shown (non-admins).
        var $specific = window.$('<div class="fwsh-specific" style="display:none"></div>');
        var $userList = window.$('<div class="fwsh-userList"></div>');
        $specific.append($userList);
        $specific.append(window.$('<label class="fwsh-label"></label>').text(_pk_translate('FeinwerkDashboardSharing_ShareEnterLogins')));
        var $logins = window.$('<input type="text" class="fwsh-input" placeholder="login1, login2" />');
        $specific.append($logins);
        $body.append($specific);

        // cache-busted so a stale (empty) response from before isn't reused
        dsAjax('getShareableUsers', { _: (new Date()).getTime() }).then(function (users) {
            if (!users || !users.length) {
                $userList.append(window.$('<div class="fwsh-hint"></div>').text(_pk_translate('FeinwerkDashboardSharing_ShareUsersAdminOnly')));
                return;
            }
            users.forEach(function (u) {
                var $l = window.$('<label class="fwsh-userItem"></label>');
                $l.append(window.$('<input type="checkbox" class="fwsh-userCb" />').val(u));
                $l.append(document.createTextNode(' ' + u));
                $userList.append($l);
            });
        });
        $all.on('change', function () { $specific.hide(); });
        $users.on('change', function () { $specific.show(); });

        // footer
        var $footer = window.$('<div class="fwsh-footer"></div>');
        var $cancel = window.$('<button type="button" class="fwsh-btn"></button>').text(_pk_translate('FeinwerkDashboardSharing_Cancel'));
        $cancel.on('click', closeModal);
        var $share = window.$('<button type="button" class="fwsh-btn fwsh-btnPrimary"></button>').text(_pk_translate('FeinwerkDashboardSharing_ShareSubmit'));
        $share.on('click', function () {
            var shareToAll = $all.is(':checked') ? 1 : 0;
            var targets = [];
            if (!shareToAll) {
                $userList.find('.fwsh-userCb:checked').each(function () { targets.push(window.$(this).val()); });
                ($logins.val() || '').split(',').forEach(function (t) {
                    t = t.trim();
                    if (t && targets.indexOf(t) === -1) { targets.push(t); }
                });
                if (!targets.length) { return; }
            }
            $share.prop('disabled', true);
            dsAjax('shareDashboard', { idDashboard: id },
                { name: $name.val(), shareToAll: shareToAll, targetLogins: targets.join(',') }
            ).then(function () { $share.prop('disabled', false); renderMyShares($myShares); },
                   function () { $share.prop('disabled', false); notifyShareError(); });
        });
        $footer.append($cancel).append($share);
        $body.append($footer);

        // my shares
        $body.append(window.$('<h4 class="fwsh-sub"></h4>').text(_pk_translate('FeinwerkDashboardSharing_MyShares')));
        var $myShares = window.$('<div class="fwsh-myShares"></div>');
        $body.append($myShares);
        renderMyShares($myShares);
    }

    function openAvailablePanel() {
        var $body = openModal(_pk_translate('FeinwerkDashboardSharing_SharedDashboards'));
        var $list = window.$('<div class="fwsh-availList"></div>');
        $body.append($list);
        dsAjax('getAvailableShares', {}).then(function (shares) {
            $list.empty();
            if (!shares || !shares.length) {
                $list.append(window.$('<div class="fwsh-hint"></div>').text(_pk_translate('FeinwerkDashboardSharing_NoAvailableShares')));
                return;
            }
            shares.forEach(function (s) {
                var $row = window.$('<div class="fwsh-shareRow"></div>');
                $row.append(window.$('<span class="fwsh-shareName"></span>').text(s.name + ' (' + s.owner + ')'));
                var $sub = window.$('<button class="fwsh-btn"></button>').text(_pk_translate('FeinwerkDashboardSharing_Subscribe'));
                $sub.on('click', function () {
                    dsAjax('subscribeToShare', {}, { idShare: s.idshare }).then(function () {
                        closeModal();
                        window.location.reload(); // refresh the tab list so the new dashboard appears
                    }, notifyShareError);
                });
                $row.append($sub);
                $list.append($row);
            });
        });
    }

    function makeIconButton(iconClass, label, onClick) {
        var $b = window.$('<button type="button" class="fwsh-btn"></button>');
        $b.append(window.$('<span class="' + iconClass + '"></span>'));
        $b.append(document.createTextNode(' ' + label));
        $b.on('click', function (e) { e.stopPropagation(); onClick(); });
        return $b;
    }

    // This plugin owns its control row (#fwshControls, above the dashboard area) so it works with or
    // without FeinwerkDashboardSegment installed.
    function injectShareControls() {
        var $area = window.$('#dashboardWidgetsArea');
        if (!$area.length || window.$('#fwshControls').length) { return; }
        var $wrap = window.$('<div id="fwshControls" class="fwsh-shareControls"></div>');
        $wrap.append(makeIconButton('icon-export', _pk_translate('FeinwerkDashboardSharing_ShareDashboard'), openShareDialog).addClass('fwsh-shareLink'));
        $wrap.append(makeIconButton('icon-download', _pk_translate('FeinwerkDashboardSharing_SharedDashboards'), openAvailablePanel).addClass('fwsh-availLink'));
        $area.before($wrap);
    }

    function removeLinkedBanner() { window.$('#fwshLinked').remove(); }

    function showLinkedBanner(owner) {
        removeLinkedBanner();
        var $area = window.$('#dashboardWidgetsArea');
        if (!$area.length) { return; }
        var $b = window.$('<div id="fwshLinked" class="fwsh-linked"></div>');
        $b.append(window.$('<span class="fwsh-linkedText"></span>').text(_pk_translate('FeinwerkDashboardSharing_SyncedNotice', [owner || '?'])));
        var $unsub = window.$('<a href="javascript:;" class="fwsh-link"></a>').text(_pk_translate('FeinwerkDashboardSharing_Unsubscribe'));
        $unsub.on('click', function () {
            dsAjax('unsubscribe', {}, { idDashboard: currentDashboardId() })
                .then(function () { window.location.reload(); }, notifyShareError);
        });
        $b.append(document.createTextNode(' ')).append($unsub);
        $area.before($b);
    }

    // Entry point for (re)entering a dashboard: subscribed/linked dashboards auto-sync from source.
    function enterDashboard() {
        var id = currentDashboardId();
        if (id === null) {
            FeinwerkDashboardSharing.readOnly = false;
            setReadOnlyMarker(false);
            removeLinkedBanner();
            injectShareControls();
            return;
        }
        dsAjax('getLinkInfo', { idDashboard: id }).then(function (info) {
            if (info && info.linked) {
                FeinwerkDashboardSharing.readOnly = true;
                FeinwerkDashboardSharing.linkedOwner = info.owner || '';
                setReadOnlyMarker(true);
                stripDecorations();
                showLinkedBanner(info.owner);
                if (!FeinwerkDashboardSharing.synced[id]) {
                    FeinwerkDashboardSharing.synced[id] = true;
                    dsAjax('syncLinkedDashboard', {}, { idDashboard: id }).then(function () {
                        reloadDashboardArea(id);
                    }, function () {});
                }
            } else {
                FeinwerkDashboardSharing.readOnly = false;
                setReadOnlyMarker(false);
                removeLinkedBanner();
                injectShareControls();
            }
        }, function () {
            FeinwerkDashboardSharing.readOnly = false;
            setReadOnlyMarker(false);
            injectShareControls();
        });
    }

    // ---- "shared" marker on dashboard tabs --------------------------------------------------

    // Links to the user's dashboards: Matomo 5's reporting menu (desktop + mobile; not the site selector, whose
    // links point to the same dashboards on other sites), and the older dashboard tab menu.
    var DASHBOARD_MENU_LINKS = '#secondNavBar .reportingMenu a[href*="category=Dashboard_Dashboard"], #Dashboard a[data-iddashboard]';

    // Dashboard id a menu link opens, or null. The reporting menu has it in the hash
    // (category=Dashboard_Dashboard&subcategory=<id>), the tab menu in data-iddashboard.
    function menuLinkDashboardId(href, dataId) {
        if (dataId) { return String(dataId); }
        href = String(href || '');
        if (!/[?#&]category=Dashboard_Dashboard(&|$)/.test(href)) { return null; }
        var m = /[?#&]subcategory=(\d+)(&|$)/.exec(href);
        return m ? m[1] : null;
    }

    function decorateTabs() {
        var linked = FeinwerkDashboardSharing.linkedDashboards;
        window.$(DASHBOARD_MENU_LINKS).each(function () {
            var $a = window.$(this);
            var id = menuLinkDashboardId($a.attr('href'), $a.attr('data-iddashboard'));
            if (id !== null && Object.prototype.hasOwnProperty.call(linked, id) && !$a.find('.fwsh-sharedTabIcon').length) {
                $a.append(window.$('<span class="fwsh-sharedTabIcon icon-users"></span>'));
                $a.attr('title', _pk_translate('FeinwerkDashboardSharing_SyncedBadge', [linked[id] || '?']));
            }
        });
    }

    function loadLinkedDashboards() {
        dsAjax('getMyLinkedDashboards', {}).then(function (list) {
            var map = {};
            (list || []).forEach(function (d) { map[String(d.iddashboard)] = d.owner; });
            FeinwerkDashboardSharing.linkedDashboards = map;
            decorateTabs();
        }, function () {});
    }

    // The menu is built/rebuilt asynchronously (Vue); watch it and (re)apply the marker.
    function watchTabs(retries) {
        var menu = document.getElementById('secondNavBar') || document.getElementById('Dashboard');
        if (menu && window.MutationObserver) {
            decorateTabs();
            new window.MutationObserver(function () { decorateTabs(); })
                .observe(menu, { childList: true, subtree: true });
            return;
        }
        if ((retries || 0) < 40) {
            window.setTimeout(function () { watchTabs((retries || 0) + 1); }, 250);
        }
    }

    // Expose helpers (also used by tests/jsSmoke.js). Keep any marker already published.
    FeinwerkDashboardSharing.setReadOnlyMarker = setReadOnlyMarker;
    FeinwerkDashboardSharing.enterDashboard = enterDashboard;
    FeinwerkDashboardSharing.menuLinkDashboardId = menuLinkDashboardId;
    FeinwerkDashboardSharing.dashboardMenuLinks = DASHBOARD_MENU_LINKS;
    window.FeinwerkDashboardSharing = FeinwerkDashboardSharing;

    window.$(function () {
        // Anonymous viewers (public dashboards) have nothing to load or save: every endpoint of this
        // plugin needs a signed-in user. Stay idle so the dashboard renders normally.
        if (!window.piwik || !window.piwik.userLogin || window.piwik.userLogin === 'anonymous') {
            return;
        }

        // Keep the controls/banner/marker present whenever widgets (re)render.
        window.$(document).on('widget:create', function (e, widget) {
            if (!widget || !widget.uniqueId) { return; }
            if (FeinwerkDashboardSharing.readOnly) {
                // keep the "synced from <owner>, read-only" banner and CSS class across re-renders
                window.$('#dashboardWidgetsArea').addClass('fwsh-readonly');
                if (!window.$('#fwshLinked').length) { showLinkedBanner(FeinwerkDashboardSharing.linkedOwner); }
            } else {
                injectShareControls();
            }
        });

        enterDashboard();
        loadLinkedDashboards();
        watchTabs();

        var lastContext = currentIdSite() + '|' + currentDashboardId();
        function onContextMaybeChanged() {
            var ctx = currentIdSite() + '|' + currentDashboardId();
            if (ctx === lastContext) { return; }
            lastContext = ctx;
            window.$('#fwshControls').remove();
            removeLinkedBanner();
            enterDashboard();
            decorateTabs();
        }
        window.$(window).on('hashchange', onContextMaybeChanged);
        if (window.piwik && window.piwik.on) {
            window.piwik.on('Dashboard.loadDashboard', onContextMaybeChanged);
        }
    });
})();
