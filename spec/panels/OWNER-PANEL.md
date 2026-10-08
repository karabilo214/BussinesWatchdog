# ТЗ панели владельца Business Watchdog

Версия дополнения 1.1 от 8 октября 2026 года. MUST для коммерческого P1. Основное ТЗ и его правила денег, tenant isolation и отсутствия ИИ сохраняются. Этот файл определяет **владельца самого SaaS — platform_owner**. Кабинет владельца организации клиента — tenant_owner — отдельно описан в `CLIENT-PANELS.md`.

## 1 Роли и поверхности

| Роль | Кто это | Панель | Граница |
|---|---|---|---|
| platform_owner | Владелец Business Watchdog | /owner | Весь сервис и его собственная коммерция |
| platform_admin | Сотрудник эксплуатации сервиса | /admin | Разрешённые support и operational actions |
| platform_support | Поддержка клиентов | /admin | Заявленные клиенты и безопасная диагностика |
| platform_billing | Сотрудник финансов сервиса | /admin/billing | Оплаты нашей услуги, без магазинных secrets |
| tenant_owner | Владелец организации клиента | /app | Свои магазины, подписка, сотрудники |
| tenant_admin | Администратор клиента | /app | Свои магазины и настройки, без управления подпиской |
| tenant_operator / tenant_viewer | Сотрудники клиента | /app | По матрице основного ТЗ |

Названия owner/admin в существующей `memberships.role` обозначают только tenant_owner/tenant_admin. Они не дают platform права. Platform staff хранится в отдельной таблице, имеет отдельный login/session guard. Staff одновременно может быть клиентом, но две sessions и permissions не объединяются. В P1 один сотрудник имеет одну platform role; гибридный custom RBAC LATER. Активных platform_owner может быть несколько, но нельзя отключить/понизить последнего.

## 2 Вход и каркас

Owner frontend — Vue3/TypeScript отдельный entrypoint в административном приложении, route `/owner`. Отдельный origin `control.<service-domain>` и `/platform-api/v1`; hostname — deployment setting, не зашит в бизнес-код. Customer app использует свой origin и `/api/v1`. Раздельные session cookies, host-only, Secure, HttpOnly, SameSite. Нет общего cookie Domain для двух зон. Backend может быть тем же Laravel монолитом с отдельными routes/guards и pools доступа к БД.

MFA обязательна; после login до MFA защищённые страницы недоступны. Idle timeout30мин, абсолютный срок session8ч — proposed defaults в config. Для финансовых mutations, смены роли, overrides, публикации тарифов и platform config step-up не старше10мин. Отдельный recovery runbook, без публичной self-registration владельца. Bootstrap owner создаётся безопасной локальной командой с audit; пароль не задаётся seed фикстурой в production.

Каркас: header с environment LIVE/TEST, freshness, user/MFA state; sidebar: обзор, клиенты, подписки, оплаты, тарифы, сервис, сотрудники, аудит, настройки. Global search по tenant/store/subscription/invoice IDs и verified email клиента. Search scoped policy; passwords/secrets/карточные номера не ищутся. Внешние provider links показывают корректный LIVE/TEST account.

Все таблицы: cursor pagination50/max100, сортировка server-side whitelist, filters сохраняются в querystring без PII, loading/empty/stale/error states, timezone и currency labels. Owner actions доступны через backend permissions; скрытая кнопка не является защитой. Экспорт async с audit и short-lived private link.

## 3 Обзор бизнеса

Период default последние30д, timezone UTC для platform reports, можно выбрать IANA; comparison previous equal duration. Owner видит active paid tenants, trial tenants, past_due, scheduled cancellations, confirmed subscription revenue, refunds, churn и onboarding funnel. Переключатель LIVE/TEST по умолчанию LIVE; test tenants/data исключены из коммерческих KPI.

| KPI | Определение | Что нельзя включать |
|---|---|---|
| Active paying tenants | Unique tenant с active paid contract и действующим оплачиваемым period | Trials, complimentary overrides, pending checkout |
| Contracted MRR | Сумма фиксированной recurring цены после recurring discount без налогов; annual amount /12 | One-off charges, usage fees, trials; не равно collected cash |
| Successful subscription collections | Успешные оплаты наших invoices в выбранном interval | Checkout redirect, failed attempts, деньги магазинов |
| Refunds | Успешные возвраты именно нашей услуги | Mere refund requests, bookkeeping credits |
| Collected cash after refunds | Successful collections − successful refunds, по occurred_at каждой операции | Не bank payout/net fees и не бухгалтерская прибыль |
| Paid churn | Завершившиеся paid contracts / active paid at start | Scheduled cancel до конца period, trial expiry |
| Trial conversion | Trials выбранного cohort, ставшие paid за defined conversion window | Без denominator definition и зрелого cohort |

MRR считать decimal/rational без float; округлять только отображение, возвращать numerator/denominator при дробных minor units. Разные валюты отдельными карточками и series; глобальный total в EUR не делать без утверждённого FX источника. Отрицательный net cash period допустим при refund. Отсутствие свежих billing sync — «Данные неполные» и last_synced_at, а не выручка0.

Графики полезны только рядом с точными численными definitions: paid subscriptions динамика, collected/refunds по дням, checkout→confirmed payment. Low-volume percentage выводить вместе с counts. Каждый KPI clickable до списка документов, из которых посчитан. Cost/margin KPI LATER до появления реального cost ingestion, не выдумывать расходы по concurrency.

## 4 Клиенты и карточка организации

Список columns: tenant ID/name, owner contact masked для support roles, created, plan/version, billing/access states отдельно, paid_until, next billing date, active stores/limit, coverage summary, last activity, unresolved incidents count, commercial notes. Filters: state,plan,currency,created interval,trial expiry, overdue, monitoring health. «Заблокирован за неоплату» отличается от «Заблокирован вручную».

