# ADR 0089: Product page with the item in the cart, and store server errors on the way

Date: 2026-10-29. Status: accepted (owner, 2026-10-29: «почему наша проверка её не нашла? — это серьёзный косяк»; both points approved).

## Context

On the first real Shopware store the product page answered 500 («Leider ist etwas schief gelaufen») as soon as that product was in the cart — in any session, any browser. A customer who returns to the product after adding it sees an error page. The check never saw it: every attempt starts with an empty cart and walks product → add to cart → cart → checkout, so the product page is only ever opened with an empty cart. Separately, a first-party 5xx on a request during a step was only written to diagnostics; the step passed when its elements were present.

## Decision

1. **New step `product_again`** after `add_to_cart` for every platform and checkout path: the product page is opened again with the product in the cart. A 4xx/5xx answer is a store failure (`product_page_fails_with_cart`); a page without the buy button or the sold-out notice is a store failure (`product_page_broken_with_cart`). Confirmed as any `site_failure`: by a second attempt.
2. **A failed page is a store failure, not «element not found».** When a step ends `inconclusive` (`selector_changed`, worker timeout) and the main page of that step answered 5xx, the step becomes `site_failure` with `page_http_<status>`.
3. **A first-party 5xx on a request (XHR/fetch) of a step that passes** adds a warning `store_server_error` (`http_<status>`) to the step; the cabinet shows it under the step and the request is in the step's network summary. The run still passes — no incident from a background request alone.

## Consequences

- One more page load per attempt (≈ 3–9 s); the 120 s attempt budget is unchanged.
- Existing incidents of type «checkout flow failed» now also name the step «product page with the item in the cart».
- Further typical customer actions (change quantity in the cart, back to shopping from the cart) are candidates for the next steps.
