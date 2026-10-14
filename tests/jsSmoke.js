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
    return o;
}
var $ = function (arg) { if (typeof arg === 'function') { arg(); return; } return jq(); };
$.extend = Object.assign;
global.window = {};
window.console = console;
window.$ = $; window.jQuery = $;
// linked mirror dashboard: getLinkInfo returns {linked:true}; everything else resolves with []
var linkInfoCalls = 0;
window.broadcast = {
    getValueFromHash: function () { return '1'; }, getValueFromUrl: function () { return '1'; }
};
window.piwik = { idSite: 1, token_auth: 't', userLogin: 'tester', on: function () {} };
window.ajaxHelper = function () {
    var action = null;
    return {
        addParams: function (p) { if (p && p.action) { action = p.action; } },
        withTokenInUrl: function () {}, setFormat: function () {},
        send: function () {
            if (action === 'getLinkInfo') { linkInfoCalls++; return Promise.resolve({ linked: true, owner: 'bob' }); }
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

// let the bootstrap's async .then() chain (enterDashboard -> getLinkInfo -> sync) run
setTimeout(function () {
    assert(linkInfoCalls >= 1, 'bootstrap called getLinkInfo');
    assert(FS.readOnly === true && classes['fwsh-readonly'] === true, 'mirror dashboard sets the marker');
    console.log('SMOKE OK: bootstrap + async callback chain ran with no error');
}, 60);
