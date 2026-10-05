# External services inventory

Verified audit of every external (third-party) service the application talks to,
so the v1.0 privacy policy (issue #49) and public copy can describe the actual
services and the data each receives. Produced for issue #47 from the correction
recorded on #28 and #30.

Any automatic HTTPS request inherently discloses the visitor's IP address,
user agent, and the requested URL (page URL / referrer) to the host serving it.
The rows below list what each service receives *beyond* that baseline.

Pinned by `tests/Privacy/ExternalServicesTest.php`, which scans Twig
templates, the reCAPTCHA Stimulus controller, and the three transactional
email templates: adding a new automatic third-party request or outbound
link there fails the suite until this inventory documents it. It does not
scan other JavaScript, backend/PHP-issued HTTP calls, form actions, or CSS
`@import`. Verified against the rendered public home, pricing, and contact
pages, plus an authenticated render of the signed-in user menu (the only
place account data reaches a template URL context; a full signed-in page
render needs a database and so stays out of the blocking suite).

## Automatic requests (fire on page view)

| Service | Where it loads | What it receives |
|---|---|---|
| Self-hosted Umami analytics (`umami.rum.luka.sh`, on our own server; website id set via `UMAMI_WEBSITE_ID`) | Every page via `templates/_analytics.html.twig`, included by `templates/base.html.twig` and the standalone `templates/maintenance.html.twig`; not rendered when `UMAMI_WEBSITE_ID` is empty | Cookieless page-view beacons: page URL, referrer, device/browser metadata. No personal data, and nothing leaves our own server. Replaced Umami Cloud, see ADR 0004. |
| Google Fonts (`fonts.googleapis.com`, `fonts.gstatic.com`) | Every page via `templates/base.html.twig` (preconnect + `Plus Jakarta Sans` / `Patrick Hand` stylesheet) | Font CSS/file fetches tied to the visited page URL. |
| Font Awesome CSS via Cloudflare CDN (`cdnjs.cloudflare.com`, 6.5.2) | Every page via `templates/base.html.twig` | Stylesheet fetch tied to the visited page URL. No Font Awesome JS kit. |
| Google reCAPTCHA v3 (`www.google.com/recaptcha/api.js`) | Contact page and the site-wide footer signup form: injected by `assets/controllers/recaptcha_controller.js` when either form connects | Risk-analysis signals (cookies Google holds, page URL, site key) on form view. On submit, the server posts the one-time token plus the visitor's IP to `siteverify`; the visitor's name, email, subject, and message are **not** sent to Google. |

## On-action disclosures (only when the visitor acts)

| Service | Where | What it receives |
|---|---|---|
| Mail delivery via Symfony Mailer (`MAILER_DSN`; production SES-compatible, development Mailcatcher, `null://` in tests) | Registration verification, password reset, and contact-form-to-operator emails | Recipient address, sender address, subject, and message body by design. Email templates contain no external URLs or tracking pixels. |
| Newsletter via AMQP `mailing` exchange to the shared `gprodb.com` list | Footer form on every page (`app_newsletter_subscribe` → `MailingSubscribe` message) | Subscriber email plus the mailing project id and routing key. Separate opt-in with its own unsubscribe path; self-service account deletion does not touch it. |
| BuyMeACoffee support link (`www.buymeacoffee.com/lbacik`) | Pricing page only, plain anchor with `rel="noopener noreferrer"` (no referrer sent) | Nothing until clicked; click-through reveals the visit to BuyMeACoffee. The former `cdn.buymeacoffee.com` button image was removed in #47 so the pricing page makes no automatic request there. |

## Explicitly not sent anywhere

- **Avatars.** The signed-in menu used to embed the raw User email in a
  `robohash.org` image URL, disclosing it (plus IP/user agent/referrer) to that
  third party on every signed-in page view. Removed in #47; the menu now renders
  a local initial with no external request.
- **Bank or inbox data.** There is none to disclose: the product is manual-first
  with no bank connection, inbox scan, or provider cancellation.
- **Structured error logging.** Self-hosted Elasticsearch logging is planned
  (issue #50) but not implemented: no bundle, client, DSN, or error payload
  leaves the application today.
