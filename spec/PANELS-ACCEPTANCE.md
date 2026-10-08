# Приёмка панелей и SaaS billing

Версия1.1. Это добавочные критерии к ACCEPTANCE.md; реализация пока отсутствует, все пункты не выполнены. Exact test evidence/commit добавляется Codex при разработке. Детальные acceptance IDs OWN/ADM/CLT/PAY/BCK перечислены в соответствующих пяти ТЗ; эта матрица проверяет сквозные сценарии.

| ID | Сценарий | Ожидаемый результат |
|---|---|---|
| EXT-01 | Tenant_owner пробует platform API | Denied, никакой выдачи staff роли |
| EXT-02 | Platform staff имеет customer membership | Две независимые sessions; права не сливаются |
| EXT-03 | Owner без MFA/step-up | Sensitive mutation forbidden |
| EXT-04 | Удаление последнего platform owner | Transaction rejects |
| EXT-05 | Support без customer grant | Только safe account summary |
| EXT-06 | Grant revoked/expired | Detailed reads и новые artifacts links запрещены |
| EXT-07 | Published price изменён | New immutable version, старые contracts сохранены |
| EXT-08 | Forged price/amount/currency from browser | Backend revalidates published mapping |
| EXT-09 | Concurrent two checkout requests | Single reservation/provider effect |
| EXT-10 | Lost provider response | Unknown+query, без double charge/refund |
| EXT-11 | Browser return без webhook | Нет fulfillment по success query |
| EXT-12 | Verified webhook без browser return | Paid entitlement applied once |
| EXT-13 | Duplicates и out-of-order events | No duplicate sums, no stale projection overwrite |
| EXT-14 | LIVE/TEST и чужой account | Commercial events rejected/isolated |
| EXT-15 | Failed renewal и grace expiry | Correct past_due/grace/paused_billing |
| EXT-16 | Scheduled cancellation | Доступ до verified end, renewal stop separately |
| EXT-17 | Downgrade превышает store limit | Customer choice required, no data deletion |
| EXT-18 | Temporary override истёк | Provider paid facts unchanged; policy recomputed |
| EXT-19 | Refund request billing staff | Owner approval needed before monetary submission |
| EXT-20 | Refund concurrency | Reservations не превышают paid remainder |
| EXT-21 | New subscription после cancellation | Old invoices и refs сохранены |
| EXT-22 | KPI MRR/cash/refunds | Definition consistent, per currency, no merchant sums |
| EXT-23 | Technical replay | Same dedupe keys, no old alert storm |
| EXT-24 | Tenant_admin billing/delete/owner role action |403 и audit denied sensitive action |
| EXT-25 | Workspace switch | Query cache/context isolated |
| EXT-26 | Customer deletion с recurring contract | Cancellation resolved before purge; tombstone retained |
| EXT-27 | Legal/policy/price version changed | New purchase preview+acceptance, old history immutable |
| EXT-28 | Provider unavailable | No paid grant, previous paid_until preserved |

Требуются также16 BILL fixture outcomes, проверка no-card storage/secret redaction, CSRF и stored XSS, изоляция guard cookies, private invoice export/download, provider sandbox upgrade/proration/cancel/refund и наблюдаемость webhook lag. Tests of actual provider behavior нельзя заменить JSON fixtures.
