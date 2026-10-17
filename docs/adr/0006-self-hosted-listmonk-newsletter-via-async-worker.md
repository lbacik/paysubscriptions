# Self-hosted listmonk for the newsletter, fed by the async worker

The product-updates newsletter was forwarded to an external mailing list over an AMQP exchange. We switch the provider to self-hosted listmonk on our own server, reached by the worker over the internal `main` network: the footer form dispatches `MailingSubscribe` on `async`, and the worker POSTs the subscriber email to the listmonk API. Listmonk owns the double opt-in: every signup receives a confirmation email and is subscribed only after clicking the confirmation link. Newsletters go out through Amazon SES with no tracking pixels or tracked links enabled.

There is no migration and no dual-write. The old list held its own opt-ins under the previous provider's confirmation flow, and replaying those addresses into listmonk would subscribe people who never confirmed with us; instead, everyone starts unsubscribed and only new double opt-ins populate the list. A single cutover keeps the footer, privacy policy, and data-handling docs describing one list instead of two overlapping ones during a transition window.

The newsletter stays a separate opt-in with its own unsubscribe path: creating an account never subscribes, deleting an account never unsubscribes, and data export never touches the list.
