# About PaySubscriptions

A streaming service here, a software subscription there — it is easy to lose sight of the total.
PaySubscriptions is a free, manual-first tracker for one person keeping on top of their own
subscriptions and household recurring costs. You enter what you pay — with no bank connection
and no inbox scan — so you can see what renews next and act in time.

It is a free subscription tracker for individuals and households. Each account belongs to exactly
one person: you can track subscriptions used by the people you live with, but there is no shared
account, no invitation, and no access for other household members. There are no paid plans and
nothing to upgrade to.

## How it works today

The product tracks only the details you add yourself: a manual subscription list with monthly
and yearly cost equivalents in a single-owner account. These figures are calculated from your
entries, not imported transactions or confirmation that a payment was made.

Upcoming dates, optional reminders, categories, a published data-handling policy and self-service
account deletion are release goals for v1.0, pending implementation and verification.

[Create your free account](/register) or [check the free plan and its limit](/pricing).

## You choose what to track

PaySubscriptions does not connect to your bank, scan your inbox, or automatically discover
subscriptions — and it cannot cancel anything with a provider for you. Removing an entry from
your list removes it from your overview only.

## Data handling in brief

Account and subscription data is not sold. Running the service involves a small number of
external services — including hosted Umami page-view analytics alongside interface fonts, icon
delivery, contact-form spam protection, and mail delivery — and each receives only the data it
needs to do its job. The product-updates newsletter is a separate opt-in to the shared
gprodb.com list with its own unsubscribe path.

There is no application-level field encryption at rest: names, amounts, notes, and your email
address are stored as plain database columns, protected by access control on the account and the
servers. Only your password gets stronger treatment — it is stored as a one-way hash and cannot
be read back.

You can delete your account yourself from the account deletion page; deletion takes effect
immediately and cannot be undone, and it does not unsubscribe the separate newsletter list.
A copy of your data is available as a manual email request — there is no self-service download:
send the contact form from your account email address with the subject “Data export request”.

The full account lives in the [published privacy policy](/privacy).

## Where the project is heading

The public roadmap moves by outcome, without dates or feature promises. Until the v1.0 release
gates pass, treat Now as the v1.0 goal, not as a description of the current product.

- **Now · v1.0 goal:** know what is coming due — manual organization, renewal dates, clear monthly
  and yearly totals, optional reminders, and control over account data.
- **Next · explore:** make records easier to keep current — possible import and self-service
  export, subject to feedback.
- **Later · explore:** make longer-range planning easier — possible automatic currency conversion
  and change history.

Next and Later are candidate directions with no dates and no guaranteed order. Bank, inbox, and
provider integrations remain outside v1.0.

## Help shape PaySubscriptions

What would make it easier to keep your subscriptions under control? [Send feedback or ask for help](/contact). Real examples of how you track subscriptions help guide what gets built next.
