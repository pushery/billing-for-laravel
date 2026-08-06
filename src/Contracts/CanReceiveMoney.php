<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The receiving-side twin of {@see CanTransactMoney}: a fail-closed gate run before money may be DESTINED
 * to a merchant, answering whether that merchant may receive it at all.
 *
 * It is a separate contract rather than a second use of the paying gate, because it answers a different
 * question about a different person. `CanTransactMoney` asks whether a buyer may move money out — age, KYC,
 * their own standing. This asks whether a merchant may take money in, which turns on facts the buyer has
 * nothing to do with: the provider's own verification of them, a payout capability that can be withdrawn
 * mid-relationship, and whatever the platform requires of the people it pays.
 *
 * Fail-closed for the same reason as its twin, and one more. The capabilities behind it are reported
 * ASYNCHRONOUSLY, so the local answer can be older than the truth — and money routed to a merchant who
 * cannot receive it does not bounce cleanly, it settles somewhere it was not meant to and is unwound by
 * hand. Unknown must therefore mean no.
 */
interface CanReceiveMoney
{
    public function check(Model $merchant): bool;
}
