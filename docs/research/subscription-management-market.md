# Subscription-management market and v1.0 expectations

Research date: 3 September 2026  
Wayfinder question: For an individual- and household-focused, manual-first subscription tracker maintained by one person, which user jobs and feature patterns recur across credible sources and comparable products?

## Scope and method

This report compares first-party product pages and help documentation for focused subscription trackers (Bobby, Subby, SubManager, Suby, SubBuddy, and TrackMySubs) and broader personal-finance products with subscription features (Rocket Money, Monarch, and Quicken Simplifi). It checks those patterns against consumer guidance from the US Federal Trade Commission (FTC), US Consumer Financial Protection Bureau (CFPB), and European Commission, and against official platform documentation for integrations whose cost or risk matters.

The classifications below are planning inputs, not a decision about PaySubscriptions v1.0:

- **Table-stakes candidate** means that the capability recurs across several comparables or is needed to complete the core tracking job safely.
- **Meaningful differentiator** means that it could distinguish a manual-first product without changing the product category entirely.
- **Poor v1.0 candidate** means that it materially expands privacy, security, integration, support, or operational responsibilities for a solo maintainer. It may still be valuable later.

The comparison is directional rather than exhaustive. Product features and platform requirements can change; any selected feature should be rechecked during implementation planning.

## Executive synthesis

The market converges on a simple loop:

1. Capture recurring commitments in one inventory.
2. Normalize the cost so the user understands the monthly and annual burden.
3. Surface the next charges and important deadlines.
4. Remind the user early enough to decide.
5. Help the user keep, pause, or cancel deliberately.
6. Preserve enough context to verify that the intended change really happened.

