# API v1 operations runbook (issue #100)

How to register clients, run the browser sign-in, operate tokens, rotate
keys, rehearse migrations, and roll releases back. The discovery and
authorization-code flow from the client's side is in
`docs/api-client.md`; key-rotation procedures are in
`docs/oauth-key-rotation.md`. This document is the operator's copy: exact
commands, exact redirects, and what to check when something fails.

Notation: `<ACCESS>` is a 15-minute access token, `<REFRESH>` a refresh
token, `<VERIFIER>` a PKCE verifier. Never paste real values of these —
or of `client_secret`, `OAUTH_*`, or User data — into tickets, chat, or
logs. All example tokens below are placeholders.

## 1. Client registration (manual approval only)

There is no self-service registration in v1: no `registration_endpoint`
is advertised and none exists. An operator creates every client with the
bundle command on the deploy host (or any host with the production
environment):

```sh
./bin/console league:oauth2-server:create-client \
  --public \
  --redirect-uri 'http://127.0.0.1:51244/callback' \
  --grant-type authorization_code \
  --grant-type refresh_token \
  --scope api:full \
  'PaySubscriptions CLI'
```

Rules, all enforced by tests — get them wrong and sign-in fails closed:

- **One scope, `api:full`.** It is the only available scope; the server
  rejects empty, unknown, and unapproved scope requests instead of
  defaulting. It grants full read/write access to the approving User's
  subscriptions, categories, and reports: only approve trustworthy apps.
- **Grants are exactly `authorization_code` + `refresh_token`.**
  Client-credentials, password, implicit, and device grants are disabled;
  client credentials must never yield access to User-owned data.
- **Public CLI clients carry no secret** (`--public`). Confidential
  clients post `client_secret_post`; secrets are stored hashed
  (`allow_plaintext_secrets: false`).
- **Redirect URIs match exactly.** The `redirect_uri` in the authorize
  request must equal a registered value byte-for-byte — scheme, host,
  port, and path. Register every loopback port the CLI listens on; a
  CLI that picks a random port per run needs each candidate registered
  or a fixed port. Loopback `http` is allowed for the CLI; anything
  else must be `https`.
- **PKCE is S256-only.** `--allow-plain-text-pkce` must not be set;
  the server rejects `plain` challenges.
- Inspect with `league:oauth2-server:list-clients`, change with
  `league:oauth2-server:update-client`, remove with
  `league:oauth2-server:delete-client`.

## 2. Discovery

Start from the Protected Resource Metadata and follow the links — never
hard-code endpoint URLs:

| Document | URL |
| --- | --- |
| Authorization Server Metadata (RFC 8414) | `/.well-known/oauth-authorization-server` |
| Protected Resource Metadata (RFC 9728) | `/.well-known/oauth-protected-resource` |
| JSON Web Key Set (RFC 7517) | `/.well-known/jwks.json` |

The resource document names the audience every access token carries
(`urn:paysubscriptions:api:v1` — reject anything else) and the issuer
to use; the authorization-server document names `authorization_endpoint`,
`token_endpoint`, `jwks_uri`, `code_challenge_methods_supported`
(`S256`), and `scopes_supported` (`api:full`). The JWKS carries one
RSA `RS256`/`sig` key: public modulus and exponent only.

Out of scope in v1 (not advertised, not implemented): dynamic client
registration, device authorization, token introspection, Userinfo/MCP
endpoints. The `/device-code` route answers `unsupported_grant_type`.

## 3. Browser sign-in (PKCE)

```sh
# 1. Read the endpoints from discovery (never hard-code them).
AUTH_URL=$(curl -fsS https://paysubscriptions.com/.well-known/oauth-authorization-server \
  | php -r '$m=json_decode(stream_get_contents(STDIN),true); echo $m["authorization_endpoint"];')

# 2. Mint a verifier and its S256 challenge.
VERIFIER=$(openssl rand -base64 48 | tr -d '=+/ ' | cut -c1-64)
CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')

# 3. Open this URL in the User's browser. The User signs in with the
#    existing web login if needed, sees the client name and the
#    full-access permission, and allows or denies. Consent is remembered
#    per User, client, and scope; deny returns access_denied and
#    remembers nothing.
#    ${AUTH_URL}?response_type=code&client_id=<CLIENT_ID>
#      &redirect_uri=http%3A%2F%2F127.0.0.1%3A51244%2Fcallback
#      &scope=api%3Afull&state=<RANDOM>&code_challenge=<CHALLENGE>&code_challenge_method=S256

# 4. Exchange the code the redirect delivers (single use, 10-minute TTL,
#    bound to client and verifier).
curl -fsS https://paysubscriptions.com/token \
  -d grant_type=authorization_code -d client_id=<CLIENT_ID> \
  -d redirect_uri='http://127.0.0.1:51244/callback' \
  -d code=<CODE> -d code_verifier="$VERIFIER"
```

