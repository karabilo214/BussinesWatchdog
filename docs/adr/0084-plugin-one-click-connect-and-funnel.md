# ADR 0084: Connecting the WooCommerce plugin in one click, and the plugin connection funnel

Date: 2026-10-28

Status: accepted (owner's request 2026-10-28, backlog 11.H and 11.I; implemented in Step 305, plugin 0.4.2)

## Context

A merchant who installs the plugin from WordPress.org does not know that a Business Watchdog account is needed (the owner tried it as «a simple customer»). Plugin 0.4.1 added links to the sign-up, but the merchant still has to create a store in the account, create a connection code, copy it and paste it into WordPress. The owner wants a visible card with buttons (like WordPress.com «Finish connecting») and a connection without copying a code. The owner also wants to see how many of the plugin's installs turn into connected stores.

## Decisions

### Card on the plugins screen

1. While the plugin is not connected and not stopped, the plugins screen shows a card instead of the text notice: «Connect Business Watchdog», one line why an account is needed, the main button «Connect to Business Watchdog», a second button «Create a free account» and a link «I have a connection code». Only users with `manage_woocommerce` see it.
2. WordPress.org guideline 11: only the plugins screen and the plugin's own page; no advertising; never after connecting. The card on the plugins screen can be dismissed; the choice is kept per WordPress user. The plugin page always shows the connection block while not connected.

### One-click connection

3. Both buttons start the same flow; «Create a free account» only opens the sign-up first instead of the login.
4. The plugin (POST with a nonce and `manage_woocommerce`) creates a random `state` (32 bytes) and a `verifier` (32 bytes). It keeps the hash of the state, the sealed verifier and the service address in the WordPress user's meta for 30 minutes. It then opens `<service>/connect/woocommerce` with the site address (`home_url`), the return address (`admin-post.php?action=business_watchdog_connect_return` of the same site), the state and `challenge = base64url(sha256(verifier))`. The verifier never leaves WordPress before the final exchange.
5. The service checks the parameters and refuses anything else:
   - the site is a public `https` address;
   - the return address has the same scheme, host and port as the site, the path ends with `/admin-post.php` and the query is exactly `action=business_watchdog_connect_return`;
   - the state and challenge have the expected format.

   The request is kept in the session for 30 minutes, never in the database. Then the service opens the sign-up, the login or the connection page of the account.
6. In the account the merchant sees the site address and chooses one of their stores with exactly this address, or creates the store with the address already filled in (WooCommerce, the usual form). Connecting needs the `integrations.manage` permission and the usual CSRF protection of the account.
7. The service refuses with a clear text and offers the manual way with a code when:
   - the store has another address (`plugin_connect_site_mismatch`);
   - the store is a Shopware store;
   - the store already reports orders through the WooCommerce REST API or Shopware: one order source per store (ADR 0024, `order_source_already_connected`).
8. On «Connect», the service creates a one-time connection code that is bound to the challenge and valid for 10 minutes, writes the audit entry `integration.connect_approved` (store, site host; never the code) and sends the browser back to the return address with the state and the code.
   - The return is a plain top-level GET: WordPress login cookies are `SameSite=Lax` in current browsers and are not sent with a cross-site POST, so a POST would land on the login page.
9. The plugin checks `manage_woocommerce`, the state against the stored hash and its expiry, and deletes the request (single use). It exchanges the code with the verifier server to server (`POST /api/v1/pairing/exchange` with `code_verifier`) and opens the plugin page without the code in the address.
   - The exchange refuses a code bound to a challenge without the matching verifier (`pairing_verifier_mismatch`, counted as an attempt).
   - The existing checks stay: the site address must equal the store address, single use, attempt limit, expiry.
10. The code appears in the browser history and in the shop's web server log, but only after it was used. It is single use, lives 10 minutes and is useless without the verifier, which is never in a URL.
11. The manual way with a code stays unchanged; a code created in the account has no challenge.

### Funnel

12. Five stages, each counted at most once:

    | Stage | Recorded when | Counted once per |
    |---|---|---|
    | `click` | a merchant opens a plugin link (`/go/wordpress-plugin`) or starts the one-click flow (`/connect/woocommerce`) | journey |
    | `signup_view` | the sign-up form is shown in a session with a plugin journey | journey |
    | `signup` | an account was created in such a session | organization |
    | `code` | a connection code was created: by hand in the account, or by «Connect» in the one-click flow | organization and store |
    | `connected` | the plugin exchanged a code (the server confirms it) | organization and store |

13. A journey is a random id created at the click and kept in the server session only. It links the stages: the session links the click, the form and the account; after that the organization's sign-up row is the link.
    - No cookie of its own, no IP address, no user agent, no full URL, no query parameters except the allowed ones.
    - Allowed values: placement (`plugins_card`, `plugins_list`, `plugin_page`, `plugin_description`, `wordpress_org`), path (`onboarding`, `connect`), language.
    - Never: customer data, order contents, passwords, keys, secrets, connection codes.
14. The plugin's static links (the description in the plugins list and the WordPress.org readme) open `/go/wordpress-plugin?placement=…&lang=…`. That address records the click and redirects to `/{lang}/signup?utm_source=wordpress_plugin&utm_medium=onboarding&utm_content=<placement>`. The plugin itself sends nothing in the background (WordPress.org guideline 7): every event is a page that the merchant opened.
15. `code` and `connected` are recorded for every plugin connection. They are attributed to the organization's plugin journey when there is one; the report shows attributed stages and the total of plugin connections separately.
16. Rows are kept for 13 months, then removed by `plugin-funnel:prune` (daily). Deleting a store or an organization deletes its rows too (ADR 0069), so the report counts only what still exists.
17. The report is in the owner panel (Business → «Plugin funnel»): counts and conversion between the stages for a period, by placement. It needs `billing.read`, like the business overview.

## Consequences

- A merchant can go from the plugins screen to a connected store without copying anything; the manual way stays for other cases (another browser, a staging site with another address).
- Clicks and form views are counted per journey, so they can be inflated by link checkers or prefetching. The stages after them are confirmed by the server.
- Plugin 0.4.2 is needed for the card and the one-click flow; older plugins keep connecting by code.
