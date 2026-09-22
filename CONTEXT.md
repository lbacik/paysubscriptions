# PaySubscriptions

Tracks an individual's recurring, subscription-like costs so they can see what they're paying and act before it renews.

## Language

**User**:
The single owner of an account, identified by email. Owns their own set of Subscriptions directly; there is no shared or multi-user account.
_Avoid_: Account holder, member

**Household**:
Product-messaging term for the audience a User represents: one person tracking recurring costs shared across the people they live with (e.g. rent, family subscriptions). Not a distinct domain entity — there is no multi-user grouping, invitation, or shared ownership. A Household is always exactly one User.
_Avoid_: Family account, shared account, organization

**Subscription**:
A recurring cost a User tracks: a name, a first-payment date, and a monthly-or-yearly amount. Owned by exactly one User.
_Avoid_: Bill, expense (until a broader "expense category" concept is decided — see the v1.0 feature-set ticket)
