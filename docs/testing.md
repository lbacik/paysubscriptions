# Testing

Behavioral suite: service and end-to-end tests that exercise outcomes and
failure paths (including two-user isolation), not method mirrors. It runs as
the blocking gate in `.github/workflows/test.yml` (`checks` job): a red suite
fails the push, the tag build in `release.yml`, and therefore the deploy.
There is no `continue-on-error` anywhere in that path.

## Local command

MySQL must be reachable; the suite uses `DATABASE_URL` with the `when@test`
`_test` suffix appended, so it can never touch the dev database:

```sh
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4" \
  vendor/bin/phpunit
```

Single files while iterating:

```sh
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4" \
  APP_ENV=test vendor/bin/phpunit tests/Controller/DashboardTest.php
```

The suite creates the `paysub_test` database on demand and rebuilds its
schema per test from the entity metadata (`tests/DatabaseTestCase`), so tests
are order-independent and need no manual setup. CI additionally replays the
real migrations into `paysub_test` first, proving they apply before tests run.

## Zero-deprecation gate

The `Run PHPUnit` step in `.github/workflows/test.yml` is also the
deprecation gate: it runs plain `vendor/bin/phpunit` with no strict override,
so the configuration in `phpunit.xml.dist` decides what fails the run, and any
deprecation fails it:

- `<env name="SYMFONY_DEPRECATIONS_HELPER" value="max[total]=0&amp;ignoreFile=tests/deprecations-ignore.txt"/>`
  (no `force`, so a real environment variable still wins) turns every
  `trigger_deprecation()` into a failure; the `ignoreFile` is the single
  exception described below.
- `<server name="DOCTRINE_DEPRECATIONS" value="trigger" force="true"/>` makes
  `doctrine/deprecations` throw instead of logging. It must stay a forced
  `<server>` entry in `phpunit.xml.dist` — not `.env.test`, not
  `tests/bootstrap.php` — because `doctrine/deprecations` caches its mode on
  first use and Symfony sets it only at the first kernel boot, so anything
  that fires earlier would otherwise slip through in the cached mode.
- There is no `SYMFONY_DEPRECATIONS_HELPER` entry in `.env.test`.

### No-suppression rule

No `ignoreFile`, `baselineFile`, or other suppression except the single
exception below. A deprecation is fixed, not hidden: any future suppression
needs its own issue with justification and a narrow `ignoreFile` regex linking
to it. `@group legacy` is only for a test that deliberately exercises
deprecated behavior.

