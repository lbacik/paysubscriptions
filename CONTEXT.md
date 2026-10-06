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
A recurring cost a User tracks: a name, exactly one billing cycle (monthly or yearly), an amount charged per cycle, a user-entered next payment date, and a required expense category. Owned by exactly one User.
_Avoid_: Bill

**ExpenseCategory**:
A User-owned label with a name and a color swatch used to group Subscriptions (e.g. the default `Subscriptions` category every account starts with). Each User manages their own set: names are unique per User, every Subscription belongs to exactly one category owned by the same User, and a category in use cannot be deleted until its Subscriptions are reassigned.
_Avoid_: Tag, label, group

**Billing cycle**:
How often a Subscription charges: monthly or yearly. Exactly one per Subscription; the amount is always priced per that cycle.
_Avoid_: Plan type, frequency (ambiguous next to renewal dates)

**Next payment date**:
The user-entered next known payment date of a Subscription. It is the anchor from which renewals are computed; the User edits it whenever the known date changes.
_Avoid_: First payment (legacy name), due date

**Renewal** (upcoming renewal):
A future payment date computed from the next payment date anchor by stepping whole billing cycles while preserving the anchor's calendar day. An occurrence landing in a month without that day uses that month's last day for that occurrence, and later occurrences return to the anchor day when possible (a January 31st monthly anchor renews February 28th/29th, then March 31st; a February 29th yearly anchor renews February 28th except in leap years). An anchor in the past rolls forward to the first occurrence on or after the reference day.
_Avoid_: Billing date, charge date

**Recipient restriction**:
The current SES deliverability state of one normalized email address: *undeliverable* after a permanent bounce, *do-not-send* after a complaint. Only SES feedback creates or tightens it; no later event loosens it. Only an audited operator action with a reason clears it, and it never changes email verification or password-reset state. It belongs to the address, not the User, so a User who changes address starts unrestricted. Restricted addresses receive no email.
_Avoid_: Blocklist, suppression (SES keeps its own suppression list per tenant)
