# Progress: Public site

Server-rendered public site, content pages, contact form, sign-up and consent to legal documents.

### Step 111: Public Site Rendered on the Server, Content Pages and the Contact Form

Status: complete

- ADR 0039. Owner choices: HTML code with a live preview, one slug with a text per language, messages stored and announced by email, draft → publish with every published version kept; server-side rendering mandatory.
- Rendering: Laravel Blade on every request (`routes/site.php`, `SiteController`, `resources/views/site`): `/` redirects by `Accept-Language`, `/{lang}` home, `/{lang}/pricing`, `/{lang}/contact` (GET/POST in the `web` group for the session and CSRF), `/{lang}/{slug}` and `/{lang}/{slug}/{slug}`, a 404 page in the site layout. Canonical and `hreflang` only for languages with published content; header/footer menus from published titles; mobile menu with `<details>`, no JavaScript. Public pages `Cache-Control: public, max-age=60`, no cookies; the contact page `no-store`. The Laravel welcome page and its example test are gone.
- `apps/frontend/public` now only builds the site stylesheet (Tailwind + frozen palette + content classes) into `apps/backend/public/site` (not in git) with a manifest read by `SiteAssets`; vite-ssg, Vue and the prerender check were removed from it (ADR 0017 superseded for this package).
- Content: `public_pages` (system `home`, `pricing`, `contact` from the migration; ordinary pages with a slug fixed at creation, parent only a top-level ordinary page, unique path among active pages, menu and order) and `public_page_versions` (per language one draft and one published version by partial unique indexes; publishing retires the previous version, restore copies into the draft, unpublish, archive). `PageAdmin` + `PageSanitizer` (Symfony HtmlSanitizer, new dependency `symfony/html-sanitizer`): no scripts, frames, forms, handlers or `style`; links http(s)/mailto/tel/relative with `rel="noopener noreferrer"`; images https/relative. Every change audited; publish/unpublish/archive with a reason; the publication audit keeps version, previous version and content hash.
- Pricing (`PricingTable`, `MoneyFormat`): published catalog of the current Paddle mode, period/currency switches only over published prices, yearly charge with “about X per month” and a saving only against a monthly price of the same plan and currency, tax wording, trial, features and limits; minor units formatted with string arithmetic. Placeholder plans only in test mode, marked “Example plan”.
- Contact form: name, email, company, message; honeypot; 5 per hour per IP; IP not stored. `ContactInbox`: list with counts per status, card, status/note with audit, `site:contact-notify` (every minute; `WATCHDOG_SITE_CONTACT_NOTIFY` or all active owners; claim before sending, released on failure) and `site:contact-purge` (daily; closed for 365 days).
- Platform API: `GET/POST /pages`, `GET/PATCH /pages/{page}`, `POST /pages/{page}/preview`, `PUT/DELETE /pages/{page}/locales/{locale}/draft`, `POST .../publish`, `POST .../unpublish`, `POST /pages/{page}/versions/{version}/restore`, `POST /pages/{page}/archive` (capability `content.manage`, owner); `GET /contact-requests`, `GET /contact-requests/{id}` (`contacts.read`: owner, admin, support), `PATCH /contact-requests/{id}` (`contacts.manage`: owner, support); OpenAPI (both copies, YAML parsed).
- Control panel: sections «Сайт» (pages with state per language, create with a slug preview) and the page editor (language tabs, title, description, HTML, a live preview of the real layout in a sandboxed frame with the stylesheet inlined, save / publish with a reason / discard / unpublish, version history with restore, menu and order, delete), «Обращения» (status tabs with counts, card with reply link, status and internal note); ru/en/de.
- Found while testing: on PostgreSQL the self-referencing foreign key of `public_pages` was created before the primary key — moved to a separate statement (SQLite did not show it); the editor preview was unstyled because the sandboxed frame could not reach `APP_URL` — the stylesheet is now inlined into the preview; the first card had an extra top margin; a page removed from the site was still shown from the browser cache for up to a minute (expected with `max-age=60`, recorded in ADR 0039 point 12).

Verification:

