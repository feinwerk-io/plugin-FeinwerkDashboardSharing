# Changelog

## 6.0.0

The first stable version for Matomo 6. Compared with the last beta for Matomo 6 before the new version numbers
(2.0.0-beta.3):

- New version numbers: the plugin for Matomo 6 now carries version 6.x, the one for Matomo 5 version 5.x.
- The buttons Share dashboard and Import dashboard sit in Matomo's bar at the top, next to the website, date and segment
  selection, instead of a line of their own above the widgets. They look like the other buttons there and appear only
  while one of your own dashboards is open.
- Security: shares and subscriptions are kept strictly apart for users whose logins the database treats as the same
  (possible on some installations, for example with "ß" and "ss"). The database now also tells logins apart that differ
  only in case: the update changes the login columns of the plugin's three tables so that "Anna" and "anna" are two
  users there too. It changes no data; a share for a login typed in another case ("Bob" for bob) still reaches that user.
- The plugin's API answers errors with a fitting HTTP status instead of 200: 400 for invalid input, 404 for a dashboard
  or share that does not exist or is not offered to you. Scripts that call the API can rely on the status.
- While the free Feinwerk Connector is not active, super users find Administration › Feinwerk Plugins › Install
  Feinwerk Connector: one click installs and activates it, after Matomo's password check. With `connector_installer = 0`
  in the section `[FeinwerkMarketplaceConnector]` of `config/config.ini.php` the entry stays hidden for all Feinwerk
  plugins.
- The plugin now speaks French, Spanish and Italian as well as English and German.
- My shares shows the number of subscribers as "Subscribers: 4".
- The share dialog draws its borders in Matomo 6's border colour.
- Shorter plugin description; the demo link of the plugin preview works again.

## 2.0.0-beta.3

- Shared dashboards are now offered only to users who can see at least one of the owner's websites, also when they
  were shared with a list of logins. Super users see every share. Before, a share with all users reached every user of
  the Matomo instance, and with it the owner's login. A copy whose owner no longer has a website in common with you is
  no longer updated; you keep its last version.
- The option for sharing with everyone now says who that is: all users who can see one of your websites.
- A subscribed copy no longer takes over widget settings that would change the website, period or segment of a widget,
  or the account it is loaded with. Matomo already refused data of websites the subscriber cannot access; now such
  settings are not copied at all. The metrics picked for a graph and, with Feinwerk Dashboard Segment, the segments and
  time ranges of single widgets are still carried over.
- Share names are limited to 100 characters, the length Matomo allows for dashboard names. Before, subscribing to a
  share with a longer name could fail.
- A share name entered through the HTTP API is stored exactly as entered, like a name entered in the share dialog.
- When the plugin is activated, it removes the shares and subscriptions of users who were deleted while it was
  inactive, so a new user with the same login does not get them.

## 2.0.0-beta.2

- Goal pages and other reports are no longer mistaken for a subscribed dashboard with the same number. Before, opening
  such a page could update your copy of the shared dashboard in the background.

## 2.0.0-beta.1

- Works with Matomo 6. For Matomo 5, version 1.x stays available and is maintained in parallel.
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

Initial standalone release. Dashboard sharing, extracted from Feinwerk Dashboard Segment: share a
dashboard with specific users or everyone; recipients get an auto-synced, read-only copy.
Optionally carries per-widget segments when Feinwerk Dashboard Segment is installed.
