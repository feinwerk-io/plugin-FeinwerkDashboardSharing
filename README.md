# FeinwerkDashboardSharing

Share a Matomo dashboard with your team or with everyone. Recipients get an automatically
synchronised, read-only copy that always reflects your current version.

## Description

Dashboards in Matomo are personal. If you have built a dashboard that your colleagues should also
see, you would normally have to recreate it for each person.

FeinwerkDashboardSharing lets you share one of your dashboards:

- **Share with specific users or everyone:** pick the Matomo users who may subscribe, or open the
  dashboard to all users.
- **Auto-synced mirror:** a subscriber gets their own copy of the dashboard. When you change your
  dashboard, the copy is updated the next time the subscriber opens it.
- **Read-only for recipients:** subscribers can look at the shared dashboard, but cannot change the
  layout or the widgets of the copy. Your original stays under your control.
- **Stop sharing at any time:** withdrawing a share removes it from the list of available dashboards.

Documentation and more Plugins for Matomo: [Feinwerk Plugins](https://plugins.feinwerk.io/en/plugins/FeinwerkDashboardSharing).

## How it works (no core patches)

The plugin is purely additive. It never modifies Matomo core or the bundled Dashboard plugin, so it
survives Matomo updates.

## Works with FeinwerkDashboardSegment

If [FeinwerkDashboardSegment](https://plugins.feinwerk.io/en/plugins/FeinwerkDashboardSegment) is
installed as well, shared dashboards also carry the per-widget segments and per-widget time ranges
you have set. Recipients then see the same segmented view as you.

This is optional. FeinwerkDashboardSharing works on its own, and it has no hard dependency on
FeinwerkDashboardSegment.

## Requirements

- Matomo 5.x
- PHP 8.1 or newer
- The bundled **Dashboard** plugin (active by default).

## Installation

1. Copy this folder to `plugins/FeinwerkDashboardSharing` in your Matomo installation.
2. Activate it: **Administration → Plugins → FeinwerkDashboardSharing → Activate**, or on the CLI:
   ```
   ./console plugin:activate FeinwerkDashboardSharing
   ./console core:clear-caches
   ```
   Activation creates the tables the plugin needs.

## Usage

**Share a dashboard**

1. Open the dashboard you want to share.
2. Click **Share this dashboard**.
3. Enter a name for recipients and choose who may subscribe: **All users**, or **Specific users**
   with a comma-separated list of logins. Selecting specific users requires Matomo admin access.
4. Click **Share**.

**Import a shared dashboard**

1. Open the dashboard list and click **Import a shared dashboard**.
2. Choose a shared dashboard from the list and click **Subscribe**. The dashboard is added to your
   dashboards as a read-only copy, marked as synced from its owner.
3. To remove it again, click **Unsubscribe**.

Under **My shares** you can see your shared dashboards, how many subscribers they have, and stop
sharing them.

## Known limitations

- Subscribers see the owner's changes when they reopen the dashboard, not live while it is open.
- A subscriber cannot edit a synced copy. To change it, the owner edits the original.

## License

GPL v3 or later. See the `LICENSE` file.

## Author

Peter Boehlke, [Feinwerk.io](https://feinwerk.io) · support@feinwerk.io