- `tests/Feature/Site/PublicSiteTest.php` — 8 tests (language redirect, built-in home without content, canonical/hreflang, no cookies, 404; draft → sanitized → preview → publish → second version → history → restore → discard → unpublish with audit; slugs fixed, unique, two levels, system pages, archive rules; home/contact texts from the panel; pricing with monthly/yearly, saving, extra store, trial, test notice, example plans in test mode only, live prices only in live mode; contact form storage, validation, honeypot, rate limit, notification and failure; roles and retention). `OpenApiRoutesTest` green.
- Backend SQLite: 494 passed, 4 skipped; PostgreSQL 18: 498 of 498. Frontend check: palette, customer 107, control 22 (new `tests/site.test.ts`, 5), builds, site stylesheet check.
- Live in Chromium against the real backend (`run-live.sh site`): page created and published in the panel with the preview, read on the public site as complete HTML with JavaScript off (script and handler removed, linked in the menu, mobile layout), English 404 until translated, pricing page, contact form errors then sent, message closed with a note in the panel, page deleted and 404 — PASS.

### Step 118: Sign-Up on the Site and Consent to Versioned Legal Documents

Status: complete

- ADR 0042. `public_pages.policy_type` (service_terms / privacy_notice / recurring_payment, one active page per type, ordinary pages only, cleared on archive; reserved slug `signup`); `policy_acceptances` (insert-only: user, organisation, type, page version, language, version, content hash, purpose, context, time).
- `Policies` (current version per language with fallback, required documents per purpose, verify + record), `Registration` (user, organisation, owner membership and sign-up consent in one transaction; used by the API and the site).
- Site: `SignupController` + `site/signup.blade.php` (SSR, no JavaScript needed, honeypot, existing email -> sign-in/recovery hint, outdated version -> asks again, 3 real sign-ups an hour per IP counted after field validation, signed-in visitors go to the account); header «Sign up», home and pricing link to it; contact form links the page marked as privacy notice (`WATCHDOG_SITE_PRIVACY_PATH` removed).
- API: `policy_version_ids` on register, trial, checkout and plan-change confirmation (422 `policy_acceptance_required` with current documents); `GET /billing/plans` returns `documents`. OpenAPI (both copies) updated, including `faster_checks` from Step 116.
- Owner panel: «Legal document» in page settings, badge in the pages list. Cabinet: consent checkbox with links on the billing page and in the plan-change panel; trial, buy and confirm disabled until ticked; documents reloaded after a refusal.
- Found while working: a guest calling the API without `Accept: application/json` got a 500 (`Route [login] not defined`) - the API now answers 401, other pages redirect guests to `/app/login`; the live script waited for `/owner/site` with a pattern that also matched the login page's `?redirect=` (fixed to the path); the shared sign-up limit counted failed form submissions (now only real attempts).

Verification: `tests/Feature/Site/PolicyAcceptanceTest.php` (7). Control 24 tests, customer 111. Backend SQLite 525 passed, 4 skipped; PostgreSQL 18: 529 of 529. Live in Chromium (`run-live.sh signup`): two pages published and marked in the panel, sign-up page without JavaScript refused without consent, then created the account, signed in and sent to the plan; consents stored with version 1 of both documents - PASS.

### Step 121: Home Page Edited From Its Built-In Text, Pricing and Contact Only in Code

Status: complete

- Owner's backlog item (2026-10-13): system pages opened in the panel with empty fields — they had no stored versions, and their built-in text lived only in Blade, so the editor and the preview showed nothing. Owner's decision: the home page becomes editable; pricing and contact are changed only in code. ADR 0039 amended (point 14).
- Home: the built-in text is now ordinary page HTML on the content classes (`resources/views/site/builtin/home.blade.php` + `SiteDefaults`, sanitized like a saved body). The site renders it until a version is published; `GET /pages/{home}` returns it per language in `builtin`, and the editor starts from it, so the owner edits exactly what visitors see. An empty body still falls back to it (site and preview). The old `site-hero`/`site-band`/`site-grid-2`/`site-feature`/`site-eyebrow` styles were removed; `.site-content .feature` got the old feature look.
- Pricing and contact: `PublicPage::isEditable()`; `PageAdmin::editable()` refuses draft, publish, restore and preview with 409 `edited_in_code`; menu/order settings still work; discard and unpublish stay allowed for leftovers. The site ignores stored versions of these pages (text and menu label from translations). Preview no longer renders pricing/contact.
- API: `editable` in page summaries and cards, `builtin` in the card; OpenAPI descriptions and 409 responses updated.
- Owner panel: the home editor is filled from the built-in text (save enabled after a change); pricing and contact show an “edited in code” note with a link to the page and only the menu settings; the pages list marks them «Редактируется в коде»; ru/en/de.
- Found while testing: the backend suite already finished close to the default 128 MB PHP memory limit (memory grows by about 0.2 MB per test), and the new test pushed it over; `phpunit.xml` now sets `memory_limit` to 512M. The growth itself is not investigated.

