# PaySubscriptions

PaySubscriptions is a web application for tracking recurring personal and household costs. Add subscriptions manually, see how much they cost each month or year, and keep track of upcoming renewals.

Each account belongs to one user and has its own subscriptions and expense categories. Household costs can be tracked by that user; shared accounts and invitations are not implemented.

## Features

- Track monthly and yearly subscriptions with amounts, currencies, and next payment dates.
- Organize subscriptions into personal expense categories with color swatches.
- View spending totals, charts, and upcoming renewals on the dashboard.
- Set spending limits and opt in to renewal reminder emails.
- Manage account settings, email verification, password resets, and account deletion.
- Access subscriptions, expense categories, and dashboard reports through an OAuth2-protected API.

Subscription data is entered manually. Currency conversion uses user-entered amounts; automatic exchange rates, bank connections, inbox scanning, and provider cancellation are outside the current scope. Data exports are handled by an operator on request; see the [data export guide](docs/data-export.md).

## Technology

- PHP 8.4 and Symfony 7.4
- Doctrine ORM and migrations, with MySQL used by CI and the current migrations
- Twig, Symfony UX, Stimulus, Turbo, and Chart.js
- Tailwind CSS and Symfony AssetMapper/import maps
- Symfony Mailer and Messenger
- API Platform and League OAuth2 Server Bundle
- PHPUnit for service and functional tests
- FrankenPHP for the production container

Frontend assets are managed through Symfony; a Node.js build pipeline is not required.

## Local development

### Requirements

Install PHP 8.4 with the extensions required by Composer, plus `pdo_mysql`, `intl`, and OpenSSL, Composer 2, and a reachable MySQL database (CI uses MySQL 8.4). The newsletter integration also needs the AMQP extension and a RabbitMQ-compatible broker. Symfony CLI is used below to serve the application locally.

The committed `.env` and development Compose database service still default to PostgreSQL, while the migration SQL targets MySQL. Use a MySQL `DATABASE_URL` for the setup below.

### Setup

1. Clone the repository:

   ```sh
   git clone https://github.com/lbacik/paysubscriptions.git
   cd paysubscriptions
   ```

2. Create an untracked `.env.dev.local` with your local configuration:

   ```dotenv
   APP_SECRET=<random-secret>
   DATABASE_URL="mysql://app:local-password@127.0.0.1:3306/paysub?serverVersion=8.4&charset=utf8mb4"
   APP_URL=http://localhost:8000
   DEFAULT_URI=http://localhost:8000
   OAUTH2_ISSUER=http://localhost:8000
   OAUTH_PASSPHRASE=<random-key-passphrase>
   OAUTH_ENCRYPTION_KEY=<random-encryption-key>
   MAILER_DSN=smtp://127.0.0.1:1025
   MESSENGER_TRANSPORT_DSN=amqp://guest:guest@localhost:5672/%2f/messages
   ```

   Replace placeholders with local values. Generate each secret independently with `openssl rand -hex 32`. The database user must be able to create the development database. Keep secrets and private keys out of version control; see [development setup](docs/development-setup.md).

   Use a local SMTP catcher on port 1025 to receive verification and reset emails. The default `MAILER_DSN=null://null` discards mail. Configure `GOOGLE_RECAPTCHA_SITE_KEY` and `GOOGLE_RECAPTCHA_SECRET` for contact and newsletter forms; the test environment uses a fake verifier.

3. Install dependencies, generate local OAuth keys, and apply migrations:

   ```sh
   composer install
   php bin/console league:oauth2-server:generate-keypair --skip-if-exists
   php bin/console doctrine:database:create --if-not-exists
   php bin/console doctrine:migrations:migrate --no-interaction
   php bin/console tailwind:build
   ```

