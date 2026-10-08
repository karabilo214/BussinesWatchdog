# ТЗ внутренней панели администратора Business Watchdog

Версия1.1. Это интерфейс сотрудников SaaS, не tenant_admin. Панель `/admin` обеспечивает ежедневную поддержку и эксплуатацию без скрытого изменения денег клиента. MUST P1; минимальная health часть MUST P0. Role definitions — `OWNER-PANEL.md`.

## 1 Матрица platform прав

| Capability | Owner | Admin | Support | Billing |
|---|---|---|---|---|
| Read platform health and technical queues | Да | Да | Summary | Нет |
| Read tenant account summary | Да | Да | Да | Commercial fields |
| Read tenant detailed evidence | С grant | С grant | С grant | Нет |
| Read SaaS invoices/payments | Да | Summary | Masked state | Да |
| Retry safe technical job | Да | Да | Requested action | Нет |
| Request support grant | Да | Да | Да | Нет |
| Suspend technical monitoring with reason | Да | Да | Нет | Нет |
| Resume after technical suspension | Да | Да | Нет | Нет |
| Create financial entitlement override | Да | Нет | Нет | Request only |
| Submit refund нашей оплаты | Да | Нет | Нет | Request only |
| Publish plans/prices | Да | Нет | Нет | Нет |
| Grant staff roles / platform config | Да | Нет | Нет | Нет |
| View/edit provider secrets | Нет | Нет | Нет | Нет |

System permission catalog backend must map each route/action to a capability, не только `/admin/*` role boolean. UI receives allowed_actions per resource; backend recalculates them for every request.

## 2 Navigation и operational overview

Разделы: обзор; клиенты; магазины; интеграции; проверки; инциденты; доставка данных; уведомления; биллинг по правам; задачи; аудит. Header environment LIVE/TEST, staff role, freshness. Основной экран приоритетно показывает проблемы **нашего сервиса**, затем affected tenant count, без объявления всех магазинных инцидентов нашими outage.

Карточки: failed integrations; stale heartbeat; oldest inbox/outbox/billing inbox; dead letters; browser capacity/backlog/expired leases; failed/uncertain alerts; entitlement sync errors. Drill-down по reason codes. Scheduled maintenance/suspended tenants не учитываются как неожиданная поломка, но отображаются отдельным счётчиком. Нельзя выводить сервис healthy при недоступном monitor backend.

Data refresh polling15–30с только visible page, stop on hidden tab/unmount, request cancellation, stale badge при missed updates. Long queries через async report, не HTTP timeout120с. Staff timezone configurable, исходные timestamps UTC доступны.

Все admin panel strings, operational action previews, safe errors и export labels MUST иметь locale coverage `ru`, `en`, `de`; provider/raw technical messages не выводятся напрямую вместо translation key.

## 3 Поиск клиента и support grant

Support case P1 — reason+target tenant+scope+expiry. Минимальные summary доступны staff по роли; подробные orders/findings/screenshots требуют grant. Grant выдаёт tenant_owner в кабинете, TTL default24ч, maximum72ч; permissions read_diagnostics/read_artifacts/request_recheck явно перечислены. Staff access read-only; изменяющее действие проходит отдельный platform action policy.

До owner approval staff видит «Ожидает разрешение». Revoke мгновенно закрывает выдачу новых links и API; уже выданный artifact link действует только до короткого TTL. Owner service emergency доступ не реализован обходом grant в P1; emergency runbook отдельно, capability break_glass LATER.

Card tabs: connection health, sync/backfill, supported capabilities, sanitized errors, runs/incidents при grant, support notes и action history. Support notes не могут включать credentials/customer card data. Нет Login as customer и импорта tenant session cookies.

## 4 Магазины и интеграции

Store list columns: tenant/store IDs, public origin, platform/checkout adapter, verified status, monitoring state, last successful run, source coverage, current backlog, pending action. Filter by health/reason/provider/adapter versions. Tenant business amounts скрыты от role без grant.

Integration detail: version matrix, mode/account ref masked, capabilities, last sync, covered windows/gaps, oldest outbox, revoked/rotating status, error counters. Provider key не показывается; кнопка «Попросить клиента переподключить» создаёт сообщение service notification без секрета. Сообщение отправляется только как действие в будущем реализованном продукте по утверждённой policy; подготовка ТЗ ничего не отправляет.

