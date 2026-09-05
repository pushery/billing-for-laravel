# Changelog

All notable changes to `pushery/billing-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.20.0] - 2026-09-05

### Added

- **`ArrearsClock` — the suspension ladder now reads "since when is this owner behind" through a seam.** The ladder is the richest part of dunning (several rungs instead of one deadline, a different rung per surface) and all of it was already free of the database — except the one class that combines the rungs with the policy, which read `billing_subscriptions` directly. An application that keeps its own view of who owes what could therefore reach every piece of the ladder and not the ladder itself. It now binds `ArrearsClock` and gets the ladder unchanged; `ArrayArrearsClock` is an in-memory implementation for exercising the rungs without a billing database. With nothing bound the package's own reading applies, so an install on the shipped schema is unaffected.

- **`WebhookDeliveryRefused` — a refused webhook delivery is now visible.** Both receivers answered a failed signature check with a bare `400` and wrote nothing anywhere: no event, no log line, no delivery row, so the refusal existed only as a status handed back to whoever sent it. A consumer searching their own logs after an incident found nothing, because nothing had ever been written. The event carries the request's facts — provider, surface, path, user agent — and never the body, which by definition did not verify, nor which part of the signature failed, which would be a probing oracle, nor the caller's network address, because this package hands an address to exactly one seam and reads one nowhere else. If you want the address in your own audit trail, take it from the request inside your listener. The dispatch is guarded so a throwing listener cannot turn the deliberate `400` into a `5xx` that tells the sender to retry, and the package writes a warning to the log as well, so an install that wires no listener still sees the attempt.

### Changed

- **`LadderSuspension` takes its clock as a third constructor argument.** Resolved from the container, so code that resolves `SuspensionLadder` (or the class itself) needs no change; only a hand-rolled `new LadderSuspension(...)` does.

- **The `stripe/stripe-php` ceiling is now named in the documentation.** The package caps the SDK below major 21, and Cashier no longer does — so on a current install this package is what holds it back, and nothing said so anywhere a consumer would look. The cap itself is unchanged: moving a payment SDK across a major without exercising it against the live API would be a claim nobody has checked.

### Fixed

- **A free trial's own days are no longer billed at the plan price.** Under a driver whose cycle this package runs itself, an order names the period it closes — so the cycle that ended a trial named the trial's own days and charged the full plan for them. Because the payment at checkout is a verification amount rather than the plan price, that pulled the first real charge *forward* by the length of the trial: taking the offer cost more than declining it, while the plan screen advertised the days as free. The recurring plan line for a period a trial covered is now priced at zero, so the invoice states which days were free instead of charging for them. Metered usage and add-ons are unaffected — a trial is an offer about the recurring charge, not about consumption.

  One consequence if you listen for payments: the cycle still closes, so it still dispatches `PaymentSucceeded` — with an amount of zero and no provider reference. A listener that mails a receipt on that event will mail a zero receipt at every trial end unless it reads the amount. The event is not suppressed on purpose: a cycle a customer's credit balance covers in full behaves the same way, so a listener that handles one already handles the other.

## [0.19.1] - 2026-09-05

### Changed

- **`DriverCapabilities` now states that every flag on it describes remote payment.** A buyer who is not present: a hosted checkout, a stored mandate, a webhook. Card-present payment is outside it, and the docblock says so in both directions — there is no flag claiming a driver can take money at a counter, and deliberately none claiming it cannot, because in-person payment is a payment path rather than a boolean and a flag would let you ask a question this package could not answer honestly.

  No behavior changes. The sentence exists because the absence read like an oversight: both providers' SDKs carry terminal APIs, so somebody comparing them against this package reaches for a flag, finds none, and cannot tell a deliberate boundary from a gap.

### Fixed

- **The README and three documentation pages described one driver as the package.** They were written when one shipped. The README said "the Stripe driver ships today. The contracts are the seam a second provider slots into" — while `src/Drivers/Mollie/` is twelve files that ship with every release, its provider registers unconditionally, and the published configuration reference documents four of its keys in detail. It was the only public page still describing a one-driver package, contradicting our own reference.

  It now says what each driver IS, because that is what a reader chooses on: one runs the subscription cycle on its own side behind a hosted checkout, the other establishes a mandate through a first payment and this package runs the cycle. Your application code does not change between them; `billing.default` does.

  The account hub page had `/plan` as "Subscribe (hosted checkout)" and `/checkout/return` as "reconciles the subscription after a hosted checkout". Under a driver this package bills itself the first is a mandate redirect and the second deliberately reconciles nothing. Its coupon field was documented nowhere on the page that owns it, and the optional two-click cancel was not written down at all.

  The webhooks page said an add-on's credit "is mirrored onto the Stripe customer balance" — that mirroring is one driver's, and the shipped default keeps the credit in this package's own ledger. It also said the package refuses to boot without a webhook signing secret, which is true for a provider that signs every delivery and deliberately false for one that still offers unsigned ones, where refusing would lock out every install that has not migrated. The page said the opposite of the configuration reference, which documents that switch in detail.

  And the invoicing page said "every invoice Stripe finalizes is persisted". Where this package runs the cycle it issues the document itself, from the order it just priced. The stored row is the same shape either way, so an install that changes driver keeps one archive rather than two.

## [0.19.0] - 2026-09-05

### Added

- **A marketplace whose platform is the seller can now mint its tier prices where the checkout can use them.** `StripePlatformPriceProvisioner` creates a merchant-defined tier's price on the platform account, alongside the existing one that creates it on the merchant's connected account. Which of the two applies is not a new setting: it follows from `billing.marketplace.seller_of_record.default_posture`, because who the buyer transacts with is the same question.

  It closes a combination the package could not produce. Under `platform_deemed_supplier` the checkout session runs on the platform account, so a price minted on a connected account is one that session cannot use — and for an electronic supply that posture is not a preference, since Art. 9a of the VAT Implementing Regulation makes it irrebuttable for a platform that sets the terms. A content marketplace therefore needs per-merchant tiers with platform prices, and had no way to get them.

  The price key carries the merchant, which the connected-account version does not need. On separate accounts a tier, an amount and an interval cannot collide; on one shared account they can, and the result would not be an error — it would be two merchants sharing a price, so one repricing its tier moves the other's.

  Nothing changes for a single-seller install, which never reaches this seam. A marketplace that had bound the connected-account provisioner explicitly keeps it; one that relied on the default now gets the provisioner its own posture implies.

### Changed (breaking — pre-1.0)

- **`StartsSubscriptions::subscribe()` is now `start()`, it takes a coupon code, and the contract answers whether it would honor one.** `honorsCoupon()` is new. If you implemented this contract yourself, both changes reach you; it shipped one version ago and the package bound the only implementation there was.

  The rename is not tidying. One test double has to stand in for every money seam at once, and `Checkout::subscribe()` returns a different shape — a class cannot carry both under one name, so a consumer whose screen went through this contract would have found the seam missing from `BillingFake` with no way to add it. `BillingFake` implements the contract now and `Billing::fake()` binds it, so a test that faked billing keeps faking all of it.

- **The coupon status in the account hub answers a different question, and some codes will now read as invalid.** It asked the configured coupon catalog, which is not who applies the discount. A code with no provider mapping was therefore reported as applied and the customer was then charged in full, with nothing anywhere saying so.

  It now asks the configured driver. Under a hosted checkout that means the code must carry a `stripe_coupon` mapping, because the provider is what applies it; under a driver this package bills itself it means a row in the coupon table, because the discount is a line the cycle writes. A code the driver cannot act on is called invalid. If an install sees a code it believed was live reported that way, that is the state it was already in, said out loud for the first time.

### Fixed

- **The Subscribe button now works under a driver this package bills itself.** The account hub resolved `Checkout`, which is bound meaningfully by the hosted-checkout driver alone, so the button reached the hosted implementation whatever driver was configured and the request ended in the provider SDK — "No API key provided", on an install that has no reason to hold such a key. The package's own screen could not reach the package's own subscribe flow, and the whole local subscribe path shipped with no caller.

  The screen asks `StartsSubscriptions` now, and every driver answers it in its own shape: a redirect to a hosted checkout under one, an intent held across a mandate redirect under the other. Nothing about the hosted checkout itself changed, and a consumer calling `Checkout` directly keeps working.

- **A coupon typed before a mandate redirect is no longer lost.** Under a driver this package bills itself there is no checkout to apply a discount at — it is a line the cycle writes, months of them for a repeating coupon — so the code has to survive a browser round trip and a webhook. It now rides on the intent and is redeemed when the mandate settles, with the entitlement re-derived at that moment rather than trusted, because days can pass on a bank transfer.

  It is carried rather than spent, and that is the load-bearing half. A coupon allows one redemption per owner, so redeeming at the redirect would take it away permanently for a checkout the customer may abandon. A coupon that has run out, expired, or was already used in the meantime leaves the customer subscribed at full price rather than not subscribed — a coupon never fails a sale.

- **The checkout return no longer asks a foreign provider about the customer.** Both driver providers register unconditionally, so a seam only one of them binds is inherited by the other — silently, and correctly typed. On an install this package bills itself, the return page asked the hosted provider about a customer whose reference the local driver wrote: one outbound call and one reported exception per completed sale, on the page a customer lands on straight after paying. An error stream that fires on every sale gets muted, and the real one goes with it.

  The reconcile is now skipped where there is nothing to reconcile against, decided on the billing engine rather than on a driver name. The webhook is the durable path either way; the reconcile is a courtesy for a customer who beats it home.

## [0.18.0] - 2026-09-05

### Added

- **A marketplace no longer queries the database while it boots.** The go-live checklist runs at boot so the marketplace switch cannot be flipped past an open blocking point. One of its nine checkpoints reads stored rows to look for duplicate buyer receipts, and it ran on every boot — an unbounded aggregation over all documents, in a path that otherwise only reads configuration.

  Worse than the cost: that checkpoint declares itself non-blocking, and it could still take the whole application down. An exception out of a checkpoint does not care what `isBlocking()` returned, so an install that flipped the switch before running the migrations found the query throwing against a table that did not exist yet — `route:list` included. The first visible effect of adopting the package was a dead application, and the two adoption steps were silently order-dependent.

  Checkpoints that read stored state are now excluded from the boot path and run from `billing:marketplace:preflight` instead, which is where a deploy already looks. What that costs is stated rather than discovered: a marketplace carrying a duplicate receipt now boots. It always did — the checkpoint never refused anything — so what changes is when the report is produced, not whether.

- **Canceling can now take two clicks, if your own acceptance asks for it.** `account.cancel_requires_confirmation` arms the cancellation on the first click and carries it out on the second, with a way back in between. Previously the only way to get a confirmation step was to fork the view.

  Off by default, and the default does not move. Canceling here is reversible, and for the person doing the canceling less friction is the better behavior — a subscription that is hard to leave is a dark pattern rather than a safeguard. An install that leaves this alone sees exactly what it saw before.

  The step lives in the action rather than in the markup. A Livewire action is callable from the client whatever the view renders, so a check that existed only in Blade would be advice rather than behavior. It renders inline instead of as a modal, so it needs no JavaScript and no dialog component, and an application that re-skins the hub keeps its own chrome.

- **`billing:doctor` now says when a provider key belongs to the other world.** A test key in production is the expensive one, and it is expensive because everything works: checkouts complete, webhooks arrive, invoices are issued, and no money moves. Nothing throws, so the first report tends to come from the bank reconciliation days later. The reverse — a live key outside production, charging real cards from staging — is reported too.

  It warns rather than fails, and it is not in the boot path. A test key in a production-like environment is legitimate often enough (an acceptance system, a demo tenant) that a check refusing them gets switched off, and a switched-off check protects nothing on the day it would have mattered. Only the active driver's key is read, so a leftover Stripe key on a Mollie install says nothing.

- **Mollie is now told which package is calling.** The client sends `PusheryBilling/<version>` alongside the version strings the SDK already announces for itself and for PHP. It changes nothing about how requests are answered; it makes this integration distinguishable from any other PHP client if it ever shows up in Mollie's own numbers or in a support thread.

  The version is derived from the installed package rather than written down, so it cannot drift away from the release that carries it.

- **The test fake can now be asked which coupon and which declaration reference actually reached the seam.** `assertSubscribeStartedWithCoupon()` and `assertPurchasedWithDeclaration()` read values the fake was already recording but had no way to hand back — the only way to assert them before was to hand-roll a checkout double to reach a field `Billing::fake()` already held.

  They are separate methods rather than optional parameters on the existing assertions, because `null` has to mean something definite: it asserts that no coupon, or no reference, was passed. An optional parameter defaulting to `null` cannot tell that apart from "do not check", and the first is the case worth proving.

  It matters where a discount is shown before the buyer commits. The package does not fail a checkout on an unknown coupon code, on purpose — the provider decides what a code is worth, and refusing here would turn a typo into a lost sale. An application that validates the code itself and shows the result needs to prove the code it validated is the one that arrived, and a failing assertion now names the values it did see rather than only reporting that something did not happen.

### Fixed

- **A switched-off billing no longer schedules anything.** With `billing.enabled` false the package registered sixteen scheduled commands anyway, and the ones that reach the database threw on every execution — against tables that a dormant install has never migrated. `billing:usage:flush` runs every minute, so a single disabled environment produced roughly 1,440 failed runs a day.

  The damage was not the noise. Those runs land in whatever schedule monitor the application uses, and a monitor that is permanently red stops being read — so what was really lost is the channel that has to carry the next real cron failure.

  The schedule is now gated where it is registered rather than inside each command. A command that starts every minute in order to discover it has nothing to do is still a process started every minute, and `schedule:list` on a disabled install now answers honestly: nothing.

- **A rotating Mollie webhook secret can now be configured the way the configuration is actually written.** `BILLING_MOLLIE_WEBHOOK_SECRET` accepts a comma-separated list — `old_secret,new_secret` — and a ping signed with either is accepted, which is what makes rotating one without dropping webhooks possible. Spacing and empty entries are ignored, so a trailing comma costs nothing, and a single secret behaves exactly as before.

  The verifier could already hold several secrets, but only as an array, and this setting reads one string out of the environment: the array was reachable only by hand-editing the published config file. So the rotation the setting promised could not be reached through the channel it is configured by.

  The obvious attempt made it worse rather than merely not better. Mollie's own package documents a comma-separated list, so an operator following that pattern wrote both secrets on the line and got one secret whose name contained a comma — matching no signature Mollie produces, refusing every webhook, starting the moment of the rotation it was meant to enable.

## [0.17.0] - 2026-09-04

### Added

- **The installation page now states what the package needs before you install it.** It went straight from its title into `composer require`, so the only place the supported versions were written down was the README — a different surface, and not the one a reader following the documentation is on. The page now opens with PHP `^8.4` and Laravel `^13.0`, and says plainly that both are floors: any Laravel 13 release works.

- **A charge now says what it paid for, on the line the customer reads.** The previous release moved the internal reference off that field and into metadata, which stopped the leak and left the description saying `Subscription` — a word, but not an answer. Somebody looking at a card statement still could not tell which service or which month, and that is the question a chargeback is opened over.

The money seams carry an optional `ChargeNarrative`: the service name and the period the charge covers. Under Mollie the description now reads `Acme Pro (2026-03-01 - 2026-03-31)`; under Stripe the payment intent carries the same text in its `description`. Dates are ISO because a statement line has no room to say whether `01/03` is January or March.

It is additive and inert for anyone who passes nothing: Mollie keeps its previous wording, and Stripe sends no `description` key at all rather than a word no caller chose. Each driver trims to its own field length — 255 at Mollie, 1000 at Stripe — and trims the SERVICE name rather than the period, because the period is the half that tells two otherwise identical charges apart.

The recurring-cycle path supplies one from the subscriber's tier and the order's period. The tier's label, falling back to the tier key and then to a generic word, so a tier configured without a label still names something a person can act on.

- **The admin console no longer hands over a booking batch that does not tie out.** The same file leaves through two doors — `billing:datev:export` and the console's download — and only the command was reading the reconciliation. The console now refuses the download, names both totals and their difference, and offers the file behind a second click.

The two doors agree in substance and differ where a download differs: the command writes the file and fails, because a difference is a statement about that file and cannot be traced without it. A download lands in a folder without being read and is forwarded from there, so an unbalanced batch that arrives silently is an unbalanced batch at the accounting firm. Nothing is withheld — what the click buys is that somebody has read the figures first.

The acknowledgement is per period and is forgotten the moment a date changes, so it cannot wave a second, different month through. An installation with no merchants sees nothing at all: an amber panel on every ordinary export is how an operator learns to click past the one that matters.

- **Every charge now says which cycle made it.** The idempotency key already traveled with each one, but only as a request *option*: a provider uses it to collapse a retry and then does not expose it, and neither Stripe nor Mollie can find a payment by the key it was created with. A charge could therefore be tied back to its cycle by amount and timestamp and nothing else, which is guessing rather than tracing — the blind spot behind every "what was this charge for", and the reason a stranded claim cannot be resumed without a person looking at the provider.

Both drivers now stamp the caller's reference into the payment's **metadata**, a machine field that can be searched. Absent rather than empty when no reference was named, so the payload an existing single-seller charge sends is unchanged.

Under Mollie this also takes an internal number off the customer's screen: `description` used to carry the reference, and that is the text Mollie shows the person paying. It now reads as a word rather than an id. Naming the actual service and period there needed context the rails contract did not carry; the entry above is that context arriving, in the same unreleased range.

- **A cycle whose claim was abandoned can be handed back to the biller.** The local engine writes its order before calling the provider, so the claim survives the call and a second run cannot bill the same cycle twice. A process that dies inside that window leaves the order `processing` with no payment behind it, and three things then line up: the cycle looks taken so no run reopens it, no payment was created so no webhook can arrive, and the credit the attempt spent sits against a row nobody queries. The subscriber is never billed again and the only symptom is that nothing happens.

`billing:release-claim {order}` returns the credit and puts the cycle in the state an ordinary refusal leaves it in; the next scheduled run reprices it and collects it under the **same** order, so the charge carries the idempotency key the abandoned attempt used rather than a fresh one.

**It is deliberately not a sweep.** A missing payment reference is the closest thing to proof that the provider was never called and it is not proof — a worker killed mid-call leaves none behind and may still have created a payment. A prompt retry collapses onto that payment through the idempotency key, but a claim must be hours old before it can be told apart from ordinary in-flight work, by which time the key has expired. Between racing a live charge and taking the money twice there is no threshold worth picking automatically, so the decision belongs to whoever can look at the provider — which is what `billing:doctor` has always told operators, and a sweep would have been contradicting a shipped diagnostic.

The six-hour boundary is now a single definition shared by the diagnostic that reports these claims and the command that acts on them, so the two cannot drift into reporting and refusing different rows. Two operators working the same report cannot both release the same claim: the decision is made from the row under a lock held across the return, so the credit comes back exactly once.
- **The DATEV export now proves that the merchant payables it wrote tie out to the sub-ledger.** `CollectiveAccountReconciler` reads the *emitted* batch rather than the numbers that produced it, so a document the export leaves out, an account resolved differently or a direction marker written the wrong way round shows up as a difference — none of which surfaces anywhere else until an accountant asks why the account does not balance. It was built and tested and nothing ever called it, which is the one state that looks exactly like a working check.

The reconciliation is computed inside `DatevPeriodBatch::render()`, next to the query that selected the documents, and that placement is the substance rather than a detail: a caller doing it would have to re-query the period, and any difference between its query and the export's — a bound snapped differently, a filter only one applies — would arrive as an accounting difference that is an artifact of the reader. `billing:datev:export` writes the file either way, because a difference is a statement about that file and it cannot be traced without it, and then fails, naming both totals rather than only the difference.

Installations without a marketplace are unaffected: with no payables both sides are zero, and the line is printed only where there is something to confirm.

- **A document can no longer state a tax nobody was in a position to determine.** The invoice table serves two worlds and cannot tell them apart: a provider that determines tax stores a *result* and nothing about how it was reached, which is correct, while a driver whose provider determines nothing has no result to store. A `tax_minor` written under the second is a claim with no basis — and zero is not the absence of a claim, it is the claim that no tax was due.

Nothing noticed, because every tax column is nullable: such a document is indistinguishable from a complete one, no column is empty, the totals add up. `TaxWithoutBasisGuard` refuses it on create, reading the condition from the driver's **capability** rather than from a list of drivers — a list would be right today and wrong at the third driver, in the silent direction.

Any one determination column satisfies it: a reverse-charge supply names a place of supply and no rate, an exempt one names its reason and no band, so demanding all of them would refuse correct documents. A document that names no driver is out of scope, because the gap this closes is a difference *between* drivers.

- **A held cycle is now closed by the webhook, in whichever direction the money went.** Until now the only writer of a paid order was the engine's own synchronous `recordSuccess()`, so a charge that settled later had nowhere to land — which is why holding a cycle open was not possible before. `SettleCycleOnPayment` closes it and advances the period when the payment settles; `FailCycleOnPayment` turns it into a real failure when the debit bounces, and **that** is the moment dunning belongs to rather than the moment the payment was created. Both are thin: every decision about the cycle stays in the engine that owns it, and both find nothing under a driver whose provider runs the subscription itself.

- **A local billing cycle can now be priced by the application, not only by its plan.** Steps configured in `billing.order_item_preprocessors` reshape a cycle's lines before its order is written — metered consumption an application prices itself, an application's own per-cycle arithmetic, anything the flat plan price cannot express. Each step implements `OrderItemPreprocessor` and is resolved through the container, so it may declare its own dependencies.

Only a driver with a local engine reaches this. A provider that meters remotely returns an amount that is already correct, which is exactly why the default is an empty chain: an install that configures nothing bills what it billed before.

The cycle is now assembled as drafts and written afterwards, and the order's total is the **sum of its lines** rather than a number carried alongside them. That ordering is the point — a line added after the row exists would leave a total that disagrees with what it totals, and nothing downstream would notice.

A step that throws aborts that one cycle before it is claimed, so it is retried on the next run rather than charged at a partial total. One subscriber's failing step does not stop the sweep.

- **Documented three reporting seams a consumer could not find.** `UsRegimeActivationPolicy` judges whether a reading means the US regime must be switched on — acting on a configurable **share** of a limit rather than the limit, because registration takes weeks and the obligation starts at the crossing. `UsTaxFormRegistry` records what a seller declared, collected at onboarding and acted on only once the regime is on. `ReportingBaseComparability` says whether two figures may be compared at all: a margin-taxed resale reports proceeds and declares margin, and a reconciler that was not told reports a discrepancy on every such transaction — which is what turns an ordinary audit into a thorough one over a difference that was never an error.

All three were carried as waiting for a caller. None was: each answers a question asked from outside the package.

- **Documented where a routed payment's `ChargeRouting` comes from.** `ChargeRouting`'s constructor is public, so assembling one by hand compiles — and gets two things wrong quietly. `ChargeRoutingResolver` takes the commission on the **net**, which is what a configured rate has always meant; on the gross it would include the buyer's tax, and that figure reaches the provider as the application fee, so the difference is money the merchant is not paid. It also checks the charge type against the resolved seller posture before anything is assembled, so an incompatible pairing is refused before a charge is made rather than after, when only the transfer is left to fail.

It was carried in the class register as waiting for a caller. It was not: `RoutedPayment::charge()` receives a routing and is itself only ever called from outside, because the package never decides that a buyer is paying a merchant.

- **Recorded that one install runs one driver**, where somebody looking for it would read: on `BillingManager::driver()` and in the configuration reference. The seams a screen resolves are bound once at boot by the active driver, so per-owner driver resolution is not possible — and it is not a goal either. Changing providers is a migration of `billing.default`, not a mixed mode, and a marketplace does not need it: its sellers are merchants at the **same** provider, scoped by `MerchantScope`.

Pinned by tests rather than left as prose, because the shape looks exactly like an oversight — and the natural response to an oversight is to fix it, which here means touching every resolution site and taking the contracts away from consumers who override them.

- **A cycle that was claimed and never collected is now findable.** The local engine commits its order before calling the provider, because the claim has to survive the call — otherwise a second run bills the same cycle twice. The cost of that ordering is a window: a process dying between the claim and the charge leaves an order in `processing` with no payment behind it, the next tick skips that subscriber permanently, and no webhook will ever arrive because no payment was created.

`billing:doctor` now reports those orders, and the count is folded into its verdict rather than carried beside it — a check held separately has to be remembered at every exit, and forgetting one is invisible: the command still prints the warning, it just stops counting it.

- **A plan change on a local driver now credits the unused time.** The local driver kept Stripe's proration strategy, whose `applySwap()` is a deliberate no-op because Stripe books the proration itself. A local provider does not — so a subscriber who changed plans was credited nothing for the period they had already paid for, and no state anywhere looked wrong.

Found by a new test that holds the superset principle directly: for every capability the provider lacks, something local must fill it. That test is what would have caught this and the missing subscription actions before either shipped.

- **Documented how to mount your own payment element**, for a product that needs the card fields inline rather than on the provider's page. The package still ships no front-end JavaScript, and that stays a decision rather than a gap: the hosted path keeps an application out of PCI scope by construction, and shipping an element would mean shipping a build pipeline and taking a position on somebody's bundler. `PaymentMethods::setupIntent()` is the seam, and the guide is explicit about the three things that move to the adopter's side when they use it.

- **A driver's recurring-capable methods are now enforced, not merely declared.** Both shipped drivers listed which payment methods can carry a merchant-initiated charge, and nothing read the list. A value that describes the system and changes none of its behavior is indistinguishable from one that is wrong. A mandate on a method the driver cannot recur with now goes to dunning instead of spending a provider round trip to be told what was already known.

- **A failed billing cycle is now retried on the dunning ladder.** It was not retried at all. A refused charge marked the subscription past_due and stopped there: the order stayed `failed`, the claim refused to reopen a cycle that already had one, and the row still carried the due date it had already been processed on — so the sweep never selected it again. The ladder was configured, the notices were wired, the states were correct, and the one thing that turns a ladder into recovered revenue was absent.

The next attempt is scheduled from when the arrears **started**, not from the last attempt: anchoring to attempts drifts the whole ladder further out with every retry. Once no rung is left, nothing further is scheduled — a card that refused three times over two weeks will not succeed on the fourth, and each attempt costs a fee and counts against the merchant.

A **paid** cycle is still never reopened, so a retry cannot double-charge.

- **A scheduled billing sweep can now be monitored from outside.** `ScheduleHeartbeat` is told when `billing:run` starts and how it ended. The shipped implementation does nothing, and that is the honest default — a package cannot know where an install watches its jobs, and a built-in pinger aimed at no service reports healthy because it was never reached.

The failure this guards is not a command that crashes; that is loud and the log has it. It is a command that stops **running** — a scheduler never installed on a new host, an overlap lock left behind by a killed process, a container that no longer starts the cron. Billing keeps appearing to work because everything except the sweep still does, nobody is charged, and the first report comes from the bank statement.

- **Cancel, resume and plan swap now work for a driver whose engine is local.** They previously fell through to `NullSubscriptionActions`, whose methods are empty: canceling did nothing, swapping did nothing, and neither said so. A screen reporting success over an untouched row is the worst of the three possible failures, because nothing anywhere goes red.

An upgrade lands immediately and books the proration credit for the unused remainder. A downgrade is scheduled for the period end, because the customer has already paid for the period they are in — switching them down at once takes away access they bought. `billing.subscriptions.downgrade_timing` flips that for an install that would rather refund than wait.

A swap is gated on eligibility because it moves money; cancel, resume and cancelNow are not, and that is deliberate — account deletion must always be able to cancel, and a gate there would trap a customer in a subscription they are trying to leave.

- **A refund against a locally raised invoice now produces a credit note.** A provider that issues its own documents announces the correction too, and the package stored it; a local engine raised the invoice itself, so nobody announced anything — and the books were left overstating turnover, with the charge recorded and the money going back absent.

The amounts are **positive**. A correcting document's type inverts the accounting direction, not a minus sign, which is what EN 16931 requires and what the provider path already did — two paths disagreeing about the sign would disagree about one customer's books.

The credited total is **capped at the invoice it corrects**. A cumulative figure larger than the original is a provider bug, an out-of-band adjustment, or an invoice reissued smaller; credit notes summing beyond their invoice is the one shape an auditor reads as fabricated.

It selects itself on the presence of an order rather than on a driver name, so a provider-issued invoice is left alone. A redelivery draws no new number: an issued document is immutable, and a gapless series is exactly what a number is for.

- **A local engine now raises the invoice for the cycle it collected.** Stripe issues the document and the package copies it; a local driver had no such source, so the invoices screen stayed empty while the money moved perfectly well. `billing_invoices` gains `order_id` — unique, which is the idempotency: an invoice number is gapless and immutable, so a duplicate is not a mess to tidy up but a second numbered document asserting a charge that happened once.

The lines are **frozen into the record**, not referenced. An invoice states what was sold when it was sold, and referencing the order's rows would let one later price correction rewrite every historical document at once, silently.

It states **no tax** rather than zero tax. A driver whose provider does not determine tax has no basis for either number, and zero is not the absence of a claim — it is the claim that none was due.

`LocalInvoices` serves the screen from those rows, so listing and downloading make no provider call at all. Ownership is a filter on the query rather than a check after fetching: the wrong row is unreachable rather than rejected.

- **A redeemed coupon now reaches the cycle it discounts, as a line of its own.** The local engine never applied one: `DiscountResolver` was reached only from Stripe's checkout, so a subscriber with a redeemed coupon was billed the full price every cycle. The discount is a negative line naming the coupon rather than a quietly smaller charge, because a discount nobody can see is one neither the customer nor the accounts can check.

Order of operations, and it is a decision: **discount, then tax, then credit.** The discount comes first because it changes what was sold — taxing the undiscounted price collects tax on money nobody paid. Credit comes last because it is not a price but payment already belonging to the customer, and spending it before the discount would take it for an amount never owed.

`billing_coupon_redemptions` gains `applied_count` and `last_applied_period`, which is what makes `duration` mean anything: until something counted, `repeating` and `forever` were the same thing, and a coupon sold as three months ran for the life of the subscription. The count is recorded against the period it discounted rather than incremented blindly — pricing happens before the order is claimed and a claim can lose, so counting attempts would burn a customer's remaining months on a run that billed nothing.

- **A local cycle is now billed from the subscription's own lines.** Every part of this existed and nothing connected them: `SubscriptionItem` carries the line and names the resolver for it, `CycleAmountResolver` was bound to price it, and `MeteredCycleAmountResolver` rates a metered line against the package's usage counters — while the local engine read the **tier price** and looked at none of it. A subscription with three lines billed as though it had one, and the number it produced was plausible.

A subscription with no lines still bills the tier price, unchanged. Adding lines is what opts in.

A line that cannot be priced — a fixed line with no amount, a metered line naming no resolver, a meter missing from the catalog — stops that cycle instead of billing what is left. Each of those is a line written wrong rather than a cycle that is free, and a shortened charge would be claimed and never revisited.

- **A production install running Mollie without a webhook signing secret is now told so.** Mollie's next generation signs every request, so an account on it with no secret configured accepts unsigned pings — a real gap. Its legacy generation signs nothing, so an account on THAT has nothing to configure and refusing to boot would lock it out on update day.

Which generation an account runs is a dashboard setting, invisible from inside the application, so the guard warns rather than refuses: loud enough to be found by whoever reads production logs, quiet enough not to break an install that is already correct. Silence was the one option wrong in both directions.

- **Mollie is now a selectable billing driver.** `billing.default = 'mollie'` resolves a driver whose rails are Mollie's and whose engine is the package's own — the first LOCAL-ENGINE driver, which is the whole difference. Stripe is told when to charge and charges itself; Mollie takes payments when asked and runs no billing cycle at all, so the package runs one.

That inverts what a missing piece costs. Under Stripe, a gap means the provider handles it. Under Mollie a gap means nobody does: a subscriber is simply never billed, and nothing fails to say so.

Registration is unconditional and the rebinds are not. A driver that exists by name can be asked for by name — an install running two providers, or mid-migration, must not be told `mollie` does not exist — while the webhook, payment-method and CSP contracts are only replaced when Mollie is the active driver.

A production install that selects Mollie without an API key now refuses to boot. The failure that replaces is the silent one: without a key nothing goes wrong at boot, it goes wrong at the first charge, inside a scheduled run, hours later, against a real subscriber. A webhook signing secret is deliberately NOT required — Mollie's legacy generation carries no signature, so demanding one would refuse to boot every install not yet on the next generation.

New setting: `billing.mollie.webhook_url` (`BILLING_MOLLIE_WEBHOOK_URL`), the absolute URL Mollie returns customers to and posts pings at. It is configuration rather than a generated route URL because a package cannot know the public host, and the scheduled run creates payments from the CLI where there is no request to take one from.

- **Payment methods can be listed, re-defaulted and removed under Mollie**, against the local mandate rows the webhook now fills. Ownership is enforced here rather than by the provider — Stripe refuses another customer's method id on the package's behalf and Mollie cannot, so every mutating call re-reads the row scoped to the billable. The method id travels from a browser; it is not a secret, and taking it on trust would let any signed-in customer point their billing at, or delete, somebody else's mandate.

Two removal rules, because removal is the operation that quietly breaks a paying customer. Removing the default promotes another usable mandate, or the account keeps methods with no default and the engine finds nothing to charge. Removing the last one is refused while a subscription is still being charged: it would not end the subscription, it would just make the next cycle fail.

- **A Mollie webhook naming something other than a payment is no longer silently dropped.** The legacy ping posts a bare id through one field, and not every id it posts is a payment — a refund (`rfd_`), a subscription (`sub_`), a chargeback (`chb_`) and a mandate (`mdt_`) all arrive the same way. Each was fetched as a payment, failed, and produced silence indistinguishable from a forged ping, so an install receiving them saw nothing and had nothing to search for. The prefix now answers that without the round trip, and the drop is logged with the kind.

- **Mollie refunds are mapped, and an unmapped payment status now says so.** A refund is read off the payment it was taken from — Mollie's webhook never names one — and carries the provider's CUMULATIVE refunded total rather than this delivery's delta, because that is what the reversal ledger needs to make a redelivery and a second partial refund both land correctly. Sending the payment's own amount instead would reverse the whole purchase for a partial refund.

Separately, a status this package does not act on is now logged — but only one nobody chose. `open`, `pending` and `authorized` stay silent deliberately (a SEPA debit sits on `open` for days, and treating that as a failure would dun money that is on its way). A status in neither list means Mollie added one and this package produces the same nothing as a settling debit for it, which is how a provider change stays invisible until somebody notices money missing.

- **Mollie mandates are now established from the webhook, not from the return redirect.** Mollie has no SetupIntent: recurring capability comes from a `sequenceType: first` payment the customer completes on checkout, and the mandate exists only once that payment is paid.

The obvious place to store it is the return redirect — and that is the one place it must not be, because the redirect happens in a browser. A customer who pays and closes the tab, loses signal, or completes the payment in a banking app that never returns has a valid mandate the package would never hear about, and is then dunned at the first renewal for a payment method they successfully added. The webhook fires regardless of the browser, so the new `MandateEstablished` event is emitted there and `StoreMandate` persists it.

`MandateEstablished` is the counterpart `MandateRevoked` never had, which is why this gap existed at all: the package could watch charging capability disappear and never watch it arrive. Storing is idempotent on `(provider, mandate reference)` — the provider redelivers and the effect is queued — and a later mandate never takes over the default, because adding a payment method is not choosing to switch to it.

Three cases deliberately record nothing: a `recurring` payment (it carries the same mandate on every renewal, so recording it writes a row per cycle), a first payment that is not paid (the mandate was never granted), and a paid first payment whose mandate id is not filled yet (Mollie creates it asynchronously for some methods, and the redelivery brings it round).

- **Mollie's next-generation webhook is handled, alongside the legacy ping and the subscription "simple" mode.** The next-generation payload carries two ids — `id` is the event (`evt_…`), `entityId` is the resource — and the receiver read `id`, so it fetched the event id as if it were a payment and then said nothing, once per delivery, at the cost of an API round trip each time. All three forms now resolve one id and run one path.

- **A webhook delivery is now recorded under the provider's own id even when the ping is form-encoded.** The receiver read the id from the decoded JSON body only, so a form post (`id=tr_abc`) fell through to a hash of the request body.

The delivery was correct and unfindable: somebody investigating holds the provider's resource id and had no route from it to the row, and `billing:replay` could not be aimed at one either. The hash was also bound to the body rather than the resource, so a provider adding a field would have produced a second delivery row for the same payment.

The hash fallback stays, for a body that names nothing — removing it would leave such a delivery with no key at all, which is worse than an opaque one. Stripe is unaffected: its JSON body carries the id where the receiver already looked.

- **Mollie webhook signatures are verified where the account signs them.** `billing.mollie.webhook_secret` (`BILLING_MOLLIE_WEBHOOK_SECRET`) accepts a secret or a **list** of them, and the verifier checks the `X-Mollie-Signature` HMAC through the SDK's own validator.

This corrects something the package previously said out loud and got wrong. Mollie runs two generations of webhook: the legacy one is unsigned, and the next generation signs every request. The shipped verifier's own documentation explained, convincingly, that there was nothing to check — which is the worst kind of wrong, because it talks a reader out of a security measure that exists.

Fetching the resource back remains a real defense: an attacker cannot invent a status Mollie will confirm. It is the weaker one, though, and that is now stated rather than implied — it lets anybody who can reach the endpoint drive unbounded processing and API calls against the account by posting real ids, where a signature throws that away before anything happens.

The legacy fallback is deliberate and not optional: with no secret configured the previous behavior stands, so an install still on legacy webhooks does not start refusing every ping the day it updates. With a secret configured, an **unsigned** ping is refused — the operator has said their account signs.

A list is accepted because rotation is a period where both secrets are live. Without that an operator chooses between rotating and losing webhooks, which is not a choice but a reason not to rotate.

- **The Mollie webhook** — `MollieWebhookVerifier` and `MollieWebhookEventMapper`.

Stripe posts a signed event body: the payload is the news and the signature proves who sent it. Mollie posts one field — a resource id — and signs nothing. That difference decides the whole design: the ping is a notification, never information, and **the authentication is the fetch**. What happened is read back from Mollie with the install's own key, so a forged ping produces exactly what a genuine one would, and an id somebody made up produces nothing at all.

Trusting the posted body would let anybody who can reach the endpoint mark a subscriber delinquent with one request. A case pins that directly: a ping claiming failure against a payment Mollie reports as paid emits success.

Silence is a normal answer, twice over. Mollie pings on every transition, including ones with no neutral meaning — a bank debit still settling is not news, and emitting a failure for it would start a dunning ladder against money on its way. And an id that does not resolve changes nothing, which is what a forged ping and a redelivered test payment both deserve.

The verifier's own check is narrower than its name suggests and says so: it refuses a ping that names no resource, which is housekeeping rather than security. Calling it security is how a reader ends up believing the endpoint is authenticated.

- **A seam for mandates the customer establishes** — `EstablishesMandateByRedirect` and `MandateHandshake`, implemented by the Mollie rails.

`PaymentRails::createMandate()` returns a non-nullable reference, which is a promise: after that call, a mandate exists. Some providers keep it. Others cannot — the mandate is born when the customer completes a first payment on the provider's checkout, and may never be born at all. The precondition is now written on the contract rather than left inside the type, because a driver that cannot meet it has three answers and two of them are defects.

A **sibling** contract rather than a method on `PaymentRails`: that interface has implementations outside this package, so appending a method is a fatal error in code the package does not own.

`MandateHandshake` is deliberately not a `MandateReference`. There is no mandate yet — there is a payment in progress and somewhere to send somebody — and calling it a reference would let a caller store it as one and charge against it.

- **The Mollie payment rails** — `MolliePaymentRails`, the lower billing layer for this provider.

Three of the five contract methods are ordinary; two **refuse**, and that is the honest answer for Mollie rather than a gap. `createMandate()` returns a non-nullable mandate reference, which promises one exists after the call — at Mollie a mandate is born only when the customer *completes* a first payment on the checkout, and may never be born at all. `tokenize()` has no equivalent there either: details are captured on the provider's own page, so there is no raw data for a server to exchange. Returning something plausible from either would be stored, charged against, refused, and read as the subscriber failing to pay.

The status mapping keeps **three** outcomes rather than two. A SEPA direct debit sits open for days before it settles; reading that as a decline would start a dunning ladder against money on its way and cancel a subscription somebody paid for. Only a paid payment is success, an expired or refused one is a decline, and everything else is "not yet".

A mandate that is not reusable is refused **before** the provider is reached. Mollie would refuse it too, at the cost of a round trip on every due cycle of a subscriber whose mandate was revoked — and each refusal reads as a payment failure in the log rather than as the settled fact it is.

- **What the Mollie driver promises** — `MollieCapabilities`, plus a `billing.mollie.methods` setting for the payment methods an account offers.

Every native flag is false, and that is the design rather than a gap: Mollie has no customer portal, no provider-side tax, no metered pricing, no proration and no customer balance, so the package fills each with its own engine. A capability is a promise the package keeps, not a description of what a provider could do — claiming one the driver does not wire sends a screen looking for a feature that is not there.

The recurring-capable set is **derived from the SDK's own mandate vocabulary** rather than typed out. A written list would be a factual claim about somebody else's product with nothing holding it true, and it would rot silently the day Mollie adds a method — in the direction that refuses a legitimate mandate.

`methods` is configurable because Mollie enables them per account, so a fixed list would be wrong for most installs in both directions at once. Left unset it falls back to the mandate methods rather than to an empty list, which would read as "this account can take no payments".

- **The Mollie client factory and its configuration** — `billing.mollie.api_key` (`BILLING_MOLLIE_API_KEY`), read only when the Mollie driver is selected, and a factory that refuses loudly when the client cannot be built.

The refusal is the point. An install that selects the driver without a key does not break on deploy; it breaks at the first charge, inside a scheduled run nobody is watching, against a real subscriber, with a stack trace from a third-party HTTP library. Three different problems share the exception and each says which one it is: no key, a key Mollie will not accept, or the suggested package not installed. A blank value counts as missing — a set-but-empty variable is what a half-finished deployment leaves behind and reads as configured to anybody looking at the file.

The SDK validates the key's shape itself and throws its own exception; that is caught and restated so the message names our setting rather than sending whoever reads it into the wrong package.

- **The amount mapping for the Mollie driver** — `MollieAmount`, between the package's minor units and Mollie's `{"currency":"EUR","value":"19.00"}`.

It lives in the driver rather than on `Money`, which is where the ticket asked for it. A `toMollie()` on the value object every driver shares would name one provider in the neutral core — the leak `ArchTest` refuses for imports, one layer down — and it would invite a sibling for each further driver until the core knew them all by name. The conversion itself is already neutral: `Money::toDecimal()` and `fromDecimal()` both work from the currency's minor-unit exponent.

Nothing rounds in either direction. An amount carrying more precision than its currency allows is refused rather than truncated: a provider sending `19.005` for a two-decimal currency is saying something we do not understand, and choosing between `19.00` and `19.01` on their behalf is a guess about somebody's money.

`mollie/mollie-api-php` is a **suggestion**, installed as a dev dependency for the tests. An install that never selects the Mollie driver carries no HTTP client it will not call — the same reasoning that keeps `horstoeko/zugferd` optional.

- **The billing cycle for a driver whose provider does not run one** — `LocalBillingEngine`. Stripe is told when to charge and charges itself, so its engine's `tick()` is a deliberate no-op; this one selects due subscriptions, assembles an order, spends any credit the owner holds, collects the remainder against a stored mandate, and moves the cycle on.

Three properties are what most of the class is about, because they are what a self-directed billing loop gets wrong:

A cycle is **claimed exactly once**, by the unique `(subscription_id, period_start)` on the orders table rather than by a check — a check has a window, a constraint does not. An overlapping scheduler, a retried job or a hand-run command is an ordinary event, and each would otherwise be a second debit.

The charge happens **outside the transaction**. A provider call held inside an open one keeps a row lock for as long as the provider takes to answer, which on the day it matters is a timeout. The visible cost is an order that reads `processing` while the provider decides, which is what is actually happening.

One subscriber's failure **does not take the run down**. A loop that stops at the first exception bills whoever sorts first and silently skips everyone after them, with the failure in the logs rather than in anybody's inbox.

A cycle that fails is **not advanced** — the retry belongs inside the period it failed in — and a past-due subscriber whose retry lands is returned to active with the dunning level reset. Credit is spent before the card, and a cycle fully covered by credit takes no charge at all: providers refuse a zero charge, and a refusal there would put a prepaid subscriber into dunning.

It is deliberately not an abstract base class, though the milestone asked for one. Everything provider-shaped already crosses the `PaymentRails` seam, so a second driver constructs it with its own rails rather than extending it — inheritance with no abstract member is a base class in name only.

- **A local store for the mandates a driver may charge off-session** — `billing_payment_mandates` and the `PaymentMandate` model, with `defaultFor()` and `makeDefault()`.

A provider-driven driver reads its stored methods back over the API. A driver whose engine is local cannot do that on the hot path: the scheduled run collects due cycles unattended, and a call that hangs there stalls every subscription behind it. So the mandate the engine charges is a row read.

Only a mandate whose status is `valid` is ever charged, and a revoked one is never returned as a fallback — handing one back would send the engine into a charge it cannot win and leave the subscriber in dunning over a method they had already withdrawn.

Uniqueness is per provider rather than global: two providers' id spaces are unrelated, so the same reference under both is a coincidence, not a duplicate. The single-default rule is enforced in the model and not by a partial unique index — MySQL has none, and a constraint holding on one of two supported engines makes an invariant true where it is tested and merely likely where it is not. Both rules are proven on real PostgreSQL and MySQL servers.

- **A subscription can now carry, and advance, its own billing cycle** — `scheduled_processing_at` plus `Subscription::advanceCycle()` and the `dueForProcessing` scope. A provider-driven driver is told when the next charge falls; a driver whose engine is local has nobody to ask, and this is what lets `billing:run` drive a cycle at all.

`scheduled_processing_at` is its own column rather than a reading of `current_period_end`. They agree on the happy path and have to be free to disagree: a failed charge is retried inside the period it belongs to, and a dunning ladder that pushed the next attempt out would otherwise move the period with it — silently re-dating the service the customer is paying for, on the row every invoice and usage bucket reads.

`BillingInterval::advance()` does the month arithmetic **without overflow**, which is the part that has to be right rather than approximate: adding a month to 31 January with overflow lands on 3 March, and the subscriber's anchor day is then gone for good, with every later cycle inheriting the drift.

Nothing changes for a Stripe install: the column stays null there, and a null schedule reads as "not scheduled" rather than "overdue" — the other reading would have charged every subscription in the table on the first run after the migration.

- **A credit balance can now be taken apart into the movements that produced it.** `billing_credit_ledger_entries` records every change to an owner's balance — signed amount, currency, a `CreditReason`, and optionally what caused it — and each entry is written in the **same transaction** as the balance it moves.

The balance table alone answered only *what* somebody has. The *why* lived in the audit log: a different table, written by a separate call after the balance transaction had already committed. Two things followed from that, and neither was theoretical. An interruption between the two writes left a balance nobody could account for. And `billing:prune` ages audit rows out on a configurable clock while a balance is a holding and is never pruned — so on an install that shortens the audit window, the explanation expires while the thing it explains stays.

Entries are append-only: no update, no model-level delete. They are owner-scoped and go with the owner on erasure, alongside the balance they explain.

- **`DriverCapabilities::$supportsProviderTrialNotice`** — whether the provider tells the customer their trial is about to end. True for Stripe, whose mapper turns `customer.subscription.trial_will_end` into a `TrialEnding` event; false for a local-engine driver, which is what lets the trial-warning command tell the two apart instead of guessing from the presence of a subscription. Appended last, like every capability before it, because the value object is constructed positionally by driver code outside this package.

- **A local-engine trial is collected when it ends.** `dueForProcessing` selected `active`, `grace` and `past_due` — `trialing` was not on the list, which was harmless while nothing in this package created a local trialing subscription. Both of the new ones are scheduled at their trial END, and both were invisible to the run that collects: no charge, no state change, no log line, and a customer keeping the paid tier indefinitely for nothing. What keeps a RUNNING trial untouched is the date, not the state.

A collected trial becomes `active`, so the account hub stops calling a subscriber a trialist. And a trial that ends with **no payment method** goes `incomplete_expired` rather than into dunning: the customer was told no card was needed, no payment was ever attempted with their consent, and mailing them "your payment failed" is a support case about money that never existed. (Which state exactly, and why it is not `incomplete`, is the entry further down — both describe this one unreleased change, so they say the same thing.)

- **A customer who once ended a subscription can subscribe again.** A subscription is unique on (owner, type, merchant), and an ended row still occupies that slot — so the write collided, on the webhook, *after* the customer had completed a real payment and their mandate had been stored. They would have paid, held no subscription, and left nothing behind but a dead-lettered job. The row is now reused rather than duplicated, and every field of the previous life is cleared with it.

- **Subscribe stopped being an unlimited free-trial renewal button.** On a generic-trial install a lapsed trial was re-granted on every press: another fourteen days, as often as you liked, and every call reporting success while the customer never paid. A generic trial is now granted only to somebody who has not had one; everybody else is sent to pay.

- **A tier key is checked against the catalog's key set, not resolved as a config path.** `pro.price_display` reached a node that happens to be an array, so it passed the guard and produced a subscription against a tier that does not exist — active in every report, never billable. The key arrives from a browser, which is what makes it an injection rather than a typo.

- **A subscription can now be started under a provider that runs no billing cycle of its own.** `StartsSubscriptions::subscribe()` takes a tier KEY — never an amount — resolves it against the configured catalog, refuses an unknown or untouchable one and refuses a second subscription for an owner who already has a live one. Three outcomes, and none of them is the other two: a generic trial is granted on the spot, a subscription trial that needs no payment method creates the row immediately in `trialing`, and everything else sends the customer to the provider to establish a mandate.

**Nothing is written until the mandate arrives.** The redirect leaves a `billing_subscription_intents` row and nothing else, so a customer who closes the tab leaves no half subscription, no state to sweep, and nothing in the subscriptions table describing something that did not happen. `StartSubscriptionOnMandate` turns the intent into the subscription when the webhook reports the mandate — over the webhook rather than the return redirect, because the browser may never come back and the webhook fires either way.

The intent is keyed on the **payment**, not the customer, and that is the safety property rather than a detail: establishing a mandate is also what happens when somebody merely adds a second payment method, and matched on the customer that would hand them a subscription they never asked for on a day they were doing something else. `MandateEstablished` carries a new trailing `paymentReference` for it.

New settings: `billing.subscribe_return_url` (where the customer comes back to, falling back to `billing.checkout.success_url`) and `billing.mandate_verification_minor` (what the first payment charges, in minor units of the plan's currency — the smallest unit by default, because its purpose is to create a mandate and the first cycle is billed by the engine on its own schedule).

- **A first-time subscriber gets a provider customer.** Nothing could create one: the reference was only ever read off an existing mandate, so it answered null for exactly the person a subscribe flow is for. `EnsuresProviderCustomer` creates it, passes the billable's name and email so the provider's dashboard and its dispute tooling have something to work with, and persists the reference on the same column the webhook directory reads. A reference the active provider did not issue is **refused** rather than replaced — replacing it would detach the owner from everything the other provider still holds.

### Changed

- **`stripe/stripe-php` 21 is not adopted yet, and this is the reason rather than an omission.** Cashier 16.8.0 vouches for it, and the SDK itself is not what holds it back: its only breaking change retypes a class this package never reads, and against 21.3.1 the static analysis and the Stripe suite come back clean. What moves with the SDK major is the Stripe API GENERATION Cashier sends, from `2026-06-24.dahlia` to `2026-08-26.dahlia`. An API version decides the shape of every response, so adopting it is a change that only a run against the real API can vouch for, and that run has not happened. The declared range therefore still ends at `^20.0`.

This supersedes the note under 0.13.0 that v21 "cannot be installed here at all" — that was true of Cashier 16.6 and stopped being true on 2026-09-01. It can be installed; it has not been proven.

- **`CreditLedger::credit()` and `::debit()` now require a `CreditReason`.** A default would have let an unexplained movement back in while looking deliberate, which is the state the entries exist to remove. Both also accept an optional `CreditSource` naming what caused the movement.

`CreditSource` is its own type rather than a second model parameter, and that is a guard rather than taste: the ledger's containment test treats any call taking two models as the shape of value moving between parties, because that shape is the risk whatever the author meant by it. Passing a cause as a model would have made a cause indistinguishable from a second owner — to the check and to a reader alike.

If you call the ledger directly, pass the reason that fits; the enum carries only cases something in the package actually writes.

- **The invoice number freeze is now pinned by its own test.** `InvoiceRecord` has refused to change `number` after a record exists all along — it is the first entry in the frozen-field list — but no test named the field. `FrozenTaxCharacteristicsTest` exercises the same guard over the tax characteristics and uses `number` only as fixture data, so dropping it from the freeze would have left the suite green on the one field carrying statutory weight.

`InvoiceNumberImmutabilityTest` closes that, and it pins the strict reading deliberately: a write to `number` is refused even where the column is still null. Numbering is therefore an act of creation rather than a later finalization, which is how every issuer in the package already works.

### Removed

- **`SellerActivityThreshold::isExemptFromReporting()` is gone, and the de-minimis boundary of the reporting duty now has exactly one home.** It is decided by the jurisdiction's reporting profile, from `billing.reporting.goods_de_minimis.*`, and nothing else answers that question.

The method had no caller. That alone would have made it dead code; what made it worth removing was what it read. Both of that class's methods took their figures from the SAME two config keys — so the platform's own "ask this seller to declare their standing" preference and the statutory exemption shared one pair of knobs, in a class whose docblock warned that harmonizing the two would move the legal boundary to match a preference, in the over-reporting direction. Reporting data that need not be reported is an incorrect report in its own right and a data protection breach at the same time, so that is not the cautious direction to drift in.

Nothing about the shipped behavior changes: the live classifier never read those keys. What changes is that it can no longer start to. `SellerActivityThreshold` keeps `requiresStatusDeclaration()`, which has a real caller and is still yours to tune.

- **`InvoiceNumberSequence::format()` is gone.** It turned a counter value into `2026-000042`, and nothing in the package ever called it — its only caller anywhere was the test asserting it. Every invoice number the package issues comes from `DocumentNumberAllocator::allocate()` as `PREFIX-YYYY-#######`, where the prefix is resolved per document series from `billing.marketplace.numbering.series.*` and a missing one is refused rather than filled with a blank.

The two shapes disagreed on every part — no prefix, no year, a narrower running part — which is what made a dead method worth removing rather than leaving alone. It sat on the obvious class under the obvious name, so reaching for it raised nothing and returned a plausible-looking number outside the configured series. Under § 14 UStG / GoBD that is the expensive direction: an issued number is itself a numbered event, so correcting one costs another.

If you called it, call `DocumentNumberAllocator::allocate()` instead — it is where the number your documents already carry has always come from. `InvoiceNumberSequence::next()` is unchanged and remains the counter under every one of them.

### Fixed

- **A Mollie refund no longer prints an internal reference on the customer's statement.** The two charge calls were corrected when the cycle reference moved into metadata; `refund()` kept it in `description`, and Mollie shows a refund's description to the customer exactly as it shows a payment's. So the one moment a customer is getting money back was also the one that looked disorganized.

The guard over it drives **every** money call the rails expose rather than naming the one that was wrong, because this was the second instance of a single defect and a case naming `refund` would prove nothing about the next call somebody adds.

- **The checkout return no longer asks a foreign provider about the customer.** `SubscriptionSync` is bound only by the Stripe provider, and both driver providers register unconditionally — so on a local-engine install the return route asked **Stripe** about a customer whose reference the other provider wrote. An outbound call to the wrong provider on the page a customer lands on straight after paying, and the catch that keeps them from seeing an error then reported it: one entry in the install's error tracker per completed sale, indistinguishable from a real failure. An error stream that fires on every sale gets muted, and the real one goes with it.

Decided on the engine rather than a driver name: a local engine holds the cycle itself, so there is no provider-side subscription to read back. The webhook is the durable path anyway — the reconcile is a courtesy for a customer who beats it home.

- **A charge that has not settled yet is no longer booked as a refusal.** `ChargeResult` states the distinction in its own comment — *"`requiresAction` and `pending` are NOT failures"* — and offers `failed()` for the question. The local engine read `successful` instead, so anything not already settled became a decline.

That is the main European path, not an edge case. A SEPA direct debit is Mollie's principal recurring method and sits at `open` for **days**. Every such cycle was booked as a failure: the credit handed back, the subscription moved to `past_due`, and the subscriber mailed *"your payment failed"* while their money was on its way. The dunning ladder — whose rungs are days — then charged them again, by which time no provider's idempotency window is still open, so it was a **second real charge**.

The cycle is held instead: the order keeps the provider's reference and stays `processing`, the credit stays spent because the payment it paid for is still live, and nothing is dunned because nothing has been refused.

- **The Mollie driver now sends the idempotency key it was given, so a retried charge no longer bills the customer twice.** `PaymentRails` says what the key is for in as many words — *"pass the invoice/charge id so a re-run collapses onto the first charge rather than billing the customer again"* — and all three money-moving calls passed it as the payment's **description** instead. A description is a label; it collapses nothing.

The exposure was the ordinary path rather than an exotic race: an off-session charge that times out is recorded as a refusal, the dunning ladder retries it with the same order id, and the customer paid twice for one cycle. Refunds carried the same omission, where a repeat sends money out twice.

Stripe's rails have always passed a real key, so two drivers made different promises behind one contract — and the contract is what a consumer reads before deciding a retry is safe.

The key is disarmed after every call, whatever happened. The SDK clears it in a *response* middleware, so it clears it only when a response comes back — and a request that throws is precisely the case an idempotency key exists for. The client is a singleton, so a key left armed would go out with whatever called next, and a key that leaks to the next request is worse than no key at all.

- **Credit is spent under the balance's own lock, and given back when the charge is refused.** It was decided before the provider was asked and debited afterwards, with an outbound HTTP call sitting in the gap — the balance read without a lock at one end and the decided figure applied without a re-check at the other. Two cycles for the same owner (two local drivers, or one `billing:cycle` run overlapping itself) both read the same balance while the other was inside its charge, and both spent it. The balance went **negative**, and negative is a one-way street: every later cycle reads a non-positive figure and skips the offset, so nothing collects it back. Two ledger entries, each reading like a correct offset.

The read, the cap and the debit are now one movement (`CreditLedger::spendUpTo()`), and the charge is derived from what actually moved. Spending before the charge is safe because a refusal now returns it, under the new `CreditReason::ChargeOffsetReturned` — the order's credit line and header are put back with it, so a failed order describes the attempt rather than a discount nobody granted.

- **A retried cycle is repriced, so the order states what is charged.** A dunning retry reopened the failed order and left its header and lines exactly as the first attempt wrote them, while the cycle itself is reassembled and re-totalled on every run. A price that moved during the dunning window — a late usage flush landing in the still-open period, a coupon whose `expires_at` passed, seats changed while the customer was past due — was therefore charged at the new figure and invoiced at the old one. The invoice is raised from the order, so that is a numbered, immutable tax document for a sum nobody was charged, and a booking batch off by the difference on every such retry.

- **Two refunds arriving at once can no longer credit past their invoice.** A credit note states the delta against what the invoice has already been credited, and that is a read-modify-write over an aggregate which the webhook backbone does not serialize: two genuinely different refunds against one payment are two events with two ids, so they get two claims and run in parallel. Neither saw the other's uncommitted note, both read nothing credited yet, and both stated their own cumulative figure — 7.50 then 20.00 against a 20.00 invoice is 27.50 of issued, numbered, immutable notes for 20.00 of refunded money, which is the one shape an auditor reads as fabricated. The invoice row is now held while the delta is decided.

- **A yearly or weekly tier is billed on its own interval.** The local engine advanced every cycle by one month, whatever the tier said — so an annual subscriber fell due again thirty days later and was charged the **annual** price off their stored mandate, twelve times a year, with a numbered invoice for each. A weekly tier collected a quarter of what it should. `interval` is a documented tier property the plan catalog already read; the engine simply never asked.

- **Credit is spent after the charge succeeds, not before it is attempted.** A partly credited cycle debited the balance and then asked the provider for the remainder. On a refusal — the ordinary case that starts dunning — nothing gave the credit back, and the retry reassembled the cycle from scratch, found a balance of zero and collected the full amount. The customer lost their credit **and** paid in full, behind a ledger entry that read like a deliberate offset.

- **An order's total now matches its own lines.** The total was written when the cycle was claimed and the credit line added afterwards, so a partly credited cycle stated one figure over lines adding to another — and the invoice raised from it copies the **header**. A numbered tax document told the customer the full amount was paid when less was taken, and the booking batch overstated turnover and output tax by the difference.

- **A cycle that is fully discounted AND has credit behind it no longer strands the subscription.** Two ways of owing nothing arriving together made the credit step try to spend nothing, and `CreditLedger::debit()` refuses a non-positive amount so that a caller cannot smuggle a credit through it. The exception left a `processing` order on the cycle, and the claim will not reopen one — so that subscriber was never billed again, visible only as a repeating log line.

(`Money` itself is not what refused: it validates the currency code and nothing about sign, which is what lets a credit line carry a negative total. An earlier version of this entry said otherwise.)

- **Partial refunds credit what they ADD.** The refund event carries the provider's cumulative total, and a credit note for that figure is the second note stating the whole refund again: 7.50 then 20.00 against a 20.00 invoice is 27.50 of issued, numbered, immutable notes for 20.00 of refunded money. Both reach the booking batch, so turnover and output tax came down by more than was ever given back.

- **The reconciliation no longer reports the destination-charge lane as entirely adrift.** A destination charge moves the merchant's share as part of the payment and makes no transfer call, so no moved figure is ever reported — while the provider still creates a transfer whose reference the row carries. Read as zero, every such row (and every row settled before that column existed) reported a drift of the full transfer value. It falls back to what was owed, exactly as the clawback ceiling already did.

- **The DATEV booking batch is downloadable from the admin console**, for whoever holds the `billing.admin.ability` Gate, re-authorized on the action itself rather than trusting that the render happened. Nothing is written to the server — the file is streamed straight to the browser, so the complete revenue history for a period never becomes a second copy that has to be protected, retained and erased from. A refusal from the writer downloads nothing at all and names which boundary was crossed.

It runs the **same** assembly as the scheduled command, extracted into `DatevPeriodBatch`. Both silent omissions this export has carried — provider fees, then voucher movements, each missing from every real monthly batch — were the assembly drifting away from what the writer accepts, while the tests that prove those bookings pass their own rows in. A second implementation would have been the third occurrence.

- **The merchant journal can now be checked against the provider that actually paid the merchants.** `billing:merchants:reconcile` reads each recorded transfer back from the provider and reports every row where the two disagree, raising `ProviderJournalDrift` for a listener to act on. The reconciliation the package had compared it against itself, which run serially passes forever.

Compared per transfer, never against the connected account's balance: that balance also moves on every payout, on the merchant's own activity elsewhere, and on a provider fee, so a balance comparison alarms on healthy accounts until somebody switches it off.

It reads and never writes. The provider is authoritative for what MOVED and this package for what was OWED, so a drift is repaired by correcting the local row — never by transferring again to match the journal, which would move real money to settle a bookkeeping disagreement.

`--driver` must name the **active** driver. It selects which rows to sweep, while the reader is bound process-wide to whichever driver the install runs — so sweeping another provider's rows through it would ask the active provider about references it never issued and report every one as missing. That is the command's most serious finding, manufactured, once per row.

- **Onboarding a merchant twice can no longer produce two connected accounts.** Account creation now carries an idempotency key derived from the merchant, so a call that fails *after* Stripe committed the account — a timeout, a database error, or an application whose error handler turns Stripe's new Accounts-v2 notice into an exception — returns the same account when it is retried instead of making a second one. The duplicate check on the local row only ever saw merchants whose row had been written; this closes the window before it.

- **A raw `git archive` of this repository no longer carries the private owner's name.** `tests/`, `.github/` and `tools/` had no `export-ignore` rule, so eight files rode along in every archive — five test files, both workflows and a tool script. Nothing public was ever affected: the published package is built from an allowlist and never contained any of them. What was exposed is the archive of the private development repository, which no allowlist stands behind.

- **Canceling a subscription no longer needs `laravel/framework`.** `LocalSubscriptionActions` reached for the `now()` global, which only exists once the full framework is installed — so on the sixteen focused `illuminate/*` components this package actually declares, canceling and scheduling a swap would have died with an undefined function. It resolves the moment through Carbon directly, like the rest of the package does.

- **A bare-id webhook no longer swallows every change after the first.** A provider that pings with a resource id and no event body sends the same id again each time that resource moves, so keying the delivery on the id alone recognized every later ping as a redelivery and dropped it. A payment that opened and then succeeded recorded one delivery, ran one effect, and booked no money — silently, with a ledger row that looked exactly like a correct one.

A mapper now states its own redelivery semantics through the new `DerivesDeliveryKey` contract, and the Mollie mapper keys on the resource id **and** its status. It is a sibling interface rather than a method on `WebhookEventMapper`, because that contract is public and consumers implement it — extending it would be a fatal error in every consumer driver that exists today. A mapper that returns null keeps the receiver's own key, so an unrecognized ping is still recorded exactly once rather than once per retry.

- **A subscription trial is granted once per owner** — and the record of it survives the next subscription reusing the row. The mandate effect wrote `trial_ends_at` plainly, so the second, trial-less subscribe erased the very column the guard reads: one free trial per two cycles, resettable forever at one verification payment a time, with every round looking like a new customer. The sibling writer of that column already preserved it; the two now agree.

- **A subscription trial is granted once per owner.** The trial branch read only configuration — `subscriptionTrialEnabled()` and `endsAt()` know nothing about history — so every press of Subscribe handed out another free period. Getting back to the button takes one click: `cancelNow()` writes `ended`, a lapsed card-less trial writes `incomplete_expired`, and both are deliberately let through so a customer who once left can return. Take the trial, cancel, take it again, forever, never paying. The generic trial states this rule in its own branch; it was missing on the shape that creates a subscription.

- **A card-less trial that lapses is `incomplete_expired`, not `incomplete` — the difference is the way back.** Both stop the sweep by clearing the schedule, and nothing in the package re-arms a cleared one: adding a card produces a mandate with no matching intent, which the mandate effect ignores by design. But `incomplete` is a state the subscribe path PROTECTS, so that customer had no access, was never charged, and was refused by name when they pressed Subscribe. Every card-less trial that did not convert in time became a row only a database edit could rescue.

- **A settling payment intent can no longer overwrite a live subscription.** The eligibility check runs when the customer presses Subscribe; the write runs when the payment settles, which for a bank transfer is days later. Intents carry no expiry and are unique only on the payment reference, so an owner can hold several — subscribe to one tier, abandon it, buy another, and the abandoned payment arrives afterwards with a valid claim. It replaced the tier, restarted the period and wiped the dunning state, decided by webhook delivery order, with no failure and no log line. The slot is now reused only for a row a new subscription may replace, and that list lives on the model so both sides read one answer.

- **Subscribe works on a stock install.** The subscribe flow re-derived its return URL from configuration and refused when nothing was set — which is the shipped state, since `checkout.success_url` is a bare `env()` and the configuration file documents that leaving it unset falls back to the account hub's own route. It now asks `CheckoutUrls`, the one reader every other checkout uses. The refusal remains for the install where it is honest: nothing configured and no account hub either.

- **The plan screen no longer advertises a trial the customer will not get.** The "includes an X-day free trial" hint came from configuration alone, which was the truth for as long as configuration alone decided. Once the subscription trial became once per owner, a returning customer was shown the promise, pressed Subscribe, and reached a paid checkout — the screen offering what the starter refuses, at the one step the whole flow exists for. Both sides read one answer now (`Subscription::ownerHasHadATrial()`), so the promise and the grant cannot drift apart.

- **A paying subscriber is not mailed about their own generic trial.** The owner's `trial_ends_at` is written when a trial is granted and cleared by nothing — not by subscribing — so anybody who converts during their generic trial keeps a future date on their row. The exclusion that used to skip them asked only whether their provider announces trial ends, which is false for a local engine, so a customer in good standing with a mandate on file was told to add a payment method. It now skips a LIVE subscription whatever the provider, and asks the capability only to avoid a second reminder.

- **A local-engine trial is announced before it converts.** `billing:trials:warn` scanned only the owner's own trial column, and skipped anyone holding a subscription because "a provider exists that could send the trial-will-end event". True while Stripe was the only driver that carried one. A local engine announces nothing — nothing at the provider knows a trial is running — so those customers were warned by nobody and the first they heard of their free period ending was the debit. The command now also scans subscription trials, and both exclusions ask the driver's declared capability rather than assuming a provider exists.

- **`billing_reporting_filings.corrects_filing_id` is indexed on PostgreSQL.** `foreignId()->constrained()` reads as though it covered both sides of the key; it does not. InnoDB creates an index for a foreign key by itself, so MySQL always had one — PostgreSQL indexes the *referenced* side and leaves the referencing column bare. Every `restrictOnDelete()` check and every walk up the correction chain was a sequential scan of the whole filings table, getting slower in exact proportion to how many periods had been filed.

It never failed, which is why it lasted: a plan property throws nothing and turns nothing red. Shipped as a new migration rather than an edit to the v0.14.0 one, so existing databases get it too, and guarded against the index InnoDB already made.

- **`supportsConnectDestinationCharges` now answers truthfully instead of always `false`.** The Stripe driver never passed the flag, so it defaulted to `false` on every install — a fully wired marketplace included. Nothing caught it, because nothing in the shipped tree read it: the marketplace guard decides on `instanceof RoutesMoney`, and the only places setting the flag to `true` were test doubles, where it decorates a decision that never looks at it. A field with no producer and no reader is indistinguishable from one that works.

It is answered from the **rails the driver was built with**, not from what Stripe is able to do. Stripe supports destination charges everywhere; a driver constructed without Connect rails refuses at the point of use, and a capability is a promise the *package* keeps — reporting `true` there would send a screen to a lane that answers with an exception.

## [0.16.0] - 2026-08-21

### Added

- **A correcting document now names the reversal it documents.** `billing_invoices.refund_attempt_id`
  stores the join between a correction and the refund attempt whose money it states, so a reader
  holding either row can reach the other. Before this the two were connected only by having been
  written in the same call.

  Stored rather than inferred, because neither obvious match is safe: several confirmations can land
  against one charge, each capped against what was still refundable at that moment, so the charge and
  the date do not identify one of them — and the amounts do not either, since every sum is capped
  against its own ceiling and the clawback floors at zero.

  **Null is an answer, not a gap.** Two of the three paths that correct a chain hold no attempt at
  all: a prepaid term cancellation opens none, and the chargeback effect runs in a different unit of
  work from the reversal. Null therefore says no reversal row stands behind this correction — never
  that the reversal moved nothing, which is what `fee_refund_minor` on an attempt says.

  **It re-clocks no reported figure.** The DAC7 fee is still placed by the money and the value by the
  document, and this link does not by itself bring them together: the case where they part company is
  a sale with no refund in it, which nothing binding a correction to a reversal can reach. Both
  counters, the arm that pins the divergence and the marketplace guide now state that explicitly.

  Additive and reversible; existing rows are null and no caller changes.
- **A settlement transaction can now name the routed charge it settles, and the collective run records
  which document settled it.** `SettlementTransaction` takes an optional `chargeProvider` +
  `chargeReference` pair — carried through `countingWith()` too, so the two opt-ins are not mutually
  exclusive — and `CollectiveSelfBillingEngine::settleMonth()` stamps each named charge with the document
  that settled it (`billing_merchant_charges.settlement_invoice_id`).

  **The gap this closes.** A per-transaction settlement records the answer on the document, which carries
  `settled_charge_reference`. A collective settlement cannot: it puts a creator's whole month into one
  Ultimo-dated document whose transactions are lines, and neither the header nor a line names a charge. So
  after a collective run nothing connected a routed charge to the document that settled it — and a refund
  on such a sale therefore found no settlement to correct and issued no correcting document at all, while
  the money ledger updated correctly and every guard stayed green.

  **Both halves of the pair or neither**, refused at construction with
  `SettlementTransactionChargeIncomplete`. A charge reference is unique only per provider, so a bare
  reference could attach a transaction to another driver's sale and the document would record that it
  settled a charge it never mentioned. The stamp is additionally narrowed by the merchant, so one
  creator's run can never claim another's charge.

  **What it does not do yet.** A collectively settled transaction still cannot be corrected: the collective
  header deliberately carries no frozen tax characteristics — one month has no single archetype — and a
  correction copies exactly those. `settlementFor()` therefore still finds no collective document on
  purpose, rather than returning one a correction would copy nulls from.

  Optional and additive: no existing caller changes, every existing row stays null, and a transaction that
  names no charge settles exactly as before.

### Fixed

- The DAC7 counters now state that the reported value and the reported fee are placed on different clocks.
  Gross inflow counts a transaction by its settlement document (`issued_at`); the withheld fee counts it by
  the money, so a sale settled on 31 March whose document is issued on 1 April puts the fee in one quarter
  and the value in the next. Both counters are right about their own source, and a return that takes the two
  lines from different populations without saying so is not. The offset is now documented on both counters
  and in the marketplace guide, and a test holds it rather than hiding it.

- `SettlementGrossInflowCounter` no longer claims the withheld fees are uncounted. They have been counted by
  `MerchantChargeAnnualEarningsCounter::feesWithheldIn()` since that method shipped, and the paragraph saying
  otherwise reached readers of the published package.

- The Stripe checkout now reads `billing_coupons.provider_coupon_id`. The column has been offered by the
  model and the migration since the coupon tables shipped, but nothing in the package ever read it — an
  adopter who filled it got a discount that never applied, with nothing thrown and nothing logged. The
  checkout prefers the value on the coupon row over the global `billing.coupons.<code>.stripe_coupon` map,
  because the row describes the coupon it sits on while the map needs an entry per code.

  A configuration-only installation is unaffected: with no row, or a row leaving the column null, the
  config answers exactly as before. Only an installation that set BOTH sees a change, and there the row
  now wins. The catalog check still runs ahead of both sources, so a coupon row can map a code to a
  provider id but can never apply a discount for a code the catalog rejects.

## [0.15.0] - 2026-08-19

### Added

- **`MeteredDimension` can now carry a burn rate and an exhaustion date**, and the usage panel renders
  them as one line: *"At about 25 req per day, this lasts until 09/01/2026."*

  Both are optional and **supplied by the provider, never computed here**. A rate needs history the
  snapshot does not carry, and "per day" is a claim about a project's own counting — a mid-period
  reset, a backfilled import, or a meter that skips weekends each give a different honest answer.
  Computing it in the package would make one of them everybody's answer and be wrong for the rest.

  `hasForecast()` requires **both** halves. A rate without a date renders as a speed with no
  destination; a date without a rate is a deadline nobody can check. Supplying one leaves the panel
  exactly as it was, which is also what every existing project gets: the parameters are optional, so
  no caller changes.

  A negative rate is refused at construction rather than drawn — usage does not run backwards, and a
  negative rate would put the exhaustion date in the past.

  Reported from a consumer's capability diff before adopting the hub: its own screen showed both, and
  the hub had no field to carry either, so the capability would have disappeared on adoption with no
  test going red.

- **A usage history can now account for individual movements, not only finished periods.** A project
  that keeps a movement-level ledger binds `SuppliesUsageMovements`, and the history screen gains a
  paginated, chronological stream: what was spent, what was credited, and the reference each came
  from.

  It answers the question the aggregate cannot. `periods()` is one row per period — a month holding
  five top-ups and two hundred sends collapses into a single number — and `topups()` sits beside it as
  a separate list. Both are true, and neither says *why the balance was empty on the 14th when it was
  topped up on the 12th*: the ordering between the two lists is what explains the outcome, and putting
  them side by side is exactly what removes it.

  **A sibling contract rather than a method on `UsageHistoryProvider`.** That interface invites
  consumers to bind their own, so a method added there is a fatal error in code this package does not
  own. The same reasoning already governs `RoutesMoney` and `SuppliesProductArchetypes`.

  **This package's own history deliberately does not implement it.** Consumption is recorded per event,
  but grants land in the prepaid ledger as a running balance with no row per credit — so the shipped
  implementation could offer spending without the top-ups beside it, which is precisely the half-answer
  the stream exists to avoid. A project that records both supplies both.

  Nothing changes for an install that binds nothing: the section is absent, and the screen is what it
  was.

### Changed

- **The pinned analyzer pair moved forward together: PHPStan 2.2.7 to 2.2.8, Rector 2.5.9 to 2.6.2.**
  Both stay pinned to an exact version — the pair is raised, never loosened. Rector reaches into
  PHPStan's private `RichParser::$parser` and writes to it, a coupling neither side owes the other and
  that no version range can express, so the exact pin is the whole protection.

  What carried the raise was not that the suite went green. A green run only measures the combination
  resolved today, and that same observation was true before the last time this broke. It was that
  `RichParser.php` is byte-identical between PHPStan 2.2.7 and 2.2.8: the property being written to
  cannot have moved across that bump, whatever the release notes say. Rector 2.6.2 was read at the
  source as well, and still performs both accesses.

  The pin register records the new measurement in place of the old one. A register that points at a
  version nobody is running is how a pin outlives its reason and then gets cleared away as obsolete.

- **The pinned Stripe API version is now `2026-06-24.dahlia`, the same one Cashier sends.** It was
  `2025-08-27.basil` — a whole API generation behind, which meant this package and the Cashier it
  depends on were addressing the same Stripe account in two different response shapes. Both calls
  succeed and both return an object, so nothing anywhere reported it.

  The split had a cause worth naming, because the guard against it was already written and could not
  work. Cashier builds its client with `Cashier::STRIPE_VERSION`, which is the SDK's own
  `StripeApiVersion::CURRENT` — so its version moves with every `composer update`. Ours is a literal,
  deliberately, and the comment above it asks that no dependency update move it. It guarded the half
  that was never going to drift.

  A new check compares the two directly and fails on the first SDK update that moves Cashier's
  constant, which is exactly when somebody should look. Raising the pin is therefore an **alignment**
  rather than a departure — and the direction is the one the API is going anyway: under the old pin,
  Stripe's Accounts v2 API is not addressable at all.

  **Consumers pinning their own version are unaffected** — `billing.stripe.api_version` still
  overrides this, and that path keeps its own test.

- **The `stripe/stripe-php` requirement now matches what Cashier allows: `^17.4|^18.0|^19.0|^20.0`.**
  It was `^17.3`, which means `>=17.3.0 <18.0.0` and cut off majors 18, 19 and 20 — majors
  `laravel/cashier` has vouched for since its v16.7.0 release on 2026-08-05. Cashier is the library
  that actually talks to Stripe, so it decides which SDK major is compatible; being narrower than it
  meant an application already running on SDK 19 could not install this package, for no reason either
  library could name.

  The symptom pointed away from the cause, which is why it lasted twelve days. Composer reports a
  too-narrow requirement here as *"cashier cannot be updated"*, never as *"your constraint is stale"*,
  and the guard meant to catch it compared the first integer in each range — reading 17 on both sides
  of `^17.3` versus `^17.4|^18.0|^19.0|^20.0` and passing. A range's floor is the one number a
  widening does not move.

  **Consumers on SDK 17.x are unaffected in what they can install** — the requirement still allows
  `>=17.4` — but note that this package's static analysis now runs against the highest allowed major,
  so 18+ is the shape it is verified against.

- **`config/billing.php`: the marketplace tip commission is now written on one line.** Behavior is
  unchanged — the same ternary, the same value, the same environment variable. Only the formatting
  moved, and it moved for a reason worth knowing if you have published this config and diff it
  against ours.

  A `: null` false branch on its own line is a line PHP never executes: the engine emits no
  instruction for a constant-null branch, so no tool that watches execution can ever see it run,
  while a tool that reads the source counts it as code. The two disagree permanently, and no test
  can settle it. Measured directly — `: null` is absent where `: 7` and `: strlen('abc')` in the
  same position are both present.

  The comment above the line says this, and it is load-bearing: re-wrapping that ternary
  reintroduces the disagreement, and nothing you can write will resolve it.

### Fixed

- **The build system this package is developed on no longer appears in shipped prose.** Three passages
  named it — one in the 0.14.0 entry, two that had been shipping since 0.13.0 and reached a public release
  page. Which machinery builds a package is nobody's business downstream: naming it dates the prose to an
  infrastructure choice a reader cannot see, cannot verify, and may outlive. Those passages say "the CI"
  now and lose no meaning.

  The check that should have caught them had been added a day earlier for build **run identifiers**, and
  was narrower than the class it was meant to cover: a command name, a sentence subject and a container
  image all carry the same information without carrying a number. A guard aimed at one shape of a leak
  class is not a guard on the class, and the shape it missed is the one that reads as an ordinary
  technical noun — so nobody stops on it.

  This note is deliberately written without quoting any of it. A changelog fragment becomes shipped prose
  the moment it is assembled, so an entry that documented the leak by example would ship the leak.

- **Two test files no longer depend on which one Pest loaded first.** `CiDefinitionsAgreeTest` exempts the
  cost-bearing CI lanes from a rule on the strength of `LiveProviderPolicyTest` pinning them — and read
  that set from a file-scope constant declared in that other file. It worked in a full run and failed with
  `Undefined constant` when the file was run alone, which is the one way a developer actually runs it
  while changing it.

  Green in the gate and red on the desk is the shape that never gets fixed: whoever hits it is mid-change
  and reasonably blames their own edit. The constant now lives in `tests/Pest.php`, which loads before any
  test file, so the dependency stops depending on load order.

  A guard holds the class rather than this one instance. It strips comments **and string contents** before
  looking, because the first measurement counted five cross-file constants and four of them were a name
  mentioned in a docblock or inside a description — sending somebody to move constants that nothing reads.

- **The manifest no longer points a reader at a file the package does not contain.** The `anthropic-ai/sdk`
  suggestion explained itself with *"`tests/Pest.php` wires it"* — and `tests/` is stripped from the
  published package, so the one person that sentence addresses cannot open the file it names. It says what
  happens now instead of where.

  The guard covers `description`, `keywords` and `suggest` — the prose a registry puts on the page and
  `composer suggest` prints. It deliberately leaves `autoload-dev`, `require-dev` and `scripts` alone:
  those name `tests/` and `workbench/` too, but Composer ignores all three for a dependency, so they
  mislead nobody. Whether they belong in a published manifest at all is a question about its shape, not a
  defect to guard against.

- **Every GitHub surface the release writes to now gets a body it can accept.** The pull request body was
  capped after it broke a release; the release body was not, and the very entry that broke the pull
  request sat at 95 % of *its* ceiling — 119 125 characters against 125 000, with 5 875 to spare. One more
  release of that size and the same class of failure returns, one surface over.

  The capping is now **one function with two call sites** rather than a rule written twice. A second copy
  is the drift this package keeps finding, and a cap that exists on one surface and not the other is
  exactly how the first one was missed.

  The failure would also have been hard to read. Whatever went wrong, the step said *"Check PAT
  permissions"* — sending a reader to audit a token that could not be the cause. The size is now printed
  before the call and named in the warning, so the next failure of this kind is legible from the log.

- **A capped body no longer collapses to its own footnote.** The cut retreats to the last blank line so a
  body never ends mid-sentence; where the cut chunk holds no blank line, the retreat had nowhere to go and
  fell back to a line count that is *zero* for a chunk containing no newline. Measured on a
  200 000-character single line: 215 bytes of output, every word of the entry gone, and the step still
  reporting success. The byte cut is the fallback now — the worst shape this bug can take is publishing,
  and publishing nothing.

- **Dates are written in the reader's own language now.** Every screen used to format with a fixed
  `d.m.Y`, so six of the seven shipped locales got a German date — and for English readers that is not
  merely unidiomatic but ambiguous: `03.09.2026` is the 3rd of September or the 9th of March depending
  on who is looking, and both readings are reasonable. On an invoice, and on the date access expires,
  guessing wrong is not a cosmetic problem.

  One helper now decides, and the locale reaches it explicitly. That last part is the whole substance:
  **Laravel does not carry its locale into Carbon.** `Application::setLocale()` sets the translator and
  fires `LocaleUpdated`, and nothing in the framework listens — the event appears in exactly two files,
  the one that fires it and the class itself. So the obvious fix, a bare `isoFormat()`, would have
  rendered American dates for all seven languages: the same defect pointing the other way, and harder
  to spot, because the test anyone writes first runs under `en` where it looks perfect.

  The invoice spells its month out (`3 September 2026`). It is the one artifact that outlives the
  session it was rendered in, and `03/09/2026` is character-for-character what English produces for a
  different day — a month name needs no locale to disambiguate it. Screens keep the compact numeric
  form, and the admin event log keeps its time.

  **Consumers see this automatically**; there is nothing to configure. If your application sets a
  locale, the package now follows it.

- **Listing invoices no longer drops rows on SDK 18 and newer.** `StripeInvoices::recent()` filtered
  each invoice through `is_string($invoice->id)` before including it. On stripe-php 17.x that guard was
  necessary — `Stripe\Invoice::$id` was declared `null|string` there — but from 18.0 the property is
  `string`, making the check a branch that can only ever be true and, worse, a filter that silently
  determined which invoices a customer sees.

- **Three new checks catch an upstream range change at the source instead of hours downstream.**
  `DevToolPinsTest` now asserts that our SDK range and Cashier's overlap at all, that we cut off no
  major Cashier allows, and — the arm the other two structurally cannot see — that Cashier's own range
  is still the one recorded here, so a *narrowing* is caught as well as a widening. A separate check
  asserts the installed major is at least 18, because the null guard removed above is only safe while
  that holds and no lockfile records which version was resolved.

  Each failure message says so in its first line: **this is probably not your diff.** Since a library
  commits no lockfile, CI re-resolves on every run, so an upstream release reaches these checks before
  it reaches anybody's changes — and without that sentence the finding reads as something the current
  author broke.

- **An optional account-hub section can no longer blank the whole screen when its source fails.**
  `orDegrade()` replaces the entire panel with a "try again" card — correct when a panel cannot read
  its own subject, wrong when a supplementary source is down and everything the reader came for
  loaded fine. Optional sections now use `orOmit()`, which costs the section and nothing else. A
  403/404 still propagates rather than softening into an outage, and the log still carries the
  exception class only.

## [0.14.0] - 2026-08-14

### Added

- **A failed payout reaches the journal.** The package knew what the platform had SENT a merchant, and since
  the provider-reversal event what had been taken back. It did not know whether any of it arrived. `payout.*`
  appeared nowhere in the shipped code, the tests, the configuration or the documentation.

  Those are different questions about different objects. A transfer moves money from the platform's balance
  to a merchant's connected balance; a payout moves it from there to their bank, and it fails on its own
  terms — a wrong IBAN, a closed account, a bank that refuses it — with nothing wrong about the transfer that
  fed it. Between the two there is a window where the merchant's money is neither on the platform nor at
  their bank, and a failed payout leaves it there. The support case is "I have not been paid", and nothing
  answered it.

  **Only the failure is recorded, and that is a decision rather than an omission.** The success is the
  ordinary case that the provider's own dashboard already shows — a row per merchant per cycle nobody reads.
  A per-payout table reconcilable against a provider statement is a reconciliation ledger and belongs to that
  line of work. It is attributed to the MERCHANT and never to a charge, because a payout bundles many
  transfers and has no 1:1 relation to a recorded sale.

  Mapped on the merchant endpoint only. The platform mapper is unchanged and a test holds it so, because
  teaching it this event would start an effect running on every existing single-seller installation at the
  next deploy.

- **Every billing notice that asks the reader to do something now links to it.** The trial-ending mail told
  the customer to add a payment method and gave them no way to get there — and so did the other ten. Across
  all eleven, the shipped code called exactly `->subject()` and `->line()` and nothing else.

  The targets had been there the whole time, and the package already used `Route::has()` in five places for
  exactly the "link only if the app mounted it" case. `BillingNotification::actionUrl()` is that guard, in
  one place: the account hub registers only when Livewire is installed and Livewire is a `suggest`, so a
  consumer installation may genuinely not have those routes, and a bare `route()` would fatal while sending
  somebody a notice about their money. Where the route is absent the mail renders exactly as before.

  Where a notice points depends on what it is for — a failed payment and a suspension warning to recovery, a
  card about to expire to payment methods, a receipt to the invoice history — and the trial notice picks by
  payment-method status: a card already on file means the reader is deciding whether to continue, so they
  get the plan screen rather than the one that takes a card.

  **One notice deliberately has none.** The tax-standing change reports a decision somebody else already
  recorded and asks for nothing, and the hub has no screen that edits it. A button there would read as "this
  is where you fix it" and land somewhere that cannot — worse than no button. The reason is written at the
  code, and a test holds both the exception and its explanation.

- **The admin console can end a subscription — it could only ever give a tier away.** `BillingAdmin` builds
  three out-of-band support operations and its own docblock names them as one set: comp, refund, cancel. One
  of them reached an operator. `BillingAdmin::cancel()` had no console action, no command, and no wording in
  any of the seven shipped locales.

  So a support agent asked to end a subscription — an abuse case, a threatened chargeback, a customer on the
  phone — had no path to it in the package. What was left was the provider's own dashboard, where the local
  row drifts and this package's audit trail stays empty, or consumer code reinventing an authorization gate
  that already exists and already fronts the at-least-as-consequential comp action.

  The console now carries a cancel action behind the same fail-closed `billing.admin.ability` gate, checked
  at mount, at render AND at the action; `billing:subscription:cancel {owner} {--reason=}` is its terminal
  twin, because an operation reachable only through a UI is unreachable exactly when the UI is what is
  broken. An unknown owner is reported, never fataled, and the command FAILS on one rather than reporting
  success over a no-op. Every execution writes an `admin.cancel` audit row naming the actor and the reason.

- **Somewhere for the realtime toasts to land — opt-in, and off by default.** The account hub's headless
  bridge relays a broadcast as a `wirekit-toast` browser event, and the package shipped nothing that
  listened: not one JavaScript file and not one `<script>` tag anywhere in what it ships. On an installation
  that switched realtime on, every toast reached the browser and vanished.

  That failure is entirely green. The broadcast goes out, the component is mounted, its test passes, the
  event is visible in the browser's own tooling, and nothing is drawn — so the consumer looks for the fault
  in their own application, and the documentation, which described a toast region without saying whose,
  agreed with them.

  `billing.realtime.render_toast_region` (`BILLING_REALTIME_TOAST_REGION`, default `false`) now renders a
  minimal one: two `aria-live` containers — polite for `info` and `success`, assertive for `warning` and
  `danger` — and a small inline listener that appends the message as TEXT and dismisses it after a few
  seconds. No UI kit, no build step.

  **It is off by default on purpose.** The consumer for whom toasts already work is a WireKit host, whose
  own region reads exactly this event; a second region would show them every toast twice, and that
  duplicate is visible only in a browser. The documentation now names all three answers — a WireKit host,
  this region, or a one-line listener of your own — because choosing none of them is the silent no-op above.

- **Every frozen column on an issued invoice is now proven frozen, not six of forty-three.** The model froze
  forty-three scalars; the suite that exists to prove it listed six by hand — the ones somebody had thought
  of. The other thirty-seven were unproven, and one of them, the EN 16931 exemption reason, was genuinely
  editable on a document that had already gone out.

  The rejection set is derived from the model's own frozen list, with a written table of a DIFFERENT valid
  value per column. Written rather than generated on purpose: a generator can land on the value already
  there, `forceFill` then marks nothing dirty, and the case passes having proven nothing — silently, and more
  so the more columns it covers. A newly frozen column without a value now fails with a sentence naming it.

- **The two lanes that can spend something now have their event lists held by a test.** Each names three
  brakes "all of which have to hold"; two were held by nothing. The live-provider suite had no assertion
  keeping it out of `phpunit.xml.dist` — the one edit that would pull real provider calls into every push,
  every pull request and every local run on a machine with a key — while its sibling eval suite had exactly
  that assertion, with a paragraph explaining why.

  Worse was the first brake. Adding `push` to a lane turned exactly one arm red, and its message said the
  lane "belongs on the gate pool". Following that instruction turned every arm green again with `push` still
  in the event list: the only reflex to a credential leak was a request to finish it. That arm now steps
  aside for a cost-bearing lane and defers to an assertion pinning its events to exactly cron and manual.

  The third brake — the event list of the CI secret itself — lives outside this repository and is named as a
  hand-check rather than implied to be covered.

- **Two shipped config comments described a package that no longer exists, and a guard now catches the
  shape.** `config/billing.php` told every adopter that the second provider call needed to pay a merchant
  their share "is not implemented yet" — it ships, bound unconditionally and made by `RoutedPayment::charge()`
  — and advised routing sales the other way because of it. A second key's comment said "NOTHING READS THIS
  KEY YET" about a key with five readers, one of them the fail-closed binding the same paragraph insisted did
  not exist.

  A claim that a capability is missing is the only kind of documentation falsified by SUCCESS: nothing fails
  when the thing is built, the tests go green, and the sentence quietly becomes untrue in the file an adopter
  opens first. One earlier commit had already fixed three copies of the same claim and missed the fourth; a
  fifth turned up in `ConfiguredChargeType` while this was being written. Both corrected texts now describe
  what the code DOES, which cannot go stale by succeeding, and a guard fails the build on the claim shape.

  The distinction the old text lost is kept: `PaymentRails::charge()` really does refuse a separate-transfer
  routing, permanently and on purpose — it has already returned by the time a transfer could go out. What was
  wrong was calling that a missing feature and steering operators to another lane over it.

- **The account hub's actions are now enumerated rather than remembered.** `EligibilityGateTest` promised in
  its own name that it blocked "every money-initiating entrypoint" and then listed five by hand. The list was
  right and stayed right; it was simply never derived, so a sixth action that skipped the eligibility check
  fell into no set at all.

  A census now discovers every public action on a hub screen by reflection and requires each to be classified
  as money-initiating — proven from the METHOD'S OWN SOURCE, not against a second list — or as starting no
  payment, with the reason written down. The overclaiming comment is corrected too, including its stake: an
  ungated action was never a money bug, because the money seams are guarded a layer deeper; what breaks is
  the error path, and a restricted customer is told the site is broken instead of that their access is
  limited.

- **A guard now holds the Postgres and MySQL suites in step.** They are deliberate filename twins — the
  repository instruction says a DB-touching test belongs in both engine suites, and the files say so about
  each other — and nothing compared the two directories. The relationship existed only in the care of
  whoever was writing a test at the time.

  The failure direction is the quiet one: a missing file looks like "there is nothing to test there", never
  like "somebody forgot", and six months on the asymmetry reads as a decision nobody can explain. The
  temptation is strongest exactly where the suites matter most — "MySQL cannot do this anyway" — and the
  twin already in the tree shows the right answer is a DIFFERENT test on the other engine rather than none.
  A deliberate one-sided file now stands in a named exception map with its reason.

- **`revokeContent()` and `revokeForMerchant()` — a takedown finally has a call, and the index built for it
  finally has a reader.** The revocation surface offered one grant, one purchase, one payment, and nothing
  that took a WORK. So an operator served with a legal order, or deleting a creator's account, had no way in
  the package to end access across every owner: they queried the table themselves, or worked the order by
  hand. The migration had built the index for exactly that read and named it in its own comment; nothing in
  the repository had ever used it, and `RevokeReason::CreatorDeleted` had no producer anywhere, not even in
  a test.

  Both are chunked — a popular work has tens of thousands of owners — and both walk by id rather than offset,
  because revoking changes the very column the query filters on and an offset walk would skip every second
  page while reporting success.

  **A delisting is not this, and the distinction is stated in both the code and the reference.** A work whose
  publication ended answers `ContentAvailability::ContentGone` and the grant is deliberately left alone;
  somebody owning a work that is no longer sold is the ordinary case. Reaching for a revocation there would
  take away something people bought.

- **A statutory withdrawal now reaches the ownership register with its own reason.** `RevokeReason::Withdrawal`
  had no producer anywhere in the package. The withdrawal entry point never touched the register: the refund
  it triggers came back as a webhook, the effect stamped the grant `refund`, and because a revocation keeps
  its FIRST reason, `withdrawal` could not be written afterwards — a correction that failed by doing nothing.

  So in an operator's data every exercise of a statutory right looked exactly like a goodwill refund they had
  chosen to give, which is the one distinction the trail exists to make. Two places in the package promised
  it out loud while the shipped path could not produce it.

  Withdrawal now revokes the purchase's grants itself, before the refund comes back — so the later delivery
  finds a row already revoked and the reason survives without anybody coordinating the two. Gated by exactly
  the two switches the refund effect reads, so no new policy is introduced by omission. A refund the provider
  REFUSED revokes nothing: the same rule the correcting documents follow, because taking access away from a
  buyer who is still out of pocket is worse than doing nothing.

- **A merchant whose relationship ended can be reopened, and onboarding stops claiming it already can.** A
  merchant who disconnects their provider account is terminated here and cannot receive money. Nothing in the
  package could undo that: the column recording the disconnection was set in one place and cleared nowhere,
  the only writer of the active status refuses a terminated merchant by design, and onboarding handed the old
  unreceivable row straight back — with exit 0 and a link to an account the provider no longer releases funds
  through. The class docblock meanwhile said termination "is undone by onboarding again".

  So the documented repair produced a plausible success and changed nothing, and the remaining option was an
  UPDATE against the table by hand, past every invariant the package holds, with no event and no audit trail.

  `billing:merchant:reopen` is the way back, and it is deliberately a command rather than a webhook effect:
  the lifecycle refuses to let a capability report reinstate a terminated merchant — a provider goes on
  reporting healthy capabilities long after an owner disconnected — and that guard only means something while
  the way back is somewhere a webhook cannot reach. A test derives the effect list from disk and fails if one
  ever calls it.

  Reopening resets the three capability flags rather than carrying them forward: they were gathered before the
  disconnection, and declaring a merchant receivable on that basis is the mistake the conservative answer
  avoids. Onboarding an ended relationship now throws `MerchantRelationshipEnded`, and the command reports it
  with a non-zero exit.

- **The seller's commission invoice — the half of intermediation that was described everywhere and built
  nowhere.** Under intermediation the platform makes two supplies of its own: a fee to the buyer and a
  commission to the seller. Only the buyer's half existed. Every commission number the package drew belonged
  to a document owned by the BUYER, so a platform withheld a commission and invoiced it nowhere — a taxable
  supply made and not documented, and a seller holding no document with which to deduct the tax on money
  kept from them.

  What hid it is that the package already described the missing half as present, in three places: the regime
  enum answers true to "does this charge the seller a fee", the shipped configuration calls the buyer fee "a
  separate supply from the seller-side commission", and the reporting period states that the platform issues
  the seller a commission invoice. Three descriptions of something that had never existed in the
  repository's history.

  `SellerFeeCalculator` and `FanReceiptIssuer::issueSellerCommission()` build it, off by default
  (`billing.marketplace.seller_fee.enabled`) so no existing installation changes. Two deliberate asymmetries
  with the buyer side: a fixed commission is CAPPED by the sale, because it comes out of the payout and an
  uncapped one would owe the seller a negative amount; and the calculator REFUSES outside intermediation
  rather than returning nothing, since a commission in the commission chain is a named red line and refusing
  before a number is drawn keeps a gapless series from spending one. The taxable base is the commission
  alone — the mediated sale is the merchant's turnover and appears nowhere on the platform's invoice.

- **`ListsEarningCurrencies` — a consumer can finally ask which currencies a creator has earned in.** Every
  per-merchant reader takes the currency as a required parameter, which is right: a currency is a bucket and
  is never converted. What was missing is the question one step earlier — which buckets exist for this party
  — so "balance per currency" had exactly one route, querying `billing_merchant_charges` directly and
  coupling an application to a schema this package owns.

  The near-miss was the worse half: `billing.tax_exchange_rates.currencies` looks like the answer and is
  silently incomplete, listing what rates are imported for rather than what money is settled in. A creator
  earning in an unimported currency was shown one balance fewer with nothing reporting a problem.

  A sibling contract rather than a fourth method on `LedgerBalanceReader`, because that interface is
  documented as implementable by a consumer and a method added to it would stop their class from satisfying
  it. Both bind to the same projection, and the marketplace guide now documents the read layer, which it
  never did.

- **`billing:doctor` now reports how old the imported exchange-rate series is, per currency.** The import
  runs daily; when a publisher is down for one currency the command reports it, skips that currency and
  returns SUCCESS anyway — no error accumulator, no failure hook on the schedule entry. Every exit-code
  monitor read green while a series quietly stopped growing.

  Nothing anywhere read the newest `rate_date`, so the first visible effect of a broken import was an invoice
  that could not be issued: the lookup walks forward at most fourteen days and then refuses the document. A
  warning that arrives at that point is not a warning, it is the incident. `billing.tax_exchange_rates.max_age_days`
  defaults to three days — two missed imports plus a weekend — and the forward window stays the ceiling, so a
  limit set above it cannot make the diagnostic agree with an operator against the money path. Silent where
  the local store is off or no currency is listed.

- **`billing:vouchers:volume` — the supervisory threshold stops being passed in silence.** The monitor that
  computes rolling voucher volume was called by no line of production code: its only occurrence in `src/` was
  its own declaration, and `breached()` had exactly one caller, inside the same class. So the package knew
  the threshold had been passed and the person who has to file did not. The ordinary way to find out was a
  letter, months later, about a notification due eleven months ago.

  Two events, deliberately separate rather than one with a level: `VoucherVolumeThresholdApproaching` says
  there is still time, `VoucherVolumeThresholdBreached` says there is not, and a recipient treating them as
  one message would let the early notice stand for the late one. Both carry the figure, because the window is
  rolling and what triggered the message is not what a recipient would compute on reading it.

  Announced once per level, per currency, per calendar year — daily would turn the channel into noise, and
  once-ever would swallow a genuinely new crossing years later. The currencies come from the vouchers
  themselves rather than a configured list, so a currency somebody sells in cannot go uncounted by omission.
  The schedule entry is registered only where `billing.marketplace.vouchers.enabled` is on: it is the one
  conditional entry in the package, so `schedule:list` shows what actually runs.

- **`billing:doctor` now reports how old the distance-sale threshold is.** Four dated jurisdiction facts
  each promise a `*ValidFrom()`; three had a reader and this one had none anywhere in the package, while its
  own contract said the date exists "so its age can be reported rather than assumed". An operator seeing the
  rate table's age and the union membership's age reasonably concludes the dated facts are watched.

  The limit is hard-coded in a consumer's profile and is exactly the sort of number that goes on working
  while it goes out of date: a legislator moves it, the monitor keeps computing, the result stays plausible.
  Too high and the operator books at the destination too late, too low and too early — a tax question, not a
  display one. Reported, never failed, and with no borrowed date: this package ships no limit of its own, so
  where no profile supplies one the doctor says exactly that. `DistanceSaleThresholdMonitor::thresholdValidFrom()`
  is the reader, the mirror of `thresholdMinor()`.

- **`RetentionMatrix::datesBy()` — a consumer can now say which column dates their own table.** The
  companion to `extendWith()`, and the half that was missing: a consumer could declare a retention rule for
  a table the package does not own, but not that the table is dated by anything other than its creation. So
  such a table ran on the wrong clock, by up to a year, in the direction that deletes too early.

- **`billing:reporting:run {year}`** and **`billing:reporting:file {year}`** — a shipped operator path for
  the reporting chain. The seams behind it (`ReportingExport`, `ReportingFilingRegister`) deliberately had
  no internal caller: when a return is prepared is the host application's decision, and this package
  transmits nothing. That stands. What was missing is a path shipped **with** it — without one, every
  adopter writes the same three calls and each decides afresh whether a plausibility refusal is fatal and
  whether a second submission is a correction. Those are answers this package already has, and leaving them
  to be re-derived per install is how they get derived differently.

  **The plausibility check blocks rather than warns**, and the command carries that through: a period with
  open findings produces no bytes at all. Producing a file and then saying "do not send this" leaves an
  artifact indistinguishable from a filed record.

  **Producing and filing are separate verbs.** Producing is repeatable — rebuilding is how somebody checks
  whether the figures moved. Filing is not, and making it a flag on the repeatable command would put an
  irreversible act one character away from every rebuild.

  The package still **transmits nothing**: it holds no portal credentials. What `file` records is that an
  operator submitted *these exact bytes* on this day, which is why it takes the produced record and not a
  year. `--by` is recorded verbatim, defaulting to the shell user rather than to an invented label — a filing
  is somebody's act, and "system" on that column would name nobody at the moment somebody has to be named.

- `ReportingFilingRefused::nothingToCorrect()` — a period whose first filing never happened is not
  corrected, it is *filed*. A correction with no predecessor would put a record into the chain claiming to
  amend something nobody ever sent.

- A counter for buyer gross per subdivision of a destination country — the early warning for an obligation
  that is reached per state rather than nationally, which a country-level total cannot see coming. It is
  its own measurement rather than a view of the reporting counter: that one measures what reached a
  *seller*, this one what a *buyer* paid, and on one sale those are different numbers.
- A sale whose subdivision was never settled is counted in an explicit `unknown` bucket. A guessed state is
  not a smaller error than a missing one — it raises a threshold in a place nobody sold into.
- `billing.tax_counters.us_state_gmv.enabled` switches it, off by default and independently of the other
  counters in both directions. Asking a disabled counter refuses rather than answering zero, because zero
  here reads as "we sold nothing into that state".
- Buyer receipts now carry `destination_subdivision` beside the destination country, and
  `PlaceEvidenceStore::subdivisionFor()` gives a caller the settled value to write there.

- `RecordRoutedSubscriptionCharge` writes one row per paid cycle, keyed on the INVOICE. Keying on the
  subscription would collapse every cycle onto one row: `firstOrCreate` would find the first cycle's row for
  the twelfth cycle's payment and write nothing, and every reversal cap afterwards would answer for the
  wrong month.
- `ReadsRoutedInvoiceCommission` is the seam the commission comes back through, and the first time an effect
  in this package reaches a provider at all. The boundary is crossed through a contract rather than
  dissolved: the effect asks "what was withheld on this invoice, and for whom" and never learns whose API
  answered, which is what makes it testable with no provider present.

- A § 18 plausibility step that runs *before* a reporting period is exported and refuses it while findings
  are open. It names every finding at once — undecided classification, a seller record that cannot be
  filed, quarters that do not add up to the year, a seller reported twice — instead of stopping at the
  first, so a period is worked through in one pass rather than one run per problem.
- Each finding can be acknowledged individually, on the record, with who and why. An acknowledgement
  clears one finding for one period: the same finding in the next period is a new finding, so an answer
  can never quietly become a switched-off rule.
- `SuppliesSellerRecords` lets an application expose its seller master data to the check. Leaving it
  unbound is reported as a finding rather than treated as a pass — "we could not look" and "we looked and
  it was fine" must not produce the same answer.
- The rule catalog is open: `ReportingPlausibilityRules::add()` takes a consumer's own rules, and what
  varies by jurisdiction comes from the bound `ReportingProfile` rather than from rules that would have to
  be switched off.

- A produced seller-reporting record can now be recorded as **filed**, which is what separates a period that
  may be produced again from one that is settled. `ReportingFilingRegister` files a period once — a second
  first filing is refused rather than absorbed, because over-reporting is the direction an authority
  sanctions and the duplicate is otherwise found by the seller whose figures went out twice. Anything that
  moves afterwards goes out as a **correction naming the filing it supersedes**: a correction that names
  nothing cannot be recorded, one that answers an already-corrected filing is refused, and one carrying the
  same bytes corrects nothing. `needsCorrection()` answers whether a freshly produced period still matches
  what was filed, and both the export and the filing stay immutable, so a period's history is a list of
  facts in the order they happened rather than a state somebody maintains.

- An archive for produced seller-reporting records. It keeps the exact bytes with the moment they were
  produced, a fingerprint over them, and which format and format version they were built to — because a
  file on a disk can be moved, regenerated or edited between production and filing, and none of that leaves
  a trace. A second run of a period is a second row, never an overwrite: figures move as late corrections
  land, and whether the two runs agree is the fact worth keeping. A written record cannot be edited.
- `billing.marketplace.reporting.export_disk` optionally puts a copy on a disk. Leaving it null keeps the
  record and writes no file, which is a supported answer rather than a missing setting.

- A reporting export that produces a period's record **after** the plausibility check and never instead of
  it: `assertClear()` runs before a byte is rendered, so a period with open findings produces nothing at
  all — no file, no row, no partial state to decide what to do with.
- `RendersReportingRecord` lets a consumer register their own target format with its own version, so a
  platform under a different duty reports what it owes without having to switch off a foreign format that
  was never theirs. The shipped renderer is a complete, deterministic delimited record; it is deliberately
  not any authority's wire format, because a guessed schema validates nowhere.
- Two runs over one unchanged period produce identical bytes — the property the archive's fingerprint
  comparison rests on, and one that is lost silently by an unordered iteration, a locale-dependent number
  or a timestamp inside the payload.

- `PurchaseDeclarations` is the point where a buyer's two pre-purchase declarations are taken, and the gate
  that will not let a checkout start without them. `WithdrawalConsentLedger::record()` had no caller
  anywhere in the package: the two declarations, the frozen wording version, the fail-closed gate before
  provision and the profile deciding the jurisdiction were all built, and every one of them read a record
  nothing ever wrote. With a profile active that meant the gate saw `null` for every purchase and refused
  every provision of a work whose right ends at delivery — safe, and unusable, because there was no way to
  give the consent it demanded.
- The package mints the key the declarations are written against, rather than taking one from the caller. A
  declaration has to be recorded before the buyer leaves for the provider, and at that moment the purchase
  has no reference of any kind. A caller-supplied key (a cart id, an order number) stays welcome beside it
  but cannot carry the uniqueness: a declaration is evidence, and evidence whose uniqueness depends on the
  discipline of whoever passes it lets a reused order number silently cover today's purchase with last
  month's declaration.
- The key makes a round trip — stamped on the provider's checkout session as opaque metadata, read back off
  the completion webhook, and used to find the record. That trip is what the whole path rests on, and its
  failure mode is the cruel one: a metadata field the provider drops comes back as "no declaration on file",
  indistinguishable from a buyer who declared nothing. It is pinned end to end, not hop by hop.

- `BillingAdmin::refund()` takes a `RefundKind`, so a statutory withdrawal is distinguishable from a
  goodwill refund in the audit trail. The two move the same money and are different events in the books:
  one is a decision the platform could have made otherwise, the other a right the buyer exercised. The
  free-text reason stays — it says what happened in one case — but only a category can be grouped, counted
  or filtered, and two operators describing one event write two different sentences.
- The default is `goodwill` rather than an unspecified value, because that is what every refund this
  package has issued so far actually was; nothing in it could exercise a withdrawal right yet.

- `ConsumerWithdrawal` gives `ConsumerWithdrawalPolicy::valueForUse()` the caller it never had. A
  subscription withdrawal now keeps the value of the part already provided and returns the difference,
  instead of refunding everything or nothing depending on what an operator typed. The refund is the
  subtraction rather than its own rounding, so the two halves add back to the payment exactly — 29.75 is
  6.94 retained plus 22.81 returned. A period used in full moves no money at all, which is not a refund of
  zero. Without an active consumer-rights profile nothing is retained, and that answer comes from the gate
  rather than from any jurisdiction's rule.
- A withdrawal on a routed sale now corrects BOTH links of the chain, not only the buyer's receipt. The
  creator had already been settled for the whole period, so a correction on the buyer side alone left the
  self-billed invoice saying the creator earned all of it — the creator kept a share of days nobody
  received and the platform covered the gap out of its own margin. Both documents add up on their own,
  which is why nothing caught it. The correction goes through the existing `RoutedRefundCorrector`, the
  same path a lost chargeback and a canceled prepaid term take, so document issuance and period assignment
  stay in one place. Only what the provider actually returned is corrected, and only after it moved.

- **The receipt now repeats both pre-purchase declarations in the wording the buyer was shown.** § 312f
  wants both: the declarations collected *and* confirmed on a durable medium, and the right of withdrawal
  ends only once that confirmation exists. A declaration collected, recorded and never confirmed leaves the
  right standing — the most expensive failure on this path, because everything about it looks like success:
  the ledger holds the consent, the gate lets the provision through, and the buyer can still withdraw for
  fourteen days.
- `billing_withdrawal_consents` keeps the wording of each declaration beside the version that names it.

  **The wording is a snapshot, not a lookup, and that follows the ticket's own argument one level up.** A
  receipt linking to "our withdrawal policy" confirms nothing: the linked text changes and the purchase does
  not. A registry keyed on the version is the same reference with a key in front of it — it can be edited and
  it can lose a row, and when it does, a legal proof becomes a 404 years later at exactly the purchase
  somebody is arguing about. This package already draws that line: `billing_invoices` freezes the buyer's
  gross, the rate it was taxed at and the seller's standing onto the document. The text of a declaration is
  the same kind of fact, and duplication across rows is the price of provability.

  **Two columns, not one.** They are two declarations about different things, and the package refuses to treat
  them as one checkbox. Both or neither reaches the receipt: one of the two reads as a complete confirmation
  and is not.

  **`ReceiptNotifier` is unchanged.** It has one method and is implemented outside this package, so a new
  required parameter would be a fatal error in code this package does not own. The shipped notifier looks the
  declarations up itself, which means no adopter has to change a line — and an adopter with their own notifier
  decides deliberately whether to do the same rather than losing them silently.

- The fee now goes back as **its own position**, recorded as `RefundKind::WithdrawnBuyerFee`. Not tidiness:
  it is a second supply between a different pair of parties, with its own rate and its own place, so one
  combined refund would be a figure no document could carry and one nobody could find again.
- **It comes out of the platform's share alone.** The fee rode on the application fee and never entered the
  merchant's transfer, so returning it through the ordinary routed-refund path would claw back from a
  creator who was never paid it. `BillingAdmin::refundPlatformSupply()` is that path: no transfer reversal,
  no entitlement clawback, no cap against a sale gross the fee was never part of.
- The charged fee is **frozen onto the sale** — `buyer_fee_gross_minor`, `buyer_fee_net_minor`,
  `buyer_fee_tax_minor`, `buyer_fee_place_of_supply` on `billing_merchant_charges`. A withdrawal returns
  what was *charged*, and recomputing it later would price an old sale at today's rate, model and place —
  three settings an operator can change without leaving a trace. The result would be plausible, which is
  what makes it the dangerous kind of wrong.
- `buyer_fee_refunded_minor` counts what has gone back, so a retried withdrawal returns the fee once. Its
  own counter, deliberately: `refunded_minor` is capped against the sale's gross, and sharing it would
  break in both directions — a fully refunded sale would have no headroom left for the fee, and a sale
  whose fee came back would read as over-refunded.

  **C2C between private parties keeps the fee, and that is not an oversight.** With no consumer contract
  there is no right to withdraw, so nothing undoes the mediated sale — but the mediation was still performed
  and is still a service. `WithdrawalType::NotApplicable` is the case, and it has an assertion of its own
  rather than a comment, because it is the one that an ordinary reading of "fees share the fate of the
  transaction" gets backwards.

  The frozen line records a gross with **no tax split** on the hosted lane, exactly as the commission beside
  it records `commission_tax_bps` as `0`: that lane hands tax to the provider and holds no rate of its own.
  Deriving 20 % from `AT` would look like knowledge and be a guess. The gross and the place are exact, and
  those are what a return needs.

  Installations without buyer fees are unaffected: the columns stay `null` — which means *no such supply
  happened*, a different statement from a fee of nought.

- A guard requiring every braced variable meant for the shell to be escaped. The line is drawn where the
  audience changes: `CI_*` belongs to the server and is left alone; everything else needs two dollars to
  survive that far. Red-probed on both real shapes — the defect above, and a placeholder in a **comment**,
  which the substituter also reads.

  A CI config linter calls all of this valid: it checks the schema, and the substituter runs afterwards on
  the server. A green lint is not a signal here, which is why the rule lives in a test rather than in a
  comment. The exemption is exercised in the test too — nothing in this repository writes `${CI_…}` today, so
  an unexercised escape hatch would be one nobody has checked.

- The routed one-time checkout now charges the fee as its own line when an installation asks for one, and
  **raises the application fee by it**. That second half is the whole correctness of the lane: the provider
  moves everything that is not the application fee to the merchant, so leaving it alone would hand the
  platform's own intermediation revenue to the seller — on every sale, with both figures looking ordinary.
  The merchant's share of the item does not move.
- The fee line is priced **tax-inclusive**. Not a preference: a buyer fee is quoted gross, so the buyer is
  told 5.00 and pays 5.00. Sent exclusive under a provider tax mode, the provider would add tax on top and
  the buyer would be charged more than they were quoted, while the figures recorded here described the
  smaller sale.
- `billing.marketplace.buyer_fee.place_of_supply` — the country whose rate the fee carries. It is where the
  *mediated sale* happens, never where the buyer banks. **Required once fees apply, and asked only then:** an
  install with fees switched off never reaches it. It is not derived from anything, because there is nothing
  honest to derive it from — a first draft fell back to the currency, and `EUR` is not a country.

- **`billing:merchant:onboard {type} {id}`** — creates a merchant's connected account and prints the
  onboarding link they open. Both verbs behind it had shipped with the marketplace lane and nothing could
  reach them: twenty-eight artisan commands, none of which touched onboarding, so connecting a creator
  meant a tinker script against the rails.
- **`billing:merchant:status`** — the three capability flags per account plus what the provider is still
  waiting for. It answers the question that comes up first on every marketplace install, *where do I turn
  `charges_enabled` on*, by replacing it: nowhere, because it is not a switch. The provider raises it after
  its own review, and the useful question is what is still outstanding.
- `ReportsOnboardingRequirements` — an OPTIONAL contract for the live requirements read. Segregated on
  purpose: `MerchantOnboarding` is public surface you may implement, and a new method on it would break
  your implementation on upgrade with nothing to do but write the method. A driver that does not opt in
  still gets the whole flag table.

  `billing:merchant:status` exits **zero** while accounts are still blocked. A merchant part-way through the
  provider's form is the normal state of an onboarding funnel, not a fault in the installation — a non-zero
  exit there would make the command unusable in the deploy check where you would first reach for it, and
  would train people to ignore it.

  Both commands work **before** `billing.marketplace.enabled` is flipped, and that is deliberate: merchants
  are connected before the switch, not after, so a command that required it could never be run at the moment
  it is for. They need billing enabled and a driver that can route, which is what `marketplaceRails()` has
  always required.

  A merchant is stored polymorphically, so `onboard` takes a type and a key rather than a bare id — the morph
  alias where you have a morph map, the class name where you do not.

- **The consumer-withdrawal window is computed and frozen onto the grant** —
  `billing_access_grants.withdrawal_window_ends_at`. It measures from **provision**, not from the purchase
  and not from the payment: for a pre-ordered work those are three different days, and only one of them is
  the day the buyer could first do anything with what they bought.
- `StatesWithdrawalWindow` — an OPTIONAL contract beside `ConsumerWithdrawalPolicy`. Which sales have a
  window at all, and how long it runs, are a jurisdiction's reading rather than a setting. Segregated
  because `ConsumerWithdrawalPolicy` is public surface you may implement, and a third method on it would
  break your profile on upgrade.

- A guard that a CI detector may not accept a marker its extractor cannot read. The difference between
  the two sets is exactly the set of cases that vanish in silence, and silence reads as health. It carries
  its own red probe against the literal pair the failing run produced, so a pattern that stopped matching
  cannot pass for a clean tree.

  Nothing here is shipped code — the lane, its aggregator and the guard are all private to this repository.
  It is recorded because the honest mutation score this package owes itself has been blocked on it, and a
  run that reports success over no measurement is the failure mode every other check in this tree is built
  to refuse.

- `WithdrawalWindowClosed`, raised **before anything moves**. A refusal after the refund would be the worst
  of both — money gone and the record saying it never happened.

  **It refuses the classification, never the money.** A platform may refund out of goodwill whenever it
  likes, and that path is untouched; the exception names it so an operator ends up in the right lane rather
  than at "the package will not let me refund".

  Two things pass on purpose. A sale with **no grant** — a subscription is not a content purchase, and
  turning *nothing to compare against* into *too late* would refuse the ordinary case rather than the exotic
  one. And a grant whose window is **null**: null means no honest date exists, and one of the four ways to
  get there is a right that extinguished on delivery. Refusing on that would say "too late" about a sale
  where the right ended immediately — a true sentence with the wrong reason attached. Whether such a sale is
  a statutory withdrawal at all is a question about the *type*, answered where the type is.

  The comparison is **inclusive** of the last day: fourteen days means the fourteenth counts, and an
  exclusive one would refuse a buyer who was in time on the single day it matters most. It reads the date
  **frozen on the row**, so an operator who shortens the profile's window tomorrow does not shorten a right
  somebody already holds.

- `StripeConnectRails` assembles the two verbs, and `StripeDriver` declares `RoutesMoney`. The account
  directory is constructed with this driver's own name rather than resolved from a shared binding: it is
  keyed on the provider, and a shared one would answer for whichever driver registered last — a merchant
  lookup quietly returning the wrong account.

  **The refund reversal is deliberately not on these rails.** The obvious reading would put
  `refundWithReversal()` and `reverseTransfer()` here, and this package already answers both through
  `MovesMerchantShare` and `ReversesMerchantShare` — which `StripeMerchantTransfers` implements and which
  `RoutedPayment` and `BillingAdmin` actually call. A second path to the same money would be two places
  deciding capping, idempotency and rounding, and they drift; for a clawback that means a merchant is either
  short-changed or paid twice, in silence.

  One existing case had to be re-based rather than merely fixed: the proof that the support guard fires at
  boot leaned on the shipped driver *not* routing. That premise is now gone, so the case brings its own
  non-routing driver — which is what it was ever really about — and a second arm asserts the boot goes through
  on the shipped one, so the first cannot be satisfied by a guard that refuses everything.

### Changed

- **The invoice record's five creation rules are callable classes instead of a third of the model.**
  `InvoiceRecord::booted()` held five model-event closures, seven refusals and 186 of the class's lines —
  the most complex method in the package. Nothing in it was wrong. All of it was reachable only by saving a
  real row against a real database.

  That is the cost worth naming: a rule like "a deemed-supplier document must name the platform" was
  exercised by building an invoice row and catching an exception message by substring, so an edge case of it
  was expensive enough not to get written — and the unwritten edge cases are the ones that return as
  defects. The pattern was already in the package and the fourth closure was already using it:
  `DocumentRoleGuard` is pure, unit-callable and separately tested. Its four siblings now are too —
  `RegimePostureGuard`, `ChargeClaimKeyDeriver`, `SellerMatchesPostureGuard` and
  `ImmutableIssuedInvoiceGuard`, the first three taking values rather than the record.

  `booted()` is 37 lines of code and registers the same five events at the same moments, so nothing routes
  around them. No behavior changes: every test that exercised these rules through a model save stayed green
  without one assertion being touched.

- **The three purchase lanes read what this installation is from one place instead of four.** Whether
  marketplace routing is on and who the merchant is, which charge type the installation uses, whether the
  provider computes the tax, and whether the supplies are electronic — each of those stood byte for byte in
  `StripeCheckout`, `StripeOneTimeCharge` and `RoutedPayment`, deriving the same answer from the same keys
  independently.

  It held until one of them stopped: the one-off lane hard-wired the supply nature where the other two read
  it, which is the defect fixed separately above. This removes the condition that produced it — a single
  `MarketplaceSaleContext` that cannot disagree with itself. `StripeCheckout` goes from seventeen
  constructor dependencies (the widest in the package) to fifteen, `StripeOneTimeCharge` from thirteen to
  eleven, and the reader of the supply nature drops from four independent copies to two: this collaborator,
  and the preflight checkpoint that reports the setting to an operator before they go live.

  No behavior changes. Consumers who construct these classes by hand rather than through the container pass
  a `MarketplaceSaleContext` where they previously passed a `SellerOfRecordResolver`.

- **A rate now belongs to the tax point, not to the moment it was looked up.** `DatedTaxRateTable` and
  `TaxRateInterval` had been built to hold rates as dated intervals and nothing ever called them: the class
  had no caller in the shipped code, its constructor was reached only from its own test, and
  `UnknownTaxRateAt` — an exception the troubleshooting guide describes to the reader — could not be thrown
  from the package at all. None of the three rate lookups carried a date. `SaleTaxDecision` even decided a
  tax point and then handed it to the facts AFTER asking for the rate.

  Today's sale was never affected, and neither was any correction: a correction copies the rate from the
  document it corrects, so a 2027 credit note for a 2025 supply already carried the 2025 rate. What took
  today's rate was a NEW document for a PAST tax point — a late invoice, a re-billing, a migration of
  historic sales. Where a rate had moved in between, such a document is wrong by exactly the size of the
  move, and both numbers look equally plausible on the page.

  `TaxContext` gained an appended, optional `taxPoint`; `TaxCalculator::calculate()` is unchanged, so an
  implementation written outside this package still satisfies the contract. `billing.tax_matrix` gained an
  optional `history` of dated intervals. Absent — which is every installation until somebody opts in — a tax
  point is carried and ignored and every sale prices exactly as before. Present, a country the history
  carries is answered from it, a country it omits keeps being priced by the table beside it, and a tax point
  falling in a gap is REFUSED rather than answered with the nearest rate: an invented rate with a date on it
  cannot be told apart from a real one.

  The audio-visual gate — any audio or video part of a supply closes the reduced band for the whole of it —
  moved onto `TaxRateCategory` itself, so the dated and undated tables apply one rule instead of two copies
  that could drift into pricing the same supply differently.

- **BREAKING — the sale's tax characteristics reach an issuer as one `SupplyTaxCharacteristics` object.**
  `FanReceiptIssuer::issue()` took seventeen parameters, eight of them tax characteristics, and the clump
  `?TaxArchetype $archetype, ?PlaceOfSupplyRule $placeOfSupply, ?TaxRateCategory $rateCategory` stood word
  for word in five signatures across three classes. The signature is now ten parameters wide and the clump
  has one home.

  Nothing was wrong with any line of it, which is why it is worth naming. The hazard was a parameter
  inserted in the MIDDLE of that signature: two callers filled it positionally with eleven arguments each,
  so every argument after the insertion point would have shifted one place — past two pairs that are
  type-compatible, `?CarbonImmutable $deliveredOn` against `CarbonImmutable $soldOn` and `?string
  $chargeReference` against `?string $provider`. Static analysis cannot tell those apart, and a document
  that comes out with the wrong date is a tax error rather than a display one.

  Every field of the object is nullable and defaults to null, so an empty set is exactly as valid as a full
  one and writes the columns an omitted argument always wrote. It also WIDENS the subscription lane: a
  cycle could state three of the eight characteristics and can now state all eight, so one that knows its
  delivery date or its exemption reason can finally say so.

  Adopting it: pass `characteristics: new SupplyTaxCharacteristics(archetype: …, placeOfSupply: …)` in
  place of the individual arguments to `FanReceiptIssuer::issue()`, `SelfBillingEngine::issue()`,
  `SubscriptionCycleBilling::issueFor()`, `::issueSchedule()` and `::issuePrepaid()`. A caller that passed
  none of them needs no change.

- **`billing:prune` reads the issue-date map from the retention matrix instead of a copy of its own.** The
  map "which column dates a document for the retention clock" existed twice, word for word — once on the
  matrix and once as a private constant in the command that already receives the matrix by injection.

  No value was ever wrong: both copies held the same single entry. What was wrong is that they could
  disagree. `RetentionMatrix::issueColumnFor()` is public and exists, by its own documented reason, for a
  consumer writing their own pruning query — so a second dated table added to one map would have answered
  that consumer one way while the package pruned the other, and the resulting window moves by up to a year
  with nothing red anywhere. A scan now fails the build if a second copy of the map appears in `src/`.
- **Buyer protection is wired at both ends, behind a switch that ships off.** The delayed payout the
  documentation promised — *"funds stay with the payment provider throughout; what is delayed is the
  transfer to the seller"* — did not exist as behavior. On the `separate_transfer` lane the merchant's share
  moved unconditionally the moment the payment succeeded, nothing in the payment path knew about buyer
  protection, and `BuyerProtectionClock::hold()` had no production caller at all.

  The other end was unwired too. Releasing, refunding and escalating set columns and nothing else: no domain
  event, and no instruction to the provider to move the money. An auto-release at 05:00 was invisible to a
  consuming application — its only channel the console output of a cron run — and
  `BuyerProtectionState::ReleasePending` was declared and never set by anything.

  Both ends land together on purpose. Building only the first half would be worse than building neither:
  holds would accumulate that nothing ever releases, with the money sitting at the provider while a cron job
  dutifully wrote columns nobody reads.

  `billing.marketplace.buyer_protection.enabled` is **off** by default, so no installation changes behavior
  on upgrade — turning it on decides when a seller is paid, which is not a call a package makes for an
  operator. It applies to the `separate_transfer` lane only; on a destination charge the provider settles as
  the payment completes and there is no moment in between to hold.

  A release sets `ReleasePending`, instructs the transfer keyed on the charge row — the same idempotency
  rule the immediate lane documents, so a sweep that runs twice pays once — and only then records
  `Released`. Where no driver can move shares the hold stays pending, which says what is true: decided, not
  paid. Three domain events announce the outcomes, including the escalation the package deliberately refuses
  to decide: releasing on silence would pay a seller over an open complaint, refunding on silence would take
  money from a seller who may have done nothing wrong.

- **`billing:merchant:refresh` — the capability flags can finally be ASKED for, not only told.** The three
  flags on a merchant account had one writer and one production caller: the webhook effect on
  `MerchantAccountUpdated`. Nothing could ask the provider what the state is. A lost delivery — endpoint
  down, wrong secret, retry window closed — left the merchant at "cannot receive" while their account was
  fully enabled at the provider, with no money routed to them and no way to find out why.

  The asymmetry was the finding. `billing:sync` has repaired subscriptions after a webhook outage since it
  was built, saying so in its own docblock; the receiving side had nothing for the same ordinary failure.

  The report goes through the same single writer the webhook uses, so "only a provider report lifts a flag"
  stays one rule in one place, and a **deauthorized** merchant is not made receivable again — the provider
  does not know they disconnected from this platform and will report every flag true. The driver seam is a
  new **optional** contract, so an implementation that cannot answer says so by name instead of dying.

  `billing:merchant:status` gains a **Heard** column. Three `false` with `never` means nobody ever told us
  anything; three `false` with a timestamp means we asked and were told no. That distinction is the reason
  `capabilities_refreshed_at` was added — its migration says so — and until now nothing read it.
- **A reversal the provider performs on its own now reaches the journal.** `transfer_reversed_minor` had one
  writer, reachable only through the admin refund and the chargeback job — both platform-initiated. A
  reversal Stripe performs itself, from the dashboard or automatically, had no entry point at all, and the
  balance reader subtracts exactly that column. The creator went on being shown money the provider had
  already taken back, and a payout on that figure came out of a pot that no longer existed.

  The failure direction was the silent one: no error path, no rejected webhook, no red log. The event
  arrived at the provider and this package simply had no door for it, while the journal looked internally
  consistent throughout.

  `transfer.reversed` is mapped on the **merchant** endpoint only — a single-seller installation receives no
  connected transfers, and teaching the shipped mapper this event would make every existing install start
  running an effect it has never run on the next deploy. The provider's figure is cumulative, so it is
  recorded as an absolute value: a redelivery states the same total and changes nothing, a second reversal
  states a higher one and is followed. A transfer this package did not create is left alone rather than
  attributed to the nearest row.

  It **records** and does not adjudicate. A provider figure lower than one the platform has already booked
  is a disagreement, and which ledger wins is a separate open question — the larger figure stands rather
  than money quietly reappearing on a creator's balance on the strength of a webhook.

  `transfer.failed` is deliberately absent: it does not exist in the pinned Stripe SDK. The four loose
  matches for it are Treasury events — a different object with different attribution — and the failure side
  of a payout is a separate question about whether money reached the merchant's bank.
- **Voucher movements are recorded, so the bookings that were built can finally happen.** `DatevExport`
  knew all three voucher bookings and both chart configurations named the liability account, and none of
  them could arise from a real monthly run: `VoucherEvent::Issued` had no producer anywhere, `Redeemed` and
  `Expired` existed only as value objects the ledger returned and nothing stored, and the export command
  never passed the sixth parameter.

  A voucher is a **liability** when issued and only becomes turnover when redeemed; what lapses unredeemed
  is income rather than turnover. An operator selling vouchers exported none of that — the liability never
  appeared, and the turnover at redemption arrived with no counter-entry.

  It hid behind a correct-looking file: the export ran green and was well-formed, and an empty voucher block
  is indistinguishable from one that was never loaded. So the command's count line now names the movements
  too, which is what makes this class of defect visible the next time.

  The persistence sits **beside** the return values rather than replacing them, so every existing caller
  keeps working; what changes is that a movement now outlives the method that produced it. An expiry with
  nothing left still records nothing — a zero there would put an income booking on a month where no income
  arose.

- **`billing:marketplace:preflight` now asks whether a receiving gate was ever composed.** `CanReceiveMoney`
  binds to `AlwaysReceivable`, whose check is a literal `return true` — the right default for a single-seller
  install and fail-*open* the moment a marketplace is switched on. Nothing asked, so the checklist came back
  green over a marketplace routing money to accounts whose capabilities nobody had looked at, and which may
  be deauthorized or suspended.

  Running the checklist is the act by which an operator satisfies themselves they have forgotten nothing, so
  it is where the question belongs. Blocking, and waivable: the package cannot judge a gate it did not write,
  and a point that refused to be waived would block every legitimate custom implementation. A single-seller
  install is unaffected.
- **`billing:trials:warn` — the generic trial no longer ends in silence.** `TrialEndingNotification` had one
  trigger in the whole package: the provider's `customer.subscription.trial_will_end`. A generic trial has no
  provider — it is a date on the owner's own row, written by `Trials::grant()` with a `save()` and nothing
  else — so nobody could send that event, and nothing looked for the date. Worse than unwired: `TrialEnding`
  takes a non-nullable subscription reference, which excludes the generic trial structurally.

  That is the trial mode **without a card**, which is exactly the one where the customer has no other signal.
  They lost access on the day having been told nothing, and for the operator it was churn that appeared in no
  error list. The published documentation promised the reminder for all three trial modes.

  The command runs daily, takes `--days` and `--dry-run`, and marks each reminder against the owner **and the
  end date** — so an extended trial is announced again rather than silenced for good, and a nightly schedule
  does not mail the same customer repeatedly. Owners who have a subscription are skipped: both paths firing
  is how a reminder stops being read.
- **`billing:tax-rates:check` — the proposing half of the rate seam now has a door.** `billing:rates:probe`
  reports *that* the shipped VAT table differs from what the source publishes. Everything that turns that
  into something actionable — plausibility, triage against the countries an installation has actually billed
  into, a reviewable proposal on disk — was built and tested, and had no entry point at all: `RateImporter`
  sat at the head of that cluster with zero callers, so all of it was unreachable from shipped code while
  every one of its own tests stayed green.

  The command shares the probe's switch (`BILLING_RATE_PROBE`) and its three exit codes, with `1` meaning a
  proposal was written. **It proposes and never applies:** the proposal lands beside the snapshot as
  `proposal-<date>.json` and the snapshot is not touched. A rate is a legal statement about what a member
  state charges, and a package that edited its own priced-from file on the strength of one HTTP response
  would be making that statement on the operator's behalf. `billing.tax_rate_probe.proposal_path` points it
  somewhere writable when the installed package is read-only.

  Both commands now read the source through one parser. Two hand-written readers over one response drift
  without throwing — the drifted one returns fewer rows, and the caller reports "no change" over a response
  it failed to read.

- **`billing.invoices.pdf_disk`** — name the disk your `billing_invoices.pdf_path` values address and
  the invoice download serves the PDF **you kept** instead of rendering a new one.

  A re-render is not the document that was issued. Everything under a renderer moves over the years an
  invoice must stay readable — a corrected rate table, an updated address, an improved writer — so a
  fresh render years later resembles the one your customer holds without being it, and the
  disagreement surfaces in a dispute, where the other party is the one holding the original. The
  package already applies that reasoning to the XML forms in `billing_document_artifacts`; this is the
  human-readable half, which only you can keep.

  **Defaults to `null`, and then nothing changes at all:** no disk is touched and the route renders
  exactly as before.

  **A recorded path whose file is gone still renders**, so an owner is never locked out of their own
  invoice over an archive they do not control — but it is logged as an **error** naming the invoice,
  the path and the disk, because a lost archive file is an incident and the quiet version of it is the
  expensive one. A path recorded with no disk configured is logged as a warning instead: that is a
  half-finished setup, not a lost file, and telling an operator the wrong one sends them looking in
  the wrong place.

- **`TaxCalculatorFactory::answeringRateMatrix()`** — the rate table that actually answers for an
  installation, or `null` when the shipped calculator does. It exists so something outside can ASK
  which table is in use rather than work it out a second time.

  `billing:doctor` reports how old the rates are, and it used to re-derive that precedence by hand:
  configured table, then profile, then the shipped constant, written again in a second file. The two
  agreed — but only for as long as nobody added a further source to the factory, and the failure that
  produces is the one the command exists to prevent: an operator told the age of a table that is not
  the one being calculated with. On an invoice a wrong rate looks exactly like a right one.

  `TaxRateMatrix::validFromDate()` comes with it, so a diagnostic can print that day beside the age.
  The command now asks the factory and derives only the LABEL itself — a matrix does not carry where
  it came from, and giving it one would put a diagnostic's vocabulary into the calculation path.


- **`Subscription` spells "this owner's default subscription" once.** The four-condition selection was
  written out by hand at thirteen call sites in twelve files, with no scope, no constant for the `default`
  literal and no test holding the copies together. Nobody was harmed by that — all thirteen were correct.
  What it cost had already been paid once: a single rule arrived as six identical one-liners in six files,
  and the reason it was six of thirteen rather than all of them lived only in a commit message.

  `forOwner()` and `ofDefaultType()` are separate scopes rather than one combined helper, and both return a
  builder. The call sites differ in ways that matter — one deliberately spans every merchant, one locks for
  update, one asks for all rows rather than the newest — and a helper that returned a subscription would
  have flattened those into a marketplace defect. A guard now refuses a fourteenth copy, in any spelling.

- **`RateChangeExclusions::dryRunRequired()` is gone; the requirement it stated now sits in the class
  docblock.** It returned `true` unconditionally and nothing called it, while its own docblock said
  "mandatory" — so the file read as an enforced protection and enforced nothing. Shipped prose that
  promises a reader a protection they do not have is worse than silence.

  The dry run is still required. It is required **of the caller**: this package ships no bulk rate-change
  command and deliberately does not, because changing rates across a corpus is an operator act. The
  requirement now lives in the one docblock a caller already reads to learn what the automation must skip.
  `mayTouch()` stays a method because it answers a question rather than declaring a rule.

- **The reporting record's treatment of sellers the duty does not cover is now stated rather than
  implied.** `render()` receives every seller the period examined, and the shipped renderer writes the
  reportability verdict as a column instead of dropping the row — so a produced record carries
  non-reportable sellers together with their quarterly figures. That was the behavior before this
  release too. What was missing was anyone saying so: no test excluded them and no test recorded that
  carrying them is meant, which left the one direction where being wrong is expensive as the one
  direction the tree did not describe.

  `NonReportableSellerInTheRecordTest` now pins it, including the part that makes it matter — the row
  carries the seller's income, not merely a flag — and that `seller_count` counts the **examined**
  rather than the reported, so it answers a different question from the one its name suggests.

  Whether the archived artifact *should* carry them is a legal question with defensible answers on both
  sides and is deliberately left open; these cases take neither side and will go red on whichever
  answer changes the behavior.

  The renderer seam is documented for the first time, with the trap named: a custom renderer that
  iterates the reports and writes them all transmits sellers who must not be reported. Over-reporting
  is a *not correct* return in its own right and a data-protection breach at the same time — and unlike
  a missing seller, nobody downstream notices it.

- Documented, from a measurement rather than an assumption, **who pays the payment provider's processing fee
  and who absorbs a chargeback**: it follows the connected account's type, not the charge type and not
  `on_behalf_of`. On the shipped default (`express`) both are the **platform** — so `marketplace.fee` is a
  gross take rather than a margin, and the package does not subtract a processing fee it cannot know your
  pricing for. The answer now sits where the choice is made — the account-type setting, `ChargeType`,
  `ChargeRouting` and `PlatformFee` — and a live smoke test re-reads it from the API so a version bump
  cannot flip it quietly.

- `invoice.payment_succeeded` now maps to a third event, `RoutedSubscriptionInvoicePaid`, beside the payment
  and the invoice snapshot. Its handler reaches the provider, so hanging it on `PaymentSucceeded` would mean
  provider calls for every payment on every install — including the overwhelming majority that route nothing.
  Carrying the subscription reference lets the handler ask the local subscription row first and stop there.

  **The commission is READ from the provider, never computed from the rate.** A routed subscription is priced
  with `application_fee_percent`, so the absolute figure exists once per cycle and only at the provider. The
  package could derive it, and that is the wrong trade: two derivations of one fact agree until one of them
  changes — a rate edited between cycles, a proration line, a rounding rule — and when they part, the ledger
  holds a plausible wrong number that flows into a clawback cap and a tax judgement with nothing going red.

  That is also why a failed read **throws** rather than returning quietly. The effect runs queued, so a
  transient failure retries and a permanent one surfaces as a failed job; returning would leave no row, no
  error and no trace — the same silence as before, bought at the price of three provider calls.

  **The route was measured, not assumed** (2026-08-07, pinned API `2025-08-27.basil`, real paid invoice): the
  delivered payload carries no link to the payment at all — no `charge`, no `latest_charge`, no
  `payment_intent`, not even the `payments` collection the link lives in. So it is invoice (expanded) →
  payment intent (the money and the recipient) → subscription (the terms a partial clawback needs). The SDK
  cannot answer this: its `@param` blocks describe what may be SENT when creating an invoice, not what comes
  back, and an earlier draft of this lane was written against a payload Stripe never sends on exactly that
  misreading. `StripePaidInvoiceLinkageSmokeTest` keeps both halves pinned.

- The changelog assembler now writes a release's comparison link along with its block, and pulls the
  `[Unreleased]` link forward to span from the new version. The predecessor is resolved from the tags
  that exist rather than from the previous heading in the file, so a version that was never tagged does
  not become the base of a link that cannot resolve.

- The changelog assembler now folds the `## [Unreleased]` section into the release alongside the
  `changelog.d/` fragments, under one heading per kind, and leaves the heading standing with an empty
  section. Until now only fragments were folded, so an entry written directly into the file — which the
  changelog guard accepts — stayed under "not released yet" while the code shipped, and the published
  release note was quietly short. A release whose only entries were written that way can now be assembled
  at all; previously the tool refused because no fragments existed.

- **A product nobody classified now refuses to reach checkout while a consumer-rights profile is active.**
  The withdrawal gate needed two conditions to bite — a profile *and* a classified archetype — so an
  operator who turned the profile on and left one product without an `archetype` key got it sold and
  delivered with no consent on file, and nothing said so. `billing:doctor` reported the combination;
  nothing refused it. "Nobody classified this" and "this needs no declarations" are indistinguishable to
  the runtime, and one of them is a statutory failure.
- `OneTimeCharge::purchase()` takes an optional declaration reference, and `ManageSubscription::purchaseAddon()`
  passes one through. **Anyone implementing `OneTimeCharge` for their own driver must add the parameter.** A
  driver handed `null` must send exactly the payload it sent before — Mode S is a byte-identity promise, and
  the shipped Stripe driver keeps the same single-entry metadata bag when there is no declaration to carry.
- `AddonPurchased` carries the declaration reference home; the grant effect prefers it over the session
  reference when looking the consent up, and `billing_addon_purchases` keeps it on the row. That column is
  what lets `WithdrawalConsentLedger::forPayment()` still work: a receipt knows the PAYMENT id, and the
  declaration is keyed on a string that did not exist when the buyer made it. Without it the lookup answers
  null — and null there reads as "the buyer declared nothing", which is wrong and indistinguishable from
  the truth. A declaration recorded out of band against the session reference itself still resolves.
- The archetype-to-withdrawal-right lookup moved out of the grant effect into `WithdrawalTypeResolver`, so
  the checkout and the provision gate answer from one reader. Copying it would have been the cheaper edit
  and the wrong one: a checkout that decided no declarations were needed and a grant that then refused to
  provide would take money for something the buyer can never receive.

  A refusal at checkout surfaces as `WithdrawalDeclarationsMissing`, not as a 403. That is deliberate — it is
  a wiring mistake with a precise remedy, and the exception names it. The package will never render the notice
  wording itself: that text is the operator's and their adviser's, for their product and their jurisdiction.

- **A manual CI run can now name the lane it is about.** There is exactly one manual trigger for a
  repository and it fires every workflow that accepts `manual` — measured 2026-08-07, a run wanted for the
  live-provider lane started **eight** workflows including the full gate, while three pull-request gates
  queued behind it. Every lane now names itself against one `LANE` variable with an empty default: a bare
  manual run behaves exactly as it did, `--var LANE=<name>` starts that one.

  **Both halves were verified on real runs before anything was built**, because the failure direction here is
  a lane that silently never starts. A run with `LANE` unset reached the probe lane; a second run with
  `LANE=something-else` did not — an unset variable compares as empty, measured rather than assumed.

  The mutation lane keeps its own `MUTATION == "1"` condition and is exempt from the shared default: it is
  **rationed**, not merely nameable, and a default that ran it would be the opposite of that.

- **Switched on for a sale whose regime has no buyer fee now refuses**, with `BuyerFeeNotApplicable`. A
  buyer fee is the platform's own supply to the buyer and exists only where the platform mediates between
  two other parties; under a commission chain the platform is itself the seller, so a fee booked there
  describes a service nobody rendered.

  **Whether buyer fees are charged is the adopting developer's call, not this package's** — which is why the
  switch is configuration and defaults to off, and why an installation that never enabled them has its
  regime neither resolved nor consulted. Off is off, and it costs nothing.

  Of the three possible behaviors for "switched on, wrong regime", quietly charging nothing is the worst:
  the setting reads as on, the operator believes they are collecting, and the first evidence otherwise is
  revenue that never arrived. So it says which of the two conditions failed, while it is still free to fix.

- **The configuration no longer says a withdrawal window cannot be computed.** It said the package "computes
  no withdrawal window at all, and it cannot — nothing records the moment a work was provided". That was
  true when it was written and stopped being true when the grant register landed: `acquired_at` is written
  immediately after the fail-closed withdrawal gate, which *is* that moment.

  **A reason does not age visibly, and that is the part worth recording.** The paragraph read as a decision
  rather than as a gap for as long as it stood, so the window was ticked off as settled instead of open — and
  it stayed open while the work around it shipped. The replacement says why there is still no length in
  configuration (it is profile data, not a setting) rather than why there is no window.

  `null` on the new column covers four situations and none of them means "no right": no profile is active,
  the right already extinguished on delivery, no right ever attached, or the profile does not state windows.
  What they share is that no honest date exists — and a date is precisely what a reader downstream would
  rely on. `withdrawal_type` and the declaration reference on the same row tell them apart.

  An **unclassified** sale gets no window either. A null archetype means unclassified, never "no right
  applies", so fourteen days computed there would be a statutory-looking date produced by a guess.

### Removed

- **`InvoiceNumberSequence::format()`.** It produced `2026-000042` and had exactly one caller in the
  repository — the test that asserted it. Every real invoice number comes from
  `DocumentNumberAllocator` as `PREFIX-YYYY-#######`, with the prefix resolved per series from config
  and a missing one refused outright.

  A dead method looks alive when a test is its only caller, and this one sat on the obvious class
  under the obvious name while returning a shape no document in the system carries. Reaching for it
  would not have raised anything — it would have minted a plausible-looking number outside the
  configured series, which under § 14 UStG / GoBD is the expensive direction to be wrong in. The
  counter now answers only in integers, and a case asserts that shape rather than a list of forbidden
  names, so a second number format cannot grow back here under a different name.

- **`SellerActivityThreshold::isExemptFromReporting()`.** Whether a seller's data falls outside the
  reporting duty is now answered in one place only — the jurisdiction's reporting profile, where the
  boundary is set by law rather than by preference.

  Nothing in the package called this method, and the duplication was the visible half of the problem.
  The dangerous half was the coupling: it read the **same two config keys** as the declaration
  trigger beside it, so a platform that asked its sellers to declare earlier would have moved the
  statutory exemption with the same switch — silently, in the over-reporting direction, from a class
  whose own docblock warned against exactly that. Reporting data that need not be reported is an
  incorrect report in its own right and a data-protection breach besides, so that direction is not
  the cautious one.

  The two questions still meet at the money figure and still answer differently there: the
  declaration fires at it, the statutory exemption holds at it. Keeping them in separate classes
  reading separate keys is what makes that a design rather than a coincidence.
- **`billing_invoices.pdf_path`** — somewhere to record where you kept the issued PDF, and
  `InvoiceRecord::locallyGenerated()` to say whether a document was produced here or mirrored from a
  provider.

  The package stores no PDF and does not start now: storage is your decision, with a disk, a
  retention policy and a bill behind it. What was missing was the anchor. A re-render is not the
  document that was issued — everything under a renderer moves over the years an invoice must stay
  readable, and each change quietly yields a copy the recipient's version disagrees with. That is the
  reasoning `billing_document_artifacts` already applies to the XML forms; this is the same anchor for
  the human-readable one, which only you can keep. Nullable, no default, no backfill: absent means
  nobody kept one, which is the honest state for every existing row.

  **There is deliberately no `locally_generated` column.** `provider_id` is written by exactly two
  writers, both webhook effects mirroring a provider's invoice; the four issuers that produce a
  document here never set it. So the origin is not a second fact about a row — it *is*
  `provider_id === null`, and a stored boolean would be a copy that can disagree with what it copies.
  The equivalence is pinned from both directions, so the day a writer hydrates a local document with
  a provider's id it arrives as a red test naming the decision rather than as silent drift.

### Fixed

- **`billing:cards:warn` honors `--days` when it is called programmatically.** The window was accepted only
  when the option was a string — always true from the command line, never true of
  `Artisan::call('billing:cards:warn', ['--days' => 60])`. Such a caller silently got the configured default
  instead of the window they asked for: a plausible result for a different window, with no error, no log and
  nothing to search for.

  The sibling trial command already read it as `is_numeric` and its own comment named this command as the one
  that did not. Both now read it the same way, a test holds them together, and that comment says so instead.

- **A support refund on a routed sale now corrects the documents behind it.** `BillingAdmin::refund()` is
  the package's only refund verb, and it did not call the chain corrector. Chargeback, statutory withdrawal
  and prepaid-term cancellation all three did. So an ordinary support refund moved the money, balanced the
  ledger, wrote its audit line — and left the creator's self-billed invoice and the buyer's receipt claiming
  the full amount.

  The creator carries that: their settlement states revenue they no longer have, and settlement numbers come
  from a gapless series, so the state cannot be tidied afterwards — only answered by a further, correctly
  numbered correcting document. It hid because the visible half worked, and nothing compared the two sides.

  `ConsumerWithdrawal` no longer corrects the chain itself. It calls the admin refund, which now does — and
  correcting in both places would write a second document per leg out of that same gapless series, for one
  event. **BREAKING for a hand-constructed `ConsumerWithdrawal`:** it no longer takes a `RoutedRefundCorrector`
  or a `CreatorTaxStatusResolver`. Resolved from the container, nothing changes.

  The correction reads the creator standing frozen at the supply, not the one they hold today, so a creator
  who has since registered for VAT does not retroactively change how an earlier sale is corrected.

- **The account hub's landing page honors `web_only` — it used to keep the account-deletion card on a
  native runtime.** `config('billing.navigation')` had two readers following different rules. The sidebar's
  knew `web_only` and dropped such an item when `billing.runtime` is `native`; the landing page's did not
  know the flag at all — its item object had no property to carry it.

  So an operator who marked the danger zone `web_only`, checked the sidebar and watched it disappear had
  every reason to consider the matter settled. The landing page went on rendering it as a clickable card,
  and the danger zone has no native gate of its own, so the card led to a working account deletion — on the
  most destructive screen the hub has.

  It hid because both halves were individually right: the sidebar filtered correctly, its test was green,
  and the documentation described exactly the behavior the operator expected. The one test that existed
  measured the other class.

  **BREAKING, and small:** `Pushery\Billing\Support\Navigation` and `Pushery\Billing\ValueObjects\NavItem`
  are removed rather than taught the flag, because a second parser of one configuration key is the condition
  that produced this. Anything reading the hub navigation uses `Pushery\Billing\Account\Navigation` —
  `visible()` for groups, the new `visibleItems()` for a flat list. Both apply all three gates: route
  registered, route resolvable without arguments, and `web_only` against the runtime.


- **Seven append-only records could be deleted by anything, and nothing said whether that was intended.**
  Ten models spelled out the same "written once, never changed" rule by hand. Three had a deletion arm and
  seven had none — and a missing hook throws nothing, so "nobody decided" and "somebody decided no" read
  identically. `ReportingExportRecord` had no arm while its sibling archive `TaxReturnExportRecord` did, with
  the retention matrix holding both tables under one rule.

  Every one of the ten now answers the question in a line of its own: `PurgingOnly` for a row retention may
  remove on its schedule, `Never` for a row that is unlinked from an erased person rather than deleted. The
  answer was measured rather than assumed — none of these tables sits on a purge axis, and the time pruner
  deletes through the query builder, so no existing path changes behavior.

  The mechanism moved into an `AppendOnly` trait: the three deletion arms that existed disagreed on shape
  (`bool` against `void`, opposite condition directions, `self::` against `static::`), and `purging()` was
  typed out twice with byte-identical bodies. The refusal MESSAGES stay with each model, because they say why
  that particular record is frozen — a guard asserts all ten are distinct, so unifying the mechanism cannot
  quietly unify the statements.

- **The contract reference claimed a shipped distance-sale threshold that does not exist.** It listed "the
  shipped threshold" as the fallback for a profile that does not implement `SuppliesDistanceSaleThreshold`.
  There is none: with no profile the watched limit is zero, which means no limit is watched and every
  cross-border consumer sale is taxed at the destination. That is the safe direction and a deliberate one,
  but a reader told a shipped limit answers may reasonably not supply their own.

- **A redelivered arranged sale no longer burns a number out of a gapless series.**
  `issueIntermediated()` wrote directly instead of going through the repeat guard every other buyer document
  goes through, so a repeated delivery of the same payment drew a commission number **first** and hit the
  unique constraint **second** — leaving the number spent on a document that was never written.

  That is the one failure a retry cannot heal. The payment is redelivered — a provider retry, a webhook
  redelivery, a queued job running twice — the package throws, and the series is permanently missing a
  number with nothing behind it to explain the gap.

  The fix has two halves and the second is the one that is easy to miss: routing through the guard alone
  would have changed nothing, because the repeat lookup was hard-wired to the buyer-receipt series and a
  commission document does not carry it. It would have read as fixed in any test that only checked for the
  absence of an exception, which is why the assertion here is the sequence counter and not the exception.

  `reissueAsFullInvoice()` deliberately still draws a fresh number — restating a sale a buyer already has a
  document for is the whole point of it — and now says so, so the next reader counting direct writes does
  not file it as a third instance of the same defect.

- **A one-off purchase no longer decides for the installation whether its supplies are electronic.**
  `billing.marketplace.seller_of_record.supplies_are_electronic` settles whether the Art. 9a presumption of
  a platform-supplied service applies. The hosted checkout and the routed payment both read it; the one-off
  lane passed a hard-coded `true` — the file did not contain the word "electronic" at all.

  So an installation that had explicitly declared it sells physical goods, on the `seller_of_record`
  posture, could subscribe but could not buy an add-on: the sale was refused with the reason "but this is an
  electronically-supplied service", which its own configuration denies. No log, no configuration error, and
  the cause in a file that does not mention the setting.

  The lane now reads the setting, and the refusal for a genuinely electronic supply is unchanged. A test
  drives both lanes under the same configuration and requires the same verdict, which is the property that
  was broken — either lane on its own was internally consistent.
- **A settlement now names the relief it claims (BT-120).** `vat_note` had no writer anywhere in the
  package, so a credit note raised to a small-business creator rendered the tax category's generic English
  fallback — `Tax exempt`, a phrase that names no ground at all — where the creator's own relief had to be
  stated. Nothing went red: BT-120 was populated, so EN 16931 validation passed, and the suite asserted the
  wrong string as the expected one because it described what the code did.

  `tax_exemption_reason` gains a value for each of the two small-business standings, and every issuing seam
  writes it: the per-transaction settlement from the tax matrix, the collective settlement from the month's
  treatments, and a correction from the document it corrects. The two standings are kept apart on purpose —
  both are exempt, but a creator in another member state is not relieved by the recipient state's statute,
  and a platform-issued document saying so would state their tax position for them.

  The WORDING is derived from the frozen reason and the locale, and ships in all seven languages. That split
  is what keeps it honest: the legal fact is frozen on the row and cannot move, only its rendering is
  computed — which is what makes it translatable and correctable, as a frozen free-text string could never
  be.

  A collective settlement also now refuses a month whose lines are relieved under different law. The
  previous check compared two booleans, and both standings answer `exempt` the same way, so a creator who
  moved between them mid-month produced one document naming one relief for lines that had two.

- **The e-invoice carries the statutory reverse-charge sentence the PDF has always printed.** BT-120 emitted
  the two words `Reverse charge` while the PDF rendered the full statutory wording from `lang/`. Same
  invoice, same package, two statements of one legal fact — and the XML is the one a tax authority reads. A
  stored `vat_note` still wins over both, unchanged.
- **`billing:webhooks:replay` reads a merchant delivery with the merchant mapper.** It resolved one mapper —
  the platform one — and drove every stored delivery through it. The platform mapper has no `account.*` arm,
  so a merchant delivery fell through to nothing and was reported as `replayed evt_… (account.updated) → 0
  effect(s)` with exit `0`. Replay is the tool for the bad day, and somebody re-driving two hundred
  deliveries after an outage read a summary whose count silently excluded every merchant one — leaving a
  merchant whose capabilities were never updated, and a deauthorized merchant still carried as active.

  The second half was worse because it was not inaction: a connected-account `customer.subscription.*` mapped
  without a merchant scope resolves against the **platform** catalog, writing state for the wrong seller.

  A delivery that maps to nothing is now reported as such, and the summary counts those separately. Not a
  non-zero exit: one unhandled event type in a bulk replay would turn the whole run red, and an operator who
  learns to ignore a red replay has lost the signal a second time.
- **A `windowed` sale now gets a window, so it stops behaving exactly like `frozen`.** Nothing wrote
  `update_window_ends_at` — no parameter, no config key, and `UpdatePolicyCatalog` supplied a policy but
  never a length. Every windowed grant came out with a null window, which the resolver correctly reads as a
  broken row and bounds at the moment of purchase. That is what `frozen` does: two of the four documented
  values of a shipped setting were byte-identical through the only write path, so an operator selling
  "updates for twelve months" delivered frozen content and the buyer got the state of the day they bought.

  Nothing was red. The resolver is right, the rows look plausible, and the existing tests build their
  windowed rows by hand — which is exactly how they skipped the write path that never filled the column.

  The length now comes from the consumer, like the policy itself, through a new **optional** contract
  `SuppliesUpdateWindows`. Optional on purpose: `UpdatePolicyCatalog` is published, and adding two methods
  to it would break every consumer implementation on the next release for a capability that is purely
  additive. A catalog that does not implement it keeps working unchanged, and the fail-closed fallback
  stands — the difference is that the absence is now somebody's answer rather than a column nothing could
  fill.
- **The card surface is now driven by a real browser.** Every one of the browser suite's interactions sat on
  the subscription, plan and danger screens; the two card actions were exercised only through `->call()` in
  the Livewire harness, which skips the DOM binding by construction — it invokes the method directly, so no
  `wire:click` is ever resolved.

  That made the failure direction the silent one: break the binding and the whole Feature suite stays green
  while the user clicks and nothing happens. Demonstrated rather than argued — with `wire:click="addMethod"`
  corrupted, the new browser case fails and the Livewire suite passes 14/14.

  On the recovery screen it is worse than an annoyance: that is the card replacement on the dunning path,
  for an owner who is already past due and whose subscription ends when the cure window closes. A dead
  button there costs a subscription outright.

  The add and remove buttons were also not asserted to exist in any suite — `payment_methods.add` and
  `payment_methods.remove` had zero occurrences across the whole test tree.

- **A buyer-protection hold withholds the merchant's share, not the price the buyer paid.** The balance
  reader subtracted a hold's gross amount from the merchant's net — two different bases against each other,
  which takes the platform commission out a second time. Where a merchant's whole settled turnover sat under
  one open hold, their available balance went **negative**.

  The commission is now taken off when the hold is opened rather than only when it is released, which was
  the other half of the same gap: every released hold had been paying out the buyer's full price, commission
  included. A new case holds the quantity itself — an available balance cannot be negative, and one that is
  always means a mixed-up basis upstream.

- **The money path now prices from the digest-checked rate snapshot the documentation always said it did.**
  `resources/tax-rates/*.json` ships with a header and a hash over its own numbers, and the published
  troubleshooting guide states that pricing *stops* rather than falling back to whatever is in the file —
  the protection against the one edit that leaves no trace, a digit changed inside `vendor/`, which appears
  in no diff and would silently reprice every invoice to a country.

  Nothing loaded the file. The calculator priced from a `private const array` beside it, and the promise was
  not kept. Measured rather than argued: with the shipped snapshot's `DE` set to 1000 bps and its digest
  correctly re-pulled, `calculate()` charged 1900 on 100.00.

  The rates now come from the snapshot, loaded once for the application, and a snapshot whose digest
  disagrees stops pricing instead of answering. The date the table was last checked comes from the file's own
  `situation_on` rather than a constant beside the numbers — a date held apart from what it describes is the
  half that goes quietly wrong, and a table whose stated age is wrong answers the staleness question
  confidently and incorrectly.

  There is now one copy of these regulated figures instead of two. The lockstep test that held the constant
  and the file equal proved today's agreement and nothing about tomorrow's.

- **The two cure-window sweeps are on the schedule the command reference already promised.**
  `billing:dunning:remind` and `billing:dunning:expire` were registered as commands and listed in the
  reference under "the service provider registers these for you" — and put on no schedule. So the
  whole cure-window half of the dunning ladder ran in no application: a customer in arrears heard once,
  when the payment failed, and then not again until the subscription was gone.

  The reminder runs before the expiry, so a window that ends today produces the final notice rather
  than a countdown that stops at zero. Both select merchant-scoped rows only, so a single-seller
  installation pays two empty queries a day and nothing else changes.

  The guard against exactly this defect was green throughout, because it derived the set of commands
  it checked from PROSE — it matched the phrase "meant to run daily", and both of these worded their
  cadence differently, so neither was ever in the set. It now also reads the published table, which is
  machine-readable and is the promise a reader actually relies on.

- **`billing:doctor` no longer reports a clean bill while its own findings stand.** The exit code was
  recomposed at each of five exits and the "could not reach Stripe" exit was assembled from a subset of
  them, so an aged tax-rate table failed the command while Stripe answered and passed it while Stripe
  was down. That is the direction that hides: a green doctor during an outage reads as confirmation
  that the tax data is fine. A finding does not stop being true because a later check could not run.

- **`ComposedReceiveGate` and `ComposedEligibilityGate` accept their checks in the constructor.** The
  documented wiring passes them there. With no constructor declared, PHP accepted the argument in
  silence and discarded it — leaving a gate with zero checks, which is fail-closed and therefore denied
  every merchant on the platform, with nothing thrown and nothing logged. From the outside that is
  indistinguishable from a merchant who has not finished onboarding.

  The fluent `require()` form is unchanged. Passing an array, as the old example did, now raises a
  `TypeError` at the call site instead of failing silently later.

- **A full invoice reissued over a short receipt keeps every characteristic of the sale.** The restatement
  was assembled from a hand-written list of columns beside the one the receipt is issued from, and the two
  had drifted: eight columns were absent, three of them EN 16931 fields both e-invoice writers read — the
  delivery date, the service period and the exemption reason. The restatement is the document a buyer asks
  for *because* they need a formal one, so it was the single document in the system that lost the fields
  that make it formal.

  It now derives its columns from the frozen characteristics the package already names in one place, with an
  explicit list of what must differ and why. A column that changes on a restatement is a decision; losing one
  because nobody typed it is not.

- **`billing_merchant_charges.transfer_moved_minor`** — what the provider reported it actually moved to the
  merchant, recorded beside what the row says they were owed. `transferShare()` has always answered with
  that figure and the caller took the reference off the answer and dropped the rest, so the journal recorded
  its own request. A journal built that way cannot disagree with the provider, which sounds like consistency
  and is the opposite: the reconciliation this package promises had nothing to compare.

  It also decides a ceiling. `reversibleMinor()` limits how much can be clawed back from a merchant and was
  read off what was owed — so where a provider moved less, the package would try to reverse money that never
  reached them, and on a lost dispute the platform is out of pocket for the difference. The ceiling now
  follows the reported figure where there is one.

  Nullable: null is "nobody reported a figure", which is the honest state for a destination charge, where
  the share moves as part of the payment and no transfer call is ever made.

- **The chargeback reversal books what came back.** `ReverseMerchantShareForChargeback` called
  `reverseShare()` and discarded its answer, so the ledger advanced by the amount the job had asked for.
  A guard now holds both provider answers — the outbound transfer and the reversal — against being
  declared and read by nobody again, which is the state both were in.

- **The readable half of a document now states what its XML half always did.** A hybrid e-invoice is a PDF
  with the XML inside it, rendered by different code from the same row — and the readable half said neither
  that a document was self-billed nor which document a correction corrects, while both XML writers emitted
  both from the same columns. One file contradicting itself, with the conformance-checked half being the
  correct one, which is why no validator ever complained.

  The same PDF is also served on its own, without the XML wrapper. There the readable half is not one of two
  halves — it is the whole document.

  `InvoiceRecord::isSelfBilled()` is now the single derivation both halves read, and a test flips the column
  and requires both to move. The new `invoice.corrects` string ships in all seven locales, and a new guard
  holds every locale to the same key set — a shipped string missing in one language serves an English
  sentence inside an otherwise translated document, which a reader cannot tell from a deliberate term.

- **`billing:prune` now carries out the retention rules for the period-scoped export documents.** The
  matrix declared a ten-year window for the produced tax-return and seller-reporting files, and
  `--dry-run` printed both under a heading calling them "the record of what this run enforces". Nothing
  enforced them: those tables name a PERIOD rather than a person, so no erasure axis can reach them, and
  the command only ever ran the axes. Rows well past the window survived a real run.

  A retention rule now states which mechanism carries it out — the time pruner, an erasure axis, a
  dedicated path, or nothing because it may never have been stored — and a test fails on any rule that
  states none. Responsibility could not be inferred from the rule's shape: `billing_place_evidence` wears
  the same signature and belongs to an axis, so a pruner that guessed from the shape would delete it twice,
  on two different clocks.

- **`billing_refund_attempts.transfer_reversal_short_minor`** — how much less of the merchant's share came
  back than the reversal asked for. A destination charge reverses proportionally, and a fee with any fixed
  component owes more than the proportional share on a partial refund, so the two figures differ by real
  money on every partial. The difference used to be computed and dropped, which made a charge that came
  back short indistinguishable from one that came back whole and left a later top-up nothing to aim at.

  Nullable on purpose: null is "nobody compared", zero is "compared, nothing missing". Additive and
  reversible; every row written before it stays null rather than claiming a comparison nobody made.

- **A support refund books the reversal that came back, not the one it asked for.** On the shipped
  separate-transfer lane the merchant's share moves in its own call, so refunding the payment does not touch
  it — and the rails report that honestly, with no reversal reference. The ledger booked the intended
  reversal anyway. It therefore spent the room a later chargeback needs, and told a consumer's ledger, via
  `MerchantTransferReversed`, to unwind a payout still sitting with the merchant.

  A null reversal reference now books zero and the announcement carries `amount: 0` beside the platform fee
  that genuinely did come back. Where the provider reports a reversal AND its amount, that amount is what is
  booked — `RefundResult::$transferReversed` had no reader until now, so a partial reversal was recorded at
  the requested figure rather than the one that moved.

- **A collective settlement that spans two currencies is refused rather than summed.** The engine took the
  currency from the first transaction and added every further amount as a raw count of minor units, without
  ever comparing them — so two currencies in one month summed to a number in neither, and the document was
  stamped with whichever arrived first. `Money` refuses currency mixing everywhere else; the accumulation
  bypasses it for speed, so the refusal is now stated there. Settle each currency in its own document.

- **`tax_exemption_reason` can no longer change on an issued invoice.** Whether a supply was exempt was
  frozen; WHY it was exempt was not, and the two are not interchangeable — a reverse-charged supply is
  taxed, an export outside the union is not. Both e-invoice renderers read that column as the EN 16931
  exemption reason, so an editable one let a numbered document claim afterwards that it had been an export,
  with every amount on it still adding up.

  Every fillable column on the invoice is now on one of two lists — frozen, or deliberately mutable with a
  stated reason — and a new column that is on neither fails a test. The previous guard listed the frozen
  columns by hand and had the same omission as the code it was guarding.

- **The creator tax-standing sweep loads the merchant it reads once, not once per row.** It read the
  relation inside the loop over every governing record, which is one query per creator — in the one
  caller whose row count grows with the customer base and which runs unattended.

- **A webhook delivery whose provider posts a form instead of JSON is now recorded under the
  provider's own id, not a hash of the body.** `WebhookReceiver` read the delivery key from the
  decoded JSON only. A form-encoded ping (`id=abc`) decodes to nothing, so the key fell through to
  `sha256:…` — a delivery that is correct and unfindable: whoever investigates a support case holds
  the provider's resource id and has no route from it to the row, and `billing:webhooks:replay` has
  nothing to aim at. A blank id now falls through to the hash as well, rather than keying two
  unrelated deliveries onto the one row `'   '`.

  The hash fallback is deliberately kept for a body that names nothing at all: such a delivery still
  needs a key, and having none is worse than having an opaque one.

  No driver shipped with this package posts a form, so nothing here exercises that path today. The
  seam that needs it is the pluggable one — `WebhookVerifier` and `WebhookEventMapper` are public
  contracts, and a consumer's driver posts whatever its provider posts. `WebhookDeliveryKeyTest`
  drives the key directly for that reason, so the proof does not depend on which drivers happen to
  ship.

- A tip now has assertions of its own where it was previously only implied: it takes a separate position in
  a creator's monthly settlement rather than being folded into the sale it accompanied, and both documents
  over one tip — the fan's receipt and the creator's settlement — remain reachable from the same charge, so
  a refund can correct both links instead of returning the fan's money while the creator keeps their share.

- **A routed subscription moved real money every cycle and wrote no ledger row at all.**
  `RoutedChargeLedger::record()` had a single caller — the one-time hosted lane — so every routed
  subscription sale was invisible to the three things that read that table: the reversal caps, the earnings
  counter and the small-business judgement. Each answered as though the cycle had not happened, and each
  answer looked entirely ordinary.

- Two deliveries of one non-recurring sale arriving together no longer produce two documents. The sale's
  first document now claims its settled charge reference exclusively, held by a unique index, while a
  full-invoice reissue, every correction and every document carrying a period claim nothing and remain
  possible — so the constraint hardens the path without refusing the documents that must exist alongside.
- The duplicate-receipt go-live checkpoint no longer reports two corrections of one sale as a duplicate.
  It grouped on the receipt tier, which every correction leaves empty, so a second partial refund looked
  like a second receipt; it now reads the columns that say what each document is.

- The `[0.11.0]` comparison range ended at `v0.12.0`, showing a reader the following release's changes
  under this release's heading.

- The comparison links for 0.11.0 and 0.12.0 pointed at tags that do not exist, so every click landed on a
  404. Both were shipped and never tagged; they now point at the wider `v0.9.0...v0.13.0` range, which
  contains their changes without being exclusive to them, and each section says so. A named exemption in
  the structure test records which versions have no tag and why, and a second assertion removes the
  exemption the moment one of them stops needing it.

- **A buyer fee survived the withdrawal of the sale it mediated.** An effective withdrawal destroys the
  mediated sale, and a mediation fee kept on a sale that legally no longer happened is not margin — it is
  the buyer's money. The amount is small; the character of it is not.

- Adding a card through the hosted setup-mode checkout (`PaymentMethods::addMethodUrl()`) now sends the
  configured currency. A setup-mode Checkout Session carries no line items, so nothing in the payload states
  one and Stripe requires it explicitly — the call was refused with *Missing required param: currency* on
  every real request, while the suite stayed green because a faked client accepts any payload. Found by
  running the live-API smoke suite for the first time since it was written.

- A seller-reporting record no longer states a **withheld fee that the seller was never charged**. Under a
  commission chain the platform's margin is the difference between two supplies — never billed to the
  merchant, never deducted from what they are owed, and deliberately backed by no commission invoice — so
  reporting it as a separately withheld fee invented a service relationship the books do not contain. The
  field is now filled per regime, read off the settlement document where the regime was frozen rather than
  from configuration at reporting time, and the seller's gross inflow is untouched: the margin does not
  vanish, it simply is not a fee.

- **A single-seller installation was told, every 31 January, that an annual seller report was due.**
  `FilingCalendar` answered that obligation on every install, unconditionally — but the annual report is
  about what OTHER people earned through the platform, so an installation that sells only its own products
  has no such duty. Being warned about a duty you do not have is not a harmless extra: it sends somebody
  looking, once a year, for a filing interface they have no account for.
- The obligation now follows `billing.marketplace.enabled`. The periodic RETURN is deliberately not gated
  with it — that one is the platform's own and exists whether or not anybody else sells through it. The two
  share a date, so a gate hung one line higher would have traded a wrong reminder for a missing one, and
  the missing one is the expensive direction. Both arms are asserted.

  Found while reconciling the acceptance criteria of the reporting umbrella. The criterion asked for a guard
  that Mode S registers no command and no scheduler entry; measuring it turned up something sharper than a
  missing guard — the *behavior* was wrong, not merely unproven. The command itself is deliberately left
  registered: it announces the platform's own return as well, and removing it would silence that for every
  single-seller install that files one.

- **A CI step reported an unreadable OOM counter and, in the same breath, that no OOM kill had happened.**
  Both lines came out of one run, and only one of them was a measurement. The summary used the braced form
  `$${oom:-unreadable}`, which the CI server rewrites *before any shell runs* — so the word "unreadable"
  was a literal baked into the script, printed on every run whatever the counter held. The branch beside
  it read the bare `$oom`, saw the real value, and correctly said no kill had occurred.
- Measured rather than reasoned about: a probe put both forms side by side on a variable that definitely
  held a value, and the echoed command itself showed the substitution had already happened.
- The step now computes its display value in the shell, and all three verdicts go to **one stream**. They
  used to be split across stdout and stderr, which is half of why the contradiction went unnoticed:
  whoever read one stream saw one answer.
- A comment in the step claimed the counter "is not readable from inside this container, so that branch is
  the normal one here". It was written from the fake output, so it described a string constant rather than
  the container. Withdrawn rather than reworded — what that branch covers is a state nothing has observed.

- **`billing.marketplace.buyer_fee.enabled` controlled nothing.** The setting shipped, and the calculator
  behind it was complete — two models, gross-to-net with the tax as the difference, its own revenue
  account, validated configuration — but nothing in the package ever asked it. Switching buyer fees on
  changed no request, no charge and no row.

- **A crash report that could not lead to an investigation.** When a process dies mid-run and leaves a core
  dump in the tree, the guard correctly refuses — but the message said only name, size and timestamp. The
  dump itself lives in a CI workspace that is destroyed when the pipeline ends, so the standing plan
  ("inspect it the next time it happens") could never run: by the time anybody looked, the only evidence
  left was a log that did not say what had died. It happened twice, and both times ended that way.
- The report now carries the identifying strings the kernel writes into the dump's own header — process
  name and command line — which is plain text, needs no debugger, and survives in the log.

  **Raw, and labeled as raw.** Parsing the ELF note by offset would be more precise and the wrong call: the
  layout is per-architecture, and a parsed field is a confident answer nobody reading a log can check. Ending
  an investigation on one of those is a mistake this area has already made once.

  Only the first 8 KiB are read — the dumps seen here are ~166 MiB and this runs inside an assertion message.
  Proven by a needle placed past the window that must not come back, and by an unreadable header reporting
  itself as unreadable rather than as an empty answer that reads like "nothing there".

- **A mutation shard could run for hours, mutate nothing, and report success.** Its counter *detector*
  accepted two markers and its *extractor* could read only one, so a unit whose output said
  `Mutations for <file>` and never reached a `Mutations:` summary satisfied the detector, extracted to
  nothing, printed nothing, and never reached the diagnosis in the other branch — where its exit code
  would have been judged. Measured on a real run: `src/Marketplace` did exactly that in eight minutes
  and its shard went green; across three shards the run spent 4 h 42 min and produced zero mutants, and
  only the ninth step of nine said so.
- The diagnosis now models **three** states rather than two. A run that mutated prints a `Mutations:`
  summary even when the answer is nought; a directory with nothing mutable never mentions mutations at
  all; and a run that *started* and stopped short says `Mutations for` with no summary behind it. The
  third was being read as the second — the one reading that turns a missing measurement into a clean bill
  of health. It is fatal now, and it names no cause, because `--covered-only` with no usable coverage and
  an abort mid-unit produce the same shape and are different faults.

- **A withdrawal declared after the buyer's window closed was booked as a statutory one.** The window is
  frozen onto the grant and, until now, nothing read it — the same "a capability nothing reaches" defect
  this package records against itself elsewhere, and this one was its own, one round old.
  `RefundKind::StatutoryWithdrawal` is the statement *the buyer exercised a right*. After the window that
  is false: the refund is the platform's decision, one it could have made differently. Same money,
  different event, and telling them apart is the entire reason the kind exists.

- **The only implementation of `MarketplaceRails` in this package was a test double.** Both halves shipped —
  `StripeMerchantOnboarding` creates connected accounts and hosted onboarding links,
  `DatabaseMerchantAccountDirectory` resolves a merchant to its account and back — and nothing put them
  together and said "these are the marketplace rails", so `FakeMarketplaceRails` was the only thing that
  answered the contract.
- The consequence was structural. `BillingManager::marketplaceRails()` refuses any driver that is not a
  `RoutesMoney`, and the shipped Stripe driver was not one, so on the only driver this package ships that
  call could only ever throw. Every marketplace capability behind it was unreachable, and the go-live
  checkpoint reported exactly that — correctly, while it was true.

## [0.13.0] - 2026-08-06

**This release carries the work that was stamped `0.10.0`, `0.11.0` and `0.12.0`.** All three headings named
versions that were never tagged — not here, not on the public mirror, not on Packagist, which agree that the
line runs 0.9.0 → 0.13.0. Everything written under them reached users in this release, so it is listed here,
under one heading per kind. Upgrading from `v0.9.0` — the last published version before this one — gets you
all of it in one step.

The numbering gap is left as it stands: 0.10.0, 0.11.0 and 0.12.0 do not exist, and minting them now would
publish three versions nobody ever installed. The comparison link below is exact for the same reason. While
the three headings stood separately, each pointed at `v0.9.0...v0.13.0` because no narrower range could be
named — the range contained their changes without being exclusive to them, and a link ending at `v0.11.0` or
`v0.12.0` would have named a ref that does not exist. Under one heading that range is simply this entry.

### Added

- **A whole reporting period, seller by seller: `SellerReportingPeriod`.** The pieces existed and none of
  them were joined — `SellerReportingRun` answers one seller, the counters answer one seller and one window,
  and a reporting duty asks all of that about everyone active in the period. Nothing did, so the
  classification rule was reachable and never asked over more than one seller at a time.

  The roster comes from the period's settlement documents rather than from the merchant registry. The
  registry lists everyone who ever onboarded, so a run built from it would produce a row of zeros for each
  merchant who sold nothing — and a zero is a *reportable answer*, a claim about that seller's year rather
  than the absence of one. Reading the documents also makes the roster and the figures come from one source,
  so they cannot disagree.

  All four quarters are always present, including empty ones, because a missing quarter and a zero are
  different statements to an authority. The verdict stays per line rather than per seller: one seller can
  have two answers, and the field basis of their record turns on it. `reportable()` **throws** on an
  unclassified line even when another line is plainly reportable — that seller is not "reportable, done",
  and an early `true` would carry them into a filing with a group nobody judged.

  What it does not assemble is the seller's own record. The package holds the field catalog and the
  completeness rule; the values belong to the consuming application.

- **The third reporting figure: what the platform kept out of a seller's sales in a period.** The gross
  inflow and the transaction count have had counters for a while; the withheld fee waited, because the only
  stored amount for it sat on a base that was being corrected and freezing the wrong number into a reporting
  total is an error nobody sees afterwards. `MerchantChargeAnnualEarningsCounter::feesWithheldIn()` answers
  it now.

  It is counted as its own figure and never derived. Gross inflow minus payout is the fee for one unmixed
  sale at one rate, and wrong for a basket that mixes rates, for a fee with a flat component, and for any
  period holding both — wrong quietly, because both inputs are right.

  It shares the window rule and the reversal replay with the earnings figure beside it rather than restating
  them, so the two can never disagree about which quarter a refund belongs to, and it reads what a
  confirmation actually MOVED rather than what the attempt asked for. Floored at zero per charge: a negative
  would read as the platform having paid the seller a commission. Asked for by name, like its sibling — the
  two are both plausible figures about one sale, and not being reachable through a bare type-hint is the
  safeguard.

- **A routed refund now says how much of the merchant's share came back, not just that something did.**
  `RefundResult` gained `$transferReversed` and `$applicationFeeRefunded`, both nullable and both absent on
  an unrouted refund. Until now the only routed dimension was the reversal's reference, which answers
  whether a reversal happened and not for how much — so a consuming ledger had to reconstruct the figure
  from the refund total, and that reconstruction is short by real money on any fee with a fixed component:
  a 100.00 sale at 10% plus 1.00 flat pays out 89.00, and half of it refunded owes 45.00 back, not 44.50.

  The amount is the provider's own, read off the reversal it made. To get it, a destination-charge refund
  now asks the provider to expand the reversal — an unrouted refund's request is unchanged, field for
  field. The two amounts stay separate rather than netted because they move between different pairs of
  parties: the reversal takes money back from the merchant, the fee refund gives up the platform's own
  margin. `null` in either field means **not reported**, never zero — the shipped rails leave the fee
  amount null, because the provider reports it only as a cumulative total across every refund of a charge,
  which stops being this refund's share as soon as a second partial refund exists.

- **`billing:doctor` now reports a sale that carries two buyer receipts of the same tier.** Two webhook
  deliveries arriving at the same instant can each write one — the numbering stays gapless, but the buyer
  holds two receipts for one purchase and the sale is declared twice. The rule will eventually be held by
  a unique index, and an index cannot be created on data that already breaks it: without this check the
  discovery would happen at an adopter's end, mid-upgrade, as a constraint error naming a column rather
  than a sale. It is a warning rather than a blocker, and it names the charge references so the sales can
  be found. A full-invoice reissue and a correction over the same sale are **not** counted — they differ
  in the receipt tier, which is why the tier is part of the key.

- **A routed one-time sale now issues the buyer their receipt, in the same movement that takes their
  money.** This lane charged, settled and paid the merchant their share while producing no document at
  all, so a fan who bought once had no receipt — and the sale's supply regime was recorded nowhere,
  because the charge table has no column for it and the document is the only place it can be frozen.
  The document is issued only after the provider confirms the payment succeeded, its tier comes from
  `FanReceiptTierResolver` rather than being chosen on the lane, and it is idempotent on the charge
  reference: a redelivered webhook returns the document already written instead of drawing a second
  number from a series that must have no gaps.

  It also states **where the supply is taxed and which rate band it falls in**, taken from the
  classification the sale already ran through rather than worked out a second time from a second source.
  A tip inherits both from whatever it was paid alongside, which is the only way the same 11.90 can be
  told apart when it belongs in two different countries' returns. A sale whose treatment is genuinely not
  settled yet — a multi-purpose voucher — states neither, which is the honest answer rather than a gap.
  The same rule decides the delivery date (EN 16931 BT-72): a one-time sale is delivered the moment it is
  paid for, and a voucher is not delivered at all until it is redeemed.

- **Canceling a prepaid term now refunds the unused part and corrects both links of the chain.** A year
  paid in January and canceled after four months owes eight: 119.00 × 8/12, with the indivisible cent
  staying with the portion that was kept, so 79.33 goes back.

  Two pieces already answered this and nothing joined them — one knew how much a part-used term owes, the
  other knew how a refund becomes correcting documents, each booked in the period the refund happened and
  computed from the frozen sale. Until now a cancellation canceled and refunded nothing.

  Call `PrepaidTermCancellation::cancel()` with the charge, the term amount and how many of its periods
  were used. It is a service you call rather than something that fires on a cancellation event: a refund is
  a money movement, not a side effect of a status change, and many cancellations owe nothing back. The
  period counts come from you because the package does not store them — deriving them from a start date
  breaks silently once a cycle has been shifted, paused or swapped.

- **Settlement and receipt documents record what a voluntary payment was paid on.** New nullable column
  `billing_invoices.sold_alongside_archetype`, frozen against later change like the characteristics beside
  it and carried across by a correction. It is what the frozen place-of-supply and rate-band answers were
  derived from — the document states those two; only this says why. And it is what the reporting run needs
  months later, when two tips of the same amount in the same quarter, one on commissioned work and one on a
  download, are otherwise the same row.

  `null` on every ordinary sale, and that is a statement rather than a gap: an archetype that answers for
  itself needs no reference. It is also `null` on a tip settled before the column existed, and such a tip
  stays **unresolved** rather than being resolved either way — clearing a reporting duty on no evidence and
  filing a seller on no evidence are both wrong, and the row can only honestly say that nobody recorded it.

- **What a canceled prepaid term owes back, with the cent decided on purpose.** A year paid in January and
  canceled after four months owes eight months: 119.00 × 8/12 is 79.3333…, and the indivisible unit has to
  go somewhere.

  `Money::allocate()` hands the remainder to the **earliest** bucket, so the order the two sides are named in
  *is* the decision — and the two answers differ by a cent on every uneven term. `ProratedTermRefund` names
  the **used** portion first, so the odd unit stays with what was kept. That is not a fresh choice: it is the
  direction this package already decided for an uneven split, applied to the same shape of question. A second
  rounding rule is the divergence nobody notices, because both numbers look reasonable.

  It answers **how much** and nothing else. The refund itself goes through the existing cascade — § 17 Abs. 1
  UStG, ex nunc, a correcting document on both links of the chain — because a second correction path is the
  one that gets forgotten at the next change in tax law, and forgotten silently.
- **The place evidence can carry a subdivision, because it cannot be given one later.** A US sales-tax nexus
  is measured **per state** over a rolling window, so „have we crossed the threshold in Texas" is only
  answerable from history — and the evidence is written once at the sale with the raw IP deliberately
  discarded. A state not captured then is gone for good, and a counter built afterwards can only fill an
  `unknown` bucket while looking as though it works.

  `billing_place_evidence.resolved_subdivision` holds the ISO 3166-2 suffix (`CA`, never `US-CA` — the
  country is already a column, and two copies of one fact eventually disagree). `SubdivisionSignals` carries
  what each of the same three sources said, and the recorded value comes **from the sources that named the
  country**, only when they agree.

  **It records nothing you did not already supply.** The package has no input finer than the country and does
  not go looking for one, so a consumer who passes no subdivision writes none — and it is written only for a
  country in `billing.tax_evidence.subdivision_countries`, whose shipped list is the US alone. Every non-US
  sale is byte-identical to before.

  Four things are deliberately absent: no postcode, no city, no coordinate, no raw address. And
  `billing.tax_evidence.collect_subdivision` switches the whole thing off without touching the rest of the
  evidence — off, a state counter runs honestly on `unknown` rather than quietly on a guess.

  Immutable and erased on the same terms as the row it sits on: the record's guard is a whitelist, so the new
  column is frozen by construction rather than by anyone remembering to extend it.

- **A lost dispute now takes the merchant's share back, through a job — because an effect cannot.**
  Every webhook effect runs inside a `DB::transaction`, which is what makes a failed effect release its dedup
  claim instead of leaving a marker for work nobody did. The cost of that is real: an effect **cannot** make
  a provider call outside a transaction, and one that opens its own gets a SAVEPOINT rather than a commit —
  so a nested transaction reads like the promise and is not one.

  `ClaimChargebackClawback` therefore writes only the intent, and `ReverseMerchantShareForChargeback` spends
  it. The job is `ShouldQueueAfterCommit`, so it is enqueued only once the claiming transaction has actually
  committed: a chargeback whose effect rolled back cannot leave a reversal in flight against money nobody
  took back.

  Two independent guards, and they guard different things — the effect ledger stops the effect running twice
  on a redelivered dispute, and the attempt row's idempotency key stops the provider acting twice on a job
  that did run twice. A refusal is recorded as an ending and moves no totals.

  It does **not** refund the buyer: on a chargeback the network has already taken the money back, and asking
  the rails to refund as well would return it a second time. What is owed is the merchant's share. And it
  claims nothing on a charge with no separate transfer — a destination charge unwinds its transfer as part of
  the dispute at the provider, so claiming there would ask for the same money twice.
- **The reporting counter can be switched off, and off means refused rather than zero.**
  `billing.tax_counters.dac7.enabled` (default `true`) lets a platform outside the reporting regime stop
  carrying a counter for a duty it does not have.

  What "off" means is the part worth reading. A disabled counter that answered **zero** would be worse than
  one left on: zero is a real reporting answer — *this seller received nothing in the window* — and it is the
  one that gets filed. A platform that switched the counter off would produce a return stating that every
  seller earned nothing, with no error anywhere and every figure internally consistent. So it raises
  `ReportingCounterDisabled`, on every public reading rather than on the one that looked most important.

  It gates the reporting basis alone. The section 19 threshold counter answers a different question on a
  different basis — whether a creator is still a small business — and keeps running, which a test asserts by
  switching this off and reading that one.

- **A reporting period can now be assembled, which is the first thing that ever asked the reportability
  rule.** `ClassifiesReportability::classify()` was bound, correct and invoked by nothing in `src/` — a
  classification nobody requested. A verdict test cannot see that: the rule passes every test it has while
  nothing runs it.

  `SellerReportingRun::linesFor()` splits a seller's period **by what was sold** and asks the bound rule once
  per line. The split is the point rather than a convenience: there is no small-scale relief for commissioned
  work and standardized supply is out however much of it there is, so one total for a quarter would take
  whichever kind the caller happened to name and apply it to all of it.

  It is built on the gross-inflow counter's own query rather than beside it, so the lines add back up to the
  figure the whole-period total states. Two queries over settlement documents would be two places the
  window, the correction sign and the reissue exclusion live — each internally consistent, drifting apart the
  first time one changed, with nothing comparing them.

  **A line whose settlements name no archetype comes back with no verdict, and asking whether it is
  reportable throws.** Two real things land there: a settlement from before the classification could be
  recorded, and a collective settlement, which covers a month of transactions and has no single archetype to
  carry. Asking the rule anyway returns *standardized* — not because the sales were, but because the question
  never reached them — and that answer is indistinguishable from one the documents supported. Both
  directions of guessing are violations, so the line is handed back unjudged, placed last, and impossible to
  read as a "no".

- **The cure window now ends in a decision.** When the seven days run out, `billing:dunning:expire` cancels
  that ONE subscription — the customer's others are untouched however long it has been unpaid — and raises
  `SubscriptionExpired` carrying the moment access actually stops. **A period already paid for is not clawed
  back**: the subscription is canceled now and lapses at the end of what was paid for, because arrears
  concern the period that was not paid. The ending is terminal and recorded on the row: a later event for the
  same provider subscription cannot revive it, while a different one is a new signup that takes the row over.
  The reminder and the expiry take complementary halves of one comparison, so the day a window runs out
  produces the final notice and not a reminder as well. Marketplace rows only — a single-seller install keeps
  the dunning ladder, which withdraws surfaces and never cancels.

- **`SettlementTransaction::countingWith()` takes the counted period from the tax point that decided the
  buyer's leg, instead of asking you to retype it.** `$countsIn` already said both legs of a chain must land
  in the same period, and nothing made that happen: it was an optional argument you filled in by hand, from a
  fact the package had already computed for the buyer's document and then did not offer. Agreement was
  therefore a matter of remembering, and forgetting is silent in the expensive direction — `countedOn()` falls
  back to the supply date, the run settles in the month of supply, and the engine's own refusal stays quiet,
  because the transaction *was* handed to the month it claims. That refusal only ever caught the smaller
  mistake: filling the field in and then grouping by the other date. Hand the `TaxPointDecision` from
  `TaxPoint::decideFor()` to this constructor and the periods agree as a consequence rather than as a
  convention. The period is recorded only where it actually differs from the supply's, compared with the same
  `Y-m` expression the engine uses to accept or refuse — so `null` keeps meaning "the ordinary case", and an
  existing `new SettlementTransaction(...)` behaves exactly as before.

- **A subscription in arrears now gets one reminder a day while it can still be rescued.** When a payment
  fails, that merchant's access is withdrawn immediately; the days that follow are a chance to cure it, not
  a grace period with the service still running. `billing:dunning:remind` sends exactly one message per day
  per subscription for the length of `billing.dunning_cure_window_days` (seven by default), carrying how many
  days remain. Running it twice in a day sends once — the marker records which day it last sent, and it sits
  on the subscription rather than the customer, so one merchant's reminder cannot suppress another's. The
  message itself is a `PaymentReminderDue` event rather than a mail: the package knows a payment is late and
  how long is left, not how a given consumer talks to their customers. **Single-seller installs are
  unaffected** until the window is configured and the command is scheduled.
- **The e-invoice syntaxes now carry the document's billing period and its delivery date** — EN 16931's
  BG-14 (BT-73/BT-74) and BT-72, in both XRechnung/UBL and ZUGFeRD/CII, at the position each syntax
  prescribes. The line-level period was already rendered; the document-level one was not, and it is a
  separate statement: on a document covering two cycles the two differ, and reducing one to the other makes
  the reader compute what the issuer should have stated. **BT-72 is never derived from the period's end** —
  a subscription billed in advance is issued before the period closes, and a derived value would assert in a
  machine-readable field that a supply happened weeks before it did. A document that states no period emits
  neither term, and every existing golden is unchanged.

- **A document can now state the period its supply covers.** EN 16931 calls it BG-14, and without it a
  subscription invoice does not say what it is for — the reader sees an amount and a date and no answer to
  "which months". `Line` already carried a service period; the document now carries one too, because "which
  months is this document for" is asked before any line is read and answering it by reducing a set of line
  periods means every reader has to agree on how. Lines additionally carry the **cycle key**: a cycle is
  whatever the subscription says it is, anchored to the signup day rather than the calendar, so two
  documents can share a start and an end and belong to different cycles. The period is frozen on an issued
  document with the amounts — moving it re-declares the supply into another return while every total stays
  perfectly consistent. All of it is nullable and additive: a document that states no period renders exactly
  as it does today.
- **A payment arriving against a written-off receivable now reopens it — when it can be told apart from a
  repayment.** A correction issued because the consideration would not be received is a judgement about the
  future, and money turning up says it was wrong; one issued because the consideration was handed back can
  never be reopened. The two produce identical figures in identical periods, so the reason on the document is
  the only thing that may decide, and `RecoveredReceivable` is what reads it. `ReopenWriteOffOnLateReceipt`
  now asks it on every `PaymentSucceeded` and raises `WriteOffRecovered` when exactly one reopenable
  correction matches the owner, currency and amount. **Ambiguity does nothing**: a payment names no
  receivable, so with two candidates of the same amount, picking would reopen the wrong period as often as
  the right one — those stay on `provisionalWriteOffs()`, the review list. A correction stating no reason is
  never reopened. An install that writes nothing off never sees any of this.

- **The exchange-rate publisher is a seam instead of a constant.** Which rate is correct is jurisdiction
  knowledge and the rules contradict each other — a German document converts at the finance ministry's
  monthly average, an OSS return at the central bank's quarter-end reference rate, a payout at whatever the
  bank gave. The publisher is the other half of that fact, and it was written into the importer as a URL
  template and the literal `'ECB'`.

  What that cost is not a failed import: an installation filing under a different rule could import rates
  and store them against a publisher they never came from — and that name is frozen onto settlement
  documents, where it is the evidence an auditor uses to check a figure against a published table.

  `PublishesExchangeRates` names where a series is fetched, what it is stored under, and how to describe
  the publisher to an operator when it cannot be reached. The central bank's implementation ships bound, so
  an installation that changes nothing fetches exactly the series it always did.
- **The tax-standing deadline now warns the merchants it is about to catch.** The configuration beside
  `enforce_from` says what to do — *"pick a date far enough out to collect declarations, tell the merchants
  who are missing one, and let the date arrive"* — and nothing did the telling. The date arrives on its own.

  So the first a merchant heard of it was a refused sale: at the till, having done nothing and been asked
  for nothing, at the one moment where a declaration cannot be produced in time.

  A daily sweep warns anyone who has taken money and would be held on the day, `warn_days_before` ahead of
  it (30 by default). Silent while no date is set — with no deadline there is nothing to warn about, and
  inventing one to have something to say is worse than silence. Sent once per deadline; a deadline an
  operator MOVES is a different deadline, and a merchant told about March has not been told about June.

  The marker needed its own table. These merchants have no tax-status record to mark — never declaring is
  exactly what puts them in the blocking state — and writing a placeholder record to hold a flag would
  invent a declaration.
- **Filing obligations are announced before their day arrives.** The calendar that computes the dates says
  in its own docblock that its purpose is to keep the day from arriving unannounced — and nothing called it,
  so the day arrived exactly as unannounced as before. What was missing is small: a calendar answers about a
  *day*, and a warning has to look *forward*.

  A daily sweep walks the notice window (`filing_notice_days`, 14 by default) and announces each obligation
  once, with the days remaining, so a recipient phrases its own urgency instead of recomputing it.

  **One announcement per obligation, never per day.** The last period's return and the annual seller report
  fall due on the same date — different law, different data. A marker keyed on the date alone would silence
  the second, which is the precise failure the calendar was written to prevent, moved one layer down into
  the bookkeeping. Two announcements for one date are the intended shape.

  Nothing here files anything: this package submits nothing to any authority and holds credentials for none.

- **A prepaid subscription term is billed as one document, dated in the month the money arrived.**
  `SubscriptionCycleBilling::issuePrepaid()`. Where tax arises on receipt it arises when the money does — for
  the whole term, including the months not yet supplied — so twelve monthly documents would spread one
  liability across a year it does not belong to, each of them individually plausible. The single document is
  measured against the term's gross, so a large enough term crosses the small-value threshold and must name
  its buyer; that is what issuing one document means, and a small-value receipt above the limit would be a
  document making a claim it is not entitled to make.

- **A subscription cycle can now be billed as what it is: a part-supply with its own document.**
  `SubscriptionCycleBilling` is the caller the period machinery never had. Every part of that chain existed
  and none of it was reached for a subscription — a line could state the stretch it covers, both e-invoice
  writers emit it, the receipt tier was decided per document, `settlement_period` was a column — and nothing
  produced a document per cycle, so all of it applied only to one-off purchases.
  The tier is asked of **each period's own gross**, never of the contract's, and that is the point of cutting
  a term up: a monthly subscription stays under the small-value threshold and may be issued with no buyer
  identity at all, while the same contract billed annually crosses it. Billing a cycle twice returns the
  first document rather than drawing a second number, so a half-finished run resumes without duplicating.
  A one-off purchase covers no stretch of time, states none, and is untouched.

- **A subscription term can be cut into the periods it is actually supplied in.**
  `SubscriptionPeriodSchedule` produces consecutive service periods whose whole-cent shares sum back to the
  term **exactly**. Dividing and rounding each period on its own is the failure it exists to prevent, and it
  is the kind that hides: every single document is internally consistent, correctly rounded and correctly
  stated, and only the sum of twelve of them is wrong. Consecutive periods touch — no day claimed twice, none
  missing — because an end stated as the next period's start makes each receipt right and any two of them
  contradictory. Not yet wired to a document producer; that is the rest of the same ticket.

- **A lost chargeback now issues the correcting documents it owes — on the leg or legs it actually owes
  them on.** `CorrectChainOnChargeback` is the missing caller: a dispute ended access and recorded the
  provider's fee, and issued no correcting document at all. The distinction it restores is not in the
  amounts, which are identical either way. Where the buyer never received the supply, both legs are
  corrected and the creator's settlement goes back with it; where the payment was disputed as fraudulent,
  the creator delivered and the platform carries the loss, so their settlement stands. `DisputeReason`
  already mapped the provider's ground code and `RoutedRefundCorrector` already took the mapped reason into
  the arithmetic — neither had a production caller. A missing or unrecognized code corrects both legs,
  because an over-corrected creator leg is a conversation and an understated outbound tax is a filing. The
  status is resolved at the SUPPLY, not at the correction date, so a creator who has since become a small
  business does not receive a correction restating today's treatment of a sale taxed under yesterday's.

- **Tips and pay-what-you-want prices can actually be charged.** `FanPayment` is the entry point a
  consumer calls; it decides the tax from what the tip was paid on, prices at that rate, and charges through
  the one path that reaches a provider. Everything it joins existed already and none of it was reachable:
  `FanChosenPricing` held the tip rate, the floor and the zero refusal with no caller in `src/`, and
  `SaleTaxDecision` could place a tip by its reference with nothing to hand it a chosen total. The tip rate
  was therefore a setting no sale could carry. A zero amount returns null without touching the provider, and
  a pay-what-you-want price below `billing.marketplace.pwyw.minimum_minor` raises `FanPriceTooLow` before
  it — a floor checked anywhere but the server is not a floor.

- **A sale can now be priced from the total the buyer chose, not only from a net the seller set.**
  `SaleTaxDecision::decideOnGross()` inverts the rate for a tip or a pay-what-you-want price. The net and
  the tax always sum back to the chosen total exactly — that is the figure a person agreed to pay, and a
  receipt whose parts miss it is wrong where anyone can see. The rate is then checked against what the
  regime actually charges on the resulting net, with one minor unit of slack: some totals genuinely have no
  whole-cent inverse, but a wider gap means the rate does not scale with the amount, and then no net answers
  the question at all. That case raises `GrossPriceNotSplittable` instead of printing a plausible number.

- **An account slot for the realized currency difference.** A sale is collected at the document's frozen
  rate and paid out later at whatever the bank gave; the euro that appears or vanishes in between is income
  or expense of its own. It is not a correction of the sale — the document's rate stays frozen at the tax
  point — so booking it against the revenue account would move a figure a tax return has already stated.

  Two `DatevTransaction` cases rather than one net figure, because a chart that nets gains against losses
  can no longer answer what either of them was. Neither is an Automatikkonto: a currency difference carries
  no VAT to derive.

  Defaults for both shipped charts; an installation that picked no chart gets the same refusal every other
  transaction gets, and a single-currency install never books either.

- **`billing.tax_counters.reversal_attribution` — which window a reversal reduces, carried as a decision
  rather than settled by whoever built first.** Two specifications describe this counter and they say
  opposite things: one asks for the period the reversal *happened* in, the other warns in as many words that
  applying the tax-booking rule ("the month it happened") mechanically to a **count** is how this gets built
  wrong. A booking and a count answer different questions about the same event.

  What hangs on it is not academic. A creator just under a threshold whose crossing sale is later refunded:
  attributed to the reversal period the crossing stands and the documents issued after it stay correct;
  attributed to the original period the year's figure is clean — but unless a crossing that has already
  happened is explicitly kept, every document issued after it becomes retrospectively wrong at once. The
  second option is only safe together with that rule, which is the other half of the same decision and is
  not built.

  The shipped value is what the package has always done. An unreadable value is **refused** rather than
  falling back: this decides which period a reported figure belongs to, and quietly picking one would be a
  reporting difference nobody could see.

- **A receipt can now find the declarations it has to repeat.** German law wants both pre-provision
  declarations echoed on a durable medium, and the receipt is where that happens — but `PaymentSucceeded`
  carries the **payment** reference while the consent is keyed on the **checkout** reference a redelivered
  webhook repeats. Different strings, so a `ReceiptNotifier` could not find the consent by the key it holds,
  and a receipt that quietly omitted the declarations would have looked complete.

  `WithdrawalConsentLedger::forPayment()` walks the link the add-on purchase row already carries. It answers
  null for a payment that bought no add-on, for an install with no consumer-rights profile, and for a
  purchase made before the declarations were collected — and never with somebody else's, because the lookup
  is scoped to the owner as well as the payment.

  Both halves are on the selling-works guide now, with the recording call and the lookup, because a seam an
  adopter cannot find is one they will not use.
- **A second counter on the shared seam: what actually reached a creator in a quarter.** The threshold
  monitor counts payout-net — what a creator's supply was worth before their own tax. Reporting needs what
  they were *paid*, which for a standard-rated creator includes the VAT on their credit note. One
  transaction, two legitimate numbers: 90.00 either way as supply, but 107.10 reaching a standard-rated
  creator and 90.00 a small business.

  A single "revenue per creator" total cannot serve both, and the one that looks obviously reusable is the
  one already there. There is deliberately **no** code path deriving one from the other: `gross = net / 0.9
  × 1.19` is right for exactly one rate, one commission and one unmixed basket, and wrong the moment any of
  the three moves.

  It counts off the **settlement documents**, because the gross inflow is decided by the creator's standing
  at the supply and that is where the decision was frozen. A creator who registers for VAT in March does not
  retroactively change what reached them in February.

  It implements the shared seam, but the container still binds the **threshold** counter to that contract:
  a bare type-hint keeps answering what it always answered, and a caller wanting the reporting basis has to
  ask for it by name. Both figures are plausible, so a silent swap between them is exactly what needs to be
  impossible.

  The withheld fees are **not** counted yet, and that is stated rather than approximated: the only stored
  amount for them is the platform fee on the charge row, computed on a base that is being corrected.

- **The separate-transfer lane can take a merchant's share back.** On a destination charge the provider
  created the transfer as part of the payment, so refunding the payment unwinds both together. On a separate
  transfer — **the shipped default** — the money moved in a second call, and refunding the payment does not
  touch it. `MovesMerchantShare` declared `transferShare()` and nothing else, so a marketplace on the
  defaults could pay a merchant and had no path to claw any of it back.

  `ReversesMerchantShare` is a **sibling opt-in contract**, not a second method on `MovesMerchantShare`:
  that interface is implemented outside this package, so adding to it would be a fatal error in code we do
  not own. A driver either can reverse a share or it cannot, and the type system answers that before
  anything runs.

  It takes an **amount**, not a percentage. The provider's own proportional reversal is the wrong figure
  whenever the platform fee has a fixed component — on a half refund it returns half of what was paid out,
  while what is owed back is the difference against a recomputed remaining payout. And the result reports
  what the provider actually reversed rather than what was asked for: a ledger recording the requested figure
  would believe the clawback complete and never ask for the rest.

  **The verb, not yet its caller.** Nothing in the package calls `reverseShare()` — the clawback path that
  will is the next slice, and it is named here rather than left to be discovered.
- **A counter's window is an argument now, not a calendar year.** This package owes three counters over the
  same transactions, and they differ in exactly two settings: the window and the basis. The existing seam
  took an `int $year`, which serves the annual threshold monitor and nothing else — so a quarterly reporting
  counter would have had to bring its own counting, and two tallies over one set of rows disagree the moment
  a refund lands in one window and not the other.

  `CountsEarnings` takes a `CountingPeriod`, and the shipped implementation answers both contracts from one
  sum. `AnnualEarningsCounter` is unchanged and still bound: it is implemented outside this package, so
  replacing it would be a fatal error in code we do not own.

  The window is **half-open** — `[from, until)`. A closed range has to name its last instant, and whatever it
  names is wrong: end-of-day drops the final hours of a timestamp and 23:59:59 drops the final second, which
  is exactly where a year-end sale lands when somebody is watching the clock.

  There is deliberately **no basis argument**. Naming an enum of bases before a second implementation exists
  would put two cases in it that nothing selects; the basis stays documented on the implementation until
  there is a second one to distinguish.

- **The reporting layer is frozen too, at its own moment.** A sale's return is filed on a different rate
  than its document states — Germany's document rule is the ministry's monthly average, while the
  one-stop-shop rule takes the central bank's rate on the last day of the period and expressly displaces
  monthly averages. That rate cannot be frozen when the document is issued, because the day it converts at
  has not happened yet. So `billing:exchange-rates:freeze-reporting` gives a closed period's documents the
  figure their return will use, and **refuses to run before the period ends** rather than approximating: a
  missing day resolves forward, so an early run would silently stamp every document with the first rate
  published afterwards. The rule is asked of the jurisdiction profile through the new
  `SuppliesReportingExchangeRateBasis`; a profile that does not implement it freezes nothing.

- **A correction now states the rate its original was converted at.** Until this, a frozen rate was written
  and never read back — which in the database is indistinguishable from a rate written and read correctly.
  `SettlementCorrectionIssuer` carries the original's document-layer rate, date, source and rule onto the
  correcting document verbatim. It is copied rather than looked up again: a correction that re-derived the
  rate would reverse an amount nobody ever declared, and the gap between the two documents would read as a
  currency movement neither party caused. A sale that was never converted carries nothing and does not
  fail, so single-currency installations are untouched.

- **A settlement document now carries the rate it was converted at.** `SelfBillingEngine` freezes the
  document-layer exchange rate onto the record it issues, under the rule the jurisdiction profile states —
  the German profile answers `MinistryMonthlyAverage`, which is the rule that actually applies to a German
  self-billed document. A conversion whose rate cannot be obtained is refused in `plan()`, **before a
  document number is drawn**, so a refusal does not burn a number out of the sequence; a sale already in the
  reporting currency is never refused for want of rates, because it needs none. Configuration is unchanged:
  an installation that holds no rates and settles in one currency sees no difference.

- **The marketplace documentation says what an adopter needs, not only what a driver author needs.** The
  upgrading guide covered the three things a driver must get right and nothing about the application
  calling it. Two additions, both about money rather than mechanics: `RoutedPayment` is the **recommended**
  path and not an enforced one — nothing in this package calls the payment verbs, because only your
  application knows when a sale happens, which means reaching for the rails directly quietly skips the
  routed-charge row and both gates. That row is the one that bites silently: the reversal cap, the earnings
  total and the small-business verdict are computed from it, and their readers answer *zero* rather than
  failing when it is absent. And the tax-standing hold arrives with a start date that begins unset, because
  a merchant nobody has declared for is the standing that blocks — so it refuses everybody the moment it is
  switched on without one.

- **`billing.tax_small_business.warning_levels` finally does something.** It shipped as `[0.80, 0.95]`,
  documented as "fractions of the threshold at which you want to hear about it", and nothing read it. The
  daily `billing:tax-status:reconcile` sweep now reports every creator who has reached one — the highest
  level only, because somebody at 96% has passed both and reporting both makes the report longer the closer
  they get, which is backwards. It is asked **after** the flip, so a creator who just crossed the limit is
  not warned about approaching a threshold they are already past. The reason it matters is the same one the
  US activation share already ships with: becoming standard rated requires a registration that takes weeks,
  so the first day a creator owes tax is otherwise the first day they cannot charge it correctly.

- **A sale's exchange rates can be frozen onto its document, one per conversion layer.** A rate looked up
  when somebody opens a document is the rate *today*, not the rate the sale was booked at — and the
  difference is exactly what an audit finds, with the added twist that the second figure looks perfectly
  reasonable. `billing_invoice_exchange_rates` records what the conversion actually was, with its date, its
  rule and its publisher, and the row is **append-only**: the model refuses an update as a whole row rather
  than listing protected columns, because a rate, its date, its rule and its publisher are one statement
  about one moment. **One row per layer**, because one sale carries more than one lawful euro figure — the
  document takes one rule, the one-stop-shop return takes the central bank's rate at period end and
  expressly excludes monthly averages, and the payout is whatever the money moved at. That divergence is
  sanctioned rather than a defect, and a single frozen rate would force one of the three to be re-derived
  later at whatever the rate is then. Re-freezing a layer a document already carries returns what is there
  rather than replacing it, and a rate nobody published is a refusal rather than a booking. **Not yet
  called by anything**: which layer a jurisdiction freezes, and when, is profile knowledge, so the
  document path wiring it is still to come.

- **The rates can now actually arrive: `billing:exchange-rates:import`.** Scheduled daily, it fetches the
  central bank's published reference rates into `billing_exchange_rates`. The package still ships no rates
  — the numbers on your documents are the ones you imported, from a publisher you can name. It uses the
  **SDMX** service rather than the daily XML file, and that is a correctness choice rather than a
  preference: asked for a weekend, SDMX returns no observation, while the daily file answers HTTP 200
  carrying Friday's data. The next-publication-day rule can only be implemented where absence is absence.
  **It contacts nobody unless asked twice** — the store switched on *and* currencies listed, which are two
  decisions rather than one — and says which switch stopped it. **The window overlaps its own history**,
  because a one-day window turns any missed run into a permanent hole that the reader then answers with the
  next day's rate. **One observation is stored under both central-bank rules**, since the bank publishes a
  rate and not a rule; storing both keeps every answer traceable to a row imported under that rule instead
  of a silent fallback. Parsing is a pure function over the response text, with no network anywhere near
  its tests, and it **refuses a whole import on a row it cannot read** rather than skipping it — a skipped
  row is a hole nobody notices. A currency the bank will not answer for is reported while the others still
  import.

- **The exchange-rate direction is defined, before an importer could make one permanent.**
  `rateFor('EUR', 'SEK', …)` asks how many SEK one EUR buys, because that is how the central bank states
  it, and a rate is never turned around to answer the other direction. Until now this was genuinely
  undefined — `rateScaled` had no consumer anywhere in the package, so no code was wrong, and whichever
  direction the first importer happened to write would silently have become the convention for every row it
  ever wrote. The inverse is a **refusal**, not a division: 1/11.0550 is 0.09045680… which has to be rounded
  to be stored, and the rounded inverse multiplied back does not return the amount you started from — a
  discrepancy that lands on a tax document as a figure nobody can hold against the official series. Whoever
  needs the other direction converts deliberately, at a scale they chose, and owns the rounding.

- **The tax-standing sales lock is wired, with a date on it** — step 3, and the end of a config key that
  claimed an enforcement it never performed. From `billing.marketplace.tax_status_hold.enforce_from`,
  `RoutedPayment` refuses a routed sale for a merchant whose taxation nobody has established
  (`TaxStandingUnestablished`), before the provider is reached, beside the receiving gate. **The date is
  not a way of leaving it off.** This gate's default refuses *everybody*, because a merchant nobody has
  declared for is `Unclarified` and that is exactly the standing that blocks — so switching it on with
  today's date would stop every creator who has not yet declared, all at once, for something they were
  never asked for. Pick a day, collect declarations until it arrives, and it starts. Two switches reading
  `true` with no date look like two active enforcements and are none, so `billing:marketplace:preflight`
  now **reports an unset date as outstanding** rather than as configured, waivable for a platform whose
  merchants have no standing to establish. A value that is not a date is refused outright naming the key,
  because reading it as "now" would refuse every routed sale on a typo and reading it as unset would switch
  a tax control off silently. A jurisdiction profile that requires the hold still overrides all of it —
  there it is a legal condition rather than a rollout. **The payout half remains unwired** and is now
  documented as such: this package has no payout path, and holding sales while paying out regardless is not
  the safe half of the pair.

- **The creator tax standing is now writable in practice, not just in principle** — the first two of three
  steps toward wiring the sales lock (the third is the lock itself). **Recording a declaration is
  documented**: `CreatorSelfDeclaration` was complete code nobody could find, which is a different defect
  from unfinished code and had been filed as the wrong one. The marketplace guide now says what to collect
  (the standing, the founding year, a reference to what was accepted), that every declaration **expires** at
  the next year boundary plus the grace period, and why — a statement about a year in progress cannot
  outlive that year, and the platform cannot answer on the creator's behalf because it only ever sees what
  was sold here. **And the automatic flip finally runs**: `billing:tax-status:reconcile`, scheduled daily,
  flips creators whose turnover through your platform has broken a small-business limit. It is the first
  scheduled command here that **decides** something rather than reporting — every other one exports,
  announces or checks — because the event it reacts to writes no row: enough sales accumulate and a
  threshold is simply past, so the moment the flip should have happened is a moment nothing dispatched. It
  never flips anybody back, since a count that stays under a limit proves nothing while external turnover is
  invisible. A creator with no recorded founding year is **named in a warning** rather than given a
  substitute year: the founding-year limit is far lower, and inventing when somebody's business started
  would be a fact nobody stated. The run still exits zero, because a scheduler that treats a warning as a
  broken job alerts nightly, and a warning that alerts nightly gets muted. `CreatorTaxStatus` gained
  `reliesOnSizeRelief()` so the flip and the sweep ask one question in one place — a second copy would
  eventually disagree, and the silent direction of that disagreement keeps issuing tax-free documents to
  somebody who has outgrown the relief.

- **A local store behind the exchange-rate contract — an importer's table, never shipped rates.** The
  `billing_exchange_rates` table holds rates you imported, one per pair per day per rule, and
  `DatabaseExchangeRateSource` reads them. `basis` is part of the unique key rather than beside it, because
  the same pair on the same day genuinely has more than one correct rate: keyed without it, importing the
  ministry's monthly average would collide with the central bank's daily rate and one would silently replace
  the other. `rate_date` is the date the publisher stated — fetched on a Saturday, a central bank's daily
  file answers HTTP 200 carrying Friday's data, so a rate stamped with the clock is a rate for a day nobody
  published. Where a day has no rate at all, the reader resolves **forward** to the next publication day, as
  the rule requires, and the frozen rate carries the day it was actually published for rather than the day
  asked about. The walk is forward-only and bounded to a fortnight: backwards would invent a rate the bank
  had not reached, and unbounded forwards would let a series that stopped in March answer a December booking
  with March's last rate — real, plausible, and wrong by nine months. Monthly averages do not walk at all; a
  month either has an announced average or it has none, and the next month's is not a late answer for this
  one. Off by default (`billing.tax_exchange_rates.enabled`), and off is a refusal rather than a silence.
  Turning it on changes where rates come from, never whether the package supplies any: it still ships none,
  because which rate is correct is jurisdiction knowledge and the rules contradict each other on the same
  turnover. Reading never touches the network — a rate for a past date does not change, so a live call on
  the critical path of a sale would buy nothing but a way to fail. Proven against real PostgreSQL and MySQL
  as well as SQLite, since the whole forward walk is date comparisons and SQLite has no date type.

- **A contract in front of the exchange rate, and a refusal behind it.** The value object was already
  here — `FrozenExchangeRate` carries the scaled rate, the date the PUBLISHER stated, the source and the
  rule that made it correct, and refuses a non-positive rate or a "conversion" between a currency and
  itself. What was missing was anything in front of it: no seam, so no jurisdiction could supply rates at
  all. `ExchangeRateSource::rateFor()` takes the currencies, the day and — as an argument, deliberately —
  the rule being asked under. Which rule applies is jurisdiction knowledge and the rules contradict each
  other on the same turnover (German domestic takes the ministry's monthly average, OSS expressly excludes
  monthly averages), so a seam that chose the rule itself would be wrong for somebody by law rather than by
  oversight. The shipped binding is a refusal, not an absence: `NoExchangeRateSource` throws
  `ExchangeRateUnavailable` saying the package ships no rates and why. An unbound contract would answer the
  same question with "Target [...] is not instantiable", which reads as a wiring mistake in the consumer's
  own application. A single-currency install never converts and reaches neither.

- **The content-ownership register: what a buyer OWNS, as opposed to what their plan lets them do.** A
  bought work outlives the plan that was current when it was bought, the creator's account, and the work's
  own publication, so it is a row of its own rather than something read from a subscription. Nothing is ever
  deleted: a grant that stopped granting says so, with a reason and a date, because "why can this person no
  longer read what they bought" is a question somebody will ask and a deleted row cannot answer. Every
  dimension is present from the start — adding one later has no honest value for rows already written.
  `ContentAccessReader` answers "may this person reach this work now, and through what" by unioning the
  persisted grant with the live subscription view: neither subsumes the other, and requiring both would take
  a bought work away the day somebody cancels. Ownership wins when both hold, because it is the answer that
  will still be true tomorrow. Availability never changes whether somebody owns something, only whether it
  can be handed over — owning a withdrawn work is an ordinary state, not an error. Two consumer seams fail
  closed in **opposite** directions on purpose: no subscription covers any work until you say which ones do,
  while availability is assumed, because reporting every owned work as taken down would be a false alarm
  about the one thing a buyer is most sensitive to. Update policies resolve rather than being carried: a
  windowed grant is "the newest one" while its window is open and "the newest from before this date" the day
  after, and the window closes lazily so no late job can hand out a version somebody no longer paid for.
  Conformity updates are a **second, independent axis** — a defect or security fix is owed regardless of
  what the creator sold, so `frozen` freezes what a buyer is entitled to and says nothing about what the
  seller still owes, and there is deliberately no install-wide switch that turns those off.

- **The commission terms of a routed sale are frozen onto the charge** (`fee_bps`, `fee_flat_minor`). The
  amounts alone settle a full clawback but not a partial one: recomputing a proportional share is the wrong
  figure whenever the fee has a flat part. Nullable and never backfilled — an old row answers `null` rather
  than being handed today's configuration, which would claw an old sale back at a new rate while both
  figures looked plausible.

- **A completed reversal now tells a consumer what actually came back.** `MerchantTransferReversed` existed
  as a class and was dispatched from nowhere — the shape of defect this package keeps finding, where a
  device that never fires reads exactly like one that protects. It is now raised by the ledger at the one
  point that knows the answer: the caller knows what it *asked* for, but the caps decide what it *got*, and
  on a redelivered confirmation those differ by the whole amount. It carries the reversed amount, the
  commission the platform returned, the cause, and the provider's dispute fee as its **own** figure rather
  than netted off — folding it in would state that the merchant returned more than they did. Dispatched
  after the transaction commits, and only when money actually moved: a second confirmation that moved
  nothing would tell a consumer to reverse its own ledger twice, which is the double-reversal the caps
  exist to prevent, reintroduced one layer up where this package can no longer see it.

- **A retained commission is now refused in a commission chain, before the first sale.** Each half is an
  ordinary setting — keeping a fee on a refund is a normal commercial choice, and a commission chain is a
  normal regime — which is why nothing downstream caught the pair: every document the combination produces
  is well-formed. Together they describe a platform keeping money for a service it never billed, visible
  only in aggregate months later as turnover on a tax return with no document behind it and merchants short
  by an amount no invoice explains. The new `billing.marketplace.fee.refund_policy` key is read by a
  blocking, non-waivable preflight checkpoint that asks the *bound* regime resolver, so a consumer who
  replaced it is checked against their own rules. A value the package cannot read is refused rather than
  defaulted — a fallback would answer a question about money with whatever the package prefers, on every
  refund, for as long as the typo survives.

- **A merchant whose tax attestation expires is now told.** They reach a hold two ways and only one of them
  writes anything: somebody *recording* a blocking standing is a write and can be watched, while an
  attestation *expiring* writes nothing at all — the hold begins because a date passed, and `statusAt()`
  simply starts answering "unclarified". That second route is the one where the merchant most needs telling,
  because they did nothing and can suddenly neither sell nor be paid. A scheduled sweep now finds them and
  dispatches `CreatorPlacedOnTaxHold` **once** — the hold persists, the announcement does not repeat, or the
  one channel they have becomes noise. The event is wired to **both** routes: attached to only one, its
  silence would read as "no hold", which is why it was deleted rather than half-wired the first time. The
  reason travels as a translation key, in all seven shipped locales, because why a standing blocks is a
  jurisdiction's rule and the reader may sit in another one.

- **A country nobody has classified now refuses to price instead of charging zero.** The failure this closes
  is not "a country is missing" — it is a country *answering when it knows nothing*. A rate of 0% for an
  unclassified country reads exactly like a relief: the invoice says zero, the return says zero, and nothing
  records that the zero was a gap rather than a rule, so the only way to notice is an audit asking why.
  `CoverageMap` therefore keeps three states apart, not two: covered, **deliberately untaxed** (a decision
  somebody made and can defend), and **unknown** (a question nobody has answered, which refuses). The map is
  replaced wholesale by a jurisdiction profile rather than grown country by country in the package — growing
  it would put answers in here that nobody here can defend.

- **An exchange rate is part of a booking, and the rule behind it is part of the rate.** `FrozenExchangeRate`
  carries the amount's original currency, the rate, **the date the publisher stated**, the source, and the
  legal basis under which that rate was the right one. There are three such bases and two of them contradict
  each other on the same turnover — the ministry's monthly average is mandatory for German domestic turnover
  while the one-stop-shop rule expressly excludes it — so "central bank by default" would simply be wrong in
  Germany, and the choice belongs to a jurisdiction profile rather than the core. The date has no default,
  deliberately: fetched on a Saturday the central bank's daily file returns HTTP 200 carrying *Friday's*
  data, so stamping with the system clock books a rate for a day that was never published. And rates from the
  publisher's two channels are compared **numerically**, because they format identical values differently
  (`11.0550` against `11.055`) and a text comparison raises a false alarm between two genuine copies of the
  same official figure.

- **The shipped rates are a versioned data file with a header and a digest, not a bare constant.**
  `resources/tax-rates/eu-<date>.json` records the source, the URL, when it was fetched, the window the
  source said it was answering for, and who accepted it — every question a constant cannot answer, and
  being unable to ask them is exactly how two rates stood wrong for a year. The digest is not about transit
  (Composer covers that): it is about the edit nobody sees, a digit changed inside `vendor/`, which appears
  in no diff and would silently reprice every invoice to a country. Pricing refuses rather than falling back.
  The snapshot is the source **on the money path — no network, ever**: an offline installation must be able
  to invoice.
- **Proposed rate changes are graded rather than gated.** A seam that demands approval for every change gets
  switched off — the third confirmation for a country nobody has ever invoiced spends the attention the first
  one needed for a real increase, and asking about everything means being read about nothing. So a confirmed
  **increase** schedules by default (failing to apply one undercharges, which the platform pays and an audit
  finds a year later; overcharging is noticed the same day and corrected), a **decrease** or a country
  appearing or vanishing **holds** for a person, and a change to a country this installation has never billed
  is recorded without a prompt. Alongside it, the list of what an automation never touches — issued invoices,
  invoices with posted prepayments, partially delivered orders, credit notes, returns — is stated as code
  rather than prose, because "the automation does not do that" is a claim, and claims that live only in prose
  stop being true without anybody noticing. A veto inside the announced window prevents a change; after it,
  the change produces a **correction** rather than a silent undo, because invoices priced with it already
  exist.
- **The rate importer proposes and cannot write.** `RateImporter` has no method that touches the snapshot,
  and that absence is the feature: a manipulated or misparsed response cannot put a rate into production
  because the code that fetched it has no path to the file every invoice is priced from. Adopting a proposal
  is a separate, human act, and the snapshot's header then records who performed it. Its exit codes keep the
  outcome that matters distinct — 0 nothing to do, 1 a proposal is waiting, **2 the source could not be
  asked or answered something that cannot be trusted**. Booking the third as the first is exactly how a
  silent network failure becomes a year of stale rates while every log shows a successful run.
- **Plausibility bounds for a proposed rate set, taken from the directive rather than from taste** — 15%
  standard floor (Art. 97), 5% reduced (Art. 99). A rate below a floor is a misread response, not an
  aggressive rate. A country the proposal stops mentioning is refused outright, because a shorter list is
  structurally valid and accepting it silently deletes a rate. A jump over five points is flagged and *not*
  refused: a state genuinely can move that far, and a check that refuses the truth is one people learn to
  bypass.
- **The shipped rate table now has an age limit, and a separate conformity probe against the source.** Two
  checks, kept apart on purpose. The age guard compares two dates and has no network in it: a
  network-dependent assertion in the push gate goes red the first time DNS hiccups, somebody marks it flaky
  and disables it, and then the arm is gone — the exact failure this area exists to remove, one level up.
  The reduction rule for the source's response is its own tested unit, because three ways of getting it
  wrong were measured against the live service rather than reasoned about: grouping naively puts Spain on a
  Canary Islands rate, discarding rows that carry a comment deletes six correct standard rates, and `EL` is
  Greece while `XI` must never be folded into the union list. Most important of all, a request outside the
  source's data window is answered *silently with current data* — so the answer's stated date is verified
  against the window that was asked for, and a date carrying a timezone offset is read as the calendar date
  it is rather than as an instant that slips back across a quarter boundary.
- **Tax rates are dated intervals with provenance, and cannot be looked up without a tax point.**
  `DatedTaxRateTable` holds `(country, category, rate, valid_from, valid_to, source, source_version,
  fetched_at, approved_by)` and its only query takes the moment it answers for — there is no method with a
  default of "today", because that is the same trap with a better conscience: the call site looks right and
  the answer is still pinned to when the code ran rather than to when the supply happened. The law binds the
  rate to the tax point, so a credit note written now for a supply made before a rate change carries the
  rate of the supply. Append-only: an interval that overlaps one already held is refused, because after an
  overwrite nobody can reconstruct what an invoice said. A moment before the first interval is refused
  rather than answered with the oldest known rate — an invention with a date on it cannot be told apart
  from a fact.
- **A sale's tax point is decided where its tax is decided, and it carries the rule that produced it.**
  `TaxPoint` computed a bare date, so the reading behind it was lost the moment it was handed on — and a date
  alone cannot be checked afterwards, because recomputing it applies today's configuration to a sale made
  under a different one. `SaleTaxFacts` now carries a `TaxPointDecision` (date + `TaxPointBasis` + whether
  the period is taxed before it begins). More importantly, `TaxPoint` had **no production call site at all**:
  `billing.tax_point_on_receipt` was read by nothing but the class itself, so an operator could set it,
  believe they had moved to receipt-basis taxation, and get exactly the behavior they had before. The key
  now does what it says.
- **An export of goods renders as EN 16931 category `G`, and an exempt intra-community supply of goods as
  `K`.** Both used to be unreachable: the category came from a chain of two booleans that could only produce
  `AE`, `E`, `S` and `Z`, so an export rendered as zero-rated `Z` and a goods supply took the services term
  `AE`. `Z` says "the supplier taxed this at 0%"; `G` says "this was exported, no tax charged" — a different
  exemption code and a different treatment at the recipient, not recoverable from the document afterwards.
  Both writers now derive category and VATEX code from **one** shared authority, so a UBL and a CII rendering
  of the same invoice cannot disagree. A document frozen as an export while naming a member state as its
  destination is refused rather than rendered: whichever field is wrong, one of them is. A service placed
  outside the union is deliberately still `Z` rather than the strictly correct `O`, because BR-O-11 makes `O`
  exclusive and emitting it without enforcing that would produce documents a validator rejects.
- **A sale that carries no tax now says why.** The calculator returns the same `Money::zero` for a supply the
  buyer accounts for and for one placed outside the union, and downstream the two arrived identical: rate 0,
  category `standard`, nothing distinguishing them. A document built from that states a standard-rated supply
  charged at nothing — which is not a zero-rated invoice, it is an invoice that forgot to charge tax, and the
  two look the same to everyone except an auditor. `SaleTaxFacts` now carries a `TaxExemptionReason`
  (`reverse_charge` or `supplied_outside_the_union`) beside the amount, so an issuer can print the exemption
  note the document needs instead of inferring it from a number that cannot carry it. A free supply is not
  exempt — there is no taxable amount to relieve.
- **The configuration reference's default column is now compared against the code.** The existing guards
  proved every key and environment variable appears on the reference; nothing proved a documented default
  still matched what the package ships. That is the half that fails silently — a missing row is found by the
  first reader who looks for it, a wrong default is found by the operator who relied on it. Every row whose
  default is a literal is now checked against `config/billing.php`, so a default that changes without its
  row changing fails the build instead of quietly misinforming every install.

- **The marketplace configuration block now opens with an explanation of itself.** It is the longest block
  in the file — twenty-two top-level keys — and it had no header, so a reader met `enabled` and then
  twenty-one settings with no account of what they belong to. The header states the three things a reader
  needs before touching any of them: that "off" means absent rather than neutral and a single-seller install
  is byte-for-byte unchanged; that the flag **alone routes nothing**, because the path hangs off an optional
  driver contract and a driver handed a routing it cannot serve must throw rather than settle the whole
  payment on the platform account; and that the keys are three groups, not one feature — the money flow,
  **whose supply it is**, and the paperwork. The middle group is called out because those keys decide who
  the buyer contracts with and who owes the VAT, and a settled sale cannot be re-classified afterwards.

- **The multi-merchant surface gains its identity, classification and safety layer.** All of it is inert
  while `billing.marketplace.enabled` is off (the shipped default): a single-seller install reads no new
  config key, gains no boot path, and its behavior is unchanged.
- **A go-live checklist the marketplace switch is bound to.** `php artisan billing:marketplace:preflight`
  prints every point in order with what it found; with the marketplace on, an open blocking point refuses
  the boot and names the points, because a refusal at boot has also taken away the command that would have
  explained it. Order is enforced rather than suggested — a stage with an open point leaves every later one
  `UNREACHABLE` and unevaluated, never green — and a stage that holds no points says so in words instead of
  reading as passed. Points a jurisdiction adds live in its profile (`billing.tax_profile`, default `null`).
- **The receiving side of a routed sale.** `CanReceiveMoney` is fail-closed and separate from the paying
  gate: it answers a different question about a different person, and the capabilities behind it arrive
  asynchronously, so "we have not heard" has to mean no. Merchant onboarding, a provider-account directory
  and a merchant's standing with the platform come with it — the standing kept beside the provider's
  capability flags rather than derived from them, because those move several times during one verification.
- **Provider events about a merchant arrive on their own endpoint with their own secret.** A verifier taught
  to accept either secret would let the merchant key authenticate platform events, and those move the
  platform's own money. Deduplication now includes the account: event ids are unique within the account that
  issued them, so without it two genuine events could collapse into one.
- **A sale's classification is resolved once and frozen onto the document it produces** — what was sold,
  which rule decided where it is taxed, which rate band, the rate, whether it is reportable, what the buyer
  was, and which shape the sale had. They used to be read back from the product on demand, and products are
  reclassified legitimately; re-deriving them later makes a document describe a transaction that did not
  happen, without anything looking wrong.
- **A merchant's tax standing as dated intervals rather than a column.** A document never asks what a
  standing IS — it asks what it was on the day the supply happened, and an overwritable column cannot answer
  that. Nothing may be paid or sold while a standing is unestablished, in either direction: both possible
  defaults are wrong in opposite directions, so holding is the only answer that is not a guess.
- **A read-only earnings journal per creator**, three honest buckets — available, pending, held — summed
  from the routed-charge record with no state table of its own, so a shown balance can never drift from the
  charges behind it. It lets a creator see what they earned without the platform ever holding or being able
  to move the money.
- **A small-business threshold monitor that names the breaking transaction, not just the year.** It reports
  which routed sale first carried the running count over a limit and at what moment, computes the count
  gross of later reversals (a refund does not un-break a threshold that was crossed), and treats the platform
  count as a lower bound of real turnover — so it reports a breach with certainty but never an all-clear.
- **The one-directional automatic tax flip.** A broken small-business limit flips the creator to standard
  rating from the right effective moment — the breaking transaction for an intra-year break, January 1 for a
  prior-year one — and never flips back, because a count under the limit proves nothing about turnover the
  platform cannot see. It writes a dated status line and lets the ledger's event carry the notification;
  running it again over the same count changes nothing.
- **The input-side tax decision for a routed sale, as a pure function.** Given a creator's standing, the
  sale, and the platform's take, it returns the whole answer — which settlement document to issue, whether
  it states tax, how much, whether the burden reverses onto the recipient, and what the creator is paid —
  and taxes the payout, never the buyer's net. Only a validated standard-rated creator has tax stated; an
  unclarified one is a hold with no document and no payout. The rate enters as an argument and no statute
  lives in the function, so a consumer in another jurisdiction runs it with their own rate.
- **The platform's own document-numbering circles.** For the documents no provider numbers, a number is
  `PREFIX-YYYY-#######` — a per-role prefix, the year, and a seven-digit running number that restarts each
  year within its series, drawn under a row lock so two documents finalizing at once never share a number
  and the counter only advances. The roles are an enum, the prefixes are config, and a role with no prefix
  is refused rather than numbered with a blank. Gaps are harmless; a duplicate or a renumbering cannot
  happen. The single-seller numbering path is untouched.
- **The hardest lock in the settlement chain: a self-billed document may state tax only for a standing that
  permits it.** A creator's standing is resolved at the SUPPLY date — not when the document is generated, so
  a later correction cannot rewrite the tax on a past supply — and unless it is on the jurisdiction's
  positive whitelist (for the German profile, only a validated standard-rated domestic creator), a document
  about to disclose tax is refused before any row is written. The list is positive by design: a standing
  added later is blocked until someone admits it, because in some jurisdictions a self-billed document that
  wrongly states tax makes the recipient owe it. A document that states no tax needs no permission and
  always passes. The whitelist lives in the profile; the guard that enforces it is neutral.
- **A buyer's receipt is chosen from the purchase, collecting the least data.** A consumer sale carries no
  invoicing duty, so a small domestic purchase gets a simplified receipt, a larger or cross-border one a
  plain payment record, and only a buyer who explicitly asks for a full invoice has their name and address
  collected. The tier is a pure function of the gross, whether the sale is domestic, and whether a full
  invoice was requested — inclusive at the threshold — with the threshold a config value (the German
  §·33 UStDV €250.00 by default) and the tiers named neutrally.
- **An exempt supply now renders EN 16931 category E, not zero-rated Z.** A small business's supply is
  exempt from VAT, not taxed at 0%, and the two are different documents to a tax authority — so the tax
  decision marks a supply exempt (a field a downstream step could not reconstruct, since an exempt supply
  and one whose tax is merely withheld pending validation both state no tax), the document freezes it, and
  both e-invoice writers emit category E with an exemption reason rather than Z. An ordinary zero-rated line
  is unchanged.
- **The platform can now settle a creator's supply into a document.** Given a supply, the self-billing
  engine reads the creator's standing at the supply date, runs the agreement and disclosure guards before it
  draws a number, computes the amounts from the input-side tax matrix, and issues the document with the
  parties reversed — the creator the seller, the platform the buyer — and the seller frozen from the
  merchant's own party through a resolver a marketplace binds. An unclarified creator is a hold that issues
  nothing. A self-billed invoice carries type code 389; a private individual gets a tax-free settlement note.
- **A creator's whole month settles into one Ultimo-dated document, a line per transaction.** The single
  month-end date puts the platform's expense, its input tax and the matching output turnover in the same
  period (what § 15 UStG's "supply received and invoice held" needs) and makes the document total equal the
  month's payout run to the cent — reconciliation by construction. Each transaction is planned through the
  same status-at-supply-date resolution, matrix and guards a single settlement runs, so a hold falls out of
  the document and a self-billed line clears the disclosure whitelist exactly as one would; a single number
  is drawn for the whole document, and running the same month twice returns the first rather than minting a
  second. Several rates of one category are fine (the writer breaks VAT down per rate); a month that spans two
  categories — a creator crossing the small-business threshold mid-month — is refused rather than issued with
  a document-level category that would misstate half the lines. The period and dating are config; "Ultimo"
  and "§ 15" live in the jurisdiction profile.
- **A fallback lane where a creator submits their own invoice, reconciled before any payout.** A creator the
  platform does not (or no longer) self-bills — after an objection or a terminated agreement — submits their
  own invoice as a first-class object, and it is reconciled against what they actually earned that period.
  The reconciliation is the point: without it the platform pays out what the creator writes, not what they
  earned, so the submitted net and tax must match the expected figures within a tolerance (config, default
  exact), and a mismatch is a per-field finding, not a rounding excuse. Only a passing review may release the
  payout, and the same invoice number cannot be submitted, and paid, twice. What the lane does is ANSWER
  whether a creator may be paid — it does not stop a payout, because there is no payout path in this package
  to stop. `holdsPayout()` has no caller here by construction, so whoever does the paying has to ask; a
  consumer that pays without asking is not defeating a lock, it is skipping a question nothing put in its
  way. Worth stating outright, because a hold that is described and not wired reads exactly like one that
  is. A foreign format is routed to a human rather than rejected (Art. 219a Abs. 2 MwStSystRL), never
  released on its own. The format parser that fills a submission's amounts is a swappable seam; this
  reviews the amounts however they arrive. Inert while marketplace is off.
- **A settled transaction books the full three-part commission chain in DATEV, not one row.** A commission-
  chain sale is two fictional supplies of one transaction, and it posts three legs: the fan sale (money in
  transit against fan revenue at the fan gross), the creator input (the input account against the creditor at
  the payout gross), and the payout (the creditor against money in transit). The creditor nets to zero per
  transaction; the money-transit account deliberately does not — it keeps the margin plus the VAT liability.
  The red line is guarded: the input expense is ALWAYS the payout, never the fan net, with both mis-booking
  forms as failing fixtures, and the cash check reconciles to the gross margin for a standard-rated creator, a
  small business, and a reverse-charge creator (split §13b Abs. 1 vs Abs. 2). The fan gross is frozen onto the
  settlement so the chain has its fan leg; a document without it books its single row as before, so the
  single-seller export stays byte-identical.
- **A creator can object to a self-billed document, and the input tax stops from that period forward.** The
  objection is unconditional by design — no reason, no deadline, no form, and it works even against an
  arithmetically correct document — because the immediate objection is exactly what protects a mis-classified
  creator (and the platform) from a § 14c liability. Recording it takes away the document's effect as an
  invoice from the taxation period of the objection forward (ex nunc): the document itself is never touched
  (it stays frozen and retained), the state lies beside it, and the receipt time and channel are kept as
  evidence. The DATEV input path reads it — an objected settlement drops out of the batch from the objection
  period on, so no input tax is drawn from it, while the period it was originally booked in is untouched. The
  objection right is not configurable away; only a self-billed document can be objected to.
- **The DATEV export books a self-billed settlement to the creator's input side, not fan revenue.** A fan
  invoice is unchanged — receivables against fan revenue — but a Gutschrift or settlement note is the
  platform's input: its Konto is the creator's input account (whose VAT the account itself carries) and its
  Gegenkonto the collective creator-liabilities account. Which input account comes from the frozen tax
  treatment: an exempt supply books the tax-free input, a reverse-charge supply the §13b input split by
  whether the creator is in the union (Abs. 1) or a third country (Abs. 2), everything else the standard 19%
  input; a reduced-rate domestic input has no confirmed account and fails the export closed rather than book
  it wrong. The Soll/Haben direction stays the single authority it always was (the credit-note flag), so a
  role never becomes a second authority that could flip it silently. The 14-field row is position-stable and
  the single-seller export is byte-identical. A neutral `UnionMembership` check backs the §13b split.
- **The e-invoice writers emit type code 389 for a self-billed invoice.** A self-billed invoice is a
  document a tax authority treats differently from an ordinary one (380) and a correction (381), so the kind
  is frozen onto the document and both the UBL and CII writers derive the BT-3 code from it — a correction
  still wins as 381. A row with no settlement document type is an ordinary invoice, byte-identical to before.
- **A self-billed document now requires a prior agreement with the creator.** A self-billed document is an
  invoice only if both sides agreed to the arrangement before the supply — one issued without it is not an
  invoice and cannot be repaired — so the write is refused when no agreement authorizes it, for every caller
  and not only in the UI. The check is strictly ex ante: an agreement accepted after the supply does not
  cover it, and a revocation dated after the supply leaves that supply covered. The agreement is a framework
  record (one covers every future settlement), versioned by appending rather than editing, and kept as
  evidence even after the creator's data is erased. On by default; a jurisdiction that does not require it
  opts out explicitly, never implicitly.
- **A document's role must match its sale's regime — the commission chain can never emit a commission
  invoice.** The platform's margin in a commission chain is the difference between two fictional supplies'
  tax bases, not a supply of its own, so a commission invoice there would bill a VAT-nonexistent supply and
  leave a § 14c liability. The role (buyer receipt, self-billed invoice, settlement note, commission invoice,
  or a correction of one) is frozen onto the document and checked against the frozen regime at creation, so a
  role from the wrong regime can never be written — a commission invoice is refused under the commission
  chain and a creator settlement under intermediation, each throwing before a row exists. The guard sits in
  the record's creation, unreachable by any second caller, and keys on the regime rather than the posture
  because a self-billed document names the creator as seller; the role mapping is structural, so a
  single-seller row (no regime, no role) is untouched.
- **A retention rule set derived from the erasure map**, each rule carrying its authority as a translation
  key, and a residue scan that REPORTS a record that should never have been kept rather than tidying it away.
  The floor guard now checks every window, not only the documents one.
- **A merchant axis for erasure and export**, with the eraser, the exporter and the retention clock doing
  their table work through one shared implementation — an export that misses a table denies somebody their
  data and an erasure that misses one keeps it, and both report success.
- **Adopter documentation for the marketplace surface.** A `docs/marketplace/overview.md` covering the
  switch, the opt-in `RoutesMoney` driver capability, the two eligibility gates and the byte-identical
  single-seller guarantee, and an upgrade note for driver authors: nothing existing was widened, the routing
  capability is opt-in, and a driver that cannot serve a routing must throw rather than no-op — because a
  silently-ignored routing settles the whole payment on the platform and the merchant is never paid.

- **Tips and pay-what-you-want run through the ordinary sale pipeline, not a donation side path.** A tip is
  consideration for the creator's supply — the fan sought the channel out — so it carries the same regime,
  commission and document chain as any sale, and its tax and place follow the referenced product. Two things
  a chosen amount must hold that a catalog price gets for free: the pay-what-you-want floor is enforced on
  the SERVER, because a buyer-chosen price is the one place the anti-injection stance would otherwise lapse,
  and a chosen amount of zero is refused as no sale rather than priced as a sale of nothing. Off by default.

- **A buyer fee on a C2C sale is its own taxable supply, not a slice of anything else.** It is what the
  platform earns for itself in the intermediary posture, and keeping it a separate line — its own net, tax,
  place of supply and revenue account — is what stops it being netted into the item price or the seller's
  turnover, where a taxable supply of the platform's own would vanish. Its place of supply is where the
  mediated sale happens rather than where the buyer banks, and it is quoted gross with the tax read back out
  of it: a 5.00 fee at 19% is 4.20 net and 0.80 tax, summing back to 5.00, where net times rate would drift
  by a cent on other amounts. Off by default.

- **A digital work is not provided until the buyer's withdrawal consent is on record.** For a work whose
  right to withdraw ends on delivery, that right ends only if the buyer agreed to immediate provision AND
  acknowledged the forfeiture, before provision — so providing it first makes every refund inside the
  window a right rather than a decision, and where the platform is the seller of record that is the
  platform's own money. Two separate declarations, neither enough alone, frozen onto the sale with the
  wording version so a later edit cannot reinterpret an old purchase.
- **A subscription withdrawn part-way is settled by value-for-use.** The elapsed portion is charged and
  the rest refunded — a 29.75 period withdrawn on day 7 of 30 keeps 6.94 and returns 22.81 — with the used
  side rounded once and the refund taken as the difference, so the two sum back to the payment exactly.
- This is a separate profile from the tax one and off by default, because it is consumer law rather than
  tax law: a single seller needs it too, and an operator may run one country's VAT under another's consumer
  regime. With no profile set there is no extra checkout step and no changed receipt.

- **Where a sale happened is decided in the checkout, from signals that exist only while it happens.** The
  payment country belongs to an instrument in use, the connection country to a connection that is open, and
  the raw address is discarded as soon as it has become a country — so a sale that did not settle this at
  the time has no evidence and no way to obtain any afterwards. The payment instrument leads, a buyer whose
  connection corroborates them outranks it, and anything else is asked rather than guessed.
- **"Cannot be settled" and "must be asked" are kept apart**, because they look alike and are opposite. A
  contradiction has an answer the buyer holds; too few sources cannot be fixed by asking, since a buyer
  cannot manufacture a second independent signal. There is no fallback to the seller's own country: a sale
  silently attributed home is a sale taxed in the wrong place with nothing in the record admitting it.
- The reading is a class with a version stamped on every answer it gives, so a consumer advised differently
  replaces it rather than forking — and replacing it changes what happens next rather than what happened.

- **A market gate that closes before the payment.** A sale into a country where nobody is registered cannot
  be repaired by any document written afterwards — the tax has arisen and the registration has not — so the
  refusal has to come first. Absent by default, because a gate defaulting to closed would stop every
  existing install at its next sale, which is an outage rather than a guard. Once configured it is
  fail-closed within itself: anything not explicitly open is refused, including a country the evidence could
  not resolve, since not knowing where a buyer is is the clearest reason not to sell rather than a reason to
  guess. A market opened that the local rates cannot price refuses the boot — that pair is dangerous
  precisely because neither half looks wrong: the market is open, the calculator answers, and the answer is
  zero.

- **A creator names what they want to be paid, and the buyer's price is worked up from it.** The direction
  matters beyond convenience: naming a fan price leaves it fixed while the payout moves underneath them, so
  a change in their own tax standing is invisible. Naming the payout makes the buyer's price the thing that
  moves, for a reason anybody can point at.
- **A sale that cannot pay the target exactly says so, rather than repeating the request back.** Under the
  decided rounding order the residual cent goes to the platform, so 50.00 at 15% is paid as 49.99. That is
  a property of the order and not a defect — and hiding it invites a second rounding somewhere to "fix" it,
  which is how a sale stops adding up. The result carries both figures so a screen can show the one that
  will actually be paid.
- The tax is the gross minus the net, never the net times the rate. On a reduced-rate sale of 119.00 the
  net is 111.21 and the tax is 7.79; computing it the other way gives 7.78 and loses a cent the buyer paid.

- **A routing whose money flow contradicts the declared seller is refused before any money moves.** The
  charge type and the seller posture are independent on purpose — how a provider moves money says nothing
  about who the law treats as the seller — and independent axes can be set to disagree. A disagreement
  raises nothing on its own: it produces a receipt naming one seller and a settlement moving money as
  though it were another, found in an audit rather than in a log. The permitted pairs are a table, so a
  different legal reading is a configuration change; a missing or misshapen table permits nothing rather
  than everything, because a typo must not open the one combination the check exists to close.

- **A neutral event when money comes back from a merchant**, so a consumer's own ledger can reverse its
  payout entry without knowing how this package stores a charge — and, more to the point, without having to
  work out the reversed figure for itself, which is not a share of what was paid out.
- **The provider's dispute fee travels as its own amount rather than netted off the reversal.** Folding it
  in would state that the merchant returned more than they did and would hide a cost the platform really
  bore behind a number that looks like a correction. A refund carries no dispute fee at all, which is a
  different fact from a dispute fee of zero — the second would claim a dispute happened and cost nothing.
- The money-atomicity proof is deliberately in two halves. A refund nets to zero across buyer, merchant and
  platform. A LOST DISPUTE cannot: the provider has already debited the platform in full and charged a fee
  no reversal returns, so the platform is down its margin plus that fee by construction. A single test
  insisting on zero for both would either be vacuous or be booking a real cost where it does not belong.

- **A partial refund claws back what the merchant would have been owed on what remains — not a share of
  what they were paid.** The two agree whenever the commission is a plain percentage, which is most test
  data and is why the mistake survives review. Add a fixed component and they part company: a 100.00 sale
  at 10% plus 1.00 flat pays out 89.00, and refunding half leaves a 50.00 sale that would have paid out
  44.00, so 45.00 comes back rather than 44.50. The proportional figure is short by half the fixed fee on
  every partial refund, permanently, and both numbers look entirely reasonable.
- The three amounts a refund moves are recorded together on the attempt rather than derived from one
  another later, because they are not proportional and because a figure recomputed at reversal time would
  use whatever the fee policy says then rather than what it said when the refund was decided.

- **A platform commission, as a rate and a fixed amount, both defaulting to nothing.** Zero is the neutral
  position rather than a placeholder: shipping a take rate would be choosing a consumer's commercial terms
  for them. The rate applies to the whole amount with the fixed part added to it — charging the rate on what remains after a fixed amount would make the effective rate depend on the transaction size. The fee and the payout are computed as one split rather than two roundings — rounding each
  side independently on a net of 100.05 at 10% gives 90.05 and 10.01, a cent nobody paid, and the
  discrepancy only ever surfaces later in an aggregate that cannot be reconciled back to a transaction.
  A rate that cannot be read as an integer is refused rather than cast, because the cast is `0` and a zero
  commission looks exactly like a platform that deliberately takes nothing.

- **The money seams gained an optional routing dimension.** A payment with no routing reaches the provider
  with exactly the fields it always has — not the same fields plus nulls, which would still be a change in
  what the provider is told. That absence is asserted key by key, because the routing fields decide who is
  the merchant of record and who carries a dispute, and a payment that quietly acquired them would move
  that liability without anybody choosing it.
- **Two charge shapes, chosen per payment.** A destination charge makes the connected account the merchant
  of record, bearing the dispute and the provider's fee while the platform keeps a stated amount. A
  separate transfer leaves the platform as merchant of record — the costlier half, and the only shape
  available when the platform must be the one issuing the document. It is a liability decision rather than
  a technical preference, so it is never inferred.
- A refund of a routed payment reverses the merchant's share in the same call. Refunding without reversing
  is an unbounded loss path rather than a variation: the buyer is made whole out of the platform's own
  money while the merchant keeps theirs, and a lost chargeback is not a call a package can decline. The
  reversal reference on the result comes from the provider's answer, never from the package's intent — a
  refund reporting a reversal that did not happen is the failure the field exists to prevent.

- **A routed payment is now a record rather than a moment.** What the buyer paid, what the platform kept
  and what the merchant is owed are stored as three values decided once at capture — re-deriving them later
  would read today's fee policy into yesterday's payment. Settlement is its own state: a payment waiting on
  a 3-D Secure step is pending, not failed, and nothing is credited to a merchant before it settles.
- **Three reversal totals, not one.** The moment a platform keeps its commission on a refund — a normal
  policy, the work was done — the buyer is returned the whole payment while only the merchant's share is
  clawed back, and the two totals part company permanently. Each is a ceiling for a different reversal, and
  each is advanced under a row lock, so two refunds arriving together cannot both be granted against the
  same starting figure. Proven on PostgreSQL and MySQL, because the lock compiles away on SQLite and a fast
  in-memory proof of it would pass while proving nothing.
- **A refund intent is recorded before the provider is called**, and the provider's idempotency key comes
  from that row's own id. A cumulative key works for the webhook path, where the provider sends the running
  total; an operator-initiated refund has no external total, so computing one locally is a read-modify-write
  — the call times out, the operator retries, the local total has not moved, a new key is minted, and the
  buyer is refunded twice. The key travels with the row instead, so the retry is collapsed by the provider.

- **A coverage matrix over the invariants the package promises**, which fails when one is neither guarded nor
  explicitly declared as awaiting its machinery. Seven are exercised today; three name the ticket that will
  cover them.

- **A subscription now belongs to a merchant, so one buyer can hold many at once.** A fan subscribes to
  several creators and to the platform, and each is its own row keyed by a NOT-NULL merchant sentinel — a
  string every database compares identically, chosen over a nullable morph whose single-seller uniqueness
  would silently disappear on an engine whose NULLs do not collide. The real merchant's key is prefixed so it
  can never collide with the platform's, and a single-seller install keys everything to the platform
  sentinel, unchanged. Proven on SQLite, PostgreSQL and MySQL, because the uniqueness is the whole guarantee.

- **Each creator owns their own tiers and prices, through one seam that takes a merchant.** The tier and plan
  catalogs are resolved FOR a merchant, so a marketplace's creators each price their own plans while every
  caller downstream keeps using the unchanged tier and plan catalogs it hands back — no existing catalog
  signature moved. The anti-price-injection guarantee is identical in both modes: a tier KEY resolves only to
  a price the relevant catalog declares, never one the client submitted, whether that catalog is the
  platform's config or a creator's own rows. The default binding answers the config catalogs for any
  merchant, so a single seller never consults one.

- **A subscription's access is readable per merchant, and a tier now carries its rank.** The state reader
  answers whether a subscription grants access on a given instant and at or above a required level, so a
  consumer gates content on the creator the fan actually pays — not a flattened global plan, which one
  column can never represent once a buyer holds several subscriptions. The provider-free reader reads the
  local rows the webhook keeps, so an entitlement check never reaches for the network.

- **A connected subscription syncs to the fan's per-creator row, never over another's.** One creator's
  webhook can no longer regress the tier a buyer holds under another creator or on the platform: the plan
  event carries the merchant it belongs to, and the sync scopes the row by it. The denormalized hot-path
  tier column stays the platform's alone — a single column cannot hold a tier per creator — so a marketplace
  tier is read back through the state reader, and a creator's dunning pulls only that creator's row. The
  inbound tier is resolved against the FIRING merchant's catalog, so a connected event reads the creator's
  prices and never the platform's, and a connected event's buyer is resolved inside the account that issued
  the customer id, never globally where a reused id would attribute the subscription to a stranger.

- **A subscription checkout can route to a creator.** Behind an implicit resolver — the merchant is context
  the app already holds, so the marketplace call site and the single-seller one stay identical — a routed
  subscription is priced from the creator's own catalog and rides a destination charge: `subscription_data`
  carries the creator's account and the platform's fee as `application_fee_percent`, MERGED with any trial
  rather than assigned over it. The fee is rate-only, so a flat component is refused loudly instead of
  undercharging the commission on every renewal, and routing is refused before any provider call when the
  merchant cannot receive or has no account on file. Its webhook syncs from the same destination, and the
  platform reconcile skips it. The resolver is consulted only while the marketplace is on, so a single-seller
  checkout is byte-for-byte unchanged.

- **What the provider charged for a dispute is now kept, once — and it books where it belongs.** The fee arrived on the event and lived
  nowhere afterwards, so nothing could post it to an account and nobody could reconcile it against a
  statement — a cost with no record shows up only as an unexplained difference at the end of a month. It is
  recorded once per dispute, because a duplicated fee is not a rounding difference but an expense that never
  happened, and one that books to an account which self-assesses tax invents that tax too. A chargeback
  carrying no fee writes nothing: the provider not saying what it charged is a different fact from it
  charging nothing, and a zero row would assert the second. The DATEV batch takes the period's fees as an
  appended, empty-by-default argument — an existing call produces the same bytes — and books each against
  money in transit on the account that carries the tax treatment. That distinction is the whole point: a fee
  IS deducted from money on its way to the bank, which is why it looks like a bank charge and why booking it
  as one is the classic audit finding. The amounts are identical either way; only the return differs, later.
- **A merchant debt nobody nets off is now a claim you can list.** A merchant who owes money and never sells
  again is a receivable, and one nobody can list is one nobody pursues. The debt carries the date it began —
  its own column, because the row's last update answers the opposite question: a debt paid down twice looks
  newer than one nobody has touched, and the untouched one is the receivable. Offsetting against the next
  settlement can be switched off (`billing.marketplace.negative_balance.offset_against_payouts`), which is a
  commercial choice rather than a technical one: the debt then stands as a claim instead of quietly
  disappearing. How long it may sit before counting as one is configuration, never a constant — baked into
  code it would silently apply somebody else's terms.
- **A lost dispute on a routed sale is now reported, with the provider's fee kept apart from the amount.**
  The neutral chargeback event existed and nothing ever produced it; a decided dispute on a merchant's own
  charge now does, carrying who received the money, which transfer moved it, why it is going back, and what
  the provider charged for handling it. The fee is its own dimension rather than netted off: it is a service
  the provider supplied to the platform, not a deduction from what the buyer paid, and folded into the amount
  it would shrink the turnover being corrected by the fee on every disputed sale while every document still
  added up. A dispute that was won, or that is still open, emits nothing — clawing back earnings over a case
  the merchant won is the most expensive way to be wrong here, and an open dispute has not decided the amount
  that would be corrected. A single-seller install is untouched: the shipped mapper keeps reversing the
  add-on credit exactly as before.
- **A clawback that cannot take the money back is now a debt, not a loss nobody sees.** A reversal can fail —
  the merchant's provider balance may hold nothing to take — and without somewhere for the shortfall to sit,
  the reversal simply did not happen and no row says so. A signed per-merchant, per-currency sub-ledger holds
  it, and the next settlement is applied against it before anything is paid out. **Offsetting is a payment
  event, never a reduction of consideration**: it changes what the merchant receives, not what they earned,
  so the settlement document still states its full amount, the tax base is untouched and no correcting
  document is due. Treating it as a reduction would quietly turn a collection into a tax correction and
  understate the platform's own turnover by the same amount, with every document still looking right. It is
  deliberately not the buyer credit balance — that is a claim on future invoices held by somebody buying,
  this is a debt owed by somebody selling.
- **A settlement document now carries proof that it reached the person it settles with.** Issuing one now
  records that it is available at the moment it exists — the only time that claim is true by construction. A document sitting
  in a database is not a delivered one: what delivers it is that the recipient can reach it AND has been
  told, and the only thing that can ever evidence either is a record written when it happened. Without one
  there is no answer to "when did they get it" — the question every dispute about a deduction date or an
  objection window turns on. Three events per document, append-only and enforced rather than intended, since
  a log whose rows can be edited proves nothing. Fetching the document is recorded because it strengthens the
  proof, never because it carries it: a recipient who never opens what they were handed has still been handed
  it, and nothing treats an unfetched document as undelivered. A failed notification is its own event rather
  than a gap, because an absence cannot be told apart from an attempt that never happened.
- **A rate can now depend on what was sold, not only on where the buyer is.** Configure `billing.tax_matrix`
  — a sibling of `billing.tax`, never a child — with rates keyed by country and supply category, and a supply
  a country taxes at a reduced rate is charged that rate instead of the standard one. Until now a table keyed
  by country alone could only charge one rate per country, and because the buyer's price is unchanged either
  way the whole difference landed on the seller's payout with nothing looking wrong. A country that
  grants no reduced band charges its standard rate, because an absence there is an answer; an unknown country
  is refused rather than priced, because every number a missing country could produce under-declares tax. Any
  audio or video part of a supply closes the reduced band for the whole of it — no majority test, no
  threshold, and the standard rate comes back as the answer rather than as a warning. The table carries the
  date it was valid from and can report its own age — `billing:doctor` reports it and exits non-zero past a
  configurable limit, so a table that has drifted surfaces in CI rather than in a tax return. An active
  `billing.tax_profile` supplies its own country's rates where the package ships one, so an operator does not
  hand-type them; a configured matrix still wins, because an operator who priced their own table has a reason
  the package cannot know.
- **An e-invoice now carries the document's actual role, not one of two codes.** A correction that reverses a
  booked turnover and a correction that amends a specific earlier invoice are different documents, and a tax
  authority reads them apart by the EN 16931 type code alone — which one boolean could never carry, so both
  were written as the same code and the amendment could not exist. The writers now select from all four roles
  (380, 381, 384, 389) in both syntaxes, an amendment must name the invoice it amends or no document is
  written at all, and the conformance harness enforces that obligation on the rendered document rather than
  trusting the row behind it. A correction recorded before the roles existed is a cancelation and renders
  exactly the code it always did, so nothing already issued changes meaning.
- **An invoice line can now say which period it covers.** A subscription is billed cycle by cycle, and each
  cycle is a separately agreed and separately invoiced part of the whole — but until now no line could state
  the period it was for, so a subscription invoice showed an amount and a date and no answer to "which
  months". Both e-invoice syntaxes now carry it (EN 16931 BG-14, BT-73/74). It is additive and absent by
  default: a line with no period renders byte-for-byte what it always did, which the committed baseline
  proves rather than asserts. A line naming only one end states no period at all — half a period would claim
  a service that started and never finished, and the document would still validate.
- **A periodic tax return is now built from the sales themselves.** Lines are grouped by destination,
  category and the rate the sale actually carried — read from its own frozen column, never looked up again,
  because a country that moved its rate between the sale and the filing would otherwise have every one of its
  sales re-rated into a return that reconciles with itself and with nothing ever invoiced. The amounts are
  the sales' own rounded figures summed rather than a rate applied to a summed base, so no cent appears that
  cannot be traced to a document. A correction to an earlier period is declared in the CURRENT one as a
  negative line naming the earlier — that return was filed, and a file that changes after filing is not a
  filing — and the window for it runs from the date the original return was DUE, not from the end of its
  period. Those are a month apart, and using the wrong one lets through corrections that are already out of
  time. Past the window the return refuses: a correction that vanishes is indistinguishable from one never
  owed, and one folded into the current period is a misdeclaration.
- **Cross-border consumer sales can now be watched against a turnover threshold, or declared out of one —
  and the package does neither for you.** The declaration is a statement an operator makes to their revenue
  office, binding for years; this cannot make it and cannot prove it. What it can do is refuse to guess: with
  nothing configured, such a sale is taxed at the destination anyway, because charging your own rate where
  the buyer's was owed under-declares in a country nobody is registered in and surfaces as an assessment
  years later. Configure a counter instead and the running total decides sale by sale — with the crossing
  sale itself already on the far side, since it is the first under the new rule rather than the last under
  the old, and with no automatic way back. Withdrawing a declaration inside its binding period is refused at
  boot rather than silently reverting.
- **A one-stop-shop supply is now rendered at the rate it was DECLARED at.** The invoice already froze the
  rate the return was filed on, and no writer read it: the tax breakdown came from the lines, whose rate was
  derived at pricing time from a product that can be reclassified afterwards. Re-rendering such a document
  could therefore state a rate the platform never declared for that sale, into a country it never declared it
  to — and it would still add up. A flag with no rate beside it changes nothing, because a document that
  cannot say what it was declared at must not fall back to the derivation the column exists to prevent.
- **A buyer's document for a routed sale now carries only the data its tier needs.** The two lower tiers
  hold no buyer identity at all, because they do not require one — and a document that carried it anyway
  would be collection with no ground. In a commission chain that matters twice over: the same document that
  names the buyer also names the platform as seller, and pairing the two is how a buyer and a merchant learn
  each other's identity from a receipt neither asked for. Only a buyer who asks for a full invoice has their
  details collected, because only then are they required. Supplying them for a lower tier is refused rather
  than quietly trimmed — passing them means the call site believes they belong there, and silently dropping
  them would leave that belief in place and untested.
- **A refund on a routed sale now carries all the way to the correcting documents.** The arithmetic, the
  document and the link from a charge back to its settlement were built separately; this is what makes them
  one act, because a refund that computed a correction and issued nothing would be the same failure as one
  that issued a document from figures computed some other way. Every input comes from the FROZEN sale — the
  buyer's gross, the rate it was taxed at, the terms it was priced under, the standing the merchant had —
  never resolved again now. A rate cut would otherwise shrink every historical clawback and a rise would
  over-collect, with the document still adding up. It is idempotent because the amount is: the routed ledger
  reports what actually moved after capping, so a redelivery moves nothing and no document is issued. Both
  sides are corrected in one call, each from its own figures: the merchant's document carries the clawback
  and the buyer's carries what the buyer got back. They are different amounts about different supplies, and
  a document holding the wrong pair would reconcile with itself while describing the wrong party.
- **A correction is now a document, not only a figure.** A settlement now also records which routed
  transaction it settles, because a refund knows the charge and the correction it owes has to find the
  document issued for it — matching on amounts and dates instead would be a guess. The merchant-side correction of a refunded routed
  sale is issued as a numbered, referenced record — a correction that exists only in a ledger corrects
  nothing anybody can be shown. It is booked in the month the refund happened, never the original's: both are
  defensible arithmetic and only one is lawful, since back-dating would reopen a period that has been
  declared and make two documents describe one month differently. It draws from the correction series paired
  to the original's, so a document and its correction never share a number, and it names what it corrects —
  read from the original rather than passed in, because a caller who could supply the reference could also
  supply the wrong one. Amounts are stated as positive magnitudes: a negative invoice is not a thing, a
  document that says "this much less" is, and its role inverts the meaning.
- **A refund now corrects every link of a routed sale, not just the buyer's.** A routed sale creates two
  taxable bases — what the platform sold to the buyer, and what the merchant supplied to the platform — and
  a refund changes both. Correcting only the buyer's side left the tax deducted on the merchant's side
  standing in full, on every refunded transaction, with nothing anywhere looking wrong. The correction is
  now one answer computed in one pass, and it reconciles itself: what the buyer gets back, less what the
  merchant returns, less the change in tax owed, must equal the commission on the refunded part, or no
  correction is produced at all. Every figure is recomputed on what remains of the sale rather than scaled
  from the original — with a fixed fee component the two disagree on every partial refund — so refunding in
  parts and refunding at once reach the same place, and a redelivered webhook corrects nothing twice.

- **The union membership list has a date, and `billing:doctor` reports its age.** `DefinesUnionMembership`
  asks a jurisdiction profile for the day its membership was last known correct, and nothing ever asked — a
  promise with no reader reads exactly like a promise being kept. The shipped list, meanwhile, carried no date
  at all, so an install running entirely on it could not answer "how old is this" from anything but the git
  log. `UnionMembership::MEMBERS_CHECKED_ON` is that date, and the diagnostic prints it, preferring a
  profile's own `unionMembersValidFrom()` where one is loaded — reporting the shipped date under a foreign
  profile would state the age of a list that decides nothing there. Deliberately reported and never failed:
  membership changes about once a decade, and a check that would be red for years at a stretch is one nobody
  reads on the day it finally means something. The rate table moves yearly and keeps its hard limit.

### Changed

- **BREAKING — `RoutedPayment::charge()` and both `FanPayment` entry points now require the buyer.**
  They take `Model $buyerOwner` and `bool $buyerIsDomestic`; on `FanPayment::tip()` and
  `FanPayment::payWhatYouWant()` the buyer sits after the merchant, and the domesticity flag after the
  existing `TaxContext`. Both are mandatory on purpose. The receipt above cannot be issued without
  knowing who the buyer is, and made optional it would go on being skipped for every caller not yet
  updated — silently, and for exactly the sales that already work. Passing your existing arguments
  positionally raises a `TypeError` rather than charging, so nothing changes shape unnoticed.
  `$buyerIsDomestic` is supplied rather than derived, the same way `SubscriptionCycleBilling` already
  takes it: the package has no second opinion about where a buyer is.

- **Changelog entries are now written as fragments, one file per change.** Add
  `changelog.d/<your-branch>.md` with a `### Fixed` (or `### Added`, …) heading and your entry;
  `CHANGELOG.md` is no longer edited by hand. At release, `just changelog-assemble <version>` folds
  every fragment into one version block in Keep a Changelog's section order and deletes them.

  Every entry used to land at the top of the same section of the same file, and git reads two insertions
  at one place as a conflict even when both sides only add and neither overwrites. With *n* open pull
  requests, each merge conflicted the other *n−1* — and the resolution is not what cost anything, since
  it is always "keep both". Each one re-ran the full gate, around forty minutes on a serial queue, for a
  change that moves one line. Measured on one day: 28 merges, 19 of them catch-up merges.

  Appending at the bottom would not have helped; two contributions appending at the bottom collide just
  as surely. As long as one file is the shared writing surface, concurrent contributions collide.

  The trade is honest: fragments introduce a release step that did not exist, and a new way to go wrong —
  a fragment left behind would surface one version late, as though the change had happened later. The
  release refuses to run while any fragment is unassembled, so that failure is loud at the tag rather
  than published.

- **The package no longer patches one of its own dev dependencies.** The mutation runner could not
  read the `--coverage-php` report that php-code-coverage 14 writes, so this repository carried a
  local patch for it. That fix is upstream as of `pest-plugin-mutate` v5.0.1, and the patch, its
  lockfile and `cweagans/composer-patches` are gone with it — verified by running a scoped mutation
  pass on the unpatched release rather than by reading the release note, because the failure this
  patch also prevented is a run that reports success having tested **nothing**.

  Nothing a consumer runs is affected, but the published manifest is: `require-dev`, `extra` and
  `config.allow-plugins` each lost an entry. The release pipeline still strips development-only
  patching keys — that guard is kept deliberately, since it has to already be in place on the day
  a patch is added back.

  Dev toolchain moved with it: Pest 5.0.3 and its Laravel/evals/type-coverage plugins, Pint 1.30.3,
  Livewire 4.3.5. `phpstan/phpstan` and `rector/rector` stay pinned to exact versions on purpose —
  see below.

- **The two exact toolchain pins now carry their reason where it will be read.** `rector` reaches
  into PHPStan's private `RichParser::$parser` — verified in `vendor/` against the installed 2.5.9,
  at the source rather than from a changelog line — and writes to it. A caret cannot express that
  coupling, because neither side owes the other anything about a private. The pins were previously
  unexplained, which is how a pin gets "tidied up" by the next reader; they are now registered with
  their retirement condition, and an unregistered or stale pin fails the build.

- **The cross-engine suites create their own databases and prove the engine they claim.** You no longer
  create `*_test` by hand: reachability is probed through a maintenance connection, so "server down" and
  "database not created yet" stop looking alike, and the second case is answered by creating it — including
  a separate database per worker under a parallel run, which is what stops workers racing each other's
  schema. A reachable server of the wrong ENGINE now fails too: a version floor cannot tell MySQL from
  MariaDB 11.4, which clears the 8.4 floor numerically, so the server's own banner must name the product.

- **The mutation receipt must come from a serial run.** `--parallel` reports survivors as killed, so a
  parallel score can only ever be falsely green. `composer mutate` is now serial and is the only run allowed
  to stamp the release receipt; the fast parallel detector lives under `composer mutate:detect` and gates
  nothing. The receipt records how it was measured, and the tag gate refuses one that does not say `serial`.

- Test toolchain and CI dependencies moved to current: PHPStan 2.2.7 and Rector 2.5.9 (still pinned exactly —
  Rector reads PHPStan internals, so the pair only works together), `composer-patches` 2.x, Playwright 1.62.1
  against the matching CI image, and Node 24. `stripe/stripe-php` v21 is deliberately not adopted: Cashier 16
  requires `^17.3.0`, so it cannot be installed here at all.

- **The test toolchain moved to the current major, and the release pipeline moved with it.** The version bump is
  the smallest part. This package's mutation lane needs the `composer-patches` channel — the mutation runner
  crashes on php-code-coverage 14 — and that channel adds a top-level `patches/` directory, which the
  default-deny release-tree audit refuses until it is classified. Proven rather than assumed: with the
  classification removed the audit reports `patches` unclassified and goes red.

  The half that would have shipped broken is the manifest. `composer.json` is published **verbatim** and names
  `patches/` in `extra.patches`, while `patches/` itself is STRIP — so the published manifest would have pointed
  at a file the public repo does not contain. That is not cosmetic: an application with patching enabled applies
  its dependencies' declarations too, so it would have failed to **install** this package over a file that was
  never its business. The release workflow now deletes the development-only patching keys from the staged
  manifest and proves structurally that no `patches*` spelling survived — checking only one of the two spellings
  reads as covering both and does not.

- **`SellerActivity` and the gross-inflow counter now say where a reporting classification comes from.** No
  behavior changed; what changed is that the boundary is written down at both seams a caller actually lands on.
  The archetype a reporting rule reads is **not** in this package's rows and no column holds it: the
  classification is enforced as a gate where money is taken, and the answer is deliberately dropped rather than
  frozen, because the product catalog belongs to the consuming application. A caller assembling a return takes
  the figures from the counter and the classification from their own catalog. Said out loud because the two feel
  like one job — a counter that produced both would look tidier and would be guessing at half of it, in the
  direction where over-reporting is itself a violation.

- **A term paid up front now covers its own cycles, so walking them afterwards does not bill them again.**
  A prepaid term is one document for the whole stretch; a scheduler that then walks the subscription's cycles
  asked for a document per month, and nothing said no — the prepaid document's period key is the term and a
  cycle's is one month, so neither the lookup nor the unique index could see that one contains the other.
  Measured before the fix: a prepaid year plus its twelve cycles produced **thirteen documents and 37.96 of tax
  where 19.00 was owed.** Every one of those documents states a real period and a correct amount; only the sum
  is wrong, and no document carries the sum. Each cycle now answers with the document that already covers it,
  and a cycle falling **after** the term still bills normally — so a subscription prepaid for a year and then
  continued monthly keeps working. Nothing changes for a term that was never prepaid.

- **A settlement correction now states the commission terms its original was priced under.** The correction
  issuer already carried the frozen *tax* characteristics, with a note on why leaving them empty was wrong: a
  document that states a taxable amount without stating what it is a taxable amount OF leaves the reader
  inferring from the original. The three commission columns were the same omission one field over. **No figure
  was wrong** — `RoutedRefundCorrector` reads those columns and treats an empty set as a zero commission, but
  it never reaches a correction, because its lookup narrows to the settlement series. The gap is worth closing
  anyway: that fallback was written about a row from *before* the terms were frozen, and nothing downstream
  could tell "never written" from "taken at zero". An original that recorded no terms still produces a
  correction that records none, because those are different claims.

- **The two Stripe hosted lanes now say that they take the commission on the GROSS, where the configured rate
  is documented as a net rate.** Nothing about the money moved changed — what changed is that the exception is
  written down, in the config comment, the database reference and both call sites. It is structural rather than
  an omission: Stripe defines the subscription's `application_fee_percent` as a percentage of the invoice
  *total*, which includes the buyer's tax, and no single percentage can be right for a 19% buyer, a 20% buyer
  and a reverse-charge buyer at once; the hosted one-off purchase needs an absolute fee at the moment the
  session opens, which is **before** the buyer's place of supply is evidenced and therefore before their rate
  is a fact. So on a 119.00 sale at 19% with a 10% rate those two lanes keep 11.90 while the routed payment
  path — the one that writes the ledger rows — keeps 10.00. Which answer the package should settle on is still
  open. Until it is, an undisclosed exception to a stated promise is exactly how the two bases came to diverge
  unnoticed in the first place, so the promise now carries its exception wherever the promise is made.

- **A subscription document now actually carries its service period at the document level, and the ZUGFeRD
  writer no longer refuses a document it was just handed.** Two defects at one seam, both in work that had
  shipped with tests. `billing_invoices.service_period_start` / `.service_period_end` were created in one
  change and read by both e-invoice writers in the next, and **nothing ever wrote them** — so EN 16931's BG-14
  rendered empty on every document the package issued, while the renderer tests passed because each built its
  own row. And `ZugferdCiiInvoice` declared its issue date as the *mutable* `Illuminate\Support\Carbon`, which
  a freshly created model does not carry: a model straight out of `create()` returns the value it was given,
  not the cast one, so rendering a document immediately after issuing it raised a `TypeError` and only a
  re-read document worked. That parameter is now `CarbonInterface`, which is all the method ever needed — it
  formats the value and nothing else. Both were found by one new test that starts at the issuer instead of
  building its own invoice: **a test that supplies its own input cannot notice that nothing else does.** A
  document that covers no stretch of time still states no period at all.

- **The line-coverage floor now reads a report file instead of the runner's screen output.** `test:coverage`
  used to be `pest --coverage --min=100`, which derives its verdict from the same rendering that informs a
  human — and above roughly six hundred tests that rendering stopped appearing, silently, so the run ended
  with a passing test count and a bare exit code while the one number the floor enforces was missing. The
  collection was never the problem: over the identical run, `--coverage-clover` wrote a complete report. So
  the floor is computed from that file, it still stands at 100%, and a run below it now names the percentage
  and the least-covered files rather than only failing. A missing, unparseable or zero-statement report is
  itself a failure — an absent report is otherwise indistinguishable from a perfect one. The **type**-coverage
  floor had the identical design and was moved the same way in the same change, because fixing one instance
  and leaving its twin is how a diagnosis returns later wearing a different hat. Nothing about this reaches
  a consumer; it is how this package proves itself.

- **Arrears now withdraw the merchant they are owed to, and nobody else.** A customer behind with one
  merchant used to lose access to every other one — including relationships they had paid for and kept
  paying for, and whose merchant had no part in the event. Both readings the code had were aggregates over
  the customer's subscription rows: first the newest row's clock (a row with no clock reset the ladder
  outright, so a customer two rungs deep returned to zero by subscribing to anyone), then the earliest,
  which fixed the reset and left the longest-standing debt governing every relationship at once. One
  relationship now answers for itself. **Nothing changes for a single-seller install**, where every row is
  the platform's own — asserted, not assumed. The scoped question arrives on two new optional contracts,
  `MerchantScopedDunningGuard` and `MerchantScopedSuspensionLadder`, rather than as a parameter on the
  published `DunningGuard` and `SuspensionLadder`: appending one fatals every existing implementation at the
  declaration, which would be a major break. An implementation that never adopts the new interfaces is
  untouched; the route middleware still asks the unscoped question, because a route does not know which
  merchant it belongs to.

- **A redelivered subscription payment no longer spends a document number finding out it was a repeat.** The
  cycle is now looked up inside `FanReceiptIssuer` itself, before the number is drawn, so a repeat returns the
  document it already has — for every caller, including one that does not come through
  `SubscriptionCycleBilling`. Only a genuinely simultaneous pair of deliveries still reaches the unique index,
  and that refusal is deliberately left to surface: the provider retries, and the retry takes the read path
  and gets the document, so the outcome is one document either way. **Absorbing that refusal instead — reading
  the winner back after the failed insert — was built and abandoned, because it cannot be done portably.** The
  two engines this package is proven on demand opposite things, neither visible on SQLite: PostgreSQL refuses
  every further statement in an aborted transaction, so the recovery read needs a rollback first, while on
  MySQL 8.4 that rollback comes back `SAVEPOINT trans2 does not exist` and the resulting `PDOException`
  replaces the violation being absorbed — but only when the call is nested inside another transaction, which
  is the shape a careful consumer produces. A mechanism that works alone and breaks inside your transaction is
  worse than the plain refusal it was meant to soften.

- **One document per owner, series and period is now enforced by the database.** Both period-billing paths
  avoided duplicates by reading whether a document existed and writing one if it did not — check-then-act,
  correct exactly as long as nothing else is doing it at the same moment. Payment events are redelivered;
  that is the normal case these paths exist for. Two deliveries arriving together both find nothing and both
  write, and the second draws its own number from the running series — so the duplicate is not a repeated row
  but a numbered document a return counts twice. The series is part of the key because a creator settlement
  and a buyer receipt can name the same owner and period; period-less documents never collide, because nulls
  are distinct in a unique index on every engine this package supports. **Upgrading:** a database already
  holding two documents for one owner, series and period will refuse the migration — that is the defect it
  prevents, and the duplicates have to be resolved first.

- **A settlement transaction can now say which period it COUNTS IN, and a run refuses one that belongs
  elsewhere.** `SettlementTransaction::$countsIn` is a third answer kept apart from the two the supply date
  already gives (the creator's standing, and the service time the line states). It diverges exactly where a
  term is paid up front: the buyer's leg is taxed in the month the money arrived while the service runs
  across the year, so settling by supply date would put the creator's leg in a month the buyer's leg already
  taxed elsewhere. Nothing about either document would look wrong — the drift is an input-tax offset across
  the remaining months, visible only where somebody compares two places no report puts side by side.
  `CollectiveSelfBillingEngine` refuses a mismatch with `SettlementTransactionOutsidePeriod` rather than
  reassigning it, because reassigning would make the engine a second place the periodisation is decided.
  Null means the ordinary case, and an ordinary run behaves exactly as before.

- **A reversal now reduces the period of the document it corrects, not the period it happened in.**
  `billing.tax_counters.reversal_attribution` ships as `original_period` (it was `reversal_period`), so a
  refund in February takes its turnover out of the year the sale was made. A reported year then describes
  the inflow that actually stayed. Set the key back to `reversal_period` if your reporting obligation reads
  the other way — both remain supported and one key governs both counters.
  **This is only safe because of its other half, and the two ship together:** a threshold crossing that has
  already happened is final. `SmallBusinessAutoFlip` only ever flips forward, and a test pins the two
  figures disagreeing on purpose — the year reads clean while the breach keeps the transaction and the date
  it happened on. Without that, a clean year would retroactively unmake a crossing that was acted upon, and
  the VAT stated on every settlement issued in between becomes unlawful at once, from a refund nobody
  thought of as a status event.

- **A correcting document now passes the tax-disclosure whitelist — at the ORIGINAL's moment, not today's.**
  `SettlementCorrectionIssuer` had no way to ask: the original settlement passes the whitelist before a
  number is drawn, and its correction passed nothing, so a document restating tax could be written for a
  party whose standing never permitted stated tax. The guard is now a required dependency, and it resolves
  the standing at the supply. That second half is the point rather than a detail — carrying "ex nunc" across
  to the status resolution as well produces stated tax that was inadmissible when the supply happened, and a
  whitelist that only ever looks at today is exactly the check that cannot catch it. A correction restating
  no tax needs no permission; one whose original has no resolvable party is refused with
  `InvalidInvoiceCorrection::partyUnresolvable()`, because an unprovable permission is not a permission.

- **A tip is now placed by what it was paid on.** `SaleTaxDecision::decide()` takes `$soldAlongside`, and
  reaches the taxonomy through `ProductClassifier` so the delegated cells come from the referenced product.
  Previously any delegating archetype was simply refused — the method had no way to be told the reference.
  A tip on commissioned work is taxed where the seller is and a tip on a download where the buyer is, so an
  identical amount belongs in two different returns; nothing about the tip itself distinguishes them, which
  is why the reference is asked for and omitting it still refuses rather than defaulting.

- **A routed sale now requires a product classification.** `RoutedPayment::charge()` takes the tax
  archetype of what is being sold and refuses without one. The classifier already refused correctly and
  **unreachably**: the archetype arrived as an argument, no record in this package carries one, and a
  consumer who simply never called it sold anyway — so "no sale without a classification" was
  documentation rather than a rule. The parameter is deliberately *required and nullable*: required so it
  cannot be forgotten, nullable so that passing nothing raises `ProductNotClassified` with its own
  explanation instead of a `TypeError`. A voluntary payment must also name what it was paid on, because a
  tip on commissioned work and a tip on a file download owe different things.

  The product catalog stays yours. This is enforced at the seam you already come through, not by a
  `billing_products` table or a mandatory column — the package holds no catalog and gains none here.

- **The one legal rule left in the neutral core moved to the jurisdiction profile.** Goods sold between
  private people were forced to intermediation by the shipped resolver. The fact that rule rests on — the
  platform never owned anything — is jurisdiction-neutral; concluding a *supply regime* from it is a legal
  characterization, and one sitting in a neutral core is a single jurisdiction's answer wearing the costume
  of a general one. A profile now states it through `SuppliesArchetypeRegimes`, and the shipped German
  profile does. A profile that does not implement it leaves your configured default standing — unlike the
  exchange-rate seams, which refuse, because there an answer would have to be invented and here you already
  wrote one down. The opt-in allow-list still refuses any regime you have not said you operate.

- **Breaking (pre-1.0): `RoutedPayment` takes three further constructor arguments** — the pairing guard,
  the seller-of-record resolver, and the config repository. Container-resolved code needs no change. What
  the installation sells is read from `billing.marketplace.seller_of_record.supplies_are_electronic` rather
  than assumed, because the posture turns on it and a platform selling physical goods may name the merchant
  as seller of record where one selling electronic services may not.

- **Breaking (pre-1.0): `SettlementCorrectionIssuer` takes a second constructor argument**, the
  `FreezeExchangeRateOnDocument` that carries rates onto a correction. Container-resolved code needs no
  change. It is required rather than optional on purpose — an optional dependency with a default is handed
  that default by Laravel for any class it has no binding for, without attempting to resolve it, which is
  exactly how the sibling seam in `SelfBillingEngine` was wired and silently inert.

- **`billing.tax_matrix.max_age_days` is documented, and a guard now sees keys that live only in code.**
  `billing:doctor` reads it to decide when a rate table is too old, falling back to 180 days. It fits the
  documented shape of `tax_matrix` and works — it simply appeared in neither the config comment nor the
  reference page, so the only way to learn the limit was adjustable was to read the command. The existing
  coverage checks could not have found it: they walk the SHIPPED config array and ask whether each key is
  documented, and a key that exists only as a read-with-a-default is in no array to walk. The new check
  runs the other way, from the reads, and it catches a second thing worth having — a mistyped key, which
  otherwise answers with its fallback forever and never fails.

- **`billing:tax-holds:announce` is documented.** It is scheduled daily and it is the only way a merchant
  learns their tax hold has begun — every other hold starts with a write somebody can watch, while an
  attestation running out writes nothing at all. It was missing from the command reference, and the check
  that exists to prevent exactly that reported clean: its pattern read `billing:[a-z:]+`, which stops at a
  hyphen, so `billing:tax-holds:announce` and `billing:tax-return:export` both captured as `billing:tax`.
  Two commands collapsed into one entry, and the documentation for the second vouched for the first. The
  patterns now include hyphens, and a count of captured signatures against command files makes a future
  truncation fail immediately — a pattern that truncates does not report an error, it reports FEWER
  commands, and a shorter list passes a containment check more easily than a correct one.

- **The data-protection notice the package ships is now documented, and a guard keeps it discoverable.**
  `lang/<locale>/privacy.php` carries the wording for the place-of-supply evidence this package collects —
  six keys in seven locales, forty-two strings. Nothing in the package rendered them and no page mentioned
  they existed, so the only way to find them was to read `lang/`. The wording was always meant for the
  consumer to render, on their own privacy page, because the hard part is getting it right in seven
  languages and the page is not; what was missing was any way to know that. The keys are now documented
  with a Blade example under Data protection, and a test requires every shipped translation key to be
  either rendered by this package or classified as the consumer's — and every classified group to be named
  on a documentation page, because a claim about discoverability is worth exactly what the documentation
  behind it is.

- **Two of the nine tax archetypes crashed `SaleTaxDecision::decide()` with an internal invariant.** A tip
  and a multi-purpose voucher hold no fixed place-of-supply by design, and reading one out reached
  `TaxonomyCell::value()`, which throws a `LogicException` reading "Handle that case rather than reading a
  value out of it" — a sentence addressed to whoever maintains this package, surfacing to whoever called
  `decide()`. Both cases still refuse, and both refusals are right; what changes is that they are now this
  package's own `ProductNotClassified`, name the archetype, and say what to do. They are deliberately
  different messages, because the two gaps are different: a tip is missing a REFERENCE the caller could
  supply (what it was paid alongside), while a voucher is missing an ANSWER that does not exist yet — what
  it will buy is undecided when it is sold, so the treatment belongs at redemption. Supplying the tip's
  reference is a signature change with a tax consequence and stays with the ticket that owns the
  delegation; this fixes the error, not the feature.

- **`RoutedPayment` now runs the receiving gate before it charges, and takes `CanReceiveMoney` as a required
  constructor dependency.** It is the only place in the package that calls `PaymentRails::charge()`, and it
  consulted no gate at all — while both sibling paths, `StripeCheckout` and `StripeOneTimeCharge`, already
  refuse a merchant the gate denies. The cost of the omission is not a failed payment: a merchant who cannot
  receive does not produce a clean rejection, so the money settles wherever the provider can reach — usually
  the platform — while the row this method writes says a merchant was paid. Nothing errors, the two records
  disagree, and the disagreement surfaces when somebody reconciles, per transaction, by hand.
  `ReceiveEligibilityDenied` has described exactly this since it was written ("the routed payment was refused
  before it reached the provider"); the routed-payment path was the one place that never raised it. The
  dependency is required rather than optional on purpose: an optional gate defaults to absent, absent reads
  as no objection, and a money gate whose default answer is yes is not a gate. The shipped default is
  unchanged (`AlwaysReceivable`), so an install that composed no gate sees no behavior change — this makes
  sure whatever *did* decide is actually asked. Consumers resolving `RoutedPayment` from the container need
  no change; a consumer constructing it by hand must pass the gate. Pre-1.0 signature change, called out here
  rather than made silently.

- **`MarketplaceUnsupported::separateTransferNotImplemented()` is now `separateTransferNeedsRoutedPayment()`.**
  The old name told a consumer to wait for a later version, and by the time the transfer capability was built
  it was doing that in the same release that shipped it. A factory name is the first thing in a stack trace
  and the string people grep for, so "not implemented" sends them away from a path that exists — while the
  message right below it already pointed at `RoutedPayment`. The message and the behavior are unchanged;
  only the name now says what to do instead of what is missing. Pre-1.0 rename, called out here rather than
  made silently.

- **The documentation is now a site rather than a folder in this repository.** It has moved to
  [docs.pushery.com/billing-for-laravel](https://docs.pushery.com/billing-for-laravel/), and the README
  links there. Nothing was removed and no page was rewritten — the same tree, published somewhere it can
  be searched and navigated instead of read as raw Markdown. This affects only where you read it: the
  installed package never carried the documentation (it was excluded from the Composer dist), so an
  existing installation is unchanged.

- **A booking batch now refuses what the import would silently mangle.** A document reference longer than
  the field, or carrying a character it cannot hold, aborts the export instead of being written: the import
  accepts a shortened or mangled reference without complaint, and the booking then points at a document
  nobody can find. And a batch covers ONE posting period — a range crossing a month boundary is refused
  rather than exported whole, because the import posts the entire file into the stated period and would land
  part of it in the wrong month. **This changes behavior for an existing call**: `billing:datev:export` with
  a range spanning months now fails instead of producing a batch. Export each period on its own.

- **A booking now says who it is for and what it undoes, and a due date has somewhere to go.** The booking
  text carries the merchant identifier — the identifier, never a name, because the batch reaches an
  accountant and a name there would put a buyer's and a merchant's together in one file — plus the document a
  correction corrects. It stays inside the field the format allows by dropping a whole part rather than
  cutting one: a cut identifier still looks like an identifier, so it reads as a reference to some other
  document, while an absent part is visibly absent and the reference is in its own field regardless. The new
  nullable `due_at` column feeds the field reserved for it; a document that states no due date produces the
  row it always did, down to the byte.

- **A period's return lines can be exported as a file somebody actually files.** `billing:tax-return:export`
  writes one quarter, defaulting to the one that has just **ended** rather than the one still running — a
  figure that is still moving is the one somebody files by mistake. The column naming the period a row
  corrects is on **every** row, empty where there is nothing to correct: a file whose shape depends on its
  contents is a file whose reader has to guess, and a correction that lost its origin period is declared into
  the wrong quarter with nothing saying so. Figures come out exactly as the return computed them, signs
  included; a second arithmetic path is a second chance to disagree with the documents by cents nobody could
  trace. A correction past the jurisdiction's window **stops the export** instead of quietly dropping the
  line, because a line that vanishes is indistinguishable from one that was never owed.

- **A self-billed document now says that it is one.** Both e-invoice formats already carried the type code
  for it; neither carried the statement. Read without it the document looks like an ordinary invoice issued
  by the wrong party, and its recipient has no way to tell it was written under an arrangement they agreed
  to. The note is emitted only on that branch — every other document renders exactly as before — and the
  wording lives in the translations, because whose statement it is depends on where you are.

- **The distance-sale threshold now has something to count.** The monitor shipped with no counter bound
  behind it, so it would have reported "under the limit" forever — the safest-looking wrong answer there is.
  The shipped counter is a **projection over the invoices**, never a stored total, so the figure can be
  rebuilt at any time and come out the same; a stored running total drifts the first time a document is
  corrected, in the direction nobody checks. One pot for every destination, because a threshold applied per
  country would let a seller spread the same turnover over five of them and stay under it forever while
  owing tax in all five. Business sales and corrections stay out, and the sale that takes the total past the
  limit is **named**, not merely counted — that sale is itself taxed at the destination, so the caller has to
  know which invoices fall on which side of the line.

- **A short buyer receipt now looks like one.** The tier a receipt was issued at is frozen on it — decided
  from that document's own gross, so a monthly subscription stays a short receipt naming nobody while the
  same contract billed once a year crosses the threshold and pulls the buyer in. The rendered document
  follows: a short receipt states the gross **with its rate in one sum** and prints no recipient block at
  all, where a full invoice shows the split and both parties. Splitting net from tax on a deliberately
  anonymous document made it read as an invoice with its recipient missing, which sends a reader looking for
  something that was never supposed to be there. A document that states no tier — every single-seller
  invoice — renders exactly as before.

- **A merchant is told when the platform changes their tax standing on its own.** Crossing a turnover limit
  changes what they owe and what their own documents have to say, from a date the platform picked out of its
  own records — and until now the change fired an event nobody turned into a message. The notice states three
  things because a merchant cannot act on fewer: what changed, from when, and that the amount reaching them
  goes up because tax now travels with it. That last line is what keeps the first larger settlement from
  reading as an error. Only the automatic case notifies: a merchant who declared it themselves has already
  heard, and noise is what makes the notice that matters get ignored. Shipped in every locale the package
  carries; silent, never failing, where a merchant cannot be reached — the status change is the thing that
  must not break over a delivery detail.

- **The routing guard now has a door to stand in.** The rule pairing a money flow with a seller-of-record
  posture was written and fully tested, and it had **no call site** — nothing in the package ever assembled a
  routed payment, so there was no moment at which it could fire. A guard with no call site reads exactly like
  a guard, which is worse than not having one: the tests are green, the rule is written down, and the wrong
  pairing still reaches the provider. Routed payments are now assembled in one place, the check happens
  there **before** the merchant is even looked up, and the posture is resolved rather than accepted from the
  caller — a caller that could pass one would pass the one that makes its own pairing legal.

- **Two figures that had quietly stopped telling the truth.** The `held` bucket of the marketplace balance
  reader answered zero unconditionally — correct only while nothing could withhold a payout, which stopped
  being true when buyer protection landed. It now reports what protection is still sitting on, and that
  amount is **taken out of `available`**: a merchant reading "available" is reading what they can be paid,
  and money a clock is still on is not that. And a refund correction assumed which side of an uneven
  commission split keeps the odd minor unit. On an installation that hands it the other way the correction
  came back **a cent off the sale it was correcting** — on every uneven split, with both documents adding up.
  The direction is now frozen on the settlement beside the rate it belongs to (`commission_residual`), and an
  older settlement that never recorded it falls back to what the installation does today rather than to a
  constant.

- **Who a platform has to report is decided by one rule, and the rule says why.** Both directions of that
  decision carry a penalty — failing to report is an offense, and reporting somebody the law leaves out is
  another one and a data protection breach besides, since it hands a person's details to an authority with
  nothing entitling anyone to them. So "when in doubt, report" is not the careful side; it is the second
  mistake. Every verdict therefore carries a **ground from a closed set**, storable and countable, because a
  classification nobody can account for is worth nothing when somebody asks. Where a regime exempts
  small-scale sales of goods, both edges have to hold at once and the **comparison operators are
  configurable** — a statute that exempts whoever "does not exceed" a figure exempts the seller standing
  exactly on it, and a strict comparison would report them. The exemption belongs to the goods branch alone:
  three commissions worth a year's rent are reportable, a thousand standardized downloads are not. With no
  reporting profile bound, nobody is reportable.

- **Vouchers, off by default.** Money paid now against a promise redeemable here — and nothing else. There is
  no method to top one up, cash one out or hand one to somebody else, and no setting that adds one: those
  absences are what the instrument IS, and a guard test asserts them so they stay properties rather than
  accidents. Redeeming always names the sale it went towards, which is what makes it a way of paying rather
  than a way of getting money back; spending more than is left is refused, because the difference would be
  lending. Expiry takes what is left to income, never to turnover. The instrument type — whether the tax
  falls at issue or at redemption — is **frozen on each voucher when it is sold**, so a later configuration
  change cannot re-decide a supply already made. A rolling volume counter reports what has gone into
  vouchers and warns before the reporting threshold rather than on the day it is crossed; it decides nothing,
  because filing anything is a decision a person makes.

- **Buyer protection: a payout that waits for the buyer.** The money is never held by your application — it
  stays with the payment provider throughout, and a release is an instruction to it. Two clocks run: the
  buyer's silence becomes consent after a while, and a dispute **stops** that one, because auto-releasing
  money a buyer has actively objected to is the single outcome the arrangement exists to prevent. The second
  cannot be stopped, because the provider will not delay a payout forever — past its limit the money goes out
  whatever the settings say, so a decision has to exist before then. On a disputed hold that deadline does
  **not** decide the dispute; it marks the hold as needing a human. Two configurations are refused up front
  rather than at the first sale: a decision deadline that does not finish inside the provider's limit, and an
  account type that pays out on the provider's own schedule and so cannot be held back at all. Run
  `billing:protection:advance` daily; a missed day is harmless, since the deadlines are dates.

- **A subscription term can be cut into the periods it is actually billed in.** Each is a supply in its own
  right, so the periods have to meet exactly — one ending on the day the next begins double-counts a day, one
  ending early leaves a gap, and neither shows in any total. The split allocates the whole rather than
  dividing it, so twelve periods still add up to the contract instead of drifting a few cents away from it.
  Alongside it, `billing.tax_point_on_receipt` (default off, so nothing changes) says whether a prepayment is
  taxed when the money arrives or as the service is rendered: on a prepaid year those are eleven months
  apart, and nothing on the documents themselves says which was meant.

- **A correction now says WHY the taxable amount changed.** Money handed back and money that will not arrive
  produce identical figures in identical periods, so nothing in the amounts can tell them apart afterwards —
  and they differ in what happens next. A repayment is final; a write-off is a judgement about the future,
  and a payment that arrives anyway puts it back. The new `tax_base_change_reason` carries the distinction on
  the document, and provisional write-offs can be listed per merchant or across the platform, oldest first,
  because a write-off nobody can list is one nobody reviews. Fail-closed on silence: a correction that states
  no reason is not treated as reopenable.

- **The bookings that depart from the ordinary chain.** A voucher whose tax is not fixed until it is used is
  money held against a promise, not turnover — issue takes it to a liability with no tax, redemption **splits**
  the debit between what the buyer paid now and what the voucher settles so the whole sale reaches revenue
  (a voucher is a way of paying, never a discount on what was sold), and expiry keeps it as income, because
  no supply was ever made. Pass the movements to the exporter alongside the invoices; empty by default, so an
  existing call produces the same batch. A sale taxed at the buyer's country now books to that country's own
  revenue account where a chart of accounts is selected — on the domestic account an automatic posting would
  derive **domestic** tax from foreign-VAT turnover, which reconciles perfectly and is wrong. And a correction
  reverses the **whole** settlement chain rather than one leg of it: reversing one moves the margin
  permanently, leaving a profit in the books that nothing ever caused.

- **Merchant payables have a sub-ledger and a month-end reconciliation.** Every merchant books against one
  liabilities account and the platform keeps the per-merchant detail — balances and the documents each is
  made of — because the number of merchants is unbounded and an account each fills an accountant's master
  data with rows nobody there will ever open. The close then compares the two, and it reads the **exported
  batch** rather than recomputing from the documents the sub-ledger already read: recomputing would compare a
  figure with itself, and every rule the two sides share would cancel out. A difference is an error state
  with a figure attached, not a note; merchants in debt are listed apart from the total, because netting one
  away hides both the shortfall and whichever payable canceled it. Set
  `billing.datev.person_accounts.mode` to `individual` for the installation whose accountant expects an
  account per creditor — same bookings, different account, numbers allocated on first use and kept.

- **A reverse-charge settlement can carry the transaction key that qualifies it.** Configure
  `reverse_charge_transaction_key` beside a chart's reverse-charge account and the booking emits it together
  with the rate it is assessed at — the two travel as a pair, because the key alone does not say at which
  rate, and the import refuses one without the other. A key that cannot be one (zero above all, which the
  format description names as not permitted) is refused where the chart is read, so it can never reach a
  file. **The record widens only for a row that has something to put there**: with no key configured — which
  is every single-seller install — the batch keeps the fields it always had, and the caption row keeps
  declaring exactly the columns the bookings carry.

### Removed

- **`CreatorTaxStatusUnclear`**, an exception nothing threw and nothing should have. It documented itself
  as the mechanism protecting against a settlement document for a creator whose taxation nobody has
  established — and that protection is real, complete and tested, but it is a **hold**, not a throw:
  `InboundTaxMatrix` answers `hold()`, `SelfBillingEngine::plan()` returns before a number is drawn, and
  the collective engine skips the transaction. Three surfaces described a throw that never happened. A hold
  is also the *right* mechanism rather than a lesser one: a creator who has not yet declared is a routine,
  expected state, and an exception would abort a whole month's collective document for everyone in it. The
  reasoning the class carried now lives on the matrix branch that performs the hold, pinned by a test so
  deleting it is a decision rather than a tidy-up. Never released — the class was added after v0.9.0, so no
  published version loses anything.

### Fixed

- **A tip is now reported by what it was paid on, which is the only thing that can decide it.** The product
  taxonomy states a tip's reportability as *delegated* — everything about a tip belongs to the sale it
  accompanied, whether it is reportable included — and the reporting rule answered from the archetype alone.
  Every tip therefore landed in the standardized branch, so a creator whose tips were on commissioned work
  was never reported, however much came in. That is the direction the statute sanctions, and nothing about it
  looked wrong from the inside: the figure was right, the line was there, and "a tip is not reportable" reads
  as a reasonable sentence.

  Two files each stated the same rule, each was internally consistent, and nothing read them together. They
  are now held together by a test that compares the delegating set the taxonomy declares against the one the
  activity resolves — including the cell that decides a filing, by name, so delegating some other cell cannot
  satisfy it.

  A reporting line is now the **effective** archetype, merged, rather than one line per document grouping.
  That matters because one branch of the rule is a **threshold**: asked per line, it measures each fragment
  on its own, so a seller over both edges — 33 sales at 205,008 against edges of 30 and 200,000 — split into
  goods and tips-on-goods came back exempt *twice* and was never filed. Merging also fixes a second, quieter
  loss: two groups with no archetype were held in one variable and the second replaced the first, so the
  lines stopped adding up to the period's own total while each one still looked right.

- **A receipt now records WHOSE charge reference it settles, and a redelivery is recognized per provider.**
  A charge reference is unique only per payment provider — `billing_merchant_charges` says so with a
  composite unique key. Documents stored the reference without the provider and three places treated it as
  a key anyway, so on an installation with two drivers a sale whose reference collided with another
  provider's would find *that* sale's document and issue none of its own: no receipt for the buyer, no
  number drawn, and nothing red. A repeat and a collision are indistinguishable to a query that cannot see
  which provider either belongs to.

  `billing_invoices.provider` is now frozen beside `settled_charge_reference` by the receipt and settlement
  issuers, carried across by a correction, and read by the redelivery lookup, the correction lookup and the
  duplicate-receipt preflight. A caller that names no provider still matches on the reference alone — sound
  on a single-driver installation and nowhere else, which is why it is documented rather than defaulted
  silently. Rows written before this recorded no provider and are not given one; they group together,
  because they genuinely share one namespace.

  Latent until now, because exactly one driver ships. It would have armed itself the day a second one did.

- **The webhook mapper's class docblock said `payment_intent.*` was deliberately not mapped, while the
  file answered three of those events.** The sentence was true when written and stopped being true when
  the routed-charge lane landed; nothing connected the two. "Not mapped" is a claim a consumer plans
  around — they build their own listener, and then both fire. The docblock now states what is actually
  excluded (every invoice-driven payment, which is the whole customer-facing surface) and names the
  routed arms that are answered.

- **The two test fakes claimed to be separate while sharing a contract.** `Billing::fake()` and
  `Billing::fakeMarketplace()` both bind `MerchantOnboarding`, so whichever is called **last** owns that
  binding for the rest of the test — a suite that calls both and asserts on the first sees an empty
  recording, which reads as "it did not happen" rather than as a clash. Nothing throws. The overlap
  itself is legitimate (onboarding belongs to both surfaces); what was wrong was the docblock calling
  them "deliberately separate". It now names the shared contract, states that the last call wins, and
  says what a test author should do about it.

- **A foreign-currency booking left the DATEV rate field empty, so the amount was posted at face value.**
  DATEV's rule is that a row whose WKZ Umsatz differs from the batch's base currency must state the rate
  or the base amount; a row stating neither is either rejected or booked into a base-currency account at
  face value. 500.00 PLN posted as 500.00 EUR overstates revenue roughly fourfold, and every figure
  involved looks plausible. The row now carries the rate **frozen on the document** (field 4), taken from
  the document layer specifically — not a rate re-derived at export time, which would let the books state
  a number the document does not. A base-currency batch is unchanged byte for byte: DATEV wants those
  fields empty there, so filling them would itself be the defect.

- **Both e-invoice writers named three document type codes where the shared trait emits four.** The
  omitted one was `384`, an amendment — and a cancellation (`381`) and an amendment are two different
  documents that a tax authority tells apart by this code alone, so a reader who trusted the sentence
  would have believed an amendment renders as a cancellation. The trait's own docblock has opened with
  "Four codes" the whole time.

- **A routed sale bought through hosted checkout was never written down.** The lane opened a Stripe
  Checkout Session with the merchant's destination on it, the money moved and the merchant was paid —
  and no row was ever recorded. `payment_intent.succeeded` has been mapped to a confirmation for
  releases, but a confirmation *settles an existing row* and returns when there is none, so every
  hosted routed sale settled into silence. What that cost is not a missing log: the reversal caps, the
  earnings counter and the small-business threshold verdict are all computed from that table, so those
  sales were invisible to every rule the money is supposed to obey afterwards. The lane now records
  the sale as `pending` when the session opens, keyed on the PaymentIntent — the id the confirmation
  arrives under, and available on the session the moment it is created, so the row exists before any
  webhook can fire and the two halves cannot race. A single-seller installation is unaffected: no
  merchant is resolved, so no row is written and the session stays byte-for-byte what it was.

- **One-time add-ons were sold untaxed on installations that delegate tax to the provider.** Under
  `billing.tax = provider` (or its alias `stripe`) the subscription lane asked Stripe to compute VAT and
  the one-time add-on lane never did. The same installation-wide setting produced two opposite answers.

  There was no symptom. Under a provider mode the package computes nothing on purpose — the local
  calculator returns zero, correctly, because the provider is meant to do it — so a lane that also never
  asks the provider produces no tax at all. Stripe opened a valid session, the money moved, the webhook
  granted the add-on. The absence surfaces at a VAT return, or never. It is the same failure the package
  already shipped once, when the `stripe` alias missed a literal comparison.

  The lane now sets `automatic_tax`, `tax_id_collection` and `customer_update.address = 'auto'` under
  exactly the same modes as the subscription lane, read from the mode classification rather than a
  literal so the alias cannot be missed again. Both branches are pinned by tests, including the
  counter-case: under a local mode nothing is sent, or the buyer would be charged tax twice.

  **If you run a provider tax mode, your add-on checkouts will now collect VAT where they did not
  before.** Whether the buyer pays more depends on the Stripe price's `tax_behavior`: an *exclusive*
  price adds tax on top of the amount, an *inclusive* one carries it inside the amount already shown.
  Check that setting on your add-on prices before deploying.

- **A buyer receipt for a one-time sale can no longer be issued twice.** Receipts deduplicated only on the
  billing-cycle key, so a document with no period went straight to the write — correct while the only caller
  was the subscription cycle, and wrong for a one-time sale, which has no period and *is* redelivered when a
  provider retries a webhook. A second delivery drew a second number out of a gapless series, and a gap
  there cannot be healed by repeating the operation.

  A periodless receipt now repeats on its charge reference, matched on the value the sale itself states.
  With neither a period nor a reference the write still stands — there is nothing to recognize a repeat by,
  and collapsing unrelated sales would be the worse error.

- **Changelog assembly put the new release above `[Unreleased]` instead of below it.** The anchor matched
  any `## [` heading and `## [Unreleased]` is almost always the first one, so a release assembled from
  fragments landed on top of it — which reads as *these entries are older than the released ones*, the
  opposite of true. It now anchors on the version digit.

  Also: a fragment written the natural way puts a blank line after its own heading, and the assembled
  heading carries one too, so a naive join produced two. That is not cosmetic — some Markdown renderers
  read the wider gap as the end of the section and start a fresh block, dropping the entries out of the
  heading they belong to.

  Both were found by assembling against this repository's own changelog **before** the first release that
  would have used it, and both are pinned by a test that fails when the old behavior is restored.

- **Five defects an adversarial pre-release review found, all in the reporting and document paths.**

  *The reporting roster and the figures asked different questions.* The roster filtered on the issue date
  alone while the counters place a correction by the configured attribution — under the shipped default, by
  the date of the document it credits. A seller whose only activity in a year was a correction of an older
  settlement therefore entered the roster and received a row of **zeros**, which states that they received
  nothing. The window rule now lives in one place, `InvoiceRecord::placedIn()`, and both queries read it.

  *A seller whose model was hidden or gone dropped out silently.* `find()` cannot tell a soft-deleted record
  from a missing one, and the run skipped both — removing a whole year of activity from a filing with
  nothing to show for it. Global scopes are now taken off, so a closed account is still reported; a record
  that is genuinely gone raises `SellerModelMissing` instead of being passed over.

  *The provider-scoped document lookups preferred the older row.* Admitting rows that recorded no provider
  is what carries the upgrade, and it also made a legacy row a candidate for a provider it may not belong
  to — with only an id ordering, the older row won against the document that names the provider outright.
  The exact match is now ordered first, and the redelivery lookup has a deterministic order at all, which
  it lacked while deciding whether to draw a number from a gapless series.

  *The duplicate-receipt check was blinded by its own key.* Grouping on the provider split two documents
  about one sale into two groups, and a mixed population of named and unnamed rows is the permanent state
  rather than a deployment artifact: only the routed one-time lane records a provider. The column is out of
  the key again — this check warns rather than blocks, so a false positive costs a look, while a missed
  second numbered document costs a gap in a series that must not have one.

  *`provider` was mutable while the reference it belongs to was frozen*, so an update could re-point a
  numbered tax document at another payment processor. It is frozen with its other half now.

- **Realtime toasts carry their severity again — they had been arriving without it.** The account
  hub's realtime bridge relays a broadcast toast to the browser as a `wirekit-toast` event, and it
  sent the severity under the key `level`. The toast region reads `variant`, and reads `level`
  nowhere.

  Nothing failed. An unknown key falls through to the default, so every toast rendered as `info`
  whatever it was — and a failed payment, which should interrupt, was announced on the polite live
  region instead of the assertive one. Both the color and the screen-reader urgency were wrong in
  the same direction, and neither was visible as an error.

  The severity now goes out as `variant`. If you listen for `wirekit-toast` with your own handler
  rather than the toast region, read `detail.variant` — `detail.level` is gone. The **inbound**
  broadcast payload is unchanged (`{ message, level }`): that one is this package's own contract,
  and the bridge is the seam that translates between the two.

- **A badge intent from another kit's vocabulary now fails the build instead of rendering as
  nothing.** The three enums that describe a status by color and text — subscription state,
  invoice status, metering policy — emit into a fixed set of intents. A value outside it does not
  throw; it resolves to no surface, leaving an unstyled badge whose meaning rests entirely on its
  label. Every case is now checked against that set.

- **A 7% creator input booked to the 19% input-VAT account, and the docblock promised the opposite.**
  `DatevExport::creatorInputTransaction()` handled the reduced rate in three of its four branches — the fan
  side and both reverse-charge arms split on it correctly — and then returned `CreatorInputDeStandard`
  **unconditionally** for a domestic input. So a settlement to a creator whose supply carries the reduced
  rate (books, e-books, cultural supplies) posted its input VAT to the standard-rated account.

  The method's own docblock stated the refusal as fact: *„a reduced-rate domestic input has no confirmed
  account, so it stays unresolved and the export fails closed rather than book it to the standard account."*
  It was a promise, not a description.

  **It had a test, and the test was green.** The test nulled `creator_input_de_standard` and then asserted
  that a 7% invoice threw — which measures the missing account, not the rate, and would have passed
  identically had the reduced-rate branch never existed. Meanwhile the shipped charts *do* map the standard
  account, so in every real installation the fall-through was reached. A test named after behavior it does
  not exercise is why this survived a prose sweep that read the docblock and believed it.

  `DatevTransaction::CreatorInputDeReduced` now carries the case, and it ships **unmapped in both charts** on
  purpose — the refusal is the feature. An operator who has agreed an account with their accountant
  configures `creator_input_de_reduced` and it books. A guard asserts the shipped charts keep no default for
  it, because mapping it „for completeness" later would reinstate the defect wearing a different number.

  **Who sees a change:** an installation that settles with reduced-rate domestic creators and has not
  configured the new key gets a failed export where one previously succeeded — wrongly. Everyone else is
  unaffected; the standard-rated path is untouched.
- **The monthly average rate had no writer, and the German profile handed it to every domestic conversion.**
  `ExchangeRateBasis::MinistryMonthlyAverage` was read by `DatabaseExchangeRateSource` as a row of its own —
  and `ExchangeRateImport` has only ever written the daily series, under the two central-bank-at-a-date
  rules. So a domestic conversion in a foreign currency asked for data that could not exist and threw
  `ExchangeRateUnavailable` every time.

  It could not be fixed by writing the importer either: the ministry table is published behind a page that
  refuses automated retrieval, and it is **an aggregation of these same central-bank reference rates** in the
  first place.

  So the average is **computed** from the daily series the package already imports — the arithmetic mean of
  the month's published rates, which is what the ministry table *is*. It averages what was published rather
  than dividing by the calendar: the reference rate appears on business days, and dividing by 30 would be
  wrong every month, slightly, in whichever direction the missing days leaned.

  The case is renamed `CentralBankMonthlyAverage`, after the source rather than the place of publication —
  the old name is what made a basis nobody could supply look supplied. **Rows carrying the old
  `ministry_monthly_average` value are not read by anything**, because nothing ever wrote one.

  Three tests that pinned the row-lookup are rewritten rather than deleted: the questions they asked (any day
  of the month asks about that month; next month is not a late answer for this one; two rules stay apart on
  one day) are still the right ones — only the mechanism beneath them changed.
- **The CI clone plugin ran on a moving `latest`, in four lanes.** Naming the image at all is what opts a
  lane OUT of the server-side pinned clone plugin, so an untagged clone-plugin image trades
  a pinned default for whatever `latest` happens to be. Nothing here watched it: the renovate container
  freeze is anchored on the docker datasource, the pin scan only knows postgres/mysql/php-pcov/playwright,
  and the version guards skip a tagless image by construction.

  The failure mode is the quiet one, too — the lanes that name it do so precisely to get **full history**,
  which the state-dedupe reads with `git log --since`, and a dedupe that cannot answer **skips** rather than
  going red. All four are tagged now; the upstream template found two.

  And the evals lane constrains `anthropic-ai/sdk` to `^0.40.0`. A `suggest` entry cannot carry a constraint
  and there is no lock, so that one line is the only place the version is decided and it resolved fresh on
  every run — of a pre-1.0 package where every minor is the breaking slot, in the one lane that costs money.
- **The money side and the document side rounded the odd minor unit in opposite directions.** A correction
  reads `commission_residual` off the settlement it froze; `MerchantCharge::frozenFee()` read whatever the
  installation is configured for **today**. So a charge priced before somebody changed
  `billing.marketplace.fee.rounding` was reconstructed the other way round from the document correcting it —
  two truths about the same cent, on every uneven split.

  It matters because a clawback is **not a fresh split**. It is a difference against the original
  (`merchantHolds − payoutOnRemainder`), and two halves rounded the other way from each other produce a
  difference carrying the rounding error of both.

  `billing_merchant_charges.fee_residual` freezes the direction beside the rate and the flat part, written by
  `RoutedChargeLedger::record()` and read by `frozenFee()`. A row from before the column falls back to the
  **configured** direction — never to the enum default, which is a value nobody chose and is the wrong one on
  every creator-first installation.

  Three tests, two of which fall against the old code. The third asserts the money side and the document side
  **against each other** rather than against a literal, so the day either changes how it resolves a
  direction, it fails instead of quietly going back to disagreeing.
- **A routed subscription on the shipped defaults sent a charge shape the package's own table forbids.**
  `StripeCheckout::routing()` checked the **configured** charge type and then assembled a payload that
  ignored it. The configured default is `separate_transfer`, which the table permits under the
  `platform_deemed_supplier` posture — so the guard passed. But the payload it went on to build carried
  `transfer_data.destination`, which *is* a destination charge, and `destination` +
  `platform_deemed_supplier` is precisely the pairing `charge_type_by_posture` does **not** allow.

  Nothing errored. The money moved straight to the merchant while the documents about to be issued named
  the platform as the seller — a contradiction that surfaces in an audit rather than at the till, and one
  the file's own comment described without the code enforcing it. A guard on the configured half cannot see
  a broken seam.

  The one-time lane and the payment rails already refused a separate transfer here, for the reason that
  applies identically to a subscription: the merchant's share moves in a **second** provider call that can
  only be made once the payment has succeeded — a webhook away, long after the session has been opened. The
  subscription lane now refuses it too, so the configured type and the emitted one are the same statement.

  **Breaking, and deliberately loud:** an installation running `billing.marketplace.enabled = true` on the
  shipped defaults now receives `MarketplaceUnsupported` instead of opening a session. Two ways forward,
  both stated in the exception: set `charge_type` to `destination` **and** a posture the table permits for
  it (`seller_of_record` or `platform_intermediary` — and the Art. 9a rebuttal must genuinely hold for the
  former), or stay on `separate_transfer` and move the share through `RoutedPayment`. With
  `marketplace.enabled = false` — the default — nothing changes at all.

- **Two sentences shipped today were already wrong by the evening, and the sweep that found them is the
  point.** `UsageMeter::remaining()` called itself *„the one place this sum is computed"* while `reserve()`
  still carried its own copy under the row lock — the claim was written in the same change that left two.
  And `StripeProrationStrategy` counted *„the eight sites that DO rethrow"* where there are ten; the
  sentence was written after the tenth existed.

  The sum is now genuinely one arithmetic: `UsageMeter::availableFrom()` is pure and takes the four numbers,
  so both callers share the formula without sharing a read — `reserve()` computes from rows it holds under a
  `FOR UPDATE` lock, and re-reading them through a helper would drop the lock's whole point. Two tests pin
  the agreement the docblocks had been claiming: one drives the arithmetic over a grid of allowances, holds
  and prepaid balances and asserts the old form is *strictly more generous* exactly where the free allowance
  is exhausted, and one drives the reservation and the point-in-time answer end to end against the same
  owner. Both fall against the old formula.

  The count is **removed** rather than corrected. A tally of code in prose is a fact with a clock on it, and
  the shape — „every other catch in this driver that meets a 429" — says the same thing and cannot go stale.
- **A shipped comment named a config key that does not exist, and now a guard says so.** `UsageBacklogStalled`
  told readers the event fires once the oldest pending rollup is older than `billing.usage.stall_hours`. The
  key is `billing.metering.stall_hours`; nothing has ever read the one the comment named. An operator looking
  for the threshold would have found nothing and had no way to tell whether they were looking in the wrong
  place or the feature was not configurable.

  `ConfigKeysNamedInCommentsExistTest` now holds it for every `billing.…` a comment in `src/` names, read
  from comment TOKENS so a key in a string literal is never mistaken for a claim about one. It knows the
  three legitimate shapes — the key itself, a section that holds no value of its own, and a leaf under a
  section the adopter populates — and exempts, per site and with a written reason, the one shape no scan can
  recognize: a key named **because** it does not exist, to record a removal or to warn the next person off it.

  **What it does not catch is stated in the file**: a comment naming a real key that is the *wrong* real key
  for the sentence around it. That is a question about meaning, and no scan answers it.

- **Three shipped sentences said the Connect money path was not built. It ships.** `StripeMerchantTransfers`
  implements `MovesMerchantShare`, the Stripe provider binds it unconditionally, and `RoutedPayment` calls
  it — while `AddonCatalog`, `StripePaymentRails::transferOf()` and `MarketplaceUnsupported` each told a
  reader the later transfer call *„does not exist in this package yet"*.

  `MarketplaceUnsupported` contradicted itself in the same file: the factory below had already been renamed
  off *„not implemented"* precisely because the gap had closed, with a note saying so, and the paragraph
  above it was left standing.

  The correction is narrower than „it exists now", and that is the part worth reading. **The rails still
  cannot make the call** — it can only go out once the payment has succeeded, which is after `charge()` has
  returned — so the refusal there is permanent and right. What was wrong was attributing the gap to the
  package rather than to the rails. And a **platform-cataloged** add-on is already routed to a creator's
  connected account (`application_fee_amount` + `transfer_data.destination`); what is open is whose
  *catalog* the item comes from, not whether the money can reach them.

- **A hybrid ZUGFeRD document could name two different sellers.** `ZugferdPdfInvoice` embeds
  `ZugferdCiiInvoice`'s XML — which reads the frozen per-document `seller` snapshot — into the PDF
  `InvoiceDocumentRenderer` produces, and that renderer read `config('billing.company')` outright. On a
  self-billed settlement the machine-readable half named the **creator** and the visible page named the
  **platform**, in one file, about the one fact the document exists to state.

  A hybrid format exists so that a person and a machine read the *same* invoice. Two answers to „who
  supplied this" is not an imprecision — which one counts depends on which software opens the file. The
  plain PDF was affected too, and there the contradiction is invisible: the document simply names the wrong
  seller, with nothing to compare it against.

  Both halves now resolve the seller the same way: the frozen snapshot, falling back to `SellerPartyResolver`.
  **A document with no snapshot is byte-identical** — the resolver's default is the platform company, which
  is what every single-seller invoice rendered before.

  Three tests, two of which fall against the old code. The third is the seam: it reads the seller out of the
  XML and asserts the visible half names that one, so the day either side changes how it resolves a seller,
  this fails rather than quietly going back to disagreeing.

  Found while correcting **prose** — two docblocks claiming the seller is always the platform. The sentences
  were wrong, and following them found the code that was.

- **The erasure docblock promised to delete provider API keys. There are none, and the direction of that
  error is what made it worth fixing.** `BillingEraser` told an operator that the owner's provider API keys
  *„go FIRST and unconditionally"* — describing a purge of something no table in this package holds. It
  stores no secret of any kind: the merchant row carries an account **reference** (`acct_…`), which names an
  account and does not open one.

  Read by somebody answering a right-to-erasure request, that sentence is the expensive way to be wrong: they
  believe the erase reached credentials **their** app stores, and stop looking. Live payment credentials left
  behind after an erasure is exactly the failure the sentence was written to prevent, and the sentence was
  what would have caused it.

  It now says the opposite, and says what to do instead: **if your app stores a merchant's API keys, erasing
  them is your job**, and `BillableAccountDeleting` — dispatched first, outside the transaction, so a listener
  can make a provider call — is the hook for it.

  `NoStoredCredentialsTest` holds the claim as the schema grows. It bans column shapes that can only ever be
  a secret and deliberately not the ones that are identifiers — `account_reference`, `stripe_id`,
  `payment_reference` — because a guard that fired on those would be switched off within a week, and then it
  would be holding nothing.

- **The webhook dedup key was documented as a pair and has been a triple since a migration replaced it.**
  `BillingWebhookEvent` and `WebhookEventLedger` both said deliveries are unique per `(provider, event_id)`.
  The index is `(provider, account_reference, event_id)`, which is also what `record()` keys its
  `firstOrCreate` on.

  The difference is the Connect case. One install receives deliveries for the platform's own account and for
  every connected one, and the reference — empty for the platform's own — is what stops two accounts'
  deliveries being read as redeliveries of each other. A reader who believed the pair would conclude the
  opposite of what the schema does. A single-seller install is unaffected either way, which is why nothing
  ever felt wrong.

  And `SyncPlanFromSubscription` described its create-race as *„the loser's unique violation reruns the
  effect"*. There is no violation and no rerun: `insertOrIgnore` makes the loser's insert a no-op, and it
  re-reads under the lock and orders its own event against whichever row won — finishing its own pass rather
  than being re-driven. The method's own docblock had it right; the class docblock above it did not.
- **A reverse-charged settlement chose its own tax category, and the two choices name different provisions.**
  `EnInvoiceTaxCategory` splits `K` (with `VATEX-EU-IC`, an intra-Community supply of **goods**) from `AE` (a
  **service**) on the archetype alone. A per-transaction self-billed settlement could be issued without one,
  and the renderer read that null as „not goods" and printed `AE` — right for a digital supply, a wrong
  statement of the provision for every other, and arrived at without the document ever being asked. The
  recipient's obligation to account for the tax was stated under a provision nobody chose.

  `SelfBillingEngine::settle()` and `issue()` now take an optional `TaxArchetype` and **refuse** a
  reverse-charged settlement that carries none, before a number is drawn — a burnt number in a gapless series
  is not recovered by retrying.

  **Two limits, and both are the point.** Nothing changes for a plain **domestic** settlement, where the
  category comes from the rate and the exemption and the archetype changes nothing a reader can see. And
  nothing changes for the monthly **collective** document, which deliberately carries no archetype: it covers
  many supplies, and a creator who sold a download and a commissioned work in the same month has no single
  one — there the absence is the answer. The refusal therefore sits in `settle()`, the single-supply path, and
  not in `plan()`, which the collective run calls once per transaction.

  **For adopters:** a caller that issues per-transaction settlements for creators outside its own country now
  passes `archetype:`. A domestic-only platform is unaffected.

- **The consumer-rights gate needs two conditions, and everything that described it named one.** Setting
  `billing.consumer_rights.profile` arms the withdrawal gate; a **classified archetype** on the work is what
  gives it something to act on. `ContentGrants` returns on a null withdrawal type *before* the profile is
  ever read, so a work nobody classified is provided with no consent recorded — no exception, no log line,
  and the classified add-on beside it correctly refused. Same money, same buyer, opposite outcome, decided by
  a config key.

  That is not tidiness. Without the recorded double declaration the buyer's right of withdrawal does not
  extinguish, so a refund inside the window is **owed rather than granted** — and where the platform is
  seller of record, that is its own money.

  Two docblocks in the shipped tree and two documentation pages promised the opposite: that an unclassified
  work under an active profile is *"exactly what an operator needs to see refused rather than silently
  provided"*. They now describe what the code does. **`billing:doctor` reports the gap**: with a profile
  active and content ownership on, it names every configured add-on that hands over a work and carries no
  `archetype`, and exits non-zero — and warns separately when the catalog cannot classify at all, where there
  is no per-add-on advice to give. It says nothing on an install with no profile, because a diagnostic about
  a regime you never opted into is one nobody reads.

  **What an operator has to do:** classify your works — an `archetype` key on the add-on, or your own
  `SuppliesProductArchetypes` — or the profile does not cover them. Whether the runtime should *refuse* an
  unclassified work instead of reporting it is a decision still open, because refusing would break every
  install that set a profile and classified nothing.

- **A subscription term beginning on a 29th, 30th or 31st swallowed a whole month, and the documents said so
  in a field a tax office reads.** `SubscriptionPeriodSchedule::monthly()` accumulated each boundary from the
  one before with Carbon's `addMonth()`, which **overflows**: 31 January plus a month is 3 March. A comment
  above the line promised the opposite — that it lands on the shorter month's last day, "the same answer a
  person would give" — and Carbon has never done that.

  Twelve periods from 31 January 2026 therefore started in 2026-01, 2026-03, 2026-04 … 2027-01. **No document
  began in February at all**, January appeared twice, the term ran three days past the year that was agreed,
  and from the second period the start date sat on the 3rd and never came back to the 31st. Nothing about it
  looked wrong: the periods still touched, and the shares still summed to the term exactly.

  Those dates are not display text. They are **BT-73/BT-74** on an XRechnung/ZUGFeRD invoice, and
  `SubscriptionCycleBilling` dates the invoice from the period start — so eleven of twelve periods declared
  their supply into the **wrong return**. `InvoiceRecord` freezes the period on issue, precisely because
  moving one re-declares a supply into another return, so a document issued with these dates can only be
  corrected by canceling and re-issuing it.

  Every boundary is now measured from the start date rather than accumulated, so the anchor day comes back the
  moment a month has one. The one-word fix — `addMonthNoOverflow()` on the accumulation — would have stopped
  the swallowing and then walked the anchor backwards forever from the first short month, which is why it was
  not taken.

  **The correct implementation already existed in the package**, unwired, as `Tax\SubscriptionPeriods`, with a
  comment naming this exact defect. `monthly()` now delegates to it, so there is one implementation instead of
  two, and the class is no longer waiting for a caller.

  The test that should have caught this was named after the case — *"lands on the shorter month's last day when
  the term starts on a 31st"* — and asserted only things that follow from the construction, whether the
  boundary overflowed or not. It asserts the boundaries now.

  **For adopters:** `ServicePeriod::key()` is `from/to`, and it is the idempotency boundary
  `SubscriptionCycleBilling` and `FanReceiptIssuer` recognize a repeat by. For a term that begins on a 29th,
  30th or 31st those keys **change**, so a re-generated schedule will not recognize documents already issued
  under the old boundaries and would issue a second document for a cycle already billed. Terms beginning on
  the 1st through the 28th are unaffected — no boundary there could overflow. Find the affected rows with
  `select id, settlement_period from billing_invoices where split_part(settlement_period, '/', 1) ~
  '-(29|30|31)$'`, and re-issue rather than re-generate.

- **A foreign-currency settlement note burnt a document number when no rate could be obtained.** The
  availability check ran only in the self-billed-invoice branch, so a settlement note reached `issue()`,
  drew its number, wrote the row, and only then failed on the freeze — the precise state the guard exists to
  prevent, and a burnt number in a gapless series is not fixed by retrying.

  The check hangs on the **currency**, not on the document type: a settlement note in SEK converts exactly
  as a self-billed invoice in SEK does. It now runs ahead of the branch, so no document type added later can
  slip past it.

- **Two config keys carried one idea, and the one an operator is most likely to find decided nothing.** The
  evidence record was stamped from `billing.tax_oss.required_signals` while the sale was gated on
  `billing.tax_evidence.required_signals`. Both shipped defaulting to `2`, so an unconfigured install was
  accidentally consistent and nothing could go red.

  Configured, they part company: a sale correctly settled under a one-signal standard was stamped „two
  required" beside its single source, and the row is immutable and outlives the documents built on it. The
  expression could not express `3` at all — a valid standard was written as `2`.

  **`billing.tax_oss.required_signals` is removed.** It was read by exactly one place, and only to describe
  a decision it had no part in. Both halves now read the same standard through one shared reader, and a test
  asserts the invariant that closes the class of defect: a record may never claim a bar higher than the
  number of sources it carries.

- **The routing resolver claimed a construction monopoly over a money-flow guard, and did not have one.** Its
  docblock said a `ChargeRouting` is „not constructible on the ordinary path except through here" and that
  this is therefore the one place the pairing is checked. The constructor is public and validates only the
  fee's sign; the class has no caller in `src/` at all; and `BillingAdmin` builds a routing directly — which
  is correct there, because its lane comes from the stored row rather than from today's configuration.

  The pairing is enforced at the three seams that actually reach a provider, each calling the guard before
  anything is sent. That placement is the property worth having, and it is now what the docblock describes.
  A guard whose promise sits in the wrong file is the one nobody misses at the next rebuild.

- **One config key decided which lane the money takes, and three readers disagreed about it — on both axes.**
  `billing.marketplace.charge_type` was read in the routing resolver, the hosted checkout and the one-time
  charge. Two fell back to `destination`, one to `separate_transfer`; two used `tryFrom()`, one used `from()`
  and so raised a raw `ValueError` at a place that takes money while the others shrugged and carried on.

  All three docblocks claimed to fall back to „the shipped default". **Exactly one was telling the truth** —
  the shipped default is `separate_transfer`, and the two that fell back to `destination` were silently
  choosing the other lane, the one that moves the merchant of record. That is the quieter mistake, not the
  smaller one.

  All three now read `ConfiguredChargeType`. An **absent** key answers with the shipped default; a key that
  is **present and unreadable** raises and names itself, because somebody typed that and meant it, and
  guessing there decides who the merchant of record is on their behalf.

  **This changes behavior for one input**, and the test that pinned the old one is adapted rather than
  deleted: a `charge_type` naming no lane used to route the sale as a destination charge and now refuses.
  An install whose key is absent, or names a real lane, is unaffected — and a typo there was already
  crashing with a raw `ValueError` on two of the three paths.

- **A Stripe outage took the subscription screen down instead of hiding one estimate.** The swap preview
  promised to degrade on „any Stripe read failure" and caught only `InvalidRequestException` — but a timeout,
  a rotated key, a 403 and a 5xx all descend from `ApiErrorException` **directly**. The four failures worth
  degrading for were exactly the four that escaped, while the one Stripe heals by itself (a 429, which does
  descend from it) was caught.

  The caller is a button, and the degraded state was already built and translated. So this was never „Stripe
  is down, nothing works" — it was one button taking a whole screen down while the text written for exactly
  this case sat beside it, never shown.

  A degraded preview is now **logged**. Silence would trade a broken screen for an invisible outage, and a
  rotated key is not transient: it stays until somebody notices.

- **A second lost dispute on the same charge booked no fee at all.** `RecordProviderFee` claimed its row on
  the **charge** reference while its own docblock, the migration and the docs all said „once per dispute".
  A charge can carry more than one — the provider's SDK says so, because only part of an order may be
  disputed — so the second dispute found the first one's row and wrote nothing.

  What went missing is not a duplicate: it is real money the platform paid, absent as an expense **and** as
  its reverse-charge position in the accounting batch.

  `ChargebackReceived` now carries `disputeReference`, which the Stripe mapper had been discarding, and the
  fee is claimed on it. It **falls back to the charge** when a caller names no dispute, so an app dispatching
  the event by hand keeps working and rows written before this stay findable. Everything else on the event is
  deliberately still about the charge — the correcting documents and the clawback act on the sale.

- **Units the customer had already paid for could be handed out twice.** The reservation enforced
  `max(0, max(0, included - used) + prepaid - reserved)`; the quota gate and the `QuotaExceeded` message both
  computed `max(0, included - used - reserved) + prepaid`, while their comments claimed to measure it „the
  same way the reservation measures it".

  The two agree until the free allowance runs out — and then the outer `max()` swallows the hold entirely,
  so it reduces nothing. With no free allowance left, two concurrent requests were both handed the same
  bought unit. That is the exact case the reservation documents as *„a unit the customer has already PAID
  for, sold twice"*. The cheap pre-check let requests through that the lock then refused, and the refusal
  quoted a remainder measured on the other formula.

  All three now call one method. `UsageGate` and `UsageRecorder` no longer take a `PrepaidLedger`: they had
  stopped reading it, and an unused dependency is a lie about what a class needs.

- **Two consecutive usage read-backs overlapped by a full minute, so the reconcile reported drift that was
  never there.** Stripe rejects a second-precise timestamp, and a subscription cycle's boundaries are
  second-precise — the window floored its start and ceiled its end, justified as „it can only widen by under
  a minute, which cannot pull in usage from an adjacent cycle".

  That is an argument about the **size** of the widening, not about where it reaches. Cycles touch — one
  period's end *is* the next one's start — so it reached into the neighbor by definition, and every meter
  event in the boundary minute was aggregated into **both** read-backs.

  Both ends now ceil, so the windows tile exactly. One minute of boundary usage is still attributed to the
  adjacent cycle — unavoidable under a minute-aligned API — but **once**, and always to the same side.
  Ceiling rather than flooring is the deliberate direction: a floored start reaches backwards, into a period
  that may already be closed, reconciled and filed.

- **A Stripe rate limit was filed as a permanent rejection, in nine places across the driver.** Stripe's SDK
  makes `RateLimitException` a **subclass** of `InvalidRequestException`, so the ordinary way to handle
  "Stripe says no" — `catch (InvalidRequestException)`, read as *gone, not ours, not classified* — also
  caught the one failure that means *ask again in a second*. Nothing about the catch said so, and nothing
  about the result looked wrong: each call returned its "nothing here" answer and the caller believed it.

  What that cost, per site: a seat quantity was not moved and the queued listener returned normally, so
  nothing re-drove it and Stripe kept billing the old count — and rate limits arrive precisely when many
  calls run at once, so the loss clustered exactly where the most seats were moving. A cancelation
  "succeeded" without reaching Stripe, including the `cancelNow` that account deletion makes. An erasure
  wiped the local customer reference while the customer was still alive at Stripe, so nothing could retry
  what it could no longer name. A payment method the customer owns was reported as not theirs. A price the
  go-live preflight never managed to read was reported as carrying no meter. And an invoice was reported
  missing.

  Every one of those sites now lets a 429 through to the caller that can retry it. Two places still swallow
  it, and both say why at the site: the upcoming-invoice preview and the swap-cost preview. Neither writes
  anything, marks anything done or caches the failure, and both sit behind a button on a screen whose job is
  to show an estimate — so the retry is the next render or the next click, and letting the 429 through would
  take a screen down over a condition that clears in a second. The swap preview additionally **logs**, which
  is what separates a deliberate degrade from a silent one.

  `RateLimitsAreNeverSwallowedTest` holds it, and reads the SDK's hierarchy rather than a list of class names:
  a catch is in scope precisely when a `RateLimitException` would land in it, so the guard follows if Stripe
  ever re-parents the class. A new catch must either sit behind the explicit rethrow or carry a written
  reason. Ten tests pin the behavior at the seams, each red before the fix.

- **The reporting counter's own comment told readers the opposite of what the shipped default does.** It
  said a correction lands in the quarter of the reversal and that "nothing here reaches back into the
  original period" — which describes `reversal_period`. The default is `original_period`, and the query
  reaches back through the credited row on every correction.

  This file ships, so the sentence was read by consumers of the published package while their installation
  did the other thing. Prose is the one part of a package no test can contradict, which is exactly why it
  has to be measured against the code rather than remembered from the design it was written for.

- **The money-atomicity golden test ran on a charge shape production had stopped writing.** Its fixture took
  the commission on the **gross** (11.90); `RoutedPayment::record()` has taken it on the **net** (10.00)
  since that defect was corrected — and the production comment names this very test as the one case where
  the two bases coincide. So the test that exists to prove the platform is never out of pocket was measuring
  something else.

  Pulled onto the production shape. Exactly one assertion moved: the lost-dispute position is **-25.00**,
  not -26.90, and the difference was never a missing tax correction — it was the commission on the buyer's
  tax that no longer exists. Every other assertion in the file passed unchanged, which is what says the
  fixture change was faithful.
- **An admin refund on a routed sale read the charge row twice, while a comment beside it said it read it
  once.** The pricing side resolved the row, then the routing side resolved it again from the reference — two
  reads, two answers. A reversal landing between them would price this refund against one state and route it
  by another, and both figures look entirely reasonable afterwards. The routing is now built from the charge
  already in hand, so the second read is not merely avoided but unrepresentable: the method no longer takes a
  reference it could look one up with.

  The comment is the part worth naming. It asserted the invariant the code broke, which is the worse of the
  two ways to be wrong — a reader checking that property found it stated rather than held.

- **The chargeback event reference said the package leaves the money side alone, which stopped being true
  the moment the clawback effect shipped.** It also counted two registered effects where there are now four.
  A reader following it would have written their own share-reversal against `ChargebackReceived` and reversed
  the merchant twice — under an idempotency key the provider has never seen, because the key belongs to the
  attempt row this package writes. The page now says where an app's own payout reversal belongs
  (`MerchantTransferReversed`, which reports what actually moved) and names both double-booking hazards.

- **A mutation run that lost a shard threw away the runtimes of the shards that survived.** The wall clock
  of a shard was persisted by the aggregate, which only runs when every shard is green — so a runtime, which
  is a property of one shard, inherited a score's precondition it never needed.

  Paid for in a real run: two shards clocked 229.6 and 252.2 minutes, the longest ever measured here and the
  evidence that the raised time limit actually holds. A third shard crashed, the aggregate skipped, and both
  numbers went with the workspace. A new `shard-times` step collects them whether or not the run produced a
  score, and each shard now states its own wall clock in its own log. It computes no score, reads no shard
  output and writes no proof — pinned three ways, because "while we are here" is exactly how a step like this
  would grow into the partial score the aggregate deliberately refuses.

- **The nightly rate-conformity lane went red for "could not ask", which is not a finding about the rates.**
  It is the only automatism that notices a VAT rate has moved — it exists because two stale rates survived a
  year — and a red that arrives regularly for a reason nobody can act on is how an operator learns to dismiss
  the one red that matters. An unreachable source now passes loudly, saying that nothing was learned rather
  than implying the shipped rates are wrong.

  Its log could also never say why it ended, and the cause was not the redirect it looked like. The CI
  runs a step under `set -e`, so `probe; code=$?; cat …; exit $code` aborts at the probe: the capture, the
  `cat` and the exit never run, and the log stops after the traced command. That reads as a silent death —
  and a ticket was written about a crash that had not happened, while the report step printed the probe's
  perfectly good JSON out of the very file the dead line was supposed to have shown. The mutation lane had
  already learned this and wrapped its invocation in an `if`; the lesson was never swept across. A guard now
  sweeps it, across every lane.
- **Three guards on an issued document can finally fire.** `seller_posture` had no writer, so all three
  checks that read it returned early — on every document this package has ever issued. The buyer receipt now
  freezes the posture beside the regime it is the locked twin of.

  Two of the three become tautological once it is derived (a posture taken from the regime cannot contradict
  it), and that is the trade. The third does not: it compares the posture against the party actually
  snapshotted as seller, and refuses a document that claims the platform is deemed supplier while naming
  somebody else. One sharp check is worth two tautologies — and all three stay meaningful for rows a consumer
  writes, which this model is public surface for.

  **Arming it surfaced a bug inside the guard itself.** The seller snapshot goes through `Party`, where an
  absent name is the empty string; raw configuration leaves it null. Compared directly, an install with no
  company configured could never satisfy the check — every commission-chain document refused for naming "a
  different party" than the one it had just snapshotted. Both sides now normalize the same way. It was
  invisible for exactly as long as the guard was unreachable.

  The **commission invoice** deliberately carries no posture. That document is not the sale: it is the
  platform's own invoice for arranging one, and its seller is correctly the platform, while the posture under
  intermediation says the merchant sells. Deriving it there would make the document contradict itself — which
  the guard said out loud rather than letting it ship.

- **A document can now say it was an export, which none it issued ever could.** `tax_exemption_reason` had
  no writer, so the renderer's fallback — *reverse charge, or nothing* — was the only reason any document
  ever carried. `SuppliedOutsideTheUnion` was unreachable, and with it EN 16931 category `G`: an export to a
  third country could not be stated as one, while the renderer has been able to render it all along and a
  test proves so against a row built by hand.

  The two are not interchangeable and the difference is not cosmetic. A reverse-charged supply **is** taxed,
  by the other party, and belongs in a return on both sides; an export placed outside the union is taxed by
  nobody. Rendering the first where the second happened tells the recipient to account for tax that nothing
  is owed on.

  Supplied by the caller and frozen on the document, like every other characteristic of a supply — and
  nullable, so an ordinary taxed sale states no exemption at all.

- **EN 16931 BT-72 now has something to render from.** Both e-invoice writers emit the actual delivery date,
  the column is frozen against later change, and the ticket that added the rendering is closed and says it
  renders. It does — it had nothing to render from, because no issuer ever wrote the column. The term was
  therefore absent on every document this package produced.

  `FanReceiptIssuer::issue()` takes the date and freezes it; a settlement takes it from the supply date it is
  already given, because a settlement documents one supply and asking twice for one fact is how the two
  answers eventually differ. It is supplied and never derived — which is what both writers already say in
  their own comments: a subscription billed in advance issues on the first of the month, and a date taken
  from the period's end would state a delivery in the future on a document dated before it. A document that
  covers a stretch says so with its service period and leaves this null.

  Found by the guard added earlier in this cycle, which is the point of it: a column that is read and never
  written is the same defect as a class nobody calls, and this one had survived three rounds of somebody
  happening to look.

- **A support refund on a routed sale now reaches the ledger that records it.** `RoutedChargeLedger`'s
  `beginRefund()` / `completeRefund()` / `failRefund()` are the only writers of `refunded_minor`,
  `transfer_reversed_minor` and `fee_refunded_minor`, and the only place `MerchantTransferReversed` is
  dispatched. Every caller of them in the repository was a test. So a refund moved money at the provider and
  left the ledger saying the merchant still held their whole share — with nothing red anywhere, because a
  verdict test cannot see a missing caller.

  `BillingAdmin::refund()` now resolves the routed charge **once**, prices the reversal from the terms the
  sale was made under, writes the attempt, and closes it either way. A refusal is recorded as an ending and
  moves no totals: an attempt row with no outcome is a reversal nobody can later say was tried.

  The idempotency key of a routed refund now comes from the attempt row, which is written **before** the
  provider is called — so a retry of the same intent arrives with the same key and is collapsed there. A key
  computed from the amounts cannot promise that: the amounts are exactly what a partly-applied reversal
  changes. A single-seller refund has no attempt row and keeps the key it always had.

  **A partial refund of a charge written before the commission terms were recorded is now refused**
  (`CommissionTermsUnknown`) rather than clawed back at a rate the sale was never made under. The refusal is
  as narrow as the missing fact: a **full** refund of the same charge still works, because with no remainder
  to price every rate returns the same figure.
- **A reporting activity can no longer say two different things about one sale.** `SellerActivity` took
  `individuallyCommissioned` as a boolean **beside** the archetype — and the archetype cases already carry
  that fact (`CustomOneToOne` is "work commissioned by one buyer, for them alone"; `Livestream` is "a
  broadcast to an audience, rather than to one buyer"). One fact written twice, statable in contradiction,
  with nothing to catch it.

  The direction it fell is what made it worth removing rather than validating: the reporting rule asks the
  boolean **first** and returns on it, so the archetype was never reached. An activity carrying
  `CustomOneToOne` with the boolean left at its default classified as *standardized* — a seller who had to
  be reported and was not, over an argument nobody passed. Under-reporting is itself an offense.

  The answer is now derived from the archetype, beside `isGoods()`, with one flag that can only **widen**
  it. The flag survives because deleting it would have taken a real case with it — a commission the catalog
  never classified is still a commission, and the duty turns on the commission rather than on the
  classification. What it cannot do is the other direction: an explicit "not commissioned" beside
  `CustomOneToOne` is the exact failure this ends, so it ORs rather than overrides.

  **BC:** the constructor signature changed. `SellerActivity` had no production caller, so no shipped path
  changes behavior.

- **A document can now say what was sold, and until it could, two readers were answering from the absence.**
  `billing_invoices` has carried `tax_archetype`, `place_of_supply_rule` and `tax_rate_category` since the
  tax characteristics were introduced — with casts, an immutability guard, and readers. No issuer wrote any
  of them. The only two assignments in the package copy the columns from an existing document, and the
  document they copy from never received them, so every document this package produced carried null and every
  correction copied the null forward.

  Neither reader treats null as *unknown*. The EN 16931 category reads it as a service, which decides `AE`
  where a reverse-charged supply of **goods** owes `K` with VATEX-EU-IC, and blocks `G` on an export of goods
  — both exempt, both carrying the same zero, so the rendered document names the wrong provision and looks
  entirely correct doing it. The periodic return files a sale under the standard band whatever rate it
  carried, so a reduced-rate line states one band beside the other band's rate.

  `FanReceiptIssuer::issue()`, `SelfBillingEngine::issue()` and the three `SubscriptionCycleBilling` entry
  points now take the three characteristics and freeze them. All three are nullable and default to null, so
  an existing call site writes the row it always wrote. They arrive from the caller rather than being looked
  up: the product catalog belongs to the consuming application, and this is the document recording what one
  supply was, not the package keeping a second copy of somebody else's classification.

  The guard is the part worth keeping. The existing test proves the columns hold and refuse to move, against
  rows it writes itself — which is the right test for immutability and structurally cannot notice that
  nothing else fills them. The new one issues through the real issuers and asserts what comes out.
- **Reconstructing a routed sale now rounds the way the installation prices one.** `MerchantCharge::frozenFee()`
  builds the commission terms a sale was made under so a partial reversal can recompute what a smaller sale
  would have paid out. It left the rounding direction off, so the constructor's default decided it — and on an
  installation configured to hand the odd minor unit to the creator, every uneven reconstruction came back one
  minor unit off the sale it was correcting. The direction is now read from
  `billing.marketplace.fee.rounding` through the same two-way mapping the pricing resolver and the document-side
  corrector already use, with the corrector's fallback for a value neither can honor. The method's own docblock
  had described this behavior all along.

  Nothing was red, and the reason is worth stating: every existing case in the guarding test divides exactly,
  and on an even split all three directions agree. The arm that was missing is an uneven one.

- **The package now calls nothing a lean install does not define.** `composer.json` declares only focused
  `illuminate/*` components, never `laravel/framework` — and shipped code was calling 184 Foundation-only
  global helpers (`app()`, `config()`, `now()`, `__()`, `abort()`, `view()`, `route()`, `redirect()`,
  `response()`, `report()`, and the four `*_path()` helpers) that only the full framework defines. Locally
  that is invisible, because the test harness pulls the framework in and its `replace` map provides every
  namespace; a consumer installing the components alone would have died on `Call to undefined function
  app()`. Every call site now resolves through the container, a Support facade, `Carbon`, the
  `ExceptionHandler` contract, the `Application` contract's path methods, or a thrown `HttpException`.

  Nine `illuminate/*` components the code imports were not declared at all — console, collections,
  validation, auth, view, broadcasting, bus, queue and filesystem — plus `illuminate/container` and
  `symfony/http-kernel`, which the fixes above now use directly. Two `Illuminate\Foundation` traits with no
  split package are gone with them.

  One behavior difference worth stating rather than hiding: the two middlewares whose HTTP status comes from
  config now throw `HttpException` for every code, where `abort()` threw `NotFoundHttpException` for a
  configured 404. The status a client sees is identical.

- **A published webhook route could be silently unreachable.** `routes/billing.php` cast the config
  repository's `mixed` to `string`, so a consumer who set `billing.webhook_path` to an array would have
  mounted a route literally named `Array` and every webhook would have 404ed. It reads the typed
  `Config::string()` instead, which throws at boot and names the key. Found by holding `config/`, `routes/`
  and `database/` to the same static-analysis level as `src/` — they all ship, and until now only `src/` was
  analyzed.

- **The test suite was reading a published copy of the config instead of the package's own.** Under
  Testbench the host application is the skeleton in `vendor/`, so publishing writes `billing.php`,
  `account.php` and `license.php` there — and such a copy wins over `mergeConfigFrom()`, which merges the
  package defaults *under* an existing file. Every test that read `config()` without setting its own value
  therefore saw a snapshot of the day it was published. On this checkout that snapshot was six days old and
  diverged from `config/billing.php` by 988 lines, all of them keys the copy simply did not have. The
  consequence is the one that matters: **a config change could not turn a test red** — a corrected default,
  a renamed key or a flipped security setting shipped against a green suite.

  The copy became permanent through the cleanup meant to prevent it, which ran only when the file had not
  existed beforehand — a guard for "a config the host checkout already had", except that the skeleton never
  legitimately carries one. Once any copy survived, every later run read it as pre-existing, declined to
  remove it, and never re-published over it either. What made one survive is a killed run: neither
  `finally` nor `afterEach` executes on `SIGKILL`. Cleanup is now unconditional, the publishing test asserts
  both that it published and that nothing remains, and a leftover from a killed run is swept at bootstrap —
  before any test can publish, which is the one moment no parallel worker can be mid-publish. Consumers are
  unaffected; this is test-harness only.

- **Settling and failing a routed charge were not under the row lock.** Both checked the settlement state
  and wrote it with no lock and no transaction, while the ledger's own docblock has always claimed a row
  lock covers every advance of that column. It covered exactly one method.

  Two provider deliveries then both read `pending`, both wrote, and **both returned true** — so two callers
  each believed they had made the transition. The dangerous pair is not two settlements: it is a settlement
  racing a failure. Whichever writes last wins, and if that is the failure, a charge whose money really did
  move is recorded as one that never completed — which every reader of that table then believes, including
  the ones deciding what a merchant may still be refunded.

  The transition is now decided from the row read **inside** the lock, never from the instance the caller
  was holding: that instance was loaded before the other delivery arrived, and deciding from it is the race.
  The caller's instance is refreshed on success, so a method that just advanced a charge cannot hand back
  an object saying it did not. Covered by race tests on both real engines.
- **Six golden fixtures were recorded from renders that were missing what they exist to show.** Five
  e-invoice baselines — the single-merchant XRechnung document and the four rendered UBL/CII files — carried
  an empty seller: no name, no address, no endpoint. The DATEV single-seller baseline carried empty Konto
  and Gegenkonto fields, on the rows whose whole purpose is to show that a single seller books the global
  accounts.

  They are the unmoving target the marketplace document rework is measured against, and a baseline that is
  itself wrong makes every later comparison agree with the wrong thing — quietly, because agreeing is what
  a passing comparison looks like.

  All six are re-recorded, and each test now asserts the thing the fixture is *for* **before** comparing
  bytes: conformance for the e-invoice goldens, the account numbers for the DATEV one. Identical-to-the-
  golden only means something once the golden is valid.
- **A routed charge confirmed by webhook was settled without the transfer the provider had already made.**
  On a destination charge the provider creates the transfer as the payment settles and names it in the same
  payload. The synchronous path has always carried that reference; the webhook path dropped it — so a hosted
  checkout produced a settled row that says the money moved and cannot say where to.

  That column exists to be checkable against the provider, and it is the join any reconciliation has to
  make. The confirmation event now carries the reference and the settlement writes it.

  Still null when the payload names none, which is the separate-transfer lane: there the share moves in a
  later call the platform makes itself, so there is genuinely nothing to name yet. A placeholder would be
  worse than null — null says nothing, a made-up string says something false.

- **A routed sale of nothing reached the provider.** Nothing on the money path refused a zero amount, so a
  charge of `0` was sent, a row was written for it, and every reader of that table — the earnings counters,
  the threshold monitor, the reportable inflow — counted a sale nobody made. A fan who leaves the tip field
  empty is the ordinary way to get there.

  The rule was already written and could not be reached: the fan-pricing class refuses a zero amount with
  exactly this reasoning, and nothing in the package calls it. The refusal now sits on the path every routed
  sale goes through. A negative amount is refused by the same check — it is the same claim, and it used to
  reach the provider *first*, because the split that would have rejected it happens after the charge.

- **The proration strategy called the package default was bound by nobody.** `ProrationStrategy` is an
  interface, and only the Stripe driver ever bound it — so on a Stripe install nothing looked wrong, and
  anywhere else the container had nothing to build. Previewing a plan swap did not degrade to "no local
  figure"; it threw, in the middle of a subscription screen, over a nicety.

  The core provider now binds `DelegatedProrationStrategy` **before** it registers the driver, so every
  driver still overrides it. The order is the mechanism: bound after, the same line would silently disable
  the driver's own preview instead of backing it up.

- **One setting governed one of the two counters.** `billing.tax_counters.reversal_attribution` decides
  which window a reversal reduces — the one it happened in, or the one it corrects. The reporting counter
  read it; the small-business threshold counter answered on its own and always the same way. An install set
  to `original_period` therefore moved a reversal for one figure and left it where it happened for the
  other, with nothing in the key's name to say which of them it meant.

  The reading now lives on `ReversalAttribution` itself, so both counters ask one question of one place —
  including the refusal on an unreadable value, which previously would have stopped one figure and let the
  other answer from the default.

  It matters more on the threshold than on the report: a December sale that tips a creator over the limit
  and is refunded in February either leaves the crossing standing or unwinds the year it happened in, and
  those are different legal outcomes for every settlement issued in between.

- **The small-business threshold counted the wrong one of three numbers.** One routed sale produces three
  legitimate figures — at 119.00 with 19% tax and a 10% commission the buyer paid 119.00, 109.00 reached the
  creator, and 90.00 is what their supply was worth. The section-19 monitor summed the middle one, the
  creator's whole receipt, while its own comment called it the payout net.

  About a fifth too high, and the direction is the expensive one: the figure decides when a creator stops
  being a small business, so counting high flips them out of the regime **early** and has them owing a tax
  they do not yet owe, on every settlement, until somebody recomputes it by hand.

  The tax reaches the creator because it is theirs to remit — which is exactly why it is not turnover of
  theirs. It now comes off before the count, using the rate frozen on the charge.

  A reversal removes the **supply** it undid rather than the clawback that moved, and the two differ by the
  tax that came back. Dividing the clawback by the tax rate is the plausible wrong answer: it spreads the
  commission's flat component as though it were proportional, when the platform performed the handling once.

  Rows written before the rate was frozen cannot separate the two and still answer with the receipt. That
  over-counts, and it is a stated limit rather than a silent one — inventing a rate for a historical sale
  would be a guess on a figure that decides a legal status.

  Nothing caught this because the counter's tests used round amounts with **no tax and no commission**, and
  at rate zero all three bases are the same number.

- **The marketplace commission was taken on what the buyer paid, not on the sale's net.** The
  configuration has described the take rate as a net rate — "applied to the transaction's net, not to what
  the buyer paid" — since the fee existed, and the pricing path obeyed it. The money path did not. On the
  specification's own base case, 119.00 at 19% VAT with a 10% rate, the platform kept **11.90 instead of
  10.00**: a commission on the buyer's tax, which was never the platform's money. The figure was withheld,
  written to `billing_merchant_charges`, and handed to the provider as the application fee.

  Nothing looked wrong because the two bases coincide **exactly** when the fee is rate-only *and* the
  creator's inbound rate equals the outbound one. A flat component, a small-business creator, a
  reverse-charge creator or a cross-border rate each break the coincidence — and each of them quietly.

  What the merchant receives is still the whole payment less the commission: the buyer's tax travels with
  the merchant's share, because on a routed sale it is the merchant who owes it.

  **Breaking (pre-1.0).** `RoutedPayment::charge()` and `ChargeRoutingResolver::resolveFor()` take the
  buyer-side tax rate in basis points, and it is **required rather than defaulted** — a default of zero
  would go on charging the commission against the gross for every caller that had not been updated, which
  is silent and is money. Existing positional calls fail loudly with a `TypeError`. If your take rate was
  chosen against the gross figure, re-derive it before upgrading.

  Existing rows are **not migrated**. They describe money that really did move on the old basis, and
  rewriting them would make the books tidier and false. A new `commission_tax_bps` column records the
  basis, and a reversal reads it off the row: a sale comes back on the basis it was made on, so an old
  charge still reverses on the gross and a new one on the net. Refunding the difference to a merchant is a
  payment of its own with its own document, not an edit to history.

- **The events reference said the opposite of the code, and three shipped effects were missing from it.**
  `ChargebackReceived` was listed under "events with no shipped effect" while two effects were registered
  against it — so an adopter following the page would have written their own fee recorder and double-booked
  every dispute fee. `GrantPurchasedContent` and `RevokeAccessOnRefund` were absent from the table entirely.

  A guard now derives the effect table from the registrations themselves and fails in both directions: an
  effect the package registers and the page omits, and a pairing the page promises that nothing registers.
  The sibling check that already existed asks whether every event is *mentioned* — a different question, and
  it stayed green throughout, because the event was mentioned in the sentence saying it had no effect.
- **A support refund on a routed sale left the merchant their share.** `PaymentRails::refund()` has taken a
  routing since the marketplace lane was built, and the reversal inside the Stripe implementation is gated on
  it — but the only caller of that method in the package, `BillingAdmin::refund()`, passed three arguments.
  So the branch was unreachable from production: the buyer got their money back out of the platform's pocket
  while the merchant kept what they had been paid, on every install, with nothing red anywhere.

  Every test of the reversal built its own rails and called `refund()` directly. That proves the payload and
  cannot see a missing caller, which is why the new ones go through `BillingAdmin`.

  The routing is read off the **row**, not resolved again: the lane decides how the reversal happens, so
  taking it from today's configuration would reverse an old sale as though it had been made under the current
  lane. A charge with no routed row — every single-seller charge — passes no routing at all, so that payload
  is byte-for-byte what it always was. A row that cannot say which lane it took passes none either, because
  guessing is what this is fixing.

- **A routed sale now records WHICH LANE it took, so a refund no longer has to guess.** The two lanes reverse
  in completely different ways: a destination charge created its transfer as part of the payment and can
  unwind both together, while a separate transfer moved the money in its own call and needs a second one with
  a calculated amount. Nothing on the row said which it had been, so the only source left was today's
  configuration — the exact mistake the commission terms beside it were frozen to avoid.

  It has a sharper edge than the terms do, because **both** directions of getting it wrong are silent. A
  destination charge read as a separate transfer reverses nothing and leaves the merchant a share of a
  refunded sale. A separate transfer read as a destination charge sends a `reverse_transfer` flag the
  provider accepts and ignores — so the failure looks exactly like success.

  Nullable, and null means "written before this was recorded" rather than a default. Backfilling from
  current configuration would be the same guess one step removed.

- **The register that finds classes nothing calls counted a name in a COMMENT as a caller.** Its reference
  scan was a word match over raw file text, so a class mentioned in prose passed as reached — and fourteen
  were, including `ClawbackCalculator`, which escaped on one sentence describing the very reason it exists.
  A register that clears the class it was written to catch is worse than no register, because it reads as
  coverage.

  Comments are masked now, using the same shared masker a sibling scan already needed. Strings are kept
  deliberately: a class named in a string is very often a real reference in Laravel — a container binding, a
  listener map, a config entry naming a driver — and masking those would swing the register the other way
  and report live wiring as dead.

  All fourteen carry an explicit verdict rather than a blanket one, which is the whole point of the register.
  Four of them were entrypoints in intent that no page named, so the marketplace guide now shows how to
  compose the receiving and eligibility gates and how to bind per-merchant catalogs — an "a consumer calls
  it" verdict claims an accessibility that has to be findable to be true.
- **The consent gate before provision could not fire, on any install, however it was configured.** The
  consumer-withdrawal policy, the fail-closed gate, the archetype-to-withdrawal-type mapping, the column on
  the access grant and the pro-rata value-for-use formula were all built, bound and tested. `ContentGrants`
  returns before consulting the gate when it is handed no withdrawal type — and the only production caller,
  the effect that turns a paid purchase into an ownership row, passed none. `WithdrawalConsent` was
  constructed nowhere outside the test suite.

  Every test of the gate stayed green because each one called the gate directly. A test that constructs a
  mechanism proves the mechanism; only a test that enters where production enters can prove it is reached,
  so the new ones go in through the webhook effect.

  Two things were missing and both are here. An add-on can now carry an `archetype`, through a new opt-in
  `SuppliesProductArchetypes` contract rather than a method on `AddonCatalog` — that one is implemented
  outside this package, so adding to it would be a fatal error in code we do not own. And a buyer's two
  declarations are now recorded, in `billing_withdrawal_consents`, keyed on the purchase the way the grant
  path already looks things up.

  **Nothing changes without a `billing.consumer_rights.profile`.** The gate checks the profile itself, so an
  install that has not set one grants exactly as before, even though the archetype is now resolved on every
  purchase. An add-on nobody classified answers "unclassified" rather than a default — a guessed archetype
  is a guessed tax treatment and a guessed withdrawal right, and both are wrong quietly. A value that is not
  one of the archetypes is refused instead, because a typo is not the same thing as an unclassified product.

  A retried checkout keeps the **first** consent. The notice version on the row is the point of it: a sale
  is governed by the words shown at the time, and letting a retry overwrite the version would move it to
  today's and quietly reinterpret what was agreed.

- **The DATEV batch never booked a provider fee, because nothing ever handed it any.** `DatevExport::export()`
  has taken the period's provider fees as a parameter for as long as the PSP-fee accounts have existed, and
  `billing:datev:export` — the only caller — passed three arguments. The accounts were configured, the
  booking was correct, and every real monthly batch contained zero provider fees. Nothing was red because the
  test that proves the booking passes the fees to `export()` itself, which is a test of the booking and not
  of the export.

  A dispute fee is what makes this expensive rather than untidy: the provider is established abroad, so the
  fee is an inbound supply carrying reverse-charge VAT the platform both self-assesses and deducts. A month
  that books none declares neither side of it.

  **One consequence worth planning for.** The export refuses fail-closed when no `psp_fee` account is
  configured for the active chart, so an installation that *has* provider fees and has not configured that
  account now gets a refusal where it previously got a batch that silently omitted them. The refusal is per
  fee, so a period with none exports exactly as before. Configure `billing.datev.accounts.<chart>.psp_fee`
  with your advisor before the next export if a lost dispute may fall in the period.

  The command's summary line now names **both** counts, including the zeroes: a line reporting only invoices
  reads as the whole batch, and there was no way to tell a month whose fees were loaded and empty from a
  month whose fees were never loaded. `voucherMovements` is still not passed, and deliberately: a voucher
  movement is a value object nothing persists, so there is nothing to load yet.
- **`billing.checkout.payment_methods_return_url` is now shipped in the config file it was always read
  from.** `CheckoutUrls::paymentMethodsReturnUrl()` has read the key since it was written, and it was the
  one of the four checkout URLs that never appeared in the published config — so the only way to discover
  it was to read the source. Behavior is unchanged: absent, it still falls back to the payment-methods
  screen. It is on the configuration reference page now, beside its three siblings.

- **The dispute fee was read from a field a dispute does not have, so it was never recorded at all.** The
  marketplace webhook mapper read `balance_transaction` — singular — from a lost dispute. The provider
  declares `balance_transactions`: plural, and a list of zero, one or two entries. The key it read is not
  sometimes absent, it is always absent, so `feeAmount` came through as `null` on every real webhook, the
  provider-fee effect dropped it at its own guard, and no fee row was written for any lost dispute. The
  same applied to the transfer reference, read from a `transfer` field a dispute has never carried.

  Nothing went red because the fixtures wrote exactly the keys the parser read. A payload a test invents
  cannot discover that the payload is wrong — it only confirms the guess it was written against. So the
  keys are now checked against the provider SDK's own declaration of the object, which is the one
  description of that payload nobody here wrote.

  The fee is now read from the list and **summed** rather than taken first: a withdrawal states the fee as
  a positive number and a later reinstatement states it back as a negative one, so summing nets a fee that
  was charged and refunded to nothing, where adding magnitudes would have reported it as charged twice. A
  payload carrying no entry reports **no fee** rather than a zero, because zero is a claim that nothing was
  charged. The transfer reference is now `null` deliberately, with the reference to be resolved from the
  routed charge row when a reversal is applied — a mapper cannot know it, and reading a field that does not
  exist made the gap look filled.

- **A lost dispute corrected the creator's document even when the creator had done nothing wrong.** The
  refund cascade issued a creator-side correcting document unconditionally, so a **fraudulent** chargeback —
  a supply that actually happened, lost to a stolen card rather than returned to a customer — reduced the
  turnover of the person who delivered exactly what they promised. It reached them as a credit note they
  could not explain, and it was the platform's loss written onto their tax return. The cascade now takes the
  reason the base is changing and issues the creator-side correction only where the consideration went back.
  The buyer's side is untouched by the distinction: they are refunded either way, and the platform's own
  output tax falls either way. Only the **document** is suppressed — whether the platform recovers the money
  from the creator under its contract is a separate claim on a separate path. The parameter defaults to
  correcting both links, so a caller that says nothing loses nothing.

- **A correcting settlement document stated a taxable amount without stating what it was a taxable amount
  of.** The correction carried the sale's regime, document type, parties and frozen exchange rate across, but
  left every **tax characteristic** empty — archetype, place-of-supply rule, rate category, rate, recipient
  standing, matrix version — so a reader had to infer all of it from the original, which is the inference
  freezing those values exists to make unnecessary. The rate is what shows this was an omission rather than a
  decision: it was already being read to state the rate on the correction's own line and was not written to
  the column beside it, so one document said one thing in its line and nothing in its column.

- **A lost dispute dropped the reason it was raised for, so only one correction branch was reachable.** The
  Stripe marketplace mapper discarded the provider's reason code, and `ChargebackReceived` had nowhere to put
  it — every lost dispute therefore arrived looking alike. It decides more than it looks: a buyer who
  received nothing has a claim against the supply itself, so both legs of a routed sale are corrected and the
  merchant's turnover moves with it, while a **fraudulent** charge is a loss over a supply that actually
  happened, and correcting the merchant's settlement document there writes the platform's loss onto the tax
  return of somebody who delivered exactly what they promised. The event now carries a nullable `reason`, and
  an unrecognized code maps to `Unknown`, which fails toward the *fuller* correction on purpose: one wrongly
  made is visible on a document somebody receives, one wrongly skipped is silence.

- **Dunning read one subscription row and called it platform-wide.** Both `LocalDunningGuard` and
  `LadderSuspension` took the owner's *newest* row (`latest('id')`), which is none of the readings their
  own documentation describes — it is insertion order. The expensive direction is silent: a customer
  already past due on the platform plan only had to subscribe to any merchant, the newer row was active,
  and the gate returned `null`. Dunning stopped, the paid allowance kept flowing, and nothing was logged
  or failed. The ladder had a second edge — a newer row carrying no delinquency clock **reset** it, so a
  customer several rungs deep could walk back to zero by subscribing to anybody, repeatedly. The guard now
  evaluates every row of the owner and blocks when any of them blocks; the ladder takes the **earliest**
  clock across them, because the rung is about the debt that has stood longest. This restores the
  platform-wide behavior the classes already documented as deliberate — whether a debt to one merchant
  *should* reach another is a separate, still-open question, and it is not one an arbitrary row ordering
  should have been answering.

- **The realtime layer had no sending half.** The package shipped every part of it that can be inspected —
  the `billing.realtime.enabled` switch, both broadcast events, the owner-scoped private channel, the
  headless bridge that turns a broadcast into a toast, and the documentation describing all of it — and
  nothing in the package ever dispatched either event. Switching realtime on therefore bought a bridge
  listening to a channel nobody published on: no error, no log line, just screens falling back to the poll
  forever. `AccountBillingUpdated` is now raised by the plan sync when a provider event actually moves
  something (a redelivery that changes nothing stays silent, so a retrying provider does not make every
  open screen re-fetch), and `AccountToastNotified` is raised where the package already tells the owner
  something: `danger` on a failed payment, `success` when a subscription goes live. Both still refuse to
  reach a socket unless realtime is switched on, so nothing changes for an install that has not asked for
  it. The toast text is resolved **in the owner's stored locale at the moment it is raised** — a broadcast
  comes from a webhook, where there is no request and so no locale but the application default, which is
  how a German customer would otherwise be told in English that their payment failed.

- **A routed payment that clears later now actually settles.** A card demanding 3-D Secure and a bank debit
  that clears in days both return successfully having moved no money, so the merchant's row is written
  `pending` — and nothing ever moved it: `RoutedChargeLedger::settle()` had one caller, the synchronous
  path, and `fail()` had none. Three bound readers count settled rows only, one of them the small-business
  turnover threshold, so a merchant paid entirely by bank debit read as having earned nothing indefinitely.
  `payment_intent.*` now maps to a neutral `RoutedChargeConfirmed` / `RoutedChargeAbandoned` **when the
  payload carries no invoice**, and an effect settles or fails the matching pending row. The dunning
  protection that kept `payment_intent.*` unmapped is untouched: every invoice-driven payment still maps to
  nothing, and an event matching no routed charge does nothing.

- **A routed payment now checks the charge-type/posture pairing its sibling paths already check.** Both
  `StripeCheckout` and `StripeOneTimeCharge` refuse an incompatible pairing before assembling anything;
  `RoutedPayment::charge()` — the only place in the package that reaches `PaymentRails::charge()` — accepted
  whatever routing it was handed. The rule and the guard both existed; what was missing was a moment. An
  incompatible pairing does not fail: the provider accepts it and quietly treats the wrong party as merchant
  of record, so nothing surfaces until a chargeback lands on them.

- **Two CI lanes ran the tools that exhaust PHP's default memory limit without raising it first**, so a
  nightly failed at type-coverage after every static check had passed — a red that reads exactly like the
  upstream drift the lane exists to report, and is not. The requirement is now derived from the lane
  directory instead of remembered, which found a third instance nobody had noticed. Internal only; no
  effect on the published package.

- **The buyer-protection clock is now wound.** `billing:protection:advance` states in its own docblock that it
  is meant to run daily, and it appeared in no schedule. Its two deadlines — the buyer's silence turning into
  consent, and the absolute decision date — are dates, and a date only means something if something reads it.
  Unscheduled, a hold simply waited until the payment provider stopped waiting and paid out anyway: the money
  arrived, so nothing looked broken, and only the promise behind it was empty. Scheduled daily, and safe to
  schedule unconditionally — with no holds it moves nothing and exits zero. A new guard derives the claim from
  each command's own docblock rather than from a list of names, so the next command that states a cadence and
  is left unscheduled fails the build; its sibling assertion keeps `billing:rates:probe` deliberately OFF the
  schedule, because a package that phoned a third party merely because it was installed would be doing
  something no operator agreed to.

- **A refund now obeys the fee refund policy, which nothing used to read.** `refund_application_fee` was the
  constant `false` on every routed refund — meaning *the platform keeps its commission* — while the shipped
  default of `billing.marketplace.fee.refund_policy` is `refund`, and a go-live checkpoint reads that key and
  **refuses** `retain` under a commission chain. So the package validated a setting nothing obeyed, then did
  the opposite of it every time money went back. The reason the checkpoint refuses is the reason the refund
  has to follow it: keeping a fee presupposes a document the platform issued the merchant for a service, and
  a commission chain has none — the platform buys and resells, unwinding the sale unwinds both supplies, and
  money kept afterwards sits on no supply at all. The check is repeated **at the refund** rather than trusted
  from preflight, because preflight is a gate that answers once while the regime and the policy are both
  configuration and both movable afterwards. An unreadable value throws instead of defaulting, and the
  refusal happens before the provider is called, so a refused refund has not already moved the buyer's money.
  `FeeRefundPolicy::refundsPlatformFee()` names the question, because the provider's field is named for the
  opposite of what a reader expects and a raw boolean here is one inverted reading away from a sign error.

- **A mid-cycle plan swap now books the proration it showed you.** `ProrationStrategy` has two halves and
  the package called only one: `previewSwap` had a call site on the plan screen, `applySwap` had **none** —
  not in the in-app path, not in the scheduled one. On the shipped Stripe driver that is invisible, because
  its `applySwap` is a documented no-op and the provider prorates for itself. On `CreditBalanceProrationStrategy`,
  which exists precisely for a provider with no proration of its own, the screen showed the customer the
  credit they were about to receive and then booked nothing at all. The promise was on the screen; the money
  never moved. Both paths now apply it, and the ORDER is load-bearing rather than incidental: the proration
  runs **before** the provider is asked to swap, because it prices the unused remainder against the tier the
  resolver answers with at that moment — run it afterwards and the credit is computed against the price the
  customer is moving to, which looks entirely reasonable and is wrong. The in-app path wraps both in one
  transaction, so a provider that refuses the swap leaves no credit behind, and a resubmitted swap finds the
  owner already on the target tier and returns before any money is touched. Proven on all three engines.

- **A content-ownership register: what a buyer OWNS, as opposed to what their plan lets them do.** Two
  questions that sound alike and are not. The licensing side answers "what may this owner do right now", and
  its answer changes the moment their tier does; ownership outlives the plan, the creator's account, and the
  work's own publication. `billing_access_grants` records it, off by default behind
  `billing.content_ownership.enabled`. Every dimension is present from the start on purpose — adding a column
  later is cheap, but adding one that has to be RIGHT for rows already written is not, and there is no honest
  value to backfill for a grant whose terms nobody recorded. Nothing is soft-deleted: a grant that stopped
  granting says so, with a reason and a date, because "why can this person no longer read what they bought"
  is a question somebody will ask and a deleted row cannot answer. A statutory withdrawal is its own
  revocation reason rather than a flavor of refund — without the extinguishing flow every refund inside the
  statutory window is a claim rather than goodwill, so which of the two happened is not a matter of wording.
  The references to content, versions, bundles and declarations are opaque strings and never foreign keys: a
  foreign key would let deleting a work cascade into "this person never owned this", and deleting a work is
  exactly when the record of who owned it matters most.

- **A service supplied outside the union now renders as category `O`, not as zero-rated `Z`.** They are
  different statements: `Z` says the tax reached the supply and the rate was nothing, `O` says it never
  reached it, and the difference is not recoverable from the document afterwards. It arrived after the
  export categories because it could not be introduced alone — BR-O-11 makes `O` **exclusive**, and the
  BR-O-* rules forbid such a document stating a tax rate or amount at all. So an out-of-scope service on the
  same document as a taxed supply is now **refused**, and the refusal says to split it in two rather than
  leaving a caller to guess. It is not downgraded to `Z` for them: that would file a supply frozen as
  outside the scope of tax as though the tax had reached it. A document that never froze a reason renders
  exactly as it always did.

- **A shipped in-memory subscription-state reader, so a content-ACL can be tested without a billing
  database.** The access question — may this person see this creator's post right now — is asked on nearly
  every page a marketplace renders, so it is the read a consuming app exercises most; standing up
  `billing_subscriptions` to answer it drags this package's schema into tests about somebody else's content.
  `ArraySubscriptionStateReader` stores grants in memory and delegates **every decision** to the same value
  object the real reader returns, so the double cannot drift into the failure that matters: a hand-rolled
  stub granting access whenever a tier is present would show paid content to a lapsed subscriber, with a
  green suite on both sides. It refuses an unsaved customer rather than keying every one of them alike.

- **A merchant's tiers are read once per request instead of once per tier key.** The reverse price lookup —
  which tier does this provider price belong to — walks a merchant's keys and asks about each one, so the
  host's tier repository was being called roughly **2N+1 times to answer a single question**, on the webhook
  path, per event, per merchant. Against the config catalogs that walk is free; against rows it is a burst of
  identical queries, and it grows with every tier a creator adds. The package now wraps whatever repository
  it is handed in a per-request memo, so the obvious host implementation — a plain query — is both correct
  and cheap. Measured rather than asserted: the covering test counts the reads and pins one, where the
  unwrapped path makes six for three tiers. The memo is keyed on the merchant scope, and a second test
  proves a read for one merchant cannot answer for another — a saving that leaked across merchants would be
  the cross-merchant price bleed reintroduced as a cache bug.

- **A routed payment is now written down, because nothing was writing it down.** Both halves existed and
  neither called the other: the rails could route a payment to a merchant, the ledger could record that one
  had been routed, and no code path did both. That is not a missing log. The reversal caps, the annual
  earnings count and the small-business threshold verdict are all computed **from** that table — so a
  payment nobody recorded is invisible to every rule the money is supposed to obey afterwards, and a
  merchant could be refunded past what they were actually paid. `RoutedPayment` makes the two one operation.
  It takes the driver rather than its rails, so the recorded provider and the reference that names the
  charge cannot drift apart. A failed charge writes nothing; one still awaiting the cardholder or still
  clearing at a bank **is** written, because those may yet settle and the window where an operator asks
  what is in flight is exactly the one that was invisible. The split is stored as decided at the charge,
  never recomputed, so a later change to the fee policy cannot rewrite what a settled sale was made under.

  A payment that simply succeeded is also **settled** on the spot, with the transfer reference the provider
  already returned — on a destination charge it creates the transfer as the payment settles, so waiting for
  a webhook to restate that would hold every routed sale in a state the money is not in. Only an outright
  success settles: an intent still awaiting the cardholder, or a bank debit still clearing, stays pending,
  because a settled row there would let a later reversal be capped against funds nobody has received yet.

- **A separate-transfer routing no longer takes the whole payment and leaves the merchant unpaid.** The
  shape is: the platform collects everything, and a second provider call moves the merchant their share
  afterwards. The first half was built and correct — the payment intent rightly carries no destination,
  because on this shape the platform is the merchant of record. The second half was never written, and the
  lane ended in a comment. So the charge succeeded, the platform kept the lot, the merchant was never paid,
  and **nothing said so**: a successful result, no exception, and a null transfer reference that reads
  exactly like one still settling. This package tells driver authors in its own upgrade guide that "a driver
  that cannot serve a routing must THROW, never no-op", and the shipped driver was doing precisely what that
  sentence forbids. It now refuses the routing — before the provider is called, on both the on-session and
  off-session paths — until the transfer step exists. Worth knowing how ordinary this was:
  `billing.marketplace.charge_type` **defaults** to `separate_transfer`, so the silent version was the
  default lane, not an edge case. Destination charges are unaffected and remain the lane that pays the
  merchant.

- **A periodic tax return refuses a batch that mixes currencies.** Its line figures are bare minor units and
  the aggregation key is country + category + rate — so two sales to the same country at the same rate, one
  in EUR and one in USD, summed into a single line as though the units were the same. The shipped export
  scopes its query by currency, so no install was filing a wrong number; the refusal exists because
  `linesFor()` is public, and because the class already applies exactly this reasoning to reissues, skipping
  them inside the method rather than trusting the caller's query. That argument does not stop at reissues.

- **A refund no longer claims a reversal on the lane where the provider ignores it.** `reverse_transfer` was
  set on every routed refund, but it only means anything on a destination charge — on a separate transfer the
  money moved in its own call, and refunding the payment leaves it untouched. The flag was accepted and did
  nothing, which read to every caller as "the merchant's share came back" while it sat in the merchant's
  account. The flag is now set only where it applies, and `RefundResult::$reversedTransferReference` is `null`
  on the other lane so the gap is visible instead of papered over. Reversing a separate transfer is the
  caller's call to make — with the amount `ClawbackCalculator` computes, not the proportional share, which is
  the wrong figure whenever the fee has a fixed component.

- A seller posture was described in its own documentation as pairing with escrow — an arrangement this
  package explicitly does not offer, stated in the one place a reader believes over any amount of prose.

## [0.9.0] - 2026-07-23

### Added

- **The documentation now describes only what ships, and the reference pages are held to the code.** The
  configuration, database, event and troubleshooting pages are written from `config/`, the migrations,
  `src/Events` and `src/Exceptions` — and each is locked to its source by a guard, so a key, table, event or
  exception added to the package and not to its page fails a test instead of leaving the page quietly
  incomplete. The database reference states what an erasure request does to every table, and that answer is
  compared against the same list the eraser and the exporter read. `docs/guides/upgrading.md` now carries the
  per-version upgrade path, including what a consumer shipping its own driver has to change.
- `billing.retention.allow_below_statutory_minimum` (default `false`) is declared in the published config.
  The retention guard already read it; a fail-closed guard whose opt-out appears in no published file is one
  a consumer can only discover by hitting it. Behavior is unchanged.

### Removed

- **Three configuration keys that promised behavior and delivered none.**
  `billing.marketplace.fee.processing_fee.borne_by` said in its own comment that the package records which
  side carries the provider's fee, and nothing recorded anything — wiring it would mean freezing a
  fee-incidence claim onto a money row while the package still contradicts itself about who actually bears
  that fee on a destination charge, so it goes until that is settled.
  `billing.tax_small_business.reattestation.on_year_change` offered to switch off the thing that makes
  declarations work at all: a declaration is a statement about a year in progress, so it cannot outlive
  that year, and an installation able to disable the expiry would carry standings that read as current and
  are not. `billing.consumer_rights.window_days` shipped a statutory-looking 14 and computed nothing —
  nothing in the package records the moment a work was provided, which is what a window would have to be
  measured from, and a number that changes nothing is worse than no number because an operator who raises
  it for their jurisdiction gets silence. Each is removed from the config, the documentation and the
  environment surface in one change, and each comes back the day it can be honest.

- **The documentation pages for features that do not ship.** Thirteen pages whose whole content was a
  placeholder banner and an outline are gone, and the entry page is rewritten as the six setup decisions a
  reader actually has to make, each with its config key and default. A page that announces itself as empty is
  worse than no page: it costs a reader the click and tells them a feature exists.

### Fixed

- The docs link guard split a link on `#` with `strtok`, which skips leading delimiters — so a same-page
  anchor came back as a filename and read as a broken link. Anchor links on a long reference page are now
  recognized, with a red-proof.

## [0.8.0] - 2026-07-23

### Changed (breaking — pre-1.0)

- **Removed the decimal-string amount helpers on `Money`.** The `Money` value object no longer exposes the
  decimal-string amount mapping methods it gained in 0.6.0 — they had no shipped consumer, so they are
  removed before anything could depend on them. Amounts remain integer minor units end to end.

## [0.7.0] - 2026-07-23

### Added

- **An optional cancelation reason on the subscription screen.** When an owner cancels, they can pick a
  reason (and add a free-text detail for "Other") from a short, localized list. It is recorded for churn
  analytics and passed to the provider, where a driver with a native cancelation-feedback field receives it
  (Stripe maps it onto `cancelation_details`). It is **never required and never blocks the cancelation** —
  no reason means a one-click cancel; a survey that could stop someone leaving would be a dark pattern. The
  reason is shape-validated (a tampered value is rejected, not stored), and it is purged with the owner like
  any other operational data — churn feedback carries no retention obligation.

### Changed (breaking — pre-1.0)

- **`SubscriptionActions::cancel()` takes an optional survey.** The signature is now
  `cancel(Model $billable, ?CancellationSurvey $survey = null)`. Callers are unaffected (the argument
  defaults to null); a **custom driver** that implements `SubscriptionActions` must add the parameter to its
  `cancel()` method. The built-in Stripe and null drivers already do.

## [0.6.0] - 2026-07-23

### Added

- **A self-harm guard on payment-method removal.** The account-hub payment-method screen now refuses to
  remove the card a live subscription is billed to — the default card, or the owner's only card — while a
  charge is still coming or being retried (`Active`, `Trialing`, `PastDue`, `Incomplete`). It answers with a
  clear, localized message ("add another card and make it the default first") instead of a 500 or a silent
  lapse into involuntary dunning; removing a spare card, or any card once the subscription is in grace or
  ended, stays allowed. Backed by a new `SubscriptionState::requiresPaymentMethod()` predicate.
- **A catalog and i18n adoption guide.** The tiers-and-pricing docs now describe the config-authoritative
  catalog services (`PricingCatalog`, `TierCatalog`, `AddonCatalog`) and how to extend them by rebinding, and
  a new i18n page covers publishing and overriding the translation namespace, adding or removing a locale, the
  informal register the strings keep, and the parity/informality/key-existence gate. The `provider_price`
  documentation is corrected: it is the active provider's price reference — a scalar or a per-provider map,
  resolved by `ProviderPriceResolver` as the anti-price-injection allowlist — not a Stripe-specific id.
- **A `docs/` tree, and the README is now a showcase.** The documentation is reorganized into a
  mode-structured `docs/` tree — single-seller (Mode S, the 90% path, written in full), plus scaffolds for the
  marketplace (K), intermediary (V) and other-jurisdiction (X) modes, and cross-cutting reference, guides and
  compliance sections. The single-seller content moved out of the README (it is not duplicated), leaving the
  README as a showcase: header, highlights, requirements, a short installation, a pointer into `docs/`,
  security and license. Two guards keep the structure honest: a Mode-S page may not use marketplace
  vocabulary, and every relative link in `docs/` must resolve with no page orphaned from the index — both with
  red-proofs. Docs prose is English, US spelling. `docs/` now ships as Markdown to the public repository —
  readable and linkable there — and is `export-ignore`d, so the package you install stays byte-for-byte as
  lean as before.
- **Local invoice renderer.** `InvoiceDocumentRenderer` renders one of the package's own invoices to a complete, deterministic HTML document from the stored row and a publishable Blade template (`billing::invoice`), with translations in all seven locales — the local counterpart to a provider's hosted invoice PDF, for a driver whose provider supplies no hosted invoice PDF. The PDF step is a seam (`PdfRenderer`): the package ships no PDF toolchain, so the default refuses loudly with instructions to bind one (dompdf, Snappy, …), keeping the package lean while the HTML stage stays browser-free and snapshot-testable. The invoice download route serves the locally-rendered document when the provider has no hosted PDF, ownership-checked (403 for another owner's invoice, 404 for an absent one), rate-limited (`throttle:60,1`) and marked `noindex`.
- **DATEV account resolution per business transaction.** The `datev` config block now carries a per-transaction account map with confirmed SKR03 and SKR04 default sets (`billing.datev.chart`), so each booking can land on the account that carries its own tax logic — a fan-revenue rate, an OSS country, a §13b input — instead of one revenue account for everything. A `DatevAccountResolver` sits in front of the export, which names a business transaction and gets an account back rather than reading a config field itself. With no chart selected the export is byte-for-byte unchanged (the single-seller revenue account). A transaction with no configured account aborts the export rather than booking to a default, the PSP fee resolves to the §13b input account rather than a bank-charge account, and an Automatikkonto never carries a BU-Schlüssel — each pinned by a test. The account numbers live only in config (behind the German jurisdiction profile); no core path contains an account number or the word "SKR".
- **Local order model.** `billing_orders` / `billing_order_items` and their `Order` / `OrderItem` models are the package's own billing unit for a driver with no provider-side order model: a due cycle is assembled as an order, processed, and an invoice produced from it. The columns are provider-neutral, the status is a typed `OrderStatus` enum, and exactly one order exists per subscription cycle (a unique on `[subscription_id, period_start]`) so a re-assembled cycle cannot bill twice. Line items carry a typed `OrderItemType` and their own currency, so a line's `Money` never depends on the parent being loaded, and a line's tax rate is stored in basis points rather than a percentage float, consistent with the rest of the money layer. Orders are purged with the owner (the retained financial record is the invoice), and their items are reached through the order for both erasure and export.
- **Custody guard: the platform-held funds mode is opt-in and gated.** `billing.marketplace.custody.platform_held` (default `false`) selects who holds money on a routed sale. Setting it `true` refuses to boot unless the host binds a `PaymentServiceLicenseAttestation` — holding other people's funds on a platform-owned account is a regulated activity, and a config flag alone must never enable it. The guard is jurisdiction-neutral (it checks a technical property, not a country's statute), a no-op unless the marketplace is enabled, and there is deliberately no yield/interest option. A README section explains when a payment-services or e-money license is needed, without claiming to be legal advice.
- **A decimal-string amount representation on `Money`.** `Money` maps to and from a decimal-string amount shape (`['value' => '10.00', 'currency' => 'EUR']`), for a provider representation that is not integer minor units. The value carries exactly the currency's precision — no point at all for a zero-decimal currency — and the read path parses through the existing integer/string decimal parser, so no float is ever constructed and a value with more precision than the currency allows is refused rather than truncated.
- **Percentage and division primitives on `Money`.** `splitByBps()` splits an amount into a basis-points portion and the remainder that sum back exactly, with the leftover minor unit of an uneven split assigned explicitly (a `RoundingResidual`) rather than by argument order — because at volume that cent is real money. `baseFromMarkup()` reads an amount that includes a markup as its base and the markup; `baseFromRate()` reconstructs the base an amount remained from after a rate was deducted. In all three, one side is computed and the other is the difference, so no cent is ever conjured or lost, and everything is integer minor-unit math with no float. A new `billing.marketplace.fee.rounding` key (default `platform_first`) documents which side keeps the residual cent; the primitive itself is neutral money arithmetic and carries no tax or marketplace meaning.
- **Provider-neutral subscription lines.** A new `billing_subscription_items` table and `SubscriptionItem` model carry what a subscription bills each cycle — catalog key, optional provider price reference, quantity, whether the line is metered, and the amount once known. Stripe prices usage remotely and keeps using Cashier's own items; this is what the local engine uses in place of a provider-side line model. A `preprocessor` column names the application-side resolver that prices a metered line when its cycle closes.
- `Subscription::items()` exposes the lines, and `SubscriptionItem::amount()` returns the line's `Money` — or `null` while a metered line is unpriced, which is a distinct state from an amount of zero.
- **A `CycleAmountResolver` contract for what a subscription line costs per cycle**, so invoicing and cycle runs stay driver-neutral whether the provider rates usage or the application does. The default reads a fixed line's stored amount and hands a metered line to the resolver named in its `preprocessor` column, so nothing rates usage unless something was asked to. A line that cannot be priced raises `CycleAmountUnresolvable` rather than returning zero, which would turn an unpriced cycle into a settled one that bills nothing.
- `MeteredCycleAmountResolver` rates a metered line from the package's own usage counters for drivers that have no provider-side rating — netting both the tier's included allowance and any prepaid units before pricing whole packages. It must not be used on a driver that rates remotely: the allowance would be netted twice and the customer would get the free units they already have.
- **Upgrade/downgrade timing.** A swap's direction is read from the tier ranking (the configured order, not price), and its timing follows: an upgrade takes effect immediately, a downgrade is scheduled to the current period end so the customer is never charged twice or refunded for time they already paid. The default is `config('billing.subscriptions.downgrade_timing')` (`period_end`, overridable to `immediate`), which both the screen and the swap read, so they cannot disagree. A scheduled downgrade is shown on the account screen as "changes on {date}" with a cancel control, an immediate upgrade supersedes any pending downgrade, and `billing:run` applies a downgrade once its date arrives — driver-neutral, since a swap due now is simply the normal swap performed then.
- **`CreditBalanceProrationStrategy` — mid-cycle proration for a provider that has none**. It computes the unused remainder of the current plan and credits it to the customer's balance, leaving the next order to raise the new charge, so a swap never bills the same days twice. `previewSwap` returns the net owed now (positive for an upgrade, negative for a downgrade, `null` when the current plan cannot be priced); `applySwap` books only the unused credit and records why on the audit ledger. A swap that leaves nothing unused writes no movement, and a downgrade whose credit exceeds the new plan leaves the surplus standing rather than producing a negative order. It is a sibling of the delegated strategy that a local-engine driver binds; the shipped Stripe behavior is unchanged.

### Changed

- **Breaking (pre-1.0) — Terminology: the cancelation "credit note" is now an "invoice correction".** The word *credit note*
  (Gutschrift) is reserved for the self-billing document (§ 14 Abs. 2 S. 5 UStG, EN 16931 type code 389);
  the document that cancels or amends an existing invoice is a **correction**. This renames part of the
  published API, so it lands in a pre-1.0 minor with this note rather than silently. Migration:

  | Old | New | Status |
  | --- | --- | --- |
  | `Pushery\Billing\ValueObjects\CreditNoteSnapshot` | `Pushery\Billing\ValueObjects\InvoiceCorrectionSnapshot` | removed |
  | `Pushery\Billing\Events\InvoiceCredited` | `Pushery\Billing\Events\InvoiceCorrected` (read `$event->correction`, was `$event->creditNote`) | **still ships, deprecated** — see below |
  | `Pushery\Billing\Webhooks\Effects\PersistCreditNote` | `Pushery\Billing\Webhooks\Effects\PersistInvoiceCorrection` | removed |
  | `InvoiceRecord::isCreditNote()` | `InvoiceRecord::isCorrection()` | removed |
  | translation key `billing::invoice.credit_note` | `billing::invoice.correction` | removed |

  **The event is the one exception, and it is deliberate.** `InvoiceCredited` still ships and
  `InvoiceCorrected` fires it through the framework dispatcher as well, so an existing
  `Event::listen(InvoiceCredited::class)` keeps being called for one deprecation window. **Do not delete
  that listener because this table says "removed"** — an earlier version of this row did, and following it
  would have been the one migration step that loses behavior rather than renaming it. Migrate when you are
  ready; the package's own effects listen on `InvoiceCorrected` only, so a correction is never persisted
  twice in the meantime.

  For one deprecation window, `InvoiceCorrected` **also fires the old `InvoiceCredited` event** through the
  framework dispatcher, so an existing `Event::listen(InvoiceCredited::class)` keeps being called instead of
  going silently quiet (the package's own effects listen on `InvoiceCorrected` only, so nothing is persisted
  twice). `InvoiceCredited` is now `@deprecated` and will be removed in a later release. The renamed value
  object, effect and model method are a hard rename with no runtime alias — a reference to an old name is a
  loud "class not found", not a silent no-op.
- **Breaking (pre-1.0) — A correction now carries a role, and it is validated.** `InvoiceCorrectionSnapshot` takes an
  `InvoiceCorrectionKind` — `Cancellation` (Storno, type code 381) or `Amendment` (Rechnungsberichtigung,
  384) — defaulting to `Cancellation` (the only role produced today; the 384 XML branch and its persisted
  role land with the correction-writer work). The snapshot refuses a **negative amount** at construction (a
  correction carries positive magnitudes; its nature, not a sign, inverts the meaning) and refuses an
  **amendment with no origin reference** (BG-3 is mandatory for a 384). The `credited_invoice_*` columns are
  left as-is (internal schema, not part of the renamed public surface) — a deliberate, documented choice to
  avoid a schema migration where none is yet needed. The rendered 381 document is byte-for-byte unchanged.

- **The e-invoice writers read the landed EN 16931 columns.** `XRechnungInvoice` (and the ZUGFeRD CII writer, in parity) now take the Leitweg-ID (BT-10) from the invoice's `buyer_reference` column rather than the buyer JSON snapshot, and derive the reverse-charge / exemption reason (BT-120) from the `vat_note` column instead of a hardcoded "Reverse charge" literal — falling back to the standard wording only when no note is stored. The single-merchant rendered output is pinned by a golden fixture so a later change is a visible drift to review. Both syntaxes derive these through the shared normalizer, so one invoice never renders a different reason text in UBL than in CII.
- **The invoice retention floor is now eight years, down from ten**, and the clock is anchored to the end of the issue year. An erased owner's retained invoices are kept `billing.retention.erased_financial_days` (default `2920`, was `3650`) counted from the end of the year each was issued (§147 Abs. 4 AO), not from the raw issue instant — so `billing:prune` no longer deletes a March invoice nine months before a December one from the same year. This is a visible behavior change: retained invoices become prunable up to two years earlier than before, which is the point (keeping them the full ten years over-retains an erased owner's personal data past its statutory obligation, in breach of storage limitation). The separate audit/book window (`audit_days`) stays ten years — a different record class under a different statute, deliberately not unified. A floor below eight years still refuses to boot unless you opt in for a jurisdiction whose minimum is genuinely shorter.
- Subscription lines are included in an owner's data export and removed by an erasure. They key on their subscription rather than on the owner, so both paths reach them by joining through it; the classification map that the eraser and exporter both read now carries this shape explicitly, so a future child table cannot be covered by one and missed by the other. The erasure does not rely on the foreign key cascading, because SQLite enforces foreign keys only when `PRAGMA foreign_keys` is enabled and that is the consuming application's setting, not the package's.

## [0.5.0] - 2026-07-22

### Fixed

- **DATEV exports are now written as locked batches.** The EXTF header's field 21 (`Festschreibekennzeichen`) was emitted as `0`, which marks a booking batch as still alterable after import. It is now `1`. This changes the bytes of every generated export file: if you keep golden copies of exports, regenerate them. A test now pins the flag's position and value against the EXTF 700 field layout rather than against whatever the exporter happens to produce.
- **An unrecognized country code no longer results in 0% VAT.** `EuOssTaxCalculator` treated any country it had no rate for as zero-rated, so a malformed or unassigned code (`"DEU"`, `"Germany"`, an empty string) was indistinguishable from a genuine supply outside the EU VAT area. A code that is not an assigned ISO 3166-1 alpha-2 country now raises `UnknownTaxCountry`; supplies to real countries outside the EU VAT area continue to be zero-rated as before.
- **A tax mode that cannot be resolved is refused at boot.** `billing.tax` was ignored by the support guard whenever it was not a string — for example after adding a sub-key underneath it, which turns the value into an array — and a mistyped mode name passed the guard as well. Both then fell through to "no tax", so every invoice was issued with 0% VAT and nothing surfaced it. Any value outside the resolvable set now raises `TaxModeUnsupported` during boot.
- **`billing.tax = 'stripe'` is recognized as provider tax by the boot guard.** The calculator resolves `'stripe'` as an alias of `'provider'`, but the guard classified it as a locally-computed mode. It therefore refused to boot on the very driver that mode requires, and accepted it on a driver that cannot apply it. Both sides now read the same classification.

### Added

- `TaxCalculatorFactory::MODES` and `TaxCalculatorFactory::PROVIDER_MODES` expose which values `billing.tax` accepts and which of them defer tax to the payment provider, so the boot guard and the calculator can no longer disagree about a mode.
- The credit ledger's shape is now asserted rather than assumed: a containment test keeps it to a single-owner balance with no value-exit path, so adding a withdrawal or an owner-to-owner transfer fails a test instead of passing silently.
- The eligibility gate is now held in place structurally. Any implementation of `Checkout`, `OneTimeCharge` or `SubscriptionActions` must consult `CanTransactMoney` before it does anything else, keyed on the contract and the dependency type rather than on a specific driver — so a new driver is covered the moment it appears, and a seam that omits the gate or runs it too late fails a test.

### Changed

- `PaymentRails` now documents why it is deliberately not eligibility-gated: the gate belongs at the entry seams where a payment begins, and gating the rails would refuse legitimate dunning retries for a subscriber who was eligible when they subscribed but no longer satisfies a consumer-supplied predicate.
- `ext-mbstring` is now declared in `composer.json`. The package already used it, so this documents an existing requirement rather than adding one — every Laravel application already satisfies it through the framework.
- The two tax failures quote the offending value back so an operator can see what was rejected, but the value is now stripped of control characters and bounded in length first. These messages are persisted into failure-reason columns and written to logs, where an unbounded value with an embedded newline can forge a log line.

## [0.4.1] - 2026-07-22

### Fixed

- Housekeeping to the published release notes; no functional or API change since 0.4.0.

## [0.4.0] - 2026-07-22

### Added

- **Marketplace seller-of-record posture (opt-in).** A new `billing.marketplace` config block and a `SellerOfRecordResolver` contract name and enforce who the seller of record is to the buyer on a routed sale: the deemed-supplier presumption for electronically-supplied services (Art. 9a VAT-IR (EU) 282/2011, CJEU C-695/20) versus a merchant-as-seller or a disclosed-intermediary posture for physical goods. It is fail-closed (an un-whitelisted posture, or `seller_of_record` on an electronic supply without a genuine Art. 9a rebuttal, is refused) and sits behind a master switch that is **off by default**, so single-merchant behavior is unchanged.

### Changed

- Every publishable asset now also sits under a shared `billing` umbrella tag, so `php artisan vendor:publish --tag=billing` publishes the config, migrations, views and translations in one go; the specific tags (`billing-config`, `billing-views`, …) still publish a single group.
- The README now carries a Laravel-versions badge next to the PHP-version badge, and `composer.json` declares its `$schema` so editors validate it — three of the standard package-skeleton conventions.
- The static-analysis composer script is now named `analyze`, the US spelling the rest of the package uses and the one `CONTRIBUTING.md` already documented; the PHPStan subcommand it wraps keeps its own name.

## [0.3.0] - 2026-07-18

### Added

- **Optional admin console.** A publishable Livewire admin console (`Livewire\BillingAdminConsole`) surfaces
  the billing metrics, the recent audit log and a comp-a-tier action on one screen — the UI counterpart of
  `BillingMetricsReporter` and `BillingAdmin`, for support agents who want a screen instead of the CLI. It
  mounts under `config('billing.admin.prefix')` (default `admin/billing`) and — like the account hub — is
  plain framework-agnostic Blade that renders only when Livewire is installed, so the billing core stays
  UI-free. It is **admin-gated, fail-closed**: mount, render and the action each authorize against the
  app-defined `config('billing.admin.ability')` Gate (default `billing-admin`), which denies everyone until
  your app defines it. The comp action validates the submitted tier against `config('billing.tiers')` — an
  unknown or empty key is refused, never written — so it cannot silently downgrade an owner, matching the
  `billing:tier:grant` CLI. The console meets WCAG 2.1 AA. New `billing.admin` config block; new `admin`
  translation namespace (seven locales).
- **ZUGFeRD / Factur-X hybrid PDF/A-3.** A new `Invoicing\ZugferdPdfInvoice` embeds the EN 16931 CII XML into
  a PDF/A-3 — the hybrid document that is both human-readable (the PDF) and machine-readable (the embedded
  invoice). The XML comes from `ZugferdCiiInvoice`; the source PDF is your own rendered invoice. Because a
  conformant PDF/A-3 needs a real PDF toolchain, this is an OPTIONAL capability: `composer require
  horstoeko/zugferd` (a `suggest` dependency) to use it. Without it the method throws a clear
  `MissingPdfEmbedder` — the lean core carries no PDF library, and the XML writers need none of it.

## [0.2.0] - 2026-07-16

### Fixed

- **The EU reverse charge no longer zero-rates a domestic sale.** A validated business in the seller's OWN
  country was granted the intra-EU reverse-charge zero-rate, silently under-charging VAT on every domestic
  B2B supply. The reverse charge is now applied only when the buyer's country differs from the seller's
  (`billing.company.country`) — a same-country supply is charged the normal domestic VAT, and when the seller
  country is unknown nothing is zero-rated (never under-charge).
- **A reverse-charge invoice no longer leaks VAT into its totals.** When a reverse-charge line carried a
  notional non-zero rate, both the XRechnung (UBL) and ZUGFeRD (CII) writers emitted the full VAT in the
  document total while the AE band showed zero — an EN 16931 violation (BR-CO-14 / BR-AE) that a validator
  rejects and that overstated the payable. The seller's tax and every AE band are now forced to zero.
- **The EU-OSS VAT table is matched case-insensitively.** A lower- or mixed-case country code (`de`) missed
  the upper-case rate table and silently charged 0% VAT; the code is now normalized before lookup.
- **A VIES member-state outage is no longer read as an invalid VAT id.** VIES answers HTTP 200 with a
  `userError` for a transient outage; that is now treated as unavailable (conservative), not invalid, so an
  outage neither rejects a real business nor lets the caller grant an unearned zero-rate.
- **The EU reverse charge now requires a validated VAT id.** The reverse-charge zero-rate was applied to any
  business that merely supplied a VAT id, even an unverified one — a VAT under-charge risk. An id must now be
  proven valid before a supply is zero-rated: a new `VatIdValidator` seam (VIES-backed `ViesVatIdValidator`,
  with a `NullVatIdValidator` default that proves nothing) validates it, and a VIES outage is treated
  conservatively (unavailable, never assumed valid), so a temporary outage can never grant an unearned zero-rate.
- **The hub navigation no longer breaks on an unregistered route.** A navigation entry whose route is not
  registered is now silently dropped instead of throwing, so the hub can host a consumer's own screen —
  registered in `billing.navigation` — that appears only once that route exists.
- **Disabled billing is a clean no-op façade.** With `billing.enabled=false`, the subscription, upcoming-invoice
  and invoice contracts now bind to no-op implementations — reads answer empty / null, mutations do nothing — so
  a clone without billing boots and reads without any provider keys, instead of resolving to the driver's
  implementations that would reach for them.
- **An untouchable (comped / manually-granted) tier is no longer offered as a swap target.** The plan catalog
  listed every other tier as a purchasable option, including tiers flagged `untouchable` — the comped or
  lifetime grants the provider webhook is forbidden to overwrite. They are now excluded from the swap options,
  so a customer can never self-serve their way onto a manually-granted plan.

### Security

- **Billing link-outs are validated before redirect.** Every redirect to a provider-hosted page — the hosted
  checkout, the hosted card page, the billing portal — now passes through a guard that accepts only an absolute
  http(s) URL with a host. A `javascript:` / `data:` scheme, a scheme-relative `//host`, or a bare path from a
  tampered or misconfigured driver payload is refused (the screen does not navigate; the portal route 404s), so
  a billing link can never become an XSS or an open redirect.

### Added

- **Account-hub app shell.** The account screens now render inside a publishable, framework-agnostic layout:
  a grouped sidebar navigation (driven by `Account\Navigation`, marking the active item for assistive tech),
  a skip link to the main content, a typed active document title, and a POST-logout form (never a GET link)
  shown only when the app registers a `logout` route. It needs no UI-kit dependency; publish `billing-views`
  to replace it wholesale with your own design system's shell.
- **External-billing link-out mode (No-/external-Merchant-of-Record).** When an external merchant of record
  owns billing, set `billing.link_out` (env `BILLING_LINK_OUT`) to its portal URL: the plan-change screen then
  links OUT to it and suppresses the entire in-app checkout surface (plans, swap/subscribe, coupon), because
  the app is not the merchant of record for those charges. The URL passes through the scheme guard, so a
  tampered or misconfigured value keeps link-out off rather than becoming an open redirect.
- **Grouped, runtime-aware account-hub navigation.** A new `Account\Navigation` renders the config-driven
  hub navigation as ordered, non-empty groups (each item may declare a `group` and `web_only` flag), and
  supplies a typed, localized active document title. An item whose route is not registered is hidden rather
  than throwing, and a `web_only` item is hidden on a native runtime (`billing.runtime`) — for flows an app
  store forbids in-app. Group labels come from `billing::account.nav.group.*`. The new `BILLING_RUNTIME`
  env var selects `web` (default) or `native`.
- **Coupon redemption with enforced limits.** A new `CouponRedeemer` atomically redeems a package-owned
  coupon, enforcing that it is active, not expired, under its global `max_redemptions` cap, and not already
  redeemed by the owner. The cap check and the count increment run inside one transaction under a row lock,
  so two accounts racing for the last redemption cannot both win; the per-owner unique index makes a
  double-apply impossible even under a race. A blocked redemption raises a `CouponUnavailable` with the
  reason (inactive / expired / limit reached / already redeemed) instead of silently over-granting.
- **ZUGFeRD / Factur-X CII e-invoice output.** A new `ZugferdCiiInvoice` renders a stored invoice as
  EN 16931 in UN/CEFACT CII syntax — the XML that ZUGFeRD and Factur-X embed in a hybrid PDF/A-3 — as the
  twin of the existing XRechnung UBL writer. Both syntaxes now build from one shared, normalized invoice
  model (seller, buyer, lines, per-rate tax bands), so a standard-rated sale, an intra-EU reverse charge
  (category AE with the exemption reason, never zero-rated Z), a credit note (type 381), and multi-rate
  bands can never drift between the two outputs of the same invoice.
- **Live screens refresh themselves without broadcasting.** When realtime is off, the subscription and
  payment-recovery screens fall back to a short, self-limiting poll: they refresh every 5s while a transition
  resolves (the post-checkout "activating" wait, a past-due recovery), stop after ~30s so a stuck state never
  polls forever, never poll a settled state, and never poll at all when realtime broadcasting is on.
- **A headless realtime bridge relays broadcast toasts.** The `AccountRealtime` component listens on the
  owner's private channel and re-dispatches each broadcast toast as a `wirekit-toast` browser event for the
  shell's toast region — clamping an untrusted payload (a non-empty message, a known level) before it reaches
  the client.
- **Opt-in realtime for the account hub.** Two broadcast events — `AccountBillingUpdated` (a live-refresh
  trigger on the owner's private channel) and `AccountToastNotified` (a `{message, level}` toast) — let the hub
  screens update without a reload. Off by default (`billing.realtime.enabled` / `BILLING_REALTIME`): a plain
  install broadcasts nothing and falls back to a bounded poll. The channel follows the resolved billing owner
  (user or team), so one owner never receives another's stream.
- **A package-owned coupon model.** Coupons are no longer a provider-only concept: the package now has its own
  `billing_coupons` table (percent or fixed value, duration, expiry, redemption limits, optional Stripe-coupon
  mapping) and a `billing_coupon_redemptions` ledger with a per-owner unique index — so the local engine can
  apply a coupon and a double redemption by the same owner is impossible at the database level.
- **The subscription screen shows when access ends.** A canceled subscription still in its grace period now
  shows the date access ends, and an ended one shows the date it ended — read from the local subscription row,
  never a provider call.
- **Buy one-time add-ons (top-ups) from the plan screen.** The subscription-management screen now has an
  add-ons section listing the configured one-time purchases and their price, each with a Buy button that sends
  the owner to the provider's hosted one-time checkout. The client submits only the add-on key — the price is
  resolved server-side, so it cannot be injected — an unknown key is refused, and the checkout URL is
  scheme-validated before the redirect. It is the section the usage screen's top-up call-to-action links to.
- **The plan screen shows the card on file.** The subscription-management screen now displays the brand and
  last four digits of the stored payment method — mirrored from local columns, never a provider call — so the
  owner sees which card a swap or new subscription will charge.
- **Invoice downloads are a bookmarkable route, and the list loads older invoices on demand.** Each invoice
  row now links to a dedicated `…/invoices/{id}/download` route — a plain href that works without JavaScript and
  owner-checks the id before streaming — instead of a Livewire action, and a "Load older" control widens the
  page up to the provider's cap when more invoices exist than the first page shows.
- **Usage history can flag an unmetered dimension.** A history provider that surfaces a dimension which was on
  an unmetered / BYOK tier in a past period can mark its `PeriodUsage` as not metered, and the screen shows
  "not metered" rather than a used count that would mean nothing there.
- **The usage screen points you at the right remedy when a meter runs low.** A metered dimension that is
  warning or over now shows a policy-driven call-to-action: a hard-capped meter offers an upgrade (the only way
  to raise a hard ceiling), while a soft or degrading one offers a top-up of more units. A dimension
  comfortably inside its allowance shows none.
- **Tax and e-invoicing fields on the stored invoice.** The invoice record now carries the buyer routing
  reference (Leitweg-ID, EN 16931 BT-10), a VAT exemption / reverse-charge note, and EU One-Stop-Shop markers
  (scheme flag, destination country, and the applied rate as an exact decimal) — all optional, all frozen once
  the invoice is issued, so a XRechnung / ZUGFeRD or OSS export reads them straight from the immutable document.

## [0.1.1] - 2026-07-16

### Fixed

- **A deleted account could keep billing.** `BillingEraser` / `billing:erase` erased an owner's data but did
  not cancel their subscription, so a deleted owner stayed active and charging at the provider. Erasing now
  fires a `BillableAccountDeleting` event that cancels the subscription immediately, before the erase —
  tolerant of a provider blip (logged, deletion continues, never orphaned). Apps with their own delete UI can
  dispatch the event directly, right after re-confirming identity and before deleting the user.

## [0.1.0] - 2026-07-16

### Added

- **The account-hub UI is an optional dependency.** The billing core — models, webhooks, invoicing, tax and
  the contracts — needs no Livewire; adopt the package without it and wire your own UI, or install
  `livewire/livewire` and get the nine ready-made hub screens. Registration is guarded, so a core-only
  install never loads a screen.
- **A usage-history account screen.** `/account/usage/history` shows an owner's usage across finished billing
  periods plus their add-on top-up timeline, read straight from the stored counters — never a provider call —
  behind a project-bindable `UsageHistoryProvider` seam.
- **Irreversible cancelation re-confirms the acting user's identity.** The immediate-cancel action verifies
  a password (or, for an account that signs in with a provider, the account email), rate-limited per user and
  never stored — so a hijacked session cannot end someone's billing without proving who is behind it.
- **`PricingCatalog::cards()` — one config-authoritative source for the in-app upgrade grid and a public
  `/pricing` page.** Each tier's feature bullets live in config as an ordered list of translation keys (your
  app owns the strings, in every locale), resolved by `PricingCatalog::bulletsFor()`; an optional
  `highlight`/`badge` per tier emphasizes a card. Because both surfaces render from the same `PricingCard`
  read-model and the bullets come only from config, the grid and `/pricing` cannot drift into different
  promises. A malformed `features` entry yields no bullets rather than a raw key on the page.
- **Customers are warned BEFORE a metered allowance runs out, instead of first learning about it by being
  refused.** Crossing a meter's warn threshold now sends a `QuotaWarningNotification` with the meter and the
  numbers that matter — once per meter per period, claimed with a single conditional update on the counter
  row, so two requests crossing the threshold at the same instant warn once and the next period warns again.
  Recording usage can never fail because a warning could not be delivered: the usage is already on the books.
  The threshold itself is now configurable per meter (`tiers[].metered[].warn_threshold`, default 0.8, and
  validated) — it used to be a hardcoded 0.8 that no config could move.
- **Customers are welcomed when their subscription goes live**, naming the tier they are now on — deduped
  once per subscription, so recovering from a past-due state back to active does not re-welcome them.
- **Customers are now told when a payment SUCCEEDS, and when a cancelation takes their access away.** Both
  notices already shipped but nothing ever sent them: `PaymentSucceededNotification` (the receipt — the other
  half of the money conversation the package only had the failure side of) and `SubscriptionCanceledNotification`
  (with the date access actually ends — the part customers write in about). Two new webhook effects wire them:
  `SendPaymentReceipt` (dedups on the payment reference, so a provider redelivery cannot send a second receipt
  for money paid once) and `SendSubscriptionCanceledNotice` (fires on the grace state only, and says nothing
  when there is no end date to announce). Rebindable through the new `ReceiptNotifier` / `SubscriptionNotifier`
  seams.
- **The scheduled `billing:run` cycle advance now runs `withoutOverlapping`**, like every other scheduled
  command — so a cycle advance that runs long can never have a second copy start on top of it and
  double-advance the same due subscriptions.
- **A `billing.dunning` middleware sends a past-due owner somewhere they can fix it.** Put it on the
  surfaces that need a working card: a browser request from an owner whose payment has failed
  (`past_due`/`incomplete`) is redirected to the payment-recovery screen, while an API/JSON request gets a
  `402 Payment Required` (configurable via `billing.dunning_status`). The recovery screen itself is never
  blocked, so there is no redirect loop, and the decision reads only the local subscription row — no
  provider call on the hot path.
- **`EntitlementsResolver` — an owner-facing numeric-limit guard.** `limit($owner, $key)`,
  `remaining($owner, $key, $used)` and `allows($owner, $key, $used, $delta = 1)` resolve the owner's tier
  and check a proposed usage against the tier's ceiling (uncapped → always allows; otherwise
  `used + delta <= limit`), so a consumer enforces a limit the same way everywhere instead of re-writing the
  `<`/`<=` comparison per call site. The count of what's used stays the project's `UsageProvider`; only the
  comparison lives here. The comparison math carries 100% mutation coverage (every off-by-one mutant killed).
- **The account hub degrades one failing panel instead of 500-ing the whole page.** A `DegradesGracefully`
  concern gives a screen a per-panel error boundary: when a panel's data assembly fails for a reason outside
  the app's control (a provider API blip, a project's own `UsageProvider` throwing), the failure is reported
  and that panel shows an inline "temporarily unavailable" notice while the rest of the screen renders — so a
  customer who came to cancel is not blocked by a usage gauge that could not load. Every account-hub screen
  with a provider read now uses it — usage, subscription (next-invoice preview), invoices, payment methods and
  payment recovery — so a provider outage degrades one panel to a notice instead of 500-ing the page; the
  `account.degraded` (and usage-specific `account.usage.unavailable`) strings ship in all seven locales.
- **A `BillingMetrics` read-model — MRR and subscription counts for your admin dashboard.**
  `BillingMetricsReporter::compute()` returns active subscriptions, trials, count in dunning, churn over a
  trailing window, and MRR — all from the local subscription rows, with no provider round-trip. MRR is the
  declared list price monthly-normalized (a yearly tier ÷ 12) summed in `billing.currency`: what the catalog
  says you charge, independent of a provider coupon or proration.
- **Two operator commands: `billing:datev:export` and `billing:tier:grant`.** `billing:datev:export` writes
  a period of issued invoices as a DATEV EXTF "Buchungsstapel" — with no `--from`/`--to` it exports the
  previous calendar month, so a monthly cron hands last month's bookings to the tax advisor; drafts (no
  issue date) are excluded and an out-of-order or unparseable period is refused. `billing:tier:grant` is the
  terminal form of a support comp (`BillingAdmin::comp`): it writes the tier column and records the grant on
  the audit trail, refuses a tier key no `billing.tiers` entry declares, and warns when the tier is not in
  `billing.untouchable_tiers` (where the next provider webhook could overwrite it).
- **The usage screen now shows a meter's prepaid balance.** When an owner bought prepaid units of a meter
  (an add-on that grants units, not money), the usage screen shows that rolling balance alongside the cycle
  usage — the units they paid for and still hold, distinct from the per-cycle included allowance which resets.
- **A self-contradictory billing config now fails at boot instead of silently mis-tiering a customer.** A
  `BillingConfigValidator` checks the invariants a misconfiguration would otherwise break quietly — the
  `zero_tier` is a defined tier, every `untouchable_tiers` entry exists, a tier references only defined
  dimensions, each `warn_threshold` is within 0..1, the dunning `after_days` strictly ascend, `owner` is
  `user` or `team`, and every `price_display.currency` is a valid ISO 4217 code — and refuses to boot with a
  clear message on a violation. It is a no-op on the shipped default, so a fresh install boots clean.
- **A tier or add-on `provider_price` can now be a per-provider map, not just one id.** Declare
  `'provider_price' => ['stripe' => 'price_...']` (a map keyed by driver name) and the new `ProviderPriceResolver`
  hands each driver its own price; a scalar id still means "one price for the active driver". The tier/add-on
  key stays the only thing the client submits — the price is always resolved from config (anti-price-injection).
- **A local tax mode can no longer silently under-collect.** Tax is a driver capability: `provider` defers
  to a provider that computes it (Stripe Tax), `eu_oss` computes VAT from a local table, `none` adds nothing.
  A local mode configured on a driver that hands the charge to the provider (Stripe) would compute VAT the
  provider never charges — the customer is under-charged and nothing looks broken until the return does not
  add up. The package now refuses to boot on that combination (a `TaxSupportGuard`, like the metering guard),
  and symmetrically refuses `provider` on a driver that computes no provider tax. A local tax figure reaches
  an invoice only on a driver that produces the invoice itself.
- **`Billing::fake()` — a recording test fake for the money seams.** One call binds a fake to `Checkout`,
  `SubscriptionActions` and `OneTimeCharge`, and gives ready-made assertions
  (`Billing::assertSubscribeStarted($owner, 'pro')`, `assertSwapped`, `assertCanceled`, `assertPurchased`,
  `assertNothingCharged`, …) — the same shape as `Bus::fake()`. The documented `Event::fake()` /
  `Mockery::spy` recipe still works; this is the convenience layer.

- **Reverse-charge invoices now render correctly per EN 16931.** An intra-EU B2B reverse charge is emitted
  as VAT category `AE` at 0% with the exemption reason (BT-121 `VATEX-EU-AE` / BT-120 "Reverse charge"),
  not the zero-rated `Z` a conformant validator rejects. The `reverse_charge` fact is frozen on the invoice
  (read from Stripe's `customer_tax_exempt`). Issued invoices are now GoBD-immutable: their number, amounts,
  currency, tax treatment, date and lines cannot change after they are recorded (the status still may).

- **Coupons are actually applied at Stripe checkout.** A coupon code entered on the plan screen is resolved
  by the `DiscountResolver` and, when mapped (`billing.coupons.<code>.stripe_coupon`), passed to Stripe as a
  Checkout Session discount — Stripe owns the money math and its native redemption limit.

- **Generic trials (a free trial with no subscription) now work end to end.** An owner mid-trial used to
  resolve to "never subscribed" and get no access — nothing built a subscription state from the owner when
  there was no subscription row, so the `generic_trial` state was unreachable. Now `Trials::grant($owner)`
  starts a trial on the owner's own `trial_ends_at`; while it runs, the owner resolves to the `GenericTrial`
  state (which grants access) and the tier resolver unlocks the configured `billing.trial.generic_tier`; when
  it lapses the owner falls to `churned` (a customer on file) or `none`. Generic trials are opt-in — without
  a `generic_tier` there is nothing to unlock, so granting one is a no-op.

- **A subscription trial can now skip the up-front card.** `billing.trial.requires_payment_method` (default
  `true`) controls whether checkout collects a payment method before the trial; set it `false` and Stripe
  collects the card only if the trial converts (`payment_method_collection: if_required`).

- **The trial policy is fully configurable, globally and per tier.** A single `TrialPolicy` now resolves the
  trial length (`billing.trial.days`), its kind (`billing.trial.mode`: `none` / `subscription` / `generic`)
  and whether a card is required, each overridable per tier under `billing.tiers.<key>.trial`. The default is
  no trial. `mode` is derived when unset — a configured `generic_tier` implies a generic trial, a positive
  length alone implies a subscription trial — so a generic-trial app never *also* attaches a subscription
  trial at checkout (which would trial the owner twice).

- **The account screens now show exactly one trial call-to-action per state.** While an owner is on a trial,
  the subscription overview shows a single policy-driven CTA — subscribe (generic trial), add a payment method
  (a card-free subscription trial), or review the plan (a trial with a card on file) — the plan screen shows
  the days remaining plus the card hint, and the usage screen notes that the usage shown is the trial tier's
  entitlement. All localized in the seven shipped locales and theme/token-aware.

- **The trial-ending reminder is now actually sent.** `TrialEndingNotification` existed but nothing ever
  dispatched it — a customer's trial could lapse into a first charge with no warning. The Stripe
  `customer.subscription.trial_will_end` webhook now maps to a neutral `TrialEnding` domain event, and a
  registered effect sends the reminder (localized, transactional/non-suppressible) with the trial end date.
  It is sent once per trial end, not once per provider redelivery, and only after the delivery transaction
  commits. A host can swap the delivery via the new `TrialNotifier` seam.

- **Per-seat billing now stays in sync with a team's actual membership.** A team could add or remove members
  all cycle and keep being billed for whatever seat count the provider happened to hold — the seat-sync logic
  existed but nothing ever called it. The wiring is now complete:
  - A `HasSeats` trait derives `seatCount()` from a configurable active-members relation
    (`billing.seats.membership_relation`); pending invites do not occupy a paid seat, and a team never bills
    below one. Adopt it on a team model — no migration, the package does not own your membership table.
  - A provider-neutral seat-sync service reconciles the billed quantity to the seat count only when they
    actually differ, firing a `SeatQuantityChanged` event on a real change. It delegates the provider call to
    a `SeatBilling` seam (Stripe books proration natively) and **refuses, loudly, to bill below the occupied
    seat count** — a silent under-bill is exactly what must not happen quietly.
  - A queued, after-commit `SyncSeatsOnMembershipChange` listener runs the re-sync on your team join/leave
    events. Name them in `billing.seats.membership_events` (and, for an event that cannot implement the
    `AffectsSeats` contract, the listener reads the team off a configured property).

- **Metered usage now has drift guards, so unbilled usage surfaces as an alert instead of on the customer's
  invoice.** The local ledger and the provider's meter are two sources of truth that can quietly diverge —
  a report accepted on our side that never arrived, a meter that stopped rating. Three additions close the
  gap:
  - `billing:usage:reconcile` (scheduled daily) reads the provider's own aggregate back for every owner,
    meter and current cycle and compares it against what was reported — netted of prepaid units, so a
    prepaid customer is not falsely flagged. Any disagreement raises a `UsageReconciliationDrift` event and
    the command exits non-zero. It also raises `UsageBacklogStalled` when the oldest unreported usage has
    been held longer than `billing.metering.stall_hours` (default 6) — past the point it can still be
    billed. Both events dispatch through the framework, so a host app can listen or alert on them.
  - `billing:meters:check` now verifies each metered price against the provider as well as the meter: that
    the price is meter-backed and its meter matches `provider_meter`, that the currency matches the tier's
    `unit_price`, and — the one that hides — that the graduated first tier's free allowance equals the
    tier's `included`. The allowance lives in the provider's price; `included` only drives the gauge, so a
    mismatch silently gives the customer a different number of free units than the interface promises.
  - An `invoice.upcoming` webhook now force-flushes that customer's usage outbox immediately, so usage lands
    on the invoice being finalized rather than a cycle late — after finalization a meter event is not
    retro-billed at all.

- **An add-on can grant usage UNITS instead of money credit.** Configure
  `billing.addons.<key>.grants = ['meter' => 'emails', 'units' => 1000]` and buying it tops up a prepaid
  balance for that meter rather than the owner's money balance. The rules: the tier's per-cycle `included`
  allowance is spent FIRST and the bought units only after it (so free units are never left unused while the
  customer's own burn); `included` expires with the cycle but **prepaid never does** — paid is paid; and
  prepaid-covered usage is **netted out before the provider is told about it**, because the provider's price
  knows nothing about prepaid and would otherwise bill the customer a second time for units they had already
  paid for. The coverage is carried per usage event, so a second flush inside the same cycle cannot subtract
  the same units twice. A refund claws back only the units NOT yet consumed, proportionally to the money
  returned — the ones already spent delivered their value.
  The reservation lock now defends the prepaid balance alongside the cycle counter, so a bought unit cannot
  be sold to two concurrent requests; that guarantee is proven with two real connections on PostgreSQL and
  MySQL, because SQLite compiles `lockForUpdate` to nothing and could never show it.

- **The read-only dunning gate is resolvable.** `DunningGuard` — which answers whether an owner's dunning
  state blocks access, from the local subscription row alone (no provider call) — now has a default binding,
  so a consuming app can `app(DunningGuard::class)->blockingState($owner)` to gate a feature on it. It had an
  implementation but no binding, so resolving the contract threw.

- **A payment method being removed now notifies the owner.** When a card is detached or a mandate is revoked
  (`payment_method.detached`), the owner is told a payment method was removed from their account — a prompt to
  re-add one before the next renewal fails, and a security signal if they did not remove it themselves.
  Without it the loss was only noticed reactively, when the renewal charge failed. The owner is resolved from
  the event's `previous_attributes` (the customer the method was attached to before the detach), so no local
  mandate store is needed. Adds a `MandateNotifier` seam — separate from `DunningNotifier`, so a consumer that
  implemented the latter is unaffected — with a localized default notification (seven languages). A SEPA
  mandate that goes inactive while its method stays attached (`mandate.updated`) still surfaces reactively via
  dunning; mapping it needs a local mandate store and is left for when a flow needs it.

- **Usage drift guards — `billing:meters:check` and `billing:usage:reconcile`.** Metered billing had no way
  to notice its two silent failures. `billing:meters:check` verifies every configured `provider_meter` exists
  and is active at the provider (a new provider-neutral `MeterInspector` seam, backed by Stripe's meter list),
  so a metered tier pointing at a meter that was never created — or was later archived — is caught by a deploy
  check instead of by an under-charged invoice a month later; it exits non-zero on a miss.
  `billing:usage:reconcile` surfaces usage that was recorded but never billed (rollups the flusher gave up on),
  sums the unbilled quantity, and with `--redrive` returns them to pending so the next flush retries them once
  the cause is fixed.

- **A real audit trail — who did it, not just what happened.** The billing ledger recorded a handful of admin
  actions and nothing else: three of the four webhook effects wrote nothing (including the tier change
  itself), the in-app cancels and swaps wrote nothing, and there was no actor — so "why is this customer on
  Free?" could not be answered, and "the customer canceled" could not be told from "an admin canceled them".
  Every money movement and entitlement change is now recorded with an ACTOR (the specific user or agent) and
  a SOURCE (customer / admin / webhook / system): plan grants and revokes, credits and clawbacks, dunning
  notices, in-app cancels/resumes/swaps, comps, refunds and erasure. The ledger is append-only — a row can
  never be updated and can only be deleted by retention pruning or an erasure request, enforced by a model
  guard and an architecture test, so it is tamper-evident. `billing.audit.level` chooses `money` (the
  default: every money/entitlement event, never noisy) or `all` (also the high-volume navigational events).
  `billing:prune` ages it out on a retention window (`billing.retention.audit_days`, default ten years), and
  `billing:export` includes an owner's audit history in their subject-access export.

- **Add-on credit is now actually spendable, and visible.** A one-time add-on credited the owner's balance,
  but nothing ever read it — the credit sat in a database column the customer could neither see nor spend.
  The balance is now mirrored onto the Stripe customer balance through a new provider-neutral `CreditSync`
  seam, so it is applied automatically against the customer's next invoice, and the account hub shows it
  ("You have €5.00 in account credit — applied to your next invoice"). The push is idempotent (the reference
  is the Stripe idempotency key, in the request header where a retry cannot double-credit), and it mirrors a
  clawback the same way when a refunded add-on's credit is reversed. The package ledger stays the source of
  truth: a driver that cannot hold a provider balance (`DriverCapabilities::supportsProviderCredit` false)
  binds a no-op sync and the balance stays local, so earning and showing credit works on every driver.

- **The package now persists the invoices it renders.** XRechnung and DATEV always existed as renderers, but
  nothing ever wrote the table they read — e-invoicing was a renderer with no data. A webhook effect now
  persists every invoice Stripe finalizes as an immutable `InvoiceRecord`: its provider number (never a second
  one of our own), its buyer snapshot frozen at finalization (§14 UStG), and its line and tax breakdown. It is
  idempotent on the provider invoice id, so a redelivery — or the finalize-then-pay pair of webhooks Stripe
  sends for one invoice — converges to a single row that ends up paid. The tax is derived as total minus
  subtotal, so a Stripe API change to the tax field can never silently zero it. Adds the neutral
  `InvoiceFinalized` event and `InvoiceSnapshot`, provider-neutral so a future driver produces them too.

- **Credit notes are now persisted when money goes back.** A refunded invoice used to leave the books
  overstating turnover: the charge was recorded, the credit was not. When a provider issues a credit note
  (Stripe's `credit_note.created`), the package now stores it as its own `InvoiceRecord`, linked to the
  invoice it credits and to that invoice's own number. DATEV books it as a Haben (credit) rather than a Soll,
  and XRechnung renders it as an EN 16931 credit note (type code 381) that references the original invoice —
  the amounts stay positive, because the document type, not a sign, carries the credit meaning. It is
  idempotent on the provider credit-note id, and the buyer is copied from the invoice's frozen §14 buyer.
  This is the accounting counterpart to the existing refund handling (which moves the money): the two are
  separate concerns, and a credit note carries the line and tax detail a raw refund event does not. Adds the
  neutral `InvoiceCredited` event and `CreditNoteSnapshot`, provider-neutral so a future driver produces them.

- **The Stripe API version is now pinned by the package, not inherited from the SDK.** Stripe versions its
  API by date, and the shape of a webhook payload follows the version — so a routine `composer update` of
  `stripe/stripe-php` in a consuming app could silently move the version the package's webhook mapper parses
  against, and a removed field makes a real billing event quietly stop firing rather than erroring. The
  version is now a package constant (overridable via `billing.stripe.api_version`), sent on every Stripe
  call, and an architecture test refuses any code that reads the version from the SDK instead. Moving it is
  a deliberate act to be proven against the live-Stripe suite. `stripe/stripe-php` is now a direct
  dependency (it arrived only transitively via Cashier before), and a Renovate rule isolates its updates so
  the live-Stripe suite runs before any SDK bump merges.

- **Erase and export an owner's billing data** — `billing:erase {owner}` and `billing:export {owner}`. The
  package stores personal data on your behalf (the buyer on an invoice; the raw webhook payloads, which carry
  the customer's email, name, billing address and card last four) and until now offered no way to answer a
  GDPR request about any of it — while `$user->delete()` left seven tables orphaned and the owner's own stored
  provider API keys sitting in the database with no owner at all.
  The erase deliberately KEEPS the invoices: a valid invoice must carry the buyer's name and address (§14
  UStG) and must be kept for years (§147 AO, §14b UStG), and the right to erasure yields to a legal retention
  obligation (Art. 17(3)(b)). Those rows are unlinked from the owner and kept; everything else is purged, the
  webhook payloads are scrubbed, and a credit balance the customer was still owed is written to the audit
  ledger before it goes rather than vanishing quietly. Deleting the customer at the provider is opt-in
  (`billing.erasure.forget_customer`) because it is irreversible and cancels their live subscriptions there.

- **Guardrails around erasure and retention**, because it is critical and EU law leads. The package now
  refuses to boot if the financial-record retention window is set below the ~10-year statutory floor
  (§147 AO, §14b UStG) unless an operator opts in on purpose (`billing.retention.allow_below_statutory_minimum`)
  — the defaults are a floor, and keeping data longer is always allowed. `billing:erase` asks for
  confirmation before erasing in production (bypass with `--force` for an automated pipeline). And an
  architecture test refuses any owner-keyed `billing_*` table left unclassified for erasure — the exact way
  a table would otherwise be silently skipped.

- **`billing:doctor`** checks that your Stripe webhook endpoints render payloads in the API version the
  package is pinned to. The pin controls the version we SEND, but a webhook payload's shape follows the
  version of the ENDPOINT that receives it — a setting that lives at Stripe — so an endpoint left on an
  older version delivers a shape the mapper was not written for, and the failure is silent (a real event
  just stops firing). The command surfaces that drift with a non-zero exit for CI.

- **`billing:prune`** (scheduled daily) is the retention clock: it ages out stored webhook payloads
  (`billing.retention.webhook_payload_days`, default 90 — long past the provider's own redelivery window,
  which is the only reason they are kept) and removes an erased owner's retained financial records once the
  law no longer requires them. A payload whose effects are still owed is never pruned, however old it is.

- **A hard limit that actually holds.** `UsageRecorder::meter($owner, 'emails', 5_000, fn () => $mailer->send($batch))`
  claims the allowance under a row lock BEFORE the work runs, records what the work really consumed, and
  hands the rest back — reserve 5 000 sends, make 4 812, and the other 188 return to the allowance. The
  quota gate shipped earlier is a point-in-time read: two simultaneous requests read the same number and
  both pass it, so an owner could beat a hard-stop limit by exactly the number of requests they fired in
  parallel. `meter()` is the oversell-safe path; the gate is the cheap pre-check in front of it. If the
  work throws, the allowance is handed back and nothing is billed. A hold that is never settled EXPIRES
  (`billing.usage.hold_seconds`, default 15 minutes) and is reclaimed by `billing:usage:flush` — a worker
  killed mid-request must not cost a paying customer the rest of their month. The guarantee is proven
  against real PostgreSQL and real MySQL with two concurrent connections, because SQLite compiles
  `lockForUpdate` away and cannot prove it at all.

- **Webhook effects no longer take each other down, and failed ones are no longer lost.** Every effect a
  webhook triggers (sync the plan, credit the add-on, send the dunning notice) now runs in its own queued
  job. Before, they ran in a loop inside the provider's own HTTP request: one effect that threw aborted
  every effect after it and answered the provider a 500, and the retry re-ran the effects that had
  already succeeded. Now an effect that fails fails alone, retries on its own (`billing.webhooks.tries`),
  and the provider gets its 204 immediately — a slow effect can no longer hold the request open or read
  as an outage. Point billing work at its own queue with `billing.webhooks.connection` / `.queue`.

- **`billing:webhooks:replay`** re-drives stored deliveries whose effects failed. Every verified delivery
  is now recorded with its raw payload, so work can be redone from what the provider already sent — long
  after the provider has stopped redelivering (Stripe gives up after about three days). Select with
  `--failed`, `--event=<id>`, `--since`; preview with `--dry-run`. Replay is safe to run twice: it goes
  through the same ledger the live path does, so an effect already handled is skipped and nobody is
  credited or mailed a second time.

- **A webhook effect ledger** (`billing_webhook_effect_runs`) records what each effect did, still owes, or
  failed at, per provider reference — which is what makes both the retry and the replay safe.

- **Seven shipped languages.** The account hub and its emails are now translated into English, German,
  Spanish, French, Italian, Dutch and Portuguese, informal register throughout — a consumer gets all
  seven out of the box. A locale-parity test keeps every locale in exact key parity with the source, so
  a new string can never ship half-translated.

- **`billing:sync`** reconciles subscriptions from the provider onto the local rows — the bulk version of
  the post-checkout reconcile, for backfilling after a webhook outage. It applies each subscription
  through the same plan-sync effect the webhook uses, so its recency guard means a sync can never
  overwrite a newer webhook state; it only moves a stale row forward. One owner's provider hiccup is
  reported and skipped rather than aborting the sweep. Scope with `--owner`, preview with `--dry-run`.

- **Escalating dunning.** A scheduled `billing:dunning:advance` walks the dunning ladder for delinquent
  owners: each day it sends the next rung's suspension warning once its `after_days` is reached, and
  charges that rung's configured late fee (added to the next invoice via a new `LateFees` seam — a no-op
  by default, a Stripe pending invoice item when Stripe is active). Before this, an owner got one notice
  on day zero and then silence until a surface returned `423 Locked` with no warning. The rung reached is
  tracked on the subscription and resets when it recovers, so each warning fires exactly once and a
  relapse restarts the escalation. The escalation rides a new `SuspensionNotifier` contract, separate
  from the published `DunningNotifier`, so nothing a consumer already implemented breaks.

- **The quota is actually enforced now.** A `billing.quota:<meter>` route middleware (and a `UsageGate`)
  refuses a request that would take an owner past a BLOCKING metered allowance, so the four metering
  policies finally differ: hard-stop and refuse block (a configurable 429 by default), degrade serves but
  flags the response, fair-use never blocks. The gate is a point-in-time pre-check the app pairs with
  recording; an app needing oversell-safe atomicity still reserves through `UsageMeter`. Adds
  `UsageGate`, the `QuotaExceeded` exception and the `QuotaDecision` value object.

- Card-expiry awareness on the payment-methods screen: a stored card now shows an "expired" or "expires
  soon" badge instead of a bare date, computed from a card being valid through the end of its printed
  month. Card expiry is the biggest source of involuntary churn. Adds `PaymentMethod::expiresAt()`,
  `hasExpired()` and `isExpiringWithin()`.

- A scheduled `billing:cards:warn` command (daily) proactively emails an owner whose default card is
  about to expire — the preventable half of involuntary churn — with a configurable window
  (`billing.cards.warn_within_days`, default 30) and a `--dry-run`.

- The usage screen's over-limit callout now reflects the meter's policy: a hard limit reads as danger, a
  degrade as a warning, and a fair-use (soft) allowance as neutral information ("billed beyond it")
  rather than an alarming red — the same allowance was previously colored as an error for every policy.

- **A refunded, disputed or admin-refunded add-on now claws back the credit it granted.** When a
  one-time add-on's charge is refunded (`charge.refunded`), a dispute over it is lost
  (`charge.dispute.closed` with status `lost`), or a support agent refunds it through `BillingAdmin`, the
  credit is reversed automatically, keyed on the payment reference the purchase recorded. A won dispute
  reverses nothing. Stripe reports a cumulative refunded total, so a partial refund reverses only its
  part, a lost dispute after a partial reverses the rest, and a redelivery reverses nothing — and a
  refund of anything that is not a tracked add-on reverses nothing at all. The reverse, the credit debit
  and the audit line commit in one transaction, so a mid-way failure rolls the whole thing back rather
  than marking the purchase reversed without clawing the credit. The credit balance is allowed to go
  negative (a customer refunded credit they already spent owes it back). Adds `CreditLedger::debit()` and
  `AddonPurchases::reverse()`.

- **`billing:install`** publishes the config and generates a migration that adds the tier column and the
  Cashier customer columns to your owner model's own table — the columns no package migration can create
  without knowing which table they belong to. Before this, a fresh install rendered "Free" while every
  plan-sync webhook died at "column not found".

- A **default `TierResolver` binding** (`ColumnTierResolver`), so a fresh install resolves a tier without
  any extra wiring; an app that keeps no tier column rebinds to `SubscriptionTierResolver` in one line.

- Domain events are now dispatched through **Laravel's event dispatcher** as well as the package's own
  effect bus, so a host app can `Event::listen` or `Event::fake` a `PaymentSucceeded` /
  `SubscriptionStateChanged`. The package's shipped effects still run either way.

- A populated default **account-hub navigation** (with translations) and an `account.stylesheet` seam so
  the hub ships with working navigation and a documented way to style it.

- **Subscribing — the entrance a billing package needs.** A visitor can now become a paying subscriber
  from the plan screen: the client submits the tier key (never a price), and the package opens a hosted
  checkout in subscription mode. The trial, the provider's tax and VAT-id collection, promotion codes and
  the billing address all ride on that one session, and the card — with SCA / 3-D Secure — is captured on
  the provider's own page. On return, the subscription is reconciled onto the local row on the spot, so a
  paying customer is never shown "Free" while the webhook is still in flight. An owner who already
  subscribes swaps in-app instead of opening a second subscription (which would double-bill them). The
  checkout return URLs default to the hub's own routes, so a fresh install can take a payment without any
  URL configuration.

- Per-tier `legacy_prices`: retired provider price ids that still resolve to a tier. Rotating a price in
  the provider no longer strands the subscribers still on the old price — they keep the tier they pay
  for — while a new subscription is always sold at the current `provider_price`.

- Usage-based billing, end to end: recorded usage is now reported to the provider that bills it, so a
  product charging "19 EUR a month plus 0.50 EUR per 1 000 emails, first 10 000 included" is expressible
  in full. A scheduled `billing:usage:flush` folds each cycle's usage for an owner and meter into a
  single report and hands it to the provider (Stripe's billing meters, via the new provider-neutral
  `UsageReporter`).
  - **A retry cannot double-bill.** The identifier is minted when the usage is recorded and replayed
    unchanged on every retry, so the provider recognizes it and bills the usage once, no matter how many
    times the network made us ask. An already-attempted report is never folded into a new one. Note that
    Stripe answers a replayed identifier by REJECTING it (`duplicate_meter_event`) rather than accepting
    it quietly; that rejection means the usage is already billed, and is treated as the success it is.
  - **An outage cannot lose revenue.** Reports retry with exponential backoff; usage that genuinely
    cannot be reported is marked failed and logged as an error, because it is money that will not be
    collected unless someone acts. It is never dropped quietly.
  - **A metered tier cannot run on a driver that cannot meter.** The app refuses to boot instead — the
    alternative is counting every unit, reporting none, and invoicing the base fee alone, which nothing
    would flag until the month's revenue came in short.

- Usage-billed tiers: a tier can now declare what it charges for USAGE on top of its base fee —
  "19 EUR a month, plus 0.50 EUR per 1 000 emails, first 10 000 included" — under
  `config('billing.tiers.<tier>.metered')`. An app records usage through a single call
  (`UsageRecorder::record($owner, 'emails', 42_000)`), which moves the owner's counter and writes the
  outbox row the provider is billed from in one local write, so the number the owner sees on the usage
  screen and the number they are charged for come from the same place. Usage carries an idempotency key,
  so a send job that runs twice is billed once, and it is accounted into the moment it HAPPENED, so a
  late record still lands in the cycle it belongs to. Malformed metering config throws rather than
  quietly billing nothing.

- Usage is accounted into the SUBSCRIPTION's billing cycle, not the calendar month: an owner who renews
  on the 17th has neither a calendar month nor a clean month boundary, and bucketing their usage by one
  would bill part of it into a cycle the provider has already invoiced. The cycle is mirrored from the
  provider onto the local subscription row.

- The default usage provider now reads the package's own counters, so an app that meters through the
  package gets a working usage screen with no extra wiring. An app that meters nothing keeps the
  unmetered provider, exactly as before.

- Usage counters are per METER, so an owner metering two things (emails sent AND contacts stored) no
  longer has both share one budget, where each would enforce the other's limit.

- Live Stripe smoke suite (`composer test:stripe-live`): the Stripe driver's setup-intent, stored
  mandate, payment-method list/default/remove, off-session charge and refund path run against the real
  Stripe test API (not the fake), proving the fake matches Stripe. It skips without a
  `STRIPE_TEST_SECRET` and is outside the default gate, so a bare checkout and CI stay offline.

- Real-browser account-hub E2E (`composer test:browser`, Playwright/Chromium): full-page rendering and
  a Livewire round-trip through the hub screens.

- DATEV export: a `DatevExport` that writes invoices as a DATEV "Buchungsstapel" (EXTF) file — the
  31-field header, the column captions, and one revenue booking per invoice (gross amount, debit
  marker, the configured receivables/revenue accounts, document date and number). The account numbers
  and length are read from `config('billing.datev')` and, being chart-of-accounts specific, are meant
  to be confirmed with the tax advisor; left empty the file is still structurally valid.

- E-invoicing (EN 16931 / XRechnung): a dependency-free `EInvoice` writer that renders a stored
  invoice as a UBL 2.1 document with the mandatory business terms — customization id, number, issue
  date, type code 380, currency, seller and buyer parties with postal addresses and VAT schemes, the
  per-rate tax breakdown, the document totals, and one line per item. The seller is the platform
  (`config('billing.company')`); the buyer, line items and tax split are stored on the immutable
  invoice. ZUGFeRD (embedding this XML in a PDF/A-3) is a separate opt-in writer.

- Admin/support console core: a `BillingAdmin` service for the three out-of-band operations a support
  agent performs on an owner's billing — comp a tier, cancel immediately, refund a charge — each
  recorded on the billing audit ledger, plus a reader for an owner's audit trail. It carries no UI and
  no authorization of its own, so an app wires it into its own admin panel behind its own gate.

- Seat sync: a `SeatSync` contract with a Stripe default that keeps a team owner's subscription
  quantity in step with its seat count. It acts only on an owner that provides seats and has a live
  subscription, and is a safe no-op otherwise (no seats, no subscription, or one Stripe has already
  canceled) — seat sync must never break a team's account.

- Licensing gate, separate from pricing: a `License` contract (with a config-backed default reading
  `config/license.php`) that answers what a tier UNLOCKS — boolean feature grants and numeric limits
  — independently of what it costs. Fail-closed (an unlisted feature is denied; an unlisted or null
  limit is uncapped) and stateless, so there is no cached grant to purge when a tier changes.

- `billing:run` scheduler command that advances the active driver's recurring billing cycle
  (scheduled hourly). A no-op under Stripe, which drives its own cycle; the seam exists so a
  local-engine driver advances every due subscription without a rewrite. Honors the master switch.

- Per-surface suspension lockout: a `billing.suspend:<surface>` middleware that returns HTTP 423
  (Locked) once a delinquent owner reaches the surface's configured dunning rung
  (`config('billing.suspension')`). The delinquency clock is a stored timestamp (`delinquent_since`,
  started when a subscription first blocks and cleared on recovery) — never a live gateway status —
  so lockout keeps working during a provider outage. Different surfaces can be withdrawn at
  different stages of delinquency.

- App-shell billing banner: a `<x-billing::banner />` component that surfaces the one thing an owner
  needs to act on — a failed payment, a lapsing grace period, or a trial about to end — with a
  severity-conveying callout and a call to action to the right hub screen, and renders nothing at all
  for a healthy account. New `config('billing.trial.ending_within_days')`.

- Scoped Content-Security-Policy for the account hub: a per-driver policy that whitelists the
  active payment provider's origins (Stripe.js, its frames and API) on the billing screens only,
  never across the rest of the app. Self-only and Livewire/Alpine-safe by default, extensible via
  `config('account.csp.additional')`, and it never overrides a CSP the host already set.

- Master switch now also drops Cashier's own routes (`Cashier::ignoreRoutes()`) when
  `billing.enabled` is off, so a disabled install exposes no billing routes at all.

- Stripe payment rails: on-session charge, stored-mandate creation, payment-method
  tokenization, off-session (merchant-initiated) charge, and refunds — all returning
  provider-neutral value objects.

- Stripe payment-method management (setup intent, list with default first, set-default,
  remove), invoice history and ownership-checked PDF download, and a null-tolerant
  next-invoice preview.

- In-app subscription actions: cancel at period end, resume, cancel now, and an
  upgrade/downgrade swap that resolves the price from the plan catalog by tier key
  (never a client-supplied price) with optional proration.

- One-time add-on purchase: opens a hosted checkout for an add-on (price resolved from
  the add-on key, never the client) and stamps the key on the session so the completion
  webhook credits the owner exactly once — completing the add-on money loop. New
  `billing.checkout.success_url` / `cancel_url` config.

- Account hub (Livewire) — the overview landing: the config-driven navigation to the hub
  sections and a one-line summary of the owner's current tier.

- Account hub (Livewire) — the subscription screen: shows the canonical subscription state
  and a best-effort next-invoice preview, and lets the owner cancel into the grace period
  or resume. Config-driven routes/middleware/layout (`config/account.php`), gated on the
  billing master switch, with a self-contained Basic-Blade view set and informal i18n.

- Account hub (Livewire) — the change-plan screen: the in-app upgrade/downgrade that offers
  the plans purchasable from the current tier and swaps to the chosen one by its key (never
  a client-supplied price). Each option can be previewed before committing — the proration
  strategy reports the net amount due for a mid-cycle change (via Stripe's invoice preview),
  degrading to "no estimate" when the change cannot be previewed rather than showing a wrong
  figure.

- Account hub — the invoice-history screen: lists recent invoices and streams a single
  invoice's document only after the driver confirms the owner owns it (no cross-owner leak).

- Account hub — the payment-methods screen: lists stored methods (default first), sets a
  new default, removes a method, and opens the add-a-method flow with the driver-shaped
  setup payload.

- Account hub — the usage screen: the owner's current metered usage per dimension (read
  from the project's usage provider), with warning/over states, or a plain unmetered note
  when the tier has no limits.

- Account hub — the payment-recovery screen: when a payment has failed (past due) it
  guides the owner to fix their payment method so the provider can retry; otherwise it
  reports nothing to recover.

- Account hub — the danger zone: stops billing immediately (the hook for an app's
  account-deletion flow) behind an explicit two-step confirmation.

- Hosted-portal bridge: a controller that redirects the owner to the provider's own
  billing portal (Stripe's customer portal), or 404s so the app falls back to the in-app
  screens when no portal is available.

- Eligibility gate: money-initiating account-hub actions (add/change payment method,
  swap plan) and the add-on checkout run a `CanTransactMoney` check first — at the UI and,
  as defense in depth, in the money-moving driver itself. The package allows everyone by
  default; an app binds the fail-closed gate with its own age/KYC checks to deny until
  eligible.

- Stripe webhook signature verification and an event mapper that translates Stripe
  events (subscription lifecycle, invoice paid/failed, one-time checkout) into the
  package's provider-neutral domain events.

- The Stripe driver is wired end to end: the SDK client, the driver, the account-hub
  and webhook contracts, the customer directory, and the default webhook effect set
  (plan sync, add-on credit, dunning) are registered so a Stripe app works out of the
  box. In production the app refuses to boot without a webhook signing secret.

### Changed

- Two concurrent first webhook deliveries for the same new subscription now converge instead of
  answering the provider with a 500. The losing insert's unique violation reruns the sync against the
  now-existing row under the same out-of-order guard, so the provider sees a clean success rather than
  an error it reads as our outage.

- The Stripe driver now reports `supportsMeteredNative: false`. It previously advertised native
  metering while nothing in the package reported usage to the provider, so an app trusting the flag
  would have billed no usage at all. A capability states what the package delivers, not what the
  provider is capable of.

- Next-invoice preview: the upcoming-invoice preview asked Stripe to preview a customer without
  saying WHAT to preview, which Stripe rejects — so the preview silently degraded to "no estimate"
  for every customer, always. It now previews against the owner's subscription (and returns null when
  there is none, which is correct). Found by running the driver against the real Stripe test API; the
  faked suite had stubbed a success response for a call Stripe never accepts.

- Account hub: the full-page screens now render inside the configured layout
  (`config('account.layout')`). They previously mounted a bare Livewire view with no layout, which
  failed full-page with "No hint path defined for [layouts]" — only surfaced by a real-browser test,
  not the component tests.

- E-invoice (EN 16931): a zero-rated line is now VAT category "Z" instead of "S" with a 0% rate
  (BR-S-05/06); the document-level tax total is derived from the per-band sum so it always reconciles
  (BR-CO-14) and the totals stay consistent (BR-CO-13/15); the buyer reference (BT-10) and the party
  electronic addresses (BT-34/49 EndpointID) are now emitted.

- DATEV export: a credit note (an invoice that credits another) is now booked as "H" (Haben), not
  "S" — booking it as a sale overstated turnover.

- Admin refund now passes an idempotency key, so a double-click or retry cannot double-refund.

- Subscription plan-sync: a subscription event with no timestamp can no longer resurrect access over a
  delinquent owner or disable the out-of-order guard for later events.

- The account-hub CSP now sets `frame-ancestors 'self'`, so the money-moving hub cannot be framed
  (clickjacking).

### Fixed

- Recording usage no longer eats an unrelated request's in-flight reservation. `UsageMeter::commit()`
  decremented the `reserved` counter unconditionally, so a plain `record()` — which never reserved
  anything — silently destroyed a hold another request was relying on, which meant reserving and recording
  could not be used in the same application. Recording now only moves what it used.

- The quota gate now counts HELD units against the allowance, not just used ones — a gate blind to
  reservations would wave through exactly the request a reservation exists to refuse.

- Adding a card or buying an add-on no longer creates an ANONYMOUS Stripe customer. Both paths created the
  customer themselves, with no email, no name and no back-reference to the owner — and nothing ever
  re-stamped it, so if either was the account's first trip to the provider, every invoice and receipt that
  account ever got was anonymous. Both now go through the customer registry, which creates the customer
  with the owner's identity on it. This was live on the default configuration.

- `billing.customer.column` is now honored everywhere, not just in half the package. Invoice history, the
  next-invoice preview, the plan-swap preview and the payment-method manager all read a hardcoded
  `stripe_id`, so on a renamed column they silently showed nothing — an empty invoice list, no cards, no
  preview, with no error to notice — and `billing:cards:warn` reported "Warned 0 owner(s)" and exited
  successfully while warning nobody. Worse, adding a card created a REAL customer at Stripe and then failed
  to write the id to a column that did not exist: a 500, and a live orphaned Stripe customer left behind on
  every retry. `billing:install` now generates the migration for the columns you configured, too, rather
  than for the default names.

- Reconciling a subscription from the provider no longer downgrades a paying customer because of a
  checkout they abandoned. The reconcile asked Stripe for the customer's single most recent subscription
  and took it — but Stripe lists newest first, and an abandoned checkout leaves an `incomplete_expired`
  subscription behind that is NEWER than the one the customer actually pays on. The lapsed one won, and a
  state that grants no access pulls the tier to zero: the customer kept paying, on the free tier, until
  something else moved their subscription. Both the post-checkout return and `billing:sync` now read every
  subscription the customer has and reconcile the one that is actually alive.

- A customer who pauses billing no longer keeps their paid plan for free. Stripe does not change a
  subscription's status when collection is paused — it keeps reporting `active` — and the package read
  only the status, so a pause taken in the hosted customer portal (which the package links from its own
  navigation) left the owner on the paid tier while Stripe raised no further invoice. Indefinitely. The
  package now reads `pause_collection`, maps it to a new `SubscriptionState::Paused`, takes the paid tier
  away, and shows the owner a banner explaining why their features stopped and how to resume. A pause is
  never treated as delinquency: it starts no dunning clock, sends no suspension warning and charges no
  late fee — the owner chose it, they did not fail to pay.

- A dunning notice can no longer be lost forever. The webhook spine recorded an effect as done _before_
  running it, so an effect that then failed left a marker saying "handled" and a customer who was never
  told their payment failed — and nothing would ever come back for it. An effect now claims, runs and
  marks itself handled inside one transaction: if it fails, the claim rolls back with it and the work
  stays owed. Notifications are queued after that commit, so a run that rolled back cannot have mailed
  anybody, and the retry that redoes the work is not a second mail to the customer.

- A payment failure that the provider retries no longer mails the customer once per retry. Stripe mints a
  fresh event id for every retry of the same failing invoice, and the dunning notice deduplicated on that
  event id. It now deduplicates on the invoice.

- A payment that needs 3-D Secure authentication, or a SEPA debit still processing, is no longer
  reported as a decline. `ChargeResult` now distinguishes settled, declined, requires-action (carrying
  the client secret the front end confirms against) and pending — so a successful European card payment
  is no longer indistinguishable from a failure, and dunning no longer acts on a payment that simply is
  not finished yet.

- The payment-recovery screen now handles an incomplete (awaiting-confirmation) subscription: it prompts
  the owner to confirm the payment, where it used to answer "nothing to recover" to the very owner the
  banner was telling to confirm their payment.

- One account can no longer remove or re-default another account's stored payment method. The method id
  travels to the browser to be rendered and comes back under the client's control; both mutating verbs
  now check it against the owner's own methods (and the driver re-checks it against the Stripe customer),
  where before a detach — which is global to the method id — went through unchecked.

- A paying owner whose provider price was rotated is no longer parked on the free tier after a single
  past-due blip. The blip pulled the tier to zero and every later event carried a now-unrecognized price,
  so nothing ever restored the paid tier; the sync now falls back to the last tier resolved on the local
  subscription row.

- A subscription carrying more than one price item no longer corrupts the account. Every subscription
  surface addressed the item at position 0 — but a subscription may legitimately carry a second item (a
  usage-billed component, an item the app added), and the provider does not promise their order. As a
  result: the tier lookup could resolve to nothing and **force a paying customer down to the free tier
  on every subscription webhook**; a plan swap could reprice the wrong item; and a seat sync could write
  a quantity onto an item that forbids one. The tier item is now identified by its price, and any item
  the package did not put there is left strictly alone.

- The tier is only pulled to zero when the subscription actually stops granting access. An
  access-granting subscription whose price maps to no configured tier now leaves the owner's tier
  untouched — unknown is not zero, and the owner is paying.

- Seat sync no longer swallows a provider rejection. A failed sync is logged instead of silently
  leaving the team billed for the seat count the provider still holds.

- Off-session (merchant-initiated) charges now include the Stripe customer, so a
  stored payment method can actually be charged when the cardholder is away.

- Plan sync ignores an out-of-order or retried older subscription webhook instead of
  regressing a paying customer's tier, and records the subscription state locally.

- One-time add-on credit waits for the payment to actually settle (asynchronous
  methods no longer credit while still pending).

- Canceling or resuming a subscription is a safe no-op when it is already gone at the
  provider, so account deletion is never blocked.

- Completed the zero-decimal currency set (UGX, XPF, …) so those amounts are no longer
  off by 100×.

- The webhook endpoint honors the billing master switch (404 when billing is disabled).

- Payment-method listing requests the full page so the default card is never truncated
  away.

- Charge, off-session charge and refund accept an idempotency key (passed to the
  provider), so a retried money-moving operation cannot double-charge or double-refund.

- A trialing or grace-period subscriber synced from a webhook resolves to their paid
  tier instead of being dropped to the free tier.

- One subscription-state row per owner is enforced, and same-second out-of-order
  webhooks can no longer restore access to a canceled subscription.

[Unreleased]: https://github.com/pushery/billing-for-laravel/compare/v0.20.0...HEAD
[0.20.0]: https://github.com/pushery/billing-for-laravel/compare/v0.19.1...v0.20.0
[0.19.1]: https://github.com/pushery/billing-for-laravel/compare/v0.19.0...v0.19.1
[0.19.0]: https://github.com/pushery/billing-for-laravel/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/pushery/billing-for-laravel/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/pushery/billing-for-laravel/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/pushery/billing-for-laravel/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/pushery/billing-for-laravel/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/pushery/billing-for-laravel/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/pushery/billing-for-laravel/compare/v0.9.0...v0.13.0
[0.9.0]: https://github.com/pushery/billing-for-laravel/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/pushery/billing-for-laravel/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/pushery/billing-for-laravel/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/pushery/billing-for-laravel/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/pushery/billing-for-laravel/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/pushery/billing-for-laravel/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/pushery/billing-for-laravel/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/pushery/billing-for-laravel/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/pushery/billing-for-laravel/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pushery/billing-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/billing-for-laravel/releases/tag/v0.1.0