Verification: `PublicSiteTest::test_home_is_edited_from_its_built_in_text_and_pricing_and_contact_only_in_code` (replaces the old system-pages test). Control 29 tests (2 new in `tests/site.test.ts`), typecheck and build; site stylesheet build and check. Backend SQLite 532 passed, 4 skipped; PostgreSQL 18: 536 of 536. Local stand: `/ru` renders the new markup; screenshots at 1280 and 390 px look like before.

### Step 122: Visual Page Editor

Status: complete

- ADR 0045 (accepted for now, to be confirmed by the owner): TipTap 3.31.4 in the owner panel instead of the HTML field; HTML mode stays as a switch ("Визуально" / "HTML-код", remembered in the browser).
- `components/editor/extensions.ts`: StarterKit without code blocks, links without auto-link and forced target/rel, images, tables, a `container` node for section/div/article/aside/header/footer/figure/nav with class and id, class/id on text blocks, lists, images and tables, `span` with a class — the content classes survive a round trip.
- `components/editor/RichEditor.vue`: toolbar (text type, text look, bold/italic/underline, lists, quote, divider, link with plain/button/secondary look, image by https with alt, table with rows/columns, into/out of a section with a section look, undo/redo); no update emitted for normalizing loaded HTML; trailing empty paragraphs are not saved.
- Site styles in the editor: the `.site-content` rules moved to `@bw/design/content.css`, imported by the site stylesheet and the panel; in visual mode the editor is full width and the preview below it.
- ru/en/de texts; the body hint no longer talks about code.

Verification: `tests/editor.test.ts` (3: the site markup of the home page with sections, grid, features, buttons, image and table survives; toolbar applies lead, heading, card section, button link, unwrap and undo; outside changes followed without reporting back). Existing site tests run in HTML mode. Frontend check: palette, customer 112, control 32, builds, site stylesheet. Backend SQLite 532 passed, 4 skipped (unchanged). Live in Chromium (`run-live.sh site`): home opens in the visual editor with its built-in text, pricing shows "edited in code", text typed in the visual editor styled as a lead paragraph in a card and shown in the preview, HTML mode, then the existing publish/site/contact flow — PASS.

### Step 123: Block Templates Inserted From the Page Editor

Status: complete

- ADR 0046 (accepted for now, to be confirmed by the owner). Migration `page_blocks` (category, name, `bodies` per language, sort, created/updated by, archived) with eight built-in templates from `database/data/page_blocks.php`: hero, three features, three steps, plan comparison without prices (links to the pricing page), FAQ, call to action, two cards, note — ru/en/de with links to the same language.
- `GET /platform-api/v1/page-blocks` (`content.manage`); OpenAPI updated.
- Visual editor: «+ Блок» opens a list of templates with their category; a click inserts the template of the page language after the top-level block with the cursor (or in place of an empty paragraph). Page editor loads templates once; without templates the button is hidden.
- Found while testing: inserting at the cursor nested a template section into the card the cursor was in — insertion moved after the top-level block; PostgreSQL `jsonb` reorders language keys (test compares them as a set).

Verification: `tests/Feature/Site/PageBlockTest.php` (2: every built-in template in three languages, kept by the sanitizer, links only to its language, no prices; owner-only). Control 35 tests (`editor.test.ts` +2, `site.test.ts` +1). Frontend check green. Backend SQLite 534 passed, 4 skipped; PostgreSQL 18: 538 of 538. Live in Chromium (`run-live.sh site`): FAQ template inserted in the visual editor, shown in the preview and in the HTML — PASS.

### Step 124: Block Templates Section in the Owner Panel

Status: complete

