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
        /**
         * The transaction key a reverse-charge booking on this account has to carry, or null when the chart
         * declares none.
         *
         * It belongs to the account rather than to the exporter because the catalog of keys is specific to
         * a chart of accounts, exactly like the account number — a value in code would bury a
         * jurisdiction-specific number in the neutral core. Null means the field is not emitted at all,
         * which is what keeps an install that never configured one unchanged.
         */
        public ?int $reverseChargeTransactionKey = null,
    ) {}
}
