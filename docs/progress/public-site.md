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

### Step 151: Public Product Options and Explainable Browser Messages

Status: complete for WooCommerce variation selects and observed public error classes

- The public browser check now selects a safe available value from each WooCommerce variation dropdown before clicking add-to-cart, including native selects hidden behind theme swatches. It never fills text, file, account or customer fields. If required options cannot be chosen, the product is reported as such instead of claiming a cart click failed.
- Public diagnostics group repeated browser messages, retain an exact count beyond the 100-detail-group cap, classify known causes (script exceptions, deprecated APIs, resource load, MIME mismatch, CSP, first-party CSS/JS 4xx, network and server failures), and show a localized explanation with a redacted source path. The result describes impact only from the observed path: reaching the customer form means the reported messages did not block that path; other functions remain untested.
- `CompleteAttemptRequest` and `contracts/openapi.yaml` accept only enumerated cause codes and the public count/truncation fields. Public report text is updated in ru/en/de. No schema migration.

Verification: worker unit tests 25/25; local Playwright WooCommerce/Shopware fixtures 5/5, including a hidden WooCommerce variation select and repeated console warnings; backend `PublicStorefrontCheckTest` 4/4. Live isolated egress check on Thompson+Hanson: selecting a size on `/product/46-1659/` added a line to the public cart without checkout submission; the garden category run reached the customer form. Browser resource diagnostics showed first-party script/style 403 responses and HTML returned as CSS/JS, so their wider functional impact remains unknown.

### Step 152: Public Result Explains the Next Check

Status: complete

- The result page now uses the observed cart/checkout outcome to state what was confirmed for the submitted store and what remains unknown. It explicitly separates public browser reachability from real payment outcomes and independent reconciliation, and explains that a payment source must be connected separately for money coverage.
- The follow-up section lists concrete connected capabilities: configured recurring browser checks with alerts, store order/payment-attempt data, and independent capture/refund reconciliation when supported sources are connected. It links to sign-up and sign-in for a supported storefront. If the platform is not recognized, it directs the visitor to contact support to check compatibility before sign-up.
- Copy is localized in ru/en/de. No migration or API contract change.

Verification: `PublicStorefrontCheckTest` 5/5 (175 assertions), including passed, inconclusive, unsupported and localized CTA links. The public site uses existing button/note styles; no frontend bundle changed.

### Step 153: Clear Unsupported-Platform Contact Path

Status: complete

- When the public checker reports `unsupported_platform`, the result now says plainly that public checks do not yet support this store platform. It invites the visitor to contact us because support may already be in development, and links directly to the localized contact form.
- This state no longer shows the generic connected-store benefits, sign-up CTA or the `adapter_unsupported` message suggesting that connecting the store alone would resolve it. Copy is available in ru/en/de.

Verification: `PublicStorefrontCheckTest` 5/5 (179 assertions), including localized contact links and absence of conflicting sign-up/connection copy. No migration or contract change.
### Step 158: Persistent Public Check History in the Control Panel (ADR 0058; Spec §19, §26)

Public URL checks now retain a metadata row for 90 days after the five-minute public result and artifact cleanup. It records normalized site URL (origin, without path or query), created/started/finished timestamps, visitor IP and User-Agent, worker browser, status/verdict and error code. The staff-only `GET /platform-api/v1/public-storefront-checks` endpoint uses `service.read`, private no-store responses and cursor pagination. The control panel has a localized «Публичные проверки» section with those fields. The public form discloses the IP/browser retention. Artifact cleanup clears diagnostics and steps, removes screenshots and expires the public token while preserving the metadata row; the existing 30-day host and 10-day IP cooldowns continue to work. Old metadata is deleted after 90 days.

Migration `2026_10_16_100000_retain_public_storefront_check_history.php` adds request IP, User-Agent and purge timestamp to the existing table; `contracts/openapi.yaml` adds the staff list endpoint. Existing checks can show blank visitor IP/browser because those values were never saved. Verified with `PublicStorefrontCheckTest` and `PublicStorefrontCheckHistoryTest` (7 tests), control panel typecheck and 55 frontend tests. No production migration or live browser smoke was run.

### Step 169: Image Upload in the Page Editor

