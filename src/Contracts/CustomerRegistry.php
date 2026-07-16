<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The provider side of an owner's identity: forgetting the customer the package created for them.
 *
 * It is a seam and not a call into the driver because deleting the customer is IRREVERSIBLE — it also
 * cancels that customer's live subscriptions at the provider — and because a package with billing switched
 * off must be able to erase an owner without telephoning anybody.
 */
interface CustomerRegistry
{
    /**
     * Delete the owner's customer at the provider and forget its reference locally.
     *
     * The provider keeps its own invoice and charge records regardless of this call: it is the customer
     * object that goes, not the accounting behind it.
     */
    public function forget(Model $billable): void;
}