The response carries `<ACCESS>` (15 minutes, `aud` = the API audience,
`iss` = the discovery issuer, `kid` = the signing-key label) and
`<REFRESH>`. Call the API with `Authorization: Bearer <ACCESS>`.
Verify the signature against the JWKS first when validating locally.

## 4. Expiry, refresh, and errors

- Access tokens expire after **15 minutes**. Refresh tokens **rotate on
  every use**: each refresh issues a new pair and the previous refresh
  token becomes unusable. Reusing a rotated token revokes the whole
  family (stolen-token containment). Families expire after **30 days
  without use** and no later than **90 days** after authorization;
  refreshing never extends the absolute deadline.
- Refresh:

```sh
curl -fsS https://paysubscriptions.com/token \
  -d grant_type=refresh_token -d client_id=<CLIENT_ID> \
  -d refresh_token=<REFRESH>
```

- Error shape: token-endpoint failures are OAuth2 protocol errors
  (`invalid_grant`, `access_denied`, `unsupported_grant_type`);
  API failures are `application/problem+json` (`401` unauthenticated,
  `403` forbidden, `404` missing/foreign id, `409` name collision,
  category in use, currency conflict, or subscription limit reached,
  `422` validation with field violations). `invalid_grant` on refresh
  always means re-authorize from §3 — never retry the same token.
- Housekeeping: `league:oauth2-server:clear-expired-tokens` purges
  expired codes and tokens.

## 5. Disconnect and residual access

- **User side:** Profile → Connected apps → disconnect. This deletes the
  remembered consent (next authorization asks again) and revokes the
  client's refresh-token families immediately: it cannot refresh or
  mint new access without renewed consent.
- **Client side:** `POST /revoke` (RFC 7009) with the refresh token;
  revokes the token and its usable family.
- **Residual window:** already-issued access tokens stay valid until
  their 15-minute expiry. A disconnected client can still read for at
  most 15 minutes — inherent to self-contained tokens, stated in the
  UI and the client docs. Plan for it; it is not a bug.
- **Account/client lifecycle:** password change or reset revokes the
  User's families; disabling a client revokes its families; deleting
  the account removes grants and token records. Web logout ends only
  the web session — the CLI stays connected.
- **Emergency:** suspected signing-key compromise → rotate per
  `docs/oauth-key-rotation.md` and revoke every family with
  `./bin/console app:oauth:revoke-refresh-families`.

## 6. Secret storage, backup, access, rotation, recovery

| Material | Lives in | Backup |
| --- | --- | --- |
| `OAUTH_PRIVATE_KEY`, `OAUTH_ENCRYPTION_KEY`, `OAUTH_PASSPHRASE` | Production environment secrets only, never git, never the image | Encrypted off-site with the secrets history (e.g. beside `APP_SECRET`); confirm a restore decrypts after every key generation |
| `OAUTH_PUBLIC_KEY` (+ previous during rotation) | Same secrets; the file itself is world-readable by design | Same as above |
| `OAUTH_KEY_ID` / previous ids | Environment variables alongside the keys | Secrets history |

- **Access:** private key files mode `600`, owned by the deploy user,
  readable only by the app runtime; verify with
  `stat -c '%a %U' config/jwt/private.pem`. The committed keypair under
  `tests/Fixtures/oauth/` is a dummy for the suite and must never be
  used outside tests.
- **Routine signing rotation:** new pair + fresh `kid`, publish the old
  public key for 30 minutes (past the 15-minute TTL plus skew), then
  empty the previous-key values. Zero re-authorization; refresh families
  survive untouched.
- **Emergency rotation:** new pair, previous-key values empty (old
  tokens fail at once), revoke all families, clients re-authorize.
- **Encryption rotation:** no overlap — changing the value fails old
  codes and refresh tokens closed with `invalid_grant`; announce a
  low-traffic window, keep the old value in secrets history for a
  mistaken-swap restore. Full procedures in
  `docs/oauth-key-rotation.md`.
- **Recovery:** losing the encryption key without backup strands every
  outstanding code and refresh token (all clients re-authorize);
  losing the private key without backup only costs the `kid` — generate
  a new pair.

## 7. Preflight and smoke checks

After every deploy, in this order:

1. `deploy.yml` already asserts: public `/login` renders with CSRF,
   the database answers from the web container, the three discovery
   documents serve the correct issuer/audience/key, the versioned
   OpenAPI document answers 401 with the `unauthorized` problem
   document behind the bearer boundary, and
   `./bin/console app:api:preflight` passes inside the container
   (keys configured and readable, one JWKS key, discovery and contract
   routes registered, `v1.json` at contract version, database answers).
2. Operator-run authenticated sequence (needs a registered client and
   a test User; do it against production only with the approved test
   client, then disconnect it per §5):

