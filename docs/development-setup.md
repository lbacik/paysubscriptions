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
