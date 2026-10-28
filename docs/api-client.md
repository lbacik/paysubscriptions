# API v1 client guide (OAuth2 discovery and sign-in)

How an approved client discovers the authorization server, signs a User in,
and calls the protected API. Client registration, the shell PKCE walkthrough,
token refresh and revocation, disconnect behavior, and every operator
procedure (secret storage, rotation, migration rehearsal, rollback) live in
the operations runbook, `docs/api-operations.md`.

## Interactive docs and the published contract

- **Swagger UI** at `/api/docs` is public: open it in a browser with no
  token, then sign in with the `api:full` scope — it uses the
  authorization-code + S256 PKCE flow through the dedicated
  `paysubscriptions-api-docs` Client and keeps the token in memory only
  (nothing persisted). Calling the API from the docs still needs the
  sign-in; reading the docs does not.
- **The generated OpenAPI document** is the published API v1 contract:
  `/api/docs.jsonopenapi` serves it publicly (the HTML page serves the
  same document through Swagger UI; the `^/api/docs` public-access rule
  also covers the `.json` variant, which API Platform answers itself),
  and `resources/openapi/v1.json` in the repository carries the identical
  snapshot. Additive backward-compatible changes stay within v1; breaking
  changes require a new version.

## Discovery URLs

All three documents are public (`GET`, no token) and live outside the
`/api/v1` resource prefix:

| Document | URL | Standard |
| --- | --- | --- |
| Authorization Server Metadata | `/.well-known/oauth-authorization-server` | RFC 8414 |
| Protected Resource Metadata | `/.well-known/oauth-protected-resource` | RFC 9728 |
| JSON Web Key Set | `/.well-known/jwks.json` | RFC 7517 |

A client starts from the Protected Resource Metadata: its `resource` value is
the PaySubscriptions API audience (`urn:paysubscriptions:api:v1`), the exact
`aud` claim every access token carries — reject tokens with any other
audience. Its `authorization_servers` value points at the issuer whose
Authorization Server Metadata names the `authorization_endpoint`,
`token_endpoint`, and `jwks_uri` to use. The JWKS carries only public
verification material (RSA `n`/`e`, `RS256`, `sig`); there is exactly one key
in v1.

## Sign-in (approved public CLI)

1. `GET` the Authorization Server Metadata and read `authorization_endpoint`,
   `token_endpoint`, `code_challenge_methods_supported` (`S256` only), and
   `scopes_supported` (`api:full` only).
2. Open `authorization_endpoint` with `response_type=code`,
   `scope=api:full`, an S256 `code_challenge`, your registered `client_id`
   and `redirect_uri`, and a `state`. The User signs in with the existing web
   login if needed, sees your client name and the full-access permission, and
   allows or denies. The Connection — the User's standing approval for your
   client — is remembered per User, client, and scope.
3. `POST` the `token_endpoint` with `grant_type=authorization_code` and the
   `code_verifier`. The response carries a 15-minute Bearer access token
   (`aud` = the API audience, `iss` = the issuer from discovery) and a
   refresh token.
4. Call the API with `Authorization: Bearer <access_token>` and verify the
   token signature against the JWKS first when validating locally.

Clients are manually approved: there is no self-service registration. Public
(CLI) clients send no secret (`token_endpoint_auth_methods_supported`:
`none`); confidential clients post `client_secret_post`.

## Outside v1 (not advertised, not implemented)

- **Dynamic client registration** (RFC 7591): no `registration_endpoint`
  exists and none is advertised. Client records are created by an operator.
- **Device authorization** (RFC 8628): no `device_authorization_endpoint`
  is advertised. The `/device-code` route answers `unsupported_grant_type`
  because the device grant is disabled; do not use it.
- **Token revocation** (RFC 7009): implemented at `POST /revoke` but no
  `revocation_endpoint` is advertised in discovery — call the documented
  URL (see `docs/api-operations.md` §5). Revoking a refresh token ends
  that one Client session and keeps the Connection — the next
  authorization auto-approves. Already-issued access tokens stay valid
  until their 15-minute expiry. Connections end through the app
  (Profile → Connected apps): disconnecting ends the Connection, so the
  next authorization shows the consent screen again.