- ADR 0046 p. 7. `PageBlockAdmin` + `PageBlockController`: `POST /page-blocks`, `GET/PATCH /page-blocks/{block}`, `POST /page-blocks/{block}/archive` (reason) — `content.manage`; name ≤ 120, category from the list, bodies per language sanitized like a page body, null/empty removes a language, at least one stays (422 `body_required`); every change audited (`site.block.created/updated/archived`, with locales and a content hash). OpenAPI updated.
- Owner panel: new menu section «Шаблоны блоков» (`/owner/blocks`): list with group, languages, order and change time; `/owner/blocks/new` and `/owner/blocks/{id}`: name, group, order, language tabs with ✓, visual editor or HTML per language, save, delete with a reason, warning before leaving with unsaved changes. ru/en/de.

Verification: `PageBlockTest` +1 (create with sanitizing, validation, edit languages, remove a language, keep one, archive with reason, audit). Control 38 tests (`tests/blocks.test.ts`, 3). Frontend check green. Backend SQLite 535 passed, 4 skipped; PostgreSQL 18: 539 of 539. Live in Chromium (`run-live.sh site`): template created in the section with the visual editor (note style), inserted into a page from «+ Блок», deleted with a reason — PASS.

### Step 128: Replying to Contact Messages From the Owner Panel

Status: complete

- ADR 0049 (accepted for now, to be confirmed by the owner). Migration `contact_request_replies` (cascade with the message, so retention also removes replies). `ContactInbox::replyTemplates()` (answer, more_details, resolved in the message language), `letter()` (greeting, text, team signature, original message with date and zone), `reply()` (validation, mail first — nothing stored if the server refuses: 502 `reply_not_sent` — then the reply, handler, new → in progress; audit `site.contact.replied` with a content hash). Card DTO has `replies` and `reply_templates`.
- API: `POST /platform-api/v1/contact-requests/{id}/replies` (`contacts.manage`, `throttle:contact-replies` 60/h per staff member); OpenAPI updated.
- Owner panel, message card: «Ответы» with history (when, who, template, subject, text) and «Ответить»: template select fills subject and text, hint about what the letter adds and its language, «Отправить на …». ru/en/de; error texts for refused mail.
- Found while testing: `DomainOutboxResultRecorderTest::test_it_uses_exponential_backoff_with_jitter_by_default` failed once on PostgreSQL — the stored retry time is truncated to the second while the test compared with a time that had microseconds, so a jitter at the lower bound (96 s) looked too early; the test now compares from the start of the second (outbox logic unchanged).

Verification: `PublicSiteTest` +1 (templates in German, validation, letter with greeting/text/original/signature, stored with sender, status, closed stays closed, refused mail stores nothing, purge removes replies) and admin cannot reply. Control 43 tests. Frontend check green. Backend SQLite 541 passed, 4 skipped; PostgreSQL 18: 545 of 545. Live in Chromium (`run-live.sh site`): reply sent from the «Нужны подробности» template, letter found in Mailpit in Russian with the quoted message, reply shown in the card — PASS.

### Step 129: Internal Notes on Contact Messages as a List

Status: complete

- ADR 0049 p. 5–6. Migration `contact_request_notes` (cascade with the message) moves every existing `contact_requests.note` into a first note (author = handler, time = last change) and drops the column. `ContactInbox::addNote()` (1–2000 characters, audit `site.contact.noted` without text); card DTO has `notes` (newest first: text, author, time) instead of `note`; `update()` only changes the status.
- API: `POST /platform-api/v1/contact-requests/{id}/notes` (`contacts.manage`); `PATCH` requires `status` and no longer takes `note`. OpenAPI updated.
- Owner panel, message card: «Внутренние заметки» — form «Новая заметка» + «Добавить заметку», list with time and author; the status form no longer has a note field; read-only staff see the list only. ru/en/de.
- Live stand: the migration moved the earlier notes (9 entries) and dropped the column. While re-running the live script, the contact form limit (5 an hour per IP) was exhausted by repeated runs and a failed run left published test pages — cleared on the local stand; no code change.

Verification: `PublicSiteTest` (notes added one by one with author and time, empty refused, audit without text, status-only PATCH, admin cannot add). Control 43 tests. Frontend check green. Backend SQLite 541 passed, 4 skipped; PostgreSQL 18: 545 of 545. Live in Chromium (`run-live.sh site`): two notes added and listed, message closed — PASS.

