<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Models\SellerDeclarationContradiction;

/**
 * Something the platform holds contradicts what a seller declared, so the seller's next sale waits for a new declaration.
 *
 * For the operator: the seller has to be asked to declare again, and until it has, a sale of goods the platform would
 * supply itself is refused.
 */
final readonly class SellerDeclarationContradicted implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $merchant,
        public SellerDeclarationContradiction $contradiction,
    ) {}
}
