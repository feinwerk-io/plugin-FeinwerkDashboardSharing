/**
 * JS bootstrap smoke test (run: `node tests/jsSmoke.js` from the plugin dir).
 *
 * Executes javascripts/dashboardSharing.js with stubbed Matomo globals to catch runtime errors in the
 * load/bootstrap path AND inside the async .then() callbacks (enterDashboard -> getLinkInfo ->
 * syncLinkedDashboard -> ...). The ajaxHelper stub returns REAL resolved promises so those callbacks
 * actually run; unhandled errors fail the test. `node --check` only checks syntax.
 */
process.on('unhandledRejection', function (e) { console.error('UNHANDLED REJECTION:', e); process.exit(1); });
process.on('uncaughtException', function (e) { console.error('UNCAUGHT:', e); process.exit(1); });

var triggered = [];
var classes = {};
var texts = [];   // every text set through $(...).text(value)
var failing = {}; // action -> true: that request fails (Matomo 6 answers errors with HTTP 400/500)
var handlers = {}; // event name -> the last handler bound with $(...).on(name, fn) (hashchange, widget:create, ...)
function jq() {
    var o = function () { return o; };
    var methods = ['find', 'first', 'each', 'on', 'one', 'attr', 'append', 'prepend', 'after',
        'before', 'next', 'remove', 'text', 'hide', 'show', 'empty', 'css', 'dashboard', 'data',
        'map', 'filter', 'val', 'prop', 'is'];
    methods.forEach(function (m) { o[m] = function () { return o; }; });
    o.length = 0;
    o.attr = function (name, value) { return arguments.length > 1 ? o : ''; }; // setter chains like jQuery
    o.on = function (name, fn) { if (typeof name === 'string' && typeof fn === 'function') { handlers[name] = fn; } return o; };
    o.val = function () { return ''; };
    o.is = function () { return false; };
    o.dashboard = function () { return ''; };
    o.addClass = function (c) { classes[c] = true; return o; };
    o.removeClass = function (c) { delete classes[c]; return o; };
    o.trigger = function (name) { triggered.push(name); return o; };
    o.text = function (t) { if (t !== undefined) { texts.push(t); } return o; };
    return o;
}
var $ = function (arg) { if (typeof arg === 'function') { arg(); return; } return jq(); };
$.extend = Object.assign;
global.window = {};
window.console = console;
window.$ = $; window.jQuery = $;
// dashboards 1 and 2 are linked mirrors (getLinkInfo returns {linked:true}), every other dashboard is the user's own;
// everything else resolves with []
var linkedIds = { '1': true, '2': true };
var linkInfoCalls = 0;
var linkInfoMode = 'answer'; // 'hang': getLinkInfo never settles, as Matomo's ajaxHelper does for an aborted request
var lostLinkInfo = [];       // the fail callbacks a hanging getLinkInfo registered on its request handle
var lastAbortable = {};      // action -> the abortable flag the plugin set before sending
var syncCalls = 0;
var page = { category: 'Dashboard_Dashboard', subcategory: '1' }; // the URL hash parameters of the current page
window.broadcast = {
    getValueFromHash: function (name) { return page[name] || ''; }, getValueFromUrl: function (name) { return page[name] || ''; }
};
window.piwik = { idSite: 1, token_auth: 't', userLogin: 'tester', on: function () {} };
window.ajaxHelper = function () {
    var action = null;
    var idDashboard = null;
    var self = {
        addParams: function (p) {
            if (p && p.action) { action = p.action; }
            if (p && p.idDashboard !== undefined) { idDashboard = String(p.idDashboard); }
        },
        withTokenInUrl: function () {}, setFormat: function () {},
        send: function () {
            lastAbortable[action] = self.abortable;
            if (action === 'getLinkInfo' && linkInfoMode === 'hang') {
                linkInfoCalls++;
                self.requestHandle = { fail: function (fn) { lostLinkInfo.push(fn); return self.requestHandle; } };
                return new Promise(function () {});
            }
            if (failing[action]) { return Promise.reject({ status: 400 }); }
            if (action === 'getLinkInfo') {
                linkInfoCalls++;
                return Promise.resolve(linkedIds[idDashboard] ? { linked: true, owner: 'bob' } : { linked: false });
            }
            if (action === 'syncLinkedDashboard') { syncCalls++; return Promise.resolve({ synced: true }); }
            return Promise.resolve([]);
        }
    };
    return self;
};
global._pk_translate = function (k) { return k; };
window.setTimeout = function (fn) { return setTimeout(fn, 0); };
window.clearTimeout = function (t) { return clearTimeout(t); };
window.MutationObserver = function () { return { observe: function () {} }; };
global.document = {
    createTextNode: function () { return {}; },
    getElementById: function () { return {}; }
};

