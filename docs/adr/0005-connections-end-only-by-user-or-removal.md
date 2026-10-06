# A Connection ends only when the User disconnects or a party is removed

Seven triggers revoke API access (User disconnect, a Client's RFC 7009 `/revoke`, password change, Client deactivation, Client removal, account deletion, and the emergency key-compromise command), and they disagreed on what happened to the remembered consent ([issue #135](https://github.com/lbacik/paysubscriptions/issues/135)). We decided that a Connection (see `CONTEXT.md`) ends, so that the next authorization shows the consent screen again, only when the User disconnects it or when the User or the Client is removed. Every other trigger is a security or sign-out event: it revokes the affected Client sessions and pending authorizations, but the Connection stands, because signing in again already requires the User's password in the browser. Whatever a trigger revokes, no Client session or pending authorization may outlive its Connection.

| Trigger | Effect |
|---|---|
| User disconnects | ends that Connection |
| Client calls `/revoke` | revokes that one Client session |
| Password change | revokes all of the User's Client sessions and pending authorizations |
| Client deactivated | revokes all of the Client's Client sessions and pending authorizations |
| Client removed | ends all of the Client's Connections |
| Account deleted | ends all of the User's Connections |
| Emergency command | revokes every Client session and pending authorization |

## Considered Options

- **`/revoke` forgets the consent** (the [#92](https://github.com/lbacik/paysubscriptions/issues/92) behavior, reversed here). RFC 7009 revokes one token, so a CLI logout on one machine would withdraw the User's approval while the same Client's sessions on other machines stayed live without a Connection. Making `/revoke` end the whole Connection instead would let one installation sign out every other one.
- **Every revocation ends the Connection.** This is simpler to state, but it turns routine security hygiene (changing a password, temporarily deactivating a Client) into re-consent for every Connected app with no security gain.

## Consequences

- Already-issued access tokens stay valid until their short expiry after any trigger ([#86](https://github.com/lbacik/paysubscriptions/issues/86)); only Client activity is re-checked per request. Checking that the Connection still exists on every request is a separate, later decision.
- A Client re-registered under a removed identifier starts with no Connections.
