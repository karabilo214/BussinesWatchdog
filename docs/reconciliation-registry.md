# Реестр контрольных финансовых примеров FIN-01…FIN-24

Источник примеров — `spec/examples/reconciliation-cases.json` (ТЗ §35.1). Проверка — `apps/backend/tests/Feature/Reconciliation/FinRegistryTest.php`: каждый пример проходит настоящий путь данных:

- заказ и возвраты магазина — события `order.snapshot` / `refund.snapshot` через `EventIngestor` от коннектора магазина;
- платёж — объекты Stripe через `StripeSync`; ответы Stripe подменены JSON-фикстурами, настоящий Stripe здесь не используется;
- `ExactReferenceMatcher`, `OrderReconciliationService` и `MoneyIncidentCorrelator`.

Из результата тест выводит то же, что лежит в примере:
- **итог**: ok / mismatch по правилу / pending / unknown / unsupported;
- **разницы** capture / refund / net по формулам ТЗ §12 — только из окончательных результатов (ok или mismatch);
- **опубликовано ли денежное расхождение** — активный денежный инцидент с суммой.

Пример со статусом «расхождение» тест держит в списке `DEVIATIONS`: если он начнёт совпадать с ТЗ, тест упадёт, и запись нужно убрать.

Срез: Step 201, 2026-10-20 (после исправлений ADR 0068).

| ID | Пример | Статус | Что делает система |
|---|---|---|---|
| FIN-01 | Оплата совпадает | совпадает | capture ok, разницы 0 |
| FIN-02 | Paid marker есть, capture нет | совпадает | MONEY_CAPTURE_MISSING, −18400, инцидент с суммой |
| FIN-03 | Частичный capture | совпадает | MONEY_CAPTURE_AMOUNT, −3400 |
| FIN-04 | Переплата | совпадает | MONEY_CAPTURE_AMOUNT, +1600 |
| FIN-05 | Частичный возврат подтверждён | совпадает | refund ok, разницы 0 |
| FIN-06 | Возврат не подтверждён | совпадает | MONEY_REFUND_MISSING, −10000, net +10000 |
| FIN-07 | Полный возврат | совпадает | refund ok |
| FIN-08 | Возврат у провайдера без возврата в магазине | совпадает | Не заявленная ни одним возвратом магазина часть возврата провайдера по платежу этого заказа входит в RP: MONEY_REFUND_EXTRA, +10000 |
| FIN-09 | Net совпал, но capture и refund ошибочны | совпадает | два расхождения: capture −5000 и refund −5000, net 0 |
| FIN-10 | Нулевая стоимость | совпадает | результатов нет, итог ok |
| FIN-11 | Pending неоплаченный заказ | совпадает | результатов нет, итог pending, суммы не публикуются |
| FIN-12 | Данные устарели | совпадает | unknown (`store_data_stale`) |
| FIN-13 | Capture в grace | совпадает | pending (`capture_missing_within_grace`) |
| FIN-14 | Gateway не поддерживается | совпадает | unsupported |
| FIN-15 | Невозможно связать transaction ID | совпадает | MONEY_PAYMENT_NOT_MATCHED: инцидент «внимание» без суммы, «платёж не сопоставлен» |
| FIN-16 | Authorization без capture | совпадает | авторизация не считается списанием, итог pending |
| FIN-17 | Ручной возврат с неизвестной отправкой | совпадает | unknown (`refund_external_unknown`), разницы не публикуются |
| FIN-18 | Повтор webhook не увеличивает capture | совпадает | повторная синхронизация не создаёт второй операции, capture 12500 |
| FIN-19 | Cancelled paid не значит возвращён | совпадает | MONEY_CANCELLED_PAID: информационный инцидент без суммы «проверьте возврат»; разницы 0 |
| FIN-20 | Total изменён после capture | совпадает | MONEY_ORDER_CHANGED без суммы; MONEY_CAPTURE_AMOUNT — unknown (`order_changed_after_capture`), capture −5000 как факт |
| FIN-21 | Валюты различаются | совпадает | только MONEY_CURRENCY_MISMATCH; MONEY_CAPTURE_MISSING — unknown (`currency_mismatch`), без суммы |
| FIN-22 | Две разные операции capture | совпадает | MONEY_MULTIPLE_CAPTURES, C = 20000 (дополнительно MONEY_CAPTURE_AMOUNT +10000) |
| FIN-23 | Большая сумма без потери точности | совпадает | 9007199254740993 без потерь |
| FIN-24 | JPY, exponent 0 | совпадает | 184 JPY, разницы 0 |

Итог: 24 из 24 совпадают с ТЗ (Step 200 нашёл 6 расхождений, Step 201 исправил их по ADR 0068).

Не покрыто этим реестром: PayPal и Shopware (у них свои сквозные тесты, Steps 155–156), гонки параллельных транзакций на PostgreSQL (`ReconciliationAtScaleTest`, `PaymentAllocationServiceTest`), обязательства §43.
