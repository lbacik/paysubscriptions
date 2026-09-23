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
A recurring cost a User tracks: a name, exactly one billing cycle (monthly or yearly), an amount charged per cycle, and a user-entered next payment date. Owned by exactly one User.
_Avoid_: Bill, expense (until a broader "expense category" concept is decided — see the v1.0 feature-set ticket)

**Billing cycle**:
How often a Subscription charges: monthly or yearly. Exactly one per Subscription; the amount is always priced per that cycle.
_Avoid_: Plan type, frequency (ambiguous next to renewal dates)

**Next payment date**:
The user-entered next known payment date of a Subscription. It is the anchor from which renewals are computed; the User edits it whenever the known date changes.
_Avoid_: First payment (legacy name), due date

**Renewal** (upcoming renewal):
A future payment date computed from the next payment date anchor by stepping whole billing cycles while preserving the anchor's calendar day. An occurrence landing in a month without that day uses that month's last day for that occurrence, and later occurrences return to the anchor day when possible (a January 31st monthly anchor renews February 28th/29th, then March 31st; a February 29th yearly anchor renews February 28th except in leap years). An anchor in the past rolls forward to the first occurrence on or after the reference day.
_Avoid_: Billing date, charge date
