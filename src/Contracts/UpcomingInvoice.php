<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;

/**
 * A best-effort preview of the next invoice. This is the one live provider read on the subscription
 * screen, so it MUST be allowed to return null — for drivers that cannot preview (Mollie/Adyen), or
 * when the read fails — and the UI degrades rather than showing a wrong figure.
 */
interface UpcomingInvoice
{
    public function preview(Model $billable): ?UpcomingInvoicePreview;
}