Status: complete (ADR 0063; limits wait for the owner's confirmation)

- Migration `2026_10_18_140000_create_site_media`, model `SiteMedia`, service `SiteMediaLibrary`: JPEG/PNG/WebP recognised by content, at most 5 MiB (`WATCHDOG_SITE_MEDIA_MAX_BYTES`), 6000 px per side, 16 MP; decoded and encoded again with GD so EXIF/XMP/comments are dropped (JPEG orientation applied first); stored on the private artifacts disk (`WATCHDOG_SITE_MEDIA_DISK`) under `site-media/{uuid}.{ext}`; audited (`site.media.uploaded`, `site.media.deleted`).
- Platform API (`content.manage`): `GET/POST /platform-api/v1/site-media`, `POST /platform-api/v1/site-media/{media}/delete` (409 `media_in_use` while a page version of any state or an active block template contains the address). Public site: `GET /media/{uuid}.{jpg|png|webp}` with immutable caching, `nosniff` and a sandbox CSP. Pages keep the relative address.
- Owner panel: the editor's image panel uploads a file, shows the library to pick from and deletes with a confirmation; the https address field stays. `@bw/api-client` `request` sends `FormData` bodies as multipart. The control dev server proxies `/media`.
- Backend Docker image: `gd` (JPEG, PNG, WebP) and `exif` extensions. README: variables and the rebuild.
- Contracts: `openapi.yaml` (three platform operations).

Verification: `tests/Feature/Site/SiteMediaTest.php` (4: a phone-like JPEG with EXIF orientation and a comment is turned upright and stored without them, served with the headers, listed and audited; SVG disguised as PNG and GIF refused, PNG and WebP taken, size and dimension limits; deletion refused while a draft or a block template uses it, then allowed and the file gone; capability). Control `tests/editor.test.ts` (upload, pick from library, insert with alt, delete). Backend SQLite 612 passed + 4 skipped; PostgreSQL 18: 616/616. Control typecheck, 59/59 tests, build; customer 133/133 and build. Backend fpm image built with the new Dockerfile: imagejpeg, imagewebp and exif_read_data available. Manual browser smoke on the local stand was not run.

### Step 170: Configurable Media Limits, Compression and Optional PDF

Status: complete (owner decision 2026-10-18, ADR 0063)

- Configuration (`config/watchdog.php` → `site.media_*`, `.env.example`, README): formats `WATCHDOG_SITE_MEDIA_TYPES` (`jpeg,png,webp`, optional `pdf`), `MAX_BYTES`, `MAX_SIDE`, `MAX_PIXELS`; compression `RESIZE_MAX_SIDE` (2560, 0 = keep), `JPEG_QUALITY`/`WEBP_QUALITY` (82), `PNG_COMPRESSION` (9). `GET /platform-api/v1/site-media` returns the limits in force; the editor builds its hint and the file chooser's `accept` from them.
- Compression: after the EXIF orientation the image is scaled to fit the configured side (alpha kept) and encoded with the configured quality; the audit records the original and stored size.
- PDF (only when enabled): recognised by `%PDF-`, must end with `%%EOF`, stored unchanged, served inline with the sandbox CSP and a filename; the editor shows it as a PDF tile and inserts it as a link (text = link text or file name). Without `pdf` in the list a PDF is refused, also when renamed to `.png`/`.jpg`.
- Not failing: effective size limit capped at PHP `upload_max_filesize`/`post_max_size`; `PostTooLargeException` on the upload route → 413 `media_too_large`; memory need checked against `memory_limit` before decoding (`media_too_large_for_server`); missing GD format → 503 `media_processing_unavailable`; storage error → 503 `media_storage_unavailable`; decode/encode errors caught → `media_unreadable`; no file → `media_missing`. Backend image gets `php-uploads.ini` (32M/34M/256M), nginx `client_max_body_size 34m` — before, the PHP default of 2 MB would have refused most 5 MB uploads.

Verification: `SiteMediaTest` 7 (new: resize to the configured side and kept size with 0; limits and formats from the configuration, PDF refused by default, accepted when enabled, served unchanged, damaged PDF refused, a PDF named `.jpg` still a document; a 90000×90000 PNG header refused before decoding, cut PNG unreadable, empty file, missing file, oversized request 413). Control `editor.test.ts`: hint and `accept` from the server limits, PDF inserted as a link. Backend SQLite 615 passed + 4 skipped; PostgreSQL 18: 619/619. Control typecheck, 59/59, build. Backend fpm image built: upload_max_filesize 32M, post_max_size 34M, memory_limit 256M, GD available. Manual browser smoke on the local stand was not run.

### Step 171: Syntax Highlighting in the HTML Mode of the Page Editor

Status: complete (ADR 0045, owner decision 2026-10-16)

- `HtmlCodeEditor.vue` (CodeMirror 6, exact versions in `apps/frontend/control/package.json`) replaces the text field of the "HTML code" mode in `PageView` and `BlockView`: highlighting in the panel palette tokens (works in the dark theme), line numbers, active line, bracket matching, auto-closing tags, undo/redo, Tab indent, line wrapping; `aria-label` from the field label. Two-way `v-model`; an outside change (restore, discard, language switch) replaces the text.
- Loaded with `defineAsyncComponent`, so CodeMirror (~164 KB gzip) is fetched only when the HTML mode is opened; the visual editor chunk stays ~141 KB gzip.
- Tests: helper `tests/code.ts` reads and sets the code editor (waits for the async chunk); site and block tests use it; `editor.test.ts` checks highlighting spans, line numbers and sync both ways.

Verification: control typecheck, 60/60 tests, production build. Backend not changed; backend SQLite 615 passed + 4 skipped. Manual browser smoke was not run.

### Step 172: Block Templates Inside the Website Section

Status: complete (ADR 0045, owner decision 2026-10-16)

- `OWNER_SECTIONS` no longer has `blocks`; routes moved to `/owner/site/blocks` and `/owner/site/blocks/:id`, `/owner/blocks/:id?` redirects there. `SiteTabs.vue` ("Pages" | "Block templates", `aria-current`) on the pages list and the template list; the "Website" menu item stays highlighted on pages, templates and a template. The e2e script uses the new address.

Verification: control typecheck, 60/60 (`blocks.test.ts`: not in the main menu, website highlighted, tab current; the old address of a new template redirects), build. Backend not changed; backend SQLite 615 passed + 4 skipped. Manual browser smoke was not run.

### Step 178: Editable Contact Reply Templates and Note Edits

Status: complete (ADR 0049 owner decision)

- Migration `2026_10_18_180000_add_contact_reply_templates_and_note_edits` (`contact_reply_templates`, `contact_request_notes.edited_at`). `ContactInbox::replyTemplate/saveReplyTemplate/editNote`; `ContactRequestController::saveTemplate/editNote`; routes under `contacts.manage`; `openapi.yaml`.
- Owner panel: "Save as template" in the reply form, own templates marked; "Edit" on the staff member's own notes with "edited" time; labels for the new audit actions.

Verification: `PublicSiteTest` (template limits, unknown code 404, saved template used and marked, audit before/after; note edit by the author with audit, another author's note 403, missing note 404), control `site.test.ts` (save template without sending, edit own note, no edit for read-only staff). Backend SQLite 622 passed + 4 skipped; PostgreSQL 18: 626/626. Control typecheck, 65/65, build. Manual browser smoke was not run.

### Step 183: Maintenance Banner on the Public Site

Status: complete (ADR 0054 owner decision)

- `site/layout.blade.php` shows the current maintenance window (`PlatformSettings::forCustomer` in the page language) above the header, reusing the warning strip style; texts `site.maintenance.planned|active` (ru/en/de), times in Europe/Berlin. Not shown in the editor preview. Pages are cached for `WATCHDOG_SITE_CACHE_SECONDS`, so the banner may appear or disappear up to that delay late.

Verification: `PublicSiteTest` (no banner without a window; planned in German and Russian with the window's message; in progress in English; gone after the end). Backend SQLite 629 passed + 4 skipped; PostgreSQL 18: 633/633. Manual browser smoke was not run.

### Step 186: More Built-in Block Templates

Status: complete (ADR 0046, composition confirmed by the owner)

- `database/data/page_blocks_more.php` (10 templates × ru/en/de) and migration `2026_10_19_110000_add_more_page_blocks` (PostgreSQL category check widened; templates appended after the existing ones, a template with the same name is skipped). `PageBlock::CATEGORIES`, `openapi.yaml` enums, owner panel type, category list and labels (ru/en/de).

Verification: `PageBlockTest` (18 templates in order, every language kept unchanged by the sanitizer, links only to their own language). Control typecheck, 67/67, build. Backend SQLite 624 passed + 4 skipped; PostgreSQL 18: 628/628. The templates were not looked at in a browser.

### Step 193: Legal Pages for Paddle (Terms, Privacy, Subscription and Refunds, Legal Notice)

Status: drafts ready; publishing waits for the owner (legal name and address, review in the panel)

- ADR 0065 (owner decisions 2026-10-19: private individual as seller, 14-day money-back on the first payment, business customers only, law of Ukraine, ADR 0041 price grid for now; live Paddle keys after the pages).
- Texts in en/de/ru in `database/data/legal/{terms,privacy,refund,imprint}.php`: Terms with Paddle's Merchant of Record wording, liability, indemnity, termination and its consequences, force majeure, assignment, changes, governing law and disputes, and data processing on the customer's behalf (Art. 28 GDPR); Privacy Policy checked against the code (data, legal bases, retention from the configuration, processors, backups, cookies, rights, complaint to a supervisory authority); Subscription and Refund Policy (trial, renewal, cancellation, plan changes, price changes, failed payments, refunds through Paddle); Legal Notice / Impressum (§ 5 DDG, § 18 (2) MStV, no consumer arbitration).
- `php artisan site:legal-drafts <owner email> --name= --address= [--email=]`: footer pages `terms`, `privacy`, `refund-policy`, `imprint` with document types `service_terms`, `privacy_notice`, `recurring_payment`; drafts in all three languages through `PageAdmin` (sanitized, audited as the owner); provider fields escaped; never publishes; refuses when another page holds a document type.
- Production compose: container logs rotate (`json-file`, 20 MB × 5) so the privacy text about logs holds.
- README «8а. Юридические страницы».

Verification: `LegalDraftsTest` (4 pages, types and footer, 12 drafts in de/en/ru, no placeholders left, name HTML-escaped, Paddle wording and links per language, Impressum address lines, nothing public before publishing, a second run keeps the published version and updates the draft, refusals without provider/owner or with the type on another page leave nothing). Backend SQLite 626 passed + 4 skipped; PostgreSQL 18: 630/630. Not reviewed by a lawyer.

### Step 199: BW Favicon

Status: complete (visual asset; no dedicated acceptance ID)

- White square, blue BW (`brand-700`, `#24549f`) and orange underline (`warning-400`, `#d59430`). SVG linked from the public Blade layout and both SPA entry pages; multi-size ICO replaces the empty backend placeholder. SPA public assets include the SVG for local Vite development.
- No migrations or contracts changed. Visual identity change only; no financial, security or coverage semantics changed.

Verification: inspected the 512 px SVG rendering and enlarged 16 px raster; ICO contains 16, 32, 48, 64, 128 and 256 px sizes. Customer and control production builds passed. No browser or production deployment check.

### Step 212: New Home and Pricing Pages (ru/en/de)

Status: complete; deployed 2026-10-21 (`fc5d72d`), home published on the server with `site:home-drafts --publish` in ru/en/de

- Source: the owner's package `business-watchdog-pages` (2026-10-21, prepared against `92f717e`). Home fragments without scripts, styles or forms; pricing as Blade fragments that read the existing `$pricing` (published plans, currencies, periods, test mode, tax line, yearly saving, extra stores and capped total, faster checks, trial, empty catalogue, individual plans).
- Styles: `apps/frontend/public/src/marketing-pages.css` (the package's Tailwind source without its own imports, scoped to `.bw-page`, no preflight) imported in `site.css`; the palette is the project one, so the site bundle compiles the `bw-*` classes and the owner-panel preview gets them through `SiteAssets::inline()`.
- Pricing: `site/pricing.blade.php` includes `site/marketing/pricing/<locale>.blade.php`; `site.pricing.meta_title` / `meta_description` give the page its own title and description (the pricing page is not editable in the panel). The calculator says the choice of extra stores, period and currency has to be repeated in the cabinet: signup carries only the plan.
- Home: `database/data/home_page.php` + `home_page/<locale>.html`; `php artisan site:home-drafts <owner email> [--publish=<reason>]` saves drafts through `PageAdmin` (sanitized, versioned) and, with `--publish`, publishes each language with an audit record. `docs/ops/site.md`.
- Wording of both pages comes from the package; claims checked against the current state (no guaranteed payments, coverage depends on the integration, public check may add to cart, Shopware App availability assessed per store). Not proofread by native speakers.

Verification: `HomeDraftsTest` (drafts only without `--publish`, nothing public; with it three published versions, audit with the reason, titles and `bw-page` markup on /en, /de, /ru; refusal without an owner), `PublicSiteTest` (pricing title, description and markup; all existing price, period, currency and extra-store assertions pass on the new template). Backend SQLite 684 passed + 4 skipped; `@bw/public` check build. No browser render of the pages in the project; the package's own QA ran 36 Chromium layouts and axe on the fragments.
Production after deploy: /ru, /en, /de and their /pricing answer with the new titles, one H1 and `bw-page` markup; the site stylesheet contains the `bw-*` rules; pricing lists start, business, growth, enterprise (€19, €39, €79 and individual).

### Step 213: Terms After the Lawyer's Answer on Checkout Observation (ADR 0062)

Status: complete; deployed 2026-10-21 (`1f99a88`), terms version 2 published in ru/en/de at the owner's request (no customers yet)

- The lawyer's answer (2026-10-21, summarised in ADR 0062 «Lawyer's answer»; ADR 0057 and 0059 point to it): no consent or cookie-banner entry for the checkout observation script; the shop's legal basis is Art. 6 (1) (f); the service is a processor.
- `database/data/legal/terms.php` (ru/en/de): Section 6 — the customer is the sole controller for the store's visitors and alone informs them under Art. 13 GDPR; our sample texts (privacy paragraphs, LIA) are not legal advice. Section 10 — indemnity also for third-party claims and supervisory fines caused by a missing or wrong privacy policy. Section 15 — checkout observation data (page type, stages, script errors, time, random report ID; no IP, form data or identifiers), its retention (Shopware store until collected, report IDs 7 days, aggregated counts until the store is removed or the contract ends) and the Shopware app's write boundary; Contabo servers and backups «in Germany».
- `site:legal-drafts --only=<slug>` saves drafts of the named pages only (`docs/ops/site.md`).
- Open: the store privacy paragraphs (DE/EN, Step 160, plus «on servers in Germany») and a LIA template are not in the cabinet yet; the observation counters have no retention of their own; the question about remote administration from Ukraine (Art. 44 ff.) goes back to the lawyer.

Verification: `LegalDraftsTest` (new: only the terms get drafts with the new clauses; an unknown slug is refused). Backend SQLite 685 passed + 4 skipped.
Production: `site:legal-drafts --only=terms` with the provider of the published Impressum; the drafts differed from version 1 only by the new clauses and the effective date; published through `PageAdmin::publish` with an audit record; /en, /de, /ru terms show the clauses and «in Germany».

### Step 217: Published Pricing and Selection Through Sign-Up to Checkout

Status: complete; deployed 2026-10-21 (`4f68110`)

- The pricing cards already came from `BillingCatalog::plans()` and use only the current published plan versions and active prices for the Paddle mode/account. The four-column layout was changed to three columns on wide screens. The extra-store calculator still uses a server-side GET; its calculated total is now the main price on the card, and minor-unit multiplication/addition use decimal string arithmetic.
- Each card links to sign-up with its plan code, exact published base price ID (which identifies interval and currency), and selected extra-store count capped for that plan. The public sign-up form and the existing-account link preserve these values. After registration or login, the billing screen selects the matching current catalog price and quantity and highlights the chosen plan. A retired price shows a warning and requires review of the current offer; checkout still validates the price on the server. No automatic purchase is made.
- No migration or API contract changed. Traceability: `PUBLIC-SUBSCRIPTION-FRONTEND` §§3–5 and PAY-01, PAY-03, PAY-04, PAY-11.

Verification: `PublicSiteTest` and `PolicyAcceptanceTest` (22 passed, 448 assertions; published/draft prices, calculator, large minor units, signup and existing-account path); customer `billing.test.ts` (20 passed, including exact price and quantity sent to checkout); customer production build with typecheck, public stylesheet check, Pint. Production GET `/en/pricing?stores=2` showed €37/€55/€93 as main prices and selection links with price IDs; `/en/signup` kept the three fields and the existing-account target. Live checkout is enabled according to the owner; Step 217 did not perform a real Paddle payment.

### Step 224: Eye Mark Across the Site and Cabinets

- Replaced the earlier BW monogram with the owner's approved radar-eye illustration from the WordPress.org plugin artwork. The public header uses the 512 px mark; the public site, customer cabinet and owner cabinet use the matching 64 px favicon. The cabinets also show the eye beside their names. The older SVG URLs contain the same eye for compatibility; the ICO has 16, 32, 48 and 64 px images.
- Production: deployed as `a744b8f`; the eye was uploaded through `SiteMediaLibrary` to `/media/01a15ecb-3b32-72c5-a929-9b48b2bd07c4.png` and selected with `WATCHDOG_SITE_BRAND_MEDIA_URL`. The public header's built-in fallback is `/brand-mark.png`. The media file, favicon and customer cabinet answer HTTP 200; `/ru` HTML selects the new media URL and favicon.
- No migrations or API contracts. Verification: image dimensions and ICO sizes checked; SVGs parsed; `PublicSiteTest` 14/14 (352 assertions); customer check 151/151 and control check (typecheck, tests, builds) passed. Traceability: site branding in Step 199, spec §8–9, customer and owner panel navigation.

### Step 225: CAPTCHA and One Safe Attachment on the Contact Form

Status: complete; deployed 2026-10-22 (`deb2162`)

- CAPTCHA: the self-hosted image CAPTCHA of the public store check (sum of two digits, HMAC in the session, 10 minutes, single use) moved into `App\Support\Site\MathCaptcha` and is now required on `/{locale}/contact` too (`GET /{locale}/contact/captcha`, its own session key). A wrong, reused or expired answer keeps the typed fields and shows a new image. No third-party script or cookie.
- Bug fixed on both forms: the CAPTCHA image request is a GET, so Laravel stored it as the "previous URL" and a validation error redirected the visitor to the image. The forms now always return to their own page (`ValidationException::redirectTo`). This was live on the store check form.
- Attachment: one optional file — JPG, PNG, WebP or PDF, up to 5 MB (`WATCHDOG_SITE_CONTACT_ATTACHMENT_TYPES`, `…_MAX_BYTES`, `…_DISK`). The file-processing core of the page editor (ADR 0063) moved from `SiteMediaLibrary` into `SafeFiles` and is shared: the type comes from the content, not the name or browser type; images are decoded and encoded again (EXIF/GPS and anything appended to the file are dropped, side/pixel limits of the media library); a PDF must have a complete structure and is kept as it is. SVG, HTML, Office/ZIP, executables and renamed scripts are refused. The original name is reduced to a safe base name plus the real extension.
- Storage: private disk under `contact-attachments/`; migration adds `attachment_key/name/content_type/bytes` to `contact_requests` (PostgreSQL CHECK: all or nothing, only the four types). A rejected file stores nothing and does not count against the hourly limit. `site:contact-purge` deletes the files together with the closed messages. The owner email names the file and its size (the file itself is not mailed).
- Owner panel: the message card has `attachment` (name, type, bytes) and a link `GET /platform-api/v1/contact-requests/{id}/attachment` (`contacts.read`): always a download (`Content-Disposition: attachment`, `nosniff`, CSP `sandbox`, `no-store`), audited as `site.contact.attachment_downloaded`. OpenAPI updated.

Verification: new `ContactFormTest` (4 tests: fresh/wrong/reused/expired CAPTCHA, cleaned PNG with an appended PHP payload, staff-only download with headers and audit, owner email, purge deletes the file, PDF kept byte for byte, HTML/SVG/EXE/PHP/ZIP/broken PDF/too large/disabled refused with nothing stored); `PublicStorefrontCheckTest` now asserts the redirect back to the form (fails without the fix). Backend SQLite 709 passed + 4 skipped, Site suite on PostgreSQL 18 48/48, Pint ok; control typecheck and 70/70 tests. Not checked in a real browser.

### Step 228: Sitemap and robots.txt

Status: complete; deployed 2026-10-22

- `/sitemap.xml` (`SitemapController`) answered 404 before. It now lists every page the site shows: home, pricing and contact in ru/en/de, other pages in the languages with a published version, archived pages left out. Each URL has its languages as `xhtml:link` hreflang alternates and, for edited pages, `lastmod` = publication date. Sign-up, store check and pay are not listed. Absolute addresses come from `APP_URL`.
- `/robots.txt` is now served by the application instead of the static `public/robots.txt`: still allows everything and adds `Sitemap: <APP_URL>/sitemap.xml`.
- Ops: `docs/ops/site.md`.

Verification: `PublicSiteTest::test_sitemap_lists_shown_pages_with_their_languages_and_robots_points_to_it` (system pages in three languages, home first, a page only in its published languages with alternates and lastmod, draft and archived pages left out, forms left out, robots line); backend SQLite 711 passed + 4 skipped, Pint ok.


### Step 288: Small Transparent WebP Header Logo

Status: complete; deployed 2026-10-27 (`15fd387`).

- Re-encoded the original 512×512 PNG eye as `public/images/brand-mark-64.webp` (1,960 bytes) and `brand-mark-128.webp` (4,102 bytes), with transparency outside the rounded blue mark. The internal white artwork is preserved.
- The public header uses `/images/brand-mark-64.webp` with `/images/brand-mark-128.webp 2x` in `srcset`, keeping `width="36"`, `height="36"`, empty alt and async decoding. It no longer selects the oversized media PNG via `WATCHDOG_SITE_BRAND_MEDIA_URL`. Existing user changes to stylesheet inclusion were preserved.
- The owner's layout change embeds the site stylesheet on public pages as well as previews. Caddy serves `/site/assets/*` from `/srv/web/current/public` with a one-year immutable cache header on successful responses. Caddy 2.10.2 `caddy validate` passed.
- No migrations or API contracts changed. Traceability: Step 224 site branding and spec §31 performance; no acceptance matrix status changed.

Verification: decoded both files (64×64 and 128×128, alpha range 0–255), visually compared them with the original on a grey background; `git diff --check`; Docker `APP_ENV=testing` PublicSiteTest: 15 passed, 384 assertions. Initial Docker run without explicitly overriding APP_ENV: 6 passed, 9 failed with login CSRF 419; rerun in testing environment passed. Production: rollout completed successfully, no migrations pending; live HTML selects both WebP sizes and inline CSS; both images return HTTP 200 with image/webp and match the committed files byte for byte; site stylesheet returns HTTP 200 with the one-year immutable cache header. The 300-second rollout probe observed only HTTP 200: `/health/ready` 495 requests, `/app/` 707, `/en` 422. Remaining: a fresh PageSpeed measurement.

### Step 295: Videos page on the public site (2026-10-28)

Status: complete

- Owner: host the sales videos (Step 294) on our own site instead of YouTube and link them from the plugin description.
- (replaced by an editor page in Step 296) `/{ru,en,de}/videos` (`SiteVideosController`, `site/videos.blade.php`, texts `site.videos.*`): three videos with a short problem-first text each, a note how they were made (test shop, Stripe test mode, real checks, incidents and e-mails; shop and customer invented), buttons «14 days free» and «check your store URL». German page shows the German videos; English and Russian the English ones (Russian page says so). In the sitemap with hreflang alternates.
- Files: `apps/backend/public/videos/*.mp4` (H.264, `+faststart`, CRF 25, ~8.7 MB for six) and `*.webp` posters; served as static files by Caddy (byte ranges for seeking — PHP media serving would not support them), `preload="none"`.
- Fixed a fragile sitemap test: `collect($xml->url)` kept only the last `<url>` (SimpleXML iterator keys are all `url`); now `iterator_to_array(..., false)`.
- WordPress.org shows an embedded player only for YouTube/Vimeo/VideoPress links; the plugin description links to the page instead. Shopware Store accepts YouTube videos only.

Verification: `PublicSiteTest` 16 (new: page per language, video and poster files exist, sitemap entry); local `/en|de|ru/videos` 200, `Range` request answered `206` with `video/mp4`; page checked on desktop and 390 px.

### Step 296: The videos page is an editable page of the site editor (2026-10-28)

Status: complete

- Owner: the videos page must be editable in the owner panel. Step 295's page in code (`SiteVideosController`, `site/videos.blade.php`, `site.videos.*`, a fixed sitemap entry) is replaced by an ordinary editor page `videos` (header menu): `<video>`/`<source>` with relative addresses already pass `PageSanitizer`.
- `php artisan site:videos-page <owner email> [--publish=reason]` creates the page when missing and saves the en/de/ru texts from `database/data/videos_page/` as drafts, publishing them with `--publish`; a second run updates the same page. The sitemap lists it like any published page.
- Video files stay in `apps/backend/public/videos/` (static, byte ranges); the media library still takes images and PDFs only, so a new video is added to git and deployed, then referenced in the page HTML.

Verification: `PublicSiteTest` 16 (new: drafts give 404, `--publish` serves `<video>` with sources and posters in de, English videos with the note on ru, header menu link, sitemap entry, one page after a second run, every referenced file exists).

Production (2026-10-28): deployed `418b224`; `site:videos-page <owner> --publish="Videos page"` published `/en|de|ru/videos` (header menu); the plugin description links the page (SVN r3770046, `trunk` and `tags/0.4.0`).

### Step 297: Change the published version of a page in place (2026-10-28)

Status: complete

- Owner: editing a published page through a draft and a new version is too heavy for a one-tag fix; the videos page's button row `<p class="site-stores">` sticks to the section above and has to become `<section>`.
- `PageAdmin::updatePublished` + `PUT /platform-api/v1/pages/{page}/locales/{locale}/published` (`content.manage`): replaces title, description and HTML of the published version in place (same version, no draft, no history row), same sanitizing and checks as a draft (shared `validatedContent`), reason required, audit `site.page.published_updated` with the text before and after. Legal documents are refused (`legal_page_needs_version`, 409) — consents refer to a version and its hash (ADR 0042); without a published version `not_published` (409). ADR 0039 amended.
- Owner panel page editor: «Update published» next to «Save draft» when the language has a published version, no draft and the page is not a legal document; the reason is asked in the usual confirmation; a hint explains what it does, legal documents show why they need a version. Audit label ru/en/de in the panel and the CSV export (`lang/audit/*.json`).
- Videos page source (`database/data/videos_page/*.html`): the button row is a `<section class="site-stores">`.

Verification: `PublicSiteTest` 17 (new: 409 without a published version, reason required, in-place change keeps one version and no draft, sanitized, audit before/after, legal document refused); owner panel tests 96 (new: button disabled until changed, sends text and reason, no draft saved, notice; no button and a hint for a legal document), types, build.

Production (2026-10-28): deployed `099f2a0`; the published videos page was fixed with the new in-place update as the owner (en/de/ru stay version 1, reason «Button row as a section (spacing)», audit `site.page.published_updated`); the button row now has its spacing.

### Step 298: Security page «Why Business Watchdog is safe for your store» (2026-10-28)

Status: complete

- Owner: visitors must see that the service reads nothing extra and changes nothing; trust first, GDPR answers (the lawyer may correct them), good for search. No links to the source code on GitHub — not proof for a merchant, and a map for attackers.
- New editor page `security` (footer menu, en/de/ru, from `database/data/pages/security/`): short summary; what is read per connection (WooCommerce read-only key checked and write keys refused, GET allowlist and the requested order fields; plugin; Shopware role and App permissions; Stripe restricted key only, `sk_` refused; PayPal); where a platform cannot be read-only (PayPal, Shopware) and how the service handles it; the checkout check never buys; storage in Germany, encrypted keys, retention, MFA/passkeys, support access only with the owner's approval; how to stay in control; GDPR Q&A (processor under Art. 28 — Terms Section 15, location — Privacy, other recipients Contabo/Paddle/Telegram, consent for the observation script, retention); links to the videos, the free store check and pricing. Every claim matches code and the legal pages.
- `site:videos-page` is replaced by the generic `php artisan site:page <slug> <owner email> [--publish=reason]` reading `database/data/pages/<slug>.php` (menu, sort, texts); the videos texts moved to `database/data/pages/videos/`.

Verification: `PublicSiteTest` 18 (new: unknown or unsafe slug refused, footer page in three languages with terms/privacy links and the GDPR answers, footer link, sitemap, no GitHub link).

Production (2026-10-28): deployed `e57c38a`; `site:page security <owner> --publish="Security page"` published `/en|de|ru/security` (footer menu, sitemap); no GitHub link on the page.

### Step 300: The security page redone as a designed page with real screenshots and permission lists (2026-10-28)

Status: complete

- Owner: the published security page was dry — plain lists, no screenshots, «designed like a log». Wanted: quality and good looks, screenshots, lazy-loaded web-optimised images for PageSpeed, and code blocks with the real permission lists.
- The page now uses the home page's design (`bw-page`): hero with «what it does / never does», section chips, six promise cards, per connection a split of text and a screenshot or code block — WooCommerce (key form with «Read», the GET allowlist, `ORDER_FIELDS`, what is kept from `meta_data`), Stripe (cabinet card with `rk_••••`, the restricted key's permissions), PayPal (the honest warning, the cabinet warning, the exact method list), Shopware (the App's `<read>` permissions, the POST allowlist, the honest note about «view only» roles), the plugin (emergency stop), the checkout check (a real passed run), data storage (facts, support access form), GDPR FAQ (6), call to action. en/de/ru; screenshots per language from the local stand (cabinet in the page language; WordPress key form en/de, the plugin page has no translation), no personal data or secrets (keys masked, test e-mails).
- Images: WebP 640/1280 with a content hash in the name under `public/images/pages/security/` (5–45 KB each), `srcset`/`sizes`, `width`/`height`, `loading="lazy"`, `decoding="async"`, descriptive `alt`; the hero has no image, so nothing lazy is above the fold. Caddy serves `/images/pages/*` as immutable for a year.
- `site/page.blade.php`: a page whose HTML starts with `<div class="bw-page"` is rendered full width like the home page and brings its own `<h1>`; other pages unchanged. New `bw-*` classes for screenshots, code blocks, check lists, section chips, facts and the honest note.

Verification: `PublicSiteTest` 18 (security test: one `<h1>`, the Shopware permission list, links to terms/privacy; every image lazy/async with size, alt and existing 640/1280 files), backend 826 + 4 skipped, Pint; site stylesheet build check. Looked at desktop 1366 and mobile 390 in en/de/ru: no horizontal scroll, all images load.