- **Token introspection** (RFC 7662): no endpoint exists and none is
  advertised.
- **MCP endpoint**: no Model Context Protocol surface exists in API v1.
- **Userinfo**: no OpenID Connect `userinfo_endpoint` is advertised; the API
  never operated as an OpenID provider.

## TypeScript SDK

The generated `paysubscriptions-sdk` is a thin HTTP client over the
published contract (`resources/openapi/v1.json`, served at
`/api/docs.jsonopenapi`). It owns no OAuth logic: discovery, PKCE,
refresh, and revocation stay the consumer's job — the sign-in, refresh,
and revocation details live in the operations runbook
(`docs/api-operations.md` §§2–5) and are not repeated here. The SDK's
generated README is overwritten on every SDK Release, so this section
is the stable guide; the generator's package metadata uses this
section's heading anchor (`#typescript-sdk`) as the package `homepage`.

### Pointing at an instance

Construct the SDK with `basePath` set to the instance origin only
(e.g. `https://paysubscriptions.com`), because every operation path
already starts with `/api/v1`. The SDK has no default instance:
omitting `basePath` silently targets `http://localhost`. The
production origin is `https://paysubscriptions.com` — the same origin
a CLI starts OAuth discovery from.

### Supplying the token

Pass `accessToken` as an async provider that returns the raw access
token; the SDK adds the `Bearer ` prefix itself, so never include it
in the returned value. The provider is called on every request, so it
must cache the token in memory and refresh it ahead of the 900-second
(15-minute) access-token expiry rather than requesting a new token per
call. Where the fresh token comes from (in-memory cache, refresh flow)
is the consumer's decision — the SDK only asks for the current value.

### Recovering from an expired token

Add a `post` middleware that handles one case only: a `401` whose
`WWW-Authenticate` response header carries `error="invalid_token"`.
Then refresh the access token once and replay the failed request with
the new `Authorization` value. If the replayed request answers `401`
again, or the refresh itself is answered with `invalid_grant`, stop
retrying: the User must sign in again, because of an ended Connection,
an inactive Client, or a revoked refresh token (see
`docs/api-operations.md` §§4–5 for what ends each).

A `403` never triggers a refresh: `/problems/insufficient-scope`
means the token lacks the `api:full` scope and
`/problems/account-inactive` means the account may not use the API —
a fresh token for the same User and Client would fail the same way.

### Reading errors

Every non-2xx response arrives as a `ResponseError`. Read the problem
document from `await e.response.json()` and branch on its `status`
and `type` fields:

```ts
import { ResponseError } from 'paysubscriptions-sdk';

try {
  await subscriptionsApi.apiV1SubscriptionsPost({ subscription: draft });
} catch (e) {
  if (e instanceof ResponseError) {
    const problem = await e.response.json();
    if (problem.type === '/problems/subscription-limit-reached') {
      // per-User Subscription limit reached (409)
    } else if (problem.status === 409) {
      // '/problems/category-in-use', '/problems/currency-conflict'
    } else if (problem.status === 422) {
      for (const v of problem.violations ?? []) {
        console.error(v.propertyPath, v.message);
      }
    }
  }
  throw e;
}
```

Conflict (`409`) problem types are `/problems/category-in-use`,
`/problems/currency-conflict`, and
`/problems/subscription-limit-reached`; validation failures (`422`)
carry a `violations` array of `{ propertyPath, message }` pairs (only
the `/validation_errors/` type prefix is stable). The contract's
per-operation examples are the full list of statuses and `type`
values — generic failures use the `/errors/{status}` type.

### Representations: plain JSON for one resource, HAL for collections

Request `application/json` for any response carrying one resource
(single-item reads, creates, updates, and the Dashboard report) and
`application/hal+json` for collections, which carry pagination links
in `_links` (`self`, `first`, `last`, `next`, `prev`) and their
members in `_embedded.item`. The ExpenseCategory list is the
unpaginated HAL collection; resolve a Subscription list item's
ExpenseCategory from its `_links.category.href` value against that
list.
