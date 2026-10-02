# Accessibility (WCAG 2.1 AA)

Automated axe scan plus a short manual pass, for issue #52 (part of #34,
source #24). The scan runs in CI as a blocking job and must report zero
WCAG 2.1 AA errors on the release candidate.

## What is scanned

`tests/accessibility/scenarios.json` is the single source of truth: every
route/state the scan visits, its auth requirement, and the viewports it is
checked at. Covered today:

- Public: homepage (`/`, desktop + narrow), About (`/about`), pricing
  (`/pricing`), contact (`/contact`), registration (`/register`), login
  (`/login`, desktop + narrow), password reset request
  (`/reset-password`) and check-email (`/reset-password/check-email`).
- Signed-in: dashboard (`/dashboard`, desktop + narrow), monthly chart
  variant (`/dashboard?chartType=monthly`), Subscription add
  (`/subscription/new`), edit (`/subscription/{id}/edit`) and delete
  (`/subscription/{id}`), where `{id}` resolves to the first subscription
  owned by the scan user (created through the form if none exists).

Four v1.0 surfaces do not exist yet and are registered as `pending` with
their tracking issue, so the scanner skips them instead of failing:
privacy (#49), categories (#39), settings (#44), account deletion (#46).
Whoever implements one flips it to covered in `scenarios.json` (set `path`,
remove `pending`/`note`) — no scanner code changes needed. The PHP guard
and the scanner both fail if a pending surface starts resolving without
being promoted.

## How it works

- `tests/accessibility/scan.mjs` — Node runner. Logs in via `fetch` with a
  minimal cookie jar (matches `AppCustomAuthenticator`: `email`,
  `password`, `_csrf_token`), injects the session cookie into each
  authenticated Playwright context, then runs `@axe-core/playwright` with
  the `wcag2a/wcag2aa/wcag21a/wcag21aa` tags. Exit 0 = zero violations,
  1 = violations or route failures, 2 = setup error. Every failure names
  the scenario (route + viewport) and the violation (`id`, impact, help
  URL, target selectors). JSON report goes to `var/accessibility-report.json`.
- `tests/accessibility/router.php` — router for `php -S`. PHP's built-in
  server does not import process env into `$_SERVER`, so without this the
  kernel boots with `.env` defaults regardless of exported variables; the
  router bridges the allow-listed vars (`APP_ENV`, `DATABASE_URL`,
  reCAPTCHA keys, …) into `$_SERVER`/`$_ENV`.
- `tests/AccessibilityScenariosTest.php` — PHPUnit guard that runs in the
  regular `phpunit` job with no browser. It proves the registry has not
  rotted: covered entries are well-formed and resolve (public → 200,
  protected → redirect to `/login`), dynamic `{id}` scenarios declare
  exactly one placeholder, and no pending entry silently became available.
- `tests/accessibility/package.json` — pinned `playwright` + 
  `@axe-core/playwright` versions for the scan.

## Local reproduction

Prerequisites: PHP 8.4 with `pdo_mysql`, Composer deps installed, MySQL
running locally, Node 20.

```bash
# 1. Test database + schema + fixtures (fixtures provide foo@bar.com / alamakota).
# APP_ENV=test throughout: `when@test` appends `_test` to the database name,
# so this seeds `paysub_test` — the database the scan server boots against.
# Bare `php bin/console …` (dev) would seed `paysub` instead and every scan
# login would fail.
export APP_SECRET=ci \
  DATABASE_URL='mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4' \
  MESSENGER_TRANSPORT_DSN='amqp://guest:guest@127.0.0.1:5672/%2f/messages' \
  MAILER_DSN='null://null' \
  JSON_HUB_PROJECT='00000000-0000-0000-0000-000000000000' \
  MAILING_PROVIDER_ROUTING_KEY='ci'
APP_ENV=test php bin/console doctrine:database:create --if-not-exists --no-interaction
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
APP_ENV=test php bin/console doctrine:fixtures:load --no-interaction

# 2. CSS the templates expect at render time
php bin/console tailwind:build

# 3. Start the test-kernel server (router bridges env into $_SERVER)
export APP_ENV=test APP_DEBUG=0 APP_SECRET=ci \
  DATABASE_URL='mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4' \
  MESSENGER_TRANSPORT_DSN='amqp://guest:guest@127.0.0.1:5672/%2f/messages' \
  MAILER_DSN='null://null' \
  GOOGLE_RECAPTCHA_SITE_KEY='6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI' \
  GOOGLE_RECAPTCHA_SECRET='6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe' \
  JSON_HUB_PROJECT='00000000-0000-0000-0000-000000000000' \
  MAILING_PROVIDER_ROUTING_KEY='ci'
php -S 127.0.0.1:8000 tests/accessibility/router.php &

# 4. Registry guard (uses the default test DATABASE_URL handling)
DATABASE_URL='mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4' \
  vendor/bin/phpunit tests/AccessibilityScenariosTest.php

# 5. Full axe scan (same credentials as fixtures; test keys for reCAPTCHA)
cd tests/accessibility && npm ci
BASE_URL=http://127.0.0.1:8000 \
  A11Y_USER_EMAIL=foo@bar.com A11Y_USER_PASSWORD=alamakota \
  node scan.mjs
```

Notes:

- The guard test uses `ResetDatabase`, so reload fixtures afterwards if you
  keep scanning against the same database.
- `BASE_URL` defaults to `http://127.0.0.1:8000`; `REPORT_PATH` defaults to
  `var/accessibility-report.json`.
- `foo@bar.com` / `alamakota` come from `AppFixtures` + `UserFactory`
  defaults — any verified fixture user works.
- Google reCAPTCHA uses the documented test keys
  (`6LeIxAcT…` / `6LeIxAcT…`): the contact page embeds the widget and must
  render without real credentials. `phpunit.xml.dist` sets the same pair
  for tests; real environment variables still win.

## CI behavior

The `accessibility` job in `.github/workflows/test.yml` mirrors the local
steps: MySQL 9 service, PHP 8.4 + Composer, Tailwind build, migrate +
fixtures, Node 20 + `npm ci` + `npx playwright install chromium`, then the
router-based server and `node scan.mjs` with the fixture credentials. It
fails on any axe violation or route failure (exit 1) and on setup errors
(exit 2); the JSON report uploads as an artifact on failure.

## Manual pass (scanner gaps)

Axe cannot judge everything. Before v1.0, walk this checklist on the
covered pages:

- **Dynamic form errors** — submit empty/invalid register, login, contact,
  reset, and Subscription forms; errors must appear in a `role="alert"`
  region and move or associate focus sensibly.
- **Dialogs / Turbo updates** — open Subscription edit/delete modals and
  dashboard month navigation; focus must enter the dialog, `Esc` must close
  it, and focus must return to the trigger.
- **Keyboard** — tab through header menus, chart-legend toggles, and modal
  actions with no mouse; every control reachable, visible
  `focus-visible` ring, `aria-expanded`/`aria-pressed` reflect state.
- **Labels** — every input has a real `<label>` (not placeholder-only);
  search/close icon buttons carry `aria-label`.
- **Contrast** — secondary text is `slate-500` or darker on light
  backgrounds; status colors (red/emerald) meet 4.5:1 for text.
- **Chart alternatives** — the spending chart container exposes
  `role="img"` + translated `aria-label`, and the KPI totals beside it
  convey the same numbers as text.

## Known limits

- The reset-confirmation route (`/reset-password/reset/{token}`) needs a
  live token and is not scanned; the request + check-email states are.
- The newsletter footer form posts to `app_newsletter_subscribe` and is
  covered as part of every page render, not as a standalone scenario.
- External embeds (Umami beacon, Google Fonts, Font Awesome CDN,
  reCAPTCHA script on contact) are third-party payloads outside axe's
  scope; see `docs/third-party-services.md`.
