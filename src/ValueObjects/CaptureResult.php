<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The outcome of capturing an authorized payment, as the provider answered it.
 */
final readonly class CaptureResult
{
    /**
     * @param  string  $reference  the provider's id of the capture, empty where the provider refused it
     * @param  ?Money  $amount  what is being captured, null where the provider stated no amount for a refused capture of the whole payment
     * @param  ?string  $status  the capture's status at the provider, such as `pending` or `succeeded`
     * @param  ?string  $failureCode  why the provider refused, in its own terms
     * @param  ?string  $failureMessage  the provider's words for the refusal, where it gave any
     */
    public function __construct(
        public bool $successful,
        public string $reference,
        public ?Money $amount,
        public ?string $status = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