function assert(cond, msg) { if (!cond) { throw new Error('ASSERT FAILED: ' + msg); } }

require('../javascripts/dashboardSharing.js');

var FS = window.FeinwerkDashboardSharing;
assert(FS && typeof FS.setReadOnlyMarker === 'function', 'global + setReadOnlyMarker published');

// marker contract
triggered.length = 0;
FS.setReadOnlyMarker(true);
assert(window.FeinwerkDashboardSharing.readOnly === true, 'readOnly true');
assert(classes['fwsh-readonly'] === true, 'class fwsh-readonly added');
assert(triggered.indexOf('fwsh:readonlychange') !== -1, 'event fired on true');
triggered.length = 0;
FS.setReadOnlyMarker(false);
assert(window.FeinwerkDashboardSharing.readOnly === false, 'readOnly false');
assert(!classes['fwsh-readonly'], 'class removed');
assert(triggered.indexOf('fwsh:readonlychange') !== -1, 'event fired on false');
triggered.length = 0;
FS.setReadOnlyMarker(false);
assert(triggered.indexOf('fwsh:readonlychange') === -1, 'no event when state unchanged (false->false)');
FS.setReadOnlyMarker(true); FS.setReadOnlyMarker(true);
assert(triggered.filter(function (t) { return t === 'fwsh:readonlychange'; }).length === 1, 'same value twice fires once');
FS.setReadOnlyMarker(false);
console.log('SMOKE OK: read-only marker');

// "synced" marker in the dashboard menu: Matomo 5's reporting menu links carry the dashboard id in the hash
assert(typeof FS.menuLinkDashboardId === 'function', 'menuLinkDashboardId published');
assert(FS.menuLinkDashboardId('#?idSite=1&period=range&date=last7&category=Dashboard_Dashboard&subcategory=3') === '3', 'menu link -> dashboard id');
assert(FS.menuLinkDashboardId('#?idSite=1&category=Dashboard_Dashboard&subcategory=12&segment=') === '12', 'id followed by more params');
assert(FS.menuLinkDashboardId('#?idSite=1&category=General_Visitors&subcategory=General_Overview') === null, 'other report page -> null');
assert(FS.menuLinkDashboardId('#?idSite=1&category=Dashboard_Dashboard&subcategory=General_Overview') === null, 'non-numeric subcategory -> null');
assert(FS.menuLinkDashboardId('#?idSite=1&subcategory=4&category=Dashboard_DashboardX') === null, 'category must match exactly');
assert(FS.menuLinkDashboardId('', '5') === '5', 'older tab menu: data-iddashboard');
assert(FS.menuLinkDashboardId(undefined, undefined) === null, 'nothing -> null');
assert(FS.dashboardMenuLinks.indexOf('#secondNavBar .reportingMenu') === 0, 'marker targets the reporting menu, not the site selector');
console.log('SMOKE OK: dashboard menu marker');

// after unsubscribing, the page reloads on the first dashboard (id 1), not on the removed copy
assert(typeof FS.firstDashboardUrl === 'function', 'firstDashboardUrl published');
assert(FS.firstDashboardUrl('https://m/index.php?module=CoreHome&action=index&idSite=1#?idSite=1&category=Dashboard_Dashboard&subcategory=7')
    === 'https://m/index.php?module=CoreHome&action=index&idSite=1#?idSite=1&category=Dashboard_Dashboard&subcategory=1', 'hash subcategory -> 1');
assert(FS.firstDashboardUrl('https://m/index.php?module=CoreHome&subcategory=12&idSite=1#?subcategory=12&period=day')
    === 'https://m/index.php?module=CoreHome&subcategory=1&idSite=1#?subcategory=1&period=day', 'query and hash subcategory -> 1');
assert(FS.firstDashboardUrl('https://m/index.php?module=CoreHome#?category=General_Visitors&subcategory=General_Overview')
    === 'https://m/index.php?module=CoreHome#?category=General_Visitors&subcategory=General_Overview', 'non-numeric subcategory untouched');
console.log('SMOKE OK: reload target after unsubscribing');

