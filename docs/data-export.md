# Manual-request data export

Fulfillment procedure for a User's emailed data-export request (issue #48,
part of #34; sources #30, #25, #28). Self-service export is explicitly
deferred: there is no download button anywhere in the product, and public
wording must keep saying so.

## Request route

The single request route is the contact process:

- Signed-in Users find it on the account-deletion page (`/account/delete`),
  which links to the contact form and names export as a manual email request.
- The contact page (`/contact`) explains how to ask: send the form **from the
  account's email address** with the subject **“Data export request”**.
- Submissions land in the monitored `CONTACT_EMAIL` mailbox (see
  `ContactController`, which sends via Symfony Mailer from `SYSTEM_EMAIL`).

Keep this route separate from the newsletter list: the product-updates list
is a shared `gprodb.com` opt-in on an external provider
(`MailingSubscriptionService`). An export request never subscribes,
unsubscribes, or discloses that list.

## Fulfillment procedure

Follow these steps in order for every request. Do not skip verification.

1. **Intake.** Read the message in the `CONTACT_EMAIL` mailbox. A valid
   request states (or clearly implies) “data export request” and arrives
   from the requester's account email address.
2. **Verify identity before releasing anything.** Confirm the sender address
   exactly matches the `User.email` on record (case-insensitive comparison
   is fine; partial or “similar” addresses are not). If it does not match,
   or the account does not exist, reply asking the requester to resend from
   the account address — never send data to an unverified address, and never
   confirm or deny what another address holds beyond what is needed to
   redirect the requester.
3. **Export only that User's records.** On a machine with production access,
   run for the verified address only:
   ```sh
   php bin/console user:export requester@example.com --output /tmp/paysub-export-<date>.json
   ```
   The command emits pretty-printed JSON (`paysubscriptions-user-export`,
   version 1) with the account profile, the User's Subscriptions (with
   their categories), ExpenseCategories, and Limits. Before sending, open
   the file and sanity-check that every `ownerEmail` equals the verified
   address and that no other address appears.
4. **What is included — and what never is.**
   - Included: account email, verification flag, main currency, timezone,
     reminder preferences, timestamps; the User's Subscriptions (name,
     billing cycle, amounts, currencies, next payment dates, notes,
     category name/color, timestamps); the User's ExpenseCategories;
     the Subscriptions limit.
   - Never included: password hashes, roles, password-reset tokens,
     reminder send-state, messenger queue rows, any other User's records,
     application secrets, or the newsletter list. The exporter
     (`UserDataExportService`) excludes these by construction; the
     pre-send check in step 3 is the backstop.
5. **Deliver securely.** Reply to the verified account address only, attach
   the JSON file, keep no other recipients in To/Cc, and use the same mail
   channel as other account mail. After the reply is accepted for delivery,
   securely delete the local file copy (e.g. `shred -u` or empty-trash on
   managed hosts) so the export does not linger on disk.
6. **Record completion without personal data.** Log the request in the
   operator log (date, channel, “verified sender == account address”,
   command run, delivery confirmation, local-copy deletion). Record
   outcome, not content: never paste the export, the address, or
   subscription details into the log or the issue tracker.

## Operator exercise (test User, no personal data)

Anyone operating this process should rehearse it before a real request:

1. Create a throwaway User with a clearly fake address (e.g.
   `export-drill+<yyyymmdd>@example.com`), add one or two Subscriptions.
2. Send a “Data export request” through the contact form from that address.
3. Work steps 1–6 above end to end, delivering to the throwaway address.
4. Delete the throwaway account afterwards via the normal deletion flow.
5. Record evidence as: date, drill identifier (not the address), “sender
   matched account”, command exit status, “single-owner JSON verified”,
   “delivered”, “local copy deleted”, “throwaway account deleted”. File
   the note on the tracking issue without any address or export content.

## Copy rules for public wording

Wherever export is mentioned (account pages, contact page, and — once it
lands — the privacy policy in #49): call it a **manual email request**,
never a self-service download; name the contact form as the route; and
state that the newsletter list is separate and never included.
