<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A fail-closed eligibility gate run at the ENTRY seams where a payment begins — `Checkout`,
 * `OneTimeCharge` and `SubscriptionActions::swap`. It answers whether the owner may transact (age / KYC),
 * and denies unless positively eligible — the default is deny.
 *
 * It is deliberately NOT run on `PaymentRails::charge()` or `offSessionCharge()`, and this docblock used to
 * list both. `PaymentRails` states the rule and the reason in full, and it is worth knowing here too: an
 * off-session charge is what a dunning retry uses, and a subscriber who was eligible when they subscribed
 * can later fail an age or KYC predicate. Gating there would refuse to collect money the customer already
 * owes — so a gate at the low layer looks more central and is wrong twice over.
 *
 * That an entry seam cannot silently lose its gate is enforced structurally rather than by this sentence:
 * `tests/Unit/MoneyEntryGateTest.php` — named as a PATH, never imported. A `use` of a test class
 * from shipped source makes the autoloader load that file, Pest loads it again, and its global
 * helper redeclares: a fatal that kills the whole suite before a single test runs.
 */
interface CanTransactMoney
{
    public function check(Model $owner): bool;
}
