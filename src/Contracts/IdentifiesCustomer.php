<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A domain event that names the provider customer it is about.
 *
 * It exists so the package can attribute a stored webhook DELIVERY to an owner. That matters for one
 * reason above all: the raw payload the receiver keeps carries the customer's personal data — their email,
 * their name, their billing address, the last four digits of their card. An erasure request has to be able
 * to FIND that, and a delivery with no owner is personal data nobody can reach.
 */
interface IdentifiesCustomer
{
    public string $customerReference { get; }
}