4. Start the application:

   ```sh
   symfony server:start --no-tls --port=8000
   ```

   Open [localhost:8000](http://localhost:8000), register an account, and follow the verification link in your local mail catcher.

During frontend development, run `php bin/console tailwind:build --watch` in another terminal.

## Background tasks

Development sends transactional emails synchronously. Production routes ordinary transactional emails through the `async` Messenger transport, which needs a worker:

```sh
php bin/console messenger:consume async --time-limit=3600
```

In production this is the `worker` service in `compose.prod.yaml`. It runs from the same image as `web` and restarts after every hourly exit. Messages that exhaust their retries land in the `failed` transport; list them with `php bin/console messenger:failed:show`.

Production mail goes through Amazon SES in `eu-central-1` with `MAILER_DSN=ses+tenant://ACCESS_KEY_ID:SECRET_ACCESS_KEY@default`. The transport always sends through the `paysubs-app` tenant and `paysubs-app-events` configuration set, and only from `no-reply@paysubscriptions.com`, so `SYSTEM_EMAIL` must be that address. The SES boundary is defined in `src/Mailer/SesTenantBoundary.php`.

Permanent bounces and complaints arrive on the `paysubs-ses-recipient-restrictions` SQS queue. A second long-running worker records them as recipient restrictions, and restricted addresses then receive no email:

```sh
php bin/console app:ses:consume-recipient-restrictions --time-limit=3600
```

It needs `SES_RESTRICTION_QUEUE_URL`, `SES_RESTRICTION_ACCESS_KEY_ID` and `SES_RESTRICTION_SECRET_ACCESS_KEY` for the `paysubs-feedback-reader` IAM user. In production this is the `restriction-consumer` service in `compose.prod.yaml`, and the deploy refuses to start without the three values: the queue URL as a variable, the key pair as secrets of the `production` environment. Invalid messages stay on the queue and move to its dead-letter queue after five receives.

A restriction is never loosened by SES feedback. To lift one, for example after a recipient fixes their mailbox or after a test against the SES mailbox simulator, run the operator command on the production host:

```sh
php bin/console app:ses:clear-recipient-restriction bounce@simulator.amazonses.com \
  --operator="<your name>" --reason="SES simulator validation (#173)"
```

The address is normalized the same way as when it was restricted. Both options are required, and the command fails when the address has no restriction. Each clear is written as a JSON record on the `audit` log channel (stderr in production, outside the error-triggered buffer) with the address, previous state, operator, process user and reason; the record's timestamp is the time of the clear. The record exists only in the container log, so keep that log for as long as the audit trail is needed. `--operator` is not verified: shell access to the host is the authentication. A later bounce or complaint restricts the address again.

Newsletter messages are published to the configured AMQP `mailing` exchange for the external mailing integration. Set `JSON_HUB_PROJECT_UUID` and `MAILING_PROVIDER_ROUTING_KEY` when enabling that integration.

Run renewal reminders from a daily scheduler. Preview due reminders before sending:

```sh
php bin/console app:send-renewal-reminders --dry-run
php bin/console app:send-renewal-reminders
```

The reminder command prevents duplicate sends across routine retries and overlapping runs. It reports ambiguous delivery outcomes as `needs_review` for operator follow-up.

## API

The API is available under `/api/v1`, with an OpenAPI document at `/api/v1/openapi.json`. Approved clients use the authorization-code flow with S256 PKCE and the `api:full` scope. Client registration is performed by an operator.

- [API client guide](docs/api-client.md): discovery, sign-in, and authenticated requests
- [API operations](docs/api-operations.md): client registration, token management, and deployment checks
- [OAuth key rotation](docs/oauth-key-rotation.md): signing-key rotation and recovery

## Testing

Run the suite against a local MySQL instance:

```sh
DATABASE_URL="mysql://root:root@127.0.0.1:3306/paysub?serverVersion=8.4&charset=utf8mb4" \
  vendor/bin/phpunit
```

Use credentials for your local database. Tests append `_test` to the database name and create the test database and schema automatically, so the database user needs permission to create it. Registration and password-reset tests may contact the compromised-password checking service; other external integrations are faked.

See [testing documentation](docs/testing.md) for coverage, individual test commands, and test-environment behavior.

## Documentation and contributions

- [Domain model](CONTEXT.md) and [architecture decisions](docs/adr/)
- [Development setup and release flow](docs/development-setup.md)
- [Accessibility](docs/accessibility.md)
- [External services inventory](docs/third-party-services.md)
- [Issue tracker conventions](docs/issue-tracker.md) and [triage labels](docs/triage-labels.md)

Track bugs and feature requests in [GitHub Issues](https://github.com/lbacik/paysubscriptions/issues). Submit changes through a short-lived branch and a pull request into `main`. CI runs on pull requests and `main`; releases are `v*` tags cut from `main`.

## License

The repository includes an [Apache License 2.0](LICENSE) file. Composer metadata currently declares `proprietary`; these declarations should be reconciled by the maintainer.
