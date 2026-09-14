<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Enums\TaxIdVerificationStatus;

/**
 * The provider reported what a tax authority's register said about a buyer's tax ID.
 *
 * A checkout that collects a tax ID checks only its format while the buyer is on the page. The register is asked
 * afterwards, pending first and a verdict later, so this arrives after the sale. It carries no decision: what follows
 * from a number the register does not know is the effect's question.
 */
final readonly class TaxIdVerificationReported implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $provider,
        public string $taxIdReference,
        public string $type,
        public string $value,
        public TaxIdVerificationStatus $status,
        public ?string $verifiedName = null,
        public ?string $verifiedAddress = null,
    ) {}
}
