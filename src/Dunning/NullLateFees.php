<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\ValueObjects\Money;

/**
 * The default LateFees: charges nothing. A driver that cannot post a fee (or a consumer that does not
 * want the package charging late fees at all) keeps this — the dunning advance still escalates its
 * reminders, it just does not add a fee. The Stripe driver replaces it with one that raises an invoice
 * item.
 */
final class NullLateFees implements LateFees
{
    public function apply(Model $owner, Money $fee, string $reference, string $description): void
    {
        // No fee is charged.
    }
}
