<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Copies the payment method a customer is charged with by default onto the owner, into the `pm_type` and
 * `pm_last_four` columns the account hub and the trial callouts read without asking the provider.
 *
 * A driver whose engine keeps those columns implements this, and only such a driver binds it. An owner whose table
 * has neither column is left alone: an application that does not want the copy needs no columns for it.
 */
interface MirrorsCardOnFile
{
    /** Write the owner's default method into the columns, or clear them when the customer has none. */
    public function mirror(Model $owner): void;
}
