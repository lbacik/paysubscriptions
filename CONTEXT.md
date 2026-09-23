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
A recurring cost a User tracks: a name, a first-payment date, a monthly-or-yearly amount, and a required expense category. Owned by exactly one User.
_Avoid_: Bill

**ExpenseCategory**:
A User-owned label with a name and a color tag used to group Subscriptions (e.g. the default `Subscriptions` category every account starts with). Each User manages their own set: names are unique per User, every Subscription belongs to exactly one category owned by the same User, and a category in use cannot be deleted until its Subscriptions are reassigned.
_Avoid_: Tag, label, group
