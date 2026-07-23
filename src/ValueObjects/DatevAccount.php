<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * A resolved DATEV account: the account number a booking lands on, and whether it is an Automatikkonto.
 *
 * An Automatikkonto carries its own tax logic — it derives the VAT from the posting itself, so a
 * BU-Schlüssel (tax key) must NEVER be set alongside it. Setting one cancels the automatic derivation and
 * is the classic DATEV import error. This flag is what a caller checks before it would emit a BU-Schlüssel:
 * on an automatic account, it stays empty.
 */
final readonly class DatevAccount
{
    public function __construct(
        public string $number,
        public bool $automatic = true,
    ) {}
}
