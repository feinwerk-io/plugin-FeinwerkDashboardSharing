# Changelog

## 1.0.4

- Shared dashboards are now offered only to users who can see at least one of the owner's websites, also when they
  were shared with a list of logins. Super users see every share. Before, a share with all users reached every user of
  the Matomo instance, and with it the owner's login. A copy whose owner no longer has a website in common with you is
  no longer updated; you keep its last version.
- The option for sharing with everyone now says who that is: all users who can see one of your websites.
- A subscribed copy no longer takes over widget settings that would change the website, period or segment of a widget,
  or the account it is loaded with. Matomo already refused data of websites the subscriber cannot access; now such
  settings are not copied at all. The metrics picked for a graph and, with FeinwerkDashboardSegment, the segments and
  time ranges of single widgets are still carried over.
- Share names are limited to 100 characters, the length Matomo allows for dashboard names. Before, subscribing to a
  share with a longer name could fail.
- A share name entered through the HTTP API is stored exactly as entered, like a name entered in the share dialog.
- When the plugin is activated, it removes the shares and subscriptions of users who were deleted while it was
  inactive, so a new user with the same login does not get them.

## 1.0.3

- Subscribing to a shared dashboard no longer replaces your own main dashboard if you had never changed it. The shared
  dashboard is added next to it.
- After "Unsubscribe" your main dashboard opens right away, without an error message in the background.
- If the list of shares or users cannot be loaded, the share dialog and the import list now say so instead of staying
  empty.
- Spaces before or after the share name and the user logins are no longer stored.
- The "Share" button keeps its colour when you point at it.
- If an earlier version had put a shared dashboard in the place of your main dashboard, "Unsubscribe" now gives you your
  main dashboard back.
- The "Share" button is easier to read in dark mode.
- Goal pages and other reports are no longer mistaken for a subscribed dashboard with the same number. Before, opening
  such a page could update your copy of the shared dashboard in the background.
- Together with FeinwerkDashboardSegment 0.7.1 or later, the owner's changed segments and time ranges of a widget show on
  the first opening of a subscribed dashboard.

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