The one exception is `tests/deprecations-ignore.txt`, kept solely by
[issue #171](https://github.com/lbacik/paysubscriptions/issues/171): a
missing-`@return` indirect notice from `league/commonmark` 2.10.3
(`Util\ArrayCollection::offsetGet()`), still absent on upstream `main`, with
no newer release carrying a fix. Its single regex matches only that exact
vendor message. Remove the file and its wiring in `phpunit.xml.dist` once an
upstream release adds the annotation.

## Running the gate locally on PHP 8.4

The gate only means something on the same interpreter production runs
(`dunglas/frankenphp:1-php8.4`, matching `.php-version` and CI's
`PHP_VERSION`). Run `vendor/bin/phpunit` directly only with a PHP 8.4 binary
(check `php -v` first); any other interpreter still runs the tests but does
not reproduce the gate. MySQL must be reachable at
`127.0.0.1:3306` — the suite appends the `_test` suffix, so it uses
`paysub_test` and can never touch the dev database:

```sh
php -v  # must report PHP 8.4.x
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4" \
  vendor/bin/phpunit
```

The explicit `DATABASE_URL` matters: a `serverVersion` in `.env.local` that is
not the full server-reported version (e.g. `9.2` instead of `9.2.0`) makes DBAL
emit a deprecation per connection, which the gate correctly turns into a
failure. With the command above the run exits 0.

Run it from the repository root: the `ignoreFile` path is relative, and from
any other working directory the deprecation handler throws on startup.

No manual setup is needed beyond a reachable MySQL: replay the migrations
first exactly as CI does (`php bin/console doctrine:database:create
--if-not-exists`, then `doctrine:migrations:migrate --no-interaction
--allow-no-migration` under `APP_ENV=test`), then run the command above.

Without a local PHP 8.4, run the same suite inside the project image with
the working tree bind-mounted (the `Dockerfile` adds `pdo_mysql`, `intl`
and `amqp` over the base image, so build it rather than using the bare
`dunglas/frankenphp:1-php8.4` image, which lacks them; `--network host` keeps
the host MySQL reachable at `127.0.0.1`):

```sh
docker build -t paysub-php84 .
docker run --rm -v "$PWD:/opt/app" --network host \
  -e APP_ENV=test \
  -e DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4" \
  paysub-php84 vendor/bin/phpunit
```

Note: the docker command above is unverified — no container runtime was
available where this was written. If it fails, the direct PHP 8.4 run is the
authoritative local gate.

## What is covered

- Registration and email verification (including tampered links, duplicates,
  weak passwords, resend without account enumeration).
- Authentication (verified login, unverified block, wrong password, logout)
  and password reset (unknown addresses get the identical redirect with no
  email; single-use tokens; invalid tokens change nothing).
- Contact (reCAPTCHA pass/fail) and newsletter signup (CSRF/method rejection,
  reCAPTCHA pass/fail, per-IP throttling, fixed homepage redirect instead of
  `Referer`, queued `MailingSubscribe` inspection).
- Public-endpoint abuse protection (issue #143): login throttling
  (per-account and per-IP lockout with a user-facing message), OAuth2 token
  rate limiting (per client and per IP, 429 + `Retry-After`), and
  activation-email resend (POST-only CSRF form, identical response with no
  email for verified/unknown addresses, per-address and per-IP throttling).
  Limiter bursts resolve from the environment (`.env` production-sized,
  `.env.test` effectively unlimited); tests opt in by pinning small values
  before the kernel boots (see `App\Tests\RateLimitTestHelper`).
- Subscription CRUD, per-user limits, billing-cycle amount normalization and
  totals (including cross-currency conversion), chart datasets
  (bar/monthly/yearly), dashboard totals and limit gauge.
- Expense categories, upcoming renewals, and account deletion.
- SES mail boundary and recipient restrictions (`tests/Mailer/`): every
  email goes through the paysubs-app tenant and configuration set from
  `no-reply@paysubscriptions.com` only, and `X-SES-*` headers or foreign
  From addresses are rejected before SES is called. Permanent bounces and
  complaints restrict an address monotonically, foreign or malformed feedback
  changes nothing and stays on the queue, and restricted recipients are
  dropped from outgoing email (an email with no remaining recipient is not
  sent). Clearing a restriction (`tests/Command/ClearRecipientRestrictionCommandTest.php`)
  needs a reason and an operator, normalizes the address, fails for an
  address without a restriction, and writes one audit record per clear.
- Renewal reminders (`tests/Service/RenewalReminderPlannerTest.php`,
  `tests/Service/RenewalReminderServiceTest.php`,
  `tests/Command/SendRenewalRemindersCommandTest.php`): opt-in/opt-out, lead
  time in the account-local calendar day (including DST boundaries), date
  edits and deleted rows producing no stale mail, retry and concurrent-claim
  idempotency via the `renewal_reminder` send identity, ambiguous provider
  outcomes held for manual review and listed by identifier on every command
  run, dry-run previews that exclude already-handled renewals, and one email
  per due renewal for Users with several renewals.
- Cross-user ownership: a second user is denied (403 via `SubscriptionVoter`
  for subscriptions, 404 elsewhere) for foreign view/edit/delete, and never
  sees foreign rows.

## External-service fakes

No test touches the network except the `NotCompromisedPassword` breach check,
which queries `api.pwnedpasswords.com`; registration/reset tests therefore
submit high-entropy passwords that cannot appear in the breach corpus.

Everything else is faked in the test environment:

| Service | Production | Under test |
|---|---|---|
| Mail delivery | SES tenant transport (`MAILER_DSN=ses+tenant://…`) | `sync` routing (`when@test` in `config/packages/messenger.yaml`) + `null://null`; assert via `MailerAssertionsTrait`. The transport itself is tested against a mock SES HTTP client (`tests/Mailer/SesTenantTransportTest.php`) |
| Newsletter queue | AMQP exchange | `in-memory://` (`when@test`); inspect `messenger.transport.newsletter::getSent()` |
| reCAPTCHA | Google API | `App\Tests\Double\FakeReCaptcha`, installed per test via `getContainer()->set()` **before** the first request, with `$client->disableReboot()` so the kernel reboot between requests does not drop it |
| reCAPTCHA/JSON Hub secrets | production secrets | dummy values in `.env.test` (construction only; never used for verification) |

## What is not covered yet

Self-service export has no implementation (explicitly deferred in #34), so
there is nothing automatable to assert for it. The manual email-request path
(issue #48) is covered instead: `UserDataExportService` scope/isolation,
the `user:export` operator command, and the account/contact request-route
wording.