### Step 138: Pricing Page — Extra Stores Field With the Recalculated Price

Status: complete

- Owner's backlog item. The pricing page has a «Дополнительных магазинов» number field with «Пересчитать» — a plain GET form (`?stores=N`, kept by the period and currency switches), so it works without JavaScript like the rest of the site (ADR 0039). `PricingTable::build()` takes the number (0–100, non-digits ignored), clamps it per plan to the plan's `max_extra_stores` limit and shows «С N доп. магазинами: total per period» = base + N × extra-store price of the same period and currency, in integer minor units formatted by `MoneyFormat`; a note says when the plan allows fewer. Plans without an extra-store price show no total; the field appears only when some plan offers extra stores. ru/en/de texts, site styles.

Verification: `PublicSiteTest::test_pricing_recalculates_the_price_for_extra_stores_without_javascript_within_the_plan_limit` (field and max, monthly total, cap with note, yearly total, invalid input). Backend SQLite 558 passed, 4 skipped; PostgreSQL 18: 562 of 562; frontend check green (site stylesheet). Local stand: `/ru/pricing?stores=2` shows 37,00 € and 63,00 € for the sandbox plans (19 + 2 × 9, 49 + 2 × 7).

### Step 139: Deleting Old Page Versions (Except Legal Documents)

Status: complete

- Owner's backlog item (added during the run): the archive of old page versions can be cleaned up. ADR 0039 p. 15. `PageAdmin::deleteVersion()` under the page lock: only `retired` versions (409 `version_not_retired` for published and drafts), never for a legal-document page or a version referenced by `policy_acceptances` (409 `version_is_legal`), audit `site.page.version_deleted` with version and content hash, reason required. `POST /platform-api/v1/pages/{page}/versions/{version}/delete`; OpenAPI updated.
- Owner panel, page history: «Удалить» next to «В черновик» for old versions of non-legal pages, with the reason confirmation showing the version number. ru/en/de, error texts.

Verification: `PublicSiteTest::test_old_page_versions_can_be_deleted_except_the_current_ones_and_legal_documents` (reason required, old version gone from history, published/draft refused, repeated 404, site unchanged, legal page and accepted version refused). Control 55 tests (+1). Frontend check green. Backend SQLite 559 passed, 4 skipped; PostgreSQL 18: 563 of 563.

- Follow-up: the owner started their own «remove» button in the page history (`data-action="remove"`, `removeHistory`, `isAllowRemove`) on an older copy of `PageView.vue`; it is finished on top of Step 139: shown only for retired versions of non-legal pages, opens the same reason confirmation and calls the version deletion; label key `site.action.remove`. Control 55 tests, backend unchanged (559 passed, 4 skipped).

### Step 145: Public Storefront Check by URL

Status: complete for the first restrictive public-diagnostic slice

- ADR 0058 accepted and implemented. Public site route `/{locale}/check-store`: a visitor enters a public HTTPS store URL and solves a mandatory local image math CAPTCHA; no account, tenant, store, integration, incident, finding or entitlement is created. The result page is `noindex` and warns that this is not monitoring, payment proof or financial coverage.
- Backend: `public_storefront_checks` and `public_storefront_check_artifacts`, keyed result tokens, normalized host/origin cooldown (one check per host/origin per 30 days), IP keyed-hash cooldown (one site per IP per 10 days), short submit-attempt limit, public HTTPS + SSRF checks (private/reserved IPs, localhost, internal/test names, metadata-style destinations), noisy PNG math CAPTCHA with a 10-minute TTL and one-time answers. Public leases are capped by `WATCHDOG_PUBLIC_CHECK_MAX_RUNNING` (default 1) and are handed out only after tenant browser checks get the first chance.
- Browser worker: leases can now be regular tenant checks or `public_storefront` checks. New adapter `public-storefront@1` opens public pages on the checked origin, tries storefront/cart/checkout links, may perform one narrowly allowlisted same-origin public add-to-cart action, refuses login/account/admin/private pages, captures public-step screenshots, and stores a cautious public platform hint (`woocommerce_hint`, `shopware_hint`, `unknown`). It never submits checkout/order/payment/account/login/register forms on unverified sites.
- Artifacts: public diagnostics use a separate object-key prefix and separate DB rows, not tenant check artifacts. Screenshots and diagnostics expire after 5 minutes; `site:public-checks-purge` runs every minute and deletes expired objects/rows.
- README documents the public-check CAPTCHA/cooldown env vars and the purge command.

