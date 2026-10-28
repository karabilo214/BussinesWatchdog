# SEO guides: initial publication (Step 304)

Nine editable pages, each in English, German and Russian. The path stays the same after `/en`, `/de` or `/ru`; canonical URLs, language alternatives and sitemap entries come from the existing public-site renderer. These are candidate search intents, **not verified search-volume claims**. Search Console and Google Keyword Planner validation remains in the backlog.

| Candidate query | Path after locale | Menu section | Scope of the answer |
|---|---|---|---|
| WooCommerce payment received but order pending | `/woocommerce-payment-pending` | WooCommerce | Confirm capture, trace notification, then review capture not recorded by the store. |
| WooCommerce missing orders | `/woocommerce-missing-orders` | WooCommerce | Distinguish filters, failed storage and delayed imports; explain the covered unmatched-capture review signal. |
| WooCommerce payment reconciliation | `/woocommerce-reconciliation` | WooCommerce | Match orders to independent captures and refunds; keep fees and payouts separate. |
| WooCommerce orders not processing | `/woocommerce-order-processing` | WooCommerce | Separate expected status behavior, payment problems and later fulfillment tasks. |
| Stripe payment not showing in WooCommerce | `/stripe-woocommerce-mismatch` | Stripe & PayPal | Follow PaymentIntent/Charge references, account, mode and gateway delivery. |
| PayPal payment received order pending | `/paypal-pending-orders` | Stripe & PayPal | Distinguish approval, pending capture and completed capture; inspect provider reason and notification. |
| Stripe WooCommerce reconciliation | `/stripe-reconciliation` | Stripe & PayPal | Order-level captures, repeated events, multicapture, refunds, fees and payout boundaries. |
| Shopware payment reconciliation | `/shopware-reconciliation` | Shopware | Supported Shopware 6.6/6.7 + Stripe/PayPal evidence, attribution and incomplete coverage. |
| Shopware paid order not processed | `/shopware-order-monitoring` | Shopware | Publish only the supported capture-not-recorded case; distinguish it from already-paid orders awaiting operational processing. |

## Content and presentation

`apps/backend/database/data/seo/{en,de,ru}.json` holds original localized copy; `common.json` holds shared localized labels and access information. `catalog.php` maps the nine pages to menu sections. `render.php` creates the initial full HTML body using the existing Security page design (`bw-page`, hero, summary panel, contents, cards, manual checklist, scope/limits, FAQ and CTA). The renderer is used only during import: public requests read the stored page versions. No extra analytics, third-party scripts, forms or external image requests were added.

Every page provides an immediate answer, distinct causes, manual checks, a recovery approach and result verification before describing Business Watchdog. It then explains supported monitoring, limits and safe connection, links to related guides and Security, and offers registration/pricing. Titles, descriptions, H1s and short menu labels are separate. Public HTML uses normal editable page versions, not code-only pricing fragments.

Scope evidence in the repository:

- `OrderReconciliationService::evaluateCaptureRecordedByStore`: independent capture with reliable allocation, then grace and store recording check.
- `UnmatchedPaymentScanner`: supported independent captures since connection, fresh store data, account attribution, 24-hour grace; a review item, not proof that an order disappeared or money was lost.
- ADR 0068 / financial registry: captures, refunds, fees and payouts have different meanings; stale/unknown is not zero.
- Current project state: post-payment fulfillment obligations (§43) are not implemented. Both processing pages state this explicitly. Shopware coverage is restricted to supported identifiable operations; SwagPayPal partial refunds may remain unknown and shared-account payments require attribution.

## Official references checked on 2026-10-28

The guides link their relevant references next to the manual checks; these support platform facts, not claims about Business Watchdog features.

- [WooCommerce order statuses](https://woocommerce.com/document/managing-orders/order-statuses/), [order troubleshooting](https://woocommerce.com/document/managing-orders/troubleshooting-orders/).
- [Stripe extension status behavior](https://woocommerce.com/document/stripe/admin-experience/order-statuses/), [Stripe references in WooCommerce](https://woocommerce.com/document/stripe/admin-experience/order-information/).
- [Stripe Charge fields](https://docs.stripe.com/api/charges/object), [webhook delivery](https://docs.stripe.com/webhooks).
- [PayPal capture statuses](https://developer.paypal.com/api/payments/v2/definitions/capture_status/), [approval/capture events](https://developer.paypal.com/payment-methods/webhooks/).
- [Shopware order/payment/delivery states](https://docs.shopware.com/en/shopware-6-en/orders/overview), [Flow Builder events](https://docs.shopware.com/en/shopware-6-en/settings/Flow-Builder).

## Release and editorial ownership

See [site operations](ops/site.md). `site:seo-pages` prepares initial drafts; `--publish=<reason>` publishes new or unchanged initial translations. The standard rollout runs it after the new app and static files are ready. New installations without an owner skip with a message. Later releases preserve published edits, changed drafts, archived pages, unpublications, section names and menu choices. No payment operations are performed.

The nullable unique `public_menu_sections.code` supplies a stable import identity (`seo-woocommerce`, `seo-payments`, `seo-shopware`). Existing release code can continue omitting the column. No API or event contract changes are required; editing uses the page and menu-section APIs already present.
