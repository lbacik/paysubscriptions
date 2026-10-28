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

The Swagger UI on the public `/api/docs` signs in with its own dedicated
public Client (decision #258) — same identifier in every environment, so it
is hard-coded in `config/packages/api_platform.yaml`, not an env var:

```sh
./bin/console league:oauth2-server:create-client \
  --public \
  --redirect-uri 'https://paysubscriptions.com/bundles/apiplatform/swagger-ui/oauth2-redirect.html' \
  --grant-type authorization_code \
  --scope api:full \
  'PaySubscriptions API docs' paysubscriptions-api-docs
```

Rules, all enforced by tests — get them wrong and sign-in fails closed:

- **One scope, `api:full`.** It is the only available scope; the server
  rejects empty, unknown, and unapproved scope requests instead of
  defaulting. It grants full read/write access to the approving User's
  subscriptions, categories, and reports: only approve trustworthy apps.
- **Grants are exactly `authorization_code` + `refresh_token` — except the
  docs Client, which is `authorization_code` only.** Client-credentials,
  password, implicit, and device grants are disabled; client credentials
  must never yield access to User-owned data. The docs Client is the one
  stated exception: it never gets `refresh_token` (League issues no refresh
  token to a Client without that grant), so a docs sign-in yields one
  15-minute access token and no long-lived Client session — Swagger UI does
  not refresh tokens anyway.
- **Public CLI clients carry no secret** (`--public`). Confidential
  clients post `client_secret_post`; secrets are stored hashed
  (`allow_plaintext_secrets: false`).
- **Redirect URIs match exactly.** The `redirect_uri` in the authorize
  request must equal a registered value byte-for-byte — scheme, host,
  port, and path. Register every loopback port the CLI listens on; a
  CLI that picks a random port per run needs each candidate registered
  or a fixed port. Loopback `http` is allowed for the CLI; anything
  else must be `https`. The docs Client's redirect is fixed per
  environment — production
  `https://paysubscriptions.com/bundles/apiplatform/swagger-ui/oauth2-redirect.html`,
  dev `http://localhost:8000/bundles/apiplatform/swagger-ui/oauth2-redirect.html`
  (what Swagger UI builds); `app:api:preflight` checks the deployment's
  value read-only.
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
  API failures are `application/problem+json`. The published contract
  (`resources/openapi/v1.json`, served at `/api/docs.jsonopenapi`) is the
  authoritative list of error statuses and problem types per operation:
  every `/api/v1` operation declares its `401`/`403`, and the operations
  that can fail that way declare their `409`/`422` with the problem
  `type` values as named examples. Generic errors use the
  `/errors/{status}` type (`401` missing or invalid token, `404`
  missing/foreign id, `405` wrong method). A `401` carries a `Bearer`
  challenge (`error="invalid_token"` when the gate itself refuses the
  token). A `403` is either `/problems/insufficient-scope` (with
  `Bearer error="insufficient_scope", scope="api:full"`) or
  `/problems/account-inactive`. Domain conflicts are `409`
  `/problems/*` (`category-in-use`, `currency-conflict`,
  `subscription-limit-reached`); validation failures are `422` with
  field violations. `invalid_grant` on refresh
  always means re-authorize from §3 — never retry the same token.
- Housekeeping: `league:oauth2-server:clear-expired-tokens` purges
  expired codes and tokens.

## 5. Disconnect and residual access

- **User side:** Profile → Connected apps → disconnect. This deletes the
  remembered consent (next authorization asks again) and revokes the
  client's Client sessions immediately: it cannot refresh or
  mint new access without renewed consent.
- **Client side:** `POST /revoke` (RFC 7009) with the refresh token;
  revokes that one Client session (the token and its usable family) and
  keeps the Connection — the next authorization auto-approves.
- **Residual window:** already-issued access tokens stay valid until
  their 15-minute expiry. A disconnected client can still read for at
  most 15 minutes — inherent to self-contained tokens, stated in the
  UI and the client docs. Plan for it; it is not a bug.
- **Account/client lifecycle:** password change or reset revokes the
  User's Client sessions and pending authorization codes but keeps their
  Connections; disabling a client revokes its sessions and codes but keeps
  its Connections; deleting a client ends all of its Connections (a client
  re-registered under the same identifier starts with none); deleting
  the account ends all of the User's Connections. Web logout ends only
  the web session — the CLI stays connected.
- **Emergency:** suspected signing-key compromise → rotate per
  `docs/oauth-key-rotation.md` and revoke every Client session **and**
  pending authorization code with
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
  tokens fail at once), revoke all Client sessions, clients re-authorize.
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
   documents serve the correct issuer/audience/key, the generated
   OpenAPI document serves publicly at `/api/docs.jsonopenapi`, a
   resource without a token answers 401 with the generic `/errors/401`
   problem document behind the bearer boundary, and
   `./bin/console app:api:preflight` passes inside the container
   (keys configured and readable, one JWKS key, discovery and docs
   routes registered, generated `v1.json` at contract version, docs
   Client registered and approved, database answers).
2. Operator-run authenticated sequence (needs a registered client and
   a test User; do it against production only with the approved test
   client, then disconnect it per §5):

