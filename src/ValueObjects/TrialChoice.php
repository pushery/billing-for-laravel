<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The trial of one start over a mandate, as the application decides it instead of the trial policy.
 *
 * `endingAt()` puts the subscription in a trial that ends at that moment, and its first run comes there, as with a trial
 * the policy gives. `none()` starts it without a trial: collected in advance, the next run charges its first period, and
 * collected in arrears, the run at the period's end closes it. A start that is passed no choice takes the trial the
 * policy gives, once per owner.
 *
 * A choice is final for the start it is passed to. The policy is not asked, whatever trial the owner had before, and a
 * code that gives the tier trial days changes the trial in neither direction. A code that names a discount is honored
 * either way.
 *
 * `endsAt` is the moment the trial ends, or null for a start without one.
 */
final readonly class TrialChoice
{
    private function __construct(
        public ?CarbonImmutable $endsAt,
    ) {}

    /**
     * A trial that ends at the moment given, which lies after now.
     *
     * @throws InvalidArgumentException when the moment is not after now
     */
    public static function endingAt(DateTimeInterface $endsAt): self
    {
        $moment = CarbonImmutable::createFromInterface($endsAt);

        if ($moment->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException(sprintf('A trial ends after now, and %s does not lie after now.', $moment->toIso8601String()));
        }

        return new self($moment);
    }

    /** A start without a trial, whatever the policy gives. */
    public static function none(): self
    {
        return new self(null);
    }
}
