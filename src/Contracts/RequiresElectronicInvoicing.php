<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A jurisdiction profile whose business documents have to be issued electronically.
 *
 * A marker a profile opts into, like {@see SuppliesTaxRates}. It exists so "we issue every document as an
 * e-invoice from the first one" stays what it is — an operator's decision under their own law — rather than
 * something the package imposes on a consumer whose jurisdiction has no such regime at all.
 *
 * Configuration still wins where it is set, in both directions: an operator who is ahead of their own
 * jurisdiction, or deliberately behind it during a migration, has a reason the package cannot know.
 */
interface RequiresElectronicInvoicing
{
    /** Whether a business document in this jurisdiction is issued electronically. */
    public function requiresElectronicInvoicing(): bool;
}
