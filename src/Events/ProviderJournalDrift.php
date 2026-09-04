<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * The package's journal and the provider DISAGREE about money that already moved to a merchant.
 *
 * ## Why this alarm and not a balance comparison
 *
 * The obvious reconciliation — our figure against the connected account's balance at the provider — fires on
 * every legitimate event. That balance also moves when the merchant is paid out to their bank, when they
 * take money through some other integration, and when the provider debits a fee. None of those is a defect,
 * and an alarm that fires on all of them is switched off within a week. On a money path an alarm nobody
 * reads is worse than no alarm, because it is also an alibi.
 *
 * So the comparison is per TRANSFER: the one object both sides name, that only this package creates, and
 * that nothing outside it moves. Everything else about the account is legitimately none of our business.
 *
 * ## Which side is right
 *
 * The provider is authoritative for what MOVED; this package is authoritative for what was OWED. They are
 * different questions, and only the first is what this event reports. So a drift is always corrected by
 * fixing the local row — never by re-transferring to match the journal, which would move real money to
 * repair a bookkeeping disagreement.
 */
final readonly class ProviderJournalDrift implements BillingDomainEvent
{
    public const string MISSING_AT_PROVIDER = 'missing_at_provider';

    public const string AMOUNT_DIFFERS = 'amount_differs';

    public const string CURRENCY_DIFFERS = 'currency_differs';

    public function __construct(
        public Model $merchant,
        public string $chargeReference,
        public string $transferReference,
        /** One of the three class constants. */
        public string $reason,
        /** What the package believes the merchant kept from this transfer. */
        public Money $ours,
        /** What the provider says, or null when it has no record of the reference at all. */
        public ?Money $theirs,
    ) {}

    /**
     * Provider minus ours, in minor units — negative means we recorded more than moved.
     *
     * Null when the provider has no record, because there is no figure to subtract. A caller that treated
     * that as zero would rank the worst finding as the smallest one.
     */
    public function deltaMinor(): ?int
    {
        if (! $this->theirs instanceof Money || $this->theirs->currency !== $this->ours->currency) {
            return null;
        }

        return $this->theirs->minorUnits - $this->ours->minorUnits;
    }
}
