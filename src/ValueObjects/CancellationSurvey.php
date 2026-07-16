<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use InvalidArgumentException;
use Pushery\Billing\Enums\CancellationReason;

/**
 * The optional response an owner gives when canceling — a reason plus an optional free-text
 * detail. When the reason requires detail ("Other"), the detail must be present, so a survey can
 * never be persisted in a self-contradictory state.
 */
final readonly class CancellationSurvey
{
    public function __construct(
        public CancellationReason $reason,
        public ?string $detail = null,
    ) {
        if ($reason->detailRequired() && ($detail === null || trim($detail) === '')) {
            throw new InvalidArgumentException("The '{$reason->value}' cancellation reason requires a detail.");
        }
    }

    public function hasDetail(): bool
    {
        return $this->detail !== null && trim($this->detail) !== '';
    }
}
