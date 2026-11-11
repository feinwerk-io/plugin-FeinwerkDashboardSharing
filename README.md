# Feinwerk Dashboard Sharing

Share a Matomo dashboard with your team. Recipients get an automatically
synchronised, read-only copy that always reflects your current version.

## Description

Dashboards in Matomo are personal. If you have built a dashboard that your colleagues should also
see, you would normally have to recreate it for each person.

Feinwerk Dashboard Sharing lets you share one of your dashboards:

- **Share with specific users or your whole team:** pick the Matomo users who may subscribe, or offer
  the dashboard to all users who can see one of your websites.
- **Auto-synced mirror:** a subscriber gets their own copy of the dashboard. When you change your
  dashboard, the copy is updated the next time the subscriber opens it.
- **Read-only for recipients:** subscribers can look at the shared dashboard, but cannot change the
  layout or the widgets of the copy. Your original stays under your control.
- **Stop sharing at any time:** withdrawing a share removes it from the list of available dashboards.
- **Only within your websites:** a shared dashboard is offered only to users who can see at least one
  of your websites (super users see every share). Recipients see the reports with their own
  permissions, so only for websites they can access in Matomo.

Documentation and more Plugins for Matomo: [Feinwerk Plugins](https://plugins.feinwerk.io/en/plugins/FeinwerkDashboardSharing).

## How it works (no core patches)

The plugin is purely additive. It never modifies Matomo core or the bundled Dashboard plugin, so it
survives Matomo updates.

## Works with Feinwerk Dashboard Segment

If [Feinwerk Dashboard Segment](https://plugins.feinwerk.io/en/plugins/FeinwerkDashboardSegment) is
installed as well, shared dashboards also carry the per-widget segments and per-widget time ranges
you have set. Recipients then see the same segmented view as you.

This is optional. Feinwerk Dashboard Sharing works on its own, and it has no hard dependency on
Feinwerk Dashboard Segment.

## Requirements

- Matomo 5
- Matomo 5: version 5.x, Matomo 6: version 6.x
- PHP 8.1 or newer
- The bundled **Dashboard** plugin (active by default).

## Installation

1. Copy this folder to `plugins/FeinwerkDashboardSharing` in your Matomo installation.
2. Activate it: **Administration → Plugins → `FeinwerkDashboardSharing` → Activate**, or on the CLI:
   ```
   ./console plugin:activate FeinwerkDashboardSharing
   ./console core:clear-caches
   ```
   Activation creates the tables the plugin needs.

## Usage

**Share a dashboard**

1. Open the dashboard you want to share.
2. Click **Share this dashboard**.
3. Enter a name for recipients (up to 100 characters) and choose who may subscribe: **All users who
   can see one of your websites**, or **Specific users** with a comma-separated list of logins. Admins
   can also pick the users from a list.
4. Click **Share**.

**Import a shared dashboard**

1. Open the dashboard list and click **Import a shared dashboard**.
2. Choose a shared dashboard from the list and click **Subscribe**. The dashboard is added to your
   dashboards as a read-only copy, marked as synced from its owner.
3. To remove it again, click **Unsubscribe**.

Under **My shares** you can see your shared dashboards, how many subscribers they have, and stop
sharing them.

### Feedback and support

At the end of the share dialog you can tell us whether dashboard sharing is helpful for you (thumbs up or down). Your
Matomo server sends the answer to Feinwerk together with the plugin and Matomo version, the browser family and version
and the language of Matomo, nothing about you, your website or your visitors. To switch it off, add to
`config/config.ini.php`:

    [FeinwerkDashboardSharing]
    feedback = 0

With `enable_internet_features = 0` in Matomo's `[General]` section nothing is sent either, and the question is not
shown. "Support" opens the Feinwerk contact form in a new tab.

## Tips from Feinwerk

The plugin shows short tips on other Feinwerk offers, for example Feinwerk Campaigns or Matomo hosting. Only users with
admin access to at least one website and super users see them, and only in Matomo's interface: never in exports, API
results, email reports, embedded widgets or on the dashboards themselves, which subscribers see as well. You see at most
one tip per place and page:

- one line at the end of the share dialog,
- one banner on the start page of Matomo's administration,
- one box at the end of the Campaigns, Ecommerce overview and Goals overview reports.

A tip for a Feinwerk plugin you already have installed is not shown. "×" hides a tip for 30 days in your browser. The
tips are part of the plugin: nothing is loaded from outside and nothing is sent. Links open feinwerk.io pages in a new
tab and carry only the campaign parameters `mtm_campaign`, `mtm_source` and `mtm_content`.

To switch the tips off for this plugin, add to `config/config.ini.php`:

    [FeinwerkDashboardSharing]
    promotions = 0

The same line in the section `[FeinwerkMarketplaceConnector]` switches them off for all Feinwerk plugins. Recent
versions of the Feinwerk Marketplace Connector also have the setting "Show notes on other Feinwerk offers".

## Known limitations

- Subscribers see the owner's changes when they reopen the dashboard, not live while it is open.
- A subscriber cannot edit a synced copy. To change it, the owner edits the original.

## License

GPL v3 or later. See the `LICENSE` file.

## Author

Peter Boehlke, [Feinwerk.io](https://feinwerk.io) · support@feinwerk.io
