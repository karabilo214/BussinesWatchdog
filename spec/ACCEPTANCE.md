# Матрица приёмки Business Watchdog

Все пункты ниже имеют статус **не выполнено**: пакет содержит ТЗ, а не рабочую реализацию. При разработке добавьте ссылку на тест, commit и evidence. P1 включает обязательные P0; P2 принимается отдельно.

| ID | Этап | Сценарий | Ожидаемое поведение | Раздел ТЗ |
|---|---|---|---|---|
| ACC-01 | P0 | Fresh setup без secrets | Demo запускается по README | 6, 34 |
| ACC-02 | P0 | Tenant A знает UUID B | Чтение/изменение/экспорт/артефакт запрещены | 7, 25 |
| ACC-03 | P0 | Нет tenant context в job | Fail-closed с безопасной диагностикой | 25, 28 |
| ACC-04 | P0 | Pairing retry/expired/used code | Повторная выдача secrets не происходит | 8, 27 |
| ACC-05 | P0 | HMAC body altered/nonce replay | 401; событие не принято | 27 |
| ACC-06 | P0 | Одно событие прислано десять раз | Один inbox event и один business effect | 11 |
| ACC-07 | P0 | Same ID но different hash | Conflict, resync; никакого overwrite | 11 |
| ACC-08 | P0 | Revision17 пришла после18 | Current snapshot остаётся18 | 9, 11 |
| ACC-09 | P0 | Crash after DB commit before queue | Sweeper восстанавливает обработку | 11, 28 |
| ACC-10 | P0 | DB down | 503, false accepted отсутствует | 28 |
| ACC-11 | P0 | Redis down | Inbox сохранён, processing восстановлен | 28 |
| ACC-12 | P0 | HPOS/legacy orders/refunds | CRUD даёт согласованные projections | 9 |
| ACC-13 | P0 | Backfill одновременно live events | Нет отката новой revision | 9 |
| ACC-14 | P0 | WP-Cron остановлен | Stale/partial, не sales outage | 9, 15 |
| ACC-15 | P0 | Classic happy path | Product/cart/checkout/payment_form passed | 18 |
| ACC-16 | P0 | Blocks checkout-draft side effect | Own synthetic draft marked/cleaned; чужие не тронуты | 19 |
| ACC-17 | P0 | Real submit/capture attempted | Blocked на DSL и network boundary | 19 |
| ACC-18 | P0 | PrivateIP/IPv6/redirect/DNS rebind | Egress blocked | 19 |
| ACC-19 | P0 | Cart/checkout relevant500 twice | Один confirmed site signal | 18, 20 |
| ACC-20 | P0 | Tracking404/favicon missing | Не critical checkout failure | 21 |
| ACC-21 | P0 | WAF/CAPTCHA | Monitoring blocked, не глобальная поломка | 20 |
| ACC-22 | P0 | Product out of stock | Test product issue | 18 |
| ACC-23 | P0 | Worker killed, lease expired | New fencing token; stale result409 | 20 |
| ACC-24 | P0 | Result retry identical/altered | Identical200; altered409 | 20, 26 |
| ACC-25 | P0 | Screenshot/trace содержит token | Artifact rejected/redacted; raw trace off | 19, 21 |
| ACC-26 | P0 | Acknowledge | Incident остаётся не resolved | 22 |
| ACC-27 | P0 | Data stale во время recovery | Нет auto resolve | 22 |
| ACC-28 | P0 | Email timeout/retry | Dedupe и visible uncertain delivery | 23 |
| ACC-29 | P1 | FIN-01…FIN-24 | Все financial expectations выполнены | 12–14 |
| ACC-30 | P1 | Intent+charge events об одном capture | Один financial operation | 10, 12 |
| ACC-31 | P1 | Два captures distinct IDs | Warning multiple captures | 14 |
| ACC-32 | P1 | Manual Woo refund | Unknown/bookkeeping, не доказанный refund | 12 |
| ACC-33 | P1 | Authorization without capture | Не captured amount | 12 |
| ACC-34 | P1 | Fee/net payout отличается от total | Нет ложной недоплаты заказа | 12 |
| ACC-35 | P1 | Two currencies | Отдельные totals; mismatch без arithmetic | 12 |
| ACC-36 | P1 | Concurrent manual allocation | Sum не превышает operation amount | 13 |
| ACC-37 | P1 | Revoked API key | Source unknown, не missing money | 10 |
| ACC-38 | P1 | Telegram binding чужому chat | Нельзя подключить без code verification | 23 |
| ACC-39 | P1 | Quiet hours и recovery | Preferences соблюдены | 23 |
| ACC-40 | P1 | Plan quota или suspended | Visible pause, данные не silently discarded | 29 |
| ACC-41 | P1 | Backup restore новым host | RPO/RTO измерены, keys доступны | 33 |
| ACC-42 | P1 | Tenant deletion с artifacts | Revoke, objects delete, resumable DB purge | 33 |
| ACC-43 | P1 | Load/capacity fixture | Измеренный SLO и pool sizing report | 31 |
| ACC-44 | P2 | Empty complete vs stale bucket | Zero отличается от unknown | 15 |
| ACC-45 | P2 | 1 order/day shop | Нет hourly critical anomaly | 16 |
| ACC-46 | P2 | DST fallback/skip | UTC buckets не теряются/не дублируются | 15 |
| ACC-47 | P2 | MAD0/мало истории | Fallback/abstain, без invented certainty | 16 |
| ACC-48 | P2 | Consent/coverage изменились | Conversion conclusion gated | 17 |
| ACC-49 | P2 | Sales drop+browser failure | Один correlated incident с фактами | 22 |
| ACC-50 | P2 | Deploy рядом со сбоем | Correlation не объявлена proven cause | 22 |

Дополнительные обязательные security проверки: CSRF, stored XSS, CSV formula injection, permission matrix, last owner, invitation expiration, secret masking и RLS при включении. Это не опциональный backlog.