// let the bootstrap's async .then() chain (enterDashboard -> getLinkInfo -> sync) run
setTimeout(function () {
    assert(linkInfoCalls >= 1, 'bootstrap called getLinkInfo');
    assert(FS.readOnly === true && classes['fwsh-readonly'] === true, 'mirror dashboard sets the marker');
    assert(triggered.indexOf('fwsh:synced') !== -1, 'a successful auto-sync is announced (fwsh:synced): ' + JSON.stringify(triggered));
    console.log('SMOKE OK: bootstrap + async callback chain ran with no error');

    // A failed list request (share dialog: users + my shares, import panel: available shares) must show the error
    // hint instead of ending in an unhandled promise rejection (process handler above exits 1).
    failing = { getShareableUsers: true, getMyShares: true, getAvailableShares: true };
    texts.length = 0;
    assert(typeof FS.openShareDialog === 'function' && typeof FS.openAvailablePanel === 'function', 'dialogs published');
    FS.openShareDialog();
    FS.openAvailablePanel();
    setTimeout(function () {
        var hints = texts.filter(function (t) { return t === 'FeinwerkDashboardSharing_ShareError'; }).length;
        assert(hints === 3, 'each failed list load shows the error hint: ' + hints);
        console.log('SMOKE OK: failed list loads show the error hint, no unhandled rejection');
        failing = {};

        // Goal pages (and other reports) use numeric subcategories too: goal 1 is not dashboard 1, the plugin stays idle.
        page = { category: 'Goals_Goals', subcategory: '1' };
        FS.setReadOnlyMarker(false);
        triggered.length = 0;
        var callsBefore = linkInfoCalls + syncCalls;
        FS.enterDashboard();
        setTimeout(function () {
            assert(linkInfoCalls + syncCalls === callsBefore, 'goal page 1: no getLinkInfo/sync for dashboard 1');
            assert(FS.readOnly === false && !classes['fwsh-readonly'], 'goal page 1: no read-only marker');
            assert(triggered.length === 0, 'goal page 1: no fwsh events: ' + JSON.stringify(triggered));
            console.log('SMOKE OK: a goal page with the id of a linked dashboard is left alone');

            page = { category: 'Dashboard_Dashboard', subcategory: '1' };
            var linkBefore = linkInfoCalls;
            FS.enterDashboard();
            setTimeout(function () {
                assert(linkInfoCalls === linkBefore + 1, 'dashboard 1: getLinkInfo asked once');
                assert(FS.readOnly === true && classes['fwsh-readonly'] === true, 'dashboard 1 is still recognised as a linked copy');
                console.log('SMOKE OK: dashboard pages unchanged');
                topBarChecks();
            }, 60);
        }, 60);
    }, 60);
}, 60);

// ---- the share buttons sit in Matomo's top bar (slot from Template.nextToCalendar), else above the widgets ----
// Records where the plugin puts things: append into the slot, before the widget area, emptying the slot.
function placementsDuring(slotPresent, run, areaMissing) {
    var placed = [];
    var origQ = window.$;
    window.$ = function (arg) {
        var o = origQ(arg);
        if ((arg === '#dashboardWidgetsArea' && !areaMissing) || (arg === '#fwsh-topcontrol-slot' && slotPresent)) {
            Object.defineProperty(o, 'length', { value: 1 });
            o.before = function () { placed.push('before ' + arg); return o; };
            o.append = function () { placed.push('append ' + arg); return o; };
            o.empty = function () { placed.push('empty ' + arg); return o; };
        }
        return o;
    };
    window.$.extend = origQ.extend;
    try { run(); } finally { window.$ = origQ; }
    return placed;
}

