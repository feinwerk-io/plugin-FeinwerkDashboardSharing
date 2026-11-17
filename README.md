# Feinwerk Dashboard Sharing

Share a dashboard with your team. Colleagues subscribe to a read-only copy that updates itself whenever you change the
original.

## Description

**Build a dashboard once and share it with your team: everyone sees your current version, nobody can change it.**

Dashboards in Matomo are personal. If you have built a dashboard that your colleagues should also see, you would
normally have to recreate it for each person. Feinwerk Dashboard Sharing lets you share it instead:

- **Share with specific users or your whole team:** pick the Matomo users who may subscribe, or offer the dashboard to
  all users who can see one of your websites.
- **Always up to date:** a subscriber gets their own copy of the dashboard. When you change your dashboard, the copy is
  updated the next time the subscriber opens it.
- **Read-only for recipients:** subscribers can look at the shared dashboard, but cannot change the layout or the
  widgets of the copy. Your original stays under your control.
- **Stop sharing at any time:** withdrawing a share removes it from the list of available dashboards.
- **Only within your websites:** a shared dashboard is offered only to users who can see at least one of your websites
  (super users see every share). Recipients see the reports with their own permissions, so only for websites they can
  access in Matomo.
- **Light and dark mode, English and German.**

The plugin never modifies Matomo core or the bundled Dashboard plugin and keeps shares and subscriptions in tables of
its own, so it survives Matomo updates.

**Works with Feinwerk Dashboard Segment (free):** shared dashboards keep the segment and time range of each widget.

**Get started:** open a dashboard and click *Share this dashboard*. Your colleagues find it under *Import a shared
dashboard*.

**More Plugins for Matomo by Feinwerk:** install [Feinwerk Connector](https://plugins.matomo.org/FeinwerkMarketplaceConnector)
from the Matomo Marketplace to discover, install and update all Feinwerk plugins, free and paid, right in your Matomo.
Documentation: [plugins.feinwerk.io](https://plugins.feinwerk.io/en/plugins/FeinwerkDashboardSharing).

## Works with Feinwerk Dashboard Segment

If [Feinwerk Dashboard Segment](https://plugins.feinwerk.io/en/plugins/FeinwerkDashboardSegment) is installed as well,
shared dashboards also carry the per-widget segments and per-widget time ranges you have set. Recipients then see the
same segmented view as you. This is optional: Feinwerk Dashboard Sharing works on its own, and it has no hard dependency
on Feinwerk Dashboard Segment.

## Privacy features

- **No visitor data:** the plugin stores only the shares themselves: the name of a share, the dashboard it comes from,
  the login of its owner, the logins it is offered to and the logins of its subscribers. It reads no tracking data and
  adds nothing to the tracker.
- **Nothing leaves your Matomo:** the plugin sends no data anywhere and loads nothing from outside.
- **Your permissions stay in force:** a share is offered only to users who can see at least one of the owner's
  websites, and every recipient sees the numbers with their own Matomo permissions.
- **Clean removal:** when a Matomo user is deleted, their shares, offers and subscriptions are deleted as well;
  uninstalling the plugin removes its tables.

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

## Known limitations

- Subscribers see the owner's changes when they reopen the dashboard, not live while it is open.
- A subscriber cannot edit a synced copy. To change it, the owner edits the original.

## License

GPL v3 or later. See the `LICENSE` file.

## Author

Peter Boehlke, [Feinwerk.io](https://feinwerk.io) · support@feinwerk.io
