<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\UpcomingInvoice;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;

/**
 * The no-op UpcomingInvoice bound when billing is disabled: there is no provider to preview a next invoice
 * against, so it always answers null (no upcoming charge) instead of reaching for Stripe without keys.
 */
final class NullUpcomingInvoice implements UpcomingInvoice
{
    public function preview(Model $billable): ?UpcomingInvoicePreview
    {
        return null;
    }
}