Verification:

- `tests/Feature/Site/PublicStorefrontCheckTest.php` (2: form/noindex, CAPTCHA, HTTPS/public destination validation, normalized host cooldown, IP cooldown; worker lease, public running-cap, screenshot upload, result, artifact download and 5-minute purge).
- Browser worker `networkPolicy.test.js`: public storefront checks allow only public cart-add mutations on the checked origin and still block checkout/order/payment/account/login/register mutations.
- Backend SQLite full suite: 581 passed, 4 skipped. Backend site feature suite: 23 passed; OpenAPI route guard: 1 passed; worker tests: 17 passed; public frontend check passed.

Limits:

- The first slice allows only one safe public add-to-cart attempt. Deeper checks with test accounts, guest data, checkout submit, payment method selection or payment observation still require connecting/verifying the store.

### Step 149: Public Check Loads Third-Party Storefront Subrequests

Status: complete

- Owner feedback from the live public check: blocked analytics/third-party beacons were shown as site failures (`request_failed ERR_ABORTED`), which made the report look like our checker broke the page. In public storefront mode, the browser worker now allows third-party non-navigation subrequests, including POST beacons/fetches such as Google Analytics, so the page loads closer to a real customer browser.
- The public guard still refuses third-party payment/capture/refund/order-confirm endpoints and still blocks external top-level navigations. Same-origin checkout/order/account/login/register mutations remain forbidden for unverified public checks; cart-add remains allowed only through the existing public allowlist.
- The public result page keeps noisy third-party `request_failed`/`http_5xx` entries out of the customer-facing error cards while preserving first-party checkout errors and JavaScript/console errors.

Verification: browser-worker `npm test` (22 passed); backend `PublicStorefrontCheckTest` (4 passed, 153 assertions).

### Step 150: Reliable Public Cart and Checkout Evidence

Status: complete for supported public WooCommerce and Shopware storefronts

- Browser diagnostics now discard navigation-cancelled `ERR_ABORTED` and Chromium's `ERR_BLOCKED_BY_CLIENT` resource messages. First-party transport failures, HTTP 5xx, JavaScript exceptions and console warnings remain visible with a redacted source path when available. A store's allowed `www` and apex origins are both first-party. The result page also filters legacy noisy diagnostics and translates error types in ru/en/de.
- Discovery follows catalog pagination within the visit budget, recognises Shopware product cards with custom URLs, excludes product tags and listing pages from product/category counts, and carries discovered product candidates into the purchase-path step. Counts describe discovered links, not a full catalog census.
- Consent handling clicks only controls inside actual overlays, recognises German `Alles akzeptieren`, waits for late dialogs, and permits narrowly scoped Cloudflare and cookie-consent POST endpoints. Public add-to-cart is confirmed only by a product line on the cart page. Checkout is reached only when a visible customer field exists, including Shopware's GET `/checkout/confirm` to `/checkout/register` transition. The step has a shared time budget so slow sites return a result before the lease expires. Order, payment, login, registration and private form submissions stay blocked.
- Screenshot capture waits briefly for visible lazy images; live Burrow Press screenshots showed loaded product imagery and masked checkout fields. Temporary public artifacts still expire after five minutes.

Verification: browser-worker unit tests 24/24 (`npm test`); Playwright local WooCommerce/Shopware fixtures 3/3 (`docker compose run --rm --no-deps -v "$PWD/apps/browser-worker/e2e:/app/e2e:ro" browser-worker node --test e2e/publicStorefront.test.js`), including false cart badge, late cookie dialog and checkout link after 240 unrelated links; backend full SQLite suite 584 passed, 4 skipped, including `PublicStorefrontCheckTest` 4/4. Live public browser checks through the egress proxy: Burrow Press (WooCommerce, 16 product links, cart and customer-details form reached), Christopeit Sport (Shopware, 32 category and 42 product links, cart and registration form reached). No purchase or checkout form was submitted.

Remaining limit: custom headless storefronts without recognizable product cards/routes and WAF-protected sites may remain inconclusive. The public check does not validate payment methods or order submission; connected checks provide that depth.