function topBarChecks() {
    assert(typeof FS.injectShareControls === 'function', 'injectShareControls published');

    // a dashboard of the user's own (3): once getLinkInfo has answered, the buttons go into the top bar slot
    page = { category: 'Dashboard_Dashboard', subcategory: '3' };
    FS.setReadOnlyMarker(false);
    FS.readOnly = false;
    FS.enterDashboard();
    setTimeout(function () {
        var inBar = placementsDuring(true, FS.injectShareControls);
        assert(inBar.length === 2 && inBar.every(function (p) { return p === 'append #fwsh-topcontrol-slot'; }),
            'share and import button go into the top bar slot: ' + JSON.stringify(inBar));
        var noBar = placementsDuring(false, FS.injectShareControls);
        assert(JSON.stringify(noBar) === '["before #dashboardWidgetsArea"]', 'without the slot the row goes above the widgets: ' + JSON.stringify(noBar));
        console.log('SMOKE OK: share buttons in the top bar slot, fallback above the widgets');

        FS.readOnly = true;
        assert(placementsDuring(true, FS.injectShareControls).length === 0, 'no share buttons on a read-only copy');
        FS.readOnly = false;
        page = { category: 'General_Visitors', subcategory: 'General_Overview' };
        assert(placementsDuring(true, FS.injectShareControls).length === 0, 'no share buttons on a report page');
        assert(placementsDuring(true, function () { handlers['widget:create']({}, { uniqueId: 'w1' }); }).length === 0,
            'a widget rendered while a report page is open adds no share buttons');
        console.log('SMOKE OK: no share buttons on a read-only copy or a report page');

        // leaving the dashboard for a report page empties the slot and puts nothing back
        page = { category: 'Dashboard_Dashboard', subcategory: '3' };
        placementsDuring(true, function () { handlers.hashchange(); }); // context: dashboard 3
        page = { category: 'General_Visitors', subcategory: 'General_Overview' };
        var leaving = placementsDuring(true, function () { handlers.hashchange(); });
        assert(leaving.indexOf('empty #fwsh-topcontrol-slot') !== -1, 'leaving the dashboard empties the slot: ' + JSON.stringify(leaving));
        assert(leaving.indexOf('append #fwsh-topcontrol-slot') === -1, 'leaving the dashboard adds no buttons: ' + JSON.stringify(leaving));
        console.log('SMOKE OK: leaving the dashboard removes the share buttons');

        // switching to a synced copy (2): widgets that render before getLinkInfo has answered add no buttons, so they
        // never flash up in the bar of a read-only copy
        page = { category: 'Dashboard_Dashboard', subcategory: '2' };
        var early = placementsDuring(true, function () {
            handlers.hashchange();
            handlers['widget:create']({}, { uniqueId: 'w2' });
        });
        assert(early.indexOf('append #fwsh-topcontrol-slot') === -1, 'no buttons before getLinkInfo has answered: ' + JSON.stringify(early));
        setTimeout(function () {
            assert(FS.readOnly === true, 'dashboard 2 is a synced copy');
            var after = placementsDuring(true, function () { handlers['widget:create']({}, { uniqueId: 'w3' }); });
            assert(after.indexOf('append #fwsh-topcontrol-slot') === -1, 'no buttons on the copy after the answer either: ' + JSON.stringify(after));
            console.log('SMOKE OK: no buttons flash up on a synced copy');
            lateAnswerCheck();
        }, 60);
    }, 60);
}

// a late getLinkInfo answer for a dashboard the user has already left changes nothing
function lateAnswerCheck() {
    FS.setReadOnlyMarker(false);
    FS.readOnly = false;
    triggered.length = 0;
    page = { category: 'Dashboard_Dashboard', subcategory: '2' };
    FS.enterDashboard(); // asks getLinkInfo for dashboard 2 (the stub answers: a linked copy)
    page = { category: 'General_Visitors', subcategory: 'General_Overview' }; // ...but the user moved on
    setTimeout(function () {
        assert(FS.readOnly === false && !classes['fwsh-readonly'], 'late answer: no read-only marker');
        assert(triggered.length === 0, 'late answer: no events: ' + JSON.stringify(triggered));
        console.log('SMOKE OK: a late answer for a dashboard the user has left is ignored');
        lostAnswerChecks();
    }, 60);
}

function buttonsPlaced(placed) {
    return placed.filter(function (p) { return p === 'append #fwsh-topcontrol-slot'; }).length;
}

// Matomo 5.14 and 6 abort every queued request on a period, date or segment change; Matomo 5.0 cannot be told otherwise,
// and its ajaxHelper then neither resolves nor rejects. The buttons must still come.
function lostAnswerChecks() {
    FS.setReadOnlyMarker(false);
    FS.readOnly = false;
    page = { category: 'Dashboard_Dashboard', subcategory: '3' };
    FS.enterDashboard();
    assert(lastAbortable.getLinkInfo === false, 'getLinkInfo is sent as not abortable: ' + lastAbortable.getLinkInfo);

    linkInfoMode = 'hang';
    lostLinkInfo.length = 0;
    page = { category: 'Dashboard_Dashboard', subcategory: '4' };
    var before = linkInfoCalls;
    placementsDuring(true, function () { handlers.hashchange(); }); // dashboard 4: its getLinkInfo hangs
    assert(linkInfoCalls === before + 1 && lostLinkInfo.length === 1, 'dashboard 4 asked once: ' + (linkInfoCalls - before) + '/' + lostLinkInfo.length);
    linkInfoMode = 'answer';
    lostLinkInfo[0]({ statusText: 'abort', status: 0 }); // Matomo 5.0 aborted it
    assert(linkInfoCalls === before + 1, 'not asked again inside the abort pass');
    setTimeout(function () {
        assert(linkInfoCalls === before + 2, 'an aborted getLinkInfo is asked again: ' + (linkInfoCalls - before));
        setTimeout(function () {
            assert(buttonsPlaced(placementsDuring(true, FS.injectShareControls)) === 2, 'buttons after the repeated answer');
            console.log('SMOKE OK: an aborted getLinkInfo is asked again after the abort pass, the buttons come');
            lostTwiceChecks();
        }, 60);
    }, 60);
}

