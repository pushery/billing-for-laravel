<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SellerDataEscalationStage;
use Pushery\Billing\Enums\SellerDataMeasure;

/**
 * A seller's record is still missing something, and they are being asked for it.
 *
 * Dispatched twice per episode at most, once for each reminder, and `reminder` says which. `missingFields`
 * names what is asked for, because "your record is incomplete" sends somebody hunting through a form.
 *
 * `measureFrom` and `measure` are set only where a measure can follow, which is where a field required of
 * this seller is missing: the date from which it applies and what it will be. A seller the duty does not
 * cover is asked all the same and told of no consequence, because there is none.
 *
 * `episodeId` names the row the reminder belongs to, so what was delivered can be written back to it.
 */
final readonly class SellerDataReminderDue implements BillingDomainEvent
{
    /**
     * @param  list<string>  $missingFields
     */
    public function __construct(
        public Model $merchant,
        public int $episodeId,
        public SellerDataEscalationStage $reminder,
        public array $missingFields,
        public ?CarbonImmutable $measureFrom,
        public ?SellerDataMeasure $measure,
    ) {}
}
