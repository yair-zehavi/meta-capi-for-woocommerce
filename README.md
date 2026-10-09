# Meta CAPI for WooCommerce

![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![WordPress](https://img.shields.io/badge/WordPress-WooCommerce-21759b.svg)

A single-file WordPress plugin that sends WooCommerce purchase and shopping events to Meta's Conversions API from the server, so ad optimization still sees sales that the browser pixel misses.

## Why it matters

- Ad blockers and browser privacy settings stop the pixel from firing. A server-side event does not depend on the visitor's browser.
- Many payment gateways complete the order in a background callback, after the shopper has left. At that moment there is no browser, no user agent and no cookies. This plugin saves the shopper's browser details at checkout and replays them on the Purchase event.
- Meta rejects or discards events that send an IP address without a matching user agent. The plugin sends the two together from the same request, or neither.
- Each Purchase is sent once per order, even when WooCommerce fires several hooks for the same sale.

## How it works

### Event flow

| Event | Trigger |
| --- | --- |
| ViewContent | Single product page |
| Search | Search results page |
| AddToCart | `woocommerce_add_to_cart` |
| InitiateCheckout | `woocommerce_before_checkout_form` |
| AddPaymentInfo | Order submitted at checkout (an approximation, since no server hook exists for this step) |
| Purchase | `woocommerce_payment_complete` or `woocommerce_thankyou`, whichever comes first |

Events are queued during the request and posted to the Graph API on `shutdown`. If PHP-FPM is available, the response is flushed to the visitor first. Otherwise the post is non-blocking, so page load is not delayed. Every handler is wrapped in try/catch, so a plugin error cannot interrupt the cart or checkout.

PageView is not sent by the server. An optional minimal browser pixel (`MCAPI_BROWSER_PIXEL`) renders PageView only.

### Deduplication

Every event carries an `event_id`. Purchase uses `purchase.<order id>`. If you also fire a browser event for the same action, pass the same value as `eventID` and Meta counts it once. On the server side, a meta flag on the order stops a second Purchase when both the payment-complete and thank-you hooks run.

### Gateway-callback snapshot

At checkout (classic and Store API), the plugin stores the shopper's user agent, IP, `_fbp`, `_fbc`, first-party id and page URL on the order. When Purchase fires later from a gateway callback or cron job, the plugin uses that snapshot instead of the callback's own request. It also reports the checkout page as the event URL, not the callback endpoint.

### Other behaviour visible in the code

- The click id (`fbclid`) is saved in a 90-day first-party cookie, since it is gone from the URL by checkout.
- A first-party id cookie provides a stable `external_id`; the customer id is added when known.
- Order events send hashed email, phone, name, city, state, postcode and country. Logged-in users and guests with an email in the session get hashed data on earlier events too.
- Requests that look like crawlers or have no user agent are dropped, except events backed by an order.

## Install

1. Copy `meta-capi-for-woocommerce.php` into `wp-content/plugins/meta-capi-for-woocommerce/`.
2. Activate it in the WordPress admin. WooCommerce must be active.
3. Add your settings (below). The plugin does nothing until a pixel ID is set.

Do not run it next to another plugin that sends the same server events, or they will be counted twice unless their event IDs match.

## Configuration

Set values in `wp-config.php` (preferred for the token) or as WordPress options. Constants win over options.

```php
define( 'MCAPI_PIXEL_ID',   'YOUR_PIXEL_ID' );
define( 'MCAPI_CAPI_TOKEN', 'YOUR_ACCESS_TOKEN' );
define( 'MCAPI_TEST_CODE',  'YOUR_TEST_EVENT_CODE' ); // optional
```

With WP-CLI instead:

```
wp option update mcapi_pixel_id YOUR_PIXEL_ID
wp option update mcapi_capi_token YOUR_ACCESS_TOKEN
```

Optional constants:

| Constant | Default | Purpose |
| --- | --- | --- |
| `MCAPI_TRUST_PROXY_HEADERS` | `false` | Read `CF-Connecting-IP` / `X-Forwarded-For`. Enable only behind a trusted proxy or CDN. |
| `MCAPI_BROWSER_PIXEL` | `false` | Render a minimal browser pixel with PageView. |
| `MCAPI_PHONE_COUNTRY_CODE` | empty | Digits only. Rewrites a leading 0 in phone numbers to this country code before hashing. |

Filters: `mcapi_has_consent`, `mcapi_is_bot`, `mcapi_trust_proxy_headers`, `mcapi_event`.

## Verifying in Meta Events Manager

1. Create a test event code in Events Manager, under Test Events.
2. Set `MCAPI_TEST_CODE` (or the `mcapi_test_code` option). The admin shows a notice while test mode is on.
3. Browse a product, add to cart, go to checkout and place a test order. Events appear in the Test Events tab with their event IDs.
4. Remove the test code. Until you do, events go to the test tab only.
5. Turn on `WP_DEBUG` if you need send errors written to the PHP error log.

## Security notes

See [SECURITY.md](SECURITY.md). In short: the outbound host is fixed, the event URL is built from the site's own `home_url()`, proxy IP headers are ignored unless you opt in, personal data is hashed with SHA-256 before it leaves the server, and secrets are never hardcoded.

## Tests

`tests/test-harness.php` mocks WordPress and WooCommerce, fires each hook and checks the captured payloads without any network call. It covers load order, one Purchase per order, hashed data, IP and user agent pairing, the gateway-callback case, bot filtering and `fbc` format.

```
php tests/test-harness.php
```

It exits with code 0 when all checks pass.

## Limitations

- Consent defaults to allowed. If your market requires opt-in, connect your consent tool through the `mcapi_has_consent` filter.
- No settings screen. Configuration is by constants or options.
- WooCommerce only. Form leads and other plugins are not covered.
- The bot check is a user-agent pattern list.
- The test harness uses mocks, so it does not test against a live WordPress or the live Meta API.
- Graph API version is pinned in the code (`v22.0`) and needs a manual bump later.