Карточка tabs: overview; stores/health; subscription; invoices/payments; members; support cases/actions; platform audit. Owner читает коммерческие данные. Детальные магазинные findings/screenshots открываются только с действующим support grant; platform_owner не превращается автоматически в tenant_owner. Provider secrets никогда не показываются даже владельцу SaaS.

Действия: suspend/resume service, temporary entitlement override, extend trial, send billing reminder, cancel at period end через billing adapter, start billing refresh. Каждое действие имеет reason, preview affected stores/access/dates и audit. Suspension не отменяет recurring платежи без отдельного cancellation действия. Resume не ставит invoice paid и не меняет provider subscription state. Если после снятия manual suspend есть past_due, доступ снова вычисляется policy, а не автоматически active.

Нельзя от имени клиента редактировать магазинный order/payment или незаметно сменить tenant owner. Передача владения tenant выполняется самим tenant_owner; спорный recovery владельца — документированная support процедура вне автоматических кнопок P1.

## 5 Подписки и тарифы

Owner управляет versioned plans: code immutable, title/description localized, availability draft/published/retired, plan version, entitlements, prices по currency и interval month/year, trial days, checkout availability. Стоимости публикуются только после выбора billing provider и проверенного provider price mapping; до этого fixture labels DEMO и payment disabled.

Plan version immutable после publish. Price менять созданием новой версии/нового price; действующих клиентов автоматически на неё не переводить. Таблица affected subscribers preview обязательна. Published price с subscriptions не удаляется, только retired для новых покупок. Public page никогда не показывает скрытый тариф. Откат publishing создаёт новую version/state transition, не переписывает оплаченные invoices.

Entitlements: max_stores≥1, browser_interval_seconds≥300, monthly_events_limit≥0, artifacts_retention_days, history_retention_days, supported_features enum. Хранить snapshot на договоре подписки. Временный override имеет starts_at/ends_at и reason, не изменяет базовый plan. На период override эффективный доступ явно показывается как complimentary/manual, не paid revenue.

Upgrade: preview provider proration и итоговый amount, подтвердить, затем новый доступ только по подтверждённому provider state/оплате. Downgrade по умолчанию на period_end; превышение нового max stores требует от tenant_owner выбрать retained stores, автоматического удаления нет. Если выбор не сделан — access reason capacity_choice_required, scheduled browser jobs pause до решения владельца организации. История и billing доступны, произвольный набор магазинов автоматически не выбирается.

## 6 Оплаты invoices refunds

Invoices list: tenant, provider invoice ID, number, document state, currency, subtotal/tax/discount/total/paid/due, period, issued/due/paid dates. Paid state не редактируется вручную. Не подменять provider documents самодельным legal invoice, пока issuer/tax/document rules не утверждены.

Payment attempts list: invoice, attempt ID, state pending/requires_action/succeeded/failed/cancelled, amount, timestamps, failure code sanitized. Card brand/last4 отображать только при необходимости через provider безопасный response; PAN/CVV не сохранять. Refund list: requested/pending/succeeded/failed, amount, reason, who requested, provider ref. Запрос refund ещё не refunded cash.

Owner refund P1 допускается только для оплаты нашей услуги: выбрать succeeded payment, preview refundable remainder, amount/currency, reason, step-up, confirmation. Backend idempotent provider operation, no duplicate refund on timeout; status unknown требует provider query. Политика доступа после refund отображается отдельно. Никаких магазинных refunds из этой панели.

Billing staff может подготовить refund request, но monetary submit требует platform_owner approval в P1. Для sole owner submit+approval объединены в один подтверждённый owner action; requester/approver fields сохраняются. Изменение этой политики требует explicit RBAC config и tests.

## 7 Эксплуатация сотрудники аудит

Owner service overview: API/DB/Redis health, inbox/outbox ages, billing webhook lag, browser slots/backlog, notification failures, sync lag, deployment versions, entitlement drift. Эти данные не заменяют детализацию /admin. Maintenance windows и worker limits config имеют bounded validation, effective_at, version и change log; не выполнять произвольную shell/SQL из UI.

Staff: invite role, activate/disable, role change, force session revoke, MFA reset через secure recovery. Invitation expires72ч, email verified, platform_owner только owner approval. Last-owner guard и preventing self-lockout обязательны. Нельзя grant platform role через tenant membership endpoint.

Platform audit отдельный от tenant `audit_log`: actor staff, action,target tenant/entity, before/after sanitized, request_id, reason, result, step-up context, time. Filters actor/action/target/date/result. Export audit same role permission. Deleting tenant не удаляет operational deletion journal и minimum retained platform audit по утверждённой policy.

## 8 Приёмка панели owner

OWN-01 tenant_owner не открывает /owner и platform API. OWN-02 last active platform_owner защищён. OWN-03 MFA+step-up gates работают. OWN-04 LIVE/TEST и валюты не смешиваются. OWN-05 MRR не включает trials, pending checkout и деньги магазинов. OWN-06 publishing price не меняет старую подписку. OWN-07 suspend/resume не фальсифицирует оплату и не отменяет billing автоматически. OWN-08 refund retry не удваивает возврат. OWN-09 provider secrets отсутствуют в UI/export/logs. OWN-10 KPI drill-down воспроизводит суммы. OWN-11 trial/entitlement override имеет expiry и audit. OWN-12 cross-tenant detailed evidence требует support grant.

Backend routes, schemas и таблицы описаны в `ADMIN-BACKEND.md` и `database/platform-billing-extension.sql`. Эта панель является ТЗ, не работающим интерфейсом.
