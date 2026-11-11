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
function jq() {
    var o = function () { return o; };
    var methods = ['find', 'first', 'each', 'on', 'one', 'attr', 'append', 'prepend', 'after',
        'before', 'next', 'remove', 'text', 'hide', 'show', 'empty', 'css', 'dashboard', 'data',
        'map', 'filter', 'val', 'prop', 'is'];
    methods.forEach(function (m) { o[m] = function () { return o; }; });
    o.length = 0;
    o.attr = function () { return ''; };
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
// linked mirror dashboard: getLinkInfo returns {linked:true}; everything else resolves with []
var linkInfoCalls = 0;
var syncCalls = 0;
var page = { category: 'Dashboard_Dashboard', subcategory: '1' }; // the URL hash parameters of the current page
window.broadcast = {
    getValueFromHash: function (name) { return page[name] || ''; }, getValueFromUrl: function (name) { return page[name] || ''; }
};
window.piwik = { idSite: 1, token_auth: 't', userLogin: 'tester', on: function () {} };
window.ajaxHelper = function () {
    var action = null;
    return {
        addParams: function (p) { if (p && p.action) { action = p.action; } },
        withTokenInUrl: function () {}, setFormat: function () {},
        send: function () {
            if (failing[action]) { return Promise.reject({ status: 400 }); }
            if (action === 'getLinkInfo') { linkInfoCalls++; return Promise.resolve({ linked: true, owner: 'bob' }); }
            if (action === 'syncLinkedDashboard') { syncCalls++; return Promise.resolve({ synced: true }); }
            return Promise.resolve([]);
        }
    };
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
            }, 60);
        }, 60);
    }, 60);
}, 60);
