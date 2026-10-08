# ТЗ кабинетов владельца и администратора клиента

Дополнение1.1. Эти кабинеты обязательны и дополняют раздел24 основного ТЗ. Tenant_owner и tenant_admin работают в `/app` со своими магазинами. Название owner не означает владельца SaaS. Панели platform_owner/platform_admin описаны отдельно.

## 1 Общий интерфейс

Vue3/TypeScript, shared component library для tenant интерфейсов. `/app`: overview, stores, incidents, reconciliation, checks, integrations, notifications, team, support, billing, settings, profile. Workspace switcher показывает только membership tenants. Business data queries явно привязаны к активному tenant. При switch cancel pending queries, очистить кеш текущего workspace, не показывать предыдущие данные на loading экране.

Customer guard + Sanctum same-origin session, no platform cookie. Customer API `/api/v1`, CSS/route скрытие не заменяет role policy. Header active organization, role, monitoring coverage, billing warning, timezone. Unpaid warning не должен закрывать доступ к истории и странице оплаты. Side nav billing только owner; tenant_admin может видеть service status/limits без суммы счетов и payment details.

UI states: loading skeleton; not_connected onboarding; warming_up; healthy with fresh coverage; incidents; degraded; paused_manual; paused_billing; unsupported. Полный outage и неподдерживаемый gateway имеют разные тексты. Error показан с request_id, retry сохраняет фильтры. Desktop1200+, tablet768, mobile360; readable tables с explicit horizontal scroll/card layout, без обрезанных денег/currency.

## 2 Первый вход владельца клиента

1. Confirm email, organization name/timezone/locale; зарегистрированный пользователь без organization создаёт tenant.
2. Trial или paid access определяется backend; каталог тарифов и checkout — `PUBLIC-SUBSCRIPTION-FRONTEND.md`.
3. Add store, verify public origin, install plugin и pairing code, capabilities/backfill health.
4. Определить тестовый товар/checkout adapter, включить проверку с описанными безопасными границами.
5. Для независимой сверки отдельно подключить поддерживаемый payment source. Отсутствие источника явно показывать.
6. Настроить email/Telegram; verified channel и test message.
7. Overview отображает «Готово» только для реально завершённых checks и sources; неполная история не становится sales baseline.

Onboarding resumable server state; закрытие browser не теряет pairing/backfill. Нельзя делать paid checkout обязательным до ознакомления с pricing и limitations. Trial eligibility computed server-side, не отдельная trial для каждого email одного tenant; антиабьюз policy не отключает легитимного paid клиента.

## 3 Экраны мониторинга для обеих ролей

| Экран | Данные | Действия owner/admin |
|---|---|---|
| Overview | Active incidents, sources coverage, recent checks, discrepancy per currency | Open incident/recheck по правам |
| Stores | Domain, setup/health, limits, adapter | Add/configure/pause; нельзя удалить финансовую историю неявно |
| Store detail | Connections, test coverage, last runs, onboarding gaps | Pair/reconnect, safe scenario setup |
| Incidents | State, severity, last_good/first_bad, facts | Acknowledge/comment/recheck/snooze по policy |
| Reconciliation | G/C/RW/RP, pending/unknown/mismatch | Manual link с audit, export |
| Checks | Logical runs/attempts/steps/artifacts | Safe manual run/cancel |
| Integrations | Capabilities, mode, sync/lag, credential suffix | Connect/revoke/rotate без display secret |
| Notifications | Verified channels, quiet hours, health | Configure/test |

Секреты при создании/вводе не уходят в frontend state persistence. API key и HMAC не сохраняются в Pinia/localStorage, analytics/events и console. Независимое payment connection read-only; user contract billing separately.

## 4 Team и полномочия

Tenant_owner: все tenant functions, subscription/payment documents, manage owner transfer, privacy export/delete organization. Tenant_admin: monitoring/configuration, invitations admin/operator/viewer; не может создать owner, изменить владельца, изменить billing subscription, delete tenant. Operator/viewer — existing matrix.