```sh
# token issuance (§3) → authorized reads (resource + public contract) → refresh (§4) → revocation (§5)
curl -fsS https://paysubscriptions.com/api/v1/expense-categories -H "Authorization: Bearer <ACCESS>"
curl -fsS https://paysubscriptions.com/api/docs.jsonopenapi | php -r '$d=json_decode(stream_get_contents(STDIN),true); exit(($d["openapi"] ?? null) === "3.2.0" ? 0 : 1);'
curl -fsS https://paysubscriptions.com/token -d grant_type=refresh_token -d client_id=<CLIENT_ID> -d refresh_token=<REFRESH>
curl -fsS https://paysubscriptions.com/revoke -d token=<REFRESH> -d client_id=<CLIENT_ID>
# the revoked refresh token must now fail closed:
curl -s -o /dev/null -w '%{http_code}\n' https://paysubscriptions.com/token \
  -d grant_type=refresh_token -d client_id=<CLIENT_ID> -d refresh_token=<REFRESH>  # expect 400 invalid_grant
```

3. In CI the same surface is covered without secrets: `release-gates`
   boots the prod container and checks discovery, JWKS, the public
   docs, the 401 problem response, and web login; the blocking PHPUnit
   suite covers issuance, two-User isolation, refresh, revocation,
   report parity, and the web login/form flows on every pull request
   and on `main`.

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
  (`doctrine:migrations:migrate --no-interaction
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
   the generated document is the published v1 contract —
   `OpenApiSnapshotTest` locks it byte-for-byte (regenerate manually
   with `bin/console api:openapi:export
   --output=resources/openapi/v1.json`) and `ApiOperationsGateTest`
   locks every operation behind the 401/403 gate — but the human
   review of wording and examples is part of the gate.
3. Secrets provisioned per §6; rotation runbooks read once. The
   `paysubscriptions-api-docs` Client from §1 is registered (production
   redirect URI) — `app:api:preflight` verifies it read-only.
4. Create one `v*` tag only after steps 1–3 pass. The tag builds one
   image (`release.yml`) and deploys it once (`deploy.yml`, which
   migrates, converges, and runs the §7 checks). After a successful
   deploy, `release.yml`'s `dispatch-sdk-release` job notifies the
   SDK generator (§10). There is no pilot
   or dark launch: the tagged pipeline is the rollout. In the tag's
   Actions run, check the `dispatch-sdk-release` job: green means
   the generator was notified, a `::notice::` in its log means the
   dispatch is disabled (no secret yet) and was skipped, and red
   means the notification failed (the deploy itself is unaffected).
5. After the deploy, run the §7 operator sequence once with the first
   approved CLI: browser sign-in with loopback PKCE (§3), an
   authorized read, a refresh (§4), and a disconnect (§5). Watch the
   structured JSON error logs while it runs; they carry no tokens,
   codes, keys, or User data by design (§7).
6. A failed smoke or CLI path blocks acceptance and invokes the §7
   failure procedure (logs → image rollback per §8 → fix forward in
   CI), not a new tag.

## 10. SDK generator dispatch (`SDK_GENERATOR_DISPATCH_TOKEN`)

After every successful tag-triggered deploy, `release.yml`'s
`dispatch-sdk-release` job sends one `repository_dispatch` with
`event_type: api-release` to the SDK generator's canonical
repository, `lbacik/jsonhub-sdk-generator`. The payload carries
only `client_payload.product` (`paysubscriptions`) and
`client_payload.source_api_version` (the pushed tag) — no
`api_url`. The generator cuts an SDK Release of
`paysubscriptions-sdk` with the tag as its Source API Version.
Manual redeploys and rollbacks (`deploy.yml` → Run workflow) never
dispatch: the job lives in `release.yml` behind `needs: deploy` for
exactly that reason. A failed notification fails only the
`dispatch-sdk-release` job — the deploy above it stays green.

- **Token.** A fine-grained personal access token scoped to the
  `lbacik/jsonhub-sdk-generator` repository with Contents: Read and
  write. That permission also allows pushing to the generator —
  accepted knowingly, since `repository_dispatch` delivery requires
  the Contents write scope.
- **Storage.** A secret of the `production` environment in this
  repository, named `SDK_GENERATOR_DISPATCH_TOKEN`. The dispatch
  job names the `production` environment so it can read it; while
  the secret is empty the job logs a `::notice::` and succeeds
  without sending anything.
- **Expiry and rotation.** The token expires after one year. Before
  it lapses, mint a replacement with the same repository scope and
  Contents permission, overwrite the environment secret, and delete
  the old token. If it lapses first, dispatches fail red until the
  replacement lands — recover per the last bullet below.
- **Rollout switch and kill switch.** Adding the secret is the
  rollout switch (this can merge before the generator side is
  ready); removing it (or emptying it) is the kill switch — the
  dispatch goes back to notice-and-skip without touching the
  workflow.
- **Prerequisites before adding it.** Do not store the secret until
  all of these hold: the generator requires
  `client_payload.product`; the `paysubscriptions` Product exists in
  the generator; the `lbacik/paysubscriptions-sdk-ts` repository
  exists; and the generator has its `PAYSUBSCRIPTIONS_SDK_TS_TOKEN`
  secret to push the generated SDK there.
- **Failed dispatch recovery.** Open the tag's Actions run and use
  "Re-run failed jobs" on the red `dispatch-sdk-release` job — it
  re-sends the same `product` + `source_api_version` pair, and a
  duplicate dispatch is harmless (the generator cuts the same SDK
  Release again for the same Source API Version). If the generator
  repository is ever renamed, the dispatch URL in `release.yml`
  must be updated to the new canonical name: the POST never follows
  redirects.
