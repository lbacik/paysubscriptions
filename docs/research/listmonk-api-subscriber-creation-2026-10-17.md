# listmonk API facts for subscriber creation (issue #223, map #221)

Verified 2026-10-17 against listmonk **v6.2.0** (latest release at that date; `gh api repos/knadh/listmonk/releases/latest`). Source code facts were read from the default branch (`master`) of https://github.com/knadh/listmonk, which matched v6.2.0 as the latest release. Docs pages are unversioned and track master; where a feature has a minimum version the page says so. Only first-party sources are used: listmonk.app/docs and the knadh/listmonk repo. Items marked **(code)** come from source, not prose docs.

## 1. Create-subscriber endpoint

`POST /api/subscribers` (auth required, permission `subscribers:manage`; route in `cmd/handlers.go`).

Body (JSON), per https://listmonk.app/docs/apis/subscribers/ :

| field | type | notes |
|---|---|---|
| `email` | string | required |
| `name` | string | docs say required; **(code)** if empty, listmonk derives a name from the email local-part (`ValidateFields`, `internal/subimporter/importer.go`) |
| `status` | string | docs say required; values `enabled` or `blocklisted`. **(code)** empty defaults to `enabled` (`InsertSubscriber`, `internal/core/subscribers.go`) |
| `lists` | number[] | list **IDs** to subscribe to; optional |
| `attribs` | JSON object | optional |
| `preconfirm_subscriptions` | bool | optional; "If true, subscriptions are marked as confirmed and no opt-in emails are sent for double opt-in lists." |

Response: HTTP 200, `{"data": {id, uuid, email, name, attribs, status, lists:[...], created_at, updated_at}}`. The subscriber `uuid` is generated server-side.

**(code)** Email is lowercased and sanitized; invalid email or a domain on the configured block/allow list gives HTTP 400. If `lists` is non-empty but none is permitted for the API user, HTTP 403.

Example (docs): `curl -u 'api_username:access_token' http://localhost:9000/api/subscribers -H 'Content-Type: application/json' --data '{"email":"...","name":"...","status":"enabled","lists":[1]}'`

## 2. Duplicate email -> 409

The docs page does not document this. **(code)** `Core.InsertSubscriber` maps a violation of the `subscribers_email_key` unique constraint to `echo.NewHTTPError(http.StatusConflict, i18n "subscribers.emailExists")`, i.e. **HTTP 409** with message `"E-mail already exists."` (`i18n/en.json`; the text is localised by the instance language). Other DB errors give 500. Source: https://github.com/knadh/listmonk/blob/master/internal/core/subscribers.go (function `InsertSubscriber`). The error body is the standard echo shape `{"message": "..."}`. Consequence: the handler must treat 409 as "already a subscriber" (idempotent success or a lookup/update path), not retry it; match on status code, not message text. Note a duplicate is detected even if the existing subscriber is blocklisted; the POST does not add the existing subscriber to the requested lists.

## 3. `unconfirmed` vs `preconfirm_subscriptions`

Two distinct statuses:

- **Subscriber status** (`status` in the body): `enabled` / `blocklisted`.
- **Subscription status** (per subscriber-list pair): `unconfirmed` / `confirmed` / `unsubscribed`.

**(code)** `InsertSubscriber` and `queries/subscribers.sql` (`insert-subscriber`): every created subscription gets `unconfirmed`, unless `preconfirm_subscriptions=true`, in which case `confirmed`. This applies to every listed list regardless of whether the list is single or double opt-in. If the subscriber `status` is `blocklisted`, the subscription is `unsubscribed`. The API responses in https://listmonk.app/docs/apis/subscribers/ show `subscription_status: "unconfirmed"` for new subscriptions.

Opt-in mail: **(code)** when `preconfirm` is false and the instance has opt-in confirmation sending enabled, listmonk sends the opt-in email (for double opt-in lists) as part of the create call. When `preconfirm` is true, none is sent (docs quote above).

Implication for our flow: for a double opt-in list, omitting `preconfirm_subscriptions` means listmonk sends its own confirmation email and the person stays `unconfirmed` until they click it. Setting it true skips consent confirmation, so only use it if consent has been captured by us. The docs do not state whether campaigns to a single opt-in list reach `unconfirmed` subscriptions; verify on the target instance before relying on it.

## 4. API user with a minimal role and token

Sources: https://listmonk.app/docs/apis/apis/ and https://listmonk.app/docs/roles-and-permissions/ .

