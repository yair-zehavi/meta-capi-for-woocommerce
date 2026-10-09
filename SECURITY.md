# Security

## Threat model

The plugin runs inside WordPress, reads request data (headers, cookies, query string) and posts events to one fixed host, `graph.facebook.com`. It has no admin form, no custom endpoint and no REST route. Nothing in it accepts input that changes where data is sent.

Risks considered:

- Spoofed request data ending up in events sent to Meta.
- Customer data leaving the site in clear text.
- Tracking running without the site owner's intent or consent.
- A failure in the plugin breaking the store's checkout.
- Credentials committed to source control.

## What is validated or escaped

| Area | Behaviour |
| --- | --- |
| Event source URL | Built from `home_url()` plus the sanitized request path and query, never from `HTTP_HOST`. Order events use the checkout page URL, not a transport endpoint such as `wc-ajax`. |
| Client IP | `REMOTE_ADDR` by default. `CF-Connecting-IP` and `X-Forwarded-For` are read only if `MCAPI_TRUST_PROXY_HEADERS` is true or the `mcapi_trust_proxy_headers` filter returns true. Every candidate passes `FILTER_VALIDATE_IP`. |
| User agent | Control characters stripped, truncated to 512 characters. |
| Cookies and query values | `_fbp`, `_fbc`, the first-party id cookie and `fbclid` pass through `sanitize_text_field`. |
| Personal data | Email, phone, names, city, state, postcode and country are normalized and SHA-256 hashed before sending. Invalid emails are dropped. |
| Browser pixel markup | Pixel ID is escaped with `esc_js` in the script and built with `add_query_arg` plus `esc_url` for the noscript image URL. |
| Credentials | Pixel ID, token and test code come from WordPress options or wp-config constants. Nothing is hardcoded. Options are not exposed through the REST settings endpoint. |
| Consent | All tracking passes through the `mcapi_has_consent` filter. The default is true, so sites that need consent gating must hook it. |
| Admin notices | Shown only to users with `manage_options`. |
| Failure isolation | Every handler is wrapped in try/catch so an error in the plugin cannot interrupt cart, checkout or payment. |
| Outbound request | Fixed host, JSON body, short timeout, non-blocking when the response could not be flushed first. |

## Known gaps

- Consent defaults to allowed. Operators in regions that require opt-in must connect their consent tool through the filter.
- The access token is stored in the options table in clear text if you use options. Prefer wp-config constants.
- A bot check is a user-agent pattern list, not bot detection.

## Reporting a vulnerability

Open a private security advisory on the repository, or contact the author directly. Please do not file public issues for vulnerabilities.
