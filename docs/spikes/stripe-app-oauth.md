> **Withdrawn 2026-10-19 (Step 185).** Не доступно для Украины: без зарегистрированного бизнеса в Германии Stripe не даёт завершить настройку аккаунта платформы, поэтому External test и авторизация клиента не работают. Код удалён (был в коммите `db0bd13`); если будет зарегистрированная компания в Германии, можно вернуться к этому пункту.

# Stripe App OAuth read-only spike (2026-10-16, ADR 0020)

## Conclusion

The old Connect OAuth `read_only` scope is limited to legacy Extensions. Stripe says new Connect Extensions cannot be created. A new Business Watchdog integration must use **Stripe Apps OAuth v2** with app permissions `payment_intent_read` and `charge_read` (the latter covers Charges and Refunds). The app must be public to install it on customer accounts. A data integration app can omit Stripe Dashboard UI views. The existing manually entered restricted key remains a fallback.

This conclusion is based on current Stripe documentation, not a live install. Stripe must still approve and publish the platform's app before the public install link works. The owner has access to Developers → Apps; app creation, credentials, external test and review require that account.

Primary sources:

- [Connect OAuth reference](https://docs.stripe.com/connect/oauth-reference): `read_only` only for Extensions.
- [Legacy Connect Extensions](https://docs.stripe.com/building-extensions): deprecated; new Extensions unavailable.
- [Stripe Apps OAuth](https://docs.stripe.com/stripe-apps/api-authentication/oauth): install link, state, one-use code, one-hour access token, rotating one-year refresh token.
- [Stripe App permissions](https://docs.stripe.com/stripe-apps/reference/permissions): `payment_intent_read`, `charge_read` (Charges and Refunds).
- [Publish a Stripe App](https://docs.stripe.com/stripe-apps/publish-app): public data integration, review and public install link.

## Platform setup and live gate

1. In the platform Stripe account, create a **public Stripe App** with OAuth API access, no UI extension, and only `payment_intent_read` and `charge_read`. Use a verified HTTPS callback `https://<service-host>/api/v1/stripe-app/callback`. Enable sandbox installation if the app will be tested in a sandbox. The app's manifest and 300×300 PNG icon are platform-owned release artifacts; Stripe validates and uploads them through its CLI/Dashboard.
2. Upload an external test version. Copy its test OAuth install link and client ID; configure `WATCHDOG_STRIPE_APP_INSTALL_URL_TEST`, `WATCHDOG_STRIPE_APP_CLIENT_ID_TEST`, `WATCHDOG_STRIPE_APP_SECRET_KEY_TEST`, and `WATCHDOG_STRIPE_APP_REDIRECT_URI` in the backend secret environment. The secret key is the **platform's** test key, never a merchant key. The backend only sends it to Stripe's token endpoint. For live mode set the matching `_LIVE` values from the published app. Do not commit any keys.
3. Install the test app from a different Stripe test account, start from a verified Business Watchdog store, and confirm: account ID, mode, read probes for Payment Intents/Charges/Refunds, an audit sync, token refresh, revoke, and a failed/replayed callback. Stripe's external test link is for testing only; after approval use the public install link from App Settings.
4. Until the app is configured, the one-click button answers `stripe_app_unconfigured` and the restricted key form continues to work. Do not mark the backlog item commercially complete until the real install and approval gates pass.

## Implementation boundary

`StripeAppOAuth` stores a hash of the 10-minute, one-use state tied to tenant/store/actor/mode. The public callback consumes it, exchanges the code server-side, requires scope `stripe_apps`, checks `livemode` and the returned account, and probes only the three GET resources before creating an integration. Access and refresh tokens are encrypted in `stripe_app_oauth_connections`; the polling worker refreshes them under a row lock and replaces both tokens together. A revoked integration deletes local token material. Polling remains the guarantee; merchant webhook setup is optional.

No payment creation, capture, refund or other Stripe write call is added to the connector. The merchant restricted-key path still rejects `sk_` keys.
