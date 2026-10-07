# API v1 client guide (OAuth2 discovery and sign-in)

How an approved client discovers the authorization server, signs a User in,
and calls the protected API. Client registration, the shell PKCE walkthrough,
token refresh and revocation, disconnect behavior, and every operator
procedure (secret storage, rotation, migration rehearsal, rollback) live in
the operations runbook, `docs/api-operations.md`.

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