Safe actions: trigger delta sync, resume stalled backfill, diagnostic rescan, request client reconnect. Система проверяет existing running job и quota; repeated click возвращает existing action/job. Force-full-backfill large range требует owner/admin confirmation preview expected data volume, rate budget и scope. Backfill не воспроизводит historical уведомления автоматически.

## 5 Очереди dead letters и jobs

Inbox view показывает event envelope metadata, status, attempts, safe error, source revision/hash и timings. Payload preview redacted и только с grant. Retry default dry_run: affected projection/action summary → confirm → scoped replay. Conflict events требуют resolution/resync и не просто repeated retry.

Outbox view: topic,dedupe key,state,age,attempts,error,consumer. Retry сохраняет dedupe key. Нельзя clear queue/delete inbox одной массовой кнопкой. Dead-letter resolution reason и actor обязательны. «Убрать из списка» не равно выполнить job; dismissed diagnostic сохраняется отдельно.

Platform actions list: queued/running/succeeded/failed/unknown/cancelled, who/why/target, idempotency, progress, result IDs. Unknown external side effect не auto retry: query provider first. Progress количественный если known count; иначе stage label. Action detail содержит safe logs/steps, но не raw exception с env.

## 6 Browser pool и checks

Worker summary: worker ID/version/location, available slots, last heartbeat, mean/p95 runtime, memory pressure summary. Staff не получает host shell, Docker socket и raw process environment. Stop scheduling или drain pool — reviewed action с audit; running jobs завершаются либо cancellation fence.

Logical check и attempts раздельно, scenario snapshot, lease/fence state, last_good/first_bad, infrastructure vs site reason codes. Lease secrets скрыты. Retry technical failure не создаёт checkout incident. Synthetic product unavailable и adapter unsupported показаны как setup issue.

Manual recheck requires verified domain+enabled monitoring+grant scope+limits. Из UI нельзя выполнить arbitrary URL, arbitrary JavaScript, отключить SSRF или разрешить реальное payment mutation. Обновление adapter version происходит через release, не редактированием production DSL без проверки.

## 7 Инциденты и уведомления

Platform incident dashboard агрегирует technical service failures отдельно от tenant business incidents. При grant staff читает relevant evidence и может оставить support comment/request recheck. Acknowledge за клиента не допускается без явного delegated capability; в P1 staff action note не меняет tenant acknowledgment.

Notification delivery detail: channel type/destination masked, template revision, queued/sent/uncertain/dead_letter, timestamps, safe failure. Retry notification: preview message, verify current incident relevance, dedupe, no replay of resolved historical critical as new open. Test alert to client only through authorized support action with clear test label.

## 8 Billing support

Billing role видит invoices нашей услуги, attempts/refunds, current contract, access policy и why past_due. Не видит financial_transactions магазинов. Не устанавливает invoice paid checkbox. Refresh billing запускает read-only provider sync; найденное состояние проецируется тем же reducer, что webhooks.

Billing request override/refund → pending owner approval, scope/amount/duration/reason. На own role submit endpoint403. Отклонение запроса сохраняется в audit. Коммерческие изменения support employees вне P1; coupon/cash manual collection LATER.

## 9 Требования UX и приёмка

Каждая mutation: preview что изменится, reason, кнопку disabled во время submit, idempotency, final server state, conflict recovery. Loading не отображать как success. Bulk максимум100 explicit selected IDs, permission на каждом; никакого «all matched across every tenant» без review+async action. Financial bulk changes не реализуются P1.

ADM-01 tenant_admin не имеет platform route. ADM-02 role permissions enforced backend. ADM-03 no grant→no tenant evidence. ADM-04 grant expiry/revoke enforce immediately. ADM-05 no secrets в all tables/export/logs. ADM-06 replay idempotent, no stale projection rollback. ADM-07 infrastructure failure не site_failure. ADM-08 billing staff не submit refund/override. ADM-09 duplicate job action harmless. ADM-10 suspension не отменяет payment contract. ADM-11 worker drain/cancel fencing tested. ADM-12 uncertain payment side effect не blindly retried.
