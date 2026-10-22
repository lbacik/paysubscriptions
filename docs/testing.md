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

The suite creates the `paysub_test` database on demand and builds its schema
once per run by replaying the migrations (`#[ResetDatabase]` on
`tests/DatabaseTestCase` and `tests/Api/V1/ApiV1TestCase`, `reset.mode: migrate` in
`config/packages/zenstruck_foundry.yaml`); each test then runs inside a
transaction that is rolled back, so tests are order-independent and need no
manual setup. CI additionally replays the
real migrations into `paysub_test` first, proving they apply before tests run.

## Zero-deprecation gate

The `Run PHPUnit` step in `.github/workflows/test.yml` is also the
deprecation gate: it runs plain `vendor/bin/phpunit` with no override, so the
configuration in `phpunit.xml.dist` decides what fails the run, and any
deprecation fails it. The gate is PHPUnit 13's native issue handling
(`symfony/phpunit-bridge`'s `SYMFONY_DEPRECATIONS_HELPER` handler is not used:
the bridge skips it on PHPUnit 10 and later):

- `failOnDeprecation="true"` and `failOnPhpunitDeprecation="true"` make any
  deprecation (userland, PHP runtime, or PHPUnit's own) exit non-zero.
  `failOnNotice="true"` and `failOnWarning="true"` make notices and warnings
  (including PHPUnit's own notices, such as a mock object with no
  expectations) fail the run too, instead of passing with an issue listed.
- `<source ignoreSuppressionOfDeprecations="true">`: Symfony, Doctrine and
  `DebugClassLoader` report deprecations through `@trigger_error()`, which
  PHPUnit would otherwise discard as suppressed. Indirect deprecations (vendor
  code calling vendor code) are not ignored either.
- `<deprecationTrigger>` names `trigger_deprecation()`,
  `Doctrine\Deprecations\Deprecation::trigger()` and
  `::triggerIfCalledFromOutside()`, so a deprecation is attributed to the code
  that called the trigger rather than to the trigger itself.
- `<server name="DOCTRINE_DEPRECATIONS" value="trigger" force="true"/>` makes
  `doctrine/deprecations` report through `@trigger_error()` instead of only
  logging. It must stay a forced `<server>` entry in `phpunit.xml.dist` — not
  `.env.test`, not `tests/bootstrap.php` — because `doctrine/deprecations`
  caches its mode on first use and Symfony sets it only at the first kernel
  boot, so anything that fires earlier would otherwise slip through in the
  cached mode.
- The `Symfony\Bridge\PhpUnit\SymfonyExtension` extension enables
  `DebugClassLoader`, which reports class-level deprecations (a missing
  `@return` on an overridden internal method, a deprecated parent class, and
  so on) for both `src/` and vendor classes.

The `DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension` and
`Zenstruck\Foundry\PHPUnit\FoundryExtension` extensions are registered next
to it: DAMA wraps each test in a rolled-back transaction (a class that runs
DDL opts out with `#[DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback]`)
and Foundry applies `#[Zenstruck\Foundry\Attribute\ResetDatabase]`. The
deprecated `ResetDatabase`/`Factories` traits must not come back: with the
extension registered they trigger deprecations, which the gate fails on.

### No-suppression rule

No deprecation filter, baseline, or other suppression. A deprecation is fixed,
not hidden: any future suppression needs its own issue with justification and
a narrow `<deprecationFilter>` in `<source><deprecationFilters>` (a class
implementing `PHPUnit\Runner\DeprecationFilter` that matches only that exact
message) linking to it. `#[IgnoreDeprecations]` is only for a test that
deliberately exercises deprecated behavior.

The former exception for
[issue #171](https://github.com/lbacik/paysubscriptions/issues/171) (a
missing `@return` on `league/commonmark`'s `Util\ArrayCollection::offsetGet()`)
is gone: `league/commonmark` 2.10.4 adds the annotation and the suite reports
no deprecation without it.

### PHP 8.5 preview report

The `php85` job in `.github/workflows/quality.yml` runs the same suite on PHP
8.5 with `--do-not-fail-on-deprecation --do-not-fail-on-phpunit-deprecation`,
so deprecations never turn it red, and `--log-events-text` writes every
triggered deprecation to a file. The job summarises that file per message in
the step summary and uploads it as the `php85-deprecations` artifact.

## Running the gate locally on PHP 8.4

The gate only means something on the same interpreter production runs
(`dunglas/frankenphp:1-php8.4`, matching `.php-version` and CI's
`PHP_VERSION`). Run `vendor/bin/phpunit` directly only with a PHP 8.4 binary
(check `php -v` first); any other interpreter still runs the tests but does
not reproduce the gate. MySQL must be reachable at
`127.0.0.1:3306` — the suite appends the `_test` suffix, so it uses
`paysub_test` and can never touch the dev database:

```sh
php -v  # must report PHP 8.4.x (PHPUnit 13 needs 8.4.1 or later)
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?charset=utf8mb4" \
  vendor/bin/phpunit
```

The explicit `DATABASE_URL` matters: a `serverVersion` in `.env.local` that is
not the full server-reported version (e.g. `9.2` instead of `9.2.0`) makes DBAL
emit a deprecation per connection, which the gate correctly turns into a
failure. With the command above the run exits 0.

Run it from the repository root, where `phpunit.xml.dist` lives.

No manual setup is needed beyond a reachable MySQL: replay the migrations
first exactly as CI does (`php bin/console doctrine:database:create
--if-not-exists`, then `doctrine:migrations:migrate --no-interaction
--allow-no-migration` under `APP_ENV=test`), then run the command above.

Without a local PHP 8.4, run the same suite inside the project image with
the working tree bind-mounted (the `Dockerfile` adds `pdo_mysql` and `intl`
over the base image, so build it rather than using the bare
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
  `Referer`, queued `MailingSubscribe` on `async`, listmonk response
  classification).
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
- API v1 (`tests/Api/V1/`): the token gate, reads and writes of every
  resource through the domain services, the problem+json error contract,
  content negotiation, two-User isolation, the generated OpenAPI snapshot
  (`OpenApiSnapshotTest`), and the every-operation 401/403 gate
  (`ApiOperationsGateTest`).
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

## API v1 tests

All API v1 resources are served by API Platform and tested in
`tests/Api/V1/` on `App\Tests\Api\V1\ApiV1TestCase`, which extends API
Platform's `ApiPlatform\Test\ApiTestCase` (its client,
`assertJsonContains()` and the JSON Schema assertions backed by
`justinrainbow/json-schema`).

- **Database and fixtures.** Same lifecycle as `DatabaseTestCase`
  (`#[ResetDatabase]`, one rolled-back transaction per test). Both base classes
  share the fixture helpers in `App\Tests\DatabaseFixtures` (`createUser()`,
  `createSubscription()`, …).
- **Tokens.** Access tokens are minted in-process by
  `App\Tests\Api\V1\MintsAccessTokens`, signed with the keys in
  `tests/Fixtures/oauth`. Every claim the gate checks can be varied. The base
  class registers the approved CLI client for every test, and
  `requestAs($user, $method, $url, $options)` sends a request with a valid
  `api:full` token for `$user`.
- **Token gate.** `TokenGateTest` covers the whole gate matrix (missing,
  tampered, expired token; wrong audience, issuer or scope; unknown, narrowed
  or inactive Client; unverified or deleted User) once, against one endpoint.
  It also holds the single end-to-end test that uses a token issued through
  the real OAuth2 flow. Resource suites do not repeat the matrix.
- **Formats.** Requests default to `Accept: application/hal+json`, the API's
  default format, and behavior tests use it. One format test per resource
  requests its item and collection in HAL, `application/json` and
  `application/ld+json`, and checks each against the JSON Schema API Platform
  generates (`assertMatchesResourceItemJsonSchema()` /
  `assertMatchesResourceCollectionJsonSchema()`). Error tests assert
  `application/problem+json` whatever the `Accept`.
- **Isolation checklist.** Every resource suite tests these with two Users:
  - the collection never shows the other User's rows;
  - GET, PATCH and DELETE on a foreign ID answer 404 and disclose nothing;
  - a foreign IRI in a write body (for example a Subscription's `category`)
    is a 422 on that field.
- **Generated contract snapshot.** `OpenApiSnapshotTest` (a `KernelTestCase`
  with no database) exports the generated document in-process
  (`api:openapi:export` via `CommandTester`) and compares it byte-for-byte
  with `assertSame` to the committed `resources/openapi/v1.json`. Regenerate
  manually only, and review the diff as a contract change:

  ```sh
  bin/console api:openapi:export --output=resources/openapi/v1.json
  ```
- **Every-operation gate.** `ApiOperationsGateTest` builds a data provider
  from every operation in the generated contract and asserts each answers
  401 without a token and 403 with a token lacking `api:full`, so a new
  operation cannot ship unprotected.

In the test environment the kernel runs in debug mode, so problem bodies
carry a `trace` that names the test method. A "discloses nothing" assertion
must look for fixture data that no test or class name contains.

## External-service fakes

No test touches the network except the `NotCompromisedPassword` breach check,
which queries `api.pwnedpasswords.com`; registration/reset tests therefore
submit high-entropy passwords that cannot appear in the breach corpus.

Everything else is faked in the test environment:

| Service | Production | Under test |
|---|---|---|
| Mail delivery | SES tenant transport (`MAILER_DSN=ses+tenant://…`) | `sync` routing (`when@test` in `config/packages/messenger.yaml`) + `null://null`; assert via `MailerAssertionsTrait`. The transport itself is tested against a mock SES HTTP client (`tests/Mailer/SesTenantTransportTest.php`) |
| Newsletter subscription | self-hosted listmonk API over the internal network | `MockHttpClient` in the handler tests; controller tests read the queued `MailingSubscribe` off the Doctrine `async` transport |
| reCAPTCHA | Google API | `App\Tests\Double\FakeReCaptcha`, installed per test via `getContainer()->set()` **before** the first request, with `$client->disableReboot()` so the kernel reboot between requests does not drop it |
| reCAPTCHA secrets | production secrets | dummy values in `.env.test` (construction only; never used for verification) |

## What is not covered yet

Self-service export has no implementation (explicitly deferred in #34), so
there is nothing automatable to assert for it. The manual email-request path
(issue #48) is covered instead: `UserDataExportService` scope/isolation,
the `user:export` operator command, and the account/contact request-route
wording.
