<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * A READ-ONLY view of what a party has earned on the marketplace — and nothing more.
 *
 * This is the supervisory-law-harmless half of the payout decision: it lets a creator SEE
 * their balance without the platform ever holding, moving, or being able to move the money. It is a pure
 * aggregate projection over the routed-charge record (`billing_merchant_charges`) — no state table of its
 * own, so the balance can never drift from the charges it sums. Every value it returns is a {@see Money},
 * never a provider figure: the platform's own books, reconciled against the provider separately.
 *
 * Three buckets, because "what has a creator earned" has three honest answers:
 *
 *  - `available` — settled money, net of anything already clawed back. What the provider has actually paid
 *    the connected account.
 *  - `pending` — routed but not yet settled (a 3-D Secure step is routine under PSD2), so real but not yet
 *    the creator's. Nothing is available before it settles.
 *  - `held` — settled but withheld under buyer protection (the delayed-release C2C flow), the creator's
 *    only once the hold releases.
 *
 * It has no writing method and reaches no provider on purpose. Money movement lives exclusively behind the
 * money-out seams (the marketplace rails), which this reader is deliberately not — reading a balance can
 * never become spending one.
 */
interface LedgerBalanceReader
{
    /** Settled earnings a party may already draw on, in the given currency, net of clawbacks. */
    public function availableFor(Model $party, string $currency): Money;

    /** Routed earnings not yet settled — real, but not yet the party's. */
    public function pendingFor(Model $party, string $currency): Money;

    /** Settled earnings withheld under buyer protection, the party's only once the hold releases. */
    public function heldFor(Model $party, string $currency): Money;
}
