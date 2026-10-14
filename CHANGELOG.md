# Changelog

## 1.0.2

- Subscribed dashboards are now marked in the dashboard menu: a people icon, and the owner's name ("Synced from
  …") when you point at it.
- Removing a dashboard with Matomo's own "Remove dashboard" now also ends its subscription or its shares. Before,
  a dashboard you created afterwards could be taken for the removed one.
- Clearer hint in the share dialog: everyone can enter logins; only admins also get a list of users to pick from.
- Easier to read labels in the share dialog, in light and dark mode.
- Requires PHP 8.1 or newer.

## 1.0.1

This version is for Matomo 5. Matomo 6 support comes with the next major version of the plugin.

## 1.0.0

Initial standalone release. Dashboard sharing, extracted from FeinwerkDashboardSegment: share a
dashboard with specific users or everyone; recipients get an auto-synced, read-only copy.
Optionally carries per-widget segments when FeinwerkDashboardSegment is installed.
