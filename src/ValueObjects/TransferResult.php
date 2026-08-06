<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a provider said about moving a merchant's share.
 *
 * Deliberately minimal: the reference the transfer got, and the amount that actually moved. Both are needed
 * and neither is derivable from the request — a provider may move less than was asked for, and the reference
 * is what a later reversal acts on.
 *
 * There is no "pending" here, and that is not an oversight. A transfer funded by a specific payment either
 * exists or the call failed; a driver that cannot say which must throw rather than return a result that
 * reads as success with nothing behind it.
 */
final readonly class TransferResult
{
    public function __construct(
        public string $reference,
        public Money $moved,
    ) {}
}