// lost on every try (e.g. no network): after two more tries, as for any other failure, the dashboard counts as the user's own
function lostTwiceChecks() {
    linkInfoMode = 'hang';
    lostLinkInfo.length = 0;
    page = { category: 'Dashboard_Dashboard', subcategory: '6' };
    placementsDuring(true, function () { handlers.hashchange(); });
    lostLinkInfo[0]({ statusText: 'error', status: 0 });
    setTimeout(function () {
        assert(lostLinkInfo.length === 2, 'asked a second time: ' + lostLinkInfo.length);
        lostLinkInfo[1]({ statusText: 'abort', status: 0 });
        setTimeout(function () {
            assert(lostLinkInfo.length === 3, 'asked a third time: ' + lostLinkInfo.length);
            linkInfoMode = 'answer';
            var lost = placementsDuring(true, function () { lostLinkInfo[2]({ statusText: 'error', status: 0 }); });
            assert(buttonsPlaced(lost) === 2, 'lost three times: the buttons come anyway: ' + JSON.stringify(lost));
            var callsAfter = linkInfoCalls;
            lostLinkInfo.forEach(function (fn) { fn({ statusText: 'error', status: 0 }); });
            setTimeout(function () {
                assert(linkInfoCalls === callsAfter, 'no further request: ' + (linkInfoCalls - callsAfter));
                console.log('SMOKE OK: a getLinkInfo lost on every try shows the buttons, no endless retries');
                linkedRetryChecks();
            }, 60);
        }, 60);
    }, 60);
}

// lost once on a synced copy (2), the repeated request answers "linked": read-only, no buttons; and a lost request of an
// earlier visit no longer counts once the copy is confirmed
function linkedRetryChecks() {
    FS.setReadOnlyMarker(false);
    FS.readOnly = false;
    linkInfoMode = 'hang';
    lostLinkInfo.length = 0;
    page = { category: 'Dashboard_Dashboard', subcategory: '2' };
    placementsDuring(true, function () { handlers.hashchange(); });
    linkInfoMode = 'answer';
    lostLinkInfo[0]({ statusText: 'abort', status: 0 });
    setTimeout(function () {
        setTimeout(function () {
            assert(FS.readOnly === true && classes['fwsh-readonly'] === true, 'the repeated answer marks the copy read-only');
            var callsAfter = linkInfoCalls;
            var late = placementsDuring(true, function () { lostLinkInfo[0]({ statusText: 'abort', status: 0 }); });
            setTimeout(function () {
                assert(linkInfoCalls === callsAfter, 'a lost request of an earlier round asks nothing: ' + (linkInfoCalls - callsAfter));
                assert(buttonsPlaced(late) === 0 && FS.readOnly === true, 'the confirmed copy stays read-only, no buttons');
                console.log('SMOKE OK: a copy whose first answer was lost stays read-only, earlier rounds are ignored');
                failedLinkInfoChecks();
            }, 60);
        }, 60);
    }, 60);
}

function failedLinkInfoChecks() {
    // a failed getLinkInfo (HTTP error) counts as the user's own dashboard
    failing = { getLinkInfo: true };
    page = { category: 'Dashboard_Dashboard', subcategory: '5' };
    placementsDuring(true, function () { handlers.hashchange(); });
    setTimeout(function () {
        failing = {};
        assert(buttonsPlaced(placementsDuring(true, FS.injectShareControls)) === 2, 'a failed getLinkInfo still shows the buttons');
        // an empty dashboard has no widget area yet: the buttons in the top bar need only the slot
        var noArea = placementsDuring(true, FS.injectShareControls, true);
        assert(buttonsPlaced(noArea) === 2, 'top bar buttons without the widget area: ' + JSON.stringify(noArea));
        assert(placementsDuring(false, FS.injectShareControls, true).length === 0, 'no slot and no widget area: nothing');
        console.log('SMOKE OK: failed getLinkInfo and an empty dashboard still get the buttons');
    }, 60);
}
