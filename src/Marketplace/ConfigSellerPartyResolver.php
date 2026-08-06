<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\SellerPartyResolver;
use Pushery\Billing\Invoicing\Party;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * The default: the seller is always the platform, read from `billing.company`.
 *
 * This is the single-seller answer and the shipped one — every document names the platform, so nothing about
 * the rendered output changes from before the seller became resolvable. The `is_array` guard mirrors the one
 * the invoice writers used inline, so a null or scalar `billing.company` still yields a Party of empty
 * strings and country DE rather than a type error, exactly as it did when the writers read the config
 * directly.
 *
 * A marketplace consumer binds a resolver that returns the merchant where the frozen posture is not the
 * platform-deemed-supplier one; this class never does, because the platform is always the seller of its own
 * supplies.
 */
final readonly class ConfigSellerPartyResolver implements SellerPartyResolver
{
    public function __construct(private Repository $config) {}

    public function sellerFor(InvoiceRecord $invoice): Party
    {
        $company = $this->config->get('billing.company');

        return Party::fromArray(is_array($company) ? $company : []);
    }
}
