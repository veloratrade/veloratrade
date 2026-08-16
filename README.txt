VELORA PROTECTED ROUTE AUTH + NO-STORE FIX

Extract directly into /home/piknet/public_html/.

Replaces only locale-router.php.

This version includes both protections:
1. Server-side refresh-session validation before protected application HTML is served.
2. Cache-Control: no-store for protected pages, so browsers must not restore a
   Dashboard/Trades/Profile shell from back/forward cache after logout.

Verified locally:
- PHP syntax: PASS
- localization validator: PASS, 0 issues

Test after extraction:
1. Login.
2. Logout.
3. Use browser Back or manually open /dashboard/.
4. Expected: Login page; protected Dashboard HTML must not be restored.

No secret, .env, database, user data, backup, or log is included.
