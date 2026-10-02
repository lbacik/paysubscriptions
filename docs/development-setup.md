# Development setup

## `APP_SECRET`

`APP_SECRET` signs password-reset and email-verification URLs. The committed
`.env` leaves it empty, and no committed file may carry a value for it.

Give the dev environment a secret through the untracked, per-environment
override file `.env.dev.local`:

```sh
echo "APP_SECRET=$(openssl rand -hex 16)" > .env.dev.local
```

The file is loaded after `.env` and is covered by the `/.env.*.local` rule in
`.gitignore`, as is every other `.env.<env>.local` file. `.env.dev` itself is
ignored too, so a secret cannot be committed there by accident. Do the same
with `.env.test.local` if the test environment ever needs a different value.
Real environment variables always win over these files.

## Branching and release flow

The repository follows GitHub flow. `main` is the only long-lived branch and is
always releasable.

1. Branch off `main` (`feature/...`, `fix/...`, `chore/...`).
2. Open a pull request against `main`. Open it as a draft early: CI (`Test` and
   `Quality`) runs on pull requests and on `main`, not on bare branch pushes.
3. Merge into `main` once CI is green. Dependabot opens its PRs against `main`
   too.
4. Cut a release by pushing a `v*` tag on a `main` commit. `release.yml` runs
   the `Test` workflow as a gate, builds and pushes the image, then calls
   `deploy.yml`. Release tags are protected by the `protect-release-tags`
   ruleset; `main` cannot be deleted or force-pushed (`protect-main`).

Hotfixes follow the same path: a short-lived branch, a PR into `main`, a new tag.
