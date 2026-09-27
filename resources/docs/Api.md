# API access for CLI clients

Connect a command-line client to your PaySubscriptions data through the
versioned REST API. Access is User-delegated: you approve each client once in
your browser with an authorization code and S256 PKCE, and the client then
uses short-lived tokens. Only manually registered clients can connect, and
every client receives the single `api:full` scope: full read and write access
to your subscriptions, expense categories, and reports. Only approve
applications you trust.

## Connecting a client

Authorizing a client opens the consent screen in your browser. Approving
remembers your choice for that application: later authorizations skip the
screen unless the application's registered identity or requested permissions
change. Denying sends the client back with an `access_denied` error and
remembers nothing.

## Access and refresh tokens

Access tokens expire after 15 minutes. Refresh tokens rotate on every use:
each refresh issues a new pair and the previous refresh token becomes
unusable. Reusing an already-rotated refresh token revokes the whole family,
so a leaked token cannot extend access. A token family expires after 30 days
without use and no later than 90 days after the initial authorization;
refreshing never extends the absolute deadline.

## Disconnecting an application

Open your connected applications from the account menu and disconnect any
client. Disconnecting deletes your remembered consent — the client asks for
consent again next time — and revokes its refresh-token families immediately:
the disconnected client cannot refresh or obtain new access without your
renewed consent.

Access tokens already issued stay valid until they expire, at most 15 minutes
after you disconnect. This is inherent to self-contained tokens: plan for a
short residual window in which a disconnected client can still read your data.

## Revoking from the client (RFC 7009)

A client revokes its own refresh token with the authenticated revocation
endpoint:

```sh
curl -X POST https://your-host/revoke \
  -d token=<refresh_token> \
  -d token_type_hint=refresh_token \
  -d client_id=<client_id>
```

Confidential clients additionally send their `client_secret`. The endpoint
revokes the whole usable family behind the presented refresh token, so every
other usable token of that family stops refreshing as well, and forgets the
remembered consent: the client asks for your consent again before accessing
your data. Unknown tokens and access tokens succeed without effect:
self-contained access tokens cannot be revoked and stay usable until their
15-minute expiry.