Admin не понижает/удаляет tenant_owner, не повышает себя до owner. Owner transfer target — existing verified member, step-up, atomically preserve at least one owner. Invitations TTL72ч, token hash, email bound. Removed member session loses tenant scope immediately. Не позволять читать organization billing по известному invoice UUID, даже если /billing скрыт.

## 5 Billing владельца клиента

Route `/app/billing`: текущий plan/version, amount/currency/interval, LIVE paid status, paid_until, renewal time, cancel_at_period_end, applicable taxes/discounts из provider invoice/preview, trial/grace dates, effective limits and usage. Billing status и actual access state отдельны.

Tabs invoices, payment attempts, plan change, billing profile. Owner видит provider-hosted payment method portal; наша форма не запрашивает PAN/CVV. Documents private, foreign tenant403/404. «Скачать счёт» — настоящий provider/accounting document, не arbitrary PDF с названием Rechnung.

Actions: buy/renew через hosted checkout, manage method через provider portal, upgrade preview/confirm, downgrade at period end, cancel at period end, resume scheduled cancel если provider supports. Unsupported provider action disabled с объяснением. Повторная кнопка retry возвращает existing checkout/action. После возврата от provider «Подтверждаем платёж» до verified backend state, а не активная подписка по querystring.

Если max stores уменьшается, owner выбирает retained store IDs перед effective_at. При отсутствии выбора — `capacity_choice_required`, scheduled browser jobs pause, история и setup доступны, stores не удаляются. При этом invoices и контракт продления не переписываются. Source ingestion limits продолжают действовать по entitlement policy и отображаются явно.

Past_due grace proposed7д: banner due date+safe payment action. После grace paused_billing, сохраняется кабинет/history/payment/support. Renew после pause запускает восстановление scheduled jobs/backfill в quota limits, не создаёт storm historical alerts. Trial по окончании без оплаты не даёт permanent monitoring access.

## 6 Support и privacy

Customer support tab содержит grant requests: staff identity, reason, requested scopes, expires_at. Только tenant_owner approves/revokes. Approval не даёт сотруднику редактировать деньги клиента. List current grants and accessed resources history, без secrets. Платёжная поддержка может работать с billing summary без granted shop evidence.

Tenant privacy export/deletion через owner step-up; admin403. Остановка мониторинга, cancellation подписки и deletion organization — три отдельных действия с понятными consequences. Отключение подписки не удаляет историю, удаление organization не гарантирует автоматическое прекращение provider renewals: workflow должен сначала отменить наш paid contract либо явно показать pending cancellation и не завершать purge, пока она не согласована.

## 7 Frontend module boundaries

Feature folders: workspace/auth, onboarding, stores, incidents, reconciliation, checks, integrations, notifications, team, billing, support. Shared UI controls reusable, authorization logic серверная. Query cache keys include tenant_id+filters+resource version; sensitive query persists only in memory. Money formatter принимает string+currency+exponent; float parse запрещён.

Locale strings `ru`, `en`, `de` обязательны для всех customer surfaces. Date formatting использует выбранную timezone, source UTC accessible; long IDs copyable. Empty reconciliation без independent provider содержит connect CTA, не «деньги сошлись».

## 8 Приёмка

CLT-01 tenant_admin может настроить проверку своего магазина, но не оплату/ownership/delete. CLT-02 billing UUID другого tenant недоступен. CLT-03 switch workspace не показывает прежний кеш. CLT-04 onboarding продолжает incomplete backfill. CLT-05 unknown coverage не отображается healthy. CLT-06 sums точные >2^53. CLT-07 support grant approval/revoke работает. CLT-08 after trial/grace история и payment остаются доступны. CLT-09 downgrade сохраняет данные и требует store choice. CLT-10 recovered subscription не рассылает historical storms. CLT-11 role removal effective immediately. CLT-12 clear ownership of /app vs /owner vs /admin.
