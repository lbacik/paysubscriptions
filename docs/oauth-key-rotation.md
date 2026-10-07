# OAuth2 key rotation runbook (issue #94)

Operators can rotate the signing and refresh-token encryption material without
unexpectedly stranding valid clients, and can rapidly invalidate access after a
signing-key incident.

## Key material inventory

| Material | Env | In the repo? | Sensitivity |
|---|---|---|---|
| Private signing key (RSA) | `OAUTH_PRIVATE_KEY` (path or PEM) | Never | Secret |
| Public verification key | `OAUTH_PUBLIC_KEY` (path or PEM) | Only the path default; file itself is gitignored (`/config/jwt/*.pem`) | Publishable |
| Private-key passphrase | `OAUTH_PASSPHRASE` | Never (empty default) | Secret |
| Refresh-token encryption key | `OAUTH_ENCRYPTION_KEY` | Never (empty default) | Secret |
| Current key identifier | `OAUTH_KEY_ID` | Default label only (`v1`) | Publishable (it rides in every token's `kid` header) |
| Previous verification key + id | `OAUTH_PREVIOUS_PUBLIC_KEY`, `OAUTH_PREVIOUS_KEY_ID` | Never (empty outside rotations) | Public key publishable; empty when no rotation is in flight |

Private signing and encryption material live **outside the repository** with
restricted access and backups; **only public verification keys are published**:

- Production keys live on the deploy host (or in the production environment's
  secrets), never in git, never in the image. `compose.prod.yaml` passes the
  `OAUTH_*` values through from the deploy environment.
- Private key files: mode `600`, owned by the deploy user, readable only by
  the app runtime. The app refuses nothing itself here — League emits a
  notice for loose private-key permissions — so verify with
  `stat -c '%a %U' config/jwt/private.pem`.
- Public keys are world-readable (`644`) by design; the resource-server wiring
  disables League's private-key permission heuristic for them on purpose.
- Back up the private key, passphrase, and encryption key **encrypted and
  offsite** (e.g. the same secrets manager that holds `APP_SECRET`), and
  confirm a restore decrypts at least once per key generation. Losing the
  encryption key without a backup strands every outstanding refresh token and
  authorization code; losing the private key without a backup only costs the
  ability to keep signing with the same `kid` — generate a new pair instead.
- The committed keypair under `tests/Fixtures/oauth/` (plus
  `previous-*.pem`) is a **dummy for the suite only** and must never be used
  outside tests. The dummy encryption key in `.env.test` likewise.

## Key identifiers

Every access token carries the signing key's identifier in its JWT `kid`
header (`App\OAuth2\ApiAccessTokenEntity`). The resource server
(`App\OAuth2\ApiResourceServer`) selects the verification key by `kid`:

- current `kid` → current public key,
- previous `kid` → previous public key (only while a rotation is in flight),
- unknown `kid` → denied, without revealing which identifiers are configured,
- no `kid` (tokens minted before identifiers existed) → tried against the
  current key, then the previous one.

Identifier values are free-form labels; the convention is a short generation
stamp (`v1`, `2026-09-a`, …). Set `OAUTH_KEY_ID` to a fresh label with every
rotation.

## Timing: the overlap window

Access tokens live 15 minutes (`app.oauth2.access_token_ttl`) plus 60 seconds
of clock-skew tolerance (`app.oauth2.clock_skew_leeway`). Both are defined
once as container parameters (issue #135, problem 6): the bundle config
references them for issued tokens, and `App\OAuth2\OAuthTokenLifetimes`
derives the overlap from those same parameters. No pre-rotation token can
outlive that sum, so a routine rotation only needs the previous verification
key published for **16 minutes** (`OAuthTokenLifetimes::getRotationOverlap()`).
The documented recommendation is **30 minutes** — generous margin, same
operational shape.

## Routine signing-key rotation (no incident)

1. Generate a new pair on the deploy host (never commit it):
   `php bin/console league:oauth2-server:generate-keypair`, or
   `openssl genrsa -out config/jwt/private.pem 2048` plus the `-pubout`
   public key. Restrict the private key to `600`.
2. Deploy with the new material **and** the overlap:
   - `OAUTH_PRIVATE_KEY` / `OAUTH_PUBLIC_KEY` → new pair,
   - `OAUTH_KEY_ID` → fresh label (e.g. `2026-09-b`),
   - `OAUTH_PREVIOUS_PUBLIC_KEY` → old public key,
   - `OAUTH_PREVIOUS_KEY_ID` → old label.
   New access tokens sign with the new key; tokens signed with the old key
   keep validating until they expire. No client is stranded.
3. Verify: decode any new access token's header (`kid` equals the new label),
   call `/api` with a new token (200) and with a pre-rotation token (200).
4. Wait at least 30 minutes — past the longest remaining pre-rotation
   token lifetime.
5. Deploy again with `OAUTH_PREVIOUS_PUBLIC_KEY` and `OAUTH_PREVIOUS_KEY_ID`
   emptied. The old key is now dead; pre-rotation tokens have all expired
   anyway, so nothing observable changes.
6. Verify: `/api` with a new token still 200s. Confirm the old public key file
   is archived (not trusted anywhere) per your retention policy.

Refresh-token families survive a routine rotation untouched: refresh payloads
are encrypted with `OAUTH_ENCRYPTION_KEY`, not signed, so sessions continue
across signing rotations with zero re-authorization.

## Emergency rotation (suspected signing-key compromise)

Speed beats seamlessness: the old key must stop validating **immediately**,
which strands its outstanding access tokens (at most 15 minutes of access
each) and ends every session.

1. Generate a new pair as above.
2. Deploy the new pair with a fresh `OAUTH_KEY_ID` and with
   `OAUTH_PREVIOUS_PUBLIC_KEY` / `OAUTH_PREVIOUS_KEY_ID` **empty**. Every
   token the compromised key signed now fails validation at once.
3. Revoke every Client session **and every pending authorization code** so
   stolen sessions cannot mint new-key tokens and in-flight authorizations
   cannot complete into them:
   `./bin/console app:oauth:revoke-refresh-families`
   The command reports how many Client sessions it revoked. Clients must authorize
   again from here.
4. Verify: a pre-incident access token gets 401, a pre-incident refresh token
   gets `invalid_grant`, a pre-incident pending authorization code fails with
   `invalid_grant`, and a fresh authorization-code flow (consent →
   `/token` → `/api` → refresh) succeeds end to end.
5. Follow up: rotate the pair's label in your records, audit recent
   authorizations for the compromised window, and consider rotating
   `OAUTH_ENCRYPTION_KEY` too (below) if the incident may have exposed more
   than the signing key.

What stays valid after step 3, and why that is accepted: already-issued
access tokens stay valid until their short (15-minute) expiry — inherent to
self-contained tokens. Everything else is dead: refresh-token families and
pending authorization codes are all revoked, so even a client mid-authorize
restarts its flow instead of redeeming a pre-incident code into a new-key
family. Connections are kept, so re-authorization auto-approves.

## Encryption-material rotation plan

`OAUTH_ENCRYPTION_KEY` encrypts authorization-code and refresh-token payloads.
It has **no overlap mechanism**: the moment the value changes, outstanding
codes and refresh tokens fail closed with `invalid_grant` (protocol errors,
never 500s) and their owners must re-authorize. The suite pins this contract
in `OAuthKeyRotationTest::testEncryptionRotationFailsClosedAndRecoversThroughReauthorization`.

Controlled procedure:

1. Pick a low-traffic window and announce that clients will need to
   re-authorize (refresh-token families become unusable, though the family
   rows themselves are left intact for audit).
2. Deploy the new `OAUTH_ENCRYPTION_KEY`. There is no previous-key setting —
   keep the old value in your secrets history in case of a mistaken swap.
3. Verify: an old refresh token gets `invalid_grant`, and a fresh
   authorization-code flow plus one refresh succeeds (this is the recovery
   path every client follows).
4. No cleanup step: families whose tokens can no longer decrypt expire on
   their normal 30-day idle / 90-day absolute deadlines, and clients that
   re-authorize start new families.

A mistaken rotation is recoverable within the outstanding-token window by
restoring the previous encryption key value — another reason to keep secrets
history.

## Verification commands

- `php bin/console lint:container` — the container does not boot with an
  unreadable key path, so this catches most deployment typos before traffic.
- `./bin/console app:oauth:revoke-refresh-families` — emergency
  Client-session revocation; prints the revoked count.
- Decode a token header to confirm its `kid`:
  `php -r '$p=json_decode(base64_decode(explode(".", $argv[1])[0]),true); echo $p["kid"]??"(none)",PHP_EOL;' <token>`.