- API users and their tokens are created in the admin UI: Admin -> Users (new user, type API). The token is generated automatically. The docs do not give click-by-click steps beyond this.
- Roles (user roles, list roles) require **v4.0.0 or later**.
- Auth, two forms: Basic auth `curl -u "api_user:token" ...`, or header `Authorization: token api_user:token`. Use the header form from Symfony HttpClient.
- Minimal user role for create-only: `subscribers:manage` ("Add, update, and delete subscribers"). **(code)** `POST /api/subscribers` is guarded by `PermSubscribersManage` only (`cmd/handlers.go`). Do not grant `subscribers:get_all`, `lists:get_all`/`lists:manage_all`, or `subscribers:sql_query` (docs: only for trusted users).
- Restrict lists with a **list role** giving `manage` on just the newsletter list, attached to the API user. Docs: a user reaches only lists in their list role; `lists:get_all`/`lists:manage_all` in the user role override per-list permissions, so leave those off. **(code)** `FilterListsByPerm(manage, ...)` drops other lists and returns 403 if none remain.
- Caveat: `subscribers:manage` also covers update and delete of subscribers. There is no create-only permission in the documented set.

## 5. List id vs UUID

- `POST /api/subscribers` takes numeric list **IDs** (`lists: number[]`), per the docs table.
- **(code)** The internal `insert-subscriber` query can also take list UUIDs, but only the public subscription form path uses that (`InsertSubscriber(..., listUUIDs, ...)`); the authenticated `CreateSubscriber` handler passes IDs only (`listUUIDs=nil`).
- List UUIDs are used by the public form (`POST /subscription/form`, `POST /api/public/subscription`, field `l`). List IDs are visible in Admin -> Lists. Use the ID in config.
- Alternative: the public unauthenticated endpoint `POST /api/public/subscription` exists (**(code)**, `cmd/handlers.go`, `cmd/public.go`), but needs "public subscription page" enabled in settings and may require captcha; it is not a fit for a trusted server-side call and exposes `/api/public/*`, which conflicts with keeping `/api/*` private.

## 6. SES / bounce configuration

Source: https://listmonk.app/docs/bounces/ .

1. Settings -> Bounces: enable bounce processing, bounce webhooks, and SES. Docs defaults: soft count 2 / action None; hard count 1 / Blocklist; complaint count 1 / Blocklist.
2. AWS SNS: Standard topic; HTTPS subscription to `https://<listmonk-host>/webhooks/service/ses`, raw message delivery **disabled**; confirm the subscription (fails if the endpoint is not publicly reachable).
3. SES identity -> Notifications -> Feedback notifications for Bounce and Complaint -> the SNS topic, include original headers. Repeat per individual sending identity.
4. Test with `ooto@`, `bounce@`, `complaint@simulator.amazonses.com`.
5. SES SMTP settings for sending are in Settings -> SMTP (docs there; not re-verified here).
6. The docs describe no signature verification for the SES webhook, only a shared secret for Azure ACS. Treat `/webhooks/service/*` as an open endpoint by design.

## 7. Public vs private paths

Source: https://listmonk.app/docs/configuration/ ("Public endpoints" / "Private endpoints" tables; also referenced for auth proxies and WAFs).

Public (expose to the internet):
- `/subscription/*` (GET, POST): HTML subscription, preference, opt-in pages
- `/link/*` (GET): tracked link redirects
- `/campaign/*` (GET): open-tracking pixel
- `/public/*` (GET): static files for the public pages
- `/webhooks/service/*` (POST): bounce webhooks (SES etc.)
- `/uploads/*` (GET): media upload path

Private (restrict):
- `/api/*` (all methods): admin APIs
- `/admin/*` (GET): admin UI
- `/webhooks/bounce` (POST): admin bounce webhook

Consequences: `/subscription/*` and `/link/*` must be public because they are linked from emails (opt-in confirmation at `/subscription/optin/:subUUID`, unsubscribe at `/subscription/:campUUID/:subUUID`, links at `/link/:linkUUID/:campUUID/:subUUID`; **(code)** routes in `cmd/handlers.go`). The Symfony worker on the same docker network calls `/api/subscribers` directly over the internal hostname, so `/api/*` and `/admin/*` need not be routed through the public proxy at all. Caveat: the table lists `/api/*` as private, but `/api/public/*` (public lists, public subscription, captcha) is registered under that prefix; blocking `/api/*` publicly also blocks the public sub form's JSON endpoint, which is fine unless embedded forms are used. The docs say nothing about a root-URL setting on that page; the app's "Root URL" setting (Settings -> General) determines email link hosts, so it must be the public hostname (not verified on this page; confirm in the admin UI).

## Open points / not verified
- Whether `unconfirmed` subscriptions on single opt-in lists receive campaigns (not in docs fetched).
- Exact Admin -> Users form fields for creating an API user (docs only name the location).
- The 409 behaviour is established from source (v6.2.0 / master), not from the API docs or swagger; swagger (`docs/swagger/collections.yaml`) does not list it.

## Sources
- https://listmonk.app/docs/apis/subscribers/
- https://listmonk.app/docs/apis/apis/
- https://listmonk.app/docs/roles-and-permissions/
- https://listmonk.app/docs/bounces/
- https://listmonk.app/docs/configuration/
- https://github.com/knadh/listmonk (`internal/core/subscribers.go`, `cmd/subscribers.go`, `cmd/handlers.go`, `cmd/public.go`, `queries/subscribers.sql`, `internal/subimporter/importer.go`, `i18n/en.json`, `docs/swagger/collections.yaml`)
