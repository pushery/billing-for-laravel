<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\RequiresElectronicInvoicing;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * Whether a document is issued electronically here.
 *
 * Two sources, in this order: what the operator set, then what their jurisdiction requires. The order is the
 * point — the jurisdiction is the default because that is where the obligation comes from, and the operator
 * overrides it because they may be ahead of their own regime, or deliberately behind it while migrating, for
 * a reason the package cannot know.
 *
 * Unset is not the same as false. A consumer who has never heard of an e-invoicing regime has no opinion,
 * and reading their silence as "no" would mean an operator in a jurisdiction that requires it silently does
 * not comply. So silence asks the profile, and only an explicit `false` turns it off.
 */
final readonly class ElectronicInvoicePolicy
{
    public function __construct(
        private Repository $config,
        private CheckpointRegistry $profiles,
    ) {}

    public function required(): bool
    {
        $configured = $this->config->get('billing.e_invoice.always');

        if (is_bool($configured)) {
            return $configured;
        }

        $profile = $this->profiles->profile();

        return $profile instanceof RequiresElectronicInvoicing && $profile->requiresElectronicInvoicing();
    }
}
