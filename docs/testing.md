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
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?serverVersion=8.4&charset=utf8mb4" \
  vendor/bin/phpunit
```

Single files while iterating:

```sh
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?serverVersion=8.4&charset=utf8mb4" \
  APP_ENV=test vendor/bin/phpunit tests/Controller/DashboardTest.php
```

The suite creates the `paysub_test` database on demand and rebuilds its
schema per test from the entity metadata (`tests/DatabaseTestCase`), so tests
are order-independent and need no manual setup. CI additionally replays the
real migrations into `paysub_test` first, proving they apply before tests run.

## What is covered

- Registration and email verification (including tampered links, duplicates,
  weak passwords, resend without account enumeration).
- Authentication (verified login, unverified block, wrong password, logout)
  and password reset (unknown addresses get the identical redirect with no
  email; single-use tokens; invalid tokens change nothing).
- Contact (reCAPTCHA pass/fail) and newsletter signup (CSRF/method rejection,
  queued `MailingSubscribe` inspection).
- Subscription CRUD, per-user limits, billing-cycle amount normalization and
  totals (including cross-currency conversion), chart datasets
  (bar/monthly/yearly), dashboard totals and limit gauge.
- Expense categories, upcoming renewals, and account deletion.
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
| Mail delivery | SES (`MAILER_DSN`) | `sync` routing (`when@test` in `config/packages/messenger.yaml`) + `null://null`; assert via `MailerAssertionsTrait` |
| Newsletter queue | AMQP exchange | `in-memory://` (`when@test`); inspect `messenger.transport.newsletter::getSent()` |
| reCAPTCHA | Google API | `App\Tests\Double\FakeReCaptcha`, installed per test via `getContainer()->set()` **before** the first request, with `$client->disableReboot()` so the kernel reboot between requests does not drop it |
| reCAPTCHA/JSON Hub secrets | production secrets | dummy values in `.env.test` (construction only; never used for verification) |

## What is not covered yet

Self-service export has no implementation (explicitly deferred in #34), so
there is nothing automatable to assert for it. The manual email-request path
(issue #48) is covered instead: `UserDataExportService` scope/isolation,
the `user:export` operator command, and the account/contact request-route
wording.
