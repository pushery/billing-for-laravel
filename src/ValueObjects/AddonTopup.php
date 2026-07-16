<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;

/**
 * One entry in an owner's add-on top-up timeline — a recorded one-time add-on purchase, read
 * column-authoritatively from the persisted purchase row. `reversed` is true once the purchase was
 * fully clawed back (revoked, or its reversed amount reached the purchase amount), so the timeline can
 * show a top-up that was later undone (a refund / dispute) without dropping it from the history.
 */
final readonly class AddonTopup
{
    public function __construct(
        public string $addonKey,
        public Money $amount,
        public DateTimeInterface $purchasedAt,
        public bool $reversed,
    ) {}
}
