### Symfony Recipes Update Report - 2026-07-05

#### Summary
All recipes in the project have been updated to their latest versions using the `symfony` binary wrapper (PHP 8.4). Conflicts were resolved manually to preserve existing application logic while adopting new Symfony best practices.

#### Decisions Log

1. **symfony/flex**
   - **Action**: Updated to 2.4.
   - **Decisions**: Accepted new `.env.dev` file for local development environment variables.

2. **symfony/framework-bundle**
   - **Action**: Updated to 7.4.
   - **Decisions**: 
     - Moved `APP_SECRET` to `.env.dev`.
     - Switched error routing to PHP configuration.
     - Simplified `services.yaml` by removing redundant exclusions.
     - Resolved conflict with `.editorconfig` by adding it to version control before update.

3. **doctrine/doctrine-bundle**
   - **Action**: Updated to 2.14.
   - **Decisions**:
     - Updated SQLite DSN to include environment suffix.
     - Enabled `identity_generation_preferences` for PostgreSQL.
     - Set `controller_resolver.auto_mapping` to `false`.

4. **phpunit/phpunit**
   - **Action**: Updated to 9.6.
   - **Decisions**: Simplified `tests/bootstrap.php`.

5. **symfony/apache-pack**
   - **Action**: Updated to 1.0.
   - **Decisions**: Changed `index.php` redirect from 301 to 308 for better request method preservation.

6. **symfony/asset-mapper**
   - **Action**: Updated to latest.
   - **Decisions**: 
     - **Conflict Resolution**: Fixed a duplication of the `importmap` block in `templates/base.html.twig`.
     - **Conflict Resolution**: Merged `missing_import_mode` configuration, setting it to `strict` in dev and `warn` in prod.

7. **symfony/mailer**
   - **Action**: Updated to 7.4.
   - **Decisions**: Uncommented default `MAILER_DSN`.

8. **symfony/messenger**
   - **Action**: Updated to latest.
   - **Decisions**:
     - **Conflict Resolution**: Merged existing `async`, `failed`, and `newsletter` transports with the new `sync` transport.
     - Enabled `sync` routing for `SendEmailMessage` in development.

9. **symfony/monolog-bundle**
   - **Action**: Updated to 3.11.
   - **Decisions**:
     - Removed obsolete commented-out logging handlers.
     - Configured `main` handler in prod to exclude `deprecation` channel to avoid double logging.

10. **symfony/routing**
    - **Action**: Updated to latest.
    - **Decisions**:
      - **Conflict Resolution**: Switched from custom `APP_URL` to the standard `DEFAULT_URI` in `routing.yaml` and `.env`.
      - Adopted `resource: routing.controllers` for automatic controller discovery.

11. **symfony/security-bundle**
    - **Action**: Updated to latest.
    - **Decisions**:
      - **Conflict Resolution**: Preserved `app_user_provider` while the recipe suggested a null memory provider.

12. **symfony/stimulus-bundle**
    - **Action**: Updated to 2.36.
    - **Decisions**:
      - Migrated to `stimulus_bootstrap.js` as the new entry point for Stimulus.
      - Added `csrf_protection_controller.js` to support new stateless CSRF protection.

13. **symfony/translation**
    - **Action**: Updated to 7.4.
    - **Decisions**: Removed explicit `fallbacks` as Symfony now uses `default_locale` automatically.

14. **symfony/twig-bundle**
    - **Action**: Updated to latest.
    - **Decisions**:
      - **Conflict Resolution**: Merged FrankenPHP Hot Reload support into `templates/base.html.twig` without breaking existing structure.

15. **symfony/web-profiler-bundle**
    - **Action**: Updated to 7.4.
    - **Decisions**: Simplified configuration and switched to PHP routing for profiler.

16. **symfony/webapp-pack**
    - **Action**: Updated to latest.
    - **Decisions**:
      - **Conflict Resolution**: Refined `messenger.yaml` to use `MESSENGER_TRANSPORT_DSN` env var while keeping Doctrine-specific options for the `async` transport.

17. **zenstruck/foundry**
    - **Action**: Updated to 2.5.
    - **Decisions**: Updated configuration to Foundry 2.x standards, including `persistence.flush_once`.

18. **symfony/ux-turbo**
    - **Action**: Installed recipe (previously missing).
    - **Decisions**: Enabled `check_header: true` for CSRF protection in `config/packages/ux_turbo.yaml`.

#### Verification Results
- **PHP Version**: 8.4.18 (verified via `symfony php`).
- **Application Status**: `symfony console about` runs successfully.
- **Tests**: `symfony php bin/phpunit` executed (no tests found in project).
- **Recipes Status**: All recipes are now reported as up to date.

#### Post-Update Manual Check
- The `assets/bootstrap.js` file is no longer imported by `assets/app.js`, replaced by `assets/stimulus_bootstrap.js`. Existing custom registrations were verified to be only comments, so no functionality was lost.
- Stateless CSRF protection was enabled; it requires `framework.csrf_protection.check_header: true` and the `csrf-protection` Stimulus controller, both of which were successfully configured.