```sh
# token issuance (§3) → authorized reads (resource + versioned contract) → refresh (§4) → revocation (§5)
curl -fsS https://paysubscriptions.com/api/v1/expense-categories -H "Authorization: Bearer <ACCESS>"
curl -fsS https://paysubscriptions.com/api/v1/openapi.json -H "Authorization: Bearer <ACCESS>" | php -r '$d=json_decode(stream_get_contents(STDIN),true); exit(($d["openapi"] ?? null) === "3.0.3" ? 0 : 1);'
curl -fsS https://paysubscriptions.com/token -d grant_type=refresh_token -d client_id=<CLIENT_ID> -d refresh_token=<REFRESH>
curl -fsS https://paysubscriptions.com/revoke -d token=<REFRESH> -d client_id=<CLIENT_ID>
# the revoked refresh token must now fail closed:
curl -s -o /dev/null -w '%{http_code}\n' https://paysubscriptions.com/token \
  -d grant_type=refresh_token -d client_id=<CLIENT_ID> -d refresh_token=<REFRESH>  # expect 400 invalid_grant
```

3. In CI the same surface is covered without secrets: `release-gates`
   boots the prod container and checks discovery, JWKS, the 401
   problem response, and web login; the blocking PHPUnit suite covers
   issuance, two-User isolation, refresh, revocation, report parity,
   and the web login/form flows on every pull request and on `main`.

### When a check fails (issue #101)

A failed deploy smoke or a failed operator CLI path blocks acceptance:
do not approve the release and do not tag a follow-up until the cause
is found. There is no pilot or dark launch to fall back on — the
pipeline ships one tagged image or nothing.

1. Read the structured error logs first. In production every error is a
   single JSON record on the container's stderr
   (`docker compose -f compose.prod.yaml -p paysubscriptions logs web`);
   correlate by timestamp with the failing check. These records never
   carry tokens, authorization codes, signing or encryption material,
   or User data — only statuses, route names, reminder/error ids, and
   exception class/message pairs. If you suspect a signing-key
   compromise from what you see, follow the emergency rotation in
   `docs/oauth-key-rotation.md` instead of the next step.
2. Roll back the image: `deploy.yml` → Run workflow → the previous
   tag (image-only rollback per §8; the schema never rolls back and
   every post-baseline migration is additive, so the old image runs
   against the migrated schema).
3. Re-run the §7 checks against the rolled-back stack, then fix
   forward on a branch: the same checks run as blocking CI
   (`release-gates` + the PHPUnit suite), so the failure must
   reproduce there before another tag.

## 8. Migration rehearsal and image rollback

- CI's `migrations` job replays the byte-for-byte production command
  (`doctrine:migrations:migrate --no-interaction --all-or-nothing
  --allow-no-migration` under `APP_ENV=prod`), loads fixtures onto the
  migrated schema, and validates the schema against the mappings.
- Migrations must stay **additive** — new tables, new nullable or
  defaulted columns, new indexes. `AdditiveMigrationTest` fails the
  build on any `DROP TABLE`/`DROP COLUMN`, rename, or truncate in a
  new migration's `up()`. The one allowlisted exception is the
  pre-release billing-cycle column replacement, which the v1 rollback
  baseline starts after.
- **Rollback** is image-only: the schema never rolls back. Redeploy the
  previous tag (`deploy.yml` → Run workflow → tag `vX.Y.Z`); the old
  image runs against the migrated schema because every post-baseline
  migration is additive. To rehearse on production-like data: snapshot
  the database, migrate a copy, boot the previous image against the
  copy, and exercise reads plus one write per resource.
- Never edit a deployed migration. Schema corrections ship as new
  migrations (see `Version20260927120001` for the pattern).

## 9. Release checklist (one production release, issue #101)

1. All of CI green: `checks`, `phpunit` (existing + new contract,
   additive-migration, and preflight tests), `migrations`,
   `release-gates`, and the main-only `accessibility` scan.
2. `resources/openapi/v1.json` reviewed against the implementation:
   `OpenApiContractTest` locks routes, schemas, filters, security,
   and problem errors, but the human review of wording and examples
   is part of the gate.
3. Secrets provisioned per §6; rotation runbooks read once.
4. Create one `v*` tag only after steps 1–3 pass. The tag builds one
   image (`release.yml`) and deploys it once (`deploy.yml`, which
   migrates, converges, and runs the §7 checks). There is no pilot
   or dark launch: the tagged pipeline is the rollout.
5. After the deploy, run the §7 operator sequence once with the first
   approved CLI: browser sign-in with loopback PKCE (§3), an
   authorized read, a refresh (§4), and a disconnect (§5). Watch the
   structured JSON error logs while it runs; they carry no tokens,
   codes, keys, or User data by design (§7).
6. A failed smoke or CLI path blocks acceptance and invokes the §7
   failure procedure (logs → image rollback per §8 → fix forward in
   CI), not a new tag.