Focused manual trackers repeatedly provide a subscription list, flexible billing cycles, upcoming renewals, reminders, totals, and basic organization. [SubManager](https://submanager.app/) combines one list, renewal reminders, monthly/yearly spending insights, and category or payment-method breakdowns. [Subby](https://play.google.com/store/apps/details?id=com.slapp.subby&hl=en-US) combines custom entries and billing cycles with renewal and trial reminders, totals, trend reporting, and payment history. [TrackMySubs](https://trackmysubs.com/how-it-works/) adds configurable alerts, calendar/list views, folders, tags, export, trial/refund dates, and currency conversion. [SubBuddy](https://subbuddy.io/en/features) presents a richer version of the same loop through status, renewal, calendar, analytics, import, and household features.

Regulator guidance reinforces the same practical outcomes. The FTC tells consumers to record trial deadlines, review renewal notices and expected prices, learn how cancellation works, retain cancellation records, and monitor later charges ([FTC subscription guidance](https://consumer.ftc.gov/articles/getting-and-out-free-trials-auto-renewals-and-negative-option-subscriptions)). The CFPB distinguishes revoking automatic payment authorization from cancelling a contract and likewise advises consumers to keep copies and monitor later charges ([CFPB automatic-payment guidance](https://www.consumerfinance.gov/ask-cfpb/how-do-i-stop-automatic-payments-from-my-bank-account-en-2023/)).

This evidence leaves room for a focused position: a calm, privacy-conscious manual tracker can be a deliberate alternative to products that ingest bank transactions or inbox contents. Subby explicitly markets no bank connection and local data as a privacy benefit, while the European Commission's GDPR guidance requires purpose limitation, data minimisation, storage limitation, and appropriate security ([European Commission GDPR principles](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/principles-gdpr_en)).

## Recurring user jobs

| User job | Evidence and interpretation |
| --- | --- |
| Capture every recurring commitment in one trusted inventory | SubManager promises one subscription list; Rocket Money and Monarch place detected and manually added recurring items in a dedicated recurring area ([Rocket Money](https://help.rocketmoney.com/en/articles/2185531-managing-your-bills-and-subscriptions), [Monarch](https://help.monarch.com/hc/en-us/articles/4890751141908-Tracking-Recurring-Expenses-and-Bills)). A manual-first product must make custom entry quick because it cannot rely on transaction discovery. |
| Understand the true recurring cost | SubManager exposes month/year totals and a monthly average; Subby exposes monthly/yearly totals and trends; TrackMySubs reports monthly, quarterly, and annual spending. Normalization across billing intervals is therefore central, not optional analytics. |
| Know what will charge next and when | Upcoming lists and calendars recur in Rocket Money, Monarch, SubManager, Suby, SubBuddy, and TrackMySubs. Annual renewals and uneven months need to be visible alongside monthly subscriptions. |
| Avoid surprise renewals, free-trial conversions, and promotion endings | The FTC explicitly advises consumers to put trial deadlines on a calendar and check whether a renewal price is expected. Subby includes trial reminders; Suby tracks introductory pricing and price-change dates; SubBuddy provides trial alerts and configurable reminder timing ([FTC](https://consumer.ftc.gov/articles/getting-and-out-free-trials-auto-renewals-and-negative-option-subscriptions), [Suby](https://subyapp.com/features), [SubBuddy](https://subbuddy.io/en/features)). |
| Review what to keep, pause, or cancel | Focused trackers expose inactive, paused, cancelled, or hidden states rather than forcing deletion. Bobby's official App Store release history records temporary disable/hide/mute support, while SubBuddy separates active, trial, paused, and cancelled records ([Bobby App Store listing](https://apps.apple.com/us/app/bobby-track-subscriptions/id1059152023), [SubBuddy](https://subbuddy.io/en/features)). |
| Act on a cancellation and verify the outcome | FTC and CFPB guidance recommends finding the provider's instructions, keeping the request and related notes, and checking later statements. The tracker should support the user's follow-through without implying that an in-app status change cancelled an external contract or payment. |
| Find and maintain records as the inventory grows | Categories, tags, status filters, sorting, and search recur across Bobby, Subby, Suby, SubBuddy, and TrackMySubs. These are most valuable once a household has enough commitments that a flat list becomes hard to audit. |
| Coordinate household responsibility | Suby offers shared-subscription tracking, SubBuddy advertises family settings, and Monarch gives household members separate logins over shared financial data ([Suby](https://subyapp.com/features), [SubBuddy](https://subbuddy.io/en/features), [Monarch](https://help.monarch.com/hc/en-us/articles/360048393272-Getting-Started-Guide)). The underlying job is knowing who pays for or uses a commitment; full collaboration is only one possible implementation. |
| Retain control of personal data | TrackMySubs and SubBuddy support export, while Subby provides local export/backup. For EU users, data access, correction, erasure, portability, minimisation, and retention are trust and compliance concerns, not merely premium conveniences ([European Commission: individual rights](https://commission.europa.eu/law/law-topic/data-protection/information-individuals_en), [GDPR principles](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/principles-gdpr_en)). |

## Table-stakes candidates

These are the strongest recurring expectations for the focused product category. They remain candidates until a later Wayfinder decision selects the actual scope.

### 1. Complete manual subscription record

Candidate fields and actions:

- Provider or custom name.
- Amount and original currency.
- Billing interval that supports monthly, yearly, and less regular recurring schedules.
- Next renewal or next charge date; optionally a start date.
- Create, edit, archive/cancel, restore, and delete.
- Optional notes and provider URL.

Rocket Money and Monarch both retain manual entry even though their main experience can detect transactions automatically. Rocket Money's manual flow asks for name, next due date, and amount; Monarch lets the user edit amount, frequency, and date ([Rocket Money](https://help.rocketmoney.com/en/articles/2185531-managing-your-bills-and-subscriptions), [Monarch](https://help.monarch.com/hc/en-us/articles/4890751141908-Tracking-Recurring-Expenses-and-Bills)). Subby supports custom billing cycles from weekly to multi-year, which shows that monthly/yearly alone does not cover the whole category ([Subby](https://play.google.com/store/apps/details?id=com.slapp.subby&hl=en-US)).

### 2. Accurate, legible cost overview

Candidates:

- Current active-subscription total.
- Normalized monthly and annual totals with transparent calculation rules.
- Upcoming amount for a useful near-term period.
- Clear exclusion rules for paused or cancelled items.

SubManager, Subby, SubBuddy, and TrackMySubs all surface normalized totals or periodic reporting. Bobby's release history repeatedly mentions fixes to total calculations and currency exchange rates, evidence that accuracy is a release criterion rather than visual polish ([Bobby App Store listing](https://apps.apple.com/us/app/bobby-track-subscriptions/id1059152023)).

### 3. Upcoming-renewal view

Candidates:

- Chronological next-renewal list.
- Month/calendar view or equivalent date grouping.
- Visibility of annual or unusually expensive charges.
- Useful empty, overdue, and inactive states.

Rocket Money offers Upcoming, All, and Calendar views; Monarch combines calendar/list views with explicit upcoming, completed, missed, active, and cancelled states ([Rocket Money](https://help.rocketmoney.com/en/articles/3117398-where-can-i-view-my-subscriptions-and-bills), [Monarch](https://help.monarch.com/hc/en-us/articles/4890751141908-Tracking-Recurring-Expenses-and-Bills)). A calendar is a common pattern, but the underlying table stake is date-oriented anticipation rather than a specific interface widget.

### 4. Renewal and deadline reminders

Candidates:

- Configurable lead time.
- Separate handling for normal renewal, trial end, promotion end, or contract expiry.
- Time-zone-aware delivery and an understandable notification state.
- A safe retry/failure strategy and user-visible preference controls.

Reminders recur across focused products and align directly with FTC advice to calendar trial and promotional deadlines. Bobby's release history contains repeated fixes for notification permissions, deleted/disabled subscriptions, and long-running schedules, which makes delivery correctness part of the product promise rather than an incidental implementation detail ([Bobby App Store listing](https://apps.apple.com/us/app/bobby-track-subscriptions/id1059152023)).

### 5. Lifecycle rather than destructive deletion

Candidates:

- At least active, paused, and cancelled/archived states.
- Trial as either a state or explicit trial metadata.
- State-aware totals and reminders.
- Historical retention that does not treat a cancelled subscription as active spend.

SubBuddy models active, paused, cancelled, trial, and one-time records. Bobby added temporary disabling with optional hiding and notification muting. Quicken Simplifi can end a recurring series while retaining its history ([SubBuddy](https://subbuddy.io/en/features), [Bobby App Store listing](https://apps.apple.com/us/app/bobby-track-subscriptions/id1059152023), [Quicken Simplifi](https://support.simplifi.quicken.com/en/articles/3625912-managing-recurring-transactions)).

### 6. Basic organization and retrieval

Candidates:

- Sort by renewal date, price, and name.
- Filter by lifecycle state and category.
- Search by name/provider.
- A small category system; tags only if categories prove insufficient.

Sorting, categories, filters, folders, and tags recur, but the comparables do not prove that every organization mechanism is necessary at once. A later scope decision should choose the lightest model that serves the expected inventory size.

### 7. User control and trustworthy boundaries

Candidates:

- Export in a machine-readable format.
- Account/data deletion and clear retention behavior.
- Plain language that PaySubscriptions tracks and reminds but does not itself cancel a provider contract or stop a payment.
- Accessible, responsive core flows and tested total/date calculations.

The FTC and CFPB both distinguish cancellation steps from payment control and encourage record retention. The GDPR's minimisation, storage, security, and accountability principles favor a narrow data model and explicit control ([FTC](https://consumer.ftc.gov/articles/getting-and-out-free-trials-auto-renewals-and-negative-option-subscriptions), [CFPB](https://www.consumerfinance.gov/ask-cfpb/how-do-i-stop-automatic-payments-from-my-bank-account-en-2023/), [European Commission](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/principles-gdpr_en)).

## Meaningful differentiator candidates

These extend the core loop without necessarily turning the app into a bank-data platform.

### Privacy-first manual operation

Make the absence of bank and inbox access an explicit benefit: the user records only what is necessary, and the product explains where data is stored and how to remove or export it. Subby's listing explicitly positions no bank login/account and device-local data as benefits. This direction also aligns with GDPR data minimisation. The tradeoff is that completeness depends on the user's setup and periodic review.

### Trial, introductory-price, and price-review tracking

Add optional fields for trial end, promotional price end, future price, or a review/cancel-by date. Suby tracks an introductory price, the date it changes, the later price, and a reminder; TrackMySubs tracks trials and refund dates. FTC guidance validates the underlying need to remember trial deadlines and verify renewal prices.

### “Cancel with confidence” workflow

A lightweight, user-driven flow could hold a provider/cancellation URL, instructions or notes, requested-on date, reference number, cancelled-on date, and a later statement-check reminder. This is narrower than cancelling on the user's behalf and directly follows FTC/CFPB guidance. It must never claim that marking a record cancelled changed the external subscription.

### Expensive-month forecast

Highlight months where annual or other infrequent renewals cluster. TrackMySubs explicitly describes identifying expensive months, and calendar views across products supply the underlying data. This can turn an existing schedule into useful planning insight without adding external data sources.

### Multi-currency with explicit semantics

Store each subscription's original currency and, if conversion is added, keep the original amount visible and explain the display rate and timestamp. Suby, SubBuddy, Subby, Bobby, and TrackMySubs all address multiple currencies. Live conversion is a separate operational choice because it introduces rate-provider availability and changing totals.

### Lightweight household awareness

Optional “paid by,” “used by,” or household labels could solve responsibility questions without shared accounts or permissions. Full multi-user collaboration should remain a separate candidate because it creates invitation, authorization, visibility, deletion, and recovery decisions. Monarch's model illustrates the privacy stakes: household members get equal visibility and connected accounts cannot be hidden from one another ([Monarch](https://help.monarch.com/hc/en-us/articles/360048393272-Getting-Started-Guide)).

### Portable setup and backup

CSV export is common enough to support trust and portability; reviewed CSV import can reduce setup friction without granting live account access. TrackMySubs offers CSV import/export, and SubBuddy uses an editable review step with partial-success feedback. Import still needs validation, duplicate handling, and rollback/error design.

## High-cost or high-risk candidates for later evaluation

| Candidate | Why it is costly or risky for a solo-maintained v1.0 | First-party evidence |
| --- | --- | --- |
| Bank linking and automatic subscription discovery | Introduces financial transaction data, vendor onboarding and cost, user consent, regional coverage, connection repair, asynchronous updates, reconciliation, duplicate detection, and false-positive review. | Plaid requires Link setup, stored integration state, transaction synchronization, webhooks for added/modified/removed data, and ongoing handling of freshness; recurring detection is a paid add-on limited to supported countries ([Plaid Transactions](https://plaid.com/docs/transactions/)). Rocket Money's support docs expose delayed, disconnected, unsupported, and user-action-required connection states ([Rocket Money connection status](https://help.rocketmoney.com/en/articles/4918001-your-account-connection-status)). |
| Gmail or inbox scanning | Requires access to highly sensitive content and a robust consent, security, deletion, and compliance story. It also requires continuously maintained parsing across provider emails. | Gmail read scopes are classified as restricted. Google says public apps need verification, and server storage or transmission of restricted-scope data requires a security assessment ([Google Gmail scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)). Google's assessment guidance says restricted-scope apps undergo annual assessment/revalidation ([Google security assessment](https://support.google.com/cloud/answer/13465431?hl=en)). |
| Done-for-you cancellation or negotiation | Requires provider-by-provider coverage, authority to act for the user, intake of extra account details, status updates, exception handling, and human support when a cancellation fails or a later charge appears. | Rocket Money limits its cancellation assistant to Premium, asks the user for a form, cannot support every provider, and routes failures to support ([Rocket Money cancellation](https://help.rocketmoney.com/en/articles/934402-how-do-i-cancel-a-subscription-on-rocket-money), [cancellation FAQs](https://help.rocketmoney.com/en/articles/4649463-subscription-cancellation-faqs)). Its terms state that a request authorizes Rocket Money to contact the provider as the user's limited agent ([Rocket Money terms](https://www.rocketmoney.com/terms)). |
| Biller credentials and live variable-bill synchronization | Expands the product from a tracker into credentialed bill management, with provider-specific authentication and reconciliation. | Quicken Simplifi's [Bill Connect](https://support.simplifi.quicken.com/en/articles/4993562-using-bill-connect-to-track-your-monthly-bills-on-the-mobile-app) links billers to update due dates and amounts; its broader [recurring engine](https://support.simplifi.quicken.com/en/articles/3625912-managing-recurring-transactions) matches downloaded transactions and needs manual correction when matching fails. |
| Full household collaboration | Requires membership invitations, roles, visibility rules, concurrent editing, auditability, account recovery, and clear deletion/export semantics for shared data. | Monarch gives separate logins but equal visibility over the household and does not allow accounts or transactions to be hidden from a member ([Monarch](https://help.monarch.com/hc/en-us/articles/360048393272-Getting-Started-Guide)). This is a substantive domain and privacy decision, not just a sharing toggle. |
| Native mobile/watch apps, widgets, and platform cloud sync | Multiplies clients, release processes, notification behavior, accessibility testing, support surfaces, and platform dependencies. | SubManager spans iPhone, iPad, Mac, Watch, Vision Pro, iCloud, widgets, and a read-only web viewer ([SubManager](https://submanager.app/)). Bobby's release history shows recurring maintenance in notifications and iCloud synchronization. |
| Live foreign-exchange conversion | Creates an external rate dependency, cache/freshness rules, historical-versus-current valuation questions, and accuracy expectations. | Bobby's release history includes stale/incorrect exchange-rate fixes; TrackMySubs, Subby, Suby, and SubBuddy all market currency conversion, confirming value but also a maintained service boundary. |
| AI or screenshot import | Adds model/vendor cost, extraction errors, privacy review, user confirmation flows, and support for ambiguous source formats. | Subby sells AI screenshot import separately, while SubBuddy adds confidence badges and a review table. Both implicitly require human review rather than safe blind ingestion. |
| Full budgeting, cash flow, credit, net worth, or payment blocking | Broadens PaySubscriptions into general personal finance, increasing data sensitivity, regulatory exposure, and product complexity without being required for the core subscription job. | Rocket Money and Monarch bundle subscriptions into much broader financial products ([Rocket Money FAQ](https://www.rocketmoney.com/faq), [Monarch recurring](https://www.monarchmoney.com/features/recurring)). Focused trackers demonstrate that the subscription job can stand alone. |
| Large provider/logo catalog and broad automation integrations | Requires ongoing asset licensing/curation, stale-link maintenance, provider coverage support, or third-party automation compatibility. | Subby maintains hundreds of branded entries, SubManager a growing icon library, and TrackMySubs advertises Zapier. These improve polish or extensibility but create continuing maintenance unrelated to schedule accuracy. |

## Candidate feature inventory for the v1.0 scope decision

The later scoping ticket can select, reject, or defer items from this evidence-backed inventory.

### Core record and lifecycle candidates

- Name/provider and optional provider URL.
- Amount and original currency.
- Flexible billing interval and next renewal date.
- Optional category, notes, payer/used-by label, and payment-method label.
- Active, trial, paused, cancelled/archived states.
- Create, edit, state change, restore, and delete.
- Explicit rules for which states count toward totals and reminders.

### Awareness and decision candidates

- Upcoming-renewal list and/or calendar.
- Accurate monthly and yearly normalized totals.
- Configurable renewal reminders.
- Trial-end, promotion-end, contract-expiry, or general review reminders.
- Search, sorting, and light filtering.
- Category or time-period spending view.
- Expensive-month forecast and high-cost-item highlighting.
- Periodic audit/review prompt.

### Action and trust candidates

- Cancellation/provider link and notes.
- Cancellation requested/completed dates, reference, and post-cancellation check reminder.
- Clear distinction between tracker status, contract cancellation, and stopped payment.
- CSV export, account/data deletion, and documented retention.
- Optional reviewed CSV import and backup/restore.
- Privacy-first explanation of what the app does not access.
- Accessibility, responsiveness, tested calculations, and notification-delivery observability as release qualities.

### Candidates requiring a separate later decision

- Live currency conversion.
- Shared household accounts and permissions.
- Bank aggregation and recurring-transaction detection.
- Email/inbox scanning.
- Direct cancellation, negotiation, or payment blocking.
- Biller connections and variable-bill synchronization.
- AI extraction or recommendations.
- Native apps, widgets, platform sync, and push-notification clients.
- Full budgeting or other general personal-finance functions.
- Broad third-party automation and a maintained provider/logo catalog.

## Planning implications

- The strongest market expectation is not automatic discovery; it is a trustworthy **inventory → anticipate → decide → follow through** loop. Automatic discovery is one acquisition method for the inventory.
- A manual-first product can compete through low friction, accurate schedules/totals, useful reminders, and explicit privacy boundaries.
- “Household-focused” does not automatically require multi-user accounts. The evidence separates the user job (shared responsibility and awareness) from the costly implementation (permissions and shared access).
- Reminder delivery, schedule calculation, state-aware totals, and currency semantics need explicit acceptance criteria if selected; competitor maintenance history shows that these are common failure points.
- A cancellation-support feature should be worded and designed so users cannot mistake an internal state change for cancellation of a provider contract or bank authorization.
- Selection and sequencing belong to the subsequent Wayfinder decisions; this report intentionally does not set the final v1.0 scope.
