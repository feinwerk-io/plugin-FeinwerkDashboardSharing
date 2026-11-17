<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

/**
 * Exact login comparison after a database lookup. The plugin's tables compare logins with their collation, which
 * treats case and some letters as equal ("Anna" = "anna", and depending on the collation "ß" = "ss"). Matomo's own user
 * table may use another collation than the plugin's tables (Matomo 5.0 created plugin tables with the server's default
 * collation), so a row found by login is checked again here before the plugin reads or changes it. Pure.
 */
final class LoginMatch
{
    /** A stored owner or subscriber login belongs to $login only when it is exactly the same string. */
    public static function isSame(string $stored, string $login): bool
    {
        return $stored === $login;
    }

    /**
     * A share target typed by its owner belongs to $login when it is exactly that login, or when Matomo's own user
     * lookup resolves it to that login (so "Anna" typed for the user "anna" keeps working).
     *
     * @param callable(string): string $canonicalLogin the stored login of the Matomo user a login resolves to, '' if none
     */
    public static function targetIs(string $target, string $login, callable $canonicalLogin): bool
    {
        if ($target === $login) {
            return true;
        }

        return $login !== '' && $canonicalLogin($target) === $login;
    }

    /**
     * A row found by the login of a deleted user (owner, target or subscriber) goes with that user when its login is
     * exactly the login the deletion named or the user's stored login, or when Matomo resolves it to no user or to the
     * deleted user. It stays only when it belongs to another existing user. Matomo names a deleted user as the caller
     * spelled it ("BOB" deletes the stored "bob") and posts the event after the user row is gone.
     *
     * @param string $storedLogin the deleted user's stored login if Matomo still knows it, else ''
     * @param callable(string): string $canonicalLogin
     */
    public static function goesWithDeletedUser(string $rowLogin, string $login, string $storedLogin, callable $canonicalLogin): bool
    {
        if ($rowLogin === $login || ($storedLogin !== '' && $rowLogin === $storedLogin)) {
            return true;
        }
        $owner = $canonicalLogin($rowLogin);

        return $owner === '' || $owner === $login || ($storedLogin !== '' && $owner === $storedLogin);
    }

    /**
     * The rows whose $column is exactly $login.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function rowsOf(array $rows, string $column, string $login): array
    {
        return array_values(array_filter($rows, static function ($row) use ($column, $login) {
            return isset($row[$column]) && self::isSame((string) $row[$column], $login);
        }));
    }
}
