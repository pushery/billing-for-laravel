<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SellerDataMeasure;

/**
 * A measure over missing seller data began or ended.
 *
 * `inForce` is true when it began and false when it ended; `reason` says why it ended: the record was
 * completed, the data stopped being required of this seller, or a withholding ran as long as it may and was
 * converted. A suspension is also announced by the merchant lifecycle's own event, because it is the same
 * suspension any other reason would cause. A withholding has no other announcement, which is why this one
 * exists.
 */
final readonly class SellerDataMeasureChanged implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        public SellerDataMeasure $measure,
        public bool $inForce,
        public ?string $reason = null,
    ) {}
}
