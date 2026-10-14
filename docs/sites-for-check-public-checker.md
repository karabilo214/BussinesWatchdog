5 магазинов на WooCommerce
Все пять присутствуют в официальном каталоге WooCommerce; в коде каждого также обнаружены ресурсы wp-content/plugins/woocommerce. WooCommerce
№	Магазин	Сайт	Что продают
1	Thompson + Hanson	thompsonhanson.com	Товары для дома и сада
2	Aunt Fannie’s	auntfannies.com	Средства для уборки
3	Eames Office	eamesoffice.com	Дизайнерские товары, книги и аксессуары
4	Kinfolk	kinfolk.com	Журналы, книги и подписки
5	Burrow Press	burrowpress.com	Книги


5 магазинов на Shopware
Первые четыре указаны в официальном каталоге Shopware, а их текущий код содержит признаки Shopware. У BUS используется собственный фронтенд, который обращается к Shopware Store API. Shopware
№	Магазин	Сайт	Подтверждение при проверке
6	MissPompadour	misspompadour.com	Shopware и ресурсы /bundles/storefront/
7	Villa Schmidt	villa-schmidt.de	Shopware и маршрут frontend.detail.page
8	BUS Industrial Tools	bus.nl	Конфигурация Shopware и адрес /store-api/
9	Christopeit Sport	christopeit-sport.com	Shopware в коде витрины
10	Holzprofi24	holzprofi24.de	Shopware и маршрут frontend.detail.page


Для Holzprofi24 есть и дополнительное подтверждение от самого магазина: в опубликованной вакансии разработчика прямо указана работа с Shopware 5/6. static.holzprofi24.de
10 магазинов на другой платформе — Shopify
Все десять ниже используют Shopify, поэтому подходят под условие «не WooCommerce и не Shopware». В таблице указаны конкретные признаки, обнаруженные в текущем коде сайтов.
№	Магазин	Сайт	Подтверждение при проверке
11	Gymshark	gymshark.com	Домен магазина gymsharkusa.myshopify.com
12	Allbirds	allbirds.com	Shopify.shop и ресурсы Shopify
13	Huel — британский магазин	uk.huel.com	Конфигурация магазина hibble.myshopify.com
14	Fashion Nova	fashionnova.com	Shopify Hydrogen и fnova.myshopify.com
15	ColourPop	colourpop.com	Shopify.shop и ресурсы Shopify
16	Brooklinen	brooklinen.com	Shopify.shop и ресурсы Shopify
17	Ruggable	ruggable.com	Домен магазина ruggable.myshopify.com
18	Death Wish Coffee	deathwishcoffee.com	Shopify.shop и ресурсы Shopify
19	Chubbies	chubbiesshorts.com	Shopify Hydrogen и chubbies.myshopify.com
20	Taylor Stitch	taylorstitch.com	Shopify.shop и ресурсы Shopify


## Прогон через public checker

Проверка 2026-10-14 через Playwright/Chrome и детектор `public-storefront@1`: 18 из 20 URL определились ожидаемо.

| Сайт | Ожидание | Результат | Статус |
| --- | --- | --- | --- |
| thompsonhanson.com | WooCommerce | WooCommerce | OK |
| auntfannies.com | WooCommerce | нет результата | таймаут загрузки страницы в браузере |
| eamesoffice.com | WooCommerce | unknown | HTTP 403 от сайта |
| kinfolk.com | WooCommerce | WooCommerce | OK |
| burrowpress.com | WooCommerce | WooCommerce | OK |
| misspompadour.com | Shopware | Shopware | OK |
| villa-schmidt.de | Shopware | Shopware | OK |
| bus.nl | Shopware | Shopware | OK |
| christopeit-sport.com | Shopware | Shopware | OK |
| holzprofi24.de | Shopware | Shopware | OK |
| gymshark.com | unknown | unknown | OK |
| allbirds.com | unknown | unknown | OK |
| uk.huel.com | unknown | unknown | OK |
| fashionnova.com | unknown | unknown | OK |
| colourpop.com | unknown | unknown | OK |
| brooklinen.com | unknown | unknown | OK |
| ruggable.com | unknown | unknown | OK |
| deathwishcoffee.com | unknown | unknown | OK |
| chubbiesshorts.com | unknown | unknown | OK |
| taylorstitch.com | unknown | unknown | OK |

Aunt Fannie's и Eames Office лучше не использовать как обязательные контрольные URL в автотесте: текущая публичная проверка должна честно показывать blocked/error для таких сайтов, а не угадывать платформу по недоступной странице.
